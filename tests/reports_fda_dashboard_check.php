<?php
/** Real controller/model methods with mocked connections backed only by in-memory SQLite. */
namespace CodeIgniter\Config {
    class BaseConfig { public function __construct() {} }
}
namespace App\Core {
    class MYT_Model
    {
        protected $db;
        public function __construct($db = null) { $this->db = $db; }
        protected function _resolve_cross_db_sql(string $sql): string
        {
            return str_replace(
                ['{MOBILE_DB}', '{OAKRIDGE_DB}', '{MAC_DB}', '{CONSO_DB}', '{MANTLEWOOD_DB}'],
                [MOBILE_DB, OAKRIDGE_DB, MAC_DB, CONSO_DB, MANTLEWOOD_DB],
                $sql
            );
        }
    }
    class MYT_Controller { protected $payload = []; }
}
namespace Config {
    class Database
    {
        public static $connections = [];
        public static $calls = [];
        public static function connect($group)
        {
            self::$calls[] = $group;
            return self::$connections[$group];
        }
    }
}
namespace {
    chdir(dirname(__DIR__));
    foreach (['MOBILE_DB' => 'fda_mobile', 'OAKRIDGE_DB' => 'fda_oakridge', 'MAC_DB' => 'fda_mactan', 'CONSO_DB' => 'fda_conso', 'MANTLEWOOD_DB' => 'fda_mantlewood', 'SUCCESS' => 200, 'FAIL' => 400] as $name => $value) {
        define($name, $value);
    }
    require 'app/Config/Branch.php';
    require 'app/Models/Report.php';
    require 'app/Controllers/Reports.php';
    // Use the actual core pagination helper without loading application configuration.
    $common_source = file_get_contents('app/Common.php');
    preg_match('/function list_page_params\(.*?\n\}/s', $common_source, $pagination_match);
    eval($pagination_match[0]);
    function config($name) { return new \Config\Branch(); }

    $checks = 0;
    function check($condition, string $label): void
    {
        global $checks;
        if (! $condition) { throw new \RuntimeException($label); }
        $checks++;
    }
    set_exception_handler(static function (\Throwable $error): void {
        fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
        exit(1);
    });
    set_error_handler(static function ($severity, $message): void { throw new \RuntimeException($message); });

    class FdaResultFixture
    {
        public function __construct(private array $rows) {}
        public function getResultArray(): array { return $this->rows; }
        public function getRowArray(): ?array { return $this->rows[0] ?? null; }
    }
    class FdaConnectionFixture
    {
        public array $calls = [];
        public ?string $failure = null;
        public bool $throw_failure = false;
        public function __construct(private \PDO $pdo, private ?string $schema = null) {}
        public function query($sql, $binds)
        {
            $this->calls[] = [$sql, $binds];
            if ($this->failure !== null && str_contains($sql, $this->failure)) {
                if ($this->throw_failure) { throw new \RuntimeException('Fixture query error'); }
                return false;
            }
            if (str_contains($sql, 'information_schema.TABLES')) {
                $rows = [];
                $table = array_pop($binds);
                foreach ($binds as $schema) {
                    $query = $this->pdo->prepare("SELECT name FROM {$schema}.sqlite_master WHERE type='table' AND name=?");
                    $query->execute([$table]);
                    if ($query->fetchColumn() !== false) { $rows[] = ['TABLE_SCHEMA' => $schema]; }
                }
                return new FdaResultFixture($rows);
            }
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                $rows = [];
                $tables = array_splice($binds, -2);
                foreach ($binds as $schema) {
                    foreach ($tables as $table) {
                        foreach ($this->pdo->query("PRAGMA {$schema}.table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC) as $column) {
                            $rows[] = ['TABLE_SCHEMA' => $schema, 'TABLE_NAME' => $table, 'COLUMN_NAME' => $column['name']];
                        }
                    }
                }
                return new FdaResultFixture($rows);
            }
            if ($this->schema !== null) {
                $sql = str_replace(['FROM dispense_medicine_details', 'JOIN item'], ['FROM ' . $this->schema . '.dispense_medicine_details', 'JOIN ' . $this->schema . '.item'], $sql);
            }
            $query = $this->pdo->prepare($sql);
            foreach ($binds as $index => $value) {
                $query->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $query->execute();
            return new FdaResultFixture($query->fetchAll(\PDO::FETCH_ASSOC));
        }
    }
    class FdaControllerFixture extends \App\Controllers\Reports
    {
        public function __construct($report, array $payload) { $this->report = $report; $this->payload = $payload; }
        protected function _create_response($status, $data_response, $skip_response_logging = false)
        {
            return ['status' => $status, 'data' => $data_response];
        }
    }

    $pdo_class = class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : \PDO::class;
    $pdo = new $pdo_class('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $concat_ws = static function ($separator, ...$values) {
        return implode($separator, array_filter($values, static fn($value) => $value !== null));
    };
    if ($pdo instanceof \Pdo\Sqlite) {
        $pdo->createFunction('CONCAT_WS', $concat_ws);
    } else {
        $pdo->sqliteCreateFunction('CONCAT_WS', $concat_ws);
    }
    $sources = ['mobile' => MOBILE_DB, 'oakridge' => OAKRIDGE_DB, 'mactan' => MAC_DB, 'consolacion' => CONSO_DB, 'mantlewood' => MANTLEWOOD_DB];
    foreach ($sources as $connection => $schema) {
        $pdo->exec("ATTACH DATABASE ':memory:' AS {$schema}");
        \Config\Database::$connections[$connection] = new FdaConnectionFixture($pdo, $schema);
    }
    $master = new FdaConnectionFixture($pdo);
    \Config\Database::$connections['master'] = $master;
    $model = new \App\Models\Report();
    foreach ([MOBILE_DB, OAKRIDGE_DB, MANTLEWOOD_DB] as $schema) {
        $pdo->exec("CREATE TABLE {$schema}.pharmacy_doctor (id INTEGER PRIMARY KEY, doctor_id INT, name TEXT, lic_no TEXT, is_cpci INT, added_by INT, added_on TEXT, updated_by INT, updated_on TEXT, is_deleted INT)");
    }
    $pdo->exec("INSERT INTO fda_mobile.pharmacy_doctor VALUES (7,NULL,'Doctor Alpha','00123',0,3,'2026-10-05',NULL,NULL,0),(4,137,'Doctor Beta','98765',1,3,'2026-10-05',NULL,NULL,0),(12,NULL,'Deleted Doctor','54321',0,3,'2026-10-05',NULL,NULL,1)");
    $pdo->exec("INSERT INTO fda_oakridge.pharmacy_doctor VALUES (7,NULL,'Doctor Alpha','00007',0,3,'2026-10-06',NULL,NULL,0)");
    foreach ([MOBILE_DB, OAKRIDGE_DB, MAC_DB] as $schema) {
        $doctor_column = $schema === MAC_DB ? '' : ', pharmacy_doctor_id INT';
        $pdo->exec("CREATE TABLE {$schema}.dispense_medicine_details (id INTEGER PRIMARY KEY, dispense_medicine_id INT, added_on TEXT, doctor_name TEXT, patient_name TEXT, patient_id INT, item_id INT, generic TEXT, dosage TEXT, preparation TEXT, quantity INT, fs INT, ps INT {$doctor_column})");
        $pdo->exec("CREATE TABLE {$schema}.item (id INT PRIMARY KEY, philhealth_medicine_type TEXT)");
        $pdo->exec("INSERT INTO {$schema}.item VALUES (84,'ncd'),(73,NULL)");
    }
    $pdo->exec("INSERT INTO fda_mobile.dispense_medicine_details VALUES
        (10,19,'2026-10-12 10:00:00','Doctor Beta','Fixture Patient',1,84,'Drug','5 mg','once',25,1,0,4),
        (2,19,'2026-10-05 10:00:00','Doctor Alpha','Fixture Patient',1,73,'Drug','5 mg','once',20,0,1,7),
        (21,20,'2026-10-09 23:59:59','Doctor Alpha','Second Patient',2,84,'Other','1 mg','daily',10,0,0,7)");
    $pdo->exec("INSERT INTO fda_oakridge.dispense_medicine_details VALUES
        (2,19,'2026-10-12 10:00:00','Different Doctor','Branch Patient',1,84,'Drug','5 mg','once',15,0,0,7),
        (3,20,'2026-10-06 00:00:00','Different Doctor','Branch Patient',1,84,'Drug','5 mg','once',15,0,0,7)");
    $pdo->exec("INSERT INTO fda_mactan.dispense_medicine_details VALUES (1,1,'2026-10-12','Legacy Doctor','Legacy Patient',1,84,'Drug','5 mg','once',5,0,0)");

    $doctors = $model->get_pharmacy_doctor();
    check($doctors['size'] === 3, 'Merge active doctors only');
    check(array_column($doctors['data'], 'branch_id') === [1,2,1], 'Name/branch/ID ordering and duplicate-name preservation');
    check(array_column($doctors['data'], 'id') === [7,7,4], 'Identical local IDs stay distinct');
    check($doctors['data'][1]['branch_name'] === 'Oakridge', 'Configured branch metadata');
    check($doctors['data'][0]['lic_no'] === '00123' && $doctors['data'][0]['doctor_id'] === null, 'Existing fields/leading zeros/nulls preserved');
    check(count($master->calls) === 2 && str_contains($master->calls[0][0], 'information_schema.TABLES'), 'One batched table-existence lookup');
    check(! str_contains($master->calls[1][0], MAC_DB) && ! str_contains($master->calls[1][0], CONSO_DB), 'Unavailable tables absent from union');
    check(substr_count($master->calls[1][0], 'UNION ALL') === 2, 'Available tables use UNION ALL');
    check(\Config\Database::$calls === ['master'], 'Doctor query always uses master');
    check($model->get_pharmacy_doctor(2)['size'] === 1, 'Selected-branch doctors');
    check($model->get_pharmacy_doctor(3) === ['data' => [], 'size' => 0], 'Selected missing table returns empty');
    check($model->get_pharmacy_doctor(7) === ['data' => [], 'size' => 0], 'Empty available table returns empty');

    $report = $model->get_fda_dashboard(null, 1, 20);
    check(array_column($report['data'], 'rx_no') === [1,2,2,3,10,21], 'Numeric Rx order takes precedence over date');
    check(array_column(array_slice($report['data'], 1, 2), 'branch_id') === [1,2], 'Branch tie-breaker for identical Rx IDs');
    check($report['size'] === 6 && $report['total_pages'] === 1, 'Merged FDA count and pages');
    check(array_keys($report['data'][0]) === ['rx_no','date','doctor_name','patient_name','address','age','medications','quantity_dispensed','remarks','rx_type','branch_id','branch_name'], 'FDA row response shape preserved');
    $page_rows = [];
    foreach ([1,2,3] as $page) {
        $batch = $model->get_fda_dashboard(null, $page, 2);
        check($batch['size'] === 6 && $batch['total_pages'] === 3 && count($batch['data']) === 2, 'Pagination retains full count');
        $page_rows = array_merge($page_rows, $batch['data']);
    }
    check($page_rows === $report['data'], 'Ordering consistent across page boundaries');
    $selected = $model->get_fda_dashboard(1, 1, 20, null, null, null, null, null, 7);
    check(array_column($selected['data'], 'rx_no') === [2,21] && $selected['size'] === 2, 'Doctor filters details rather than all siblings of parent');
    check(array_unique(array_column($selected['data'], 'branch_id')) === [1], 'Doctor ID cannot leak matching IDs across branches');
    $query_calls = \Config\Database::$connections['mobile']->calls;
    $count_call = $query_calls[count($query_calls) - 2];
    $row_call = $query_calls[count($query_calls) - 1];
    check(str_contains($count_call[0], 'dmd.pharmacy_doctor_id = ?') && str_contains($row_call[0], 'dmd.pharmacy_doctor_id = ?'), 'Identical bound doctor condition on count and rows');
    check($count_call[1] === array_slice($row_call[1], 0, -2), 'Count and row filter binds match');
    $filtered = $model->get_fda_dashboard(1, 1, 1, '2026-10-05', '2026-10-09', 2, 84, 'Second', 7);
    check(array_column($filtered['data'], 'rx_no') === [21] && $filtered['size'] === 1, 'Doctor combines with dates/patient/item/search');
    check($model->get_fda_dashboard(1, 1, 20, '2026-10-05', '2026-10-05')['size'] === 1, 'Inclusive same-day date boundaries');
    check($model->get_fda_dashboard(1, 1, 20, null, null, null, null, "' OR 1=1 --", 7)['size'] === 0, 'Search remains bound');
    check($model->get_fda_dashboard(3, 1, 20, null, null, null, null, null, 7)['size'] === 0, 'Missing doctor column never ignores filter');
    check($model->get_fda_dashboard(1, 1, 20, null, null, null, null, null, 999)['data'] === [], 'Unknown doctor returns empty results');
    check($model->get_fda_dashboard(7, 1, 20)['size'] === 0, 'Missing FDA table remains empty');

    foreach ([['pharmacy_doctor_id' => 7], ['branch_id' => 1, 'pharmacy_doctor_id' => 0], ['branch_id' => 1, 'pharmacy_doctor_id' => -1], ['branch_id' => 1, 'pharmacy_doctor_id' => '1.5'], ['branch_id' => 1, 'pharmacy_doctor_id' => 'abc'], ['branch_id' => 1, 'pharmacy_doctor_id' => ['7']], ['branch_id' => 1, 'pharmacy_doctor_id' => '99999999999999999999999'], ['branch_id' => [1]], ['branch_id' => 6], ['date_from' => '2026-10-05'], ['date_from' => '2026-02-30', 'date_to' => '2026-10-09']] as $payload) {
        $previous_calls = count(\Config\Database::$calls);
        $response = (new FdaControllerFixture($model, $payload))->get_fda_dashboard();
        check($response['status'] === FAIL && count(\Config\Database::$calls) === $previous_calls, 'Invalid FDA payload fails before queries');
    }
    $response = (new FdaControllerFixture($model, ['branch_id' => '1', 'pharmacy_doctor_id' => '7']))->get_fda_dashboard();
    check($response['status'] === SUCCESS && $response['data']['size'] === 2, 'Controller forwards selected doctor');
    foreach ([[], ['branch_id' => '', 'pharmacy_doctor_id' => ''], ['branch_id' => null, 'pharmacy_doctor_id' => null]] as $payload) {
        check((new FdaControllerFixture($model, $payload))->get_fda_dashboard()['data']['size'] === 6, 'Omitted/empty filters preserve all FDA rows');
    }
    foreach ([[], ['branch_id' => ''], ['branch_id' => null]] as $payload) {
        check((new FdaControllerFixture($model, $payload))->get_pharmacy_doctor()['data']['size'] === 3, 'Doctor endpoint default returns all branches');
    }
    check((new FdaControllerFixture($model, ['branch_id' => '2']))->get_pharmacy_doctor()['data']['size'] === 1, 'Doctor endpoint selected branch');
    foreach ([0, 6, 'abc', [1]] as $branch_id) {
        check((new FdaControllerFixture($model, ['branch_id' => $branch_id]))->get_pharmacy_doctor()['status'] === FAIL, 'Doctor endpoint rejects invalid branch');
    }
    foreach ([[null,7], [1,0], [6,null]] as [$branch_id, $doctor_id]) {
        $failed = false;
        try { $model->get_fda_dashboard($branch_id, 1, 20, null, null, null, null, null, $doctor_id); }
        catch (\InvalidArgumentException $error) { $failed = true; }
        check($failed, 'Direct model calls also enforce branch/doctor constraints');
    }
    foreach (['information_schema.TABLES', 'SELECT pharmacy_doctors.*'] as $failure) {
        foreach ([false, true] as $throw_failure) {
            $master->failure = $failure;
            $master->throw_failure = $throw_failure;
            $failed = false;
            try { $model->get_pharmacy_doctor(); }
            catch (\RuntimeException $error) { $failed = true; }
            check($failed, 'Metadata/data query failures cannot appear as empty success');
        }
    }
    $master->failure = null;
    foreach ([MOBILE_DB, OAKRIDGE_DB, MANTLEWOOD_DB] as $schema) { $pdo->exec("DROP TABLE {$schema}.pharmacy_doctor"); }
    check($model->get_pharmacy_doctor() === ['data' => [], 'size' => 0], 'No available doctor tables returns empty');

    $collection = json_decode(file_get_contents('docs/postman/Reports_fda_dashboard.postman_collection.json'), true, 512, JSON_THROW_ON_ERROR);
    check($collection['info']['schema'] === 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json', 'Postman v2.1 schema');
    $variables = array_column($collection['variable'], 'value', 'key');
    foreach (['api_key', 'token', 'requester'] as $credential) { check($variables[$credential] === '', 'Blank Postman credentials'); }
    $existing_requests = array_filter($collection['item'], static fn($item) => !str_contains($item['request']['url']['raw'], '/get_fda_sales'));
    check(count($existing_requests) === 4, 'Four existing dashboard/doctor request scenarios preserved');
    foreach ($collection['item'] as $item) {
        check($item['request']['method'] === 'GET', 'Postman GET method');
        $url = $item['request']['url'];
        $query = array_filter($url['query'], static fn($field) => ! ($field['disabled'] ?? false));
        $expected_raw = '{{base_url}}/' . implode('/', $url['path']) . '?' . implode('&', array_map(static fn($field) => $field['key'] . '=' . $field['value'], $query));
        check($url['raw'] === $expected_raw, 'Raw Postman URL agrees with enabled parameters');
        preg_match_all('/\{\{([^}]+)\}\}/', json_encode($item['request']), $matches);
        foreach (array_unique($matches[1]) as $variable) { check(array_key_exists($variable, $variables), 'Every referenced Postman variable exists'); }
        foreach ($item['response'] as $example) {
            $body = json_decode($example['body'], true, 512, JSON_THROW_ON_ERROR);
            check(is_array($body), 'Saved response JSON parses');
            if ($example['code'] === 200) {
                check(isset($body['data'], $body['size']) && is_array($body['data']), 'Success example matches report shape');
                check($body['size'] >= count($body['data']), 'Example count matches returned rows');
            } else { check(isset($body['messages']['error_messages']), 'Validation example follows core failure response'); }
        }
    }
    echo "PASS: {$checks} isolated FDA/pharmacy-doctor checks (in-memory only).\n";
}
