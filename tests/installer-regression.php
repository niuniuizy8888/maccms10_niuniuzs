<?php
// Isolated installer behavior checks: no site configuration or database is loaded.
namespace app\common\service\nnzs {
    class Config {
        static $saved = [];
        static function isInstalled() { return !empty(self::$saved['auth_key']); }
        static function newSiteId() { return 'test-site'; }
        static function apiUrl() { return 'https://example.test'; }
        static function set($data) { self::$saved = array_merge(self::$saved, $data); }
    }
    class Store {
        static $calls = 0;
        static $reports = 0;
        static $ok = true;
        static function report($data) { self::$reports++; return ['ok' => self::$ok, 'msg' => 'test']; }
        static function ensureRegistered($force) { return ['ok' => self::$ok, 'msg' => 'test']; }
        static $data = ['type' => ['v' => [['type_name' => 'Movies']]]];
        static function clearCache() {}
        static function data() { self::$calls++; return self::$data; }
        static function hostname($host) { return $host; }
    }
    class TypeImporter {
        static $calls = 0;
        static function import($data, $mid) { self::$calls++; }
    }
    class Group {
        static $calls = 0;
        static function grantAll() { self::$calls++; }
    }
    class Rsync { static $calls = 0; static function heartbeat() { self::$calls++; } }
    // Resource and Cron intentionally absent: any unintended initialization fails.
    class Log { static function info($message) {} }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
    function mac_get_rndstr($length) { return str_repeat('a', $length); }
    function model($name) { return new class { function setCache() {} }; }
    require dirname(__DIR__) . '/application/common/service/nnzs/Installer.php';
    use app\common\service\nnzs\{Installer, Config, Store, TypeImporter, Group, Rsync};
    $checks = 0;
    function check($value) { global $checks; if (!$value) { throw new RuntimeException('Failed check ' . ($checks + 1)); } $checks++; }
    check(Installer::runDefault(false)['code'] === 1);
    check(Config::isInstalled());
    check(Store::$calls === 0);
    check(TypeImporter::$calls === 0 && Group::$calls === 0);
    Config::$saved = [];
    check(Installer::runDefault(true)['code'] === 1);
    check(Config::isInstalled());
    check(Store::$calls === 1);
    check(TypeImporter::$calls === 1 && Group::$calls === 1);
    Config::$saved = []; Store::$data = [];
    check(Installer::runDefault(true)['code'] === 0);
    check(!Config::isInstalled());
    check(Installer::runDefault(false)['code'] === 1);
    check(Store::$reports === 3 && Rsync::$calls === 3);
    Config::$saved = []; Store::$ok = false;
    $r = Installer::runDefault(false);
    check($r['code'] === 1 && $r['registration']['ok'] === false);
    check(Rsync::$calls === 3);
    echo "PASS: $checks installer regression checks\n";
}
