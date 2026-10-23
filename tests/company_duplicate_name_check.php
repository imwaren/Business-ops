<?php
/** Isolated controller/model regression checks: disposable local socket only. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function(Throwable $error):void {fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");exit(1);});
use App\Controllers\Companies;
use App\Models\Company;
use App\Models\Customer_account;
use CodeIgniter\Database\MySQLi\Connection;
$pdo=new PDO('mysql:unix_socket=/private/tmp/company_cleanup_db/mysql.sock','myt_waren','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS company_name_fixture');$pdo->exec('USE company_name_fixture');
$pdo->exec('DROP TABLE IF EXISTS company,customer_account');
$pdo->exec("CREATE TABLE company(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,email VARCHAR(100) NULL,company_no VARCHAR(20),registered_date DATE,added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0,UNIQUE KEY name(name,is_deleted),UNIQUE KEY email(email)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE customer_account(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT,name VARCHAR(100),added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB");
function connection(bool $throw=false):Connection {
 $db=new Connection(['hostname'=>'/private/tmp/company_cleanup_db/mysql.sock','username'=>'myt_waren','password'=>'','database'=>'company_name_fixture','DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4']);$db->initialize();$db->transException($throw);return $db;
}
class CompanyControllerFixture extends Companies {
 public $attachmentCalls=0;
 public function __construct($db,$payload,$company=null) {
  $this->db=$db;$this->payload=$payload;$this->company=$company??new Company($db);$this->customer_account=new Customer_account($db);
  $this->request=new class {function getFileMultiple($field){return [];}};
 }
 protected function _api_validation(){return true;}
 protected function _create_response($status,$data,$skip_response_logging=false){
  return ['status'=>$status,'data'=>$data];
 }
 public function _upload_attachments($company_id,$path){$this->attachmentCalls++;return true;}
}
$checks=0;
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rowCount($table){global $pdo;return (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function run($method,array $input,bool $throw=false,$company=null) {
 $db=connection($throw);$c=new CompanyControllerFixture($db,$input,$company);$result=$c->$method();
 return [$result,$c,$db];
}
[$created,$c,$db]=run('create',['requester'=>67,'name'=>'MYT test','email'=>'first@example.test']);
check($created['status']===SUCCESS,'Successful create');$first=(int)$created['data']['company_id'];
check(rowCount('company')===1 && rowCount('customer_account')===1,'Company/customer created together');$db->close();
foreach([false,true] as $throw){
 [$r,$c,$db]=run('create',['requester'=>67,'name'=>'MYT test'],$throw);
 check($r['status']===FAIL && in_array('Company name already exists.',$r['data'],true),'Duplicate create HTTP400 in return/exception mode');
 check(rowCount('company')===1 && rowCount('customer_account')===1,'Duplicate create no extra records');
 check($c->attachmentCalls===0,'Duplicate create no attachment handling');
 check((int)$db->query('SELECT @@in_transaction active')->getRow()->active===0,'Duplicate create rollback');$db->close();
}
[$created,$c,$db]=run('create',['requester'=>67,'name'=>'Second company','email'=>'second@example.test']);$second=(int)$created['data']['company_id'];$db->close();
foreach([false,true] as $throw){
 [$r,$c,$db]=run('update',['requester'=>67,'company_id'=>$second,'name'=>'MYT test'],$throw);
 check($r['status']===FAIL && in_array('Company name already exists.',$r['data'],true),'Duplicate rename HTTP400 in return/exception mode');
 check($pdo->query('SELECT name FROM company WHERE id='.$second)->fetchColumn()==='Second company','Failed rename retains company');
 check($pdo->query('SELECT name FROM customer_account WHERE company_id='.$second)->fetchColumn()==='Second company','Failed rename retains account');
 check($c->attachmentCalls===0,'Failed rename no attachment handling');
 check((int)$db->query('SELECT @@in_transaction active')->getRow()->active===0,'Failed rename rollback');$db->close();
}
[$r,$c,$db]=run('update',['requester'=>67,'company_id'=>$second,'name'=>'Renamed company']);
check($r['status']===SUCCESS && $c->attachmentCalls===1,'Successful update retains attachment workflow');
check($pdo->query('SELECT name FROM customer_account WHERE company_id='.$second)->fetchColumn()==='Renamed company','Successful account sync');$db->close();
foreach([false,true] as $throw){
 [$r,$c,$db]=run('create',['requester'=>67,'name'=>'Different company','email'=>'first@example.test'],$throw);
 check($r['status']===FAIL && !in_array('Company name already exists.',$r['data'],true),'Other unique key not mislabeled');
 check(rowCount('company')===2 && rowCount('customer_account')===2,'Other duplicate rollback');$db->close();
}
[$r,$c,$db]=run('create',['requester'=>67,'name'=>null],true);
check($r['status']===FAIL && !in_array('Company name already exists.',$r['data'],true),'Nonduplicate database failure generic');$db->close();
// Exception-only drivers can have no structured error left on their connection.
class ThrowCompanyFixture extends Company {
 public $failure;
 public function __construct($db,$failure){parent::__construct($db);$this->failure=$failure;}
 public function insert($data=null,bool $returnID=true){throw $this->failure;}
 public function db_error():array{return ['code'=>0,'message'=>''];}
}
foreach([
 new mysqli_sql_exception("Duplicate entry 'MYT test-0' for key 'name'",1062),
 new \CodeIgniter\Database\Exceptions\DatabaseException("Duplicate entry 'MYT test-0' for key 'company.name'",0,new mysqli_sql_exception('Duplicate entry',1062))
] as $exception){
 $db=connection();$c=new CompanyControllerFixture($db,['requester'=>67,'name'=>'MYT test'],new ThrowCompanyFixture($db,$exception));$r=$c->create();
 check($r['status']===FAIL && in_array('Company name already exists.',$r['data'],true),'Exception fallback and qualified name index');
 check((int)$db->query('SELECT @@in_transaction active')->getRow()->active===0,'Exception fallback rollback');$db->close();
}
// A stale or string-valued message is never inspected as an array.
$db=connection();$model=new Company($db);check(is_string($model->error()) && is_array($model->db_error()),'Shared error interfaces unchanged');$db->close();
echo "PASS: {$checks} isolated company duplicate-name checks.\n";
