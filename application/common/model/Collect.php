<?php
namespace app\common\model;
use think\Db;
use think\Cache;
use app\common\util\Pinyin;
use think\Request;
use app\common\validate\Vod as VodValidate;

class Collect extends Base {

    // 设置数据表（不含前缀）
    protected $name = 'collect';

    // 定义时间戳字段名
    protected $createTime = '';
    protected $updateTime = '';

    // 自动完成
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function listData($where,$order,$page=1,$limit=20,$start=0)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        $total = $this->where($where)->count();
        $list = Db::name('Collect')->where($where)->order($order)->page($page)->limit($limit)->select();
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
        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($data)
    {
        $validate = \think\Loader::validate('Collect');
        if(!empty($data['collect_id'])){
            if(!$validate->scene('edit')->check($data)){
                return ['code'=>1001,'msg'=>lang('param_err').'：'.$validate->getError() ];
            }

            $where=[];
            $where['collect_id'] = ['eq',$data['collect_id']];
            $res = $this->where($where)->update($data);
        }
        else{
            if(!$validate->scene('edit')->check($data)){
                return ['code'=>1002,'msg'=>lang('param_err').'：'.$validate->getError() ];
            }
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

    public function check_flag($param)
    {
        if($param['cjflag'] != md5($param['cjurl'])){
            return ['code'=>9001, 'msg'=>lang('model/collect/flag_err')];
        }
        return ['code'=>1,'msg'=>'ok'];
    }

    public function vod($param)
    {
        if($param['type'] == '1'){
            return $this->vod_xml($param);
        }
        elseif($param['type'] == '2'){
            return $this->vod_json($param);
        }
        else{
            $data = $this->vod_json($param);

            if($data['code'] == 1){
                return $data;
            }
            else{
                return $this->vod_xml($param);
            }
        }
    }

    public function art($param)
    {
        return $this->art_json($param);
    }

    public function actor($param)
    {
        return $this->actor_json($param);
    }

    public function role($param)
    {
        return $this->role_json($param);
    }

    public function website($param)
    {
        return $this->website_json($param);
    }

    public function vod_xml_replace($url)
    {
        $array_url = array();
        $arr_ji = explode('#',str_replace('||','//',$url));
        foreach($arr_ji as $key=>$value){
            $urlji = explode('$',$value);
            if( count($urlji) > 1 ){
                $array_url[$key] = $urlji[0].'$'.trim($urlji[1]);
            }else{
                $array_url[$key] = trim($urlji[0]);
            }
        }
        return implode('#',$array_url);
    }

    public function vod_xml($param,$html='')
    {
        $url_param = [];
        $url_param['ac'] = $param['ac'];
        $url_param['t'] = $param['t'];
        $url_param['pg'] = is_numeric($param['page']) ? $param['page'] : '';
        $url_param['h'] = $param['h'];
        $url_param['ids'] = $param['ids'];
        $url_param['wd'] = $param['wd'];
        if(empty($param['h']) && !empty($param['rday'])){
            $url_param['h'] = $param['rday'];
        }

        if($param['ac']!='list'){
            $url_param['ac'] = 'videolist';
        }

        $url = $param['cjurl'];
        if(strpos($url,'?')===false){
            $url .='?';
        }
        else{
            $url .='&';
        }
        $url .= http_build_query($url_param). base64_decode($param['param']);
        $result = $this->checkCjUrl($url);
        if ($result['code'] > 1) {
            return $result;
        }
        // 安全：SSRF 场景禁跟随重定向，避免校验过的公网地址 302 到内网
        $html = mac_curl_get($url, [], '', true);
        if(empty($html)){
            return ['code'=>1001, 'msg'=>lang('model/collect/get_html_err') . ', url: ' . $url];
        }
        $html = mac_filter_tags($html);
        $xml = @simplexml_load_string($html);
        if(empty($xml)){
            $labelRule = '<pic>'."(.*?)".'</pic>';
            $labelRule = mac_buildregx($labelRule,"is");
            preg_match_all($labelRule,$html,$tmparr);
            $ec=false;
            foreach(($tmparr[1] ?? []) as $tt){
                if(strpos($tt,'[CDATA')===false){
                    $ec=true;
                    $ne = '<pic>'.'<![CDATA['.$tt .']]>'.'</pic>';
                    $html = str_replace('<pic>'.$tt.'</pic>',$ne,$html);
                }
            }
            if($ec) {
                $xml = @simplexml_load_string($html);
            }
            if(empty($xml)) {
                return ['code' => 1002, 'msg'=>lang('model/collect/xml_err')];
            }
        }

        $array_page = [];
        $array_page['page'] = (string)$xml->list->attributes()->page;
        $array_page['pagecount'] = (string)$xml->list->attributes()->pagecount;
        $array_page['pagesize'] = $this->getRemotePageSize((string)$xml->list->attributes()->pagesize);
        $array_page['recordcount'] = (string)$xml->list->attributes()->recordcount;
        $array_page['url'] = $url;

        $type_list = model('Type')->getCache('type_list');
        $bind_list = config('bind');


        $key = 0;
        $array_data = [];
        $__max_rows = $this->maxRemoteRows(); $__row_i = 0;
        foreach($xml->list->video as $video){
            if ($__row_i++ >= $__max_rows) { break; }
            $bind_key = $param['cjflag'] .'_'.(string)$video->tid;
            $local_type_id = intval(is_array($bind_list) ? ($bind_list[$bind_key] ?? 0) : 0);
            if($local_type_id >0){
                $array_data[$key]['type_id'] = $local_type_id;
            }
            else{
                $array_data[$key]['type_id'] = 0;
            }
            $array_data[$key]['vod_id'] = (string)$video->id;
            // $array_data[$key]['type_id'] = (string)$video->tid
            $array_data[$key]['vod_name'] = (string)$video->name;
            $array_data[$key]['vod_sub'] = (string)$video->subname;
            $array_data[$key]['vod_remarks'] = (string)$video->note;
            $array_data[$key]['type_name'] = (string)$video->type;
            $array_data[$key]['vod_pic'] = (string)$video->pic;
            $array_data[$key]['vod_lang'] = (string)$video->lang;
            $array_data[$key]['vod_area'] = (string)$video->area;
            $array_data[$key]['vod_year'] = (string)$video->year;
            $array_data[$key]['vod_serial'] = (string)$video->state;
            $array_data[$key]['vod_actor'] = (string)$video->actor;
            $array_data[$key]['vod_director'] = (string)$video->director;
            $array_data[$key]['vod_content'] = (string)$video->des;

            $array_data[$key]['vod_status'] = 1;
            $array_data[$key]['vod_type'] = $array_data[$key]['list_name'];
            $array_data[$key]['vod_time'] = (string)$video->last;
            $array_data[$key]['vod_total'] = 0;
            $array_data[$key]['vod_isend'] = 1;
            if($array_data[$key]['vod_serial']){
                $array_data[$key]['vod_isend'] = 0;
            }
            // 格式化地址与播放器
            $array_from = [];
            $array_url = [];
            $array_server=[];
            $array_note=[];
            // videolist|list播放列表不同
            if(isset($video->dl->dd) && $count=count($video->dl->dd)){
                for($i=0; $i<$count; $i++){
                    $array_from[$i] = (string)$video->dl->dd[$i]['flag'];
                    $array_url[$i] = $this->vod_xml_replace((string)$video->dl->dd[$i]);
                    $array_server[$i] = 'no';
                    $array_note[$i] = '';

                }
            }else{
                $array_from[]=(string)$video->dt;
                $array_url[] ='';
                $array_server[]='';
                $array_note[]='';
            }

            if(strpos(base64_decode($param['param']),'ct=1')!==false){
                $array_data[$key]['vod_down_from'] = implode('$$$', $array_from);
                $array_data[$key]['vod_down_url'] = implode('$$$', $array_url);
                $array_data[$key]['vod_down_server'] = implode('$$$', $array_server);
                $array_data[$key]['vod_down_note'] = implode('$$$', $array_note);
            }
            else{
                $array_data[$key]['vod_play_from'] = implode('$$$', $array_from);
                $array_data[$key]['vod_play_url'] = implode('$$$', $array_url);
                $array_data[$key]['vod_play_server'] = implode('$$$', $array_server);
                $array_data[$key]['vod_play_note'] = implode('$$$', $array_note);
            }

            $key++;
        }

        $array_type = [];
        $key=0;
        // 分类列表
        if($param['ac'] == 'list'){
            foreach($xml->class->ty as $ty){
                $array_type[$key]['type_id'] = (string)$ty->attributes()->id;
                $array_type[$key]['type_name'] = (string)$ty;
                $key++;
            }
        }

        $res = ['code'=>1, 'msg'=>'xml', 'page'=>$array_page, 'type'=>$array_type, 'data'=>$array_data ];
        return $res;
    }

    public function vod_json($param)
    {
        $url_param = [];
        $url_param['ac'] = $param['ac'];
        $url_param['t'] = $param['t'];
        $url_param['pg'] = is_numeric($param['page']) ? $param['page'] : '';
        $url_param['h'] = $param['h'];
        $url_param['ids'] = $param['ids'];
        $url_param['wd'] = $param['wd'];

        if($param['ac']!='list'){
            $url_param['ac'] = 'videolist';
        }

        $url = $param['cjurl'];
        if(strpos($url,'?')===false){
            $url .='?';
        }
        else{
            $url .='&';
        }
        $url .= http_build_query($url_param). base64_decode($param['param']);
        $result = $this->checkCjUrl($url);
        if ($result['code'] > 1) {
            return $result;
        }
        // 安全：SSRF 场景禁跟随重定向，避免校验过的公网地址 302 到内网
        $html = mac_curl_get($url, [], '', true);
        if(empty($html)){
            return ['code'=>1001, 'msg'=>lang('model/collect/get_html_err') . ', url: ' . $url];
        }
        $html = mac_filter_tags($html);
        $json = $this->decodeCollectJson($html);
        if(!$json){
            return ['code'=>1002, 'msg'=>lang('model/collect/json_err') . ', url: ' . $url . ', response: ' . mb_substr($html, 0, 15)];
        }

        $array_page = [];
        $array_page['page'] = $json['page'];
        $array_page['pagecount'] = $json['pagecount'];
        $array_page['pagesize'] = $this->getRemotePageSize($json['limit'] ?? 0);
        $array_page['recordcount'] = $json['total'];
        $array_page['url'] = $url;

        $type_list = model('Type')->getCache('type_list');
        $bind_list = config('bind');

        $key = 0;
        $array_data = [];
        $__max_rows = $this->maxRemoteRows(); $__row_i = 0;
        foreach($json['list'] as $key=>$v){
            if ($__row_i++ >= $__max_rows) { break; }
            $array_data[$key] = $v;
            $bind_key = $param['cjflag'] .'_'.$v['type_id'];
            $local_type_id = intval(is_array($bind_list) ? ($bind_list[$bind_key] ?? 0) : 0);
            if($local_type_id >0){
                $array_data[$key]['type_id'] = $local_type_id;
            }
            else{
                $array_data[$key]['type_id'] = 0;
            }

            if(!empty($v['dl'])) {
                // 格式化地址与播放器
                $array_from = [];
                $array_url = [];
                $array_server = [];
                $array_note = [];
                // videolist|list播放列表不同
                foreach ($v['dl'] as $k2 => $v2) {
                    $array_from[] = $k2;
                    $array_url[] = $v2;
                    $array_server[] = 'no';
                    $array_note[] = '';
                }

                $array_data[$key]['vod_play_from'] = implode('$$$', $array_from);
                $array_data[$key]['vod_play_url'] = implode('$$$', $array_url);
                $array_data[$key]['vod_play_server'] = implode('$$$', $array_server);
                $array_data[$key]['vod_play_note'] = implode('$$$', $array_note);
            }
        }

        $array_type = [];
        $key=0;
        // 分类列表
        if($param['ac'] == 'list'){
            foreach($json['class'] as $k=>$v){
                $array_type[$key]['type_id'] = $v['type_id'];
                $array_type[$key]['type_name'] = $v['type_name'];
                $key++;
            }
        }

        $res = ['code'=>1, 'msg'=>'json', 'page'=>$array_page, 'type'=>$array_type, 'data'=>$array_data ];
        return $res;
    }

    /**
     * 同步图片
     *
     * @param $pic_status int 是否同步。为1时，同步图片
     * @param $pic_url
     * @param string $flag
     * @return array
     */
    private function syncImages($pic_status, $pic_url, $flag = 'vod')
    {
        $img_url_downloaded = $pic_url;
        $des = '';
        if ($pic_status == 1) {
            $config = (array)config('maccms.upload');
            $img_url_downloaded = model('Image')->down_load($pic_url, $config, $flag);
            if ($img_url_downloaded == $pic_url) {
                // 下载失败，显示老图信息
                $des = '<a href="' . $pic_url . '" target="_blank">' . $pic_url . '</a><font color=red>'.lang('download_err').'!</font>';
            } else {
                // 下载成功，显示新图信息
                if (str_starts_with($img_url_downloaded, 'upload/')) {
                    $link = MAC_PATH . $img_url_downloaded;
                } else {
                    $link = str_replace('mac:', $config['protocol'] . ':', $img_url_downloaded);
                }
                $des = '<a href="' . $link . '" target="_blank">' . $link . '</a><font color=green>'.lang('download_ok').'!</font>';
            }
        }
        return ['pic' => $img_url_downloaded, 'msg' => $des];
    }

    /** Validate remote response structure before any field access or iteration. */
    private function decodeCollectJson($html)
    {
        $json = json_decode($html, true);
        if (!is_array($json) || !isset($json['list']) || !is_array($json['list'])) {
            return false;
        }
        foreach ($json['list'] as &$row) {
            if (!is_array($row)) { return false; }
            foreach ($row as $key => $value) {
                if ($key === 'dl' && is_array($value)) {
                    foreach ($value as $url) {
                        if (!is_string($url)) { return false; }
                    }
                } elseif (($key === 'dl' && !empty($value)) || (!is_scalar($value) && $value !== null)) {
                    return false;
                }
            }
            $row += ['type_id' => 0, 'type_name' => ''];
        }
        unset($row);
        $json += ['class' => [], 'page' => 1, 'pagecount' => 1, 'total' => count($json['list']), 'limit' => 0];
        if (!is_array($json['class'])) { return false; }
        foreach ($json['class'] as $type) {
            if (!is_array($type) || !isset($type['type_id'], $type['type_name'])
                || !is_scalar($type['type_id']) || !is_scalar($type['type_name'])) { return false; }
        }
        foreach (['page', 'pagecount', 'total', 'limit'] as $key) {
            if (!is_numeric($json[$key]) || $json[$key] < 0) { return false; }
            $json[$key] = (int)$json[$key];
        }
        return $json;
    }

    /** 采集入库字段净化：*_content 保留安全 HTML，其余字段去除标签。 */
    private function cleanCollectRow($v)
    {
        if (!is_array($v)) {
            return $v;
        }
        $hasCleaner = function_exists('mac_clean_html');
        foreach ($v as $k => $val) {
            if (!is_string($k)) {
                continue;
            }
            // 正文/详情类字段：白名单 HTML 净化（保留富文本与播放地址里的 & 等）
            if (strpos($k, '_content') !== false) {
                if ($hasCleaner && is_string($val) && $val !== '') {
                    $v[$k] = mac_clean_html($val);
                } elseif (!is_string($val)) {
                    $v[$k] = '';
                }
            } elseif (is_string($val)) {
                $v[$k] = strip_tags($val);
            } elseif ($val === null) {
                $v[$k] = '';
            } elseif (!is_scalar($val)) {
                unset($v[$k]);
            }
        }
        return $v;
    }

    /** 1 = compatible identity, 0 = insufficient evidence, -1 = different work. */
    private function videoIdentity(array $incoming, $stored)
    {
        if ((int)$incoming['type_id'] !== (int)$stored['type_id']) {
            return -1;
        }
        $newId = (int)($incoming['vod_douban_id'] ?? 0);
        $oldId = (int)($stored['vod_douban_id'] ?? 0);
        if ($newId > 0 && $oldId > 0) {
            return $newId === $oldId ? 1 : -1;
        }
        $year = function ($value) {
            return preg_match('/^(?:18|19|20|21)\d{2}$/', trim((string)$value)) ? trim((string)$value) : '';
        };
        $newYear = $year($incoming['vod_year'] ?? '');
        $oldYear = $year($stored['vod_year'] ?? '');
        if ($newYear !== '' && $oldYear !== '' && $newYear !== $oldYear) {
            return -1;
        }
        $people = function ($value) {
            $value = preg_replace('/\s+/u', '', mb_strtolower(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8')));
            return array_values(array_filter(preg_split('/[,，、\/|&]+/u', $value), function ($name) {
                return !in_array($name, ['', '未知', '不详', '暂无', 'unknown'], true);
            }));
        };
        $newDirector = $people($incoming['vod_director'] ?? '');
        $oldDirector = $people($stored['vod_director'] ?? '');
        $directorsMatch = array_intersect($newDirector, $oldDirector);
        if ($newDirector && $oldDirector && !$directorsMatch) {
            return -1;
        }
        $actorsMatch = array_intersect($people($incoming['vod_actor'] ?? ''), $people($stored['vod_actor'] ?? ''));
        if ($newYear !== '' && $newYear === $oldYear && ($directorsMatch || $actorsMatch)) {
            return 1;
        }
        // An exact existing episode URL proves a repeat even for sparse feeds.
        // A shared player name alone cannot distinguish two same-name works.
        $urls = function ($value) {
            $result = [];
            foreach (preg_split('/\$\$\$|#/', (string)$value) as $episode) {
                $parts = explode('$', $episode, 2);
                $url = trim(end($parts));
                if (preg_match('~^https?://~i', $url)) { $result[] = $url; }
            }
            return $result;
        };
        return array_intersect($urls($incoming['vod_play_url'] ?? ''), $urls($stored['vod_play_url'] ?? '')) ? 1 : 0;
    }

    public function vod_data($param,$data,$show=1)
    {
        if($show==1) {
            mac_echo('[' . __FUNCTION__ . '] ' . lang('model/collect/data_tip1', [$data['page']['page'],$data['page']['pagecount'],$data['page']['url']]));
        }

        $config = config('maccms.collect');
        $config = $config['vod'];
        $config_sync_pic = $param['sync_pic_opt'] > 0 ? $param['sync_pic_opt'] : $config['pic'];
        $filter_year = !empty($param['filter_year']) ? $param['filter_year'] : '';
        $filter_year_list = $filter_year ? get_array_unique_id_list(explode(',', $filter_year)) : [];
        $players = config('vodplayer');
        $downers = config('voddowner');
        $vod_search = model('VodSearch');
        $vod_search_enabled = $vod_search->isCollectEnabled();
        $vs_max_id_count = $vod_search->maxIdCount;

        $type_list = model('Type')->getCache('type_list');
        $filter_arr = explode(',',$config['filter']);
        $filter_arr = array_filter($filter_arr);
        $pse_rnd = explode('#',$config['words']);
        $pse_rnd = array_filter($pse_rnd);
        $pse_name = mac_txt_explain($config['namewords'] ?? '', true);
        $pse_syn = mac_txt_explain($config['thesaurus'] ?? '', true);
        $pse_player = mac_txt_explain($config['playerwords'] ?? '', true);
        $pse_area = mac_txt_explain($config['areawords'] ?? '', true);
        $pse_lang = mac_txt_explain($config['langwords'] ?? '', true);

        foreach($data['data'] as $k=>$v){
            $color='red';
            $action = '';
            $des='';
            $msg='';
            $tmp='';

            if ($v['type_id'] ==0) {
                $des = lang('model/collect/type_err');
            } elseif (empty($v['vod_name'])) {
                $des = lang('model/collect/name_err');
            } elseif (mac_array_filter($filter_arr,$v['vod_name']) !==false) {
                $des = lang('model/collect/name_in_filter_err');
            } elseif ($filter_year_list && !in_array(intval($v['vod_year'] ?? 0), $filter_year_list)) {
                // 采集时，过滤年份
                // https://github.com/magicblack/maccms10/issues/1057
                $color = 'orange';
                $des = 'year [' . intval($v['vod_year'] ?? 0) . '] not in: ' . join(',', $filter_year_list);
            } else {
                unset($v['vod_id']);
                // 采集/推送数据不得指定模板与权限类字段（模板名可致目录穿越，权限字段可绕过收费/审核）
                foreach (['vod_tpl','vod_tpl_play','vod_tpl_down','vod_pwd','vod_pwd_url','vod_pwd_play','vod_pwd_play_url','vod_pwd_down','vod_pwd_down_url','vod_copyright','vod_points','vod_points_play','vod_points_down'] as $_k) { unset($v[$_k]); }

                $v = $this->cleanCollectRow($v);
                $v += array_fill_keys(['type_name', 'vod_class', 'vod_actor', 'vod_director', 'vod_tag', 'vod_plot_name', 'vod_plot_detail', 'vod_content', 'vod_area', 'vod_lang', 'vod_pic', 'vod_play_from', 'vod_play_server', 'vod_play_note', 'vod_down_from', 'vod_down_server', 'vod_down_note'], '');

                $v['type_id_1'] = intval(($type_list[$v['type_id']] ?? [])['type_pid'] ?? 0);
                $v['vod_en'] = Pinyin::get($v['vod_name']);
                $v['vod_letter'] = strtoupper(substr($v['vod_en'],0,1));
                // 使用资源站的添加时间，更新时间保持当前
                // https://github.com/magicblack/maccms10/issues/780
                if (empty($v['vod_time_add']) || strlen($v['vod_time_add']) != 10) {
                    $v['vod_time_add'] = time();
                }
                // 支持外部自定义修改时间
                // https://github.com/magicblack/maccms10/issues/862
                $v['vod_time'] = time();
                if (!empty($v['vod_time_update']) && strlen($v['vod_time_update']) == 10) {
                    $v['vod_time'] = (int)$v['vod_time_update'];
                }
                $v['vod_status'] = intval($config['status']);
                $v['vod_lock'] = intval($v['vod_lock'] ?? 0);
                if(!empty($v['vod_status'])) {
                    $v['vod_status'] = intval($v['vod_status'] ?? 0);
                }
                $v['vod_year'] = intval($v['vod_year'] ?? 0);
                $v['vod_level'] = intval($v['vod_level'] ?? 0);
                $v['vod_hits'] = intval($v['vod_hits'] ?? 0);
                $v['vod_hits_day'] = intval($v['vod_hits_day'] ?? 0);
                $v['vod_hits_week'] = intval($v['vod_hits_week'] ?? 0);
                $v['vod_hits_month'] = intval($v['vod_hits_month'] ?? 0);
                $v['vod_stint_play'] = intval($v['vod_stint_play'] ?? 0);
                $v['vod_stint_down'] = intval($v['vod_stint_down'] ?? 0);

                $v['vod_total'] = intval($v['vod_total'] ?? 0);
                $v['vod_serial'] = intval($v['vod_serial'] ?? 0);
                $v['vod_isend'] = intval($v['vod_isend'] ?? 0);
                $v['vod_up'] = intval($v['vod_up'] ?? 0);
                $v['vod_down'] = intval($v['vod_down'] ?? 0);

                $v['vod_score'] = floatval($v['vod_score'] ?? 0);
                $v['vod_score_all'] = intval($v['vod_score_all'] ?? 0);
                $v['vod_score_num'] = intval($v['vod_score_num'] ?? 0);

                $v['vod_class'] = mac_txt_merge($v['vod_class'],$v['type_name']);

                $v['vod_actor'] = mac_format_text($v['vod_actor'], true);
                $v['vod_director'] = mac_format_text($v['vod_director'], true);
                $v['vod_class'] = mac_format_text($v['vod_class'], true);
                $v['vod_tag'] = mac_format_text($v['vod_tag'], true);

                $v['vod_plot_name'] = (string)$v['vod_plot_name'];
                $v['vod_plot_detail'] = (string)$v['vod_plot_detail'];

                if(!empty($v['vod_plot_name'])){
                    $v['vod_plot'] = 1;
                    $v['vod_plot_name'] = trim($v['vod_plot_name'],'$$$');
                }
                if(!empty($v['vod_plot_detail'])){
                    $v['vod_plot_detail'] = trim($v['vod_plot_detail'],'$$$');
                }
                if(empty($v['vod_isend']) && !empty($v['vod_serial'])){
                    $v['vod_isend'] = 0;
                }
                if($config['hits_start']>0 && $config['hits_end']>0) {
                    $v['vod_hits'] = rand($config['hits_start'], $config['hits_end']);
                    $v['vod_hits_day'] = rand($config['hits_start'], $config['hits_end']);
                    $v['vod_hits_week'] = rand($config['hits_start'], $config['hits_end']);
                    $v['vod_hits_month'] = rand($config['hits_start'], $config['hits_end']);
                }

                if($config['updown_start']>0 && $config['updown_end']){
                    $v['vod_up'] = rand($config['updown_start'], $config['updown_end']);
                    $v['vod_down'] = rand($config['updown_start'], $config['updown_end']);
                }

                if($config['score']==1) {
                    $v['vod_score_num'] = rand(1, 1000);
                    $v['vod_score_all'] = $v['vod_score_num'] * rand(1, 10);
                    $v['vod_score'] = round($v['vod_score_all'] / $v['vod_score_num'], 1);
                }

                if ($config['psename'] == 1) {
                    $v['vod_name'] = mac_rep_pse_syn($pse_name, $v['vod_name']);
                }
                if ($config['psernd'] == 1) {
                    $v['vod_content'] = mac_rep_pse_rnd($pse_rnd, $v['vod_content']);
                }
                if ($config['psesyn'] == 1) {
                    $v['vod_content'] = mac_rep_pse_syn($pse_syn, $v['vod_content']);
                }
                if (($config['pseplayer'] ?? 0) == 1) {
                    $v['vod_play_from'] = mac_rep_pse_syn($pse_player, $v['vod_play_from']);
                }
                if (($config['psearea'] ?? 0) == 1) {
                    $v['vod_area'] = mac_rep_pse_syn($pse_area, $v['vod_area']);
                }
                if (($config['pselang'] ?? 0) == 1) {
                    $v['vod_lang'] = mac_rep_pse_syn($pse_lang, $v['vod_lang']);
                }

                if(empty($v['vod_blurb'])){
                    $v['vod_blurb'] = mac_plain_text($v['vod_content'] ?? '', 100);
                } else {
                    $v['vod_blurb'] = mac_plain_text($v['vod_blurb']);
                }

                $where = [];

                if (strpos($config['inrule'], 'a')!==false) {
                    $where['vod_name'] = mac_filter_xss($v['vod_name']);
                }
                $blend=false;
                if (strpos($config['inrule'], 'b')!==false) {
                    $where['type_id'] = $v['type_id'];
                }
                if (strpos($config['inrule'], 'c')!==false) {
                    $where['vod_year'] = $v['vod_year'];
                }
                if (strpos($config['inrule'], 'd')!==false) {
                    $where['vod_area'] = $v['vod_area'];
                }
                if (strpos($config['inrule'], 'e')!==false) {
                    $where['vod_lang'] = $v['vod_lang'];
                }
                $search_actor_id_list = [];
                if (strpos($config['inrule'], 'f')!==false) {
                    $where['vod_actor'] = ['like', mac_like_arr(mac_filter_xss($v['vod_actor'])), 'OR'];
                    if ($vod_search_enabled) {
                        $search_actor_id_list = $vod_search->getResultIdList(mac_filter_xss($v['vod_actor']), 'vod_actor', true);
                        $search_actor_id_list = empty($search_actor_id_list) ? [0] : $search_actor_id_list;
                    }
                }
                if (strpos($config['inrule'], 'g')!==false) {
                    $where['vod_director'] = mac_filter_xss($v['vod_director']);
                }
                if (strpos($config['inrule'], 'h')!==false) {
                    $where['vod_douban_id'] = intval($v['vod_douban_id'] ?? 0);
                }
                // Cast, director and empty metadata do not identify a title. Legacy
                // rules without a name or a positive Douban ID must fall back to name,
                // otherwise unrelated videos can overwrite the first matching record.
                if (!isset($where['vod_name']) && (int)($where['vod_douban_id'] ?? 0) <= 0) {
                    $where['vod_name'] = mac_filter_xss($v['vod_name']);
                }
                if ($config['tag'] == 1) {
                    $v['vod_tag'] = mac_filter_xss(mac_get_tag($v['vod_name'], $v['vod_content']));
                }

                if(!empty($where['vod_actor']) && !empty($where['vod_director'])){
                    $blend = true;
                    $GLOBALS['blend'] = [
                        'vod_actor'    => $where['vod_actor'],
                        'vod_director' => $where['vod_director'],
                    ];
                    // 结果太大时，筛选更耗时。仅在结果数量较小时，才加入
                    $GLOBALS['blend']['vod_id'] = null;
                    if ($vod_search_enabled && count($search_actor_id_list) <= $vs_max_id_count) {
                        $GLOBALS['blend']['vod_id'] = ['IN', $search_actor_id_list];
                    }
                    unset($where['vod_actor'],$where['vod_director']);
                }

                if(empty($v['vod_play_url'])){
                    $v['vod_play_url'] = '';
                }
                if(empty($v['vod_down_url'])){
                    $v['vod_down_url'] = '';
                }
                // 验证地址
                $cj_play_from_arr = explode('$$$',$v['vod_play_from'] );
                $cj_play_url_arr = explode('$$$',$v['vod_play_url']);
                $cj_play_server_arr = explode('$$$',$v['vod_play_server']);
                $cj_play_note_arr = explode('$$$',$v['vod_play_note']);
                $cj_down_from_arr = explode('$$$',$v['vod_down_from'] );
                $cj_down_url_arr = explode('$$$',$v['vod_down_url']);
                $cj_down_server_arr = explode('$$$',$v['vod_down_server']);
                $cj_down_note_arr = explode('$$$',$v['vod_down_note']);


                $collect_filter=[];
                foreach($cj_play_from_arr as $kk=>$vv){
                    if(empty($vv)){
                        unset($cj_play_from_arr[$kk]);
                        unset($cj_play_url_arr[$kk]);
                        unset($cj_play_server_arr[$kk]);
                        unset($cj_play_note_arr[$kk]);
                        continue;
                    }

                    if(empty($players[$vv])){
                        unset($cj_play_from_arr[$kk]);
                        unset($cj_play_url_arr[$kk]);
                        unset($cj_play_server_arr[$kk]);
                        unset($cj_play_note_arr[$kk]);
                        continue;
                    }

                    $cj_play_url_arr[$kk] = rtrim($cj_play_url_arr[$kk] ?? '','#');
                    $cj_play_server_arr[$kk] = $cj_play_server_arr[$kk] ?? '';
                    $cj_play_note_arr[$kk] = $cj_play_note_arr[$kk] ?? '';

                    if($param['filter'] > 0){
                        if(strpos(','.$param['filter_from'].',',$vv)!==false) {
                            $collect_filter['play'][$param['filter']]['cj_play_from_arr'][$kk] = $vv;
                            $collect_filter['play'][$param['filter']]['cj_play_url_arr'][$kk] = $cj_play_url_arr[$kk];
                            $collect_filter['play'][$param['filter']]['cj_play_server_arr'][$kk] = $cj_play_server_arr[$kk];
                            $collect_filter['play'][$param['filter']]['cj_play_note_arr'][$kk] = $cj_play_note_arr[$kk];
                        }
                    }
                }
                foreach($cj_down_from_arr as $kk=>$vv){
                    if(empty($vv)){
                        unset($cj_down_from_arr[$kk]);
                        unset($cj_down_url_arr[$kk]);
                        unset($cj_down_server_arr[$kk]);
                        unset($cj_down_note_arr[$kk]);
                        continue;
                    }
                    if(empty($downers[$vv])){
                        unset($cj_down_from_arr[$kk]);
                        unset($cj_down_url_arr[$kk]);
                        unset($cj_down_server_arr[$kk]);
                        unset($cj_down_note_arr[$kk]);
                        continue;
                    }

                    $cj_down_url_arr[$kk] = rtrim($cj_down_url_arr[$kk] ?? '');
                    $cj_down_server_arr[$kk] = $cj_down_server_arr[$kk] ?? '';
                    $cj_down_note_arr[$kk] = $cj_down_note_arr[$kk] ?? '';

                    if($param['filter'] > 0){
                        if(strpos(','.$param['filter_from'].',',$vv)!==false) {
                            $collect_filter['down'][$param['filter']]['cj_down_from_arr'][$kk] = $vv;
                            $collect_filter['down'][$param['filter']]['cj_down_url_arr'][$kk] = $cj_down_url_arr[$kk];
                            $collect_filter['down'][$param['filter']]['cj_down_server_arr'][$kk] = $cj_down_server_arr[$kk];
                            $collect_filter['down'][$param['filter']]['cj_down_note_arr'][$kk] = $cj_down_note_arr[$kk];
                        }
                    }
                }
                $v['vod_play_from'] = (string)join('$$$', (array)$cj_play_from_arr);
                $v['vod_play_url'] = (string)join('$$$', (array)$cj_play_url_arr);
                $v['vod_play_server'] = (string)join('$$$', (array)$cj_play_server_arr);
                $v['vod_play_note'] = (string)join('$$$', (array)$cj_play_note_arr);
                $v['vod_down_from'] = (string)join('$$$', (array)$cj_down_from_arr);
                $v['vod_down_url'] = (string)join('$$$', (array)$cj_down_url_arr);
                $v['vod_down_server'] = (string)join('$$$', (array)$cj_down_server_arr);
                $v['vod_down_note'] = (string)join('$$$', (array)$cj_down_note_arr);

                if($blend===false){
                    $candidates = model('Vod')->where($where)->select();
                }
                else{
                    $candidates = model('Vod')->where($where)
                        ->where(function($query) {
                            $query->where('vod_director',$GLOBALS['blend']['vod_director']);
                            if (!empty($GLOBALS['blend']['vod_id'])) {
                                $query->whereOr('vod_id', $GLOBALS['blend']['vod_id']);
                            } else {
                                $query->whereOr('vod_actor', $GLOBALS['blend']['vod_actor']);
                            }
                        })
                        ->select();
                }

                $matches = [];
                $uncertain = false;
                foreach ($candidates as $candidate) {
                    $identity = $this->videoIdentity($v, $candidate);
                    if ($identity === 1) { $matches[] = $candidate; }
                    elseif ($identity === 0) { $uncertain = true; }
                }
                if (count($matches) > 1 || (!$matches && $uncertain)) {
                    $des = '同名影片身份不明确，已跳过，请补充年份、导演或豆瓣 ID 后重试。';
                    if ($show == 0) { return ['code' => 1001, 'msg' => $des, 'action' => '']; }
                    mac_echo(($k + 1) . '、' . htmlspecialchars($v['vod_name'], ENT_QUOTES, 'UTF-8') . ' ' . $des);
                    continue;
                }
                $info = $matches ? $matches[0] : null;

                if (!$info) {
                    // 新增
                    if ($param['opt'] == 2) {
                        $des= lang('model/collect/not_check_add');
                    } else {
                        if ($param['filter'] == 1 || $param['filter'] == 2) {
                            $pf = $collect_filter['play'][$param['filter']] ?? [];
                            $df = $collect_filter['down'][$param['filter']] ?? [];
                            $v['vod_play_from'] = (string)join('$$$', (array)($pf['cj_play_from_arr'] ?? []));
                            $v['vod_play_url'] = (string)join('$$$', (array)($pf['cj_play_url_arr'] ?? []));
                            $v['vod_play_server'] = (string)join('$$$', (array)($pf['cj_play_server_arr'] ?? []));
                            $v['vod_play_note'] = (string)join('$$$', (array)($pf['cj_play_note_arr'] ?? []));
                            $v['vod_down_from'] = (string)join('$$$', (array)($df['cj_down_from_arr'] ?? []));
                            $v['vod_down_url'] = (string)join('$$$', (array)($df['cj_down_url_arr'] ?? []));
                            $v['vod_down_server'] = (string)join('$$$', (array)($df['cj_down_server_arr'] ?? []));
                            $v['vod_down_note'] = (string)join('$$$', (array)($df['cj_down_note_arr'] ?? []));
                        }
                        $tmp = $this->syncImages($config_sync_pic,  $v['vod_pic'], 'vod');
                        $v['vod_pic'] = (string)$tmp['pic'];
                        $msg = $tmp['msg'];
                        $v = VodValidate::formatDataBeforeDb($v);
                        $vod_id = model('Vod')->insert($v, false, true);
                        if ($vod_id > 0) {
                            $vod_search_enabled && $vod_search->checkAndUpdateTopResults(['vod_id' => $vod_id] + $v, true);
                            $color = 'green';
                            $des = lang('model/collect/add_ok');
                        } else {
                            $color = 'red';
                            $des = 'vod insert failed';
                        }
                    }
                } else {
                    // 更新
                    if(empty($config['uprule'])){
                        $des = lang('model/collect/uprule_empty');
                    }
                    elseif ($info['vod_lock'] == 1) {
                        $des = lang('model/collect/data_lock');
                    }
                    elseif($param['opt'] == 1){
                        $des = lang('model/collect/not_check_update');
                    }
                    else {
                        unset($v['vod_time_add']);

                        $update = [];
                        $ec=false;

                        if($param['filter'] ==1 || $param['filter']==3){
                            // PHP8：filter 开启但本条无匹配到播放/下载组时，$collect_filter[...][$filter] 不存在
                            $pf = $collect_filter['play'][$param['filter']] ?? [];
                            $df = $collect_filter['down'][$param['filter']] ?? [];
                            $cj_play_from_arr = $pf['cj_play_from_arr'] ?? [];
                            $cj_play_url_arr = $pf['cj_play_url_arr'] ?? [];
                            $cj_play_server_arr = $pf['cj_play_server_arr'] ?? [];
                            $cj_play_note_arr = $pf['cj_play_note_arr'] ?? [];
                            $cj_down_from_arr = $df['cj_down_from_arr'] ?? [];
                            $cj_down_url_arr = $df['cj_down_url_arr'] ?? [];
                            $cj_down_server_arr = $df['cj_down_server_arr'] ?? [];
                            $cj_down_note_arr = $df['cj_down_note_arr'] ?? [];
                        }

                        if (strpos(',' . $config['uprule'], 'a')!==false && !empty($v['vod_play_from'])) {
                            $old_play_from = $info['vod_play_from'];
                            $old_play_url = $info['vod_play_url'];
                            $old_play_server = $info['vod_play_server'];
                            $old_play_note = $info['vod_play_note'];
                            foreach ($cj_play_from_arr as $k2 => $v2) {
                                $cj_play_from = $v2;
                                $cj_play_url = $cj_play_url_arr[$k2] ?? '';
                                $cj_play_server = $cj_play_server_arr[$k2] ?? '';
                                $cj_play_note = $cj_play_note_arr[$k2] ?? '';
                                if ($cj_play_url == $info['vod_play_url']) {
                                    $des .= lang('model/collect/playurl_same');
                                } elseif (empty($cj_play_from)) {
                                    $des .= lang('model/collect/playfrom_empty');
                                } elseif (strpos('$$$'.$info['vod_play_from'].'$$$', '$$$'.$cj_play_from.'$$$') === false) {
                                    // 新类型播放组，加入
                                    $color = 'green';
                                    $des .= lang('model/collect/playgroup_add_ok',[$cj_play_from]);
                                    if(!empty($old_play_from)){
                                        $old_play_url .="$$$";
                                        $old_play_from .= "$$$" ;
                                        $old_play_server .= "$$$" ;
                                        $old_play_note .= "$$$" ;
                                    }
                                    $old_play_url .= "" . $cj_play_url;
                                    $old_play_from .= "" . $cj_play_from;
                                    $old_play_server .= "" . $cj_play_server;
                                    $old_play_note .= "" . $cj_play_note;
                                    $ec=true;
                                }  elseif (!empty($cj_play_url)) {
                                    // 同类型播放组
                                    $arr1 = explode("$$$", $old_play_url);
                                    $arr2 = explode("$$$", $old_play_from);
                                    $play_key = array_search($cj_play_from, $arr2);
                                    if ($play_key === false || !array_key_exists($play_key, $arr1)) {
                                        $des .= lang('model/collect/playgroup_same',[$cj_play_from]);
                                    } elseif ($arr1[$play_key] == $cj_play_url) {
                                        $des .= lang('model/collect/playgroup_same',[$cj_play_from]);;
                                    } else {
                                        $color = 'green';
                                        $des .= lang('model/collect/playgroup_update_ok',[$cj_play_from]);
                                        // 根据「地址二更规则」配置，替换或合并
                                        if ($config['urlrole'] == 1) {
                                            $tmp1 = explode('#',$arr1[$play_key]);
                                            $tmp2 = explode('#',$cj_play_url);
                                            $tmp1 = array_merge($tmp1,$tmp2);
                                            $tmp1 = array_unique($tmp1);
                                            $cj_play_url = join('#', (array)$tmp1);
                                            unset($tmp1,$tmp2);
                                        }
                                        $arr1[$play_key] = $cj_play_url;
                                        $ec=true;
                                    }
                                    $old_play_url = join('$$$', (array)$arr1);
                                }
                            }
                            if($ec) {
                                $update['vod_play_from'] = $old_play_from;
                                $update['vod_play_url'] = $old_play_url;
                                $update['vod_play_server'] = $old_play_server;
                                $update['vod_play_note'] = $old_play_note;
                            }
                        }

                        $ec=false;
                        if (strpos(',' . $config['uprule'], 'b')!==false && !empty($v['vod_down_from'])) {
                            $old_down_from = $info['vod_down_from'];
                            $old_down_url = $info['vod_down_url'];
                            $old_down_server = $info['vod_down_server'];
                            $old_down_note = $info['vod_down_note'];

                            foreach ($cj_down_from_arr as $k2 => $v2) {
                                $cj_down_from = $v2;
                                $cj_down_url = $cj_down_url_arr[$k2] ?? '';
                                $cj_down_server = $cj_down_server_arr[$k2] ?? '';
                                $cj_down_note = $cj_down_note_arr[$k2] ?? '';


                                if ($cj_down_url == $info['vod_down_url']) {
                                    $des .= lang('model/collect/downurl_same');
                                } elseif (empty($cj_down_from)) {
                                    $des .= lang('model/collect/downfrom_empty');
                                } elseif (strpos('$$$'.$info['vod_down_from'].'$$$', '$$$'.$cj_down_from.'$$$')===false) {
                                    $color = 'green';
                                    $des .= lang('model/collect/downgroup_add_ok',[$cj_down_from]);
                                    if(!empty($old_down_from)){
                                        $old_down_url .="$$$";
                                        $old_down_from .= "$$$" ;
                                        $old_down_server .= "$$$" ;
                                        $old_down_note .= "$$$" ;
                                    }

                                    $old_down_url .= "" .$cj_down_url;
                                    $old_down_from .= "" .$cj_down_from;
                                    $old_down_server .= "" .$cj_down_server;
                                    $old_down_note .= "" .$cj_down_note;
                                    $ec=true;
                                } elseif (!empty($cj_down_url)) {
                                    $arr1 = explode("$$$", $old_down_url);
                                    $arr2 = explode("$$$", $old_down_from);
                                    $down_key = array_search($cj_down_from, $arr2);
                                    if ($down_key === false || !array_key_exists($down_key, $arr1)) {
                                        $des .= lang('model/collect/downgroup_same',[$cj_down_from]);
                                    } elseif ($arr1[$down_key] == $cj_down_url) {
                                        $des .= lang('model/collect/downgroup_same',[$cj_down_from]);
                                    } else {
                                        $color = 'green';
                                        $des .= lang('model/collect/downgroup_update_ok',[$cj_down_from]);
                                        // 根据「地址二更规则」配置，替换或合并
                                        // “采集参数配置--地址二更规则”配置需要对下载地址生效
                                        // https://github.com/magicblack/maccms10/issues/893
                                        if ($config['urlrole'] == 1) {
                                            $tmp1 = explode('#',$arr1[$down_key]);
                                            $tmp2 = explode('#',$cj_down_url);
                                            $tmp1 = array_merge($tmp1,$tmp2);
                                            $tmp1 = array_unique($tmp1);
                                            $cj_down_url = join('#', (array)$tmp1);
                                            unset($tmp1,$tmp2);
                                        }
                                        $arr1[$down_key] = $cj_down_url;
                                        $ec=true;
                                    }
                                    $old_down_url = join('$$$', (array)$arr1);
                                }
                            }

                            if($ec) {
                                $update['vod_down_from'] = $old_down_from;
                                $update['vod_down_url'] = $old_down_url;
                                $update['vod_down_server'] = $old_down_server;
                                $update['vod_down_note'] = $old_down_note;
                            }
                        }

                        if (strpos(',' . $config['uprule'], 'c')!==false && !empty($v['vod_serial']) && $v['vod_serial']!=$info['vod_serial']) {
                            $update['vod_serial'] = $v['vod_serial'];
                            // 连载数如果均为整数，则取较大值
                            // https://github.com/magicblack/maccms10/issues/878
                            if (floor($v['vod_serial']) == $v['vod_serial'] && floor($info['vod_serial']) == $info['vod_serial']) {
                                $update['vod_serial'] = max($v['vod_serial'], $info['vod_serial']);
                            }
                        }
                        if (strpos(',' . $config['uprule'], 'd')!==false && !empty($v['vod_remarks']) && $v['vod_remarks']!=$info['vod_remarks']) {
                            $update['vod_remarks'] = $v['vod_remarks'];
                        }
                        if (strpos(',' . $config['uprule'], 'e')!==false && !empty($v['vod_director']) && $v['vod_director']!=$info['vod_director']) {
                            $update['vod_director'] = $v['vod_director'];
                        }
                        if (strpos(',' . $config['uprule'], 'f')!==false && !empty($v['vod_actor']) && $v['vod_actor']!=$info['vod_actor']) {
                            $update['vod_actor'] = $v['vod_actor'];
                        }
                        if (strpos(',' . $config['uprule'], 'g')!==false && !empty($v['vod_year']) && $v['vod_year']!=$info['vod_year']) {
                            $update['vod_year'] = $v['vod_year'];
                        }
                        if (strpos(',' . $config['uprule'], 'h')!==false && !empty($v['vod_area']) && $v['vod_area']!=$info['vod_area']) {
                            $update['vod_area'] = $v['vod_area'];
                        }
                        if (strpos(',' . $config['uprule'], 'i')!==false && !empty($v['vod_lang']) && $v['vod_lang']!=$info['vod_lang']) {
                            $update['vod_lang'] = $v['vod_lang'];
                        }
                        if (strpos(',' . $config['uprule'], 'j')!==false && (substr($info["vod_pic"], 0, 4) == "http" || empty($info['vod_pic']) ) && $v['vod_pic']!=$info['vod_pic'] ) {
                            $tmp = $this->syncImages($config_sync_pic, $v['vod_pic'],'vod');
                            $update['vod_pic'] = (string)$tmp['pic'];
                            $msg =$tmp['msg'];
                        }
                        if (strpos(',' . $config['uprule'], 'k')!==false && !empty($v['vod_content']) && $v['vod_content']!=$info['vod_content']) {
                            $update['vod_content'] = $v['vod_content'];
                        }
                        if (strpos(',' . $config['uprule'], 'l')!==false && !empty($v['vod_tag']) && $v['vod_tag']!=$info['vod_tag']) {
                            $update['vod_tag'] = $v['vod_tag'];
                        }
                        if (strpos(',' . $config['uprule'], 'm')!==false && !empty($v['vod_sub']) && $v['vod_sub']!=$info['vod_sub']) {
                            $update['vod_sub'] = $v['vod_sub'];
                        }
                        if (strpos(',' . $config['uprule'], 'n')!==false && !empty($v['vod_class']) && $v['vod_class']!=$info['vod_class']) {
                            $update['vod_class'] = mac_txt_merge($info['vod_class'], $v['vod_class']);
                        }
                        if (strpos(',' . $config['uprule'], 'o')!==false && !empty($v['vod_writer']) && $v['vod_writer']!=$info['vod_writer']) {
                            $update['vod_writer'] = $v['vod_writer'];
                        }
                        if (strpos(',' . $config['uprule'], 'p')!==false && !empty($v['vod_version']) && $v['vod_version']!=$info['vod_version']) {
                            $update['vod_version'] = $v['vod_version'];
                        }
                        if (strpos(',' . $config['uprule'], 'q')!==false && !empty($v['vod_state']) && $v['vod_state']!=$info['vod_state']) {
                            $update['vod_state'] = $v['vod_state'];
                        }
                        if (strpos(',' . $config['uprule'], 'r')!==false && !empty($v['vod_blurb']) && $v['vod_blurb']!=$info['vod_blurb']) {
                            $update['vod_blurb'] = $v['vod_blurb'];
                        }
                        if (strpos(',' . $config['uprule'], 's')!==false && !empty($v['vod_tv']) && $v['vod_tv']!=$info['vod_tv']) {
                            $update['vod_tv'] = $v['vod_tv'];
                        }
                        if (strpos(',' . $config['uprule'], 't')!==false && !empty($v['vod_weekday']) && $v['vod_weekday']!=$info['vod_weekday']) {
                            $update['vod_weekday'] = $v['vod_weekday'];
                        }
                        if (strpos(',' . $config['uprule'], 'u')!==false && !empty($v['vod_total']) && $v['vod_total']!=$info['vod_total']) {
                            $update['vod_total'] = $v['vod_total'];
                        }
                        if (strpos(',' . $config['uprule'], 'v')!==false && (isset($v['vod_isend']) && $v['vod_isend'] !== '') && $v['vod_isend']!=$info['vod_isend']) {
                            $update['vod_isend'] = $v['vod_isend'];
                        }
                        if (strpos(',' . $config['uprule'], 'w')!==false && !empty($v['vod_plot_name']) && $v['vod_plot_name']!=$info['vod_plot_name']) {
                            $update['vod_plot'] = 1;
                            $update['vod_plot_name'] = $v['vod_plot_name'];
                            $update['vod_plot_detail'] = $v['vod_plot_detail'];
                        }

                        if(count($update)>0){
                            $update['vod_time'] = time();
                            $where = [];
                            $where['vod_id'] = $info['vod_id'];
                            $update = VodValidate::formatDataBeforeDb($update);
                            $res = model('Vod')->where($where)->update($update);
                            $color = 'green';
                            if ($res === false) {
                                $color = 'red';
                                $des = 'update failed';
                            } else {
                                $action = 'updated';
                                $des .= lang('update_ok');
                            }
                        }
                        else{
                            $des = lang('model/collect/not_need_update');
                        }

                    }
                }
                if(Cache::has(($GLOBALS['config']['app']['cache_flag'] ?? '').'_vod_repeat_table_created_time')){
                    Cache::rm(($GLOBALS['config']['app']['cache_flag'] ?? '').'_vod_repeat_table_created_time');
                }
            }
            if($show==1) {
                mac_echo( ($k + 1) .'、'. $v['vod_name'] . " <font color='{$color}'>" .$des .'</font>'. $msg.'' );
            }
            else{
                return ['code'=>($color=='red' ? 1001 : 1),'msg'=>$des, 'action'=>$action ];
            }
        }

        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_vod';
        if(ENTRANCE=='api'){
            Cache::rm($key);
            if ($data['page']['page'] < $data['page']['pagecount']) {
                $param['page'] = intval($data['page']['page']) + 1;
                $res = $this->vod($param);
                if($res['code']>1){
                    return $this->error($res['msg']);
                }
                $this->vod_data($param,$res );
            }
            mac_echo(lang('model/collect/is_over'));
            die;
        }

        if(empty($GLOBALS['config']['app']['collect_timespan'])){
            $GLOBALS['config']['app']['collect_timespan'] = 3;
        }
        if($show==1) {
            if ($param['ac'] == 'cjsel') {
                Cache::rm($key);
                mac_echo(lang('model/collect/is_over'));
                unset($param['ids']);
                $param['ac'] = 'list';
                $url = url('api') . '?' . http_build_query($param);
                $ref = $_SERVER["HTTP_REFERER"];
                if(!empty($ref)){
                   $url = $ref;
                }

                mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
            } else {
                if ($data['page']['page'] >= $data['page']['pagecount']) {
                    Cache::rm($key);
                    mac_echo(lang('model/collect/is_over'));
                    unset($param['page'],$param['ids']);
                    $param['ac'] = 'list';
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                } else {
                    $param['page'] = intval($data['page']['page']) + 1;
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan'] );
                }
            }
        }
    }

    public function art_json($param)
    {
        $url_param = [];
        $url_param['ac'] = $param['ac'];
        $url_param['t'] = $param['t'];
        $url_param['pg'] = is_numeric($param['page']) ? $param['page'] : '';
        $url_param['h'] = $param['h'];
        $url_param['ids'] = $param['ids'];
        $url_param['wd'] = $param['wd'];

        if($param['ac']!='list'){
            $url_param['ac'] = 'detail';
        }

        $url = $param['cjurl'];
        if(strpos($url,'?')===false){
            $url .='?';
        }
        else{
            $url .='&';
        }

        $url .= http_build_query($url_param). base64_decode($param['param']);
        $result = $this->checkCjUrl($url);
        if ($result['code'] > 1) {
            return $result;
        }
        // 安全：SSRF 场景禁跟随重定向，避免校验过的公网地址 302 到内网
        $html = mac_curl_get($url, [], '', true);
        if(empty($html)){
            return ['code'=>1001, 'msg'=>lang('model/collect/get_html_err') . ', url: ' . $url];
        }
        $html = mac_filter_tags($html);
        $json = $this->decodeCollectJson($html);
        if(!$json){
            return ['code'=>1002, 'msg'=>lang('model/collect/json_err') . ': ' . mb_substr($html, 0, 15)];
        }

        $array_page = [];
        $array_page['page'] = $json['page'];
        $array_page['pagecount'] = $json['pagecount'];
        $array_page['pagesize'] = $this->getRemotePageSize($json['limit'] ?? 0);
        $array_page['recordcount'] = $json['total'];
        $array_page['url'] = $url;

        $type_list = model('Type')->getCache('type_list');
        $bind_list = config('bind');

        $key = 0;
        $array_data = [];
        $__max_rows = $this->maxRemoteRows(); $__row_i = 0;
        foreach($json['list'] as $key=>$v){
            if ($__row_i++ >= $__max_rows) { break; }
            $array_data[$key] = $v;
            $bind_key = $param['cjflag'] .'_'.$v['type_id'];
            $local_type_id = intval(is_array($bind_list) ? ($bind_list[$bind_key] ?? 0) : 0);
            if($local_type_id >0){
                $array_data[$key]['type_id'] = $local_type_id;
            }
            else{
                $array_data[$key]['type_id'] = 0;
            }
        }

        $array_type = [];
        $key=0;
        // 分类列表
        if($param['ac'] == 'list'){
            foreach($json['class'] as $k=>$v){
                $array_type[$key]['type_id'] = $v['type_id'];
                $array_type[$key]['type_name'] = $v['type_name'];
                $key++;
            }
        }

        $res = ['code'=>1, 'msg'=>'ok', 'page'=>$array_page, 'type'=>$array_type, 'data'=>$array_data ];
        return $res;
    }

    public function art_data($param,$data,$show=1)
    {
        if($show==1) {
            mac_echo('[' . __FUNCTION__ . '] ' . lang('model/collect/data_tip1',[$data['page']['page'],$data['page']['pagecount'],$data['page']['url']]));
        }

        $config = config('maccms.collect');
        $config = $config['art'];
        $config_sync_pic = $param['sync_pic_opt'] > 0 ? $param['sync_pic_opt'] : $config['pic'];

        $type_list = model('Type')->getCache('type_list');
        $filter_arr = explode(',',$config['filter']); $filter_arr = array_filter($filter_arr);
        $pse_rnd = explode('#',$config['words']); $pse_rnd = array_filter($pse_rnd);
        $pse_syn = mac_txt_explain($config['thesaurus'] ?? '', true);


        foreach($data['data'] as $k=>$v){
            $color='red';
            $des='';
            $msg='';
            $tmp='';

            if($v['type_id'] ==0){
                $des = lang('model/collect/type_err');
            }
            elseif(empty($v['art_name'])) {
                $des = lang('model/collect/name_err');
            }
            elseif( mac_array_filter($filter_arr,$v['art_name']) !==false) {
                $des = lang('model/collect/name_in_filter_err');
            }
            else {
                unset($v['art_id']);

                $v = $this->cleanCollectRow($v);
                $v += array_fill_keys(['art_title', 'art_note', 'art_content', 'art_pic'], '');
                $v['art_name'] = trim($v['art_name']);
                $v['type_id_1'] = intval(($type_list[$v['type_id']] ?? [])['type_pid'] ?? 0);
                $v['art_en'] = Pinyin::get($v['art_name']);
                $v['art_letter'] = strtoupper(substr($v['art_en'],0,1));
                $v['art_time_add'] = time();
                $v['art_time'] = time();
                $v['art_status'] = intval($config['status']);
                $v['art_lock'] = intval($v['art_lock'] ?? 0);
                if(!empty($v['art_status'])) {
                    $v['art_status'] = intval($v['art_status'] ?? 0);
                }
                $v['art_level'] = intval($v['art_level'] ?? 0);
                $v['art_hits'] = intval($v['art_hits'] ?? 0);
                $v['art_hits_day'] = intval($v['art_hits_day'] ?? 0);
                $v['art_hits_week'] = intval($v['art_hits_week'] ?? 0);
                $v['art_hits_month'] = intval($v['art_hits_month'] ?? 0);
                $v['art_stint'] = intval($v['art_stint'] ?? 0);

                $v['art_up'] = intval($v['art_up'] ?? 0);
                $v['art_down'] = intval($v['art_down'] ?? 0);


                $v['art_score'] = floatval($v['art_score'] ?? 0);
                $v['art_score_all'] = intval($v['art_score_all'] ?? 0);
                $v['art_score_num'] = intval($v['art_score_num'] ?? 0);

                if($config['hits_start']>0 && $config['hits_end']>0) {
                    $v['art_hits'] = rand($config['hits_start'], $config['hits_end']);
                    $v['art_hits_day'] = rand($config['hits_start'], $config['hits_end']);
                    $v['art_hits_week'] = rand($config['hits_start'], $config['hits_end']);
                    $v['art_hits_month'] = rand($config['hits_start'], $config['hits_end']);
                }

                if($config['updown_start']>0 && $config['updown_end']){
                    $v['art_up'] = rand($config['updown_start'], $config['updown_end']);
                    $v['art_down'] = rand($config['updown_start'], $config['updown_end']);
                }

                if($config['score']==1) {
                    $v['art_score_num'] = rand(1, 1000);
                    $v['art_score_all'] = $v['art_score_num'] * rand(1, 10);
                    $v['art_score'] = round($v['art_score_all'] / $v['art_score_num'], 1);
                }

                if ($config['psernd'] == 1) {
                    $v['art_content'] = mac_rep_pse_rnd($pse_rnd, $v['art_content']);
                }
                if ($config['psesyn'] == 1) {
                    $v['art_content'] = mac_rep_pse_syn($pse_syn, $v['art_content']);
                }

                if(empty($v['art_blurb'])){
                    $v['art_blurb'] = mac_plain_text(str_replace('$$$', '', $v['art_content'] ?? ''), 100);
                } else {
                    $v['art_blurb'] = mac_plain_text($v['art_blurb']);
                }

                $where = [];
                $where['art_name'] = $v['art_name'];
                if (strpos($config['inrule'], 'b')!==false) {
                    $where['type_id'] = $v['type_id'];
                }

                // 验证地址
                $cj_title_arr = explode('$$$',$v['art_title'] );
                $cj_note_arr = explode('$$$',$v['art_note']);
                $cj_content_arr = explode('$$$',$v['art_content']);

                $tmp_title_arr=[];
                $tmp_note_arr=[];
                $tmp_content_arr=[];
                foreach($cj_content_arr as $kk=>$vv){
                    $tmp_content_arr[] = $vv;
                    $tmp_title_arr[] = $cj_title_arr[$kk] ?? '';
                    $tmp_note_arr[] = $cj_note_arr[$kk] ?? '';
                }
                $v['art_title'] = join('$$$', (array)$tmp_title_arr);
                $v['art_note'] = join('$$$', (array)$tmp_note_arr);
                $v['art_content'] = join('$$$', (array)$tmp_content_arr);


                $info = model('Art')->where($where)->find();
                if (!$info) {
                    $tmp = $this->syncImages($config_sync_pic, $v['art_pic'],'art');
                    $v['art_pic'] = (string)$tmp['pic'];

                    $msg = $tmp['msg'];
                    $res = model('Art')->insert($v);
                    if($res===false){

                    }
                    $color ='green';
                    $des= lang('model/collect/add_ok');
                }
                else {


                    if(empty($config['uprule'])){
                        $des = lang('model/collect/uprule_empty');
                    }
                    elseif($info['art_lock'] == 1) {
                        $des = lang('model/collect/data_lock');
                    }
                    else {
                        unset($v['art_time_add']);

                        $old_art_title = $info['art_title'];
                        $old_art_note = $info['art_note'];
                        $old_art_content = $info['art_content'];

                        $cj_art_title = $v['art_title'];
                        $cj_art_note = $v['art_note'];
                        $cj_art_content = $v['art_content'];

                        $rc=true;

                        if($rc){
                            $update=[];

                            if(strpos(','.$config['uprule'],'a')!==false && !empty($v['art_content']) && $v['art_content']!=$info['art_content']){
                                $update['art_content'] = $v['art_content'];
                            }
                            if(strpos(','.$config['uprule'],'b')!==false && !empty($v['art_author']) && $v['art_author']!=$info['art_author']){
                                $update['art_author'] = $v['art_author'];
                            }
                            if(strpos(','.$config['uprule'],'c')!==false && !empty($v['art_from']) && $v['art_from']!=$info['art_from']){
                                $update['art_from'] = $v['art_from'];
                            }

                            if(strpos(','.$config['uprule'],'d')!==false && (substr($info["art_pic"], 0, 4) == "http" || empty($info['art_pic']))  && $v['art_pic']!=$info['art_pic'] ){
                                $tmp = $this->syncImages($config_sync_pic, $v['art_pic'],'art');
                                $update['art_pic'] = (string)$tmp['pic'];
                                $msg =$tmp['msg'];
                            }
                            if(strpos(','.$config['uprule'],'e')!==false && !empty($v['art_tag']) && $v['art_tag']!=$info['art_tag']){
                                $update['art_tag'] = $v['art_tag'];
                            }
                            if(strpos(','.$config['uprule'],'f')!==false && !empty($v['art_blurb']) && $v['art_blurb']!=$info['art_blurb']){
                                $update['art_blurb'] = $v['art_blurb'];
                            }


                            if(count($update)>0){
                                $update['art_time'] = time();
                                $where = [];
                                $where['art_id'] = $info['art_id'];
                                $res = model('Art')->where($where)->update($update);
                                $color = 'green';
                                if($res===false){

                                }
                            }
                            else{
                                $des = lang('model/collect/not_need_update');
                            }
                        }

                    }
                }
            }
            if($show==1) {
                mac_echo( ($k + 1) . $v['art_name'] . "<font color=$color>" .$des .'</font>'. $msg . '');
            }
            else{
                return ['code'=>($color=='red' ? 1001 : 1),'msg'=> $v['art_name'] .' '.$des ];
            }
        }

        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_art';
        if(ENTRANCE=='api'){
            Cache::rm($key);
            if ($data['page']['page'] < $data['page']['pagecount']) {
                $param['page'] = intval($data['page']['page']) + 1;
                $res = $this->art($param);
                if($res['code']>1){
                    return $this->error($res['msg']);
                }
                $this->art_data($param,$res );
            }
            mac_echo(lang('model/collect/is_over'));
            die;
        }

        if(empty($GLOBALS['config']['app']['collect_timespan'])){
            $GLOBALS['config']['app']['collect_timespan'] = 3;
        }

        if($show==1) {
            if ($param['ac'] == 'cjsel') {
                Cache::rm($key);
                mac_echo(lang('model/collect/is_over'));
                unset($param['ids']);
                $param['ac'] = 'list';
                $url = url('api') . '?' . http_build_query($param);
                $ref = $_SERVER["HTTP_REFERER"];
                if(!empty($ref)){
                    $url = $ref;
                }
                mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
            } else {
                if ($data['page']['page'] >= $data['page']['pagecount']) {
                    Cache::rm($key);
                    mac_echo(lang('model/collect/is_over'));
                    unset($param['page']);
                    $param['ac'] = 'list';
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                } else {
                    $param['page'] = intval($data['page']['page']) + 1;
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                }
            }
        }
    }

    public function actor_json($param)
    {
        $url_param = [];
        $url_param['ac'] = $param['ac'];
        $url_param['t'] = $param['t'];
        $url_param['pg'] = is_numeric($param['page']) ? $param['page'] : '';
        $url_param['h'] = $param['h'];
        $url_param['ids'] = $param['ids'];
        $url_param['wd'] = $param['wd'];

        if($param['ac']!='list'){
            $url_param['ac'] = 'detail';
        }

        $url = $param['cjurl'];
        if(strpos($url,'?')===false){
            $url .='?';
        }
        else{
            $url .='&';
        }
        $url .= http_build_query($url_param).base64_decode($param['param']);
        $result = $this->checkCjUrl($url);
        if ($result['code'] > 1) {
            return $result;
        }
        // 安全：SSRF 场景禁跟随重定向，避免校验过的公网地址 302 到内网
        $html = mac_curl_get($url, [], '', true);
        if(empty($html)){
            return ['code'=>1001, 'msg'=>lang('model/collect/get_html_err') . ', url: ' . $url];
        }
        $html = mac_filter_tags($html);
        $json = $this->decodeCollectJson($html);
        if(!$json){
            return ['code'=>1002, 'msg'=>lang('model/collect/json_err') . ': ' . mb_substr($html, 0, 15)];
        }

        $array_page = [];
        $array_page['page'] = $json['page'];
        $array_page['pagecount'] = $json['pagecount'];
        $array_page['pagesize'] = $this->getRemotePageSize($json['limit'] ?? 0);
        $array_page['recordcount'] = $json['total'];
        $array_page['url'] = $url;

        $type_list = model('Type')->getCache('type_list');
        $bind_list = config('bind');

        $key = 0;
        $array_data = [];
        $__max_rows = $this->maxRemoteRows(); $__row_i = 0;
        foreach($json['list'] as $key=>$v){
            if ($__row_i++ >= $__max_rows) { break; }
            $array_data[$key] = $v;
            $bind_key = $param['cjflag'] .'_'.$v['type_id'];
            $local_type_id = intval(is_array($bind_list) ? ($bind_list[$bind_key] ?? 0) : 0);
            if($local_type_id >0){
                $array_data[$key]['type_id'] = $local_type_id;
            }
            else{
                $array_data[$key]['type_id'] = 0;
            }
        }

        $array_type = [];
        $key=0;
        // 分类列表
        if($param['ac'] == 'list'){
            foreach($json['class'] as $k=>$v){
                $array_type[$key]['type_id'] = $v['type_id'];
                $array_type[$key]['type_name'] = $v['type_name'];
                $key++;
            }
        }

        $res = ['code'=>1, 'msg'=>'ok', 'page'=>$array_page, 'type'=>$array_type, 'data'=>$array_data ];
        return $res;
    }

    public function actor_data($param,$data,$show=1)
    {
        if($show==1) {
            mac_echo('[' . __FUNCTION__ . '] ' . lang('model/collect/data_tip1',[$data['page']['page'],$data['page']['pagecount'],$data['page']['url']]));
        }

        $config = config('maccms.collect');
        $config = $config['actor'];
        $config_sync_pic = $param['sync_pic_opt'] > 0 ? $param['sync_pic_opt'] : $config['pic'];

        $type_list = model('Type')->getCache('type_list');
        $filter_arr = explode(',',$config['filter']); $filter_arr = array_filter($filter_arr);
        $pse_rnd = explode('#',$config['words']); $pse_rnd = array_filter($pse_rnd);
        $pse_syn = mac_txt_explain($config['thesaurus'] ?? '', true);

        foreach($data['data'] as $k=>$v){

            $color='red';
            $des='';
            $msg='';
            $tmp='';

            if($v['type_id'] ==0){
                $des = lang('model/collect/type_err');
            }
            elseif(empty($v['actor_name']) || empty($v['actor_sex'])) {
                $des = lang('odel/collect/actor_data_require');
            }
            elseif( mac_array_filter($filter_arr,$v['actor_name'])!==false) {
                $des = lang('model/collect/name_in_filter_err');
            }
            else {
                unset($v['actor_id']);

                $v = $this->cleanCollectRow($v);
                $v['actor_name'] = trim($v['actor_name']);
                $v['type_id_1'] = intval(($type_list[$v['type_id']] ?? [])['type_pid'] ?? 0);
                $v['actor_en'] = Pinyin::get($v['actor_name']);
                $v['actor_letter'] = strtoupper(substr($v['actor_en'],0,1));
                $v['actor_time_add'] = time();
                $v['actor_time'] = time();
                $v['actor_status'] = intval($config['status']);
                $v['actor_lock'] = intval($v['actor_lock'] ?? 0);
                if(!empty($v['actor_status'])) {
                    $v['actor_status'] = intval($v['actor_status'] ?? 0);
                }
                $v['actor_level'] = intval($v['actor_level'] ?? 0);
                $v['actor_hits'] = intval($v['actor_hits'] ?? 0);
                $v['actor_hits_day'] = intval($v['actor_hits_day'] ?? 0);
                $v['actor_hits_week'] = intval($v['actor_hits_week'] ?? 0);
                $v['actor_hits_month'] = intval($v['actor_hits_month'] ?? 0);

                $v['actor_up'] = intval($v['actor_up'] ?? 0);
                $v['actor_down'] = intval($v['actor_down'] ?? 0);

                $v['actor_score'] = floatval($v['actor_score'] ?? 0);
                $v['actor_score_all'] = intval($v['actor_score_all'] ?? 0);
                $v['actor_score_num'] = intval($v['actor_score_num'] ?? 0);

                if($config['hits_start']>0 && $config['hits_end']>0) {
                    $v['actor_hits'] = rand($config['hits_start'], $config['hits_end']);
                    $v['actor_hits_day'] = rand($config['hits_start'], $config['hits_end']);
                    $v['actor_hits_week'] = rand($config['hits_start'], $config['hits_end']);
                    $v['actor_hits_month'] = rand($config['hits_start'], $config['hits_end']);
                }

                if($config['updown_start']>0 && $config['updown_end']){
                    $v['actor_up'] = rand($config['updown_start'], $config['updown_end']);
                    $v['actor_down'] = rand($config['updown_start'], $config['updown_end']);
                }

                if($config['score']==1) {
                    $v['actor_score_num'] = rand(1, 1000);
                    $v['actor_score_all'] = $v['actor_score_num'] * rand(1, 10);
                    $v['actor_score'] = round($v['actor_score_all'] / $v['actor_score_num'], 1);
                }

                if ($config['psernd'] == 1) {
                    $v['actor_content'] = mac_rep_pse_rnd($pse_rnd, $v['actor_content']);
                }
                if ($config['psesyn'] == 1) {
                    $v['actor_content'] = mac_rep_pse_syn($pse_syn, $v['actor_content']);
                }

                if(empty($v['actor_blurb'])){
                    $v['actor_blurb'] = mac_substring( strip_tags($v['actor_content']) ,100);
                }

                $where = [];
                $where['actor_name'] = $v['actor_name'];
                if (strpos($config['inrule'], 'b')!==false) {
                    $where['actor_sex'] = $v['actor_sex'];
                }
                if (strpos($config['inrule'], 'c')!==false) {
                    $where['type_id'] = $v['type_id'];
                }

                $info = model('Actor')->where($where)->find();
                if (!$info) {
                    $tmp = $this->syncImages($config_sync_pic, $v['actor_pic'],'actor');
                    $v['actor_pic'] = $tmp['pic'];
                    $msg = $tmp['msg'];
                    $res = model('Actor')->insert($v);
                    if($res===false){

                    }
                    $color ='green';
                    $des= lang('model/collect/add_ok');
                } else {

                    if(empty($config['uprule'])){
                        $des = lang('model/collect/uprule_empty');
                    }
                    elseif ($info['actor_lock'] == 1) {
                        $des = lang('model/collect/data_lock');
                    }
                    else {
                        unset($v['actor_time_add']);
                        $rc=true;
                        if($rc){
                            $update=[];

                            if(strpos(','.$config['uprule'],'a')!==false && !empty($v['actor_content']) && $v['actor_content']!=$info['actor_content']){
                                $update['actor_content'] = $v['actor_content'];
                            }
                            if(strpos(','.$config['uprule'],'b')!==false && !empty($v['actor_blurb']) && $v['actor_blurb']!=$info['actor_blurb']){
                                $update['actor_blurb'] = $v['actor_blurb'];
                            }
                            if(strpos(','.$config['uprule'],'c')!==false && !empty($v['actor_remarks']) && $v['actor_remarks']!=$info['actor_remarks']){
                                $update['actor_remarks'] = $v['actor_remarks'];
                            }
                            if(strpos(','.$config['uprule'],'d')!==false && !empty($v['actor_works']) && $v['actor_works']!=$info['actor_works']){
                                $update['actor_works'] = $v['actor_works'];
                            }
                            if(strpos(','.$config['uprule'],'e')!==false && (substr($info["actor_pic"], 0, 4) == "http" ||empty($info['actor_pic']) ) && $v['actor_pic']!=$info['actor_pic'] ){
                                $tmp = $this->syncImages($config_sync_pic, $v['actor_pic'],'actor');
                                $update['actor_pic'] =$tmp['pic'];
                                $msg =$tmp['msg'];
                            }

                            if(count($update)>0){
                                $update['actor_time'] = time();
                                $where = [];
                                $where['actor_id'] = $info['actor_id'];
                                $res = model('Actor')->where($where)->update($update);
                                $color = 'green';
                                if($res===false){

                                }
                            }
                            else{
                                $des = lang('model/collect/not_need_update');
                            }
                        }

                    }
                }
            }
            if($show==1) {
                mac_echo( ($k + 1) . $v['actor_name'] . "<font color=$color>" .$des .'</font>'. $msg . '');
            }
            else{
                return ['code'=>($color=='red' ? 1001 : 1),'msg'=> $v['actor_name'] .' '.$des ];
            }
        }

        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_actor';
        if(ENTRANCE=='api'){
            Cache::rm($key);
            if ($data['page']['page'] < $data['page']['pagecount']) {
                $param['page'] = intval($data['page']['page']) + 1;
                $res = $this->actor($param);
                if($res['code']>1){
                    return $this->error($res['msg']);
                }
                $this->actor_data($param,$res );
            }
            mac_echo(lang('model/collect/is_over'));
            die;
        }

        if(empty($GLOBALS['config']['app']['collect_timespan'])){
            $GLOBALS['config']['app']['collect_timespan'] = 3;
        }

        if($show==1) {
            if ($param['ac'] == 'cjsel') {
                Cache::rm($key);
                mac_echo(lang('model/collect/is_over'));
                unset($param['ids']);
                $param['ac'] = 'list';
                $url = url('api') . '?' . http_build_query($param);
                $ref = $_SERVER["HTTP_REFERER"];
                if(!empty($ref)){
                    $url = $ref;
                }
                mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
            } else {
                if ($data['page']['page'] >= $data['page']['pagecount']) {
                    Cache::rm($key);
                    mac_echo(lang('model/collect/is_over'));
                    unset($param['page']);
                    $param['ac'] = 'list';
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                } else {
                    $param['page'] = intval($data['page']['page']) + 1;
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                }
            }
        }
    }

    public function role_json($param)
    {
        $url_param = [];
        $url_param['ac'] = $param['ac'];
        $url_param['t'] = $param['t'];
        $url_param['pg'] = is_numeric($param['page']) ? $param['page'] : '';
        $url_param['h'] = $param['h'];
        $url_param['ids'] = $param['ids'];
        $url_param['wd'] = $param['wd'];

        if($param['ac']!='list'){
            $url_param['ac'] = 'detail';
        }

        $url = $param['cjurl'];
        if(strpos($url,'?')===false){
            $url .='?';
        }
        else{
            $url .='&';
        }
        $url .= http_build_query($url_param).base64_decode($param['param']);
        $result = $this->checkCjUrl($url);
        if ($result['code'] > 1) {
            return $result;
        }
        // 安全：SSRF 场景禁跟随重定向，避免校验过的公网地址 302 到内网
        $html = mac_curl_get($url, [], '', true);
        if(empty($html)){
            return ['code'=>1001, 'msg'=>lang('model/collect/get_html_err') . ', url: ' . $url];
        }
        $html = mac_filter_tags($html);
        $json = $this->decodeCollectJson($html);
        if(!$json){
            return ['code'=>1002, 'msg'=>lang('model/collect/json_err') . ': ' . mb_substr($html, 0, 15)];
        }

        $array_page = [];
        $array_page['page'] = $json['page'];
        $array_page['pagecount'] = $json['pagecount'];
        $array_page['pagesize'] = $this->getRemotePageSize($json['limit'] ?? 0);
        $array_page['recordcount'] = $json['total'];
        $array_page['url'] = $url;

        $key = 0;
        $array_data = [];
        $__max_rows = $this->maxRemoteRows(); $__row_i = 0;
        foreach($json['list'] as $key=>$v){
            if ($__row_i++ >= $__max_rows) { break; }
            $array_data[$key] = $v;
        }


        $res = ['code'=>1, 'msg'=>'ok', 'page'=>$array_page, 'data'=>$array_data ];
        return $res;
    }

    public function role_data($param,$data,$show=1)
    {
        if($show==1) {
            mac_echo('[' . __FUNCTION__ . '] ' . lang('model/collect/data_tip1',[$data['page']['page'],$data['page']['pagecount'],$data['page']['url']]));
        }

        $config = config('maccms.collect');
        $config = $config['role'];
        $config_sync_pic = $param['sync_pic_opt'] > 0 ? $param['sync_pic_opt'] : $config['pic'];

        $filter_arr = explode(',',$config['filter']); $filter_arr = array_filter($filter_arr);
        $pse_rnd = explode('#',$config['words']); $pse_rnd = array_filter($pse_rnd);
        $pse_syn = mac_txt_explain($config['thesaurus'] ?? '', true);

        foreach($data['data'] as $k=>$v){

            $color='red';
            $des='';
            $msg='';
            $tmp='';

            if(empty($v['role_name']) || empty($v['role_actor']) || empty($v['vod_name']) ) {
                $des = lang('model/collect/role_data_require');
            }
            elseif( mac_array_filter($filter_arr,$v['role_name']) !==false) {
                $des = lang('model/collect/name_in_filter_err');
            }
            else {
                unset($v['role_id']);

                $v = $this->cleanCollectRow($v);

                $v['role_en'] = Pinyin::get($v['role_name']);
                $v['role_letter'] = strtoupper(substr($v['role_en'],0,1));
                $v['role_time_add'] = time();
                $v['role_time'] = time();
                $v['role_status'] = intval($config['status']);
                $v['role_lock'] = intval($v['role_lock'] ?? 0);
                if(!empty($v['role_status'])) {
                    $v['role_status'] = intval($v['role_status'] ?? 0);
                }
                $v['role_level'] = intval($v['role_level'] ?? 0);
                $v['role_hits'] = intval($v['role_hits'] ?? 0);
                $v['role_hits_day'] = intval($v['role_hits_day'] ?? 0);
                $v['role_hits_week'] = intval($v['role_hits_week'] ?? 0);
                $v['role_hits_month'] = intval($v['role_hits_month'] ?? 0);

                $v['role_up'] = intval($v['role_up'] ?? 0);
                $v['role_down'] = intval($v['role_down'] ?? 0);

                $v['role_score'] = floatval($v['role_score'] ?? 0);
                $v['role_score_all'] = intval($v['role_score_all'] ?? 0);
                $v['role_score_num'] = intval($v['role_score_num'] ?? 0);

                if($config['hits_start']>0 && $config['hits_end']>0) {
                    $v['role_hits'] = rand($config['hits_start'], $config['hits_end']);
                    $v['role_hits_day'] = rand($config['hits_start'], $config['hits_end']);
                    $v['role_hits_week'] = rand($config['hits_start'], $config['hits_end']);
                    $v['role_hits_month'] = rand($config['hits_start'], $config['hits_end']);
                }

                if($config['updown_start']>0 && $config['updown_end']){
                    $v['role_up'] = rand($config['updown_start'], $config['updown_end']);
                    $v['role_down'] = rand($config['updown_start'], $config['updown_end']);
                }

                if($config['score']==1) {
                    $v['role_score_num'] = rand(1, 1000);
                    $v['role_score_all'] = $v['role_score_num'] * rand(1, 10);
                    $v['role_score'] = round($v['role_score_all'] / $v['role_score_num'], 1);
                }

                if ($config['psernd'] == 1) {
                    $v['role_content'] = mac_rep_pse_rnd($pse_rnd, $v['role_content']);
                }
                if ($config['psesyn'] == 1) {
                    $v['role_content'] = mac_rep_pse_syn($pse_syn, $v['role_content']);
                }

                $where = [];
                $where['role_name'] = $v['role_name'];
                $where['role_actor'] = $v['role_actor'];

                $where2 = [];
                $blend = false;

                if(!empty($v['douban_id'])){
                    $where2['vod_douban_id'] = ['eq',$v['douban_id']];
                    unset($v['douban_id']);
                }
                else{
                    $where2['vod_name'] = ['eq',$v['vod_name']];
                }

                if (strpos($config['inrule'], 'c')!==false) {
                    $where2['vod_actor'] = ['like', mac_like_arr($v['role_actor']), 'OR'];
                }
                if (strpos($config['inrule'], 'd')!==false) {
                    $where2['vod_director'] = ['like', mac_like_arr($v['role_actor']), 'OR'];
                }
                if(!empty($where2['vod_actor']) && !empty($where2['vod_director'])){
                    $blend = true;
                    $GLOBALS['blend'] = [
                        'vod_actor' => $where2['vod_actor'],
                        'vod_director' => $where2['vod_director']
                    ];
                    unset($where2['vod_actor'],$where2['vod_director']);
                }

                if($blend===false){
                    $vod_info = model('Vod')->where($where2)->find();

                }
                else{
                    $vod_info = model('Vod')->where($where2)
                        ->where(function($query){
                            $query->where('vod_director',$GLOBALS['blend']['vod_director'])
                                ->whereOr('vod_actor',$GLOBALS['blend']['vod_actor']);
                        })
                        ->find();
                }

                if (!$vod_info) {
                    $des = lang('model/collect/not_found_rel_vod');
                }
                else {
                    $v['role_rid'] = $vod_info['vod_id'];
                    $where['role_rid'] = $vod_info['vod_id'];
                    $info = model('Role')->where($where)->find();
                    if (!$info) {
                        $tmp = $this->syncImages($config_sync_pic,  $v['role_pic'], 'role');
                        $v['role_pic'] = $tmp['pic'];
                        $msg = $tmp['msg'];
                        $res = model('Role')->insert($v);
                        if ($res === false) {

                        }
                        $color = 'green';
                        $des = lang('model/collect/add_ok');
                    } else {

                        if(empty($config['uprule'])){
                            $des = lang('model/collect/uprule_empty');
                        }
                        elseif ($info['role_lock'] == 1) {
                            $des = lang('model/collect/data_lock');
                        }
                        else {
                            unset($v['role_time_add']);
                            $rc = true;
                            if ($rc) {
                                $update = [];

                                if (strpos(',' . $config['uprule'], 'a') !== false && !empty($v['role_content']) && $v['role_content'] != $info['role_content']) {
                                    $update['role_content'] = $v['role_content'];
                                }
                                if (strpos(',' . $config['uprule'], 'b') !== false && !empty($v['role_remarks']) && $v['role_remarks'] != $info['role_remarks']) {
                                    $update['role_remarks'] = $v['role_remarks'];
                                }
                                if (strpos(',' . $config['uprule'], 'c') !== false && (substr($info["role_pic"], 0, 4) == "http" || empty($info['role_pic'])) && $v['role_pic'] != $info['role_pic']) {
                                    $tmp = $this->syncImages($config_sync_pic,  $v['role_pic'], 'role');
                                    $update['role_pic'] = $tmp['pic'];
                                    $msg = $tmp['msg'];
                                }

                                if(count($update)>0){
                                    $update['role_time'] = time();
                                    $where = [];
                                    $where['role_id'] = $info['role_id'];
                                    $res = model('Role')->where($where)->update($update);
                                    $color = 'green';
                                    if ($res === false) {

                                    }
                                }
                                else{
                                    $des = lang('model/collect/not_need_update');
                                }
                            }

                        }
                    }
                }

            }
            if($show==1) {
                mac_echo( ($k + 1) . $v['role_name'] . "<font color=$color>" .$des .'</font>'. $msg . '');
            }
            else{
                return ['code'=>($color=='red' ? 1001 : 1),'msg'=> $v['role_name'] .' '.$des ];
            }
        }

        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_role';
        if(ENTRANCE=='api'){
            Cache::rm($key);
            if ($data['page']['page'] < $data['page']['pagecount']) {
                $param['page'] = intval($data['page']['page']) + 1;
                $res = $this->role($param);
                if($res['code']>1){
                    return $this->error($res['msg']);
                }
                $this->role_data($param,$res );
            }
            mac_echo(lang('model/collect/is_over'));
            die;
        }

        if(empty($GLOBALS['config']['app']['collect_timespan'])){
            $GLOBALS['config']['app']['collect_timespan'] = 3;
        }

        if($show==1) {
            if ($param['ac'] == 'cjsel') {
                Cache::rm($key);
                mac_echo(lang('model/collect/is_over'));
                unset($param['ids']);
                $param['ac'] = 'list';
                $url = url('api') . '?' . http_build_query($param);
                $ref = $_SERVER["HTTP_REFERER"];
                if(!empty($ref)){
                    $url = $ref;
                }
                mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
            } else {
                if ($data['page']['page'] >= $data['page']['pagecount']) {
                    Cache::rm($key);
                    mac_echo(lang('model/collect/is_over'));
                    unset($param['page']);
                    $param['ac'] = 'list';
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                } else {
                    $param['page'] = intval($data['page']['page']) + 1;
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                }
            }
        }
    }

    public function website_json($param)
    {
        $url_param = [];
        $url_param['ac'] = $param['ac'];
        $url_param['t'] = $param['t'];
        $url_param['pg'] = is_numeric($param['page']) ? $param['page'] : '';
        $url_param['h'] = $param['h'];
        $url_param['ids'] = $param['ids'];
        $url_param['wd'] = $param['wd'];

        if($param['ac']!='list'){
            $url_param['ac'] = 'detail';
        }

        $url = $param['cjurl'];
        if(strpos($url,'?')===false){
            $url .='?';
        }
        else{
            $url .='&';
        }
        $url .= http_build_query($url_param).base64_decode($param['param']);
        $result = $this->checkCjUrl($url);
        if ($result['code'] > 1) {
            return $result;
        }
        // 安全：SSRF 场景禁跟随重定向，避免校验过的公网地址 302 到内网
        $html = mac_curl_get($url, [], '', true);
        if(empty($html)){
            return ['code'=>1001, 'msg'=>lang('model/collect/get_html_err') . ', url: ' . $url];
        }
        $html = mac_filter_tags($html);
        $json = $this->decodeCollectJson($html);
        if(!$json){
            return ['code'=>1002, 'msg'=>lang('model/collect/json_err') . ': ' . mb_substr($html, 0, 15)];
        }

        $array_page = [];
        $array_page['page'] = $json['page'];
        $array_page['pagecount'] = $json['pagecount'];
        $array_page['pagesize'] = $this->getRemotePageSize($json['limit'] ?? 0);
        $array_page['recordcount'] = $json['total'];
        $array_page['url'] = $url;

        $type_list = model('Type')->getCache('type_list');
        $bind_list = config('bind');

        $key = 0;
        $array_data = [];
        $__max_rows = $this->maxRemoteRows(); $__row_i = 0;
        foreach($json['list'] as $key=>$v){
            if ($__row_i++ >= $__max_rows) { break; }
            $array_data[$key] = $v;
            $bind_key = $param['cjflag'] .'_'.$v['type_id'];
            $local_type_id = intval(is_array($bind_list) ? ($bind_list[$bind_key] ?? 0) : 0);
            if($local_type_id >0){
                $array_data[$key]['type_id'] = $local_type_id;
            }
            else{
                $array_data[$key]['type_id'] = 0;
            }
        }

        $array_type = [];
        $key=0;
        // 分类列表
        if($param['ac'] == 'list'){
            foreach($json['class'] as $k=>$v){
                $array_type[$key]['type_id'] = $v['type_id'];
                $array_type[$key]['type_name'] = $v['type_name'];
                $key++;
            }
        }

        $res = ['code'=>1, 'msg'=>'ok', 'page'=>$array_page, 'type'=>$array_type, 'data'=>$array_data ];
        return $res;
    }

    public function website_data($param,$data,$show=1)
    {
        if($show==1) {
            mac_echo('[' . __FUNCTION__ . '] ' . lang('model/collect/data_tip1',[$data['page']['page'],$data['page']['pagecount'],$data['page']['url']]));
        }

        $config = config('maccms.collect');
        $config = $config['website'];
        $config_sync_pic = $param['sync_pic_opt'] > 0 ? $param['sync_pic_opt'] : $config['pic'];

        $type_list = model('Type')->getCache('type_list');
        $filter_arr = explode(',',$config['filter']); $filter_arr = array_filter($filter_arr);
        $pse_rnd = explode('#',$config['words']); $pse_rnd = array_filter($pse_rnd);
        $pse_syn = mac_txt_explain($config['thesaurus'] ?? '', true);

        foreach($data['data'] as $k=>$v){

            $color='red';
            $des='';
            $msg='';
            $tmp='';

            if($v['type_id'] ==0){
                $des = lang('model/collect/type_err');
            }
            elseif(empty($v['website_name'])) {
                $des = lang('model/collect/name_err');
            }
            elseif( mac_array_filter($filter_arr,$v['website_name'])!==false) {
                $des = lang('model/collect/name_in_filter_err');
            }
            else {
                unset($v['website_id']);

                $v = $this->cleanCollectRow($v);
                $v['website_name'] = trim($v['website_name']);
                $v['type_id_1'] = intval(($type_list[$v['type_id']] ?? [])['type_pid'] ?? 0);
                $v['website_en'] = Pinyin::get($v['website_name']);
                $v['website_letter'] = strtoupper(substr($v['website_en'],0,1));
                $v['website_time_add'] = time();
                $v['website_time'] = time();
                $v['website_status'] = intval($config['status']);
                $v['website_lock'] = intval($v['website_lock'] ?? 0);
                if(!empty($v['website_status'])) {
                    $v['website_status'] = intval($v['website_status'] ?? 0);
                }
                $v['website_level'] = intval($v['website_level'] ?? 0);
                $v['website_hits'] = intval($v['website_hits'] ?? 0);
                $v['website_hits_day'] = intval($v['website_hits_day'] ?? 0);
                $v['website_hits_week'] = intval($v['website_hits_week'] ?? 0);
                $v['website_hits_month'] = intval($v['website_hits_month'] ?? 0);

                $v['website_up'] = intval($v['website_up'] ?? 0);
                $v['website_down'] = intval($v['website_down'] ?? 0);

                $v['website_score'] = floatval($v['website_score'] ?? 0);
                $v['website_score_all'] = intval($v['website_score_all'] ?? 0);
                $v['website_score_num'] = intval($v['website_score_num'] ?? 0);

                if($config['hits_start']>0 && $config['hits_end']>0) {
                    $v['website_hits'] = rand($config['hits_start'], $config['hits_end']);
                    $v['website_hits_day'] = rand($config['hits_start'], $config['hits_end']);
                    $v['website_hits_week'] = rand($config['hits_start'], $config['hits_end']);
                    $v['website_hits_month'] = rand($config['hits_start'], $config['hits_end']);
                }

                if($config['updown_start']>0 && $config['updown_end']){
                    $v['website_up'] = rand($config['updown_start'], $config['updown_end']);
                    $v['website_down'] = rand($config['updown_start'], $config['updown_end']);
                }

                if($config['score']==1) {
                    $v['website_score_num'] = rand(1, 1000);
                    $v['website_score_all'] = $v['website_score_num'] * rand(1, 10);
                    $v['website_score'] = round($v['website_score_all'] / $v['website_score_num'], 1);
                }

                if ($config['psernd'] == 1) {
                    $v['website_content'] = mac_rep_pse_rnd($pse_rnd, $v['website_content']);
                }
                if ($config['psesyn'] == 1) {
                    $v['website_content'] = mac_rep_pse_syn($pse_syn, $v['website_content']);
                }

                if(empty($v['website_blurb'])){
                    $v['website_blurb'] = mac_substring( strip_tags($v['website_content']) ,100);
                }

                $where = [];
                $where['website_name'] = $v['website_name'];

                if (strpos($config['inrule'], 'b')!==false) {
                    $where['type_id'] = $v['type_id'];
                }
                // 采集网址入库重复规则建议增加跳转url
                // https://github.com/magicblack/maccms10/issues/1071
                if (strpos($config['inrule'], 'c')!==false) {
                    $where['website_jumpurl'] = $v['website_jumpurl'];
                }

                $info = model('Website')->where($where)->find();
                if (!$info) {
                    $tmp = $this->syncImages($config_sync_pic, $v['website_pic'],'website');
                    $v['website_pic'] = $tmp['pic'];
                    $msg = $tmp['msg'];
                    $res = model('Website')->insert($v);
                    if($res===false){

                    }
                    $color ='green';
                    $des= lang('model/collect/add_ok');
                } else {

                    if(empty($config['uprule'])){
                        $des = lang('model/collect/uprule_empty');
                    }
                    elseif ($info['website_lock'] == 1) {
                        $des = lang('model/collect/data_lock');
                    }
                    else {
                        unset($v['website_time_add']);
                        $rc=true;
                        if($rc){
                            $update=[];

                            if(strpos(','.$config['uprule'],'a')!==false && !empty($v['website_content']) && $v['website_content']!=$info['website_content']){
                                $update['website_content'] = $v['website_content'];
                            }
                            if(strpos(','.$config['uprule'],'b')!==false && !empty($v['website_blurb']) && $v['website_blurb']!=$info['website_blurb']){
                                $update['website_blurb'] = $v['website_blurb'];
                            }
                            if(strpos(','.$config['uprule'],'c')!==false && !empty($v['website_remarks']) && $v['website_remarks']!=$info['website_remarks']){
                                $update['website_remarks'] = $v['website_remarks'];
                            }
                            if(strpos(','.$config['uprule'],'d')!==false && !empty($v['website_jumpurl']) && $v['website_jumpurl']!=$info['website_jumpurl']){
                                $update['website_jumpurl'] = $v['website_jumpurl'];
                            }
                            if(strpos(','.$config['uprule'],'e')!==false && (substr($info["website_pic"], 0, 4) == "http" ||empty($info['website_pic']) ) && $v['website_pic']!=$info['website_pic'] ){
                                $tmp = $this->syncImages($config_sync_pic, $v['website_pic'],'website');
                                $update['website_pic'] =$tmp['pic'];
                                $msg =$tmp['msg'];
                            }

                            if(count($update)>0){
                                $update['website_time'] = time();
                                $where = [];
                                $where['website_id'] = $info['website_id'];
                                $res = model('Website')->where($where)->update($update);
                                $color = 'green';
                                if($res===false){

                                }
                            }
                            else{
                                $des = lang('model/collect/not_need_update');
                            }
                        }

                    }
                }
            }
            if($show==1) {
                mac_echo( ($k + 1) . $v['website_name'] . "<font color=$color>" .$des .'</font>'. $msg . '');
            }
            else{
                return ['code'=>($color=='red' ? 1001 : 1),'msg'=> $v['website_name'] .' '.$des ];
            }
        }

        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_website';
        if(ENTRANCE=='api'){
            Cache::rm($key);
            if ($data['page']['page'] < $data['page']['pagecount']) {
                $param['page'] = intval($data['page']['page']) + 1;
                $res = $this->actor($param);
                if($res['code']>1){
                    return $this->error($res['msg']);
                }
                $this->website_data($param,$res );
            }
            mac_echo(lang('model/collect/is_over'));
            die;
        }

        if(empty($GLOBALS['config']['app']['collect_timespan'])){
            $GLOBALS['config']['app']['collect_timespan'] = 3;
        }

        if($show==1) {
            if ($param['ac'] == 'cjsel') {
                Cache::rm($key);
                mac_echo(lang('model/collect/is_over'));
                unset($param['ids']);
                $param['ac'] = 'list';
                $url = url('api') . '?' . http_build_query($param);
                $ref = $_SERVER["HTTP_REFERER"];
                if(!empty($ref)){
                    $url = $ref;
                }
                mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
            } else {
                if ($data['page']['page'] >= $data['page']['pagecount']) {
                    Cache::rm($key);
                    mac_echo(lang('model/collect/is_over'));
                    unset($param['page']);
                    $param['ac'] = 'list';
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                } else {
                    $param['page'] = intval($data['page']['page']) + 1;
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                }
            }
        }
    }

    public function comment_json($param)
    {
        $url_param = [];
        $url_param['ac'] = $param['ac'];
        $url_param['t'] = $param['t'];
        $url_param['pg'] = is_numeric($param['page']) ? $param['page'] : '';
        $url_param['h'] = $param['h'];
        $url_param['ids'] = $param['ids'];
        $url_param['wd'] = $param['wd'];

        if($param['ac']!='list'){
            $url_param['ac'] = 'detail';
        }

        $url = $param['cjurl'];
        if(strpos($url,'?')===false){
            $url .='?';
        }
        else{
            $url .='&';
        }
        $url .= http_build_query($url_param).base64_decode($param['param']);
        $result = $this->checkCjUrl($url);
        if ($result['code'] > 1) {
            return $result;
        }
        // 安全：SSRF 场景禁跟随重定向，避免校验过的公网地址 302 到内网
        $html = mac_curl_get($url, [], '', true);
        if(empty($html)){
            return ['code'=>1001, 'msg'=>lang('model/collect/get_html_err') . ', url: ' . $url];
        }
        $html = mac_filter_tags($html);
        $json = $this->decodeCollectJson($html);
        if(!$json){
            return ['code'=>1002, 'msg'=>lang('model/collect/json_err') . ': ' . mb_substr($html, 0, 15)];
        }

        $array_page = [];
        $array_page['page'] = $json['page'];
        $array_page['pagecount'] = $json['pagecount'];
        $array_page['pagesize'] = $this->getRemotePageSize($json['limit'] ?? 0);
        $array_page['recordcount'] = $json['total'];
        $array_page['url'] = $url;

        $key = 0;
        $array_data = [];
        $__max_rows = $this->maxRemoteRows(); $__row_i = 0;
        foreach($json['list'] as $key=>$v){
            if ($__row_i++ >= $__max_rows) { break; }
            $array_data[$key] = $v;
        }


        $res = ['code'=>1, 'msg'=>'ok', 'page'=>$array_page, 'data'=>$array_data ];
        return $res;
    }

    public function comment_data($param,$data,$show=1)
    {
        if($show==1) {
            mac_echo('[' . __FUNCTION__ . '] ' . lang('model/collect/data_tip1',[$data['page']['page'],$data['page']['pagecount'],$data['page']['url']]));
        }

        $config = config('maccms.collect');
        $config = $config['comment'];
        $config_sync_pic = $param['sync_pic_opt'] > 0 ? $param['sync_pic_opt'] : $config['pic'];

        $filter_arr = explode(',',$config['filter']); $filter_arr = array_filter($filter_arr);
        $pse_rnd = explode('#',$config['words']); $pse_rnd = array_filter($pse_rnd);
        $pse_syn = mac_txt_explain($config['thesaurus'] ?? '', true);

        foreach($data['data'] as $k=>$v){

            $color='red';
            $des='';
            $msg='';
            $tmp='';

            if(empty($v['comment_name']) || empty($v['comment_content']) || empty($v['rel_name']) ) {
                $des = lang('model/collect/comment_data_require');
            }
            elseif( mac_array_filter($filter_arr,$v['comment_content']) !==false) {
                $des = lang('model/collect/name_in_filter_err');
            }
            else {
                unset($v['comment_id']);

                $v = $this->cleanCollectRow($v);

                $v['comment_time'] = time();
                $v['comment_status'] = intval($config['status']);
                $v['comment_up'] = intval($v['comment_up'] ?? 0);
                $v['comment_down'] = intval($v['comment_down'] ?? 0);
                $v['comment_mid'] = intval($v['comment_mid'] ?? 0);
                if(!empty($v['comment_ip']) && !is_numeric($v['comment_ip'])){
                    $v['comment_ip'] = mac_get_ip_long($v['comment_ip']);
                }

                if($config['updown_start']>0 && $config['updown_end']){
                    $v['comment_up'] = rand($config['updown_start'], $config['updown_end']);
                    $v['comment_down'] = rand($config['updown_start'], $config['updown_end']);
                }
                if ($config['psernd'] == 1) {
                    $v['comment_content'] = mac_rep_pse_rnd($pse_rnd, $v['comment_content']);
                }
                if ($config['psesyn'] == 1) {
                    $v['comment_content'] = mac_rep_pse_syn($pse_syn, $v['comment_content']);
                }

                $where = [];
                $where2 = [];
                $blend = false;

                if (strpos($config['inrule'], 'b')!==false) {
                    $where['comment_content'] = ['eq', $v['comment_content']];
                }
                if (strpos($config['inrule'], 'c')!==false) {
                    $where['comment_name'] = ['eq', $v['comment_name']];
                }

                if(empty($v['rel_id'])){
                    if($v['comment_mid']==1){
                        if(!empty($v['douban_id'])){
                            $where2['vod_douban_id'] = ['eq',$v['douban_id']];
                            unset($v['douban_id']);
                        }
                        else{
                            $where2['vod_name'] = ['eq',$v['rel_name']];
                        }
                        $rel_info = model('Vod')->where($where2)->find();
                    }
                    elseif($v['comment_mid']==2){
                        $where2['art_name'] = ['eq',$v['rel_name']];
                        $rel_info = model('Art')->where($where2)->find();
                    }
                    elseif($v['comment_mid']==3){
                        $where2['topic_name'] = ['eq',$v['rel_name']];
                        $rel_info = model('Topic')->where($where2)->find();
                    }
                    elseif($v['comment_mid']==8){
                        $where2['actor_name'] = ['eq',$v['rel_name']];
                        $rel_info = model('Actor')->where($where2)->find();
                    }
                    elseif($v['comment_mid']==9){
                        $where2['role_name'] = ['eq',$v['rel_name']];
                        $rel_info = model('Role')->where($where2)->find();
                    }
                    elseif($v['comment_mid']==11){
                        $where2['website_name'] = ['eq',$v['rel_name']];
                        $rel_info = model('Website')->where($where2)->find();
                    }

                    $rel_id = $rel_info[mac_get_mid_code($v['comment_mid']).'_id'];
                }
                else{
                    $rel_id = $v['rel_id'];
                }

                if(empty($rel_id)){
                    $des = lang('model/collect/not_found_rel_data');
                }
                else {

                    $v['comment_rid'] = $rel_id;
                    $info=false;

                    if(!empty($where)) {
                        $where['comment_rid'] = $rel_id;
                        $info = model('Comment')->where($where)->find();
                    }
                    if (!$info) {
                        $msg = isset($tmp['msg']) ? $tmp['msg'] : '';
                        $res = model('Comment')->insert($v);
                        if ($res === false) {

                        }
                        $color = 'green';
                        $des = lang('model/collect/add_ok');
                    } else {

                        if(empty($config['uprule'])){
                            $des = lang('model/collect/uprule_empty');
                        }
                        else {
                            $rc = true;
                            if ($rc) {
                                $update = [];

                                if (strpos(',' . $config['uprule'], 'a') !== false && !empty($v['comment_time']) && $v['comment_time'] != $info['comment_time']) {
                                    $update['comment_time'] = $v['comment_time'];
                                }

                                if(count($update)>0){
                                    $update['comment_time'] = time();
                                    $where = [];
                                    $where['comment_id'] = $info['comment_id'];
                                    $res = model('Comment')->where($where)->update($update);
                                    $color = 'green';
                                    if ($res === false) {

                                    }
                                }
                                else{
                                    $des = lang('model/collect/not_need_update');
                                }
                            }

                        }
                    }
                }

            }
            if($show==1) {
                mac_echo( ($k + 1) . $v['comment_content'] . "<font color=$color>" .$des .'</font>'. $msg . '');
            }
            else{
                return ['code'=>($color=='red' ? 1001 : 1),'msg'=> $v['comment_content'] .' '.$des ];
            }
        }

        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_comment';
        if(ENTRANCE=='api'){
            Cache::rm($key);
            if ($data['page']['page'] < $data['page']['pagecount']) {
                $param['page'] = intval($data['page']['page']) + 1;
                $res = $this->role($param);
                if($res['code']>1){
                    return $this->error($res['msg']);
                }
                $this->actor_data($param,$res );
            }
            mac_echo(lang('model/collect/is_over'));
            die;
        }

        if(empty($GLOBALS['config']['app']['collect_timespan'])){
            $GLOBALS['config']['app']['collect_timespan'] = 3;
        }

        if($show==1) {
            if ($param['ac'] == 'cjsel') {
                Cache::rm($key);
                mac_echo(lang('model/collect/is_over'));
                unset($param['ids']);
                $param['ac'] = 'list';
                $url = url('api') . '?' . http_build_query($param);
                $ref = $_SERVER["HTTP_REFERER"];
                if(!empty($ref)){
                    $url = $ref;
                }
                mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
            } else {
                if ($data['page']['page'] >= $data['page']['pagecount']) {
                    Cache::rm($key);
                    mac_echo(lang('model/collect/is_over'));
                    unset($param['page']);
                    $param['ac'] = 'list';
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                } else {
                    $param['page'] = intval($data['page']['page']) + 1;
                    $url = url('api') . '?' . http_build_query($param);
                    mac_jump($url, $GLOBALS['config']['app']['collect_timespan']);
                }
            }
        }
    }

    /**
     * 检查url合法性
     * https://github.com/magicblack/maccms10/issues/763
     */
    private function checkCjUrl($url)
    {
        // 安全：原实现只 parse_url 后挡字面量 127.0.0.1/localhost
        // 既不看 DNS 解析结果（多条 A 记录/DNS rebinding 可绕），也不挡其它内网段
        // 且调用方 mac_curl_get 允许跟随 302 → 一条公网 URL 即可打内网/云元数据
        // （169.254.169.254 等）。现按项目安全守则 §6 接入 mac_safe_url：
        // 协议白名单（http/https）+ 解析全部 A/AAAA 记录 + 任一为私网/保留即拒绝。
        // 兼容性：内网/本机采集源属真实场景，故保留显式白名单 app.collect_allow_hosts
        // （逗号分隔，支持主机名与 IP/CIDR，如 "api.intra.corp,10.0.0.0/8"）。
        // 白名单**只**用于放行私网地址；公网 URL 一律走 mac_safe_url 校验，不让白名单变成旁路。
        $parts = @parse_url((string)$url);
        $host = isset($parts['host']) ? strtolower(trim($parts['host'], '[]')) : '';

        if (!mac_is_http_url($url)) {
            return ['code' => 1001, 'msg' => lang('model/collect/cjurl_err') . ': ' . $url];
        }

        if ($host !== '' && $this->isCollectHostAllowed($host)) {
            return ['code' => 1];
        }

        if (empty($host) || !mac_safe_url($url)) {
            return ['code' => 1001, 'msg' => lang('model/collect/cjurl_err') . ': ' . $url];
        }
        return ['code' => 1];
    }

    /**
     * 采集源主机是否显式白名单（app.collect_allow_hosts）。
     * 支持主机名精确/后缀匹配与 IPv4 精确/CIDR 匹配；仅在 mac_safe_url 拒绝时作为放行依据。
     */
    private function isCollectHostAllowed($host)
    {
        $list = isset($GLOBALS['config']['app']['collect_allow_hosts'])
            ? (string) $GLOBALS['config']['app']['collect_allow_hosts'] : '';
        $list = trim($list);
        if ($list === '' || $host === '') {
            return false;
        }
        foreach (explode(',', $list) as $item) {
            $item = strtolower(trim($item));
            if ($item === '') {
                continue;
            }
            // IP / CIDR
            if (filter_var($item, FILTER_VALIDATE_IP) || strpos($item, '/') !== false) {
                if (mac_ip_in_list($host, $item)) {
                    return true;
                }
                continue;
            }
            // 主机名：精确匹配，或 *.example.com 形式后缀匹配
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

    /**
     * 统一裁剪远端返回的分页大小（XML 的 pagesize 属性 / JSON 的 limit 字段）。
     * 远端可以把该值放大到任意数字，进而放大单次采集请求的循环次数与数据量
     * 这里强制转 int 并夹取到 1..200，非法或小于 1 时回落到默认值。
     *
     * @param mixed $v 远端返回值
     * @param int $default 非法（非数字或小于 1）时使用的默认值
     * @return int 取值区间 1..200
     */
    /**
     * 单次请求从远端接受的最大记录数（安全）。
     * pagesize 只影响分页大小，真正决定单请求写库量的是远端返回的 list/video 数组本身
     * 异常或恶意上游返回数万行会让单请求内存与写库量失控。上限可由 app.collect_max_rows 调整（默认 200）。
     */
    private function maxRemoteRows()
    {
        $max = isset($GLOBALS['config']['app']['collect_max_rows']) ? intval($GLOBALS['config']['app']['collect_max_rows']) : 0;
        return $max > 0 ? $max : 200;
    }

    private function getRemotePageSize($v, $default = 20)
    {
        $default = intval($default);
        if($default < 1){
            $default = 20;
        }
        $size = is_numeric($v) ? intval($v) : 0;
        if($size < 1){
            $size = $default;
        }
        if($size > 200){
            $size = 200;
        }
        return $size;
    }
}
