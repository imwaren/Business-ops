<?php

use App\Models\Report;
use CodeIgniter\Test\CIUnitTestCase;

require_once ROOTPATH . 'scripts/accounting_cron/request.php';

final class AccountingCronTest extends CIUnitTestCase
{
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/accounting_cron_test_' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->root . 'writable/cache', 0700, true);
        mkdir($this->root . 'writable/logs', 0700, true);
    }

    protected function tearDown(): void
    {
        $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function run_request($url, $body = '', $status = 200, $secret = 'synthetic-fixture-secret')
    {
        return Accounting_cron_request::run($this->root, 'uat.example.test', $url,
            static function ($url, $headers) use ($body, $status, $secret) {
                self::assertSame(['X-Cron-Secret: ' . $secret], $headers);
                return ['status' => $status, 'errno' => 0, 'body' => $body];
            }, static function () use ($secret) { return $secret; });
    }

    public function testValidSecretUsesHeaderAndLogsNoCredentialsOrBody()
    {
        $result = $this->run_request('https://uat.example.test/cron/get_daily_sales', 'private response body');
        $this->assertTrue($result['ok']);
        $file = $this->root . 'writable/logs/log-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') . '.log';
        $log = file_get_contents($file);
        $this->assertStringContainsString('"job":"get_daily_sales"', $log);
        $this->assertSame(1, substr_count($log, 'Accounting cron'));
        $this->assertStringNotContainsString('synthetic-fixture-secret', $log);
        $this->assertStringNotContainsString('private response body', $log);
    }

    public function testTargetsStayWithinConfiguredHttpsHostAndCronPaths()
    {
        foreach (['http://uat.example.test/cron/get_daily_sales', 'https://prod.example.test/cron/get_daily_sales',
            'https://uat.example.test/items/get', 'https://user@uat.example.test/cron/get_daily_sales',
            'https://uat.example.test:444/cron/get_daily_sales', 'https://uat.example.test/cron/../items/get',
            'https://uat.example.test/cron/get_daily_sales#fragment', 'https://uat.example.test/cron/get_daily_sales?cron_secret=hidden'] as $url) {
            $result = Accounting_cron_request::run($this->root, 'uat.example.test', $url,
                static function () { self::fail('Invalid target reached transport.'); }, static function () { return 'fixture'; });
            $this->assertFalse($result['ok']);
        }
    }

    public function testMissingAndInvalidSecretsDoNotBecomeSuccessfulRequests()
    {
        foreach ([null, '', "bad\r\nheader"] as $secret) {
            $result = $this->run_request('https://uat.example.test/cron/get_daily_sales', '', 200, $secret);
            $this->assertFalse($result['ok']);
            $this->assertSame('credentials_unavailable', $result['reason']);
        }
        $result = $this->run_request('https://uat.example.test/cron/get_daily_sales', 'private error', 401);
        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http_status']);
        $this->assertSame('http_failed', $result['reason']);
    }

    public function testOverlappingJobsAreNotExecutedAndLockIsReleasedAfterFailure()
    {
        $lock = fopen($this->root . 'writable/cache/accounting_cron_get_daily_sales.lock', 'c');
        flock($lock, LOCK_EX);
        $result = $this->run_request('https://uat.example.test/cron/get_daily_sales');
        $this->assertSame('lock_unavailable', $result['reason']);
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertFalse($this->run_request('https://uat.example.test/cron/get_daily_sales', '', 500)['ok']);
        $this->assertTrue($this->run_request('https://uat.example.test/cron/get_daily_sales')['ok']);
    }

    public function testSnapshotRequiresAllSevenCountsAndReturnsOnlySummary()
    {
        $body = json_encode(['status' => 200, 'snapshot_date' => '2026-10-17', 'data' => array_fill(1, 7, 12), 'private' => 'not returned']);
        $result = $this->run_request('https://uat.example.test/cron/snapshot_item_inventory?snapshot_date=2026-10-17', $body);
        $this->assertTrue($result['ok']);
        $this->assertSame(array_fill(1, 7, 12), $result['branch_counts']);
        $this->assertArrayNotHasKey('private', $result);
        $this->assertFalse($this->run_request('https://uat.example.test/cron/snapshot_item_inventory', '{}')['ok']);
        $this->assertFalse($this->run_request('https://uat.example.test/cron/get_daily_sales', '{"status":500}')['ok']);
    }

    public function testEnvironmentSelectionIsolatesCredentialsAndTransportFailuresAreRedacted()
    {
        foreach (['uat', 'prod'] as $environment) {
            $host = $environment . '.example.test';
            $result = Accounting_cron_request::run($this->root, $host, 'https://' . $host . '/cron/get_daily_sales',
                static function ($url, $headers) use ($environment) {
                    self::assertSame(['X-Cron-Secret: fixture-' . $environment], $headers);
                    return ['status' => 200, 'errno' => 0, 'body' => ''];
                }, static function () use ($environment) { return 'fixture-' . $environment; });
            $this->assertTrue($result['ok']);
        }
        $result = Accounting_cron_request::run($this->root, 'uat.example.test', 'https://uat.example.test/cron/get_daily_sales',
            static function () { throw new RuntimeException('sensitive SQL token fixture'); }, static function () { return 'fixture'; });
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('sensitive SQL token', json_encode($result));
    }

    public function testRestoredMethodsUseConfiguredSourceAndRetainUpsertQueries()
    {
        $db = new class {
            public $queries = [];
            public function query($sql) { $this->queries[] = $sql; return true; }
        };
        $report = new class($db) extends Report {
            protected function _resolve_cross_db_sql(string $sql): string
            {
                return preg_replace_callback('/\{([A-Z_]+_DB)\}/', static function ($matches) {
                    return 'fixture_' . strtolower($matches[1]);
                }, $sql);
            }
        };
        foreach (['mobile', 'oakridge', 'mactan', 'consolacion'] as $index => $branch) {
            foreach (['daily_branch_sales_report_', 'patient_tat_report_'] as $prefix) {
                $this->assertTrue($report->{$prefix . $branch}());
                $sql = end($db->queries);
                $this->assertStringContainsString('INSERT INTO fixture_report_db.', $sql);
                if ($prefix . $branch !== 'patient_tat_report_consolacion') {
                    $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
                }
                $this->assertStringNotContainsString('{', $sql);
                $this->assertStringContainsString('CURDATE()', $sql);
            }
        }
        $this->assertCount(8, $db->queries);
    }
}
