<?php
namespace app\common\service\nnzs;

/**
 * 在线升级（契约：GET {base}/update/{code.macv10}.zip，响应头 X-Store-SHA256；无此版本 404 {code:-1,msg}；30 次/分钟）。
 *
 * 流程：download() 下载并按 X-Store-SHA256 校验后保存（附 .sha256 旁注）→ apply() 安全应用。
 *
 * 只接受为整合版（v2 结构）构建的升级包：zip 根目录必须有 nnzs_manifest.json：
 *   {"name":"nnzs","layout":"v2","version":"2.0.1","files":{"<相对路径>":"<sha256>", ...}}
 *   - files 必须与包内文件一一对应，逐个校验 sha256；
 *   - 路径只能落在牛牛助手自己的目录/文件（见 ALLOW_*），禁止 ..、绝对路径、反斜杠、符号链接；
 *   - 含 niuniuzs/ 目录或无清单 = 旧插件结构包，拒绝并提示。
 * 覆盖前把原文件备份到 application/data/backup/nnzs/upgrade-<时间>-<旧>-<新>/，任一步失败即回滚。
 */
class Upgrade
{
    const ALLOW_PREFIX = [
        'application/common/service/nnzs/',
        'application/admin/view/nnzs/',
        'application/data/nnzs/theme/',
    ];
    const ALLOW_FILE = [
        'application/admin/controller/Nnzs.php',
        'application/admin/controller/Auto.php',
        'application/api/controller/Auto.php',
        'docs/INSTALL-zh.md',
    ];
    const MAX_FILES = 2000;
    const MAX_BYTES = 52428800;

    const VERSION_RE = '/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]+)?$/';

    /** 是否有新版本：与旧插件一致，hostversion != code.macv10 即提示 */
    public static function check(?array $data = null)
    {
        $data = $data ?: Store::data();
        $remote = isset($data['macv10']) ? (string)$data['macv10'] : '';
        $local = (string)Config::get('version', Config::VERSION);
        $valid = $remote !== '' && preg_match(self::VERSION_RE, $remote);
        return ['local' => $local, 'remote' => $remote, 'valid' => (bool)$valid,
            'available' => $valid && $remote !== $local];
    }

    public static function dir()
    {
        return APP_PATH . 'data' . DIRECTORY_SEPARATOR . 'nnzs' . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR;
    }

    /** @return array ['code'=>1|0, 'msg'=>..., 'file'=>..., 'sha256'=>...] */
    public static function download($version)
    {
        $version = (string)$version;
        if (!preg_match(self::VERSION_RE, $version)) {
            return ['code' => 0, 'msg' => '商店返回的版本号不合法'];
        }
        $base = Config::apiUrl();
        if ($base === '') {
            return ['code' => 0, 'msg' => '未配置商店地址'];
        }
        $r = Http::send($base . '/update/' . rawurlencode($version) . '.zip', null, 120000);
        if (!$r['ok']) {
            return ['code' => 0, 'msg' => '下载失败：' . $r['error']];
        }
        if ($r['status'] !== 200) {
            $j = json_decode($r['body'], true);
            $msg = is_array($j) && isset($j['msg']) && is_scalar($j['msg']) ? (string)$j['msg'] : '';
            $hint = $r['status'] === 429 ? '请求过于频繁，请稍后再试' : ($r['status'] === 404 ? '商店没有该版本的升级包' : '');
            return ['code' => 0, 'msg' => 'HTTP ' . $r['status'] . ' ' . trim($hint . ' ' . mb_substr(strip_tags($msg), 0, 100))];
        }
        $want = strtolower(trim((string)($r['headers']['x-store-sha256'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $want)) {
            return ['code' => 0, 'msg' => '商店未提供 X-Store-SHA256，拒绝保存'];
        }
        $got = hash('sha256', $r['body']);
        if (!hash_equals($want, $got)) {
            Log::error('升级包 SHA256 不一致：' . $version . ' 期望 ' . $want . ' 实际 ' . $got);
            return ['code' => 0, 'msg' => '升级包 SHA256 校验失败，已丢弃'];
        }
        if (substr($r['body'], 0, 4) !== "PK\x03\x04") {
            return ['code' => 0, 'msg' => '升级包不是 zip 文件'];
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['code' => 0, 'msg' => '无法创建目录 ' . $dir];
        }
        $file = $dir . $version . '.zip';
        if (@file_put_contents($file, $r['body']) === false || @file_put_contents($file . '.sha256', $got) === false) {
            return ['code' => 0, 'msg' => '写入失败 ' . $file];
        }
        Log::info('已下载并校验升级包 ' . $version . ' sha256=' . $got);
        return ['code' => 1, 'msg' => '已下载并通过 SHA256 校验：' . str_replace(ROOT_PATH, '', $file) . '，可在概览页「应用升级」',
            'file' => $file, 'sha256' => $got];
    }

    /** 已下载且校验过的升级包 */
    public static function downloaded($version)
    {
        $version = (string)$version;
        if (!preg_match(self::VERSION_RE, $version)) {
            return false;
        }
        $f = self::dir() . $version . '.zip';
        return is_file($f) && is_file($f . '.sha256') ? $f : false;
    }

    public static function isAllowedPath($path)
    {
        $path = (string)$path;
        if ($path === '' || strlen($path) > 255 || strpos($path, "\0") !== false || strpos($path, '\\') !== false
            || $path[0] === '/' || preg_match('#^[A-Za-z]:#', $path) || preg_match('#(^|/)\.\.?(/|$)#', $path)
            || !preg_match('#^[A-Za-z0-9_./\-]+$#', $path) || substr($path, -1) === '/') {
            return false;
        }
        if (in_array($path, self::ALLOW_FILE, true)) {
            return true;
        }
        foreach (self::ALLOW_PREFIX as $p) {
            if (strpos($path, $p) === 0) {
                // 主题素材目录里不允许可执行脚本
                if ($p === 'application/data/nnzs/theme/' && preg_match('/\.(php\d?|phtml|phar|htaccess)$/i', $path)) {
                    return false;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * 校验升级包，返回 ['code'=>1,'manifest'=>..,'entries'=>[path=>index]] 或 ['code'=>0,'msg'=>..]
     */
    public static function inspect($zipFile)
    {
        if (!class_exists('ZipArchive')) {
            return ['code' => 0, 'msg' => '服务器缺少 PHP zip 扩展，无法应用升级包'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return ['code' => 0, 'msg' => '升级包无法打开'];
        }
        try {
            if ($zip->numFiles > self::MAX_FILES + 1) {
                return ['code' => 0, 'msg' => '升级包文件数过多'];
            }
            $entries = [];
            $legacy = false;
            $total = 0;
            $bad = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = (string)$st['name'];
                if (stripos($name, 'niuniuzs/') === 0) {
                    $legacy = true;
                }
                if (substr($name, -1) === '/') {
                    continue;   // 目录条目
                }
                $opsys = 0; $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX
                    && ((($attr >> 16) & 0170000) === 0120000)) {
                    $bad[] = $name . '（符号链接）';
                    continue;
                }
                $total += (int)$st['size'];
                $entries[$name] = $i;
            }
            if ($legacy || !isset($entries['nnzs_manifest.json'])) {
                return ['code' => 0, 'msg' => '这是旧版插件结构（niuniuzs/ 目录）的升级包，不适用于整合版牛牛助手；请向商店获取带 nnzs_manifest.json（layout=v2）的升级包，或手动更新'];
            }
            if ($total > self::MAX_BYTES) {
                return ['code' => 0, 'msg' => '升级包解压后过大'];
            }
            $m = json_decode((string)$zip->getFromIndex($entries['nnzs_manifest.json']), true);
            unset($entries['nnzs_manifest.json']);
            if (!is_array($m) || ($m['layout'] ?? '') !== 'v2' || ($m['name'] ?? '') !== 'nnzs'
                || !isset($m['version']) || !is_string($m['version']) || !preg_match(self::VERSION_RE, $m['version'])
                || !isset($m['files']) || !is_array($m['files']) || !$m['files']) {
                return ['code' => 0, 'msg' => 'nnzs_manifest.json 无效（需 name=nnzs、layout=v2、version、files）'];
            }
            foreach ($entries as $name => $i) {
                if (!self::isAllowedPath($name)) {
                    $bad[] = $name;
                } elseif (!isset($m['files'][$name])) {
                    $bad[] = $name . '（清单未列出）';
                }
            }
            foreach ($m['files'] as $name => $sha) {
                if (!isset($entries[$name])) {
                    $bad[] = $name . '（清单列出但包内缺失）';
                } elseif (!is_string($sha) || !hash_equals(strtolower($sha), hash('sha256', (string)$zip->getFromIndex($entries[$name])))) {
                    $bad[] = $name . '（sha256 不符）';
                }
            }
            if ($bad) {
                return ['code' => 0, 'msg' => '升级包包含不允许或校验失败的文件：' . implode('，', array_slice($bad, 0, 10))];
            }
            return ['code' => 1, 'manifest' => $m, 'entries' => $entries];
        } finally {
            $zip->close();
        }
    }

    /** 应用已下载的升级包：校验 → 备份 → 覆盖（失败回滚）→ 记录版本 */
    public static function apply($version)
    {
        $file = self::downloaded($version);
        if ($file === false) {
            return ['code' => 0, 'msg' => '请先下载并校验该版本升级包'];
        }
        $want = trim((string)@file_get_contents($file . '.sha256'));
        if (!preg_match('/^[0-9a-f]{64}$/', $want) || !hash_equals($want, (string)hash_file('sha256', $file))) {
            return ['code' => 0, 'msg' => '升级包与下载时的 SHA256 不一致，请重新下载'];
        }
        $chk = self::inspect($file);
        if ($chk['code'] !== 1) {
            return $chk;
        }
        $m = $chk['manifest'];
        if ($m['version'] !== (string)$version) {
            return ['code' => 0, 'msg' => '清单版本 ' . $m['version'] . ' 与商店版本 ' . $version . ' 不一致'];
        }
        $from = (string)Config::get('version', Config::VERSION);
        $bdir = APP_PATH . 'data/backup/nnzs/upgrade-' . date('Ymd-His') . '-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $from) . '-' . $m['version'] . '/';
        if (!@mkdir($bdir, 0755, true)) {
            return ['code' => 0, 'msg' => '无法创建备份目录'];
        }
        $zip = new \ZipArchive();
        $zip->open($file);
        $done = [];      // path => 'replaced'|'created'
        $root = rtrim(ROOT_PATH, '/') . '/';
        try {
            foreach ($chk['entries'] as $path => $i) {
                $dest = $root . $path;
                if (is_link($dest)) {
                    throw new \RuntimeException('目标是符号链接：' . $path);
                }
                if (is_file($dest)) {
                    $bk = $bdir . 'files/' . $path;
                    if (!is_dir(dirname($bk)) && !@mkdir(dirname($bk), 0755, true) || !@copy($dest, $bk)) {
                        throw new \RuntimeException('备份失败：' . $path);
                    }
                    $done[$path] = 'replaced';
                } else {
                    $done[$path] = 'created';
                }
                if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
                    throw new \RuntimeException('无法创建目录：' . dirname($path));
                }
                $tmp = $dest . '.nnzs-tmp';
                if (@file_put_contents($tmp, (string)$zip->getFromIndex($i)) === false || !@rename($tmp, $dest)) {
                    @unlink($tmp);
                    throw new \RuntimeException('写入失败：' . $path);
                }
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($dest, true);
                }
            }
        } catch (\Throwable $e) {
            foreach ($done as $path => $how) {
                $dest = $root . $path;
                if ($how === 'replaced') {
                    @copy($bdir . 'files/' . $path, $dest);
                } else {
                    @unlink($dest);
                }
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($dest, true);
                }
            }
            $zip->close();
            Log::error('升级 ' . $m['version'] . ' 失败已回滚：' . $e->getMessage());
            return ['code' => 0, 'msg' => '升级失败，已回滚：' . $e->getMessage()];
        }
        $zip->close();
        @file_put_contents($bdir . 'upgrade.json', json_encode(['from' => $from, 'to' => $m['version'], 'time' => date('c'),
            'files' => $done], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        Config::set(['version' => $m['version']]);
        Log::info('已应用升级 ' . $from . ' → ' . $m['version'] . '，' . count($done) . ' 个文件，备份 ' . $bdir);
        return ['code' => 1, 'msg' => '已升级到 ' . $m['version'] . '（' . count($done) . ' 个文件，原文件备份在 '
            . str_replace(ROOT_PATH, '', $bdir) . '）', 'backup' => $bdir, 'files' => $done];
    }
}
