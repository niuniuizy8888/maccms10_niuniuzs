<?php
namespace app\api\controller;
use think\Controller;

class Timming extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        // 安全：入口必须先鉴权（app.cron_key 或本机回环）
        // 且鉴权失败时不得写 runtime —— 否则被拒请求会把任务标记为"本时段已执行"，造成任务静默跳过。
        if (!mac_cron_authorized()) {
            http_response_code(403);
            echo 'forbidden';
            exit;
        }
        $param = input('get.','','trim,urldecode');
        $name = $param['name'] ?? '';
        if(empty($name)){
            // return $this->error('参数错误!')
        }

        $list = config('timming');
        foreach($list as $k=>$v){
            if(!empty($name) && $v['name'] !=$name){
                continue;
            }

            $oldweek = ''; $oldhours = '';
            if(!empty($v['runtime'])) { $oldweek= date('w',$v['runtime']); $oldhours= date('H',$v['runtime']); }
            $curweek= date('w',time()) ;	$curhours= date("H",time());
            if(strlen($oldhours)==1 && intval($oldhours) <10){ $oldhours= '0'.$oldhours; }
            if(strlen($curhours)==1 && intval($curhours) <10){ $curhours= substr($curhours,1,1); }
            $last = (!empty($v['runtime']) ? date('Y-m-d H:i:s',$v['runtime']) : lang('api/never'));
            $status = $v['status'] == '1' ?  lang('open'): lang('close');

            // 测试
            // $v['runtime']=0

            if( $v['status']=='1' &&
                ( empty($v['runtime']) || ($oldweek."-".$oldhours) != ($curweek."-".$curhours) && strpos($v['weeks'],$curweek)!==false && strpos($v['hours'],$curhours)!==false  || ($param['enforce'] ?? '') =='1')
               ) {
                mac_echo( lang('api/task_tip_exec',[$v['name'] ,$status,$last]));
                $list[$k]['runtime'] = time();

                $res = mac_arr2file( APP_PATH .'extra/timming.php', $list);
                if($res===false){
                    return $this->error(lang('write_err_config'));
                }
                $this->reset();
                $file = isset($v['file']) ? $v['file'] : '';
                // 方法名白名单，阻断 $this->$file 任意方法调用（本类真实存在且允许调度的仅下列 5 个）
                if (!in_array($file, ['collect', 'make', 'cj', 'cache', 'urlsend', 'nnzs'], true)) {
                    mac_echo('[skip] invalid task file: ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8'));
                    continue;
                }
                $this->$file(isset($v['param']) ? $v['param'] : '');
                die;
            }
            else{
                mac_echo(lang('api/task_tip_jump',[$v['name'] ,$status,$last]));
            }
        }
    }

    private function reset()
    {
        foreach($_REQUEST as $k=>$v){
            $_REQUEST[$k]='';
        }
    }

    protected function collect($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/collect');
        $request->api($output);
    }

    protected function make($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/make');
        $request->make($output);
    }

    protected function cj($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/cj');
        $request->col_all($output);
    }

    /** 牛牛助手：异步采集心跳（任务 file=nnzs, param=ac=heartbeat） */
    protected function nnzs($param)
    {
        @parse_str($param, $output);
        if (($output['ac'] ?? '') === 'heartbeat') {
            $r = \app\common\service\nnzs\Rsync::heartbeat();
            mac_echo('[nnzs] heartbeat ' . ($r['ok'] ? 'ok' : 'fail: ' . htmlspecialchars($r['msg'], ENT_QUOTES, 'UTF-8')));
        }
    }

    protected function cache($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/index');
        $request->clear();
    }

    protected function urlsend($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/urlsend');
        $request->push($output);
    }
}
