<?php
/** Real controller/model writes against disposable local MariaDB schemas only. */
foreach (['MASTER_DB'=>'journal_shared_fixture','OAKRIDGE_DB'=>'journal_clinic_fixture',
    'MOBILE_DB'=>'journal_empty_fixture','MAC_DB'=>'journal_empty_fixture','CONSO_DB'=>'journal_empty_fixture','MANTLEWOOD_DB'=>'journal_empty_fixture'] as $key=>$value) {define($key,$value);}
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $e): void {fwrite(STDERR,$e."\n");exit(1);});
use CodeIgniter\Database\MySQLi\Connection;
$socket=getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/billing_deposit_fixture_20261021/mysql.sock';
if (!str_starts_with($socket,'/private/tmp/')) {throw new RuntimeException('Local fixture socket only');}
$pdo=new PDO('mysql:unix_socket='.$socket,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$connections=[];$checks=0;
function check($ok,$message) {global $checks;if (!$ok) {throw new RuntimeException($message);} $checks++;}
foreach ([MASTER_DB,OAKRIDGE_DB,MOBILE_DB] as $schema) {
    $pdo->exec("DROP DATABASE IF EXISTS $schema");$pdo->exec("CREATE DATABASE $schema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE $schema");
    $pdo->exec("CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(100),remarks TEXT,
        total_debit DECIMAL(13,4) DEFAULT 0,total_credit DECIMAL(13,4) DEFAULT 0,status VARCHAR(30) DEFAULT 'pending',
        added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0,is_migrated INT DEFAULT 0,
        approved_by INT,approved_on DATETIME,disapproved_by INT,disapproved_on DATETIME,
        approved_by_general_accountant INT,approved_on_general_accountant DATETIME,
        approved_by_finance_manager INT,approved_on_finance_manager DATETIME,
        approved_by_finance_director INT,approved_on_finance_director DATETIME) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,journal_entry_id INT,account_type_id INT NOT NULL,
        capital_expenditure_id INT,cost_center_id INT,direct_payment_id INT,type VARCHAR(100),remarks TEXT,debit DECIMAL(13,4),credit DECIMAL(13,4),
        added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB");
    $connections[$schema]=new Connection(['hostname'=>$socket,'username'=>'root','password'=>'','database'=>$schema,'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci']);
    $connections[$schema]->initialize();
}
$master=$connections[MASTER_DB];$clinic=$connections[OAKRIDGE_DB];
$master->query('CREATE TABLE branch(id INT PRIMARY KEY,name VARCHAR(100),type VARCHAR(30),is_deleted INT DEFAULT 0)');
$master->query("INSERT INTO branch VALUES(2,'Clinic','clinic',0),(21,'Sales','shared',0),(22,'HR','shared',0),(99,'Future','shared',0),(100,'Deleted','shared',1)");
$master->query('CREATE TABLE user(id INT PRIMARY KEY,first_name VARCHAR(100),last_name VARCHAR(100),role_id INT,is_deleted INT DEFAULT 0)');
$master->query("INSERT INTO user VALUES(1,'Admin','User',4,0),(2,'Unprivileged','User',1,0)");
$master->query('CREATE TABLE account_type(id INT PRIMARY KEY,name VARCHAR(100),is_deleted INT DEFAULT 0)');
$master->query("INSERT INTO account_type VALUES(1,'Account',0)");
$cache=new ReflectionProperty(CodeIgniter\Database\Config::class,'instances');$cache->setAccessible(true);
$cache->setValue(null,['master'=>$master,'default'=>$master,'tests'=>$master,'oakridge'=>$clinic,
    'mobile'=>$connections[MOBILE_DB],'mactan'=>$connections[MOBILE_DB],'consolacion'=>$connections[MOBILE_DB],'mantlewood'=>$connections[MOBILE_DB]]);
class SharedJournalControllerFixture extends App\Controllers\Journal_entries {
    public function configure(array $payload) {foreach (['master','oakridge'] as $group) {db_connect($group)->resetTransStatus();} $this->payload=$payload;$this->user=new App\Models\User(db_connect('master'));$this->db=db_connect('master');}
    protected function _api_validation(){return true;}
    protected function _create_response($status,$data,$skip_response_logging=false){return ['status'=>$status,'data'=>$data];}
}
$c=new SharedJournalControllerFixture();
function payload($center,$remarks='fixture') {return ['cost_center_id'=>$center,'requester'=>1,'date'=>'2026-10-23','remarks'=>$remarks,'items'=>[
    ['account_type_id'=>1,'debit'=>'100','credit'=>'0','type'=>'bank_adjustments'],['account_type_id'=>1,'debit'=>'0','credit'=>'100','type'=>'payroll']]];}
function createJournal($c,$center) {$c->configure(payload($center));$r=$c->create();check($r['status']===SUCCESS,'Create center '.$center);return (int)$r['data']['journal_entry_id'];}
$ids=[];
foreach ([21,22,99,2] as $center) {
    $id=$ids[$center]=createJournal($c,$center);$db=$center===2?$clinic:$master;
    $row=$db->query('SELECT * FROM journal_entry WHERE id=?',[$id])->getRowArray();
    check((int)$row['cost_center_id']===$center,'Actual manual center retained');
    check((float)$row['total_debit']===100.0 && (float)$row['total_credit']===100.0,'Header totals saved');
    $c->configure(['cost_center_id'=>$center,'journal_entry_id'=>$id]);$r=$c->get();
    check($r['status']===SUCCESS && count($r['data']['data'][0]['items'])===2,'Scoped GET returns matching items');
    check(($center===2 ? (int)$r['data']['data'][0]['branch_id']===2 : $r['data']['data'][0]['branch_id']===null),'Master response marker retained');
    $edit=payload($center,'updated');$edit['journal_entry_id']=$id;
    $edit['items']=$db->query('SELECT * FROM journal_entry_item WHERE journal_entry_id=?',[$id])->getResultArray();
    $c->configure($edit);check($c->update()['status']===SUCCESS,'Own journal update');
}
check($ids[21]===$ids[2],'Master/clinic collision fixture');
$foreign=$master->query('SELECT id FROM journal_entry_item WHERE journal_entry_id=? LIMIT 1',[$ids[22]])->getRowArray()['id'];
$bad=payload(21,'must roll back');$bad['journal_entry_id']=$ids[21];$bad['items'][0]['id']=$foreign;
$c->configure($bad);check($c->update()['status']===FAIL,'Foreign-center child ID rejected');
check($master->query('SELECT remarks FROM journal_entry WHERE id=?',[$ids[21]])->getRowArray()['remarks']==='updated','Header edit rolled back on foreign child');
$secondSame=createJournal($c,21);
$bad['items'][0]['id']=$master->query('SELECT id FROM journal_entry_item WHERE journal_entry_id=? LIMIT 1',[$secondSame])->getRowArray()['id'];
$c->configure($bad);check($c->update()['status']===FAIL,'Other journal child in same center rejected');
foreach (['update','change_status','delete'] as $action) {
    $bad=payload(21);$bad['journal_entry_id']=$ids[22];$bad['status']='disapproved';$c->configure($bad);
    check($c->$action()['status']===FAIL_NOT_FOUND,'Cross-center '.$action.' rejected');
}
$c->configure(['cost_center_id'=>21,'journal_entry_id'=>$ids[22]]);check($c->get()['status']===FAIL_NOT_FOUND,'Cross-center detail rejected');
// Database exception after a successful header and first item must roll back both.
foreach ([21,2] as $center) {
    $db=$center===2?$clinic:$master;
    $before=$db->query('SELECT COUNT(*) AS n FROM journal_entry')->getRowArray()['n'];
    $bad=payload($center);unset($bad['items'][1]['account_type_id']);$c->configure($bad);
    check($c->create()['status']===FAIL,'Item insert failure returned safely');
    check($db->query('SELECT COUNT(*) AS n FROM journal_entry')->getRowArray()['n']===$before,'Resolved connection rolls back header');
    check($db->query('SELECT COUNT(*) AS n FROM journal_entry_item i LEFT JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.id IS NULL')->getRowArray()['n']==0,'No orphan items after rollback');
}
foreach ([21,22,99,2] as $center) {
    $db=$center===2?$clinic:$master;
    for ($stage=0;$stage<3;$stage++) {$c->configure(['cost_center_id'=>$center,'journal_entry_id'=>$ids[$center],'requester'=>1,'status'=>'approved']);check($c->change_status()['status']===SUCCESS,'Existing approval stage succeeds');}
    check($db->query('SELECT status FROM journal_entry WHERE id=?',[$ids[$center]])->getRowArray()['status']==='approved','Final approval persists');
    $c->configure(['cost_center_id'=>$center,'journal_entry_id'=>$ids[$center],'requester'=>1,'status'=>'disapproved']);check($c->change_status()['status']===SUCCESS,'Disapproval succeeds');
}
$master->query("INSERT INTO journal_entry(cost_center_id,date,remarks,status) VALUES(0,'2026-10-23','automated shared','approved')");
$model=new App\Models\Journal_entry($master);
$all=$model->get_details(null,null,null,1,100);
check(count($all)===6 && $model->get_details_count()===6,'Unfiltered results include master journals once');
check(count(array_filter($all,fn($r)=>$r['cost_center_id']==0))===1,'ID-zero history unchanged');
check($model->get_details_count(null,null,null,21)===2,'Shared counts isolated');
$a=$model->get_details(null,null,null,1,1,21);$b=$model->get_details(null,null,null,2,1,21);
check(count($a)===1 && count($b)===1 && $a[0]['id']!==$b[0]['id'],'Shared pagination distinct');
$c->configure(['branch_id'=>21,'status'=>'disapproved','term'=>'updated','date_from'=>'2026-10-23','date_to'=>'2026-10-23']);
$filtered=$c->get();
check($filtered['status']===SUCCESS && $filtered['data']['size']===1,'Shared GET alias/status/term/date/count filters');
$headers=(new App\Models\Journal_entry($master))->with_journal_cost_center(21);
check(!$headers->select('', ['id'=>$ids[22]],1),'Scoped model hides other center header');
check(!$headers->update(['id'=>$ids[21]],['cost_center_id'=>null]),'Scoped model cannot erase ownership');
check(!$headers->insert(['cost_center_id'=>22,'date'=>'2026-10-23']),'Scoped model rejects foreign owner insert');
$children=(new App\Models\Journal_entry_item($master))->with_journal_cost_center(21)->with_journal_parent($ids[21]);
check(!$children->select('', ['id'=>$foreign],1),'Scoped model hides foreign child');
check(!$children->insert(['journal_entry_id'=>$ids[22],'account_type_id'=>1]),'Scoped model rejects foreign parent');
$c->configure(['cost_center_id'=>21,'journal_entry_id'=>$master->insertID()]);
check(count(array_filter($model->get_details(null,null,null,1,100,21),fn($r)=>$r['cost_center_id']==0))===0,'Shared filter excludes automated accounting zero');
foreach ([100,777,'invalid',[]] as $bad) {$c->configure(payload($bad));check($c->create()['status']===FAIL,'Invalid/deleted center rejected');}
$c->configure(['cost_center_id'=>21,'journal_entry_id'=>$ids[21],'requester'=>2,'status'=>'approved']);check($c->change_status()['status']===FAIL,'Approval permission retained');
$zero=payload(99);$zero['items'][0]['debit']=0;$zero['items'][1]['credit']=0;$c->configure($zero);check($c->create()['status']===SUCCESS,'Zero items retain existing validation behavior');
$c->configure(['cost_center_id'=>22,'journal_entry_id'=>$ids[22],'requester'=>1]);check($c->delete()['status']===SUCCESS,'Own delete succeeds');
$c->configure(['cost_center_id'=>22,'journal_entry_id'=>$ids[22]]);check($c->get()['status']===FAIL_NOT_FOUND,'Deleted journal hidden');
echo "Passed $checks isolated shared journal checks.\n";
