<?php
namespace app\common\service;

use think\Cache;

class AjaxSecurity
{
    /** Only list-card metadata is public; access controls, bodies and source URLs are not. */
    public static function publicRow(array $row, $prefix)
    {
        $keys = ['type_id', 'type_id_1'];
        foreach (['id','name','en','sub','name_trad','color','letter','pic','pic_thumb','pic_slide',
            'blurb','remarks','time','time_add','hits','hits_day','hits_week','hits_month',
            'up','down','score','score_num','score_all','class','area','lang','year','actor',
            'director','writer','version','state','serial','total','weekday','duration',
            'pubdate','tag','douban_id','douban_score','gender','birthday','birtharea'] as $suffix) {
            $keys[] = $prefix . '_' . $suffix;
        }
        return array_intersect_key($row, array_flip($keys));
    }

    public static function sameOrigin($url, $origin)
    {
        if (!is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $a = parse_url($url); $b = parse_url($origin);
        if (!$a || !$b || isset($a['user']) || isset($a['pass'])) { return false; }
        $scheme = strtolower($a['scheme'] ?? '');
        return in_array($scheme, ['http', 'https'], true)
            && $scheme === strtolower($b['scheme'] ?? '')
            && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
            && ($a['port'] ?? ($scheme === 'https' ? 443 : 80)) === ($b['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    public static function shortcut($name, $url, $origin)
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!self::sameOrigin($url, $origin)) { return null; }
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/[\x00-\x1f\x7f\\\\\/:*?"<>|]/u', '', $name);
        $name = mb_substr(trim($name, " ."), 0, 80, 'UTF-8');
        return ['name' => ($name === '' ? 'website' : $name) . '.url',
            'body' => "[InternetShortcut]\r\nURL=" . $url . "\r\n"];
    }

    public static function refererDomain($url, $domain)
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || !filter_var($url, FILTER_VALIDATE_URL)) { return false; }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) { return false; }
        $authority = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $domain !== '' && strtolower($domain) === $authority;
    }

    /** Shared across sessions, with a bounded set of locks for the file cache driver. */
    public static function allowAttempt($scope, $limit = 30, $seconds = 300)
    {
        $key = 'ajax-attempt-' . hash('sha256', mac_get_client_ip() . '|' . $scope);
        $lock = fopen(RUNTIME_PATH . 'ajax-rate-' . (hexdec(substr(hash('sha256', $key), 0, 2)) % 32) . '.lock', 'c');
        if (!$lock) { return false; }
        try {
            if (!flock($lock, LOCK_EX)) { return false; }
            $now = time();
            $state = Cache::get($key);
            if (!is_array($state) || ($state['until'] ?? 0) <= $now) { $state = ['count' => 0, 'until' => $now + $seconds]; }
            if ($state['count'] >= $limit) { return false; }
            $state['count']++;
            return Cache::set($key, $state, max(1, $state['until'] - $now));
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
