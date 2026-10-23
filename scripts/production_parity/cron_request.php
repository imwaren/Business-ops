<?php
/** Private scheduled-request helper. Header secrets never appear in process arguments. */
if (PHP_SAPI !== 'cli' || count($argv) !== 2) { exit(1); }
$root = '/home/mytemr/web/accounting-back.myt-emr.com/public_html/';
try {
    $url = $argv[1];
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'accounting-back.myt-emr.com'
        || !str_starts_with($parts['path'] ?? '', '/cron/') || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('Invalid scheduled target.');
    }
    require $root . 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($root))->load();
    $secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET');
    if (!is_string($secret) || $secret === '') { throw new RuntimeException('Missing scheduled credentials.'); }
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_HTTPHEADER => ['X-Cron-Secret: ' . $secret], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 3600, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false || $status < 200 || $status >= 300) { throw new RuntimeException('Scheduled request failed.'); }
    exit(0);
} catch (Throwable $error) {
    error_log('Production accounting scheduled request failed; check the daily application/domain logs.');
    exit(1);
}
