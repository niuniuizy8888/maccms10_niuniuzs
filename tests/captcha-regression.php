<?php
// No vendor directory or Composer autoloader; no business database or network access.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__) . '/';
$tmp = sys_get_temp_dir() . '/maccms-captcha-' . bin2hex(random_bytes(8)) . '/';
mkdir($tmp . 'runtime', 0700, true);
define('ROOT_PATH', $root); define('APP_PATH', $root . 'application/');
defined('VENDOR_PATH') or define('VENDOR_PATH', $tmp . 'no-vendor/');
define('RUNTIME_PATH', $tmp . 'runtime/');
// A stale optimized map from an older installation must not select a missing vendor file.
file_put_contents(RUNTIME_PATH . 'classmap.php', '<?php return ' . var_export(['think\\captcha\\Captcha'=>$tmp.'missing-class.php'], true) . ';');
require $root . 'thinkphp/base.php'; require $root . 'thinkphp/helper.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set('database', ['type'=>'sqlite','database'=>':memory:','prefix'=>'mac_','fields_strict'=>false]);
\think\Config::set('session', ['auto_start'=>true,'prefix'=>'captcha-test','path'=>$tmp]);
\think\Session::init();
\think\Config::set('captcha', include APP_PATH . 'extra/captcha.php');
$GLOBALS['config'] = include APP_PATH . 'extra/maccms.php';
$GLOBALS['config']['app']['admin_login_verify'] = '1';
\think\Lang::load(APP_PATH . 'lang/zh-cn.php');
$checks = 0; $status = 0;
function check($v, $m) { global $checks; if (!$v) { throw new RuntimeException($m); } $checks++; }
try {
    set_error_handler(function($n, $s) { if ($n === E_WARNING) { throw new RuntimeException($s); } return false; });
    check(!is_dir(VENDOR_PATH), 'Test unexpectedly has Composer dependencies');
    check(class_exists('think\\captcha\\Captcha'), 'Bundled captcha cannot autoload');
    check(str_contains(str_replace('\\','/', (new ReflectionClass(\think\captcha\Captcha::class))->getFileName()), '/extend/think-captcha/'), 'External captcha implementation selected');
    check(function_exists('captcha') && function_exists('captcha_check'), 'Template captcha helpers missing');
    check(captcha_src('sample') === '/index.php/verify/index.html?id=sample', 'Template image URL requires custom routing');
    $captcha = new \think\captcha\Captcha(config('captcha'));
    $auth = new ReflectionMethod($captcha, 'authcode');
    $key = $auth->invoke($captcha, 'ThinkPHP.CN');
    $image = $captcha->entry(); $png = $image->getData();
    check(substr($png,0,8) === "\x89PNG\r\n\x1a\n" && strlen($png)>100, 'Captcha is not a PNG');
    $info = getimagesizefromstring($png);
    check($info && $info[0]>50 && $info[1]>20 && $info['mime']==='image/png', 'Captcha image is invalid');
    check(!empty(\think\Session::get($key,'')['verify_code']), 'Image generation did not store session challenge');
    check($captcha->entry()->getData() !== $png, 'Captcha refresh returned the same image');
    check((new \think\captcha\Captcha(['useZh'=>true]))->entry()->getCode() === 200, 'Bundled Chinese font missing');
    $verify = (new ReflectionClass(\app\index\controller\Verify::class))->newInstanceWithoutConstructor();
    $image = $verify->index();
    check(str_starts_with($image->getHeader()['Content-Type'], 'image/png'), 'Verify endpoint returned wrong content type');
    check(str_contains($image->getHeader()['Cache-Control'], 'no-store'), 'Captcha can be cached');
    $seed = function($time = null) use($auth, $captcha, $key) {
        \think\Session::set($key, ['verify_code'=>$auth->invoke($captcha,'ABCD'),'verify_time'=>$time ?? time()], '');
    };
    $seed(); check(mac_captcha_check('abcd'), 'Correct challenge rejected');
    check(!mac_captcha_check('ABCD'), 'Successful challenge replay accepted');
    $seed(); check(!mac_captcha_check('wrong'), 'Incorrect challenge accepted');
    check(!mac_captcha_check('ABCD'), 'Incorrect attempt did not invalidate challenge');
    $seed(time()-601); check(!mac_captcha_check('ABCD'), 'Expired challenge accepted');
    foreach ([null, [], str_repeat('x',33)] as $invalid) {
        $seed(); check(!mac_captcha_check($invalid), 'Malformed captcha accepted');
        check(!mac_captcha_check('ABCD'), 'Malformed input did not invalidate challenge');
    }
    $registrationConfig = $GLOBALS['config'];
    $registrationConfig['user']['reg_open'] = '1';
    $registrationConfig['user']['reg_verify'] = '0';
    \think\Config::set('maccms', $registrationConfig);
    $registration = ['user_name'=>'captcha-test','user_pwd'=>'ExamplePass1','user_pwd2'=>'ExamplePass2','uid'=>0,'verify'=>'ABCD'];
    $seed();
    check((new \app\common\model\User())->register($registration)['code'] === 1004, 'Disabled captcha blocked password validation');
    check(mac_captcha_check('ABCD'), 'Registration consumed a challenge while verification was disabled');
    $registrationConfig['user']['reg_verify'] = '1';
    \think\Config::set('maccms', $registrationConfig);
    unset($registration['verify']);
    check((new \app\common\model\User())->register($registration)['code'] === 1003, 'Enabled registration captcha accepted missing input');
    preg_match('/CREATE TABLE `mac_admin` \((.*?)\) ENGINE/s', file_get_contents(APP_PATH.'install/sql/install.sql'), $table);
    preg_match_all('/^\s*`([^`]+)`\s+(\w+)/m', $table[1], $fields, PREG_SET_ORDER);
    $columns=[];
    foreach($fields as $f) { $columns[]='`'.$f[1].'` '.($f[1]==='admin_id'?'INTEGER PRIMARY KEY':(stripos($f[2],'int')!==false?'INTEGER DEFAULT 0':"TEXT DEFAULT ''")); }
    \think\Db::execute('CREATE TABLE mac_admin ('.implode(',',$columns).')');
    \think\Db::name('admin')->insert(['admin_id'=>1,'admin_name'=>'captcha-test','admin_status'=>1,'admin_pwd'=>password_hash('test-password',PASSWORD_DEFAULT)]);
    $admin = new \app\common\model\Admin();
    $credentials=['admin_name'=>'captcha-test','admin_pwd'=>'test-password','verify'=>'ABCD'];
    $seed(); check($admin->login(array_merge($credentials,['verify'=>'wrong']))['code']===1002, 'Admin login accepted wrong captcha');
    check($admin->login($credentials)['code']===1002, 'Admin login reused failed captcha');
    $seed(); check($admin->login($credentials)['code']===1, 'Admin login with correct captcha failed');
    check(session('admin_auth')==='1', 'Admin session was not created');
    check($admin->login($credentials)['code']===1002, 'Admin login reused successful captcha');
    $installer=(new ReflectionClass(\app\install\controller\Index::class))->newInstanceWithoutConstructor();
    $items=(new ReflectionMethod($installer,'checkFunc'))->invoke($installer);
    $row=array_values(array_filter($items,fn($r)=>$r[0]==='think\\captcha\\Captcha'))[0];
    check($row[2]==='yes', 'Installation check rejects built-in captcha');
    echo "PASS: $checks captcha checks without Composer\n";
} catch(Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); $status=1;
} finally {
    restore_error_handler(); session_write_close();
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) { $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); }
    rmdir($tmp);
}
exit($status);
