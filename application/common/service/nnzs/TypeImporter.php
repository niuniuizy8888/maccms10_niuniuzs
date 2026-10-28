<?php
namespace app\common\service\nnzs;

use think\Db;

/**
 * 从商店分类树导入本站分类（只增不改，同名即视为已存在）。
 * 树结构：[0 => [父类...], <商店父类ID> => [子类...]]，元素含 type_id/type_name。
 *
 * 相对旧插件的修正：无子类的顶级分类原来每次导入都会重复插入一条同名分类，现按名称去重。
 */
class TypeImporter
{
    public static function template($mid)
    {
        return [
            'type_mid' => (int)$mid,
            'type_status' => 1,
            'type_sort' => '',
            'type_en' => '',
            'type_tpl' => 'type.html',
            'type_tpl_list' => 'show.html',
            'type_tpl_detail' => 'detail.html',
            'type_tpl_play' => '',
            'type_tpl_down' => '',
            'type_title' => '',
            'type_key' => '',
            'type_des' => '',
            'type_logo' => '',
            'type_pic' => '',
            'type_jumpurl' => '',
            'type_extend' => ['class' => '', 'area' => '', 'lang' => '', 'year' => '', 'star' => '',
                'director' => '', 'state' => '', 'version' => ''],
        ];
    }

    /**
     * @return int 新增分类数；树结构不合法返回 false
     */
    public static function import($tree, $mid)
    {
        if (!is_array($tree) || !isset($tree[0]) || !is_array($tree[0])) {
            return false;
        }
        $added = 0;
        Db::startTrans();
        try {
            foreach ($tree[0] as $parent) {
                if (!is_array($parent) || !isset($parent['type_name']) || (string)$parent['type_name'] === '') {
                    continue;
                }
                $pid = self::ensure((string)$parent['type_name'], 0, $mid, $added);
                $children = isset($parent['type_id'], $tree[$parent['type_id']]) && is_array($tree[$parent['type_id']])
                    ? $tree[$parent['type_id']] : [];
                foreach ($children as $child) {
                    if (is_array($child) && isset($child['type_name']) && (string)$child['type_name'] !== '' && $pid > 0) {
                        self::ensure((string)$child['type_name'], $pid, $mid, $added);
                    }
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('导入分类失败，已回滚: ' . $e->getMessage());
            throw $e;
        }
        model('Type')->setCache();
        // 新建分类后会员组未授权则前台看不到内容。
        // 资讯(mid=2)即使同名已存在也刷新权限，避免历史清空分类后文章仍不可见。
        if ($added > 0 || (int)$mid === 2) {
            try {
                Group::grantAll();
            } catch (\Throwable $e) {
                Log::error('导入分类后设置会员组权限失败: ' . $e->getMessage());
            }
        }
        return $added;
    }

    /** 同名存在则返回其 ID，否则新建 */
    private static function ensure($name, $pid, $mid, &$added)
    {
        $row = Db::name('type')->where('type_name', $name)->find();
        if (!empty($row)) {
            return (int)$row['type_id'];
        }
        $data = self::template($mid);
        $data['type_pid'] = (int)$pid;
        $data['type_name'] = $name;
        $res = model('Type')->saveData($data);
        if (!is_array($res) || (int)$res['code'] !== 1) {
            throw new \RuntimeException('保存分类「' . $name . '」失败：' . (is_array($res) ? $res['msg'] : ''));
        }
        $added++;
        $row = Db::name('type')->where('type_name', $name)->find();
        return empty($row) ? 0 : (int)$row['type_id'];
    }

    /** 清空全部分类（先备份）。行为与旧插件一致：TRUNCATE */
    public static function clearAll()
    {
        if (Backup::table('type', [], '*', 'clear') === false) {
            throw new \RuntimeException('备份失败，已中止清空分类');
        }
        Db::execute('TRUNCATE TABLE ' . config('database.prefix') . 'type');
        model('Type')->setCache();
        return true;
    }
}
