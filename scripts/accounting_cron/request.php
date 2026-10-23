<?php

/** Private CLI HTTP runner. Installed outside public_html with environment-specific defaults. */
class Accounting_cron_request
{
    public static function run($root, $host, $url, $transport = null, $secret_loader = null)
    {
        $started = microtime(true);
        $result = ['ok' => false, 'http_status' => null, 'curl_errno' => null, 'reason' => 'invalid_target'];
        $lock = null;
        $job = 'invalid_target';
        try {
            $parts = parse_url($url);
            if (!is_array($parts) || preg_match('/[\x00-\x20\x7f]/', $url)
                || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $host
                || (isset($parts['port']) && $parts['port'] !== 443)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || !preg_match('~^/cron/([a-z][a-z0-9_]*)$~D', $parts['path'] ?? '', $matches)) {
                throw new RuntimeException('invalid_target');
            }
            parse_str($parts['query'] ?? '', $query);
            if (isset($query['cron_secret'])) {
                throw new RuntimeException('query_credentials_forbidden');
            }
            $job = $matches[1];
            $result['reason'] = 'lock_unavailable';
            $lock = @fopen($root . 'writable/cache/accounting_cron_' . $job . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('lock_unavailable');
            }
            $result['reason'] = 'credentials_unavailable';
            $secret = $secret_loader ? $secret_loader($root) : self::load_secret($root);
            if (!is_string($secret) || trim($secret) === '' || preg_match('/[\r\n]/', $secret)) {
                throw new RuntimeException('credentials_unavailable');
            }
            $result['reason'] = 'transport_failed';
            $response = ($transport ?? [self::class, 'request'])($url, ['X-Cron-Secret: ' . $secret]);
            $result['http_status'] = (int) $response['status'];
            $result['curl_errno'] = (int) $response['errno'];
            if ($response['body'] === false || $result['curl_errno'] !== 0) {
                throw new RuntimeException('transport_failed');
            }
            $result['reason'] = 'http_failed';
            if ($result['http_status'] < 200 || $result['http_status'] >= 300) {
                throw new RuntimeException('http_failed');
            }
            $decoded = json_decode($response['body'], true);
            $result['reason'] = 'response_failed';
            if (is_array($decoded) && (int) ($decoded['code'] ?? $decoded['status'] ?? 200) >= 400) {
                throw new RuntimeException('response_failed');
            }
            if ($job === 'snapshot_item_inventory') {
                if (!is_array($decoded) || !isset($decoded['snapshot_date'], $decoded['data'])
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $decoded['snapshot_date']) || !is_array($decoded['data'])) {
                    throw new RuntimeException('response_failed');
                }
                $counts = [];
                foreach (range(1, 7) as $branch_id) {
                    $count = $decoded['data'][$branch_id] ?? null;
                    if (!is_int($count) || $count < 0) {
                        throw new RuntimeException('response_failed');
                    }
                    $counts[$branch_id] = $count;
                }
                $result['snapshot_date'] = $decoded['snapshot_date'];
                $result['branch_counts'] = $counts;
            }
            $result['ok'] = true;
            $result['reason'] = 'completed';
        } catch (Throwable $error) {
            // Exceptions and response bodies can contain credentials or database values; do not emit them.
        } finally {
            $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            self::write_log($root, $job, $result);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        return $result;
    }

    public static function load_secret($root)
    {
        $file = $root . 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
        if (!is_file($file)) {
            return null;
        }
        require_once $file;
        (new CodeIgniter\Config\DotEnv($root))->load();
        return $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET');
    }

    private static function request($url, $headers)
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 3600, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($handle);
        $response = ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'errno' => curl_errno($handle), 'body' => $body];
        curl_close($handle);
        return $response;
    }

    private static function write_log($root, $job, $result)
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $level = $result['ok'] ? 'INFO' : 'ERROR';
        $entry = $level . ' - ' . $now->format('Y-m-d H:i:s') . ' --> Accounting cron '
            . json_encode(['job' => $job] + $result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $file = $root . 'writable/logs/log-' . $now->format('Y-m-d') . '.log';
        if (@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) === false) {
            error_log('Accounting cron ' . $job . ': ' . $result['reason'] . '; daily log unavailable.');
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (count($argv) !== 2) {
        exit(1);
    }
    $result = Accounting_cron_request::run('__ACCOUNTING_ROOT__', '__ACCOUNTING_HOST__', $argv[1]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
