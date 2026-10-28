<?php
namespace app\common\service\nnzs;

use think\Db;

/**
 * 异步采集（商店端 rsync_task worker 代采集、推送入库）。
 *
 * 出站（本站 → 商店，契约 ?api=rsync/*，token = 安装登记的 auth_key，且商店端登记状态 status=1）：
 *   start     token, from_code, count, page, code(8-64 位字母数字任务号), h     → {msg:'running', code}
 *   stop      token, code                                                       → {msg}
 *   restart   token, code, from_code, h [, full=1]                              → {msg, code}
 *   status    token [, code]   有 code 返回单个 {progress,msg,total,fail,last_error}，无 code 返回任务数组
 *   heartbeat token                                                             → {msg}
 *   出错：429 {code:-1,msg:'rate limited'}；其它 {code:-1,msg}；也兼容 {code,data} 包装。
 *
 * 入站（worker → 本站）：POST api.php/auto/rsync（详见 README.md 的对外接口说明）
 *   口令：token / key 参数或 X-Nnzs-Token 头 = auth_key；JSON 体：
 *   {code, from_code, page, pagecount, total, list:[资源站 ?ac=videolist 原始条目]}
 *   成功 200 {code:1,msg,data:{inserted,updated,skipped,failed,...}}；鉴权/校验错误为 4xx + code 0
 *   或事件 {code, from_code, event:"done"|"error"|"stopped", msg} → 记录状态，返回 code 1
 *   写库走 MacCMS 采集模型 vod_data（与普通采集同一套入库/更新/过滤/分类绑定规则），按名称去重，重复推送幂等。
 */
class Rsync
{
    const CODE_RE = '/^[A-Za-z0-9]{8,64}$/';
    const MAX_ITEMS = 200;
    const MAX_BODY = 10485760;
    const VOD_FIELDS = ['vod_name', 'vod_sub', 'vod_en', 'vod_tag', 'vod_class', 'vod_pic', 'vod_actor', 'vod_director', 'vod_writer',
        'vod_blurb', 'vod_remarks', 'vod_area', 'vod_lang', 'vod_year', 'vod_serial', 'vod_total', 'vod_isend', 'vod_lock', 'vod_level',
        'vod_hits', 'vod_hits_day', 'vod_hits_week', 'vod_hits_month', 'vod_up', 'vod_down', 'vod_score', 'vod_score_all', 'vod_score_num',
        'vod_stint_play', 'vod_stint_down', 'vod_douban_id', 'vod_content', 'vod_plot_name', 'vod_plot_detail',
        'vod_play_from', 'vod_play_url', 'vod_play_server', 'vod_play_note',
        'vod_down_from', 'vod_down_url', 'vod_down_server', 'vod_down_note', 'vod_time_add', 'vod_time_update', 'type_name'];

    /* ---------------- 本地任务登记 ---------------- */

    private static function file()
    {
        return APP_PATH . 'data/nnzs/rsync.json';
    }

    /** 读-改-写（flock 串行化）；$fn 收到数组并返回新数组 */
    private static function mutate(callable $fn)
    {
        $f = self::file();
        if (!is_dir(dirname($f))) {
            @mkdir(dirname($f), 0755, true);
        }
        $h = @fopen($f, 'c+');
        if (!$h) {
            return $fn(self::all());
        }
        flock($h, LOCK_EX);
        $raw = stream_get_contents($h);
        $data = json_decode((string)$raw, true);
        $data = is_array($data) ? $data : ['tasks' => [], 'heartbeat' => []];
        $new = $fn($data);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
        return $new;
    }

    public static function all()
    {
        $raw = is_file(self::file()) ? file_get_contents(self::file()) : '';
        $d = json_decode((string)$raw, true);
        $d = is_array($d) ? $d : [];
        return $d + ['tasks' => [], 'heartbeat' => []];
    }

    /** 每个资源站固定的任务号（24 位十六进制，由随机 siteid 派生，不含口令） */
    public static function codeFor($fromCode)
    {
        return substr(hash('sha256', Config::get('siteid', '') . '|rsync|' . (string)$fromCode), 0, 24);
    }

    /** 资源站 → 本地采集接口（必须已一键接入，才能用分类绑定） */
    public static function resolve($fromCode, ?array $data = null)
    {
        $fromCode = (string)$fromCode;
        $tasks = self::all()['tasks'];
        $cjurl = '';
        $name = $fromCode;
        foreach ($tasks as $t) {
            if (($t['from_code'] ?? '') === $fromCode && !empty($t['cjurl'])) {
                $cjurl = (string)$t['cjurl'];
                $name = (string)($t['name'] ?? $name);
            }
        }
        if ($cjurl === '') {
            $api = Store::findApi($fromCode, $data);
            if (!$api || empty($api['api'])) {
                return null;
            }
            $cjurl = (string)$api['api'];
            $name = (string)($api['name'] ?? $name);
        }
        $collect = Db::name('collect')->where('collect_url', $cjurl)->find();
        if (empty($collect)) {
            return null;
        }
        return ['from_code' => $fromCode, 'name' => $name, 'cjurl' => $cjurl, 'cjflag' => md5($cjurl), 'collect' => $collect];
    }

    /* ---------------- 出站：调用商店 ---------------- */

    public static function call($action, array $params)
    {
        if (!in_array($action, ['start', 'stop', 'restart', 'status', 'heartbeat'], true)) {
            return ['ok' => false, 'msg' => 'bad action', 'data' => null, 'status' => 0];
        }
        $base = Store::endpoint('api=rsync/' . $action);
        if ($base === '') {
            return ['ok' => false, 'msg' => '未配置商店地址', 'data' => null, 'status' => 0];
        }
        $params = ['token' => Config::authKey()] + $params;
        $r = Http::send($base . '&' . http_build_query($params), null, 15000, ['Accept: application/json']);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => '连接商店失败：' . $r['error'], 'data' => null, 'status' => 0];
        }
        $j = json_decode(trim($r['body'], "\xEF\xBB\xBF \r\n\t"), true);
        if ($r['status'] === 429) {
            return ['ok' => false, 'msg' => '商店限流，请稍后再试', 'data' => $j, 'status' => 429];
        }
        if (!is_array($j)) {
            return ['ok' => false, 'msg' => '商店返回非 JSON（HTTP ' . $r['status'] . '）', 'data' => null, 'status' => $r['status']];
        }
        // 兼容 {code,data} 包装
        if (isset($j['code'], $j['data']) && is_numeric($j['code']) && (is_array($j['data']) || $j['data'] === null)) {
            $ok = (int)$j['code'] >= 0 && $r['status'] < 400;
            return ['ok' => $ok, 'msg' => (string)($j['msg'] ?? ''), 'data' => $j['data'], 'status' => $r['status']];
        }
        $isErr = $r['status'] >= 400 || (isset($j['code']) && is_numeric($j['code']) && (int)$j['code'] < 0);
        $msg = isset($j['msg']) && is_scalar($j['msg']) ? (string)$j['msg'] : '';
        return ['ok' => !$isErr, 'msg' => $isErr ? ($msg !== '' ? $msg : 'HTTP ' . $r['status']) : $msg, 'data' => $j, 'status' => $r['status']];
    }

    /**
     * 向商店提交异步采集任务。
     * 契约：count = 总页数上限（商店护栏 50 万，等同不限）；page = 起始页；h = 最近 N 小时（0=全部）。
     * Go worker（origin=macplugin）会按资源站真实 pagecount 提前结束。
     */
    public static function start($fromCode, $h = 0, $count = 0, $page = 1)
    {
        $res = self::resolve($fromCode);
        if (!$res) {
            return ['ok' => false, 'msg' => '该资源站尚未在本站接入（需先「一键接入」以绑定分类）'];
        }
        $code = self::codeFor($fromCode);
        // 默认给足页数护栏；过小会导致「采集全部」只采前 N 页
        $count = (int)$count > 0 ? (int)$count : 500000;
        $count = max(1, min(500000, $count));
        $r = self::call('start', ['from_code' => (string)$fromCode, 'count' => $count,
            'page' => max(1, (int)$page), 'code' => $code, 'h' => max(0, (int)$h)]);
        self::remember($code, $res, ['h' => (int)$h, 'last_action' => 'start', 'last_result' => $r['msg']]);
        return $r + ['code' => $code];
    }

    public static function stop($fromCode)
    {
        $code = self::codeFor($fromCode);
        $r = self::call('stop', ['code' => $code]);
        self::touch($code, ['last_action' => 'stop', 'last_result' => $r['msg']]);
        return $r + ['code' => $code];
    }

    public static function restart($fromCode, $h = 0, $full = false)
    {
        $res = self::resolve($fromCode);
        if (!$res) {
            return ['ok' => false, 'msg' => '该资源站尚未在本站接入'];
        }
        $code = self::codeFor($fromCode);
        $p = ['code' => $code, 'from_code' => (string)$fromCode, 'h' => max(0, (int)$h)];
        if ($full) {
            $p['full'] = 1;
        }
        $r = self::call('restart', $p);
        self::remember($code, $res, ['h' => (int)$h, 'last_action' => $full ? 'restart-full' : 'restart', 'last_result' => $r['msg']]);
        return $r + ['code' => $code];
    }

    /** 不带 code 时返回 [code => 状态]；带 code 时返回单个状态 */
    public static function status($fromCode = null)
    {
        if ($fromCode !== null) {
            $code = self::codeFor($fromCode);
            $r = self::call('status', ['code' => $code]);
            if ($r['ok'] && is_array($r['data'])) {
                self::touch($code, ['remote' => self::pickStatus($r['data']), 'remote_time' => time()]);
            }
            return $r + ['code' => $code];
        }
        $r = self::call('status', []);
        $map = [];
        if ($r['ok'] && is_array($r['data'])) {
            $list = isset($r['data']['list']) && is_array($r['data']['list']) ? $r['data']['list'] : $r['data'];
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $c = isset($row['code']) && is_scalar($row['code']) ? (string)$row['code'] : '';
                if ($c === '' && isset($row['from_code']) && is_scalar($row['from_code'])) {
                    $c = self::codeFor((string)$row['from_code']);
                }
                if ($c !== '') {
                    $map[$c] = self::pickStatus($row);
                }
            }
            if ($map) {
                self::mutate(function ($d) use ($map) {
                    foreach ($map as $c => $st) {
                        if (isset($d['tasks'][$c])) {
                            $d['tasks'][$c]['remote'] = $st;
                            $d['tasks'][$c]['remote_time'] = time();
                        }
                    }
                    return $d;
                });
            }
        }
        return $r + ['map' => $map];
    }

    public static function heartbeat()
    {
        $r = self::call('heartbeat', []);
        self::mutate(function ($d) use ($r) {
            $d['heartbeat'] = ['time' => time(), 'ok' => $r['ok'], 'msg' => $r['msg']];
            return $d;
        });
        if (!$r['ok']) {
            Log::info('异步采集心跳失败：' . $r['msg']);
        }
        return $r;
    }

    private static function pickStatus(array $row)
    {
        $out = [];
        foreach (['code', 'from_code', 'progress', 'msg', 'total', 'fail', 'last_error', 'state', 'status', 'page'] as $k) {
            if (isset($row[$k]) && is_scalar($row[$k])) {
                $out[$k] = mb_substr((string)$row[$k], 0, 300);
            }
        }
        return $out;
    }

    private static function remember($code, array $res, array $extra)
    {
        self::mutate(function ($d) use ($code, $res, $extra) {
            $t = $d['tasks'][$code] ?? [];
            $t = array_merge($t, ['code' => $code, 'from_code' => $res['from_code'], 'name' => $res['name'],
                'cjurl' => $res['cjurl'], 'updated' => time()], $extra);
            $t += ['created' => time(), 'push' => []];
            $d['tasks'][$code] = $t;
            return $d;
        });
    }

    private static function touch($code, array $extra)
    {
        self::mutate(function ($d) use ($code, $extra) {
            if (isset($d['tasks'][$code])) {
                $d['tasks'][$code] = array_merge($d['tasks'][$code], $extra, ['updated' => time()]);
            }
            return $d;
        });
    }

    /* ---------------- 入站：worker 推送 ---------------- */

    /**
     * @return array [httpStatus, body(array)]
     */
    public static function receive(array $p)
    {
        $code = isset($p['code']) && is_scalar($p['code']) ? (string)$p['code'] : '';
        $from = isset($p['from_code']) && is_scalar($p['from_code']) ? (string)$p['from_code'] : '';
        if (!preg_match(self::CODE_RE, $code) || $from === '' || strlen($from) > 64) {
            return [400, ['code' => 0, 'msg' => 'code(8-64 位字母数字) 与 from_code 必填']];
        }
        $known = self::all()['tasks'][$code] ?? null;
        if (!hash_equals(self::codeFor($from), $code)
            && !(is_array($known) && (string)($known['from_code'] ?? '') === $from)) {
            return [409, ['code' => 0, 'msg' => '未知任务：code 与 from_code 不匹配本站任务']];
        }
        $res = self::resolve($from);
        if (!$res) {
            return [409, ['code' => 0, 'msg' => '资源站 ' . $from . ' 未在本站接入（无采集接口/分类绑定）']];
        }
        if (isset($p['event']) && is_scalar($p['event'])) {
            $ev = (string)$p['event'];
            if (!in_array($ev, ['done', 'error', 'stopped', 'running'], true)) {
                return [400, ['code' => 0, 'msg' => 'event 只能为 done/error/stopped']];
            }
            self::remember($code, $res, ['event' => $ev, 'event_msg' => mb_substr((string)($p['msg'] ?? ''), 0, 300), 'event_time' => time()]);
            return [200, ['code' => 1, 'msg' => 'ok', 'data' => ['code' => $code, 'event' => $ev]]];
        }
        $list = $p['list'] ?? null;
        if (!is_array($list)) {
            return [400, ['code' => 0, 'msg' => 'list 必须是数组']];
        }
        if (count($list) > self::MAX_ITEMS) {
            return [413, ['code' => 0, 'msg' => '单批最多 ' . self::MAX_ITEMS . ' 条']];
        }
        $page = max(1, (int)($p['page'] ?? 1));
        $pagecount = max($page, (int)($p['pagecount'] ?? $page));

        $lock = @fopen(RUNTIME_PATH . 'nnzs_rsync_' . md5($res['cjflag']) . '.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $stat = self::import($res, $list, $page, $pagecount);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        self::mutate(function ($d) use ($code, $res, $stat, $page, $pagecount, $p) {
            $t = $d['tasks'][$code] ?? ['code' => $code, 'from_code' => $res['from_code'], 'name' => $res['name'],
                'cjurl' => $res['cjurl'], 'created' => time()];
            $s = $t['push'] ?? [];
            foreach (['received', 'inserted', 'updated', 'skipped', 'failed'] as $k) {
                $s[$k] = (int)($s[$k] ?? 0) + $stat[$k];
            }
            $s['batches'] = (int)($s['batches'] ?? 0) + 1;
            $s['last_page'] = $page;
            $s['pagecount'] = $pagecount;
            $s['total'] = (int)($p['total'] ?? 0);
            $s['last_time'] = time();
            if ($stat['errors']) {
                $s['last_error'] = mb_substr($stat['errors'][0]['msg'] . '：' . $stat['errors'][0]['vod_name'], 0, 200);
            }
            $t['push'] = $s;
            $t['updated'] = time();
            $d['tasks'][$code] = $t;
            return $d;
        });
        return [200, ['code' => 1, 'msg' => 'ok', 'data' => ['code' => $code, 'page' => $page] + $stat]];
    }

    /** 按 MacCMS 采集规则逐条入库 */
    private static function import(array $res, array $list, $page, $pagecount)
    {
        $c = $res['collect'];
        $param = [
            'ac' => 'cj', 'cjflag' => $res['cjflag'], 'cjurl' => $res['cjurl'], 'page' => $page,
            'opt' => (int)$c['collect_opt'], 'filter' => (int)$c['collect_filter'],
            'filter_from' => (string)$c['collect_filter_from'], 'filter_year' => (string)$c['collect_filter_year'],
            'sync_pic_opt' => (int)$c['collect_sync_pic_opt'],
        ];
        $bind = ConfigFile::read('bind');
        $model = model('Collect');
        $pageInfo = ['page' => $page, 'pagecount' => $pagecount, 'pagesize' => count($list), 'recordcount' => 0, 'url' => 'rsync'];
        $stat = ['received' => count($list), 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $okAdd = lang('model/collect/add_ok');
        foreach ($list as $v) {
            if (!is_array($v)) {
                $stat['failed']++;
                continue;
            }
            $v = self::normalize($v, $res['cjflag'], $bind);
            $name = is_scalar($v['vod_name'] ?? null) ? (string)$v['vod_name'] : '';
            try {
                $r = $model->vod_data($param, ['page' => $pageInfo, 'data' => [$v]], 0);
            } catch (\Throwable $e) {
                $r = ['code' => 1001, 'msg' => '入库异常：' . $e->getMessage()];
            }
            $msg = is_array($r) ? strip_tags((string)($r['msg'] ?? '')) : '';
            // vod_data(show=0) 对"无需更新/播放组相同"也返回 1001，按说明文字分类；带 err 或异常才算失败
            $isErr = !is_array($r) || stripos($msg, 'err') !== false || strpos($msg, '入库异常') === 0 || stripos($msg, 'failed') !== false
                || ((int)($r['code'] ?? 0) !== 1 && $msg === '');
            if ($isErr) {
                $stat['failed']++;
                if (count($stat['errors']) < 20) {
                    $stat['errors'][] = ['vod_name' => mb_substr($name, 0, 100), 'msg' => mb_substr($msg, 0, 200)];
                }
            } elseif (($r['action'] ?? '') === 'updated') {
                $stat['updated']++;
            } elseif (strpos($msg, $okAdd) !== false) {
                $stat['inserted']++;
            } elseif (preg_match('/无需更新|相同|跳过/u', $msg) && !preg_match('/更新ok|新增ok/u', $msg)) {
                $stat['skipped']++;
            } else {
                $stat['updated']++;
            }
        }
        return $stat;
    }

    /** 与 Collect::vod_json 相同的条目转换：分类绑定（cjflag_源分类ID）+ dl 播放组展开 */
    private static function normalize(array $v, $cjflag, array $bind)
    {
        $tid = isset($v['type_id']) && is_scalar($v['type_id']) ? (string)$v['type_id'] : '';
        $key = $cjflag . '_' . $tid;
        $v['type_id'] = isset($bind[$key]) && (int)$bind[$key] > 0 ? (int)$bind[$key] : 0;
        if (!empty($v['dl']) && is_array($v['dl'])) {
            $from = $url = $server = $note = [];
            foreach ($v['dl'] as $k2 => $v2) {
                $from[] = $k2;
                $url[] = is_scalar($v2) ? $v2 : '';
                $server[] = 'no';
                $note[] = '';
            }
            $v['vod_play_from'] = implode('$$$', $from);
            $v['vod_play_url'] = implode('$$$', $url);
            $v['vod_play_server'] = implode('$$$', $server);
            $v['vod_play_note'] = implode('$$$', $note);
        }
        unset($v['dl']);
        foreach ($v as $k => $x) {
            if (!is_scalar($x) && $x !== null) {
                unset($v[$k]);   // 嵌套结构不入库
            } elseif ($x === null) {
                $v[$k] = '';
            }
        }
        // 资源站接口通常返回完整字段；推送数据缺省的字段按"空值"补齐（与资源站返回空字段等价），
        // 避免采集模型读取未定义键（PHP 8.5 告警），不改变入库/更新规则。
        foreach (self::VOD_FIELDS as $f) {
            if (!array_key_exists($f, $v)) {
                $v[$f] = '';
            }
        }
        return $v;
    }
}
