<?php
/** Dedicated local database only: optional memo allocations and atomic writes. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string) $error . PHP_EOL); exit(1); });
use App\Libraries\Memo_allocations;
use CodeIgniter\Database\MySQLi\Connection;
$socket = getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/billing_deposit_fixture_20261021/mysql.sock';
if (!str_starts_with($socket, '/private/tmp/')) { throw new RuntimeException('Local fixture socket required.'); }
$options = ['hostname'=>$socket,'username'=>'root','password'=>'','database'=>'','DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci'];
$db = new Connection($options); $db->initialize();
$db->query('CREATE DATABASE IF NOT EXISTS memo_allocations_fixture');
$db->query('USE memo_allocations_fixture');
foreach (['credit_memo_entries','debit_memo_entries','credit_memo','debit_memo','billing_entry','billing'] as $table) { $db->query("DROP TABLE IF EXISTS $table"); }
$db->query('CREATE TABLE billing (id INT PRIMARY KEY,is_deleted INT DEFAULT 0,status VARCHAR(20),balance DECIMAL(13,2),credit_memo_total DECIMAL(13,2) DEFAULT 0,debit_memo_total DECIMAL(13,2) DEFAULT 0,updated_by INT,updated_on DATETIME) ENGINE=InnoDB');
$db->query("INSERT INTO billing(id,status,balance) VALUES (10,'open_bill',100)");
$db->query('CREATE TABLE billing_entry (id INT PRIMARY KEY,billing_id INT,branch_id INT,balance DECIMAL(13,2) DEFAULT 100,amount DECIMAL(13,2) DEFAULT 100,status VARCHAR(20) DEFAULT \'open_bill\',updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB');
$db->query('INSERT INTO billing_entry(id,billing_id,branch_id,is_deleted) VALUES (1,10,2,0),(2,10,2,0),(3,20,2,0),(4,10,4,0),(5,10,2,1)');
foreach (['credit','debit'] as $kind) {
    $db->query("CREATE TABLE {$kind}_memo (id INT AUTO_INCREMENT PRIMARY KEY,billing_id INT,branch_id INT,amount DECIMAL(13,2),status VARCHAR(20),approved_by INT,approved_on DATETIME,disapproved_by INT,disapproved_on DATETIME,reason TEXT,remarks TEXT,debit_account_type_id INT,credit_account_type_id INT,date_issued DATE,control_no VARCHAR(100),added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB");
    $db->query("CREATE TABLE {$kind}_memo_entries (id INT AUTO_INCREMENT PRIMARY KEY,{$kind}_memo_id INT NOT NULL,billing_entry_id INT NOT NULL,billing_id INT NOT NULL,branch_id INT NOT NULL,amount DECIMAL(13,2) NOT NULL DEFAULT 0,added_by INT NOT NULL,added_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_by INT,updated_on TIMESTAMP NULL,is_deleted TINYINT DEFAULT 0) ENGINE=InnoDB");
}
$checks=0;
function check($ok,$label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function invalid(callable $fn,$label) { try { $fn(); } catch (InvalidArgumentException $e) { check(true,$label); return; } throw new RuntimeException($label); }
trait Fixture_memo_controller {
    public $fail_control = false;
    protected function _api_validation() { return true; }
    protected function _create_response($status, $data_response, $skip_response_logging = false) { return ['status'=>$status,'data'=>$data_response]; }
    protected function _validate_billing_branch($billing_id,$branch_id) { return true; }
    protected function _account_type_exists($id) { return true; }
    protected function _get_trade_receivable_account_type_id() { return 57; }
    protected function _attempt_generate_control_no($id,$branch) { return !$this->fail_control; }
    public function fixture($db,$kind,$payload) {
        $db->resetTransStatus(); // Simulate a fresh HTTP request after an intentional SQL failure.
        $this->db=$db; $this->payload=$payload; $this->billing=new App\Models\Billing($db); $this->billing_entry=new App\Models\Billing_entry($db);
        $class='App\\Models\\'.ucfirst($kind).'_memo'; $this->{$kind.'_memo'}=new $class($db);
        $class.='_entries'; $this->memo_entries=new $class($db);
    }
}
class Fixture_credit_memos extends App\Controllers\Credit_memos { use Fixture_memo_controller; }
class Fixture_debit_memos extends App\Controllers\Debit_memos { use Fixture_memo_controller; }
foreach (['credit','debit'] as $kind) {
    $type=$kind.'_memo'; $class='App\\Models\\'.ucfirst($kind).'_memo_entries'; $model=new $class($db);
    $rows=[['billing_entry_id'=>1,'amount'=>'0.10'],['billing_entry_id'=>2,'amount'=>'0.20']];
    $normalized=Memo_allocations::validate($model,$rows,'0.30',10,2);
    check($normalized[1]['amount']==='0.20' && $normalized[0]['billing_id']===10,'Exact cents and ownership');
    check(Memo_allocations::cents('99999999999.99')===9999999999999,'Decimal upper bound');
    foreach (['-1','0.001','1e2','100000000000','NaN',null,[],true] as $amount) { invalid(fn()=>Memo_allocations::cents($amount),'Reject malformed amount'); }
    foreach ([[],null,'bad',[['billing_entry_id'=>1,'amount'=>'0.30'],['billing_entry_id'=>1,'amount'=>'0.00']],[['billing_entry_id'=>3,'amount'=>'0.30']],[['billing_entry_id'=>4,'amount'=>'0.30']],[['billing_entry_id'=>5,'amount'=>'0.30']],[['billing_entry_id'=>999,'amount'=>'0.30']],[['billing_entry_id'=>1,'amount'=>'0.30','billing_id'=>20]],[['billing_entry_id'=>1,'amount'=>'0.30','branch_id'=>4]],[['billing_entry_id'=>1]],[['billing_entry_id'=>1,'amount'=>'0.29']]] as $bad) { invalid(fn()=>Memo_allocations::validate($model,$bad,'0.30',10,2),'Invalid allocations rejected'); }
    $controllerClass='Fixture_'.ucfirst($kind).'_memos'; $c=new $controllerClass();
    $payload=['billing_id'=>10,'branch_id'=>2,'amount'=>'0.30','reason'=>'fixture','debit_account_type_id'=>1,'credit_account_type_id'=>1,'requester'=>67,'memo_entries'=>$rows];
    $c->fixture($db,$kind,$payload);$r=$c->create();check($r['status']===SUCCESS,'Create controller success');$id=$r['data'][$type.'_id'];
    check(count($model->active_rows($id))===2,'Create children');
    foreach (['amount','billing_id','branch_id'] as $field) {
        $c->fixture($db,$kind,[$type.'_id'=>$id,'requester'=>67,$field=>null]);
        check($c->update()['status']===FAIL, 'Null header cannot invalidate retained children');
    }
    $c->fixture($db,$kind,[$type.'_id'=>$id,'requester'=>67,'amount'=>'0.40']);check($c->update()['status']===FAIL,'Retained children reject mismatched header');
    check($db->query("SELECT amount FROM $type WHERE id=?",[$id])->getRow()->amount==='0.30','Failed update preserves header');
    $replacement=[['billing_entry_id'=>2,'amount'=>'0.40']];
    $c->fixture($db,$kind,[$type.'_id'=>$id,'requester'=>67,'amount'=>'0.40','memo_entries'=>$replacement]);check($c->update()['status']===SUCCESS,'Replacement update');
    check(count($model->active_rows($id))===1 && $model->active_rows($id)[0]['amount']==='0.40','Replacement amount');
    check((int)$db->query("SELECT COUNT(*) n FROM {$type}_entries WHERE {$type}_id=? AND is_deleted=1",[$id])->getRow()->n===2,'Old children soft deleted');
    $c->fixture($db,$kind,[$type.'_id'=>$id,'requester'=>67,'remarks'=>'retained']);check($c->update()['status']===SUCCESS,'Omitted children retained');
    check($model->for_billings([10])===[], 'Pending parent hidden like existing billing memo headers');
    $db->query("UPDATE $type SET status='approved' WHERE id=?",[$id]);
    check(count($model->for_billings([10]))===1 && $model->for_billings([20])===[] && $model->for_billings([])===[],'Scoped batch reads');
    $db->query("UPDATE $type SET branch_id=4 WHERE id=?",[$id]);check($model->for_billings([10])===[],'Mismatched parent excluded');$db->query("UPDATE $type SET branch_id=2 WHERE id=?",[$id]);
    $db->query("UPDATE $type SET status='pending' WHERE id=?",[$id]);
    $before=(int)$db->query("SELECT COUNT(*) n FROM $type")->getRow()->n;
    $c->fail_control=true;$c->fixture($db,$kind,$payload);check($c->create()['status']===FAIL,'Control failure');
    check((int)$db->query("SELECT COUNT(*) n FROM $type")->getRow()->n===$before,'Control failure rolls back header and children');$c->fail_control=false;
    $db->query("CREATE TRIGGER fixture_{$kind}_fail BEFORE INSERT ON {$type}_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture insertion failure'");
    $c->fixture($db,$kind,[$type.'_id'=>$id,'requester'=>67,'amount'=>'0.30','memo_entries'=>$rows]);check($c->update()['status']===FAIL,'Child insert error handled');
    check($model->active_rows($id)[0]['amount']==='0.40','Failed replacement restores old children');
    $c->fixture($db,$kind,$payload);check($c->create()['status']===FAIL,'Create child failure handled');
    check((int)$db->query("SELECT COUNT(*) n FROM $type")->getRow()->n===$before,'Child error rolls back parent');
    $db->query("DROP TRIGGER fixture_{$kind}_fail");
    $c->fixture($db,$kind,[$type.'_id'=>$id,'requester'=>67]);check($c->delete()['status']===SUCCESS,'Pending delete');check($model->active_rows($id)===[] && $model->for_billings([10])===[],'Deleted children omitted');
    unset($payload['memo_entries']);$c->fixture($db,$kind,$payload);$r=$c->create();check($r['status']===SUCCESS,'Legacy header only');$legacy=$r['data'][$type.'_id'];check($model->active_rows($legacy)===[],'No inferred allocations');
    // Existing memo lock serializes approval, update and deletion on the same row.
    $otherOptions=$options;$otherOptions['database']='memo_allocations_fixture';$other=new Connection($otherOptions);$other->initialize();$other->query('SET innodb_lock_wait_timeout=1');
    $db->transBegin();$memoClass='App\\Models\\'.ucfirst($kind).'_memo';(new $memoClass($db))->get_for_update($legacy);
    try { $other->query("UPDATE $type SET status='approved' WHERE id=?",[$legacy]);throw new RuntimeException('Expected lock conflict'); } catch (CodeIgniter\Database\Exceptions\DatabaseException $e) { check(str_contains($e->getMessage(),'Lock wait timeout'),'Memo row lock excludes concurrent approval'); }
    $db->transRollback();$other->close();
    $c->fixture($db,$kind,[$type.'_id'=>$legacy,'requester'=>67,'status'=>'approved']);
    check($c->change_status()['status']===SUCCESS, 'Existing approval flow remains successful');
    $c->fixture($db,$kind,[$type.'_id'=>$legacy,'requester'=>67,'amount'=>'0.40']);
    check($c->update()['status']===FAIL, 'Approved memo cannot be edited');
}
// Exercise the real billing response assembler with fixture-only header/entry adapters.
class Fixture_billing_headers {
    public function get_details(...$args) { return [['id'=>($args[6] ?? 1)===2 ? 20 : 10,'company_id'=>1,'charge_to'=>1]]; }
    public function count_details(...$args) { return 7; }
}
class Fixture_billing_entries {
    public function get_details_for_billing_ids($ids) { return ['entries'=>$ids===[20] ? [['id'=>3,'billing_id'=>20,'branch_id'=>2]] : [['id'=>1,'billing_id'=>10,'branch_id'=>2],['id'=>2,'billing_id'=>10,'branch_id'=>2]]]; }
}
class Fixture_billing_memos {
    public function get_details_for_billing_ids($ids) { return [['id'=>123,'billing_id'=>10]]; }
}
class Fixture_billings extends App\Controllers\Billings {
    public function fixture($db,$page=1) {
        $this->db=$db;$this->payload=['page'=>$page,'data_size'=>1];
        $this->billing=new Fixture_billing_headers();$this->billing_entry=new Fixture_billing_entries();
        $this->credit_memo=new Fixture_billing_memos();$this->debit_memo=new Fixture_billing_memos();
    }
    protected function _api_validation() { return true; }
    protected function _create_response($status,$data_response,$skip_response_logging=false) { return ['status'=>$status,'data'=>$data_response]; }
    protected function _companies_by_ids(array $ids): array { return []; }
    protected function _branch_names(): array { return [2=>'Fixture']; }
    protected function _decorate_billing_entry(array $entry,$charge_to=null,$include_clinic_extras=true): array { return $entry; }
}
foreach (['credit','debit'] as $kind) {
    $db->query("INSERT INTO {$kind}_memo(billing_id,branch_id,amount,status) VALUES (10,2,0.30,'approved')");$memo_id=$db->insertID();
    $cls='App\\Models\\'.ucfirst($kind).'_memo_entries';$m=new $cls($db);
    $m->replace_rows($memo_id,[['billing_entry_id'=>1,'billing_id'=>10,'branch_id'=>2,'amount'=>'0.30']],67);
}
$c=new Fixture_billings();$c->fixture($db);$r=$c->get();
check($r['status']===SUCCESS && $r['data']['size']===7 && count($r['data']['data'])===1,'Billing count/page preserved');
$bill=$r['data']['data'][0];
check(count($bill['entries'][0]['credit_memo_entries'])===1 && count($bill['entries'][0]['debit_memo_entries'])===1,'Children on matching entry');
check($bill['entries'][1]['credit_memo_entries']===[] && $bill['entries'][1]['debit_memo_entries']===[],'Empty arrays on unrelated entry');
check(count($bill['credit_memo'])===1 && count($bill['debit_memo'])===1,'Existing header-level arrays preserved');
check($bill['entries'][0]['credit_memo_total']==='0.30' && $bill['entries'][0]['debit_memo_total']==='0.30','Initial per-entry totals');
check($bill['entries'][1]['credit_memo_total']==='0.00' && $bill['entries'][1]['debit_memo_total']==='0.00','Empty totals formatted');
foreach ([['credit','approved',0,'0.10',0,2],['credit','approved',0,'0.20',0,2],['debit','approved',0,'1.23',0,2],['credit','pending',0,'50.00',0,2],['credit','approved',1,'50.00',0,2],['debit','approved',0,'50.00',1,2],['debit','approved',0,'50.00',0,4]] as [$kind,$status,$deleted,$amount,$child_deleted,$branch]) {
    $db->query("INSERT INTO {$kind}_memo(billing_id,branch_id,amount,status,is_deleted) VALUES (10,?,?,?,?)",[$branch,$amount,$status,$deleted]);$mid=$db->insertID();
    $db->query("INSERT INTO {$kind}_memo_entries({$kind}_memo_id,billing_entry_id,billing_id,branch_id,amount,added_by,is_deleted) VALUES (?,1,10,?,?,67,?)",[$mid,$branch,$amount,$child_deleted]);
}
$r=$c->get();$entry=$r['data']['data'][0]['entries'][0];
check($entry['credit_memo_total']==='0.60' && $entry['debit_memo_total']==='1.53','Independent multi-memo exact totals exclude inactive and wrong ownership');
check(count($entry['credit_memo_entries'])===3 && count($entry['debit_memo_entries'])===2,'Totals use precisely the exposed child arrays');
check($r['data']['size']===7 && $r['data']['data'][0]['entries'][1]['credit_memo_total']==='0.00','Count and entry isolation preserved');
$db->query("INSERT INTO credit_memo(billing_id,branch_id,amount,status) VALUES (20,2,2.40,'approved')");$mid=$db->insertID();
$db->query('INSERT INTO credit_memo_entries(credit_memo_id,billing_entry_id,billing_id,branch_id,amount,added_by) VALUES (?,3,20,2,2.40,67)',[$mid]);
$c->fixture($db,2);$page2=$c->get();$entry2=$page2['data']['data'][0]['entries'][0];
check((int)$entry2['id']===3 && $entry2['credit_memo_total']==='2.40' && $entry2['debit_memo_total']==='0.00','Second page totals belong to its entry');
$c->fixture($db,1);$page1=$c->get();
check($page1['data']['data'][0]['entries'][0]['credit_memo_total']==='0.60' && $page1['data']['size']===7,'Other-page allocations do not leak into first page');
// Approval applies allocated balances once, atomically with the billing header.
$db->query("INSERT INTO billing(id,status,balance) VALUES (30,'open_bill',70)");
$db->query("INSERT INTO billing_entry(id,billing_id,branch_id,balance,amount,status) VALUES (30,30,2,50,100,'open_bill'),(31,30,2,20,100,'open_bill')");
foreach (['credit','debit'] as $kind) {
 $type=$kind.'_memo';$class='Fixture_'.ucfirst($kind).'_memos';$c=new $class();
 $rows=[['billing_entry_id'=>31,'amount'=>'25.00'],['billing_entry_id'=>30,'amount'=>'60.00']];
 $payload=['billing_id'=>30,'branch_id'=>2,'amount'=>'85.00','reason'=>'allocated approval','requester'=>67,'debit_account_type_id'=>1,'credit_account_type_id'=>1,'memo_entries'=>$rows];
 $c->fixture($db,$kind,$payload);$r=$c->create();check($r['status']===SUCCESS,'Allocated approval create');$id=$r['data'][$type.'_id'];
 $before=$db->query('SELECT balance FROM billing WHERE id=30')->getRow()->balance;
 $c->fixture($db,$kind,[$type.'_id'=>$id,'requester'=>67,'status'=>'approved']);check($c->change_status()['status']===SUCCESS,'Allocated approval succeeds');
 $balances=$db->query('SELECT balance,status FROM billing_entry WHERE id IN (30,31) ORDER BY id')->getResultArray();
 check($balances[0]['balance']===($kind==='credit'?'-10.00':'50.00') && $balances[1]['balance']===($kind==='credit'?'-5.00':'20.00'),'Credit subtracts, debit restores entry balances');
 check($balances[0]['status']===($kind==='credit'?'closed_bill':'open_bill'),'Entry status follows balance');
 check($db->query('SELECT balance FROM billing WHERE id=30')->getRow()->balance===($kind==='credit'?'-15.00':'70.00'),'Header and entries consistent');
 check($c->change_status()['status']===FAIL,'Repeated approval rejected');
 check($db->query('SELECT balance FROM billing WHERE id=30')->getRow()->balance===($kind==='credit'?'-15.00':'70.00'),'No duplicate header adjustment');
 // Invalid child totals and ownership must fail before changing any balance/status.
 $c->fixture($db,$kind,$payload);$r=$c->create();$badid=$r['data'][$type.'_id'];
 $db->query("UPDATE {$type}_entries SET amount=1 WHERE {$type}_id=?",[$badid]);
 $c->fixture($db,$kind,[$type.'_id'=>$badid,'requester'=>67,'status'=>'approved']);check($c->change_status()['status']===FAIL,'Approval revalidates child total');
 check($db->query("SELECT status FROM $type WHERE id=?",[$badid])->getRow()->status==='pending','Failed validation preserves pending status');
 $db->query("UPDATE {$type}_entries SET amount=CASE billing_entry_id WHEN 30 THEN 60 ELSE 25 END,billing_id=10 WHERE {$type}_id=?",[$badid]);
 check($c->change_status()['status']===FAIL,'Approval rejects wrong child ownership');
 $db->query("UPDATE {$type}_entries SET billing_id=30 WHERE {$type}_id=?",[$badid]);
 $db->query('UPDATE billing_entry SET is_deleted=1 WHERE id=31');check($c->change_status()['status']===FAIL,'Deleted allocated entry rejected');$db->query('UPDATE billing_entry SET is_deleted=0 WHERE id=31');
 $db->query("CREATE TRIGGER fixture_entry_failure BEFORE UPDATE ON billing_entry FOR EACH ROW BEGIN IF NEW.id=31 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture second entry failure'; END IF; END");
 $c->fixture($db,$kind,[$type.'_id'=>$badid,'requester'=>67,'status'=>'approved']);check($c->change_status()['status']===FAIL,'Second entry failure rolls back approval');
 $db->query('DROP TRIGGER fixture_entry_failure');
 check($db->query('SELECT balance FROM billing WHERE id=30')->getRow()->balance===($kind==='credit'?'-15.00':'70.00'),'Header update rolled back');
 check($db->query('SELECT balance FROM billing_entry WHERE id=30')->getRow()->balance===($kind==='credit'?'-10.00':'50.00'),'First entry update rolled back');
 check($db->query("SELECT status FROM $type WHERE id=?",[$badid])->getRow()->status==='pending','Approval status rolled back');
 $c->fixture($db,$kind,[$type.'_id'=>$badid,'requester'=>67,'status'=>'disapproved']);check($c->change_status()['status']===SUCCESS,'Disapproval needs no allocation adjustment');
 check($db->query('SELECT balance FROM billing_entry WHERE id=30')->getRow()->balance===($kind==='credit'?'-10.00':'50.00'),'Disapproval leaves balances unchanged');
}
echo "PASS $checks memo allocation checks\n";
