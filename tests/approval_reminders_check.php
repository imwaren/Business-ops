<?php

/** Isolated local integration checks. Never connects to configured application databases. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
});

use App\Libraries\Approval_reminder_schedule as Schedule;
use App\Libraries\Approval_reminders;
use App\Models\Approval_reminder;
use CodeIgniter\Database\MySQLi\Connection;

$socket = getenv('BILLING_FIXTURE_SOCKET') ?: '/private/tmp/company_cleanup_db/mysql.sock';
$database = 'approval_reminder_fixture';
$pdo = new PDO('mysql:unix_socket=' . $socket, (getenv('BILLING_FIXTURE_USER') ?: 'myt_waren'), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS {$database}");
$pdo->exec("USE {$database}");
$pdo->exec('DROP TABLE IF EXISTS approval_reminder_delivery, approval_reminder_sequence, purchase_request, purchase_order, aoq, receipt_note, user, role, branch');
foreach (explode(';', file_get_contents('sql/approval_reminders.sql')) as $sql) {
    if (trim($sql)) {
        $pdo->exec($sql);
    }
}
foreach (['purchase_request', 'purchase_order', 'aoq', 'receipt_note'] as $table) {
    $pdo->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, status VARCHAR(20) DEFAULT 'pending', is_deleted INT DEFAULT 0,
        added_by INT DEFAULT 1, approved_by_department_director INT NULL, received_status VARCHAR(20) DEFAULT 'pending',
        is_closed_request INT DEFAULT 0, closed_request_status VARCHAR(20) NULL) ENGINE=InnoDB");
}
$pdo->exec("CREATE TABLE user (id INT PRIMARY KEY, role_id INT, email VARCHAR(200), immediate_head INT NULL,
    is_deleted INT DEFAULT 0, branch_id VARCHAR(100) DEFAULT '2', first_name VARCHAR(40) DEFAULT '',
    last_name VARCHAR(40) DEFAULT '', middle_name VARCHAR(40) DEFAULT '')");
$pdo->exec('CREATE TABLE role (id INT PRIMARY KEY, name VARCHAR(50), is_deleted INT DEFAULT 0)');
$pdo->exec('CREATE TABLE branch (id INT PRIMARY KEY, name VARCHAR(50), is_deleted INT DEFAULT 0)');
$pdo->exec("INSERT INTO branch VALUES (2,'Oakridge',0)");
$pdo->exec("INSERT INTO user(id,role_id,email,immediate_head,is_deleted) VALUES
 (1,24,'creator@example.test',2,0),(2,25,'HEAD@example.test',NULL,0),
 (3,25,'head@example.test',NULL,0),(4,25,'invalid',NULL,0),(5,25,'deleted@example.test',NULL,1),
 (6,23,'finance@example.test',NULL,0),(7,35,'ap@example.test',NULL,0),(8,27,'accountant@example.test',NULL,0)");
$config = ['hostname' => $socket, 'username' => (getenv('BILLING_FIXTURE_USER') ?: 'myt_waren'), 'password' => '', 'database' => $database,
    'DBDriver' => 'MySQLi', 'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci', 'port' => 3306];
$db = new Connection($config);
$db->initialize();
$model = new Approval_reminder($db);
$checks = 0;
function check($condition, string $message): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function at(string $time): DateTimeImmutable {
    return new DateTimeImmutable($time, new DateTimeZone('Asia/Manila'));
}
$cases = [
 ['2026-10-12 07:59:00','2026-10-12 08:00:00','2026-10-12 12:00:00','2026-10-12 17:00:00'],
 ['2026-10-12 08:00:00','2026-10-12 08:00:00','2026-10-12 12:00:00','2026-10-12 17:00:00'],
 ['2026-10-12 12:45:00','2026-10-12 12:45:00','2026-10-12 16:00:00','2026-10-12 17:00:00'],
 ['2026-10-12 13:00:00','2026-10-12 13:00:00','2026-10-13 08:00:00','2026-10-13 17:00:00'],
 ['2026-10-12 16:59:00','2026-10-12 16:59:00','2026-10-13 08:00:00','2026-10-13 17:00:00'],
 ['2026-10-12 17:00:00','2026-10-13 08:00:00','2026-10-13 12:00:00','2026-10-13 17:00:00'],
 ['2026-10-09 14:00:00','2026-10-09 14:00:00','2026-10-12 08:00:00','2026-10-12 17:00:00'],
 ['2026-10-10 10:00:00','2026-10-12 08:00:00','2026-10-12 12:00:00','2026-10-12 17:00:00'],
];
foreach ($cases as [$trigger,$first,$second,$eod]) {
    $times = Schedule::times(at($trigger));
    check(array_map(fn ($d) => $d->format('Y-m-d H:i:s'), array_values($times)) === [$first,$second,$eod], 'Schedule: ' . $trigger);
}
check(!Schedule::deliveryWindow(at('2026-10-10 10:00')), 'Weekend delivery prohibited');
check(!Schedule::deliveryWindow(at('2026-10-12 17:01')), 'After-hours delivery prohibited');
foreach (['purchase_request' => 'department_manager','aoq' => 'finance_manager','receipt_note' => 'department_manager'] as $module => $stage) {
    check(Schedule::stage($module, ['status'=>'pending','is_deleted'=>0]) === $stage, 'Stage ' . $module);
}
check(Schedule::stage('purchase_order', ['status'=>'pending']) === 'department_director', 'PO director');
check(Schedule::stage('purchase_order', ['status'=>'pending','approved_by_department_director'=>2]) === 'president', 'PO president');
check(Schedule::stage('purchase_order', ['status'=>'approved','received_status'=>'open','is_closed_request'=>1,'closed_request_status'=>'requested']) === 'finance_director', 'PO close');
check(Schedule::stage('purchase_request', ['status'=>'pending','is_deleted'=>1]) === null, 'Deleted source');

$db->transBegin();
$db->query('INSERT INTO purchase_request(id) VALUES (1)');
$rolled = $model->transition('purchase_request', 1, 2, null, 1);
$db->transRollback();
check((int) $pdo->query('SELECT COUNT(*) FROM purchase_request')->fetchColumn() === 0, 'Document rollback');
check((int) $pdo->query('SELECT COUNT(*) FROM approval_reminder_sequence')->fetchColumn() === 0, 'Queue rollback');
$db->query('INSERT INTO purchase_request(id) VALUES (1)');
$db->transBegin();
$seq = $model->transition('purchase_request', 1, 2, null, 1);
$db->transCommit();
check($seq > 0, 'Persist sequence');
check($model->transition('purchase_request', 1, 2, 'department_manager', 1) === null, 'Same stage no duplicate');
$db->query('INSERT INTO purchase_request(id) VALUES (2)');
check($model->transition('purchase_request', 2, 2, 'department_manager', 1) === null, 'Do not backfill old pending');
$now = at('2026-10-12 09:00');
$db->table('approval_reminder_sequence')->where('id',$seq)->update(['next_eod_on'=>'2026-10-12 17:00:00']);
$db->table('approval_reminder_delivery')->where(['sequence_id'=>$seq,'delivery_key'=>'first'])->update(['due_on'=>'2026-10-12 09:00:00']);
$db->table('approval_reminder_delivery')->where(['sequence_id'=>$seq,'delivery_key'=>'second'])->update(['due_on'=>'2026-10-12 13:00:00']);
$_ENV['APPROVAL_REMINDER_MODE'] = 'send';
$sent = [];
$worker = new Approval_reminders(fn () => $now, fn ($group) => $db,
    function ($subject,$body,$to,$cc) use (&$sent) { $sent[] = [$subject,$to,$cc]; return true; });
[$to,$cc] = $worker->recipients(['stage'=>'department_manager','creator_id'=>1],true);
check(count($to) === 1 && strtolower($to[0]) === 'head@example.test', 'Validate and deduplicate To recipients');
check(count($cc) === 3, 'EOD CC roles');
check(count($worker->recipients(['stage'=>'finance_manager','creator_id'=>1],true)[1]) === 2, 'Remove To from CC');
$other = new Connection($config); $other->initialize(); $otherModel = new Approval_reminder($other);
check($model->acquire($seq) && !$otherModel->acquire($seq), 'Exclusive sequence lock');
$model->release($seq);
check($worker->run(2,$seq,true)['sent'] === 1, 'Immediate send');
check($worker->run(2,$seq,true)['sent'] === 0 && count($sent) === 1, 'Repeated run cannot duplicate send');
$now = at('2026-10-12 17:00');
$worker = new Approval_reminders(fn () => $now, fn ($g) => $db, function () use (&$sent) { $sent[] = 'later'; return true; });
check($worker->run(2,$seq)['sent'] === 2, 'Second and first EOD');
$now = at('2026-10-13 17:00');
$worker = new Approval_reminders(fn () => $now, fn ($g) => $db, fn () => true);
check($worker->run(2,$seq)['sent'] === 1, 'Daily EOD recurrence');
$now = at('2026-10-16 17:00');
$worker = new Approval_reminders(fn () => $now, fn ($g) => $db, fn () => true);
check($worker->run(2,$seq)['sent'] === 1, 'Downtime EOD consolidated');
$db->query("UPDATE purchase_request SET status='approved' WHERE id=1");
$now = at('2026-10-19 17:00');
check((new Approval_reminders(fn () => $now, fn ($g) => $db, fn () => true))->run(2,$seq)['sent'] === 0, 'Resolved stage not sent');
check($pdo->query("SELECT status FROM approval_reminder_sequence WHERE id={$seq}")->fetchColumn() === 'cancelled', 'Resolved sequence cancelled');
$db->query("UPDATE purchase_request SET status='pending' WHERE id=1");
$db->transBegin(); $new = $model->transition('purchase_request',1,2,null,1); $db->transCommit();
check($new !== $seq, 'Re-entry new sequence');
$db->table('approval_reminder_delivery')->where(['sequence_id'=>$new,'delivery_key'=>'first'])->update(['due_on'=>'2026-10-12 09:00:00']);
$now = at('2026-10-12 09:00');
check((new Approval_reminders(fn () => $now, fn ($g) => $db, fn () => false))->run(2,$new,true)['uncertain'] === 1, 'Ambiguous SMTP failure held');
check((new Approval_reminders(fn () => $now, fn ($g) => $db, fn () => true))->run(2,$new,true)['sent'] === 0, 'Uncertain SMTP not resent');
$db->table('approval_reminder_delivery')->where(['sequence_id'=>$new,'delivery_key'=>'second'])
    ->update(['status'=>'processing','claimed_on'=>'2026-10-12 08:00:00']);
$model->prepare($new,$now);
check($pdo->query("SELECT status FROM approval_reminder_delivery WHERE sequence_id={$new} AND delivery_key='second'")->fetchColumn() === 'uncertain', 'Interrupted send held');
$_ENV['APPROVAL_REMINDER_MODE'] = 'log';
$db->query('INSERT INTO receipt_note(id) VALUES(3)');
$db->transBegin();$preview=$model->transition('receipt_note',3,2,null,1);$db->transCommit();
$db->table('approval_reminder_delivery')->where(['sequence_id'=>$preview,'delivery_key'=>'first'])->update(['due_on'=>'2026-10-12 09:00:00']);
check((new Approval_reminders(fn () => $now, fn ($g) => $db, function () { throw new RuntimeException('UAT must not send'); }))->run(2,$preview,true)['logged']===1,'UAT log mode');
$_ENV['APPROVAL_REMINDER_MODE'] = 'send';
$db->query('INSERT INTO aoq(id) VALUES(4)');
$db->transBegin();$retrySeq=$model->transition('aoq',4,2,null,1);$db->transCommit();
$db->table('approval_reminder_delivery')->where(['sequence_id'=>$retrySeq,'delivery_key'=>'first'])->update(['due_on'=>'2026-10-12 09:00:00']);
$rejected = fn () => ['status'=>'failed','error'=>'Explicit mock SMTP rejection'];
foreach (['09:00','09:05','09:10'] as $time) {
    $now=at('2026-10-12 '.$time);
    check((new Approval_reminders(fn () => $now, fn ($g) => $db, $rejected))->run(2,$retrySeq,true)['failed']===1,'Explicit failure retried');
}
check($pdo->query("SELECT status FROM approval_reminder_delivery WHERE sequence_id={$retrySeq} AND delivery_key='first'")->fetchColumn()==='failed','Retries bounded at three');
$db->query('INSERT INTO purchase_order(id) VALUES(5)');
$db->transBegin();$director=$model->transition('purchase_order',5,2,null,1);$db->transCommit();
$db->transBegin();$db->query('UPDATE purchase_order SET approved_by_department_director=2 WHERE id=5');
$president=$model->transition('purchase_order',5,2,'department_director',2);$db->transCommit();
check($president!==$director,'PO next level gets new sequence');
check($pdo->query("SELECT status FROM approval_reminder_sequence WHERE id={$director}")->fetchColumn()==='cancelled','PO previous level cancelled');
$db->transBegin();$db->query("UPDATE purchase_order SET status='approved',received_status='open',is_closed_request=1,closed_request_status='requested' WHERE id=5");
$close=$model->transition('purchase_order',5,2,'president',2);$db->transCommit();
check($pdo->query("SELECT stage FROM approval_reminder_sequence WHERE id={$close}")->fetchColumn()==='finance_director','PO close approval sequence');
check(!$model->claim((int)$pdo->query("SELECT id FROM approval_reminder_delivery WHERE sequence_id={$seq} AND delivery_key='first'")->fetchColumn(),$now),'Sent delivery cannot be claimed again');
class ApprovalHookFixture {
    use \App\Controllers\Traits\Approval_notifications;
    protected $db;
    protected $payload=['requester'=>1];
    public $dispatches=0;
    public function __construct($db){$this->db=$db;$this->approvalBranchId=2;}
    public function create($id):bool {
        $this->beginApprovalTransaction('receipt_note',null);
        $this->db->query('INSERT INTO receipt_note(id) VALUES (?)',[$id]);
        return $this->commitApprovalTransaction('receipt_note',$id);
    }
    protected function dispatchApprovalReminder(int $branch,int $sequence):void {
        check((int)$this->db->query('SELECT @@in_transaction AS active')->getRow()->active===0,'Dispatch follows commit');
        $this->dispatches++;
        throw new RuntimeException('Mock mail unavailable after commit');
    }
}
$hook=new ApprovalHookFixture($db);
check($hook->create(100),'Mail failure does not undo committed document');
check($hook->dispatches===1 && (int)$pdo->query('SELECT COUNT(*) FROM receipt_note WHERE id=100')->fetchColumn()===1,'Committed source retained');
$db->query('RENAME TABLE approval_reminder_delivery TO approval_reminder_delivery_offline');
check(!$hook->create(101),'Queue failure fails create');
check((int)$pdo->query('SELECT COUNT(*) FROM receipt_note WHERE id=101')->fetchColumn()===0,'Queue failure rolls back source');
$db->query('RENAME TABLE approval_reminder_delivery_offline TO approval_reminder_delivery');
$_ENV['APPROVAL_REMINDER_MODE']='hold';
check(array_sum((new Approval_reminders(fn () => $now, fn ($g) => $db, function(){throw new RuntimeException('Held delivery must not send');}))->run(2))===0,'Hold preserves pending deliveries');
echo "PASS: {$checks} isolated scheduling, recipient and database checks.\n";
$db->close();$other->close();
