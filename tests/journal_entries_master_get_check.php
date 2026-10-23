<?php
/** Execute real GET/list/count/item methods with in-memory fixtures, never application DBs. */
namespace App\Core {
    class MYT_Model
    {
        protected $db;
        public function __construct($db = null) { $this->db = $db ?? \Config\Database::connect(); }
        protected function _resolve_cross_db_sql(string $sql): string
        {
            return str_replace(
                ['{MASTER_DB}', '{MOBILE_DB}', '{OAKRIDGE_DB}', '{MAC_DB}', '{CONSO_DB}', '{MANTLEWOOD_DB}'],
                [MASTER_DB, MOBILE_DB, OAKRIDGE_DB, MAC_DB, CONSO_DB, MANTLEWOOD_DB],
                $sql
            );
        }
    }
    class MYT_Controller { protected $payload = []; }
}
namespace Config {
    class Database
    {
        public static array $connections = [];
        public static array $calls = [];
        public static function connect($group = null)
        {
            $group = $group ?? 'default';
            self::$calls[] = $group;
            return self::$connections[$group];
        }
    }
}
namespace {
    chdir(dirname(__DIR__));
    foreach (['MASTER_DB' => 'je_master', 'MOBILE_DB' => 'je_mobile', 'OAKRIDGE_DB' => 'je_oakridge', 'MAC_DB' => 'je_mactan', 'CONSO_DB' => 'je_conso', 'MANTLEWOOD_DB' => 'je_mantlewood', 'SUCCESS' => 200, 'FAIL' => 400, 'FAIL_NOT_FOUND' => 404, 'FAIL_UNAUTHORIZED' => 401] as $name => $value) {
        define($name, $value);
    }
    require 'app/Core/Journal_center_scope.php';
    require 'app/Libraries/Fund_cost_center.php';
    require 'app/Models/Journal_entry.php';
    require 'app/Controllers/Journal_entries.php';

    function db_connect($group = null) { return \Config\Database::connect($group); }
    $checks = 0;
    function check($condition, string $label): void
    {
        global $checks;
        if (!$condition) { throw new \RuntimeException($label); }
        $checks++;
    }
    set_exception_handler(static function (\Throwable $error): void {
        fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
        exit(1);
    });
    set_error_handler(static function ($severity, $message): void { throw new \RuntimeException($message); });

    class MasterJournalResultFixture
    {
        public function __construct(private array $rows) {}
        public function getResultArray(): array { return $this->rows; }
        public function getRowArray(): ?array { return $this->rows[0] ?? null; }
    }
    class MasterJournalConnectionFixture
    {
        public array $calls = [];
        public function __construct(private \PDO $pdo) {}
        public function query($sql, $binds)
        {
            $this->calls[] = [$sql, $binds];
            $query = $this->pdo->prepare($sql);
            foreach ($binds as $index => $value) {
                $query->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $query->execute();
            return new MasterJournalResultFixture($query->fetchAll(\PDO::FETCH_ASSOC));
        }
    }
    class MasterJournalControllerFixture extends \App\Controllers\Journal_entries
    {
        public bool $authorized = true;
        public function __construct(array $payload) { $this->payload = $payload; }
        protected function _api_validation()
        {
            return $this->authorized ? true : ['status' => FAIL_UNAUTHORIZED, 'data' => ['Unauthorized.']];
        }
        protected function _create_response($status, $data_response, $skip_response_logging = false)
        {
            return ['status' => $status, 'data' => $data_response];
        }
    }

    $pdo_class = class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : \PDO::class;
    $pdo = new $pdo_class('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $concat = static fn(...$values) => implode('', $values);
    if ($pdo instanceof \Pdo\Sqlite) { $pdo->createFunction('CONCAT', $concat); }
    else { $pdo->sqliteCreateFunction('CONCAT', $concat); }

    $pdo->exec("CREATE TABLE branch(id INT,name TEXT,type TEXT,is_deleted INT)");
    $pdo->exec("INSERT INTO branch VALUES(1,'Mobile','clinic',0),(2,'Oakridge','clinic',0),(3,'Mactan','clinic',0),(4,'Conso','clinic',0),(7,'Mantlewood','clinic',0),(21,'Shared','shared',0)");
    $schemas = [MASTER_DB, MOBILE_DB, OAKRIDGE_DB, MAC_DB, CONSO_DB, MANTLEWOOD_DB];
    foreach ($schemas as $schema) {
        $pdo->exec("ATTACH DATABASE ':memory:' AS {$schema}");
        $pdo->exec("CREATE TABLE {$schema}.journal_entry (
            id INTEGER PRIMARY KEY, cost_center_id INT, date TEXT, remarks TEXT, status TEXT,
            total_debit TEXT, total_credit TEXT, approved_by_general_accountant INT,
            approved_by_finance_manager INT, approved_by_finance_director INT,
            is_deleted INT DEFAULT 0)");
        $pdo->exec("CREATE TABLE {$schema}.journal_entry_item (
            id INTEGER PRIMARY KEY, journal_entry_id INT, account_type_id INT,
            debit TEXT, credit TEXT, remarks TEXT, is_deleted INT DEFAULT 0)");
    }
    $pdo->exec("CREATE TABLE je_master.user (id INT PRIMARY KEY,first_name TEXT,last_name TEXT,is_deleted INT)");
    $pdo->exec("INSERT INTO je_master.user VALUES (67,'General','Accountant',0),(68,'Finance','Manager',0),(69,'Finance','Director',0)");
    $pdo->exec("CREATE TABLE je_master.account_type (id INT PRIMARY KEY,name TEXT,is_deleted INT)");
    $pdo->exec("INSERT INTO je_master.account_type VALUES (11,'AR CEBU (NEW)',0),(12,'AR CEBU - UNBILLED',0),(13,'Deleted account',1)");
    $pdo->exec("INSERT INTO je_master.journal_entry VALUES
        (76020,1,'2026-10-09','Billing #5729 MASTER','approved','900.0000','900.0000',67,68,69,0),
        (76021,2,'2026-10-08','Billing #5729 pending','pending','100.0000','100.0000',NULL,NULL,NULL,0),
        (76022,1,'2026-09-30','Billing #5729 old','approved','200.0000','200.0000',67,68,69,0),
        (76023,1,'2026-10-12','Billing #5729 deleted','approved','200.0000','200.0000',67,68,69,1),
        (76024,7,'2026-10-10','Other billing MASTER','approved','100.0000','100.0000',67,68,69,0),
        (76025,4,'2026-10-13','Billing #5729 future','approved','200.0000','200.0000',67,68,69,0),
        (76026,3,'2026-10-08','FM approval MASTER','pending','100.0000','100.0000',67,NULL,NULL,0),
        (76027,4,'2026-10-08','FD approval MASTER','pending','100.0000','100.0000',67,68,NULL,0)");
    $pdo->exec("INSERT INTO je_master.journal_entry_item VALUES
        (1,76020,11,'900.0000','0.0000','MASTER debit',0),
        (2,76020,12,'0.0000','900.0000','MASTER credit',0),
        (3,76020,11,'5.0000','0.0000','Deleted MASTER item',1),
        (4,76024,13,'100.0000','0.0000','MASTER deleted account item',0)");
    foreach ([MOBILE_DB => 1, OAKRIDGE_DB => 2, MAC_DB => 3, CONSO_DB => 4, MANTLEWOOD_DB => 7] as $schema => $branch_id) {
        $pdo->exec("INSERT INTO {$schema}.journal_entry VALUES
            (76020,{$branch_id},'2026-10-12','Billing #5729 CLINIC {$branch_id}','approved','9999.0000','9999.0000',67,68,69,0)");
        $pdo->exec("INSERT INTO {$schema}.journal_entry_item VALUES
            (1,76020,11,'9999.0000','0.0000','CLINIC {$branch_id} item',0)");
    }
    $master = new MasterJournalConnectionFixture($pdo);
    $default = new MasterJournalConnectionFixture($pdo);
    \Config\Database::$connections = ['master' => $master, 'default' => $default];
    $model = new \App\Models\Journal_entry($master);

    foreach ([0, '0'] as $selector) {
        $before_queries = count($master->calls);
        $result = $model->get_details(76020, null, null, null, null, $selector);
        check(count($result) === 1 && $result[0]['remarks'] === 'Billing #5729 MASTER', 'Explicit zero selects only master header');
        check($result[0]['branch_id'] === null && $result[0]['cost_center_id'] === 1, 'Source marker and stored cost center unchanged');
        check($result[0]['approved_by_general_accountant_name'] === 'General Accountant' && $result[0]['approved_by_finance_manager_name'] === 'Finance Manager' && $result[0]['approved_by_finance_director_name'] === 'Finance Director', 'Approval names preserved');
        check(count($result[0]['items']) === 2 && array_column($result[0]['items'], 'remarks') === ['MASTER debit','MASTER credit'], 'Master-only active items despite duplicate clinic IDs');
        check(array_column($result[0]['items'], 'account_type_name') === ['AR CEBU (NEW)','AR CEBU - UNBILLED'], 'Master item account names retained');
        check($model->get_details_count(null, null, null, $selector) === 7, 'Master count includes all stored cost centers');
        foreach (array_slice($master->calls, $before_queries) as [$sql]) {
            check(!str_contains($sql, 'je_mobile') && !str_contains($sql, 'UNION ALL'), 'Master selection never queries clinic sources');
        }
    }

    $date_filtered = $model->get_details(null, 'approved', 'Billing #5729', 1, 10, 0, '2026-10-01', '2026-10-12');
    check(array_column($date_filtered, 'id') === [76020], 'Status/search/date filters apply to master');
    check($model->get_details_count(null, 'approved', 'Billing #5729', 0, '2026-10-01', '2026-10-12') === 1, 'Same filters scope master counts');
    check($model->get_details_count(null, 'approved', null, 0, '2026-10-09', '2026-10-10') === 2, 'Date boundaries inclusive');
    $filtered_query_calls = array_slice($master->calls, -4);
    check($filtered_query_calls[0][1] === ['approved','%Billing #5729%','2026-10-01','2026-10-12'], 'Bound filters preserved');
    $pages = [];
    for ($page = 1; $page <= 4; $page++) {
        $batch = $model->get_details(null, 'approved', null, $page, 1, 0);
        check(count($batch) === 1, 'Master pagination returns bounded page');
        $pages[] = $batch[0]['id'];
    }
    check($pages === [76025,76024,76020,76022], 'Existing date/ID descending order retained');
    check($model->get_details(null, 'approved', null, 5, 1, 0) === [], 'Page beyond results is empty');
    foreach (['for_approval_ga' => 76021, 'for_approval_fm' => 76026, 'for_approval_fd' => 76027] as $status => $expected_id) {
        check(array_column($model->get_details(null, $status, null, 1, 50, 0), 'id') === [$expected_id], 'Approval-stage filter unchanged');
        check($model->get_details_count(null, $status, null, 0) === 1, 'Approval-stage count unchanged');
    }
    check($model->get_details(76023, null, null, null, null, 0) === [], 'Deleted master header excluded');
    check($model->get_details(99999, null, null, null, null, 0) === [], 'Missing master ID empty');
    $other = $model->get_details(76024, null, null, null, null, 0)[0];
    check($other['items'][0]['account_type_name'] === null, 'Deleted account name does not remove active item');
    foreach ([null, ''] as $selector) {
        $rows = $model->get_details(null, null, null, 1, 50, $selector);
        check(count($rows) === 12 && $model->get_details_count(null, null, null, $selector) === 12, 'Omitted/empty model selector preserves combined sources');
        $overlap_rows = array_filter($rows, static fn($row) => $row['id'] === 76020);
        check(count($overlap_rows) === 6, 'Combined list preserves overlapping journal IDs');
        foreach ($overlap_rows as $row) {
            check(count($row['items']) === ($row['branch_id'] === null ? 2 : 1), 'Combined items stay attached to their source');
        }
    }
    foreach ([1 => 3, 2 => 2, 3 => 2, 4 => 3, 7 => 2] as $branch_id => $expected_count) {
        $row = $model->get_details(76020, null, null, null, null, $branch_id)[0];
        check($row['branch_id'] === $branch_id && $row['remarks'] === "Billing #5729 CLINIC {$branch_id}", 'Clinic-specific header preserved');
        check($row['items'][0]['remarks'] === "CLINIC {$branch_id} item" && $model->get_details_count(null, null, null, $branch_id) === $expected_count, 'Clinic items retained and matching master rows counted');
    }

    foreach ([0, '0'] as $selector) {
        $before_connections = count(\Config\Database::$calls);
        $response = (new MasterJournalControllerFixture(['journal_entry_id' => 76020, 'cost_center_id' => $selector]))->get();
        check($response['status'] === SUCCESS && $response['data']['size'] === 1, 'Controller accepts integer/string zero');
        check($response['data']['data'][0]['remarks'] === 'Billing #5729 MASTER', 'Controller forwards master-only selection');
        check(array_slice(\Config\Database::$calls, $before_connections) === ['master'], 'Explicit master connection selected');
    }
    foreach ([['branch_id' => 0], ['branch_id' => '0', 'cost_center_id' => 1]] as $payload) {
        $response = (new MasterJournalControllerFixture($payload + ['journal_entry_id' => 76020]))->get();
        check($response['data']['data'][0]['remarks'] === 'Billing #5729 MASTER', 'Master branch alias and alias precedence');
    }
    $response = (new MasterJournalControllerFixture(['branch_id' => 1, 'cost_center_id' => 0, 'journal_entry_id' => 76020]))->get();
    check($response['data']['data'][0]['branch_id'] === 1, 'Clinic branch alias retains precedence over cost center');
    $response = (new MasterJournalControllerFixture(['cost_center_id' => '0','status' => 'approved','term' => 'Billing #5729','date_from' => '2026-10-01','date_to' => '2026-10-12','data_size' => 10,'page' => 1]))->get();
    check($response['data']['size'] === 1 && count($response['data']['data']) === 1, 'Example list filters and total count work with zero');
    foreach (['date' => '2026-10-09', 'date_from' => ['2026-09-01','2026-10-09'], 'date_to' => ['2026-09-01','2026-10-09']] as $field => $value) {
        $response = (new MasterJournalControllerFixture(['cost_center_id' => 0, 'status' => 'approved', $field => $value]))->get();
        check($response['data']['size'] === 1, 'Existing exact/single/array date normalization retained');
    }
    foreach ([[], ['cost_center_id' => ''], ['cost_center_id' => null], ['branch_id' => '', 'cost_center_id' => 0]] as $payload) {
        $response = (new MasterJournalControllerFixture($payload))->get();
        check($response['status'] === SUCCESS && $response['data']['size'] === 12 && count($response['data']['data']) === 12, 'Default/empty selector and empty-alias precedence remain combined');
    }
    foreach ([['journal_entry_id' => 99999], ['journal_entry_id' => 76023], ['status' => 'missing'], ['page' => 999]] as $payload) {
        $response = (new MasterJournalControllerFixture($payload + ['cost_center_id' => 0]))->get();
        check($response['status'] === FAIL_NOT_FOUND && $response['data'] === ['No journal_entries found.'], 'Existing no-results response retained');
    }
    foreach ([6, -1, 'abc', '0abc', '1abc', '0.0', 0.0, false, true, [], [0], new \stdClass(), '99999999999999999999999'] as $selector) {
        $before_connections = count(\Config\Database::$calls);
        $response = (new MasterJournalControllerFixture(['cost_center_id' => $selector]))->get();
        check($response['status'] === FAIL && $response['data'] === ['Invalid Cost Center ID.'], 'Malformed/unsupported controller selectors return HTTP400');
        check(array_diff(array_slice(\Config\Database::$calls, $before_connections), ['master']) === [], 'Invalid selection only reads master routing metadata');
        check($model->get_details(null, null, null, 1, 50, $selector) === false && $model->get_details_count(null, null, null, $selector) === 0, 'Malformed direct model selection never becomes master');
    }
    $controller = new MasterJournalControllerFixture(['cost_center_id' => 0]);
    $controller->authorized = false;
    $before_connections = count(\Config\Database::$calls);
    check($controller->get()['status'] === FAIL_UNAUTHORIZED && count(\Config\Database::$calls) === $before_connections, 'API authorization remains before source selection');

    // New branch-filtered master behavior, added after the unchanged zero/default checks.
    $pdo->exec("INSERT INTO je_master.journal_entry VALUES
        (76064,4,'2026-10-14','credit_memo #5 MASTER','approved','100.0000','100.0000',67,68,69,0),
        (76065,2,'2026-10-14','credit OTHER BRANCH','approved','200.0000','200.0000',67,68,69,0),
        (76066,4,'2026-10-14','credit DELETED','approved','100.0000','100.0000',67,68,69,1),
        (76067,4,'2026-10-14','credit PENDING','pending','100.0000','100.0000',NULL,NULL,NULL,0),
        (76068,4,'2026-09-30','credit OLD','approved','100.0000','100.0000',67,68,69,0),
        (76069,0,'2026-10-14','credit ZERO COST CENTER','approved','100.0000','100.0000',67,68,69,0),
        (76070,NULL,'2026-10-14','credit NULL COST CENTER','approved','100.0000','100.0000',67,68,69,0)");
    $pdo->exec("INSERT INTO je_master.journal_entry_item VALUES
        (9901,76064,11,'100.0000','0.0000','MASTER 76064 debit',0),
        (9902,76064,12,'0.0000','100.0000','MASTER 76064 credit',0),
        (9903,76064,11,'5.0000','0.0000','MASTER 76064 deleted item',1)");
    $pdo->exec("INSERT INTO je_conso.journal_entry VALUES
        (501,2,'2026-10-13','credit CLINIC 4','approved','25.0000','25.0000',67,68,69,0)");
    $pdo->exec("INSERT INTO je_conso.journal_entry_item VALUES
        (9901,501,11,'25.0000','0.0000','CLINIC 501 debit',0),
        (9902,501,12,'0.0000','25.0000','CLINIC 501 credit',0)");
    $pdo->exec("INSERT INTO je_oakridge.journal_entry VALUES
        (502,2,'2026-10-14','credit CLINIC 2','approved','25.0000','25.0000',67,68,69,0)");

    foreach ([4, '4'] as $selector) {
        $rows = $model->get_details(null, 'approved', 'credit', 1, 10, $selector, '2026-10-01', '2026-10-14');
        check(array_column($rows, 'id') === [76064,501], 'Selected clinic and matching master results share global date/ID order');
        check($model->get_details_count(null, 'approved', 'credit', $selector, '2026-10-01', '2026-10-14') === 2, 'Master cost center and all filters apply identically to list/count');
        check($rows[0]['branch_id'] === null && $rows[0]['cost_center_id'] === 4, 'Master marker retained despite selected clinic cost center');
        check(array_column($rows[0]['items'], 'remarks') === ['MASTER 76064 debit','MASTER 76064 credit'], 'Master item lookup excludes deleted items and clinic item-ID collisions');
        check($rows[1]['branch_id'] === 4 && $rows[1]['cost_center_id'] === 2, 'Stored cost-center filter applies only to master, preserving clinic source behavior');
        check(array_column($rows[1]['items'], 'remarks') === ['CLINIC 501 debit','CLINIC 501 credit'], 'Clinic item lookup remains source isolated');
        check($rows[0]['approved_by_general_accountant_name'] === 'General Accountant'
            && $rows[0]['items'][0]['account_type_name'] === 'AR CEBU (NEW)', 'Approval/account names remain decorated');
        check(array_column($model->get_details(null, 'approved', 'credit', 1, 1, $selector, '2026-10-01', '2026-10-14'), 'id') === [76064]
            && array_column($model->get_details(null, 'approved', 'credit', 2, 1, $selector, '2026-10-01', '2026-10-14'), 'id') === [501], 'Pagination occurs after combining master/clinic results');
        check($model->get_details(null, 'approved', 'credit', 3, 1, $selector, '2026-10-01', '2026-10-14') === [], 'Branch-filtered page beyond results remains empty');
        check($model->get_details(76065, null, null, null, null, $selector) === [], 'Other master cost center excluded from ID lookup');
        check($model->get_details(76066, null, null, null, null, $selector) === [], 'Deleted matching master journal excluded');
        check($model->get_details(502, null, null, null, null, $selector) === [], 'Other clinic source excluded from ID lookup');
    }
    $model->get_details(null, 'approved', 'credit', 1, 10, 4, '2026-10-01', '2026-10-14');
    [$rows_sql,$rows_binds] = $master->calls[count($master->calls)-3];
    $model->get_details_count(null, 'approved', 'credit', 4, '2026-10-01', '2026-10-14');
    [$count_sql,$count_binds] = $master->calls[count($master->calls)-1];
    check($rows_binds === ['approved','%credit%','2026-10-01','2026-10-14','approved','%credit%','2026-10-01','2026-10-14',4]
        && $rows_binds === $count_binds, 'Identical bound filters per source for rows and counts');
    check(substr_count($rows_sql, 'journal_entry.cost_center_id = ?') === 1
        && substr_count($count_sql, 'journal_entry.cost_center_id = ?') === 1, 'Only one bound master cost-center predicate per combined query');

    foreach (['cost_center_id', 'branch_id'] as $selector_name) {
        $before_connections = count(\Config\Database::$calls);
        $response = (new MasterJournalControllerFixture([$selector_name => '4','status' => 'approved','term' => 'credit',
            'date_from' => '2026-10-01','date_to' => '2026-10-14','data_size' => 10,'page' => 1]))->get();
        check($response['status'] === SUCCESS && $response['data']['size'] === 2
            && array_column($response['data']['data'], 'id') === [76064,501], 'Supplied list request shape includes matching master journals');
        check(array_unique(array_slice(\Config\Database::$calls, $before_connections)) === ['master'], 'Selected-branch controller uses master cross-database connection');
        $response = (new MasterJournalControllerFixture([$selector_name => '4','journal_entry_id' => 76064]))->get();
        check($response['status'] === SUCCESS && $response['data']['size'] === 1
            && $response['data']['data'][0]['branch_id'] === null, 'Supplied detail request falls back to matching master journal');
        check(array_column($response['data']['data'][0]['items'], 'remarks') === ['MASTER 76064 debit','MASTER 76064 credit'], 'Detail fallback returns master items');
    }
    $response = (new MasterJournalControllerFixture(['branch_id' => 2,'cost_center_id' => 4,'journal_entry_id' => 76064]))->get();
    check($response['status'] === FAIL_NOT_FOUND, 'Branch alias precedence also controls master cost-center filtering');
    $response = (new MasterJournalControllerFixture(['cost_center_id' => 4,'journal_entry_id' => 76065]))->get();
    check($response['status'] === FAIL_NOT_FOUND && $response['data'] === ['No journal_entries found.'], 'Wrong cost center retains the existing no-results response');
    check(count($model->get_details(76069, null, null, null, null, 0)) === 1
        && count($model->get_details(76070, null, null, null, null, 0)) === 1, 'Explicit zero remains master-only without filtering stored cost center');

    $pdo->exec("INSERT INTO je_conso.journal_entry VALUES
        (76064,4,'2026-10-10','credit CLINIC COLLISION','approved','900.0000','900.0000',67,68,69,0)");
    $pdo->exec("INSERT INTO je_conso.journal_entry_item VALUES
        (9904,76064,11,'900.0000','0.0000','CLINIC collision item',0)");
    $response = (new MasterJournalControllerFixture(['cost_center_id' => 4,'journal_entry_id' => 76064]))->get();
    check($response['data']['size'] === 1 && $response['data']['data'][0]['branch_id'] === 4
        && $response['data']['data'][0]['items'][0]['remarks'] === 'CLINIC collision item', 'Clinic wins ID collision even when master date is newer');
    $both = array_values(array_filter($model->get_details(null, 'approved', 'credit', 1, 50, 4), static fn($row) => $row['id'] === 76064));
    check(count($both) === 2, 'List preserves distinct master/clinic headers sharing one ID');
    foreach ($both as $row) {
        check(array_column($row['items'], 'remarks') === ($row['branch_id'] === null
            ? ['MASTER 76064 debit','MASTER 76064 credit'] : ['CLINIC collision item']), 'Colliding list IDs retain source-specific items');
    }
    $response = (new MasterJournalControllerFixture(['cost_center_id' => 0,'journal_entry_id' => 76064]))->get();
    check($response['data']['data'][0]['branch_id'] === null, 'Explicit zero overrides clinic-first lookup with master-only source');
    $pdo->exec("UPDATE je_conso.journal_entry SET status='pending' WHERE id=76064");
    check($model->get_details(76064, 'approved', null, null, null, 4)[0]['branch_id'] === null, 'Clinic-first applies only among journals matching all filters');
    check($model->get_details(76064, null, 'MASTER', null, null, 4)[0]['branch_id'] === null, 'Search can select master when colliding clinic does not match');
    check($model->get_details(76064, null, null, null, null, 4, '2026-10-14', '2026-10-14')[0]['branch_id'] === null, 'Date filters can select master when colliding clinic does not match');
    $pdo->exec("UPDATE je_conso.journal_entry SET is_deleted=1 WHERE id=76064");
    check($model->get_details(76064, null, null, null, null, 4)[0]['branch_id'] === null, 'Deleted clinic collision allows master fallback');
    echo "PASS: {$checks} isolated master journal GET checks (in-memory only).\n";
}
