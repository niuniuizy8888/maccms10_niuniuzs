<?php
namespace app\common\extend\pay;

class Weixin {

    public $name = '微信支付';
    public $ver = '1.0';

    public function submit($user,$order,$param)
    {
        $total_fee = $order['order_price'];
        $data = array();
        $data['appid'] =  trim($GLOBALS['config']['pay']['weixin']['appid']);//公众号
        $data['mch_id'] =  trim($GLOBALS['config']['pay']['weixin']['mchid']);//商户号
        $data['nonce_str'] =  mac_get_rndstr();//随机字符串
        $data['body'] =  '积分充值（UID：'.$user['user_id'].'）';//商品描述
        $data['fee_type'] =  'CNY';//标价币种
        $data['out_trade_no'] = $order['order_code'];//商户订单号
        $data['total_fee'] = $total_fee*100;//金额，单位分
        $data['spbill_create_ip'] =  mac_get_client_ip();//终端IP
        // 回调地址优先使用站点配置域名，避免 Host 头注入把回调指向攻击者域名
        $data['notify_url'] =  $this->getSiteUrl() . '/index.php/payment/notify/pay_type/weixin';
        $data['trade_type'] =  'NATIVE';//交易类型 JSAPI，NATIVE，APP
        $data['product_id'] = '1';//商品ID
        // $data['openid'] = '';//用户标识 trade_type=JSAPI时（即公众号支付），此参数必传
        $data['sign'] =  $this->makeSign($data);
        // 获取付款二维码
        $data_xml = mac_array2xml($data);
        $res = mac_curl_post('https://api.mch.weixin.qq.com/pay/unifiedorder', $data_xml);
        $res = mac_xml2array($res);

        if($res['return_code']=='SUCCESS' && $res['result_code']=='SUCCESS'){
            // 返回付款信息
            $res = [
                'user_id'=>$user['user_id'],
                'total_fee'=>$total_fee,
                'out_trade_no'=>$data['out_trade_no'],
                'code_url'=>$res['code_url']
            ];

            // echo '<img src=http://paysdk.weixin.qq.com/example/qrcode.php?data='.urlencode($res['code_url']).'/>'
            return $res;
        }
        // echo '获取微信二维码失败,'.$res['return_msg']
        return false;
    }

    public function notify()
    {
        $xml = file_get_contents('php://input');
        $config = config('maccms.pay');

        // 将服务器返回的XML数据转化为数组
        $data = mac_xml2array($xml);
        // 保存微信服务器返回的签名sign
        $data_sign = $data['sign'];
        // sign不参与签名算法
        unset($data['sign']);
        // 生成签名
        $sign = $this->makeSign($data);
        // 判断签名是否正确 判断支付状态（签名比对原已为 === 严格比较，保持不变）
        if ( ($sign===$data_sign) && !empty($data['out_trade_no']) && is_string($data['out_trade_no']) && ($data['return_code']=='SUCCESS') && ($data['result_code']=='SUCCESS') ) {
            // 金额复核——订单必须存在，且回调金额与订单金额一致（微信 total_fee 单位为分，容差 0.01 元）
            $order = model('Order')->infoData(['order_code' => $data['out_trade_no']]);
            $pay_money = null;
            if (isset($data['total_fee']) && $data['total_fee'] !== '') {
                // 非标量（如数组）一律视为异常金额，必然校验失败
                $pay_money = is_scalar($data['total_fee']) ? ((float)$data['total_fee'] / 100) : -1;
            }
            if ($order['code'] > 1 || ($pay_money !== null && abs($pay_money - (float)$order['info']['order_price']) >= 0.01)) {
                echo '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[order check fail]]></return_msg></xml>';
            }
            else {
                $res = model('Order')->notify($data['out_trade_no'],'weixin');
                echo '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            }
        }
        else{
            echo '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[签名失败]]></return_msg></xml>';
        }
    }

    public function makeSign($data){
        // 获取微信支付秘钥
        $key = trim($GLOBALS['config']['pay']['weixin']['appkey']);
        // 去空
        $data=array_filter($data);
        // 签名步骤一：按字典序排序参数
        ksort($data);
        $string_a=http_build_query($data);
        $string_a=urldecode($string_a);
        // 签名步骤二：在string后加入KEY
        $string_sign_temp=$string_a."&key=".$key;
        // 签名步骤三：MD5加密
        $sign = md5($string_sign_temp);
        // 签名步骤四：所有字符转为大写
        $result=strtoupper($sign);
        return $result;
    }
    // 安全：回调域名走站点配置，避免 Host 头注入
    private function getSiteUrl()
    {
        return mac_site_abs_url();
    }

}
