<?php

use CodeIgniter\Test\CIUnitTestCase;

final class ErrorHandlingTest extends CIUnitTestCase
{
    private static $server;
    private static $address;
    private static $root;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once ROOTPATH . 'app/Config/BootstrapGuard.php';
        self::$root = sys_get_temp_dir() . '/uat_errors_fixture_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0700, true);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error_code, $error_message);
        if (!$socket) {
            throw new RuntimeException('Cannot allocate an isolated error fixture.');
        }
        self::$address = stream_socket_get_name($socket, false);
        fclose($socket);
        $environment = getenv();
        $environment['ERROR_TEST_ROOT'] = self::$root;
        self::$server = proc_open([PHP_BINARY, '-S', self::$address, ROOTPATH . 'tests/_support/Http/error_router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$root . '/http.log', 'a'], 2 => ['file', self::$root . '/http.log', 'a']], $pipes, ROOTPATH, $environment);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $connection = @stream_socket_client('tcp://' . self::$address, $error_code, $error_message, 0.1);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }
        throw new RuntimeException('Isolated error fixture did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$root && is_dir(self::$root)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir(self::$root);
        }
        parent::tearDownAfterClass();
    }

    public function testBootstrapAndFrameworkErrorsHaveTheSameRedactedEnvelope(): void
    {
        foreach (['bootstrap', 'framework'] as $path) {
            $response = $this->http_response($path);
            $this->assertSame(500, $response['status'], $response['body']);
            $this->assertStringContainsString('application/json', $response['headers']['content-type']);
            $this->assertSame('http://errors-fixture.local:3001', $response['headers']['access-control-allow-origin']);
            $payload = json_decode($response['body'], true);
            $this->assertSame(['code', 'message', 'error'], array_keys($payload));
            $this->assertSame(500, $payload['code']);
            $this->assertSame('something went wrong', $payload['message']);
            $this->assertSame(['type', 'message', 'file', 'line'], array_keys($payload['error']));
            $this->assertSame(RuntimeException::class, $payload['error']['type']);
            foreach (['fixture-password', 'fixture-token', 'fixture-private-api-key', 'SELECT', 'patient_name'] as $secret) {
                $this->assertStringNotContainsString($secret, $response['body']);
            }
            $this->assertGreaterThan(0, $payload['error']['line']);
            $this->assertFalse(str_starts_with($payload['error']['file'], '/'));
        }
    }

    public function testDisabledDetailsAndNon500ResponsesKeepExistingShapes(): void
    {
        $response = $this->http_response('bootstrap?enabled=false');
        $this->assertSame('bootstrap_failed', json_decode($response['body'], true)['error']);
        $response = $this->http_response('framework?enabled=false');
        $this->assertSame(500, $response['status']);
        $this->assertStringNotContainsString('Synthetic failure', $response['body']);
        $this->assertStringNotContainsString('something went wrong', $response['body']);
        $response = $this->http_response('framework404');
        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('something went wrong', $response['body']);
    }

    public function testReadinessFailuresAndInvalidUtf8HaveValidJson(): void
    {
        $response = $this->http_response('readiness');
        $payload = json_decode($response['body'], true);
        $this->assertSame('BootstrapError', $payload['error']['type']);
        $this->assertSame('Required vendor/autoload.php is missing.', $payload['error']['message']);
        $this->assertNull($payload['error']['file']);
        $this->assertNull($payload['error']['line']);
        $response = $this->http_response('invalid_utf8');
        $this->assertIsArray(json_decode($response['body'], true));
        $this->assertSame(500, json_decode($response['body'], true)['code']);
    }

    public function testPreflightStillReturns204(): void
    {
        $response = $this->http_response('bootstrap', 'OPTIONS');
        $this->assertSame(204, $response['status']);
        $this->assertSame('', $response['body']);
        $this->assertStringContainsString('Origin-API-Key', $response['headers']['access-control-allow-headers']);
    }

    public function testFrontControllerPreservesOriginalEarlyException(): void
    {
        $response = $this->http_response('front_controller');
        $payload = json_decode($response['body'], true);
        $this->assertSame(500, $response['status']);
        $this->assertSame('RuntimeException', $payload['error']['type']);
        $this->assertSame('Synthetic Paths initialization failure.', $payload['error']['message']);
        $this->assertSame('app/Config/Paths.php', $payload['error']['file']);
        $lines = file(self::$root . '/front/writable/logs/log-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') . '.log');
        $this->assertCount(1, $lines);
    }

    public function testFlagIsAvailableBeforeDotenvAndDefaultsOff(): void
    {
        $root = self::$root . '/flag';
        mkdir($root);
        $saved_env = $_ENV['ERROR_RESPONSE_DETAILS'] ?? null;
        $saved_server = $_SERVER['ERROR_RESPONSE_DETAILS'] ?? null;
        $saved_process = getenv('ERROR_RESPONSE_DETAILS');
        unset($_ENV['ERROR_RESPONSE_DETAILS'], $_SERVER['ERROR_RESPONSE_DETAILS']);
        putenv('ERROR_RESPONSE_DETAILS');
        try {
            $this->assertFalse(bootstrap_error_details_enabled($root));
            file_put_contents($root . '/.env', "ERROR_RESPONSE_DETAILS = 'true' # UAT only\n");
            $this->assertTrue(bootstrap_error_details_enabled($root));
            $_ENV['ERROR_RESPONSE_DETAILS'] = 'false';
            $this->assertFalse(bootstrap_error_details_enabled($root));
        } finally {
            unset($_ENV['ERROR_RESPONSE_DETAILS'], $_SERVER['ERROR_RESPONSE_DETAILS']);
            if ($saved_env !== null) { $_ENV['ERROR_RESPONSE_DETAILS'] = $saved_env; }
            if ($saved_server !== null) { $_SERVER['ERROR_RESPONSE_DETAILS'] = $saved_server; }
            putenv($saved_process === false ? 'ERROR_RESPONSE_DETAILS' : 'ERROR_RESPONSE_DETAILS=' . $saved_process);
        }
    }

    public function testRelativeLocationsConfiguredSecretsAndDatabaseValues(): void
    {
        $_ENV['TEST_PRIVATE_TOKEN'] = 'fixture-configured-secret';
        $exception = new RuntimeException("fixture-configured-secret Duplicate entry 'O\\'Brien' for key 'name'\nSQL Query: INSERT INTO company VALUES('private')");
        $payload = bootstrap_error_payload($exception, '', ROOTPATH);
        $this->assertSame('tests/unit/ErrorHandlingTest.php', $payload['error']['file']);
        $this->assertStringNotContainsString('fixture-configured-secret', $payload['error']['message']);
        $this->assertStringNotContainsString('Brien', $payload['error']['message']);
        $this->assertStringNotContainsString('INSERT', $payload['error']['message']);
        $this->assertStringContainsString("for key 'name'", $payload['error']['message']);
        $message = bootstrap_redact_error("Access denied for user 'private_user'@'private_host' (using password: YES)");
        $this->assertStringNotContainsString('private_user', $message);
        $this->assertStringNotContainsString('private_host', $message);
        $this->assertStringNotContainsString('private_password', bootstrap_redact_error('mysql://private_user:private_password@localhost'));
        unset($_ENV['TEST_PRIVATE_TOKEN']);
    }

    public function testDailyLoggingUsesManilaMidnight(): void
    {
        $this->assertStringEndsWith('/log-2026-10-15.log', bootstrap_log_path('/fixture', new DateTimeImmutable('2026-10-15T15:59:59Z')));
        $this->assertStringEndsWith('/log-2026-10-16.log', bootstrap_log_path('/fixture', new DateTimeImmutable('2026-10-15T16:00:00Z')));
    }

    public function testLoggersFallBackWithoutDuplicateDailyEntries(): void
    {
        foreach (['no_logger', 'false_logger', 'throwing_logger', 'framework_logger', 'unwritable'] as $mode) {
            $root = self::$root . '/' . $mode;
            mkdir($root);
            $process = proc_open([PHP_BINARY, ROOTPATH . 'tests/_support/Http/error_worker.php', $mode, $root],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr);
            $this->assertTrue(json_decode($stdout, true)['timezone_restored']);
            $this->assertFileDoesNotExist($root . '/writable/logs/boot-error.log');
            if ($mode === 'framework_logger') {
                $this->assertFileExists($root . '/framework.log');
                $this->assertFileDoesNotExist($root . '/writable/logs/log-2026-10-16.log');
            } elseif ($mode === 'unwritable') {
                $this->assertStringContainsString('Synthetic logging failure', file_get_contents($root . '/server.log'));
                $this->assertStringNotContainsString('fixture-secret', file_get_contents($root . '/server.log'));
            } else {
                $lines = file($root . '/writable/logs/log-2026-10-16.log');
                $this->assertCount(1, $lines);
                $this->assertStringStartsWith('ERROR - 2026-10-16 00:00:00 --> ', $lines[0]);
                $this->assertStringNotContainsString('fixture-secret', $lines[0]);
                $this->assertFileDoesNotExist($root . '/server.log');
            }
        }
    }

    public function testBootstrapAndFrameworkEachLogOneException(): void
    {
        foreach (['bootstrap', 'framework'] as $path) {
            $before = $this->exception_log_count();
            $this->http_response($path);
            $this->assertSame($before + 1, $this->exception_log_count());
        }
        $this->assertFileDoesNotExist(self::$root . '/writable/logs/boot-error.log');
    }

    private function exception_log_count()
    {
        $count = 0;
        foreach (glob(self::$root . '/writable/logs/log-*.log') as $path) {
            $count += preg_match_all('/^(?:ERROR|CRITICAL) - .*Synthetic failure/m', file_get_contents($path));
        }
        return $count;
    }

    private function http_response($path, $method = 'GET')
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10,
            'header' => "Origin: http://errors-fixture.local:3001\r\nAccept: application/json"]]);
        $stream = fopen('http://' . self::$address . '/' . $path, 'r', false, $context);
        $body = stream_get_contents($stream);
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        preg_match('/\s(\d{3})\s/', $metadata['wrapper_data'][0], $matches);
        $headers = [];
        foreach ($metadata['wrapper_data'] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }
        return ['status' => (int) $matches[1], 'headers' => $headers, 'body' => $body];
    }
}
