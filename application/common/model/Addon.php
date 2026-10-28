<?php
namespace app\common\model;
use think\Db;
use think\Config;

class Addon extends Base {

    /**
     * 在线插件市场数据 —— **已永久停用**（与 maccms-tp8 对齐）
     *
     * 【背景】原实现以 base64 隐藏了一个远端插件市场的请求地址：
     * mac_curl_get(base64_decode(...) . 'store/?page=' . $page)
     * 属"发行包内嵌的远程调用"，且该插件市场（FastAdmin 体系）已按用户要求弃用。
     *
     * base64 常量与远端请求（字面量不再保留在本文件中：不透明编码
     * 会被安全扫描工具判定为后门/混淆特征）。本方法现固定返回"获取失败"
     * 与远端不可达时的行为一致。
     *
     * 【调用方】全项目 0 处调用（实测），删除远端请求无功能影响。
     */
    public function onlineData($page=1)
    {
        return ['code' => 1001, 'msg' => lang('obtain_err')];
    }

    public function localData()
    {
        $results = glob(ADDON_PATH.'*');

        $list = [];
        foreach ($results as $addonDir) {
            if ($addonDir === '.' or $addonDir === '..')
                continue;

            if (!is_dir($addonDir))
                continue;

            $info_file = $addonDir .DS. 'info.ini';
            if (!is_file($info_file))
                continue;
            $name = str_replace(ADDON_PATH,'',$addonDir);
            $info = Config::parse($info_file, '', "addon-info-{$name}");
            $info['url'] = mac_url($name);
            $info['install'] = 1;
            $list[$name] = $info;
        }
        return ['code'=>1,'list'=>$list];
    }



}