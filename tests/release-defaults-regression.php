<?php
// Release defaults only; never loads a site's database or performs network requests.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__).'/';$n=0;
function check($v,$msg){global $n;if(!$v)throw new RuntimeException($msg);$n++;}
$c=include $root.'application/extra/maccms.php';
foreach(['bind','vodplayer','vodserver','timming'] as $name)check((include $root.'application/extra/'.$name.'.php')===[], $name.' contains installed-site state');
check($c===(include $root.'application/extra/maccms.php.example'),'Config example differs from defaults');
foreach(['db.name','db.user','db.pass','app.cache_flag','app.cron_key','interface.pass','upload.remoteurl','upload.api.ftp.user','upload.api.ftp.pwd','pay.alipay.account','pay.codepay.appid','pay.weixin.appid','pay.zhapay.appid','email.test','email.phpmailer.username','weixin.duijie','weixin.sousuo','weixin.wuziyuanlink','nnzs.auth_key','nnzs.siteid','nnzs.domian','nnzs.installed_at','nnzs.registered_at'] as $path){$v=$c;foreach(explode('.',$path) as $key)$v=$v[$key];check($v==='',$path.' must be empty before installation');}
check($c['collect']['vod']['inrule'] === ',a,b', 'Default collection identity must include title and category');
$db=include $root.'application/database.php';foreach(['hostname','database','username','password'] as $key)check($db[$key]==='', 'Bundled database credentials: '.$key);
check(!is_file($root.'application/install/sql/initdata.sql'),'Demo SQL must not ship');
foreach (['index.html', 'wap_index.html'] as $page) check(!is_file($root.$page), 'Generated homepage must not ship: '.$page);
check(!str_contains(file_get_contents($root.'application/install/view/index/step3.html'),'name="initdata"'),'Demo data option still exposed');
check(!str_contains(file_get_contents($root.'application/install/controller/Index.php'),'post.initdata'),'Demo data injection still accepted');
check(!is_file($root.'static/player/wjwsym3u8.js'),'Test source player script still bundled');
$js=file_get_contents($root.'static/js/playerconfig.js');check(str_contains($js,'player_list={}')&&str_contains($js,'server_list={}'),'Player cache includes sample entries');
echo "PASS: $n release defaults checks\n";
