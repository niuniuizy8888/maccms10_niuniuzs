<?php
namespace app\common\extend\pay;

class Epay {

    public $name = '易支付';
    public $ver = '1.0';

    public function submit($user,$order,$param)
    {
        $pay_type = 0;
        $pay_type_map = [1 => 'alipay', 2 => 'qqpay', 3 => 'wxpay',];
        if(!empty($param['paytype']) && isset($pay_type_map[$param['paytype']])){
            $pay_type = $pay_type_map[$param['paytype']];
        }
        // 组装参数
        $epay_config = $GLOBALS['config']['pay']['epay'];
        // 回调/跳转地址优先使用站点配置域名，避免 Host 头注入把回调指向攻击者域名
        $site_url = $this->getSiteUrl();
        $data = array(
            "pid"          => trim($epay_config['appid']),//你的商户ID
            "type"         => $pay_type,//1支付宝支付 3微信支付 2QQ钱包
            "notify_url"   => $site_url . '/index.php/payment/notify/pay_type/epay',//通知地址
            "return_url"   => $site_url . '/index.php/payment/notify/pay_type/epay',//跳转地址
            "out_trade_no" => $order['order_code'], //唯一标识 可以是用户ID,用户名,session_id(),订单ID,ip 付款后返回
            "name"         => '积分充值（UID：'.$user['user_id'].'）',
            "money"        => (float)$order['order_price'],//金额100元
        );
        // 跳转至收银台，选择其他方式
        if (empty($data['type'])) {
            unset($data['type']);
        }
        ksort($data); //重新排序$data数组
        reset($data); //内部指针指向数组中的第一个元素

        $sign = ''; //初始化需要签名的字符为空
        $urls = ''; //初始化URL参数为空

        foreach ($data as $key => $val) { //遍历需要传递的参数
            if ($val == ''||$key == 'sign') continue; //跳过这些不参数签名
            if ($sign != '') { //后面追加&拼接URL
                $sign .= "&";
                $urls .= "&";
            }
            $sign .= "$key=$val"; //拼接为url参数形式
            $urls .= "$key=" . urlencode($val); //拼接为url参数形式并URL编码参数值
        }

        $query = $urls . '&sign='.md5($sign.trim( $epay_config['appkey'] )); //创建订单所需的参数
        $url = $epay_config['api_url'] . "submit.php?{$query}"; //支付页面
        mac_redirect($url);
    }

    public function notify()
    {
        $param = $_REQUEST;
        // $param['trade_no'] 这是付款人的唯一身份标识或订单ID
        // $param['out_trade_no'] 这是流水号 没有则表示没有付款成功 流水号不同则为不同订单
        // $param['money'] 这是付款金额

        unset($param['/payment/notify/pay_type/epay']);
        unset($param['paytype']);
        unset($param['s']);

        ksort($param); //排序post参数
        reset($param); //内部指针指向数组中的第一个元素
        $sign = '';
        foreach ($param as $key => $val) {
            if ($val == '') continue; //跳过空值
            if ($key != 'sign' && $key != 'sign_type') { //跳过sign
                $sign .= "$key=$val&"; //拼接为url参数形式
            }
        }

        $epay_config = $GLOBALS['config']['pay']['epay'];
        // 签名比对改用 hash_equals（原 != 为松散比较，存在 "0e..." 魔术哈希风险）
        $expected_sign = md5(substr($sign, 0, -1) . trim($epay_config['appkey']));
        if (empty($param['out_trade_no']) || !is_string($param['out_trade_no']) || !isset($param['sign']) || !is_string($param['sign']) || !hash_equals($expected_sign, $param['sign'])) {
            echo 'fail';
        }
        else{
            // 金额复核——订单必须存在，且回调金额与订单金额一致（容差 0.01）
            $order = model('Order')->infoData(['order_code' => $param['out_trade_no']]);
            $pay_money = null;
            if (isset($param['money']) && $param['money'] !== '') {
                // 非标量（如数组）一律视为异常金额，必然校验失败
                $pay_money = is_scalar($param['money']) ? $param['money'] : -1;
            }
            if ($order['code'] > 1 || ($pay_money !== null && abs((float)$pay_money - (float)$order['info']['order_price']) >= 0.01)) {
                echo 'fail';
            }
            else {
                $res = model('Order')->notify($param['out_trade_no'], 'epay');
                if($res['code'] >1){
                    echo 'fail2';
                }
                else {
                    echo 'success';
                }
            }
        }
    }
    // 安全：回调域名走站点配置，避免 Host 头注入
    private function getSiteUrl()
    {
        return mac_site_abs_url();
    }
}
