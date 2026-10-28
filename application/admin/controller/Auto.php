<?php
namespace app\admin\controller;

use think\Controller;

/**
 * 旧「牛牛助手」后台地址兼容层：admin.php/auto/* → admin.php/nnzs/*（307，保留方法、参数与请求体）。
 * 登录与权限由目标控制器 Nnzs 统一校验。对外接口 api.php/auto/* 不在此处，保持原样。
 */
class Auto extends Controller
{
    const MAP = [
        'index' => 'index', 'zyk' => 'resource', 'rep' => 'replace', 'rvm' => 'promo', 'free' => 'promo',
        'crons' => 'crons', 'poster' => 'poster', 'news' => 'news', 'install' => 'install',
        'intov' => 'importv', 'intox' => 'importx', 'inton' => 'newsimport', 'artcp' => 'artcp',
        'cleartype' => 'cleartype', 'autotype' => 'oneclick', 'autoposter' => 'posterapply', 'update' => 'update',
    ];

    public function _empty($name)
    {
        $name = strtolower((string)$name);
        $target = isset(self::MAP[$name]) ? self::MAP[$name] : 'index';
        $qs = (string)($this->request->server('QUERY_STRING') ?? '');
        $qs = preg_replace('/(^|&)s=[^&]*/', '', $qs);
        $qs = ltrim($qs, '&');
        $url = url('nnzs/' . $target) . ($qs !== '' ? '?' . $qs : '');
        header('Location: ' . $url, true, 307);
        exit;
    }

    public function index()
    {
        return $this->_empty('index');
    }
}
