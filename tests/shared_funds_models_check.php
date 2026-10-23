<?php
/** Run after shared_funds_check.py; dedicated local fixture only. */
define('MASTER_DB', 'shared_funds_fixture');
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e . "\n"); exit(1); });
use App\Libraries\Fund_cost_center;
use CodeIgniter\Database\MySQLi\Connection;
$socket = getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/billing_deposit_fixture_20261021/mysql.sock';
if (!str_starts_with($socket, '/private/tmp/')) { throw new RuntimeException('Isolated local socket required'); }
$db = new Connection(['hostname'=>$socket,'username'=>'root','password'=>'','database'=>'shared_funds_fixture',
    'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci','port'=>3306]);
$db->initialize();
$checks=0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
$router = new Fund_cost_center($db);
check($router->group(20)==='master' && $router->group(23)==='master' && $router->group(99)==='master', 'Dynamic shared routing');
check($router->group(2)==='oakridge' && $router->group(100)===null && $router->group(777)===null, 'Clinic/deleted/unknown resolution');
check(Fund_cost_center::storage_groups($router->groups())['master']===[20,23,99], 'Master queried once for all shared IDs');
foreach (array_diff(Fund_cost_center::ROOTS,['accounts_payable_voucher']) as $table) {
    $class='App\\Models\\'.ucfirst($table);
    $m=(new $class($db))->with_shared_cost_centers(20);
    check((int)$m->select('', ['id'=>1],1)->cost_center_id===20, "$table owns own ID");
    check(!$m->select('', ['id'=>2],1), "$table hides other center ID");
    check(!$m->update(['id'=>2],['remarks'=>'forbidden']), "$table rejects cross-center update");
    check(!$m->insert(['cost_center_id'=>23,'amount'=>1]), "$table rejects cross-center create");
    check($m->update(['id'=>1],['remarks'=>'scope fixture']), "$table own update");
    check(!$m->update(['id'=>1],['cost_center_id'=>23]), "$table rejects owner change");
    $all=$m->get_details();
    check(count($all)===1 && (int)$all[0]['id']===1, "$table custom GET isolated");
}
$cash=(new App\Models\Cash_advance($db))->with_shared_cost_centers(20);
check(count($cash->get_details(null,null,null,'scope',1,1))===1,'Bound term and pagination');
$liq=(new App\Models\Liquidation($db))->with_shared_cost_centers(20);
check(!$liq->insert(['cash_advance_id'=>2,'amount'=>1]),'Cross-center cash advance reference denied');
$repl=(new App\Models\Replenishment($db))->with_shared_cost_centers(20);
check(!$repl->insert(['petty_cash_id'=>2,'amount'=>1]),'Cross-center petty cash reference denied');
foreach (Fund_cost_center::CHILDREN as $table=>[$parent,$key]) {
    if (!$db->tableExists($table)) { continue; }
    $class='App\\Models\\'.ucfirst($table);
    $model=(new $class($db))->with_shared_cost_centers(20);
    // Source parents 1 and 2 belong to distinct centers; create second APV below for APV children.
    if ($parent==='accounts_payable_voucher') { continue; }
    check(!$model->insert([$key=>2]), "$table cross-center child create denied");
    check($model->select('',[$key=>2])===[], "$table cross-center read denied");
}
$voucher=(new App\Models\Accounts_payable_voucher($db))->with_shared_cost_centers(20);
check(!$voucher->insert(['type'=>'change_fund','type_id'=>2,'amount'=>1]),'Cross-center APV source denied');
check(count($voucher->get_details(1))===1,'Own APV detail');
$other=(new App\Models\Accounts_payable_voucher($db))->with_shared_cost_centers(23);
check(!$other->select('', ['id'=>1],1),'Other center cannot see APV');
check($other->get_details(1)===[],'Other center custom APV GET empty');
check(!$other->update(['id'=>1],['is_deleted'=>1]),'Other center cannot cancel APV');
foreach (['Accounts_payable_voucher_entry','Accounts_payable_voucher_amortization_entry','Accounts_payable_voucher_attachment','Accounts_payable_voucher_payment_detail'] as $short) {
    $class='App\\Models\\'.$short;$m=(new $class($db))->with_shared_cost_centers(23);
    check(!$m->insert(['accounts_payable_voucher_id'=>1]),"$short cross-center write denied");
    check($m->select('', ['accounts_payable_voucher_id'=>1])===[],"$short cross-center read denied");
}
$entries=(new App\Models\Accounts_payable_voucher_entry($db))->with_shared_cost_centers(23);
check(!$entries->get_header_for_update(1),'Cross-center row lock lookup denied');
check($entries->get_for_vouchers([1])===[],'Cross-center nested explicit entries denied');
$entries->with_shared_cost_centers(20);
check(count($entries->get_for_vouchers([1])[1])===2,'Own nested explicit entries');
$picker=$voucher->get_all_for_apv(null,null,null,null,null,null,1,100);
check(count($picker)===6 && count(array_unique(array_column($picker,'branch_id')))===1,'Shared picker includes only selected center and excludes linked APV');
$all=(new App\Models\Accounts_payable_voucher($db))->with_shared_cost_centers([20,23,99]);
$picker=$all->get_all_for_apv(null,null,null,null,null,null,1,100);
check(count($picker)===20,'Merged picker has no duplicates');
$pay=(new App\Models\Accounts_payable_voucher_payment_detail($db))->with_shared_cost_centers(20);
check((int)$pay->get_details(1)[0]['cost_center_id']===20,'Payment detail ownership from parent voucher');
check((new App\Models\Accounts_payable_voucher_payment_detail($db))->with_shared_cost_centers(23)->get_details(1)===[],'Other center payment detail hidden');
check(count($all->get_aged_payables())===0,'Aged report accepts shared scope');
// SQL lexical binding: SELECT placeholder precedes the scoped FROM; quoted '?' remains literal.
$method=new ReflectionMethod(App\Models\Cash_advance::class,'scoped_query');$method->setAccessible(true);
$row=$method->invoke($cash,"SELECT ? AS parameter, '?' AS literal FROM cash_advance c WHERE c.id=?",['before',1])->getRowArray();
check($row['parameter']==='before' && $row['literal']==='?','Binding order and SQL literals preserved');
echo "PASS $checks shared PHP model checks\n";
// Resolve all-center model methods through fixture-only named connections.
$cache=new ReflectionProperty(CodeIgniter\Database\Config::class,'instances');$cache->setAccessible(true);
$cache->setValue(null,['master'=>$db,'default'=>$db,'tests'=>$db,'oakridge'=>$db]);
$merged=(new App\Models\Accounts_payable_voucher($db))->get_all_details_all_branches([20=>'master',23=>'master',99=>'master']);
check($merged['size']===1 && (int)$merged['data'][0]['cost_center_id']===20,'All-center master APVs not duplicated per shared branch');
$db->query("INSERT INTO accounts_payable_voucher(cost_center_id,type,type_id,amount,status) VALUES(23,'cash_advance',2,100,'pending')");
$aggregate=new App\Models\Accounts_payable_voucher($db);
$a=$aggregate->get_all_details_all_branches([20=>'master',23=>'master',99=>'master'],null,null,null,null,null,null,null,null,1,1);
$b=$aggregate->get_all_details_all_branches([20=>'master',23=>'master',99=>'master'],null,null,null,null,null,null,null,null,2,1);
check($a['size']===2 && $b['size']===2 && $a['data'][0]['id']!==$b['data'][0]['id'],'Merged master pagination/count includes each row once');
$merged=(new App\Models\Accounts_payable_voucher_payment_detail($db))->get_all_details_all_branches([20=>'master',23=>'master',99=>'master']);
check($merged['size']===1 && $merged['data'][0]['cost_center_name']==='Finance','All-center payment ownership/name and deduplication');
// Exercise real controller resolvers (all operations use these same scoped models).
foreach (['Petty_cash_funds'=>'petty_cash','Cash_advances'=>'cash_advance','Liquidations'=>'liquidation','Reimbursements'=>'reimbursement','Replenishments'=>'replenishment','Change_funds'=>'change_fund','Direct_payments'=>'direct_payment','Accounts_payable_vouchers'=>'accounts_payable_voucher'] as $short=>$property) {
    $class='App\\Controllers\\'.$short;
    $c=(new ReflectionClass($class))->newInstanceWithoutConstructor();
    $resolve=new ReflectionMethod($c,'resolve_branch_models');$resolve->setAccessible(true);
    check($resolve->invoke($c,20),"$short resolves shared master");
    $prop=new ReflectionProperty($c,$property);$prop->setAccessible(true);$m=$prop->getValue($c);
    check(!$m->select('', ['id'=>2],1),"$short resolver scopes ID lookups");
    $connection=new ReflectionProperty($c,'db');$connection->setAccessible(true);
    check($connection->getValue($c)===$db,"$short transaction uses scoped master connection");
    check(!$resolve->invoke($c,100) && !$resolve->invoke($c,404),"$short rejects deleted/unknown branches");
    check($resolve->invoke($c,99),"$short handles future shared branch");
    check($resolve->invoke($c,2),"$short preserves clinic routing");
    $scope=new ReflectionProperty($prop->getValue($c),'shared_cost_centers');$scope->setAccessible(true);
    check($scope->getValue($prop->getValue($c))===null,"$short clinic models stay unscoped");
}
echo "PASS $checks total PHP checks including controller routing/all-center pagination\n";
$db->query('UPDATE accounts_payable_voucher SET balance=100 WHERE id=1');
$aged=(new App\Models\Accounts_payable_voucher($db))->get_aged_payables_all_branches([20=>'master',23=>'master',99=>'master']);
check(count($aged)===1 && $aged[0]['branch_name']==='Finance' && $aged[0]['branch_id']===20,'Aged master query retains actual owner/name');
$journal=new App\Models\Journal_entry($db);
$movements=$journal->get_apv_movements([['id'=>1]],20,true);
check(count($movements[1])>=2 && array_sum(array_map(static fn($row)=>count($row['items']), $movements[1]))>=4,'Shared APV movement retrieval includes master JE items');
check($journal->get_apv_movements([['id'=>1]],23,true)===[],'APV movements cannot cross centers');
// Shared amortization emits GL explicitly because master intentionally has no global JE hook.
$cron=(new ReflectionClass(App\Controllers\Cron::class))->newInstanceWithoutConstructor();
foreach (['branch_db'=>$db,'direct_payment'=>new App\Models\Direct_payment($db),'journal_entry'=>$journal,'journal_entry_item'=>new App\Models\Journal_entry_item($db)] as $field=>$value) {
    $prop=new ReflectionProperty($cron,$field);$prop->setAccessible(true);$prop->setValue($cron,$value);
}
$period=new ReflectionMethod($cron,'_post_direct_payment_due_periods');$period->setAccessible(true);
$payment=['id'=>1,'cost_center_id'=>20,'amount'=>'100.0000','duration_from'=>'2026-10-01','duration_to'=>'2026-10-01',
    'amortization_entries'=>[['expense_account_type_id'=>80,'prepaid_account_type_id'=>13,'amount'=>'100.0000']]];
$result=$period->invoke($cron,$payment,'2026-10-21',0);
check($result['posted']===1 && $result['failed']===0,'Shared amortization posts');
$row=$db->query("SELECT COUNT(*) AS n,SUM(debit) AS d,SUM(credit) AS c,MAX(branch_id) AS b FROM general_ledger WHERE source='journal_entry'")->getRowArray();
check((int)$row['n']===2 && $row['d']==='100.0000' && $row['c']==='100.0000' && (int)$row['b']===0,'Shared amortization produces balanced master GL at0');
check($period->invoke($cron,$payment,'2026-10-21',0)['skipped']===1,'Repeated shared amortization skips posted period');
echo "PASS $checks final PHP checks\n";
// Actual fund-controller write helpers, approval and deletion through the shared resolver.
foreach (['Petty_cash_funds'=>'petty_cash','Cash_advances'=>'cash_advance','Liquidations'=>'liquidation','Reimbursements'=>'reimbursement','Replenishments'=>'replenishment','Change_funds'=>'change_fund','Direct_payments'=>'direct_payment'] as $short=>$table) {
    foreach ([20=>1,23=>2] as $center=>$linked) {
        $c=(new ReflectionClass('App\\Controllers\\'.$short))->newInstanceWithoutConstructor();
        $resolve=new ReflectionMethod($c,'resolve_branch_models');$resolve->setAccessible(true);$resolve->invoke($c,$center);
        $payload=['requester'=>1,'cost_center_id'=>$center,'requested_by_id'=>1,'liquidated_by_id'=>1,
            'date'=>'2026-10-21','due_date'=>'2026-10-21','amount'=>'100.0000','grand_total'=>'100.0000',
            'account_type_id'=>80,'status'=>'approved','cash_advance_id'=>$linked];
        $prop=new ReflectionProperty($c,'payload');$prop->setAccessible(true);$prop->setValue($c,$payload);
        $create=new ReflectionMethod($c,'_attempt_create');$create->setAccessible(true);
        $id=$short==='Replenishments' ? $create->invoke($c,(object)['id'=>$linked]) : $create->invoke($c);
        check((int)$id>0,"$short controller create center $center");
        $modelProp=new ReflectionProperty($c,$table);$modelProp->setAccessible(true);$m=$modelProp->getValue($c);
        $row=$m->select('', ['id'=>$id],1);
        check((int)$row->cost_center_id===$center,"$short stores actual source center");
        $approve=new ReflectionMethod($c,'_attempt_update_status');$approve->setAccessible(true);
        check($approve->invoke($c,$row,4),"$short approval center $center");
        $delete=new ReflectionMethod($c,'_attempt_delete');$delete->setAccessible(true);
        check($delete->invoke($c,$id),"$short deletion center $center");
    }
}
echo "PASS $checks total PHP checks with controller write helpers\n";
class SharedApvFixtureController extends App\Controllers\Accounts_payable_vouchers {
    public function configure(array $payload): void {
        $this->_load_essentials();$this->payload=$payload;
        $this->request=new class {public function getFileMultiple($key){return [];}};
    }
    protected function _api_validation(){return true;}
    protected function _create_response($status,$data_response,$skip_response_logging=false){return ['status'=>$status,'data'=>$data_response];}
}
foreach ([20=>['cash_advance',1],23=>['direct_payment',2]] as $center=>[$type,$sourceId]) {
    $c=(new ReflectionClass(SharedApvFixtureController::class))->newInstanceWithoutConstructor();
    $payload=['requester'=>1,'cost_center_id'=>$center,'type'=>$type,'type_id'=>$sourceId,'amount'=>'100.0000',
        'supplier_id'=>1,'supplier_type'=>'payor','entries'=>[['account_type_id'=>80,'debit'=>'100.0000','credit'=>0],['account_type_id'=>57,'debit'=>0,'credit'=>'100.0000']]];
    $c->configure($payload);$response=$c->create();
    check($response['status']===SUCCESS,'Real shared APV controller create: '.json_encode($response));
    $id=$response['data']['accounts_payable_voucher_id'];
    check((int)$db->query('SELECT cost_center_id FROM accounts_payable_voucher WHERE id=?',[$id])->getRowArray()['cost_center_id']===$center,'APV stored with actual shared owner');
    $c->configure(['requester'=>1,'cost_center_id'=>$center,'accounts_payable_voucher_id'=>$id,'status'=>'approved']);
    check($c->change_status()['status']===SUCCESS,'Real shared APV approval');
    $c->configure(['requester'=>1,'mop'=>'cash','account_type_id'=>13,'grand_total'=>100,'details'=>[
        ['cost_center_id'=>$center,'accounts_payable_voucher_id'=>$id,'amount'=>100,'supplier_id'=>1,'supplier_type'=>'payor']]]);
    $response=$c->create_payment();
    check($response['status']===SUCCESS,'Real shared APV payment: '.json_encode($response));
    check($db->query('SELECT status FROM accounts_payable_voucher WHERE id=?',[$id])->getRowArray()['status']==='closed','Shared APV payment closes voucher');
    $c->configure(['requester'=>1,'cost_center_id'=>$center===20?23:20,'accounts_payable_voucher_id'=>$id]);
    check($c->delete()['status']!==SUCCESS,'Real APV cancellation rejects wrong center');
    $c->configure(['requester'=>1,'cost_center_id'=>$center,'accounts_payable_voucher_id'=>$id]);
    check($c->delete()['status']===SUCCESS,'Real shared APV cancellation');
}
echo "PASS $checks final shared PHP/controller checks\n";

// for_apv is the approved, unvouchered source picker alias for shared centers.
$db->transBegin();
try {
    $source=(new App\Models\Cash_advance($db))->with_shared_cost_centers(99);
    $eligible=$source->insert(['requested_by_id'=>1,'amount'=>100,'status'=>'approved','added_on'=>'2030-01-01']);
    $pending=$source->insert(['requested_by_id'=>1,'amount'=>100,'status'=>'pending']);
    $deleted=$source->insert(['requested_by_id'=>1,'amount'=>100,'status'=>'approved','is_deleted'=>1]);
    $linked=$source->insert(['requested_by_id'=>1,'amount'=>100,'status'=>'approved']);
    $voucher99=(new App\Models\Accounts_payable_voucher($db))->with_shared_cost_centers(99);
    check((bool)$voucher99->insert(['type'=>'cash_advance','type_id'=>$linked,'supplier_id'=>1,'supplier_type'=>'payor','amount'=>100,'status'=>'pending']), 'Picker fixture has an active linked APV');
    $rows=$voucher99->get_all_for_apv(null,'cash_advance','for_apv');
    $ids=array_map('intval',array_column($rows,'type_id'));
    check(in_array((int)$eligible,$ids,true),'for_apv returns approved shared cash advance');
    check(!in_array((int)$pending,$ids,true) && !in_array((int)$deleted,$ids,true) && !in_array((int)$linked,$ids,true),'for_apv excludes pending/deleted/already-vouchered sources');
    check(array_unique(array_map('intval',array_column($rows,'branch_id'))) === [99],'for_apv retains actual shared owner');
    check($rows===$voucher99->get_all_for_apv(null,'cash_advance','approved') && $rows===$voucher99->get_all_for_apv(null,'cash_advance',null),'for_apv/approved/omitted statuses have identical eligibility');
    check($voucher99->get_all_for_apv(null,'cash_advance','pending')===[],'Other shared picker statuses remain excluded');
    check(!in_array((int)$eligible,array_map('intval',array_column($voucher->get_all_for_apv(null,'cash_advance','for_apv'),'type_id')),true),'for_apv cannot cross shared cost centers');

    // Separate physical clinic fixture verifies merging and pagination after both queries.
    $fixture='shared_apv_picker_clinic_fixture';
    $db->query("DROP DATABASE IF EXISTS $fixture");
    $db->query("CREATE DATABASE $fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (Fund_cost_center::ROOTS as $table) { $db->query("CREATE TABLE $fixture.$table LIKE shared_funds_fixture.$table"); }
    $db->query("CREATE TABLE $fixture.receipt_note(id INT PRIMARY KEY,added_on DATETIME,due_date DATE,supplier_id INT,grand_total DECIMAL(13,4),status VARCHAR(30),is_deleted INT)");
    $db->query("CREATE TABLE $fixture.send_out(id INT PRIMARY KEY,branch_id INT,added_on DATETIME,due_date DATE,supplier_id INT,total_expense DECIMAL(13,4),is_deleted INT)");
    $db->query('CREATE TABLE IF NOT EXISTS refund(id INT PRIMARY KEY,branch_id INT,added_on DATETIME,patient_id INT,status VARCHAR(30),amount DECIMAL(13,4),is_deleted INT)');
    $clinic=new Connection(['hostname'=>$socket,'username'=>'root','password'=>'','database'=>$fixture,'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci']);
    $clinic->initialize();
    $clinic->query("INSERT INTO cash_advance(id,cost_center_id,requested_by_id,amount,status,added_on) VALUES(900,2,1,100,'approved','2031-01-01')");
    $cache->setValue(null,['master'=>$db,'default'=>$db,'tests'=>$db,'oakridge'=>$clinic]);
    $map=[2=>'oakridge',20=>'master',23=>'master',99=>'master'];
    $aggregate=new App\Models\Accounts_payable_voucher($db);
    $allRows=$aggregate->get_all_for_apv_all_branches($map,null,'cash_advance','for_apv');
    $identities=array_map(static fn($row)=>(int)$row['branch_id'].':'.(int)$row['type_id'],$allRows['data']);
    check(in_array('2:900',$identities,true) && in_array('99:'.$eligible,$identities,true),'Mixed picker returns clinic and shared sources');
    check(count($identities)===count(array_unique($identities)),'Master sources are not duplicated per shared center');
    $pageIdentities=[];
    for ($page=1;$page<=$allRows['size'];$page++) {
        $result=$aggregate->get_all_for_apv_all_branches($map,null,'cash_advance','for_apv',null,null,null,$page,1);
        check($result['size']===$allRows['size'] && count($result['data'])===1,'Mixed picker count is independent of page');
        $row=$result['data'][0];$pageIdentities[]=(int)$row['branch_id'].':'.(int)$row['type_id'];
    }
    check($pageIdentities===$identities,'Merged pagination preserves full source ordering');
    $filtered=$aggregate->get_all_for_apv_all_branches($map,99,'cash_advance','for_apv');
    check(count($filtered['data'])===count($rows) && array_unique(array_map('intval',array_column($filtered['data'],'branch_id'))) === [99],'Explicit shared filter retains eligible shared rows only');
} finally {
    $db->transRollback();
    $cache->setValue(null,['master'=>$db,'default'=>$db,'tests'=>$db,'oakridge'=>$db]);
    if (isset($clinic)) { $clinic->close(); }
    if (isset($fixture)) { $db->query("DROP DATABASE IF EXISTS $fixture"); }
}
echo "PASS $checks shared PHP checks including for_apv status and mixed pagination\n";
