<?php
/** Disposable MariaDB fixture only. No configured application connection or live records. */
chdir(dirname(__DIR__));
define('MASTER_DB', 'items_blindsheet_fixture_master');
define('API_KEY', 'fixture-normal-key');
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
use App\Models\Branch_item_blindsheet;
use CodeIgniter\Database\MySQLi\Connection;
/** Exercise the real controller helpers with an injected disposable connection. */
class BlindsheetWorkflowFixture extends App\Controllers\Items
{
    public function __construct($db, array $schemas)
    {
        $this->db = $db;
        $this->blindsheet_db = $db;
        $this->blindsheet_schemas = $schemas;
    }
    protected function _api_validation() { return true; }
    protected function _create_response($status, $data_response, $skip_response_logging = false)
    {
        if ($status !== SUCCESS) {
            throw new RuntimeException(implode(' ', $data_response));
        }
        return $data_response;
    }
    public function run_blindsheet_submission(array $payload, int $actor_id, int $actor_branch_id): array
    {
        $this->payload = $payload + ['requester' => $actor_id];
        $this->csr_items_authenticated = $actor_branch_id !== 0;
        return $this->blindsheet();
    }
    public function run_blindsheet_status(array $payload, int $actor_id, int $actor_branch_id): array
    {
        $this->payload = $payload + ['requester' => $actor_id];
        $this->csr_items_authenticated = $actor_branch_id !== 0;
        return $this->blindsheet_change_status();
    }
}
$socket = '/private/tmp/items_blindsheet_fixture/mysql.sock';
$schemas = [];
foreach (Branch_item_blindsheet::database_groups() as $branch_id => $group) {
    $schemas[$branch_id] = 'items_blindsheet_fixture_' . $group;
}
function connection(string $schema): Connection
{
    global $socket;
    $db = new Connection(['hostname' => $socket, 'username' => 'root', 'password' => '', 'database' => $schema,
        'DBDriver' => 'MySQLi', 'DBDebug' => true, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci', 'port' => 3306]);
    $db->initialize();
    return $db;
}
$checks = 0;
function check($ok, string $label): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
function rejects(callable $action, string $label, string $message = ''): void
{
    try { $action(); } catch (Throwable $error) {
        check($message === '' || str_contains($error->getMessage(), $message), $label . ': correct failure');
        return;
    }
    throw new RuntimeException($label . ': did not fail');
}
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, 'FAIL: ' . $error . "\n"); exit(1); });
function sql_statements(string $source): array
{
    $delimiter = ';'; $buffer = ''; $statements = [];
    foreach (explode("\n", $source) as $line) {
        if (str_starts_with(trim($line), '--')) { continue; }
        if (preg_match('/^DELIMITER\s+(\S+)/i', trim($line), $match)) { $delimiter = $match[1]; continue; }
        $buffer .= $line . "\n";
        while (($position = strpos($buffer, $delimiter)) !== false) {
            $statement = trim(substr($buffer, 0, $position));
            $buffer = substr($buffer, $position + strlen($delimiter));
            if ($statement !== '') { $statements[] = $statement; }
        }
    }
    if (trim($buffer) !== '') { $statements[] = trim($buffer); }
    return $statements;
}
function definition(string $source, string $kind, string $name): string
{
    if (!preg_match('/DROP ' . $kind . ' IF EXISTS ' . $name . '\$\$\s*CREATE ' . $kind . ' ' . $name . '\b.*?END\$\$/s', $source, $match)) {
        throw new RuntimeException('Missing definition ' . $name);
    }
    return "DELIMITER $$\n" . $match[0] . "\nDELIMITER ;\n";
}
function install(PDO $pdo, string $source): void { foreach (sql_statements($source) as $sql) { $pdo->exec($sql); } }
$config = config('Database');
$config->defaultGroup = 'master';
foreach (Branch_item_blindsheet::database_groups() as $branch_id => $group) {
    $config->$group = ['hostname' => $socket, 'port' => 3306, 'database' => $schemas[$branch_id],
        'username' => 'root', 'password' => '', 'DBDriver' => 'MySQLi', 'DBDebug' => true,
        'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci'];
}
$config->tests = $config->master;
$_ENV['API_KEYS'] = 'fixture-normal-key';
$_SERVER['API_KEYS'] = 'fixture-normal-key';
if (in_array($argv[1] ?? '', ['--worker', '--approve-worker'], true)) {
    $db = connection($schemas[0]);
    $workflow = new BlindsheetWorkflowFixture($db, $schemas);
    echo "READY\n"; flush();
    try {
        if ($argv[1] === '--approve-worker') {
            $workflow->run_blindsheet_status(['branch_id' => 2, 'blindsheet_id' => (int) $argv[2], 'status' => 'approved'], 67, 2);
            echo "APPROVED\n";
            exit(0);
        }
        $result = $workflow->run_blindsheet_submission(['branch_id' => 2, 'branch_item_id' => 1, 'item_id' => 1,
            'physical_count' => '10.00', 'prev_qty' => '10.00', 'date_counted' => '2026-10-13'], 67, 2);
        echo 'CREATED:' . $result['blindsheet_id'] . "\n";
    } catch (Throwable $error) { echo 'REJECTED:' . $error->getMessage() . "\n"; }
    exit(0);
}
foreach (['get_blindsheet', 'blindsheet', 'blindsheet_change_status'] as $method) {
    $reflection = new ReflectionMethod(App\Controllers\Items::class, $method);
    check($reflection->getDeclaringClass()->getName() === App\Controllers\Items::class
        && realpath($reflection->getFileName()) === realpath('app/Controllers/Items.php'), 'Endpoint physically declared in Items.php');
}
check(!file_exists('app/Controllers/Traits/Blindsheet.php') && !file_exists('app/Libraries/' . 'Items_' . 'blindsheet.php'), 'Removed workflow trait and library');
check(!method_exists(App\Controllers\Items::class, 'blind_sheet'), 'No unintended endpoint alias');
$controller_lines=file('app/Controllers/Items.php');
foreach (['get_blindsheet','blindsheet','blindsheet_change_status'] as $method) {
    $reflection=new ReflectionMethod(App\Controllers\Items::class,$method);
    $source=implode('',array_slice($controller_lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
    check($method === 'get_blindsheet' || (str_contains($source, 'release_operation_lock')
        && strpos($source, 'release_operation_lock') < strrpos($source, 'return $this->_create_response')),
        'Write endpoint releases its operation lock before the final response');
    check(str_contains($source,'$response_status =') && str_contains($source,'$response =')
        && str_contains($source,'return $this->_create_response($response_status, $response);'),'Adjustment-style response assignments');
    if ($method === 'get_blindsheet') {
        check(str_contains($source, '$this->branch_item_blindsheet->get_details(')
            && str_contains($source, '$this->branch_item_blindsheet->get_count('), 'Direct model list and count calls');
    } else {
        check(str_contains($source, 'elseif (') && str_contains($source, '$this->db->transBegin()')
            && str_contains($source, '$this->db->transCommit()'), 'Explicit adjustment-style transaction flow');
    }
}

foreach (['_attempt_blindsheet', '_attempt_blindsheet_branch_item', '_attempt_blindsheet_change_status',
    '_attempt_validate_blindsheet', '_attempt_resolve_blindsheet_branch', '_attempt_validate_blindsheet_filters',
    '_attempt_validate_blindsheet_status', '_attempt_prepare_blindsheet', '_attempt_rollback_blindsheet'] as $method) {
    check((string)(new ReflectionMethod(App\Controllers\Items::class,$method))->getReturnType()==='bool','Attempt helper boolean contract');
}
foreach (['_blindsheet_transaction','_attempt_blindsheet_mirror_insert','_attempt_blindsheet_mirror_update','_attempt_blindsheet_weighted_cost'] as $method) {
    check(!method_exists(App\Controllers\Items::class,$method),'Custom write implementation removed');
}

function check_item_history_weighted_cost(PDO $pdo, string $master, string $clinic): void
{
    check(str_starts_with($master, 'items_blindsheet_fixture_') && str_starts_with($clinic, 'items_blindsheet_fixture_'), 'Weighted-cost fixtures use disposable schemas only');
    $pdo->exec("UPDATE `$master`.item SET weighted_cost=12.5 WHERE id=1;
        INSERT INTO `$clinic`.item_conversion VALUES(3,1,'usage','usage',100,0);
        INSERT INTO `$clinic`.branch_item_adjustment VALUES(9001,12,10,0),(9002,8,10,0);
        INSERT INTO `$clinic`.branch_item_blindsheet(id,branch_item_id,branch_id,item_id,physical_count,prev_qty,difference,date_counted,status,added_by,added_on)
            VALUES(9101,1,2,1,12,10,2,'2026-10-15','approved',67,NOW()),
                  (9102,1,2,1,8,10,-2,'2026-10-15','approved',67,NOW()),
                  (9103,1,4,1,12,10,2,'2026-10-15','approved',67,NOW());");
    $cases = [
        ['perform',0,2,2,'2.00',null,[[3,25.0,0.0],[1,0.0,25.0]]],
        ['Machine Run',0,2,2,'2.00',null,[[3,25.0,0.0],[1,0.0,25.0]]],
        ['lab supplies',0,2,2,'2.00',null,[[3,25.0,0.0],[1,0.0,25.0]]],
        ['pharmacy',0,2,2,'2.00',null,[[3,25.0,0.0],[2,0.0,25.0]]],
        ['Transfer In',0,2,1,'2.00',null,[[1,25.0,0.0]]],
        ['Transfer Out',0,2,1,'2.00',null,[[1,0.0,25.0]]],
        ['Adjustment',9001,2,2,'2.00',null,[[1,25.0,0.0],[3,0.0,25.0]]],
        ['Adjustment',9002,2,2,'2.00',null,[[3,25.0,0.0],[1,0.0,25.0]]],
        ['Adjustment',0,2,2,'2.00','returned',[[4,25.0,0.0],[1,0.0,25.0]]],
        ['Blindsheet',9101,2,2,'2.00',null,[[1,25.0,0.0],[3,0.0,25.0]]],
        ['Blindsheet',9102,2,2,'2.00',null,[[3,25.0,0.0],[1,0.0,25.0]]],
        ['Machine Run',0,2,2,'0.05',null,[[3,0.625,0.0],[1,0.0,0.625]]],
        ['Machine Run',0,2,2,'-2.00',null,[[3,25.0,0.0],[1,0.0,25.0]]],
        ['Machine Run',0,2,2,'0.00',null,[]],
        ['Machine Run',0,2,1,'2.00',null,[]],
        ['Machine Run',0,2,3,'50.00',null,[]],
        ['Blindsheet',9103,4,2,'2.00',null,[]],
        ['Blindsheet',9999,2,2,'2.00',null,[]],
        ['Beginning Inventory',0,2,2,'2.00',null,[]],
    ];
    $insert = $pdo->prepare("INSERT INTO `$clinic`.item_history(id,branch_item_id,item_id,branch_id,unit,activity,activity_id,quantity,adjustment_type,added_by,added_on)
        VALUES(?,1,1,?,?,?,?,?,?,67,'2026-10-15 08:00:00')");
    foreach ($cases as $index => [$activity,$activity_id,$branch_id,$unit,$quantity,$adjustment_type,$expected]) {
        $history_id = 80000 + $index;
        $insert->execute([$history_id,$branch_id,$unit,$activity,$activity_id,$quantity,$adjustment_type]);
        $rows = $pdo->query("SELECT account_type_id,debit,credit FROM `$clinic`.general_ledger WHERE source='item_history' AND source_id=$history_id AND is_deleted=0 ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        $actual = array_map(static fn($row) => [(int)$row[0],(float)$row[1],(float)$row[2]], $rows);
        check($actual === $expected, $activity . ': weighted cost drives value while eligibility/accounts/quantity rules remain');
        $journals = $pdo->query("SELECT * FROM `$clinic`.journal_entry WHERE source='item_history' AND source_id=$history_id AND is_deleted=0")->fetchAll(PDO::FETCH_ASSOC);
        if (!$expected) {
            check($journals === [], 'Ineligible/zero movement creates no journal');
            continue;
        }
        check(count($journals) === 1 && $journals[0]['status'] === 'approved' && $journals[0]['date'] === '2026-10-15'
            && (int)$journals[0]['cost_center_id'] === 2, 'Journal grouping/status/date/source branch preserved');
        check((float)$journals[0]['total_debit'] === array_sum(array_column($expected,1))
            && (float)$journals[0]['total_credit'] === array_sum(array_column($expected,2)), 'Journal totals match existing movement sides');
        $items = $pdo->query("SELECT i.account_type_id,i.debit,i.credit FROM `$clinic`.journal_entry_item i JOIN `$clinic`.journal_entry j ON j.id=i.journal_entry_id
            WHERE j.source='item_history' AND j.source_id=$history_id AND j.is_deleted=0 AND i.is_deleted=0 ORDER BY i.id")->fetchAll(PDO::FETCH_NUM);
        check(array_map(static fn($row) => [(int)$row[0],(float)$row[1],(float)$row[2]],$items) === $expected, 'Journal items match weighted-cost GL lines');
        $pdo->exec("CALL `$clinic`.gl_post_item_history($history_id)");
        check((int)$pdo->query("SELECT COUNT(*) FROM `$clinic`.general_ledger WHERE source='item_history' AND source_id=$history_id AND is_deleted=0")->fetchColumn() === count($expected)
            && (int)$pdo->query("SELECT COUNT(*) FROM `$clinic`.journal_entry WHERE source='item_history' AND source_id=$history_id AND is_deleted=0")->fetchColumn() === 1, 'Repeat posting has no duplicate active GL or JE rows');
    }
    foreach (['NULL','0'] as $weighted_cost) {
        $pdo->exec("UPDATE `$master`.item SET weighted_cost=$weighted_cost WHERE id=1; CALL `$clinic`.gl_post_item_history(80001)");
        check((int)$pdo->query("SELECT COUNT(*) FROM `$clinic`.general_ledger WHERE source='item_history' AND source_id=80001 AND is_deleted=0")->fetchColumn() === 0
            && (int)$pdo->query("SELECT COUNT(*) FROM `$clinic`.journal_entry WHERE source='item_history' AND source_id=80001 AND is_deleted=0")->fetchColumn() === 0, 'Null/zero weighted cost never falls back to nonzero purchase cost');
        check((int)$pdo->query("SELECT COUNT(*) FROM `$clinic`.general_ledger WHERE source='item_history' AND source_id=80001 AND is_deleted=1")->fetchColumn() > 0, 'Retired posting audit history is retained');
    }
    $pdo->exec("UPDATE `$master`.item SET weighted_cost=5 WHERE id=1;");
    // Remove only synthetic setup records and reset IDs expected by the controller fixture.
    foreach (['general_ledger','journal_entry_item','journal_entry','item_history','branch_item_blindsheet','branch_item_adjustment'] as $table) {
        $pdo->exec("DELETE FROM `$clinic`.`$table`");
        if ($table !== 'branch_item_adjustment') { $pdo->exec("ALTER TABLE `$clinic`.`$table` AUTO_INCREMENT=1"); }
    }
    $pdo->exec("DELETE FROM `$clinic`.item_conversion WHERE id=3");
    check((float)$pdo->query("SELECT cost FROM `$master`.item WHERE id=1")->fetchColumn() === 9.0, 'Weighted-cost posting never mutates catalog purchase cost');
}

$pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ($schemas as $branch_id => $schema) {
    check(str_starts_with($schema, 'items_blindsheet_fixture_'), 'Fixture-only schema guard');
    $pdo->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
    $pdo->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $schema . '`');
    $user_name_columns = $branch_id === 0
        ? 'first_name VARCHAR(50), last_name VARCHAR(50)' : 'name VARCHAR(100)';
    $active_user_name = $branch_id === 0 ? "'Source0','User'" : "'Source{$branch_id} User'";
    $deleted_user_name = $branch_id === 0 ? "'Deleted','User'" : "'Deleted User'";
    $pdo->exec("CREATE TABLE item (id INT PRIMARY KEY, name VARCHAR(100), cost DECIMAL(13,4), weighted_cost DECIMAL(13,4), account_type_id INT, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE item_conversion (id INT PRIMARY KEY, item_id INT, unit VARCHAR(20), conversion_type VARCHAR(30), qty DECIMAL(13,4), is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE branch_item (id INT PRIMARY KEY, item_id INT, branch_id INT, beggining_qty DECIMAL(13,2), current_qty DECIMAL(13,2), unit INT,
            latest_activity VARCHAR(255), latest_activity_id INT DEFAULT 0, adjustment_type VARCHAR(250), latest_department_id INT,
            added_by INT, added_on DATETIME, updated_by INT, updated_on DATETIME, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE item_cost (id INT PRIMARY KEY, item_id INT, branch_id INT, last_value DECIMAL(13,4), latest_total DECIMAL(13,4), current_amount DECIMAL(13,4),
            added_by INT, added_on DATETIME, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE item_history (id INT AUTO_INCREMENT PRIMARY KEY, branch_item_id INT, item_id INT, branch_id INT, department_id INT, beggining_qty DECIMAL(13,2),
            current_qty DECIMAL(13,2), unit INT, activity VARCHAR(255), activity_id INT, quantity DECIMAL(13,2), adjustment_type VARCHAR(250),
            added_by INT, added_on DATETIME, updated_on DATETIME, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE branch_item_adjustment (id INT PRIMARY KEY, physical_count DECIMAL(13,2), prev_qty DECIMAL(13,2), is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE user (id INT PRIMARY KEY, {$user_name_columns}, token VARCHAR(100), token_expiry DATETIME, role_id INT, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE department (id INT PRIMARY KEY, name VARCHAR(50)) ENGINE=InnoDB;
        CREATE TABLE `transaction` (id INT PRIMARY KEY, transaction_no VARCHAR(30)) ENGINE=InnoDB;
        CREATE TABLE refund (id INT PRIMARY KEY, transaction_id INT, branch_id INT) ENGINE=InnoDB;
        INSERT INTO item VALUES (1,'Synthetic item',9.00,5.00,3,0);
        INSERT INTO item_conversion VALUES (1,1,'box','purchase',1,0),(2,1,'piece','clinic',10,0);
        INSERT INTO branch_item (id,item_id,branch_id,beggining_qty,current_qty,unit,latest_activity,added_by,added_on) VALUES(1,1,2,10,10,2,'Beginning Inventory',67,NOW());
        INSERT INTO item_cost VALUES (1,1,2,5,1,5,67,NOW(),0);
        INSERT INTO department VALUES (1,'Synthetic department');
        INSERT INTO user VALUES (67,{$active_user_name},'local-token-{$branch_id}','2099-01-01 00:00:00',4,0),(68,{$deleted_user_name},'deleted-token','2099-01-01 00:00:00',4,1);");
    install($pdo, file_get_contents('sql/migrations/branch_item_blindsheet.sql'));
    install($pdo, file_get_contents('sql/migrations/branch_item_blindsheet.sql'));
    check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$schema' AND TABLE_NAME = 'branch_item_blindsheet'")->fetchColumn() === 25, 'Repeatable additive schema');
    install($pdo, file_get_contents('sql/blindsheet/inventory_history.sql'));
    install($pdo, file_get_contents('sql/blindsheet/inventory_history.sql'));
    if (in_array($branch_id, [0, 5, 6], true)) {
        // Inventory-only mirrors may have ledger tables without diagnostic posting routines.
        $pdo->exec('CREATE TABLE general_ledger(id INT PRIMARY KEY, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
            CREATE TABLE journal_entry(id INT PRIMARY KEY, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
            CREATE TABLE journal_entry_item(id INT PRIMARY KEY, is_deleted INT DEFAULT 0) ENGINE=InnoDB;');
        if ($branch_id === 0) {
        $pdo->exec("CREATE TABLE account_type(id INT PRIMARY KEY, code VARCHAR(50), parent_id INT, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
            INSERT INTO account_type VALUES(1,'104-030',NULL,0),(2,'104-020B',NULL,0),(3,'500-205',NULL,0),(4,'201-111',NULL,0);");
        } else {
            check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$schema' AND ROUTINE_NAME='gl_post_item_history'")->fetchColumn()===0,'Inventory-only mirror has no poster');
            check((int)$pdo->query("SELECT COUNT(*) FROM general_ledger")->fetchColumn()===0,'Inventory-only ledger remains empty');
        }
        continue;
    }
    $pdo->exec("CREATE TABLE general_ledger(id INT AUTO_INCREMENT PRIMARY KEY, branch_id INT, source VARCHAR(250), source_id INT, account_type_id INT, date DATE,
        debit DECIMAL(13,4), credit DECIMAL(13,4), remark LONGTEXT, added_by INT, added_on DATETIME, updated_on DATETIME, is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(64),remarks LONGTEXT,status VARCHAR(20),total_debit DECIMAL(13,4),
        total_credit DECIMAL(13,4),source VARCHAR(250),source_id INT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,journal_entry_id INT,account_type_id INT,debit DECIMAL(13,4),credit DECIMAL(13,4),remarks LONGTEXT,
        added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
        CREATE FUNCTION gl_branch_id() RETURNS INT DETERMINISTIC RETURN $branch_id;
        CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250),p_id INT,p_branch INT,p_remark LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN p_remark;
        CREATE FUNCTION gl_csr_txn_id(p_source VARCHAR(250),p_id INT) RETURNS INT DETERMINISTIC RETURN NULL;
        CREATE PROCEDURE gl_rebuild_txn_je(IN p_id INT) SELECT p_id;");
    foreach (['', '_uat'] as $suffix) {
        $source = str_replace(['mytemr_master_uat', 'mytemr_master'], $schemas[0], file_get_contents('sql/general_ledger_triggers' . $suffix . '.sql'));
        foreach ([['FUNCTION','gl_account_id'], ['PROCEDURE','gl_clear'], ['PROCEDURE','gl_je_from_gl'], ['TRIGGER','trg_gl_general_ledger_ai'],
            ['FUNCTION','gl_item_history_remarks'], ['PROCEDURE','gl_post_item_history'], ['TRIGGER','trg_gl_item_history_ai'], ['TRIGGER','trg_gl_item_history_au']] as [$kind,$name]) {
            install($pdo, definition($source,$kind,$name));
            install($pdo, definition($source,$kind,$name));
        }
        check(true, 'Diagnostic variant and scoped routines install repeatedly');
        if ($branch_id === 2) { check_item_history_weighted_cost($pdo, $schemas[0], $schema); }
    }
}
$db = connection($schemas[0]);
$workflow = new BlindsheetWorkflowFixture($db, $schemas);
$payload = ['branch_id' => '2', 'branch_item_id' => '1', 'item_id' => '1', 'physical_count' => '10.00', 'prev_qty' => '10.00',
    'difference' => '0.00', 'date_counted' => '2026-10-13', 'department_id' => 1, 'counted_by_id' => '67,68', 'remarks' => 'Synthetic count'];
function fingerprint(PDO $pdo): string
{
    global $schemas;
    $rows = [];
    foreach ($schemas as $branch_id => $schema) {
        $tables = ['branch_item_blindsheet','branch_item','item_cost','item_history'];
        if ($branch_id !== 0) { $tables = array_merge($tables, ['general_ledger', 'journal_entry', 'journal_entry_item']); }
        foreach ($tables as $table) {
            $rows[] = $pdo->query("SELECT * FROM `$schema`.`$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    return hash('sha256', json_encode($rows));
}
$zero = $workflow->run_blindsheet_submission($payload,67,2);
check($zero['blindsheet_id'] === 1 && $zero['item_id'] === 1,'Zero-difference submission');
rejects(fn()=> $workflow->run_blindsheet_submission($payload,67,2),'Pending duplicate','pending');
$before = fingerprint($pdo);
$workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>1,'status'=>'approved'],67,2);
foreach ($schemas as $schema) {
    check((int)$pdo->query("SELECT COUNT(*) FROM `$schema`.branch_item_blindsheet WHERE status='approved' AND approved_by=67 AND approved_by_branch_id=2")->fetchColumn()===1,'Mirrored approval with source audit');
    check((int)$pdo->query("SELECT COUNT(*) FROM `$schema`.item_history")->fetchColumn()===0,'Zero count creates no history');
    check((int)$pdo->query("SELECT COUNT(*) FROM `$schema`.item_cost")->fetchColumn()===1,'Zero count creates no cost trail');
}
rejects(fn()=> $workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>1,'status'=>'approved'],67,2),'Repeated approval','pending');
$up = $workflow->run_blindsheet_submission(array_replace($payload,['physical_count'=>'12.50','difference'=>'2.50']),67,2);
$workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$up['blindsheet_id'],'status'=>'approved'],67,2);
foreach ($schemas as $branch_id=>$schema) {
    check((float)$pdo->query("SELECT current_qty FROM `$schema`.branch_item WHERE id=1")->fetchColumn()===12.5,'Mirrored fractional stock');
    check((float)$pdo->query("SELECT quantity FROM `$schema`.item_history ORDER BY id DESC LIMIT 1")->fetchColumn()===2.5,'Decimal trigger preserves fraction');
    check((float)$pdo->query("SELECT latest_total FROM `$schema`.item_cost ORDER BY id DESC LIMIT 1")->fetchColumn()===1.25,'Purchase-unit weighted cost');
    if ($branch_id!==0) {
        check((int)$pdo->query("SELECT COUNT(*) FROM `$schema`.general_ledger WHERE is_deleted=0")->fetchColumn()===($branch_id===2?2:0),'GL source branch only');
    }
}
$gl = $pdo->query("SELECT account_type_id,debit,credit FROM `{$schemas[2]}`.general_ledger WHERE is_deleted=0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check((int)$gl[0]['account_type_id']===1 && (float)$gl[0]['debit']===12.5 && (int)$gl[1]['account_type_id']===3 && (float)$gl[1]['credit']===12.5,'Count increase inventory debit and expense credit');
$je = $pdo->query("SELECT * FROM `{$schemas[2]}`.journal_entry WHERE is_deleted=0")->fetch(PDO::FETCH_ASSOC);
check($je['status']==='approved' && (float)$je['total_debit']===12.5 && (float)$je['total_credit']===12.5,'Actual journal procedure matches GL');
check(str_contains($je['remarks'],'blindsheet #2'),'Journal header identifies blindsheet');
foreach ([5,6] as $source_id) {
    check((float)$pdo->query("SELECT current_qty FROM `{$schemas[$source_id]}`.branch_item WHERE id=1")->fetchColumn()===12.5,'Inventory-only stock mirrors approval');
    check((int)$pdo->query("SELECT COUNT(*) FROM `{$schemas[$source_id]}`.general_ledger")->fetchColumn()===0,'Inventory-only mirror creates no GL');
}

$pdo->exec("INSERT INTO `{$schemas[2]}`.branch_item_adjustment VALUES(3,999,0,0)");
$down = $workflow->run_blindsheet_submission(array_replace($payload,['physical_count'=>'11.25','prev_qty'=>'12.50','difference'=>'-1.25']),67,0);
$workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$down['blindsheet_id'],'status'=>'approved'],67,0);
$gl = $pdo->query("SELECT account_type_id,debit,credit FROM `{$schemas[2]}`.general_ledger WHERE is_deleted=0 ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
check((int)$gl[0]['account_type_id']===1 && (float)$gl[0]['credit']===6.25 && (int)$gl[1]['account_type_id']===3 && (float)$gl[1]['debit']===6.25,'Decrease uses blindsheet rather than colliding adjustment');
$pdo->exec("CALL `{$schemas[2]}`.gl_post_item_history(2)");
check((int)$pdo->query("SELECT COUNT(*) FROM `{$schemas[2]}`.general_ledger WHERE source_id=2 AND is_deleted=0")->fetchColumn()===2,'Reposting has two active GL lines');
check((int)$pdo->query("SELECT COUNT(*) FROM `{$schemas[2]}`.journal_entry WHERE source_id=2 AND is_deleted=0")->fetchColumn()===1,'Reposting has one active journal');
$stale_payload = array_replace($payload,['physical_count'=>'11.25','prev_qty'=>'11.25']);
$stale = $workflow->run_blindsheet_submission($stale_payload,67,2);
foreach ($schemas as $schema) { $pdo->exec("UPDATE `$schema`.branch_item SET current_qty=12,latest_activity='Other' WHERE id=1"); }
$before = fingerprint($pdo);
rejects(fn()=> $workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$stale['blindsheet_id'],'status'=>'approved'],67,2),'Stale approval','Inventory changed');
check(fingerprint($pdo)===$before,'Stale approval rolls back all records');
$workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$stale['blindsheet_id'],'status'=>'disapproved'],67,2);
rejects(fn()=> $workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$stale['blindsheet_id'],'status'=>'pending'],67,2),'No status reset');
rejects(fn()=> $workflow->run_blindsheet_status(['branch_id'=>1,'blindsheet_id'=>1,'status'=>'approved'],67,1),'Wrong branch ID');
foreach ([['physical_count'=>-1],['physical_count'=>'1.001'],['physical_count'=>'1e3'],['physical_count'=>[]],['physical_count'=>'100000000000.00'],
    ['prev_qty'=>null],['date_counted'=>'2026-02-30'],['difference'=>1],['branch_id'=>0],['item_id'=>true],['remarks'=>[]]] as $invalid) {
    $before = fingerprint($pdo);
    rejects(fn()=> $workflow->run_blindsheet_submission(array_replace($payload,$invalid),67,2),'Invalid submission');
    check(fingerprint($pdo)===$before,'Invalid submission changes nothing');
}
$valid = array_replace($payload,['physical_count'=>'13.00','prev_qty'=>'12.00','difference'=>'1.00']);
function snapshot_fixture(PDO $pdo): array
{
    global $schemas;
    $saved=[];
    foreach ($schemas as $source_id=>$schema) {
        $tables=['branch_item_blindsheet','branch_item','item_cost','item_history'];
        if ($source_id!==0) { array_push($tables,'general_ledger','journal_entry','journal_entry_item'); }
        foreach ($tables as $table) { $saved[$schema][$table]=$pdo->query("SELECT * FROM `$schema`.`$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); }
    }
    return $saved;
}
function restore_fixture(PDO $pdo, array $saved): void
{
    // Synthetic fixtures only: reconcile earlier autocommits to continue independent cases.
    foreach ($saved as $schema=>$tables) {
        check(str_starts_with($schema,'items_blindsheet_fixture_'),'Restore guarded fixture schema');
        foreach ($tables as $table=>$rows) {
            $pdo->exec("DELETE FROM `$schema`.`$table`");
            foreach ($rows as $row) {
                $columns=implode(',',array_map(static fn($column)=>'`'.$column.'`',array_keys($row)));
                $query=$pdo->prepare("INSERT INTO `$schema`.`$table` ($columns) VALUES (".implode(',',array_fill(0,count($row),'?')).")");
                $query->execute(array_values($row));
            }
        }
    }
}
$saved=snapshot_fixture($pdo);
$pdo->exec("CREATE TRIGGER `{$schemas[6]}`.fixture_insert_failure BEFORE INSERT ON `{$schemas[6]}`.branch_item_blindsheet FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic insert failure'");
$before = fingerprint($pdo);
rejects(fn()=> $workflow->run_blindsheet_submission($valid,67,2),'Late mirror insert failure','Review inventory mirrors');
check(fingerprint($pdo)!==$before,'Earlier separate-connection inserts persist');
check((int)$pdo->query("SELECT COUNT(*) FROM `{$schemas[1]}`.branch_item_blindsheet WHERE status='pending'")->fetchColumn()===1,'Earlier branch insert committed');
check((int)$pdo->query("SELECT COUNT(*) FROM `{$schemas[6]}`.branch_item_blindsheet WHERE status='pending'")->fetchColumn()===0,'Failing branch insert absent');
$pdo->exec("DROP TRIGGER `{$schemas[6]}`.fixture_insert_failure");
restore_fixture($pdo,$saved);
$pending = $workflow->run_blindsheet_submission($valid,67,2);
$saved=snapshot_fixture($pdo);
$pdo->exec("CREATE TRIGGER `{$schemas[6]}`.fixture_status_failure BEFORE UPDATE ON `{$schemas[6]}`.branch_item_blindsheet FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic status failure'");
$before = fingerprint($pdo);
$gl_before = (int)$pdo->query("SELECT COUNT(*) FROM `{$schemas[2]}`.general_ledger")->fetchColumn();
rejects(fn()=> $workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$pending['blindsheet_id'],'status'=>'approved'],67,2),'Late approval failure','Review inventory mirrors');
check(fingerprint($pdo)!==$before,'Earlier inventory, cost, and status writes persist');
check((int)$pdo->query("SELECT COUNT(*) FROM `{$schemas[2]}`.general_ledger")->fetchColumn()>$gl_before,'Earlier source-branch GL commits persist');
check($pdo->query("SELECT status FROM `{$schemas[6]}`.branch_item_blindsheet WHERE id=".$pending['blindsheet_id'])->fetchColumn()==='pending','Failing mirror remains pending');
rejects(fn()=> $workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$pending['blindsheet_id'],'status'=>'approved'],67,2),'Partial approval cannot repeat blindly','pending');
$pdo->exec("DROP TRIGGER `{$schemas[6]}`.fixture_status_failure");
restore_fixture($pdo,$saved);
class_alias(CodeIgniter\Database\MySQLi\Result::class, 'CommitFailureResult');
class_alias(CodeIgniter\Database\MySQLi\Builder::class, 'CommitFailureBuilder');
class CommitFailureConnection extends Connection { public int $commit_calls = 0; public function transCommit(): bool { $this->commit_calls++; return false; } }
$commit_db = new CommitFailureConnection($config->master); $commit_db->initialize();
$before = fingerprint($pdo);
rejects(fn()=> (new BlindsheetWorkflowFixture($commit_db,$schemas))->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$pending['blindsheet_id'],'status'=>'approved'],67,2),'Commit failure','Review inventory mirrors');
check($commit_db->commit_calls===1,'Outer commit attempted');
check(fingerprint($pdo)!==$before,'Outer commit failure does not reverse mirror commits');
restore_fixture($pdo,$saved);
$workflow->run_blindsheet_status(['branch_id'=>2,'blindsheet_id'=>$pending['blindsheet_id'],'status'=>'disapproved'],67,2);
// Actual batched list/count queries and names from both master and local user sources.
$model = new App\Models\Branch_item_blindsheet(connection($schemas[2]));
$rows = $model->get_details(null,1,2,1,null,1,2);
check(count($rows)===2 && $model->get_count(null,1,2,1)===5,'List pagination and full count');
$master_row = $model->get_details(3,null,2)[0];
check($master_row['approved_by_name']==='Source0 User','Master audit namespace');
$local_row = $model->get_details(2,null,2)[0];
check($local_row['approved_by_name']==='Source2 User','Local audit namespace despite identical ID');
$audit_snapshot = $pdo->query("SELECT added_by,added_by_branch_id,updated_by,updated_by_branch_id,approved_by,approved_by_branch_id,disapproved_by,disapproved_by_branch_id FROM `{$schemas[2]}`.branch_item_blindsheet WHERE id=2")->fetch(PDO::FETCH_ASSOC);
$pdo->exec("UPDATE `{$schemas[2]}`.branch_item_blindsheet SET added_by=67,added_by_branch_id=0,updated_by=67,updated_by_branch_id=5,approved_by=67,approved_by_branch_id=2,disapproved_by=67,disapproved_by_branch_id=6 WHERE id=2");
$mixed = $model->get_details(2,null,2)[0];
foreach (['added'=>'Source0 User','updated'=>'Source5 User','approved'=>'Source2 User','disapproved'=>'Source6 User'] as $audit => $expected) {
    check($mixed[$audit.'_by_name']===$expected,'Mixed audit sources: '.$audit);
}
$pdo->exec("UPDATE `{$schemas[2]}`.user SET name='   ' WHERE id=67");
check($model->get_details(2,null,2)[0]['approved_by_name']===null,'Blank clinic audit name returns null');
$pdo->exec("UPDATE `{$schemas[2]}`.user SET name='  Source2 User  ' WHERE id=67");
check($model->get_details(2,null,2)[0]['approved_by_name']==='Source2 User','Clinic audit name trimmed');
$pdo->exec("UPDATE `{$schemas[2]}`.user SET name='Source2 User' WHERE id=67");
$pdo->exec("UPDATE `{$schemas[0]}`.user SET first_name=NULL,last_name='  User  ' WHERE id=67");
check($model->get_details(2,null,2)[0]['added_by_name']==='User','Master partial name trimmed');
$pdo->exec("UPDATE `{$schemas[0]}`.user SET first_name=NULL,last_name=NULL WHERE id=67");
check($model->get_details(2,null,2)[0]['added_by_name']===null,'Blank master audit name returns null');
$pdo->exec("UPDATE `{$schemas[0]}`.user SET first_name='Source0',last_name='User' WHERE id=67");
$pdo->exec("UPDATE `{$schemas[2]}`.branch_item_blindsheet SET approved_by=999999 WHERE id=2");
check($model->get_details(2,null,2)[0]['approved_by_name']===null,'Missing audit user returns null');
$pdo->exec("UPDATE `{$schemas[2]}`.branch_item_blindsheet SET approved_by=67,approved_by_branch_id=999 WHERE id=2");
check($model->get_details(2,null,2)[0]['approved_by_name']===null,'Unknown audit source never falls back to master');
$pdo->exec("RENAME TABLE `{$schemas[5]}`.user TO `{$schemas[5]}`.user_audit_unavailable");
check($model->get_details(2,null,2)[0]['updated_by_name']===null,'Absent source user table returns null');
$pdo->exec("RENAME TABLE `{$schemas[5]}`.user_audit_unavailable TO `{$schemas[5]}`.user");
$audit_assignments = implode(',', array_map(static fn($field) => '`'.$field.'`=?', array_keys($audit_snapshot)));
$pdo->prepare("UPDATE `{$schemas[2]}`.branch_item_blindsheet SET {$audit_assignments} WHERE id=2")->execute(array_values($audit_snapshot));
$pdo->exec("UPDATE `{$schemas[2]}`.branch_item_blindsheet SET approved_by_branch_id=NULL WHERE id=1");
check($model->get_details(1,null,2)[0]['approved_by_name']===null,'Unknown legacy namespace gives null');
check($model->get_details(null,null,1)===[] && $model->get_count(null,null,1)===0,'Branch filter applies to list and count');
// CSR validation uses real User model and token data, while responses/logging remain mocked.
class BlindsheetRequestFixture
{
    public function __construct(public array $payload, public string $key) {}
    public function getHeaderLine($name) { return strtoupper($name)==='API-KEY' ? $this->key : ''; }
    public function getVar($name) { return $this->payload[$name] ?? null; }
}
class BlindsheetRouterFixture
{
    public function __construct(public string $method) {}
    public function methodName() { return $this->method; }
    public function controllerName() { return App\Controllers\Items::class; }
}
class BlindsheetControllerFixture extends App\Controllers\Items
{
    public function __construct(array $payload, string $method, string $key='fixture-normal-key')
    {
        $this->db=Config\Database::connect();
        $this->payload=$payload; $this->request=new BlindsheetRequestFixture($payload,$key);
        $this->router=new BlindsheetRouterFixture($method);
        $this->api_log=new class { public function insert($values) { return 1; } };
    }
    public function check_auth() { return $this->_api_validation(); }
    public function permission($method) { return $this->_permission_allowed($method); }
    protected function _create_response($status,$data_response,$skip_response_logging=false) { return [$status,$data_response]; }
}
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$auth=['origin'=>'csr','branch_id'=>2,'requester'=>67,'token'=>'local-token-2'];
$key_controller=new BlindsheetControllerFixture($auth,'get');
$key_method=new ReflectionMethod(App\Controllers\Items::class,'_items_configured_api_key_is_valid');
$saved_env_keys=$_ENV['API_KEYS']??null; $saved_server_keys=$_SERVER['API_KEYS']??null; $saved_process_keys=getenv('API_KEYS');
unset($_ENV['API_KEYS'],$_SERVER['API_KEYS']); putenv('API_KEYS');
check($key_method->invoke($key_controller,'fixture-normal-key')===true,'Legacy constants use the normal API key');
check($key_method->invoke($key_controller,'')===false,'Legacy constants reject an empty key');
check($key_method->invoke($key_controller,'invalid-key')===false,'Legacy constants reject an invalid key');
$_ENV['API_KEYS']=' first-key, second-key '; $_SERVER['API_KEYS']='server-key';
check($key_method->invoke($key_controller,'first-key')===true,'Legacy key normalization trims the first key');
check($key_method->invoke($key_controller,'second-key')===true,'Legacy key normalization accepts the second key');
check($key_method->invoke($key_controller,'server-key')===false,'Legacy key settings preserve environment precedence');
if ($saved_env_keys===null) { unset($_ENV['API_KEYS']); } else { $_ENV['API_KEYS']=$saved_env_keys; }
if ($saved_server_keys===null) { unset($_SERVER['API_KEYS']); } else { $_SERVER['API_KEYS']=$saved_server_keys; }
putenv($saved_process_keys===false ? 'API_KEYS' : 'API_KEYS='.$saved_process_keys);
foreach (['get','get_blindsheet','blindsheet','blindsheet_change_status'] as $method) {
    $controller=new BlindsheetControllerFixture($auth,$method);
    check($controller->check_auth()===true,'CSR allowlisted endpoint');
    check($controller->permission($method)===true,'CSR skips master permission lookup');
}
foreach ([['token'=>'local-token-0'],['branch_id'=>1],['requester'=>68,'token'=>'deleted-token'],['token'=>null],['branch_id'=>0],['requester'=>[]]] as $invalid) {
    check((new BlindsheetControllerFixture(array_replace($auth,$invalid),'get'))->check_auth()[0]===401,'CSR invalid credentials rejected');
}
check((new BlindsheetControllerFixture($auth,'adjust'))->check_auth()[0]===401,'CSR cannot access adjustment');
$controller = new BlindsheetControllerFixture($auth, 'get');
check($controller->check_auth() === true && $controller->permission('adjust')[0] === 401, 'Permission gate independently enforces CSR allowlist');
check($controller->_remap('adjust')[0] === 401, 'Remap method cannot differ from authenticated CSR allowlist');
check((new BlindsheetControllerFixture($auth,'get','invalid-key'))->check_auth()[0]===401,'CSR wrong key does not fall back');
$pdo->exec("UPDATE `{$schemas[2]}`.user SET token_expiry='2000-01-01 00:00:00' WHERE id=67");
check((new BlindsheetControllerFixture($auth,'get'))->check_auth()[0]===401,'Expired CSR token');
$pdo->exec("UPDATE `{$schemas[2]}`.user SET token_expiry='2099-01-01 00:00:00' WHERE id=67");
$normal=['requester'=>67,'token'=>'local-token-0'];
check((new BlindsheetControllerFixture($normal,'get','fixture-normal-key'))->check_auth()===true,'Ordinary master authentication preserved');
check((new BlindsheetControllerFixture($normal,'get','invalid-key'))->check_auth()[0]===401,'Ordinary invalid key is rejected');
$pdo->exec("CREATE TABLE `{$schemas[0]}`.page(id INT PRIMARY KEY,controller VARCHAR(100),method VARCHAR(100));
    CREATE TABLE `{$schemas[0]}`.permission(id INT PRIMARY KEY,role_id INT,page_id INT,is_allowed INT);
    INSERT INTO `{$schemas[0]}`.page VALUES(501,'Items','blindsheet');
    INSERT INTO `{$schemas[0]}`.permission VALUES(1,19,501,0);
    UPDATE `{$schemas[0]}`.user SET role_id=19 WHERE id=67;
    UPDATE `{$schemas[2]}`.user SET role_id=19 WHERE id=67;");
$controller=new BlindsheetControllerFixture($normal,'blindsheet');
check($controller->check_auth()===true && $controller->permission('blindsheet')[0]===401,'Ordinary master permission denial preserved');
$controller=new BlindsheetControllerFixture($auth,'blindsheet');
check($controller->check_auth()===true && $controller->permission('blindsheet')===true,'Local role namespace bypasses master denial');
$pdo->exec("UPDATE `{$schemas[0]}`.permission SET is_allowed=1 WHERE id=1");
$controller=new BlindsheetControllerFixture($normal,'blindsheet');
check($controller->check_auth()===true && $controller->permission('blindsheet')===true,'Ordinary master permission allowance preserved');
$pdo->exec("UPDATE `{$schemas[0]}`.user SET role_id=4 WHERE id=67; UPDATE `{$schemas[2]}`.user SET role_id=4 WHERE id=67");

$detail=(new BlindsheetControllerFixture($auth+['blindsheet_id'=>2],'get_blindsheet'))->_remap('get_blindsheet');
check($detail[0]===200 && $detail[1]['size']===1 && $detail[1]['data'][0]['approved_by_name']==='Source2 User','Remap and endpoint gate both accept local auth');
check((new BlindsheetControllerFixture(array_replace($auth,['token'=>'bad']),'get_blindsheet'))->_remap('get_blindsheet')[0]===401,'Remap rejects bad token before endpoint');
$create_payload = array_replace($payload, ['physical_count' => '12.00', 'prev_qty' => '12.00']);
$created = (new BlindsheetControllerFixture($auth + $create_payload, 'blindsheet'))->_remap('blindsheet');
check($created[0] === 200 && isset($created[1]['blindsheet_id']), 'Actual submission endpoint');
$status_response = (new BlindsheetControllerFixture($auth + ['blindsheet_id' => $created[1]['blindsheet_id'], 'status' => 'disapproved'], 'blindsheet_change_status'))->_remap('blindsheet_change_status');
check($status_response[0] === 200, 'Actual status endpoint');
check((new BlindsheetControllerFixture($auth + ['branch_item_id' => 1], 'blindsheet'))->_remap('blindsheet')[0] === 400, 'Endpoint validation failure');
check($model->get_count(null, 1, 2, 1, 'disapproved') === 3, 'Status-filtered count');
$master_created=(new BlindsheetControllerFixture($normal + $create_payload,'blindsheet'))->_remap('blindsheet');
check($master_created[0]===200,'Master-authenticated submission');
$master_id=$master_created[1]['blindsheet_id'];
check($model->get_details($master_id,null,2)[0]['added_by_name']==='Source0 User','Master endpoint records source zero');
$master_status=(new BlindsheetControllerFixture($normal+['branch_id'=>2,'blindsheet_id'=>$master_id,'status'=>'disapproved'],'blindsheet_change_status'))->_remap('blindsheet_change_status');
check($master_status[0]===200 && $model->get_details($master_id,null,2)[0]['disapproved_by_name']==='Source0 User','Master status audit namespace');
class BlindsheetMirrorFailureFixture extends BlindsheetControllerFixture
{
    protected function mirror_insert(string $modelProperty,array $values): int|false
    {
        $this->error_message='Synthetic mirror returned false.';
        return false;
    }
}
$before=fingerprint($pdo);
$failed=(new BlindsheetMirrorFailureFixture($auth+$create_payload,'blindsheet'))->_remap('blindsheet');
check($failed[0]===400 && $failed[1]===['Synthetic mirror returned false.'],'False mirror result becomes failure response');
check(fingerprint($pdo)===$before,'First mirror failure saves nothing');
$lock_name='items_blindsheet_'.substr(hash('sha256',$schemas[0]),0,32);
function check_operation_unlocked(PDO $pdo, string $lock_name): void
{
    $query=$pdo->prepare('SELECT IS_FREE_LOCK(?)'); $query->execute([$lock_name]);
    check((int)$query->fetchColumn()===1,'Operation lock released on failure');
}
check_operation_unlocked($pdo,$lock_name);
foreach ([['branch_id'=>null],['branch_id'=>'bad'],['status'=>[]],['blindsheet_id'=>[]]] as $invalid) {
    $request_payload=array_replace($normal+['branch_id'=>2,'blindsheet_id'=>1,'status'=>'approved'],$invalid);
    check((new BlindsheetControllerFixture($request_payload,'blindsheet_change_status'))->_remap('blindsheet_change_status')[0]===400,'Status guards return HTTP 400');
    check_operation_unlocked($pdo,$lock_name);
}
$not_found=(new BlindsheetControllerFixture($auth+['blindsheet_id'=>99999,'status'=>'approved'],'blindsheet_change_status'))->_remap('blindsheet_change_status');
check($not_found[0]===404,'Missing master blindsheet returns HTTP 404');
check_operation_unlocked($pdo,$lock_name);
foreach ([['branch_id'=>null],['branch_id'=>99],['item_id'=>[]],['status'=>[]]] as $invalid) {
    check((new BlindsheetControllerFixture(array_replace($normal+['branch_id'=>2],$invalid),'get_blindsheet'))->_remap('get_blindsheet')[0]===400,'List guards return HTTP 400');
}
$empty=(new BlindsheetControllerFixture($auth+['blindsheet_id'=>99999],'get_blindsheet'))->_remap('get_blindsheet');
check($empty[0]===404 && $empty[1]===['No blindsheets found.'],'Existing no-results response');
class BlindsheetLookupFailureFixture extends BlindsheetControllerFixture
{
    protected function resolve_branch_models(int $branch_id)
    {
        if (!parent::resolve_branch_models($branch_id)) { return false; }
        $this->branch_item_blindsheet=new class {
            public function get_details(...$args) { throw new RuntimeException('Synthetic query failure'); }
            public function get_count(...$args) { return 0; }
        };
        return true;
    }
}
$lookup_failure=(new BlindsheetLookupFailureFixture($auth,'get_blindsheet'))->_remap('get_blindsheet');
check($lookup_failure[0]===400 && str_contains($lookup_failure[1][0],'Blindsheet operation failed.'),'Direct list query exception preserves failure response');
class_alias(CodeIgniter\Database\MySQLi\Result::class,'LifecycleFailureResult');
class_alias(CodeIgniter\Database\MySQLi\Builder::class,'LifecycleFailureBuilder');
class LifecycleFailureConnection extends Connection
{
    public string $failure_mode='begin_false';
    public int $rollback_calls=0;
    public function transBegin(bool $testMode=false): bool
    {
        if ($this->failure_mode==='begin_false') { return false; }
        $result=parent::transBegin($testMode);
        if ($this->failure_mode==='begin_throw') { throw new RuntimeException('Synthetic start failure'); }
        return $result;
    }
    public function transCommit(): bool
    {
        if ($this->failure_mode==='commit_throw') { throw new RuntimeException('Synthetic commit exception'); }
        return parent::transCommit();
    }
    public function transRollback(): bool { $this->rollback_calls++; return parent::transRollback(); }
    public function transaction_depth(): int { return $this->transDepth; }
}
foreach (['begin_false','begin_throw'] as $failure_mode) {
    $failure_db=new LifecycleFailureConnection($config->master); $failure_db->initialize();
    $failure_db->failure_mode=$failure_mode;
    $before=fingerprint($pdo);
    rejects(fn()=> (new BlindsheetWorkflowFixture($failure_db,$schemas))->run_blindsheet_submission($create_payload,67,2),'Transaction start failure','Review inventory mirrors');
    check(fingerprint($pdo)===$before,'Failed start saves nothing');
    check($failure_db->transaction_depth()===0,'Failed start leaves no open transaction');
    check_operation_unlocked($pdo,$lock_name);
    $failure_db->close();
}
$failure_db=new LifecycleFailureConnection($config->master); $failure_db->initialize();
$failure_db->failure_mode='commit_throw';
$saved=snapshot_fixture($pdo); $before=fingerprint($pdo);
rejects(fn()=> (new BlindsheetWorkflowFixture($failure_db,$schemas))->run_blindsheet_submission($create_payload,67,2),'Commit exception','Review inventory mirrors');
check($failure_db->rollback_calls===1 && $failure_db->transaction_depth()===0,'Commit exception rolls back owner transaction');
check(fingerprint($pdo)!==$before,'Commit exception preserves adjustment-compatible mirror commits');
check_operation_unlocked($pdo,$lock_name);
restore_fixture($pdo,$saved); $failure_db->close();


$pdo->exec("UPDATE `{$schemas[2]}`.user SET is_deleted=1 WHERE id=67");
check($model->get_details(2,null,2)[0]['approved_by_name'] === null, 'Deleted audit user returns null');
$pdo->exec("UPDATE `{$schemas[2]}`.user SET is_deleted=0 WHERE id=67");
// Concurrent submit workers must serialize and create one pending record.
foreach ($schemas as $schema) {
    $pdo->exec("UPDATE `$schema`.branch_item SET current_qty=10,latest_activity='Other' WHERE id=1");
}
$workers=[];
for ($index=0;$index<2;$index++) {
    $process=proc_open([PHP_BINARY,__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($process),'Concurrent worker started'); fclose($pipes[0]);
    $workers[]=[$process,$pipes];
}
$results=[];
foreach ($workers as [$process,$pipes]) {
    $results[]=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0 && $errors==='','Concurrent worker completed');
}
check(count(array_filter($results,static fn($result)=>str_contains($result,'CREATED:')))===1,'Concurrent submissions create exactly one record');
check(count(array_filter($results,static fn($result)=>str_contains($result,'REJECTED:There is already a pending')))===1,'Concurrent duplicate rejected');
$pending_id = (int) $pdo->query("SELECT id FROM `{$schemas[0]}`.branch_item_blindsheet WHERE status='pending' ORDER BY id DESC LIMIT 1")->fetchColumn();
$workers=[];
for ($index=0;$index<2;$index++) {
    $process=proc_open([PHP_BINARY,__FILE__,'--approve-worker',(string)$pending_id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $workers[]=[$process,$pipes];
}
$results=[];
foreach ($workers as [$process,$pipes]) {
    $results[]=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0 && $errors==='','Concurrent approval worker completed');
}
check(count(array_filter($results,static fn($result)=>str_contains($result,"\nAPPROVED\n")))===1,'Concurrent approval succeeds once');
check(count(array_filter($results,static fn($result)=>str_contains($result,'REJECTED:Only pending')))===1,'Concurrent repeated approval rejected');
// Readiness failure must precede writes.
$pdo->exec("ALTER TABLE `{$schemas[7]}`.branch_item_blindsheet DROP COLUMN added_by_branch_id");
$before=fingerprint($pdo);
rejects(fn()=> $workflow->run_blindsheet_submission($payload,67,2),'Missing audit schema','missing added_by_branch_id');
check(fingerprint($pdo)===$before,'Readiness failure saves nothing');
install($pdo, 'USE `'.$schemas[7]."`;\n".file_get_contents('sql/migrations/branch_item_blindsheet.sql'));
$pdo->exec("ALTER TABLE `{$schemas[7]}`.branch_item_blindsheet ENGINE=MyISAM");
rejects(fn()=> $workflow->run_blindsheet_submission($payload,67,2),'Nontransactional table rejected','InnoDB');
$pdo->exec("ALTER TABLE `{$schemas[7]}`.branch_item_blindsheet ENGINE=InnoDB");
$pdo->exec("DROP TRIGGER `{$schemas[7]}`.trg_gl_item_history_ai");
rejects(fn()=> $workflow->run_blindsheet_submission($payload,67,2),'Missing accounting hook','accounting trigger');
install($pdo, 'USE `'.$schemas[7]."`;\n".definition(str_replace('mytemr_master_uat', $schemas[0], file_get_contents('sql/general_ledger_triggers_uat.sql')),'TRIGGER','trg_gl_item_history_ai'));
$pdo->exec("DROP USER IF EXISTS 'blindsheet_fixture_reader'@'localhost'");
$pdo->exec("CREATE USER 'blindsheet_fixture_reader'@'localhost' IDENTIFIED BY ''");
foreach ($schemas as $schema) { $pdo->exec("GRANT SELECT ON `$schema`.* TO 'blindsheet_fixture_reader'@'localhost'"); }
$reader_settings=$config->master; $reader_settings['username']='blindsheet_fixture_reader';
$reader=new Connection($reader_settings); $reader->initialize();
$privilege_method=new ReflectionMethod(Branch_item_blindsheet::class,'has_effective_privilege');
$privilege_before=fingerprint($pdo);
foreach (['SELECT','INSERT','UPDATE'] as $privilege) {
    check($privilege_method->invoke($model,$db,'branch_item_blindsheet',$privilege)===true,'Effective '.$privilege.' check accepts a permitted connection');
    check($privilege_method->invoke($model,$reader,'branch_item_blindsheet',$privilege)===($privilege==='SELECT'),'Effective '.$privilege.' check respects the SELECT-only user');
}
check(fingerprint($pdo)===$privilege_before,'Effective privilege checks execute no data writes');
$original_pharmacy_settings=$config->pharmacy;
$config->pharmacy=array_replace($config->pharmacy,['username'=>'blindsheet_fixture_reader']);
$instances_property=new ReflectionProperty(Config\Database::class,'instances');
$instances=$instances_property->getValue(); unset($instances['pharmacy']); $instances_property->setValue(null,$instances);
rejects(fn()=> (new BlindsheetWorkflowFixture($reader,$schemas))->run_blindsheet_submission($payload,67,2),'Missing write privilege','privileges');
$config->pharmacy=$original_pharmacy_settings;
$reader->close(); $pdo->exec("DROP USER 'blindsheet_fixture_reader'@'localhost'");
$db->close(); $commit_db->close();
foreach ($schemas as $schema) { $pdo->exec('DROP DATABASE `'.$schema.'`'); }
echo "PASS: {$checks} isolated blindsheet, authentication, SQL, and transaction checks.\n";
