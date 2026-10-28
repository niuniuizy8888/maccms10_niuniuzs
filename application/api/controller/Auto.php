<?php

namespace app\api\controller;

use app\common\service\nnzs\Config;
use app\common\service\nnzs\Cron;
use app\common\service\nnzs\Log;
use app\common\service\nnzs\Replacer;
use app\common\service\nnzs\Rsync;

/**
 * 牛牛助手对外接口 api.php/auto/{test,replace,update,rsync,heartbeat}。
 *
 * ！！兼容契约：大量站点在用，URL、参数、口令（auth_key）规则、HTTP 状态码与响应文本必须与旧版逐字一致。！！
 *   口令来源：参数 token → 参数 key → 请求头 X-Nnzs-Token；与 Config::authKey()（环境变量 NNZS_AUTH_KEY 优先）hash_equals 比对
 *   未安装         → 403 "not-installed"
 *   口令错误       → 403 "token-err"
 *   test / replace / update / heartbeat 均接受 GET 与 POST（商店以 GET 推送 replace）；rsync 见下
 *   test           → "ok"
 *   replace        → "prefix-err" | "old-empty" | "--replace img--N--" | "--replace play_url--N 条" | "type-err"
 *   update         → 依次输出各采集定时任务的触发结果
 *   heartbeat      → JSON {code,msg}（插件 crontab / 整合版 Timming::nnzs 均可触发向商店上报）
 * 实现已改为调用 app\common\service\nnzs\* 服务类；替换前会把受影响行原值备份到 application/data/backup/nnzs/（不影响响应）。
 */
class Auto extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    private function nnzs_api_guard($requirePost = false)
    {
        if (!Config::isInstalled()) {
            Log::info('拒绝未安装状态下的对外接口调用: ' . request()->action());
            http_response_code(403);
            echo 'not-installed';
            exit;
        }
        $param = input();
        $token = '';
        if (isset($param['token']) && is_scalar($param['token'])) {
            $token = (string)$param['token'];
        } elseif (isset($param['key']) && is_scalar($param['key'])) {
            $token = (string)$param['key'];
        }
        if ($token === '') {
            $hdr = request()->header('x-nnzs-token');
            if (is_string($hdr) && $hdr !== '') {
                $token = $hdr;
            }
        }
        if (!Config::checkToken($token)) {
            Log::info('对外接口口令校验失败: ' . request()->action());
            http_response_code(403);
            echo 'token-err';
            exit;
        }
        if ($requirePost && !request()->isPost()) {
            http_response_code(405);
            echo 'POST only';
            exit;
        }
        return true;
    }

    public function test()
    {
        $this->nnzs_api_guard();
        echo 'ok';
    }

    public function replace()
    {
        // 商店推送替换用 GET {site}/api.php/auto/replace?token&types&old&new（pic 已在商店端映射为 img），
        // 因此 replace 必须同时接受 GET 与 POST；口令校验不变。
        $this->nnzs_api_guard(false);
        $param = input();
        if (Replacer::table() === false) {
            echo 'prefix-err';
            return;
        }
        $type = isset($param['types']) && is_scalar($param['types']) ? (string)$param['types'] : '';
        $old = isset($param['old']) && is_scalar($param['old']) ? (string)$param['old'] : '';
        $new = isset($param['new']) && is_scalar($param['new']) ? (string)$param['new'] : '';
        if ($old === '') {
            echo 'old-empty';
            return;
        }
        try {
            if ($type === 'img') {
                $upres = Replacer::replace('img', $old, $new);
                echo "--replace img--$upres--";
            } elseif ($type === 'play') {
                $upres = Replacer::replace('play', $old, $new);
                echo "--replace play_url--$upres 条";
            } else {
                echo 'type-err';
            }
        } catch (\RuntimeException $e) {
            // 成功时的输出格式不变；备份失败则不改数据，并返回明确失败标记。
            echo 'backup-fail';
        }
    }

    public function update()
    {
        // GET 与 POST 均可（旧对接方有用 GET 调用的），口令校验不变
        $this->nnzs_api_guard(false);
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $scheme = $https ? 'https' : 'http';
        $base = isset($GLOBALS['config']['site']['site_url']) ? trim((string)$GLOBALS['config']['site']['site_url']) : '';
        if ($base === '') {
            $base = $scheme . '://127.0.0.1';
        } elseif (!preg_match('#^https?://#i', $base)) {
            $base = $scheme . '://' . $base;   // site_url 通常只填域名
        }
        foreach (Cron::enabledCollect() as $value) {
            echo mac_curl_get(rtrim($base, '/') . Cron::triggerPath($value['name']));
        }
    }

    /**
     * 异步采集心跳：向商店 ?api=rsync/heartbeat 上报，避免 installs 被标为失联(status=3)。
     * 插件版 crontab：curl -s -H "X-Nnzs-Token: 口令" https://域名/api.php/auto/heartbeat
     * 整合版亦可走系统定时任务 file=nnzs（Timming::nnzs）。
     */
    public function heartbeat()
    {
        $this->nnzs_api_guard(false);
        $r = Rsync::heartbeat();
        return \think\Response::create([
            'code' => !empty($r['ok']) ? 1 : 0,
            'msg'  => (string)($r['msg'] ?? ''),
        ], 'json', !empty($r['ok']) ? 200 : 502, [], ['json_encode_param' => JSON_UNESCAPED_UNICODE]);
    }

    /**
     * 异步采集入站：商店 rsync_task worker 推送采集数据。
     *   POST api.php/auto/rsync   口令同上（token/key 参数或 X-Nnzs-Token 头）
     *   Content-Type: application/json   {code, from_code, page, pagecount, total, list:[...]} 或 {code, from_code, event, msg}
     *   GET 带口令 = 连通性检查，返回 {"code":1,"msg":"pong",...}
     * 响应均为 JSON；4xx 表示请求本身有误（worker 不应重试），5xx/超时可重试（重复推送幂等）。
     */
    public function rsync()
    {
        $out = function ($status, array $body) {
            return \think\Response::create($body, 'json', $status, [], ['json_encode_param' => JSON_UNESCAPED_UNICODE]);
        };
        // 鉴权：商店 worker 只用 X-Nnzs-Token 头；保留 token/key 参数兜底。错误一律 JSON + 4xx（code 非 1）
        if (!\app\common\service\nnzs\Config::isInstalled()) {
            return $out(403, ['code' => 0, 'msg' => 'not-installed']);
        }
        $token = request()->header('x-nnzs-token');
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            foreach (['token', 'key'] as $k) {
                $v = input('param.' . $k, '', null);
                if (is_scalar($v) && (string)$v !== '') {
                    $token = (string)$v;
                    break;
                }
            }
        }
        if (!\app\common\service\nnzs\Config::checkToken($token)) {
            Log::info('异步采集推送口令校验失败');
            return $out(403, ['code' => 0, 'msg' => 'token-err']);
        }
        if (!request()->isPost()) {
            return $out(200, ['code' => 1, 'msg' => 'pong', 'data' => ['version' => \app\common\service\nnzs\Config::get('version', ''),
                'max_items' => Rsync::MAX_ITEMS, 'mid' => [1]]]);
        }
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > Rsync::MAX_BODY) {
            return $out(413, ['code' => 0, 'msg' => 'body too large']);
        }
        $p = json_decode($raw, true);
        if (!is_array($p)) {
            // 兼容表单：data=<json>
            $d = input('post.data', '', null);
            $p = is_string($d) ? json_decode($d, true) : null;
        }
        if (!is_array($p)) {
            return $out(400, ['code' => 0, 'msg' => 'invalid json']);
        }
        try {
            list($status, $body) = Rsync::receive($p);
        } catch (\Throwable $e) {
            Log::error('异步采集入库异常：' . $e->getMessage());
            list($status, $body) = [500, ['code' => 0, 'msg' => 'internal error']];
        }
        return $out($status, $body);
    }
}
