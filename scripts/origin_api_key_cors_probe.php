<?php

/** Run privately over CLI. HTTP GET/OPTIONS only; emit status/counts, never keys or item data. */
ob_start();
$probe_phase = 'bootstrap';
try {
    $environment = $argv[1];
    if (!in_array($environment, ['uat', 'prod'], true)) {
        throw new RuntimeException('Invalid environment.');
    }
    $domain = ($environment === 'uat' ? 'zuat-' : '') . 'accounting-back.myt-emr.com';
    $root = '/home/mytemr/web/' . $domain . '/public_html/';
    chdir($root);
    require $root . 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($root))->load();
    require $root . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
    $probe_phase = 'configured_keys';
    $configured_value = static function ($name, $fallback = '') {
        if (function_exists('myt_env')) {
            return myt_env($name, $fallback);
        }
        return $_ENV[$name] ?? $_SERVER[$name] ?? (getenv($name) !== false ? getenv($name) : $fallback);
    };
    $api_keys = array_values(array_filter(array_map('trim', explode(',', (string) $configured_value('API_KEYS',
        $configured_value('API_KEY', defined('API_KEY') ? constant('API_KEY') : ''))))));
    $origin_keys = array_values(array_filter(array_map('trim', explode(',', (string) $configured_value('CSR_API_KEYS',
        $configured_value('CSR_API_KEY', defined('CSR_API_KEY') ? constant('CSR_API_KEY') : ''))))));
    $origin = $environment === 'uat' ? 'http://192.168.1.11:3001' : 'https://accounting-front.myt-emr.com';
    $results = [];
    $cases = ['invalid_normal_key' => ['invalid-cors-fixture-key', 'invalid-cors-origin-key', 401]];
    if ($api_keys) {
        $cases['invalid_origin_key'] = [$api_keys[0], 'invalid-cors-origin-key', 401];
        $cases['ordinary_auth_without_origin_key'] = [$api_keys[0], null, 401];
    }
    if ($api_keys && $origin_keys) {
        $cases['configured_keys'] = [$api_keys[0], $origin_keys[0], 200];
    }
    foreach ($cases as $name => [$api_key, $origin_key, $expected_status]) {
        $probe_phase = 'http_' . $name;
        $headers = ['Accept: application/json', 'Origin: ' . $origin, 'API-KEY: ' . $api_key];
        if ($origin_key !== null) {
            $headers[] = 'Origin-API-Key: ' . $origin_key;
        }
        $handle = curl_init('https://' . $domain . '/items/get_items_list?kind=pharmacy&branch_id=2&page=1&data_size=1&requester=10&token=cors-readonly-invalid-token');
        $response_headers = [];
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$response_headers) {
                if (str_contains($line, ':')) {
                    [$header_name, $value] = explode(':', $line, 2);
                    $response_headers[strtolower(trim($header_name))] = trim($value);
                }
                return strlen($line);
            }]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $allow_origin = $response_headers['access-control-allow-origin'] ?? '';
        $allowed = array_map('trim', explode(',', strtolower($response_headers['access-control-allow-headers'] ?? '')));
        $cors_ok = in_array($allow_origin, [$origin, '*'], true) && in_array('origin-api-key', $allowed, true);
        $results[$name] = ['status' => $status, 'expected_status' => $expected_status, 'cors' => $cors_ok,
            'json' => is_array($decoded), 'matches' => $status === $expected_status && $cors_ok && is_array($decoded)];
        if ($name === 'configured_keys' && $status === 200) {
            $results[$name]['size'] = $decoded['size'] ?? null;
            $results[$name]['returned_rows'] = isset($decoded['data']) && is_array($decoded['data']) ? count($decoded['data']) : null;
        }
        unset($decoded, $body);
    }
    $passed = !in_array(false, array_column($results, 'matches'), true);
    ob_end_clean();
    echo json_encode(['ok' => $passed, 'environment' => $environment, 'cases' => $results,
        'configured_keys_available' => (bool) ($api_keys && $origin_keys),
        'requests' => 'GET only; normal API audit logging preserved; no business writes']) . "\n";
    exit($passed ? 0 : 1);
} catch (Throwable $error) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error_class' => get_class($error), 'phase' => $probe_phase,
        'file' => basename($error->getFile()), 'line' => $error->getLine(),
        'message' => 'Private read-only HTTP probe failed.']) . "\n";
    exit(1);
}
