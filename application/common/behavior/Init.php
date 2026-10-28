<?php
namespace app\common\behavior;

use think\Cache;
use think\Exception;

class Init
{
    public function run(&$params)
    {
        // PHP 8 兼容 / CLI 可用性：ENTRANCE 由入口脚本定义
        // （index.php / admin.php / api.php / install.php）。用 php -r、本地验证脚本或计划任务探针
        // 引导 TP5 时没有入口脚本：PHP 7 下未定义常量只是 notice（取字符串 'ENTRANCE'，不匹配任何分支）
        // PHP 8 下升为致命 Error —— app_init 钩子直接抛
        // "Undefined constant app\common\behavior\ENTRANCE"，于是 runtime/log/<yyyymm>/<dd>_cli.log
        // 里留下这条错误（00:30 的 16_cli.log），CLI 侧任何脚本都跑不起来。
        // 兜底值取 'cli'：与 PHP 7 的等价语义一致（不匹配 index/admin/api/install 任一分支）
        // 因而不改变任何入口的行为；同时保护同进程后续对 ENTRANCE 的引用（application/common.php 2 处）。
        // 新站 app/common/compat/constants.php 有同类兜底（值 'index'，因其 CLI 静态生成需要前台模板）。
        if (!defined('ENTRANCE')) {
            define('ENTRANCE', 'cli');
        }

        $config = config('maccms');
        $domain = config('domain');

        // 安全：Host 头用于选择站点配置（多域名绑定），必须先规范化并校验字符
        // 避免大小写/端口差异导致匹配失败，也避免把非法 Host 当作数组键使用。
        $req_host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim((string)$_SERVER['HTTP_HOST'])) : '';
        if ($req_host !== '' && ($pos = strpos($req_host, ':')) !== false) {
            $req_host = substr($req_host, 0, $pos);   // 去掉端口
        }
        if ($req_host !== '' && !preg_match('/^[a-z0-9.\-]{1,253}$/', $req_host)) {
            $req_host = '';
        }

        $isMobile = 0;
        $ua = strtolower(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '');
        $uachar = "/(nokia|sony|ericsson|mot|samsung|sgh|lg|philips|panasonic|alcatel|lenovo|meizu|cldc|midp|iphone|wap|mobile|android)/i";
        if((preg_match($uachar, $ua))) {
            $isMobile = 1;
        }

        $isDomain=0;
        if( $req_host !== '' && is_array($domain) && !empty($domain[$req_host])){
            $config['site'] = array_merge($config['site'],$domain[$req_host]);
            $isDomain=1;
            if(empty($config['site']['mob_template_dir']) || $config['site']['mob_template_dir'] =='no'){
                $config['site']['mob_template_dir'] = $config['site']['template_dir'];
            }
            $config['site']['site_wapurl'] = $config['site']['site_url'];
            $config['site']['mob_html_dir'] = $config['site']['html_dir'];
            $config['site']['mob_ads_dir'] = $config['site']['ads_dir'];
        }
        $TMP_ISWAP = 0;
        $TMP_TEMPLATEDIR = $config['site']['template_dir'];
        $TMP_HTMLDIR = $config['site']['html_dir'];
        $TMP_ADSDIR = $config['site']['ads_dir'];

        if($isMobile && $isDomain==0){
            if( ($config['site']['mob_status']==2 ) || ($config['site']['mob_status']==1 && $req_host==$config['site']['site_wapurl']) || ($config['site']['mob_status']==1 && $isDomain) ) {
                $TMP_ISWAP = 1;
                $TMP_TEMPLATEDIR = $config['site']['mob_template_dir'];
                $TMP_HTMLDIR = $config['site']['mob_html_dir'];
                $TMP_ADSDIR = $config['site']['mob_ads_dir'];
            }
        }

        // 新主题已并入 template/default，原 template/niuniu 目录移除。
        // 旧站点配置若仍指向 niuniu（或任何已不存在的模板目录），运行时回退到 default，前台不至于整站报错。
        $TMP_TEMPLATEDIR = mac_tpl_dir_safe($TMP_TEMPLATEDIR);

        // 安全：MAC_URL 改为站点自身地址（原第三方域名已停用）
        define('MAC_URL', (!empty($config['site']['site_url'])) ? rtrim($config['site']['site_url'], '/') . '/' : '');
        define('MAC_NAME','苹果CMS');
        define('MAC_PATH', $config['site']['install_dir'] .'');
        define('MAC_MOB', $TMP_ISWAP);
        define('MAC_ROOT_TEMPLATE', ROOT_PATH .'template/'.$TMP_TEMPLATEDIR.'/'. $TMP_HTMLDIR .'/');
        define('MAC_PATH_TEMPLATE', MAC_PATH.'template/'.$TMP_TEMPLATEDIR.'/');
        define('MAC_PATH_TPL', MAC_PATH_TEMPLATE. $TMP_HTMLDIR  .'/');
        define('MAC_PATH_ADS', MAC_PATH_TEMPLATE. $TMP_ADSDIR  .'/');
        define('MAC_PAGE_SP', $config['path']['page_sp'] .'');
        define('MAC_PLAYER_SORT', isset($config['app']['player_sort']) ? $config['app']['player_sort'] : '' );
        define('MAC_ADDON_PATH', ROOT_PATH . 'addons' . '/');
        define('MAC_ADDON_PATH_STATIC', ROOT_PATH . 'static/addons/');

        $GLOBALS['MAC_ROOT_TEMPLATE'] = ROOT_PATH .'template/'.$TMP_TEMPLATEDIR.'/'. $TMP_HTMLDIR .'/';
        $GLOBALS['MAC_PATH_TEMPLATE'] = MAC_PATH.'template/'.$TMP_TEMPLATEDIR.'/';
        $GLOBALS['MAC_PATH_TPL'] = $GLOBALS['MAC_PATH_TEMPLATE']. $TMP_HTMLDIR  .'/';
        $GLOBALS['MAC_PATH_ADS'] = $GLOBALS['MAC_PATH_TEMPLATE']. $TMP_ADSDIR  .'/';

        $GLOBALS['http_type'] = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) ? 'https://' : 'http://';

        if(ENTRANCE=='index'){
            config('dispatch_success_tmpl','public/jump');
            config('dispatch_error_tmpl','public/jump');
        }

        config('template.view_path', 'template/' . $TMP_TEMPLATEDIR .'/' . $TMP_HTMLDIR .'/');

        if(ENTRANCE=='admin'){
            if(!file_exists('./template/' . $TMP_TEMPLATEDIR .'/' . $TMP_HTMLDIR .'/')){
                config('template.view_path','');
            }
        }
        // PHP8：
        // 下面多处直接读 $config['app'][...]，而 app 段在某些阶段（安装向导、旧配置升级）可能缺键。
        // 这里统一先补齐可能被读到的键，避免逐处 isset 造成遗漏。
        $appDefaults = array(
            'search_len'    => 10,
            'pathinfo_depr' => '/',
            'cache_time'    => 60,
            'cache_type'    => 'file',
            'cache_host'    => '',
            'cache_port'    => '',
            'cache_username'=> '',
            'cache_password'=> '',
            'lang'          => '',
            'player_sort'   => '',
        );
        if (!isset($config['app']) || !is_array($config['app'])) {
            $config['app'] = array();
        }
        foreach ($appDefaults as $k => $dv) {
            if (!array_key_exists($k, $config['app'])) {
                $config['app'][$k] = $dv;
            }
        }

        if(intval($config['app']['search_len'])<1){
            $config['app']['search_len'] = 10;
        }
        config('url_route_on',$config['rewrite']['route_status']);
        if(empty($config['app']['pathinfo_depr'])){
            $config['app']['pathinfo_depr'] = '/';
        }
        config('pathinfo_depr',$config['app']['pathinfo_depr']);

        if(intval($config['app']['cache_time'])<1){
            $config['app']['cache_time'] = 60;
        }
        config('cache.expire', $config['app']['cache_time'] );


        if(!in_array($config['app']['cache_type'],['file','memcache','memcached','redis'])){
            $config['app']['cache_type'] = 'file';
        }
        if(!empty($config['app']['lang'])){
            config('default_lang', $config['app']['lang']);
        } elseif (ENTRANCE == 'install') {
            // 安装向导阶段还没有 app.lang，保持框架默认（zh-cn），不写空值
            config('default_lang', 'zh-cn');
        }

        config('cache.type', $config['app']['cache_type']);
        config('cache.timeout',1000);
        config('cache.host',$config['app']['cache_host']);
        config('cache.port',$config['app']['cache_port']);
        config('cache.username',$config['app']['cache_username']);
        config('cache.password',$config['app']['cache_password']);
        if($config['app']['cache_type'] != 'file'){
            $opt = config('cache');
            Cache::$handler = null;
        }

        $GLOBALS['config'] = $config;

        // 稳定：$GLOBALS['type_id'] / type_pid 原先只在
        // 详情标签（mac_label_*_detail）里赋值，而各模型 listCacheData 的 "PAGELINK" 分页分支
        // 会直接读它（Vod/Art/Actor/Website），在没有详情上下文的请求里就产生
        // "Undefined global variable $type_id" + "Undefined array key 0" 两类告警。这里统一置 0
        // 语义与"当前无分类上下文"一致；详情标签随后仍会覆盖成真实值。
        if (!isset($GLOBALS['type_id'])) {
            $GLOBALS['type_id'] = 0;
        }
        if (!isset($GLOBALS['type_pid'])) {
            $GLOBALS['type_pid'] = 0;
        }
    }
}