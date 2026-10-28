<?php
namespace app\common\service\nnzs;

/** 统一走 ThinkPHP 日志，前缀 [nnzs] */
class Log
{
    public static function info($msg)
    {
        \think\Log::record('[nnzs] ' . $msg, 'info');
    }

    public static function error($msg)
    {
        \think\Log::record('[nnzs] ' . $msg, 'error');
    }
}
