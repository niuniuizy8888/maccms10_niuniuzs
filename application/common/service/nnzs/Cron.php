<?php
namespace app\common\service\nnzs;

/**
 * 与 MacCMS 定时任务（application/extra/timming.php，后台「系统 → 定时任务」）集成。
 * 任务项结构与系统定时任务完全一致，可在系统页面查看/编辑/停用。
 */
class Cron
{
    const NEWS_NAME = '牛牛助手-影视资讯';
    const NEWS_DESC = '最新影视娱乐资讯采集';
    const HEARTBEAT_NAME = '牛牛助手-异步采集心跳';

    private static function hours()
    {
        return '00,01,02,03,04,05,06,07,08,09,10,11,12,13,14,15,16,17,18,19,20,21,22,23';
    }

    /** 新增/覆盖一个"当日采集"任务（键 = 任务名，与旧插件一致） */
    public static function upsertCollect($name, $nname, $cjurl, $extraParam = '')
    {
        $name = (string)$name;
        $list = ConfigFile::read('timming');
        $old = isset($list[$name]) && is_array($list[$name]) ? $list[$name] : [];
        $list[$name] = [
            'id' => $name,
            'status' => '1',
            'name' => $name,
            'des' => '当日采集：' . $name . '【' . $nname . '】',
            'file' => 'collect',
            'param' => 'ac=cj&h=24&cjflag=' . md5((string)$cjurl) . '&cjurl=' . $cjurl . $extraParam,
            'weeks' => '1,2,3,4,5,6,0',
            'hours' => self::hours(),
        ];
        if (isset($old['runtime'])) {
            $list[$name]['runtime'] = $old['runtime'];   // 保留系统记录的上次运行时间
        }
        ConfigFile::write('timming', $list);
        return true;
    }

    public static function upsertNews($cjurl)
    {
        return self::upsertCollect(self::NEWS_NAME, self::NEWS_DESC, $cjurl, '&type=2&mid=2');
    }

    /** 异步采集心跳任务（每小时一次，走系统定时任务；Timming::nnzs 执行） */
    public static function upsertHeartbeat($enable = true)
    {
        $list = ConfigFile::read('timming');
        $old = isset($list[self::HEARTBEAT_NAME]) && is_array($list[self::HEARTBEAT_NAME]) ? $list[self::HEARTBEAT_NAME] : [];
        $list[self::HEARTBEAT_NAME] = [
            'id' => self::HEARTBEAT_NAME, 'status' => $enable ? '1' : '0', 'name' => self::HEARTBEAT_NAME,
            'des' => '向商店上报异步采集心跳', 'file' => 'nnzs', 'param' => 'ac=heartbeat',
            'weeks' => '1,2,3,4,5,6,0', 'hours' => self::hours(),
        ] + (isset($old['runtime']) ? ['runtime' => $old['runtime']] : []);
        ConfigFile::write('timming', $list);
        return true;
    }

    public static function heartbeatTask()
    {
        $list = ConfigFile::read('timming');
        return isset($list[self::HEARTBEAT_NAME]) && is_array($list[self::HEARTBEAT_NAME]) ? $list[self::HEARTBEAT_NAME] : null;
    }

    /** 由牛牛助手创建的任务（按采集地址与商店数据匹配） */
    public static function managed(?array $data = null)
    {
        $data = $data ?: Store::data();
        $urls = [];
        foreach ($data['apilist'] as $a) {
            if (!empty($a['api'])) {
                $urls[md5((string)$a['api'])] = (string)$a['name'];
            }
        }
        if (!empty($data['news']['api'])) {
            $urls[md5((string)$data['news']['api'])] = self::NEWS_NAME;
        }
        $out = [];
        foreach (ConfigFile::read('timming') as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            parse_str(isset($v['param']) ? (string)$v['param'] : '', $p);
            $flag = isset($p['cjflag']) ? (string)$p['cjflag'] : '';
            $v['managed'] = isset($urls[$flag]) || $k === self::NEWS_NAME || $k === self::HEARTBEAT_NAME;
            $v['key'] = $k;
            $v['name'] = isset($v['name']) ? (string)$v['name'] : (string)$k;
            $out[] = $v;
        }
        return $out;
    }

    /** 已启用的采集类任务（供对外接口 update 触发，口径与旧版一致：status=1 且 file=collect，最多 100 条） */
    public static function enabledCollect()
    {
        $out = [];
        foreach (ConfigFile::read('timming') as $v) {
            if (is_array($v) && isset($v['name']) && (string)($v['status'] ?? '') == '1' && (string)($v['file'] ?? '') === 'collect') {
                $out[] = $v;
                if (count($out) >= 100) {
                    break;
                }
            }
        }
        return $out;
    }

    /** 系统定时任务触发 URL（相对站点根） */
    public static function triggerPath($name)
    {
        $uri = self::apiEntry() . '/timming/index.html?enforce=1&name=' . rawurlencode((string)$name);
        $key = isset($GLOBALS['config']['app']['cron_key']) ? (string)$GLOBALS['config']['app']['cron_key'] : '';
        if ($key !== '') {
            $uri .= '&cron_key=' . rawurlencode($key);
        }
        return $uri;
    }

    /**
     * api 入口（与旧版 nnzs_api_entry() 一致）：在 api 入口（含改名）下就是当前入口；
     * 在后台等其他入口下回退 api.php，可用 app.api_entry 覆盖。
     */
    public static function apiEntry()
    {
        $f = defined('IN_FILE') ? (string)IN_FILE : '';
        if ($f === '' && isset($_SERVER['SCRIPT_NAME'])) {
            $f = (string)$_SERVER['SCRIPT_NAME'];
        }
        $f = basename(str_replace('\\', '/', $f));
        if ($f === '' || !preg_match('/^[A-Za-z0-9_]+\.php$/', $f)) {
            $f = 'index.php';
        }
        if (stripos($f, 'api') === false) {
            $cfg = isset($GLOBALS['config']['app']['api_entry']) ? (string)$GLOBALS['config']['app']['api_entry'] : '';
            $f = ($cfg !== '' && preg_match('/^[A-Za-z0-9_]+\.php$/', $cfg)) ? $cfg : 'api.php';
        }
        return '/' . $f;
    }
}
