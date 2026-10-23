<?php
/** Disposable local fixtures only: no configured application DBs or email delivery. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
});
use CodeIgniter\Database\MySQLi\Connection;
$pdo = new PDO('mysql:unix_socket=/private/tmp/company_cleanup_db/mysql.sock', 'myt_waren', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS sales_ownership_fixture');
$pdo->exec('USE sales_ownership_fixture');
$pdo->exec('DROP TABLE IF EXISTS request,costing,quotation,endorsement,company,customer_account,branch,mobile_van');
$pdo->exec('CREATE TABLE company(id INT PRIMARY KEY,name VARCHAR(100),email VARCHAR(100),is_deleted INT DEFAULT 0)');
$pdo->exec('CREATE TABLE customer_account(id INT PRIMARY KEY,name VARCHAR(100),is_deleted INT DEFAULT 0)');
$pdo->exec('CREATE TABLE branch(id INT PRIMARY KEY,name VARCHAR(100),is_deleted INT DEFAULT 0)');
$pdo->exec('CREATE TABLE mobile_van(id INT PRIMARY KEY,name VARCHAR(100))');
$pdo->exec("CREATE TABLE request(id INT PRIMARY KEY,company_id INT,added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0,status VARCHAR(20),reference_no VARCHAR(30),remarks VARCHAR(100),sae_in_charge_id INT)");
foreach (['costing', 'quotation', 'endorsement'] as $table) {
    $pdo->exec("CREATE TABLE $table(id INT PRIMARY KEY,request_id INT NULL,company_id INT,customer_account_id INT,costing_id INT,quotation_id INT,added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0,status VARCHAR(20),disapproved_reason TEXT NULL,remarks VARCHAR(100),preferred_branch_id INT,mobile_branch_id INT,mobile_van_ids VARCHAR(100),branch_id VARCHAR(100),is_pending_revision INT DEFAULT 0,is_force_evaluate INT DEFAULT 0,with_masterlist INT DEFAULT 0,contract_date_from DATE)");
}
$pdo->exec("INSERT INTO company VALUES(1,'Example','example@example.test',0)");
$pdo->exec("INSERT INTO customer_account VALUES(1,'Example',0)");
$pdo->exec("INSERT INTO request VALUES (1,1,67,'2026-01-01',0,'pending','R1','match',999),(2,1,67,'2026-02-01',0,'approved','R2','other',999),(3,1,68,'2026-01-01',0,'pending','R3','match',67),(5,1,67,'2026-01-01',1,'pending','R5','match',999)");
foreach (['costing', 'quotation', 'endorsement'] as $table) {
    // Own source, other source, absent source, deleted own source, deleted document.
    $pdo->exec("INSERT INTO $table(id,request_id,company_id,customer_account_id,costing_id,added_by,added_on,is_deleted,status,remarks,branch_id) VALUES
    (1,1,1,1,1,68,'2026-01-01',0,'pending','match','2'),
    (2,2,1,1,2,68,'2026-02-01',0,'approved','other','3'),
    (3,3,1,1,3,67,'2026-01-01',0,'pending','match','2'),
    (4,NULL,1,1,4,67,'2026-01-01',0,'pending','match','2'),
    (5,5,1,1,5,68,'2026-01-01',0,'pending','match','2'),
    (6,1,1,1,1,67,'2026-01-01',1,'pending','match','2'),
    (7,999,1,1,1,67,'2026-01-01',0,'pending','match','2')");
}
$db = new Connection(['hostname' => '/private/tmp/company_cleanup_db/mysql.sock', 'username' => 'myt_waren', 'password' => '', 'database' => 'sales_ownership_fixture', 'DBDriver' => 'MySQLi', 'DBDebug' => true, 'charset' => 'utf8mb4']);
$db->initialize();
$checks = 0;
function check($ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function query_arguments($model, string $method, array $values): array
{
    $args = [];
    foreach ((new ReflectionMethod($model, $method))->getParameters() as $parameter) {
        $args[] = $values[$parameter->getName()] ?? $parameter->getDefaultValue();
    }
    return $args;
}
function rows($model, array $values): array { return $model->get_details(...query_arguments($model, 'get_details', $values)); }
function total($model, array $values): int { return $model->get_count(...query_arguments($model, 'get_count', $values)); }
function ids(array $rows): array { $ids = array_map('intval', array_column($rows, 'id')); sort($ids); return $ids; }
foreach (['Request', 'Costing', 'Quotation', 'Endorsement'] as $name) {
    $class = 'App\\Models\\' . $name;
    $model = new $class($db);
    $owned = ['request_added_by' => 67];
    $expected = $name === 'Request' ? [1, 2] : [1, 2, 5];
    check(ids(rows($model, $owned)) === $expected, $name . ' ownership follows request creator');
    check(total($model, $owned) === count($expected), $name . ' count matches ownership');
    check(ids(rows($model, ['request_added_by' => 68])) === [3], $name . ' other executive sees only own request');
    check(ids(rows($model, ['request_added_by' => 999])) === [], $name . ' assigned executive is not creator');
    $id_key = strtolower($name) . '_id';
    check(rows($model, $owned + [$id_key => 3]) === [] && total($model, $owned + [$id_key => 3]) === 0, $name . ' explicit foreign ID blocked');
    check(ids(rows($model, $owned + [$id_key => 1])) === [1], $name . ' own explicit ID allowed');
    check(ids(rows($model, $owned + ['status' => 'approved'])) === [2] && total($model, $owned + ['status' => 'approved']) === 1, $name . ' status and count');
    $matching = $name === 'Request' ? [1] : [1, 5];
    check(ids(rows($model, $owned + ['term' => 'match', 'status' => 'pending'])) === $matching, $name . ' term cannot bypass ownership');
    check(total($model, $owned + ['term' => 'match', 'status' => 'pending']) === count($matching), $name . ' filtered count');
    $pages = [];
    for ($page = 1; $page <= count($expected); $page++) {
        $batch = rows($model, $owned + ['page' => $page, 'data_size' => 1]);
        check(count($batch) === 1, $name . ' bounded page');
        $pages = array_merge($pages, $batch);
    }
    check(ids($pages) === $expected, $name . ' pagination covers only owned records');
    check(rows($model, $owned + ['page' => count($expected) + 1, 'data_size' => 1]) === [], $name . ' later empty page');
    check(total($model, []) === ($name === 'Request' ? 3 : 6), $name . ' omitted filter preserves existing callers');
    if ($name === 'Endorsement') {
        check(ids(rows($model, $owned + ['branch_id' => 2])) === [1, 5] && total($model, $owned + ['branch_id' => 2]) === 2, 'Endorsement branch filter preserved');
    }
}
// Execute real controller GETs through their no-results path to verify authentication scope.
class OwnershipQuerySpy
{
    public $calls = [];
    public function get_details(...$args) { $this->calls['list'] = $args; return []; }
    public function get_count(...$args) { $this->calls['count'] = $args; return 0; }
}
trait OwnershipControllerFixture
{
    public $spy;
    public function __construct($role_id)
    {
        $this->requester_user = (object) ['id' => 67, 'role_id' => $role_id];
        $this->payload = ['requester' => 999, 'request_added_by' => 999];
        $this->spy = new OwnershipQuerySpy();
        foreach (['request_model', 'costing', 'quotation', 'endorsement'] as $property) {
            if (property_exists($this, $property)) { $this->$property = $this->spy; }
        }
    }
    protected function _api_validation() { return true; }
    protected function _create_response($status, $data_response, $skip_response_logging = false) { return [$status, $data_response]; }
}
class RequestsOwnershipFixture extends App\Controllers\Requests { use OwnershipControllerFixture; }
class CostingsOwnershipFixture extends App\Controllers\Costings { use OwnershipControllerFixture; }
class QuotationsOwnershipFixture extends App\Controllers\Quotations { use OwnershipControllerFixture; }
class EndorsementsOwnershipFixture extends App\Controllers\Endorsements { use OwnershipControllerFixture; }
foreach (['Requests', 'Costings', 'Quotations', 'Endorsements'] as $name) {
    foreach ([19, '19', 20, 4] as $role_id) {
        $class = $name . 'OwnershipFixture';
        $controller = new $class($role_id);
        $controller->get();
        $args = $controller->spy->calls['list'];
        $model_class = 'App\\Models\\' . rtrim($name, 's');
        $parameters = array_map(static fn($parameter) => $parameter->getName(), (new ReflectionMethod($model_class, 'get_details'))->getParameters());
        $ownership_index = array_search('request_added_by', $parameters, true);
        check($args[$ownership_index] === ((int) $role_id === 19 ? 67 : null), $name . ' authenticated role/ID determines filter');
        $source = file_get_contents('app/Controllers/' . $name . '.php');
        check((bool) preg_match('/->get_count\([^;]*\$request_added_by(?:,\s*\$transaction_type)?\)/', $source), $name . ' count receives same scope');
    }
}
$db->close();
$pdo->exec('DROP DATABASE sales_ownership_fixture');
echo "PASS: {$checks} isolated sales request ownership checks.\n";
