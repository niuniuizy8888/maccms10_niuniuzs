<?php
namespace app\index\controller;
use think\captcha\Captcha;
use think\Config;
use think\Controller;

class Verify extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index($id='')
    {
        ob_end_clean();
        $captcha = new Captcha((array)Config::get('captcha'));
        return $captcha->entry($id);
    }

    public function check($verify,$id='')
    {
        // 安全：本方法是公开的 1/0 判定接口，除"失败即作废验证码"外
        // 再加会话级最小间隔限制，避免被当作高频 oracle 使用。
        if (mac_get_time_span('last_verify_check') < 1) {
            return 0;
        }
        if(!mac_captcha_check($verify)){
            return 0;
        }
        else{
            return 1;
        }
    }

}
