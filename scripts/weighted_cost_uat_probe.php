<?php
/** SELECT/metadata-only probe using the selected deployed application credentials. */
ob_start();
try {
    $environment = 'uat';
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--environment=')) {
            $environment = substr($argument, strlen('--environment='));
        }
    }
    if (!in_array($environment, ['uat', 'prod'], true)) {
        throw new RuntimeException('Invalid probe environment.');
    }
    $host = $environment === 'uat' ? 'zuat-accounting-back.myt-emr.com' : 'accounting-back.myt-emr.com';
    $root = $argv[1] ?? '/home/mytemr/web/' . $host . '/public_html';
    if (!str_contains($root, '/' . $host . '/')) {
        throw new RuntimeException('Application root does not match the selected environment.');
    }
    chdir($root);
    require_once 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv(getcwd()))->load();
    require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
    ini_set('display_errors', '0');
    $config = config('Database');
    $config->tests = $config->master;
    $config->defaultGroup = 'master';
    $results = [];
    $schemas = [1 => 'mytemr_diagnostic_mobile', 2 => 'mytemr_diagnostic', 3 => 'mytemr_diagnostic_mactan',
        4 => 'mytemr_diagnostic_conso', 7 => 'mytemr_diagnostic_mantlewood',
        5 => 'mytemr_oakridge_warehouse', 6 => 'mytemr_oakridge_pharmacy'];
    foreach ([1 => 'mobile', 2 => 'oakridge', 3 => 'mactan', 4 => 'consolacion',
              7 => 'mantlewood', 5 => 'warehouse', 6 => 'pharmacy'] as $branch_id => $group) {
        $db = db_connect($group);
        if ($db->getDatabase() !== $schemas[$branch_id] . ($environment === 'uat' ? '_uat' : '')) {
            throw new RuntimeException('Database does not match the selected environment.');
        }
        $grants = $db->query('SHOW GRANTS')->getResultArray();
        $grant_text = implode("\n", array_map(static fn($row) => implode(' ', $row), $grants));
        $schema = $db->getDatabase();
        $required = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'EXECUTE'];
        $allowed = [];
        foreach (explode("\n", $grant_text) as $grant) {
            if (!preg_match('/^GRANT (.+?) ON (.+?) TO /', $grant, $matches)) {
                continue;
            }
            $target = str_replace(['`', '\\_'], ['', '_'], $matches[2]);
            if ($target !== '*.*' && $target !== $schema . '.*') {
                continue;
            }
            $allowed = array_merge($allowed, str_contains($matches[1], 'ALL PRIVILEGES')
                ? $required : array_map('trim', explode(',', $matches[1])));
        }
        $permission_check = 'direct_grants';
        if (array_diff($required, $allowed)) {
            // Check effective access without executing any DML. Direct SHOW GRANTS
            // output alone is insufficient for this older production runtime.
            $permission_check = 'nonexecuting_explain';
            $statements = [
                'EXPLAIN SELECT id FROM branch_item WHERE id=0',
                "EXPLAIN INSERT INTO branch_item(item_id,branch_id,beggining_qty,current_qty,unit,latest_activity,added_by,added_on) VALUES(0," . $branch_id . ",0,0,0,'',0,NOW())",
                'EXPLAIN UPDATE branch_item SET current_qty=current_qty WHERE id=0',
                'EXPLAIN DELETE FROM branch_item WHERE id=0',
            ];
            foreach ($statements as $statement) {
                if (!$db->query($statement)) {
                    throw new RuntimeException('Effective inventory privileges need review for branch ' . $branch_id);
                }
            }
            if (!in_array($branch_id, [5, 6], true) || !in_array('--before', $argv, true)) {
                $row = $db->query('SELECT gl_branch_id() AS branch_id')->getRowArray();
                if ((int) $row['branch_id'] !== $branch_id) {
                    throw new RuntimeException('Routine execution check failed.');
                }
            }
        }
        $db->query('SET SESSION TRANSACTION READ ONLY');
        $result = ['schema' => $schema, 'permissions' => true, 'permission_check' => $permission_check];
        if (!in_array('--before', $argv, true)) {
            $row = $db->query('SELECT gl_branch_id() AS branch_id')->getRowArray();
            if ((int) $row['branch_id'] !== $branch_id) {
                throw new RuntimeException('Branch identity mismatch.');
            }
            $row = $db->query("SELECT COUNT(*) AS size FROM general_ledger WHERE source='item_history' AND is_deleted=0")->getRowArray();
            $result['active_inventory_gl_rows'] = (int) $row['size'];
            if (in_array($branch_id, [5, 6], true)) {
                $db->query('SELECT COUNT(*) AS size FROM accounts_payable_voucher_entry')->getRowArray();
                $row = $db->query('SELECT gl_transaction_context(0) IS NULL AS missing_link_ok')->getRowArray();
                if ((int) $row['missing_link_ok'] !== 1) {
                    throw new RuntimeException('Missing-link helper failed.');
                }
            }
            $result['functions_and_reads'] = true;
        }
        $results[$branch_id] = $result;
    }
    ob_end_clean();
    echo json_encode(['ok' => true, 'environment' => $environment, 'schemas' => $results, 'live_writes' => 0]) . "\n";
} catch (Throwable $error) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error_class' => get_class($error),
        'message' => $error instanceof RuntimeException ? $error->getMessage() : 'Application read-only probe failed.']) . "\n";
    exit(1);
}
