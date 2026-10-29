<?php
// Isolated AJAX controller integration checks, using temporary files and SQLite memory only.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$repo = dirname(__DIR__) . '/';
$tmp = sys_get_temp_dir() . '/maccms-ajax-' . bin2hex(random_bytes(8)) . '/';
mkdir($tmp . 'runtime', 0700, true);
define('ROOT_PATH', $repo); define('APP_PATH', $repo . 'application/');
define('RUNTIME_PATH', $tmp . 'runtime/'); define('MAC_PATH', '/');
define('ENTRANCE', 'index');
require $repo . 'thinkphp/base.php'; require $repo . 'thinkphp/helper.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set('database', ['type'=>'sqlite','database'=>':memory:','prefix'=>'mac_','fields_strict'=>false]);
\think\Config::set('session', ['auto_start'=>true,'prefix'=>'ajax-test','path'=>$tmp]);
\think\Session::init();
\think\Config::set('cache', ['type'=>'File','path'=>RUNTIME_PATH . 'cache/']);
$config = include APP_PATH . 'extra/maccms.php';
$config['app']['cache_flag'] = 'ajax-test'; $config['app']['cache_core'] = 0;
$config['site']['site_url'] = 'https://example.test';
$GLOBALS['config'] = $config; \think\Config::set('maccms', $config);
\think\Lang::load(APP_PATH . 'lang/zh-cn.php');
$_SERVER['REMOTE_ADDR'] = '2001:db8::1'; $_SERVER['SCRIPT_NAME'] = '/install.php';
$GLOBALS['http_type'] = 'https://';
\think\Config::set('vodplayer', []); \think\Config::set('voddowner', []);
\think\Cache::set('ajax-test_type_list', [1=>['type_id'=>1,'type_pid'=>0,'type_en'=>'category']]);
\think\Cache::set('ajax-test_group_list', [1=>['group_id'=>1]]);
$n = 0; $exitCode = 0;
function check($value, $message) { global $n; if (!$value) { throw new RuntimeException($message); } $n++; }
function ajax($action, array $params = []) {
    $_GET = $_REQUEST = $params; $_POST = [];
    \think\Request::destroy();
    \think\Request::instance(['get'=>$params])->module('index');
    $a = (new ReflectionClass(\app\index\controller\Ajax::class))->newInstanceWithoutConstructor();
    $a->_param = mac_param_url();
    return $a->$action();
}
function result($response) { return $response->getData(); }
try {
    set_error_handler(function($level, $message) {
        if ($level === E_WARNING) { throw new RuntimeException($message); }
        return false;
    });
    $schema = file_get_contents(APP_PATH . 'install/sql/install.sql');
    $models = [1=>'vod',2=>'art',3=>'topic',8=>'actor',9=>'role',11=>'website'];
    foreach ($models as $prefix) {
        preg_match('/CREATE TABLE `mac_' . $prefix . '` \((.*?)\) ENGINE/s', $schema, $definition);
        preg_match_all('/^\s*`([^`]+)`\s+(\w+)/m', $definition[1], $fields, PREG_SET_ORDER);
        $columns = [];
        foreach ($fields as $field) {
            $columns[] = '`'.$field[1].'` '.($field[1] === $prefix.'_id' ? 'INTEGER PRIMARY KEY' : (stripos($field[2], 'int') !== false ? 'INTEGER DEFAULT 0' : "TEXT DEFAULT ''"));
        }
        \think\Db::execute('CREATE TABLE mac_'.$prefix.' ('.implode(',', $columns).')');
        \think\Db::name($prefix)->insert([$prefix.'_id'=>1,$prefix.'_name'=>'Public sample',$prefix.'_status'=>1,$prefix.'_pic'=>'cover.jpg']);
    }
    \think\Db::name('vod')->where('vod_id',1)->update(['vod_pwd'=>'10','vod_pwd_play'=>'play-secret','vod_pwd_down'=>'download-secret','vod_content'=>'private body','vod_points_play'=>9,'vod_play_url'=>'private source']);
    \think\Db::name('art')->where('art_id',1)->update(['art_pwd'=>'article-secret','art_content'=>'private article body']);
    \think\Db::name('role')->where('role_id',1)->update(['role_rid'=>1]);
    foreach ($models as $mid=>$prefix) {
        $r = result(ajax('data', ['mid'=>$mid,'limit'=>10]));
        $row = reset($r['list']);
        check($r['code'] === 1 && $row[$prefix.'_name'] === 'Public sample' && !empty($row['detail_link']), 'Public list metadata failed: '.$prefix);
        check(!preg_match('/private|secret|"\w+_pwd|"\w+_points|"\w+_content|"\w+_play_url/', json_encode($row)), 'Private fields leaked: '.$prefix);
    }
    for ($i=2;$i<=26;$i++) { \think\Db::name('vod')->insert(['vod_id'=>$i,'vod_name'=>'Public sample '.$i,'vod_status'=>($i===26 ? 0 : 1)]); }
    $r = result(ajax('suggest', ['mid'=>1,'wd'=>'Public','limit'=>999999]));
    check(count($r['list']) === 20 && (int)$r['limit'] === 20 && (int)$r['total'] === 25, 'Suggest bound/status failed');
    check(!in_array(26, array_column($r['list'], 'id')), 'Unpublished suggestion leaked');
    check((int)result(ajax('suggest', ['mid'=>1,'wd'=>'Public','limit'=>5]))['limit'] === 5, 'Normal suggestion limit changed');
    ajax('suggest', ['mid'=>[1],'id'=>[1],'wd'=>['nested'=>['x']],'limit'=>[100],'letter'=>['a']]);
    check(mac_param_url()['id'] === '' && mac_param_url()['mid'] === 0 && mac_param_url()['wd'] === '', 'Non-scalar URL parameter accepted');
    foreach ([-1,11,999999,'1e1','1.5',[],['x']] as $bad) {
        check(result(ajax('score', ['mid'=>1,'id'=>1,'score'=>$bad]))['code'] !== 1, 'Invalid score accepted');
    }
    check((int)\think\Db::name('vod')->where('vod_id',1)->value('vod_score_num') === 0, 'Invalid score wrote database');
    check(result(ajax('score', ['mid'=>1,'id'=>1,'score'=>8]))['data']['score'] === '8.0', 'Normal scoring failed');
    check(result(ajax('score', ['mid'=>1,'id'=>1,'score'=>10]))['code'] !== 1, 'Duplicate score accepted');
    check(result(ajax('score', ['mid'=>1,'id'=>1]))['code'] === 1, 'Read-only score query failed');
    for ($i=0;$i<30;$i++) {
        cookie('vod-score-2', null);
        check(result(ajax('score', ['mid'=>1,'id'=>2,'score'=>6]))['code'] === 1, 'Normal score budget ended early');
    }
    cookie('vod-score-2', null);
    check(result(ajax('score', ['mid'=>1,'id'=>2,'score'=>6]))['code'] === 1003, 'Cookie reset bypassed score rate limit');
    for ($i=0;$i<30;$i++) {
        cookie('vod-digg-2', null);
        check(result(ajax('digg', ['mid'=>1,'id'=>2,'type'=>'up']))['code'] === 1, 'Normal vote budget ended early');
    }
    cookie('vod-digg-2', null);
    check(result(ajax('digg', ['mid'=>1,'id'=>2,'type'=>'up']))['code'] === 1003, 'Cookie reset bypassed vote rate limit');
    check((int)\think\Db::name('vod')->where('vod_id',2)->value('vod_up') === 30, 'Blocked vote wrote database');
    for ($i=0;$i<120;$i++) {
        check(result(ajax('hits', ['mid'=>1,'id'=>2,'type'=>'update']))['code'] === 1, 'Normal hits budget ended early');
    }
    check(result(ajax('hits', ['mid'=>1,'id'=>2,'type'=>'update']))['code'] === 1003, 'Hit flood was not limited');
    check(result(ajax('hits', ['mid'=>1,'id'=>2]))['code'] === 1, 'Hit rate limit blocked reads');
    session('last_pwd', null);
    check(result(ajax('pwd', ['mid'=>1,'id'=>1,'type'=>1,'pwd'=>'1e1']))['code'] === 1012, 'Loose password comparison survived');
    session('last_pwd', null);
    check(result(ajax('pwd', ['mid'=>1,'id'=>1,'type'=>1,'pwd'=>'10']))['code'] === 1, 'Correct numeric-looking password rejected');
    session('last_pwd', null);
    check(result(ajax('pwd', ['mid'=>1,'id'=>1,'type'=>4,'pwd'=>'play-secret']))['code'] === 1, 'Playback password failed');
    session('last_pwd', null);
    check(result(ajax('pwd', ['mid'=>1,'id'=>1,'type'=>5,'pwd'=>'download-secret']))['code'] === 1, 'Download password failed');
    session('last_pwd', null);
    check(result(ajax('pwd', ['mid'=>2,'id'=>1,'type'=>1,'pwd'=>'article-secret']))['code'] === 1, 'Article password failed');
    check(result(ajax('pwd', ['mid'=>1,'id'=>1,'type'=>1,'pwd'=>[]]))['code'] !== 1, 'Password array accepted');
    \think\Db::name('vod')->where('vod_id',2)->update(['vod_pwd'=>'0']);
    session('last_pwd', null);
    check(result(ajax('pwd', ['mid'=>1,'id'=>2,'type'=>1,'pwd'=>'0']))['code'] === 1, 'Zero password rejected');
    for ($i=0;$i<30;$i++) {
        session('last_pwd', null); session('1-1-3', null);
        check(result(ajax('pwd', ['mid'=>1,'id'=>3,'type'=>1,'pwd'=>'wrong']))['code'] === 1012, 'Attempt budget ended early');
    }
    session(null); // A new session must not reset the IP/content budget.
    check(result(ajax('pwd', ['mid'=>1,'id'=>3,'type'=>1,'pwd'=>'wrong']))['code'] === 1003, 'Session reset bypassed password attempt limit');
    $download = ajax('desktop', ['name'=>'本站"名称','url'=>'https://example.test/watch?id=1&next=2']);
    check($download->getCode() === 200 && $download->getData() === "[InternetShortcut]\r\nURL=https://example.test/watch?id=1&next=2\r\n", 'Valid shortcut changed');
    check(strpos($download->getHeader()['Content-Disposition'], 'filename*=UTF-8') !== false, 'UTF-8 attachment filename missing');
    foreach (['https://other.example/','https://example.test.evil.example/','https://example.test/%0aIconIndex=2',"https://example.test/\r\nIconIndex=2",'file:///sample','https://user@example.test/'] as $bad) {
        check(ajax('desktop', ['name'=>'Site','url'=>$bad])->getCode() === 400, 'Invalid shortcut accepted');
    }
    $defaultShortcut = ajax('desktop');
    check($defaultShortcut->getCode() === 200, 'Default shortcut failed');
    check(strpos($defaultShortcut->getHeader()['Content-Disposition'], rawurlencode($config['site']['site_name'])) !== false, 'Default site shortcut name lost');
    foreach ([[], ['type'=>[],'verify'=>'x'], ['type'=>'search','verify'=>[]]] as $bad) {
        check(ajax('verify_check', $bad)['code'] === 1001, 'Invalid captcha check input accepted');
    }
    $_SERVER['HTTP_REFERER'] = 'https://example.test/page';
    foreach (['','other.example','site.example%','site.example/path'] as $domain) {
        check(result(ajax('referer', ['url'=>'https://site.example/path','domain'=>$domain]))['code'] === 1003, 'Invalid referral domain accepted');
    }
    $_SERVER['HTTP_REFERER'] = 'https://example.test.evil.example/';
    check(result(ajax('referer', ['url'=>'https://site.example/','domain'=>'site.example']))['code'] === 1002, 'Referral origin substring accepted');
    check(\app\common\service\AjaxSecurity::refererDomain('https://site.example/path','site.example'), 'Valid referral rejected');
    \think\Db::execute('CREATE TABLE mac_visit (visit_id INTEGER PRIMARY KEY, user_id INTEGER, visit_ip INTEGER, visit_time INTEGER, visit_ly TEXT)');
    \think\Db::name('website')->where('website_id',1)->update(['website_jumpurl'=>'https://site.example.evil.example/']);
    \think\Db::name('website')->insert(['website_id'=>2,'website_name'=>'Referral sample','website_jumpurl'=>'https://site.example/path','website_status'=>1]);
    $_SERVER['HTTP_REFERER'] = 'https://example.test/page';
    check(result(ajax('referer', ['url'=>'https://site.example/path','domain'=>'site.example','type'=>'update']))['code'] === 1, 'Valid referral update failed');
    check((int)\think\Db::name('website')->where('website_id',2)->value('website_referer') === 1, 'Referral selected incorrect website');
    check((int)\think\Db::name('website')->where('website_id',1)->value('website_referer') === 0, 'Referral prefix spoof changed another website');
    $installer = (new ReflectionClass(\app\install\controller\Index::class))->newInstanceWithoutConstructor();
    $checks = (new ReflectionMethod($installer, 'checkFunc'))->invoke($installer);
    check(array_values(array_filter($checks, fn($r)=>$r[0]==='think\\captcha\\Captcha'))[0][2] === 'yes', 'Installed captcha dependency rejected');
    check(lang('install/not_support') !== lang('install/support'), 'Missing dependency labeled supported');
    // Render the dependency hint for a missing class without changing the real vendor directory.
    $view = file_get_contents(APP_PATH . 'install/view/index/step2.html');
    $view = preg_replace('/\{include[^}]+\}/', '', $view);
    ob_start(); (new \think\Template())->display($view, ['data'=>['env'=>[],'dir'=>[],'func'=>[['think\\captcha\\Captcha','不支持','no','类']]]]); $html = ob_get_clean();
    check(strpos($html, 'extend/think-captcha') !== false, 'Missing bundled captcha hint not rendered');
    echo "PASS: $n AJAX and dependency checks\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); $exitCode=1;
} finally {
    restore_error_handler(); session_write_close();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? rmdir($p->getPathname()) : unlink($p->getPathname()); }
    rmdir($tmp);
}
exit($exitCode);
