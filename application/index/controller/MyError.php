<?php
namespace app\index\controller;
use think\Request;

class MyError
{
    public function _empty()
    {
        http_response_code(404);
        echo  '<script>setTimeout(function (){location.href='.json_encode(MAC_PATH, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';},'.(2000).');</script>';
        $msg = lang('page_not_found');
        abort(404,$msg);
        exit;
    }
}