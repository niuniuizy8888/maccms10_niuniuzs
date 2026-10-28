<?php
namespace app\common\service\nnzs;

/**
 * 牛牛助手初始化（后台「初始化」页面与安装向导共用）。
 *
 * 选项（与旧插件 auto/install 表单同名同义，便于旧链接/旧表单继续工作）：
 *   type  0=不导入分类 1=导入影视分类 2=导入X分类
 *   clear 1=先清空全部分类（会先备份）
 *   users 1=设置会员组权限
 *   api / play / bind / cron  1=跳过该步（旧约定："==1 表示跳过"）
 *   password 可选（不再参与 siteid；siteid 为随机值）
 *   report   1=向商店登记（默认取配置 nnzs.report）
 */
class Installer
{
    /** 新装整合版必装助手，仅分类导入可选，其余资源留待后台按需接入。 */
    public static function runDefault(bool $importTypes = true)
    {
        return self::run([
            'type' => $importTypes ? '1' : '0', 'clear' => '0', 'users' => '0',
            'api' => '1', 'play' => '1', 'bind' => '1', 'cron' => '1',
            'report' => '1',
        ]);
    }

    /**
     * @return array ['code'=>1|0, 'msg'=>string]
     */
    public static function run(array $opt, array $env = [])
    {
        if (Config::isInstalled()) {
            return ['code' => 0, 'msg' => '已安装，请勿重复安装'];
        }
        // 先确认商店可用再落配置：否则会出现「已生成口令（=已安装）但什么都没导入、也无法重新初始化」
        Store::clearCache();
        $type = (int)($opt['type'] ?? 0);
        $flag = function ($k) use ($opt) {
            return (int)($opt[$k] ?? 0) === 1;
        };
        $needStore = $type !== 0 || !$flag('api') || !$flag('play') || !$flag('bind') || !$flag('cron');
        $data = $needStore ? Store::data() : [];
        $needApis = !$flag('api') || !$flag('play') || !$flag('bind') || !$flag('cron');
        $typeKey = $type === 1 ? 'v' : 'x';
        if (($needApis && empty($data['apilist'])) || ($type !== 0 && empty($data['type'][$typeKey]))) {
            return ['code' => 0, 'msg' => '未取到商店资源列表，请确认服务器可访问 '
                . (Config::apiUrl() ?: '(未配置商店地址)') . ' 后重试'];
        }

        $host = isset($env['host']) ? (string)$env['host'] : (isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '');
        $host = preg_replace('/[^a-z0-9.\-:]/', '', strtolower(trim($host)));
        $scheme = isset($env['scheme']) ? (string)$env['scheme']
            : (((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? 'https' : 'http');   // 反代/隧道后 PHP 只见 http
        $serverip = isset($_SERVER['SERVER_ADDR']) ? (string)$_SERVER['SERVER_ADDR'] : '';
        if ($serverip === '' && function_exists('mac_get_client_ip')) {
            $serverip = (string)mac_get_client_ip();
        }
        $reg = [
            'auth_key' => mac_get_rndstr(24),
            'siteid'   => Config::newSiteId(),   // 随机标识，不由管理员密码派生
            'domian'   => Store::hostname($host),
            'http'     => $scheme,
            'sitename' => isset($GLOBALS['config']['site']['site_name']) ? (string)$GLOBALS['config']['site']['site_name'] : '',
            'serverip' => $serverip,
            'type'     => 'install',
        ];
        if ($flag('clear')) {
            TypeImporter::clearAll();
        }
        if ($type !== 0) {
            $k = $type === 1 ? 'v' : 'x';
            if (isset($data['type'][$k]) && is_array($data['type'][$k])) {
                TypeImporter::import($data['type'][$k], 1);
            }
            // 导入分类后必须开放会员组，否则前台游客/会员看不到对应内容（与「会员组」勾选项叠加均可）
            Group::grantAll();
        } elseif ($flag('users')) {
            Group::grantAll();
        }
        $opts = ['skip_api' => $flag('api'), 'skip_play' => $flag('play'), 'skip_bind' => $flag('bind'), 'skip_cron' => $flag('cron')];
        if (!($opts['skip_api'] && $opts['skip_play'] && $opts['skip_bind'] && $opts['skip_cron'])) {
            foreach ($data['apilist'] as $api) {
                Resource::onboard($api, $data, $opts);
            }
        }
        model('Type')->setCache();
        Config::set($reg + ['installed_at' => date('Y-m-d H:i:s')]);
        if (!Config::isInstalled()) {
            return ['code' => 0, 'msg' => '初始化 auth_key 失败，请检查 application/extra/maccms.php 可写性后重试'];
        }
        Store::clearCache();

        $report = isset($opt['report']) ? (string)$opt['report'] === '1' : (string)Config::get('report', '1') === '1';
        $registration = null;
        if ($report) {
            // 登记成功即获得异步采集权限（助手服务端 installs.status=1, origin=macplugin）
            $rep = Store::report($reg);
            $registration = $rep;
            if (!empty($rep['ok'])) {
                Config::set(['registered_at' => date('Y-m-d H:i:s')]);
                Rsync::heartbeat();
            } else {
                $retry = !empty($rep['rate_limited']) ? $rep : Store::ensureRegistered(true);
                $registration = $retry;
                if (!$retry['ok']) {
                    Log::info('助手服务端登记未成功：' . $retry['msg'] . '（可稍后在概览页点「补登记」）');
                } else {
                    Rsync::heartbeat();
                }
            }
            if (!$flag('cron')) {
                Cron::upsertHeartbeat(true);
            }
        } else {
            Log::info('按站点选择跳过助手服务端登记（不上报）');
        }

        Log::info('牛牛助手初始化完成');
        return ['code' => 1, 'msg' => '安装成功', 'registration' => $registration];
    }

    /** 资讯：导入文章分类 + 采集接口 + 分类绑定 + 定时任务 */
    public static function news(?array $data = null)
    {
        $data = $data ?: Store::data();
        $data['news'] = NewsSource::load($data['news'] ?? []);
        if (!isset($data['news']['newstype'])) {
            return ['code' => 0, 'msg' => '类型数据不存在'];
        }
        TypeImporter::import($data['news']['newstype'], 2);
        $api = isset($data['news']['api']) ? (string)$data['news']['api'] : '';
        if ($api !== '' && mac_is_http_url($api)) {
            // Reuse the retired provider's collection entry, preserving its options.
            if (!\think\Db::name('collect')->where('collect_url', $api)->find()) {
                foreach (\think\Db::name('collect')->where('collect_mid', 2)->select() as $row) {
                    if (NewsSource::isLegacy($row['collect_url'])) {
                        \think\Db::name('collect')->where('collect_id', $row['collect_id'])->update(['collect_url' => $api]);
                        break;
                    }
                }
            }
            Resource::addCollect(Cron::NEWS_NAME, $api, 2);
            if (isset($data['news']['type'])) {
                Resource::bind($data['news']['type'], md5($api));
            }
            Cron::upsertNews($api);
        }
        // 资讯分类导入后同样开放会员组，避免文章列表/详情对游客不可见
        try {
            Group::grantAll();
        } catch (\Throwable $e) {
            Log::error('资讯导入后设置会员组权限失败: ' . $e->getMessage());
            return ['code' => 0, 'msg' => '分类已导入，但会员组权限未更新（' . $e->getMessage() . '）'];
        }
        return ['code' => 1, 'msg' => '添加分类 自动绑定 定时任务设置成功'];
    }
}
