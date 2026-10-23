<?php

use CodeIgniter\Boot;
use Config\Paths;

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . 'app/Config/BootstrapGuard.php';

$rootPath = FCPATH;

try {
    $minPhpVersion = '8.1';
    bootstrap_ensure_php($minPhpVersion, static function (string $code, string $message): void {
        bootstrap_fail($code, $message, FCPATH);
    });

    $pathsFile = FCPATH . 'app/Config/Paths.php';
    if (! is_file($pathsFile)) {
        bootstrap_fail('paths_missing', 'app/Config/Paths.php was not found.', FCPATH);
    }

    require $pathsFile;

    $paths    = new Paths();
    $rootPath = bootstrap_safe_root($paths->appDirectory);

    bootstrap_ensure_vendor($rootPath, static function (string $code, string $message) use ($rootPath): void {
        bootstrap_fail($code, $message, $rootPath);
    });
    bootstrap_ensure_writable($rootPath, static function (string $code, string $message) use ($rootPath): void {
        bootstrap_fail($code, $message, $rootPath);
    });

    $systemDirectory = rtrim($paths->systemDirectory, '\\/ ');
    $dotEnv          = $systemDirectory . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'DotEnv.php';
    if (! is_file($dotEnv)) {
        bootstrap_fail('vendor_missing', 'Bootstrap failed: run composer install --no-dev in the document root.', $rootPath);
    }

    require_once $dotEnv;
    (new CodeIgniter\Config\DotEnv($rootPath))->load();

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method === 'OPTIONS') {
        bootstrap_attach_options_cors();
    }

    require $paths->systemDirectory . '/Boot.php';

    exit(Boot::bootWeb($paths));
} catch (Throwable $e) {
    bootstrap_fail(
        'boot_exception',
        'Application bootstrap failed. See the daily log in writable/logs.',
        $rootPath,
        $e
    );
}
