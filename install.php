<?php
/*
'软件名称：苹果CMS 源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/
header('Content-Type:text/html;charset=utf-8');
// 检测PHP环境
if (version_compare(PHP_VERSION, '8.5.12', '<')) {
    http_response_code(503);
    exit('PHP 版本需要 >= 8.5.12，请升级【PHP >= 8.5.12 required】');
}
//超时时间
@ini_set('max_execution_time', '0');
//内存限制 取消内存限制
@ini_set("memory_limit",'-1');
// 定义应用目录
define('ROOT_PATH', __DIR__ . '/');
define('APP_PATH', __DIR__ . '/application/');
define('MAC_COMM', __DIR__.'/application/common/common/');
define('MAC_HOME_COMM', __DIR__.'/application/index/common/');
define('MAC_ADMIN_COMM', __DIR__.'/application/admin/common/');
define('MAC_START_TIME', microtime(true) );
define('BIND_MODULE', 'install');
define('ENTRANCE', 'install');
$in_file = rtrim($_SERVER['SCRIPT_NAME'],'/');
if(substr($in_file,strlen($in_file)-4)!=='.php'){
    $in_file = substr($in_file,0,strpos($in_file,'.php')) .'.php';
}
define('IN_FILE',$in_file);
// 已安装（存在 install.lock）则拒绝重装，显示提示页；不存在则允许安装（与官方行为一致）
if(is_file(__DIR__.'/application/data/install/install.lock')) {
	http_response_code(403);
	echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>系统已安装</title>'
		.'<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#eef2ff,#f8fafc);font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;color:#1e293b}'
		.'.box{background:#fff;max-width:460px;margin:20px;padding:36px 32px;border-radius:16px;box-shadow:0 10px 40px rgba(30,41,59,.12);text-align:center}'
		.'h1{font-size:22px;margin:0 0 12px}p{color:#475569;line-height:1.8;margin:8px 0}code{background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#be123c;word-break:break-all}'
		.'a{display:inline-block;margin:16px 8px 0;padding:10px 22px;border-radius:8px;background:#4f46e5;color:#fff;text-decoration:none}a.g{background:#64748b}</style></head><body><div class="box">'
		.'<h1>系统已安装</h1><p>检测到安装锁文件，安装程序已停用。</p>'
		.'<p>如需重新安装，请先删除<br><code>application/data/install/install.lock</code><br>后刷新本页。</p>'
		.'<p>为安全起见，建议安装完成后删除根目录的 <code>install.php</code>。</p>'
		.'<a href="./">访问首页</a><a class="g" href="./admina.php">进入后台</a></div></body></html>';
	exit;
}

if(!is_writable('./runtime')) {
	echo '请开启[runtime]目录的读写权限【Please turn on the read and write permissions of the [runtime] folder】';
	exit;
}

// 加载框架引导文件
require __DIR__ . '/thinkphp/start.php';
