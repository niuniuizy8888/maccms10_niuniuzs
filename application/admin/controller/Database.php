<?php
namespace app\admin\controller;
use think\Db;
use app\common\util\Dir;
use app\common\util\Database as dbOper;

class Database extends Base
{
    var $_db_config;
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $group = input('group');
        if($group=='import'){
            //列出备份文件列表
            $path = trim( $GLOBALS['config']['db']['backup_path'], '/').DS;
            if (!is_dir($path)) {
                Dir::create($path);
            }
            $flag = \FilesystemIterator::KEY_AS_FILENAME;
            $glob = new \FilesystemIterator($path,  $flag);

            $list = [];
            foreach ($glob as $name => $file) {
                if(preg_match('/^\d{8,8}-\d{6,6}-\d+\.sql(?:\.gz)?$/', $name)){
                    $name = sscanf($name, '%4s%2s%2s-%2s%2s%2s-%d');
                    $date = "{$name[0]}-{$name[1]}-{$name[2]}";
                    $time = "{$name[3]}:{$name[4]}:{$name[5]}";
                    $part = $name[6];

                    if(isset($list["{$date} {$time}"])){
                        $info = $list["{$date} {$time}"];
                        $info['part'] = max($info['part'], $part);
                        $info['size'] = $info['size'] + $file->getSize();
                    } else {
                        $info['part'] = $part;
                        $info['size'] = $file->getSize();
                    }

                    $extension        = strtoupper($file->getExtension());
                    $info['compress'] = ($extension === 'SQL') ? '无' : $extension;
                    $info['time']     = strtotime("{$date} {$time}");

                    $list["{$date} {$time}"] = $info;
                }
            }
        }
        else{
            $group='export';
            $list = Db::query("SHOW TABLE STATUS");
        }

        $this->assign('list',$list);
        $this->assign('title',lang('admin/database/title'));
        return $this->fetch('admin@database/'.$group);
    }

    public function export($ids = '', $start = 0)
    {
        if ($this->request->isPost()) {
            if (empty($ids)) {
                return $this->error(lang('admin/database/select_export_table'));
            }

            if (!is_array($ids)) {
                $tables[] = $ids;
            } else {
                $tables = $ids;
            }
            $start = intval($start);
            $tables = self::checkTables($tables);
            if ($tables === false) {
                return $this->error('Table is invalid.');
            }
            $have_admin = false;
            $admin_table='';
            foreach($tables as $k=>$v){
                if(strpos($v,'_admin')!==false){
                    $have_admin=true;
                    $admin_table = $v;
                    unset($tables[$k]);
                }
            }
            if($have_admin){
                $tables[] = $admin_table;
            }

            //读取备份配置
            $config = array(
                'path'     => $GLOBALS['config']['db']['backup_path'] .DS,
                'part'     => $GLOBALS['config']['db']['part_size'] ,
                'compress' => $GLOBALS['config']['db']['compress'] ,
                'level'    => $GLOBALS['config']['db']['compress_level'] ,
            );

            //检查是否有正在执行的任务
            $lock = "{$config['path']}backup.lock";
            if(is_file($lock)){
                return $this->error(lang('admin/database/lock_check'));
            } else {
                if (!is_dir($config['path'])) {
                    Dir::create($config['path'], 0755, true);
                }
                //创建锁文件
                file_put_contents($lock, $this->request->time());
            }

            //生成备份文件信息
            $file = [
                'name' => date('Ymd-His', $this->request->time()),
                'part' => 1,
            ];

            // 创建备份文件
            $database = new dbOper($file, $config);
            if($database->create() !== false) {
                // 备份指定表
                foreach ($tables as $table) {
                    $start = $database->backup($table, $start);
                    while (0 !== $start) {
                        if (false === $start) {
                            return $this->error(lang('admin/database/backup_err'));
                        }
                        $start = $database->backup($table, $start[0]);
                    }
                }
                // 备份完成，删除锁定文件
                unlink($lock);
            }
            return $this->success(lang('admin/database/backup_ok'));
        }
        return $this->error(lang('admin/database/backup_err'));
    }

    /**
     * 恢复数据库 [参考原作者 麦当苗儿 <zuojiazi@vip.qq.com>]
     * @param string|array $ids 表名
     * @param integer $start 起始行数
     * @author 橘子俊 <364666827@qq.com>
     * @return mixed
     */
    public function import($id = '')
    {
        if (empty($id)) {
            return $this->error(lang('admin/database/select_file'));
        }
        // 安全：$id 为 strtotime 时间戳（见 admin/view/database/import.html）
        // 非标量/非数字在 PHP 8 下会触发 TypeError 500，这里先做严格校验再使用。
        if (!is_scalar($id) || !preg_match('/^\d{1,10}$/', (string)$id)) {
            return $this->error(lang('param_err'));
        }
        $id = intval($id);

        $name  = date('Ymd-His', $id) . '-*.sql*';
        $path  = trim( $GLOBALS['config']['db']['backup_path'] , '/').DS.$name;
        $files = glob($path);
        $list  = array();
        foreach($files as $name){
            $basename = basename($name);
            $match    = sscanf($basename, '%4s%2s%2s-%2s%2s%2s-%d');
            $gz       = preg_match('/^\d{8,8}-\d{6,6}-\d+\.sql.gz$/', $basename);
            $list[$match[6]] = array($match[6], $name, $gz);
        }
        ksort($list);

        // 检测文件正确性
        $last = end($list);
        if(count($list) === $last[0]){
            foreach ($list as $item) {
                $config = [
                    'path'     => trim($GLOBALS['config']['db']['backup_path'], '/').DS,
                    'compress' => $item[2]
                ];
                $database = new dbOper($item, $config);
                $start = $database->import(0);
                // 导入所有数据
                while (0 !== $start) {
                    if (false === $start) {
                        return $this->error(lang('admin/database/import_err'));
                    }
                    $start = $database->import($start[0]);
                }
            }
            return $this->success(lang('admin/database/import_ok'));
        }
        return $this->error(lang('admin/database/file_damage'));
    }

    public function optimize($ids = '')
    {
        if (empty($ids)) {
            return $this->error(lang('admin/database/select_optimize_table'));
        }

        if (!is_array($ids)) {
            $table[] = $ids;
        } else {
            $table = $ids;
        }

        $table = self::checkTables($table);
        if ($table === false) {
            return $this->error('Table is invalid.');
        }

        $tables = implode('`,`', $table);
        $res = Db::query("OPTIMIZE TABLE `{$tables}`");
        if ($res) {
            return $this->success(lang('admin/database/optimize_ok'));
        }
        return $this->error(lang('admin/database/optimize_err'));
    }

    public function repair($ids = '')
    {
        if (empty($ids)) {
            return $this->error(lang('admin/database/select_repair_table'));
        }

        if (!is_array($ids)) {
            $table[] = $ids;
        } else {
            $table = $ids;
        }

        $table = self::checkTables($table);
        if ($table === false) {
            return $this->error('Table is invalid.');
        }

        $tables = implode('`,`', $table);
        $res = Db::query("REPAIR TABLE `{$tables}`");
        if ($res) {
            return $this->success(lang('admin/database/repair_ok'));
        }
        return $this->error(lang('admin/database/repair_ok'));
    }

    public function del($id = '')
    {
        if (empty($id)) {
            return $this->error(lang('admin/database/select_del_file'));
        }
        // 安全：$id 为备份文件时间戳，必须纯数字
        if (!is_scalar($id) || !preg_match('/^\d{1,10}$/', (string)$id)) {
            return $this->error(lang('param_err'));
        }
        $id = intval($id);

        $name  = date('Ymd-His', $id) . '-*.sql*';
        $path = trim($GLOBALS['config']['db']['backup_path']).DS.$name;
        array_map("unlink", glob($path));
        if(count(glob($path)) && glob($path)){
            return $this->error(lang('del_err'));
        }
        return $this->success(lang('del_ok'));
    }

    public function sql()
    {
        // 安全：SQL 控制台默认关闭
        //仅超管(admin_id=1) 且 application/extra/maccms.php 的 app.sql_console 显式开启时可用
        if(empty($GLOBALS['config']['app']['sql_console']) || empty($this->_admin['admin_id']) || intval($this->_admin['admin_id']) !== 1){
            return $this->error(lang('permission_denied'));
        }

        if($this->request->isPost()){
            $param=input();
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }

            $sql = trim($param['sql']);

            if(!empty($sql)){
                $sql = str_replace('{pre}',config('database.prefix'),$sql);
                //查询语句返回结果集
                if(
                    strtolower(substr($sql,0,6))=="select" || 
                    stripos($sql, ' outfile') !== false
                ){

                }
                else{
                    Db::execute($sql);
                }
            }
            $this->success(lang('run_ok'));
        }
        return $this->fetch('admin@database/sql');
    }

    public function columns()
    {
        $param = input();
        $table = $param['table'];
        if (!empty($table) && !$this->isValidTable($table)) {
            return $this->error('Table is invalid.');
        }
        if (!empty($table)) {
            $list = Db::query('SHOW COLUMNS FROM ' . $table);
            $this->success(lang('obtain_ok'),null, $list);
        }
        $this->error(lang('param_err'));
    }

    public function rep()
    {
        if($this->request->isPost()){
            $param = input();
            $table = $param['table'];
            $field = $param['field'];
            $findstr = $param['findstr'];
            $tostr = $param['tostr'];
            $where = $param['where'];

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }
            if (empty($table) || !$this->isValidTable($table)) {
                return $this->error('Table is invalid.');
            }
            if(empty($field) || empty($findstr) || empty($tostr)){
                return $this->error(lang('param_err'));
            }

            // 安全：$field 必须是 SHOW COLUMNS 结果集内的真实列名
            $columns = [];
            foreach(Db::query('SHOW COLUMNS FROM `'.$table.'`') as $col){
                $columns[] = $col['Field'];
            }
            $realfield = self::pickColumn($field, $columns);
            if($realfield === null){
                return $this->error(lang('param_err'));
            }

            // 安全：$where 白名单解析（列名+运算符），值全部参数绑定
            $bind = [$findstr, $tostr];
            $wheresql = self::parseWhere($where, $columns, $bind);
            if($wheresql === false){
                return $this->error(lang('param_err'));
            }

            $sql = "UPDATE `".$table."` SET `".$realfield."`=Replace(`".$realfield."`,?,?) WHERE ".$wheresql;
            Db::execute($sql, $bind);
            return $this->success(lang('run_ok'));
        }
        $list = Db::query("SHOW TABLE STATUS");
        $this->assign('list',$list);
        return $this->fetch('admin@database/rep');
    }

    //SHOW TABLES 结果集（static 缓存），动态表名的唯一可信来源
    private static function realTables()
    {
        static $tables = null;
        if($tables === null){
            $tables = [];
            foreach(Db::query('SHOW TABLES') as $row){
                if(is_array($row)){
                    $name = reset($row);
                    if(is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name)){
                        $tables[] = $name;
                    }
                }
            }
        }
        return $tables;
    }

    //表名白名单：逐个校验 ^[A-Za-z0-9_]+$ 且在 SHOW TABLES 结果集内，不合法返回 false
    private static function checkTables($ids)
    {
        $tables = is_array($ids) ? $ids : [$ids];
        if(empty($tables)){
            return false;
        }
        $realtables = self::realTables();
        foreach($tables as $v){
            if(!is_string($v) || !preg_match('/^[A-Za-z0-9_]+$/', $v) || !in_array($v, $realtables, true)){
                return false;
            }
        }
        return $tables;
    }

    //列名白名单：与 SHOW COLUMNS 的 Field 比对，返回表结构中的原始列名，未命中返回 null
    private static function pickColumn($field, $columns)
    {
        if(!is_string($field) || !preg_match('/^[A-Za-z0-9_]+$/', $field)){
            return null;
        }
        foreach($columns as $col){
            if(strcasecmp($col, $field) === 0){
                return $col;
            }
        }
        return null;
    }

    // where 白名单解析：仅允许 `列 运算符 值` 形式，多条件用 and/or 连接
    //值一律参数绑定，无法安全解析时返回 false
    private static function parseWhere($where, $columns, &$bind)
    {
        if($where === null){
            $where = '';
        }
        if(!is_scalar($where)){
            return false;
        }
        $where = trim((string)$where);
        if($where === ''){
            return '1=1';
        }
        $where = preg_replace('/^\s*(and|or)\s+/i', '', $where);
        $parts = preg_split('/(\s+and\s+|\s+or\s+)/i', $where, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if($parts === false){
            return false;
        }
        $sql = '';
        $logic = 'and';
        foreach($parts as $part){
            $part = trim($part);
            if($part === ''){
                continue;
            }
            if(strcasecmp($part,'and')===0 || strcasecmp($part,'or')===0){
                $logic = strtolower($part);
                continue;
            }
            if(!preg_match('/^([A-Za-z0-9_]+)\s*(!=|>=|<=|=|>|<|like)\s*(.+)$/i', $part, $m)){
                return false;
            }
            $op = strtolower($m[2]);
            if(!in_array($op, ['=','!=','>','<','>=','<=','like'], true)){
                return false;
            }
            $col = self::pickColumn($m[1], $columns);
            if($col === null){
                return false;
            }
            $val = trim($m[3]);
            $len = strlen($val);
            if($len > 1 && ($val[0] === "'" || $val[0] === '"') && substr($val, -1) === $val[0]){
                $val = substr($val, 1, -1);
            }
            elseif(!is_numeric($val)){
                return false;
            }
            if($sql === ''){
                $sql = '1=1';
            }
            $sql .= ' '.$logic.' `'.$col.'` '.$op.' ?';
            $bind[] = $val;
        }
        return $sql === '' ? false : $sql;
    }

    //表名合法性：格式白名单 + 精确存在于 SHOW TABLES 结果集（区分大小写，与原实现一致）
    private function isValidTable($table) {
        if(!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)){
            return false;
        }
        return in_array($table, self::realTables(), true);
    }
}
