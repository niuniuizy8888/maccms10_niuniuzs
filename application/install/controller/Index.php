<?php
namespace app\install\controller;
use think\Controller;
use think\Db;
use think\Lang;
use think\Request;

class Index extends Controller
{

    /**
     * 构造方法
     * @access public
     * @param Request $request Request 对象
     */
    public function __construct(?Request $request = null)   // PHP 8.4+：显式可空，避免隐式可空弃用告警
    {
        // 仅安装脚本可进入
        if (!defined('BIND_MODULE') || BIND_MODULE != 'install') {
            header('HTTP/1.1 403 Forbidden');
            exit();
        }
        parent::__construct($request);
    }

    public function index($step = 0)
    {
        $langs = glob('./application/lang/*.php');
        foreach ($langs as $k => &$v) {
            $v = str_replace(['./application/lang/','.php'],['',''],$v);
        }
        $this->assign('langs', $langs);

        // PHP8：视图 index.html:10 用了 {if condition="$lang eq $vo"}
        // 而 $lang 原先只在默认分支 assign —— 直接访问 ?step=N 时未定义，会逐次刷
        // "Undefined array key "lang"" 告警。这里统一给出默认值。
        $lang = 'zh-cn';
        if(in_array(session('lang'),$langs)){
            $lang = Lang::range(session('lang'));
            Lang::load('./application/lang/'.$lang.'.php',$lang);
        }
        $this->assign('lang', $lang);

        switch ($step) {
            case 2:
                session('install_error', false);
                return self::step2();
                break;
            case 3:
                if (session('install_error')) {
                    return $this->error(lang('install/environment_failed'));
                }
                return self::step3();
                break;
            case 4:
                if (session('install_error')) {
                    return $this->error(lang('install/environment_failed'));
                }
                return self::step4();
                break;
            case 5:
                if (session('install_error')) {
                    return $this->error(lang('install/init_err'));
                }
                return self::step5();
                break;
            default:
                $param = input();

                // PHP8：直接访问 install.php（不带 ?lang=）时
                // $param['lang'] 未定义，会刷 "Undefined array key \"lang\"" 告警。
                $param_lang = isset($param['lang']) && is_scalar($param['lang']) ? (string) $param['lang'] : '';
                if (!in_array($param_lang, $langs)) {
                    $param_lang = 'zh-cn';
                }
                $param['lang'] = $param_lang;
                $lang = Lang::range($param_lang);
                Lang::load('./application/lang/'.$lang.'.php',$lang);
                session('lang',$param_lang);
                $this->assign('lang',$param_lang);

                session('install_error', false);
                return $this->fetch('install@/index/index');
                break;
        }
    }

    /**
     * 第二步：环境检测
     * @return mixed
     */
    private function step2()
    {
        $data = [];
        $data['env'] = self::checkNnv();
        $data['dir'] = self::checkDir();
        $data['func'] = self::checkFunc();
        $this->assign('data', $data);
        return $this->fetch('install@index/step2');
    }
    
    /**
     * 第三步：初始化配置
     * @return mixed
     */
    private function step3()
    {
        $install_dir = $_SERVER["SCRIPT_NAME"];
        $install_dir = mac_substring($install_dir, strripos($install_dir, "/")+1);
        $this->assign('install_dir',$install_dir);
        return $this->fetch('install@index/step3');
    }

    /**
     * 判断本站是否内置牛牛助手
     */
    private static function hasNnzsPlugin()
    {
        return class_exists('\app\common\service\nnzs\Config') && is_file(APP_PATH . 'admin/controller/Nnzs.php');
    }
    
    /**
     * 第四步：执行安装
     * @return mixed
     */
    private function step4()
    {
        if ($this->request->isPost()) {
            if (!is_writable(APP_PATH.'database.php')) {
                return $this->error('[app/database.php]'.lang('install/write_read_err'));
            }
            $data = input('post.');
            $data['type'] = 'mysql';
            $rule = [
                'hostname|'.lang('install/server_address') => 'require',
                'hostport|'.lang('install/database_port') => 'require|number',
                'database|'.lang('install/database_name') => 'require',
                'username|'.lang('install/database_username') => 'require',
                // mkDatabase 已改为 var_export 落盘，任何字符都安全
                // 原先的字符白名单会把含 $ &  ~ 空格等的正常强密码判为非法（自伤）。只保留必填与长度上限。
                'password|数据库密码' => 'require|length:1,64',
                'prefix|'.lang('install/database_pre') => 'require|regex:^[a-z0-9]{1,20}[_]{1}',
                'cover|'.lang('install/overwrite_database') => 'require|in:0,1',
            ];
            $validate = $this->validate($data, $rule);
            if (true !== $validate) {
                return $this->error($validate);
            }
            $cover = $data['cover'];
            unset($data['cover']);
            $config = include APP_PATH.'database.php';
            foreach ($data as $k => $v) {
                if (array_key_exists($k, $config) === false) {
                    return $this->error(lang('param').''.$k.''.lang('install/not_found'));
                }
            }
            // 不存在的数据库会导致连接失败
            $database = $data['database'];
            unset($data['database']);
            // 创建数据库连接
            $db_connect = Db::connect($data);
            // 检测数据库连接
            try{
                $db_connect->execute('select version()');
            }catch(\Exception $e){
                $this->error(lang('install/database_connect_err'));
            }

            // 生成数据库配置文件
            $data['database'] = $database;
            self::mkDatabase($data);


            // 不覆盖检测是否已存在数据库
            if (!$cover) {
                $check = $db_connect->query('SELECT * FROM information_schema.schemata WHERE schema_name=?', [$database]);
                if ($check) {
                    $this->success(lang('install/database_name_haved'),'');
                }
            }
            // 创建数据库
            // execute 返回受影响行数：库已存在时为 0，原实现会把它当失败（空错误信息）。改为以异常判断失败。
            try {
                $db_connect->execute("CREATE DATABASE IF NOT EXISTS `{$database}` DEFAULT CHARACTER SET utf8mb4");
            } catch (\Exception $e) {
                return $this->error(lang('install/database_connect_err') . ' ' . $e->getMessage());
            }


            return $this->success(lang('install/database_connect_ok'), '');
        } else {
            return $this->error(lang('install/access_denied'));
        }
    }
    
    /**
     * 第五步：数据库安装
     * @return mixed
     */
    private function step5()
    {
        if (!self::hasNnzsPlugin()) {
            return $this->error('安装包缺少牛牛助手文件，请重新上传完整安装包');
        }
        $account = input('post.account');
        $password = input('post.password');
        $install_dir = input('post.install_dir');

        $config = include APP_PATH.'database.php';
        if (empty($config['hostname']) || empty($config['database']) || empty($config['username'])) {
            return $this->error(lang('install/please_test_connect'));
        }
        if (empty($account) || empty($password)) {
            return $this->error(lang('install/please_input_admin_name_pass'));
        }

        $rule = [
            'account|'.lang('install/admin_name') => 'require|alphaNum',
            'password|'.lang('install/admin_pass') => 'require|length:6,20',
        ];
        $validate = $this->validate(['account' => $account, 'password' => $password], $rule);
        if (true !== $validate) {
            return $this->error($validate);
        }
        if(empty($install_dir)) {
            $install_dir='/';
        }
        $config_new = config('maccms');
        $config_new['app']['cache_flag'] = bin2hex(random_bytes(5));
        // PHP 8 兼容：直接进 ?step=5 时 session('lang') 尚未写入（默认分支才会写），会触发未定义键告警
        $session_lang = session('lang');
        $config_new['app']['lang'] = is_string($session_lang) && $session_lang !== '' ? $session_lang : 'zh-cn';

        $config_new['api']['vod']['status'] = 0;
        $config_new['api']['art']['status'] = 0;

        $config_new['interface']['status'] = 0;
        $config_new['interface']['pass'] = mac_get_rndstr(16);
        // 新装站点默认生成计划任务口令：站点在反代/隧道后时 REMOTE_ADDR 恒为 127.0.0.1，仅靠回环判断会让 timming 入口对公网开放
        $config_new['app']['cron_key'] = mac_get_rndstr(24);
        $config_new['site']['install_dir'] = $install_dir;
        
        // 更新程序配置文件
        $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
		if ($res === false) {
			return $this->error(lang('write_err_config'));
		}
		
        // 导入系统初始数据库结构
        // 导入SQL
        $sql_file = APP_PATH.'install/sql/install.sql';
        if (file_exists($sql_file)) {
            $sql = file_get_contents($sql_file);
            // 数据库版本：InnoDB + utf8mb4 + varchar(255) 索引需 MySQL 5.7.7+ / MariaDB 10.2.2+
            $ver = '';
            try { $r = Db::query('SELECT VERSION() AS v'); $ver = (string)($r[0]['v'] ?? ''); } catch (\Exception $e) {}
            $is_maria = stripos($ver, 'mariadb') !== false;
            $num = preg_match('/^(\d+\.\d+\.\d+)/', $ver, $m) ? $m[1] : '0.0.0';
            if ($ver !== '' && version_compare($num, $is_maria ? '10.2.2' : '5.7.7', '<')) {
                return $this->error('数据库版本过低（'.htmlspecialchars($ver).'），需 MySQL 5.7.7+ 或 MariaDB 10.2.2+');
            }
            // TEXT 列默认值：MySQL 8.0.13+ / MariaDB 10.2.1+ 支持 DEFAULT ('')，补上后严格模式下
            // 插入未带这些列的记录不会报 1364；旧版本（5.7）保持原样（其 TEXT 列不允许默认值）。
            $text_default = $ver !== '' && version_compare($num, $is_maria ? '10.2.1' : '8.0.13', '>=');
            $sql_list = mac_parse_sql($sql, 0, ['mac_' => $config['prefix']]);
            if ($sql_list) {
                $sql_list = array_filter($sql_list);
                foreach ($sql_list as $v) {
                    if ($text_default) {
                        $v = preg_replace('/\b((?:medium)?text)( CHARACTER SET \w+ COLLATE \w+)? NOT NULL(?! DEFAULT)/i', "$1$2 NOT NULL DEFAULT ('')", $v);
                    }
                    try {
                        Db::execute($v);
                    } catch(\Exception $e) {
                        return $this->error(lang('install/sql_err'). $e);
                    }
                }
            }
        }
        // 注册管理员账号
        $data = [
            'admin_name' => $account,
            'admin_pwd' => $password,
            'admin_status' =>1,
        ];
        $res = model('Admin')->saveData($data);
        if (!$res['code']>1) {
            return $this->error(lang('install/admin_name_err').'：'.$res['msg']);
        }
        file_put_contents(APP_PATH.'data/install/install.lock', date('Y-m-d H:i:s'));

        // 获取站点根目录
        $root_dir = request()->baseFile();
        $root_dir  = preg_replace(['/install.php$/'], [''], $root_dir);

        // 助手必装，仅允许选择是否导入影视分类。
        $importTypes = (string) input('post.nnzs_type', '1') !== '0';
        try {
            $res = \app\common\service\nnzs\Installer::runDefault($importTypes);
        } catch (\Throwable $e) {
            $res = ['code' => 0, 'msg' => $e->getMessage()];
        }
        if ($res['code'] != 1) {
            return $this->error('牛牛助手初始化未完成：' . $res['msg'] . '。站点安装入口已锁定，请登录后台重试初始化。', $root_dir . self::adminEntry());
        }
        $registration = $res['registration'] ?? [];
        $reportMsg = !empty($registration['ok']) ? '已自动上报商店。' : '商店上报未完成，请在后台补登记：' . ($registration['msg'] ?? '未知错误') . '。';
        return $this->success(lang('install/is_ok') . $reportMsg . ' 牛牛助手已初始化' . ($importTypes ? '，影视分类已导入。' : '，未导入分类。') . '采集接口、播放器、绑定和定时任务可在后台按需配置。', $root_dir . self::adminEntry());

    }
    
    /**
     * 环境检测
     * @return array
     */
    private function checkNnv()
    {
        $items = [
            'os'      => [lang('install/os'), lang('install/not_limited'), 'Windows/Unix', PHP_OS, 'ok'],
            'php'     => [lang('install/php'), '8.5.12', '8.5.12及以上', PHP_VERSION, 'ok'],
        ];
        if (version_compare(PHP_VERSION, $items['php'][1], '<')) {
            $items['php'][4] = 'no';
            session('install_error', true);
        }
        /*
        $tmp = function_exists('gd_info') ? gd_info() : [];
        if (empty($tmp['GD Version'])) {
            $items['gd'][3] = lang('install/not_installed');
            $items['gd'][4] = 'no';
            session('install_error', true);
        } else {
            $items['gd'][3] = $tmp['GD Version'];
        }
        */
        return $items;
    }
    
    /**
     * 目录权限检查
     * @return array
     */
    private function checkDir()
    {
        $items = [
            ['file', './application/database.php', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['file', './application/route.php', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', './application/extra', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', './application/data/backup', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', './application/data/update', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', './runtime', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', './upload', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            // 可选项（不阻断安装）：后台「模板 → 广告管理」保存 ads/slots.js 需要写权限
            ['dir', './template/default/ads', lang('install/read_and_write'), lang('install/read_and_write'), 'ok', 'optional'],
        ];
        foreach ($items as &$v) {
            if (!empty($v[5]) && $v[5] === 'optional') {
                if (!is_dir($v[1]) || !is_writable($v[1])) {
                    $v[3] = (is_dir($v[1]) ? lang('install/not_writable') : lang('install/not_found')) . '（可选：后台广告管理需要）';
                    $v[4] = 'warn';
                }
                continue;
            }
            if ($v[0] == 'dir') {// 文件夹
                if(!is_writable($v[1])) {
                    if(is_dir($v[1])) {
                        $v[3] = lang('install/not_writable');
                        $v[4] = 'no';
                    } else {
                        $v[3] = lang('install/not_found');
                        $v[4] = 'no';
                    }
                    session('install_error', true);
                }
            } else {// 文件
                if(!is_writable($v[1])) {
                    $v[3] = lang('install/not_writable');
                    $v[4] = 'no';
                    session('install_error', true);
                }
            }
        }
        return $items;
    }
    
    /**
     * 函数及扩展检查
     * @return array
     */
    private function checkFunc()
    {
        $items = [
            ['pdo', lang('install/support'), 'yes',lang('install/class')],
            ['think\\captcha\\Captcha', lang('install/support'), 'yes', '类'],
            ['pdo_mysql', lang('install/support'), 'yes', lang('install/model')],
            ['gd', lang('install/support'), 'yes', lang('install/model')],
            ['imagettftext', lang('install/support'), 'yes', lang('install/function')],
            ['imagepng', lang('install/support'), 'yes', lang('install/function')],
            ['zip', lang('install/support'), 'yes', lang('install/model')],
            ['fileinfo', lang('install/support'), 'yes', lang('install/model')],
            ['curl', lang('install/support'), 'yes', lang('install/model')],
            ['xml', lang('install/support'), 'yes', lang('install/function')],
            ['file_get_contents', lang('install/support'), 'yes', lang('install/function')],
            ['mb_strlen', lang('install/support'), 'yes', lang('install/function')],
        ];

        if(version_compare(PHP_VERSION,'5.6.0','ge') && version_compare(PHP_VERSION,'5.7.0','lt')){
            $items[] = ['always_populate_raw_post_data',lang('install/support'),'yes',lang('install/config')];
        }

        foreach ($items as &$v) {
            if(('类'==$v[3] && !class_exists($v[0])) || (lang('install/model')==$v[3] && !extension_loaded($v[0])) || (lang('install/function')==$v[3] && !function_exists($v[0])) || (lang('install/config')==$v[3] && ini_get('always_populate_raw_post_data')!=-1)) {
                $v[1] = lang('install/not_support');
                $v[2] = 'no';
                session('install_error', true);
            }
        }

        return $items;
    }
    
    /**
     * 生成数据库配置文件
     * @return array
     */
    /** 后台入口文件名（本包默认 admina.php，兼容改名回 admin.php 的情况） */
    private static function adminEntry()
    {
        return is_file(ROOT_PATH . 'admina.php') ? 'admina.php' : 'admin.php';
    }

    private function mkDatabase(array $data)
    {
        // 以现有 database.php 为默认结构，逐字段校验后赋值，避免字符串插值生成 PHP 源码
        $config = include APP_PATH.'database.php';
        if (!is_array($config)) {
            $config = [];
        }
        $hostname = (string)$data['hostname'];
        if (!filter_var($hostname, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9._-]{1,128}$/', $hostname)) {
            return $this->error('数据库配置格式错误：hostname');
        }
        $database = (string)$data['database'];
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database)) {
            return $this->error('数据库配置格式错误：database');
        }
        $username = (string)$data['username'];
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $username)) {
            return $this->error('数据库配置格式错误：username');
        }
        $prefix = (string)$data['prefix'];
        if (!preg_match('/^[a-z0-9]{1,20}_$/', $prefix)) {
            return $this->error('数据库配置格式错误：prefix');
        }
        $config['hostname'] = $hostname;
        $config['hostport'] = (int)$data['hostport'];
        $config['database'] = $database;
        $config['username'] = $username;
        // 密码原样写入，由 var_export 负责转义
        $config['password'] = (string)$data['password'];
        $config['prefix']   = $prefix;
        $code = "<?php\nreturn " . var_export($config, true) . ";\n";
        file_put_contents(APP_PATH.'database.php', $code);
        // OPcache 下立即 include 会读到旧缓存，导致误报"写入失败"
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(APP_PATH.'database.php', true);
        }
        // 判断写入是否成功
        $config = include APP_PATH.'database.php';
        if (empty($config['database']) || $config['database'] != $data['database']) {
            return $this->error('[application/database.php]'.lang('write_err_database'));
            exit;
        }
    }
}
