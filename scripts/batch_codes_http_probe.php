<?php
/** Private read-only deployment probe. Never prints credentials or returned records. */
ob_start();
try {
    $root = $argv[1];
    $environment = $argv[2];
    if (!in_array($environment, ['uat', 'prod'], true)) {
        throw new RuntimeException('Invalid environment.');
    }
    $domain = ($environment === 'uat' ? 'zuat-' : '') . 'accounting-back.myt-emr.com';
    if (!str_contains($root, '/' . $domain . '/')) {
        throw new RuntimeException('Invalid application path.');
    }
    chdir($root);
    require_once 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv(getcwd()))->load();
    require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
    $config = config('Database');
    $config->tests = $config->master;
    $config->defaultGroup = 'master';
    $db = db_connect('master');
    $db->query('SET SESSION TRANSACTION READ ONLY');
    $requester = $db->query("SELECT id,token FROM user WHERE id=? AND is_deleted=0
        AND token IS NOT NULL AND token<>'' AND token_expiry>NOW()", [67])->getRowArray();
    $api_keys = array_values(array_filter(array_map('trim', explode(',', (string) myt_env('API_KEYS', API_KEY)))));
    $authenticated = $requester && $api_keys;
    $item = $db->query('SELECT id FROM item WHERE is_deleted=0 ORDER BY id LIMIT 1')->getRowArray();
    $item_id = $item['id'] ?? 2147483647;
    $checks = [
        'filters' => ['get_item_filters', [], [200]],
        'report' => ['transfer_report', ['page' => 1, 'data_size' => 1], [200, 404]],
        'report_batch' => ['transfer_report', ['batch_no' => 'AB123', 'page' => 1, 'data_size' => 1], [200, 404]],
        'batches' => ['get_item_batches', ['item_id' => $item_id, 'branch_id_to' => 2, 'date' => '2099-12-31'], [200, 404]],
        'invalid_batch' => ['transfer_report', ['batch_no' => 'A,B'], [400]],
        'invalid_date' => ['get_item_batches', ['item_id' => $item_id, 'branch_id_to' => 2, 'date' => '2026-02-30'], [400]],
    ];
    $results = [];
    $ok = true;
    if ($authenticated) {
        foreach ($checks as $name => [$method, $params, $expected]) {
            $params += ['requester' => $requester['id'], 'token' => $requester['token']];
            $curl = curl_init('https://' . $domain . '/items/' . $method . '?' . http_build_query($params));
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
                CURLOPT_HTTPHEADER => ['API-KEY: ' . $api_keys[0], 'Accept: application/json']]);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $decoded = is_string($body) ? json_decode($body, true) : null;
            $passed = in_array($status, $expected, true) && is_array($decoded);
            $results[$name] = ['status' => $status, 'json' => is_array($decoded), 'passed' => $passed];
            $ok = $ok && $passed;
            curl_close($curl);
        }
    }
    ob_end_clean();
    echo json_encode(['environment' => $environment, 'authenticated_credentials_available' => (bool) $authenticated,
        'ok' => $ok, 'checks' => $results, 'business_writes' => 0,
        'limitation' => $authenticated ? null : 'Known requester has no active token; authenticated HTTP checks unavailable.']) . "\n";
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'exception' => get_class($error), 'message' => 'Private HTTP probe failed; no record/credential output.']) . "\n";
    exit(1);
}
