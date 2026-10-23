<?php
/** Disposable local MariaDB fixtures only; no application database connections. */
define('MASTER_DB','reimbursement_combined_fixture');
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR,$e."\n");exit(1); });
use CodeIgniter\Database\MySQLi\Connection;
$socket=getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/billing_deposit_fixture_20261021/mysql.sock';
if (!str_starts_with($socket,'/private/tmp/')) { throw new RuntimeException('Isolated local socket required'); }
function connection($database) { global $socket; $db=new Connection(['hostname'=>$socket,'username'=>'root','password'=>'','database'=>$database,'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci']);$db->initialize();return $db; }
$admin=connection('mysql');$checks=0;
function check($ok,$message) {global $checks;if(!$ok){throw new RuntimeException($message);}$checks++;}
$names=['reimbursement_combined_fixture','reimbursement_combined_clinic_fixture'];
foreach ($names as $name) {$admin->query("DROP DATABASE IF EXISTS $name");$admin->query("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
$master=connection($names[0]);$clinic=connection($names[1]);
$master->query("CREATE TABLE user(id INT PRIMARY KEY,first_name VARCHAR(50),last_name VARCHAR(50),is_deleted INT DEFAULT 0)");
$master->query("INSERT INTO user VALUES(1,'Master','Liquidator',0),(2,'Deleted','User',1)");
$master->query("CREATE TABLE department(id INT PRIMARY KEY,name VARCHAR(50))");
$master->query("INSERT INTO department VALUES(1,'Finance')");
$master->query("CREATE TABLE branch(id INT PRIMARY KEY,name VARCHAR(50),code VARCHAR(10),type VARCHAR(20),is_deleted INT DEFAULT 0)");
$master->query("INSERT INTO branch VALUES(4,'Consolacion','4','clinic',0),(20,'Shared Finance','0','shared',0),(21,'Shared IT','0','shared',0)");
$master->query('CREATE TABLE account_type(id INT PRIMARY KEY,name VARCHAR(50),is_deleted INT DEFAULT 0)');
$master->query('CREATE TABLE supplier(id INT PRIMARY KEY,name VARCHAR(50),is_deleted INT DEFAULT 0)');
$columns=['id'=>'INT PRIMARY KEY','liquidated_by_id'=>'INT','department_id'=>'INT','cost_center_id'=>'INT','due_date'=>'DATE','grand_total'=>'DECIMAL(13,4)','status'=>'VARCHAR(30)','disapproved_reason'=>'TEXT','approved_by'=>'INT','approved_on'=>'DATETIME','disapproved_by'=>'INT','disapproved_on'=>'DATETIME','added_by'=>'INT','added_on'=>'DATETIME','updated_by'=>'INT','updated_on'=>'DATETIME','is_deleted'=>'INT DEFAULT 0'];
foreach ([$master,$clinic] as $db) {
    $fields=[];foreach($columns as $field=>$type){$fields[]="$field $type";}
    $db->query('CREATE TABLE reimbursement('.implode(',',$fields).')');
    // Deliberately different physical order, one extra business column, plus an optional remarks column.
    $fields=[];foreach(array_reverse($columns,true) as $field=>$type){$fields[]="$field $type";}
    $db->query('CREATE TABLE replenishment(petty_cash_id INT,remarks TEXT,'.implode(',',$fields).')');
    foreach (['reimbursement','replenishment'] as $type) {
        $db->query("CREATE TABLE {$type}_item(id INT PRIMARY KEY,{$type}_id INT,account_type_id INT,supplier_id INT,invoice_no TEXT,tin TEXT,address TEXT,description TEXT,expense_date DATE,amount DECIMAL(13,4),vat DECIMAL(13,4),total_value DECIMAL(13,4),total DECIMAL(13,4),remarks TEXT,is_vat INT,is_deleted INT DEFAULT 0)");
        $db->query("CREATE TABLE {$type}_attachment(id INT PRIMARY KEY,{$type}_id INT,file_name TEXT,file_path TEXT,file_url TEXT,mime TEXT,is_deleted INT DEFAULT 0)");
    }
    $owner=$db===$master?20:4;$other=$db===$master?21:4;
    $db->query("INSERT INTO reimbursement(id,liquidated_by_id,department_id,cost_center_id,due_date,grand_total,status,is_deleted) VALUES(1,1,1,?,'2026-10-21',100,'pending',0),(2,1,1,?,'2026-10-21',200,'pending',0),(3,1,1,?,'2026-10-22',300,'pending',1),(4,1,1,?,'2026-10-23',400,'approved',0)",[$owner,$other,$owner,$owner]);
    $db->query("INSERT INTO replenishment(id,liquidated_by_id,department_id,cost_center_id,due_date,grand_total,status,petty_cash_id,remarks,is_deleted) VALUES(1,1,1,?,'2026-10-21',500,'pending',77,'Taxi receipt',0)",[$owner]);
    foreach (['reimbursement','replenishment'] as $type) {
        $db->query("INSERT INTO {$type}_item(id,{$type}_id,description,is_deleted) VALUES(1,1,?,0),(2,2,'other center child',0)",[$type.' child']);
        $db->query("INSERT INTO {$type}_attachment(id,{$type}_id,file_name,is_deleted) VALUES(1,1,?,0),(2,2,'other.pdf',0)",[$type.'.pdf']);
    }
}
$cache=new ReflectionProperty(CodeIgniter\Database\Config::class,'instances');$cache->setAccessible(true);
$cache->setValue(null,['master'=>$master,'default'=>$master,'tests'=>$master,'consolacion'=>$clinic]);
try {
    $model=(new App\Models\Reimbursement($master))->with_shared_cost_centers(20);
    $rows=$model->get_all_details(null,null,'pending');
    check(count($rows)===2,'Different source widths/order produce two scoped pending rows');
    check($rows[0]['form_type']==='reimbursement' && $rows[1]['form_type']==='replenishment','Equal due date/ID uses deterministic form type');
    check($rows[0]['petty_cash_id']===null && (int)$rows[1]['petty_cash_id']===77,'petty_cash_id remains correctly aligned');
    check($rows[0]['remarks']===null && $rows[1]['remarks']==='Taxi receipt','Legacy missing remarks and present remarks align');
    check($rows[0]['liquidated_by_name']==='Master Liquidator','Master liquidator name selected in SQL');
    check(count($model->get_all_details(null,null,'pending','Master Liquidator'))===2,'Search by joined liquidator name works');
    check(count($model->get_all_details(null,null,'pending','Taxi'))===1,'Search by remarks works when one source lacks remarks');
    check(count($model->get_all_details(1,1,'pending'))===2,'Overlapping IDs and department filter retain both forms');
    check($model->get_all_details(null,2,'pending')===[],'Wrong department excluded');
    check(count($model->get_all_details(null,null,'approved'))===1,'Status filter and soft deletion preserved');
    $page1=$model->get_all_details(null,null,'pending',null,1,1);$page2=$model->get_all_details(null,null,'pending',null,2,1);
    check(array_merge($page1,$page2)===$rows,'Pagination matches complete deterministic ordering');
    check(count((new App\Models\Reimbursement($clinic))->get_all_details(null,null,'pending'))===3,'Clinic routing keeps combined rows');
    check((new App\Models\Reimbursement($master))->with_shared_cost_centers(21)->get_all_details(1,null,'pending')===[],'Other shared center cannot read matching source IDs');
    $master->query('UPDATE reimbursement SET liquidated_by_id=2 WHERE id=1');
    check($model->get_all_details(1,null,'pending')[0]['liquidated_by_name']===null,'Deleted liquidator has null SQL name');
    $master->query('UPDATE reimbursement SET liquidated_by_id=1 WHERE id=1');
    class CombinedReimbursementFixtureController extends App\Controllers\Reimbursements {
        public function configure(array $payload) {$this->_load_essentials();$this->payload=$payload;}
        protected function _api_validation(){return true;}
        protected function _create_response($status,$data_response,$skip_response_logging=false){return ['status'=>$status,'data'=>$data_response];}
    }
    foreach ([20=>2,4=>3] as $center=>$expected) {
        $controller=(new ReflectionClass(CombinedReimbursementFixtureController::class))->newInstanceWithoutConstructor();
        $controller->configure(['cost_center_id'=>$center,'status'=>'pending','page'=>1,'data_size'=>10]);
        $response=$controller->get_all();check($response['status']===SUCCESS && $response['data']['size']===$expected,'Real controller combined GET count for center '.$center);
        foreach($response['data']['data'] as $row) {
            check((int)$row['cost_center_id']===$center,'Controller source belongs to requested center');
            if ((int)$row['id']===1) {
                check($row['items'][0]['description']===$row['form_type'].' child','Items follow form type despite overlapping IDs');
                check($row['attachments'][0]->file_name===$row['form_type'].'.pdf','Attachments follow form type despite overlapping IDs');
            }
        }
        $controller->configure(['cost_center_id'=>$center,'status'=>'pending','page'=>1,'data_size'=>1,'term'=>'Master Liquidator']);
        $response=$controller->get_all();check($response['status']===SUCCESS && count($response['data']['data'])===1 && $response['data']['size']===$expected,'Filtered controller count remains independent of page');
    }
} finally {
    $master->close();$clinic->close();
    foreach($names as $name){$admin->query("DROP DATABASE IF EXISTS $name");}
    $admin->close();
}
echo "PASS $checks reimbursement/replenishment combined GET checks\n";
