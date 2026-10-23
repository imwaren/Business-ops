<?php
/** Private release probe. No endpoint authentication bypass is installed in the application. */
ob_start();
$probe_phase = 'bootstrap';
try {
    $root = $argv[1];
    $environment = $argv[2];
    $before = in_array('--before', $argv, true);
    $domain = ($environment === 'uat' ? 'zuat-' : '') . 'accounting-back.myt-emr.com';
    if (!in_array($environment, ['uat', 'prod'], true) || !str_contains($root, '/' . $domain . '/')) {
        throw new RuntimeException('Application/environment mismatch.');
    }
    chdir($root);
    require_once 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv(getcwd()))->load();
    require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
    ini_set('display_errors', '0');
    $config = config('Database');
    $config->tests = $config->master;
    $config->defaultGroup = 'master';
    helper(['text']);
    $groups = [0 => 'master', 1 => 'mobile', 2 => 'oakridge', 3 => 'mactan',
        4 => 'consolacion', 5 => 'warehouse', 6 => 'pharmacy', 7 => 'mantlewood'];
    $schemas = [0 => 'mytemr_master', 1 => 'mytemr_diagnostic_mobile', 2 => 'mytemr_diagnostic',
        3 => 'mytemr_diagnostic_mactan', 4 => 'mytemr_diagnostic_conso',
        5 => 'mytemr_oakridge_warehouse', 6 => 'mytemr_oakridge_pharmacy', 7 => 'mytemr_diagnostic_mantlewood'];
    $checks = [];
    function receipt_release_check($name, $passed)
    {
        global $checks;
        $checks[$name] = (bool) $passed;
        if (!$passed) {
            throw new RuntimeException($name);
        }
    }
    $probe_phase = 'application_permissions';
    foreach ($groups as $branch_id => $group) {
        $db = db_connect($group);
        receipt_release_check('schema_' . $branch_id,
            $db->getDatabase() === $schemas[$branch_id] . ($environment === 'uat' ? '_uat' : ''));
        $account = $db->query("SELECT SUBSTRING_INDEX(CURRENT_USER(), '@', 1) NOT IN ('root', 'mysql') AS protected_account")->getRowArray();
        receipt_release_check('read_only_protected_account_' . $branch_id, (int) $account['protected_account'] === 1);
        $tables = ['receipt_note', 'receipt_note_item', 'receipt_note_item_expiry_date',
            'branch_item', 'item', 'item_conversion', 'item_cost', 'item_history'];
        if ($branch_id === 0) {
            $tables = array_merge($tables, ['branch_item_transfer', 'branch_item_transfer_item']);
        }
        foreach ($tables as $table) {
            receipt_release_check('select_' . $branch_id . '_' . $table,
                $db->query('SELECT id FROM `' . $table . '` LIMIT 0') !== false);
            if ($before) {
                // EXPLAIN checks effective privileges without executing the statement or its triggers.
                receipt_release_check('update_' . $branch_id . '_' . $table,
                    $db->query('EXPLAIN UPDATE `' . $table . '` SET id=id WHERE id=0') !== false);
                receipt_release_check('insert_' . $branch_id . '_' . $table,
                    $db->query('EXPLAIN INSERT INTO `' . $table . '` (id) VALUES (0)') !== false);
            }
        }
    }
    receipt_release_check('master_schema_constant', MASTER_DB === db_connect('master')->getDatabase());
    if ($before) {
        ob_end_clean();
        echo json_encode(['ok' => true, 'phase' => 'before', 'checks' => count($checks), 'live_writes' => 0]) . "\n";
        exit;
    }
    foreach ($groups as $group) {
        db_connect($group)->query('SET SESSION TRANSACTION READ ONLY');
    }
    foreach ($groups as $branch_id => $group) {
        $columns = db_connect($group)->query("SELECT DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH,
            COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME IN ('receipt_note_item','receipt_note_item_expiry_date','branch_item_transfer_item')
            AND COLUMN_NAME='batch_no'")->getResultArray();
        receipt_release_check('batch_column_count_' . $branch_id, count($columns) === ($branch_id === 0 ? 3 : 2));
        foreach ($columns as $column) {
            receipt_release_check('batch_column_type_' . $branch_id,
                $column['DATA_TYPE'] === 'varchar' && $column['IS_NULLABLE'] === 'YES'
                && (int) $column['CHARACTER_MAXIMUM_LENGTH'] >= 255
                && $column['COLLATION_NAME'] === 'utf8mb4_bin');
        }
    }
    class ReceiptBatchItemsProbe extends App\Controllers\Items
    {
        public function __construct($payload)
        {
            $this->payload = $payload;
            $this->_load_essentials();
        }
        protected function _api_validation() { return true; }
        protected function _create_response($status, $data_response, $skip_response_logging = false)
        {
            return ['status' => $status, 'data' => $data_response];
        }
    }
    class ReceiptBatchNotesProbe extends App\Controllers\Receipt_notes
    {
        public function __construct($payload)
        {
            $this->payload = $payload;
            $this->_load_essentials();
        }
        protected function _api_validation() { return true; }
        protected function _create_response($status, $data_response, $skip_response_logging = false)
        {
            return ['status' => $status, 'data' => $data_response];
        }
    }
    $probe_phase = 'installed_reads';
    $receipt_sizes = [];
    foreach ($groups as $branch_id => $group) {
        if ($branch_id === 0) {
            continue;
        }
        $result = (new ReceiptBatchNotesProbe(['branch_id' => $branch_id, 'page' => 1, 'data_size' => 1]))->get();
        receipt_release_check('receipt_get_' . $branch_id,
            in_array($result['status'], [SUCCESS, FAIL_NOT_FOUND], true));
        $receipt_sizes[$branch_id] = $result['data']['size'] ?? 0;
        foreach ($result['data']['data'] ?? [] as $receipt) {
            foreach ($receipt['items'] as $item) {
                receipt_release_check('receipt_batch_' . $branch_id, array_key_exists('batch_no', $item));
                foreach ($item['expiry_dates'] as $expiry) {
                    receipt_release_check('expiry_batch_' . $branch_id, array_key_exists('batch_no', $expiry));
                }
            }
        }
        $db = db_connect($group);
        $row = $db->query("SELECT COUNT(*) AS size FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='receipt_note_item_expiry_date' AND INDEX_NAME='idx_expiry_batch'")->getRowArray();
        receipt_release_check('expiry_index_' . $branch_id, (int) $row['size'] === 3);
    }
    $master = db_connect('master');
    $model = new App\Models\Branch_item_transfer_item($master);
    $item = $master->query('SELECT item.id FROM item INNER JOIN branch_item ON branch_item.item_id=item.id AND branch_item.is_deleted=0 WHERE item.is_deleted=0 ORDER BY item.id LIMIT 1')->getRowArray();
    $item_id = $item['id'] ?? 2147483647;
    $filters = (new ReceiptBatchItemsProbe([]))->get_item_filters();
    receipt_release_check('item_filter_endpoint', $filters['status'] === SUCCESS);
    receipt_release_check('item_filter_fields', array_keys($filters['data']['data']) === ['brand', 'generic', 'dosage', 'form']);
    foreach ($groups as $branch_id => $group) {
        if ($branch_id === 0) {
            continue;
        }
        $batch_model = new App\Models\Receipt_note_item_expiry_date(db_connect($group));
        foreach (['1900-01-01', '2099-12-31'] as $cutoff) {
            $batches = $batch_model->get_item_batches($item_id, $branch_id, $cutoff);
            receipt_release_check('batch_lookup_' . $branch_id . '_' . $cutoff, is_array($batches));
            foreach ($batches as $batch) {
                receipt_release_check('batch_lookup_string_' . $branch_id, is_string($batch['batch_no']));
            }
        }
        $batch_result = (new ReceiptBatchItemsProbe(['item_id' => $item_id,
            'branch_id_to' => $branch_id, 'date' => '2099-12-31']))->get_item_batches();
        receipt_release_check('batch_endpoint_' . $branch_id, in_array($batch_result['status'], [SUCCESS, FAIL_NOT_FOUND], true));
    }
    foreach ([null, '0', '0012', 'AB123', 'ab123'] as $batch_no) {
        $rows = $model->get_transfer_report($item_id, $batch_no, 1, 2);
        $all_rows = $model->get_transfer_report($item_id, $batch_no, null, null);
        receipt_release_check('report_query_' . ($batch_no ?? 'all'), is_array($rows) && is_array($all_rows));
        receipt_release_check('report_pagination_' . ($batch_no ?? 'all'), $rows === array_slice($all_rows, 0, 2));
        receipt_release_check('report_count_' . ($batch_no ?? 'all'), $model->get_transfer_report_count($item_id, $batch_no) === count($all_rows));
    }
    $all_rows = $model->get_transfer_report(null, null, 1, 2);
    receipt_release_check('report_filter_query', is_array($model->get_transfer_report(null, 'AB123', 1, 2,
        '__batch_release_missing_brand__', null, null, null, 'pharmacy,medical_supply')));
    receipt_release_check('report_filter_count', $model->get_transfer_report_count(null, 'AB123',
        '__batch_release_missing_brand__', null, null, null, 'pharmacy,medical_supply') === 0);
    foreach ($all_rows as $row) {
        receipt_release_check('report_string_batch', is_string($row['batch_no']));
    }
    $result = (new ReceiptBatchItemsProbe(['item_id' => $item_id, 'page' => 1, 'data_size' => 2]))->transfer_report();
    receipt_release_check('report_endpoint', in_array($result['status'], [SUCCESS, FAIL_NOT_FOUND], true));
    receipt_release_check('report_optional_item', in_array((new ReceiptBatchItemsProbe(['page' => 1, 'data_size' => 2]))->transfer_report()['status'], [SUCCESS, FAIL_NOT_FOUND], true));
    receipt_release_check('report_invalid_batch', (new ReceiptBatchItemsProbe(['item_id' => $item_id, 'batch_no' => 'A,B']))->transfer_report()['status'] === FAIL);
    $result = (new ReceiptBatchItemsProbe(['page' => 1, 'data_size' => 1]))->get_transfer();
    receipt_release_check('transfer_get', in_array($result['status'], [SUCCESS, FAIL_NOT_FOUND], true));
    foreach ($result['data']['data'] ?? [] as $transfer) {
        foreach ($transfer['transfer_items'] as $line) {
            receipt_release_check('transfer_batch_field', array_key_exists('batch_no', $line));
        }
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'checks' => count($checks), 'receipt_sizes' => $receipt_sizes,
        'live_writes' => 0, 'http_authentication' => 'not checked; no fresh credentials supplied']) . "\n";
} catch (Throwable $error) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'phase' => $probe_phase, 'exception' => get_class($error),
        'failed_check' => $error instanceof RuntimeException ? $error->getMessage() : 'Read-only probe failed.']) . "\n";
    exit(1);
}
