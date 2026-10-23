<?php
/** Real controller helpers and model queries; independent in-memory branch databases only. */
namespace App\Core {
    class MYT_Controller { protected $payload = []; protected $db; }
    class MYT_Model
    {
        protected $db;
        public function __construct($db) { $this->db = $db; }
        public function insert($values)
        {
            $columns = implode(',', array_keys($values));
            $marks = implode(',', array_fill(0, count($values), '?'));
            $this->db->query("INSERT INTO {$this->table} ({$columns}) VALUES ({$marks})", array_values($values));
            return (int) $this->db->pdo->lastInsertId();
        }
        public function update($where, $values)
        {
            $sets = implode(',', array_map(static fn($column) => $column . '=?', array_keys($values)));
            $filters = implode(' AND ', array_map(static fn($column) => $column . '=?', array_keys($where)));
            $this->db->query("UPDATE {$this->table} SET {$sets} WHERE {$filters}", array_merge(array_values($values), array_values($where)));
            return true;
        }
        public function select($columns, $where, $limit = null)
        {
            $filters = implode(' AND ', array_map(static fn($column) => $column . '=?', array_keys($where)));
            $rows = $this->db->query('SELECT ' . ($columns ?: '*') . " FROM {$this->table} WHERE {$filters}", array_values($where))->getResultArray();
            return $limit === 1 ? ($rows ? (object) $rows[0] : false) : $rows;
        }
        public function error() { return 'Synthetic model failure'; }
    }
}
namespace {
    chdir(dirname(__DIR__));
    define('SUCCESS', 200); define('FAIL', 400); define('FAIL_NOT_FOUND', 404);
    require 'app/Controllers/Traits/Entity_consistency.php';
    require 'app/Models/Consultation_doctor.php';
    require 'app/Controllers/Doctors.php';
    $checks = 0;
    function check($condition, string $label): void
    {
        global $checks;
        if (!$condition) { throw new \RuntimeException($label); }
        $checks++;
    }
    set_error_handler(static function ($severity, $message): void { throw new \RuntimeException($message); });
    set_exception_handler(static function (\Throwable $error): void { fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n"); exit(1); });

    class DoctorShareResultFixture
    {
        public function __construct(private array $rows) {}
        public function getResultArray() { return $this->rows; }
    }
    class DoctorShareConnectionFixture
    {
        public array $calls = [];
        public int $transactions = 0;
        public \PDO $pdo;
        public function __construct()
        {
            $pdo_class = class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : \PDO::class;
            $this->pdo = new $pdo_class('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('CREATE TABLE consultation(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,is_deleted INT DEFAULT 0);
                CREATE TABLE consultation_doctor(id INTEGER PRIMARY KEY AUTOINCREMENT,consultation_id INT,doctor_id INT,fee_name TEXT,
                    amount TEXT,doctor_share TEXT,doctor_share_amount TEXT,discount_percentage TEXT,added_by INT,added_on TEXT,
                    updated_by INT,updated_on TEXT,is_deleted INT DEFAULT 0);');
        }
        public function query($sql, $binds)
        {
            $this->calls[] = [$sql,$binds];
            $query = $this->pdo->prepare($sql);
            $query->execute($binds);
            return new DoctorShareResultFixture($query->fetchAll(\PDO::FETCH_ASSOC));
        }
        public function transBegin() { $this->transactions++; throw new \RuntimeException('Endpoint validation reached transaction unexpectedly'); }
    }
    class DoctorShareCatalogFixture
    {
        public function __construct(private DoctorShareConnectionFixture $db) {}
        public function find_by_name($name)
        {
            $rows = $this->db->query('SELECT id FROM consultation WHERE name=?', [$name])->getResultArray();
            return $rows ? (object) $rows[0] : false;
        }
        public function insert($values)
        {
            $this->db->query('INSERT INTO consultation(name) VALUES (?)', [$values['name']]);
            return (int) $this->db->pdo->lastInsertId();
        }
    }
    class DoctorShareDoctorFixture
    {
        public function __construct(private int $branch_id) {}
        public function select($columns, $where, $limit) { return (object) ['id' => 1000 + $this->branch_id]; }
        public function get_details(...$args) { return [['id' => 99,'branch_in' => '1,2,3,4,7','signature' => null]]; }
    }
    class DoctorShareHmoFixture { public function get_by_doctor($id) { return []; } }
    class DoctorShareControllerFixture extends \App\Controllers\Doctors
    {
        public bool $authorized = true;
        public array $branches = [];
        public function __construct(public array $connections, array $payload = [])
        {
            $this->payload = $payload;
            $this->db = $connections[0];
        }
        protected function _api_validation() { return $this->authorized ? true : ['status' => 401,'data' => ['Unauthorized.']]; }
        protected function _create_response($status, $data_response, $skip_response_logging = false) { return ['status' => $status,'data' => $data_response]; }
        protected function resolve_branch_models(int $branch_id)
        {
            $this->branches[] = $branch_id;
            $db = $this->connections[$branch_id];
            $this->doctor = new DoctorShareDoctorFixture($branch_id);
            $this->consultation = new DoctorShareCatalogFixture($db);
            $this->consultation_doctor = new \App\Models\Consultation_doctor($db);
            $this->doctor_hmo = new DoctorShareHmoFixture();
            return true;
        }
        public function save_consultations(string $operation): bool
        {
            if (!$this->_validate_consultation_shares()) { return false; }
            $method = new \ReflectionMethod(\App\Controllers\Doctors::class, '_attempt_' . $operation . '_consultations');
            return $method->invoke($this, 99);
        }
        public function normalize($value) { return $this->_normalize_consultation_value($value); }
    }
    function connections(): array
    {
        $result = [];
        foreach ([0,1,2,3,4,7] as $branch_id) { $result[$branch_id] = new DoctorShareConnectionFixture(); }
        return $result;
    }
    function fingerprint(array $connections): string
    {
        $rows = [];
        foreach ($connections as $db) {
            foreach (['consultation','consultation_doctor'] as $table) { $rows[] = $db->pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC); }
        }
        return json_encode($rows);
    }
    $connections = connections();
    foreach (['create','update'] as $operation) {
        foreach ([['doctor_share' => 90,'doctor_share_amount' => 100], ['doctor_share' => 0,'doctor_share_amount' => '0'],
                  ['doctor_share' => '0','doctor_share_amount' => 100], ['doctor_share' => 90,'doctor_share_amount' => 0]] as $conflict) {
            $payload = ['consultations' => [['fee_name' => 'Valid earlier row','doctor_share' => 90], $conflict + ['fee_name' => 'Conflicting row']]];
            $controller = new DoctorShareControllerFixture($connections, $payload);
            $before = fingerprint($connections);
            $response = $controller->$operation();
            check($response['status'] === FAIL && str_contains($response['data'][0], 'consultations[1]'), 'Conflict returns HTTP 400 and the submitted index');
            check(str_contains($response['data'][0], 'doctor_share and doctor_share_amount'), 'Failure names both fields');
            check(fingerprint($connections) === $before && $controller->branches === [], 'Conflict anywhere stops before writes or branch resolution');
            foreach ($connections as $db) { check($db->transactions === 0 && $db->calls === [], 'No transaction, doctor, user, catalog or mirror calls before conflict response'); }
        }
        $controller = new DoctorShareControllerFixture($connections, ['consultations' => [5 => ['doctor_share' => 90,'doctor_share_amount' => 10]]]);
        check(str_contains($controller->$operation()['data'][0], 'consultations[5]'), 'Sparse supplied indices are preserved in errors');
        $controller = new DoctorShareControllerFixture($connections, ['consultations' => [['doctor_share' => 1,'doctor_share_amount' => 2]]]);
        $controller->authorized = false;
        check($controller->$operation()['status'] === 401 && $controller->branches === [], 'Authentication remains ahead of consultation validation');
        $controller = new DoctorShareControllerFixture($connections, ['consultations' => ['invalid row']]);
        $response = $controller->$operation();
        check($response['status'] === FAIL && str_contains($response['data'][0], 'consultations[0] must be an array.'), 'Malformed row fails before writes rather than a PHP type error');
    }
    $normalizer = new DoctorShareControllerFixture($connections);
    foreach ([null,''," \t\n"] as $blank) { check($normalizer->normalize($blank) === null, 'Null/blank normalization'); }
    foreach ([0,'0','0.00',90.125,'090.00',-1] as $value) { check($normalizer->normalize($value) === $value, 'Zero and existing numeric representations unchanged'); }

    $rows = [
        ['fee_name' => 'Percentage','amount' => '500','doctor_share' => '90','discount_percentage' => '10'],
        ['fee_name' => 'Amount','amount' => '500','doctor_share_amount' => '125.50','discount_percentage' => '0'],
        ['fee_name' => 'Zero percentage','doctor_share' => 0,'doctor_share_amount' => null],
        ['fee_name' => 'Zero amount','doctor_share' => '', 'doctor_share_amount' => '0'],
        ['fee_name' => 'No shares'],
        ['fee_name' => 'Blank unused amount','doctor_share' => '85','doctor_share_amount' => " \t"],
        ['fee_name' => 'Blank unused percentage','doctor_share' => '  ','doctor_share_amount' => '50','discount_percentage' => ' '],
        ['fee_name' => 'Both empty','doctor_share' => null,'doctor_share_amount' => '', 'discount_percentage' => null],
    ];
    $controller = new DoctorShareControllerFixture($connections, ['requester' => 67,'consultations' => $rows]);
    check($controller->save_consultations('create'), 'Create helpers accept one populated share or neither');
    foreach ($connections as $branch_id => $db) {
        $saved = $db->pdo->query('SELECT * FROM consultation_doctor ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        check(count($saved) === 8, 'Every consultation is mirrored to each existing target database');
        foreach ($saved as $index => $row) {
            foreach (['doctor_share','doctor_share_amount','discount_percentage'] as $field) {
                $expected = $normalizer->normalize($rows[$index][$field] ?? null);
                check($row[$field] === ($expected === null ? null : (string) $expected), 'Mirrored optional values and alternative share nulls are explicit');
            }
            check($row['doctor_id'] === ($branch_id === 0 ? 99 : 1000 + $branch_id), 'Branch-local doctor links preserved');
            check($row['added_by'] === 67 && $row['added_on'] !== null, 'Existing create audit fields preserved');
        }
    }
    $response = (new DoctorShareControllerFixture($connections))->get();
    check($response['status'] === SUCCESS && count($response['data']['data'][0]['consultations']) === 8, 'Real GET decoration includes consultations');
    $first = $response['data']['data'][0]['consultations'][0];
    check(array_key_exists('doctor_share_amount', $first) && array_key_exists('discount_percentage', $first), 'Real model SELECT includes both new fields');
    check($first['consultation_name'] === 'Percentage' && $first['discount_percentage'] === '10', 'GET retains existing consultation decoration and stored discount');
    $allowed = (new \ReflectionClass(\App\Models\Consultation_doctor::class))->getDefaultProperties()['allowedFields'];
    check(in_array('doctor_share_amount', $allowed, true) && in_array('discount_percentage', $allowed, true), 'Model allowed fields include new columns');

    foreach ([
        ['id' => 1,'fee_name' => 'Percentage','doctor_share_amount' => '200','discount_percentage' => '15.50'],
        ['id' => 1,'fee_name' => 'Percentage','doctor_share' => '80','doctor_share_amount' => null],
        ['id' => 1,'fee_name' => 'Percentage'],
    ] as $replacement) {
        // Retain all other selections while changing one selection's share mode.
        $payload_rows = $rows;
        foreach ($payload_rows as $index => &$row) { $row['id'] = $index + 1; }
        unset($row);
        $payload_rows[0] = $replacement;
        $controller = new DoctorShareControllerFixture($connections, ['requester' => 68,'consultations' => $payload_rows]);
        check($controller->save_consultations('update'), 'Update accepts share mode switch or clearing');
        foreach ($connections as $db) {
            $row = $db->pdo->query('SELECT * FROM consultation_doctor WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
            foreach (['doctor_share','doctor_share_amount','discount_percentage'] as $field) {
                $expected = $normalizer->normalize($replacement[$field] ?? null);
                check($row[$field] === ($expected === null ? null : (string) $expected), 'Update clears previous alternative and stores supplied optional values');
            }
            check($row['updated_by'] === 68 && $row['added_by'] === 67, 'Update audit conventions retained');
        }
    }
    $before = fingerprint($connections);
    $controller = new DoctorShareControllerFixture($connections, ['requester' => 68]);
    check($controller->save_consultations('update') && fingerprint($connections) === $before && $controller->branches === [], 'Omitted consultations skip replacement and all model calls');
    $controller = new DoctorShareControllerFixture($connections, ['requester' => 68,'consultations' => [
        ['id' => 1,'fee_name' => 'Percentage','doctor_share_amount' => '100'],
        ['fee_name' => 'Inserted during update','doctor_share_amount' => '75','discount_percentage' => '5'],
    ]]);
    check($controller->save_consultations('update'), 'Update supports retained and newly inserted consultations');
    foreach ($connections as $db) {
        $saved = $db->pdo->query('SELECT * FROM consultation_doctor WHERE is_deleted=0 ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        check(count($saved) === 2 && $saved[0]['id'] === 1 && $saved[1]['doctor_share_amount'] === '75' && $saved[1]['doctor_share'] === null, 'Replacement deletes omitted IDs and inserts amount-mode selection');
        check($saved[1]['discount_percentage'] === '5', 'Update insertion stores discount');
    }
    $before = fingerprint($connections);
    $controller = new DoctorShareControllerFixture($connections, ['consultations' => [
        ['id' => 1,'fee_name' => 'Would update','doctor_share' => 90],
        ['fee_name' => 'Conflict','doctor_share' => 80,'doctor_share_amount' => 100],
    ]]);
    check(!$controller->save_consultations('update') && fingerprint($connections) === $before, 'Invalid replacement never deletes or modifies existing mirrored rows');
    $controller = new DoctorShareControllerFixture($connections, ['requester' => 68,'consultations' => []]);
    check($controller->save_consultations('update'), 'Explicit empty replacement retains existing deletion behavior');
    foreach ($connections as $db) {
        check((int) $db->pdo->query('SELECT COUNT(*) FROM consultation_doctor WHERE is_deleted=0')->fetchColumn() === 0, 'Empty replacement soft-deletes all mirrored selections');
    }
    $selected_connections = connections();
    $controller = new DoctorShareControllerFixture($selected_connections, ['branch_in' => '4,7','requester' => 67,
        'consultations' => [['fee_name' => 'Selected branches','doctor_share_amount' => 0,'discount_percentage' => 0]]]);
    check($controller->save_consultations('create'), 'Existing selected branch synchronization supported');
    foreach ($selected_connections as $branch_id => $db) {
        check((int) $db->pdo->query('SELECT COUNT(*) FROM consultation_doctor')->fetchColumn() === (in_array($branch_id, [0,4,7], true) ? 1 : 0), 'Sync remains limited to master and selected branches');
    }
    echo "PASS: {$checks} isolated Doctors consultation-share checks (in-memory only).\n";
}
