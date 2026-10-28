<?php
namespace app\common\service\nnzs;

/**
 * 访问商店端的 HTTP 客户端（替代 func.php 的 mac_curl_gets / https_request）。
 * 一律经 mac_safe_url() 做 SSRF 校验，校验证书，仅 http/https，限制重定向次数。
 */
class Http
{
    const MAX_BODY = 62914560;   // 60MB（升级包上限 50MB）

    /** 解析并校验全部 A 记录为公网后返回 CURLOPT_RESOLVE 条目；IP 直连或无法解析返回 null（交由 mac_safe_url 结果） */
    private static function pin($url)
    {
        $u = parse_url($url);
        $host = isset($u['host']) ? trim($u['host'], '[]') : '';
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        $ips = @gethostbynamel($host);
        if (!$ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (function_exists('mac_ip_is_public') && !mac_ip_is_public($ip)) {
                return false;
            }
        }
        $port = isset($u['port']) ? (int)$u['port'] : (strtolower($u['scheme'] ?? '') === 'https' ? 443 : 80);
        return $host . ':' . $port . ':' . $ips[0];
    }
    public static function get($url, $timeout = 60)
    {
        $r = self::send($url, null, $timeout * 1000);
        return $r['ok'] ? $r['body'] : false;
    }

    public static function post($url, array $data, $timeout = 15)
    {
        $r = self::send($url, http_build_query($data), $timeout * 1000);
        return $r['ok'] ? $r['body'] : false;
    }

    /**
     * 完整请求：返回 ['ok'=>bool(传输成功), 'status'=>int, 'body'=>string, 'headers'=>[小写名=>值], 'error'=>string]
     * $timeoutMs 毫秒；$headers 形如 ['If-None-Match: "x"']
     */
    public static function send($url, $body = null, $timeoutMs = 60000, array $headers = [], $hops = 0)
    {
        $fail = ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'error' => ''];
        $url = (string)$url;
        if ($url === '' || !function_exists('mac_safe_url') || (!mac_safe_url($url) && !self::isStoreHost($url))) {
            Log::info('拒绝非安全 URL 的请求: ' . $url);
            return ['error' => 'unsafe-url'] + $fail;
        }
        $ch = curl_init();
        if ($ch === false) {
            return ['error' => 'curl-init'] + $fail;
        }
        $timeoutMs = max(100, (int)$timeoutMs);
        $hdr = [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   // 重定向手动跟随，每一跳都重新做 SSRF 校验
            CURLOPT_CONNECTTIMEOUT_MS => min(10000, $timeoutMs),
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $hdr[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'MacCMS-nnzs/' . Config::VERSION,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        // 防 DNS rebinding：把通过校验的公网 IP 钉住，curl 不再二次解析
        if (!self::isStoreHost($url)) {
            $pin = self::pin($url);
            if ($pin === false) {
                curl_close($ch);
                return ['error' => 'unsafe-url'] + $fail;
            }
            if ($pin) {
                curl_setopt($ch, CURLOPT_RESOLVE, [$pin]);
            }
        }
        // 响应体上限，防商店/重定向目标返回超大内容耗尽内存
        $buf = '';
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $chunk) use (&$buf) {
            if (strlen($buf) + strlen($chunk) > self::MAX_BODY) {
                return 0;   // 中止
            }
            $buf .= $chunk;
            return strlen($chunk);
        });
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        if ($res !== false) {
            $res = $buf;
        }
        if ($res === false) {
            $err = curl_error($ch);
            Log::info('请求失败 ' . $url . ' -> ' . $err);
            return ['error' => $err] + $fail;
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if ($code >= 300 && $code < 400 && $loc !== '') {
            if ($hops >= 3) {
                Log::info('重定向次数过多: ' . $url);
                return ['error' => 'too-many-redirects'] + $fail;
            }
            // 307/308 保留方法与请求体，其余按浏览器习惯改为 GET
            $keep = in_array($code, [307, 308], true);
            return self::send($loc, $keep ? $body : null, $timeoutMs, $headers, $hops + 1);
        }
        return ['ok' => true, 'status' => $code, 'body' => (string)$res, 'headers' => $hdr, 'error' => ''];
    }

    /**
     * 站长在后台配置的商店地址本身可以是内网地址：
     * 仅对 scheme+host+port 与配置的商店地址完全一致的 URL 放行内网校验；其它地址与重定向目标仍走 mac_safe_url。
     */
    private static function isStoreHost($url)
    {
        $base = Config::apiUrl();
        if ($base === '') {
            return false;
        }
        $a = parse_url($url);
        $b = parse_url($base);
        if (!is_array($a) || !is_array($b) || empty($a['host']) || empty($b['host'])) {
            return false;
        }
        $sa = strtolower($a['scheme'] ?? '');
        if (!in_array($sa, ['http', 'https'], true) || isset($a['user']) || isset($a['pass'])) {
            return false;
        }
        $port = function ($u, $s) { return isset($u['port']) ? (int)$u['port'] : ($s === 'https' ? 443 : 80); };
        $sb = strtolower($b['scheme'] ?? '');
        return $sa === $sb && strtolower($a['host']) === strtolower($b['host']) && $port($a, $sa) === $port($b, $sb);
    }
}
