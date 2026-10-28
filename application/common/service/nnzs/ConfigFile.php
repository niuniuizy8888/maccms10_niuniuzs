<?php
namespace app\common\service\nnzs;

/**
 * application/extra/*.php 数组配置的读写（绕开请求内 config() 缓存，写后立即可读）。
 * 旧插件用 config('timming') 读、mac_arr2file 写，同一请求里连续写多项时后写覆盖先写（只剩最后一项）。
 */
class ConfigFile
{
    public static function read($name)
    {
        $file = self::path($name);
        if (!is_file($file)) {
            return [];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        $arr = include $file;
        return is_array($arr) ? $arr : [];
    }

    public static function write($name, array $arr)
    {
        mac_arr2file(self::path($name), $arr);
        \think\Config::set($name, $arr);
        return true;
    }

    private static function path($name)
    {
        if (!preg_match('/^[a-z_]+$/', $name)) {
            throw new \InvalidArgumentException('bad config name');
        }
        return APP_PATH . 'extra/' . $name . '.php';
    }
}
