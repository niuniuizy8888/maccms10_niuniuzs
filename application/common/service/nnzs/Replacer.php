<?php
namespace app\common\service\nnzs;

use think\Db;

/**
 * 批量替换视频封面 / 播放地址（对外接口 api.php/auto/replace 与后台「批量替换」共用）。
 * SQL 与旧版完全一致（参数绑定的 REPLACE），返回受影响行数；执行前备份受影响行的原值。
 */
class Replacer
{
    const FIELDS = ['img' => 'vod_pic', 'play' => 'vod_play_url'];

    public static function table()
    {
        $table = config('database.prefix') . 'vod';
        return preg_match('/^[A-Za-z0-9_]+$/', $table) ? $table : false;
    }

    /**
     * @return int|false 受影响行数；类型非法返回 false
     */
    public static function replace($type, $old, $new)
    {
        if (!isset(self::FIELDS[$type])) {
            return false;
        }
        $table = self::table();
        if ($table === false) {
            return false;
        }
        $col = self::FIELDS[$type];
        // 只备份将被改动的行（主键 + 原值）。备份失败则不执行 UPDATE。
        if (Backup::table('vod', [$col => ['like', '%' . self::escapeLike($old) . '%']], 'vod_id,' . $col, 'replace_' . $type) === false) {
            throw new \RuntimeException('备份失败，已中止批量替换');
        }
        $n = Db::execute('UPDATE `' . $table . '` SET ' . $col . ' = REPLACE(' . $col . ', ?, ?)', [(string)$old, (string)$new]);
        Log::info('批量替换 ' . $col . '：' . $n . ' 行');
        return $n;
    }

    private static function escapeLike($s)
    {
        return addcslashes((string)$s, '\\%_');
    }
}
