<?php
/** Private read-only verification, with optional private snapshot-row backup. No job invocation. */
ob_start();
try {
    [$script, $root, $folder, $mode] = $argv;
    $root = rtrim($root, '/') . '/';
    chdir($root);
    require $root . 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($root))->load();
    require $root . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
    date_default_timezone_set('Asia/Manila');
    $uat = str_contains($root, '/zuat-accounting-back.');
    $groups = [1 => 'mobile', 2 => 'oakridge', 3 => 'mactan', 4 => 'consolacion', 5 => 'warehouse', 6 => 'pharmacy', 7 => 'mantlewood'];
    $date = date('Y-m-d');
    $month = date('Y-m-01');
    $checks = ['secret_configured' => is_string(CRON_SECRET) && CRON_SECRET !== '', 'schema_mapping' => true];
    foreach ($groups + [0 => 'master'] as $branch => $group) {
        $schema = db_connect($group)->getDatabase();
        if (str_ends_with($schema, '_uat') !== $uat) {
            throw new RuntimeException('Database environment mismatch.');
        }
    }
    if (!$checks['secret_configured']) { throw new RuntimeException('Missing cron credential.'); }
    if ($mode === 'auth') {
        $host = $uat ? 'zuat-accounting-back.myt-emr.com' : 'accounting-back.myt-emr.com';
        $statuses = [];
        $missing_method = false;
        foreach (['missing' => null, 'invalid' => 'invalid-cron-verification', 'valid' => CRON_SECRET] as $name => $secret) {
            $handle = curl_init('https://' . $host . '/cron/accounting_cron_verification_unimplemented');
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 40,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $secret === null ? [] : ['X-Cron-Secret: ' . $secret]]);
            $body = curl_exec($handle);
            if ($name === 'valid') {
                $decoded = json_decode($body ?: '', true);
                $missing_method = ($decoded['error']['type'] ?? '') === 'CodeIgniter\\Exceptions\\PageNotFoundException';
            }
            $statuses[$name] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
        }
        file_put_contents($folder . '/auth.statuses.json', json_encode($statuses));
        if ($statuses['missing'] !== 401 || $statuses['invalid'] !== 401
            || !($statuses['valid'] === 404 || ($statuses['valid'] === 500 && $missing_method))) { throw new RuntimeException('Cron authorization status mismatch.'); }
        $checks['authenticated_missing_route_exception'] = $missing_method;
        $checks['existing_404_rendered_as_500'] = $statuses['valid'] === 500;
        $checks['auth_statuses'] = $statuses;
    } elseif ($mode === 'preflight') {
        require $folder . '/Report.stage.php';
        $db = db_connect('report');
        $proxy = new class($db) {
            private $connection;
            public $queries = 0;
            public function __construct($connection) { $this->connection = $connection; }
            public function query($sql) {
                if (!preg_match('/^INSERT\s+INTO\s/i', $sql)) { throw new RuntimeException('Unexpected query.'); }
                $query = $this->connection->query('EXPLAIN ' . $sql);
                if (!$query) { throw new RuntimeException('Query explanation failed.'); }
                $this->queries++;
                return $query;
            }
        };
        $report = new App\Models\Report($proxy);
        foreach (['mobile', 'oakridge', 'mactan', 'consolacion'] as $branch) {
            foreach (['daily_branch_sales_report_', 'patient_tat_report_'] as $prefix) {
                if (!$report->{$prefix . $branch}()) { throw new RuntimeException('Report method failed.'); }
            }
        }
        $checks['explained_report_queries'] = $proxy->queries;
    } else {
        $record = [];
        foreach ($groups + [0 => 'master'] as $branch => $group) {
            $db = db_connect($group);
            $tables = $branch === 0 ? ['item_inventory_monthly'] : ['item_inventory_daily', 'item_inventory_monthly'];
            foreach ($tables as $table) {
                $column = $table === 'item_inventory_daily' ? 'snapshot_date' : 'snapshot_month';
                $target = $column === 'snapshot_date' ? $date : $month;
                $rows = $db->query('SELECT * FROM ' . $table . ' WHERE ' . $column . ' = ? AND branch_id = ? ORDER BY id', [$target, $branch])->getResultArray();
                if ($branch === 0) {
                    $rows = $db->query('SELECT * FROM ' . $table . ' WHERE ' . $column . ' = ? AND branch_id IN (1,2,3,4,5,6,7) ORDER BY id', [$target])->getResultArray();
                }
                $hash = hash_init('sha256');
                $history = $db->query('SELECT * FROM ' . $table . ' WHERE ' . $column . ' <> ? ORDER BY id', [$target]);
                while ($row = $history->getUnbufferedRow('array')) { hash_update($hash, json_encode($row) . "\n"); }
                $history->freeResult();
                $record[$branch][$table] = ['rows' => $rows, 'history_hash' => hash_final($hash)];
            }
        }
        if ($mode === 'backup_snapshot') {
            file_put_contents($folder . '/snapshot.before.private.json', json_encode(['date' => $date, 'month' => $month, 'records' => $record], JSON_THROW_ON_ERROR));
            chmod($folder . '/snapshot.before.private.json', 0600);
            $checks['backed_up_schemas'] = count($record);
        } elseif ($mode === 'verify_snapshot') {
            $before = json_decode(file_get_contents($folder . '/snapshot.before.private.json'), true);
            $run = json_decode(file_get_contents($folder . '/snapshot.run.json'), true);
            if ($before['date'] !== $date || !$run['ok'] || $run['snapshot_date'] !== $date) { throw new RuntimeException('Snapshot date or response mismatch.'); }
            $summary = [];
            foreach ($record as $branch => $tables) {
                foreach ($tables as $table => $data) {
                    if ($data['history_hash'] !== $before['records'][$branch][$table]['history_hash']) { throw new RuntimeException('Out-of-date-range history changed.'); }
                }
                if ($branch === 0) { continue; }
                $rows = $tables['item_inventory_daily']['rows'];
                $ids = array_column($rows, 'item_id');
                $source_rows = (new App\Models\Item(db_connect($groups[$branch])))->get_inventory_report($branch) ?: [];
                $source_ids = array_map('intval', array_column($source_rows, 'item_id'));
                $unique_source_ids = array_values(array_unique($source_ids));
                $daily_ids = array_map('intval', $ids);
                sort($unique_source_ids);
                sort($daily_ids);
                // The existing snapshot counts input rows; duplicate source items upsert the same daily key.
                if (count($ids) !== count(array_unique($ids)) || $daily_ids !== $unique_source_ids
                    || count($source_rows) !== $run['branch_counts'][$branch]) { throw new RuntimeException('Snapshot count or uniqueness mismatch.'); }
                $totals = (new App\Models\Item_inventory_monthly(db_connect($groups[$branch])))->totals_from_daily($branch, $month);
                $monthly = $tables['item_inventory_monthly']['rows'];
                $master = array_values(array_filter($record[0]['item_inventory_monthly']['rows'], static function ($row) use ($branch) { return (int) $row['branch_id'] === $branch; }));
                if (count($monthly) !== 1 || count($master) !== 1) { throw new RuntimeException('Monthly row count mismatch.'); }
                foreach (['current_qty', 'ending_cost'] as $field) {
                    $expected = number_format((float) $totals[$field], 4, '.', '');
                    if (number_format((float) $monthly[0][$field], 4, '.', '') !== $expected
                        || number_format((float) $master[0][$field], 4, '.', '') !== $expected) { throw new RuntimeException('Monthly totals mismatch.'); }
                }
                $summary[$branch] = ['date' => $date, 'rows' => count($rows), 'processed_source_rows' => count($source_rows),
                    'duplicate_source_items' => count($source_ids) - count($unique_source_ids), 'duplicate_daily_keys' => 0, 'monthly_matches' => true];
            }
            $checks['snapshot_branches'] = $summary;
            $checks['historical_snapshots_unchanged'] = true;
        } else { throw new RuntimeException('Unknown probe mode.'); }
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    ob_end_clean();
    @file_put_contents(($folder ?? '/tmp') . '/probe_error.private.log', (string) $error, FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => false, 'type' => get_class($error), 'line' => $error->getLine()]) . PHP_EOL;
    exit(1);
}
