<?php
namespace app\index\controller;
use think\Controller;
use \think\Request;

class Payment extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function notify()
    {
        $param = input();
        $pay_type = isset($param['pay_type']) ? (string)$param['pay_type'] : '';

        // 安全：pay_type 决定下面实例化的校验类，必须先白名单（原实现接受任意字符串）
        if (!in_array($pay_type, array('alipay', 'weixin', 'epay', 'codepay', 'zhapay'), true)) {
            echo lang('index/payment_not');
            exit;
        }

        if (empty($GLOBALS['config']['pay'][$pay_type]['appid'])) {
            echo lang('index/payment_status');
            exit;
        }

        $cp = 'app\\common\\extend\\pay\\' . ucfirst($pay_type);
        if (class_exists($cp)) {
            $c = new $cp;
            $c->notify();
        }
        else{
            echo lang('index/payment_not');
            exit;
        }
    }
}
