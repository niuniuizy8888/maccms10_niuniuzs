<?php
namespace app\common\service\nnzs;

use think\Db;

/**
 * 会员组权限：把游客/默认会员/VIP会员三个组设为可访问全部分类（权限 1..5 全开）。
 * 行为与旧插件 users() 一致；执行前备份 mac_group，整体放在事务中。
 */
class Group
{
    public static function grantAll()
    {
        $types = Db::name('type')->order('type_pid ASC, type_sort ASC, type_id ASC')->column('type_id');
        $groups = [1 => '游客', 2 => '默认会员', 3 => 'VIP会员'];
        if (Backup::table('group', [], '*', 'grant') === false) {
            throw new \RuntimeException('备份失败，已中止重置会员组权限');
        }
        Db::startTrans();
        try {
            foreach ($groups as $gid => $gname) {
                $g = ['group_id' => $gid, 'group_name' => $gname, 'group_type' => [], 'group_popedom' => []];
                foreach ($types as $tid) {
                    $g['group_type'][] = $tid;
                    $g['group_popedom'][$tid] = ['1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5];
                }
                $res = model('Group')->saveData($g);
                if (!is_array($res) || (int)$res['code'] !== 1) {
                    throw new \RuntimeException('保存会员组「' . $gname . '」失败：' . (is_array($res) ? $res['msg'] : ''));
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('设置会员组权限失败，已回滚: ' . $e->getMessage());
            throw $e;
        }
        return count($groups);
    }
}
