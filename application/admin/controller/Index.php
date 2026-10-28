<?php
namespace app\admin\controller;
use think\Hook;
use think\Config;
use think\Session;

class Index extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function login()
    {
        if(Request()->isPost()) {
            $data = input('post.');
            $admin_name = isset($data['admin_name']) && is_string($data['admin_name']) ? $data['admin_name'] : '';
            // 安全：锁定键必须使用**不可伪造**的对端地址。
            // TP5.0.24 的 request->ip 默认高级模式会优先取 HTTP_X_FORWARDED_FOR（可任意伪造）
            // 攻击者每个请求换一个 XFF 即可完全绕过失败计数，故改用 $_SERVER['REMOTE_ADDR']。
            $client_ip = (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] !== '') ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
            // 账号维度固定 5 次/15 分钟；IP 维度阈值可配（app.admin_login_ip_limit，默认 0 = 关闭）。
            // 独立复核：CDN/反代之后所有请求的 REMOTE_ADDR 相同，固定 IP 阈值会让攻击者用
            // 少量错登就锁死全体管理员；故默认关闭，仅在确认能取到真实客户端 IP（trusted_proxies 已配）时启用。
            $fail_key    = 'admin_login_fail_' . md5($admin_name . '|' . $client_ip);
            $fail_ip_key = 'admin_login_fail_ip_' . md5($client_ip);
            $ip_limit = isset($GLOBALS['config']['app']['admin_login_ip_limit']) ? intval($GLOBALS['config']['app']['admin_login_ip_limit']) : 0;
            if (intval(cache($fail_key)) >= 5 || ($ip_limit > 0 && intval(cache($fail_ip_key)) >= $ip_limit)) {
                return $this->error('登录失败次数过多，请15分钟后再试');
            }
            $res = model('Admin')->login($data);
            if ($res['code'] > 1) {
                // 验证码失败等一律计数
                cache($fail_key, intval(cache($fail_key)) + 1, 900);
                if ($ip_limit > 0) {
                    cache($fail_ip_key, intval(cache($fail_ip_key)) + 1, 900);
                }
                // 验证码一次性：失败即作废（统一走公共助手，键推导与 think-captcha 内核一致）
                mac_captcha_destroy();
                return $this->error($res['msg']);
            }
            cache($fail_key, null);
            cache($fail_ip_key, null);
            return $this->success($res['msg']);
        }
        Hook::listen("admin_login_init", $this->request);
        // admin/view/index/login.html:16 用 $background 作为登录页背景
        // （{:htmlspecialchars((string)$background, ENT_QUOTES)}），但该变量全库从未被 assign →
        // 每次打开登录页触发 "Undefined variable $background"，且背景始终为空。这里补齐取值：
        // 兼容历史配置键 adminloginbg（无则空串，模板端已有 htmlspecialchars 转义）。
        $this->assign('background', (string)($GLOBALS['config']['app']['adminloginbg'] ?? ''));
        return $this->fetch('admin@index/login');
    }

    public function logout()
    {
        $res = model('Admin')->logout();
        $this->redirect('index/login');
    }

    public function index()
    {
        $menus = @include MAC_ADMIN_COMM . 'auth.php';

        foreach($menus as $k1=>$v1){
            foreach($v1['sub'] as $k2=>$v2){
                if($v2['show'] == 1) {
                    if(strpos($v2['action'],'javascript')!==false){
                        $url = $v2['action'];
                    }
                    else {
                        $url = url('admin/' . $v2['controller'] . '/' . $v2['action']);
                    }
                    if (!empty($v2['param'])) {
                        $url .= '?' . $v2['param'];
                    }
                    if ($this->check_auth($v2['controller'], $v2['action'])) {
                        $menus[$k1]['sub'][$k2]['url'] = $url;
                    } else {
                        unset($menus[$k1]['sub'][$k2]);
                    }
                }
                else{
                    unset($menus[$k1]['sub'][$k2]);
                }
            }

            if(empty($menus[$k1]['sub'])){
                unset($menus[$k1]);
            }
        }

        $quickmenu = config('quickmenu');
        if(empty($quickmenu)){
            $quickmenu = mac_read_file( APP_PATH.'data/config/quickmenu.txt');
            $quickmenu = explode(chr(13),$quickmenu);
        }
        if(!empty($quickmenu)){
            // 自定义快捷菜单固定挂在「首页」左侧（欢迎页侧栏可见）
            // 点到 nnzs/* 时由 admin_common.js 同步选中顶部「牛牛助手」，避免再注入到助手菜单造成双份。
            $menus[1]['sub'][13] = ['name'=>lang('admin/index/quick_tit'), 'url'=>'javascript:void(0);return false;','controller'=>'', 'action'=>'' ];

            foreach($quickmenu as $k=>$v){
                if(empty($v)){
                    continue;
                }
                $one = explode(',',trim($v));
                if(substr($one[1],0,4)=='http' || substr($one[1],0,2)=='//'){

                }
                elseif(substr($one[1],0,1) =='/'){

                }
                elseif(strpos($one[1],'###')!==false || strpos($one[1],'javascript:')!==false){

                }
                else{
                    $one[1] = url($one[1]);
                }
                $menus[1]['sub'][14 + $k] = ['name'=>$one[0], 'url'=>$one[1],'controller'=>'', 'action'=>'' ];
            }
        }
        $this->assign('menus',$menus);
        $this->assign('config',config('maccms'));

        $this->assign('title',lang('admin/index/title'));
        return $this->fetch('admin@index/index');
    }

    public function welcome()
    {
        $version = config('version');
        $update_sql = file_exists('./application/data/update/database.php');

        $this->assign('version',$version);
        $this->assign('update_sql',$update_sql);
        $this->assign('mac_lang',config('default_lang'));

        $this->assign('admin',$this->_admin);
        $this->assign('title',lang('admin/index/welcome/title'));
        return $this->fetch('admin@index/welcome');
    }

    public function quickmenu()
    {
        if(Request()->isPost()){
            $param = input();
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }
            $quickmenu = input('post.quickmenu');
            $quickmenu = str_replace(chr(10),'',$quickmenu);
            $menu_arr = explode(chr(13),$quickmenu);
            $res = mac_arr2file(APP_PATH . 'extra/quickmenu.php', $menu_arr);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }
        else{
            $config_menu = config('quickmenu');
            if(empty($config_menu)){
                $quickmenu = mac_read_file(APP_PATH.'data/config/quickmenu.txt');
            }
            else{
                $quickmenu = array_values($config_menu);
                $quickmenu = join(chr(13),$quickmenu);
            }
            $this->assign('quickmenu',$quickmenu);
            $this->assign('title',lang('admin/index/quickmenu/title'));
            return $this->fetch('admin@index/quickmenu');
        }
    }

    public function checkcache()
    {
        $res = 'no';
        $r = cache('cache_data');
        if($r=='1'){
            $res = 'haved';
        }
        echo $res;
    }

    public function clear()
    {
        $res = $this->_cache_clear();
        // 运行缓存
        if(!$res) {
            $this->error(lang('admin/index/clear_err'));
        }
        // 搜索缓存结果清理
        model('VodSearch')->clearOldResult(true);
        return $this->success(lang('admin/index/clear_ok'));
    }

    public function iframe()
    {
        $val = input('post.val', 0);
        if ($val != 0 && $val != 1) {
            return $this->error(lang('admin/index/clear_ok'));
        }
        if ($val == 1) {
            cookie('is_iframe', 'yes');
        } else {
            cookie('is_iframe', null);
        }
        return $this->success(lang('admin/index/iframe'));
    }

    public function unlocked()
    {
        $param = input();
        $password = isset($param['password']) && is_string($param['password']) ? $param['password'] : '';
        $admin_pwd = isset($this->_admin['admin_pwd']) ? (string)$this->_admin['admin_pwd'] : '';

        // password_verify 优先，兼容存量 MD5
        if(!password_verify($password, $admin_pwd) && !hash_equals($admin_pwd, md5($password))){
            return $this->error(lang('admin/index/pass_err'));
        }

        return $this->success(lang('admin/index/unlock_ok'));
    }

    public function check_back_link()
    {
        $param = input();
        $url = isset($param['url']) && is_string($param['url']) ? trim($param['url']) : '';
        // SSRF 防护：仅 http/https 且解析后的地址为公网，内网/回环/链路本地一律拒绝
        if (!function_exists('mac_safe_url') || !mac_safe_url($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }
        $res = mac_check_back_link($url);
        return json($res);
    }

    public function select()
    {
        $param = input();
        $tpl = ($param['tpl'] ?? null);
        $tab = ($param['tab'] ?? null);
        $col = ($param['col'] ?? null);
        $ids = ($param['ids'] ?? null);
        $url = ($param['url'] ?? null);
        $val = ($param['val'] ?? null);

        $refresh = ($param['refresh'] ?? null);

        if(empty($tpl) || empty($tab) || empty($col) || empty($ids) || empty($url)){
            return $this->error(lang('param_err'));
        }

        // tpl 白名单：仅 application/admin/view/public/ 下真实存在的 select_*.html
        $tpl_allow = ['select_type','select_level','select_status','select_state','select_lock','select_hits','select_copyright'];
        if(!is_string($tpl) || !in_array($tpl, $tpl_allow)){
            return $this->error(lang('param_err'));
        }
        // tab/col 仅允许字母数字下划线
        if(!is_string($tab) || !is_string($col) || !preg_match('/^[A-Za-z0-9_]+$/', $tab) || !preg_match('/^[A-Za-z0-9_]+$/', $col)){
            return $this->error(lang('param_err'));
        }
        if(!is_string($url)){
            return $this->error(lang('param_err'));
        }

        if(is_array($ids)){
            $ids = join(',',$ids);
        }
        $ids = htmlspecialchars((string)$ids, ENT_QUOTES);

        if(empty($refresh)){
            $refresh = 'yes';
        }
        $refresh = is_string($refresh) ? htmlspecialchars($refresh, ENT_QUOTES) : 'yes';

        $url = url($url);
        $url = htmlspecialchars($url, ENT_QUOTES);
        $mid = 1;
        if($tab=='art'){
            $mid = 2;
        }
        elseif($tab=='actor'){
            $mid=8;
        }
        elseif($tab=='website'){
            $mid=11;
        }
        $this->assign('mid',$mid);

        if($tpl=='select_type'){
            $type_tree = model('Type')->getCache('type_tree');
            $this->assign('type_tree',$type_tree);
        }
        elseif($tpl =='select_level'){
            $level_list = [1,2,3,4,5,6,7,8,9];
            $this->assign('level_list',$level_list);
        }

        $this->assign('refresh',$refresh);
        $this->assign('url',$url);
        $this->assign('tab',$tab);
        $this->assign('col',$col);
        $this->assign('ids',$ids);
        $this->assign('val', is_string($val) ? htmlspecialchars($val, ENT_QUOTES) : '');
        return $this->fetch( 'admin@public/'.$tpl);
    }

}
