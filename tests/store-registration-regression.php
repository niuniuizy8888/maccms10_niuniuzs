<?php
// Run: php tests/store-registration-regression.php /path/to/store
// Executes production registration logic with in-memory database stubs only.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$store = $argv[1] ?? '';
if (!is_file($store.'/model/cloud.php')) { fwrite(STDERR,"Pass the store directory\n"); exit(1); }
function store_normalize_site_url($url) { return $url; }
function sanitize_text($v,$n) { return substr($v,0,$n); }
function db_one($sql,$args) { return null; }
function db_exec($sql,$args) { $GLOBALS['saved_domain']=$args[1]; }
function store_now() { return time(); }
function store_day() { return date('Y-m-d'); }
function stats_bump($day,$key) {}
require $store.'/model/cloud.php';
$n=0;
foreach([''=>'https://example.test','api.php'=>'https://example.test','custom_api.php'=>'https://example.test/custom_api.php','API.php'=>'https://example.test/API.php','../api.php'=>null,'api.php/other'=>null,'api.php?x=1'=>null,'custom-api.php'=>null] as $entry=>$expected){
 $GLOBALS['saved_domain']=null;
 [$status,$body]=cloud_macinstall_register(['auth_key'=>str_repeat('a',24),'domian'=>'example.test','http'=>'https','api_entry'=>$entry,'serverip'=>'','siteid'=>'test','sitename'=>'test']);
 if($expected===null ? $status!==400 || $GLOBALS['saved_domain']!==null : $status!==200 || $GLOBALS['saved_domain']!==$expected)throw new RuntimeException('Incorrect API entry handling: '.$entry);
 $n++;
}
if(!preg_match("/'api_entry'\\s*=>\\s*param_str\\('api_entry'/",file_get_contents($store.'/route/macinstall.php')))throw new RuntimeException('Route drops API entry');
echo 'PASS: ',++$n," store registration checks\n";
