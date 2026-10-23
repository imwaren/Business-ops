<?php
/** All transaction, shutdown, and SMTP collaborators are isolated mocks. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n"); exit(1); });
$checks = 0;
function check($ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
class NotificationTransactionFixture
{
    public $depth = 0;
    public $status = true;
    public $fail_commit = false;
    public $throw_commit = false;
    public function transBegin() { $this->depth++; return true; }
    public function transStatus() { return $this->status; }
    public function transCommit() {
        if ($this->throw_commit) { throw new RuntimeException('Fixture commit'); }
        if ($this->fail_commit) { return false; }
        $this->depth--; return true;
    }
    public function transRollback() { $this->depth = max(0, $this->depth - 1); return true; }
}
trait NotificationFixture
{
    public $batches = [];
    public function __construct() { $this->db = new NotificationTransactionFixture(); }
    public function begin() { return $this->_begin_notification_transaction(); }
    public function commit() { return $this->_commit_notification_transaction(); }
    public function rollback() { return $this->_rollback_notification_transaction(); }
    public function transaction() { return $this->db; }
    public function stage($type = 'fixture', array $primary = ['USER@example.test', 'user@example.test'], array $fallback = ['fallback@example.test']) {
        return $this->_stage_email_notification('Subject', '<p>Body</p>', $primary, $fallback, ['module' => 'fixture', 'document_id' => 1, 'notification_type' => $type]);
    }
    public function notify(...$args) { return $this->send_email_notif(...$args); }
    public function models($user, $model): void {
        $this->payload = ['requester' => 67];
        foreach (['user', 'request_model', 'costing', 'quotation', 'endorsement', 'company'] as $property) {
            if (property_exists($this, $property)) { $this->$property = $property === 'user' ? $user : $model; }
        }
    }
    protected function _dispatch_notification_batch(array $notifications): void {
        if ($notifications) { $this->batches[] = $notifications; }
    }
}
class RequestsNotificationFixture extends App\Controllers\Requests { use NotificationFixture; }
class CostingsNotificationFixture extends App\Controllers\Costings { use NotificationFixture; }
class QuotationsNotificationFixture extends App\Controllers\Quotations { use NotificationFixture; }
class EndorsementsNotificationFixture extends App\Controllers\Endorsements { use NotificationFixture; }
foreach (['Requests', 'Costings', 'Quotations', 'Endorsements'] as $name) {
    $class = $name . 'NotificationFixture';
    $fixture = new $class();
    check(!$fixture->stage(), $name . ' cannot queue before owning transaction');
    check($fixture->begin(), $name . ' begin');
    check($fixture->stage('first') && $fixture->stage('nested-helper'), $name . ' stage messages');
    check($fixture->batches === [], $name . ' no dispatch before commit');
    check($fixture->commit() && count($fixture->batches) === 1 && count($fixture->batches[0]) === 2, $name . ' release once after commit');
    $batch = $fixture->batches[0][0];
    check($batch['subject'] === 'Subject' && $batch['message'] === '<p>Body</p>', $name . ' content preserved');
    check(count($batch['to']) === 1 && count($batch['cc']) === 0, $name . ' recipients deduplicated');
    check($fixture->stage('post-commit') && count($fixture->batches) === 2, $name . ' postcommit operational notification');
    $fixture = new $class(); $fixture->begin(); $fixture->stage(); $fixture->rollback();
    check($fixture->batches === [] && !$fixture->stage(), $name . ' rollback discards staged messages');
    foreach (['fail_commit', 'throw_commit', 'status'] as $failure) {
        $fixture = new $class(); $fixture->begin(); $fixture->stage();
        $fixture->transaction()->$failure = $failure !== 'status';
        check(!$fixture->commit() && $fixture->batches === [] && $fixture->transaction()->depth === 0, $name . ' failed commit discarded ' . $failure);
    }
    $fixture = new $class(); $fixture->begin(); $fixture->stage(); $fixture->begin(); $fixture->stage();
    check($fixture->commit() && $fixture->batches === [], $name . ' nested commit not released');
    check($fixture->commit() && count($fixture->batches[0]) === 2, $name . ' outer commit releases nested helpers');
    $fixture = new $class(); $fixture->begin(); $fixture->stage(); $fixture->begin(); $fixture->rollback();
    check($fixture->transaction()->depth === 0 && $fixture->batches === [], $name . ' nested rollback discards all');
    $fixture = new $class(); $fixture->begin();
    check(!$fixture->stage('missing', [], []), $name . ' missing recipients remains synchronous failure');
    check($fixture->stage('fallback', [], ['fallback@example.test']), $name . ' fallback recipient');
    $fixture->commit();
    check($fixture->batches[0][0]['to'] === ['fallback@example.test'], $name . ' fallback preserved');
    $source = file_get_contents('app/Controllers/' . $name . '.php');
    check(!preg_match('/\$this->db->trans(?:Begin|Rollback|Commit)\(/', $source), $name . ' all transaction boundaries integrated');
    check(!str_contains($source, 'return dispatch_email_notification('), $name . ' SMTP replaced by staging');
}
// Run real notification builders with deterministic fake user/document models.
$user = new class {
    public function get_details(...$args) { return [['id' => 68, 'email' => 'approver@example.test']]; }
    public function select($columns, $conditions, $limit = null) {
        if ($limit === 1) { return (object) ['id' => 67, 'role_id' => 19, 'email' => 'creator@example.test', 'immediate_head' => 68]; }
        return [(object) ['id' => 68, 'email' => 'approver@example.test']];
    }
};
$model = new class {
    public function select(...$args) { return (object) ['id' => 1, 'added_by' => 67, 'request_id' => 1, 'company_id' => 1, 'package_name' => 'Package', 'name' => 'Example', 'status' => 'pending']; }
};
$scenarios = [
    'Requests' => [
        ['created_request', 1], ['request_for_approval', 1], ['request_for_costing', 1],
        ['request_status_update', 1, 'approved'], ['request_status_update', 1, 'returned'], ['request_status_update', 1, 'disapproved'],
    ],
    'Costings' => [
        ['request_moved_to_costing', 1], ['costing_for_approval_by_sales_manager', 1],
        ['costing_for_approval_by_sales_director', 1], ['costing_for_approval_by_finance_director', 1],
        ['quotation_for_approval_by_sales', 1, 1, 1], ['contracts_for_approval_by_finance_director', 1, 1, 1],
        ['costing_status_update', 1, null, null, 'approved'], ['costing_status_update', 1, null, null, 'disapproved'],
    ],
    'Quotations' => [
        ['request_moved_to_quotation', 1], ['contracts_for_approval_by_sales_manager', 1],
        ['contracts_for_approval_by_sales_director', 1], ['contracts_for_approval_by_finance_director', 1],
        ['contracts_for_moa', 1], ['quotation_status_update', 1, 'approved'], ['quotation_status_update', 1, 'disapproved'],
    ],
    'Endorsements' => [
        ['request_moved_to_endorsement', 1], ['endorsement_for_approval', 1], ['endorsement_revision_for_approval', 1],
        ['endorsement_approved', 1], ['endorsement_revision_approved', 1], ['upload_patients', 1], ['endorsement_status_update', 1],
    ],
];
foreach ($scenarios as $name => $notifications) {
    foreach ($notifications as $args) {
        $class = $name . 'NotificationFixture'; $fixture = new $class(); $fixture->models($user, $model); $fixture->begin();
        check($fixture->notify(...$args), $name . ' actual builder prepares ' . $args[0]);
        check($fixture->batches === [], $name . ' builder does not send during transaction');
        $fixture->commit();
        check(count($fixture->batches[0]) === 1 && $fixture->batches[0][0]['context']['module'] === strtolower($name), $name . ' builder context and dispatch');
    }
}
// The reusable dispatcher registers once even when a workflow prepares emails after commit.
$callback = null; $registrations = 0; $events = [];
$register = static function ($handler) use (&$callback, &$registrations) { $callback = $handler; $registrations++; };
$finish = static function () use (&$events) { $events[] = 'response-finished'; };
$sender = static function ($subject, $body, $to, $cc) use (&$events) {
    check($events[0] === 'response-finished', 'SMTP runs after response finishing');
    $events[] = $subject;
    if ($subject === 'throws') { throw new RuntimeException('Fixture SMTP interruption'); }
    return $subject !== 'fails';
};
$make_message = static fn($subject) => ['subject' => $subject, 'message' => 'Body', 'to' => ['to@example.test'], 'cc' => [], 'context' => ['module' => 'fixture', 'document_id' => 1, 'notification_type' => 'test']];
_dispatch_notification_emails_background([$make_message('fails'), $make_message('throws')], $register, $finish, $sender);
_dispatch_notification_emails_background([$make_message('succeeds')], $register, $finish, $sender);
check($registrations === 1 && $events === [], 'Single shutdown callback; SMTP not called while preparing response');
$callback();
check($events === ['response-finished', 'fails', 'throws', 'succeeds'], 'Failure and exception do not stop later delivery');
$quotation = file_get_contents('app/Controllers/Quotations.php');
check(str_contains($quotation, "elseif (!\$this->send_email_notif('request_moved_to_quotation'"), 'Quotation success condition corrected');
echo "PASS: {$checks} isolated background notification checks; no real emails.\n";
