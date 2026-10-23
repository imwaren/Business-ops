<?php

/** Private CLI verification only. No routes, database calls, synthetic live logs or failure endpoints. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = '/home/mytemr/web/zuat-accounting-back.myt-emr.com/public_html/';
chdir($root);
require $root . 'app/Config/BootstrapGuard.php';
if (($argv[1] ?? '') === 'handler') {
    define('FCPATH', $root);
    require $root . 'app/Config/Paths.php';
    $paths = new Config\Paths();
    require $paths->systemDirectory . '/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($root))->load();
    require $paths->systemDirectory . '/Boot.php';
    CodeIgniter\Boot::preload($paths);
    require_once SYSTEMPATH . 'Common.php';
    require_once APPPATH . 'Common.php';
    $app = config('App');
    $request = new CodeIgniter\HTTP\IncomingRequest($app, new CodeIgniter\HTTP\URI('https://zuat-accounting-back.myt-emr.com/'), null, new CodeIgniter\HTTP\UserAgent());
    $request->setMethod('GET');
    $request->setHeader('Origin', 'http://192.168.1.11:3001');
    $response = new CodeIgniter\HTTP\Response($app);
    // Invoke only rendering: the framework exception logger is deliberately not called.
    (new App\Libraries\CorsExceptionHandler(config('Exceptions')))->handle(
        new RuntimeException('Synthetic private CLI rendering password=fixture-secret'), $request, $response, 500, 1
    );
    exit(1);
}
$results = [];
try {
    $results['early_flag_enabled'] = bootstrap_error_details_enabled($root);
    $results['daily_manila_filename'] = str_ends_with(bootstrap_log_path($root, new DateTimeImmutable('2026-10-15T16:00:00Z')), '/log-2026-10-16.log');
    $payload = bootstrap_error_payload(new RuntimeException("Duplicate entry 'fixture-value' for key 'name'\nSQL Query: INSERT INTO fixture VALUES('fixture-value')"), '', $root);
    $results['redacted_formatter'] = $payload['code'] === 500 && $payload['message'] === 'something went wrong'
        && !str_contains(json_encode($payload), 'fixture-value') && !str_contains(json_encode($payload), 'INSERT');
    $results['bounded_details'] = array_keys($payload['error']) === ['type', 'message', 'file', 'line'];
    $process = proc_open([PHP_BINARY, __FILE__, 'handler'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit_code = proc_close($process);
    $rendered = json_decode($body, true);
    $results['production_mode_handler'] = $exit_code === 1 && ($rendered['code'] ?? null) === 500
        && ($rendered['error']['type'] ?? null) === 'RuntimeException'
        && ($rendered['message'] ?? null) === 'something went wrong'
        && !str_contains($body, 'fixture-secret') && $stderr === '';
    $ok = !in_array(false, $results, true);
    echo json_encode(['ok' => $ok, 'checks' => $results]) . PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'type' => get_class($error), 'message' => 'Private verification failed.']) . PHP_EOL;
    exit(1);
}
