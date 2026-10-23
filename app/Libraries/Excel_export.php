<?php

namespace App\Libraries;

use RuntimeException;
use Throwable;

/**
 * Private response snapshots. Workers never receive request credentials or query reports.
 */
class Excel_export
{
    public const SOURCES = [
        'Depreciation_entries' => ['get_asset_depreciation_report_excel' => 'get_asset_depreciation_report'],
        'Billings' => ['get_mobile_payment_excel' => 'get_mobile_payment'],
        'Cash_deposits' => ['get_excel' => 'get', 'get_report_excel' => 'get_report'],
        'Account_types' => ['get_excel' => 'get'],
        'Check_deposits' => ['get_excel' => 'get'],
        'Credit_memos' => ['get_excel' => 'get'],
        'Debit_memos' => ['get_excel' => 'get'],
        'Facilities' => ['get_excel' => 'get'],
        'Non_cash_deposits' => ['get_excel' => 'get', 'get_report_excel' => 'get_report'],
        'Reports' => [
            'get_sales_report_excel' => 'get_sales_report',
            'get_fda_dashboard_excel' => 'get_fda_dashboard',
            'get_payment_terms_excel' => 'get_payment_terms',
            'get_sales_tracker_excel' => 'get_sales_tracker',
            'sawt_report_excel' => 'sawt_report',
            'uip_hmo_corporate_excel' => 'uip_hmo_corporate',
            'uip_lost_excel' => 'uip_lost',
            'get_ppl_membership_corporate_excel' => 'get_ppl_membership_corporate',
        ],
    ];

    protected $directory;
    protected $base_url;
    protected $application_root;
    protected $namespace;
    private const RETENTION = 86400;
    private const START_TIMEOUT = 60;
    private const RUN_TIMEOUT = 900;

    public function __construct(?string $directory = null, ?string $base_url = null)
    {
        $this->base_url = rtrim($base_url ?? (string) config('App')->baseURL, '/') . '/';
        $url = parse_url($this->base_url);
        if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new RuntimeException('Invalid application base URL.');
        }
        $root = realpath(ROOTPATH) ?: ROOTPATH;
        $this->application_root = rtrim($root, '/');
        $this->namespace = substr(hash('sha256', $root . '|' . $this->base_url . '|' . ENVIRONMENT), 0, 20);
        $configured = $directory ?? (string) myt_env('EXCEL_EXPORT_DIR', '');
        $base = $configured !== '' ? $configured : '/tmp/cpci_excel_exports';
        if ($base[0] !== '/' || str_contains($base, "\0") || in_array('..', explode('/', $base), true)) {
            throw new RuntimeException('Invalid export directory.');
        }
        $this->directory = rtrim($base, '/') . '/' . $this->namespace;
        // Reject public storage before writing any response data.
        $resolved_base = realpath($base);
        $candidate = $resolved_base !== false ? $resolved_base . '/' . $this->namespace : $this->directory;
        foreach ([$root, realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: $root] as $public_root) {
            if ($candidate === rtrim($public_root, '/') || str_starts_with($candidate, rtrim($public_root, '/') . '/')) {
                throw new RuntimeException('Export storage must be outside the web root.');
            }
        }
        if (is_link($base) || is_link($this->directory)) {
            throw new RuntimeException('Export storage must not be a symbolic link.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Export storage is unavailable.');
        }
        $actual = realpath($this->directory);
        foreach ([$root, realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: $root] as $public_root) {
            if ($actual === rtrim($public_root, '/') || str_starts_with($actual, rtrim($public_root, '/') . '/')) {
                throw new RuntimeException('Export storage must be outside the web root.');
            }
        }
        if (function_exists('posix_geteuid') && fileowner($this->directory) !== posix_geteuid()) {
            throw new RuntimeException('Export storage belongs to a different user.');
        }
        if (!chmod($this->directory, 0700)) {
            throw new RuntimeException('Export storage permissions could not be restricted.');
        }
    }

    public static function base_method(string $controller, string $method): ?string
    {
        return self::SOURCES[$controller][$method] ?? null;
    }

    public function queue(string $controller, string $method, string $owner, $snapshot): array
    {
        if (!self::base_method($controller, $method) || $owner === '') {
            throw new RuntimeException('Invalid export source or owner.');
        }
        $this->prune();
        $id = bin2hex(random_bytes(16));
        $file_name = strtolower($controller . '_' . self::base_method($controller, $method)) . '_' . $id . '.xlsx';
        $path = 'assets/exports/excel/' . $this->namespace . '/' . $id . '/';
        $job = [
            'export_id' => $id, 'controller' => $controller, 'method' => $method, 'owner' => $owner,
            'status' => 'queued', 'created_at' => time(), 'updated_at' => time(),
            'file_name' => $file_name, 'relative_path' => $path . $file_name,
            'file_url' => $this->base_url . $path . $file_name,
            'launcher_pid' => null, 'worker_pid' => null, 'error' => null,
        ];
        try {
            $this->atomic_json($id . '.snapshot.json', $snapshot);
            $this->atomic_json($id . '.json', $job);
            $pid = $this->launch($id);
            if ($pid <= 0) {
                throw new RuntimeException('Worker startup failed.');
            }
            $this->update($id, static function ($current) use ($pid) {
                $current['launcher_pid'] = $pid;
                return $current;
            });
        } catch (Throwable $exception) {
            if ($this->read($id)) {
                $this->fail_job($id, 'Export worker could not be started.');
            } else {
                $this->cleanup($id);
            }
            throw new RuntimeException('Export worker could not be started.');
        }
        // Accepted jobs always start with this API contract, even if a fast worker has finished.
        return $this->public_job($job);
    }

    public function owned_job(string $id, string $owner): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            return null;
        }
        $job = $this->read($id);
        if (!$job || !hash_equals($job['owner'], $owner) || $job['created_at'] < time() - self::RETENTION) {
            return null;
        }
        return $job;
    }

    public function get_status(string $id, string $owner): ?array
    {
        $this->prune();
        $job = $this->owned_job($id, $owner);
        if (!$job) {
            return null;
        }
        if (in_array($job['status'], ['queued', 'running'], true)) {
            $lock = $this->lock($id . '.worker.lock', false);
            if ($lock) {
                try {
                    $job = $this->read($id);
                    if (!$job) {
                        return null;
                    }
                    $expired_start = $job['status'] === 'queued'
                        && (time() - $job['created_at'] > self::START_TIMEOUT
                            || (!empty($job['launcher_pid']) && function_exists('posix_kill') && !$this->process_alive((int) $job['launcher_pid'])));
                    if ($expired_start || $job['status'] === 'running') {
                        $this->fail_job($id, 'Export worker stopped before completion.');
                    }
                } finally {
                    $this->unlock($lock);
                }
            }
            $job = $this->read($id);
        }
        if ($job && $job['status'] === 'ready' && !$this->published_file_exists($job)) {
            $lock = $this->lock($id . '.worker.lock', false);
            if ($lock) {
                try {
                    $job = $this->read($id);
                    if ($job && $job['status'] === 'ready' && !$this->published_file_exists($job)) {
                        $this->fail_job($id, 'Export file is no longer available.');
                    }
                } finally {
                    $this->unlock($lock);
                }
                $job = $this->read($id);
            }
        }
        return $job ? $this->public_job($job) : null;
    }

    public function generate(string $id): bool
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id) || !$this->read($id)) {
            return false;
        }
        $lock = $this->lock($id . '.worker.lock', false);
        if (!$lock) {
            return false;
        }
        $stage = 'generation';
        try {
            $job = $this->read($id);
            if (!$job) {
                return false;
            }
            if ($job['status'] !== 'queued') {
                return $job['status'] === 'ready' && $this->published_file_exists($job);
            }
            if (time() - $job['created_at'] > self::START_TIMEOUT) {
                throw new RuntimeException('Expired job.');
            }
            $this->destination($job);
            $this->update($id, static function ($current) {
                $current['status'] = 'running';
                $current['worker_pid'] = getmypid();
                $current['started_at'] = time();
                return $current;
            });
            $snapshot = json_decode(file_get_contents($this->path($id . '.snapshot.json')), false, 512, JSON_THROW_ON_ERROR);
            $deadline = time() + self::RUN_TIMEOUT;
            $check = static function () use ($deadline) {
                if (time() > $deadline) {
                    throw new RuntimeException('Export exceeded its time limit.');
                }
            };
            $this->render($this->path($id . '.xlsx'), $snapshot, $job['controller'] . '/' . self::base_method($job['controller'], $job['method']), $check);
            $check();
            $stage = 'publication';
            $this->publish($this->path($id . '.xlsx'), $job);
            $check();
            if (!$this->published_file_exists($job)) {
                throw new RuntimeException('Published workbook is unavailable.');
            }
            $this->update($id, static function ($current) {
                $current['status'] = 'ready';
                $current['finished_at'] = time();
                return $current;
            });
            return true;
        } catch (Throwable $exception) {
            // Never expose response values, filesystem paths, or credentials.
            $this->fail_job($id, $stage === 'publication' ? 'Export file publication failed.' : 'Export workbook generation failed.');
            return false;
        } finally {
            try {
                $this->cleanup($id);
            } finally {
                $this->unlock($lock);
            }
        }
    }

    protected function render(string $path, $snapshot, string $source, callable $check): void
    {
        (new Excel_workbook())->write($path, $snapshot, $source, $check);
    }

    /** Publish only a completed, verified workbook at its promised same-domain URL. */
    protected function publish(string $source, array $job): void
    {
        $destination = $this->destination($job);
        $directory = dirname($destination);
        $this->check_public_directory($directory, true);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Export destination already exists.');
        }
        $staging = $directory . '/.part_' . bin2hex(random_bytes(16)) . '.tmp';
        try {
            $this->copy_workbook($source, $staging);
            $source_hash = hash_file('sha256', $source);
            if ($source_hash === false || !hash_equals($source_hash, (string) hash_file('sha256', $staging))) {
                throw new RuntimeException('Workbook copy verification failed.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($staging, \ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('Invalid workbook.');
            }
            try {
                if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                    throw new RuntimeException('Incomplete workbook.');
                }
            } finally {
                $zip->close();
            }
            // The chosen direct-download policy requires static web-server read access.
            if (!chmod($staging, 0644)) {
                throw new RuntimeException('Cannot make workbook readable.');
            }
            $this->publish_file($staging, $destination);
        } finally {
            if (is_file($staging)) {
                unlink($staging);
            }
        }
    }

    protected function copy_workbook(string $source, string $staging): void
    {
        $input = fopen($source, 'rb');
        if (!$input) {
            throw new RuntimeException('Cannot read workbook.');
        }
        $output = null;
        try {
            $output = fopen($staging, 'xb');
            if (!$output || !chmod($staging, 0600)) {
                throw new RuntimeException('Cannot stage workbook.');
            }
            if (stream_copy_to_stream($input, $output) !== filesize($source) || !fflush($output)) {
                throw new RuntimeException('Cannot copy workbook.');
            }
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    protected function publish_file(string $staging, string $destination): void
    {
        if (!rename($staging, $destination)) {
            throw new RuntimeException('Cannot publish workbook.');
        }
    }

    protected function public_root(): string
    {
        return $this->application_root . '/assets/exports/excel';
    }

    private function destination(array $job): string
    {
        $id = $job['export_id'] ?? '';
        $base_method = self::base_method($job['controller'] ?? '', $job['method'] ?? '');
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id) || !$base_method) {
            throw new RuntimeException('Invalid export destination.');
        }
        $name = strtolower($job['controller'] . '_' . $base_method) . '_' . $id . '.xlsx';
        $relative_path = 'assets/exports/excel/' . $this->namespace . '/' . $id . '/' . $name;
        if (($job['file_name'] ?? null) !== $name || ($job['relative_path'] ?? null) !== $relative_path
            || ($job['file_url'] ?? null) !== $this->base_url . $relative_path) {
            throw new RuntimeException('Invalid export destination.');
        }
        return $this->public_root() . '/' . $this->namespace . '/' . $id . '/' . $name;
    }

    private function published_file_exists(array $job): bool
    {
        try {
            $path = $this->destination($job);
            clearstatcache(true, $path);
            return $this->check_public_directory(dirname($path), false) && !is_link($path)
                && is_file($path) && is_readable($path) && filesize($path) > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Check every component so publication/cleanup cannot follow an unexpected symlink. */
    private function check_public_directory(string $directory, bool $create): bool
    {
        $path = '';
        foreach (explode('/', ltrim($directory, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Invalid public export directory.');
            }
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new RuntimeException('Public export directories must not be symbolic links.');
            }
            if (!is_dir($path)) {
                if (!$create) {
                    return false;
                }
                if (!mkdir($path, 0755) || !chmod($path, 0755)) {
                    throw new RuntimeException('Cannot create public export directory.');
                }
            }
        }
        return true;
    }

    private function remove_public_files(string $id): void
    {
        $directory = $this->public_root() . '/' . $this->namespace . '/' . $id;
        try {
            if (!$this->check_public_directory($directory, false)) {
                return;
            }
        } catch (RuntimeException $exception) {
            // An unexpected symlink was never a valid publication destination.
            return;
        }
        $files = array_merge(glob($directory . '/*_' . $id . '.xlsx') ?: [], glob($directory . '/.part_*.tmp') ?: []);
        foreach ($files as $file) {
            if (!unlink($file)) {
                throw new RuntimeException('Cannot remove export file.');
            }
        }
        if (count(scandir($directory)) === 2) {
            rmdir($directory);
        }
    }

    protected function launch(string $id): int
    {
        $php = $this->php_cli();
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(ROOTPATH . 'spark')
            . ' exports:generate ' . escapeshellarg($id) . ' </dev/null >/dev/null 2>&1 & echo $!';
        $output = [];
        $code = 1;
        exec($command, $output, $code);
        return $code === 0 ? (int) end($output) : 0;
    }

    protected function php_cli(): string
    {
        $configured = (string) myt_env('EXCEL_EXPORT_PHP_CLI', '');
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $candidates = $configured !== '' ? [$configured] : [
            PHP_SAPI === 'cli' ? PHP_BINARY : dirname(PHP_BINARY) . '/php',
            '/usr/bin/php' . $version, '/usr/local/bin/php' . $version, '/usr/bin/php', '/usr/local/bin/php', '/opt/homebrew/bin/php',
        ];
        foreach (array_unique($candidates) as $candidate) {
            if (!@is_file($candidate) || !@is_executable($candidate) || preg_match('/(?:fpm|cgi)/i', basename($candidate))) {
                continue;
            }
            $output = [];
            $code = 1;
            exec(escapeshellarg($candidate) . ' -r ' . escapeshellarg('echo PHP_SAPI . ":" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;') . ' 2>/dev/null', $output, $code);
            if ($code === 0 && implode('', $output) === 'cli:' . $version) {
                return $candidate;
            }
        }
        throw new RuntimeException('A matching PHP CLI runtime is required.');
    }

    protected function process_alive(int $pid): bool
    {
        return $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    public function prune(): void
    {
        foreach (glob($this->path('*.json')) ?: [] as $path) {
            $id = basename($path, '.json');
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
                continue;
            }
            $job = $this->read($id);
            if (!$job || $job['created_at'] >= time() - self::RETENTION) {
                continue;
            }
            $lock = $this->lock($id . '.worker.lock', false);
            if (!$lock) {
                continue;
            }
            try {
                $this->cleanup($id);
                $this->remove_public_files($id);
                if (!unlink($path)) {
                    throw new RuntimeException('Cannot remove expired export metadata.');
                }
            } finally {
                $this->unlock($lock);
            }
            // Do not unlink lock files: a waiting process must never lock a different inode.
        }
    }

    private function fail_job(string $id, string $message): void
    {
        try {
            $this->update($id, static function ($current) use ($message) {
                $current['status'] = 'failed';
                $current['error'] = $message;
                $current['finished_at'] = time();
                return $current;
            });
        } finally {
            try {
                $this->cleanup($id);
            } finally {
                $this->remove_public_files($id);
            }
        }
        if (function_exists('log_message')) {
            log_message('error', 'Excel export {export_id} failed.', ['export_id' => $id]);
        }
    }

    private function cleanup(string $id): void
    {
        foreach ([$id . '.snapshot.json', $id . '.xlsx'] as $name) {
            if (is_file($this->path($name))) {
                unlink($this->path($name));
            }
        }
        foreach (glob($this->path($id . '.xlsx.work.*')) ?: [] as $path) {
            @unlink($path);
        }
    }

    private function public_job(array $job): array
    {
        $result = array_intersect_key($job, array_flip(['export_id', 'status', 'file_name', 'file_url']));
        if ($job['status'] === 'failed') {
            $result['error'] = $job['error'];
        }
        return $result;
    }

    private function read(string $id): ?array
    {
        $path = $this->path($id . '.json');
        $json = @file_get_contents($path);
        return $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function update(string $id, callable $change): void
    {
        $lock = $this->lock($id . '.state.lock', true);
        try {
            $job = $this->read($id);
            if (!$job) {
                throw new RuntimeException('Export not found.');
            }
            $job = $change($job);
            $job['updated_at'] = time();
            $this->atomic_json($id . '.json', $job);
        } finally {
            $this->unlock($lock);
        }
    }

    private function atomic_json(string $name, $value): void
    {
        $temp = $this->path($name . '.' . bin2hex(random_bytes(6)) . '.tmp');
        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $handle = fopen($temp, 'xb');
            if (!$handle) {
                throw new RuntimeException('Cannot store export.');
            }
            chmod($temp, 0600);
            try {
                if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                    throw new RuntimeException('Cannot store export.');
                }
            } finally {
                fclose($handle);
            }
            if (!rename($temp, $this->path($name))) {
                throw new RuntimeException('Cannot store export.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    private function lock(string $name, bool $wait)
    {
        if (is_link($this->path($name))) {
            throw new RuntimeException('Invalid export lock.');
        }
        $handle = fopen($this->path($name), 'c');
        if (!$handle) {
            throw new RuntimeException('Cannot lock export.');
        }
        chmod($this->path($name), 0600);
        if (!flock($handle, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
            fclose($handle);
            return false;
        }
        return $handle;
    }

    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
