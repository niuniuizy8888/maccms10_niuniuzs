<?php
namespace app\common\service\nnzs;

/** Default news provider; never reuse category IDs from the retired provider. */
class NewsSource
{
    const DEFAULT_API = ''; // 正式资讯接口由商店配置，不内置测试源。

    public static function isLegacy($url)
    {
        if (!is_string($url)) { return false; }
        $parts = parse_url((string)$url);
        return is_array($parts) && strtolower($parts['host'] ?? '') === 'news.niuniuzs.com'
            && rtrim($parts['path'] ?? '', '/') === '/api.php/provide/art';
    }

    public static function normalize(array $news)
    {
        if (empty($news['api']) || self::isLegacy($news['api'])) {
            // Old cached article IDs and categories belong to a different database.
            return ['api' => self::DEFAULT_API];
        }
        return $news;
    }

    public static function metadata(array $response, $api = '')
    {
        if ((int)($response['code'] ?? 0) !== 1 || empty($response['class']) || !is_array($response['class'])) {
            throw new \RuntimeException('资讯接口未返回有效分类，未更改采集配置');
        }
        $tree = [0 => []];
        foreach ($response['class'] as $type) {
            if (!is_array($type) || !isset($type['type_id'], $type['type_name'])
                || !is_numeric($type['type_id']) || !is_string($type['type_name']) || trim($type['type_name']) === ''
                || (isset($type['type_pid']) && !is_numeric($type['type_pid']))) {
                throw new \RuntimeException('资讯接口分类格式错误，未更改采集配置');
            }
            $tree[(int)($type['type_pid'] ?? 0)][] = $type;
        }
        if (!$tree[0]) { throw new \RuntimeException('资讯接口缺少顶级分类'); }
        return ['api' => $api, 'type' => $response['class'], 'newstype' => $tree];
    }

    public static function load(array $news)
    {
        $news = self::normalize($news);
        $api = (string)($news['api'] ?? '');
        if ($api === '') {
            throw new \RuntimeException('尚未配置正式资讯接口，请配置后再启用资讯采集');
        }
        $r = Http::send($api . (strpos($api, '?') === false ? '?' : '&') . 'ac=list', null, 15000);
        if (!$r['ok'] || $r['status'] !== 200) {
            throw new \RuntimeException('资讯接口连接失败：' . ($r['error'] ?: 'HTTP ' . $r['status']));
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) { throw new \RuntimeException('资讯接口返回的不是有效 JSON'); }
        return self::metadata($data, $api);
    }
}
