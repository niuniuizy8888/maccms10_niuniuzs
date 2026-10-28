<?php
namespace app\common\model;
use think\Db;

class Admin extends Base {
    // 设置数据表（不含前缀）
    protected $name = 'admin';

    // 定义时间戳字段名
    protected $createTime = '';
    protected $updateTime = '';

    // 自动完成
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function getAdminStatusTextAttr($val,$data)
    {
        $arr = [0=>lang('disable'),1=>lang('enable')];
        return $arr[$data['admin_status']];
    }

    public function listData($where,$order,$page,$limit=20)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $total = $this->where($where)->count();
        $list = Db::name('Admin')->where($where)->order($order)->page($page)->limit($limit)->select();
        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    public function infoData($where,$field='*')
    {
        if(empty($where) || !is_array($where)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $info = $this->field($field)->where($where)->find();

        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('obtain_err')];
        }
        $info = $info->toArray();

        $info['admin_pwd'] = '';
        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    /**
     * 生成口令哈希。
     * 仅当 admin_pwd 列可容纳 60+ 字符（DBA 已执行 VARCHAR(255) 迁移）时才写 password_hash，
     * 否则回退旧的 md5 写法，避免 char(32) 列被截断导致无法登录。
     */
    private function _pwd_make($plain)
    {
        static $width = null;
        if ($width === null) {
            $width = 32;
            try {
                $table = $this->getQuery()->getTable();
                if (preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    $column = Db::query("SHOW COLUMNS FROM " . $table . " LIKE 'admin_pwd'");
                    if (!empty($column[0])) {
                        $column[0] = array_change_key_case($column[0], CASE_LOWER);
                        if (preg_match('/^(?:var)?char\((\d+)\)/i', $column[0]['type'], $match)) {
                            $width = intval($match[1]);
                        }
                    }
                }
            } catch (\Exception $e) {
                $width = 32;
            }
        }
        return $width >= 60 ? mac_password_make($plain) : md5($plain);
    }

    public function saveData($data)
    {
        if(!empty($data['admin_auth'])){
            $data['admin_auth'] = ','.join(',',$data['admin_auth']).',';
        }
        else{
            $data['admin_auth'] = '';
        }
        $validate = \think\Loader::validate('Admin');
        if(!empty($data['admin_id'])){
            if(!$validate->scene('edit')->check($data)){
                return ['code'=>1001,'msg'=>lang('param_err').'：'.$validate->getError() ];
            }

            if(empty($data['admin_pwd'])){
                unset($data['admin_pwd']);
            }
            else{
                $data['admin_pwd'] = $this->_pwd_make($data['admin_pwd']);
            }
            $where=[];
            $where['admin_id'] = ['eq',$data['admin_id']];
            $res = $this->where($where)->update($data);
        }
        else{
            if(!$validate->scene('edit')->check($data)){
                return ['code'=>1002,'msg'=>lang('param_err').'：'.$validate->getError() ];
            }

            $data['admin_pwd'] = $this->_pwd_make($data['admin_pwd']);
            $res = $this->insert($data);
        }
        if(false === $res){
            return ['code'=>1003,'msg'=>''.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if($res===false){
            return ['code'=>1001,'msg'=>lang('del_err').'：'.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('del_ok')];
    }

    public function fieldData($where,$col,$val)
    {
        if(!isset($col) || !isset($val)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $data = [];
        $data[$col] = $val;
        $res = $this->where($where)->update($data);
        if($res===false){
            return ['code'=>1002,'msg'=>lang('set_err').'：'.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('set_ok')];
    }

    public function login($data)
    {
        if(empty($data['admin_name']) || empty($data['admin_pwd'])  ) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        if($GLOBALS['config']['app']['admin_login_verify'] !='0'){
            if(!mac_captcha_check((string)($data['verify'] ?? ''))){
                return ['code'=>1002,'msg'=>lang('verify_err')];
            }
        }


        $where=[];
        $where['admin_name'] = ['eq',$data['admin_name']];
        $where['admin_status'] = ['eq',1];

        $row = $this->where($where)->find();

        if(empty($row)){
            return ['code'=>1003,'msg'=>lang('access_or_pass_err')];
        }

        // 口令校验：password_verify 优先，存量 MD5 回退
        $row_pwd = (string)$row['admin_pwd'];
        if (!password_verify($data['admin_pwd'], $row_pwd)) {
            if (!hash_equals(md5($data['admin_pwd']), $row_pwd)) {
                return ['code'=>1003,'msg'=>lang('access_or_pass_err')];
            }
            // 命中存量 MD5：登录成功即升级为 password_hash（列宽不足 60 时 _pwd_make 回退 md5，此处不写）
            $new_pwd = $this->_pwd_make($data['admin_pwd']);
            if (strlen($new_pwd) >= 60) {
                $this->where($where)->update(['admin_pwd' => $new_pwd]);
                $row['admin_pwd'] = $new_pwd;
            }
        }
        $random = bin2hex(random_bytes(16));
        $update['admin_login_ip'] = mac_get_ip_long();
        $update['admin_login_time'] = time();
        $update['admin_login_num'] = $row['admin_login_num'] + 1;
        $update['admin_random'] = $random;
        $update['admin_last_login_time'] = $row['admin_login_time'];
        $update['admin_last_login_ip'] = $row['admin_login_ip'];

        $res = $this->where($where)->update($update);
        if($res===false){
            return ['code'=>1004,'msg'=>lang('model/admin/update_login_err')];
        }

        session('admin_auth','1');
        session('admin_info',$row->toArray());

        //cookie('admin_id',$row['admin_id']);
        //cookie('admin_name',$row['admin_name']);
        //cookie('admin_check',md5($random .'-'. $row['admin_name'] .'-'.$row['admin_id'] .'-'.mac_get_client_ip() ) );

        return ['code'=>1,'msg'=>lang('model/admin/login_ok')];
    }

    public function logout()
    {
        session('admin_auth',null);
        session('admin_info',null);
        //cookie('admin_id',null);
        //cookie('admin_name',null);
        //cookie('admin_check',null);

        return ['code'=>1,'msg'=>lang('model/admin/logout_ok')];
    }

    public function checkLogin()
    {
        if(session('admin_auth')!=='1'){
            return ['code'=>1009,'msg'=>lang('model/admin/not_login')];
        }
        $info = session('admin_info');
        if(!is_array($info) || empty($info)){
            $this->logout();
            return ['code'=>1002,'msg'=>lang('model/admin/not_login')];
        }
        // 每次请求对照数据库：禁用/删除/改密立即失效；权限以库里的 admin_auth 为准，不沿用登录时的快照。
        $adminId = isset($info['admin_id']) ? intval($info['admin_id']) : 0;
        $row = $adminId > 0 ? $this->where('admin_id', $adminId)->find() : null;
        if(empty($row)){
            $this->logout();
            return ['code'=>1002,'msg'=>lang('model/admin/not_login')];
        }
        $fresh = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array)$row;
        $sessPwd = isset($info['admin_pwd']) ? (string)$info['admin_pwd'] : '';
        if($sessPwd === '' || !hash_equals($sessPwd, (string)$fresh['admin_pwd']) || intval($fresh['admin_status']) !== 1){
            $this->logout();
            return ['code'=>1002,'msg'=>lang('model/admin/not_login')];
        }
        session('admin_info', $fresh);
        return ['code'=>1,'msg'=>lang('model/admin/haved_login'),'info'=>$fresh];
    }

    public function checkLogin2()
    {
        $admin_id = cookie('admin_id');
        $admin_name = cookie('admin_name');
        $admin_check = cookie('admin_check');

        if(empty($admin_id) || empty($admin_name) || empty($admin_check)){
            return ['code'=>1001, 'msg'=>lang('model/admin/not_login')];
        }
        if(!is_string($admin_id) || !is_string($admin_name) || !is_string($admin_check)){
            return ['code'=>1001, 'msg'=>lang('model/admin/not_login')];
        }

        $where = [];
        $where['admin_id'] = $admin_id;
        $where['admin_name'] = $admin_name;
        $where['admin_status'] =1 ;

        $info = $this->where($where)->find();
        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('model/admin/not_login')];
        }
        $info = $info->toArray();

        $login_check = hash_hmac('sha256', $info['admin_id'] . '|' . $info['admin_name'], $info['admin_random']);
        if(!hash_equals($login_check, (string)$admin_check)){
            // 兼容旧的 md5 校验值（含客户端 IP 绑定）
            $old_check = md5($info['admin_random'] .'-'. $info['admin_name'] .'-'.$info['admin_id'] .'-'.mac_get_client_ip() ) ;
            if(!hash_equals($old_check, (string)$admin_check)){
                return ['code'=>1003,'msg'=>lang('model/admin/not_login')];
            }
        }
        return ['code'=>1,'msg'=>lang('model/admin/haved_login'),'info'=>$info];
    }

}