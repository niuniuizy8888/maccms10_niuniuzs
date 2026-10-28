<?php
// Run: php tests/security-regression.php (requires pdo_sqlite and Composer dependencies).
// Uses only a temporary directory and an in-memory database; never loads site credentials.
namespace app\common\service\nnzs {
    function file_put_contents($file, $data, $flags = 0) {
        if (!empty($GLOBALS['test_short_write'])) {
            return \file_put_contents($file, substr($data, 0, 10), $flags);
        }
        return \file_put_contents($file, $data, $flags);
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
    if (version_compare(PHP_VERSION, '8.5.12', '<')) {
        fwrite(STDERR, "PHP >= 8.5.12 required\n");
        exit(1);
    }
    $repo = dirname(__DIR__) . '/';
    $tmp = sys_get_temp_dir() . '/maccms-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($tmp . 'application/data/backup/nnzs', 0700, true);
    mkdir($tmp . 'runtime', 0700, true);
    define('ROOT_PATH', $repo);
    define('MAC_PATH', '/');
    define('APP_PATH', $tmp . 'application/');
    define('RUNTIME_PATH', $tmp . 'runtime/');
    require $repo . 'thinkphp/base.php';
    require $repo . 'thinkphp/helper.php';
    \think\Loader::addNamespace('app', $repo . 'application');
    require $repo . 'application/common.php';
    \think\Config::set('database', ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'mac_', 'fields_strict' => false]);
    \think\Config::set('session', ['auto_start' => true, 'prefix' => 'test', 'path' => $tmp]);
    \think\Config::set('captcha', include $repo . 'application/extra/captcha.php');
    \think\Session::init();
    $checks = 0;
    function check($ok, $message) {
        global $checks;
        if (!$ok) { throw new \RuntimeException($message); }
        $checks++;
    }
    $exitCode = 0;
    try {
        check(class_exists('think\\captcha\\Captcha'), 'Composer captcha dependency missing');
        // File integrity checks must inspect arbitrary project directories while skipping runtime data.
        $scanRoot = $tmp . 'file-scan/';
        foreach (['runtime', 'upload', 'application/data/backup', 'analysis-artifacts'] as $dir) {
            mkdir($scanRoot . $dir, 0700, true);
            file_put_contents($scanRoot . $dir . '/sample.txt', 'fixture');
        }
        $originalCwd = getcwd();
        try {
            chdir($scanRoot);
            $safety = (new \ReflectionClass(\app\admin\controller\Safety::class))->newInstanceWithoutConstructor();
            (new \ReflectionMethod($safety, 'listDir'))->invoke($safety, './');
            check(isset($safety->_files['./analysis-artifacts/sample.txt']), 'Project directory omitted from integrity scan');
            check(count($safety->_files) === 1, 'Runtime, upload or backup data included in integrity scan');
        } finally { chdir($originalCwd); }
        $captcha = new \think\captcha\Captcha(config('captcha'));
        $auth = new \ReflectionMethod($captcha, 'authcode');
        $auth->setAccessible(true);
        $key = $auth->invoke($captcha, 'ThinkPHP.CN');
        $challenge = ['verify_code' => $auth->invoke($captcha, 'ABCD'), 'verify_time' => time()];
        \think\Session::set($key, $challenge, '');
        check(mac_captcha_check('abcd'), 'Valid captcha rejected');
        check(!mac_captcha_check('ABCD'), 'Successful captcha replay accepted');
        \think\Session::set($key, $challenge, '');
        check(!mac_captcha_check('wrong'), 'Wrong captcha accepted');
        check(!mac_captcha_check('ABCD'), 'Failed captcha was not destroyed');
        $challenge['verify_time'] = time() - 601;
        \think\Session::set($key, $challenge, '');
        check(!mac_captcha_check('ABCD'), 'Configured captcha expiry ignored');

        $html = \app\common\service\nnzs\Store::richHtml('<span onmouseover="alert(1)">测试</span><b onclick="alert(2)">ok</b><a href="javascript:alert(3)">bad</a><a href="https://example.com/">safe</a><svg onload="alert(4)"></svg>');
        check(!preg_match('/onmouseover|onclick|javascript:|onload|<svg/i', $html), 'Unsafe announcement HTML survived');
        check(strpos($html, 'https://example.com/') !== false && strpos($html, 'noopener noreferrer nofollow') !== false, 'Safe announcement link lost');
        check(strpos($html, '测试') !== false, 'UTF-8 announcement text lost');
        ob_start();
        (new \think\Template())->display('{$text|raw}', ['text' => '<b>ok</b>']);
        check(ob_get_clean() === '<b>ok</b>', 'raw template regression');
        ob_start();
        (new \think\Template(['tpl_deny_php' => true]))->display('<?xml version="1.0" encoding="UTF-8"?><root>{$text}</root>', ['text' => 'safe']);
        check(simplexml_load_string(ob_get_clean()) !== false, 'XML declaration rejected as PHP');
        foreach (['<?php echo "unsafe"; ?>', '<?= "unsafe" ?>'] as $unsafe) {
            try { (new \think\Template(['tpl_deny_php' => true]))->display($unsafe); throw new \LogicException('Executable PHP accepted'); }
            catch (\think\Exception $e) { check((int)$e->getCode() === 11600, 'PHP template guard changed'); }
        }


        \think\Db::execute('CREATE TABLE mac_admin (admin_id INTEGER PRIMARY KEY, admin_pwd TEXT, admin_status INTEGER, admin_auth TEXT)');
        $row = ['admin_id' => 2, 'admin_pwd' => password_hash('test password', PASSWORD_DEFAULT), 'admin_status' => 1, 'admin_auth' => ',index/index,'];
        \think\Db::name('admin')->insert($row);
        $admin = new \app\common\model\Admin();
        $login = function () use ($row) { session('admin_auth', '1'); session('admin_info', $row); };
        $login();
        check($admin->checkLogin()['code'] === 1, 'Valid admin session rejected');
        \think\Db::name('admin')->where('admin_id', 2)->update(['admin_auth' => ',index/welcome,']);
        check($admin->checkLogin()['info']['admin_auth'] === ',index/welcome,', 'Permissions did not refresh');
        \think\Db::name('admin')->where('admin_id', 2)->update(['admin_status' => 0]);
        check($admin->checkLogin()['code'] !== 1 && session('admin_auth') === null, 'Disabled admin session survived');
        \think\Db::name('admin')->where('admin_id', 2)->update(['admin_status' => 1, 'admin_pwd' => 'changed']);
        $login();
        check($admin->checkLogin()['code'] !== 1, 'Password change did not revoke session');
        \think\Db::name('admin')->where('admin_id', 2)->delete();
        $login();
        check($admin->checkLogin()['code'] !== 1, 'Deleted admin session survived');

        \think\Db::execute('CREATE TABLE mac_type (type_id INTEGER PRIMARY KEY, type_pid INTEGER, type_sort INTEGER, type_name TEXT)');
        \think\Db::name('type')->insert(['type_id' => 1, 'type_pid' => 0, 'type_sort' => 0, 'type_name' => '<?php invalid syntax { ?>']);
        $file = \app\common\service\nnzs\Backup::table('type');
        check(is_string($file) && is_file($file), 'Backup creation failed');
        $body = file_get_contents($file);
        check(json_decode(substr($body, strlen(\app\common\service\nnzs\Backup::GUARD)), true)['count'] === 1, 'Backup JSON invalid');
        $proc = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $lint = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc) === 0, 'Data in backup parsed as PHP: ' . $lint);
        $GLOBALS['test_short_write'] = true;
        check(\app\common\service\nnzs\Backup::table('type') === false, 'Partial backup write accepted');
        check(count(\app\common\service\nnzs\Backup::lists()) === 1, 'Partial backup file retained');
        try {
            \app\common\service\nnzs\TypeImporter::clearAll();
            throw new \LogicException('Destructive operation proceeded after short write');
        } catch (\RuntimeException $e) {
            check(strpos($e->getMessage(), '备份失败') !== false, 'Unexpected failure instead of backup guard');
        }
        check(\think\Db::name('type')->count() === 1, 'Failed backup changed data');
        \think\Db::execute('CREATE TABLE mac_vod (vod_id INTEGER PRIMARY KEY, vod_pic TEXT, vod_douban_id INTEGER, vod_pic_slide TEXT, vod_level INTEGER)');
        \think\Db::name('vod')->insert(['vod_id' => 1, 'vod_pic' => 'old.jpg', 'vod_douban_id' => 123, 'vod_pic_slide' => 'original.jpg', 'vod_level' => 0]);
        \think\Db::execute('CREATE TABLE mac_group (group_id INTEGER PRIMARY KEY, group_name TEXT)');
        \think\Db::name('group')->insert(['group_id' => 1, 'group_name' => 'original']);
        $operations = [
            function () { \app\common\service\nnzs\Replacer::replace('img', 'old', 'new'); },
            function () { \app\common\service\nnzs\Poster::apply('test', 5, ['hb' => ['test' => [['dbid' => 123, 'image_url' => 'https://8.8.8.8/poster.jpg']]]]); },
            function () { \app\common\service\nnzs\Group::grantAll(); },
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                throw new \LogicException('Operation proceeded after short backup write');
            } catch (\RuntimeException $e) {
                check(strpos($e->getMessage(), '备份失败') !== false, 'Operation failed outside backup guard');
            }
        }
        $vod = \think\Db::name('vod')->find();
        check($vod['vod_pic'] === 'old.jpg' && $vod['vod_pic_slide'] === 'original.jpg' && (int)$vod['vod_level'] === 0, 'Failed backup changed video');
        check(\think\Db::name('group')->value('group_name') === 'original', 'Failed backup changed group');
        unset($GLOBALS['test_short_write']);
        check(\app\common\service\nnzs\Replacer::replace('img', 'old', 'new') === 1, 'Normal replacement failed');
        check(\think\Db::name('vod')->value('vod_pic') === 'new.jpg', 'Normal replacement did not persist');
        $backups = \app\common\service\nnzs\Backup::lists();
        check(count($backups) === 2, 'Successful replacement has no backup');
        $collect = new \app\common\model\Collect();
        $decode = new \ReflectionMethod($collect, 'decodeCollectJson');
        foreach (['"ok"', 'true', '123', 'null', '{}', '{broken', '{"list":"bad"}', '{"list":["bad"]}', '{"list":[{"type_id":[]}]}', '{"list":[],"class":"bad"}', '{"list":[],"page":[]}', '{"list":[{"dl":"bad"}]}', '{"list":[{"dl":{"player":[]}}]}'] as $json) {
            check($decode->invoke($collect, $json) === false, 'Invalid feed accepted: ' . $json);
        }
        $feed = $decode->invoke($collect, '{"list":[{"vod_name":"Test","dl":{"player":"Episode$url"}}]}');
        check($feed['page'] === 1 && $feed['total'] === 1 && $feed['class'] === [], 'Optional feed metadata defaults failed');
        check($feed['list'][0]['type_id'] === 0 && $feed['list'][0]['dl']['player'] === 'Episode$url', 'Sparse feed or player map changed');
        check($decode->invoke($collect, '{"list":[],"total":0}')['list'] === [], 'Empty feed rejected');
        set_error_handler(function ($severity, $message) {
            if ($severity === E_WARNING) { throw new \RuntimeException($message); }
            return false;
        });
        try {
            check(\think\Db::name('vod')->where(['vod_pic' => ['not null']])->count() === 1, 'Unary not-null condition failed');
            check(\think\Db::name('vod')->where(['vod_pic' => ['null']])->count() === 0, 'Unary null condition failed');
        } finally { restore_error_handler(); }
        // Exercise the real collection model with sparse rows and metadata-only updates.
        \think\Db::execute('DROP TABLE mac_vod');
        $schema = file_get_contents($repo . 'application/install/sql/install.sql');
        foreach (['vod', 'art', 'comment'] as $table) {
            preg_match('/CREATE TABLE `mac_' . $table . '` \((.*?)\) ENGINE/s', $schema, $definition);
            preg_match_all('/^\s*`([^`]+)`\s+(\w+)/m', $definition[1], $fields, PREG_SET_ORDER);
            $columns = [];
            foreach ($fields as $field) {
                $columns[] = '`' . $field[1] . '` ' . ($field[1] === $table . '_id' ? 'INTEGER PRIMARY KEY' : (stripos($field[2], 'int') !== false ? 'INTEGER DEFAULT 0' : "TEXT DEFAULT ''"));
            }
            \think\Db::execute('CREATE TABLE mac_' . $table . ' (' . implode(',', $columns) . ')');
        }
        $siteConfig = include $repo . 'application/extra/maccms.php';
        // The preceding backup tests used a deliberately smaller video schema.
        (new \ReflectionProperty(\think\db\Query::class, 'info'))->setValue(null, []);
        $siteConfig['app']['cache_flag'] = 'regression';
        $siteConfig['collect']['vod']['inrule'] = ',a';
        $siteConfig['collect']['vod']['uprule'] = ',a,d';
        \think\Config::set('maccms', $siteConfig);
        $GLOBALS['config'] = $siteConfig;
        \think\Request::instance()->module('admin');
        \think\Config::set('vodplayer', ['fixture' => ['status' => 1]]);
        \think\Config::set('voddowner', []);
        \think\Cache::set('regression_type_list', [1 => ['type_pid' => 0]]);
        \think\Lang::load($repo . 'application/lang/zh-cn.php');
        $param = ['sync_pic_opt' => 2, 'filter' => 0, 'opt' => 0];
        $row = ['type_id' => 1, 'vod_name' => 'Sparse test', 'vod_play_from' => 'fixture', 'vod_play_url' => 'Episode$https://example.com/test.m3u8', 'vod_remarks' => 'old'];
        set_error_handler(function ($severity, $message) {
            if ($severity === E_WARNING) { throw new \RuntimeException($message); }
            return false;
        });
        try {
            check($collect->vod_data($param, ['data' => [$row]], 0)['code'] === 1, 'Sparse video insert failed');
            $row['vod_remarks'] = 'updated';
            $result = $collect->vod_data($param, ['data' => [$row]], 0);
            check(($result['action'] ?? '') === 'updated', 'Metadata update was reported as skipped');
            check(\think\Db::name('vod')->value('vod_remarks') === 'updated', 'Metadata update was not saved');
            check(($collect->vod_data($param, ['data' => [$row]], 0)['action'] ?? '') !== 'updated', 'Unchanged video reported as updated');
            $article = ['type_id' => 1, 'art_name' => 'Sparse article', 'art_content' => 'Page one$$$Page two'];
            check($collect->art_data($param, ['data' => [$article]], 0)['code'] === 1, 'Sparse multipage article insert failed');
            check(\think\Db::name('art')->value('art_title') === '$$$', 'Missing article page titles were not aligned');
        } finally { restore_error_handler(); }
        // Different titles must not merge under legacy actor/director, empty, or zero-ID rules.
        foreach ([',f,g', '', ',h', ',f,g'] as $i => $rule) {
            $siteConfig['collect']['vod']['inrule'] = $rule;
            \think\Config::set('maccms', $siteConfig);
            $a = ['type_id' => 1, 'vod_name' => 'Identity A ' . $i, 'vod_play_from' => 'fixture', 'vod_play_url' => 'Episode$https://example.com/a.m3u8', 'vod_actor' => '', 'vod_director' => '', 'vod_douban_id' => 0];
            if ($i === 3) { $a['vod_actor'] = 'Shared actor'; $a['vod_director'] = 'Shared director'; }
            $b = array_merge($a, ['vod_name' => 'Identity B ' . $i, 'vod_play_url' => 'Episode$https://example.com/b.m3u8']);
            $before = \think\Db::name('vod')->count();
            $collect->vod_data($param, ['data' => [$a]], 0);
            $collect->vod_data($param, ['data' => [$b]], 0);
            check(\think\Db::name('vod')->count() === $before + 2, 'Unrelated titles merged under rule ' . $rule);
            check(\think\Db::name('vod')->where('vod_name', $a['vod_name'])->value('vod_play_url') === $a['vod_play_url'], 'Another title overwrote playback URLs');
            $collect->vod_data($param, ['data' => [$b]], 0);
            check(\think\Db::name('vod')->count() === $before + 2, 'Same-title repeat created duplicate');
        }
        $siteConfig['collect']['vod']['inrule'] = ',h';
        \think\Config::set('maccms', $siteConfig);
        $before = \think\Db::name('vod')->count();
        $a['vod_name'] = 'Douban original'; $a['vod_douban_id'] = 1234567;
        $collect->vod_data($param, ['data' => [$a]], 0);
        $b = array_merge($a, ['vod_name' => 'Douban alternate', 'vod_play_url' => 'Episode$https://example.com/updated.m3u8']);
        $collect->vod_data($param, ['data' => [$b]], 0);
        check(\think\Db::name('vod')->count() === $before + 1, 'Positive Douban identity no longer matches alternate title');
        check(\think\Db::name('vod')->where('vod_douban_id', 1234567)->value('vod_play_url') === $b['vod_play_url'], 'Positive Douban match failed to update');
        // Keep legacy settings intact while protecting identity and merging real model routes.
        \think\Config::set('vodplayer', ['fixture' => ['status' => 1], 'second' => ['status' => 1]]);
        foreach ([',a,b', ',f,g', ',a', ''] as $i => $rule) {
            $siteConfig['collect']['vod']['inrule'] = $rule;
            \think\Config::set('maccms', $siteConfig);
            $a = ['type_id' => 1, 'vod_name' => 'Cross source ' . $i, 'vod_year' => '2001', 'vod_actor' => 'Actor', 'vod_director' => 'Director', 'vod_douban_id' => 0, 'vod_play_from' => 'fixture', 'vod_play_url' => 'Episode$https://example.com/first.m3u8'];
            $b = array_merge($a, ['vod_play_from' => 'second', 'vod_play_url' => 'Episode$https://example.com/second.m3u8']);
            $before = \think\Db::name('vod')->count();
            $collect->vod_data($param, ['data' => [$a]], 0);
            $collect->vod_data($param, ['data' => [$b]], 0);
            $merged = \think\Db::name('vod')->where('vod_name', $a['vod_name'])->find();
            check(\think\Db::name('vod')->count() === $before + 1 && $merged['vod_play_from'] === 'fixture$$$second', 'Cross-source routes failed under rule ' . $rule);
            check($merged['vod_play_url'] === $a['vod_play_url'] . '$$$' . $b['vod_play_url'], 'Route URL alignment lost');
            $collect->vod_data($param, ['data' => [$b]], 0);
            check(\think\Db::name('vod')->count() === $before + 1, 'Repeated source duplicated title');
            $other = array_merge($a, ['vod_year' => '2020', 'vod_play_url' => 'Episode$https://example.com/remake.m3u8']);
            $collect->vod_data($param, ['data' => [$other]], 0);
            check(\think\Db::name('vod')->count() === $before + 2, 'Same title with different year merged');
            $collect->vod_data($param, ['data' => [$other]], 0);
            check(\think\Db::name('vod')->count() === $before + 2, 'Matching second candidate created duplicate');
            $unknown = array_merge($b, ['vod_year' => '', 'vod_douban_id' => 0, 'vod_play_url' => 'Episode$https://example.com/unknown.m3u8']);
            $result = $collect->vod_data($param, ['data' => [$unknown]], 0);
            check($result['code'] === 1001 && \think\Db::name('vod')->count() === $before + 2, 'Ambiguous title was silently merged or inserted');
            check(\think\Config::get('maccms.collect')['vod']['inrule'] === $rule, 'Legacy settings were overwritten');
        }
        $siteConfig['collect']['vod']['inrule'] = ',a,b';
        \think\Config::set('maccms', $siteConfig);
        foreach (['vod_douban_id' => [100001, 100002], 'vod_director' => ['Director A', 'Director B'], 'type_id' => [1, 2]] as $field => $values) {
            $a['vod_name'] = 'Identity conflict ' . $field; $a[$field] = $values[0];
            $b = array_merge($a, [$field => $values[1], 'vod_play_from' => 'second', 'vod_play_url' => 'Episode$https://example.com/conflict.m3u8']);
            $before = \think\Db::name('vod')->count();
            $collect->vod_data($param, ['data' => [$a]], 0); $collect->vod_data($param, ['data' => [$b]], 0);
            check(\think\Db::name('vod')->count() === $before + 2, 'Conflicting identity merged: ' . $field);
            $a['vod_douban_id'] = 0; $a['vod_director'] = 'Director'; $a['type_id'] = 1;
        }
        // AJAX scope must override unscoped/custom template tags, across items and modules.
        foreach ([[1, 99001], [1, 99002], [2, 99001]] as $i => $scope) {
            \think\Db::name('comment')->insert(['comment_id' => $i + 1, 'comment_mid' => $scope[0], 'comment_rid' => $scope[1], 'comment_pid' => 0, 'comment_status' => 1, 'comment_content' => 'Scoped comment', 'user_id' => 0]);
        }
        $request = \think\Request::instance();
        $request->controller('Comment'); $request->action('ajax');
        foreach ([[1, 99001, 1], [1, 99002, 2], [2, 99001, 3], [1, 99003, 0]] as $scope) {
            $_REQUEST = ['mid' => $scope[0], 'rid' => $scope[1]];
            $result = (new \app\common\model\Comment())->listCacheData(['num' => 10, 'rid' => 99002, 'mid' => 1]);
            check(count($result['list']) === ($scope[2] ? 1 : 0), 'AJAX leaked comments from another item or module');
            if ($scope[2]) { check((int)$result['list'][0]['comment_id'] === $scope[2], 'AJAX selected wrong content comment'); }
        }
        $_REQUEST = []; $request->controller('Index'); $request->action('index');
        check((include $repo . 'application/extra/timming.php') === [], 'New installations must not ship collection tasks');
        $news = \app\common\service\nnzs\NewsSource::normalize(['api' => 'https://news.niuniuzs.com/api.php/provide/art/?ac=list', 'newstype' => [0 => [['type_id' => 1]]], 'list' => [['art_id' => 70059]]]);
        check($news === ['api' => \app\common\service\nnzs\NewsSource::DEFAULT_API], 'Retired news IDs leaked into the new provider');
        try {
            \app\common\service\nnzs\NewsSource::load([]);
            throw new \LogicException('Unconfigured news provider accepted');
        } catch (\RuntimeException $e) { check(strpos($e->getMessage(), '尚未配置') !== false, 'Missing news provider must fail before network requests'); }
        $custom = ['api' => 'https://custom.example/art/', 'type' => [['type_id' => 9]]];
        check(\app\common\service\nnzs\NewsSource::normalize($custom) === $custom, 'Custom news provider was overwritten');
        $metadata = \app\common\service\nnzs\NewsSource::metadata(['code' => 1, 'class' => [
            ['type_id' => 42, 'type_pid' => 0, 'type_name' => '新闻资讯'],
            ['type_id' => 44, 'type_pid' => 42, 'type_name' => '娱乐新闻'],
        ]]);
        check($metadata['newstype'][0][0]['type_id'] === 42 && $metadata['newstype'][42][0]['type_id'] === 44, 'New provider category hierarchy lost');
        check($metadata['type'][1]['type_id'] === 44, 'Category binding uses stale IDs');
        foreach ([[], ['code' => 1, 'class' => []], ['code' => 1, 'class' => ['bad']]] as $invalid) {
            try {
                \app\common\service\nnzs\NewsSource::metadata($invalid);
                throw new \LogicException('Invalid news metadata accepted');
            } catch (\RuntimeException $e) { check(true, 'Invalid news metadata rejected'); }
        }
        echo "PASS: $checks security regression checks\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        $exitCode = 1;
    } finally {
        session_write_close();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($tmp);
    }
    exit($exitCode);
}
