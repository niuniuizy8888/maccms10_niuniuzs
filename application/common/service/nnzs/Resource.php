<?php
namespace app\common\service\nnzs;

use think\Db;

/**
 * 资源站接入：采集接口、播放器、分类绑定。
 */
class Resource
{
    /** 播放器标识白名单（落盘为 static/player/<apiinfo>.js，必须防目录穿越） */
    public static function validApiinfo($apiinfo)
    {
        return is_scalar($apiinfo) && preg_match('/^[a-z0-9_]{1,32}$/', strtolower(trim((string)$apiinfo)));
    }

    /** 新增采集接口（同 URL 已存在则跳过）。$mid 1=视频 2=文章 */
    public static function addCollect($name, $url, $mid = 1)
    {
        $exists = Db::name('collect')->where('collect_url', (string)$url)->find();
        if (!empty($exists)) {
            return (int)$exists['collect_id'];
        }
        $res = model('Collect')->saveData([
            'collect_name' => (string)$name,
            'collect_url' => (string)$url,
            'collect_param' => '',
            'collect_type' => 2,
            'collect_mid' => (int)$mid,
            'collect_opt' => 0,
            'collect_filter' => 0,
            'collect_filter_from' => '',
            'collect_filter_year' => '',
            'collect_sync_pic_opt' => 0,
        ]);
        if (!is_array($res) || (int)$res['code'] !== 1) {
            throw new \RuntimeException('保存采集接口失败：' . (is_array($res) ? $res['msg'] : ''));
        }
        return (int)Db::name('collect')->where('collect_url', (string)$url)->value('collect_id');
    }

    /** 写入播放器：extra/vodplayer.php 一项 + static/player/<apiinfo>.js */
    public static function addPlayer($apiinfo, $name, $playurl)
    {
        $apiinfo = is_scalar($apiinfo) ? strtolower(trim((string)$apiinfo)) : '';
        if (!self::validApiinfo($apiinfo)) {
            Log::info('拒绝非法的播放器标识: ' . var_export($apiinfo, true));
            return false;
        }
        $list = ConfigFile::read('vodplayer');
        $list[$apiinfo] = [
            'status' => '1',
            'from' => $apiinfo,
            'show' => (string)$name,
            'des' => (string)$name,
            'target' => '_self',
            'ps' => '1',
            'parse' => (string)$playurl,
            'sort' => '1000',
            'tip' => '无需安装任何插件',
        ];
        ConfigFile::write('vodplayer', $list);

        $dir = ROOT_PATH . 'static/player/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $jsUrl = json_encode((string)$playurl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $code = "MacPlayer.Html='<iframe width=\"100%\" height=\"'+MacPlayer.Height+'\" src=\"' + "
            . $jsUrl . " + MacPlayer.PlayUrl + '\" frameborder=\"0\" allowfullscreen=\"true\" border=\"0\" "
            . "marginwidth=\"0\" marginheight=\"0\" scrolling=\"no\"></iframe>';MacPlayer.Show();";
        if (@file_put_contents($dir . $apiinfo . '.js', $code, LOCK_EX) === false) {
            Log::error('写播放器脚本失败: static/player/' . $apiinfo . '.js');
            return false;
        }
        return true;
    }

    /**
     * 按名称把商店分类绑定到本站分类：extra/bind.php["{cjflag}_{商店分类ID}"] = 本站分类ID。
     * 本站没有同名分类的跳过（"未创建不绑定"）。
     * @return int 绑定条数
     */
    public static function bind($storeTypes, $cjflag)
    {
        if (!is_array($storeTypes) || !preg_match('/^[a-f0-9]{32}$/', (string)$cjflag)) {
            return 0;
        }
        $local = [];
        foreach (Db::name('type')->field('type_id,type_name')->order('type_id asc')->select() as $t) {
            $local[(string)$t['type_name']] = (int)$t['type_id'];
        }
        $bind = ConfigFile::read('bind');
        $n = 0;
        foreach ($storeTypes as $st) {
            if (!is_array($st) || !isset($st['type_id'], $st['type_name'])) {
                continue;
            }
            $name = (string)$st['type_name'];
            if (!empty($local[$name])) {
                $bind[$cjflag . '_' . $st['type_id']] = $local[$name];
                $n++;
            }
        }
        ConfigFile::write('bind', $bind);
        return $n;
    }

    /**
     * 一键接入一个资源站：采集接口 + 播放器 + 分类绑定 + 定时任务。
     * @param array $api  商店 apilist 条目（name, api, apiinfo, play, newname）
     * @param array $opts skip_api/skip_play/skip_bind/skip_cron
     */
    public static function onboard(array $api, array $data, array $opts = [])
    {
        $name = isset($api['name']) ? (string)$api['name'] : '';
        $url = isset($api['api']) ? (string)$api['api'] : '';
        $apiinfo = isset($api['apiinfo']) ? (string)$api['apiinfo'] : '';
        $play = isset($api['play']) ? (string)$api['play'] : '';
        $nname = isset($api['newname']) ? (string)$api['newname'] : '';
        if ($name === '' || $url === '' || $apiinfo === '') {
            return false;
        }
        if (!self::validApiinfo($apiinfo)) {
            // 旧插件仍会添加采集接口与定时任务，只跳过播放器；现在整条跳过，避免半成品配置
            Log::info('跳过标识非法的资源站: ' . $name);
            return false;
        }
        if (!mac_is_http_url($url)) {
            Log::info('跳过采集地址非法的资源站: ' . $name);
            return false;
        }
        $cjflag = md5($url);
        if (empty($opts['skip_api'])) {
            self::addCollect($name, $url, 1);
        }
        if (empty($opts['skip_play'])) {
            self::addPlayer($apiinfo, $name, $play);
        }
        if (empty($opts['skip_bind'])) {
            $types = isset($data['restype'][$apiinfo]) ? $data['restype'][$apiinfo] : [];
            self::bind($types, $cjflag);
            // 商店 restype 的分类 ID 常与资源站实时接口不一致，再按采集接口返回结果按名补绑
            self::bindFromCollectApi($url, $cjflag);
        }
        if (empty($opts['skip_cron'])) {
            Cron::upsertCollect($name, $nname, $url);
        }
        return true;
    }

    /**
     * 拉取资源站 ac=list 分类，按名称绑定到本站已有分类（不新建）。
     * @return int 绑定条数
     */
    public static function bindFromCollectApi($cjurl, $cjflag)
    {
        if (!mac_is_http_url($cjurl) || !preg_match('/^[a-f0-9]{32}$/', (string)$cjflag)) {
            return 0;
        }
        try {
            $list_param = [
                'cjurl' => (string)$cjurl, 'cjflag' => (string)$cjflag, 'cjtype' => '', 'type' => '2',
                'mid' => '1', 'ac' => 'list', 't' => '', 'h' => '', 'ids' => '', 'wd' => '',
                'page' => '1', 'param' => '', 'filter' => '0', 'filter_from' => '', 'opt' => '0',
                'sync_pic_opt' => '0', 'show' => 0,
            ];
            $res = model('Collect')->vod($list_param);
            if (!is_array($res) || intval($res['code'] ?? 0) > 1) {
                return 0;
            }
            $types = (isset($res['type']) && is_array($res['type'])) ? $res['type'] : [];
            return self::bind($types, $cjflag);
        } catch (\Throwable $e) {
            Log::info('按采集接口补绑分类失败: ' . $e->getMessage());
            return 0;
        }
    }
}
