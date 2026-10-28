<?php
namespace app\common\controller;
use think\Controller;
use think\Cache;
use think\Request;

class All extends Controller
{
    var $_ref;
    var $_cl;
    var $_ac;
    var $_tsp;
    var $_url;

    public function __construct()
    {
        parent::__construct();
        $this->_ref = mac_get_refer();
        $this->_cl = request()->controller();
        $this->_ac = request()->action();
        $this->_tsp = date('Ymd');
        $this->assign('obj', []);
        $this->assign('type_id', 0);
    }

    /**
     * 页面缓存准入判断（安全）。
     * 原实现用未校验的 $_SERVER['HTTP_HOST'] 作缓存键，且会员已登录时也写公共缓存
     * 可造成 Host 头分片投毒与"付费播放页被广播给游客"。现规则：
     * - 缓存键改用配置域名（site_url），不再使用请求头
     * - 携带会员 cookie 的请求不读也不写页面缓存（个性化页面不共享）。
     */
    protected function page_cache_allowed()
    {
        if (!defined('ENTRANCE') || ENTRANCE != 'index') {
            return false;
        }
        if (empty($GLOBALS['config']['app']['cache_page']) || empty($GLOBALS['config']['app']['cache_time_page'])) {
            return false;
        }
        if (!empty($_COOKIE['user_id']) || !empty($_COOKIE['user_check'])) {
            return false;
        }
        return true;
    }

    protected function page_cache_key($tpl)
    {
        $site = isset($GLOBALS['config']['site']) ? $GLOBALS['config']['site'] : array();
        $host = !empty($site['site_url']) ? strtolower($site['site_url']) : 'default';
        return $host . '_' . MAC_MOB . '_' . $GLOBALS['config']['app']['cache_flag'] . '_' . $tpl . '_' . http_build_query(mac_param_url());
    }

    protected function load_page_cache($tpl,$type='html')
    {
        if($this->page_cache_allowed()) {
            $cach_name = $this->page_cache_key($tpl);
            $res = Cache::get($cach_name);
            if ($res) {
                // 修复后台开启页面缓存时，模板json请求解析问题
                // https://github.com/magicblack/maccms10/issues/965
                if($type=='json' || str_contains(request()->header('accept'), 'application/json')){
                    $res = json_encode($res);
                }
                echo $res;
                die;
            }
        }
    }

    protected function label_fetch($tpl,$loadcache=1,$type='html')
    {
        if($loadcache==1){
            $this->load_page_cache($tpl,$type);
        }


        $html = $this->fetch($tpl);
        if($GLOBALS['config']['app']['compress'] == 1){
            $html = mac_compress_html($html);
        }
        if($this->page_cache_allowed()){
            $cach_name = $this->page_cache_key($tpl);
            $res = Cache::set($cach_name,$html,$GLOBALS['config']['app']['cache_time_page']);
        }
        if (strtolower(request()->controller()) != 'rss' && isset($GLOBALS['config']['site']['site_polyfill']) && $GLOBALS['config']['site']['site_polyfill'] == 1){
            // 安全：已移除远程 polyfill；如需兼容旧浏览器，自托管 static/js/polyfill.min.js
            $polyfill_local = ROOT_PATH . 'static/js/polyfill.min.js';
            if (is_file($polyfill_local)) {
                $polyfill = '<script src="/static/js/polyfill.min.js"></script>';
                $html = str_replace('</body>', $polyfill . '</body>', $html);
            }
        }
        return $html;
    }

    protected function label_maccms()
    {
        $maccms = $GLOBALS['config']['site'];
        $maccms['path'] = MAC_PATH;
        $maccms['path_tpl'] = $GLOBALS['MAC_PATH_TEMPLATE'];
        $maccms['path_ads'] = $GLOBALS['MAC_PATH_ADS'];
        $maccms['user_status'] = $GLOBALS['config']['user']['status'];
        // PHP8：模板 user/login.html 用 {$maccms.user_login_verify} 决定是否显示验证码
        // 但该键此前从未写入 $maccms → 每次都触发 Undefined array key，且登录页验证码开关失效。补齐。
        $maccms['user_login_verify'] = intval($GLOBALS['config']['user']['login_verify'] ?? 0);
        $maccms['date'] = date('Y-m-d');

        $maccms['search_hot'] = $GLOBALS['config']['app']['search_hot'];
        $maccms['art_extend_class'] = $GLOBALS['config']['app']['art_extend_class'];
        $maccms['vod_extend_class'] = $GLOBALS['config']['app']['vod_extend_class'];
        $maccms['vod_extend_state'] = $GLOBALS['config']['app']['vod_extend_state'];
        $maccms['vod_extend_version'] = $GLOBALS['config']['app']['vod_extend_version'];
        $maccms['vod_extend_area'] = $GLOBALS['config']['app']['vod_extend_area'];
        $maccms['vod_extend_lang'] = $GLOBALS['config']['app']['vod_extend_lang'];
        $maccms['vod_extend_year'] = $GLOBALS['config']['app']['vod_extend_year'];
        $maccms['vod_extend_weekday'] = $GLOBALS['config']['app']['vod_extend_weekday'];
        $maccms['actor_extend_area'] = $GLOBALS['config']['app']['actor_extend_area'];

        $maccms['http_type'] = $GLOBALS['http_type'];
        $maccms['http_url'] = $GLOBALS['http_type']. ''.$_SERVER['SERVER_NAME'].($_SERVER["SERVER_PORT"]==80 ? '' : ':'.$_SERVER["SERVER_PORT"]).$_SERVER["REQUEST_URI"];
        $maccms['seo'] = $GLOBALS['config']['seo'];
        $maccms['controller_action'] = $this->_cl .'/'.$this->_ac;
        $maccms['mid'] = 0;
        $maccms['aid'] = 0;

        if(!empty($GLOBALS['mid'])) {
            $maccms['mid'] = $GLOBALS['mid'];
        }
        else{
            $maccms['mid'] = mac_get_mid($this->_cl);
        }
        if(!empty($GLOBALS['aid'])) {
            $maccms['aid'] = $GLOBALS['aid'];
        }
        else{
            $maccms['aid'] = mac_get_aid($this->_cl,$this->_ac);
        }
        $this->assign( ['maccms'=>$maccms] );
    }

    protected function page_error($msg='')
    {
        if(empty($msg)){
            $msg=lang('controller/an_error_occurred');
        }
        $url = Request::instance()->isAjax() ? '' : 'javascript:history.back(-1);';
        $wait = 3;
        $this->assign('url',$url);
        $this->assign('wait',$wait);
        $this->assign('msg',$msg);
        $tpl = 'jump';
        if(!empty($GLOBALS['config']['app']['page_404'])){
            $tpl = $GLOBALS['config']['app']['page_404'];
        }
        $html = $this->label_fetch('public/'.$tpl);
        http_response_code(404);
        header("Status: 404 Not Found");
        exit($html);
    }

    protected function label_user()
    {
        if(ENTRANCE != 'index'){
            return;
        }
        $user_id = intval(cookie('user_id'));
        $user_name = cookie('user_name');
        $user_check = cookie('user_check');

        $user = ['user_id'=>0,'user_name'=>lang('controller/visitor'),'user_portrait'=>'static/images/touxiang.png','group_id'=>1,'points'=>0];
        $group_list = model('Group')->getCache();

        if(!empty($user_id) && !empty($user_name) && !empty($user_check)){
            $res = model('User')->checkLogin();
            if($res['code'] == 1){
                $user = $res['info'];
            }
            else{
                cookie('user_id','0');
                cookie('user_name',lang('controller/visitor'));
                cookie('user_check','');
                $user['group'] = $group_list[1];
            }
        }
        else{
            $user['group'] = $group_list[1];
        }
        $GLOBALS['user'] = $user;
        $this->assign('user',$user);
    }

    protected function label_comment()
    {
        $comment = config('maccms.comment');
        $this->assign('comment',$comment);
    }

    protected function label_search($param)
    {
        $param = mac_filter_words($param);
        $param = mac_search_len_check($param);
        // vod/search 各个参数下都可能出现回显关键词
        if(!empty($GLOBALS['config']['app']['wall_filter'])){
            $param = mac_escape_param($param);
        }
        $this->assign('param',$param);
    }

    protected function label_type($view=0, $type_id_specified = 0)
    {
        $param = mac_param_url();
        $param = mac_filter_words($param);
        $param = mac_search_len_check($param);
        $info = mac_label_type($param, $type_id_specified);
        if(!empty($GLOBALS['config']['app']['wall_filter'])){
            $param['wd'] = mac_escape_param($param['wd']);
        }
        $this->assign('param',$param);
        $this->assign('obj',$info);
        $this->assign('type_id', intval($info['type_id'] ?? 0));
        if(empty($info)){
            return $this->error(lang('controller/get_type_err'));
        }
        if($view<2) {
            $res = $this->check_user_popedom($info['type_id'], 1);
            if($res['code']>1){
                echo $this->error($res['msg'], mac_url('user/index') );
                exit;
            }
        }
        return $info;
    }

    protected function label_actor($total='')
    {
        $param = mac_param_url();
        $this->assign('param',$param);
    }

    protected function label_actor_detail($info=[],$view=0)
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_actor_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }

        if(empty($info['actor_tpl'])){
            $info['actor_tpl'] = $info['type']['type_tpl_detail'];
        }

        if($view <2) {
            $popedom = $this->check_user_popedom($info['type_id'], 2,$param,'actor',$info);
            $this->assign('popedom',$popedom);

            if($popedom['code']>1){
                $this->assign('obj',$info);

                if(($popedom['confirm'] ?? 0)==1){
                    echo $this->fetch('actor/confirm');
                    exit;
                }

                echo $this->error($popedom['msg'], mac_url('user/index') );
                exit;
            }
        }

        $this->assign('obj',$info);
        $this->assign('type_id', intval($info['type_id'] ?? 0));
        $comment = config('maccms.comment');
        $this->assign('comment',$comment);
        return $info;
    }


    protected function label_role($total='')
    {
        $param = mac_param_url();
        $param = mac_filter_words($param);
        $param = mac_search_len_check($param);
        if(!empty($GLOBALS['app']['wall_filter'])){
            $param['wd'] = mac_escape_param($param['wd']);
        }
        $this->assign('param',$param);
    }

    protected function label_role_detail($info=[])
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_role_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }
        $this->assign('obj',$info);
        $this->assign('type_id', intval($info['type_id'] ?? 0));
        $comment = config('maccms.comment');
        $this->assign('comment',$comment);

        return $info;
    }

    protected function label_website_detail($info=[],$view=0)
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_website_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }

        if(empty($info['website_tpl'])){
            $info['website_tpl'] = $info['type']['type_tpl_detail'];
        }

        if($view <2) {
            $popedom = $this->check_user_popedom($info['type_id'], 2,$param,'website',$info);
            $this->assign('popedom',$popedom);

            if($popedom['code']>1){
                $this->assign('obj',$info);

                if(($popedom['confirm'] ?? 0)==1){
                    echo $this->fetch('website/confirm');
                    exit;
                }

                echo $this->error($popedom['msg'], mac_url('user/index') );
                exit;
            }
        }

        $this->assign('obj',$info);
        $comment = config('maccms.comment');
        $this->assign('comment',$comment);

        return $info;
    }

    protected function label_topic_index($total='')
    {
        $param = mac_param_url();
        $this->assign('param',$param);

        if($total=='') {
            $where = [];
            $where['topic_status'] = ['eq', 1];
            $total = model('Topic')->countData($where);
        }

        $url = mac_url_topic_index(['page'=>'PAGELINK']);
        $__PAGING__ = mac_page_param($total,1,$param['page'],$url);
        $this->assign('__PAGING__',$__PAGING__);
    }

    protected function label_topic_detail($info=[])
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_topic_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }
        $this->assign('obj',$info);
        $this->assign('type_id', intval($info['type_id'] ?? 0));

        $comment = config('maccms.comment');
        $this->assign('comment',$comment);

        return $info;
    }

    protected function label_art_detail($info=[],$view=0)
    {
        $param = mac_param_url();
        $this->assign('param',$param);

        if(empty($info)) {
            $res = mac_label_art_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }
        if(empty($info['art_tpl'])){
            $info['art_tpl'] = $info['type']['type_tpl_detail'];
        }

        if($view <2) {
            $popedom = $this->check_user_popedom($info['type_id'], 2,$param,'art',$info);
            $this->assign('popedom',$popedom);

            if($popedom['code']>1){
                $this->assign('obj',$info);

                if(($popedom['confirm'] ?? 0)==1){
                    echo $this->fetch('art/confirm');
                    exit;
                }

                echo $this->error($popedom['msg'], mac_url('user/index') );
                exit;
            }
        }

        $this->assign('obj',$info);
        $this->assign('type_id', intval($info['type_id'] ?? 0));

        $url = mac_url_art_detail($info,['page'=>'PAGELINK']);

        $__PAGING__ = mac_page_param(max(1, (int)($info['art_page_total'] ?? 1)),1,$param['page'],$url);
        $this->assign('__PAGING__',$__PAGING__);

        $this->label_comment();

        return $info;
    }

    protected function label_vod_detail($info=[],$view=0)
    {
        $param = mac_param_url();

        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_vod_detail($param);
            if ($res['code'] > 1){
                $this->page_error($res['msg']);
            }
            $info = $res['info'];
        }

        if(empty($info['vod_tpl'])){
            $info['vod_tpl'] = $info['type']['type_tpl_detail'] ?? '';
        }
        if(empty($info['vod_tpl_play'])){
            $info['vod_tpl_play'] = $info['type']['type_tpl_play'] ?? '';
        }
        if(empty($info['vod_tpl_down'])){
            $info['vod_tpl_down'] = $info['type']['type_tpl_down'] ?? '';
        }

        if($view <2) {
            $res = $this->check_user_popedom($info['type']['type_id'] ?? 0, 2);
            if($res['code']>1){
                echo $this->error($res['msg'], mac_url('user/index') );
                exit;
            }
        }
        $this->assign('obj',$info);
        $this->assign('type_id', intval($info['type_id'] ?? 0));
        $this->label_comment();

        return $info;
    }

    protected function label_vod_role($info=[],$view=0)
    {
        $param = mac_param_url();
        $this->assign('param', $param);

        if (empty($info)) {
            $res = mac_label_vod_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);
            }
            $info = $res['info'];
        }
        $role = mac_label_vod_role(['rid'=>intval($info['vod_id'])]);
        if ($role['code'] > 1) {
            return $this->error($role['msg']);
        }
        $info['role'] = $role['list'];

        $this->assign('obj',$info);
    }

    protected function label_vod_play($flag='play',$info=[],$view=0,$pe=0)
    {
        $param = mac_param_url();

        if(empty($info)) {
            $res = mac_label_vod_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);
            }
            $info = $res['info'];
        }
        if(empty($info['vod_tpl'])){
            $info['vod_tpl'] = $info['type']['type_tpl_detail'] ?? '';
        }
        if(empty($info['vod_tpl_play'])){
            $info['vod_tpl_play'] = $info['type']['type_tpl_play'] ?? '';
        }
        if(empty($info['vod_tpl_down'])){
            $info['vod_tpl_down'] = $info['type']['type_tpl_down'] ?? '';
        }


        $trysee = 0;
        $urlfun='mac_url_vod_'.$flag;
        $listfun = 'vod_'.$flag.'_list';
        $source_list = isset($info[$listfun]) && is_array($info[$listfun]) ? $info[$listfun] : [];
        $sid = intval($param['sid'] ?? 0);
        if($sid < 1 || empty($source_list[$sid])){
            $sid = intval(key($source_list));
        }
        $current_source = ($sid > 0 && !empty($source_list[$sid]) && is_array($source_list[$sid])) ? $source_list[$sid] : [];
        $current_urls = isset($current_source['urls']) && is_array($current_source['urls']) ? $current_source['urls'] : [];
        $nid = intval($param['nid'] ?? 0);
        if($nid < 1 || empty($current_urls[$nid])){
            $nid = intval(key($current_urls));
        }
        $current_item = ($nid > 0 && !empty($current_urls[$nid]) && is_array($current_urls[$nid])) ? $current_urls[$nid] : [];
        if($sid < 1 || $nid < 1 || empty($current_item)){
            $this->page_error(lang('admin/vod/player_empty'));
        }
        $param['sid'] = $sid;
        $param['nid'] = $nid;
        $this->assign('param',$param);

        if($view <2) {
            if ($flag == 'play') {
                $trysee = $GLOBALS['config']['user']['trysee'];
                if($info['vod_trysee'] >0){
                    $trysee = $info['vod_trysee'];
                }
                $popedom = $this->check_user_popedom($info['type_id'], ($pe==0 ? 3 : 5),$param,$flag,$info,$trysee);
            }
            else {
                $popedom =  $this->check_user_popedom($info['type_id'], 4,$param,$flag,$info);
            }
            $this->assign('popedom',$popedom);


            if($pe==0 && $popedom['code']>1 && empty($popedom["trysee"])){
                $info['player_info']['flag'] = $flag;
                $this->assign('obj',$info);

                if(($popedom['confirm'] ?? 0)==1){
                    $this->assign('flag',$flag);
                    echo $this->fetch('vod/confirm');
                    exit;
                }
                echo $this->error($popedom['msg'], mac_url('user/index') );
                exit;
            }
        }

        $player_info=[];
        $player_info['flag'] = $flag;
        $player_info['encrypt'] = intval($GLOBALS['config']['app']['encrypt']);
        $player_info['trysee'] = intval($trysee);
        $player_info['points'] = intval($info['vod_points_'.$flag]);
        $player_info['link'] = $urlfun($info,['sid'=>'{sid}','nid'=>'{nid}']);
        $player_info['link_next'] = '';
        $player_info['link_pre'] = '';
        $player_info['name'] = (string)($current_item['name'] ?? '');
        $player_info['url_raw'] = (string)($current_item['url'] ?? '');
        $player_info['vod_data'] = [
            'vod_name'     => $info['vod_name'],
            'vod_actor'    => $info['vod_actor'],
            'vod_director' => $info['vod_director'],
            'vod_class'    => $info['vod_class'],
        ];
        if($param['nid']>1 && !empty($current_urls[$param['nid']-1])){
            $player_info['link_pre'] = $urlfun($info,['sid'=>$param['sid'],'nid'=>$param['nid']-1]);
        }
        $url_count = intval($current_source['url_count'] ?? count($current_urls));
        if($param['nid'] < $url_count && !empty($current_urls[$param['nid']+1])){
            $player_info['link_next'] = $urlfun($info,['sid'=>$param['sid'],'nid'=>$param['nid']+1]);
        }
        $player_info['url'] = (string)($current_item['url'] ?? '');
        $player_info['url_next'] = (string)($current_urls[$param['nid']+1]['url'] ?? '');

        if(substr($player_info['url'],0,6) == 'upload'){
            $player_info['url'] = MAC_PATH . $player_info['url'];
        }
        if(substr($player_info['url_next'],0,6) == 'upload'){
            $player_info['url_next'] = MAC_PATH . $player_info['url_next'];
        }

        $player_info['from'] = (string)($current_source['from'] ?? '');
        if((string)($current_item['from'] ?? '') != $player_info['from']){
            $player_info['from'] = (string)($current_item['from'] ?? '');
        }
        $player_info['server'] = (string)($current_source['server'] ?? '');
        $player_info['note'] = (string)($current_source['note'] ?? '');

        // 先留一份**未加密**明文副本
        // 下面按 app.encrypt 决定统一播放器 iframe 到底带明文还是密文 —— 详见 player_js 构造处注释。
        $__player_url_plain  = (string) $player_info['url'];
        $__player_next_plain = (string) ($player_info['url_next'] ?? '');
        $__player_pic_plain  = (string) mac_url_img((string) ($info['vod_pic'] ?? ''));

        if($GLOBALS['config']['app']['encrypt']=='1'){
            $player_info['url'] = mac_escape($player_info['url']);
            $player_info['url_next'] = mac_escape($player_info['url_next']);
        }
        elseif($GLOBALS['config']['app']['encrypt']=='2'){
            $player_info['url'] = base64_encode(mac_escape($player_info['url']));
            $player_info['url_next'] = base64_encode(mac_escape($player_info['url_next']));
        }
        // 安全：这三个值来自查询串，强制整型避免进入 JS 上下文
        $player_info['id'] = intval($param['id']);
        $player_info['sid'] = intval($param['sid']);
        $player_info['nid'] = intval($param['nid']);
        $info['player_info'] = $player_info;
        $this->assign('obj',$info);
        $this->assign('type_id', intval($info['type_id'] ?? 0));

        $pwd_key = '1-'.($flag=='play' ?'4':'5').'-'.$info['vod_id'];

        if( $pe==0 && $flag=='play' && ($popedom['trysee']>0 ) || ($info['vod_pwd_'.$flag]!='' && session($pwd_key)!='1') || ($info['vod_copyright']==1 && $GLOBALS['config']['app']['copyright_status']==4) ) {
            $id = $info['vod_id'];
            if($GLOBALS['config']['rewrite']['vod_id']==2){
                $id = mac_alphaID($info['vod_id'],false,$GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key']);
            }
            $dy_play = mac_url('index/vod/'.$flag.'er',['id'=>$id,'sid'=>$param['sid'],'nid'=>$param['nid']]);
            $this->assign('player_data','');
            $this->assign('player_js','<div class="MacPlayer" style="z-index:99999;width:100%;height:100%;margin:0px;padding:0px;"><iframe id="player_if" name="player_if" src="'.$dy_play.'" style="z-index:9;width:100%;height:100%;" border="0" marginWidth="0" frameSpacing="0" marginHeight="0" frameBorder="0" scrolling="no" allowfullscreen="allowfullscreen" mozallowfullscreen="mozallowfullscreen" msallowfullscreen="msallowfullscreen" oallowfullscreen="oallowfullscreen" webkitallowfullscreen="webkitallowfullscreen" ></iframe></div>');
        }
        else {
            // 安全：json_encode 默认不转义 < > /，单个 </script> 即可闭合内联脚本
            // 显式加 JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT 阻断 HTML 上下文逃逸。
            $this->assign('player_data', '<script type="text/javascript">var player_aaaa=' . json_encode($player_info, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>');

            // 播放器改为**统一播放器**
            // static/player/player.html（DPlayer + hls，可播 m3u8/mp4/flv）
            // 不再按 from 去 document.write 加载 /static/player/{from}.js。
            // 同时保留老契约（老主题/第三方插件依赖的三样东西）：
            // ① {$player_data} → window.player_aaaa（上面已 assign，未变）
            // ② static/js/playerconfig.js → window.MacPlayerConfig（后台「播放器设置」生成）
            // ③ static/js/player.js → window.MacPlayer（老 API；新版已**移除整套 base64 工具链**
            // 那是历史上隐藏投毒载荷（//union.maccms.la/html/top10.js）的载体）。
            // 顺序很重要：iframe 在前、脚本在后 —— player.js 会检查 #player_if 是否已存在
            // 存在就只补齐 API、不再自建容器，避免出现两个播放器。
            // 加密（app.encrypt）继续生效：iframe 里带明文还是密文由配置决定，并附 encrypt 标记
            // 由 player.html 用与老 player.js::Init 同一套算法还原（1→unescape；2→unescape(base64)）。
            $__playCfg = (isset($GLOBALS['config']['play']) && is_array($GLOBALS['config']['play'])) ? $GLOBALS['config']['play'] : [];
            $__pW = (string) ($__playCfg['width']  ?? '');
            $__pH = (string) ($__playCfg['height'] ?? '');
            if (defined('MAC_MOB') && MAC_MOB) {
                if (!empty($__playCfg['widthmob']))  { $__pW = (string) $__playCfg['widthmob']; }
                if (!empty($__playCfg['heightmob'])) { $__pH = (string) $__playCfg['heightmob']; }
            }
            if (strpos($__pW, 'px') === false && strpos($__pW, '%') === false) { $__pW = '100%'; }
            if (strpos($__pH, 'px') === false && strpos($__pH, '%') === false) { $__pH = '100%'; }

            // ps / parse：复刻老 player.js::Init 的语义（组内 ps=1 → 组自定义 parse 优先 → 全局 parse）
            $__playerCfgAll = config('vodplayer');
            $__playerCfg = (is_array($__playerCfgAll) && isset($__playerCfgAll[$player_info['from']]) && is_array($__playerCfgAll[$player_info['from']]))
                ? $__playerCfgAll[$player_info['from']] : [];
            $__psVal = (string) ($__playerCfg['ps'] ?? '');
            $__parseApi = '';
            if ($__psVal === '1') {
                $__parseApi = (string) ($__playerCfg['parse'] ?? '');
                if ($__parseApi === '') {
                    $__parseApi = (string) ($__playCfg['parse'] ?? '');
                }
            }
            $__enc = intval($GLOBALS['config']['app']['encrypt']);
            $__encOn = ($__enc === 1 || $__enc === 2);
            $__iframeUrl  = $__encOn ? (string) $player_info['url'] : $__player_url_plain;
            $__iframeNext = $__encOn ? (string) ($player_info['url_next'] ?? '') : $__player_next_plain;

            $__pjq = MAC_PATH . 'static/player/player.html?url=' . rawurlencode($__iframeUrl);
            if ($__iframeNext !== '') {
                $__pjq .= '&next=' . rawurlencode($__iframeNext);
            }
            if ($__player_pic_plain !== '') {
                $__pjq .= '&pic=' . rawurlencode($__player_pic_plain);
            }
            $__pjq .= '&encrypt=' . $__enc
                . '&from=' . rawurlencode((string) $player_info['from'])
                . '&id=' . intval($player_info['id'])
                . '&sid=' . intval($player_info['sid'])
                . '&nid=' . intval($player_info['nid'])
                . '&server=' . rawurlencode((string) $player_info['server'])
                . '&note=' . rawurlencode((string) $player_info['note'])
                . '&link=' . rawurlencode((string) ($player_info['link'] ?? ''))
                . '&flag=' . rawurlencode((string) $flag)
                . '&ps=' . rawurlencode($__psVal)
                . '&parse_api=' . rawurlencode($__parseApi)
                . '&second=' . rawurlencode((string) ($__playCfg['second'] ?? ''))
                . '&prestrain=' . rawurlencode((string) ($__playCfg['prestrain'] ?? ''))
                . '&buffer=' . rawurlencode((string) ($__playCfg['buffer'] ?? ''))
                . '&autofull=' . rawurlencode((string) ($__playCfg['autofull'] ?? ''))
                . '&t=' . rawurlencode((string) $this->_tsp);
            if (!empty($player_info['link_next'])) {
                $__pjq .= '&link_next=' . rawurlencode((string) $player_info['link_next']);
            }
            if (!empty($player_info['link_pre'])) {
                $__pjq .= '&link_pre=' . rawurlencode((string) $player_info['link_pre']);
            }

            $this->assign('player_js',
                '<iframe id="player_if" name="player_if" class="MacPlayer" src="' . htmlspecialchars($__pjq, ENT_QUOTES, 'UTF-8') . '"'
                . ' style="z-index:9;width:' . htmlspecialchars($__pW, ENT_QUOTES, 'UTF-8') . ';height:' . htmlspecialchars($__pH, ENT_QUOTES, 'UTF-8') . ';border:0;"'
                . ' allowfullscreen="allowfullscreen" mozallowfullscreen="mozallowfullscreen"'
                . ' msallowfullscreen="msallowfullscreen" oallowfullscreen="oallowfullscreen"'
                . ' webkitallowfullscreen="webkitallowfullscreen" scrolling="no"></iframe>'
                . '<script type="text/javascript" src="' . MAC_PATH . 'static/js/playerconfig.js?t=' . $this->_tsp . '"></script>'
                . '<script type="text/javascript" src="' . MAC_PATH . 'static/js/player.js?t=a' . $this->_tsp . '"></script>');
        }
        $this->label_comment();
        return $info;
    }
}
