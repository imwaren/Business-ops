<?php
/** Private deployment probe: application credentials, SELECT/metadata only; no write endpoints. */
$root = $argv[1];
chdir($root);
ob_start();
$probe_phase = 'dotenv';
try {
    require_once 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv(getcwd()))->load();
    $probe_phase = 'framework_bootstrap';
    require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
    ini_set('display_errors', '0');
    $probe_phase = 'database_config';
    $config = config('Database');
    $config->tests = $config->master;
    $config->defaultGroup = 'master';
    helper(['text']);
    if (isset($argv[2]) && !str_starts_with($argv[2], '--')) {
        require $argv[2] . '/app/Models/Branch_item_blindsheet.php';
        require $argv[2] . '/app/Controllers/Items.php';
    }
    $checks = [];
    function release_check(string $name, bool $passed): void
    {
        global $checks;
        $checks[$name] = $passed;
        if (!$passed) { throw new RuntimeException($name); }
    }
    $probe_phase = 'database_connections';
    foreach (App\Models\Branch_item_blindsheet::database_groups() as $branch_id => $group) {
        $db = db_connect($group);
        release_check('schema_' . $branch_id, $db->getDatabase() === $config->$group['database']);
    }
    $master_db = db_connect('master');
    release_check('master_constant', MASTER_DB === $master_db->getDatabase());
    release_check('model_autoload', class_exists(App\Models\Branch_item_blindsheet::class));
    release_check('normal_key_helper', function_exists('api_key_is_valid'));
    release_check('model_db_error', method_exists(App\Models\Branch_item::class, 'db_error'));
    class BlindsheetReleaseProbe extends App\Controllers\Items
    {
        public function __construct(array $payload)
        {
            $this->payload = $payload;
            $this->_load_essentials();
        }
        protected function _api_validation() { return true; }
        protected function _create_response($status, $data_response, $skip_response_logging = false)
        {
            return ['status' => $status, 'data' => $data_response];
        }
        public function readiness(): void
        {
            $model = new App\Models\Branch_item_blindsheet(db_connect('master'));
            $model->check_readiness(App\Models\Branch_item_blindsheet::configured_schemas());
        }
    }
    $probe_phase = 'controller_readiness';
    $key_controller = new BlindsheetReleaseProbe([]);
    release_check('csr_empty_key_rejected', (new ReflectionMethod(App\Controllers\Items::class, '_items_api_key_is_valid'))->invoke($key_controller, '') === false);
    release_check('csr_invalid_key_rejected', (new ReflectionMethod(App\Controllers\Items::class, '_items_api_key_is_valid'))->invoke($key_controller, 'blindsheet-release-invalid-key') === false);
    if (in_array('--privileges-only', $argv, true)) {
        $model = new App\Models\Branch_item_blindsheet($master_db);
        $model->check_privileges(
            ['branch_item_blindsheet', 'branch_item', 'item_cost', 'item', 'item_conversion', 'item_history'],
            App\Models\Branch_item_blindsheet::configured_schemas()
        );
        ob_end_clean();
        echo json_encode(['ok' => true, 'effective_privileges' => 'verified across all eight connections', 'live_writes' => 0]) . "\n";
        exit;
    }
    $full_readiness = !in_array('--held', $argv, true);
    if ($full_readiness) {
        (new BlindsheetReleaseProbe([]))->readiness();
        release_check('application_credentials_readiness', true);
    }
    // MariaDB classifies EXPLAIN INSERT/UPDATE as write-type for a READ ONLY
    // session, although neither executes DML. Readiness only uses EXPLAIN.
    foreach (App\Models\Branch_item_blindsheet::database_groups() as $group) {
        db_connect($group)->query('SET SESSION TRANSACTION READ ONLY');
    }
    $counts = [];
    foreach ([1,2,3,4,5,6,7] as $branch_id) {
        $result = (new BlindsheetReleaseProbe(['branch_id' => $branch_id, 'page' => 1, 'data_size' => 1]))->get_blindsheet();
        release_check('branch_' . $branch_id . '_list', in_array($result['status'], [SUCCESS,FAIL_NOT_FOUND], true));
        if ($result['status'] === SUCCESS) {
            release_check('branch_' . $branch_id . '_shape', isset($result['data']['size'], $result['data']['data']));
            $counts[$branch_id] = $result['data']['size'];
        } else {
            release_check('branch_' . $branch_id . '_empty', $result['data'] === ['No blindsheets found.']);
            $counts[$branch_id] = 0;
        }
    }
    $invalid = (new BlindsheetReleaseProbe([]))->get_blindsheet();
    release_check('required_branch', $invalid['status'] === FAIL);
    $invalid = (new BlindsheetReleaseProbe(['branch_id' => 2, 'blindsheet_id' => []]))->get_blindsheet();
    release_check('invalid_filter', $invalid['status'] === FAIL);
    foreach (['get_blindsheet','blindsheet','blindsheet_change_status'] as $method) {
        release_check('endpoint_' . $method, (new ReflectionMethod(App\Controllers\Items::class, $method))->getDeclaringClass()->getName() === App\Controllers\Items::class);
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'full_readiness' => $full_readiness, 'checks' => count($checks), 'branch_counts' => $counts, 'live_post_requests' => 0, 'checks_detail' => $checks]) . "\n";
} catch (Throwable $error) {
    ob_end_clean();
    // Keep credentials, SQL values, and record names out of the output.
    $trace = array_map(static fn($frame) => array_intersect_key($frame, array_flip(['class', 'function', 'file', 'line'])), $error->getTrace());
    echo json_encode(['ok' => false, 'exception' => get_class($error), 'phase' => $probe_phase, 'trace' => $trace, 'failed_check' => $error instanceof RuntimeException ? $error->getMessage() : 'application bootstrap/readiness failed']) . "\n";
    exit(1);
}
