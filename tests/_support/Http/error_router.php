<?php

/** Loopback-only synthetic failures. Never deployed; no application controllers or DB calls. */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit;
}

$repository = dirname(__DIR__, 3);
$test_root = getenv('ERROR_TEST_ROOT');
$_ENV['ERROR_RESPONSE_DETAILS'] = $_GET['enabled'] ?? 'true';
$_ENV['CORS_ALLOWED_ORIGINS'] = 'http://errors-fixture.local:3001';
$_ENV['API_KEY'] = 'fixture-private-api-key';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/front_controller') {
    $front_root = $test_root . '/front';
    mkdir($front_root . '/app/Config', 0775, true);
    copy($repository . '/index.php', $front_root . '/index.php');
    copy($repository . '/app/Config/BootstrapGuard.php', $front_root . '/app/Config/BootstrapGuard.php');
    file_put_contents($front_root . '/app/Config/Paths.php', "<?php throw new RuntimeException('Synthetic Paths initialization failure.');");
    require $front_root . '/index.php';
    exit;
}
require $repository . '/app/Config/BootstrapGuard.php';
$exception = new RuntimeException("Synthetic failure password=fixture-password token=fixture-token fixture-private-api-key\nSQL Query: SELECT patient_name FROM patient WHERE id=123");

if ($path === '/bootstrap') {
    bootstrap_fail('boot_exception', 'Generic bootstrap failure.', $test_root, $exception);
}
if ($path === '/readiness') {
    bootstrap_fail('vendor_missing', 'Required vendor/autoload.php is missing.', $test_root);
}
if ($path === '/invalid_utf8') {
    bootstrap_fail('boot_exception', 'Failure.', $test_root, new RuntimeException("Invalid byte \xFF"));
}
if ($path === '/framework' || $path === '/framework404') {
    $_ENV['CI_ENVIRONMENT'] = $_SERVER['CI_ENVIRONMENT'] = 'production';
    chdir($repository);
    define('FCPATH', $repository . '/');
    require $repository . '/app/Config/Paths.php';
    $paths = new Config\Paths();
    require $paths->systemDirectory . '/Boot.php';
    CodeIgniter\Boot::preload($paths);
    require_once SYSTEMPATH . 'Common.php';
    require_once APPPATH . 'Common.php';
    ini_set('display_errors', '0');
    date_default_timezone_set('Asia/Manila');
    $app = config('App');
    $app->baseURL = 'http://localhost/';
    $request = new CodeIgniter\HTTP\IncomingRequest($app, new CodeIgniter\HTTP\URI('http://localhost' . $path), null, new CodeIgniter\HTTP\UserAgent());
    $request->setMethod('GET');
    $request->setHeader('Origin', 'http://errors-fixture.local:3001');
    $request->setHeader('Accept', 'application/json');
    Config\Services::injectMock('request', $request);
    Config\Services::injectMock('response', new CodeIgniter\HTTP\Response($app));
    config('Logger')->handlers[CodeIgniter\Log\Handlers\FileHandler::class]['path'] = $test_root . '/writable/logs/';
    if (!is_dir($test_root . '/writable/logs')) {
        mkdir($test_root . '/writable/logs', 0775, true);
    }
    if ($path === '/framework404') {
        $exception = CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }
    service('exceptions')->exceptionHandler($exception);
    exit;
}

http_response_code(404);
