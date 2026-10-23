<?php
/** Pure validation checks; no database or configured credentials are used. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string) $error . PHP_EOL); exit(1); });
class Fixture_allocation_references extends App\Models\Credit_memo_entries
{
    public function __construct() {}
    public function validate_reference($entry_id, $billing_id, $branch_id): bool
    {
        return in_array($entry_id, [1, 2], true) && (int) $billing_id === 10 && (int) $branch_id === 2;
    }
}
$model = new Fixture_allocation_references();
$checks=0;
function allocation_check($ok): void { global $checks; if (!$ok) { throw new RuntimeException('Allocation assertion failed.'); } $checks++; }
$valid=[['billing_entry_id'=>1,'amount'=>'0.10'],['billing_entry_id'=>2,'amount'=>'0.20']];
$result=App\Libraries\Memo_allocations::validate($model,$valid,'0.30',10,2);
allocation_check($result[0]['amount']==='0.10' && $result[1]['amount']==='0.20');
allocation_check($result[0]['billing_id']===10 && $result[0]['branch_id']===2);
allocation_check(App\Libraries\Memo_allocations::cents('99999999999.99')===9999999999999);
foreach (['0'=>0,'0.01'=>1,'1.2'=>120,'13.99'=>1399] as $value=>$expected) { allocation_check(App\Libraries\Memo_allocations::cents((string)$value)===$expected); }
$badRows=[null,[],false,'bad',[null],[[]],[['billing_entry_id'=>[],'amount'=>'0.30']],[['billing_entry_id'=>1,'amount'=>'0.29']],[['billing_entry_id'=>1,'amount'=>'0.31']],[['billing_entry_id'=>1,'amount'=>'0.30','billing_id'=>20]],[['billing_entry_id'=>1,'amount'=>'0.30','branch_id'=>4]],[['billing_entry_id'=>3,'amount'=>'0.30']],[['billing_entry_id'=>1,'amount'=>'0.30'],['billing_entry_id'=>1,'amount'=>'0.00']]];
foreach ($badRows as $rows) {
    try { App\Libraries\Memo_allocations::validate($model,$rows,'0.30',10,2); throw new RuntimeException('Invalid rows accepted.'); }
    catch (InvalidArgumentException $error) { allocation_check(true); }
}
foreach (['-1','0.001','1e2','100000000000','NaN',null,[],true] as $amount) {
    try { App\Libraries\Memo_allocations::cents($amount); throw new RuntimeException('Invalid amount accepted.'); }
    catch (InvalidArgumentException $error) { allocation_check(true); }
}
allocation_check(App\Libraries\Memo_allocations::total([])==='0.00');
allocation_check(App\Libraries\Memo_allocations::total([['amount'=>'0.10'],['amount'=>'0.20']])==='0.30');
allocation_check(App\Libraries\Memo_allocations::total([['amount'=>'99999999999.99'],['amount'=>'0.01']])==='100000000000.00');
echo "PASS $checks pure memo allocation checks\n";
