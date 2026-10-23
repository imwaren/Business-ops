<?php

/** Isolated native-header fixture: no framework boot, database, or authentication calls. */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit;
}

$_ENV['CORS_ALLOWED_ORIGINS'] = 'http://cors-fixture.local:3001';
$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/bootstrap') {
    require $root . '/app/Config/BootstrapGuard.php';
    if (isset($_GET['failure'])) {
        bootstrap_fail('cors_fixture', 'Synthetic bootstrap failure.');
    }
    bootstrap_attach_options_cors();
} elseif ($path === '/common') {
    unset($_ENV['CSR_API_KEYS'], $_SERVER['CSR_API_KEYS']);
    putenv('CSR_API_KEYS');
    define('CSR_API_KEY', 'fixture-origin-key');
    require $root . '/app/Common.php';
    if (isset($_GET['legacy_validator'])) {
        header('Content-Type: application/json');
        echo json_encode(['valid' => csr_api_key_is_valid('fixture-origin-key'),
            'invalid' => csr_api_key_is_valid('fixture-invalid-key'),
            'empty' => csr_api_key_is_valid(''), 'missing' => csr_api_key_is_valid(null)]);
        exit;
    }
    $request = new class {
        public function getHeaderLine($name)
        {
            return $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';
        }
    };
    $response = new class {
        public function setHeader($name, $value)
        {
            header($name . ': ' . $value);
        }
    };
    cors_apply_to_response($request, $response);
} else {
    http_response_code(404);
    exit;
}

http_response_code(isset($_GET['unauthorized']) ? 401 : 204);
