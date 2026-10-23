<?php

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\Excel_export_fixture;

final class ExcelExportDownloadTest extends CIUnitTestCase
{
    private $directory;
    private $server;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->directory && is_dir($this->directory)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testFutureDirectUrlServesOnlyCompletedWorkbookWithoutAuthentication(): void
    {
        // This server only serves the disposable test web root; no application/API runs.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        $this->assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->directory = '/tmp/cpci_excel_download_' . bin2hex(random_bytes(8));
        $service = new Excel_export_fixture($this->directory, 'http://' . $address . '/');
        mkdir($service->document_root(), 0755, true);
        $this->server = proc_open([PHP_BINARY, '-S', $address, '-t', $service->document_root()], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
        ], $pipes);
        $this->assertIsResource($this->server);
        $started = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            if ($this->get('http://' . $address . '/')[0] !== 0) {
                $started = true;
                break;
            }
            usleep(25000);
        }
        $this->assertTrue($started, 'Local static test server must start.');
        $job = $service->queue('Reports', 'get_fda_dashboard_excel', 'owner-a', ['size' => 1, 'data' => [['id' => '0012', 'amount' => '0.50']]]);
        $this->assertSame(404, $this->get($job['file_url'])[0]);
        $service->during_publish = function () use ($job) {
            $this->assertSame(404, $this->get($job['file_url'])[0], 'The promised URL cannot expose an incomplete file.');
        };
        $this->assertTrue($service->generate($job['export_id']));
        [$status, $bytes] = $this->get($job['file_url']);
        $this->assertSame(200, $status);
        $this->assertSame(hash_file('sha256', $service->file_path($job)), hash('sha256', $bytes));
        $this->assertSame('ready', $service->get_status($job['export_id'], 'owner-a')['status']);
        $this->assertSame(404, $this->get(dirname($job['file_url']) . '/' . $job['export_id'] . '.json')[0]);
        $this->assertSame(404, $this->get(dirname($job['file_url']) . '/' . $job['export_id'] . '.snapshot.json')[0]);
        $path = $service->directory() . '/' . $job['export_id'] . '.json';
        $manifest = json_decode(file_get_contents($path), true);
        $manifest['created_at'] = time() - 86401;
        file_put_contents($path, json_encode($manifest));
        $this->assertNull($service->get_status($job['export_id'], 'owner-a'));
        $this->assertSame(404, $this->get($job['file_url'])[0]);
    }

    private function get(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return [$status, $body];
    }
}
