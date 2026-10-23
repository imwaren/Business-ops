<?php
/** Run after shared_funds_check.py; all connections target disposable local fixtures. */
define('MASTER_DB','shared_funds_fixture');
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function(Throwable $e): void {fwrite(STDERR,$e."\n");exit(1);});
use CodeIgniter\Database\MySQLi\Connection;
use App\Libraries\Fund_cost_center;
$socket=getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/billing_deposit_fixture_20261021/mysql.sock';
if(!str_starts_with($socket,'/private/tmp/')){throw new RuntimeException('Local socket required');}
function connection($name){global $socket;$db=new Connection(['hostname'=>$socket,'username'=>'root','password'=>'','database'=>$name,'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci']);$db->initialize();return $db;}
$db=connection('shared_funds_fixture');$checks=0;
function check($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);}$checks++;}
function batch($text,$selected='shared_funds_fixture') {global $socket;$cmd=['/Applications/XAMPP/xamppfiles/bin/mysql','--no-defaults','--socket='.$socket,'-uroot',$selected];$pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$text);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException($err);}return $out;}
// Replace the intentionally abbreviated old fund-only procurement fixture tables.
foreach(['receipt_note','purchase_order','aoq'] as $table){$db->query("DROP TABLE IF EXISTS $table");}
batch(file_get_contents('sql/shared_procurement/schema.sql'));
// Test data omits unrelated UI metadata; production definitions remain unchanged.
foreach(array_merge(array_keys(Fund_cost_center::PROCUREMENT_OWNERS),array_keys(array_filter(Fund_cost_center::CHILDREN,static fn($entry)=>isset(Fund_cost_center::PROCUREMENT_OWNERS[$entry[0]])))) as $table){
    foreach($db->query('SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND IS_NULLABLE=\'NO\' AND COLUMN_NAME<>\'id\'',[$table])->getResultArray() as $field){
        $db->query("ALTER TABLE `$table` MODIFY `{$field['COLUMN_NAME']}` {$field['COLUMN_TYPE']} NULL");
    }
}
$db->query("ALTER TABLE branch ADD COLUMN IF NOT EXISTS code VARCHAR(10) DEFAULT '0'");
foreach([1=>'mobile',3=>'mactan',4=>'consolacion',5=>'warehouse',6=>'pharmacy',7=>'mantlewood',21=>'Shared Sales'] as $id=>$name){$db->query("INSERT IGNORE INTO branch(id,name,type,is_deleted) VALUES(?,?,?,0)",[$id,$name,$id===21?'shared':'clinic']);}
$db->query("INSERT IGNORE INTO account_type VALUES(81,'104-030','Inventory','active',0),(82,'106-000','VAT','active',0),(83,'201-110','Transitory','active',0)");
$db->query('CREATE TABLE IF NOT EXISTS item(id INT PRIMARY KEY,name VARCHAR(100),account_type_id INT,vat_account_type_id INT,vat_type VARCHAR(50),cost DECIMAL(13,4),weighted_cost DECIMAL(13,4),updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB');
$db->query('CREATE TABLE IF NOT EXISTS item_conversion(id INT PRIMARY KEY,item_id INT,conversion_type VARCHAR(30),qty DECIMAL(13,4),unit VARCHAR(30),price DECIMAL(13,4),min INT,max INT,is_deleted INT DEFAULT 0) ENGINE=InnoDB');
$db->query('ALTER TABLE item ADD COLUMN is_canvassing INT DEFAULT 0');
$db->query('CREATE TABLE IF NOT EXISTS capital_expenditure(id INT PRIMARY KEY,name VARCHAR(100))');
$db->query("INSERT INTO item(id,name,account_type_id,vat_account_type_id,vat_type,cost,weighted_cost,updated_by,updated_on,is_deleted) VALUES(1,'Shared test item',81,82,'non_vat',10,10,NULL,NULL,0)");
$db->query("INSERT INTO item_conversion VALUES(1,1,'purchase',1,'box',10,0,100,0),(2,1,'retail',10,'piece',1,0,1000,0)");
$connections=['master'=>$db,'default'=>$db,'tests'=>$db];$schemas=[];
foreach([1=>'mobile',2=>'oakridge',3=>'mactan',4=>'consolacion',5=>'warehouse',6=>'pharmacy',7=>'mantlewood'] as $id=>$group){
    $schema='shared_procurement_stock_'.$id;$schemas[]=$schema;
    $db->query("DROP DATABASE IF EXISTS $schema");$db->query("CREATE DATABASE $schema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->query("CREATE TABLE $schema.item LIKE item");$db->query("INSERT INTO $schema.item SELECT * FROM item");
    $db->query("CREATE TABLE $schema.branch_item(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT,branch_id INT,unit INT,beggining_qty DECIMAL(13,4),current_qty DECIMAL(13,4),latest_activity VARCHAR(100),latest_activity_id INT,added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB");
    $db->query("CREATE TABLE $schema.item_cost(id INT AUTO_INCREMENT PRIMARY KEY,branch_id INT,item_id INT,last_value DECIMAL(13,4),latest_total DECIMAL(13,4),current_amount DECIMAL(13,4),added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB");
    $connections[$group]=connection($schema);
}
$cache=new ReflectionProperty(CodeIgniter\Database\Config::class,'instances');$cache->setAccessible(true);$cache->setValue(null,$connections);
batch(file_get_contents('sql/shared_procurement/posting.sql'));
foreach([20,21] as $owner){
    $pr=(new App\Models\Purchase_request($db))->with_shared_cost_centers($owner);
    $id=$pr->insert(['branch_id'=>$owner,'cost_center_id'=>$owner,'supplier_id'=>1,'type'=>'goods']);
    check((int)$id>0,'Shared PR inserts with actual branch ownership');
    $pids[$owner]=$id;
    $aoq=(new App\Models\Aoq($db))->with_shared_cost_centers($owner);
    check((bool)$aoq->insert(['purchase_request_id'=>$id,'branch_id'=>$owner,'cost_center_id'=>$owner]),'AOQ links own PR');
    $po=(new App\Models\Purchase_order($db))->with_shared_cost_centers($owner);
    $oid=$po->insert(['purchase_request_id'=>$id,'branch_id'=>$owner,'delivery_branch_id'=>$owner===20?4:5,'supplier_id'=>1,'type'=>'goods']);
    check((bool)$oid,'Shared PO inserts');$oids[$owner]=$oid;
    $rn=(new App\Models\Receipt_note($db))->with_shared_cost_centers($owner);
    $rid=$rn->insert(['purchase_order_id'=>$oid,'branch_id'=>$owner,'supplier_id'=>1,'type'=>'goods','grand_total'=>20]);
    check((bool)$rid,'RN links own PO');$rids[$owner]=$rid;
    $db->query("UPDATE receipt_note SET status='pending',added_on=NOW(),due_date='2026-10-21' WHERE id=?",[$rid]);
    $line=(new App\Models\Receipt_note_item($db))->with_shared_cost_centers($owner);
    check((bool)$line->insert(['receipt_note_id'=>$rid,'purchase_order_id'=>$oid,'item_id'=>1,'qty'=>2,'unit'=>1,'cost'=>10,'amount'=>20,'vat'=>0,'account_type_id'=>81,'added_by'=>1,'added_on'=>date('Y-m-d H:i:s')]),'RN child stores in master');
}
check(!(new App\Models\Aoq($db))->with_shared_cost_centers(20)->insert(['purchase_request_id'=>$pids[21]]),'Cross-center PR reference rejected');
check((new App\Models\Purchase_request($db))->with_shared_cost_centers(21)->select('', ['id'=>$pids[20]],1)===null,'Other center cannot see PR');
check(!(new App\Models\Receipt_note($db))->with_shared_cost_centers(20)->update(['id'=>$rids[21]],['grand_total'=>999]),'Cross-center RN update rejected');
$child=(new App\Models\Receipt_note_item($db))->with_shared_cost_centers(20)->with_shared_parent($rids[20]);
check(!$child->insert(['receipt_note_id'=>$rids[21],'item_id'=>1]),'Child cannot move to another parent');
$service=new App\Models\Shared_procurement_inventory($db);
foreach([20=>4,21=>5] as $owner=>$destination){
    $db->transBegin();check($service->post('receipt_note',(int)$rids[$owner],$owner,1),'Shared inventory posts');
    $db->query("UPDATE receipt_note SET status='approved' WHERE id=?",[$rids[$owner]]);$db->transCommit();
    $stock=$db->query("SELECT current_qty FROM shared_procurement_stock_$destination.branch_item WHERE item_id=1 AND unit=1")->getRowArray();
    check((float)$stock['current_qty']===2.0,'Goods received in physical destination only');
    check((int)$db->query('SELECT inventory_branch_id FROM receipt_note WHERE id=?',[$rids[$owner]])->getRowArray()['inventory_branch_id']===$destination,'RN destination persisted');
    check((float)$db->query("SELECT current_amount FROM shared_procurement_stock_$destination.item_cost ORDER BY id DESC LIMIT 1")->getRowArray()['current_amount']===10.0,'Destination weighted cost trail');
    $db->transBegin();$service->post('receipt_note',(int)$rids[$owner],$owner,1);$db->transCommit();
    check((float)$db->query("SELECT current_qty FROM shared_procurement_stock_$destination.branch_item WHERE unit=1")->getRowArray()['current_qty']===2.0,'Repeated receipt does not duplicate inventory');
}
$ledger=$db->query("SELECT COUNT(*) n,SUM(debit) d,SUM(credit) c,MAX(branch_id) b FROM general_ledger WHERE source='receipt_note_item' AND is_deleted=0")->getRowArray();
check((int)$ledger['n']===4 && $ledger['d']===$ledger['c'] && (int)$ledger['b']===0,'Shared RN accounting is balanced at master branch0');
check((int)$db->query("SELECT COUNT(*) n FROM journal_entry WHERE source='receipt_note_item' AND is_deleted=0 AND cost_center_id=0")->getRowArray()['n']===2,'Shared RN has master JE per existing item source');
$picker=(new App\Models\Accounts_payable_voucher($db))->with_shared_cost_centers(20)->get_all_for_apv(null,'receipt_note','for_apv');
check(count($picker)===1 && (int)$picker[0]['branch_id']===20,'Shared RN appears in APV picker with actual owner');
check(!(new App\Models\Accounts_payable_voucher($db))->with_shared_cost_centers(21)->insert(['type'=>'receipt_note','type_id'=>$rids[20],'amount'=>20]),'APV cannot use another shared center RN');
$apv=(new App\Models\Accounts_payable_voucher($db))->with_shared_cost_centers(20);
$aid=$apv->insert(['type'=>'receipt_note','type_id'=>$rids[20],'supplier_id'=>1,'supplier_type'=>'supplier','amount'=>20,'status'=>'pending']);
check((bool)$aid,'Shared RN APV creates in master');
$db->query('SET @apv_entry_sync=1');
$entry=(new App\Models\Accounts_payable_voucher_entry($db))->with_shared_cost_centers(20);
$entry->insert(['accounts_payable_voucher_id'=>$aid,'account_type_id'=>83,'debit'=>20,'credit'=>0,'added_by'=>1,'added_on'=>date('Y-m-d H:i:s')]);
$entry->insert(['accounts_payable_voucher_id'=>$aid,'account_type_id'=>57,'debit'=>0,'credit'=>20,'added_by'=>1,'added_on'=>date('Y-m-d H:i:s')]);
$db->query('SET @apv_entry_sync=0');check($apv->update(['id'=>$aid],['status'=>'approved']),'Shared RN APV approval posts');
check((int)$db->query("SELECT cost_center_id FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=? AND is_deleted=0",[$aid])->getRowArray()['cost_center_id']===0,'Shared APV JE uses accounting0');
// Delivery changes after receipt must not redirect a return.
$db->query('UPDATE purchase_order SET delivery_branch_id=6 WHERE id=?',[$oids[20]]);
$return=(new App\Models\Purchase_return($db))->with_shared_cost_centers(20);
$tid=$return->insert(['receipt_note_id'=>$rids[20],'purchase_order_id'=>$oids[20],'branch_id'=>20,'status'=>'pending']);
$rl=(new App\Models\Purchase_return_item($db))->with_shared_cost_centers(20);
$source_line=$db->query('SELECT id FROM receipt_note_item WHERE receipt_note_id=?',[$rids[20]])->getRowArray()['id'];
check((bool)$rl->insert(['purchase_return_id'=>$tid,'receipt_note_item_id'=>$source_line,'receipt_note_id'=>$rids[20],'purchase_order_id'=>$oids[20],'item_id'=>1,'qty'=>1,'unit'=>1]),'Shared return child validated');
$db->transBegin();$service->post('purchase_return',(int)$tid,20,1);$return->update(['id'=>$tid],['status'=>'approved']);$db->transCommit();
check((float)$db->query('SELECT current_qty FROM shared_procurement_stock_4.branch_item WHERE unit=1')->getRowArray()['current_qty']===1.0,'Return uses recorded receipt destination');
check((int)$db->query('SELECT COUNT(*) n FROM shared_procurement_stock_6.branch_item')->getRowArray()['n']===0,'Changed PO destination receives no return');
// Atomic rollback includes remote stock, cost trails and persisted inventory identity.
$db->query('DELETE FROM shared_procurement_inventory WHERE document_type=\'receipt_note\' AND document_id=?',[$rids[20]]);
$before=$db->query('SELECT current_qty FROM shared_procurement_stock_4.branch_item WHERE unit=1')->getRowArray()['current_qty'];
$db->transBegin();$service->post('receipt_note',(int)$rids[20],20,1);$db->transRollback();
check($db->query('SELECT current_qty FROM shared_procurement_stock_4.branch_item WHERE unit=1')->getRowArray()['current_qty']===$before,'Cross-schema inventory rolls back with document transaction');
$db->transBegin();$first=App\Models\Shared_procurement_number::next($db,'00',2026);$second=App\Models\Shared_procurement_number::next($db,'00',2026);$db->transCommit();
check($second===$first+1,'Shared PO numbering has one master series across centers');
foreach(['Purchase_requests','Aoqs','Purchase_orders','Receipt_notes','Purchase_returns'] as $name){
    $controller=(new ReflectionClass('App\\Controllers\\'.$name))->newInstanceWithoutConstructor();
    $resolve=new ReflectionMethod($controller,'resolve_branch_models');$resolve->setAccessible(true);
    check($resolve->invoke($controller,20),'Controller routes shared procurement: '.$name);
    check(!$resolve->invoke($controller,9999),'Controller rejects invalid owner: '.$name);
    check($resolve->invoke($controller,5) && $resolve->invoke($controller,6),'Controller preserves warehouse/pharmacy routing: '.$name);
}

// Independent children with identical IDs in different master centers remain isolated.
foreach (['purchase_request_attachment'=>'purchase_request','aoq_detail'=>'aoq','purchase_order_item'=>'purchase_order','receipt_note_attachment'=>'receipt_note'] as $table=>$parent) {
    $class='App\\Models\\'.ucfirst($table);$m=(new $class($db))->with_shared_cost_centers(20);
    $parent_id=$parent==='purchase_request'?$pids[20]:($parent==='purchase_order'?$oids[20]:($parent==='receipt_note'?$rids[20]:1));
    $values=[$parent.'_id'=>$parent_id,'added_by'=>1,'added_on'=>date('Y-m-d H:i:s')];
    $id=$m->insert($values);check((bool)$id,'Procurement child creates in master: '.$table);
    check(!(new $class($db))->with_shared_cost_centers(21)->select('', ['id'=>$id],1),'Other center cannot read child: '.$table);
    check(!(new $class($db))->with_shared_cost_centers(21)->update(['id'=>$id],['is_deleted'=>1]),'Other center cannot delete child: '.$table);
}
foreach (['Purchase_request'=>$pids[20],'Aoq'=>1,'Purchase_order'=>$oids[20],'Receipt_note'=>$rids[20],'Purchase_return'=>$tid] as $name=>$id) {
    $class='App\\Models\\'.$name;$m=(new $class($db))->with_shared_cost_centers(20);
    check(count($m->get_details($id))===1,'Scoped master header detail: '.$name);
    check((new $class($db))->with_shared_cost_centers(21)->get_details($id)===[],'Scoped custom query hides other owner: '.$name);
}
// Master approval reminders retain the actual shared branch, not accounting ID0.
$db->query('UPDATE purchase_request SET status=\'pending\',added_by=1 WHERE id=?',[$pids[20]]);
$db->transBegin();$seq=(new App\Models\Approval_reminder($db))->transition('purchase_request',(int)$pids[20],20,null,1);$db->transCommit();
check((int)$db->query('SELECT branch_id FROM approval_reminder_sequence WHERE id=?',[$seq])->getRowArray()['branch_id']===20,'Reminder retains actual shared owner');
// Number reservations contend on a real row lock across two separate PHP processes.
$db->query("INSERT INTO shared_procurement_number VALUES('00',2099,0)");
$db->transBegin();$db->query("SELECT last_number FROM shared_procurement_number WHERE code='00' AND year=2099 FOR UPDATE");
$workers=[];
for($n=0;$n<2;$n++){
    $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/_support/shared_procurement_number_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);check(trim(fgets($pipes[1]))==='READY','Concurrent shared PO allocator ready');$workers[]=[$proc,$pipes];
}
$db->transCommit();$numbers=[];
foreach($workers as [$proc,$pipes]){$numbers[]=(int)stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'Concurrent numbering completed: '.$error);}
sort($numbers);check($numbers===[1,2],'Concurrent owners cannot receive duplicate shared numbers');


class SharedReceiptControllerFixture extends App\Controllers\Receipt_notes {
    public function configure(array $payload) { $this->_load_essentials(); $this->payload=$payload; }
    protected function _api_validation(){ return true; }
    protected function _create_response($status,$data_response,$skip_response_logging=false){ return ['status'=>$status,'data'=>$data_response]; }
    protected function dispatchApprovalReminder(int $branch,int $sequence): void { }
}
// Shared pharmacy delivery includes additional stock and both conversion quantities.
$rn20=(new App\Models\Receipt_note($db))->with_shared_cost_centers(20);
$pharmacy_rn=$rn20->insert(['branch_id'=>20,'purchase_order_id'=>$oids[20],'supplier_id'=>1,'type'=>'goods','grand_total'=>10]);
$db->query("UPDATE receipt_note SET status='pending',added_on=NOW() WHERE id=?",[$pharmacy_rn]);
$line20=(new App\Models\Receipt_note_item($db))->with_shared_cost_centers(20);
$line20->insert(['receipt_note_id'=>$pharmacy_rn,'purchase_order_id'=>$oids[20],'item_id'=>1,'qty'=>1,'unit'=>1,'cost'=>10,'amount'=>10,'vat'=>0,'account_type_id'=>81,'added_by'=>1,'added_on'=>date('Y-m-d H:i:s')]);
$extra=(new App\Models\Receipt_note_additional_item($db))->with_shared_cost_centers(20);
$extra->insert(['receipt_note_id'=>$pharmacy_rn,'item_id'=>1,'qty'=>0.5,'unit'=>1,'added_by'=>1,'added_on'=>date('Y-m-d H:i:s')]);
$receipt_controller=(new ReflectionClass(SharedReceiptControllerFixture::class))->newInstanceWithoutConstructor();
$receipt_controller->configure(['branch_id'=>20,'receipt_note_id'=>$pharmacy_rn,'status'=>'approved','requester'=>1]);
$response=$receipt_controller->change_status();check($response['status']===SUCCESS,'Real shared RN controller approval: '.json_encode($response));
$receipt_controller->configure(['branch_id'=>20,'receipt_note_id'=>$pharmacy_rn,'status'=>'approved','requester'=>1]);
check($receipt_controller->change_status()['status']!==SUCCESS,'Repeated shared approval rejected without duplicate stock');
check((float)$db->query('SELECT current_qty FROM shared_procurement_stock_6.branch_item WHERE unit=1')->getRowArray()['current_qty']===1.5,'Pharmacy receives primary plus additional purchase units');
check((float)$db->query('SELECT current_qty FROM shared_procurement_stock_6.branch_item WHERE unit=2')->getRowArray()['current_qty']===15.0,'Pharmacy receives converted retail units');
check((float)$db->query('SELECT latest_total FROM shared_procurement_stock_6.item_cost ORDER BY id DESC LIMIT 1')->getRowArray()['latest_total']===1.5,'Additional stock included in destination cost trail');
// Wrong/missing physical destination fails without persisting inventory ownership.
$rn_bad=$rn20->insert(['branch_id'=>20,'purchase_order_id'=>$oids[20],'supplier_id'=>1,'type'=>'goods','grand_total'=>10]);
$line20->insert(['receipt_note_id'=>$rn_bad,'purchase_order_id'=>$oids[20],'item_id'=>1,'qty'=>1,'unit'=>1,'cost'=>10,'amount'=>10,'vat'=>0,'account_type_id'=>81,'added_by'=>1,'added_on'=>date('Y-m-d H:i:s')]);
$db->query('UPDATE purchase_order SET delivery_branch_id=20 WHERE id=?',[$oids[20]]);
$db->query("UPDATE receipt_note SET status='pending',added_by=1,added_on=NOW() WHERE id=?",[$rn_bad]);
$db->transBegin();$rejected=false;try{$service->post('receipt_note',(int)$rn_bad,20,1);}catch(InvalidArgumentException $e){$rejected=true;}$db->transRollback();
check($rejected && $db->query('SELECT inventory_branch_id FROM receipt_note WHERE id=?',[$rn_bad])->getRowArray()['inventory_branch_id']===null,'Shared destination rejected without partial ownership write');
$receipt_controller->configure(['branch_id'=>20,'receipt_note_id'=>$rn_bad,'status'=>'approved','requester'=>1]);
check($receipt_controller->change_status()['status']!==SUCCESS && $db->query('SELECT status FROM receipt_note WHERE id=?',[$rn_bad])->getRowArray()['status']==='pending','Controller inventory failure leaves document pending');
// Service/expense documents do not require inventory or create movement registries.
$db->query("UPDATE receipt_note SET type='services' WHERE id=?",[$rn_bad]);
$db->transBegin();check($service->post('receipt_note',(int)$rn_bad,20,1),'Services retain non-inventory behavior');$db->transCommit();
check((int)$db->query("SELECT COUNT(*) n FROM shared_procurement_inventory WHERE document_type='receipt_note' AND document_id=?",[$rn_bad])->getRowArray()['n']===0,'Services create no stock movement');
// Reposting protects migrated/manual journals and does not duplicate current item journals.
$sid=(int)$source_line;
$db->query("INSERT INTO journal_entry(cost_center_id,date,type,remarks,total_debit,total_credit,status,source,source_id,added_by,added_on,is_deleted,is_migrated) VALUES(0,CURDATE(),'migration','keep',1,1,'approved','receipt_note_item',?,1,NOW(),0,1)",[$sid]);$migrated=(int)$db->insertID();
$db->query("INSERT INTO journal_entry(cost_center_id,date,type,remarks,total_debit,total_credit,status,source,source_id,added_by,added_on,is_deleted,is_migrated) VALUES(0,CURDATE(),'manual','keep',1,1,'approved',NULL,NULL,1,NOW(),0,0)");$manual=(int)$db->insertID();
$db->query('CALL gl_shared_post_receipt_note(?)',[$rids[20]]);$db->query('CALL gl_shared_post_receipt_note(?)',[$rids[20]]);
check((int)$db->query('SELECT COUNT(*) n FROM journal_entry WHERE id IN (?,?) AND is_deleted=0',[$migrated,$manual])->getRowArray()['n']===2,'Migrated/manual journals preserved');
check((int)$db->query("SELECT COUNT(*) n FROM journal_entry WHERE source='receipt_note_item' AND source_id=? AND is_deleted=0 AND is_migrated=0",[$sid])->getRowArray()['n']===1,'Repeated RN posting retains one active automated item journal');
$before_install=$db->query('SELECT COUNT(*) n FROM general_ledger')->getRowArray()['n'];
$install=str_replace("'mytemr_master','mytemr_master_uat'","'shared_funds_fixture','shared_funds_fixture_uat'",file_get_contents('sql/shared_procurement/install.sql'));
batch($install);batch($install);
check($before_install===$db->query('SELECT COUNT(*) n FROM general_ledger')->getRowArray()['n'],'Scoped installer repeats without historical repost');

echo "PASS $checks shared procurement integration checks\n";
foreach($schemas as $schema){$db->query("DROP DATABASE $schema");}
