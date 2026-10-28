<?php
namespace app\common\util;
use think\Db;

//数据库操作模型
class Database {
    /**
     * 文件指针
     * @var resource
     */
    private $fp;

    /**
     * 备份文件信息 part - 卷号，name - 文件名
     * @var array
     */
    private $file;

    /**
     * 当前打开文件大小
     * @var integer
     */
    private $size = 0;

    /**
     * 备份配置
     * @var integer
     */
    private $config;

    /**
     * 数据库备份构造方法
     * @param array $file 备份或还原的文件信息
     * @param array $config 备份配置信息
     * @param string $type 执行类型，export - 备份数据， import - 还原数据
     */
    public function __construct($file, $config, $type = 'export'){
        $this->file   = $file;
        $this->config = $config;
    }

    /**
     * 打开一个卷，用于写入数据
     * @param integer $size 写入数据的大小
     */
    private function open($size){
        if($this->fp){
            $this->size += $size;
            if($this->size > $this->config['part']){
                $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
                $this->fp = null;
                $this->file['part']++;
                session('backup_file', $this->file);
                $this->create();
            }
        } else {
            $backuppath = $this->config['path'];
            $filename   = "{$backuppath}{$this->file['name']}-{$this->file['part']}.sql";
            if($this->config['compress']){
                $filename = "{$filename}.gz";
                $this->fp = @gzopen($filename, "a{$this->config['level']}");
            } else {
                $this->fp = @fopen($filename, 'a');
            }
            $this->size = filesize($filename) + $size;
        }
    }

    /**
     * 写入初始数据
     * @return boolean true - 写入成功，false - 写入失败
     */
    public function create(){
        $sql  = "-- -----------------------------\n";
        $sql .= "-- Think MySQL Data Transfer \n";
        $sql .= "-- \n";
        $sql .= "-- Host     : " . config('database.hostname') . "\n";
        $sql .= "-- Port     : " . config('database.hostport') . "\n";
        $sql .= "-- Database : " . config('database.database') . "\n";
        $sql .= "-- \n";
        $sql .= "-- Part : #{$this->file['part']}\n";
        $sql .= "-- Date : " . date("Y-m-d H:i:s") . "\n";
        $sql .= "-- -----------------------------\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        return $this->write($sql);
    }

    /**
     * 写入SQL语句
     * @param string $sql 要写入的SQL语句
     * @return boolean true - 写入成功，false - 写入失败！
     */
    private function write($sql){
        $size = strlen($sql);
        
        // 由于压缩原因，无法计算出压缩后的长度，这里假设压缩率为50%
        // 一般情况压缩率都会高于50%
        $size = $this->config['compress'] ? $size / 2 : $size;
        
        $this->open($size); 
        return $this->config['compress'] ? @gzwrite($this->fp, $sql) : @fwrite($this->fp, $sql);
    }

    /**
     * 取可用于游标分块的主键列（仅单列 + 整型）；不满足则返回空串，调用方回退偏移分块。
     * 表名由调用方白名单校验后传入，此处仍用反引号包裹并校验列名。
     */
    private function primaryKeyColumn($table)
    {
        $keys = Db::query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        if (!is_array($keys) || count($keys) !== 1) {
            return '';
        }
        $col = isset($keys[0]['Column_name']) ? (string)$keys[0]['Column_name'] : '';
        if ($col === '' || !preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            return '';
        }
        $cols = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '" . addslashes($col) . "'");
        $type = isset($cols[0]['Type']) ? strtolower((string)$cols[0]['Type']) : '';
        if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $type)) {
            return '';
        }
        return $col;
    }

    /**
     * 备份表结构
     * @param string $table 表名
     * @param integer $start 起始行数
     * @return boolean false - 备份失败
     */
    public function backup($table, $start){
        // 安全：$start 参与 LIMIT 拼接，必须强制为整数
        $start = (int)$start;
        //备份表结构
        if(0 == $start){
            $result = Db::query("SHOW CREATE TABLE `{$table}`");
            $sql  = "\n";
            $sql .= "-- -----------------------------\n";
            $sql .= "-- Table structure for `{$table}`\n";
            $sql .= "-- -----------------------------\n";
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= trim($result[0]['Create Table']) . ";\n\n";
            if(false === $this->write($sql)){
                return false;
            }
        }

        //数据总数
        $result = Db::query("SELECT COUNT(*) AS count FROM `{$table}`");
        $count  = $result['0']['count'];
            
        //备份表数据
        if($count){
            //写入数据注释
            if(0 == $start){
                $sql  = "-- -----------------------------\n";
                $sql .= "-- Records of `{$table}`\n";
                $sql .= "-- -----------------------------\n";
                $this->write($sql);
            }

            // 性能：原实现按**偏移**分块（LIMIT {$start},1000）
            // 每块都要从表头重新扫描并丢弃前 $start 行 —— 大表上是 O(N²)。现改为按自增主键游标分块：
            //   WHERE `pk` > :start ORDER BY `pk` LIMIT 1000
            // $start 的语义由"偏移量"变为"上一批最后一条记录的主键值"（调用方只是回传返回值，契约不变）。
            // 没有单一整型主键的表仍回退旧的偏移分块，保持兼容。
            $pk = $this->primaryKeyColumn($table);
            if ($pk !== '') {
                $sqlQuery = "SELECT * FROM `{$table}` WHERE `{$pk}` > {$start} ORDER BY `{$pk}` ASC LIMIT 1000";
            } else {
                $sqlQuery = "SELECT * FROM `{$table}` LIMIT {$start}, 1000";
            }

            //备份数据记录
            $result = Db::query($sqlQuery);
            $rows   = 0;
            $last   = $start;
            foreach ($result as $row) {
                $rows++;
                if ($pk !== '') {
                    $last = (int)$row[$pk];
                }
                $row = array_map('addslashes', $row);
                $one = implode("', '", $row);
                $one = str_replace([chr(10),chr(13)],'',$one);
                $sql = "INSERT INTO `{$table}` VALUES ('" . $one . "');\n";
                if(false === $this->write($sql)){
                    return false;
                }
            }

            //还有更多数据
            if ($pk !== '') {
                if ($rows >= 1000) {
                    return array($last, $count);
                }
            } elseif ($count > $start + 1000) {
                return array($start + 1000, $count);
            }
        }

        //备份下一表
        return 0;
    }

    public function import($start){
        //还原数据
        if($this->config['compress']){
            $gz   = gzopen($this->file[1], 'r');
            $size = 0;
        } else {
            $size = filesize($this->file[1]);
            $gz   = fopen($this->file[1], 'r');
        }
        
        $sql  = '';
        if($start){
            $this->config['compress'] ? gzseek($gz, $start) : fseek($gz, $start);
        }
        
        for($i = 0; $i < 1000; $i++){
            $sql .= $this->config['compress'] ? gzgets($gz) : fgets($gz);

            // 安全：只需判断行尾分号，去掉正则回溯
            if(substr(trim($sql), -1) === ';'){
                if(false !== Db::execute($sql)){
                    $start += strlen($sql);
                } else {
                    return false;
                }
                $sql = '';
            } elseif ($this->config['compress'] ? gzeof($gz) : feof($gz)) {
                return 0;
            }
        }

        return array($start, $size);
    }

    /**
     * 析构方法，用于关闭文件资源
     */
    public function __destruct(){
        $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
    }
}
