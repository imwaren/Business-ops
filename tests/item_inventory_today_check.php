<?php
/** Read paths are exercised only against a disposable, networking-disabled local fixture. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

use App\Models\Item_inventory_daily;
use CodeIgniter\Database\MySQLi\Connection;

$socket = '/private/tmp/items_blindsheet_fixture/mysql.sock';
$schema = 'inventory_today_fixture';
$pdo = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `$schema`");
$pdo->exec("CREATE DATABASE `$schema`");
$pdo->exec("USE `$schema`");
$db = new Connection(['hostname'=>$socket,'username'=>'root','password'=>'','database'=>$schema,
    'DBDriver'=>'MySQLi','DBDebug'=>true,'charset'=>'utf8mb4']);
$db->initialize();
$model = new Item_inventory_daily($db);
$checks = 0;
function inventory_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}

class InventoryTodayController extends App\Controllers\Items
{
    public function __construct(private Item_inventory_daily $daily) {}
    protected function _api_validation() { return true; }
    protected function resolve_branch_models(int $branch_id)
    {
        $this->item_inventory_daily = $this->daily;
        $this->branch_master = new class {
            public function select(...$args) { return (object) ['name'=>'Fixture branch']; }
        };
        return $branch_id === 2;
    }
    protected function _create_response($status, $data_response, $skip_response_logging = false)
    {
        return ['status'=>$status,'body'=>$data_response];
    }
    protected function _export_item_inventory_report_excel(array $report, ?callable $fetch_batch = null)
    {
        return ['report'=>$report,'first'=>$fetch_batch(1),'second'=>$fetch_batch(2)];
    }
    public function report(array $payload, bool $excel = false)
    {
        $this->payload = $payload;
        return $excel ? $this->item_inventory_report_excel() : $this->item_inventory_report();
    }
}

try {
    $pdo->exec("CREATE TABLE item(id INT PRIMARY KEY,name VARCHAR(100),brand VARCHAR(100),type VARCHAR(50),kind VARCHAR(100),selling_price DECIMAL(13,4),philhealth_medicine_type VARCHAR(50),weighted_cost DECIMAL(13,4),is_deleted INT DEFAULT 0);
        CREATE TABLE item_conversion(id INT PRIMARY KEY,item_id INT,unit VARCHAR(30),conversion_type VARCHAR(30),is_deleted INT DEFAULT 0);
        CREATE TABLE branch_item(id INT PRIMARY KEY,branch_id INT,item_id INT,unit INT,current_qty DECIMAL(13,4),is_deleted INT DEFAULT 0);
        CREATE TABLE item_cost(id INT PRIMARY KEY,branch_id INT,item_id INT,current_amount DECIMAL(13,4),is_deleted INT DEFAULT 0);
        CREATE TABLE item_inventory_daily(id INT AUTO_INCREMENT PRIMARY KEY,snapshot_date DATE,branch_id INT,item_id INT,current_qty DECIMAL(13,4),weighted_cost DECIMAL(13,4),ending_cost DECIMAL(13,4));");
    for ($i=1;$i<=105;$i++) {
        $pdo->exec("INSERT INTO item VALUES($i,'Item ".sprintf('%03d',$i)."','Brand $i','supply','pharmacy',12,NULL,3,0);
            INSERT INTO item_conversion VALUES($i,$i,'box','purchase',0), (".($i+1000).",$i,'piece','clinic',0);
            INSERT INTO branch_item VALUES($i,2,$i,$i,10,0);");
    }
    $pdo->exec("INSERT INTO branch_item VALUES(200,2,1,1,2,0),(201,3,1,1,999,0),(202,2,1,1001,999,0),(203,2,1,1,999,1);
        INSERT INTO item_cost VALUES(1,2,1,4,0),(2,2,1,5,0),(3,2,1,99,1),(4,3,1,999,0),(5,2,2,NULL,0),(6,2,3,0,0);
        UPDATE branch_item SET current_qty=0 WHERE id=4;
        UPDATE item SET weighted_cost=NULL WHERE id=5;
        UPDATE item SET philhealth_medicine_type='yakap' WHERE id=2;
        UPDATE item SET kind='lab',type='equipment' WHERE id=3;
        UPDATE item SET is_deleted=1 WHERE id=105;");
    $today = (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $base = ['tab'=>'breakdown','branch_id'=>2,'date_from'=>$today,'date_to'=>$today,'page'=>1,'data_size'=>2];
    $controller = new InventoryTodayController($model);
    $result = $controller->report($base);
    inventory_check($result['status']===SUCCESS,'Today succeeds without a snapshot');
    $report = $result['body'];
    inventory_check($report['snapshot_date']===$today && $report['size']===104 && count($report['data'])===2,'Date, SQL total and pagination');
    $first = $report['data'][0];
    inventory_check((float)$first['current_qty']===12.0 && (float)$first['weighted_cost']===5.0 && (float)$first['ending_cost']===60.0,'Purchase quantities aggregated; latest active local cost used');
    inventory_check((float)$report['data'][1]['weighted_cost']===3.0,'Null branch cost falls back to item cost');
    $rows = $model->get_breakdown(2,$today,live:true);
    inventory_check((float)$rows[2]['weighted_cost']===0.0 && (float)$rows[2]['ending_cost']===0.0,'Zero branch cost does not fall back');
    inventory_check((float)$rows[3]['current_qty']===0.0 && (float)$rows[3]['ending_cost']===0.0,'Zero stock retained');
    inventory_check((float)$rows[4]['weighted_cost']===0.0,'Missing costs default to zero');
    $page2 = $controller->report(array_replace($base,['page'=>2]));
    inventory_check($page2['body']['data'][0]['item_id']==3 && $page2['body']['size']===104,'Stable second page and full count');
    $filtered = $controller->report(array_replace($base,['type'=>'equipment','kind'=>'lab']));
    inventory_check($filtered['body']['size']===1 && $filtered['body']['data'][0]['philhealth_medicine_type']===null,'Type/kind filters and non-pharmacy field');
    $filtered = $controller->report(array_replace($base,['term'=>'Brand 2','philhealth_medicine_type'=>'yakap','conversion_type'=>'clinic']));
    inventory_check($filtered['body']['size']===1 && $filtered['body']['data'][0]['unit_name']==='piece' && (float)$filtered['body']['data'][0]['current_qty']===10.0,'Medicine, brand and display unit filters');
    inventory_check($model->get_breakdown(2,$today,term:'Item 001',live:true,for_count:true)===1,'Name count filter');
    inventory_check($model->get_breakdown(2,$today,philhealth_medicine_type:' , ',live:true,for_count:true)===0,'Empty medicine selection count');
    $insert = $pdo->prepare('INSERT INTO item_inventory_daily(snapshot_date,branch_id,item_id,current_qty,weighted_cost,ending_cost) VALUES(?,2,1,1,1,1)');
    $insert->execute([$today]); $insert->execute([$yesterday]);
    $pdo->exec('UPDATE branch_item SET current_qty=20 WHERE id=1');
    $after = $controller->report($base);
    inventory_check((float)$after['body']['data'][0]['current_qty']===22.0,'Live data wins after a snapshot exists');
    $historical = $controller->report(array_replace($base,['date_from'=>$yesterday,'date_to'=>$yesterday]));
    inventory_check((float)$historical['body']['data'][0]['current_qty']===1.0,'Historical day remains stored');
    $range = $controller->report(array_replace($base,['date_from'=>$yesterday]));
    inventory_check((float)$range['body']['data'][0]['current_qty']===1.0,'Wider range remains stored');
    $missing = $controller->report(array_replace($base,['date_from'=>'2000-01-01','date_to'=>'2000-01-01']));
    inventory_check($missing['status']===FAIL_NOT_FOUND && $missing['body'][0]==='No inventory snapshot found for the selected dates.','Missing historical snapshot unchanged');
    $export = $controller->report($base,true);
    inventory_check($export['report']['size']===104 && count($export['first'])===100 && count($export['second'])===4,'Excel live fetch callback retrieves later pages');
    inventory_check((int)$pdo->query('SELECT COUNT(*) FROM item_inventory_daily')->fetchColumn()===2,'Report does not save snapshots');
    $invalid = $controller->report(array_replace($base,['conversion_type'=>'invalid']));
    inventory_check($invalid['status']===FAIL,'Conversion validation preserved');
    echo "PASS: $checks isolated today inventory checks.\n";
} finally {
    $db->close();
    $pdo->exec("DROP DATABASE `$schema`");
}
