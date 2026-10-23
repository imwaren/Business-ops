<?php
/** Isolated fixture; no configured production/UAT connection is opened. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error . "\n"); exit(1); });
use App\Libraries\Apv_accounting as Money;
use CodeIgniter\Database\MySQLi\Connection;

function fixtureCron($db): App\Controllers\Cron {
    $cron=(new ReflectionClass(App\Controllers\Cron::class))->newInstanceWithoutConstructor();
    foreach (['branch_db'=>$db,'direct_payment'=>new App\Models\Direct_payment($db),'journal_entry'=>new App\Models\Journal_entry($db),'journal_entry_item'=>new App\Models\Journal_entry_item($db)] as $field=>$value) {
        $prop=new ReflectionProperty($cron,$field);$prop->setAccessible(true);$prop->setValue($cron,$value);
    }
    return $cron;
}
function concurrentPayment(): array {
    return ['id'=>777,'amount'=>'1000.0000','cost_center_id'=>2,'duration_from'=>'2026-11-01','duration_to'=>'2026-11-01',
        'amortization_entries'=>[['expense_account_type_id'=>4,'prepaid_account_type_id'=>5,'amount'=>'1000.0000']]];
}
if (($argv[1] ?? '')==='--worker') {
    $connection=new Connection(['hostname'=>(getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/company_cleanup_db/mysql.sock'),'username'=>(getenv('BILLING_FIXTURE_USER') ?: 'myt_waren'),'password'=>'','database'=>'apv_accounting_fixture',
        'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci','port'=>3306]);
    $connection->initialize();
    $cron=fixtureCron($connection);$method=new ReflectionMethod($cron,'_post_direct_payment_due_periods');$method->setAccessible(true);
    echo "READY\n";flush();
    echo json_encode($method->invoke($cron,concurrentPayment(),'2026-11-01',2))."\n";
    $connection->close();exit(0);
}

$checks = 0;
function check($ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (InvalidArgumentException $error) { check(true, $message); return; }
    throw new RuntimeException($message);
}
$lookup = static fn(int $id) => [1=>['code'=>'201-110'],2=>['code'=>'202-120'],3=>['code'=>'201-111'],4=>['code'=>'expense'],5=>['code'=>'prepaid']][$id] ?? null;
$lines = [['account_type_id'=>1,'debit'=>'1120.0000'],['account_type_id'=>2,'credit'=>'10.0000'],['account_type_id'=>3,'credit'=>'1110.0000']];
check(count(Money::entries($lines, '1110', $lookup)) === 3, 'Receipt-note payable matches net amount');
check(Money::decimal(Money::units('0.0001')) === '0.0001', 'Four decimal precision');
foreach (['-1','NaN','1e3','0.00001',null,[],true,'1000000000'] as $value) {
    rejects(fn()=>Money::units($value),'Reject invalid amount');
}
foreach ([[], [['account_type_id'=>99,'debit'=>1]], [['account_type_id'=>1,'debit'=>1,'credit'=>1]], [['account_type_id'=>1]], [['account_type_id'=>1,'debit'=>1],['account_type_id'=>3,'credit'=>2]]] as $invalid) {
    rejects(fn()=>Money::entries($invalid,1,$lookup),'Reject invalid accounting lines');
}
rejects(fn()=>Money::entries($lines,1120,$lookup),'Do not equate payable with gross debit');
$allocations = [['expense_account_type_id'=>4,'prepaid_account_type_id'=>5,'amount'=>'600.0001'],['expense_account_type_id'=>1,'prepaid_account_type_id'=>5,'amount'=>'399.9999']];
check(count(Money::allocations($allocations,1000,$lookup))===2,'Multiple explicit allocations');
rejects(fn()=>Money::allocations($allocations,999,$lookup),'Allocation total mismatch');
rejects(fn()=>Money::allocations([['expense_account_type_id'=>4,'prepaid_account_type_id'=>4,'amount'=>1]],1,$lookup),'Different allocation accounts');

$socket=(getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/company_cleanup_db/mysql.sock');
$pdo=new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4',(getenv('BILLING_FIXTURE_USER') ?: 'myt_waren'),'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixture='apv_accounting_fixture';
$pdo->exec('DROP DATABASE IF EXISTS '.$fixture);
$pdo->exec('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE '.$fixture);
$pdo->exec("CREATE TABLE account_type(id INT PRIMARY KEY,code VARCHAR(30),name VARCHAR(30),status VARCHAR(20) DEFAULT 'active',is_deleted INT DEFAULT 0)");
$pdo->exec("INSERT INTO account_type(id,code,name) VALUES(1,'201-110','Transitory'),(2,'202-120','EWT'),(3,'201-111','Payable'),(4,'expense','Expense'),(5,'prepaid','Prepaid')");
$pdo->exec("CREATE TABLE accounts_payable_voucher(id INT PRIMARY KEY,amount DECIMAL(13,4),withholding_tax DECIMAL(13,4),status VARCHAR(20) DEFAULT 'pending',type VARCHAR(30),type_id INT,is_deleted INT DEFAULT 0,due_date DATE,approved_on DATETIME,added_on DATETIME,added_by INT,updated_by INT)");
$pdo->exec("CREATE TABLE direct_payment(id INT PRIMARY KEY,amount DECIMAL(13,4),cost_center_id INT,status VARCHAR(20) DEFAULT 'approved', duration_from DATE, duration_to DATE,is_deleted INT DEFAULT 0)");
$pdo->exec("CREATE TABLE general_ledger(id INT AUTO_INCREMENT PRIMARY KEY,branch_id INT,source VARCHAR(250),source_id INT,account_type_id INT,date DATE,debit DECIMAL(13,4),credit DECIMAL(13,4),remark LONGTEXT,added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0,updated_on DATETIME)");
$pdo->exec("CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(64),remarks LONGTEXT,status VARCHAR(20),total_debit DECIMAL(13,4),total_credit DECIMAL(13,4),source VARCHAR(250),source_id INT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0)");
$pdo->exec("CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,journal_entry_id INT,account_type_id INT,direct_payment_id INT NULL,cost_center_id INT,debit DECIMAL(13,4),credit DECIMAL(13,4),remarks LONGTEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0)");
foreach (explode(';',file_get_contents('sql/apv_entries/schema.sql')) as $sql) { if(trim($sql)) {$pdo->exec($sql);} }
foreach (explode(';',file_get_contents('sql/apv_entries/schema.sql')) as $sql) { if(trim($sql)) {$pdo->exec($sql);} }
check(true,'Schema is repeatable');
foreach (explode(';',file_get_contents('sql/apv_entries/cutover.sql')) as $sql) { if(trim($sql)) {$pdo->exec($sql);} }
$pdo->exec('CREATE TABLE refund(id INT PRIMARY KEY, transaction_id INT, branch_id INT)');
$pdo->exec('CREATE TABLE `transaction`(id INT PRIMARY KEY,transaction_no VARCHAR(30))');
$pdo->exec('CREATE FUNCTION gl_item_history_remarks(p_id INT) RETURNS VARCHAR(100) DETERMINISTIC RETURN NULL');
$pdo->exec('CREATE FUNCTION gl_branch_id() RETURNS INT DETERMINISTIC RETURN 2');
// This monetary fixture has no patient/catalog tables; full metadata lookup
// integration is covered by general_ledger_remark_names_check.py.
$pdo->exec('CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250),p_id INT,p_branch INT,p_base LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN p_base');
// Install real GL->JE synchronization and history-clearing routines with fixture schema substitution.
$script=file_get_contents('sql/general_ledger_triggers.sql');
foreach (['gl_clear','gl_je_from_gl'] as $procedure) {
    preg_match('/CREATE PROCEDURE '.$procedure.'\(.*?END\$\$/s',$script,$match);
    $pdo->exec(str_replace('mytemr_master',$fixture,substr($match[0],0,-2)));
}
preg_match('/CREATE TRIGGER trg_gl_general_ledger_ai.*?END\$\$/s',$script,$match);
$pdo->exec(substr($match[0],0,-2));
foreach (explode('$$',preg_replace('/DELIMITER[^\n]*\n/','',file_get_contents('sql/apv_entries/posting_prod.sql'))) as $sql) {
    if(trim($sql)) {$pdo->exec(str_replace('mytemr_master',$fixture,trim($sql)));}
}
$pdo->exec("CREATE TRIGGER fixture_apv_update AFTER UPDATE ON accounts_payable_voucher FOR EACH ROW CALL gl_post_apv(NEW.id)");
$config=['hostname'=>$socket,'username'=>(getenv('BILLING_FIXTURE_USER') ?: 'myt_waren'),'password'=>'','database'=>$fixture,'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci','port'=>3306];
$db=new Connection($config);$db->initialize();
// Keep the shared-center router on the same isolated fixture as the controller.
$db->query("CREATE TABLE branch(id INT PRIMARY KEY,name VARCHAR(100),type VARCHAR(30),is_deleted INT DEFAULT 0)");
$db->query("INSERT INTO branch VALUES(2,'Fixture clinic','clinic',0)");
$connectionCache=new ReflectionProperty(CodeIgniter\Database\Config::class,'instances');
$connectionCache->setAccessible(true);
$connectionCache->setValue(null,['master'=>$db,'default'=>$db,'tests'=>$db,'oakridge'=>$db]);

class FixtureEntry extends App\Models\Accounts_payable_voucher_entry {
    protected function _resolve_cross_db_sql(string $sql): string { return str_replace('{MASTER_DB}','apv_accounting_fixture',$sql); }
}
$model=new FixtureEntry($db);
$pdo->exec("INSERT INTO accounts_payable_voucher(id,amount,type,type_id,added_on,added_by) VALUES(1,1110,'receipt_note',1,NOW(),1)");
$db->query('SET @apv_entry_sync=1');
$db->transBegin();
check($model->replace_entries(1,Money::entries($lines,1110,$lookup),1),'Save child lines');
$db->transCommit();$db->query('SET @apv_entry_sync=0');
check((int)$pdo->query('SELECT COUNT(*) FROM general_ledger')->fetchColumn()===0,'Pending voucher posts no GL');
$pdo->exec("UPDATE accounts_payable_voucher SET status='approved' WHERE id=1");
check((int)$pdo->query('SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0')->fetchColumn()===3,'Approve posts each line');
check((int)$pdo->query('SELECT COUNT(*) FROM journal_entry_item WHERE is_deleted=0')->fetchColumn()===3,'Journal has each line');
check($pdo->query('SELECT total_debit=1120 AND total_credit=1120 FROM journal_entry WHERE is_deleted=0')->fetchColumn()==1,'Journal balanced');
for($i=0;$i<2;$i++){ $pdo->exec('CALL gl_post_apv(1)'); }
check((int)$pdo->query('SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0')->fetchColumn()===3,'Repeated rebuild does not duplicate GL');
check((int)$pdo->query('SELECT COUNT(*) FROM journal_entry WHERE is_deleted=0')->fetchColumn()===1,'Repeated rebuild does not duplicate JE');
check((int)$pdo->query('SELECT COUNT(*) FROM general_ledger WHERE is_deleted=1')->fetchColumn()===6,'Retired history preserved');
// Invalid direct edits cannot change an approved movement.
try {$pdo->exec('UPDATE accounts_payable_voucher_entry SET credit=1111 WHERE account_type_id=3');throw new RuntimeException('Expected SQL rejection');}catch(PDOException $error){check(true,'Unbalanced direct edit rejected');}
check($pdo->query('SELECT credit FROM accounts_payable_voucher_entry WHERE account_type_id=3')->fetchColumn()==1110,'Rejected edit rolls back');
$db->transBegin();$db->query('SET @apv_entry_sync=1');
check($model->replace_entries(1,Money::entries($lines,1110,$lookup),2),'Replace while deferred');
$db->transRollback();$db->query('SET @apv_entry_sync=0');
check(count($model->get_for_vouchers([1])[1])===3,'Replacement rollback preserves active entries');
check((int)$pdo->query('SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0')->fetchColumn()===3,'Rollback preserves GL');
// Successful replacement remains atomic and creates one active set.
$db->transBegin();$db->query('SET @apv_entry_sync=1');
$model->replace_entries(1,Money::entries($lines,1110,$lookup),2);
$db->query('SET @apv_entry_sync=0');$q=$db->query('CALL gl_post_apv(1)');$q->freeResult();
while($db->connID->more_results() && $db->connID->next_result()){$r=$db->connID->store_result();if($r){$r->free();}}
$db->transCommit();
check(count($model->get_for_vouchers([1])[1])===3,'Successful replacement active entry count');
check((int)$pdo->query('SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0')->fetchColumn()===3,'Successful replacement active GL count');
$pdo->exec('CALL gl_post_direct_payment(999)');
check((int)$pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='direct_payment' AND is_deleted=0")->fetchColumn()===0,'Direct-payment creates no GL');
$dp=new App\Models\Direct_payment($db);
check(array_sum(array_map([Money::class,'units'],$dp->split_amount('1000.0001',3)))===10000001,'Monthly schedule preserves four-decimal amount');
// Duplicate IDs in a second source branch must not leak entries.
$pdo->exec('CREATE DATABASE IF NOT EXISTS apv_accounting_other_fixture');
$pdo->exec('CREATE TABLE IF NOT EXISTS apv_accounting_other_fixture.accounts_payable_voucher_entry LIKE accounts_payable_voucher_entry');
$config['database']='apv_accounting_other_fixture';$other=new Connection($config);$other->initialize();
check((new FixtureEntry($other))->get_for_vouchers([1])===[],'Entry lookups stay on supplied branch');

// The unchanged APV-payment procedure still clears AP and credits its selected bank.
$pdo->exec('ALTER TABLE refund ADD is_deleted INT DEFAULT 0');
$pdo->exec('CREATE TABLE item(id INT PRIMARY KEY,name VARCHAR(40),is_deleted INT DEFAULT 0)');
$pdo->exec('CREATE TABLE receipt_note_item(id INT PRIMARY KEY,receipt_note_id INT,item_id INT,is_deleted INT DEFAULT 0)');
$pdo->exec("INSERT INTO item VALUES(1,'CBC',0)");
$pdo->exec('INSERT INTO receipt_note_item VALUES(1,1,1,0)');
preg_match('/CREATE FUNCTION gl_receipt_note_item_names\(.*?END\$\$/s',$script,$match);
$pdo->exec(str_replace('mytemr_master',$fixture,substr($match[0],0,-2)));
$pdo->exec('CREATE TABLE accounts_payable_voucher_payment(id INT PRIMARY KEY,account_type_id INT,is_deleted INT DEFAULT 0)');
$pdo->exec('CREATE TABLE accounts_payable_voucher_payment_detail(id INT PRIMARY KEY,accounts_payable_voucher_id INT,accounts_payable_voucher_payment_id INT,date DATE,added_on DATETIME,amount DECIMAL(13,4),added_by INT,is_deleted INT DEFAULT 0)');
$pdo->exec("CREATE FUNCTION gl_account_id(p_code VARCHAR(30)) RETURNS INT READS SQL DATA RETURN (SELECT id FROM account_type WHERE code=p_code LIMIT 1)");
preg_match('/CREATE PROCEDURE gl_post_apv_payment_detail\(.*?END\$\$/s',$script,$match);
$pdo->exec(str_replace('mytemr_master',$fixture,substr($match[0],0,-2)));
$pdo->exec('INSERT INTO accounts_payable_voucher_payment VALUES(1,5,0)');
$pdo->exec("INSERT INTO accounts_payable_voucher_payment_detail VALUES(1,1,1,'2026-10-08',NOW(),1110,1,0)");
$pdo->exec('CALL gl_post_apv_payment_detail(1)');
check($pdo->query("SELECT SUM(debit)=1110 AND SUM(credit)=1110 FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0")->fetchColumn()==1,'Payment retains balanced AP/bank movement');
check($pdo->query("SELECT debit=1110 FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND account_type_id=3 AND is_deleted=0")->fetchColumn()==1,'Payment debits Accounts Payable');
check($pdo->query("SELECT credit=1110 FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND account_type_id=5 AND is_deleted=0")->fetchColumn()==1,'Payment credits selected account');
check($pdo->query("SELECT remark LIKE '%CBC%' FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND account_type_id=5 AND is_deleted=0")->fetchColumn()==1,'Payment retains item-name remarks');
// Exercise cron's explicit allocation path and preserve period deduplication.
preg_match('/CREATE PROCEDURE gl_post_journal_entry\(.*?END\$\$/s',$script,$match);
$pdo->exec(substr($match[0],0,-2));
$pdo->exec("CREATE TRIGGER fixture_manual_journal AFTER UPDATE ON journal_entry FOR EACH ROW BEGIN IF IFNULL(NEW.source,'')='' THEN CALL gl_post_journal_entry(NEW.id); END IF; END");
$pdo->exec("INSERT INTO direct_payment(id,amount,cost_center_id,duration_from,duration_to,is_deleted) VALUES(9,1000.0001,2,'2026-10-01','2026-12-01',0)");
$cron=(new ReflectionClass(App\Controllers\Cron::class))->newInstanceWithoutConstructor();
foreach (['branch_db'=>$db,'direct_payment'=>$dp,'journal_entry'=>new App\Models\Journal_entry($db),'journal_entry_item'=>new App\Models\Journal_entry_item($db)] as $field=>$value) {
    $prop=new ReflectionProperty($cron,$field);$prop->setAccessible(true);$prop->setValue($cron,$value);
}
$due=new ReflectionMethod($cron,'_post_direct_payment_due_periods');$due->setAccessible(true);
$payment=['id'=>9,'amount'=>'1000.0001','cost_center_id'=>2,'duration_from'=>'2026-10-01','duration_to'=>'2026-12-01','amortization_entries'=>[
    ['expense_account_type_id'=>4,'prepaid_account_type_id'=>5,'amount'=>'600.0001'],
    ['expense_account_type_id'=>1,'prepaid_account_type_id'=>5,'amount'=>'400.0000']]];
$result=$due->invoke($cron,$payment,'2026-12-01',2);
check($result['posted']===3 && $result['failed']===0,'Explicit amortization posts due months');
$result=$due->invoke($cron,$payment,'2026-12-01',2);
check($result['posted']===0 && $result['skipped']===3,'Amortization rerun preserves periods');
check($pdo->query('SELECT SUM(debit)=1000.0001 AND SUM(credit)=1000.0001 FROM journal_entry_item WHERE direct_payment_id=9 AND is_deleted=0')->fetchColumn()==1,'Amortization preserves allocated amount');
check($pdo->query("SELECT SUM(debit)=1000.0001 AND SUM(credit)=1000.0001 FROM general_ledger WHERE source='journal_entry' AND is_deleted=0")->fetchColumn()==1,'Amortization GL matches journals');
// Historical backfill is repeatable and never chooses an account for unbalanced history.
$pdo->exec("INSERT INTO accounts_payable_voucher(id,amount,status,type,type_id,added_by,added_on) VALUES(2,1110,'approved','receipt_note',2,1,NOW())");
$pdo->exec("INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,added_by,added_on,is_deleted) VALUES
    (2,'accounts_payable_voucher',2,1,'2026-10-08',1120,0,1,NOW(),0),
    (2,'accounts_payable_voucher',2,2,'2026-10-08',0,10,1,NOW(),0),
    (2,'accounts_payable_voucher',2,3,'2026-10-08',0,1110,1,NOW(),0)");
// No prepaid fixture is eligible for migration until it has explicit allocations and one successor.
$pdo->exec("INSERT INTO accounts_payable_voucher(id,amount,status,type,type_id,added_by,added_on) VALUES(9,1000.0001,'pending','direct_payment',9,1,NOW())");
$db->query('SET @apv_entry_sync=1');
$model->replace_entries(9,Money::entries([['account_type_id'=>5,'debit'=>'1000.0001'],['account_type_id'=>3,'credit'=>'1000.0001']],'1000.0001',$lookup),1);
$amort=new App\Models\Accounts_payable_voucher_amortization_entry($db);$amort->replace_entries(9,$payment['amortization_entries'],1);
$db->query('SET @apv_entry_sync=0');
$migration=file_get_contents('sql/apv_entries/migrate_prod.sql');
preg_match('/CREATE PROCEDURE migrate_apv_entries\(\).*?END\$\$/s',$migration,$match);
$pdo->exec(str_replace('mytemr_master',$fixture,substr($match[0],0,-2)));
$pdo->exec('CALL migrate_apv_entries()');
check(count($model->get_for_vouchers([2])[2])===3,'Historical balanced GL backfilled');
$pdo->exec('CALL migrate_apv_entries()');
check(count($model->get_for_vouchers([2])[2])===3,'Migration repeat does not duplicate entries');
$pdo->exec("INSERT INTO accounts_payable_voucher(id,amount,status,type,type_id,added_by,added_on) VALUES(3,100,'pending','cash_advance',3,1,NOW())");
try {$pdo->exec('CALL migrate_apv_entries()');throw new RuntimeException('Expected migration failure');}catch(PDOException $error){check(true,'Historical missing mapping blocks migration');}
check($pdo->query('SELECT @apv_entry_sync')->fetchColumn()===null || $pdo->query('SELECT @apv_entry_sync')->fetchColumn()==0,'Migration failure restores deferral');


// Exercise the actual controller save boundary with an isolated account/source resolver.
foreach (["supplier_id INT", "supplier_type VARCHAR(30)", "balance DECIMAL(13,4)", "updated_on DATETIME", "cost_center_id INT"] as $column) {
    $pdo->exec('ALTER TABLE accounts_payable_voucher ADD '.$column);
}
$pdo->exec('ALTER TABLE accounts_payable_voucher MODIFY id INT AUTO_INCREMENT');
class FixtureApvController extends App\Controllers\Accounts_payable_vouchers {
    public $fixtureDb;
    public $attachmentFailure = false;
    public function configure($db,$sentinel,array $payload): void {
        $this->fixtureDb=$db;$this->db=$sentinel;$this->payload=$payload;
        $this->request=new class {public function getFileMultiple($key){return [];}};
    }
    protected function _api_validation(){return true;}
    protected function _create_response($status,$data_response,$skip_response_logging=false){return ['status'=>$status,'data'=>$data_response];}
    protected function resolve_branch_models(int $branch_id) {
        $this->branch_db=$this->fixtureDb;
        $this->accounts_payable_voucher=new App\Models\Accounts_payable_voucher($this->branch_db);
        $this->voucher_entries=new FixtureEntry($this->branch_db);
        $this->amortization_entries=new App\Models\Accounts_payable_voucher_amortization_entry($this->branch_db);
        $this->receipt_note=new class {public function select($columns,$where,$limit){return (object)['id'=>$where['id'],'status'=>'approved'];}};
        $this->liquidation=new class {public function select($columns,$where,$limit){return (object)['id'=>$where['id'],'status'=>'approved'];}};
        if ($branch_id===20) {
            foreach ([$this->accounts_payable_voucher,$this->voucher_entries,$this->amortization_entries] as $model) {
                $model->with_shared_cost_centers([$branch_id]);
            }
        }
        return in_array($branch_id,[2,20],true);
    }
    protected function _lookup_entry_account(int $id): ?array {
        $r=$this->fixtureDb->query('SELECT id,code FROM account_type WHERE id=? AND status=? AND is_deleted=0',[$id,'active'])->getRowArray();
        return $r ?: null;
    }
    protected function _attempt_create_voucher_attachments($id,$branch,array $files): bool {
        return !$this->attachmentFailure;
    }
    public function connection(){return $this->db;}
}
$controller=new FixtureApvController();
$payload=['requester'=>1,'cost_center_id'=>2,'type'=>'receipt_note','type_id'=>91,'amount'=>'1110.0000','supplier_id'=>1,'supplier_type'=>'supplier','entries'=>$lines];
$controller->configure($db,$other,$payload);
$created=$controller->create();
check($created['status']===SUCCESS,'Actual controller creates balanced APV');
$id=$created['data']['accounts_payable_voucher_id'];
check(count($model->get_for_vouchers([$id])[$id])===3,'Controller stores branch child entries');
check($controller->connection()===$other,'Controller restores original connection');
check($db->query('SELECT @apv_entry_sync AS v')->getRowArray()['v']==0,'Controller restores deferral');
$omitted=$payload;unset($omitted['entries']);$omitted['accounts_payable_voucher_id']=$id;$omitted['due_date']='2026-12-31';
$controller->configure($db,$other,$omitted);
$before=array_column($model->get_for_vouchers([$id])[$id],'id');
$updated=$controller->update();
check($updated['status']===SUCCESS,'Omitted entries update succeeds');
check(array_column($model->get_for_vouchers([$id])[$id],'id')===$before,'Omitted entries are unchanged');
$replacement=$payload;$replacement['accounts_payable_voucher_id']=$id;
$controller->configure($db,$other,$replacement);$updated=$controller->update();
check($updated['status']===SUCCESS,'Controller replacement succeeds');
check(array_column($model->get_for_vouchers([$id])[$id],'id')!==$before,'Replacement saves a new active set');
$failed=$payload;$failed['type_id']=92;$controller->attachmentFailure=true;$controller->configure($db,$other,$failed);
$count=(int)$pdo->query('SELECT COUNT(*) FROM accounts_payable_voucher')->fetchColumn();
$result=$controller->create();
check($result['status']===FAIL,'Attachment failure reports failure');
check((int)$pdo->query('SELECT COUNT(*) FROM accounts_payable_voucher')->fetchColumn()===$count,'Attachment failure rolls back header');
check($pdo->query('SELECT COUNT(*) FROM accounts_payable_voucher_entry e LEFT JOIN accounts_payable_voucher v ON v.id=e.accounts_payable_voucher_id WHERE v.id IS NULL')->fetchColumn()==0,'Attachment failure leaves no orphan lines');
$controller->attachmentFailure=false;$failed['entries'][2]['credit']='1111.0000';$controller->configure($db,$other,$failed);$result=$controller->create();
check($result['status']===FAIL,'Controller rejects unbalanced lines');
check((int)$pdo->query('SELECT COUNT(*) FROM accounts_payable_voucher')->fetchColumn()===$count,'Validation failure writes no header');
check($controller->connection()===$other,'Failure restores controller connection');


$pdo->exec('CREATE TABLE liquidation(id INT PRIMARY KEY,cost_center_id INT,status VARCHAR(20),is_deleted INT DEFAULT 0)');
// Liquidation creation may defer entry selection; every update supplies a valid set.
foreach ([2,20] as $center) {
    $liquidation=$payload;
    $liquidation['type']='liquidation';$liquidation['cost_center_id']=$center;
    unset($liquidation['entries']);
    foreach (['omitted','empty','populated','excess'] as $offset=>$variant) {
        $draft=$liquidation;$draft['type_id']=1000+$center*10+$offset;
        $pdo->exec("INSERT INTO liquidation VALUES(".$draft['type_id'].",$center,'approved',0)");
        if ($variant==='empty') {$draft['entries']=[];}
        if ($variant==='populated') {$draft['entries']=$lines;}
        if ($variant==='excess') {$draft['type']='excess';}
        $controller->configure($db,$other,$draft);$result=$controller->create();
        check($result['status']===SUCCESS,"Liquidation $variant create in center $center");
        $draftId=$result['data']['accounts_payable_voucher_id'];
        $saved=$pdo->query('SELECT * FROM accounts_payable_voucher WHERE id='.$draftId)->fetch(PDO::FETCH_ASSOC);
        check($saved['type']==='liquidation','Excess alias persists as liquidation');
        $childCount=(int)$pdo->query('SELECT COUNT(*) FROM accounts_payable_voucher_entry WHERE is_deleted=0 AND accounts_payable_voucher_id='.$draftId)->fetchColumn();
        check($childCount===($variant==='populated'?3:0),'Optional create does not infer entries');
        $edit=$draft;$edit['accounts_payable_voucher_id']=$draftId;unset($edit['entries']);
        foreach (['omitted','empty','null','string'] as $invalid) {
            $bad=$edit;
            if ($invalid!=='omitted') {$bad['entries']=['empty'=>[],'null'=>null,'string'=>'bad'][$invalid];}
            $controller->configure($db,$other,$bad);$result=$controller->update();
            check($result['status']===FAIL,"Liquidation update rejects $invalid");
        }
        // A missing payload type must still enforce the locked header's rule.
        $bad=$edit;unset($bad['type']);$controller->configure($db,$other,$bad);
        check($controller->update()['status']===FAIL,'Locked liquidation header requires supplied entries');
        if ($childCount===0) {
            try {$pdo->exec("UPDATE accounts_payable_voucher SET status='approved' WHERE id=".$draftId);throw new RuntimeException('Approval should fail');}
            catch (PDOException $error) {check(str_contains($error->getMessage(),'Invalid APV entries'),'Approval without entries remains blocked');}
        }
        $edit['entries']=$lines;$controller->configure($db,$other,$edit);
        check($controller->update()['status']===SUCCESS,'Valid liquidation replacement succeeds');
        check(count($model->get_for_vouchers([$draftId])[$draftId])===3,'Liquidation saves supplied entries');
    }
    foreach ([null,'bad',[['account_type_id'=>1,'debit'=>'1']]] as $invalid) {
        $bad=$liquidation;$bad['type_id']=2000+$center;$bad['entries']=$invalid;
        $controller->configure($db,$other,$bad);
        check($controller->create()['status']===FAIL,'Malformed/populated invalid liquidation create rejected');
    }
    $rollback=$liquidation;$rollback['type_id']=3000+$center;
    $pdo->exec("INSERT INTO liquidation VALUES(".$rollback['type_id'].",$center,'approved',0)");
    $beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM accounts_payable_voucher')->fetchColumn();
    $controller->attachmentFailure=true;$controller->configure($db,$other,$rollback);
    check($controller->create()['status']===FAIL,'Optional-entry attachment failure rolls back');
    check((int)$pdo->query('SELECT COUNT(*) FROM accounts_payable_voucher')->fetchColumn()===$beforeCount,'Optional-entry header rolled back');
    $controller->attachmentFailure=false;
}
// Legacy liquidation cannot use the existing legacy omitted-entry update exception.
$pdo->exec("INSERT INTO accounts_payable_voucher(id,type,type_id,amount,status,is_deleted,cost_center_id) VALUES(-1,'liquidation',8888,1110,'pending',0,2)");
$legacy=$liquidation;$legacy['cost_center_id']=2;$legacy['type_id']=8888;$legacy['accounts_payable_voucher_id']=-1;
$controller->configure($db,$other,$legacy);
check($controller->update()['status']===FAIL,'Legacy liquidation update requires entries');
$legacy['entries']=$lines;$controller->configure($db,$other,$legacy);
check($controller->update()['status']===SUCCESS,'Legacy liquidation update accepts valid entries');
$bad=$payload;unset($bad['entries']);$bad['type_id']=9999;$controller->configure($db,$other,$bad);
check($controller->create()['status']===FAIL,'Other APV types still require entries on create');

// Two workers contend on the same source; the waiter must recheck after lock acquisition.
$pdo->exec("INSERT INTO direct_payment(id,amount,cost_center_id,duration_from,duration_to,is_deleted) VALUES(777,1000,2,'2026-11-01','2026-11-01',0)");
$db->transBegin();$db->query('SELECT id FROM direct_payment WHERE id=777 FOR UPDATE');
$process=proc_open([PHP_BINARY,__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
fclose($pipes[0]);
check(trim(fgets($pipes[1]))==='READY','Concurrent worker starts');
$waiter=false;$deadline=microtime(true)+10;
while(microtime(true)<$deadline){
    foreach($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $processRow){
        if (strpos((string)$processRow['Info'],'SELECT id FROM direct_payment WHERE id = 777 FOR UPDATE')!==false) {$waiter=true;break;}
    }
    if ($waiter) {break;}
    usleep(20000);
}
check($waiter,'Second worker waits on source lock');
$result=$due->invoke($cron,concurrentPayment(),'2026-11-01',2);$db->transCommit();
$child=json_decode(trim(stream_get_contents($pipes[1])),true);$errors=stream_get_contents($pipes[2]);
fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
check($exit===0 && !$errors,'Concurrent worker exits without error');
check($result['posted']===1 && $child['posted']===0 && $child['skipped']===1,'Only one worker posts a period');
check($pdo->query('SELECT COUNT(DISTINCT journal_entry_id) FROM journal_entry_item WHERE direct_payment_id=777 AND is_deleted=0')->fetchColumn()==1,'Concurrent workers leave one journal');


$pdo->exec('DROP PROCEDURE migrate_apv_entries'); // Match the successful installer cleanup.
// Rebuild preserves ordinary receipt/payment/manual movements and soft-retires old DP accounting.
$pdo->exec("INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,added_by,added_on,is_deleted) VALUES
 (2,'direct_payment',9,4,'2026-10-08',1000.0001,0,1,NOW(),0),(2,'direct_payment',9,5,'2026-10-08',0,1000.0001,1,NOW(),0)");
$manualBefore=$pdo->query("SELECT COUNT(*),SUM(debit),SUM(credit) FROM general_ledger WHERE source='journal_entry' AND is_deleted=0")->fetch(PDO::FETCH_NUM);
$paymentBefore=$pdo->query("SELECT COUNT(*),SUM(debit),SUM(credit) FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0")->fetch(PDO::FETCH_NUM);
preg_match('/CREATE PROCEDURE rebuild_apv_entries\(\).*?END\$\$/s',file_get_contents('sql/apv_entries/rebuild.sql'),$match);$pdo->exec(substr($match[0],0,-2));
$pdo->exec('CALL rebuild_apv_entries()');
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='direct_payment' AND is_deleted=0")->fetchColumn()==0,'Rebuild retires superseded ordinary DP GL');
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='direct_payment' AND is_deleted=1")->fetchColumn()==2,'Retirement preserves old DP GL history');
check($pdo->query("SELECT COUNT(*) FROM journal_entry WHERE source='direct_payment' AND is_deleted=0")->fetchColumn()==0,'Rebuild retires old DP journal');
check($manualBefore===$pdo->query("SELECT COUNT(*),SUM(debit),SUM(credit) FROM general_ledger WHERE source='journal_entry' AND is_deleted=0")->fetch(PDO::FETCH_NUM),'Rebuild preserves amortization GL');
check($paymentBefore===$pdo->query("SELECT COUNT(*),SUM(debit),SUM(credit) FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0")->fetch(PDO::FETCH_NUM),'Rebuild preserves payment GL');
$pdo->exec('CALL rebuild_apv_entries()');
check($pdo->query("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=1 AND is_deleted=0")->fetchColumn()==1,'Full rebuild repeat keeps one active APV journal');
foreach (explode(';',file_get_contents('sql/apv_entries/postflight.sql')) as $sql) {
    if (trim($sql)) {check($pdo->query($sql)->fetchAll()===[],'Postflight has no accounting discrepancies');}
}
$pdo->exec('CREATE TABLE direct_payment_entry(id INT PRIMARY KEY)');
preg_match('/CREATE PROCEDURE retire_legacy_payment_entries\(\).*?END\$\$/s',file_get_contents('sql/apv_entries/drop_legacy.sql'),$match);$pdo->exec(substr($match[0],0,-2));
try {$pdo->exec('CALL retire_legacy_payment_entries()');throw new RuntimeException('Expected backup gate');}catch(PDOException $error){check(true,'Drop requires verified backup/deployment flags');}
$pdo->exec('SET @apv_legacy_backup_verified=1,@apv_deployment_verified=1');
$pdo->exec('CREATE TRIGGER fixture_old_dependency AFTER INSERT ON direct_payment_entry FOR EACH ROW CALL gl_post_direct_payment(NEW.id)');
try {$pdo->exec('CALL retire_legacy_payment_entries()');throw new RuntimeException('Expected dependency gate');}catch(PDOException $error){check(true,'Drop rejects old installed trigger');}
$pdo->exec('DROP TRIGGER fixture_old_dependency');
$pdo->exec('CALL retire_legacy_payment_entries()');
check($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='apv_accounting_fixture' AND TABLE_NAME='direct_payment_entry'")->fetchColumn()==0,'Drop executes only after safety/dependency gates');


// The migration remains repeatable after the physical legacy-table removal.
$pdo->exec('UPDATE accounts_payable_voucher SET is_deleted=1 WHERE id=3');
$pdo->exec('UPDATE direct_payment SET is_deleted=1 WHERE id=777');
preg_match('/CREATE PROCEDURE migrate_apv_entries\(\).*?END\$\$/s',file_get_contents('sql/apv_entries/migrate_prod.sql'),$match);
$pdo->exec(str_replace('mytemr_master',$fixture,substr($match[0],0,-2)));
$pdo->exec('CALL migrate_apv_entries()');$pdo->exec('DROP PROCEDURE migrate_apv_entries');
check(count($model->get_for_vouchers([2])[2])===3,'Migration repeats after legacy table removal');


// Legacy compatibility is based on the immutable cutoff and absence of any child history.
$pdo->exec("INSERT INTO accounts_payable_voucher(id,amount,status,type,type_id,added_by,added_on,withholding_tax,balance,supplier_id,supplier_type) VALUES
(50,1110,'approved','receipt_note',50,1,NOW(),10,1110,1,'supplier'),
(51,1110,'pending','receipt_note',51,1,NOW(),10,1110,1,'supplier')");
$pdo->exec("INSERT INTO direct_payment(id,amount,cost_center_id,status) VALUES(77,1110,2,'approved')");
$pdo->exec('UPDATE apv_accounting_cutover SET legacy_max_apv_id=100,legacy_max_direct_payment_id=100 WHERE id=1');
$pdo->exec("INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,added_by,added_on,is_deleted) VALUES
(2,'accounts_payable_voucher',50,3,'2026-10-01',0,1110,1,NOW(),0),
(2,'direct_payment',77,4,'2026-10-01',1110,0,1,NOW(),0)");
$legacyGl=$pdo->query("SELECT * FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=50")->fetchAll(PDO::FETCH_ASSOC);
$legacyJe=$pdo->query("SELECT * FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=50")->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec("UPDATE accounts_payable_voucher SET status='open',balance=500 WHERE id=50");
check($legacyGl===$pdo->query("SELECT * FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=50")->fetchAll(PDO::FETCH_ASSOC),'Legacy payment status preserves all GL fields');
check($legacyJe===$pdo->query("SELECT * FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=50")->fetchAll(PDO::FETCH_ASSOC),'Legacy payment status preserves all JE fields');
try {$pdo->exec("UPDATE accounts_payable_voucher SET status='approved' WHERE id=51");throw new RuntimeException('Expected first approval guard');}catch(PDOException $e){check(true,'Legacy first approval requires entries');}
try {$pdo->exec('UPDATE accounts_payable_voucher SET amount=1120 WHERE id=50');throw new RuntimeException('Expected financial edit guard');}catch(PDOException $e){check(true,'Legacy financial edit requires entries');}
$legacyPayload=['requester'=>1,'cost_center_id'=>2,'type'=>'receipt_note','type_id'=>50,'amount'=>'1110.0000','withholding_tax'=>'10.0000','supplier_id'=>1,'supplier_type'=>'supplier','accounts_payable_voucher_id'=>50];
$controller->configure($db,$other,$legacyPayload);$result=$controller->update();
check($result['status']===SUCCESS,'Legacy controller update without entries succeeds');
check($legacyGl===$pdo->query("SELECT * FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=50")->fetchAll(PDO::FETCH_ASSOC),'Legacy controller leaves GL unchanged');
$changed=$legacyPayload;$changed['amount']='1120.0000';$controller->configure($db,$other,$changed);
check($controller->update()['status']===FAIL,'Controller rejects legacy amount change without entries');
$legacyPayload['entries']=$lines;$controller->configure($db,$other,$legacyPayload);
check($controller->update()['status']===SUCCESS,'Explicit entries adopt legacy voucher');
check(!$model->is_legacy(50),'Any entry history permanently ends legacy classification');
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=50 AND is_deleted=0")->fetchColumn()==3,'Adoption creates one new active set');
$dpGl=$pdo->query("SELECT * FROM general_ledger WHERE source='direct_payment' AND source_id=77")->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec('CALL gl_post_direct_payment(77)');
check($dpGl===$pdo->query("SELECT * FROM general_ledger WHERE source='direct_payment' AND source_id=77")->fetchAll(PDO::FETCH_ASSOC),'Legacy DP update preserves posting');
$pdo->exec("INSERT INTO accounts_payable_voucher(id,amount,status,type,type_id,added_by,added_on) VALUES(101,1110,'pending','direct_payment',77,1,NOW())");
$db->query('SET @apv_entry_sync=1');$model->replace_entries(101,Money::entries([['account_type_id'=>4,'debit'=>1110],['account_type_id'=>3,'credit'=>1110]],1110,$lookup),1);$db->query('SET @apv_entry_sync=0');
$pdo->exec("UPDATE accounts_payable_voucher SET status='approved' WHERE id=101");
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='direct_payment' AND source_id=77 AND is_deleted=0")->fetchColumn()==0,'Approved explicit APV retires superseded legacy DP posting');
$pdo->exec("UPDATE accounts_payable_voucher SET is_deleted=1 WHERE id=50");
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=50 AND is_deleted=0")->fetchColumn()==0,'Deletion retires adopted voucher posting');
$pdo->exec("INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,added_by,added_on,is_deleted) VALUES(2,'accounts_payable_voucher',51,3,'2026-10-01',0,1110,1,NOW(),0)");
$pdo->exec("UPDATE accounts_payable_voucher SET status='disapproved' WHERE id=51");
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=51 AND is_deleted=0")->fetchColumn()==0,'Legacy cancellation soft-retires postings');
foreach (explode(';',file_get_contents('sql/apv_entries/cutover.sql')) as $sql){if(trim($sql)){$pdo->exec($sql);}}
check($pdo->query('SELECT legacy_max_apv_id FROM apv_accounting_cutover WHERE id=1')->fetchColumn()==100,'Cutover rerun never advances existing cutoff');
// Refund APV date and actual master-patient remark lookup, using real SQL hooks.
$pdo->exec('ALTER TABLE refund ADD patient_id INT; ALTER TABLE `transaction` ADD patient_id INT');
$pdo->exec('CREATE TABLE patient(id INT PRIMARY KEY,last_name VARBINARY(255),first_name VARBINARY(255),middle_name VARBINARY(255),suffix VARBINARY(50),is_deleted INT DEFAULT 0)');
$pdo->exec('CREATE TABLE master_patient LIKE patient');
$pdo->exec('CREATE TABLE IF NOT EXISTS item(id INT PRIMARY KEY,name VARCHAR(100)); CREATE TABLE booking(id INT PRIMARY KEY,transaction_id INT,is_deleted INT); CREATE TABLE booking_medicine(id INT PRIMARY KEY,booking_id INT,medicine_id INT,is_deleted INT)');
$fixtureKey=bin2hex('refund-apv-local-fixture');
$pdo->exec("INSERT INTO patient VALUES(700,AES_ENCRYPT('Clinic',UNHEX('$fixtureKey')),AES_ENCRYPT('Wrong',UNHEX('$fixtureKey')),NULL,NULL,0)");
$pdo->exec("INSERT INTO master_patient VALUES(700,AES_ENCRYPT('Master',UNHEX('$fixtureKey')),AES_ENCRYPT('José',UNHEX('$fixtureKey')),NULL,NULL,0)");
$pdo->exec("INSERT INTO `transaction`(id,transaction_no,patient_id) VALUES(700,'200115017',700); INSERT INTO refund(id,transaction_id,branch_id,patient_id) VALUES(700,700,2,700)");
$pdo->exec("INSERT INTO item(id,name) VALUES(700,'Refund fixture item'); INSERT INTO booking VALUES(700,700,0); INSERT INTO booking_medicine VALUES(700,700,700,0)");
$helpers=file_get_contents('sql/fragments/general_ledger_remarks_common.sql').file_get_contents('sql/fragments/general_ledger_remarks_diagnostic.sql');
$helpers=str_replace(['{MASTER}','__GL_ENCRYPTION_KEY_HEX__'],[$fixture,$fixtureKey],$helpers);
// Two physical patient catalogs represented by distinct fixture tables to force ID collisions.
$helpers=str_replace($fixture.'.patient',$fixture.'.master_patient',$helpers);
foreach (explode('$$',$helpers) as $definition) { if(trim($definition)) {$pdo->exec(trim($definition));} }
preg_match('/CREATE TRIGGER trg_gl_general_ledger_bi.*?END\$\$/s',$script,$match);$pdo->exec(substr($match[0],0,-2));
$pdo->exec("INSERT INTO accounts_payable_voucher(id,amount,status,type,type_id,due_date,added_on,added_by) VALUES(700,100,'pending','refund',700,'2026-10-31','2026-10-21 09:00:00',1)");
$pdo->exec("SET @apv_entry_sync=1; INSERT INTO accounts_payable_voucher_entry(accounts_payable_voucher_id,account_type_id,debit,credit,remarks,added_by,added_on,is_deleted) VALUES(700,4,100,0,'Refund expense',1,NOW(),0),(700,3,0,100,'Refund payable',1,NOW(),0); SET @apv_entry_sync=0");
$pdo->exec("UPDATE accounts_payable_voucher SET status='approved' WHERE id=700");
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0 AND date='2026-10-21' AND remark LIKE '%Master, José%' AND remark NOT LIKE '%Clinic%' AND remark LIKE '%200115017%' AND remark LIKE '%Refund fixture item%'")->fetchColumn()==2,'Refund GL date and encrypted master patient/item context');
check($pdo->query("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0 AND date='2026-10-21' AND remarks LIKE '%Master, José%'")->fetchColumn()==1,'Refund JE creation date and master patient context');
$pdo->exec("UPDATE accounts_payable_voucher SET approved_on='2026-10-22 08:30:00' WHERE id=700");
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0 AND date='2026-10-22'")->fetchColumn()==2,'Refund GL approval date wins over creation/due dates');
check($pdo->query("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0 AND date='2026-10-22'")->fetchColumn()==1,'Refund JE matches approval date');

check($pdo->query("SELECT COUNT(*) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source_id=700 AND j.source='accounts_payable_voucher' AND j.is_deleted=0 AND i.is_deleted=0 AND i.remarks LIKE '%Master, José%'")->fetchColumn()==2,'Refund journal items carry master patient');
$pdo->exec("CALL gl_post_apv(700); CALL gl_post_apv(700)");
check($pdo->query("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0")->fetchColumn()==1,'Repeated refund posting has one active journal');
$pdo->exec("INSERT INTO accounts_payable_voucher_payment_detail(id,accounts_payable_voucher_id,accounts_payable_voucher_payment_id,amount,date,added_by,is_deleted) VALUES(700,700,700,100,'2026-10-23',1,0)");
preg_match('/CREATE PROCEDURE gl_post_apv_payment_detail\(.*?END\$\$/s',$script,$match);
$pdo->exec('DROP PROCEDURE IF EXISTS gl_post_apv_payment_detail');$pdo->exec(str_replace('mytemr_master',$fixture,substr($match[0],0,-2)));
$pdo->exec("INSERT INTO accounts_payable_voucher_payment VALUES(700,4,0); CALL gl_post_apv_payment_detail(700)");
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND source_id=700 AND is_deleted=0 AND date='2026-10-23' AND remark LIKE '%Master, José%'")->fetchColumn()==2,'Refund payment retains payment date and master patient on both GL sides');
$paymentContext=$pdo->query("SELECT gl_enrich_remark('accounts_payable_voucher_payment_detail',700,2,'APV pay #700')")->fetchColumn();
check(str_contains($paymentContext,'Master, José') && !str_contains($paymentContext,'Clinic') && str_contains($paymentContext,'200115017'),'Linked refund payment resolves master patient');
$pdo->exec("UPDATE master_patient SET is_deleted=1 WHERE id=700; CALL gl_post_apv(700)");
check($pdo->query("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0 AND remarks NOT LIKE '%Patient:%' AND remarks LIKE '%200115017%'")->fetchColumn()==1,'Deleted master patient omits label without local fallback');
$pdo->exec('DELETE FROM master_patient WHERE id=700');
check(!str_contains($pdo->query("SELECT gl_remark_context('accounts_payable_voucher',700,2)")->fetchColumn(),'Patient:'),'Missing master patient omits label');
$pdo->exec('UPDATE refund SET branch_id=3 WHERE id=700');
check($pdo->query("SELECT gl_remark_context('accounts_payable_voucher',700,2)")->fetchColumn()===null,'Wrong refund branch cannot expose patient/transaction context');
$pdo->exec("UPDATE accounts_payable_voucher SET due_date='2026-10-31',added_on='2026-10-21',is_deleted=0,status='approved' WHERE id=101");
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=101 AND is_deleted=0 AND date='2026-10-21'")->fetchColumn()==2,'Non-refund APV falls back to creation date');
$pdo->exec("UPDATE accounts_payable_voucher SET approved_on='2026-10-22 23:59:00' WHERE id=101");
check($pdo->query("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=101 AND is_deleted=0 AND date='2026-10-22'")->fetchColumn()==2,'Non-refund GL uses approval date');
check($pdo->query("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=101 AND is_deleted=0 AND date='2026-10-22'")->fetchColumn()==1,'Non-refund JE uses approval date');
// Master-side context helper uses the same master identity and branch-qualified tracing.
$pdo->exec('ALTER TABLE accounts_payable_voucher ADD COLUMN IF NOT EXISTS cost_center_id INT NULL');
$pdo->exec("UPDATE accounts_payable_voucher SET cost_center_id=2 WHERE id=700; UPDATE refund SET branch_id=2 WHERE id=700");
$pdo->exec("INSERT INTO master_patient VALUES(700,AES_ENCRYPT('Master',UNHEX('$fixtureKey')),AES_ENCRYPT('José',UNHEX('$fixtureKey')),NULL,NULL,0)");
$pdo->exec("CREATE FUNCTION gl_billing_transaction_no(bid INT,tid INT) RETURNS VARCHAR(100) READS SQL DATA RETURN IF(bid=2,(SELECT transaction_no FROM `transaction` WHERE id=tid),NULL)");
preg_match('/CREATE FUNCTION gl_master_apv_context\(.*?END\$\$/s',file_get_contents('sql/general_ledger_triggers_master.sql'),$match);
$pdo->exec(str_replace('LEFT JOIN patient p','LEFT JOIN master_patient p',substr($match[0],0,-2)));
check(str_contains($pdo->query("SELECT gl_master_apv_context('accounts_payable_voucher',700,'APV #700')")->fetchColumn(),'Master, José'),'Master refund APV header helper resolves master patient');
check(str_contains($pdo->query("SELECT gl_master_apv_context('accounts_payable_voucher_payment_detail',700,'Payment #700')")->fetchColumn(),'Master, José'),'Master payment context resolves master patient');
check($pdo->query("SELECT gl_master_apv_context('accounts_payable_voucher',101,'Existing context')")->fetchColumn()==='Existing context','Master non-refund context remains unchanged');
$db->close();$other->close();
echo "Passed {$checks} isolated APV checks.\n";
