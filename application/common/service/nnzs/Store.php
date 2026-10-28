<?php
namespace app\common\service\nnzs;

/**
 * 商店端（api.niuniucms.cc，站长自有服务）数据访问。
 *
 * 契约（站长提供，）：
 * GET {base}?macapi → 顶层 JSON（无 {code,data} 包装），14 个键：apilist, apijsonlist, imglist, imgad, replist,
 * hb{tophb,newhb,randhb:[{tid,image_url,dbid}]}, nva, restype, info, img, play, type, news,
 * code{..., site_num, macv10=在线升级版本}；所有值均为字符串。
 * 10 次/秒/IP，超限 429 {code:-1,msg}；带 ETag/Cache-Control 60s，If-None-Match 命中返回 304。
 * POST {base}?macinstall → 表单 auth_key, siteid, domian(拼写如此,主机名), http, sitename, serverip, type=install,
 * 可选 php_version/cms_version（≤20 字符）；返回 {"code":0,"msg":"ok"}，非 POST 405，参数错 400
 * 6 次/小时。插件只等 500ms、不读响应体（发出即走）。
 * GET {code.web_site 或 base}update/{macv10}.zip → zip + X-Store-SHA256；无此版本 404 {code:-1,msg}；30 次/分钟。
 * 不要向 {base}?hb 发送 page/kw/list（会返回 HTML）；本模块不使用 ?hb。
 */
class Store
{
    /** 登记需等商店落库 installs(status=1,origin=macplugin)，过短会导致「未启用采集」 */
    const REPORT_TIMEOUT_MS = 8000;

    /** 最近一次拉取失败原因（成功或命中缓存时为空），供后台提示 */
    public static $lastError = '';

    public static function cacheKey()
    {
        return 'apicache_' . md5(Config::apiUrl());
    }

    /** 长期保留的上一份有效数据 + ETag：商店 429/5xx/超时时兜底，304 时直接复用 */
    private static function staleKey()
    {
        return 'apicache_stale_' . md5(Config::apiUrl());
    }

    /** {base}?xxx —— base 为 http://host[:port][/path]，不需要 index.php 前缀 */
    public static function endpoint($query)
    {
        $url = Config::apiUrl();
        return $url === '' ? '' : $url . '/?' . $query;
    }

    /** 是否为有效的 ?macapi 数据（错误响应 {code:-1,msg} 的 code 不是对象） */
    public static function valid($data)
    {
        return is_array($data) && (is_array($data['code'] ?? null) || is_array($data['apilist'] ?? null));
    }

    /** 原始数据（带缓存）；商店不可用且无历史数据时返回空数组 */
    public static function raw($refresh = false)
    {
        $url = Config::apiUrl();
        if ($url === '') {
            return [];
        }
        $key = self::cacheKey();
        if (!$refresh) {
            $cached = \think\Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $stale = \think\Cache::get(self::staleKey());
        $stale = is_array($stale) && isset($stale['data']) && self::valid($stale['data']) ? $stale : null;
        $headers = ['Accept: application/json'];
        if ($stale && !empty($stale['etag'])) {
            $headers[] = 'If-None-Match: ' . $stale['etag'];
        }
        $ttl = max(60, (int)Config::get('cache_ttl', 1800));
        self::$lastError = '';
        $r = Http::send(self::endpoint('macapi'), null, 30000, $headers);
        if ($r['ok'] && $r['status'] === 304 && $stale) {
            \think\Cache::set($key, $stale['data'], $ttl);
            return $stale['data'];
        }
        $data = null;
        if ($r['ok'] && $r['status'] === 200) {
            $data = json_decode(trim($r['body'], "\xEF\xBB\xBF \r\n\t"), true);
        }
        if (!self::valid($data)) {
            $why = !$r['ok'] ? $r['error'] : ('HTTP ' . $r['status'] . ($r['status'] === 429 ? '（限流）' : ''));
            self::$lastError = $why;
            Log::info('拉取商店数据失败：' . $why . ($stale ? '，使用上一份数据' : ''));
            if ($stale) {
                // 短暂缓存兜底数据，避免限流期间每个页面都再打一次商店
                \think\Cache::set($key, $stale['data'], 60);
                return $stale['data'];
            }
            // 商店不可达且无兜底数据：短暂负缓存，避免每个后台页/入站推送都卡满 30s 超时
            \think\Cache::set($key, [], 60);
            return [];
        }
        \think\Cache::set($key, $data, $ttl);
        \think\Cache::set(self::staleKey(), ['data' => $data, 'etag' => (string)($r['headers']['etag'] ?? '')], 86400 * 30);
        return $data;
    }

    public static function clearCache()
    {
        \think\Cache::rm(self::cacheKey());
    }

    /** 规范化后的视图数据（键名沿用旧插件 apidata()） */
    public static function data($refresh = false)
    {
        $a = self::raw($refresh);
        $pick = function ($k, $def = []) use ($a) {
            return isset($a[$k]) ? $a[$k] : $def;
        };
        return [
            'info'      => (string)(is_scalar($pick('info', '')) ? $pick('info', '') : ''),
            'version'   => is_array($pick('code')) ? $pick('code') : [],
            'code'      => is_array($pick('code')) ? $pick('code') : [],
            'imglist'   => self::rows($pick('imglist')),
            'apilist'   => self::rows($pick('apilist')),
            'apijsonlist' => self::rows($pick('apijsonlist')),
            'replist'   => self::rows($pick('replist')),
            'imgadlist' => self::rows($pick('imgad')),
            'nvalist'   => self::rows($pick('nva')),
            'topinfo'   => is_scalar($pick('topinfo', '')) ? (string)$pick('topinfo', '') : '',   // 契约外的旧字段，可缺省
            'macv10'    => isset($a['code']['macv10']) && is_scalar($a['code']['macv10']) ? (string)$a['code']['macv10'] : '',
            'type'      => is_array($pick('type')) ? $pick('type') : [],
            'restype'   => is_array($pick('restype')) ? $pick('restype') : [],
            'news'      => NewsSource::normalize(is_array($pick('news')) ? $pick('news') : []),
            'hb'        => is_array($pick('hb')) ? $pick('hb') : [],
            'online'    => !empty($a),
        ];
    }

    /** 根据 apiinfo 找资源站条目 */
    public static function findApi($apiinfo, ?array $data = null)
    {
        $data = $data ?: self::data();
        foreach ($data['apilist'] as $row) {
            if (isset($row['apiinfo']) && (string)$row['apiinfo'] === (string)$apiinfo) {
                return $row;
            }
        }
        return null;
    }

    /**
     * 安装登记：写入助手服务端 installs（origin=macplugin, status=1）。
     * @return array{ok:bool,msg:string,status:int,rate_limited?:bool}
     */
    public static function report(array $payload)
    {
        $url = self::endpoint('macinstall');
        if ($url === '') {
            return ['ok' => false, 'msg' => '未配置助手服务端地址', 'status' => 0];
        }
        $form = [];
        foreach (['auth_key', 'siteid', 'domian', 'http', 'sitename', 'serverip', 'type'] as $k) {
            $form[$k] = isset($payload[$k]) ? (string)$payload[$k] : '';
        }
        $form['domian'] = self::hostname($form['domian']);
        $form['http'] = $form['http'] === 'https' ? 'https' : 'http';
        $form['type'] = 'install';
        // 告知商店 api 入口名（默认 api.php）；worker 改名入口时可拼对推送 URL
        $apiEntry = ltrim((string)Cron::apiEntry(), '/');
        if ($apiEntry !== '' && preg_match('/^[A-Za-z0-9_]+\.php$/', $apiEntry)) {
            $form['api_entry'] = $apiEntry;
        }
        $form['php_version'] = self::shortVer(PHP_VERSION);
        $ver = @include APP_PATH . 'extra/version.php';
        $cms = self::shortVer(is_array($ver) && isset($ver['code']) ? $ver['code'] : '');
        if ($cms !== '') {
            $form['cms_version'] = $cms;
        }
        $r = Http::send($url, http_build_query($form), self::REPORT_TIMEOUT_MS, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        if (!$r['ok']) {
            Log::info('助手服务端登记失败：' . $r['error']);
            return ['ok' => false, 'msg' => '连接助手服务端失败：' . $r['error'], 'status' => 0];
        }
        $j = json_decode(trim((string)$r['body'], "\xEF\xBB\xBF \r\n\t"), true);
        if ($r['status'] === 429 || (is_array($j) && isset($j['msg']) && stripos((string)$j['msg'], 'rate') !== false)) {
            Log::info('助手服务端登记限流（macinstall 约 6 次/小时）');
            return ['ok' => false, 'msg' => '助手服务端登记限流（约每小时 6 次），请稍后再试', 'status' => 429, 'rate_limited' => true];
        }
        if ($r['status'] >= 400) {
            $msg = is_array($j) && isset($j['msg']) ? (string)$j['msg'] : ('HTTP ' . $r['status']);
            Log::info('助手服务端登记被拒：' . $msg);
            return ['ok' => false, 'msg' => '助手服务端拒绝：' . $msg, 'status' => $r['status']];
        }
        if (is_array($j) && isset($j['code']) && (int)$j['code'] !== 0) {
            $msg = (string)($j['msg'] ?? 'unknown');
            Log::info('助手服务端登记返回错误：' . $msg);
            return ['ok' => false, 'msg' => '助手服务端返回：' . $msg, 'status' => $r['status']];
        }
        return ['ok' => true, 'msg' => 'ok', 'status' => $r['status']];
    }

    /** 本地登记状态（不打网络，避免每次打开页面触发 macinstall 限流） */
    public static function registrationStatus()
    {
        if (Config::authKey() === '') {
            return ['ok' => false, 'msg' => '未初始化'];
        }
        $at = (string)Config::get('registered_at', '');
        $host = (string)Config::get('domian', '');
        if ($at !== '' && $host !== '') {
            return ['ok' => true, 'msg' => '已登记'];
        }
        return ['ok' => false, 'msg' => '未登记，请点击「补登记」'];
    }

    /**
     * 向助手服务端补登记（修正缺端口域名 / 恢复 status=1）。
     * $force=false 时若本地已登记且域名未变则跳过网络请求。
     * @return array{ok:bool,msg:string}
     */
    public static function ensureRegistered($force = false)
    {
        $key = Config::authKey();
        if ($key === '') {
            return ['ok' => false, 'msg' => '未初始化（无 auth_key）'];
        }
        $host = (string)Config::get('domian', '');
        $http = (string)Config::get('http', 'http');
        $reqHost = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
        $hostChanged = false;
        if ($reqHost !== '') {
            $want = self::hostname($reqHost);
            if ($want !== '' && ($host === '' || $host !== $want)) {
                $host = $want;
                $hostChanged = true;
                $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
                Config::set(['domian' => $host, 'http' => $https ? 'https' : $http]);
                $http = (string)Config::get('http', $http);
            }
        }
        if ($host === '') {
            return ['ok' => false, 'msg' => '缺少登记域名'];
        }
        $had = (string)Config::get('registered_at', '') !== '';
        if (!$force && !$hostChanged && $had) {
            return ['ok' => true, 'msg' => '已登记'];
        }
        $r = self::report([
            'auth_key' => $key,
            'siteid'   => (string)Config::get('siteid', ''),
            'domian'   => $host,
            'http'     => $http,
            'sitename' => (string)Config::get('sitename', ''),
            'serverip' => (string)Config::get('serverip', ''),
            'type'     => 'install',
        ]);
        if (!empty($r['ok'])) {
            Config::set(['registered_at' => date('Y-m-d H:i:s'), 'domian' => $host]);
            return ['ok' => true, 'msg' => '已向助手服务端登记（异步采集已启用）'];
        }
        // 限流时：若曾经登记成功，视为仍有效，避免误报失败
        if (!empty($r['rate_limited']) && $had) {
            return ['ok' => true, 'msg' => '已登记（助手服务端限流，跳过重复登记）'];
        }
        return ['ok' => false, 'msg' => (string)($r['msg'] ?? '助手服务端登记失败，请稍后重试')];
    }

    /** 公告/固定文案：解码实体后走标签+属性白名单，去掉事件属性和非 http(s) 链接 */
    public static function richHtml($html)
    {
        $s = (string)$html;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!function_exists('mac_clean_html')) {
            return htmlspecialchars(strip_tags($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $s = mac_clean_html($s, 'a,b,strong,em,i,br,p,span,u');
        return self::richHtmlAnchors($s);
    }

    /** 公告里的链接只保留 http(s)，并强制 rel/target；其余 <a> 降级为 <span> */
    private static function richHtmlAnchors($html)
    {
        $html = (string)$html;
        if ($html === '' || !class_exists('DOMDocument')) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="UTF-8"><div id="nnzs-rich-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return '';
        }
        $root = null;
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'nnzs-rich-root') {
                $root = $div;
                break;
            }
        }
        if (!$root) {
            return '';
        }
        $anchors = [];
        foreach ($root->getElementsByTagName('a') as $a) {
            $anchors[] = $a;
        }
        foreach ($anchors as $a) {
            $href = trim(html_entity_decode($a->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            while ($a->attributes && $a->attributes->length) {
                $a->removeAttribute($a->attributes->item(0)->name);
            }
            $safeHttp = ($href !== ''
                && preg_match('#^https?://#i', $href)
                && !preg_match('/[\x00-\x20]/', $href));
            if (!$safeHttp) {
                $span = $doc->createElement('span');
                while ($a->firstChild) {
                    $span->appendChild($a->firstChild);
                }
                if ($a->parentNode) {
                    $a->parentNode->replaceChild($span, $a);
                }
                continue;
            }
            $a->setAttribute('href', $href);
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener noreferrer nofollow');
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /**
     * domian：主机名；保留非 80/443 端口（Go worker 回调需要）。
     * IPv6 方括号仅用于解析，落库为 addr 或 addr:port。
     */
    public static function hostname($host)
    {
        $host = strtolower(trim((string)$host));
        if ($host === '') {
            return '';
        }
        if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d+))?$/', $host, $m)) {
            $port = isset($m[2]) ? (int)$m[2] : 0;
            return ($port > 0 && !in_array($port, [80, 443], true)) ? ($m[1] . ':' . $port) : $m[1];
        }
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            $port = (int)$m[2];
            return ($port > 0 && !in_array($port, [80, 443], true)) ? ($m[1] . ':' . $port) : $m[1];
        }
        return $host;
    }

    private static function shortVer($v)
    {
        return substr(preg_replace('/[^A-Za-z0-9.\-+_]/', '', (string)$v), 0, 20);
    }

    private static function rows($v)
    {
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter($v, 'is_array'));
    }
}
