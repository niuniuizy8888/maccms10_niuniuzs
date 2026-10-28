<?php
namespace app\common\extend\sms;

class Aliyun {

    public $name = '阿里云短信';
    public $ver = '2.0';

    /**
     * 生成签名并发起请求
     *
     * @param $accessKeyId string AccessKeyId (https://ak-console.aliyun.com/)
     * @param $accessKeySecret string AccessKeySecret
     * @param $domain string API接口所在域名
     * @param $params array API具体参数
     * @param $security boolean 使用https
     * @param $method boolean 使用GET或POST方法请求，VPC仅支持POST
     * @return bool|\stdClass 返回API接口调用结果，当发生错误时返回false
     */
    public function request($accessKeyId, $accessKeySecret, $domain, $params, $security=false, $method='POST') {
        $apiParams = array_merge(array (
            "SignatureMethod" => "HMAC-SHA1",
            "SignatureNonce" => uniqid(mt_rand(0,0xffff), true),
            "SignatureVersion" => "1.0",
            "AccessKeyId" => $accessKeyId,
            "Timestamp" => gmdate("Y-m-d\TH:i:s\Z"),
            "Format" => "JSON",
        ), $params);
        ksort($apiParams);

        $sortedQueryStringTmp = "";
        foreach ($apiParams as $key => $value) {
            $sortedQueryStringTmp .= "&" . $this->encode($key) . "=" . $this->encode($value);
        }

        $stringToSign = "{$method}&%2F&" . $this->encode(substr($sortedQueryStringTmp, 1));

        $sign = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret . "&",true));

        $signature = $this->encode($sign);

        $url = ($security ? 'https' : 'http')."://{$domain}/";

        try {
            $content = $this->fetchContent($url, $method, "Signature={$signature}{$sortedQueryStringTmp}");
            return json_decode($content,true);
        } catch( \Exception $e) {
            return false;
        }
    }

    private function encode($str)
    {
        $res = urlencode($str);
        $res = preg_replace("/\+/", "%20", $res);
        $res = preg_replace("/\*/", "%2A", $res);
        $res = preg_replace("/%7E/", "~", $res);
        return $res;
    }

    private function fetchContent($url, $method, $body) {
        $ch = curl_init();

        if($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            $url .= '?'.$body;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "x-sdk-client" => "php/2.0.0"
        ));

        if(substr($url, 0,5) == 'https') {
            // 安全：默认校验；白名单主机才降级。
            $_ssl_insecure = function_exists('mac_curl_insecure_host') && mac_curl_insecure_host($url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $_ssl_insecure ? false : true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $_ssl_insecure ? false : 2);
        }

        $rtn = curl_exec($ch);

        if($rtn === false) {
            // 大多由设置等原因引起，一般无法保障后续逻辑正常执行
            // 所以这里触发的是E_USER_ERROR，会终止脚本执行，无法被try...catch捕获，需要用户排查环境、网络等故障
            trigger_error("[CURL_" . curl_errno($ch) . "]: " . curl_error($ch), E_USER_ERROR);
        }
        if (PHP_VERSION_ID < 80000) { curl_close($ch); } // PHP 8.5 起 curl_close() 弃用（PHP 8 起为空操作）

        return $rtn;
    }

    public function submit($phone,$code,$type_flag,$type_des,$text)
    {
        if(empty($phone) || empty($code) || empty($type_flag)){
            return ['code'=>101,'msg'=>'参数错误'];
        }

        $appid = $GLOBALS['config']['sms']['aliyun']['appid'];
        $appkey = $GLOBALS['config']['sms']['aliyun']['appkey'];
        $sign = $GLOBALS['config']['sms']['sign'];
        $security = false;
        $tpl = $GLOBALS['config']['sms']['tpl_code_'.$type_flag];

        $params=[];
        $params['PhoneNumbers'] = $phone;
        $params['SignName'] = $sign;
        $params['TemplateCode'] = $tpl;
        $params['TemplateParam'] = [
            'code'=>$code,
        ];

        if( is_array($params["TemplateParam"])) {
            $params["TemplateParam"] = json_encode($params["TemplateParam"], JSON_UNESCAPED_UNICODE);
        }

        try {
            $rsp = $this->request(
                $appid,
                $appkey,
                "dysmsapi.aliyuncs.com",
                array_merge($params, array(
                    "RegionId" => "cn-hangzhou",
                    "Action" => "SendSms",
                    "Version" => "2017-05-25",
                )),
                $security
            );

            if($rsp['Code'] == 'OK'){
                $rsp['result'] = 1;
            }

            if($rsp['result'] ==1){
                return ['code'=>1,'msg'=>'ok'];
            }
            return ['code'=>101,'msg'=>$rsp['Message']];
        }
        catch(\Exception $e) {
            return ['code'=>102,'msg'=>'发生异常请重试'];
        }
    }
}
