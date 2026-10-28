<?php
namespace app\common\service\nnzs;

use think\Db;

/**
 * 按豆瓣 ID 批量设置轮播海报与推荐等级（数据来自商店 hb[mode]）。
 */
class Poster
{
    /**
     * @return int|false 更新条数；该模式无数据返回 false
     */
    public static function apply($mode, $level, ?array $data = null)
    {
        $data = $data ?: Store::data();
        $mode = (string)$mode;
        $level = max(0, min(9, (int)$level));
        if (!isset($data['hb'][$mode]) || !is_array($data['hb'][$mode])) {
            return false;
        }
        $table = Replacer::table();
        if ($table === false) {
            return false;
        }
        $items = [];
        foreach ($data['hb'][$mode] as $v) {
            $dbid = is_array($v) && isset($v['dbid']) ? (int)$v['dbid'] : 0;
            $img = is_array($v) && isset($v['image_url']) ? (string)$v['image_url'] : '';
            if ($dbid > 0 && $img !== '' && mac_safe_url($img)) {
                $items[$dbid] = $img;
            }
        }
        if (empty($items)) {
            return 0;
        }
        if (Backup::table('vod', ['vod_douban_id' => ['in', array_keys($items)]], 'vod_id,vod_douban_id,vod_pic_slide,vod_level', 'poster') === false) {
            throw new \RuntimeException('备份失败，已中止海报更新');
        }
        $num = 0;
        Db::startTrans();
        try {
            foreach ($items as $dbid => $img) {
                $n = Db::execute('UPDATE `' . $table . '` SET vod_pic_slide = ?, vod_level = ? WHERE vod_douban_id = ?', [$img, $level, $dbid]);
                if ($n > 0) {
                    $num++;
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('海报更新失败，已回滚: ' . $e->getMessage());
            throw $e;
        }
        return $num;
    }

    /** 本站可匹配到的影片数（展示用） */
    public static function matchCount($mode, array $data)
    {
        if (empty($data['hb'][$mode]) || !is_array($data['hb'][$mode])) {
            return 0;
        }
        $ids = [];
        foreach ($data['hb'][$mode] as $v) {
            if (is_array($v) && !empty($v['dbid'])) {
                $ids[] = (int)$v['dbid'];
            }
        }
        return $ids ? (int)Db::name('vod')->where('vod_douban_id', 'in', $ids)->count() : 0;
    }
}
