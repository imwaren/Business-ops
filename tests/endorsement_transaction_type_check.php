<?php
/** Real list/count SQL and controller validation, with no application or external connections. */
namespace App\Core {
    class MYT_Model
    {
        protected $db;
        public function __construct($db) { $this->db = $db; }
    }
    class MYT_Controller { protected $payload = []; }
}
namespace {
    chdir(dirname(__DIR__));
    define('FAIL', 400);
    define('FAIL_NOT_FOUND', 404);
    require 'app/Models/Endorsement.php';
    require 'app/Controllers/Endorsements.php';
    preg_match('/function sql_limit_clause\(.*?\n\}/s', file_get_contents('app/Common.php'), $pagination_match);
    eval($pagination_match[0]);

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

    class SelectionResultFixture
    {
        public function __construct(private array $rows) {}
        public function getResultArray(): array { return $this->rows; }
        public function getRowArray(): ?array { return $this->rows[0] ?? null; }
    }
    class SelectionConnectionFixture
    {
        public array $calls = [];
        public function __construct(private \PDO $pdo) {}
        public function query($sql, $binds)
        {
            $this->calls[] = [$sql, $binds];
            // SQLite-only adaptation of the existing display aggregation; WHERE SQL is unchanged.
            $sql = str_replace("GROUP_CONCAT(mobile_van.name SEPARATOR ', ')", "GROUP_CONCAT(mobile_van.name, ', ')", $sql);
            $query = $this->pdo->prepare($sql);
            foreach ($binds as $index => $value) {
                $query->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $query->execute();
            return new SelectionResultFixture($query->fetchAll(\PDO::FETCH_ASSOC));
        }
    }
    $pdo_class = class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : \PDO::class;
    $pdo = new $pdo_class('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $find_in_set = static function ($token, $csv): ?int {
        if ($token === null || $csv === null) { return null; }
        $index = array_search((string) $token, explode(',', (string) $csv), true);
        return $index === false ? 0 : $index + 1;
    };
    if ($pdo instanceof \Pdo\Sqlite) {
        $pdo->createFunction('FIND_IN_SET', $find_in_set, 2);
        $pdo->createFunction('CURDATE', static fn() => '2026-10-13', 0);
    } else {
        $pdo->sqliteCreateFunction('FIND_IN_SET', $find_in_set, 2);
        $pdo->sqliteCreateFunction('CURDATE', static fn() => '2026-10-13', 0);
    }
    $pdo->exec("CREATE TABLE company (id INTEGER, name TEXT, is_deleted INTEGER);
        CREATE TABLE branch (id INTEGER, name TEXT, is_deleted INTEGER);
        CREATE TABLE mobile_van (id INTEGER, name TEXT);
        CREATE TABLE request (id INTEGER, added_by INTEGER, is_deleted INTEGER, transaction_type TEXT);
        CREATE TABLE endorsement (id INTEGER PRIMARY KEY, request_id INTEGER, company_id INTEGER,
            preferred_branch_id INTEGER, mobile_branch_id INTEGER, mobile_van_ids TEXT, branch_id TEXT,
            status TEXT, remarks TEXT, is_pending_revision INTEGER, is_force_evaluate INTEGER,
            with_masterlist INTEGER, contract_date_from TEXT, transaction_type TEXT, is_deleted INTEGER);
        INSERT INTO company VALUES (1, 'Fixture company', 0), (2, 'Other company', 0);
        INSERT INTO branch VALUES (1, 'Mobile', 0), (2, 'Oakridge', 0);
        INSERT INTO request VALUES (1, 67, 0, 'unrelated'), (2, 68, 0, 'mobile'), (3, 67, 1, 'unrelated');");
    $stored_types = [1 => 'mobile', 2 => 'clinic_based', 3 => 'mobile,clinic_based',
        4 => 'clinic_based,mobile', 5 => 'mobile_other', 6 => 'clinic_based_extra',
        7 => null, 8 => '', 9 => 'unknown', 10 => 'mobile', 11 => 'mobile',
        12 => 'mobile', 13 => 'mobile', 14 => 'mobile', 15 => 'mobile', 16 => '0'];
    $insert = $pdo->prepare('INSERT INTO endorsement VALUES (?, ?, ?, 1, 2, NULL, ?, ?, ?, 0, 0, 1, ?, ?, ?)');
    foreach ($stored_types as $id => $value) {
        $request_id = $id === 10 ? 2 : ($id === 11 ? null : ($id === 12 ? 999 : ($id === 13 ? 3 : 1)));
        $insert->execute([$id, $request_id, $id === 15 ? 2 : 1, $id === 15 ? '4' : '1,2',
            $id === 14 ? 'pending' : 'approved', 'Fixture match', '2026-01-01', $value, $id === 15 ? 1 : 0]);
    }
    $db = new SelectionConnectionFixture($pdo);
    $model = new \App\Models\Endorsement($db);
    function selection_rows($model, array $filters = []): array
    {
        return $model->get_details(...$filters);
    }
    function selection_count($model, array $filters = []): int
    {
        unset($filters['page'], $filters['data_size']);
        return $model->get_count(...$filters);
    }
    function selection_ids(array $rows): array { return array_column($rows, 'id'); }
    $all_ids = array_values(array_diff(array_keys($stored_types), [15]));
    $mobile_ids = [1, 3, 4, 10, 11, 12, 13, 14];
    $combined_ids = [1, 2, 3, 4, 10, 11, 12, 13, 14];
    foreach ([
        [null, $all_ids], ['', $all_ids], [' , , ', $all_ids], ['mobile', $mobile_ids],
        ['clinic_based', [2, 3, 4]], ['mobile,clinic_based', $combined_ids],
        ['clinic_based,mobile', $combined_ids], [' mobile, clinic_based, mobile, , ', $combined_ids],
        ['mobile_other', [5]], ['unknown', [9]], ['missing', []], ['0', [16]],
        ["mobile') OR 1=1 --", []],
    ] as [$value, $expected]) {
        $filters = ['transaction_type' => $value];
        check(selection_ids(selection_rows($model, $filters)) === $expected, 'Complete token matches: ' . ($value ?? 'null'));
        check(selection_count($model, $filters) === count($expected), 'Matching count');
        $list_call = $db->calls[count($db->calls) - 2];
        $count_call = $db->calls[count($db->calls) - 1];
        check($list_call[1] === $count_call[1], 'List/count bindings identical');
    }
    $normalized = ['transaction_type' => ' mobile, clinic_based, mobile, , '];
    selection_rows($model, $normalized);
    [$sql, $binds] = $db->calls[array_key_last($db->calls)];
    check($binds === ['mobile', 'clinic_based'], 'Trim, remove duplicates and empty tokens');
    check(str_contains($sql, 'AND (FIND_IN_SET(?, endorsement.transaction_type) > 0 OR FIND_IN_SET(?, endorsement.transaction_type) > 0)'), 'Bound grouped OR against endorsement');
    $owned = ['request_added_by' => 67, 'transaction_type' => 'mobile,clinic_based'];
    check(selection_ids(selection_rows($model, $owned)) === [1, 2, 3, 4, 13, 14], 'Ownership excludes other and missing requests, retains deleted linked request behavior');
    check(selection_count($model, $owned) === 6, 'Ownership total');
    foreach ([['endorsement_id' => 10], ['endorsement_id' => 11], ['endorsement_id' => 12], ['endorsement_id' => 5]] as $filter) {
        check(selection_rows($model, $filter + $owned) === [], 'Explicit ID cannot bypass token or ownership restriction');
        check(selection_count($model, $filter + $owned) === 0, 'Explicit ID count restricted');
    }
    $filtered = ['status' => 'approved', 'company_id' => 1, 'branch_id' => '2', 'term' => 'match',
        'is_pending_revision' => 0, 'is_force_evaluate' => 0] + $owned;
    check(selection_ids(selection_rows($model, $filtered)) === [1, 2, 3, 4, 13], 'Existing filters AND token group');
    check(selection_count($model, $filtered) === 5, 'Combined count');
    check(count(selection_rows($model, ['status' => 'patient_upload'] + $owned)) === 5, 'Patient upload special status preserved');
    $page_ids = [];
    for ($page = 1; $page <= 3; $page++) {
        $rows = selection_rows($model, ['page' => $page, 'data_size' => 2] + $owned);
        check(count($rows) === 2, 'Bounded page');
        check(selection_count($model, $owned) === 6, 'Total independent of page');
        $page_ids = array_merge($page_ids, selection_ids($rows));
    }
    check($page_ids === [1, 2, 3, 4, 13, 14], 'Matching rows across page boundaries');
    check(selection_rows($model, ['page' => 4, 'data_size' => 2] + $owned) === [], 'Later empty page');
    check(selection_rows($model, ['endorsement_id' => 4] + $owned)[0]['transaction_type'] === 'clinic_based,mobile', 'Stored response remains unchanged');

    class SelectionQuerySpy
    {
        public array $calls = [];
        public function get_details(...$args) { $this->calls['list'] = $args; return []; }
        public function get_count(...$args) { $this->calls['count'] = $args; return 0; }
    }
    class SelectionUserFixture
    {
        public int $calls = 0;
        public function __construct(private $role_id) {}
        public function select(...$args) { $this->calls++; return (object) ['id' => 67, 'role_id' => $this->role_id]; }
    }
    class SelectionControllerFixture extends \App\Controllers\Endorsements
    {
        public SelectionQuerySpy $spy;
        public SelectionUserFixture $user_fixture;
        public function __construct(array $payload, $role_id = 19)
        {
            $this->payload = $payload + ['requester' => 67, 'request_added_by' => 999];
            $this->endorsement = $this->spy = new SelectionQuerySpy();
            $this->user = $this->user_fixture = new SelectionUserFixture($role_id);
        }
        protected function _api_validation() { return true; }
        protected function _create_response($status, $data_response, $skip_response_logging = false) { return [$status, $data_response]; }
    }
    foreach ([[], (object) [], 1, 0, 1.5, true, false] as $invalid) {
        $controller = new SelectionControllerFixture(['transaction_type' => $invalid]);
        $response = $controller->get();
        check($response === [400, ['transaction_type must be a comma-separated string.']], 'Invalid input gives clear HTTP 400');
        check($controller->spy->calls === [] && $controller->user_fixture->calls === 0, 'Invalid input stops before query');
    }
    foreach ([[], ['transaction_type' => null], ['transaction_type' => ''], ['transaction_type' => 'mobile,clinic_based']] as $payload) {
        foreach ([19, '19', 20] as $role_id) {
            $controller = new SelectionControllerFixture($payload, $role_id);
            check($controller->get() === [404, ['No endorsements found.']], 'No-results response preserved');
            check($controller->spy->calls['list'][10] === ($payload['transaction_type'] ?? null), 'List receives appended input');
            check($controller->spy->calls['count'][8] === ($payload['transaction_type'] ?? null), 'Count receives appended input');
            check($controller->spy->calls['list'][9] === ((int) $role_id === 19 ? 67 : null), 'Role 19 scope retained, spoofed scope ignored');
            check($controller->spy->calls['count'][7] === $controller->spy->calls['list'][9], 'Count has same ownership');
        }
    }
    foreach (['get_details', 'get_count'] as $method) {
        $parameters = (new \ReflectionMethod($model, $method))->getParameters();
        $parameter = $parameters[array_key_last($parameters)];
        check($parameter->getName() === 'transaction_type' && $parameter->getDefaultValue() === null, 'Appended optional parameter preserves callers');
    }
    echo "PASS: {$checks} isolated endorsement transaction-type checks.\n";
}
