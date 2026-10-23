<?php
/** Tests only a disposable local socket database; no configured application connections. */
chdir(dirname(__DIR__));require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");exit(1);});
use App\Models\Company;
use CodeIgniter\Database\MySQLi\Connection;
$pdo=new PDO('mysql:unix_socket=/private/tmp/company_cleanup_db/mysql.sock','myt_waren','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS company_annual_fixture');$pdo->exec('USE company_annual_fixture');
$pdo->exec('DROP TABLE IF EXISTS company,request,trail,wallet_history');
$pdo->exec("CREATE TABLE company(id INT PRIMARY KEY,name VARCHAR(100),account_status ENUM('active','inactive') NOT NULL DEFAULT 'active',accreditation_status ENUM('pending','approved','hold','active','inactive') NOT NULL DEFAULT 'active',updated_by INT NULL,updated_on DATETIME NULL,is_deleted INT DEFAULT 0,wallet_validity_date_from DATE NULL,wallet_validity_date_to DATE NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE request(id INT PRIMARY KEY,company_id INT,added_on DATETIME,is_deleted INT DEFAULT 0,status VARCHAR(30),KEY idx_request_company(company_id,is_deleted)) ENGINE=InnoDB");
$pdo->exec('CREATE TABLE trail(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT,added_by INT NULL,added_on DATETIME) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE wallet_history(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT) ENGINE=InnoDB');
$pdo->exec("CREATE TRIGGER fixture_audit AFTER UPDATE ON company FOR EACH ROW BEGIN IF NEW.account_status<>OLD.account_status OR NEW.accreditation_status<>OLD.accreditation_status THEN INSERT INTO trail(company_id,added_by,added_on) VALUES(NEW.id,NEW.updated_by,NEW.updated_on); END IF; END");
$pdo->exec("CREATE TRIGGER fixture_wallet AFTER UPDATE ON company FOR EACH ROW BEGIN IF NOT (NEW.wallet_validity_date_from<=>OLD.wallet_validity_date_from) OR NOT (NEW.wallet_validity_date_to<=>OLD.wallet_validity_date_to) THEN INSERT INTO wallet_history(company_id) VALUES(NEW.id); END IF; END");
// Populate pre-existing inconsistent rows before installing synchronization triggers.
for($id=1;$id<=13;$id++)$pdo->exec("INSERT INTO company(id,name) VALUES($id,'Fixture $id')");
$pdo->exec("UPDATE company SET account_status='inactive',accreditation_status='hold' WHERE id=9");
$pdo->exec('UPDATE company SET is_deleted=1 WHERE id=10');
$pdo->exec("UPDATE company SET account_status='inactive',accreditation_status='inactive' WHERE id=11");
$pdo->exec('DELETE FROM trail');
$pdo->exec("INSERT INTO request VALUES
(1,1,'2025-01-01 00:00:00',0,'pending'),(2,2,'2025-12-31 23:59:59',0,'approved'),
(3,3,'2025-05-01',0,'archived'),(4,4,'2025-05-01',0,'returned'),(5,5,'2025-05-01',0,'disapproved'),
(6,6,'2025-03-01',1,'approved'),(7,7,'2024-12-31 23:59:59',0,'approved'),(8,8,'2026-01-01 00:00:00',0,'approved'),
(9,9,'2025-01-01',0,'approved'),(10,11,'2025-05-01',0,'approved')");
function connection():Connection{$db=new Connection(['hostname'=>'/private/tmp/company_cleanup_db/mysql.sock','username'=>'myt_waren','password'=>'','database'=>'company_annual_fixture','DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4']);$db->initialize();return $db;}
$checks=0;function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
for($i=0;$i<2;$i++){
 $p=proc_open(['/Applications/XAMPP/xamppfiles/bin/mysql','--no-defaults','--socket=/private/tmp/company_cleanup_db/mysql.sock','-u','myt_waren','company_annual_fixture'],[['file','sql/company_annual_status/triggers.sql','r'],['pipe','w'],['pipe','w']],$pipes);
 $err=stream_get_contents($pipes[2]);check(proc_close($p)===0,'Trigger installation repeatable '.$err);
}
$db=connection();$model=new Company($db);
$before=$pdo->query('SELECT * FROM company ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$preview=$model->annualStatus(2025,true);check($preview['matched_rows']===6 && $preview['affected_rows']===0,'Correct dry-run candidates');
check($before===$pdo->query('SELECT * FROM company ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Dry run read only');
$result=$model->annualStatus(2025);check($result['affected_rows']===6,'Six companies updated');
foreach([1,2,3,4,5] as $id)check($pdo->query('SELECT account_status FROM company WHERE id='.$id)->fetchColumn()==='active','Any nondeleted request status qualifies '.$id);
foreach([6,7,8,9,12,13] as $id){$row=$pdo->query('SELECT * FROM company WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);check($row['account_status']==='inactive'&&$row['accreditation_status']==='inactive'&&$row['updated_by']===null&&$row['updated_on']!==null,'Inactive result and system audit '.$id);}
check($pdo->query('SELECT account_status FROM company WHERE id=10')->fetchColumn()==='active','Deleted company untouched');
check((int)$pdo->query('SELECT COUNT(*) FROM trail')->fetchColumn()===6,'Existing audit trigger preserved');
check((int)$pdo->query('SELECT COUNT(*) FROM wallet_history')->fetchColumn()===0,'Wallet trigger unaffected');
check($model->annualStatus(2025)['affected_rows']===0,'Rerun idempotent');
check((int)$pdo->query('SELECT COUNT(*) FROM trail')->fetchColumn()===6,'Rerun no extra audit');
$pdo->exec("INSERT INTO company(id,name,account_status,accreditation_status) VALUES(20,'Inactive insert','inactive','active')");
check($pdo->query('SELECT accreditation_status FROM company WHERE id=20')->fetchColumn()==='inactive','Insert synchronization');
$pdo->exec("UPDATE company SET account_status='inactive',accreditation_status='active' WHERE id=1");
check($pdo->query('SELECT accreditation_status FROM company WHERE id=1')->fetchColumn()==='inactive','Inactive takes precedence');
$pdo->exec("UPDATE company SET accreditation_status='active' WHERE id=1");
check($pdo->query('SELECT accreditation_status FROM company WHERE id=1')->fetchColumn()==='inactive','Accreditation alone cannot override inactive account');
$db->transBegin();$model->update(['id'=>1],['account_status'=>'active','accreditation_status'=>'active','updated_by'=>67]);$db->transRollback();
check($pdo->query('SELECT account_status FROM company WHERE id=1')->fetchColumn()==='inactive','Quotation-style activation rollback');
$db->transBegin();$model->update(['id'=>1],['account_status'=>'active','accreditation_status'=>'active','updated_by'=>67]);$db->transCommit();
check($pdo->query('SELECT accreditation_status FROM company WHERE id=1')->fetchColumn()==='active','Quotation-style activation commits both statuses');
foreach([true,'2025x',(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y'),1899] as $year){try{$model->annualStatus($year,true);throw new RuntimeException('Invalid year accepted');}catch(InvalidArgumentException $e){check(true,'Invalid completed-year input');}}
check($model->annualStatus(null,true)['year']===(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y')-1,'Default previous Manila year');
// Manual scripts contain the same cutoff and candidate set; test with preview SQL only.
foreach(['uat','prod'] as $env){$sql=file_get_contents('sql/company_annual_status/cleanup_'.$env.'.sql');check(str_contains($sql,"'2025-01-01'")&&str_contains($sql,"'2026-01-01'")&&str_contains($sql,'START TRANSACTION;')&&str_contains($sql,'COMMIT;'),'Manual cleanup package '.$env);}
$quotation=file_get_contents('app/Controllers/Quotations.php');check(str_contains($quotation,"'accreditation_status' => 'active',\n                    'account_status' => 'active',"),'Existing quotation activation supplies both values');
echo "PASS: {$checks} isolated annual company status checks.\n";
