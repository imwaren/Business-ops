<?php
chdir(dirname(__DIR__,2));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
$socket=getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/billing_deposit_fixture_20261021/mysql.sock';
if(!str_starts_with($socket,'/private/tmp/')){throw new RuntimeException('Local fixture socket required');}
$db=new CodeIgniter\Database\MySQLi\Connection(['hostname'=>$socket,'username'=>'root','password'=>'','database'=>'shared_funds_fixture','DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci']);
$db->initialize();echo "READY\n";flush();$db->transBegin();
echo App\Models\Shared_procurement_number::next($db,'00',2099);$db->transCommit();
