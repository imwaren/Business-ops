<?php
/** Dedicated disposable local schemas; no configured remote connection is used. */
define('MASTER_DB', 'aged_payables_fixture');
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error . "\n"); exit(1); });
use App\Models\Accounts_payable_voucher;
use CodeIgniter\Database\MySQLi\Connection;
$socket=getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/billing_deposit_fixture_20261021/mysql.sock';
if (!str_starts_with($socket,'/private/tmp/')) { throw new RuntimeException('Local fixture socket required'); }
$pdo=new PDO('mysql:unix_socket='.$socket,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schemas=['master'=>'aged_payables_fixture','oakridge'=>'aged_payables_clinic_fixture','mactan'=>'aged_payables_second_fixture'];
$dbs=[];$checks=0;
function check($ok,$message) {global $checks;if (!$ok) {throw new RuntimeException($message);} $checks++;}
foreach ($schemas as $group=>$schema) {
    $pdo->exec("DROP DATABASE IF EXISTS $schema");$pdo->exec("CREATE DATABASE $schema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE $schema");
    $pdo->exec('CREATE TABLE accounts_payable_voucher(id INT PRIMARY KEY,type VARCHAR(50),type_id INT,supplier_id INT,supplier_type VARCHAR(30),cost_center_id INT,amount DECIMAL(13,4),balance DECIMAL(13,4),status VARCHAR(30),due_date DATE,added_on DATETIME,added_by INT,approved_by INT,disapproved_by INT,is_deleted INT DEFAULT 0)');
    foreach (['petty_cash','cash_advance','liquidation','change_fund','direct_payment','reimbursement','replenishment'] as $table) {
        $pdo->exec("CREATE TABLE $table(id INT PRIMARY KEY,cost_center_id INT,remarks TEXT,is_deleted INT DEFAULT 0)");
    }
    foreach (['liquidation_detail'=>'liquidation_id','reimbursement_item'=>'reimbursement_id','replenishment_item'=>'replenishment_id'] as $table=>$key) {
        $pdo->exec("CREATE TABLE $table(id INT PRIMARY KEY,$key INT,invoice_no VARCHAR(100),is_deleted INT DEFAULT 0)");
        foreach (['account_type_id','supplier_id','tin','address','description','expense_date','amount','vat','total_value','total','remarks','is_vat'] as $column) {
            $pdo->exec("ALTER TABLE $table ADD $column VARCHAR(100) NULL");
        }
    }
    $pdo->exec('CREATE TABLE receipt_note(id INT PRIMARY KEY,branch_id INT,purchase_order_id INT,sales_invoice_no VARCHAR(100),remarks TEXT,is_deleted INT DEFAULT 0)');
    $pdo->exec('CREATE TABLE purchase_order(id INT PRIMARY KEY,branch_id INT,purchase_request_id INT,po_number VARCHAR(100),is_deleted INT DEFAULT 0)');
    $pdo->exec('CREATE TABLE aoq(id INT PRIMARY KEY,branch_id INT,purchase_request_id INT,is_deleted INT DEFAULT 0)');
    $pdo->exec('CREATE TABLE patient(id INT PRIMARY KEY,first_name VARBINARY(255),last_name VARBINARY(255),middle_name VARBINARY(255),suffix VARBINARY(255),is_deleted INT DEFAULT 0)');
    $dbs[$group]=new Connection(['hostname'=>$socket,'username'=>'root','password'=>'','database'=>$schema,'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci']);
    $dbs[$group]->initialize();
}
$master=$dbs['master'];$pdo->exec('USE aged_payables_fixture');
$pdo->exec('CREATE TABLE branch(id INT PRIMARY KEY,name VARCHAR(100),type VARCHAR(30),is_deleted INT DEFAULT 0)');
$pdo->exec("INSERT INTO branch VALUES(2,'Clinic A','clinic',0),(3,'Clinic B','clinic',0),(20,'Finance','shared',0),(24,'EO','shared',0),(99,'Future','shared',0),(100,'Deleted','shared',1)");
$pdo->exec('CREATE TABLE user(id INT PRIMARY KEY,first_name VARCHAR(50),last_name VARCHAR(50),middle_name VARCHAR(50),is_deleted INT DEFAULT 0)');
$pdo->exec("INSERT INTO user VALUES(1,'MYT','CHA','Middle',0)");
$pdo->exec('CREATE TABLE account_type(id INT PRIMARY KEY,name VARCHAR(100),is_deleted INT DEFAULT 0)');
$pdo->exec('CREATE TABLE supplier(id INT PRIMARY KEY,name VARCHAR(100),category VARCHAR(100),is_deleted INT DEFAULT 0)');
$pdo->exec("INSERT INTO supplier VALUES(1,'Supplier','Clinic Supplies',0)");
$cache=new ReflectionProperty(CodeIgniter\Database\Config::class,'instances');$cache->setAccessible(true);$cache->setValue(null,$dbs+['default'=>$master,'tests'=>$master]);
function voucher($db,$id,$center,$type,$payee,$payeeType,$amount,$balance,$due,$status='approved',$deleted=0) {
    $db->query('INSERT INTO accounts_payable_voucher VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$type,$id,$payee,$payeeType,$center,$amount,$balance,$status,$due,'2026-10-23 09:00:00',1,1,null,$deleted]);
}
voucher($dbs['oakridge'],1,2,'refund',7,'patient',2500,2500,'2026-10-31');
voucher($dbs['mactan'],1,3,'replenishment',1,'payor',4500,4500,'2026-06-13');
voucher($master,1,24,'liquidation',1,'payor',100,25,'2026-10-21');
voucher($master,2,20,'cash_advance',1,'payor',5000,5000,'2026-11-01');
voucher($master,3,99,'receipt_note',1,'supplier',10,10,'2026-09-30');
voucher($dbs['mactan'],2,3,'refund',7,'patient',2,2,'2026-08-23');
voucher($dbs['mactan'],3,3,'refund',88,'patient',3,3,'2026-07-25');
foreach ([['pending',10,0],['disapproved',10,0],['open',10,0],['closed',10,0],['approved',0,0],['approved',-1,0],['approved',10,1]] as $i=>$case) {
    voucher($master,10+$i,20,'cash_advance',1,'payor',10,$case[1],'2026-10-23',$case[0],$case[2]);
}
voucher($master,30,100,'cash_advance',1,'payor',999,999,'2026-10-23');
voucher($master,31,777,'cash_advance',1,'payor',999,999,'2026-10-23');
$master->query("INSERT INTO liquidation VALUES(1,24,'EO source',0),(2,20,'Finance source',0)");
$master->query("INSERT INTO liquidation_detail(id,liquidation_id,invoice_no,is_deleted) VALUES(1,1,'EO-INV',0),(2,2,'FIN-INV',0),(3,1,'DELETED',1)");
$master->query("INSERT INTO receipt_note VALUES(3,99,3,'RN-INV','Receipt',0)");
$master->query("INSERT INTO purchase_order VALUES(3,99,3,'PO-003',0)");
$dbs['mactan']->query("INSERT INTO replenishment VALUES(1,3,'Replenishment',0)");
$dbs['mactan']->query("INSERT INTO replenishment_item(id,replenishment_id,invoice_no,is_deleted) VALUES(1,1,'CLINIC-INV',0)");
foreach (['oakridge'=>'ALPHA','mactan'=>'BETA'] as $group=>$last) {
    $dbs[$group]->query('INSERT INTO patient VALUES(7,AES_ENCRYPT(?,?),AES_ENCRYPT(?,?),NULL,NULL,0)',['PATIENT',ENCRYPTION_KEY,$last,ENCRYPTION_KEY]);
}
$router=new App\Libraries\Fund_cost_center($master);$map=$router->groups();
$model=new Accounts_payable_voucher($master);
$rows=$model->get_aged_payables_all_branches($map,'2026-10-23');
check(count($rows)===7,'Same approved positive-balance population including future shared center');
$identity=static fn($r)=>((int)($r['branch_id'] ?? $r['cost_center_id'])).':'.$r['id'];
$payments=[];
for ($page=1;$page<=4;$page++) {
    $pack=$model->get_all_details_all_branches($map,null,null,null,null,null,null,'for_payment',null,$page,2);
    check($pack['size']===7,'Payment pagination count');$payments=array_merge($payments,$pack['data']);
}
$a=array_map($identity,$rows);$b=array_map($identity,$payments);sort($a);sort($b);
check($a===$b,'All payment pages match report voucher identities');
check(array_sum(array_column($rows,'balance'))===12040.0,'Expected outstanding balance 12040');
check(array_sum(array_column($rows,'balance'))===array_sum(array_column($payments,'balance')),'Payment and report balances reconcile');
$scoped=$model->get_aged_payables_all_branches([24=>'master'],'2026-10-23');
check(count($scoped)===1 && $scoped[0]['branch_id']===24 && $scoped[0]['branch_name']==='EO','Shared filter isolates actual owner');
$report=(new ReflectionClass(App\Controllers\Reports::class))->newInstanceWithoutConstructor();
function invokeReport($report,$method,...$args) {$r=new ReflectionMethod($report,$method);$r->setAccessible(true);return $r->invoke($report,...$args);}
$names=invokeReport($report,'_fill_aged_payables_patient_names',$rows);
$patients=array_values(array_filter($names,fn($r)=>$r['supplier_type']==='patient'));
check(count(array_unique(array_filter(array_column($patients,'supplier_name'))))===2,'Encrypted colliding branch patients resolve distinct names');
check(count(array_filter($patients,fn($r)=>$r['supplier_id']==88 && $r['supplier_name']===null))===1,'Missing patient remains null');
$named=invokeReport($report,'_fill_aged_payables_invoice_references',$names);
$eo=array_values(array_filter($named,fn($r)=>$r['branch_id']===24))[0];
check($eo['invoice_reference']==='EO-INV','Shared invoice scope excludes other center/deleted children');
$rn=array_values(array_filter($named,fn($r)=>$r['branch_id']===99))[0];
check($rn['invoice_reference']==='RN-INV' && $rn['remarks']==='Payment for PO # PO-003','Shared procurement references preserved');
foreach (['summary','detail','analysis'] as $tab) {
    $built=invokeReport($report,'_build_aged_payables_'.$tab,$named,'2026-10-23');
    check($built['totals']['total']==='12040.0000',"$tab total reconciles including current");
    if ($tab==='detail') {
        $ids=[];foreach ($built['suppliers'] as $g) {foreach ($g['items'] as $r) {$ids[]=$identity($r);}}
        sort($ids);check($ids===$a,'Detail retains complete branch-qualified identities');
        check(count(array_filter($built['suppliers'],fn($g)=>$g['supplier_type']==='patient' && $g['supplier_id']==7))===2,'Patient grouping remains branch-specific');
    }
}
$older=$model->get_aged_payables_all_branches($map,'2020-01-01');
check(array_map($identity,$older)===array_map($identity,$rows),'As-of changes buckets, not payable selection');
foreach ($rows as $r) {check((float)$r['balance']==array_sum(array_map(fn($k)=>(float)$r[$k],['current','1_month','2_months','3_months','older'])),'Ageing buckets sum to balance');}
// Boundary dates retain the existing month-start and day-range ageing rules.
foreach (['2026-11-01'=>'current','2026-10-01'=>'current','2026-09-30'=>'1_month',
    '2026-08-24'=>'1_month','2026-08-23'=>'2_months','2026-07-25'=>'2_months',
    '2026-07-24'=>'3_months','2026-06-25'=>'3_months','2026-06-24'=>'older'] as $due=>$bucket) {
    $master->query('UPDATE accounts_payable_voucher SET due_date=? WHERE id=1',[$due]);
    $boundary=$model->get_aged_payables_all_branches([24=>'master'],'2026-10-23')[0];
    check((float)$boundary[$bucket]===25.0,'Ageing boundary '.$due.' uses '.$bucket);
    check(array_sum(array_map(fn($k)=>(float)$boundary[$k],['current','1_month','2_months','3_months','older']))===25.0,'Boundary counts amount once');
}
$master->query("UPDATE accounts_payable_voucher SET due_date='2026-10-21' WHERE id=1");
$dbs['oakridge']->query('UPDATE patient SET is_deleted=1 WHERE id=7');
$deletedNames=invokeReport($report,'_fill_aged_payables_patient_names',$rows);
check(array_values(array_filter($deletedNames,fn($r)=>$r['branch_id']===2))[0]['supplier_name']===null,'Deleted patient name remains null');
$dbs['oakridge']->query('UPDATE patient SET is_deleted=0 WHERE id=7');
check(array_values(array_filter($named,fn($r)=>$r['branch_id']===24))[0]['supplier_name']==='MYT CHA Middle','Payor display order matches APV list');
check(array_values(array_filter($named,fn($r)=>$r['branch_id']===99))[0]['supplier_name']==='Supplier','Master supplier display name retained');

// Exercise public report payloads and branch rejection through the actual controller.
class AgedReportFixture extends App\Controllers\Reports {
    public function configure($model,$payload) {$this->accounts_payable_voucher=$model;$this->payload=$payload;}
    protected function _create_response($status,$data,$skip_response_logging=false) {return ['status'=>$status,'data'=>$data];}
}
$c=new AgedReportFixture();$c->configure($model,['tab'=>'summary','as_of_date'=>'2026-10-23','branch_id'=>24]);
check($c->get_accounts_payable()['data']['totals']['total']==='25.0000','Public selected shared report balance');
foreach ([100,777,'2invalid'] as $bad) {$c->configure($model,['tab'=>'summary','branch_id'=>$bad]);check(invokeReport($c,'_aged_payables_payload')['status']===FAIL,'Invalid/deleted branch rejected');}
// Supplier filters are applied before ageing and aggregation on every storage group.
foreach (['supplier'=>10,'payor'=>9525,'patient'=>2505] as $type=>$total) {
    foreach (['summary','detail','analysis'] as $tab) {
        $c->configure($model,['tab'=>$tab,'as_of_date'=>'2026-10-23','supplier_type'=>$type]);
        $result=$c->get_accounts_payable()['data'];
        check((float)$result['totals']['total']===$total*1.0,"$tab type-only $type reconciles");
        if ($tab!=='analysis') {
            check(array_unique(array_column($result['suppliers'],'supplier_type'))===[$type],"$tab returns supplier_type");
        }
    }
}
foreach (['supplier'=>10,'payor'=>9525] as $type=>$total) {
    $c->configure($model,['tab'=>'summary','supplier_id'=>1,'supplier_type'=>$type]);
    check((float)$c->get_accounts_payable()['data']['totals']['total']===$total*1.0,'Same numeric supplier/payor ID isolated');
}
$c->configure($model,['tab'=>'detail','supplier_id'=>7,'supplier_type'=>'patient']);
$result=$c->get_accounts_payable()['data'];
check(count($result['suppliers'])===2 && $result['totals']['total']==='2502.0000','Patient ID filter retains branch-specific groups');
$c->configure($model,['tab'=>'summary','supplier_id'=>' 1 ','supplier_type'=>' payor ','branch_id'=>24]);
check($c->get_accounts_payable()['data']['totals']['total']==='25.0000','Combined shared center/payee filters and trimming');
$c->configure($model,['tab'=>'summary','supplier_id'=>7,'supplier_type'=>'patient','branch_id'=>2]);
check($c->get_accounts_payable()['data']['totals']['total']==='2500.0000','Combined clinic/patient filter');
foreach ([[],['supplier_id'=>'','supplier_type'=>''],['supplier_id'=>null,'supplier_type'=>null],['supplier_id'=>'  ','supplier_type'=>' ']] as $filter) {
    $c->configure($model,['tab'=>'summary']+$filter);
    check($c->get_accounts_payable()['data']['totals']['total']==='12040.0000','Empty filters preserve full report');
}
foreach ([['supplier_id'=>1],['supplier_type'=>'unknown'],['supplier_type'=>[]],['supplier_type'=>true],
    ['supplier_id'=>0,'supplier_type'=>'supplier'],['supplier_id'=>-1,'supplier_type'=>'supplier'],
    ['supplier_id'=>'1.5','supplier_type'=>'supplier'],['supplier_id'=>1.0,'supplier_type'=>'supplier'],
    ['supplier_id'=>[],'supplier_type'=>'supplier'],['supplier_id'=>true,'supplier_type'=>'supplier'],
    ['supplier_id'=>"1 OR 1=1",'supplier_type'=>'supplier']] as $filter) {
    $c->configure($model,['tab'=>'summary']+$filter);
    check(invokeReport($c,'_aged_payables_payload')['status']===FAIL,'Invalid payee filter rejected');
}
$c->configure($model,['tab'=>'summary','supplier_id'=>999,'supplier_type'=>'supplier']);
check(invokeReport($c,'_aged_payables_payload')['status']===FAIL_NOT_FOUND,'Unmatched filter preserves no-results behavior');
check($model->get_aged_payables_all_branches($map,'2026-10-23',1,"payor' OR 1=1 --")===[],'Model type predicate uses bound parameters');

echo "Passed $checks isolated aged payable checks.\n";
