<?php
namespace app\common\model;
use think\Db;
use think\Cache;

class Comment extends Base {
    // 设置数据表（不含前缀）
    protected $name = 'comment';

    // 定义时间戳字段名
    protected $createTime = '';
    protected $updateTime = '';

    // 自动完成
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function getCommentStatusTextAttr($val,$data)
    {
        $arr = [0=>lang('disable'),1=>lang('enable')];
        return $arr[$data['comment_status']];
    }

    public function countData($where)
    {
        $total = $this->where($where)->count();
        return $total;
    }

    public function listData($where,$order,$page=1,$limit=20,$start=0,$field='*',$addition=1,$totalshow=1)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        $limit_str = ($limit * ($page-1) + $start) .",".$limit;
        // PHP8：$totalshow=0 时原实现不定义 $total
        // 但返回数组里仍要算 ceil($total/$limit)，会触发 "Undefined variable $total" 警告。
        // 显式初始化为 0：既不查 COUNT（原意），也保证返回值结构稳定。
        $total = 0;
        if($totalshow==1) {
            $total = $this->where($where)->count();
        }
        $list = Db::name('Comment')->field($field)->where($where)->order($order)->limit($limit_str)->select();

        // 性能：原实现逐行查询回复与所属内容（N+1）
        // 现改为一次性 IN 预取后在内存分组，返回字段与结构保持不变。
        $mid_pre_arr = [1=>'Vod', 2=>'Art', 3=>'Topic', 8=>'Actor', 9=>'Role', 11=>'Website'];
        $mid_key_arr = [1=>'vod_id', 2=>'art_id', 3=>'topic_id', 8=>'actor_id', 9=>'role_id', 11=>'website_id'];
        $comment_ids = [];
        $content_rids = [];
        foreach($list as $k=>$v){
            $list[$k]['user_portrait'] = mac_get_user_portrait($v['user_id']);
            $list[$k]['comment_content'] = mac_restore_htmlfilter($list[$k]['comment_content']);
            $comment_ids[] = intval($v['comment_id']);
            $mid = intval($v['comment_mid']);
            if(isset($mid_key_arr[$mid])){
                $content_rids[$mid][] = intval($v['comment_rid']);
            }
        }

        // 批量预取回复（同一父级内保持原 order 顺序）
        $sub_map = [];
        if(!empty($comment_ids)){
            $sub_list = Db::name('Comment')->where('comment_pid','in',$comment_ids)->where('comment_status','eq',1)->order($order)->select();
            foreach($sub_list as $sub){
                $sub['user_portrait'] = mac_get_user_portrait($sub['user_id']);
                $sub['comment_content'] = mac_restore_htmlfilter($sub['comment_content']);
                $sub_map[intval($sub['comment_pid'])][] = $sub;
            }
        }

        // 所属内容按 rid 去重后调 infoData（需派生字段，不能只取裸行）
        $content_map = [];
        foreach($content_rids as $mid=>$rids){
            $rids = array_values(array_unique($rids));
            $pre = $mid_pre_arr[$mid];
            $pk = $mid_key_arr[$mid];
            foreach($rids as $rid){
                $res = model($pre)->infoData([$pk => $rid]);
                $content_map[$mid][intval($rid)] = (isset($res['code']) && $res['code'] == 1 && isset($res['info'])) ? $res['info'] : null;
            }
        }

        foreach($list as $k=>$v){
            $cid = intval($v['comment_id']);
            $list[$k]['sub'] = isset($sub_map[$cid]) ? $sub_map[$cid] : [];
            $mid = intval($v['comment_mid']);
            $list[$k]['data'] = [];
            if(isset($mid_key_arr[$mid])){
                $rid = intval($v['comment_rid']);
                $list[$k]['data'] = isset($content_map[$mid][$rid]) ? $content_map[$mid][$rid] : null;
            }
        }

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    public function listCacheData($lp)
    {
        if (!is_array($lp)) {
            $lp = json_decode($lp, true);
        }

        $order = $lp['order'] ?? '';
        $by = $lp['by'] ?? '';
        $paging = $lp['paging'] ?? '';
        $start = intval(abs((int)($lp['start'] ?? 0)));
        $num = intval(abs((int)($lp['num'] ?? 0)));
        $rid = intval(abs((int)($lp['rid'] ?? 0)));
        $pid = intval(abs((int)($lp['pid'] ?? 0)));
        $mid = intval(abs((int)($lp['mid'] ?? 0)));
        $uid = intval(abs((int)($lp['uid'] ?? 0)));
        $half = intval(abs((int)($lp['half'] ?? 0)));
        $pageurl = $lp['pageurl'] ?? '';
        $page = 1;
        $where = [];

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }
        if (!in_array($mid, ['1','2','3','8','9'])) {
            // $mid = 1
        }

        if(!in_array($paging, ['yes', 'no'])) {
            $paging = 'no';
        }

        if($paging=='yes') {
            $param = mac_param_url();
            if(!empty($param['mid'])){
                $mid = $param['mid'];
            }
            if(!empty($param['rid'])){
                $rid = $param['rid'];
            }
            if(!empty($param['pid'])){
                $pid = $param['pid'];
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
                $pageurl = 'comment/index';
            }
            $param['page'] = 'PAGELINK';
            $pageurl = mac_url($pageurl,$param);
        }

        // AJAX is always scoped to a content item, even with a custom template.
        if (strtolower(request()->controller()) === 'comment' && strtolower(request()->action()) === 'ajax') {
            $scope = mac_param_url();
            $where['comment_mid'] = ['eq', (int)($scope['mid'] ?? 0)];
            $where['comment_rid'] = ['eq', (int)($scope['rid'] ?? 0)];
            $mid = (int)($scope['mid'] ?? 0);
            $rid = (int)($scope['rid'] ?? 0);
        }

        $where['comment_status'] = ['eq',1];
        $where['comment_pid'] = ['eq',0];

        if(!empty($rid)){
            $where['comment_rid'] = ['eq',$rid];
        }
        if(!empty($pid)){
            $where['comment_pid'] = ['eq',$pid];
        }
        if(!empty($uid)){
            $where['user_id'] = ['eq',$uid];
        }
        if(!empty($mid)){
            $where['comment_mid'] = ['eq',$mid];
        }

        if(!in_array($by, ['id', 'time','up','down'])) {
            $by = 'time';
        }
        if(!in_array($order, ['asc', 'desc'])) {
            $order = 'desc';
        }
        $order= 'comment_'.$by .' ' . $order . ',comment_id desc';

        // 缓存键：join('&',$where) 遇嵌套数组（如会员组过滤）会退化成 "Array" 并产生 PHP 8 警告
        // 改用 http_build_query（递归展开，天然区分不同条件）。
        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' .md5('comment_listcache_'.http_build_query($where).'_'.$order.'_'.$page.'_'.$num.'_'.$start);

        // 此处**不启用**列表缓存 —— 评论新增/审核/删除没有任何写侧失效通道
        // 开启后前台评论最长 cache_time 不更新（原实现本就无缓存）。性能收益由 listData 的批量预取承担。
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
        $validate = \think\Loader::validate('Comment');
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').'：'.$validate->getError() ];
        }

        // xss过滤
        $filter_fields = [
            'comment_name',
            'comment_content',
        ];
        foreach ($filter_fields as $filter_field) {
            if (!isset($data[$filter_field])) {
                continue;
            }
            $data[$filter_field] = mac_filter_xss($data[$filter_field]);
        }

        // 安全：防批量赋值。
        // 非后台入口禁止按 comment_id 更新，状态/所属用户/IP 一律服务端赋值
        // 后台入口允许按 id 更新，但也只写入白名单字段。
        // 注：TP5.0 的 Model::__call 会把 where->update/insert 转交 Query
        // allowField 不会生效，因此同时对数据数组做白名单裁剪。
        $allow_field = ['comment_mid','comment_rid','comment_pid','comment_status','comment_name','comment_content','comment_up','comment_down','comment_time','user_id','comment_ip'];
        $comment_id = !empty($data['comment_id']) ? intval($data['comment_id']) : 0;
        $is_admin = defined('ENTRANCE') && ENTRANCE == 'admin';
        if($is_admin && $comment_id > 0){
            $data = array_intersect_key($data, array_flip($allow_field));
            $where=[];
            $where['comment_id'] = ['eq',$comment_id];
            $res = $this->allowField($allow_field)->where($where)->update($data);
        }
        else{
            unset($data['comment_id']);
            if($is_admin){
                if(!isset($data['comment_status'])){
                    $data['comment_status'] = 1;
                }
                $data['comment_time'] = time();
            }
            else{
                $data['comment_status'] = !empty($GLOBALS['config']['comment']['audit']) ? 0 : 1;
                $data['user_id'] = intval(cookie('user_id'));
                $data['comment_ip'] = mac_get_ip_long();
                $data['comment_time'] = time();
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