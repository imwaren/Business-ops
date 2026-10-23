<?php
/** Private CLI checks. Only explicit metadata/SELECT checks may connect to databases. */
if (PHP_SAPI !== 'cli') { exit(1); }
$root = rtrim($argv[1], '/') . '/';
$release = rtrim($argv[2], '/') . '/';
$mode = $argv[3] ?? 'isolated';
ob_start();
$phase = 'bootstrap';
try {
    chdir($root);
    define('FCPATH', $root);
    require $root . 'app/Config/Paths.php';
    $paths = new Config\Paths();
    require $paths->systemDirectory . '/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($root))->load();
    require $paths->systemDirectory . '/Boot.php';
    CodeIgniter\Boot::preload($paths);
    require_once SYSTEMPATH . 'Common.php';
    require_once APPPATH . 'Common.php';
    require_once APPPATH . 'Config/BootstrapGuard.php';
    date_default_timezone_set('Asia/Manila');
    $before = unserialize(file_get_contents($release . 'prod/production.config.private'), ['allowed_classes' => false]);
    $uat = unserialize(file_get_contents($release . 'prod/uat.config.private'), ['allowed_classes' => false]);
    $checks = [];
    $checks['framework'] = CodeIgniter\CodeIgniter::CI_VERSION === '4.7.4';
    $checks['mailer'] = PHPMailer\PHPMailer\PHPMailer::VERSION === '7.0.2';
    $checks['environment'] = ENVIRONMENT === 'production';
    $checks['detail_flag'] = bootstrap_error_details_enabled($root);
    $checks['urls'] = config('App')->baseURL === 'https://accounting-back.myt-emr.com/' && FRONT_END_URL === $before['constants']['FRONT_END_URL'];
    $checks['tls'] = config('App')->forceGlobalSecureRequests && config('Cookie')->secure;
    $checks['encryption_keys'] = hash_equals((string) $before['constants']['ENCRYPTION_KEY'], (string) ENCRYPTION_KEY)
        && hash_equals((string) $before['encryption']['key'], (string) config('Encryption')->key);
    $checks['api_keys'] = api_key_is_valid($before['constants']['API_KEY']) && !api_key_is_valid('invalid-parity-fixture-key');
    $checks['csr_key'] = empty($before['constants']['CSR_API_KEY']) || csr_api_key_is_valid($before['constants']['CSR_API_KEY']);
    $checks['cors_origins'] = cors_allowed_origins() === $uat['origins'];
    $checks['cron_secret'] = strlen(CRON_SECRET) === 64 && cron_secret_is_valid(CRON_SECRET, CRON_SECRET, '') && !cron_secret_is_valid(CRON_SECRET, 'invalid-fixture', '');
    $checks['roles'] = (int) ROLE_ADMIN === (int) ($uat['constants']['ROLE_ADMIN'] ?? 4) && (int) ROLE_IT === (int) ($uat['constants']['ROLE_IT'] ?? 17);
    $checks['password_hash'] = password_verify('ParityFixture!123', app_password_hash('ParityFixture!123'));
    $checks['legacy_password_hash'] = password_verify('ParityFixture!123', password_hash('ParityFixture!123', PASSWORD_BCRYPT));
    $clean = strip_sensitive_user_fields([['id' => 1, 'password' => 'private', 'token' => 'private', 'token_expiry' => 'private']]);
    $checks['sensitive_fields'] = $clean === [['id' => 1]];
    $checks['input_safety'] = sanitize_order_by('id; DROP TABLE fixture') === null && sanitize_printer_name('../fixture') === null;
    $config = new Config\Database();
    $database_checks = [];
    $phase = 'connection_settings';
    foreach ($before['groups'] as $group => $original) {
        $settings = $config->{$group};
        $matches = $settings['DSN'] === '';
        foreach (['hostname', 'username', 'password', 'database', 'port', 'DBDriver', 'charset', 'DBCollat'] as $field) {
            $matches = $matches && (string) $settings[$field] === (string) $original[$field];
        }
        $matches = $matches && !str_contains(strtolower($settings['database']), '_uat');
        if ($mode === 'live') {
            $connection = Config\Database::connect($group, false);
            $row = $connection->query('SELECT DATABASE() AS schema_name')->getRowArray();
            $matches = $matches && $row['schema_name'] === $original['database'];
            $connection->close();
        }
        $database_checks[$group] = $matches;
    }
    $checks['database_groups'] = !in_array(false, $database_checks, true) && count($database_checks) === 15;
    $request = new CodeIgniter\HTTP\IncomingRequest(config('App'), new CodeIgniter\HTTP\URI('https://accounting-back.myt-emr.com/items/get_items_list'), null, new CodeIgniter\HTTP\UserAgent());
    $request->setMethod('OPTIONS');
    $request->setHeader('Origin', 'http://192.168.1.11:3001');
    $response = new CodeIgniter\HTTP\Response(config('App'));
    Config\Services::injectMock('response', $response);
    $filter = new App\Filters\SecurityHeaders();
    $filtered = $filter->before($request);
    $checks['preflight'] = $filtered->getStatusCode() === 204
        && str_contains(strtolower($filtered->getHeaderLine('Access-Control-Allow-Headers')), 'origin-api-key');
    $checks['security_headers'] = $filtered->getHeaderLine('X-Frame-Options') === 'DENY'
        && $filtered->getHeaderLine('X-Content-Type-Options') === 'nosniff'
        && $filtered->getHeaderLine('Content-Security-Policy') !== '';
    $request->setHeader('Origin', 'https://not-allowed-parity.invalid');
    $response = new CodeIgniter\HTTP\Response(config('App'));
    $filter->after($request, $response);
    $checks['denied_origin'] = $response->getHeaderLine('Access-Control-Allow-Origin') === '';
    $payload = bootstrap_error_payload(new RuntimeException("Synthetic private token=private\nSQL Query: SELECT private FROM fixture"), '', $root);
    $checks['error_details'] = array_keys($payload) === ['code','message','error'] && $payload['code'] === 500
        && array_keys($payload['error']) === ['type','message','file','line'] && !str_contains($payload['error']['message'], 'SELECT');
    $checks['print_helper_protected'] = (new ReflectionMethod(App\Controllers\Prints::class, 'printJob'))->isProtected();
    $template = view('emails/forgot_password', ['user'=>(object)['first_name'=>'<script>fixture</script>','last_name'=>'User','username'=>'<script>fixture</script>'],
        'reset_link'=>'https://fixture.invalid/reset?x=" onclick="fixture']);
    $checks['email_escaping'] = !str_contains($template, '<script>fixture</script>') && str_contains($template, '&lt;script&gt;fixture&lt;/script&gt;')
        && !str_contains($template, 'x=" onclick="fixture');
    $phase = 'routes';
    $routes = Config\Services::routes();
    $routes->loadRoutes();
    $checks['legacy_routes'] = config('Routing')->autoRoute && !config('Feature')->autoRoutesImproved;
    $checks['session'] = config('Session')->cookieName === $before['app']['sessionCookieName'] && config('Session')->expiration === (int) $before['app']['sessionExpiration'];
    $ok = !in_array(false, $checks, true);
    ob_end_clean();
    echo json_encode(['ok' => $ok, 'mode' => $mode, 'checks' => $checks, 'database_checks' => $database_checks]), PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'phase' => $phase, 'type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]), PHP_EOL;
    exit(1);
}
