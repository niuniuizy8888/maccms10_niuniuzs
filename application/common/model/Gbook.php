<?php
namespace app\common\model;
use think\Db;

class Gbook extends Base {
    // 设置数据表（不含前缀）
    protected $name = 'gbook';

    // 定义时间戳字段名
    protected $createTime = '';
    protected $updateTime = '';

    // 自动完成
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function getGbookStatusTextAttr($val,$data)
    {
        $arr = [0=>lang('disable'),1=>lang('enable')];
        return $arr[$data['gbook_status']];
    }

    public function listData($where,$order,$page=1,$limit=20,$start=0)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        $limit_str = ($limit * ($page-1) + $start) .",".$limit;
        $total = $this->where($where)->count();
        $list = Db::name('Gbook')->where($where)->order($order)->limit($limit_str)->select();
        foreach ($list as $k=>$v){
            $list[$k]['user_portrait'] = mac_get_user_portrait($v['user_id']);
            $list[$k]['gbook_content'] = mac_restore_htmlfilter($list[$k]['gbook_content']);
            $list[$k]['gbook_reply'] = mac_restore_htmlfilter($list[$k]['gbook_reply']);
        }
        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    public function listCacheData($lp)
    {
        if (!is_array($lp)) {
            $lp = json_decode($lp, true);
        }

        $order = $lp['order'] ?? '';
        $by = $lp['by'] ?? '';
        $paging = $lp['paging'] ?? '';
        $start = intval(abs($lp['start'] ?? ''));
        $num = intval(abs($lp['num'] ?? ''));
        $rid = intval(abs($lp['rid'] ?? ''));
        $uid = intval(abs($lp['uid'] ?? ''));
        $half = intval(abs($lp['half'] ?? ''));
        $pageurl = $lp['pageurl'] ?? '';
        $page = 1;
        $where = [];

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }
        if (!in_array($paging, ['yes', 'no'])) {
            $paging = 'no';
        }

        if($paging=='yes') {
            $param = mac_param_url();
            if(!empty($param['rid'])){
                $rid = $param['rid'];
            }
            if(!empty($param['by'])){
                $by = $param['by'];
            }
            if(!empty($param['order'])){
                $order = $param['order'];
            }
            if(!empty($param['page'])){
                $page = intval($param['page']);
            }

            foreach($param as $k=>$v){
                if(empty($v)){
                    unset($param[$k]);
                }
            }
            if(empty($pageurl)){
                $pageurl = 'gbook/index';
            }
            $param['page'] = 'PAGELINK';
            $pageurl = mac_url($pageurl,$param);
        }

        $where['gbook_status'] = ['eq',1];
        if(!empty($rid)){
            $where['gbook_rid'] = ['eq',$rid];
        }
        if(!empty($uid)){
            $where['user_id'] = ['eq',$uid];
        }
        if(!in_array($by, ['id', 'time','reply_time'])) {
            $by = 'time';
        }
        if(!in_array($order, ['asc', 'desc'])) {
            $order = 'desc';
        }
        $order= 'gbook_'.$by .' ' . $order . ',gbook_id desc';


        // PHP8：原用 join('&',$where)，而 $where 的值形如 ['eq',1]（数组）→ 'Array to string conversion' 告警
        // 改为 http_build_query（与其它模型一致），缓存键语义等价且不再告警。
        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' .md5('gbook_listcache_'.http_build_query($where).'_'.$order.'_'.$page.'_'.$num.'_'.$start);

        $res = $this->listData($where,$order,$page,$num,$start);
        $res['pageurl'] = $pageurl;
        $res['half'] = $half;
        return $res;

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

        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($data)
    {
        $validate = \think\Loader::validate('Gbook');
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').'：'.$validate->getError() ];
        }

        // xss过滤
        $filter_fields = [
            'gbook_name',
            'gbook_content',
            'gbook_reply',
        ];
        foreach ($filter_fields as $filter_field) {
            if (!isset($data[$filter_field])) {
                continue;
            }
            $data[$filter_field] = mac_filter_xss($data[$filter_field]);
        }

        // 安全：防批量赋值。
        // 非后台入口禁止按 gbook_id 更新，状态/所属用户/IP 一律服务端赋值
        // 后台入口允许按 id 更新，但也只写入白名单字段。
        // 注：TP5.0 的 Model::__call 会把 where->update/insert 转交 Query
        // allowField 不会生效，因此同时对数据数组做白名单裁剪。
        $allow_field = ['gbook_rid','gbook_name','gbook_content','gbook_reply','gbook_status','gbook_time','gbook_reply_time','user_id','gbook_ip'];
        $gbook_id = !empty($data['gbook_id']) ? intval($data['gbook_id']) : 0;
        $is_admin = defined('ENTRANCE') && ENTRANCE == 'admin';
        if($is_admin && $gbook_id > 0){
            if(!empty($data['gbook_reply'])){
                $data['gbook_reply_time'] = time();
            }
            $data = array_intersect_key($data, array_flip($allow_field));
            $where=[];
            $where['gbook_id'] = ['eq',$gbook_id];
            $res = $this->allowField($allow_field)->where($where)->update($data);
        }
        else{
            unset($data['gbook_id']);
            if($is_admin){
                if(!isset($data['gbook_status'])){
                    $data['gbook_status'] = 1;
                }
                $data['gbook_time'] = time();
            }
            else{
                $data['gbook_status'] = !empty($GLOBALS['config']['gbook']['audit']) ? 0 : 1;
                $data['user_id'] = intval(cookie('user_id'));
                $data['gbook_ip'] = mac_get_ip_long();
                $data['gbook_time'] = time();
            }
            $data = array_intersect_key($data, array_flip($allow_field));
            $res = $this->allowField($allow_field)->insert($data);
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').'：'.$this->getError() ];
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
        $res = $this->allowField(true)->where($where)->update($data);
        if($res===false){
            return ['code'=>1001,'msg'=>lang('set_err').'：'.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('set_ok')];
    }


}