<?php

use CodeIgniter\Test\CIUnitTestCase;

final class CorsHeadersTest extends CIUnitTestCase
{
    private static $server;
    private static $address;
    private static $server_log;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error_code, $error_message);
        if (!$socket) {
            throw new RuntimeException('Cannot allocate the isolated loopback HTTP fixture.');
        }
        self::$address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$server_log = tempnam(sys_get_temp_dir(), 'cors_fixture_');
        self::$server = proc_open([PHP_BINARY, '-S', self::$address, ROOTPATH . 'tests/_support/Http/cors_router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$server_log, 'a'], 2 => ['file', self::$server_log, 'a']], $pipes);
        if (!is_resource(self::$server)) {
            throw new RuntimeException('Cannot start the isolated loopback HTTP fixture.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $connection = @stream_socket_client('tcp://' . self::$address, $error_code, $error_message, 0.1);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }
        self::tearDownAfterClass();
        throw new RuntimeException('The isolated loopback HTTP fixture did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$server_log && is_file(self::$server_log)) {
            unlink(self::$server_log);
        }
        parent::tearDownAfterClass();
    }

    public function cors_cases(): array
    {
        return [['common', 'api-key,origin-api-key'], ['bootstrap', 'api-key,origin-api-key'],
            ['common', 'API-KEY,Origin-API-Key'], ['bootstrap', 'API-KEY,Origin-API-Key']];
    }

    /** @dataProvider cors_cases */
    public function testCorsEmittersAllowOriginApiKey($path, $requested_headers): void
    {
        $headers = $this->request_headers($path, 'http://cors-fixture.local:3001', $requested_headers);
        $this->assertStringContainsString('204', $headers['status']);
        $this->assertSame('http://cors-fixture.local:3001', $headers['access-control-allow-origin']);
        $allowed = array_map('trim', explode(',', strtolower($headers['access-control-allow-headers'])));
        foreach (['content-type', 'authorization', 'x-requested-with', 'api-key', 'latitude', 'longitude', 'x-cron-secret', 'origin-api-key'] as $name) {
            $this->assertContains($name, $allowed);
        }
        $this->assertSame('GET, POST, OPTIONS, PUT, PATCH, DELETE', $headers['access-control-allow-methods']);
        $this->assertSame('86400', $headers['access-control-max-age']);
    }

    public function testUnknownAndMissingOriginsRemainWithoutCorsHeaders(): void
    {
        foreach (['common', 'bootstrap'] as $path) {
            foreach (['https://not-allowed.invalid', ''] as $origin) {
                $headers = $this->request_headers($path, $origin);
                $this->assertArrayNotHasKey('access-control-allow-origin', $headers);
                $this->assertArrayNotHasKey('access-control-allow-headers', $headers);
            }
        }
    }

    public function testCorsHeadersRemainOnErrorResponses(): void
    {
        foreach (['common?unauthorized=1', 'bootstrap?unauthorized=1', 'bootstrap?failure=1'] as $path) {
            $headers = $this->request_headers($path, 'http://cors-fixture.local:3001');
            $this->assertStringContainsString(str_contains($path, 'failure') ? '500' : '401', $headers['status']);
            $this->assertSame('http://cors-fixture.local:3001', $headers['access-control-allow-origin']);
            $this->assertStringContainsString('Origin-API-Key', $headers['access-control-allow-headers']);
        }
    }

    public function testLegacyConfigurationKeepsOriginKeyValidation(): void
    {
        $result = json_decode(file_get_contents('http://' . self::$address . '/common?legacy_validator=1'), true);
        $this->assertSame(['valid' => true, 'invalid' => false, 'empty' => false, 'missing' => false], $result);
    }

    private function request_headers($path, $origin, $requested_headers = 'api-key,origin-api-key')
    {
        $context = stream_context_create(['http' => ['method' => str_contains($path, '?') ? 'GET' : 'OPTIONS', 'ignore_errors' => true, 'timeout' => 5,
            'header' => 'Origin: ' . $origin . "\r\nAccess-Control-Request-Method: GET\r\nAccess-Control-Request-Headers: " . $requested_headers]]);
        $stream = fopen('http://' . self::$address . '/' . $path, 'r', false, $context);
        $metadata = stream_get_meta_data($stream);
        fclose($stream);
        $headers = ['status' => $metadata['wrapper_data'][0]];
        foreach ($metadata['wrapper_data'] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }
        return $headers;
    }
}
