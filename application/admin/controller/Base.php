<?php
namespace app\admin\controller;
use think\Controller;
use app\common\controller\All;
use think\Cache;
use app\common\util\Dir;

class Base extends All
{
    var $_admin;
    var $_pagesize;
    var $_makesize;

    public function __construct()
    {
        parent::__construct();

        //判断用户登录状态
        if(in_array($this->_cl,['Index']) && in_array($this->_ac,['login'])) {

        }
        elseif(ENTRANCE=='api' && in_array($this->_cl,['Timming']) && in_array($this->_ac,['index'])){
            // 安全：定时任务入口鉴权
            //app.cron_key 已配置时必须携带匹配的 cron_key；未配置时仅允许本机回环触发
            $key = $GLOBALS['config']['app']['cron_key'] ?? '';
            $key = is_scalar($key) ? (string)$key : '';
            $got = input('get.cron_key','');
            $got = is_scalar($got) ? (string)$got : '';
            if($key === ''){
                // 不使用 request->ip：TP5 默认 adv=true 会信任 X-Forwarded-For（可伪造）
                //这里只认 TCP 对端地址 REMOTE_ADDR
                $remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
                if(!in_array($remote, ['127.0.0.1','::1','::ffff:127.0.0.1'], true)){
                    http_response_code(403);
                    exit;
                }
            }
            elseif(!hash_equals($key,$got)){
                http_response_code(403);
                exit;
            }
        }
        else {
            $res = model('Admin')->checkLogin();
            if ($res['code'] > 1) {
                return $this->redirect('index/login');
            }
            $this->_admin = $res['info'];
            $this->_pagesize = $GLOBALS['config']['app']['pagesize'];
            $this->_makesize = $GLOBALS['config']['app']['makesize'];

            // 安全：取消 Update 控制器的权限豁免，一律走 check_auth
            if(!$this->check_auth($this->_cl,$this->_ac)){
                return $this->error(lang('permission_denied'));
            }
        }
        $this->assign('cl',$this->_cl);
        $this->assign('MAC_VERSION',config('version')['code']);
    }

    public function check_auth($c,$a)
    {
        $c = strtolower($c);
        $a = strtolower($a);

        $auths = $this->_admin['admin_auth'] . ',index/index,index/welcome,index/logout,';
        $cur = ','.$c.'/'.$a.',';
        if($this->_admin['admin_id'] =='1'){
            return true;
        }
        elseif(strpos($auths,$cur)===false){
            return false;
        }
        else{
            return true;
        }
    }

    public function _cache_clear()
    {
        if(ENTRANCE=='admin') {
            //播放器配置缓存
            // ① 改用统一写入口 mac_playerconfig_write —— 参数区与缓存区一次写全
            // （旧实现此处只写缓存区，参数区要等管理员保存「播放器设置」才写，两区不一致时前端读到空值）
            // 列表区（player_list/downer_list/server_list）由该函数内部按同一来源重建
            //    所以这里原先手写的三段 foreach 已无用，一并删除，避免两处实现漂移。
            // ② 不再依赖 CWD 的 './static/js/...' 相对路径（CLI/其它入口下会写错位置）。
            if (mac_playerconfig_write() === false) {
                \think\Log::record('[playerconfig] 播放器配置写入失败：static/js/playerconfig.js（标记缺失或目录不可写）', 'error');
            }
        }

        Dir::delDir(RUNTIME_PATH.'cache/');
        Dir::delDir(RUNTIME_PATH.'log/');
        Dir::delDir(RUNTIME_PATH.'temp/');

        Cache::clear();

        return true;
    }

}
