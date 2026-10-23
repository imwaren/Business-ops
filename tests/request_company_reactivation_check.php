<?php
/** Isolated transaction checks: local socket fixtures only; all notifications mocked. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
});

use App\Controllers\Requests;
use App\Models\Request as RequestModel;
use App\Models\Request_detail;
use App\Models\Company;
use CodeIgniter\Database\MySQLi\Connection;

class_alias(\CodeIgniter\Database\MySQLi\Builder::class, 'FailedCommitBuilder');
class_alias(\CodeIgniter\Database\MySQLi\Result::class, 'FailedCommitResult');
class FailedCommitConnection extends Connection
{
    public function transCommit(): bool { return false; }
}
class RequestCreationFixture extends Requests
{
    public $notifications = [];
    public $mail_failure = null;
    public $background_batches = [];
    public function __construct(Connection $db, array $payload, $role = 19)
    {
        $this->db = $db;
        $this->payload = $payload;
        $this->request_model = new RequestModel($db);
        $this->request_detail = new Request_detail($db);
        $this->company = new Company($db);
        $this->user = new class($role) {
            private $role;
            public function __construct($role) { $this->role = $role; }
            public function select(...$args) { return $this->role === null ? null : (object) ['id' => 67, 'role_id' => $this->role]; }
        };
    }
    protected function _api_validation() { return true; }
    protected function _create_response($status, $data_response, $skip_response_logging = false)
    { return ['status' => $status, 'data' => $data_response]; }
    protected function send_email_notif($subject, $request_id, $status = null)
    {
        $this->notifications[] = $subject;
        if ($subject === $this->mail_failure) { return false; }
        return $this->_stage_email_notification($subject, '<p>Fixture</p>', ['to@example.test'], [], [
            'module' => 'requests', 'document_id' => $request_id, 'notification_type' => $subject,
        ]);
    }
    protected function _dispatch_notification_batch(array $notifications): void
    {
        if ($notifications) { $this->background_batches[] = $notifications; }
    }

}
$pdo = new PDO('mysql:unix_socket=/private/tmp/company_cleanup_db/mysql.sock', 'myt_waren', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS request_company_fixture');
$pdo->exec('USE request_company_fixture');
$pdo->exec('DROP TABLE IF EXISTS request_detail,request,company');
$pdo->exec("CREATE TABLE company(id INT PRIMARY KEY,name VARCHAR(100),account_status VARCHAR(20),accreditation_status VARCHAR(20),updated_by INT NULL,updated_on DATETIME NULL,is_deleted INT DEFAULT 0) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE request(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT NULL,added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0,status VARCHAR(20) DEFAULT 'pending',approved_by INT NULL,approved_on DATETIME NULL,approved_reason TEXT NULL) ENGINE=InnoDB");
$pdo->exec('CREATE TABLE request_detail(id INT AUTO_INCREMENT PRIMARY KEY,request_id INT,lab_test_id INT,branch_id INT,added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB');
$p = proc_open(['/Applications/XAMPP/xamppfiles/bin/mysql', '--no-defaults', '--socket=/private/tmp/company_cleanup_db/mysql.sock', '-u', 'myt_waren', 'request_company_fixture'], [['file', 'sql/company_annual_status/triggers.sql', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
$err = stream_get_contents($pipes[2]);
if (proc_close($p) !== 0) { throw new RuntimeException('Trigger fixture failed: ' . $err); }
function fixture_connection(bool $fail_commit = false): Connection
{
    $class = $fail_commit ? FailedCommitConnection::class : Connection::class;
    $db = new $class(['hostname' => '/private/tmp/company_cleanup_db/mysql.sock', 'username' => 'myt_waren', 'password' => '', 'database' => 'request_company_fixture', 'DBDriver' => 'MySQLi', 'DBDebug' => true, 'charset' => 'utf8mb4']);
    $db->initialize();
    return $db;
}
function reset_fixture(): void
{
    global $pdo;
    $pdo->exec('DELETE FROM request_detail');
    $pdo->exec('DELETE FROM request');
    $pdo->exec('DELETE FROM company');
    $pdo->exec("INSERT INTO company(id,name,account_status,accreditation_status,is_deleted) VALUES (1,'Inactive','inactive','inactive',0),(2,'Other inactive','inactive','inactive',0),(3,'Active','active','inactive',0),(4,'Deleted','inactive','inactive',1)");
}
function company_row(int $id): array
{
    global $pdo;
    return $pdo->query('SELECT * FROM company WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
}
$checks = 0;
function check($condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function rolled_back(string $label): void
{
    global $pdo;
    check((int) $pdo->query('SELECT COUNT(*) FROM request')->fetchColumn() === 0, $label . ' request rolled back');
    check((int) $pdo->query('SELECT COUNT(*) FROM request_detail')->fetchColumn() === 0, $label . ' children rolled back');
    check(company_row(1)['account_status'] === 'inactive' && company_row(1)['accreditation_status'] === 'inactive' && company_row(1)['updated_on'] === null, $label . ' activation/audit rolled back');
}
$payload = ['requester' => 67, 'company_id' => 1, 'details' => [['lab_test_id' => 10, 'branch_id' => 2]]];
reset_fixture();
$c = new RequestCreationFixture(fixture_connection(), $payload);
$r = $c->create();
check($r['status'] === SUCCESS, 'Successful request');
$row = company_row(1);
check($row['account_status'] === 'active' && $row['accreditation_status'] === 'active', 'Activates both statuses with installed synchronization triggers');
check((int) $row['updated_by'] === 67 && $row['updated_on'] !== null, 'Requester and timestamp recorded');
check(count($c->notifications) === 2, 'Existing notifications preserved');
check((int) $pdo->query('SELECT COUNT(*) FROM request_detail')->fetchColumn() === 1, 'Children committed');
foreach ([3, 4, 999, null] as $id) {
    reset_fixture();
    $before = $pdo->query('SELECT * FROM company ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $r = (new RequestCreationFixture(fixture_connection(), ['requester' => 67, 'company_id' => $id]))->create();
    check($r['status'] === SUCCESS, 'Active/deleted/missing link accepted');
    check($before === $pdo->query('SELECT * FROM company ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Active/deleted/missing link leaves companies unchanged');
}
foreach ([4, 19, 20, 21] as $role) {
    reset_fixture();
    $r = (new RequestCreationFixture(fixture_connection(), $payload, $role))->create();
    check($r['status'] === SUCCESS, 'Allowed creator ' . $role);
    $saved = $pdo->query('SELECT * FROM request')->fetch(PDO::FETCH_ASSOC);
    check($saved['status'] === (in_array($role, [20, 21]) ? 'approved' : 'pending'), 'Automatic approval retained ' . $role);
}
// A later child failure also rolls back children already inserted.
$pdo->exec("CREATE TRIGGER fixture_child_failure BEFORE INSERT ON request_detail FOR EACH ROW BEGIN IF NEW.lab_test_id=99 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture child failure'; END IF; END");
reset_fixture();
$bad = $payload;
$bad['details'][] = ['lab_test_id' => 99];
check((new RequestCreationFixture(fixture_connection(), $bad))->create()['status'] === FAIL, 'Child failure response');
rolled_back('Child failure');
$pdo->exec('DROP TRIGGER fixture_child_failure');
// A failed company write marks the transaction failed and prevents dispatch.
$pdo->exec("CREATE TRIGGER fixture_company_failure BEFORE UPDATE ON company FOR EACH ROW BEGIN IF NEW.id=1 AND NEW.account_status='active' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture reactivation failure'; END IF; END");
foreach ([false] as $throws) {
    reset_fixture();
    $db = fixture_connection();
    $db->transException($throws);
    check((new RequestCreationFixture($db, $payload))->create()['status'] === FAIL, 'Reactivation failure response');
    rolled_back('Reactivation failure');
}
$pdo->exec('DROP TRIGGER fixture_company_failure');
foreach (['request_for_approval', 'created_request'] as $failure) {
    reset_fixture();
    $c = new RequestCreationFixture(fixture_connection(), $payload);
    $c->mail_failure = $failure;
    check($c->create()['status'] === FAIL, 'Notification failure response');
    rolled_back('Notification failure');
}
reset_fixture();
check((new RequestCreationFixture(fixture_connection(true), $payload))->create()['status'] === FAIL, 'Commit failure response');
rolled_back('Commit failure');
// SMTP failure after response completion cannot undo the saved request or activation.
foreach (['false', 'exception'] as $failure) {
    reset_fixture();
    $c = new RequestCreationFixture(fixture_connection(), $payload);
    check($c->create()['status'] === SUCCESS, 'Background SMTP not part of creation result');
    check(count($c->background_batches) === 1 && count($c->background_batches[0]) === 2, 'Committed messages buffered for shutdown');
    $response_finished = false;
    _deliver_notification_email_batch($c->background_batches[0],
        static function () use (&$response_finished) { $response_finished = true; },
        static function (...$args) use ($failure, &$response_finished) {
            check($response_finished, 'Response completed before mocked SMTP');
            if ($failure === 'exception') { throw new RuntimeException('Fixture SMTP error'); }
            return false;
        }
    );
    check(company_row(1)['account_status'] === 'active' && company_row(1)['accreditation_status'] === 'active', 'Background failure preserves company activation');
    check((int) $pdo->query('SELECT COUNT(*) FROM request')->fetchColumn() === 1, 'Background failure preserves request');
}
$pdo->exec('DROP DATABASE request_company_fixture');
echo "PASS: {$checks} isolated request/company reactivation checks; notifications mocked.\n";
