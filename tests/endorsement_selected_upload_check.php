<?php
/** Isolated local fixtures. Patient mirrors, DICOM uploads and email are mocked. */
chdir(dirname(__DIR__));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
if (getenv('ENDORSEMENT_UPLOAD_CONTROLLER')) {
    require getenv('ENDORSEMENT_UPLOAD_CONTROLLER');
}
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
});

use App\Controllers\Endorsements;
use App\Models\Endorsement_patient;
use App\Models\Endorsement_service;
use CodeIgniter\Database\MySQLi\Connection;

class_alias(\CodeIgniter\Database\MySQLi\Builder::class, 'UploadCommitFailureBuilder');
class_alias(\CodeIgniter\Database\MySQLi\Result::class, 'UploadCommitFailureResult');
class UploadCommitFailureConnection extends Connection
{
    public function transCommit(): bool { return false; }
}

class UploadSelectionModel extends Endorsement_patient
{
    public $failure = null;
    public $worklist_ids = [];
    public $batch_calls = 0;
    public function insert($data = null, bool $returnID = true)
    {
        if ($this->failure === 'insert') { return false; }
        return parent::insert($data, $returnID);
    }
    public function insert_batch($data)
    {
        $this->batch_calls++;
        if ($this->failure === 'insert') { return false; }
        return parent::insert_batch($data);
    }
    public function update($where = null, $values = null): bool
    {
        if ($this->failure === 'update') { return false; }
        return parent::update($where, $values);
    }
    public function get_selected_upload_rows($endorsement_id, array $selected_rows)
    {
        if ($this->failure === 'resolve') { return false; }
        if ($this->failure === 'missing') { return []; }
        return parent::get_selected_upload_rows($endorsement_id, $selected_rows);
    }
    public function get_details_for_worklist($endorsement_id = null, $patient_ids = null, $page = null, $data_size = null, $endorsement_patient_ids = null)
    {
        $this->worklist_ids = $endorsement_patient_ids;
        return $this->failure === 'worklist' ? false : [];
    }
    public function error(): string { return 'Isolated fixture failure'; }
}

class UploadSelectionController extends Endorsements
{
    public $fixture_model;
    public $email_calls = 0;
    public $mirror_calls = 0;
    public $mail_failure = false;
    public $demographic_writes = 0;
    public function __construct(Connection $db, array $patients, $failure = null, $from_file = false)
    {
        $this->db = $db;
        $this->payload = ['requester' => 172, 'endorsement_id' => 41, 'patients' => $patients];
        $this->endorsement_patient = $this->fixture_model = new UploadSelectionModel($db);
        $this->fixture_model->failure = $failure;
        $this->endorsement_service = new Endorsement_service($db);
        $this->endorsement = new class {
            public function select(...$args) { return (object) ['id' => 41, 'contract_date_from' => '2026-10-20']; }
        };
        $this->endorsement_service_package_detail = new class {
            public function get_details(...$args) { throw new RuntimeException('Unselected package add-ons must not be read'); }
        };
        $this->patient_master = new class {
            public function select($fields, $where, $limit = null)
            { return in_array((int) $where['id'], [1,2], true) ? (object) ['id' => $where['id']] : null; }
            public function get_patients_by_identities($identities)
            {
                $rows = [];
                foreach ($identities as $identity) {
                    $rows[] = (object) array_merge($identity, ['id' => $identity['first_name'] === 'FixtureTwo' ? 2 : 1]);
                }
                return $rows;
            }
        };
        $mirror = new class($this) {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function get_existing_master_patient_ids($ids) { $this->owner->mirror_calls++; return array_fill_keys($ids, true); }
            public function get_existing_master_ids($ids) { $this->owner->mirror_calls++; return array_fill_keys($ids, true); }
        };
        foreach ([10, 12, 13, 14, 17] as $branch_id) { $this->patientBranchModels['emr_' . $branch_id] = $mirror; }
        foreach ([1, 2, 3, 4, 7] as $branch_id) { $this->patientBranchModels['diagnostic_' . $branch_id] = $mirror; }
        $file = null;
        if ($from_file) {
            $path = tempnam(sys_get_temp_dir(), 'selection_fixture_');
            file_put_contents($path, json_encode($patients));
            $file = new class($path) {
                private $path;
                public function __construct($path) { $this->path = $path; }
                public function isValid() { return true; }
                public function hasMoved() { return false; }
                public function getClientExtension() { return 'txt'; }
                public function getClientMimeType() { return 'text/plain'; }
                public function getTempName() { return $this->path; }
                public function __destruct() { unlink($this->path); }
            };
            unset($this->payload['patients']);
        }
        $this->request = new class($file) {
            private $file;
            public function __construct($file) { $this->file = $file; }
            public function getFile($key) { return $this->file; }
        };
    }
    protected function _create_response($status, $data_response, $skip_response_logging = false)
    { return ['status' => $status, 'data' => $data_response]; }
    protected function send_email_notif($subject, $endorsement_id, $status = null)
    { $this->email_calls++; return !$this->mail_failure; }
    public function set_update_target($patient_id, $endorsement_patient_id): void
    {
        $this->payload['patient_id'] = $patient_id;
        $this->payload['endorsement_patient_id'] = $endorsement_patient_id;
        $this->request = new class($this->payload) {
            private $payload;
            public function __construct($payload) { $this->payload = $payload; }
            public function getVar($key) { return $this->payload[$key] ?? null; }
        };
    }
    protected function _attempt_update_patient($patient)
    {
        $this->demographic_writes++;
        return (bool) $this->db->query('UPDATE patient SET first_name=? WHERE id=?', ['Synthetic updated', $patient->id]);
    }
}

$socket = '/private/tmp/company_cleanup_db/mysql.sock';
$pdo = new PDO('mysql:unix_socket=' . $socket, 'myt_waren', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS endorsement_upload_fixture');
$pdo->exec('USE endorsement_upload_fixture');
$pdo->exec('DROP TABLE IF EXISTS endorsement_patient,endorsement_service,patient,lab_test,package,package_detail');
$pdo->exec("CREATE TABLE endorsement_patient (
    id INT AUTO_INCREMENT PRIMARY KEY, endorsement_id INT, endorsement_service_id INT NOT NULL, patient_id INT, type VARCHAR(100), type_id INT,
    branch_id VARCHAR(100), charge_to VARCHAR(30), charge_to_ids VARCHAR(100) NULL,
    with_consultation INT DEFAULT 0, type_price DECIMAL(13,4), mark_up DECIMAL(13,4), total_amount DECIMAL(13,4),
    package_ids VARCHAR(100) NULL, is_add_on INT DEFAULT 0, mobile_van_id VARCHAR(100), is_availed INT DEFAULT 0,
    added_by INT, added_on DATETIME, updated_by INT NULL, updated_on DATETIME NULL, is_deleted INT DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec('CREATE TABLE patient(id INT PRIMARY KEY,sex VARCHAR(20),first_name BLOB,last_name BLOB,middle_name BLOB,suffix BLOB,birthdate BLOB,civil_status BLOB,email BLOB) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE lab_test(id INT PRIMARY KEY,name VARCHAR(100),price DECIMAL(13,4),category_id INT,is_deleted INT DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE package(id INT PRIMARY KEY,name VARCHAR(100),is_deleted INT DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE package_detail(id INT AUTO_INCREMENT PRIMARY KEY,package_id INT,lab_test_id INT,is_deleted INT DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE endorsement_service(id INT PRIMARY KEY,endorsement_id INT,type VARCHAR(100),type_id INT,is_deleted INT DEFAULT 0) ENGINE=InnoDB');
$pdo->exec("INSERT INTO endorsement_service VALUES(331,41,'package',2,0),(332,41,'package',2,0),(333,41,'package',2,0),(334,41,'lab_test',20,0),(335,41,'lab_test',856,0),(336,41,'lab_test',2,0),(337,41,'lab_test',463,0),(338,41,'lab_test',524,0),(339,41,'lab_test',20,1),(340,42,'lab_test',20,0)");
$pdo->exec("INSERT INTO patient(id,sex) VALUES(1,'MALE'),(2,'FEMALE')");
$pdo->exec("INSERT INTO lab_test(id,name,price,category_id) VALUES(20,'Selected A',2400,43),(856,'Selected B',1050,43),(2,'Unselected addon A',100,43),(463,'Unselected addon B',200,43),(478,'Package component',100,43),(54,'Non-worklist component',100,1),(524,'Restricted test',100,43)");
$pdo->exec("INSERT INTO package VALUES(2,'Selected package',0)");
$pdo->exec('INSERT INTO package_detail(package_id,lab_test_id) VALUES(2,478),(2,54)');
function fixture_db(bool $fail_commit = false): Connection
{
    global $socket;
    $class = $fail_commit ? UploadCommitFailureConnection::class : Connection::class;
    $db = new $class(['hostname' => $socket, 'username' => 'myt_waren', 'password' => '', 'database' => 'endorsement_upload_fixture', 'DBDriver' => 'MySQLi', 'DBDebug' => false, 'charset' => 'utf8mb4']);
    $db->initialize();
    return $db;
}
function selection($type, $type_id, $charge_to = 'company', $patient = 'FixtureOne'): array
{
    $service_ids = ['package' => [2 => 331], 'lab_test' => [20 => 334, 856 => 335, 2 => 336, 463 => 337, 524 => 338]];
    return ['endorsement_service_id' => $service_ids[$type][$type_id], 'patient_first_name' => $patient, 'patient_last_name' => 'Synthetic', 'patient_middle_name' => '',
        'patient_birthdate' => '1990-01-01', 'patient_sex' => 'MALE', 'patient_civil_status' => 'SINGLE', 'patient_email' => null,
        'type' => $type, 'type_id' => (string) $type_id, 'branch_id' => $type === 'package' ? '2,3,4,7' : '1,2,3,4,7',
        'charge_to' => $charge_to, 'charge_to_ids' => $charge_to === 'company' ? '3731' : null,
        'mobile_van_id' => '1,2,3', 'with_consultation' => 0, 'type_price' => 100, 'mark_up' => 0, 'total_amount' => 100];
}
$checks = 0;
function check($condition, string $label): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($label); }
    $checks++;
}
function rows(): array
{
    global $pdo;
    return $pdo->query('SELECT * FROM endorsement_patient ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}
function reset_rows(): void { global $pdo; $pdo->exec('DELETE FROM endorsement_patient'); }
$patients = [selection('package', 2), selection('lab_test', 20), selection('lab_test', 856, 'personal')];
if (isset($argv[1])) {
    $patients = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    // Exercise the supplied selection data without sending/storing real demographics.
    $synthetic = selection('package', 2);
    foreach ($patients as &$patient) {
        // This old file predates the field: supply explicit fixture service IDs.
        $patient['endorsement_service_id'] = selection($patient['type'], $patient['type_id'])['endorsement_service_id'];
        foreach ($synthetic as $field => $value) {
            if (strpos($field, 'patient_') === 0) { $patient[$field] = $value; }
        }
    }
    unset($patient);
}
$controller = new UploadSelectionController(fixture_db(), $patients, null, true);
$response = $controller->upload_patients();
check($response['status'] === SUCCESS, 'Multipart JSON array succeeds');
check(count(rows()) === 3, 'Exactly three explicit selections saved');
check(array_column(rows(), 'type_id') === [2,20,856], 'No unrelated variant add-ons inserted');
check(array_sum(array_column(rows(), 'is_add_on')) === 0, 'No automatically generated add-on rows');
check($controller->fixture_model->batch_calls === 1, 'Package and standalone selections saved in one batch');
check($controller->mirror_calls === 10, 'Existing five EMR and five diagnostic mirror paths retained');
check($controller->email_calls === 1, 'Existing first-upload notification retained');
check(count($controller->fixture_model->worklist_ids) === 3, 'Worklist receives selected row IDs');
check($response['data'] === ['endorsement_id' => 41, 'message' => 'endorsement patients uploaded successfully.'], 'Response unchanged');
check(rows()[2]['charge_to'] === 'personal' && rows()[2]['charge_to_ids'] === null, 'Personal payer and null ID retained');
foreach ($patients as $index => $patient) {
    check((float) rows()[$index]['total_amount'] === (float) $patient['total_amount'], 'Submitted amount retained for selection ' . $index);
    check((float) rows()[$index]['type_price'] === (float) $patient['type_price'], 'Submitted price retained for selection ' . $index);
}
$model = new Endorsement_patient(fixture_db());
$worklist = $model->get_details_for_worklist(41, null, null, null, $controller->fixture_model->worklist_ids);
check(array_map('intval', array_column($worklist, 'type_id')) === [478,20,856], 'Selected package expands standard worklist component only');
check(array_map('intval', array_column($worklist, 'endorsement_service_id')) === [331,334,335], 'Worklist lookup retains selected service IDs');
check($model->get_details_for_worklist(41, null, null, null, []) === [], 'Empty selection never queries all rows');
$before_ids = array_column(rows(), 'id');
$controller = new UploadSelectionController(fixture_db(), $patients);
check($controller->upload_patients()['status'] === SUCCESS, 'Repeated upload succeeds');
check(array_column(rows(), 'id') === $before_ids, 'Repeated upload does not insert duplicates');
check($controller->fixture_model->batch_calls === 0 && $controller->email_calls === 0, 'Repeat does not insert or re-notify first upload');
check(array_map('intval', $controller->fixture_model->worklist_ids) === $before_ids, 'Repeat retains selected row IDs');
$pdo->exec("INSERT INTO endorsement_patient(endorsement_id,endorsement_service_id,patient_id,type,type_id,branch_id,charge_to,charge_to_ids,mobile_van_id,is_add_on) VALUES(41,0,1,'lab_test',2,'2,3,4,7','company','3731','9',1),(41,0,1,'lab_test',463,'2,3,4,7','company','3731','9',1),(41,0,1,'lab_test',524,'1,2,3,4,7','company','3731','9',0)");
$unselected = array_slice(rows(), 3);
$controller = new UploadSelectionController(fixture_db(), [array_merge($patients[1], ['mobile_van_id' => '7'])]);
check($controller->upload_patients()['status'] === SUCCESS, 'Single existing selection upload succeeds');
check(array_slice(rows(), 3) === $unselected, 'Older selections and historical addons unchanged');
check(rows()[0]['mobile_van_id'] === '1,2,3' && rows()[1]['mobile_van_id'] === '7' && rows()[2]['mobile_van_id'] === '1,2,3', 'Only exact matched row updated');
check(array_map('intval', $controller->fixture_model->worklist_ids) === [$before_ids[1]], 'Older services excluded from selected worklist IDs');
check(array_map('intval', array_column($model->get_details_for_worklist(41, null, null, null, [$before_ids[1]]), 'type_id')) === [20], 'Bound row filter excludes older worklists');
$explicit_addon = selection('lab_test', 2);
$explicit_addon['branch_id'] = '2,3,4,7';
$controller = new UploadSelectionController(fixture_db(), [$explicit_addon]);
check($controller->upload_patients()['status'] === SUCCESS, 'Explicit lab selection allowed even with same historical addon');
check(count(rows()) === 7 && rows()[6]['is_add_on'] === 0, 'Explicit lab remains distinct from generated history');
check(count($controller->fixture_model->worklist_ids) === 1 && (int) $controller->fixture_model->worklist_ids[0] === rows()[6]['id'], 'Historical addon not reused as explicit selection');
reset_rows();
$controller = new UploadSelectionController(fixture_db(), array_merge($patients, $patients));
check($controller->upload_patients()['status'] === SUCCESS && count(rows()) === 3, 'Duplicate objects within file deduplicated');
reset_rows();
$controller = new UploadSelectionController(fixture_db(), array_merge($patients, [selection('lab_test',20,'company','FixtureTwo'),selection('lab_test',20,'personal')]));
check($controller->upload_patients()['status'] === SUCCESS && count(rows()) === 5, 'Multiple patients and distinct payers remain separate');
check(count($controller->fixture_model->worklist_ids) === 5, 'All explicit multi-patient selections resolved');
foreach (['insert','resolve','missing'] as $failure) {
    reset_rows();
    $controller = new UploadSelectionController(fixture_db(), $patients, $failure);
    check($controller->upload_patients()['status'] === FAIL, $failure . ' returns failure');
    check(rows() === [], $failure . ' rolls back batch');
    check($controller->email_calls === 0 && $controller->fixture_model->worklist_ids === [], $failure . ' skips external delivery');
}
reset_rows();
$controller = new UploadSelectionController(fixture_db(true), $patients);
check($controller->upload_patients()['status'] === FAIL && rows() === [], 'Failed commit rolls back selected rows');
check($controller->email_calls === 0 && $controller->fixture_model->worklist_ids === [], 'Failed commit does not dispatch notifications/worklists');
reset_rows();
$controller = new UploadSelectionController(fixture_db(), [selection('lab_test',524)]);
check($controller->upload_patients()['status'] === SUCCESS && rows() === [], 'Existing male restricted-test skip retained');
check($controller->fixture_model->worklist_ids === [], 'Skipped selections do not expand worklists');
reset_rows();
$controller = new UploadSelectionController(fixture_db(), $patients, 'worklist');
$controller->mail_failure = true;
$response = $controller->upload_patients();
check($response['status'] === SUCCESS && count(rows()) === 3, 'Postcommit external failures preserve selections');
check($response['data']['warnings'] === ['worklist','email'], 'Existing external failure warnings retained');
$before = rows();
$controller = new UploadSelectionController(fixture_db(), [array_merge($patients[1], ['mobile_van_id' => '7'])], 'update');
check($controller->upload_patients()['status'] === FAIL && rows() === $before, 'Failed matched update leaves existing rows unchanged');
$controller = new UploadSelectionController(fixture_db(), [array_merge($patients[1], ['mobile_van_id' => '7']), selection('lab_test',463)], 'insert');
check($controller->upload_patients()['status'] === FAIL && rows() === $before, 'Mixed existing update and failed insert roll back together');
$controller = new UploadSelectionController(fixture_db(), [array_merge($patients[1], ['mobile_van_id' => '7']), selection('lab_test',463)], 'resolve');
check($controller->upload_patients()['status'] === FAIL && rows() === $before, 'Failed resolution rolls back existing updates and new selections together');
check($model->get_selected_upload_rows(41, [array_merge(rows()[0], ['type' => "package' OR 1=1 -- "])]) === [], 'Selection conditions use safe binds');
check($model->get_selected_upload_rows(999, rows()) === [], 'Other endorsements excluded');
check($model->get_selected_upload_rows(41, []) === [], 'Empty resolver result is explicit');
check(count($model->get_selected_upload_rows(41, array_fill(0, 101, rows()[0]))) === 2, 'Resolver chunks large uploads without malformed SQL');
$pdo->exec('UPDATE endorsement_patient SET is_deleted=1 WHERE id=' . rows()[0]['id']);
check($model->get_selected_upload_rows(41, [$before[0]]) === [], 'Deleted selection excluded');
check($model->get_details_for_worklist(41, null, null, null, [$before[0]['id']]) === [], 'Deleted worklist selection excluded');

// Service validation must happen before identity creation, mirrors or delivery.
reset_rows();
foreach ([null, '', 0, '0', -1, '1,2', 1.5, true, [], '331x', 2147483648, 999, 339, 340] as $invalid_id) {
    $invalid = array_merge($patients[0], ['endorsement_service_id' => $invalid_id]);
    $controller = new UploadSelectionController(fixture_db(), [$patients[1], $invalid], null, true);
    $response = $controller->upload_patients();
    check($response['status'] === FAIL && rows() === [], 'Invalid service ID rejected before save: ' . json_encode($invalid_id));
    check($controller->mirror_calls === 0 && $controller->email_calls === 0 && $controller->fixture_model->worklist_ids === [], 'Invalid service skips fan-out and delivery');
}
$invalid = $patients[0];
unset($invalid['endorsement_service_id']);
$controller = new UploadSelectionController(fixture_db(), [$invalid], null, true);
check($controller->upload_patients()['status'] === FAIL && $controller->mirror_calls === 0, 'Missing multipart service ID rejected');
foreach ([null, 'invalid', 3] as $malformed) {
    $controller = new UploadSelectionController(fixture_db(), [$malformed]);
    check($controller->upload_patients()['status'] === FAIL && rows() === [] && $controller->mirror_calls === 0, 'Malformed selection object rejected before writes');
}
foreach (['type' => 'lab_test', 'type_id' => 20] as $field => $value) {
    $controller = new UploadSelectionController(fixture_db(), [array_merge($patients[0], [$field => $value])]);
    check($controller->upload_patients()['status'] === FAIL && rows() === [], 'Conflicting ' . $field . ' rejected');
}
$minimal_type = $patients[0];
unset($minimal_type['type'], $minimal_type['type_id']);
$controller = new UploadSelectionController(fixture_db(), [$minimal_type]);
check($controller->upload_patients()['status'] === SUCCESS && rows()[0]['type'] === 'package' && rows()[0]['type_id'] === 2, 'Missing type fields populated from explicit service');
check(rows()[0]['endorsement_service_id'] === 331, 'Explicit service ID persisted');

// A service ID distinguishes variants that otherwise have identical keys.
reset_rows();
$variant = array_merge($patients[0], ['endorsement_service_id' => 332]);
$controller = new UploadSelectionController(fixture_db(), [$patients[0], $variant]);
check($controller->upload_patients()['status'] === SUCCESS && count(rows()) === 2, 'Identical package metadata with two service IDs remains distinct');
check(array_column(rows(), 'endorsement_service_id') === [331,332], 'Variant IDs stored separately');
$variant_ids = array_column(rows(), 'id');
$controller = new UploadSelectionController(fixture_db(), [$patients[0], $variant]);
check($controller->upload_patients()['status'] === SUCCESS && array_column(rows(), 'id') === $variant_ids, 'Two-variant re-upload remains idempotent');
check(array_map('intval', $controller->fixture_model->worklist_ids) === $variant_ids, 'Both selected variant row IDs reach worklist');
check(count($model->get_selected_upload_rows(41, [rows()[0]])) === 1, 'Batch resolver distinguishes exact service ID');

// Explicit IDs safely adopt one uniquely matching zero-ID legacy row.
reset_rows();
$controller = new UploadSelectionController(fixture_db(), $patients);
$controller->upload_patients();
$legacy_ids = array_column(rows(), 'id');
$legacy_creation = array_column(rows(), 'added_on');
$pdo->exec('UPDATE endorsement_patient SET endorsement_service_id=0');
$controller = new UploadSelectionController(fixture_db(), $patients);
check($controller->upload_patients()['status'] === SUCCESS && array_column(rows(), 'id') === $legacy_ids, 'Unique legacy rows linked without new rows');
check(array_column(rows(), 'endorsement_service_id') === [331,334,335], 'Legacy rows receive supplied service IDs');
check($controller->fixture_model->batch_calls === 0 && array_column(rows(), 'added_on') === $legacy_creation, 'Legacy linkage retains creation audit and avoids inserts');
check(array_column(rows(), 'updated_by') === [172,172,172], 'Legacy linkage records requester');
$pdo->exec('UPDATE endorsement_patient SET endorsement_service_id=0 WHERE id=' . $legacy_ids[1]);
$controller = new UploadSelectionController(fixture_db(), $patients);
check($controller->upload_patients()['status'] === SUCCESS && array_column(rows(), 'id') === $legacy_ids, 'Mixed linked and unlinked selections resolve without duplication');

// Prefer a linked row; do not modify additional legacy history.
$legacy_copy = rows()[0];
unset($legacy_copy['id']);
$legacy_copy['endorsement_service_id'] = 0;
$fixture_writer = new Endorsement_patient(fixture_db());
$extra_legacy_id = $fixture_writer->insert($legacy_copy);
$legacy_snapshot = rows();
$controller = new UploadSelectionController(fixture_db(), [$patients[0]]);
check($controller->upload_patients()['status'] === SUCCESS && (int) $controller->fixture_model->worklist_ids[0] === $legacy_ids[0], 'Linked row takes precedence over zero-ID history');
check(rows()[3] === $legacy_snapshot[3], 'Extra legacy row unchanged when linked row exists');

// Ambiguous adoption is rejected before branch mirror writes.
$pdo->exec('UPDATE endorsement_patient SET endorsement_service_id=0 WHERE id=' . $legacy_ids[0]);
$before_ambiguous = rows();
$controller = new UploadSelectionController(fixture_db(), [$patients[0]]);
$response = $controller->upload_patients();
check($response['status'] === FAIL && strpos($response['data'][0], 'Ambiguous') !== false, 'Multiple matching legacy rows rejected clearly');
check(rows() === $before_ambiguous && $controller->mirror_calls === 0, 'Ambiguous legacy rows preserved before fan-out');
$pdo->exec('DELETE FROM endorsement_patient WHERE id=' . $extra_legacy_id);
$before_ambiguous = rows();
$controller = new UploadSelectionController(fixture_db(), [$patients[0], $variant]);
check($controller->upload_patients()['status'] === FAIL && rows() === $before_ambiguous && $controller->mirror_calls === 0, 'Two variants cannot adopt a single legacy row');
$controller = new UploadSelectionController(fixture_db(), [$patients[0]], 'update');
check($controller->upload_patients()['status'] === FAIL && rows() === $before_ambiguous, 'Failed legacy linkage preserves original zero ID');

// Update validation, scoped replacement and transaction rollback.
reset_rows();
$controller = new UploadSelectionController(fixture_db(), $patients);
$controller->upload_patients();
$update_rows = rows();
$anchor = $update_rows[0]['id'];
$pdo->exec('UPDATE patient SET first_name=NULL');
$retained = array_merge($patients[0], ['id' => $anchor, 'endorsement_service_id' => 332]);
$new_selection = selection('lab_test',463);
$controller = new UploadSelectionController(fixture_db(), [$retained, $new_selection]);
$controller->set_update_target(1, $anchor);
$response = $controller->update_patient();
check($response['status'] === SUCCESS, 'Patient update accepts retained and new service IDs');
$updated = rows();
check($updated[0]['id'] === $anchor && $updated[0]['endorsement_service_id'] === 332, 'Update replaces service link on retained row');
check($updated[1]['is_deleted'] === 1 && $updated[2]['is_deleted'] === 1 && $updated[3]['endorsement_service_id'] === 337, 'Update soft-deletes omissions and inserts explicit new selection');
check($controller->demographic_writes === 1 && $controller->email_calls === 0 && $controller->mirror_calls === 0, 'Valid update exercises mocked demographics with no external delivery');

$snapshot = rows();
foreach ([[], ['endorsement_service_id' => 0], ['endorsement_service_id' => 340], ['type_id' => 856]] as $changes) {
    $invalid = array_merge($retained, $changes);
    if (!$changes) { unset($invalid['endorsement_service_id']); }
    $controller = new UploadSelectionController(fixture_db(), [$invalid]);
    $controller->set_update_target(1, $anchor);
    check($controller->update_patient()['status'] === FAIL && rows() === $snapshot && $controller->demographic_writes === 0, 'Invalid update service rejected before all writes');
}
// Foreign rows from another patient/endorsement must not be modified.
$foreign = array_merge($snapshot[0], ['endorsement_id' => 42]);
unset($foreign['id']);
$foreign_id = $fixture_writer->insert($foreign);
$foreign['endorsement_id'] = 41;
$foreign['patient_id'] = 2;
$other_patient_id = $fixture_writer->insert($foreign);
$foreign['patient_id'] = 1;
$foreign['is_deleted'] = 1;
$deleted_id = $fixture_writer->insert($foreign);
$snapshot = rows();
foreach ([$foreign_id, $other_patient_id, $deleted_id, 99999, '1,2'] as $foreign_row_id) {
    $invalid = array_merge($retained, ['id' => $foreign_row_id]);
    $controller = new UploadSelectionController(fixture_db(), [$invalid]);
    $controller->set_update_target(1, $anchor);
    check($controller->update_patient()['status'] === FAIL && rows() === $snapshot && $controller->demographic_writes === 0, 'Foreign/deleted/invalid row ID rejected before demographics');
}
$controller = new UploadSelectionController(fixture_db(), [$retained]);
$controller->set_update_target(1, $foreign_id);
check($controller->update_patient()['status'] === FAIL_NOT_FOUND && $controller->demographic_writes === 0, 'Top-level patient-row anchor constrained by endorsement');
$controller = new UploadSelectionController(fixture_db(), [$retained, $retained]);
$controller->set_update_target(1, $anchor);
check($controller->update_patient()['status'] === FAIL && rows() === $snapshot, 'Duplicate retained row ID rejected');

foreach (['insert','update','commit'] as $failure) {
    $pdo->exec('UPDATE patient SET first_name=NULL');
    $controller = new UploadSelectionController(fixture_db($failure === 'commit'), [$retained, selection('lab_test',20)], $failure === 'commit' ? null : $failure);
    $controller->set_update_target(1, $anchor);
    check($controller->update_patient()['status'] === FAIL && rows() === $snapshot, 'Update ' . $failure . ' failure rolls back service replacement');
    check($pdo->query('SELECT first_name FROM patient WHERE id=1')->fetchColumn() === null, 'Update ' . $failure . ' failure rolls back mocked master demographics');
}
$pdo->exec('UPDATE endorsement_patient SET endorsement_service_id=0 WHERE id=' . $anchor);
$without_type = $retained;
unset($without_type['type'], $without_type['type_id']);
$controller = new UploadSelectionController(fixture_db(), [$without_type]);
$controller->set_update_target(1, $anchor);
check($controller->update_patient()['status'] === SUCCESS && rows()[0]['endorsement_service_id'] === 332 && rows()[0]['type'] === 'package', 'Update explicitly links legacy row and fills type from service');
$controller = new UploadSelectionController(fixture_db(), []);
$controller->set_update_target(1, $anchor);
check($controller->update_patient()['status'] === SUCCESS, 'Empty update selection list retains full replacement behavior');
check(count(array_filter(rows(), static function ($row) { return $row['endorsement_id'] === 41 && $row['patient_id'] === 1 && $row['is_deleted'] === 0; })) === 0, 'Empty selection list soft-deletes only target patient selections');
check($pdo->query('SELECT is_deleted FROM endorsement_patient WHERE id=' . $foreign_id)->fetchColumn() === 0, 'Other endorsement remains active after replacement');
check($pdo->query('SELECT is_deleted FROM endorsement_patient WHERE id=' . $other_patient_id)->fetchColumn() === 0, 'Other patient remains active after replacement');

echo 'PASS: ' . $checks . " isolated endorsement upload/update checks\n";
