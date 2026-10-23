<?php
/** Disposable socket fixture only; never uses configured live connections. */
chdir(dirname(__DIR__));
define('MASTER_DB', 'yakap_period_fixture');
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function(Throwable $e):void { fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");exit(1); });
use App\Models\Yakap_period_billing as Yakap;
use CodeIgniter\Database\MySQLi\Connection;
function connection(): Connection {
 $db=new Connection(['hostname'=>'/private/tmp/company_cleanup_db/mysql.sock','username'=>'myt_waren','password'=>'','database'=>'yakap_period_fixture','DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4']);$db->initialize();return $db;
}
class FixtureYakap extends Yakap {
 public $sourceRows=null;
 protected function connect_emr(int $branch_id){return $this->db;}
 protected function metrics(int $branch,int $year):array {return $this->sourceRows ?? parent::metrics($branch,$year);}
 public function source(int $branch,int $year):array {return parent::metrics($branch,$year);}
}
$checks=0;
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $call,$message){try{$call();}catch(InvalidArgumentException $e){check(true,$message);return;}throw new RuntimeException($message);}
if(($argv[1]??'')==='child') {
 $model=new FixtureYakap(connection());$model->sourceRows=[['month'=>1,'fpe_count'=>1]];
 try{$model->createPeriod(['branch_id'=>7,'year'=>2020,'tranche_number'=>1,'month'=>1,'requester'=>1]);echo 'created';}
 catch(InvalidArgumentException $e){echo 'duplicate';}exit;
}
$pdo=new PDO('mysql:unix_socket=/private/tmp/company_cleanup_db/mysql.sock','myt_waren','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS yakap_period_fixture');$pdo->exec('USE yakap_period_fixture');
$pdo->exec('DROP TABLE IF EXISTS yakap_billing_period,billing_entry,billing,patient_fpe,patient,assessment,lab_result,therapeutic,item');
$pdo->exec("CREATE TABLE billing(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT NOT NULL,charge_to INT NOT NULL,due_date DATE,date_from DATE NOT NULL,date_to DATE NOT NULL,grand_total DECIMAL(13,4),balance DECIMAL(13,4),status ENUM('pending','approved','open_bill','closed_bill','cancelled'),approved_by INT,approved_on DATETIME,invoice_no VARCHAR(64) UNIQUE,invoice_date DATE,added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE billing_entry(id INT AUTO_INCREMENT PRIMARY KEY,billing_id INT,branch_id INT,company_id INT,booking_id INT NOT NULL,transaction_id INT NOT NULL,yakap_tranche TINYINT,as_of_date DATE,amount DECIMAL(13,4),balance DECIMAL(13,4),remarks TEXT,added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB");
// Apply the real idempotent migration twice using the fixture-only client.
for($i=0;$i<2;$i++){
 $proc=proc_open(['/Applications/XAMPP/xamppfiles/bin/mysql','--no-defaults','--socket=/private/tmp/company_cleanup_db/mysql.sock','-u','myt_waren','yakap_period_fixture'],[['file','sql/yakap_periods/schema.sql','r'],['pipe','w'],['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);check(proc_close($proc)===0,'Migration repeatability '.$err);
}
foreach(['patient'=>'id INT PRIMARY KEY,is_deleted INT DEFAULT 0', 'patient_fpe'=>'patient_id INT,added_on DATETIME,is_deleted INT DEFAULT 0',
 'assessment'=>"id INT PRIMARY KEY,patient_id INT,assessment_type VARCHAR(50),added_on DATETIME,is_deleted INT DEFAULT 0",
 'lab_result'=>'patient_id INT,added_on DATETIME,is_deleted INT DEFAULT 0',
 'therapeutic'=>'patient_id INT,assessment_id INT,medicine_id INT,added_on DATETIME,is_deleted INT DEFAULT 0',
 'item'=>'id INT PRIMARY KEY,is_deleted INT DEFAULT 0,is_antibiotic INT,philhealth_medicine_type VARCHAR(50)'] as $table=>$cols)$pdo->exec("CREATE TABLE $table ($cols) ENGINE=InnoDB");
$pdo->exec("INSERT INTO patient VALUES(1,0),(2,0),(3,0),(4,1),(5,0)");
$pdo->exec("INSERT INTO patient_fpe VALUES(1,'2020-01-10',0),(1,'2020-02-10',0),(2,'2020-02-01',0),(3,'2019-01-01',0),(4,'2020-01-01',0),(5,'2020-01-01',1)");
$pdo->exec("INSERT INTO assessment VALUES(1,1,'ekonsulta','2020-03-01',0),(2,1,'ekonsulta','2020-04-01',0),(3,3,'ekonsulta','2020-03-01',0),(4,2,'other','2020-02-01',0)");
$pdo->exec("INSERT INTO lab_result VALUES(1,'2020-03-01',0),(1,'2020-04-01',0),(2,'2020-05-01',0),(3,'2020-04-01',0)");
$pdo->exec("INSERT INTO item VALUES(1,0,1,'yakap'),(2,0,0,'yakap'),(3,0,0,NULL),(4,1,1,'yakap')");
$pdo->exec("INSERT INTO therapeutic VALUES(1,1,1,'2020-03-01',0),(1,1,1,'2020-04-01',0),(1,1,2,'2020-03-01',0),(1,1,3,'2020-03-01',0),(2,4,1,'2020-03-01',0),(3,3,1,'2020-03-01',0)");
$db=connection();$model=new FixtureYakap($db);$rows=$model->source(2,2020);
check(count($rows)===2 && (int)$rows[0]['fpe_count']===1 && (int)$rows[1]['fpe_count']===1,'First FPE month and active unique patients');
check((int)$rows[0]['consultation']===1 && (int)$rows[1]['consultation']===0,'Repeated ekonsulta and cohort restriction');
check((int)$rows[0]['laboratory']===1 && (int)$rows[1]['laboratory']===1,'Labs work without is_sent');
check((int)$rows[0]['antibiotic']===1 && (int)$rows[0]['ncd']===1 && (int)$rows[1]['antibiotic']===0,'Medicine classification and assessment filter');
$sample=Yakap::scores(14850,['consultation'=>8000,'laboratory'=>3000,'antibiotic'=>2000,'ncd'=>1500]);
check($sample['performance_factor']===0.52 && $sample['calculated_amount']===7876440.0,'Screenshot totals');
check(Yakap::scores(0,[])['calculated_amount']===0.0,'Zero denominator');
check(Yakap::scores(1,array_fill_keys(['consultation','laboratory','antibiotic','ncd'],10))['performance_factor']===1.0,'KPI caps');
$zone=new DateTimeZone('Asia/Manila');
check(!Yakap::completed(2020,1,new DateTimeImmutable('2020-01-31 23:59:59',$zone)) && Yakap::completed(2020,1,new DateTimeImmutable('2020-02-01 00:00:00',$zone)),'Monthly boundary');
check(!Yakap::completed(2020,0,new DateTimeImmutable('2020-12-31 23:59:59',$zone)) && Yakap::completed(2020,0,new DateTimeImmutable('2021-01-01',$zone)),'Annual boundary');
foreach([[],['branch_id'=>5,'year'=>2020],['branch_id'=>2,'year'=>'2020x'],['branch_id'=>2,'year'=>2020,'tranche_number'=>1,'month'=>13],['branch_id'=>2,'year'=>2020,'tranche_number'=>2,'month'=>1]] as $input)rejects(fn()=>Yakap::validatePeriod($input,true),'Input rejected');
$input=['branch_id'=>2,'year'=>2020,'tranche_number'=>1,'month'=>1,'requester'=>1];
$result=$model->createPeriod($input);
check($result['grand_total']===680.0,'Server-derived monthly amount');
check($pdo->query('SELECT status FROM billing WHERE id='.$result['billing_id'])->fetchColumn()==='approved','Automatic approval');
$preview=$model->preview(2,2020,1);
check(count($preview['months'])===12 && $preview['months'][0]['is_billed'] && !$preview['months'][1]['is_billed'],'12 month billed/unbilled results');
check($preview['totals']['total']['fpe_count']===2 && $preview['totals']['billed']['fpe_count']===1,'Monthly totals');
check($preview['months'][0]['billing']['calculation_snapshot']['calculated_amount']===680,'Stored snapshot');
rejects(fn()=>$model->createPeriod($input),'Duplicate active period');
rejects(fn()=>$model->createPeriod($input+['grand_total'=>100]),'Duplicate remains rejected');
$bad=$input;$bad['month']=2;$bad['grand_total']=100;rejects(fn()=>$model->createPeriod($bad),'Conflicting amount');
check((int)$pdo->query('SELECT COUNT(*) FROM yakap_billing_period')->fetchColumn()===1,'Reservation rollback');
$bad['grand_total']=680;$bad['charge_to']=2032;rejects(fn()=>$model->createPeriod($bad),'Conflicting company');
$bad=$input;$bad['year']=2090;rejects(fn()=>$model->createPeriod($bad),'Future period');
$empty=$input;$empty['year']=2018;rejects(fn()=>$model->createPeriod($empty),'Empty period');
$model->assertMetadataUpdate($result['billing_id'],['invoice_no'=>'YAKAP-INVOICE']);
rejects(fn()=>$model->assertMetadataUpdate($result['billing_id'],['grand_total'=>10]),'Protected amount update');
$pdo->exec('UPDATE billing SET status="cancelled" WHERE id='.$result['billing_id']);
$replacement=$model->createPeriod($input);check($replacement['billing_id']!==$result['billing_id'],'Cancelled bill replacement');
check((int)$pdo->query('SELECT COUNT(*) FROM billing_entry')->fetchColumn()===2,'Cancelled history retained');
$pdo->exec('UPDATE billing SET is_deleted=1 WHERE id='.$replacement['billing_id']);
$third=$model->createPeriod($input);check($third['billing_id']!==$replacement['billing_id'],'Deleted bill replacement');
$model->sourceRows=[['month'=>1,'fpe_count'=>100]];$big=$input;$big['branch_id']=3;
$large=$model->createPeriod($big);check($pdo->query('SELECT status FROM billing WHERE id='.$large['billing_id'])->fetchColumn()==='pending','Approval threshold retained');
check(!$model->preview(2,2020,1)['months'][0]['can_bill'],'Branch reservations isolated');
$annual=$input;$annual['tranche_number']=2;unset($annual['month']);$annual['branch_id']=4;
$model->sourceRows=[['month'=>1,'fpe_count'=>14850,'consultation'=>8000,'laboratory'=>3000,'antibiotic'=>2000,'ncd'=>1500]];
$yearBill=$model->createPeriod($annual);check($yearBill['grand_total']===7876440.0,'Annual creation snapshot amount');
check($model->preview(4,2020,2)['is_billed'],'Annual billed status');
// Force entry insertion failure after header insert, proving all writes roll back.
$pdo->exec("CREATE TRIGGER fixture_fail BEFORE INSERT ON billing_entry FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture insert failure'");
$before=(int)$pdo->query('SELECT COUNT(*) FROM billing')->fetchColumn();$bad=$input;$bad['branch_id']=1;
try{$model->createPeriod($bad);throw new RuntimeException('Expected insert failure');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'Unable to create YAKAP billing entry'),'Insertion failed');}
check((int)$pdo->query('SELECT COUNT(*) FROM billing')->fetchColumn()===$before,'Atomic header rollback');$pdo->exec('DROP TRIGGER fixture_fail');
$jobs=[];for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,__FILE__,'child'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);$jobs[]=[$p,$pipes];}
$out=[];foreach($jobs as [$p,$pipes]){$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);check(proc_close($p)===0,'Concurrent worker '.$err);}sort($out);check($out===['created','duplicate'],'Concurrent creation produces one bill');
check((int)$pdo->query('SELECT COUNT(*) FROM billing_entry WHERE branch_id=7')->fetchColumn()===1,'No duplicate concurrent entry');
$model=new FixtureYakap(connection());$pdo->exec('RENAME TABLE lab_result TO lab_result_missing');
try{$model->source(2,2020);throw new RuntimeException('Expected missing source failure');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'Required EMR'),'Missing source not silently zero');}
$pdo->exec('RENAME TABLE lab_result_missing TO lab_result');
echo "PASS: {$checks} isolated YAKAP checks.\n";
