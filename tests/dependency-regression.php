<?php
// Offline release checks: no root vendor, site database, mail delivery or cloud requests.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__) . '/';
$tmp = sys_get_temp_dir() . '/maccms-deps-' . bin2hex(random_bytes(8)) . '/';
mkdir($tmp, 0700, true);
define('ROOT_PATH', $root); define('APP_PATH', $root . 'application/');
define('VENDOR_PATH', $tmp . 'absent-vendor/'); define('RUNTIME_PATH', $tmp);
require $root . 'thinkphp/base.php'; require $root . 'thinkphp/helper.php';
\think\Loader::addNamespace('app', APP_PATH);
$n = 0; $exitCode = 0;
function check($ok, $message) { global $n; if (!$ok) { throw new RuntimeException($message); } $n++; }
try {
    check(!is_dir(VENDOR_PATH), 'Root Composer dependencies unexpectedly available');
    foreach (['think\\captcha\\Captcha','phpmailer\\src\\PHPMailer','phpmailer\\src\\SMTP',
        'login\\sdk\\QqSDK','login\\sdk\\WeixinSDK','app\\admin\\controller\\Nnzs'] as $class) {
        check(class_exists($class), 'Missing bundled class: '.$class);
    }
    $mail = new \phpmailer\src\PHPMailer(); $mail->isSMTP();
    check(\phpmailer\src\PHPMailer::isValidHost('mail.example.test'), 'Valid SMTP hostname rejected');
    check(!\phpmailer\src\PHPMailer::isValidHost("mail.example.test\r\nInjected"), 'Invalid SMTP hostname accepted');
    $mail->setFrom('sender@example.test'); $mail->addAddress('recipient@example.test');
    $mail->Subject = 'Offline check'; $mail->Body = 'No delivery';
    check($mail->preSend(), 'SMTP message preparation failed');
    check(str_contains($mail->getSentMIMEMessage(), 'Offline check'), 'SMTP MIME generation failed');
    require_once $root . 'extend/qiniu/autoload.php';
    check(class_exists('Qiniu\\Storage\\UploadManager'), 'Qiniu SDK missing');
    $auth = new \Qiniu\Auth('example-key', 'example-secret');
    check(is_string($auth->uploadToken('example-bucket')), 'Qiniu local signing failed');
    require_once $root . 'extend/upyun/vendor/autoload.php';
    foreach (['Upyun\\Config','Upyun\\Upyun','GuzzleHttp\\Client', 'GuzzleHttp\\Promise\\Promise'] as $class) {
        check(class_exists($class), 'Missing bundled cloud dependency: '.$class);
    }
    check(interface_exists('Psr\\Http\\Message\\RequestInterface'), 'PSR interface missing');
    $config = new \Upyun\Config('example-bucket', 'example-operator', 'example-password');
    check(new \Upyun\Upyun($config) instanceof \Upyun\Upyun, 'Upyun SDK initialization failed');
    $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], 'offline'),
    ]));
    $client = new \GuzzleHttp\Client(['handler'=>$handler]);
    check((string)$client->request('GET', 'https://example.test/')->getBody() === 'offline', 'Bundled HTTP dependencies failed with offline handler');
    $controller = (new ReflectionClass(\app\admin\controller\Addon::class))->newInstanceWithoutConstructor();
    $guard = new ReflectionMethod($controller, 'checkAddonRuntime');
    foreach ([false, true] as $ajax) {
        \think\Request::destroy();
        $_SERVER['HTTP_X_REQUESTED_WITH'] = $ajax ? 'XMLHttpRequest' : '';
        try { $guard->invoke($controller); throw new LogicException('Incomplete addon runtime allowed'); }
        catch (\think\exception\HttpResponseException $e) {
            $response = $e->getResponse();
            check($response->getCode() === ($ajax ? 503 : 200), 'Unexpected unavailable-addon response status');
            $data = $response->getData();
            check($ajax ? ($data['code']===1001 && str_contains($data['msg'],'牛牛助手')) : str_contains($data,'插件管理说明'), 'Addon notice missing');
        }
    }
    echo "PASS: $n offline dependency checks\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); $exitCode = 1;
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($tmp);
}
exit($exitCode);
