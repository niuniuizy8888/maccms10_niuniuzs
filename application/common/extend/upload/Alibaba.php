<?php
namespace app\common\extend\upload;

class Alibaba
{
    public $name = '阿里巴巴云存储';
    public $ver = '1.0';
    private $config = [];

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function submit($file_path)
    {
        $filePath = ROOT_PATH . $file_path;

        $url = 'https://kfupload.alibaba.com/kupload';
        $data = [];
        $data['scene'] = 'aeMessageCenterV2ImageRule';
        $data['name'] = 'player.jpg';
        if (class_exists('CURLFile')) {
            $data['file'] = new \CURLFile(realpath($file_path));
        } else {
            $data['file'] = '@'.realpath($file_path);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        // 安全：默认校验；白名单主机才降级。
        $_ssl_insecure = function_exists('mac_curl_insecure_host') && mac_curl_insecure_host($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $_ssl_insecure ? false : true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $_ssl_insecure ? false : 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $httpheader[] = "Accept:application/json";
        $httpheader[] = "Accept-Encoding:gzip,deflate,sdch";
        $httpheader[] = "Accept-Language:zh-CN,zh;q=0.8";
        $httpheader[] = "Connection:close";
        $ip = mt_rand(48, 140) . "." . mt_rand(10, 240) . "." . mt_rand(10, 240) . "." . mt_rand(10, 240); //随机 ip
        $httpheader[] = 'CLIENT-IP:' . $ip;
        $httpheader[] = 'X-FORWARDED-FOR:' . $ip;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Dalvik/2.1.0 (Linux; U; Android 10; ONEPLUS A5010 Build/QKQ1.191014.012)');
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $html = @curl_exec($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); } // PHP 8.5 起 curl_close() 弃用（PHP 8 起为空操作）
        $json = @json_decode($html,true);

        if($json['code']=='0'){
            $file_path = $json['url'];
            empty($this->config['keep_local']) && @unlink($filePath);
        }

        return $file_path;
    }
}
