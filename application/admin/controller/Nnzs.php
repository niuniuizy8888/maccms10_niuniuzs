<?php
namespace app\admin\controller;

use app\common\service\nnzs\ArtTheme;
use app\common\service\nnzs\Backup;
use app\common\service\nnzs\Config;
use app\common\service\nnzs\Cron;
use app\common\service\nnzs\Group;
use app\common\service\nnzs\Installer;
use app\common\service\nnzs\Log;
use app\common\service\nnzs\Poster;
use app\common\service\nnzs\Replacer;
use app\common\service\nnzs\Resource;
use app\common\service\nnzs\Rsync;
use app\common\service\nnzs\Store;
use app\common\service\nnzs\Upgrade;
use app\common\service\nnzs\TypeImporter;

/**
 * 牛牛助手（原独立插件，已并入后台）。
 *
 * 页面：index 概览 / resource 资源站 / news 资讯 / poster 海报 / replace 批量替换 / crons 定时任务 / config 设置 / install 初始化
 * 动作：一律 POST + CSRF 令牌；直接 GET 打开时给出二次确认页（便于旧书签/旧链接继续可用）。
 * 危险操作（清空分类、重置会员组权限、海报批量改库、批量替换）在权限树里单独列出，可按角色单独授权。
 */
class Nnzs extends Base
{
    public function __construct()
    {
        parent::__construct();
        Config::all();   // 触发旧配置迁移
        $this->assign('nnzs_installed', Config::isInstalled());
    }

    /* ---------------- 内部 ---------------- */

    private function storeData($refresh = false)
    {
        return Store::data($refresh);
    }

    private function page($tpl, array $vars = [])
    {
        $this->assign('title', '牛牛助手');
        $this->assign('store_url', Config::apiUrl());
        $this->assign('nnzs_tab', $tpl);
        // 商店广告位：与原插件一致，默认开启、每个助手页面都显示（无开关）
        if (!isset($vars['nnzs_ad'])) {
            try { $sd = Store::data(); } catch (\Throwable $e) { $sd = []; }
            $this->assign('nnzs_ad', [
                'imgadlist' => $sd['imgadlist'] ?? [], 'imglist' => $sd['imglist'] ?? [], 'nvalist' => $sd['nvalist'] ?? [],
                'qq' => $sd['code']['qq'] ?? '', 'info' => Store::richHtml($sd['info'] ?? ''),
            ]);
        }
        foreach ($vars as $k => $v) {
            $this->assign($k, $v);
        }
        return $this->fetch('admin@nnzs/' . $tpl);
    }

    /** 非 POST 时渲染二次确认页（带令牌的表单，回 POST 到当前地址） */
    private function confirmOr($title, $desc, $danger = false)
    {
        if ($this->request->isPost()) {
            return null;
        }
        $params = [];
        foreach ($this->request->get() as $k => $v) {
            if (is_scalar($v) && $k !== 's') {
                $params[$k] = (string)$v;
            }
        }
        $this->assign('confirm', ['title' => $title, 'desc' => $desc, 'danger' => $danger, 'params' => $params,
            'action' => $this->request->url()]);
        return $this->page('confirm');
    }

    private function needInstalled()
    {
        if (!Config::isInstalled()) {
            $this->error('请先完成牛牛助手初始化', url('nnzs/install'));
        }
    }

    /* ---------------- 页面 ---------------- */

    public function index()
    {
        if (!Config::isInstalled()) {
            return $this->redirect('nnzs/install');
        }
        $d = $this->storeData();
        $d['info_html'] = Store::richHtml($d['info'] ?? '');
        $stat = [
            'collect' => (int)\think\Db::name('collect')->count(),
            'type'    => (int)\think\Db::name('type')->count(),
            'cron'    => count(array_filter(Cron::managed($d), function ($v) { return $v['managed']; })),
            'backup'  => count(Backup::lists(1000)),
        ];
        return $this->page('index', [
            'd' => $d, 'stat' => $stat, 'conf' => Config::all(),
            'reg' => Store::registrationStatus(),
            'upgrade' => Upgrade::check($d) + ['downloaded' => (bool)Upgrade::downloaded(Upgrade::check($d)['remote'])],
        ]);
    }

    public function resource()
    {
        $this->needInstalled();
        $d = $this->storeData();
        $collects = \think\Db::name('collect')->column('collect_id', 'collect_url');
        $players = config('vodplayer');
        $list = [];
        foreach ($d['apilist'] as $a) {
            $url = (string)($a['api'] ?? '');
            $info = (string)($a['apiinfo'] ?? '');
            $a['cjflag'] = md5($url);
            $a['collect_id'] = isset($collects[$url]) ? $collects[$url] : 0;
            $a['has_player'] = $info !== '' && isset($players[$info]);
            $a['valid'] = Resource::validApiinfo($info) && mac_is_http_url($url);
            $list[] = $a;
        }
        return $this->page('resource', ['d' => $d, 'list' => $list]);
    }

    public function news()
    {
        $this->needInstalled();
        $d = $this->storeData();
        $api = (string)($d['news']['api'] ?? '');
        return $this->page('news', ['d' => $d, 'news_api' => $api, 'news_flag' => $api === '' ? '' : md5($api),
            'art_missing' => ArtTheme::missing(), 'art_dir' => str_replace(ROOT_PATH, '', ArtTheme::targetDir())]);
    }

    public function poster()
    {
        $this->needInstalled();
        $d = $this->storeData();
        $modes = [];
        foreach ($d['hb'] as $k => $items) {
            if (is_array($items)) {
                $labels = ['tophb' => '热门海报', 'newhb' => '最新海报', 'randhb' => '随机海报'];
                $modes[] = ['mode' => (string)$k, 'label' => $labels[(string)$k] ?? (string)$k, 'count' => count($items), 'match' => Poster::matchCount($k, $d)];
            }
        }
        return $this->page('poster', ['modes' => $modes]);
    }

    public function replace()
    {
        $this->needInstalled();
        $d = $this->storeData();
        return $this->page('replace', ['replist' => $d['replist'], 'backups' => Backup::lists(20)]);
    }

    public function crons()
    {
        $this->needInstalled();
        $list = Cron::managed($this->storeData());
        $base = rtrim((string)($GLOBALS['config']['site']['install_dir'] ?? '/'), '/');
        foreach ($list as &$v) {
            $v['trigger'] = $base . Cron::triggerPath($v['name'] ?? $v['key']);
        }
        unset($v);
        return $this->page('crons', ['list' => $list]);
    }

    public function config()
    {
        if ($this->request->isPost()) {
            $p = input('post.');
            $url = trim((string)($p['api_url'] ?? ''));
            if ($url !== '' && !mac_is_http_url($url)) {
                return $this->error('助手服务端地址必须是 http(s):// 开头的网址');
            }
            Config::set([
                'api_url'    => rtrim($url, '/'),
                'report'     => !empty($p['report']) ? '1' : '0',
                'cache_ttl'  => (string)max(60, min(86400, (int)($p['cache_ttl'] ?? 1800))),
            ]);
            Store::clearCache();
            return $this->success('保存成功', url('nnzs/config'));
        }
        $conf = Config::all();
        $envKey = trim((string)getenv('NNZS_AUTH_KEY')) !== '' || !empty($_SERVER['NNZS_AUTH_KEY']) || !empty($_ENV['NNZS_AUTH_KEY']);
        $key = Config::authKey();
        return $this->page('config', ['conf' => $conf, 'env_key' => $envKey,
            'key_mask' => $key === '' ? '' : substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4),
            'api_entry' => Cron::apiEntry()]);
    }

    /** 在线客服：跳转商店返回的客服链接 */
    public function service()
    {
        $d = Store::data();
        $qq = isset($d['code']['qq']) ? (string)$d['code']['qq'] : '';
        if ($qq !== '' && function_exists('mac_is_http_url') && mac_is_http_url($qq)) {
            return redirect($qq);
        }
        return $this->error('暂无在线客服链接，请稍后刷新数据后再试', url('nnzs/index'));
    }

    /** @deprecated 推广页已移除，保留入口以免旧书签 404 */
    public function promo()
    {
        return $this->redirect('nnzs/index');
    }

    public function install()
    {
        if (!$this->request->isPost()) {
            if (Config::isInstalled()) {
                return $this->success('已安装，请勿重复安装', url('nnzs/index'));
            }
            return $this->page('install', ['conf' => Config::all()]);
        }
        $param = input('post.');   // 未传 report 时按配置 nnzs.report（默认登记，同旧插件）
        try {
            $res = Installer::run($param);
        } catch (\Throwable $e) {
            Log::error('初始化失败: ' . $e->getMessage());
            return $this->error('初始化失败：' . $e->getMessage());
        }
        return $res['code'] == 1 ? $this->success($res['msg'], url('nnzs/index')) : $this->error($res['msg'], url('nnzs/index'));
    }

    /* ---------------- 动作 ---------------- */

    public function refresh()
    {
        if ($r = $this->confirmOr('刷新数据', '清除本地缓存并立即重新拉取助手服务端数据。')) {
            return $r;
        }
        $d = Store::data(true);
        if (!$d['online']) {
            return $this->error('助手服务端暂不可达（' . Store::$lastError . '）：' . Config::apiUrl());
        }
        return Store::$lastError === '' ? $this->success('已刷新助手服务端数据')
            : $this->success('助手服务端暂时不可用（' . Store::$lastError . '），继续使用上一份数据');
    }

    public function oneclick()
    {
        $this->needInstalled();
        if ($r = $this->confirmOr('一键接入资源站', '将自动添加：采集接口、播放器、分类绑定（本站已有同名分类才绑定）、每小时定时采集任务。')) {
            return $r;
        }
        $d = Store::data();
        $api = Store::findApi(input('apiinfo'), $d);
        if (!$api && input('?cjurl')) {
            // 兼容旧链接 auto/autotype?name=&apiinfo=&cjurl=&cname=&playurl=
            $api = ['name' => input('name'), 'api' => input('cjurl'), 'apiinfo' => input('apiinfo'),
                'play' => input('playurl'), 'newname' => input('cname')];
        }
        if (!$api || !Resource::onboard($api, $d)) {
            return $this->error('资源站不存在或数据不完整');
        }
        return $this->success('一键添加解析/自动分类(未创建不绑定)/定时任务成功', url('nnzs/resource'));
    }

    public function importv()
    {
        return $this->importTypes('v', '导入影视分类', '添加影视分类成功');
    }

    public function importx()
    {
        return $this->importTypes('x', '导入X分类', '添加x分类成功');
    }

    private function importTypes($k, $title, $okmsg)
    {
        if ($r = $this->confirmOr($title, '只新增本站没有的同名分类，不修改、不删除已有分类。')) {
            return $r;
        }
        $d = Store::data();
        if (!isset($d['type'][$k]) || !is_array($d['type'][$k])) {
            return $this->error('类型数据不存在');
        }
        try {
            TypeImporter::import($d['type'][$k], 1);
            // 即使无新增（同名已存在），也确保游客/会员/VIP 具备全部分类权限，否则前台看不到内容
            Group::grantAll();
        } catch (\Throwable $e) {
            return $this->error('导入失败（已回滚）：' . $e->getMessage());
        }
        return $this->success($okmsg, url('nnzs/resource'));
    }

    public function newsimport()
    {
        $this->needInstalled();
        if ($r = $this->confirmOr('导入资讯分类与采集', '新增文章分类、资讯采集接口、分类绑定和每小时定时采集任务。')) {
            return $r;
        }
        try {
            $res = Installer::news();
        } catch (\Throwable $e) {
            return $this->error('导入失败（已回滚）：' . $e->getMessage());
        }
        return $res['code'] == 1 ? $this->success($res['msg'], url('nnzs/news')) : $this->error($res['msg']);
    }

    public function artcp()
    {
        if ($r = $this->confirmOr('初始化资讯模板', '把内置的 art/type.html、art/detail.html 复制到当前前台模板（已存在的文件不覆盖）。')) {
            return $r;
        }
        ArtTheme::install();
        return $this->success('资讯模板初始化完成', url('nnzs/news'));
    }

    public function cleartype()
    {
        if ($r = $this->confirmOr('清空全部分类', '将清空本站所有分类（TRUNCATE）。执行前会自动备份；备份失败则不会清空。', true)) {
            return $r;
        }
        try {
            TypeImporter::clearAll();
        } catch (\Throwable $e) {
            return $this->error('清空失败：' . $e->getMessage());
        }
        return $this->success('清空所有分类成功', url('nnzs/resource'));
    }

    public function groups()
    {
        if ($r = $this->confirmOr('重置会员组权限', '游客 / 默认会员 / VIP会员 三个组将被设置为可访问全部分类、全部权限。执行前自动备份会员组表。', true)) {
            return $r;
        }
        try {
            Group::grantAll();
        } catch (\Throwable $e) {
            return $this->error('设置失败（已回滚）：' . $e->getMessage());
        }
        return $this->success('会员组权限已更新', url('nnzs/index'));
    }

    public function posterapply()
    {
        $this->needInstalled();
        if ($r = $this->confirmOr('批量更新海报', '按豆瓣 ID 匹配本站影片，覆盖轮播图并设置推荐等级。执行前自动备份受影响影片的原值。', true)) {
            return $r;
        }
        $levl = max(0, min(9, (int)input('levl/d', 0)));
        try {
            $n = Poster::apply(input('mode'), $levl);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
        if ($n === false) {
            return $this->error('海报数据不存在');
        }
        return $this->success("自动更新了 $n 条到推荐等级 $levl", url('nnzs/poster'));
    }

    public function replacedo()
    {
        $this->needInstalled();
        if ($r = $this->confirmOr('执行批量替换', '对全部影片的封面或播放地址做字符串替换。执行前自动备份受影响行原值。', true)) {
            return $r;
        }
        $type = (string)input('post.types');
        $old = (string)input('post.old', '', null);
        $new = (string)input('post.new', '', null);
        if ($old === '') {
            return $this->error('原字符串不能为空');
        }
        try {
            $n = Replacer::replace($type, $old, $new);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
        if ($n === false) {
            return $this->error('替换类型错误');
        }
        return $this->success(($type === 'img' ? '封面' : '播放地址') . "替换完成，影响 $n 条", url('nnzs/replace'));
    }

    /** 异步采集面板：每个资源站一个任务（任务号由本站随机 siteid 派生） */
    public function rsync()
    {
        $this->needInstalled();
        // 仅读本地登记状态，不自动打 macinstall（服务端约 6 次/小时限流）
        $reg = Store::registrationStatus();
        $d = $this->storeData();
        $local = Rsync::all();
        $rows = [];
        foreach ($d['apilist'] as $api) {
            $from = isset($api['apiinfo']) ? (string)$api['apiinfo'] : '';
            if ($from === '' || empty($api['api'])) {
                continue;
            }
            $code = Rsync::codeFor($from);
            $t = $local['tasks'][$code] ?? [];
            $rows[] = [
                'from_code' => $from, 'name' => (string)($api['name'] ?? $from), 'code' => $code,
                'onboard' => (bool)Rsync::resolve($from, $d),
                'remote' => $t['remote'] ?? [], 'remote_time' => $t['remote_time'] ?? 0,
                'push' => ($t['push'] ?? []) + ['received' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
                    'last_page' => 0, 'pagecount' => 0, 'last_time' => 0, 'last_error' => ''],
                'last_action' => $t['last_action'] ?? '', 'last_result' => $t['last_result'] ?? '',
                'event' => $t['event'] ?? '', 'event_msg' => $t['event_msg'] ?? '',
            ];
        }
        $scheme = (string)Config::get('http', 'http');
        $host = (string)Config::get('domian', '');
        $base = ($host !== '' && strpos($host, '://') === false) ? ($scheme . '://' . $host) : $host;
        $site = rtrim($base !== '' ? $base : (string)($GLOBALS['config']['site']['site_url'] ?? ''), '/');
        return $this->page('rsync', ['rows' => $rows, 'hb' => $local['heartbeat'], 'hbtask' => Cron::heartbeatTask(),
            'reg' => $reg,
            'push_url' => ($site !== '' && strpos($site, '://') !== false ? $site : '') . Cron::apiEntry() . '/auto/rsync']);
    }

    /** 异步采集操作：start / stop / restart / status / statusall / heartbeat / cron / reregister */
    public function rsyncop()
    {
        $this->needInstalled();
        if ($r = $this->confirmOr('异步采集操作', '向助手服务端发送异步采集指令。')) {
            return $r;
        }
        $op = (string)input('op', '');
        $from = (string)input('from_code', '');
        $h = (int)input('h', 0);
        switch ($op) {
            case 'start':
                $res = Rsync::start($from, $h, (int)input('count', 0), (int)input('page', 1));
                break;
            case 'stop':
                $res = Rsync::stop($from);
                break;
            case 'restart':
                $res = Rsync::restart($from, $h, (string)input('full', '') === '1');
                break;
            case 'status':
                $res = Rsync::status($from);
                break;
            case 'statusall':
                $res = Rsync::status(null);
                break;
            case 'heartbeat':
                $res = Rsync::heartbeat();
                break;
            case 'reregister':
                $res = Store::ensureRegistered(true);
                if ($res['ok']) {
                    Rsync::heartbeat();
                }
                $back = (string)input('back', 'rsync');
                $url = $back === 'index' ? url('nnzs/index') : url('nnzs/rsync');
                return $res['ok'] ? $this->success($res['msg'], $url) : $this->error($res['msg']);
            case 'cron':
                Cron::upsertHeartbeat((string)input('enable', '1') === '1');
                return $this->success('已更新心跳定时任务（系统 → 定时任务）', url('nnzs/rsync'));
            default:
                return $this->error('未知操作');
        }
        $msg = $res['msg'] !== '' ? $res['msg'] : ($res['ok'] ? 'ok' : '失败');
        return $res['ok'] ? $this->success('助手服务端返回：' . $msg, url('nnzs/rsync')) : $this->error($msg);
    }

    /** 在线升级：下载商店 code.macv10 对应升级包并校验 X-Store-SHA256，只保存不解压 */
    public function update()
    {
        $this->needInstalled();
        if ($r = $this->confirmOr('下载升级包', '从助手服务端下载最新版本升级包并校验 SHA256，保存到 application/data/nnzs/upgrade/；下载后需另行点击「应用升级」。')) {
            return $r;
        }
        $u = Upgrade::check(Store::data(true));
        if (!$u['valid']) {
            return $this->error('助手服务端未提供有效的升级版本号（code.macv10）');
        }
        $res = Upgrade::download($u['remote']);
        return $res['code'] === 1 ? $this->success($res['msg'], url('nnzs/index')) : $this->error($res['msg']);
    }

    /** 应用已下载的升级包（仅接受 v2 结构 + 清单逐文件校验；覆盖前备份，失败回滚） */
    public function upgradeapply()
    {
        $this->needInstalled();
        if ($r = $this->confirmOr('应用升级包', '校验 nnzs_manifest.json 与逐文件 SHA256 后覆盖牛牛助手自身文件；原文件备份到 application/data/backup/nnzs/，失败自动回滚。', true)) {
            return $r;
        }
        $u = Upgrade::check(Store::data());
        if (!$u['valid']) {
            return $this->error('助手服务端未提供有效的升级版本号（code.macv10）');
        }
        $res = Upgrade::apply($u['remote']);
        return $res['code'] === 1 ? $this->success($res['msg'], url('nnzs/index')) : $this->error($res['msg']);
    }
}
