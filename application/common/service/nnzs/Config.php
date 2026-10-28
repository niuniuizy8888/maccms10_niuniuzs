<?php
namespace app\common\service\nnzs;

/**
 * 牛牛助手配置。
 *
 * 配置统一存放在 application/extra/maccms.php 的 `nnzs` 段，替代旧的 niuniuzs/conf/{nnzs,conf}.php。
 * 兼容约定（对外接口 api.php/auto/* 依赖，不得改变）：
 *   - 运行口令 auth_key：环境变量 NNZS_AUTH_KEY（getenv / $_SERVER / $_ENV，去首尾空白）优先，其次配置；
 *   - auth_key 为空 = 未安装，对外接口一律拒绝；
 *   - 校验用 hash_equals，严格相等。
 * 旧站升级：首次读取时若 maccms.php 里尚无 auth_key，而旧 niuniuzs/conf/conf.php 存在，则自动迁移（只读旧文件，不删除）。
 */
class Config
{
    const DEFAULT_API_URL = 'https://api.niuniucms.cc';
    const VERSION = '2.0.0';

    /** @var array|null */
    private static $cache = null;

    public static function defaults()
    {
        return [
            'api_url'     => self::DEFAULT_API_URL,
            'version'     => self::VERSION,
            'auth_key'    => '',
            'siteid'      => '',
            'domian'      => '',   // 拼写沿用商店端登记字段名
            'http'        => 'http',
            'sitename'    => '',
            'serverip'    => '',
            'type'        => '',
            'installed_at'=> '',
            'registered_at' => '', // 商店 macinstall 成功时间；缺省会导致 Config::set 静默丢弃
            'report'      => '1',  // 后台手动初始化时是否向商店登记（保持原插件默认：登记）
            'cache_ttl'   => '1800',
            'schema'      => '0',
            'siteid_random' => '0',  // 本地升级迁移版本，见 upgradeSchema()
        ];
    }

    /** 全部配置（已与默认值合并，含旧配置迁移） */
    public static function all()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $maccms = self::readMaccms();
        $cur = isset($maccms['nnzs']) && is_array($maccms['nnzs']) ? $maccms['nnzs'] : [];
        $conf = array_merge(self::defaults(), $cur);
        if ((string)$conf['auth_key'] === '') {
            $migrated = self::migrateLegacy($conf);
            if ($migrated !== null) {
                $conf = $migrated;
            }
        }
        if ((int)$conf['schema'] < self::SCHEMA) {
            $conf = self::upgradeSchema($conf);
        }
        return self::$cache = $conf;
    }

    const SCHEMA = 2;

    /** 随机站点标识（32 位十六进制，与旧格式一致；绝不由管理员密码派生） */
    public static function newSiteId()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 已有站点的一次性迁移（schema 2）：
     *   - siteid 旧算法为 md5(管理员密码 . 域名)，改为随机值（只在本地替换，不重新向商店登记）；
     *   - 旧插件写入快捷菜单的「牛牛助手,auto/index」等条目删除（已改为左侧一级菜单，紧跟「首页」）。
     */
    private static function upgradeSchema(array $conf)
    {
        $vals = ['schema' => (string)self::SCHEMA];
        if ((string)$conf['auth_key'] !== '' && (string)($conf['siteid_random'] ?? '') !== '1') {
            $vals['siteid'] = self::newSiteId();
        }
        $vals['siteid_random'] = '1';
        $vals['__migrate'] = 1;
        try {
            self::cleanQuickmenu();
            $conf = self::set($vals);
            Log::info('牛牛助手本地迁移到 schema ' . self::SCHEMA . '（随机 siteid、快捷菜单清理）');
        } catch (\Throwable $e) {
            Log::error('牛牛助手本地迁移失败：' . $e->getMessage());
        }
        return $conf;
    }

    /** 删除快捷菜单里指向旧插件 / 牛牛助手的条目（extra/quickmenu.php 与 data/config/quickmenu.txt） */
    public static function cleanQuickmenu()
    {
        $hit = function ($line) {
            $parts = explode(',', trim((string)$line), 2);
            $url = strtolower(trim($parts[1] ?? ''));
            return preg_match('#^(admin/)?(auto|nnzs)/#', $url) || strpos($url, 'niuniuzs/') !== false;
        };
        $q = ConfigFile::read('quickmenu');
        if ($q) {
            $new = array_values(array_filter($q, function ($l) use ($hit) { return !is_string($l) || !$hit($l); }));
            if (count($new) !== count($q)) {
                ConfigFile::write('quickmenu', $new);
            }
        }
        $txt = APP_PATH . 'data/config/quickmenu.txt';
        if (is_file($txt) && is_writable($txt)) {
            $lines = preg_split('/\r\n|\r|\n/', (string)file_get_contents($txt));
            $keep = array_filter($lines, function ($l) use ($hit) { return !$hit($l); });
            if (count($keep) !== count($lines)) {
                file_put_contents($txt, implode(chr(13), $keep));
            }
        }
    }

    public static function get($key, $default = '')
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** 合并写入 nnzs 段（只允许已知键），立即生效 */
    public static function set(array $values)
    {
        $lock = @fopen(RUNTIME_PATH . 'nnzs_config.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            return self::setLocked($values);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function setLocked(array $values)
    {
        $maccms = self::readMaccms();
        if (!isset($maccms['site'])) {
            throw new \RuntimeException('maccms.php 读取失败，放弃写入');   // 防把空配置写回
        }
        $cur0 = isset($maccms['nnzs']) && is_array($maccms['nnzs']) ? $maccms['nnzs'] : [];
        // 迁移并发：另一个请求已完成随机 siteid 迁移时，不再二次替换 siteid
        if (isset($values['siteid']) && !empty($values['__migrate']) && (string)($cur0['siteid_random'] ?? '') === '1') {
            unset($values['siteid']);
        }
        unset($values['__migrate']);
        $cur = isset($maccms['nnzs']) && is_array($maccms['nnzs']) ? $maccms['nnzs'] : [];
        $conf = array_merge(self::defaults(), $cur);
        foreach ($values as $k => $v) {
            if (array_key_exists($k, $conf) && is_scalar($v)) {
                $conf[$k] = (string)$v;
            }
        }
        $maccms['nnzs'] = $conf;
        mac_arr2file(APP_PATH . 'extra/maccms.php', $maccms);
        \think\Config::set('maccms', $maccms);
        if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
            $GLOBALS['config']['nnzs'] = $conf;
        }
        self::$cache = null;
        return $conf;
    }

    public static function reset()
    {
        self::$cache = null;
    }

    /** 商店地址（无尾斜杠）；为空表示未配置 */
    public static function apiUrl()
    {
        $url = trim((string)self::get('api_url', ''));
        return $url === '' ? '' : rtrim($url, '/');
    }

    /** 运行口令：环境变量优先（与旧版 nnzs_auth_key() 完全一致） */
    public static function authKey()
    {
        $env = getenv('NNZS_AUTH_KEY');
        if (!is_string($env) || $env === '') {
            if (isset($_SERVER['NNZS_AUTH_KEY']) && is_scalar($_SERVER['NNZS_AUTH_KEY'])) {
                $env = (string)$_SERVER['NNZS_AUTH_KEY'];
            } elseif (isset($_ENV['NNZS_AUTH_KEY']) && is_scalar($_ENV['NNZS_AUTH_KEY'])) {
                $env = (string)$_ENV['NNZS_AUTH_KEY'];
            } else {
                $env = '';
            }
        }
        $env = trim($env);
        if ($env !== '') {
            return $env;
        }
        $key = self::get('auth_key', '');
        return is_scalar($key) ? (string)$key : '';
    }

    public static function isInstalled()
    {
        return self::authKey() !== '';
    }

    public static function checkToken($token)
    {
        $key = self::authKey();
        if ($key === '' || !is_scalar($token)) {
            return false;
        }
        return hash_equals($key, (string)$token);
    }

    /** 读取 maccms.php（绕开请求内的 config 缓存，避免同请求内写后读到旧值） */
    private static function readMaccms()
    {
        $file = APP_PATH . 'extra/maccms.php';
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        $arr = is_file($file) ? include $file : [];
        return is_array($arr) ? $arr : [];
    }

    /**
     * 旧版配置迁移：niuniuzs/conf/conf.php（auth_key 等）+ niuniuzs/conf/nnzs.php（商店地址）。
     * 只在 maccms.php 里尚无 auth_key 时执行；成功返回新配置，否则返回 null。
     */
    private static function migrateLegacy(array $conf)
    {
        $dir = ROOT_PATH . 'niuniuzs/conf/';
        $legacy = $dir . 'conf.php';
        if (!is_file($legacy)) {
            return null;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($legacy, true);
        }
        $old = @include $legacy;
        if (!is_array($old) || empty($old['auth_key']) || !is_scalar($old['auth_key'])) {
            return null;
        }
        $vals = [];
        foreach (['auth_key', 'domian', 'http', 'sitename', 'serverip', 'type'] as $k) {
            if (isset($old[$k]) && is_scalar($old[$k])) {
                $vals[$k] = (string)$old[$k];
            }
        }
        // 旧 siteid = md5(管理员密码 . 域名)，不迁移，改为随机值
        $vals['siteid'] = self::newSiteId();
        $vals['siteid_random'] = '1';
        if (is_file($dir . 'nnzs.php')) {
            $n = @include $dir . 'nnzs.php';
            if (is_array($n) && !empty($n['url']) && is_string($n['url'])) {
                $vals['api_url'] = rtrim($n['url'], '/');
            }
        }
        $vals['installed_at'] = date('Y-m-d H:i:s');
        try {
            $new = self::set($vals);
            Log::info('已从 niuniuzs/conf/conf.php 迁移配置到 maccms.php 的 nnzs 段');
            return $new;
        } catch (\Throwable $e) {
            Log::error('迁移旧配置失败：' . $e->getMessage());
            return null;
        }
    }
}
