<?php
/** Local-only SQL search and shared memo enrichment regression. */
chdir(dirname(__DIR__));
define('ENCRYPTION_KEY','fixture-only-patient-key');
foreach (['MASTER_DB'=>'memo_patient_master_fixture','MOBILE_DB'=>'memo_patient_clinic_fixture','OAKRIDGE_DB'=>'memo_patient_clinic_fixture','MAC_DB'=>'memo_patient_other_fixture','CONSO_DB'=>'memo_patient_clinic_fixture','MANTLEWOOD_DB'=>'memo_patient_clinic_fixture','MOBILE_EMR_DB'=>'memo_patient_clinic_fixture','OAKRIDGE_EMR_DB'=>'memo_patient_clinic_fixture','MAC_EMR_DB'=>'memo_patient_other_fixture','CONSO_EMR_DB'=>'memo_patient_clinic_fixture','MANTLEWOOD_EMR_DB'=>'memo_patient_clinic_fixture'] as $key=>$value) { define($key,$value); }
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function(Throwable $e):void { fwrite(STDERR,(string)$e.PHP_EOL);exit(1); });
use CodeIgniter\Database\MySQLi\Connection;
$options=['hostname'=>getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/billing_deposit_fixture_20261021/mysql.sock','username'=>'root','password'=>'','database'=>'','DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci'];
if (!str_starts_with($options['hostname'],'/private/tmp/')) { throw new RuntimeException('Fixture socket required'); }
$db=new Connection($options);$db->initialize();
foreach ([MASTER_DB,OAKRIDGE_DB,MAC_DB] as $schema) { $db->query("DROP DATABASE IF EXISTS `$schema`");$db->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
foreach ([MASTER_DB,OAKRIDGE_DB,MAC_DB] as $schema) {
 $db->query("CREATE TABLE `$schema`.patient (id INT PRIMARY KEY,master_patient_id INT,master_id INT,is_deleted INT DEFAULT 0,first_name BLOB,last_name BLOB,middle_name BLOB,suffix BLOB)");
 $db->query("INSERT INTO `$schema`.patient(id,master_patient_id,first_name,last_name) VALUES (1,1,AES_ENCRYPT(?,?),AES_ENCRYPT(?,?))",[$schema===MAC_DB?'Bob':'Alice',ENCRYPTION_KEY,$schema===MASTER_DB?'Master':'Clinic',ENCRYPTION_KEY]);
 if ($schema!==MASTER_DB) {
  $db->query("CREATE TABLE `$schema`.booking (id INT PRIMARY KEY,patient_id INT,transaction_id INT,is_deleted INT DEFAULT 0)");
  $db->query("CREATE TABLE `$schema`.`transaction` (id INT PRIMARY KEY,transaction_no VARCHAR(100))");
  $db->query("INSERT INTO `$schema`.booking VALUES (1,1,1,0)");$db->query("INSERT INTO `$schema`.`transaction` VALUES (1,?)",[$schema===MAC_DB?'MAC-1':'OAK-1']);
 }
}
$db->query('USE `'.MASTER_DB.'`');
$db->query('CREATE TABLE branch(id INT PRIMARY KEY,name VARCHAR(100),is_deleted INT DEFAULT 0)');
$db->query("INSERT INTO branch VALUES (2,'Oakridge',0),(3,'Mactan',0)");
$db->query('CREATE TABLE billing (id INT PRIMARY KEY,company_id INT,charge_to INT,is_deleted INT DEFAULT 0,due_date DATE,invoice_no VARCHAR(80),invoice_id INT,invoice_date DATE)');
$db->query("INSERT INTO billing VALUES (10,1,1,0,'2026-10-31','INV-10',1,'2026-10-22')");
$db->query('CREATE TABLE billing_entry (id INT PRIMARY KEY,billing_id INT,branch_id INT,company_id INT,booking_id INT,transaction_id INT,endorsement_id INT,floating_patient_no INT,master_patient_id INT,as_of_date DATE,yakap_tranche INT,yakap_year INT,yakap_month INT,yakap_calculation TEXT,yakap_patient_ids TEXT,yakap_master_patient_ids TEXT,yakap_patient_names TEXT,remarks TEXT,added_on DATETIME,is_migrated INT DEFAULT 0,amount DECIMAL(13,2) DEFAULT 100,is_deleted INT DEFAULT 0)');
$db->query('CREATE TABLE billing_payment_entry (id INT PRIMARY KEY,billing_entry_id INT,amount DECIMAL(13,2),is_deleted INT DEFAULT 0)');
$db->query('INSERT INTO billing_entry(id,billing_id,branch_id,company_id,booking_id,transaction_id) VALUES (1,10,2,1,1,1),(2,10,3,1,1,1),(3,10,2,1,0,NULL),(4,10,2,1,0,NULL),(5,10,2,1,0,NULL),(6,10,2,1,0,NULL),(7,10,2,1,0,NULL)');
$db->query("UPDATE billing_entry SET floating_patient_no=7 WHERE id=3");
$db->query("UPDATE billing_entry SET remarks='Stored Person | previous context' WHERE id=4");
$db->query("UPDATE billing_entry SET yakap_tranche=1,master_patient_id=1 WHERE id=5");
$db->query("UPDATE billing_entry SET yakap_patient_names='Stored Yakap',yakap_tranche=2 WHERE id=6");
$db->query("UPDATE billing_entry SET remarks='Stored Paid' WHERE id=7");
$db->query('INSERT INTO billing_payment_entry VALUES (1,7,100,0)');
$checks=0;function check($ok,$message) { global $checks;if (!$ok) { throw new RuntimeException($message); }$checks++; }
foreach (['credit','debit'] as $kind) {
 $type=$kind.'_memo';$db->query("CREATE TABLE $type(id INT PRIMARY KEY,billing_id INT,branch_id INT,status VARCHAR(20),is_deleted INT DEFAULT 0)");
 $db->query("CREATE TABLE {$type}_entries(id INT PRIMARY KEY,{$type}_id INT,billing_entry_id INT,billing_id INT,branch_id INT,amount DECIMAL(13,2),is_deleted INT DEFAULT 0)");
}
$m=new App\Models\Billing_entry($db);
foreach (['alice'=>[1,5],'BOB'=>[2],' floating patient 7 '=>[3],'stored person'=>[4],'master'=>[5],'stored yakap'=>[6],"' OR 1=1 --"=>[],'Stored Paid'=>[]] as $term=>$ids) {
 $rows=$m->get_details(null,null,null,null,'collection',null,1,20,true,false,$term);
 $actual=array_map('intval',array_column($rows,'id'));sort($actual);sort($ids);
 check($actual===$ids,'Patient search '.$term);
 check($m->count_details(null,null,null,null,'collection',null,$term)===count($ids),'Matching count '.$term);
}
check(count($m->get_details(null,null,null,null,'collection',null,1,20,true,false,'   '))===6,'Blank search unchanged');
$first=$m->get_details(null,null,null,null,'collection',null,1,1,true,false,'alice');$second=$m->get_details(null,null,null,null,'collection',null,2,1,true,false,'alice');
check(count($first)===1 && count($second)===1 && $first[0]['id']!==$second[0]['id'],'Pagination after filtering');
check($m->count_details(null,10,999,null,'collection',null,'alice')===0,'Existing filters retained');
class Fixture_booking_details extends App\Models\Booking_detail {
 public int $calls=0;
 public function get_details($booking_id=null,$charge_to=null) { $this->calls++;return [['service'=>'Fixture CBC','booking_id'=>$booking_id]]; }
}
class Fixture_branch_names extends App\Models\Branch_master {
 public function get_details($branch_id=null,$type=null,$term=null,$page=null,$data_size=null) { return [(object)['id'=>2,'name'=>'Oakridge'],(object)['id'=>3,'name'=>'Mactan']]; }
}
$details=new Fixture_booking_details($db);$clinic=[];
foreach ([2=>OAKRIDGE_DB,3=>MAC_DB] as $id=>$schema) { $o=$options;$o['database']=$schema;$clinic[$id]=new Connection($o);$clinic[$id]->initialize(); }
$enrichment=new App\Libraries\Billing_collection_entries(new App\Models\Billing_payment_entry($db),$m,new Fixture_branch_names($db),new App\Models\Yakap_billing($db),fn($id)=>isset($clinic[$id])?['booking'=>new App\Models\Booking($clinic[$id]),'booking_detail'=>$details]:null);
$service=new App\Libraries\Memo_entries_response($db,$enrichment,$m);
foreach (['credit','debit'] as $kind) {
 $type=$kind.'_memo';
 $db->query("INSERT INTO $type VALUES (1,10,2,'pending',0),(2,10,2,'approved',0),(3,10,3,'approved',0),(4,10,2,'approved',1),(5,10,2,'pending',0)");
 $db->query("INSERT INTO {$type}_entries VALUES (1,1,1,10,2,10,0),(2,2,1,10,2,20,0),(3,3,2,10,3,30,0),(4,4,1,10,2,10,0),(5,1,2,10,3,10,0),(6,1,1,10,2,10,1),(7,1,4,10,2,10,0)");
 $class='App\\Models\\'.ucfirst($kind).'_memo_entries';$model=new $class($db);$before=$details->calls;
 $memos=[['id'=>1],['id'=>2],['id'=>3],['id'=>4],['id'=>5]];$out=$service->enrich($memos,$model,$type);
 check(count($out[0]['memo_entries'])===2,'Pending entries included; wrong owner/deleted excluded');
 check(str_contains($out[0]['memo_entries'][0]['patient_name'],'Alice') && str_contains($out[2]['memo_entries'][0]['patient_name'],'Bob'),'Colliding branch patient IDs isolated');
 check($out[0]['memo_entries'][0]['transaction_no']==='OAK-1' && $out[2]['memo_entries'][0]['transaction_no']==='MAC-1','Branch transaction numbers');
 check($out[0]['memo_entries'][0]['branch_name']==='Oakridge' && $out[2]['memo_entries'][0]['branch_name']==='Mactan','Memo children use their own branch names');
 check($out[0]['memo_entries'][0]['invoice_no']==='INV-10' && $out[2]['memo_entries'][0]['invoice_no']==='INV-10','Invoice copied from linked billing');
 check($out[0]['memo_entries'][0]['booking_details'][0]['service']==='Fixture CBC','Booking details use shared helper');
 check($details->calls-$before===2,'Repeated entry enriched only once');
 check($out[0]['memo_entries'][1]['patient_name']==='Stored Person' && $out[0]['memo_entries'][1]['patient_id']===null,'Missing booking uses stored-name fallback');
 check($out[3]['memo_entries']===[] && $out[4]['memo_entries']===[],'Empty children for deleted/no references');
 check(count($model->for_memos([1]))===2,'Page scoped child query');
 check(count($model->for_billings([10]))===2,'Billing remains approved-only');
}
// Run real memo GET/model joins with explicitly fixture-bound named connections.
$db->query('CREATE TABLE endorsement_service(id INT PRIMARY KEY,endorsement_id INT,is_deleted INT,type_id INT,type VARCHAR(30),pro_rate_price DECIMAL(13,2))');
$db->query('CREATE TABLE endorsement_patient(endorsement_service_id INT,endorsement_id INT,patient_id INT)');
foreach ([OAKRIDGE_DB,MAC_DB] as $schema) {
 $db->query("CREATE TABLE `$schema`.booking_details(id INT PRIMARY KEY,result_status VARCHAR(30),result_approval VARCHAR(30),type VARCHAR(30),test_id INT,amount DECIMAL(13,2),patient_id INT,endorsement_id INT,is_deleted INT,booking_id INT,charge_to INT)");
 $db->query("CREATE TABLE `$schema`.lab_test(id INT PRIMARY KEY,name VARCHAR(80))");
 $db->query("CREATE TABLE `$schema`.package(id INT PRIMARY KEY,name VARCHAR(80))");
 $db->query("INSERT INTO `$schema`.lab_test VALUES (1,'CBC')");
 $db->query("INSERT INTO `$schema`.booking_details VALUES (1,NULL,NULL,'lab',1,100,1,NULL,0,1,1)");
}

$db->query('CREATE TABLE company(id INT PRIMARY KEY,name VARCHAR(100),is_deleted INT DEFAULT 0)');
$db->query("INSERT INTO company VALUES (1,'Fixture Company',0)");
$db->query('CREATE TABLE account_type(id INT PRIMARY KEY,name VARCHAR(100),code VARCHAR(50))');
$db->query('CREATE TABLE user(id INT PRIMARY KEY,first_name VARCHAR(50),middle_name VARCHAR(50),last_name VARCHAR(50))');
foreach (['credit','debit'] as $kind) {
 $db->query("ALTER TABLE {$kind}_memo ADD debit_account_type_id INT,ADD credit_account_type_id INT,ADD approved_by INT,ADD disapproved_by INT,ADD date_issued DATE");
}
$config=config('Database');
foreach (['master'=>MASTER_DB,'oakridge'=>OAKRIDGE_DB,'mactan'=>MAC_DB] as $group=>$schema) {
 $settings=$options;$settings['database']=$schema;$config->{$group}=$settings;
}
trait Fixture_memo_get {
 protected function _api_validation() { return true; }
 protected function _create_response($status,$data_response,$skip_response_logging=false) { return ['status'=>$status,'data'=>$data_response]; }
 public function fixture($db,$kind,$page=1) {
  $this->db=$db;$this->payload=['status'=>'pending','page'=>$page,'data_size'=>1];
  $class='App\\Models\\'.ucfirst($kind).'_memo';$this->{$kind.'_memo'}=new $class($db);
  $class.='_entries';$this->memo_entries=new $class($db);
 }
}
class Fixture_credit_get extends App\Controllers\Credit_memos { use Fixture_memo_get; }
class Fixture_debit_get extends App\Controllers\Debit_memos { use Fixture_memo_get; }
foreach (['credit','debit'] as $kind) {
 $class='Fixture_'.$kind.'_get';$c=new $class();$c->fixture($db,$kind,1);$r=$c->get();
 check($r['status']===SUCCESS && $r['data']['size']===2 && count($r['data']['data'])===1,'Memo GET pending count/pagination');
 $row=$r['data']['data'][0];check($row['company_id']==='1' && $row['company_name']==='Fixture Company','Company comes from linked billing');
 check($row['memo_entries']===[],'First page with no children');
 $c->fixture($db,$kind,2);$r=$c->get();$row=$r['data']['data'][0];
 check(count($row['memo_entries'])===2,'Second page child loading includes pending memo');
 check(str_contains($row['memo_entries'][0]['patient_name'],'Alice') && $row['memo_entries'][0]['transaction_no']==='OAK-1','Actual GET uses clinic patient enrichment');
 check($row['memo_entries'][0]['branch_name']==='Oakridge' && $row['memo_entries'][0]['invoice_no']==='INV-10','Actual memo GET exposes branch and invoice');
 check($row['memo_entries'][0]['booking_details'][0]['type_name']==='CBC','Booking-detail response uses actual shared SQL');
}
class Fixture_patient_entry_get extends App\Controllers\Billings {
 protected function _api_validation() { return true; }
 protected function _create_response($status,$data_response,$skip_response_logging=false) { return ['status'=>$status,'data'=>$data_response]; }
 public function fixture($db,$enrichment,$page,$screen=null) {
  $this->billing_entry=new App\Models\Billing_entry($db);$this->collection_entries_service=$enrichment;
  $this->payload=['term'=>'  aLiCe  ','page'=>$page,'data_size'=>1,'screen'=>$screen];
 }
}
$c=new Fixture_patient_entry_get();$c->fixture($db,$enrichment,1);$a=$c->get_entry();
$c->fixture($db,$enrichment,2);$b=$c->get_entry();
check($a['status']===SUCCESS && $a['data']['size']===2 && count($a['data']['data'])===1,'Entry controller forwards term to rows and count');
check($a['data']['data'][0]['id']!==$b['data']['data'][0]['id'],'Entry controller paginates filtered results');
check(!array_key_exists('_patient_name_sort',$a['data']['data'][0]),'Internal patient sort key not exposed');
$db->query('INSERT INTO billing_entry(id,billing_id,branch_id,company_id,booking_id) VALUES (8,10,2,1,999)');
foreach (['credit','debit'] as $kind) {
 $type=$kind.'_memo';$db->query("INSERT INTO $type(id,billing_id,branch_id,status) VALUES (6,10,2,'pending')");
 $db->query("INSERT INTO {$type}_entries VALUES (8,6,8,10,2,1,0)");
 $class='App\\Models\\'.ucfirst($kind).'_memo_entries';$out=$service->enrich([['id'=>6]],new $class($db),$type);
 $entry=$out[0]['memo_entries'][0];
 check($entry['patient_id']===null && $entry['patient_name']===null && $entry['transaction_no']===null,'Missing booking yields null context');
}
// Approved memo aggregates adjust all read paths without multiplying payments.
$db->query("INSERT INTO credit_memo(id,billing_id,branch_id,status) VALUES (7,10,2,'approved')");
$db->query('INSERT INTO credit_memo_entries VALUES (9,7,1,10,2,150,0)');
$db->query("INSERT INTO debit_memo(id,billing_id,branch_id,status) VALUES (7,10,2,'approved')");
$db->query('INSERT INTO debit_memo_entries VALUES (9,7,7,10,2,12,0)');
$db->query('INSERT INTO billing_payment_entry VALUES (2,1,5,0),(3,1,7,0)');
$row=$m->get_details(1,null,null,null,'credit_memo')[0];
check($row['balance']==='-62.00' && $row['paid_amount']==='12.00','Negative memo-adjusted balance; payments not multiplied');
check($m->get_details(1,null,null,null,'collection')===[] && $m->count_details(1,null,null,null,'collection')===0,'Collection excludes nonpositive net balance');
check(count($m->get_details(1,null,null,null,'credit_memo'))===1 && $m->count_details(1,null,null,null,'credit_memo')===1,'Credit screen includes negative balance');
check($m->get_details(7,null,null,null,'collection')[0]['balance']==='12.00','Debit memo reopens a fully paid entry for collection');
$packed=$m->get_details_for_billing_ids([10]);$sum=array_sum(array_column($packed['entries'],'balance'));
check(abs($sum-$packed['totals']['1:1']['balance'])<0.001,'Grouped balance summary matches entry rows');
check($packed['totals']['1:1']['paid_amount']===112.0,'Grouped payment amount is not duplicated by memo joins');
check($m->count_details(null,null,null,null,'collection',null,'alice')===1,'Filtered count uses adjusted collection balance');
$c=new Fixture_patient_entry_get();$c->fixture($db,$enrichment,1,'credit_memo');$credit=$c->get_entry();
check($credit['data']['size']===2,'Controller credit-memo screen bypasses positive-balance filter');
$c->fixture($db,$enrichment,1);$collection=$c->get_entry();
check($collection['data']['size']===1,'Omitted screen retains adjusted collection filter');
$db->query('UPDATE billing SET invoice_no=NULL WHERE id=10');
$db->query('UPDATE branch SET is_deleted=1 WHERE id=2');
$null_enrichment=new App\Libraries\Billing_collection_entries(new App\Models\Billing_payment_entry($db),$m,new App\Models\Branch_master($db),new App\Models\Yakap_billing($db),fn($id)=>isset($clinic[$id])?['booking'=>new App\Models\Booking($clinic[$id]),'booking_detail'=>$details]:null);
$null_service=new App\Libraries\Memo_entries_response($db,$null_enrichment,$m);
foreach (['credit','debit'] as $kind) {
 $class='App\\Models\\'.ucfirst($kind).'_memo_entries';$out=$null_service->enrich([['id'=>6]],new $class($db),$kind.'_memo');
 $entry=$out[0]['memo_entries'][0];
 check($entry['branch_name']===null && $entry['invoice_no']===null && $entry['transaction_no']===null,'Unavailable branch/invoice/transaction remain null');
}
echo "PASS $checks patient search/memo enrichment checks\n";
