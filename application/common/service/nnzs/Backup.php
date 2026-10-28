<?php
namespace app\common\service\nnzs;

use think\Db;

/**
 * 破坏性操作前的数据备份（JSON 行快照），存放于 application/data/backup/nnzs/。
 * 文件是 .php：第一行直接 403 退出，JSON 在其后，避免被当成静态文件下载。
 * 备份失败返回 false，调用方必须中止后续 UPDATE/TRUNCATE。
 */
class Backup
{
    /** HTTP 直访时立即 403，不输出后面的 JSON */
    const GUARD = "<?php http_response_code(403); exit; __halt_compiler();\n";

    public static function dir()
    {
        return APP_PATH . 'data/backup/nnzs/';
    }

    /**
     * @param string $table 不含前缀的表名
     * @param array|string $where 查询条件（同 Db::where）
     * @param array|string $fields 字段
     * @return string|false 备份文件路径
     */
    public static function table($table, $where = [], $fields = '*', $tag = '')
    {
        try {
            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                Log::error('备份目录不可写: ' . $dir);
                return false;
            }
            $q = Db::name($table)->field($fields);
            if (!empty($where)) {
                $q->where($where);
            }
            $rows = $q->select();
            $rows = is_object($rows) ? $rows->toArray() : (array)$rows;
            $file = $dir . date('Ymd-His') . '-' . $table . ($tag !== '' ? '-' . preg_replace('/[^a-z0-9_]/i', '', $tag) : '')
                . '-' . substr(md5(uniqid('', true)), 0, 6) . '.php';
            $json = json_encode(['table' => $table, 'time' => date('c'), 'count' => count($rows), 'rows' => $rows],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                Log::error('备份 JSON 编码失败: ' . $table);
                return false;
            }
            $content = self::GUARD . $json;
            if (@file_put_contents($file, $content, LOCK_EX) !== strlen($content)) {
                @unlink($file);
                Log::error('写备份失败: ' . $file);
                return false;
            }
            Log::info('已备份 ' . $table . ' ' . count($rows) . ' 行 -> ' . basename($file));
            return $file;
        } catch (\Throwable $e) {
            Log::error('备份 ' . $table . ' 失败: ' . $e->getMessage());
            return false;
        }
    }

    /** 最近的备份文件列表 */
    public static function lists($limit = 50)
    {
        $files = glob(self::dir() . '*.php') ?: [];
        $files = array_values(array_filter($files, function ($f) {
            return basename($f) !== 'index.php';
        }));
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, $limit) as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
        }
        return $out;
    }
}
