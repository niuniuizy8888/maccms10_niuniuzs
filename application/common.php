<?php
/*
'软件名称：苹果CMS  源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/

use think\View;

error_reporting(E_ERROR | E_PARSE );

/** Escape text for XML 1.0, including feed titles and query-string separators. */
function mac_xml_escape($value)
{
    $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$value);
    return htmlspecialchars($value ?? '', ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute, XML-safe feed links; site_url is normally configured without a scheme. */
function mac_feed_url($path)
{
    $scheme = ($GLOBALS['http_type'] ?? '') === 'https://' ? 'https://' : 'http://';
    $site = trim((string)($GLOBALS['config']['site']['site_url'] ?? ''));
    if ($site === '') {
        $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
        if (!preg_match('/^[a-z0-9.\-]+$/i', $host)) { $host = 'localhost'; }
        $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
        $site = $host . (in_array($port, [80, 443], true) ? '' : ':' . $port);
    }
    $base = preg_match('~^https?://~i', $site) ? $site : $scheme . ltrim($site, '/');
    $parts = parse_url($base);
    $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $path = (string)$path;
    if (preg_match('~^https?://~i', $path)) { return mac_xml_escape($path); }
    if (strpos($path, '//') === 0) { return mac_xml_escape($scheme . substr($path, 2)); }
    return mac_xml_escape($origin . '/' . ltrim($path, '/'));
}

/**
 * 给列表行补齐所属分类名 type_name（模板 {$vo.type_name} 的取值来源）。
 *
 * 模板普遍直接输出 {$vo.type_name}，但各模型
 * listCacheData/detail 并不提供该字段 → 每行一次 "Undefined array key" 警告
 * （实测单次首页 ~25 条）。这里挂到各模型的返回处补齐，避免改模板也避免逐行 isset。
 *
 * @param mixed $list 模型返回的列表数组（按引用修改）
 * @param string $keyTid 行内分类 id 的键名（默认 type_id）
 * @return mixed 原 $list（便于链式/直接 return）
 */
if (!function_exists('mac_attach_type_name')) {
    function mac_attach_type_name(&$list, $keyTid = 'type_id')
    {
        if (!is_array($list) || empty($list)) {
            return $list;
        }
        $type_list = model('Type')->getCache('type_list');
        if (!is_array($type_list)) {
            return $list;
        }
        foreach ($list as $k => $row) {
            if (!is_array($row) || array_key_exists('type_name', $row)) {
                continue;
            }
            $tid = isset($row[$keyTid]) ? intval($row[$keyTid]) : 0;
            $list[$k]['type_name'] = isset($type_list[$tid]['type_name']) ? (string)$type_list[$tid]['type_name'] : '';
        }
        return $list;
    }
}

/**
 * 取"父分类 id => 子分类行数组"的索引表（请求级缓存，可按 mid 过滤）。
 *
 * @param mixed $mid 内容模型 id（1=视频 2=文章 …）；null/空 = 不按 mid 过滤
 * @return array
 */
if (!function_exists('mac_subtype_map')) {
    function mac_subtype_map($mid = null)
    {
        static $cache = array();
        $key = ($mid === null || $mid === '') ? '*' : (string) intval($mid);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $map = array();
        $list = array();
        if (function_exists('model')) {
            $params = array('num' => '100', 'start' => '0', 'order' => 'asc', 'by' => 'sort');
            if ($key !== '*') {
                $params['mid'] = $key;
            }
            $res = model('Type')->listCacheData(json_encode($params));
            if (is_array($res) && !empty($res['list']) && is_array($res['list'])) {
                $list = $res['list'];
            }
        }
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = isset($row['type_pid']) ? (int) $row['type_pid'] : 0;
            if ($pid > 0) {
                $map[$pid][] = $row;
            }
        }
        $cache[$key] = $map;
        return $map;
    }
}

/**
 * 取某分类的**子分类**列表（模板函数记法专用）。
 *
 * 用途（-2 附带修复）：模板里原先写 `{volist name="__ct[$obj.type_id]"}`
 * 但 TP5 的标签属性**不会解析 PHP 变量**——`$obj.type_id` 被当成字面量，于是
 * ① 子分类导航永远取不到（导航不显示）；② PHP 8 每次触发 "Undefined array key"。
 * 正确写法是用 volist 支持的函数记法：`{volist name=":mac_type_sub_list($obj['type_id'])"}`。
 *
 * @param mixed $typeId 父分类 id
 * @return array 子分类行数组（无子类时返回 []）
 */
if (!function_exists('mac_type_sub_list')) {
    function mac_type_sub_list($typeId)
    {
        $map = mac_subtype_map();
        $id = is_numeric($typeId) ? (int) $typeId : 0;
        return ($id > 0 && isset($map[$id])) ? $map[$id] : array();
    }
}

/**
 * 同 mac_type_sub_list，但按内容模型 mid 过滤（站点地图按 mid=1/2 分别取视频/文章分类）。
 *
 * @param mixed $typeId
 * @param mixed $mid
 * @return array
 */
if (!function_exists('mac_subtype_list')) {
    function mac_subtype_list($typeId, $mid = null)
    {
        $map = mac_subtype_map($mid);
        $id = is_numeric($typeId) ? (int) $typeId : 0;
        return ($id > 0 && isset($map[$id])) ? $map[$id] : array();
    }
}

/**
 * 数组按 key 取值并给默认值（模板里也可用 `:mac_array_get($arr,$k,'')`）。
 */
if (!function_exists('mac_array_get')) {
    function mac_array_get($arr, $key, $default = '')
    {
        return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default;
    }
}

function get_array_unique_id_list($list, $need_sort = false) {
    $list = array_unique($list);
    $list = array_map('intval', $list);
    $list = array_filter($list);
    $list = array_values($list);
    $need_sort && sort($list);
    return $list;
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

// 访问日志记录，根目录创建log目录
function slog($logs)
{
    $ymd = date('Y-m-d-H');
    $now = date('Y-m-d H:i:s');
    $toppath = "./log/$ymd.txt";
    $ts = @fopen($toppath,"a+");
    @fputs($ts, $now .' '. $logs ."\r\n");
    @fclose($ts);
}
// foreach($_GET as $k=>$v){ $getData .= $k.'='.$v.'&'; }
// foreach($_POST as $k=>$v){ $postData .= $k.'='.$v.'&'; }
// foreach($_COOKIE as $k=>$v){ $cookieData .= $k.'='.$v.'&'; }
// $log = $_SERVER['PHP_SELF'] . '---get:' .$getData .'---post:' . $postData .'---'. json_encode($_POST).'---cookie:' . $cookieData
// slog($log)

// 是否IP
function mac_string_is_ip($string) {
    return preg_match('/^(\d{1,3}\.){3}\d{1,3}(:\d{1,5})?$/', $string) === 1;
}

// 应用公共文件
function mac_return($msg,$code=1,$data=''){
    if(is_array($msg)){
        return json_encode($msg);
    }
    else {
        $rs = ['code' => $code, 'msg' => $msg, 'data'=>'' ];
        if(is_array($data)) $rs['data'] = $data;
        return json_encode($rs);
    }
}

function mac_run_statistics()
{
    $t2 = microtime(true) - MAC_START_TIME;
    $size = memory_get_usage();
    $memory = mac_format_size($size);
    unset($unit);
    return 'Processed in: '.round($t2,4).' second(s),&nbsp;' . $memory . ' Mem On.';
}

function mac_format_size($s=0)
{
    if($s==0){ return '0 kb'; }
    $unit=array('b','kb','mb','gb','tb','pb');
    return round($s/pow(1024,($i=floor(log($s,1024)))),2).' '.$unit[$i];
}

function mac_read_file($f)
{
    return @file_get_contents($f);
}

function mac_write_file($f,$c='')
{
    $dir = dirname($f);
    if(!is_dir($dir)){
        mac_mkdirss($dir);
    }
    return @file_put_contents($f, $c);
}

// 安全：默认权限由 0777 收敛为 0755（上传目录无需其他用户可写）
function mac_mkdirss($path,$mode=0755)
{
    if (!is_dir(dirname($path))){
        mac_mkdirss(dirname($path));
    }
    if(!file_exists($path)){
        return mkdir($path,$mode);
    }
    return true;
}

function mac_rmdirs($dirname, $withself = true)
{
    if (!is_dir($dirname))
        return false;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo)
    {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    if ($withself)
    {
        @rmdir($dirname);
    }
    return true;
}

function mac_arr2file($f,$arr='')
{
    if(is_array($arr)){
        $con = var_export($arr,true);
    } else{
        $con = $arr;
    }
    $con = "<?php\nreturn $con;";
    // 先写临时文件再 rename：避免并发请求 include 到半截配置（解析失败甚至被读成空数组再写回）
    $dir = dirname($f);
    if (!is_dir($dir)) {
        mac_mkdirss($dir);
    }
    $tmp = $dir . '/.' . basename($f) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $con) === strlen($con) && @rename($tmp, $f)) {
        @chmod($f, 0664);
    } else {
        @unlink($tmp);
        mac_write_file($f, $con);   // 目录不可写时退回原地写
    }
    // opcache清理以实时生效配置
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($f, true);
    }
}

/**
 * 生成/刷新 static/js/playerconfig.js —— 前端 MacPlayerConfig 的唯一产物。
 *
 * 新增：
 * 这份文件原由**两处**分别写两个区（admin/controller/System.php::configplay 写「参数区」
 * admin/controller/Base.php::_cache_clear 写「缓存区」），且都用
 * `str_replace(mac_get_body(...))` 定位 —— 标记找不到时 mac_get_body 返回 ''
 * 替换会静默失败甚至把文件写坏；两处路径还都依赖 CWD 的 './static/...'。
 *
 * 本函数：① 两个区一次写全，永远自洽；② 四个标记缺一就**直接返回 false**，绝不破坏原文件
 * ③ 先写临时文件再 rename（避免前端读到半截文件）；④ 参数区做键/类型归一。
 *
 * @param array|null $play play 配置数组；null = 取当前 config('maccms.play')
 * @return bool 是否写入成功
 */
function mac_playerconfig_write($play = null)
{
    $path = ROOT_PATH . 'static/js/playerconfig.js';
    if (!is_file($path)) {
        return false;
    }
    $fc = @file_get_contents($path);
    if ($fc === false || $fc === '') {
        return false;
    }

    $startM = '//参数开始';
    $endM   = '//参数结束';
    $startC = '//缓存开始';
    $endC   = '//缓存结束';
    foreach ([$startM, $endM, $startC, $endC] as $marker) {
        if (strpos($fc, $marker) === false) {
            // 结构已损坏：宁可不写，也不要像旧实现那样把文件写坏
            return false;
        }
    }

    if (!is_array($play)) {
        $play = config('maccms.play');
        if (!is_array($play)) {
            $play = [];
        }
    }
    // 只保留已知键、值统一成字符串（static/js/player.js 全程按字符串比较 ps/second 等）
    $playKeys = ['width', 'height', 'widthmob', 'heightmob', 'widthpop', 'heightpop', 'second',
                 'prestrain', 'buffer', 'parse', 'autofull', 'showtop', 'showlist', 'flag', 'colors'];
    $playOut = [];
    foreach ($playKeys as $k) {
        $playOut[$k] = (isset($play[$k]) && is_scalar($play[$k])) ? (string) $play[$k] : '';
    }

    // 列表区：与 admin/controller/Base.php::_cache_clear 同一来源（vodplayer/voddowner/vodserver 配置）
    $pick = function ($src, $keys) {
        $out = [];
        if (is_array($src)) {
            foreach ($src as $k => $v) {
                if (!is_array($v)) {
                    continue;
                }
                $row = [];
                foreach ($keys as $kk) {
                    $row[$kk] = isset($v[$kk]) ? (string) $v[$kk] : '';
                }
                $out[$k] = $row;
            }
        }
        return $out;
    };
    $lists = 'MacPlayerConfig.player_list=' . json_encode($pick(config('vodplayer'), ['show', 'des', 'ps', 'parse']))
           . ',MacPlayerConfig.downer_list=' . json_encode($pick(config('voddowner'), ['show', 'des', 'ps', 'parse']))
           . ',MacPlayerConfig.server_list=' . json_encode($pick(config('vodserver'), ['show', 'des'])) . ';';

    // 逐区替换（替换后缓存区偏移会变，所以每步重新定位）
    $posM1 = strpos($fc, $startM);
    $posM2 = strpos($fc, $endM);
    if ($posM2 < $posM1) {
        return false;
    }
    $new = substr($fc, 0, $posM1 + strlen($startM))
         . "\r\nMacPlayerConfig=" . json_encode($playOut) . ";\r\n"
         . substr($fc, $posM2);

    $posC1 = strpos($new, $startC);
    $posC2 = strpos($new, $endC);
    if ($posC1 === false || $posC2 === false || $posC2 < $posC1) {
        return false;
    }
    $new = substr($new, 0, $posC1 + strlen($startC))
         . "\r\n" . $lists . "\r\n"
         . substr($new, $posC2);

    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $new) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0644);
    return true;
}

function mac_replace_text($txt,$type=1)
{
    $txt = (string)$txt;
    if($type==1){
        return str_replace('#',Chr(13),$txt);
    }
    return str_replace(chr(13),'#',str_replace(chr(10),'',$txt));
}

function mac_compress_html($s){
    $s = str_replace(array("\r\n","\n","\t"), array('','','') , $s);
    $pattern = array (
        "/> *([^ ]*) *</",
        "/[\s]+/",
        "/<!--[\\w\\W\r\\n]*?-->/",
        // "/\" /",
        "/ \"/",
        "'/\*[^*]*\*/'"
    );
    $replace = array (
        ">\\1<",
        " ",
        "",
        // "\"",
        "\"",
        ""
    );
    return preg_replace($pattern, $replace, $s);
}

function mac_build_regx($regstr,$regopt)
{
    return '/'.str_replace([
        '/',
        '$',
        '+',
        '-',
        '{',
    ],[
        '\/',
        '\$',
        '\+',
        '\-',
        '\{',
    ],$regstr).'/'.$regopt;
}

function mac_reg_replace($str,$rule,$value)
{
    $res='';
    $rule = mac_build_regx($rule,"is");
    if (!empty($str)){
        $res = preg_replace($rule,$value,$str);
    }
    return $res;
}

function mac_reg_match($str,$rule)
{
    $res='';
    $rule = mac_build_regx($rule,"is");
    preg_match_all($rule,$str,$mc);
    $mfv=$mc[1];
    foreach($mfv as $f=>$v){
        $res = trim(preg_replace("/[ \r\n\t\f]{1,}/"," ",$v));
        break;
    }
    unset($mc);
    return $res;
}

// 安全：以下函数原先把外部传入值直接拼进 <script> 字符串，构成 JS 注入面
// （典型来源：$_SERVER['HTTP_REFERER']、跳转 url 参数）。统一改为 json_encode（JS 字符串上下文）
// + htmlspecialchars（HTML 属性上下文）。
function mac_redirect($url,$obj='')
{
    $js_url = json_encode((string)$url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<script>'.$obj.'location.href=' .$js_url .';</script>';
    exit;
}

function mac_alert($str)
{
    $js_str = json_encode((string)$str, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<script>alert(' .$js_str. ');history.go(-1);</script>';
}

function mac_alert_url($str,$url)
{
    $js_str = json_encode((string)$str, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $js_url = json_encode((string)$url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<script>alert(' .$js_str. ');location.href=' .$js_url .';</script>';
}

function mac_jump($url,$sec=0)
{
    $js_url = json_encode((string)$url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html_url = htmlspecialchars((string)$url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<script>setTimeout(function (){location.href='.$js_url.';},'.(intval($sec)*1000).');</script><span>'.lang('pause').''.$sec.''.lang('continue_in_second').'  >>>  </span><a href="'.$html_url.'" >'.lang('browser_jump').'</a><br>';
}

function mac_echo($str)
{
    echo $str.'<br>';
    ob_flush();flush();
}

function mac_day($t,$f='',$c='#FF0000')
{
    if(empty($t)) { return ''; }
    if(is_numeric($t)){
        $t = date('Y-m-d H:i:s',$t);
    }
    $now = date('Y-m-d',time());
    if($f=='color' && strpos(','.$t,$now)>0){
        return '<font color="' .$c. '">' .$t. '</font>';
    }
    return  $t;
}

function mac_friend_date($time)
{
    if (!$time)
        return false;
    $fdate = '';
    $d = time() - intval($time);
    $ld = $time - mktime(0, 0, 0, 0, 0, date('Y')); //得出年
    $md = $time - mktime(0, 0, 0, date('m'), 0, date('Y')); //得出月
    $byd = $time - mktime(0, 0, 0, date('m'), date('d') - 2, date('Y')); //前天
    $yd = $time - mktime(0, 0, 0, date('m'), date('d') - 1, date('Y')); //昨天
    $dd = $time - mktime(0, 0, 0, date('m'), date('d'), date('Y')); //今天
    $td = $time - mktime(0, 0, 0, date('m'), date('d') + 1, date('Y')); //明天
    $atd = $time - mktime(0, 0, 0, date('m'), date('d') + 2, date('Y')); //后天
    if ($d == 0) {
        $fdate = lang('just');
    } else {
        switch ($d) {
            case $d < $atd:
                $fdate = date('Y'.lang('year').'m'.lang('month').'d'.lang('day'), $time);
                break;
            case $d < $td:
                $fdate = lang('day_after_tomorrow') . date('H:i', $time);
                break;
            case $d < 0:
                $fdate = lang('tomorrow') . date('H:i', $time);
                break;
            case $d < 60:
                $fdate = $d . lang('seconds_ago');
                break;
            case $d < 3600:
                $fdate = floor($d / 60) . lang('minutes_ago');
                break;
            case $d < $dd:
                $fdate = floor($d / 3600) . lang('hours_ago');
                break;
            case $d < $yd:
                $fdate = lang('yesterday') . date('H:i', $time);
                break;
            case $d < $byd:
                $fdate = lang('day_before_yesterday') . date('H:i', $time);
                break;
            case $d < $md:
                $fdate = date('m'.lang('month').'d'.lang('day').' H:i', $time);
                break;
            case $d < $ld:
                $fdate = date('m'.lang('month').'d'.lang('day'), $time);
                break;
            default:
                $fdate = date('Y'.lang('year').'m'.lang('month').'d'.lang('day'), $time);
                break;
        }
    }
    return $fdate;
}

function mac_get_time_span($sn)
{
    $lastTime = session($sn);

    if(empty($lastTime)){
        $lastTime= "1228348800";
    }
    $res = time() - intval($lastTime);
    session($sn,time());
    return $res;
}

function mac_get_rndstr($length=32,$f='')
{
    $pattern = "234567890ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    if($f=='num'){
        $pattern = '1234567890';
    }
    elseif($f=='letter'){
        $pattern = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    }
    $len = strlen($pattern) -1;
    $res='';
    for($i=0; $i<$length; $i++){
        $res .= $pattern[mt_rand(0,$len)];
    }
    // 开头为0的随机替换为1~9，优化导出格式问题
    if (str_starts_with($res, '0')) {
        $res = mt_rand(1, 9) . substr($res, 1);
    }
    return $res;
}

function mac_convert_encoding($str,$nfate,$ofate){
    if ($ofate=="UTF-8"){ return $str; }
    if ($ofate=="GB2312"){ $ofate="GBK"; }

    if(function_exists("mb_convert_encoding")){
        $str=mb_convert_encoding($str,$nfate,$ofate);
    }
    else{
        $ofate.="//IGNORE";
        $str=iconv($nfate ,$ofate ,$str);
    }
    return $str;
}

function mac_get_refer()
{
    return trim(urldecode((string)($_SERVER["HTTP_REFERER"] ?? '')));
}

function mac_extends_list($flag)
{
    $path = './application/common/extend/'.$flag;
    $file_list = glob($path . '/*.php',GLOB_NOSORT );
    $res=[];
    $res['ext_list'] = [];
    $res['ext_html'] = '';
    foreach($file_list as $k=>$v) {
        $cl = str_replace([$path . '/', '.php'], '', $v);
        $cp = 'app\\common\\extend\\'.$flag.'\\' . $cl;
        if (class_exists($cp)) {
            $c = new $cp;
            $res['ext_list'][$cl] = $c->name;
            if(file_exists( './application/admin/view/extend/'.$flag.'/'.strtolower($cl) .'.html')) {
                $res['ext_html'] .= View::instance()->fetch('admin@extend/'.$flag.'/' . strtolower($cl));
            }
        }
    }
    return $res;
}

function mac_send_sms($to,$code,$type_flag,$type_des,$msg)
{
    if(empty($GLOBALS['config']['sms']['type'])){
        return ['code'=>9005,'msg'=> lang('sms_not_config')];
    }
    $pattern = "/^1[345789][0-9]{9}$/";
    if(!preg_match($pattern,$to)){
        return ['code'=>999,'msg'=>lang('phone_format_err')];
    }
    if(empty($code)){
        return ['code'=>998,'msg'=>lang('title_not_empty')];
    }
    if(empty($type_flag)){
        return ['code'=>997,'msg'=>lang('tpl_not')];
    }


    $cp = 'app\\common\\extend\\sms\\' . ucfirst($GLOBALS['config']['sms']['type']);
    if (class_exists($cp)) {
        $c = new $cp;
        return $c->submit($to,$code,$type_flag,$type_des,$msg);
    }
    else{
        return ['code'=>991,'msg'=>lang('sms_not')];
    }
}

function mac_send_mail($to,$title,$body,$conf=[])
{
    if(empty($GLOBALS['config']['email']['type'])){
        return ['code'=>9005,'msg'=>lang('email_not_config')];
    }
    $pattern = '/\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*/';
    if(!preg_match( $pattern, $to)){
        return ['code'=>999,'msg'=>lang('email_format_err')];
    }
    if(empty($title)){
        return ['code'=>998,'msg'=>lang('title_not_empty')];
    }
    if(empty($body)){
        return ['code'=>997,'msg'=>lang('body_not_empty')];
    }

    $cp = 'app\\common\\extend\\email\\' . ucfirst($GLOBALS['config']['email']['type']);
    if (class_exists($cp)) {
        $c = new $cp;
        return $c->submit($to,$title,$body,$conf);
    }
    else{
        return ['code'=>991,'msg'=>lang('email_not')];
    }
}

function mac_check_back_link($url)
{
    $res=[];
    $res['code'] = 0;
    $res['msg'] = lang('param_err');

    if(empty($url)){
        return json($res);
    }

    $site_url = $GLOBALS['config']['site']['site_url'];
    $site_wapurl = $GLOBALS['config']['site']['site_wapurl'];
    // 安全：不跟随重定向，避免"校验过的公网地址 302 到内网"绕过 SSRF 防护。
    $html = mac_curl_get($url, array(), '', true);
    $msg = '';
    $code = 1;

    $ok = lang('back_link').lang('normal');
    $err = lang('back_link').lang('abnormal');

    $msg .= '['.$site_url.']';
    if(strpos($html,$site_url)!==false){
        $code=1;
        $msg .=$ok;
    }
    else{
        $code=101;
        $msg .=$err;
    }

    $msg .= '，['.$site_wapurl.']';
    if(strpos($html,$site_wapurl)!==false){
        $code =1;
        $msg .=$ok;
    }
    else{
        $code=101;
        $msg .=$err;
    }
    $res['code'] = $code;
    $res['msg'] = $msg;

    return $res;
}

function mac_list_to_tree($list, $pk='id',$pid = 'pid',$child = 'child',$root=0)
{
    $tree = array();
    if(is_array($list)) {
        $refer = array();
        foreach ($list as $key => $data) {
            $refer[$data[$pk]] =& $list[$key];
        }

        foreach ($list as $key => $data) {
            $parentId = $data[$pid];

            if ($root == $parentId) {
                $tree[] =& $list[$key];

            }else{
                if (isset($refer[$parentId])) {
                    $parent =& $refer[$parentId];
                    $parent[$child][] =& $list[$key];
                }
            }
        }
    }
    return $tree;
}

function mac_str_correct($str,$from,$to)
{
    return str_replace($from,$to,(string)$str);
}

function mac_buildregx($regstr,$regopt)
{
    return '/'.str_replace('/','\/',$regstr).'/'.$regopt;
}

function mac_em_replace($s)
{
    return preg_replace("/\[em:(\d{1,})?\]/","<img src=\"". MAC_PATH ."static/images/face/$1.gif\" border=0/>",$s);
}

function mac_page_param($record_total, $page_size, $page_current, $page_url,$page_half=5)
{
    $page_param = array();
    $page_num = array();
    $page_size = max(1, intval($page_size));
    $page_current = max(1, intval($page_current));

    if ($record_total == 0) {
        return [
            'record_total' => 0,
            'page_current' => 1,
            'page_total' => 1,
            'page_sp' => MAC_PAGE_SP,
            'page_prev' => 1,
            'page_next' => 1,
            'page_num' => [],
            'page_url' => $page_url,
        ];
    }
    if(empty($page_half)){
        $page_half=5;
    }

    $page_param['record_total'] = $record_total;
    $page_param['page_current'] = $page_current;

    $page_total = ceil($record_total / $page_size);
    $page_param['page_total'] = $page_total;
    $page_param['page_sp'] = MAC_PAGE_SP;

    $page_prev = $page_current - 1;
    if ($page_prev <= 0) {
        $page_prev = 1;
    }
    $page_next = $page_current + 1;
    if ($page_next > $page_total) {
        $page_next = $page_total;
    }
    $page_param['page_prev'] = $page_prev;
    $page_param['page_next'] = $page_next;

    if ($page_total <= $page_half) {
        for ($i = 1; $i <= $page_total; $i++) {
            $page_num[$i] = $i;
        }
    } else {
        $page_num_left = floor($page_half / 2);
        $page_num_right = $page_total - $page_half;

        if ($page_current <= $page_num_left) {
            for ($i = 1; $i <= $page_half; $i++) {
                $page_num[$i] = $i;
            }
        } elseif ($page_current > $page_num_right) {
            for ($i = ($page_num_right + 0); $i <= $page_total; $i++) {
                $page_num[$i] = $i;
            }
        } else {
            for ($i = ($page_current - $page_num_left); $i <= ($page_current + $page_num_left); $i++) {
                $page_num[$i] = $i;
            }
        }
    }
    $page_param['page_num'] = $page_num;
    $page_param['page_url'] = $page_url;

    return $page_param;
}

/**
 * 出站 HTTPS 是否跳过证书校验（按主机名白名单）。
 *
 * 核心 mac_curl_get/mac_curl_post 原先固定
 * `CURLOPT_SSL_VERIFYPEER=0`（get 侧连 `VERIFYHOST=1`），等于**所有** HTTPS 出网
 * （支付下单、短信、推送、采集、远程图片）都可被中间人替换内容。插件侧早已修为
 * VERIFYPEER=1/VERIFYHOST=2，核心函数却仍是 0。
 *
 * 现改为**默认校验证书**；只有显式列入 app.curl_insecure_hosts（逗号分隔，支持
 * 主机名与 *.后缀）的站点才豁免 —— 用于确实使用自签/过期证书的老采集源
 * 属"按域名降级"而非全局关闭。
 *
 * @param string $url
 * @return bool true = 跳过校验（不安全，仅白名单主机）
 */
if (!function_exists('mac_curl_insecure_host')) {
    function mac_curl_insecure_host($url)
    {
        $list = isset($GLOBALS['config']['app']['curl_insecure_hosts'])
            ? trim((string) $GLOBALS['config']['app']['curl_insecure_hosts']) : '';
        if ($list === '') {
            return false;
        }
        $parts = @parse_url((string) $url);
        $host = isset($parts['host']) ? strtolower(trim($parts['host'], '[]')) : '';
        if ($host === '') {
            return false;
        }
        foreach (explode(',', $list) as $item) {
            $item = strtolower(trim($item));
            if ($item === '') {
                continue;
            }
            if ($item[0] === '*') {
                $suffix = ltrim($item, '*');
                if ($suffix !== '' && substr($host, -strlen($suffix)) === $suffix) {
                    return true;
                }
                continue;
            }
            if ($host === $item) {
                return true;
            }
        }
        return false;
    }
}

// CurlPOST数据提交-----------------------------------------
function mac_curl_post($url,$data,$heads=array(),$cookie='')
{
    $ch = @curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.101 Safari/537.36');
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    // 安全：限制协议为 http/https，防止 file/gopher 等协议滥用
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLINFO_CONTENT_LENGTH_UPLOAD,strlen($data));
    curl_setopt($ch, CURLOPT_HEADER,0);
    curl_setopt($ch, CURLOPT_REFERER, $url);
    // 安全：默认校验证书；仅 app.curl_insecure_hosts 白名单主机豁免
    $insecure = mac_curl_insecure_host($url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $insecure ? 0 : 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);

    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    if(!empty($cookie)){
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    if(count($heads)>0){
        curl_setopt ($ch, CURLOPT_HTTPHEADER , $heads );
    }
    $response = @curl_exec($ch);
    if(curl_errno($ch)){//出错则显示错误信息
        // print curl_error($ch)
    }
    if (PHP_VERSION_ID < 80000) { curl_close($ch); } // PHP 8.5 起 curl_close() 弃用（PHP 8 起为空操作） //关闭curl链接
    return $response;//显示返回信息
}
// CurlPOST数据提交-----------------------------------------
function mac_curl_get($url,$heads=array(),$cookie='',$no_redirect=false)
{
    if (!mac_is_http_url($url)) { return false; } // 安全：仅允许 http/https
    $ch = @curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.101 Safari/537.36');

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    // 安全：限制协议为 http/https，防止 file/gopher 等协议滥用
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    // 安全：SSRF 场景传 $no_redirect=true，避免公网地址 302 到内网
    if ($no_redirect) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
    }
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HEADER,0);
    curl_setopt($ch, CURLOPT_REFERER, $url);
    curl_setopt($ch, CURLOPT_POST, 0);
    // 安全：默认严格校验证书；仅 curl_insecure_hosts 白名单主机降级
    $insecure = mac_curl_insecure_host($url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $insecure ? 0 : 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);
    if(!empty($cookie)){
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    if(count($heads)>0){
        curl_setopt ($ch, CURLOPT_HTTPHEADER , $heads );
    }
    $response = @curl_exec($ch);
    if(curl_errno($ch)){//出错则显示错误信息
        // print curl_error($ch);die
    }
    if (PHP_VERSION_ID < 80000) { curl_close($ch); } // PHP 8.5 起 curl_close() 弃用（PHP 8 起为空操作） //关闭curl链接
    return $response;//显示返回信息
}


function mac_substring($str, $lenth, $start=0)
{
    $len = strlen($str);
    $r = array();
    $n = 0;
    $m = 0;

    for($i=0;$i<$len;$i++){
        $x = substr($str, $i, 1);
        $a = base_convert(ord($x), 10, 2);
        $a = substr( '00000000 '.$a, -8);

        if ($n < $start){
            if (substr($a, 0, 1) == 0) {
            }
            else if (substr($a, 0, 3) == 110) {
                $i += 1;
            }
            else if (substr($a, 0, 4) == 1110) {
                $i += 2;
            }
            $n++;
        }
        else{
            if (substr($a, 0, 1) == 0) {
                $r[] = substr($str, $i, 1);
            }else if (substr($a, 0, 3) == 110) {
                $r[] = substr($str, $i, 2);
                $i += 1;
            }else if (substr($a, 0, 4) == 1110) {
                $r[] = substr($str, $i, 3);
                $i += 2;
            }else{
                $r[] = ' ';
            }
            if (++$m >= $lenth){
                break;
            }
        }
    }
    return  join('',$r);
}


function mac_array2xml($arr,$level=1)
{
    $s = $level == 1 ? "<xml>" : '';
    foreach($arr as $tagname => $value) {
        if (is_numeric($tagname)) {
            $tagname = $value['TagName'];
            unset($value['TagName']);
        }
        if(!is_array($value)) {
            $s .= "<{$tagname}>".(!is_numeric($value) ? '<![CDATA[' : '').$value.(!is_numeric($value) ? ']]>' : '')."</{$tagname}>";
        } else {
            $s .= "<{$tagname}>" . mac_array2xml($value, $level + 1)."</{$tagname}>";
        }
    }
    $s = preg_replace("/([\x01-\x08\x0b-\x0c\x0e-\x1f])+/", ' ', $s);
    return $level == 1 ? $s."</xml>" : $s;
}


function mac_xml2array($xml)
{
    libxml_disable_entity_loader(true);
    $result= json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    return $result;
}

function mac_array_rekey($arr,$key)
{
    $list = [];
    foreach($arr as $k=>$v){
        $list[$v[$key]] = $v;
    }
    return $list;
}

function mac_array_filter($arr,$str)
{
    if(!is_array($arr)){
        $arr = explode(',',$arr);
    }
    $arr = array_filter($arr);
    if(empty($arr)){
        return false;
    }
    // 方式一
    $new_str = str_replace($arr,'*',$str);
    // $badword1 = array_combine($arr,array_fill(0,count($arr),'*'))
    // $new_str = strtr($str, $badword1)
    return $new_str != $str;
}

function mac_parse_sql($sql='',$limit=0,$prefix=[])
{
    // 被替换的前缀
    $from = '';
    // 要替换的前缀
    $to = '';

    // 替换表前缀
    if (!empty($prefix)) {
        $to   = current($prefix);
        $from = current(array_flip($prefix));
    }

    if ($sql != '') {
        // 纯sql内容
        $pure_sql = [];

        // 多行注释标记
        $comment = false;

        // 按行分割，兼容多个平台
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $sql = explode("\n", trim($sql));
        // 作者水印/署名串（由 base64 改为明文，解码结果逐字节一致；本变量全站无调用点）
        $cnm = 'a版权所有magicblack，源码https://github.com/magicblack';
        // 循环处理每一行
        foreach ($sql as $key => $line) {
            // 跳过空行
            if ($line == '') {
                continue;
            }

            // 跳过以#或者--开头的单行注释
            if (preg_match("/^(#|--)/", $line)) {
                continue;
            }

            // 跳过以/**/包裹起来的单行注释
            if (preg_match("/^\/\*(.*?)\*\//", $line)) {
                continue;
            }

            // 多行注释开始
            if (substr($line, 0, 2) == '/*') {
                $comment = true;
                continue;
            }

            // 多行注释结束
            if (substr($line, -2) == '*/') {
                $comment = false;
                continue;
            }

            // 多行注释没有结束，继续跳过
            if ($comment) {
                continue;
            }

            // 替换表前缀
            if ($from != '') {
                $line = str_replace('`'.$from, '`'.$to, $line);
            }
            if ($line == 'BEGIN;' || $line =='COMMIT;') {
                continue;
            }
            // sql语句
            array_push($pure_sql, $line);
        }

        // 只返回一条语句
        if ($limit == 1) {
            return implode("",$pure_sql);
        }


        // 以数组形式返回sql语句
        $pure_sql = implode("\n",$pure_sql);
        $pure_sql = explode(";\n", $pure_sql);
        return $pure_sql;
    } else {
        return $limit == 1 ? '' : [];
    }
}

function mac_interface_type()
{
    $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'interface_type';
    $data = think\Cache::get($key);
    if(empty($data)){
        $config = config('maccms.interface');
        $vodtype = str_replace([chr(10),chr(13)],['','#'],$config['vodtype']);
        $arttype = str_replace([chr(10),chr(13)],['','#'],$config['arttype']);
        $actortype = str_replace([chr(10),chr(13)],['','#'],$config['actortype']);
        $websitetype = str_replace([chr(10),chr(13)],['','#'],$config['websitetype']);

        $data =[];
        $type_arr = explode('#',$vodtype);
        foreach($type_arr as $k=>$v){
            list($from, $to) = explode('=', $v);
            $data['vodtype'][$to] = $from;
        }

        $type_arr = explode('#',$arttype);
        foreach($type_arr as $k=>$v){
            list($from, $to) = explode('=', $v);
            $data['arttype'][$to] = $from;
        }

        $type_arr = explode('#',$actortype);
        foreach($type_arr as $k=>$v){
            list($from, $to) = explode('=', $v);
            $data['actortype'][$to] = $from;
        }

        $type_arr = explode('#',$websitetype);
        foreach($type_arr as $k=>$v){
            list($from, $to) = explode('=', $v);
            $data['websitetype'][$to] = $from;
        }

        think\Cache::set($key,$data);
    }

    $type_list = model('Type')->getCache('type_list');
    $type_names = [];
    foreach($type_list as $k=>$v){
        $type_names[$v['type_name']] = $v['type_id'];
    }

    foreach($data['vodtype'] as $k=>$v){
        $data['vodtype'][$k] = (int)$type_names[$v];
    }
    foreach($data['arttype'] as $k=>$v){
        $data['arttype'][$k] = (int)$type_names[$v];
    }
    foreach($data['actortype'] as $k=>$v){
        $data['actortype'][$k] = (int)$type_names[$v];
    }
    foreach($data['websitetype'] as $k=>$v){
        $data['websitetype'][$k] = (int)$type_names[$v];
    }
    return $data;
}

function mac_rep_pse_rnd($psearr,$txt,$id=0)
{
    if(empty($psearr)){
        return $txt;
    }
    $i=count($psearr);
    if(empty($txt)){
        if(empty($id)){
            $r = mt_rand(0,$i-1);
        }
        else{
            $r = $id % $i;
        }
        $res= $psearr[$r];
    }
    else{
        if(empty($id)){
            $id = crc32($txt);
        }
        $j=mb_strpos($txt,"<br>");
        $k=mb_strlen($txt);
        if($j==0){ $j=mb_strpos($txt,"<br/>"); }
        if($j==0){ $j=mb_strpos($txt,"<br />"); }
        if($j==0){ $j=mb_strpos($txt,"</p>"); }
        if($j==0){ $j=mb_strpos($txt,"。"); }
        if($j==0){ $j=mb_strpos($txt,"！"); }
        if($j==0){ $j=mb_strpos($txt,"!"); }
        if($j==0){ $j=mb_strpos($txt,"？"); }
        if($j==0){ $j=mb_strpos($txt,"?"); }
        if($j>0){
            $res= mac_substring($txt,$j-1) . $psearr[$id % $i] . mac_substring($txt,$k-$j,$j);
        }
        else{
            $res= $psearr[$id % $i]. $txt;
        }
    }
    return $res;
}

function mac_txt_explain($txt, $decode = false)
{
    $txtarr = explode('#',(string)$txt);
    $data=[];
    foreach($txtarr as $v){
        if (stripos($v, '=') === false) {
            continue;
        }
        list($from, $to) = explode('=', $v, 2);
        if ($decode === true && stripos($from, '&') !== false && stripos($from, ';') !== false) {
            $from = html_entity_decode($from, ENT_QUOTES, 'UTF-8');
        }
        if ($decode === true && stripos($to, '&') !== false && stripos($to, ';') !== false) {
            $to = html_entity_decode($to, ENT_QUOTES, 'UTF-8');
        }
        $data['from'][] = $from;
        $data['to'][] = $to;
    }
    return $data;
}

function mac_rep_pse_syn($psearr,$txt)
{
    if(empty($txt)){ $txt=""; }
    if(is_array($psearr['from']) && is_array($psearr['to'])){
        $txt = str_replace($psearr['from'],$psearr['to'],$txt);
    }
    return $txt;
}

function mac_get_tag($title,$content){
    // ---- 已永久停用：官方发行包内嵌的第三方关键词接口（与 maccms-tp8 对齐） ----
    // 【背景】原实现在此处内置了一个远程调用：把视频/文章/演员的**标题与正文前 200 字**
    // 外发到一个第三方域名换取"自动 TAG"。该行为属**内容外泄 + 供应链依赖**
    // 与官方升级通道同类风险。
    // return false）
    // 同一处置 —— **删除该远程调用的全部残留代码**。原实现把请求地址以 base64 字面量
    // 隐藏（本次一并删除，不在本文件保留该字面量）：不透明编码会被安全扫描工具
    // 判定为后门特征，也是隐藏载荷的天然载体（参见 static/js/player.js 的历史）。
    // 【兼容性】返回 false 就是原有的"接口不可用"分支，6 处调用点均能正确处理 ——
    // 对外行为与停用后完全一致，仅"自动 TAG"功能不再生效。
    // 【为什么不恢复】恢复即意味着把站点内容持续外发给第三方，且该域名的归属与
    // 数据使用方式无法审计。若确需自动 TAG，建议改用本地词库方案。
    return false;
}

function mac_get_client_ip()
{
    static $final;
    if (!is_null($final)) {
        return $final;
    }
    // 安全：原实现无条件信任 5 个客户端可伪造的请求头
    // （CF-Connecting-IP / Ali-CDN-Real-IP / Client-IP / Proxy-User / X-Real-IP）
    // 使 IP 绑定、评论/留言黑名单、访问统计、登录 IP 审计全部可绕过。现规则：
    // 1) app.behind_cdn 打开时，才采信 CDN 注入头（CF-Connecting-IP / Ali-CDN-Real-IP / X-Forwarded-For）
    // 2) 仅当直连对端 REMOTE_ADDR 命中 app.trusted_proxies（IP 或 CIDR 逗号列表）时
    // 才采信通用转发头（Client-IP / Proxy-User / X-Real-IP / X-Forwarded-For）
    // 3) 其余情况一律返回 REMOTE_ADDR。
    // 默认两项配置均为空/关闭，等价于"只信 REMOTE_ADDR"；启用 CDN/反代时由运维显式配置。
    $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app']) ? $GLOBALS['config']['app'] : array();
    $trusted = isset($app['trusted_proxies']) ? trim((string)$app['trusted_proxies']) : '';
    $behindCdn = !empty($app['behind_cdn']);

    $ips = array();
    if ($behindCdn) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) { $ips[] = $_SERVER['HTTP_CF_CONNECTING_IP']; }
        if (!empty($_SERVER['HTTP_ALI_CDN_REAL_IP'])) { $ips[] = $_SERVER['HTTP_ALI_CDN_REAL_IP']; }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $xff = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ips[] = trim($xff[0]);
        }
    }
    if ($trusted !== '' && mac_ip_in_list($remote, $trusted)) {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) { $ips[] = $_SERVER['HTTP_CLIENT_IP']; }
        if (!empty($_SERVER['HTTP_PROXY_USER'])) { $ips[] = $_SERVER['HTTP_PROXY_USER']; }
        $real_ip = getenv('HTTP_X_REAL_IP');
        if (!empty($real_ip)) { $ips[] = $real_ip; }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $xff = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ips[] = trim($xff[0]);
        }
    }
    if (!empty($remote)) { $ips[] = $remote; }

    // 取第一个合法且非保留段的地址（顺序即优先级）
    foreach ($ips as $ip) {
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
            $final = $ip;
            break;
        }
    }
    empty($final) && $final = '0.0.0.0';
    return $final;
}

function mac_get_ip_long($ip_addr = '')
{
    $ip_addr = !empty($ip_addr) ? $ip_addr : mac_get_client_ip();
    $ip_long = sprintf('%u',ip2long($ip_addr));
    // 排除不正确的IP
    if ($ip_long < 0 || $ip_long >= 0xFFFFFFFF) {
        $ip_long = 0;
    }
    return $ip_long;
}

function mac_get_uniqid_code($code_prefix='')
{
    $code_prefix = strtoupper($code_prefix);
    $now_date = date('YmdHis');
    $now_time = rand(100000, 999999);
    return $code_prefix . $now_date . $now_time;
}

function mac_escape($string, $in_encoding = 'UTF-8',$out_encoding = 'UCS-2') {
    $return = '';
    if (function_exists('mb_get_info')) {
        for($x = 0; $x < mb_strlen ( $string, $in_encoding ); $x ++) {
            $str = mb_substr ( $string, $x, 1, $in_encoding );
            if (strlen ( $str ) > 1) { // 多字节字符
                $return .= '%u' . strtoupper ( bin2hex ( mb_convert_encoding ( $str, $out_encoding, $in_encoding ) ) );
            } else {
                $return .= '%' . strtoupper ( bin2hex ( $str ) );
            }
        }
    }
    return $return;
}
function mac_unescape($str)
{
    $ret = '';
    $len = strlen($str);
    for ($i = 0; $i < $len; $i ++)
    {
        if ($str[$i] == '%' && $str[$i + 1] == 'u')
        {
            $val = hexdec(substr($str, $i + 2, 4));
            if ($val < 0x7f)
                $ret .= chr($val);
            else
                if ($val < 0x800)
                    $ret .= chr(0xc0 | ($val >> 6)) .
                        chr(0x80 | ($val & 0x3f));
                else
                    $ret .= chr(0xe0 | ($val >> 12)) .
                        chr(0x80 | (($val >> 6) & 0x3f)) .
                        chr(0x80 | ($val & 0x3f));
            $i += 5;
        } else
            if ($str[$i] == '%')
            {
                $ret .= urldecode(substr($str, $i, 3));
                $i += 2;
            } else
                $ret .= $str[$i];
    }
    return $ret;
}

/*特殊字段的值转换*/
function mac_get_mid_code($data)
{
    $arr = [
        1  => 'vod',
        2  => 'art',
        3  => 'topic',
        4  => 'comment',
        5  => 'gbook',
        6  => 'user',
        7  => 'label',
        8  => 'actor',
        9  => 'role',
        10 => 'plot',
        11 => 'website',
    ];
    return $arr[$data];
}
function mac_get_mid_text($data)
{
    $arr = [
        1  => lang('vod'),
        2  => lang('art'),
        3  => lang('topic'),
        4  => lang('comment'),
        5  => lang('gbook'),
        6  => lang('user'),
        7  => lang('label'),
        8  => lang('actor'),
        9  => lang('role'),
        10 => lang('plot'),
        11 => lang('website'),
    ];
    return $arr[$data];
}
function mac_get_mid($controller)
{
    $controller=strtolower($controller);
    $arr = [
        'vod'     => 1,
        'art'     => 2,
        'topic'   => 3,
        'comment' => 4,
        'gbook'   => 5,
        'user'    => 6,
        'label'   => 7,
        'actor'   => 8,
        'role'    => 9,
        'plot'    => 10,
        'website' => 11,
    ];
    return $arr[$controller] ?? 0;
}
function mac_get_aid($controller,$action='')
{
    $controller=strtolower($controller);
    $action=strtolower($action);
    $key = $controller.'/'.$action;

    $arr=['index'=>1,'map'=>2,'rss'=>3,'gbook'=>4,'comment'=>5,'user'=>6,'label'=>7,'vod'=>10,'art'=>20,'topic'=>30,'actor'=>80,'role'=>90,'plot'=>100,'website'=>110];
    $res = $arr[$controller] ?? 0;

    // https://github.com/magicblack/maccms10/issues/960
    $arr=[
        'vod/type'=>11,'vod/show'=>12,'vod/search'=>13,'vod/detail'=>14,'vod/play'=>15,'vod/down'=>16,'vod/role'=>17,'vod/plot'=>18,
        'art/type'=>21,'art/show'=>22,'art/search'=>23,'art/detail'=>24,
        'topic/search'=>33,'topic/detail'=>34,
        'actor/type'=>81,'actor/show'=>82,'actor/search'=>83,'actor/detail'=>84,
        'role/show'=>92,'role/search'=>93,'role/detail'=>94,
        'plot/search'=>103,'plot/detail'=>104,
        'website/type'=>111,'website/show'=>112,'website/search'=>113,'website/detail'=>114,
    ];
    if(!empty($arr[$key])){
        $res= $arr[$key];
    }
    return $res;
}

function mac_get_user_status_text($data)
{
    $arr = [
        0 => lang('disable'),
        1 => lang('enable'),
    ];
    return $arr[$data];
}
function mac_get_user_flag_text($data)
{
    $arr = [
        0 => lang('counting_points'),
        1 => lang('counting_times'),
        2 => lang('counting_ips'),
    ];
    return $arr[$data];
}

function mac_get_ulog_type_text($data)
{
    $arr = [
        1 => lang('browse'),
        2 => lang('collect'),
        3 => lang('want_see'),
        4 => lang('play'),
        5 => lang('down'),
    ];
    return $arr[$data];
}

function mac_get_plog_type_text($data)
{
    $arr = [
        1 => lang('integral_recharge'),
        2 => lang('registration_promotion'),
        3 => lang('visit_promotion'),
        4 => lang('one_level_distribution'),
        5 => lang('two_level_distribution'),
        6 => lang('three_level_distribution'),
        7 => lang('points_upgrade'),
        8 => lang('integral_consumption'),
        9 => lang('integral_withdrawal'),
    ];
    return $arr[$data];
}

function mac_get_card_sale_status_text($data)
{
    $arr = [
        0 => lang('not_sale'),
        1 => lang('sold'),
    ];
    return $arr[$data];
}

function mac_get_card_use_status_text($data)
{
    $arr = [
        0 => lang('not_used'),
        1 => lang('used'),
    ];
    return $arr[$data];
}

function mac_get_order_status_text($data)
{
    $arr = [
        0 => lang('not_paid'),
        1 => lang('paid'),
    ];
    return $arr[$data];
}

function mac_get_user_portrait($user_id)
{
    $res = MAC_PATH . 'static/images/touxiang.png';
    if(!empty($user_id)){
        $res2 = 'upload/user/'.($user_id % 10 ). '/'.$user_id.'.jpg';
        if(file_exists(ROOT_PATH . $res2)){
            $res = MAC_PATH . $res2;
        }
    }
    return $res;
}

function mac_filter_html($str)
{
    return strip_tags((string)$str);
}

function mac_filter_xss($str)
{
    return trim(htmlspecialchars(strip_tags((string)$str), ENT_QUOTES));
}

function mac_restore_htmlfilter($str) {
    $str = (string)$str;
    if (stripos($str, '&amp;') !== false) {
        return htmlspecialchars_decode($str, ENT_QUOTES);
    }
    return $str;
}

/**
 * 简介/纯文本展示：解码双重 HTML 实体（如 &amp;nbsp;），去掉标签，压空白。
 * 采集源常把 &nbsp; 存成 &amp;nbsp;，前台直接输出会显示成实体源码。
 */
function mac_plain_text($str, $len = 0)
{
    $s = (string)$str;
    if ($s === '') {
        return '';
    }
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strip_tags($s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s, " \t\n\r\0\x0B\xC2\xA0");
    if ((int)$len > 0) {
        $s = mac_substring($s, (int)$len);
    }
    return $s;
}

function mac_format_text($str, $allow_space = false)
{
    $finder = array('/', '，', '|', '、', ',,', ',,,');
    if ($allow_space === false) {
        $finder[] = ' ';
    }
    return str_replace($finder, ',', $str);
}
function mac_format_count($str)
{
    $arr = explode(',',$str);
    return count($arr);
}

function mac_txt_merge($txt,$str)
{
    if(empty($str)){
        return $txt;
    }
    if($GLOBALS['config']['collect']['vod']['class_filter'] !='0') {
        if (mb_strlen($str) > 2) {
            $str = str_replace([lang('slice')], [''], $str);
        }
        if (mb_strlen($str) > 2) {
            $str = str_replace([lang('drama')], [''], $str);
        }
    }
    $txt = mac_format_text($txt);
    $str = mac_format_text($str);
    $arr1 = explode(',',$txt);
    $arr2 = explode(',',$str);
    $arr = array_merge($arr1,$arr2);
    return join(',',array_unique( array_filter($arr)));
}

function mac_array_check_num($arr)
{
    if(!is_array($arr)){
        return false;
    }
    $res = true;
    foreach($arr as $a){
        if(!is_numeric($a)){
            $res=false;
            break;
        }
    }
    return $res;
}

function mac_like_arr($s)
{
    $tmp = explode(',',$s);
    $like_arr = [];
    foreach($tmp as $v){
        $like_arr[] = '%'.$v.'%';
    }
    return $like_arr;
}

function mac_art_list($art_title,$art_note,$art_content)
{
    $art_title_list = [];
    $art_note_list = [];
    $art_content_list = [];
    if(!empty($art_title)) {
        $art_title_list = explode('$$$', $art_title);
    }
    if(!empty($art_note)) {
        $art_note_list = explode('$$$', $art_note);
    }
    if(!empty($art_content)) {
        $art_content_list = explode('$$$', $art_content);
    }
    $res_list = [];
    foreach($art_content_list as $k=>$v){
        $res_list[$k+1] = [
            'page'=> $k+1,
            'title'=>$art_title_list[$k] ?? '',
            'note'=>$art_note_list[$k] ?? '',
            'content'=>$v,
        ];
    }
    return $res_list;
}

function mac_plot_list($vod_plot_name,$vod_plot_detail)
{
    $vod_plot_name_list = [];
    $vod_plot_detail_list = [];

    if(!empty($vod_plot_name)) {
        $vod_plot_name_list = explode('$$$', $vod_plot_name);
    }
    if(!empty($vod_plot_detail)) {
        $vod_plot_detail_list = explode('$$$', $vod_plot_detail);
    }

    $res_list = [];
    foreach($vod_plot_name_list as $k=>$v){

        $res_list[$k + 1] = [
                'name' => $vod_plot_name_list[$k],
                'detail' => $vod_plot_detail_list[$k],
        ];
    }
    return $res_list;

}



function mac_play_list($vod_play_from,$vod_play_url,$vod_play_server,$vod_play_note,$flag='play')
{
    $vod_play_from_list = [];
    $vod_play_url_list = [];
    $vod_play_server_list = [];
    $vod_play_note_list = [];

    if(!empty($vod_play_from)) {
        $vod_play_from_list = explode('$$$', $vod_play_from);
    }
    if(!empty($vod_play_url)) {
        $vod_play_url_list = explode('$$$', $vod_play_url);
    }
    if(!empty($vod_play_server)) {
        $vod_play_server_list = explode('$$$', $vod_play_server);
    }
    if(!empty($vod_play_note)) {
        $vod_play_note_list = explode('$$$', $vod_play_note);
    }

    if($flag=='play'){
        $player_list = config('vodplayer');
    }
    else{
        $player_list = config('voddowner');
    }
    $server_list = config('vodserver');

    $res_list = [];
    $sort=[];
    foreach($vod_play_from_list as $k=>$v){
        $server = (string)($vod_play_server_list[$k] ?? '');
        $urls = mac_play_list_one((string)($vod_play_url_list[$k] ?? ''),$v);

        $player_info = isset($player_list[$v]) && is_array($player_list[$v]) ? $player_list[$v] : ['status' => '0', 'sort' => 0];
        $server_info = isset($server_list[$server]) && is_array($server_list[$server]) ? $server_list[$server] : [];
        if(($player_info['status'] ?? '0') == '1') {
            $sort[] = intval($player_info['sort'] ?? 0);
            $res_list[$k + 1] = [
                'sid' => $k + 1,
                'player_info' => $player_info,
                'server_info' => $server_info,
                'from' => $v,
                'url' => (string)($vod_play_url_list[$k] ?? ''),
                'server' => $server,
                'note' => (string)($vod_play_note_list[$k] ?? ''),
                'url_count' => count($urls),
                'urls' => $urls,
            ];
        }
    }

    if( (ENTRANCE!='admin' && MAC_PLAYER_SORT=='1') ||  ($GLOBALS['ismake'] ?? '')=='1' ){
        array_multisort($sort, SORT_DESC, SORT_FLAG_CASE , $res_list);
        $tmp=[];
        foreach($res_list as $k=>$v){
            $tmp[$v['sid']] = $v;
        }
        $res_list = $tmp;
    }
    return $res_list;
}

function new_stripslashes($string) {
    if(!is_array($string)) return stripslashes($string);
    foreach($string as $key => $val) $string[$key] = new_stripslashes($val);
    return $string;
}

function mac_screenshot_list($screenshot)
{
    $url_list = array();
    $array_url = explode('#',$screenshot);
    foreach($array_url as $key=>$val){
        if(empty($val)) continue;

        list($title, $url) = array_pad(explode('$', $val, 2), 2, '');
        if ( empty($url) ) {
            $url_list[$key+1]['name'] = ($key+1);
            $url_list[$key+1]['url'] = $title;
        }else{
            $url_list[$key+1]['name'] = $title;
            $url_list[$key+1]['url'] = $url;
        }
    }
    return $url_list;
}

function mac_play_list_one($url_one, $from_one, $server_one=''){
    $url_list = array();
    $array_url = explode('#',$url_one);
    foreach($array_url as $key=>$val){
        if(empty($val)) continue;

        list($title, $url, $from) = array_pad(explode('$', $val, 3), 3, '');
        if ( empty($url) ) {
            $url_list[$key+1]['name'] = lang('the').($key+1).lang('episode');
            $url_list[$key+1]['url'] = $server_one.$title;
        }else{
            $url_list[$key+1]['name'] = $title;
            $url_list[$key+1]['url'] = $server_one.$url;
        }
        if(empty($from)){
            $from = $from_one;
        }
        $url_list[$key+1]['from'] = (string)$from;
        $url_list[$key+1]['nid'] = $key+1;
    }
    return $url_list;
}

function mac_filter_words($p)
{
    $config = config('maccms.app');
    $arr = explode(",",$config['filter_words']);
    if(is_array($p)){
        foreach($p as $k=>$v){
            $p[$k] = str_replace($arr,"***",$v);
        }
    }
    else{
        $p = str_replace($arr,"***",$p);
    }
    return $p;
}

function mac_long2ip($ip){
    $ip = long2ip($ip);
    $reg2 = '~(\d+)\.(\d+)\.(\d+)\.(\d+)~';
    return preg_replace($reg2, "$1.$2.*.*", $ip);
}
function mac_default($s,$def='')
{
    if(empty($s)){
        return $def;
    }
    return $s;
}
function mac_num_fill($num)
{
    if($num<10){
        $num = '0' . $num;
    }
    return $num;
}

function mac_multisort($arr,$col_sort,$sort_order,$col_status='',$status_val='')
{
    $sort=[];
    foreach($arr as $k=>$v){
        if($col_status!='' && $v[$col_status] != $status_val){
            unset($arr[$k]);
       } else {
            $sort[] = isset($v[$col_sort]) ? $v[$col_sort] : 0;
       }
    }
    array_multisort($sort, $sort_order, SORT_FLAG_CASE, $arr);
    return $arr;
}

function mac_get_body($text,$start,$end)
{
    if(empty($text)){ return false; }
    if(empty($start)){ return false; }
    if(empty($end)){ return false; }

    $start=stripslashes($start);
    $end=stripslashes($end);

    if(strpos($text,$start)!=""){
        $str = substr($text,strpos($text,$start)+strlen($start));
        $str = substr($str,0,strpos($str,$end));
    }
    else{
        $str='';
    }
    return $str;
}

function mac_find_array($text,$start,$end)
{
    $start=stripslashes($start);
    $end=stripslashes($end);
    if(empty($text)){ return false; }
    if(empty($start)){ return false; }
    if(empty($end)){ return false; }

    $start = str_replace(["(",")","'","?"],["\(","\)","\'","\?"],$start);
    $end = str_replace(["(",")","'","?"],["\(","\)","\'","\?"],$end);

    $labelRule = $start."(.*?)".$end;
    $labelRule = mac_buildregx($labelRule,"is");
    preg_match_all($labelRule,$text,$tmparr);
    $tmparrlen=count($tmparr[1]);
    $rc=false;
    $str='';
    $arr=[];
    for($i=0;$i<$tmparrlen;$i++) {
        if($rc){ $str .= "{array}"; }
        $str .= $tmparr[1][$i];
        $rc=true;
    }

    if(empty($str)) { return false ;}
    $str=str_replace($start,"",$str);
    $str=str_replace($end,"",$str);
    // $str=str_replace("\"\"","",$str)
    // $str=str_replace("'","",$str)
    // $str=str_replace(" ","",$str)
    if(empty($str)) { return false ;}
    return $str;
}

/*前台页面*/
function mac_param_url(){
    $input = input() ;
    $param = [];
    $tmp = $_REQUEST;
    
    $input = array_merge($input,$tmp);
    // Public URL parameters are scalar. Reject arrays before numeric/string conversion.
    foreach ($input as $key => $value) {
        if (!is_scalar($value) && $value !== null) { $input[$key] = ''; }
    }
    // $param['id'] = intval($input['id'])
    // 安全：原实现使用 htmlspecialchars 默认 flags（PHP 8 为 ENT_COMPAT
    // 单引号不转义），在第三方模板的单引号 JS 串里可逃逸；且直接索引未定义键会触发 PHP 8 警告。
    // 现统一 ENT_QUOTES | ENT_SUBSTITUTE 显式声明编码，并对缺失键做空值兜底。
    $param['page'] = intval($input['page'] ?? 0) <1 ? 1 : intval($input['page'] ?? 0);
    foreach (array('ajax','aid','tid','mid','rid','pid','sid','nid','uid','level','score','limit') as $ik) {
        $param[$ik] = intval($input[$ik] ?? 0);
    }

    $str_keys = array('id','ids','wd','en','state','area','year','lang','actor','director','tag','class',
                      'order','by','file','name','url','type','sex','version','blood','starsign','domain');
    foreach ($str_keys as $sk) {
        $param[$sk] = htmlspecialchars(urldecode(trim((string)($input[$sk] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $param['letter'] = htmlspecialchars(trim((string)($input['letter'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return $param;
}

function mac_get_page($page)
{
    if(empty($page)) {
        $param = mac_param_url();
        $page = $param['page'];
    }
    return $page;
}

function mac_tpl_fetch($model,$tpl,$def='')
{
    // 安全：模板名来自内容记录（可被采集源/推送数据写入），只允许简单文件名，防 ../ 目录穿越包含任意文件为模板
    $tpl = empty($tpl) ? '' : str_replace('.html', '', (string)$tpl);
    if ($tpl === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $tpl)) {
        $tpl = $def;
    }
    return $model . '/' . $tpl;
}

function mac_get_order($order,$param)
{
    if(!empty($param['order'])) {
        $order = $param['order'];
    }
    if(!in_array($order, ['asc', 'desc'])) {
        $order = 'desc';
    }
    return $order;
}

function mac_url_img($url)
{
    if(substr($url,0,4) == 'mac:'){
        $protocol = $GLOBALS['config']['upload']['protocol'];
        if(empty($protocol)){
            $protocol = 'http';
        }
        $url = str_replace('mac:', $protocol.':',$url);
    }
    elseif(substr($url,0,4) != 'http' && substr($url,0,2) != '//' && substr($url,0,1) != '/'){
        if($GLOBALS['config']['upload']['mode']=='remote'){
            $url = $GLOBALS['config']['upload']['remoteurl'] . $url;
        }
        else{
            $url = MAC_PATH . $url;
        }
    }
    elseif(!empty($GLOBALS['config']['upload']['img_key']) && preg_match('/'.$GLOBALS['config']['upload']['img_key'].'/',$url)){
        $url = $GLOBALS['config']['upload']['img_api'] . '' . $url;
    }
    $url = mac_filter_xss($url);
    $url = str_replace('&quot;&gt;', '', $url);
    $url = str_replace('&amp;', '&', $url);
    return $url;
}

function mac_url_content_img($content)
{
    $protocol = $GLOBALS['config']['upload']['protocol'];
    if(empty($protocol)){
        $protocol = 'http';
    }
    $content = str_replace('mac:',$protocol.':',(string)$content);
    if(!empty($GLOBALS['config']['upload']['img_key'])){
        $rule = mac_buildregx("<img[^>]*src\s*=\s*['" . chr(34) . "]?([\w/\-\:.]*)['" . chr(34) . "]?[^>]*>", "is");
        preg_match_all($rule, $content, $matches);
        if(is_array($matches[1])){
            foreach ($matches[1] as $f => $matchfieldstr) {
                $img_src = trim(preg_replace("/[ \r\n\t\f]{1,}/", " ", $matchfieldstr));
                if(preg_match('/'.$GLOBALS['config']['upload']['img_key'].'/',$img_src)){
                    $content = str_replace($img_src,$GLOBALS['config']['upload']['img_api'] . '' . $img_src,$content);
                }
            }
        }
    }
    return $content;
}

function mac_alphaID($in, $to_num=false, $pad_up=false, $passKey='')
{
    $key = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if (!empty($passKey)) {
        for ($n = 0; $n<strlen($key); $n++) {
            $i[] = substr($key,$n ,1);
        }
        $len = strlen($key);
        $passhash = hash('sha256',$passKey);
        $passhash = (strlen($passhash) < $len)
            ? hash('sha512',$passKey)
            : $passhash;
        for ($n=0; $n < $len; $n++) {
            $p[] = substr($passhash, $n ,1);
        }
        array_multisort($p, SORT_DESC, $i);
        $key = implode($i);
    }
    $base = strlen($key);
    if ($to_num) {
        $in = strrev($in);
        $out = 0;
        $len = strlen($in) - 1;
        for ($t = 0; $t <= $len; $t++) {
            $bcpow = bcpow($base, $len - $t);
            $out = $out + strpos($key, substr($in, $t, 1)) * $bcpow;
        }
        if (is_numeric($pad_up)) {
            $pad_up--;
            if ($pad_up > 0) {
                $out -= pow($base, $pad_up);
            }
        }
    } else {
        if (is_numeric($pad_up)) {
            $pad_up--;
            if ($pad_up > 0) {
                $in += pow($base, $pad_up);
            }
        }
        $out = "";
        for ($t = floor(log10($in) / log10($base)); $t >= 0; $t--) {
            $a = floor($in / bcpow($base, $t));
            $out = $out . substr($key, $a, 1);
            $in = $in - ($a * bcpow($base, $t));
        }
        $out = strrev($out);
    }
    return $out;
}

function mac_url($model,$param=[],$info=[])
{
    foreach($param as $k=>$v){
        if(empty($v)){
            unset($param[$k]);
        }
    }

    if(!isset($param['page'])) $param['page']=1;

    if($param['page'] == 1){
        $param['page']='';
    }

    $type = isset($info['type']) && is_array($info['type']) ? $info['type'] : [];
    $type_1 = isset($info['type_1']) && is_array($info['type_1']) ? $info['type_1'] : [];
    $type_id = intval($info['type_id'] ?? $type['type_id'] ?? 0);
    $type_en = (string)($info['type_en'] ?? $type['type_en'] ?? '');
    $type_pid = intval($type_1['type_id'] ?? $type['type_pid'] ?? $info['type_id_1'] ?? 0);
    $type_pen = (string)($type_1['type_en'] ?? '');
    $sid = intval($param['sid'] ?? 1);
    $nid = intval($param['nid'] ?? 1);
    if($sid < 1){
        $sid = 1;
    }
    if($nid < 1){
        $nid = 1;
    }

    ksort($param); 

    $config = $GLOBALS['config'];
    $view = isset($config['view']) && is_array($config['view']) ? $config['view'] : [];
    $view = array_merge([
        'index' => 0,
        'map' => 0,
        'search' => 0,
        'rss' => 0,
        'label' => 0,
        'vod_type' => 0,
        'vod_show' => 0,
        'vod_detail' => 0,
        'vod_play' => 0,
        'vod_down' => 0,
        'vod_role' => 0,
        'vod_plot' => 0,
        'art_type' => 0,
        'art_show' => 0,
        'art_detail' => 0,
        'topic_index' => 0,
        'topic_detail' => 0,
        'actor_index' => 0,
        'actor_type' => 0,
        'actor_detail' => 0,
        'role_index' => 0,
        'role_detail' => 0,
        'plot_index' => 0,
        'plot_detail' => 0,
        'website_index' => 0,
        'website_type' => 0,
        'website_detail' => 0,
    ], $view);
    $config['view'] = $view;
    $replace_from = ['{id}','{en}','{page}','{type_id}','{type_en}','{type_pid}','{type_pen}','{md5}','{year}','{month}','{day}','{sid}','{nid}'];
    $replace_to = [];
    $page_sp = $config['path']['page_sp'];
    $path = '';


    switch ($model)
    {
        case 'index/index':
            if($view['index'] == 2){
                $path = 'index';
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
            }
            else{
                $index_param = $param;
                unset($index_param['sid'], $index_param['nid']);
                $page_value = (string)($index_param['page'] ?? '');
                unset($index_param['page']);
                $path_parts = [];
                foreach($index_param as $key => $value){
                    if($value === '' || $value === null){
                        continue;
                    }
                    $path_parts[] = $key;
                    $path_parts[] = $value;
                }
                if(empty($path_parts) && $page_value === ''){
                    $url = '/';
                }
                else{
                    $url = rtrim(IN_FILE, '/') . '/index/index';
                    if(!empty($path_parts)){
                        $url .= '/' . implode('/', $path_parts);
                    }
                    if($page_value !== ''){
                        $url .= '/page/' . $page_value;
                    }
                    $url .= '.html';
                }
            }
            break;
        case 'map/index':
            if($config['view']['map'] == 2){
                $path = 'map';
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
            }
            else{
                $url = url($model,$param);
            }
            break;
        case strpos($model,'rss/')!==false:
            if($config['view']['rss'] == 2){
                $path = $model;
                if($param['page'] !=''){
                    $path .= $page_sp . $param['page'];
                }

                $path .= '.xml';
            }
            else{
                $url = url($model,$param,'xml');
            }
            break;
        case strpos($model,'label/')!==false:
            if($config['view']['label'] == 2){
                $path = $model;
            }
            else{
                $url = url($model,$param);
            }
            break;
        case 'vod/show':
        case 'art/show':
        case 'actor/show':
        case 'website/show':
            switch($config['rewrite']['type_id'])
            {
                case 1:
                    $id = $info['type_en'];
                    break;
                case 2:
                    $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                    break;
                default:
                    $id = $info['type_id'];
                    break;
            }
            if(!empty($id)){
                $param['id'] = $id;
            }
            $url = url($model,$param);
            break;
        case 'vod/type':
            $replace_to = [$type_id,$type_en,$param['page'],
                $type_id,$type_en,$type_pid,$type_pen,
            ];
            if($config['view']['vod_type'] == 2){
                $path = $config['path']['vod_type'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($type_id);
                if($param['page'] !=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['type_id'])
                {
                    case 1:
                        $id = $type_en;
                        break;
                    case 2:
                        $id = mac_alphaID($type_id,false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $type_id;
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            break;
        case 'vod/detail':
            $replace_to = [$info['vod_id'],$info['vod_en'],'',
                $type_id,$type_en,$type_pid,$type_pen
            ];
            if($config['view']['vod_detail'] == 2){
                $path = $config['path' ]['vod_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['vod_id']);
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }

                $url = url($model,['id'=> $id ]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time'])]);
            break;
        case 'vod/play':
            $replace_to = [
                $info['vod_id'],$info['vod_en'],'',
                $type_id,$type_en,$type_pid,$type_pen,

            ];
            if($config['view']['vod_play'] >=2){
                $path = $config['path' ]['vod_play'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['vod_id']);
                if($config['view']['vod_play'] ==2){
                    $path.= '.'. $config['path']['suffix'];
                    $path .= '?'.$info['vod_id'] . '-' . $sid . '-' . $nid ;
                }
                elseif($config['view']['vod_play'] ==3){
                    $path .= $config['path']['page_sp'] . $sid . $config['path']['page_sp'] . $nid ;
                }
                elseif($config['view']['vod_play'] ==4){
                    $path .= $config['path']['page_sp'] .''. $sid . $config['path']['page_sp'] . '1';
                    $path.= '.'. $config['path']['suffix'];
                    $path .= '?'.$info['vod_id'] . '-' . $sid . '-' . $nid ;
                }
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'sid'=>$sid,'nid'=>$nid]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time']),$sid,$nid]);
            break;
        case 'vod/down':
            $replace_to = [
                $info['vod_id'],$info['vod_en'],'',
                $type_id,$type_en,$type_pid,$type_pen
            ];
            if($config['view']['vod_down'] >= 2){
                $path = $config['path' ]['vod_down'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['vod_id']);
                if($config['view']['vod_down'] ==2){
                    $path.= '.'. $config['path']['suffix'];
                    $path .= '?'.$info['vod_id'] . '-' . $sid . '-' . $nid ;
                }
                elseif($config['view']['vod_down'] ==3){
                    $path .= $config['path']['page_sp'] . $sid . $config['path']['page_sp'] . $nid ;
                }
                elseif($config['view']['vod_down'] ==4){
                    $path .= $config['path']['page_sp'] .''. $sid . $config['path']['page_sp'] . '1';
                    $path.= '.'. $config['path']['suffix'];
                    $path .= '?'.$info['vod_id'] . '-' . $sid . '-' . $nid ;
                }
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'sid'=>$sid,'nid'=>$nid]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time']),$sid,$nid]);
            break;
        case 'vod/role':
            $replace_to = [$info['vod_id'],$info['vod_en'],'',
                $type_id,$type_en,$type_pid,$type_pen
            ];
            if($config['view']['vod_role'] == 2){
                $path = $config['path' ]['vod_role'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }   
                $replace_to[] = md5($info['vod_id']);
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time'])]);
            break;
        case 'vod/plot':
            $replace_to = [
                $info['vod_id'],$info['vod_en'],$param['page'],
                $type_id,$type_en,$type_pid,$type_pen
            ];
            if($config['view']['vod_plot'] == 2){
                $path = $config['path' ]['vod_plot'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['vod_id']);
                if($param['page']!=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time'])]);
            break;
        case 'art/type':
            $replace_to = [$info['type_id'],$info['type_en'],$param['page'],
                $info['type_id'],$info['type']['type_en'] ?? '',$info['type_1']['type_id'] ?? 0,$info['type_1']['type_en'] ?? '',
            ];
            if($config['view']['art_type'] == 2){
                $path = $config['path']['art_type'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['type_id']);
                }
                if($param['page']!=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['type_id'])
                {
                    case 1:
                        $id = $info['type_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['type_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            break;
        case 'art/detail':
            $replace_to = [
                $info['art_id'],$info['art_en'],'',
                $info['type_id'],$info['type']['type_en'] ?? '',$info['type_1']['type_id'] ?? 0,$info['type_1']['type_en'] ?? ''
            ];
            if($config['view']['art_detail'] == 2){
                $path = $config['path' ]['art_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                $replace_to[] = md5($info['art_id']);
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['art_id'])
                {
                    case 1:
                        $id = $info['art_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['art_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['art_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['art_time']),date('m',$info['art_time']),date('d',$info['art_time'])]);
            break;
        case 'topic/index':
            if($config['view']['topic_index'] == 2){
                $path = $config['path' ]['topic_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'topic/detail':
            $replace_to = [$info['topic_id'],$info['topic_en'],'','','','',''];
            if($config['view']['topic_detail'] == 2){
                $path = $config['path' ]['topic_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['topic_id']);
                }
            }
            else{
                switch($config['rewrite']['topic_id'])
                {
                    case 1:
                        $id = $info['topic_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['topic_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['topic_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            break;
        case 'actor/index':
            if($config['view']['actor_index'] == 2){
                $path = $config['path' ]['actor_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'actor/type':
            $replace_to = [$info['type_id'],$info['type_en'],$param['page'],
                $info['type_id'],$info['type']['type_en'] ?? '',$info['type_1']['type_id'] ?? 0,$info['type_1']['type_en'] ?? '',
            ];
            if($config['view']['actor_type'] == 2){
                $path = $config['path']['actor_type'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['type_id']);
                }
                if($param['page']!=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['type_id'])
                {
                    case 1:
                        $id = $info['type_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['type_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            break;
        case 'actor/detail':
            $replace_to = [$info['actor_id'],$info['actor_en'],'','','','',''];
            if($config['view']['actor_detail'] == 2){
                $path = $config['path' ]['actor_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['actor_id']);
                }
            }
            else{
                switch($config['rewrite']['actor_id'])
                {
                    case 1:
                        $id = $info['actor_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['actor_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['actor_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            break;
        case 'role/index':
            if($config['view']['role_index'] == 2){
                $path = $config['path' ]['role_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'role/detail':
            $replace_to = [$info['role_id'],($info['role_en'] ?? ($info['actor_en'] ?? '')),'','','','',''];
            if($config['view']['role_detail'] == 2){
                $path = $config['path' ]['role_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['role_id']);
                }
            }
            else{
                switch($config['rewrite']['role_id'])
                {
                    case 1:
                        $id = $info['role_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['role_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['role_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            break;
        case 'plot/index':
            if($config['view']['plot_index'] == 2){
                $path = $config['path' ]['plot_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'plot/detail':
            $replace_to = [
                $info['vod_id'],$info['vod_en'],'',
                $info['type_id'],$info['type']['type_en'] ?? '',$info['type_1']['type_id'] ?? 0,$info['type_1']['type_en'] ?? ''
            ];
            if($config['view']['plot_detail'] == 2){
                $path = $config['path' ]['plot_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['vod_id']);
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['vod_id'])
                {
                    case 1:
                        $id = $info['vod_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['vod_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['vod_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            $replace_to = array_merge($replace_to,[date('Y',$info['vod_time']),date('m',$info['vod_time']),date('d',$info['vod_time'])]);
            break;
        case 'website/index':
            if($config['view']['website_index'] == 2){
                $path = $config['path' ]['website_index'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if($param['page']>1 || $param['page'] =='PAGELINK'){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                $url = url($model,['page'=>$param['page']]);
            }
            break;
        case 'website/type':
            $replace_to = [$info['type_id'],$info['type_en'],$param['page'],
                $info['type_id'],$info['type']['type_en'] ?? '',$info['type_1']['type_id'] ?? 0,$info['type_1']['type_en'] ?? '',
            ];
            if($config['view']['website_type'] == 2){
                $path = $config['path']['website_type'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['type_id']);
                }
                if($param['page']!=''){
                    $path .= $page_sp . $param['page'];
                }
            }
            else{
                switch($config['rewrite']['type_id'])
                {
                    case 1:
                        $id = $info['type_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['type_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['type_id'];
                        break;
                }
                $url = url($model,['id'=>$id,'page'=>$param['page']]);
            }
            break;
        case 'website/detail':
            $replace_to = [$info['website_id'],$info['website_en'],'','','','',''];
            if($config['view']['website_detail'] == 2){
                $path = $config['path' ]['website_detail'];
                if(substr($path,strlen($path)-1,1)=='/'){
                    $path .= 'index';
                }
                if(strpos($path,'{md5}')!==false){
                    $replace_to[] = md5($info['website_id']);
                }
            }
            else{
                switch($config['rewrite']['website_id'])
                {
                    case 1:
                        $id = $info['website_en'];
                        break;
                    case 2:
                        $id = mac_alphaID($info['website_id'],false,$config['rewrite']['encode_len'],$config['rewrite']['encode_key']);
                        break;
                    default:
                        $id = $info['website_id'];
                        break;
                }
                $url = url($model,['id'=>$id]);
            }
            break;
        case 'gbook/index':
            $url = url($model,['page'=>$param['page']]);
            break;
        case 'comment/index':
            $url = url($model,['page'=>$param['page']]);
            break;
        default:
            $url = url($model,$param);
            break;
    }
    if(!empty($path)) {
        $path = str_replace($replace_from, $replace_to, $path);
        $path = str_replace('//', '/', $path);
        $delimiter = false;
        if(substr($path,strlen($path)-6) =='/index'){
            $delimiter = true;
            $path = substr($path,0, strlen($path)-5);
        }

        if($delimiter==false && strpos($path,'.')===false){
            $path.= '.'. $config['path']['suffix'];
        }
        $url = $path;
        if(substr($path,0,1)!='/') {
            $url = MAC_PATH . $path;
        }
    }
    else{
        if(ENTRANCE!='index'){
            $sto= MAC_PATH ;
            if($config['rewrite']['status']==0){
                $sto = MAC_PATH .'index.php/';
            }
            if(!empty(IN_FILE)){
                $url = str_replace(IN_FILE.'/',$sto,$url);
                $url = str_replace(ENTRANCE.'/','',$url);
            }
        }
        elseif($config['rewrite']['status']==0 && strpos($url,'index.php')===false){
            if(MAC_PATH !='/'){
                $url = str_replace(MAC_PATH,'/',$url);
            }
            $url = MAC_PATH. 'index.php' . $url;
        }
        elseif($config['rewrite']['status']==1 && strpos($url,'index.php')!==false){
            $url = str_replace('index.php/','',$url);
        }

        if($config['rewrite']['suffix_hide']==1){
            $url = str_replace('.html','/',$url);
            if(strpos($model,'/show')===false && strpos($model,'/search')===false) {
                $url = str_replace(['-/','_/','-.','_.'],'/',$url);
            }
        }
        else{
            if(strpos($model,'search')===false && strpos($model,'show')===false ) {
                $url = str_replace(['-.', '/.'], '.', $url);
            }
        }
    }

    return $url;
}
function mac_url_page($url,$num)
{
    $url = str_replace(MAC_PAGE_SP.'PAGELINK',($num>1 ? MAC_PAGE_SP.$num : ''),$url);
    $url = str_replace('PAGELINK',$num,$url);
    return $url;
}

function mac_url_create($str,$type='actor',$flag='vod',$ac='search',$sp='&nbsp;')
{
    if(!$str){
        return '未知';
    }
    $res = [];
    // 分割时，中文关键词允许空格分割，英文不用空格（英文名中间是空格分隔的问题）
    $base_finder = array(' / ', '/', '|', ',', '，', ',,');
    $str = str_replace($base_finder, ',', $str);
    $str = trim($str, ',');
    $arr = [];
    foreach (explode(',', $str) as $tag) {
        if (preg_match("/[\x{2E80}-\x{9FFF}]+/u", $tag) && str_contains($tag, ' ')) {
            foreach (explode(' ', $tag) as $tag_exp) {
                $arr[] = $tag_exp;
            }
        } else {
            $arr[] = $tag;
        }
    }
    foreach ($arr as $k => $v) {
        $res[$k] = '<a href="'.mac_url($flag.'/'.$ac,[$type=>$v]).'" target="_blank">'.$v.'</a>'.$sp;
    }
    return implode('',$res);
}

function mac_url_search($param=[],$flag='vod')
{
    return mac_url($flag.'/search',$param);
}

function mac_url_type($info,$param=[],$flag='type')
{
    $info = is_array($info) ? $info : [];
    $tab = 'vod';
    $type_mid = intval($info['type_mid'] ?? 1);
    if($type_mid == 1){

    }
    else if($type_mid == 2) {
        $tab ='art';
    }
    else if($type_mid == 8) {
        $tab ='actor';
    }
    else if($type_mid == 11) {
        $tab ='website';
    }
    if(empty($param['id'])){
        $param['id'] = intval($info['type_id'] ?? 0);
    }

    return mac_url($tab.'/'.$flag,$param,$info);
}

function mac_url_topic_index($param=[])
{
    return mac_url('topic/index',['page'=>$param['page'] ?? 1]);
}

function mac_url_topic_detail($info)
{
    return mac_url('topic/detail',[],$info);
}

function mac_url_role_index($param=[])
{
    return mac_url('role/index',['page'=>$param['page'] ?? 1]);
}

function mac_url_role_detail($info)
{
    return mac_url('role/detail',[],$info);
}
function mac_url_actor_index($param=[])
{
    return mac_url('actor/index',['page'=>$param['page'] ?? 1]);
}
function mac_url_actor_detail($info)
{
    return mac_url('actor/detail',[],$info);
}
function mac_url_actor_search($param)
{
    return mac_url('actor/search',$param);
}
function mac_url_plot_index($param=[])
{
    return mac_url('plot/index',['page'=>$param['page'] ?? 1]);
}
function mac_url_plot_detail($info,$param=[])
{
    return mac_url('plot/detail',['page'=>$param['page'] ?? 1],$info);
}
function mac_url_vod_plot($info,$param=[])
{
    return mac_url('vod/plot',$param,$info);
}
function mac_url_vod_role($info,$param=[])
{
    return mac_url('vod/role',$param,$info);
}
function mac_url_website_index($param=[])
{
    return mac_url('website/index',['page'=>$param['page'] ?? 1]);
}
function mac_url_website_detail($info)
{
    return mac_url('website/detail',[],$info);
}
function mac_url_website_search($param)
{
    return mac_url('website/search',$param);
}
function mac_url_art_index($param=[])
{
    return mac_url('art/index',['page'=>$param['page'] ?? 1]);
}
function mac_url_art_detail($info,$param=[])
{
    return mac_url('art/detail',['page'=>$param['page'] ?? 1],$info);
}
function mac_url_art_search($param)
{
    return mac_url('art/search',$param);
}
function mac_url_vod_index($param=[])
{
    return mac_url('vod/index',['page'=>$param['page'] ?? 1]);
}
function mac_url_vod_detail($info)
{
    return mac_url('vod/detail',[],$info);
}
function mac_url_vod_search($param)
{
    return mac_url('vod/search',$param);
}
function mac_url_vod_play($info,$param=[])
{
    if($param=='first'){
        $sid = intval(key($info['vod_play_list']));
        $nid = intval(key($info['vod_play_list'][$sid]['urls']));
        if($sid==0 || $nid==0){
            return '';
        }
        $param=[];
        $param['sid'] = $sid;
        $param['nid'] = $nid;
    }
    if(intval($param['sid'] ?? 0)<1){
        $param['sid'] =1;
    }
    if(intval($param['nid'] ?? 0)<1){
        $param['nid']=1;
    }

    return mac_url('vod/play',['sid'=>$param['sid'],'nid'=>$param['nid']],$info);
}

function mac_url_vod_down($info,$param=[])
{
    if($param=='first'){
        $sid = intval(key($info['vod_down_list']));
        $nid = intval(key($info['vod_down_list'][$sid]['urls']));
        if($sid==0 || $nid==0){
            return '';
        }
        $param=[];
        $param['sid'] = $sid;
        $param['nid'] = $nid;
    }

    if(intval($param['sid'] ?? 0)<1){
        $param['sid'] =1;
    }
    if(intval($param['nid'] ?? 0)<1){
        $param['nid']=1;
    }

    return mac_url('vod/down',['sid'=>$param['sid'],'nid'=>$param['nid']],$info);
}



function mac_label_website_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['website_id']==1){
        $where['website_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['website_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['website_id'] = ['eq',$param['id']];
    }
    $where['website_status'] = ['eq',1];
    $res = model('Website')->infoData($where,'*',1);

    $GLOBALS['type_id'] = intval($res['info']['type_id'] ?? 0);
    $GLOBALS['type_pid'] = intval($res['info']['type']['type_pid'] ?? $res['info']['type_id_1'] ?? 0);
    return $res;
}
function mac_label_actor_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['actor_id']==1){
        $where['actor_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['actor_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['actor_id'] = ['eq',$param['id']];
    }
    $where['actor_status'] = ['eq',1];
    $res = model('Actor')->infoData($where,'*',1);

    $GLOBALS['type_id'] = intval($res['info']['type_id'] ?? 0);
    $GLOBALS['type_pid'] = intval($res['info']['type']['type_pid'] ?? $res['info']['type_id_1'] ?? 0);
    return $res;
}
function mac_label_role_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['role_id']==1){
        $where['role_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['role_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['role_id'] = ['eq',$param['id']];
    }
    $where['role_status'] = ['eq',1];
    $res = model('Role')->infoData($where,'*',1);

    // https://github.com/magicblack/maccms10/issues/960
    $GLOBALS['type_id'] = isset($res['info']['data']['type_id']) ? $res['info']['data']['type_id'] : 0;
    $GLOBALS['type_pid'] = isset($res['info']['data']['type_id_1']) ? $res['info']['data']['type_id_1'] : 0;
    return $res;
}
function mac_label_topic_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['topic_id']==1){
        $where['topic_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['topic_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['topic_id'] = ['eq',$param['id']];
    }
    $where['topic_status'] = ['eq',1];
    $res = model('Topic')->infoData($where,'*',1);
    return $res;
}
function mac_label_art_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['art_id']==1){
        $where['art_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['art_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['art_id'] = ['eq',$param['id']];
    }
    $where['art_status'] = ['eq',1];
    $res = model('Art')->infoData($where,'*',1);
    if($res['code'] ==1){
        if($param['page'] > (int)($res['info']['art_page_total'] ?? 1)){ $param['page'] = max(1, (int)($res['info']['art_page_total'] ?? 1)); }
    }
    $GLOBALS['type_id'] = intval($res['info']['type_id'] ?? 0);
    $GLOBALS['type_pid'] = intval($res['info']['type']['type_pid'] ?? $res['info']['type_id_1'] ?? 0);

    return $res;
}
function mac_label_vod_detail($param)
{
    $where = [];
    if($GLOBALS['config']['rewrite']['vod_id']==1){
        $where['vod_en'] = ['eq',$param['id']];
    }
    else{
        if($GLOBALS['config']['rewrite']['vod_id']==2) {
            $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
        }
        $where['vod_id'] = ['eq',$param['id']];
    }
    $where['vod_status'] = ['eq',1];
    $res = model('Vod')->infoData($where,'*',1);

    $GLOBALS['type_id'] = intval($res['info']['type_id'] ?? 0);
    $GLOBALS['type_pid'] = intval($res['info']['type']['type_pid'] ?? $res['info']['type_id_1'] ?? 0);
    return $res;
}

function mac_label_vod_role($param)
{
    $where = [];
    $where['role_rid'] = $param['rid'];
    $where['role_status'] = ['eq',1];
    $order='role_sort desc,role_id desc';
    $res = model('Role')->listData($where,$order,1,999,0,'*',0,0);
    return $res;
}

function mac_label_type($param, $type_id_specified)
{
    if ($type_id_specified > 0) {
        $type_id = $type_id_specified;
    } else {
        if($GLOBALS['config']['rewrite']['type_id']==1){

        }
        else{
            if($GLOBALS['config']['rewrite']['type_id']==2) {
                $param['id'] = mac_alphaID($param['id'], true, $GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key'] );
            }
        }
        $type_id = $param['id'];
    }
    $type_info = model('Type')->getCacheInfo($type_id);

    $GLOBALS['type_id'] = intval($type_info['type_id'] ?? 0);
    $GLOBALS['type_pid'] = intval($type_info['type_pid'] ?? 0);

    $parent = [];
    if(!empty($type_info['type_pid'])){
        $parent = model('Type')->getCacheInfo($type_info['type_pid']);
    }
    $type_info['parent'] = $parent;
    return $type_info;
}

function mac_data_count($tid=0,$range='all',$flag='vod')
{
    if(!in_array($flag,['vod','art','actor','role','topic','website'])) {
        $flag='vod';
    }
    if(!in_array($range,['all','today','min'])){
        $range='all';
    }

    $data = model('Extend')->dataCount();
    $key = 'type_'.$range.'_'.$tid;
    if($tid>0 && in_array($flag,['vod','art']) ){

    }
    else{
        $key = $flag.'_'.$range;
    }
    return intval($data[$key] ?? 0);
}

function mac_get_popedom_filter($group_type,$type_list=[])
{
    if(empty($type_list)){
        $type_list = model('Type')->getCache('type_list');
    }
    $type_keys = array_keys($type_list);
    $group_type = trim($group_type,',');
    $group_keys = explode(',',$group_type);
    $cha_keys = array_diff($type_keys, $group_keys);
    return implode(',',$cha_keys);
}

function reset_html_filename($htmlfile)
{
    $htmlpath = './';
    if(substr($htmlfile,strlen($htmlfile)-1,1)=='/'){
        $htmlfile .= 'index';
    }

    if(strpos($htmlfile,'.') ===false){
        $htmlfile .= '.'. $GLOBALS['config']['path']['suffix'];
    }

    if(strpos($htmlfile,'?')!==false){
        $htmlfile = substr($htmlfile,0,strpos($htmlfile,'?'));
    }
    $htmlfile   =   $htmlpath.$htmlfile;
    $htmlfile = str_replace('//','/', $htmlfile);

    if(MAC_PATH !='/'){
        $htmlfile = str_replace('.'.MAC_PATH, './', $htmlfile);
    }

    $htmlfile = str_replace('//','/', $htmlfile);
    return $htmlfile;
}

function mac_unicode_encode($str, $encoding = 'UTF-8', $prefix = '&#', $postfix = ';') {
    $str = iconv($encoding, 'UCS-2', $str);
    $arrstr = str_split($str, 2);
    $unistr = '';
    for($i = 0, $len = count($arrstr); $i < $len; $i++) {
        $dec = hexdec(bin2hex($arrstr[$i]));
        $unistr .= $prefix . $dec . $postfix;
    }
    return $unistr;
}
function mac_unicode_decode($unistr, $encoding = 'UTF-8', $prefix = '&#', $postfix = ';') {
    $arruni = explode($prefix, $unistr);
    $unistr = '';
    for($i = 1, $len = count($arruni); $i < $len; $i++) {
        if (strlen($postfix) > 0) {
            $arruni[$i] = substr($arruni[$i], 0, strlen($arruni[$i]) - strlen($postfix));
        }
        $temp = intval($arruni[$i]);
        $unistr .= ($temp < 256) ? chr(0) . chr($temp) : chr($temp / 256) . chr($temp % 256);
    }
    return iconv('UCS-2', $encoding, $unistr);
}

function mac_escape_param($param)
{
    if(is_array($param)){
        foreach($param as $k=>$v){
            if(!is_numeric($v) && !empty($v)){

                if($GLOBALS['config']['app']['wall_filter'] ==1){
                    $v = mac_unicode_encode($v);
                }
                elseif($GLOBALS['config']['app']['wall_filter'] ==2){
                    $v = '';
                }
                $param[$k] = $v;
            }
        }
    }
    else{
        if(!is_numeric($param) && !empty($param)){
            if($GLOBALS['config']['app']['wall_filter'] ==1){
                $param = mac_unicode_encode($param);
            }
            elseif($GLOBALS['config']['app']['wall_filter'] ==2){
                $param = '';
            }
        }
    }
    return $param;
}

function mac_search_len_check($param)
{
    // PHP8：原实现在两处会触发告警——
    // 1) $param[$v] 未定义（前台/安装向导只传了部分键）
    // 2) app.search_len 未配置（本仓库 extra/maccms.php 确实没有该键，只在语言包里出现过）。
    // 现统一兜底：缺键按空串、缺配置按 10（与后台选项文案「默认 10 字」一致）。
    $psm = array('wd','tag','class','letter','name','state','level','area','lang','version','actor','director','starsign','blood');
    $len = isset($GLOBALS['config']['app']['search_len']) ? intval($GLOBALS['config']['app']['search_len']) : 0;
    if ($len <= 0) {
        $len = 10;
    }
    foreach($psm as $v){
        $val = isset($param[$v]) && is_scalar($param[$v]) ? (string)$param[$v] : '';
        if(mb_strlen($val) > $len){
            $val = mac_substring($val, $len);
        }
        $param[$v] = $val;
    }
    return $param;
}

function mac_no_cahche()
{
    @header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    @header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . 'GMT');
    @header('Cache-Control: no-cache, must-revalidate');
    @header('Pragma: no-cache');
}

function mac_filter_tags($rs)
{
    // 注意：不再预替换 <iframe —— 资讯正文常含视频播放器嵌入，采集阶段截断会导致内容残缺。
    // iframe 的安全校验改由入库前 mac_clean_html（仅放行 http/https/协议相对 src）完成。
    $rex = array('{:','<script','<frameset','<object','onerror');
    if(is_array($rs)){
        foreach($rs as $k2=>$v2){
            if(!is_numeric($v2)){
                $rs[$k2] = str_ireplace($rex,'*',$rs[$k2]);
            }
        }
    }
    else{
        if(!is_numeric($rs)){
            $rs = str_ireplace($rex,'*',$rs);
        }
    }
    return $rs;
}

/*
 * ============================================================================
 * 安全相关工具函数
 * 说明：供各控制器/模型复用的统一安全原语，避免各处重复实现导致策略漂移。
 * ============================================================================
 */

/**
 * 仅允许 http/https 协议（阻断 file:// gopher:// dict:// php:// 等）。
 */
function mac_is_http_url($url)
{
    $parts = @parse_url((string)$url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    return in_array(strtolower($parts['scheme']), array('http', 'https'), true);
}

/**
 * 站点绝对根地址（支付回调等用）：优先 site_url 配置，缺协议时补全，最后回退 HTTP_HOST。
 * 避免直接信任 Host 头被注入到回调 URL。
 */
function mac_site_abs_url()
{
    $site_url = str_replace(["\r", "\n", "\t"], '', trim((string)config('maccms.site.site_url')));
    if ($site_url === '' && isset($GLOBALS['config']['site']['site_url'])) {
        $site_url = str_replace(["\r", "\n", "\t"], '', trim((string)$GLOBALS['config']['site']['site_url']));
    }
    $http_type = isset($GLOBALS['http_type']) ? (string)$GLOBALS['http_type'] : 'http://';
    if ($site_url === '') {
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        $host = str_replace(["\r", "\n", "\t"], '', $host);
        return rtrim($http_type . $host, '/');
    }
    if (stripos($site_url, 'http') !== 0) {
        $site_url = $http_type . $site_url;
    }
    return rtrim($site_url, '/');
}

/**
 * 判断 IP 是否属于公网可路由地址（排除私有/保留/链路本地/回环/CGNAT）。
 */
function mac_ip_is_public($ip)
{
    $ip = trim((string)$ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }
    // CGNAT 100.64.0.0/10（FILTER_FLAG_NO_PRIV_RANGE 不覆盖）
    if (preg_match('/^100\.(6[4-9]|[7-9][0-9]|1[0-2][0-9])\./', $ip)) {
        return false;
    }
    return true;
}

/**
 * IP 是否命中 IP/CIDR 列表（逗号分隔，如 "127.0.0.1,10.0.0.0/8"）。
 */
function mac_ip_in_list($ip, $list)
{
    $ip = trim((string)$ip);
    $list = trim((string)$list);
    if ($ip === '' || $list === '') {
        return false;
    }
    foreach (explode(',', $list) as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        if (strpos($item, '/') === false) {
            if ($item === $ip) {
                return true;
            }
            continue;
        }
        list($net, $bits) = array_pad(explode('/', $item, 2), 2, '');
        $bits = intval($bits);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            continue;
        }
        if ($bits <= 0) {
            $mask = 0;
        } elseif ($bits >= 32) {
            $mask = 0xFFFFFFFF;
        } else {
            $mask = (~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF;
        }
        if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
            return true;
        }
    }
    return false;
}

/**
 * SSRF 防护：校验外部 URL 是否可安全请求。
 * 仅 http/https；主机必须是可解析的公网地址（除非显式允许内网）。
 */
function mac_safe_url($url, $allow_private = false)
{
    if (!mac_is_http_url($url)) {
        return false;
    }
    $parts = @parse_url((string)$url);
    $host = isset($parts['host']) ? trim($parts['host'], '[]') : '';
    if ($host === '') {
        return false;
    }
    $ips = array();
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        // gethostbyname 只返回单条 A 记录，攻击者可用"多条 A 记录（先公网后内网）"
        // 或 DNS rebinding 绕过校验；改为取全部 A/AAAA 记录并逐条校验，任一为内网即拒绝。
        if (function_exists('dns_get_record')) {
            foreach (array(DNS_A, DNS_AAAA) as $rtype) {
                $records = @dns_get_record($host, $rtype);
                if (is_array($records)) {
                    foreach ($records as $rec) {
                        if (!empty($rec['ip'])) {
                            $ips[] = $rec['ip'];
                        } elseif (!empty($rec['ipv6'])) {
                            $ips[] = $rec['ipv6'];
                        }
                    }
                }
            }
        }
        if (empty($ips)) {
            $resolved = @gethostbyname($host);
            if (!empty($resolved) && $resolved !== $host) {
                $ips[] = $resolved;
            }
        }
        if (empty($ips)) {
            return false;   // 无法解析
        }
    }
    foreach ($ips as $ip) {
        if (!mac_ip_is_public($ip) && !$allow_private) {
            return false;
        }
    }
    return true;
}

/**
 * 递归 HTML 转义（用于把请求数组整体 assign 给模板前的兜底）。
 */
function mac_escape_array($data)
{
    if (is_array($data)) {
        $out = array();
        foreach ($data as $k => $v) {
            $out[$k] = mac_escape_array($v);
        }
        return $out;
    }
    if (is_string($data)) {
        return htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return $data;
}

/**
 * 白名单 HTML 净化（采集/接口推送入库前使用）。
 * 无 DOM 扩展时退化为纯文本，绝不放行原始标签。
 */
function mac_clean_html($html, $allow = '')
{
    $html = (string)$html;
    if ($html === '') {
        return '';
    }
    if (trim($allow) === '') {
        // 结构标签保留；script/object 等丢弃；iframe/video 放行（src 限 http/https，资讯嵌入播放器用）
        $allow = 'p,br,strong,b,em,i,u,s,ul,ol,li,blockquote,hr,h1,h2,h3,h4,h5,h6,'
               . 'div,span,dl,dt,dd,figure,figcaption,section,article,header,footer,main,nav,aside,'
               . 'img,a,table,thead,tbody,tfoot,tr,td,th,iframe,video,source';
    }
    $allowTags = array_values(array_filter(array_map('trim', explode(',', strtolower($allow)))));
    $allowAttrs = array('href', 'src', 'alt', 'title', 'width', 'height', 'colspan', 'rowspan',
        'frameborder', 'allow', 'allowfullscreen', 'scrolling', 'referrerpolicy', 'loading',
        'sandbox', 'controls', 'poster', 'preload', 'playsinline', 'type');
    if (!class_exists('DOMDocument')) {
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8"><div id="mac-clean-root">' . $html . '</div>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    $root = null;
    foreach ($doc->getElementsByTagName('div') as $div) {
        if ($div->getAttribute('id') === 'mac-clean-root') {
            $root = $div;
            break;
        }
    }
    if (!$root) {
        return '';
    }
    mac_clean_html_node($root, $allowTags, $allowAttrs);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/**
 * 解包 DOM 节点：把子节点提升到父节点位置后移除自身（保留内容结构）。
 */
function mac_unwrap_html_node($el)
{
    $parent = $el->parentNode;
    if (!$parent) {
        return;
    }
    while ($el->firstChild) {
        $parent->insertBefore($el->firstChild, $el);
    }
    $parent->removeChild($el);
}

/**
 * mac_clean_html 的递归节点处理（内部函数）。
 */
function mac_clean_html_node($node, $allowTags, $allowAttrs)
{
    if (!$node->hasChildNodes()) {
        return;
    }
    // 索引式遍历：解包后索引不前进，保证提升上来的子节点也被净化；$ci/$ai 勿混用
    $dropTags = array('script', 'style', 'object', 'embed', 'form', 'input',
                      'button', 'link', 'meta', 'base', 'svg', 'math', 'template');
    $ci = 0;
    while ($ci < $node->childNodes->length) {
        $child = $node->childNodes->item($ci);
        if ($child === null) {
            break;
        }
        if ($child->nodeType === XML_COMMENT_NODE) {
            $node->removeChild($child);
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            $ci++;
            continue;
        }
        $tag = strtolower($child->nodeName);
        if (!in_array($tag, $allowTags, true)) {
            if (in_array($tag, $dropTags, true)) {
                $node->removeChild($child);
            } else {
                // 未知但非危险标签：解包保留子节点与文本；索引不前进 —— 下一轮净化提升上来的节点
                mac_unwrap_html_node($child);
            }
            continue;
        }
        // iframe：无 http(s)/协议相对 src 则整棵丢弃，避免空壳或 javascript: 嵌入
        if ($tag === 'iframe') {
            $src = trim((string)$child->getAttribute('src'));
            if ($src === '' || !preg_match('#^(https?:)?//#i', $src)
                || preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $src)) {
                $node->removeChild($child);
                continue;
            }
        }
        if ($child->hasAttributes()) {
            for ($ai = $child->attributes->length - 1; $ai >= 0; $ai--) {
                $attr = $child->attributes->item($ai);
                $name = strtolower($attr->name);
                $drop = (strpos($name, 'on') === 0) || !in_array($name, $allowAttrs, true);
                if (!$drop && in_array($name, array('href', 'src'), true)) {
                    $val = trim($attr->value);
                    if (preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $val)
                        && stripos($val, 'data:image/') !== 0) {
                        $drop = true;
                    }
                }
                if ($drop) {
                    $child->removeAttribute($attr->name);
                }
            }
        }
        // iframe：清空子节点；去掉可放宽权限的 sandbox/allow，避免采集源自定沙箱逃逸
        if ($tag === 'iframe') {
            while ($child->firstChild) {
                $child->removeChild($child->firstChild);
            }
            $child->removeAttribute('sandbox');
            $child->removeAttribute('allow');
            if (!$child->hasAttribute('referrerpolicy')) {
                $child->setAttribute('referrerpolicy', 'no-referrer');
            }
            $ci++;
            continue;
        }
        mac_clean_html_node($child, $allowTags, $allowAttrs);
        $ci++;
    }
}

/**
 * 销毁当前验证码（安全）。
 * think-captcha 仅在**校验成功**时删除 session 中的验证码，导致同一张图可被在线反复枚举
 * 本函数按内核相同的密钥推导规则（vendor/topthink/think-captcha/src/Captcha.php:authcode）
 * 主动删除，用于"失败即作废"。
 */
function mac_captcha_destroy($id = '')
{
    $seKey = 'ThinkPHP.CN';
    $cfg = config('captcha');
    if (is_array($cfg) && !empty($cfg['seKey'])) {
        $seKey = $cfg['seKey'];
    }
    $key = md5(substr(md5($seKey), 5, 8) . substr(md5($seKey), 8, 10));
    \think\Session::delete($key . $id, '');
}

/**
 * 验证码校验（失败即作废）。
 * 用法与 captcha_check 完全一致，额外保证：一次错误的尝试会销毁本次挑战
 * 使攻击者无法对同一张验证码暴力枚举（原实现 10^4 数字空间 + 不销毁 ≈ 200 秒可破）。
 */
function mac_captcha_check($code, $id = '')
{
    // 官方 topthink/think-captcha 1.0.8 的 captcha_check($code, $id) 只有两参：
    // 额外的配置参数不会被该函数使用，仍会 new Captcha([])，
    // extra/captcha.php 的 length/expire 无法生效。这里直接带配置构造。
    if (!class_exists('\\think\\captcha\\Captcha')) {
        return false;
    }
    $captcha = new \think\captcha\Captcha((array)config('captcha'));
    if ($captcha->check($code, $id)) {
        return true;
    }
    mac_captcha_destroy($id);
    return false;
}

/**
 * 后台 JS 使用的会话级稳定 CSRF 令牌。
 * 背景：{$Request.token} 会被 Loader::validate('Token') 在校验后消费（成功与失败都删）
 * 页面上异步操作（删除/开关/清缓存）若沿用同一令牌，会在任意一次表单提交后集体 403。
 * 故 JS 侧使用本函数返回的**独立、不被消费**的会话令牌；CsrfGuard 对两种令牌都接受。
 */
function mac_csrf_token()
{
    $t = \think\Session::get('__mac_csrf');
    if (!is_string($t) || strlen($t) < 16) {
        $t = bin2hex(random_bytes(16));
        \think\Session::set('__mac_csrf', $t);
    }
    return $t;
}

/**
 * 定时任务入口授权（安全）。
 * 规则（与 admin/controller/Base.php 保持一致）：
 * - 配置了 app.cron_key：必须 hash_equals 通过
 * - 未配置：仅允许本机回环调用（只信 TCP 对端 REMOTE_ADDR，绝不使用可伪造的转发头）。
 * 说明：api/controller/Timming.php 在写 runtime 之前必须先调用本函数
 * 否则被拒请求也会把任务标记为"本时段已执行"。
 */
function mac_cron_authorized()
{
    $key = isset($GLOBALS['config']['app']['cron_key']) ? (string)$GLOBALS['config']['app']['cron_key'] : '';
    $got = input('get.cron_key', '');
    $got = is_string($got) ? $got : '';
    if ($key !== '') {
        return hash_equals($key, $got);
    }
    $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    return in_array($remote, array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true);
}

/**
 * 生成口令哈希（新写入一律使用 password_hash）。
 */
function mac_password_make($plain)
{
    return password_hash((string)$plain, PASSWORD_DEFAULT);
}

/**
 * 口令校验：兼容历史无盐 MD5（32 位），新哈希走 password_verify。
 */
function mac_password_verify($plain, $hash)
{
    $plain = (string)$plain;
    $hash = (string)$hash;
    if ($hash === '') {
        return false;
    }
    if (strlen($hash) > 32) {
        return password_verify($plain, $hash);
    }
    return hash_equals($hash, md5($plain));
}

/**
 * 判断存量哈希是否需要升级为 password_hash。
 */
function mac_password_needs_upgrade($hash)
{
    $hash = (string)$hash;
    if ($hash === '' || strlen($hash) <= 32) {
        return true;
    }
    return password_needs_rehash($hash, PASSWORD_DEFAULT);
}

if (!function_exists('is_really_writable')) {

    /**
     * 判断文件或文件夹是否可写
     * @param string $file 文件或目录
     * @return bool
     */
    function is_really_writable($file)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return is_writable($file);
        }
        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/' . md5(mt_rand());
            if (($fp = @fopen($file, 'ab')) === false) {
                return false;
            }
            fclose($fp);
            @chmod($file, 0777);
            @unlink($file);
            return true;
        } elseif (!is_file($file) or ($fp = @fopen($file, 'ab')) === false) {
            return false;
        }
        fclose($fp);
        return true;
    }
}
if (!function_exists('rmdirs')) {

    /**
     * 删除文件夹
     * @param string $dirname 目录
     * @param bool $withself 是否删除自身
     * @return boolean
     */
    function rmdirs($dirname, $withself = true)
    {
        if (!is_dir($dirname)) {
            return false;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        if ($withself) {
            @rmdir($dirname);
        }
        return true;
    }
}
if (!function_exists('copydirs')) {

    /**
     * 复制文件夹
     * @param string $source 源文件夹
     * @param string $dest 目标文件夹
     */
    function copydirs($source, $dest)
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        foreach (
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            ) as $item
        ) {
            if ($item->isDir()) {
                $sontDir = $dest . DS . $iterator->getSubPathName();
                if (!is_dir($sontDir)) {
                    mkdir($sontDir, 0755, true);
                }
            } else {
                copy($item, $dest . DS . $iterator->getSubPathName());
            }
        }
    }
}

/**
 * 校验模板目录名；目录名非法或 template/ 下不存在时回退 default。
 * 用于兼容配置仍指向已合并的 niuniu 主题的旧站点。
 */
function mac_tpl_dir_safe($dir)
{
    $dir = (string)$dir;
    if ($dir === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $dir) || !is_dir(ROOT_PATH . 'template/' . $dir)) {
        return 'default';
    }
    return $dir;
}
