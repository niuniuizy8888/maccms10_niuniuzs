<?php
// Run: php tests/store-requeue-regression.php /path/to/store (PDO SQLite required).
// Uses an in-memory database only, never production configuration.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root=$argv[1]??'';
if(!is_file($root.'/model/rsync.php'))throw new RuntimeException('Pass store root');
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE rsync_tasks(id INTEGER PRIMARY KEY,status TEXT,worker TEXT,done_pages INTEGER,fail_pages INTEGER,next_page INTEGER,last_error TEXT,finished_at INTEGER,heartbeat_at INTEGER,created_at INTEGER)");
$p->exec("INSERT INTO rsync_tasks VALUES(1,'stopped','w1',2,0,3,'',1,1,1)");
function db_exec($sql,$args=[]){global $p;$s=$p->prepare($sql);$s->execute($args);return $s;}
function store_now(){return time();}
require $root.'/model/rsync.php';
$n=0;function check($v){global $n;if(!$v)throw new RuntimeException('Check '.($n+1).' failed');$n++;}
check(rsync_requeue(['id'=>1],false)==='busy');
check(rsync_requeue(['id'=>1],true)==='busy');
check((int)$p->query('SELECT done_pages FROM rsync_tasks')->fetchColumn()===2);
$p->exec("UPDATE rsync_tasks SET worker=''");
check(rsync_requeue(['id'=>1],false)==='resume');
check((int)$p->query('SELECT next_page FROM rsync_tasks')->fetchColumn()===3);
check(rsync_requeue(['id'=>1],false)==='busy');
check(rsync_requeue(['id'=>1],true)==='busy');
$p->exec("UPDATE rsync_tasks SET status='done'");
check(rsync_requeue(['id'=>1],true)==='restart');
check((int)$p->query('SELECT done_pages+next_page+fail_pages FROM rsync_tasks')->fetchColumn()===0);
echo "PASS: $n store requeue checks\n";
