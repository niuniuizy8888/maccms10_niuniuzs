<?php
namespace app\common\service\nnzs;

/**
 * 资讯（文章）模板初始化：把内置的 art/type.html、art/detail.html 复制到当前前台模板，
 * 并把配套静态资源复制到 static/art/。已存在的文件不覆盖。
 */
class ArtTheme
{
    public static function src()
    {
        return APP_PATH . 'data/nnzs/theme/';
    }

    public static function targetDir()
    {
        return ROOT_PATH . trim((string)config('template.view_path'), '/') . '/art/';
    }

    /** 当前模板缺哪些文件 */
    public static function missing()
    {
        $dir = self::targetDir();
        $miss = [];
        foreach (['type.html', 'detail.html'] as $f) {
            if (!is_file($dir . $f)) {
                $miss[] = $f;
            }
        }
        return $miss;
    }

    public static function install()
    {
        $dir = self::targetDir();
        $copied = [];
        $missing = self::missing();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        foreach ($missing as $f) {
            if (is_file(self::src() . 'art/' . $f) && @copy(self::src() . 'art/' . $f, $dir . $f)) {
                $copied[] = $f;
            }
        }
        if ($copied || !is_dir(ROOT_PATH . 'static/art')) {
            self::copyDir(self::src() . 'static_art/', ROOT_PATH . 'static/art/');
        }
        return $copied;
    }

    private static function copyDir($from, $to)
    {
        if (!is_dir($from)) {
            return;
        }
        if (!is_dir($to)) {
            @mkdir($to, 0755, true);
        }
        foreach (scandir($from) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (is_dir($from . $f)) {
                self::copyDir($from . $f . '/', $to . $f . '/');
            } elseif (!is_file($to . $f)) {
                @copy($from . $f, $to . $f);
            }
        }
    }
}
