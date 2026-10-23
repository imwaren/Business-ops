<?php

use App\Core\MYT_Controller;
use App\Libraries\Excel_export;
use App\Libraries\Excel_workbook;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\Excel_export_fixture;

final class ExcelExportTest extends CIUnitTestCase
{
    private $storage;
    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = '/tmp/cpci_excel_test_' . bin2hex(random_bytes(8));
        $this->service = new Excel_export_fixture($this->storage, 'https://app.invalid/api/');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->storage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storage);
        }
        parent::tearDown();
    }

    private function property($object, string $name, $value): void
    {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function call($object, string $method, ...$arguments)
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }

    private function controller(string $class, string $method, $snapshot, int $status = 200, array $payload = [], array $denied = [])
    {
        $base = Excel_export::base_method($class, $method);
        $methods = ['_api_validation', '_permission_allowed', '_controller_short_name', '_excel_export_owner', '_excel_export_service'];
        if (method_exists('App\\Controllers\\' . $class, $base)) {
            $methods[] = $base;
        }
        $controller = $this->getMockBuilder('App\\Controllers\\' . $class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
        $this->configure($controller, $method, $payload);
        $controller->method('_api_validation')->willReturn(true);
        $controller->method('_controller_short_name')->willReturn($class);
        $controller->method('_excel_export_owner')->willReturn('verified-user-context');
        $controller->method('_excel_export_service')->willReturn($this->service);
        $controller->method('_permission_allowed')->willReturnCallback(function ($method) use ($controller, $denied) {
            return in_array($method, $denied, true) ? $this->call($controller, '_create_response', 401, ['message' => 'Forbidden']) : true;
        });
        if (in_array($base, $methods, true)) {
            $controller->expects($this->once())->method($base)->willReturnCallback(function () use ($controller, $snapshot, $status, $payload) {
                $property = new ReflectionProperty($controller, 'payload');
                $property->setAccessible(true);
                $this->assertSame($payload, $property->getValue($controller));
                return $this->call($controller, '_create_response', $status, $snapshot);
            });
        }
        return $controller;
    }

    private function configure($controller, string $method, array $payload = []): void
    {
        $this->property($controller, 'payload', $payload);
        $this->property($controller, 'response', service('response'));
        $this->property($controller, 'request', service('request'));
        $this->property($controller, 'router', new class($method) {
            private $method;
            public function __construct($method) { $this->method = $method; }
            public function methodName() { return $this->method; }
        });
        $this->property($controller, 'api_response', new class {
            public $calls = [];
            public function insert($values) { $this->calls[] = $values; return 1; }
        });
    }

    public static function sources(): array
    {
        $rows = [];
        foreach (Excel_export::SOURCES as $class => $methods) {
            foreach ($methods as $method => $base) {
                if ($base !== 'get_payment_terms') {
                    $rows[$class . '/' . $method] = [$class, $method];
                }
            }
        }
        return $rows;
    }

    /** @dataProvider sources */
    public function testAllExistingWrappersCaptureBaseResponseOnceWithoutChangingRequest($class, $method): void
    {
        $snapshot = ['size' => 41, 'page' => 3, 'data_size' => 2, 'data' => [
            ['id' => '0012', 'branch_id' => '2', 'name' => 'Łódź 倉庫 =SUM(A1)', 'amount' => '0.00',
                'details' => [['id' => 'same-id', 'quantity' => 0, 'nullable' => null]]],
        ], 'summary' => ['total' => 2.5]];
        if ($method === 'uip_lost_excel') {
            $snapshot = $snapshot['data'];
        } elseif (in_array($method, ['uip_hmo_corporate_excel', 'get_ppl_membership_corporate_excel'], true)) {
            $snapshot = ['patient_category' => $snapshot['data'], 'age_group' => [['count' => 0]], 'account_name' => []];
        }
        $payload = ['page' => '3', 'data_size' => '2', 'branch_id' => '2', 'term' => "%'quoted", 'token' => 'do-not-persist-token', 'requester' => 999];
        $controller = $this->controller($class, $method, $snapshot, 200, $payload);
        $result = $controller->{$method}();
        $this->assertSame(200, $result->getStatusCode());
        $queued = json_decode($result->getBody(), true);
        $this->assertSame('queued', $queued['status']);
        $this->assertStringContainsString('/exports/get_status?export_id=', $queued['status_url']);
        $id = $queued['export_id'];
        $this->assertSame($snapshot, json_decode(file_get_contents($this->service->directory() . '/' . $id . '.snapshot.json'), true));
        $manifest = file_get_contents($this->service->directory() . '/' . $id . '.json');
        $this->assertStringNotContainsString('do-not-persist-token', $manifest);
        $this->assertArrayNotHasKey('requester', json_decode($manifest, true));
        $property = new ReflectionProperty($controller, 'api_response');
        $property->setAccessible(true);
        $this->assertCount(should_skip_api_response_logging($method) ? 0 : 1, $property->getValue($controller)->calls, 'Only the final queue response may be logged, respecting existing skip rules.');
        $this->assertTrue($this->service->generate($id));
        $status = $this->service->get_status($id, 'verified-user-context');
        $this->assertSame('ready', $status['status']);
        $this->assertSame($queued['file_url'], $status['file_url']);
        $this->assertFileDoesNotExist($this->service->directory() . '/' . $id . '.snapshot.json');
        $sheets = $this->sheets($this->service->publications[0]['bytes']);
        $xml = implode('', array_column($sheets, 'xml'));
        $this->assertStringContainsString('0012', $xml);
        $this->assertStringContainsString('Łódź 倉庫 =SUM(A1)', $xml);
        $this->assertStringContainsString('same-id', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
    }

    public function testMissingPaymentTermsBaseUsesExactCoreErrorAndQueuesNothing(): void
    {
        $controller = $this->controller('Reports', 'get_payment_terms_excel', []);
        $response = $controller->get_payment_terms_excel();
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['base endpoint doesnt exist'], json_decode($response->getBody(), true)['messages']['error_messages']);
        $this->assertSame([], glob($this->service->directory() . '/*.json'));
        $this->assertFalse(method_exists(App\Controllers\Reports::class, 'get_payment_terms'));
    }

    /** @dataProvider errors */
    public function testBaseErrorsArePropagatedWithoutAJob($status): void
    {
        $controller = $this->controller('Reports', 'get_fda_dashboard_excel', ['validation or no-results message'], $status);
        $response = $controller->get_fda_dashboard_excel();
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame(['validation or no-results message'], json_decode($response->getBody(), true)['messages']['error_messages']);
        $this->assertSame([], glob($this->service->directory() . '/*.json'));
    }

    public static function errors(): array
    {
        return [[400], [401], [404], [429], [500]];
    }

    public function testSuccessfulEmptyResponseStillCreatesWorkbook(): void
    {
        $controller = $this->controller('Reports', 'get_fda_dashboard_excel', ['data' => [], 'size' => 0]);
        $id = json_decode($controller->get_fda_dashboard_excel()->getBody(), true)['export_id'];
        $this->assertTrue($this->service->generate($id));
        $this->assertCount(2, $this->sheets($this->service->publications[0]['bytes']));
    }

    public function testDeniedBaseOrExcelPermissionDoesNotCallBase(): void
    {
        foreach (['get_fda_dashboard', 'get_fda_dashboard_excel'] as $denied) {
            $controller = $this->getMockBuilder(App\Controllers\Reports::class)->disableOriginalConstructor()->onlyMethods([
                '_api_validation', '_permission_allowed', '_controller_short_name', 'get_fda_dashboard',
            ])->getMock();
            $this->configure($controller, 'get_fda_dashboard_excel');
            $controller->method('_api_validation')->willReturn(true);
            $controller->method('_controller_short_name')->willReturn('Reports');
            $controller->method('_permission_allowed')->willReturnCallback(function ($method) use ($controller, $denied) {
                return $method === $denied ? $this->call($controller, '_create_response', 401, ['message' => 'Forbidden']) : true;
            });
            $controller->expects($this->never())->method('get_fda_dashboard');
            $this->assertSame(401, $controller->get_fda_dashboard_excel()->getStatusCode());
        }
    }

    public function testCaptureStateRestoredAfterExceptionAndOrdinaryResponsesStillLog(): void
    {
        $controller = $this->getMockBuilder(App\Controllers\Reports::class)->disableOriginalConstructor()->onlyMethods([
            '_api_validation', '_permission_allowed', '_controller_short_name', 'get_fda_dashboard',
        ])->getMock();
        $this->configure($controller, 'get_fda_dashboard_excel');
        $controller->method('_api_validation')->willReturn(true);
        $controller->method('_permission_allowed')->willReturn(true);
        $controller->method('_controller_short_name')->willReturn('Reports');
        $controller->method('get_fda_dashboard')->willThrowException(new RuntimeException('fixture failure'));
        try {
            $controller->get_fda_dashboard_excel();
            $this->fail('Expected exception');
        } catch (RuntimeException $exception) {
            $this->assertSame('fixture failure', $exception->getMessage());
        }
        $this->assertSame(['unchanged' => 0], json_decode($this->call($controller, '_create_response', 200, ['unchanged' => 0])->getBody(), true));
        $property = new ReflectionProperty($controller, 'excel_response_capture');
        $property->setAccessible(true);
        $this->assertFalse($property->getValue($controller));
        $this->assertSame([], glob($this->service->directory() . '/*.json'));
    }


    public function testStatusEndpointChecksOwnerAndBothSourcePermissions(): void
    {
        $job = $this->queue();
        $controller = $this->getMockBuilder(App\Controllers\Exports::class)->disableOriginalConstructor()->onlyMethods([
            '_api_validation', '_permission_allowed_for', '_excel_export_owner', '_excel_export_service',
        ])->getMock();
        $this->configure($controller, 'get_status', ['export_id' => $job['export_id'], 'requester' => 'forged']);
        $controller->method('_api_validation')->willReturn(true);
        $controller->method('_excel_export_owner')->willReturn('owner-a');
        $controller->method('_excel_export_service')->willReturn($this->service);
        $seen = [];
        $controller->method('_permission_allowed_for')->willReturnCallback(function ($class, $method) use (&$seen) {
            $seen[] = [$class, $method];
            return true;
        });
        $response = $controller->get_status();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['Reports', 'get_fda_dashboard_excel'], ['Reports', 'get_fda_dashboard']], $seen);
        $this->assertSame('queued', json_decode($response->getBody(), true)['status']);

        $other = $this->getMockBuilder(App\Controllers\Exports::class)->disableOriginalConstructor()->onlyMethods([
            '_api_validation', '_permission_allowed_for', '_excel_export_owner', '_excel_export_service',
        ])->getMock();
        $this->configure($other, 'get_status', ['export_id' => $job['export_id'], 'requester' => 'owner-a']);
        $other->method('_api_validation')->willReturn(true);
        $other->method('_excel_export_owner')->willReturn('owner-b');
        $other->method('_excel_export_service')->willReturn($this->service);
        $other->expects($this->never())->method('_permission_allowed_for');
        $this->assertSame(404, $other->get_status()->getStatusCode());
    }

    public function testOwnerUsesValidatedUserOrValidatedOriginAndNeverRequesterPayload(): void
    {
        $controller = $this->getMockBuilder(App\Controllers\Exports::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $this->configure($controller, 'get_status', ['requester' => 'forged-id']);
        $request = new class {
            public $key = '';
            public function getHeaderLine($name) { return $name === 'Origin-API-Key' ? $this->key : ''; }
        };
        $this->property($controller, 'request', $request);
        $this->assertNull($this->call($controller, '_excel_export_owner'));
        $this->property($controller, 'requester_user', (object) ['id' => 25]);
        $first = $this->call($controller, '_excel_export_owner');
        $this->assertNotEmpty($first);
        $this->property($controller, 'payload', ['requester' => 999]);
        $this->assertSame($first, $this->call($controller, '_excel_export_owner'));
        $this->property($controller, 'requester_user', (object) ['id' => 26]);
        $this->assertNotSame($first, $this->call($controller, '_excel_export_owner'));
        $old = $_ENV['CSR_API_KEYS'] ?? null;
        try {
            $_ENV['CSR_API_KEYS'] = 'isolated-origin-key-a,isolated-origin-key-b';
            $request->key = 'invalid';
            $this->assertNull($this->call($controller, '_excel_export_owner'));
            $request->key = 'isolated-origin-key-a';
            $origin_a = $this->call($controller, '_excel_export_owner');
            $this->property($controller, 'requester_user', null);
            $this->assertSame($origin_a, $this->call($controller, '_excel_export_owner'));
            $request->key = 'isolated-origin-key-b';
            $this->assertNotSame($origin_a, $this->call($controller, '_excel_export_owner'));
            $this->assertNotSame($first, $origin_a);
        } finally {
            if ($old === null) {
                unset($_ENV['CSR_API_KEYS']);
            } else {
                $_ENV['CSR_API_KEYS'] = $old;
            }
        }
    }

    public function testAuthenticationFailureDoesNotCaptureOrQueue(): void
    {
        $controller = $this->getMockBuilder(App\Controllers\Reports::class)->disableOriginalConstructor()->onlyMethods([
            '_api_validation', 'get_fda_dashboard', '_excel_export_service',
        ])->getMock();
        $this->configure($controller, 'get_fda_dashboard_excel');
        $controller->method('_api_validation')->willReturnCallback(function () use ($controller) {
            return $this->call($controller, '_create_response', 401, ['message' => 'Invalid Requester']);
        });
        $controller->expects($this->never())->method('get_fda_dashboard');
        $controller->expects($this->never())->method('_excel_export_service');
        $this->assertSame(401, $controller->get_fda_dashboard_excel()->getStatusCode());
    }

    /** @dataProvider pagination */
    public function testRealFdaActionPreservesValidationFiltersPaginationAndBranchIsolation($pagination): void
    {
        $payload = array_merge(['branch_id' => '2', 'patient_id' => '12', 'item_id' => '44', 'term' => 'quoted name',
            'date_from' => '2026-10-01', 'date_to' => '2026-10-19', 'pharmacy_doctor_id' => '7'], $pagination);
        $controller = $this->getMockBuilder(App\Controllers\Reports::class)->disableOriginalConstructor()->onlyMethods([
            '_api_validation', '_permission_allowed', '_controller_short_name', '_excel_export_owner', '_excel_export_service',
        ])->getMock();
        $this->configure($controller, 'get_fda_dashboard_excel', $payload);
        $controller->method('_api_validation')->willReturn(true);
        $controller->method('_permission_allowed')->willReturn(true);
        $controller->method('_controller_short_name')->willReturn('Reports');
        $controller->method('_excel_export_owner')->willReturn('owner-a');
        $controller->method('_excel_export_service')->willReturn($this->service);
        $report = $this->getMockBuilder(App\Models\Report::class)->disableOriginalConstructor()->onlyMethods(['get_fda_dashboard'])->getMock();
        [$page, $size] = list_page_params($payload);
        $expected = ['data' => [['branch_id' => 2, 'item_id' => 44, 'quantity' => '0.50']], 'size' => 22, 'page' => $page, 'data_size' => $size, 'total_pages' => (int) ceil(22 / $size)];
        $report->expects($this->once())->method('get_fda_dashboard')->with(2, $page, $size, '2026-10-01', '2026-10-19', 12, 44, 'quoted name', 7)->willReturn($expected);
        $this->property($controller, 'report', $report);
        $id = json_decode($controller->get_fda_dashboard_excel()->getBody(), true)['export_id'];
        $this->assertSame($expected, json_decode(file_get_contents($this->service->directory() . '/' . $id . '.snapshot.json'), true));
    }

    public static function pagination(): array
    {
        return [[[]], [['page' => '3', 'data_size' => '7']]];
    }

    public function testRealBaseValidationErrorDoesNotQueryOrQueue(): void
    {
        $controller = $this->getMockBuilder(App\Controllers\Reports::class)->disableOriginalConstructor()->onlyMethods([
            '_api_validation', '_permission_allowed', '_controller_short_name', '_excel_export_service',
        ])->getMock();
        $this->configure($controller, 'get_fda_dashboard_excel', ['branch_id' => 6]);
        $controller->method('_api_validation')->willReturn(true);
        $controller->method('_permission_allowed')->willReturn(true);
        $controller->method('_controller_short_name')->willReturn('Reports');
        $controller->expects($this->never())->method('_excel_export_service');
        $this->assertSame(400, $controller->get_fda_dashboard_excel()->getStatusCode());
        $this->assertSame([], glob($this->service->directory() . '/*.json'));
    }


    public function testRunningPermissionDenialAndQueueStartupFailureUseCoreResponses(): void
    {
        $job = $this->queue();
        $controller = $this->getMockBuilder(App\Controllers\Exports::class)->disableOriginalConstructor()->onlyMethods([
            '_api_validation', '_permission_allowed_for', '_excel_export_owner', '_excel_export_service',
        ])->getMock();
        $this->configure($controller, 'get_status', ['export_id' => $job['export_id']]);
        $controller->method('_api_validation')->willReturn(true);
        $controller->method('_excel_export_owner')->willReturn('owner-a');
        $controller->method('_excel_export_service')->willReturn($this->service);
        $controller->method('_permission_allowed_for')->willReturnCallback(function () use ($controller) {
            return $this->call($controller, '_create_response', 401, ['message' => 'Forbidden']);
        });
        $this->assertSame(401, $controller->get_status()->getStatusCode());
        $this->service->launch_failure = true;
        $export = $this->controller('Reports', 'get_fda_dashboard_excel', ['data' => []]);
        $response = $export->get_fda_dashboard_excel();
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['Unable to queue Excel export.'], json_decode($response->getBody(), true)['messages']['error_messages']);
    }

    public function testUnclaimedJobsExpireAndRepeatedStatusIsStable(): void
    {
        $job = $this->queue();
        $path = $this->service->directory() . '/' . $job['export_id'] . '.json';
        $manifest = json_decode(file_get_contents($path), true);
        $manifest['created_at'] = time() - 61;
        file_put_contents($path, json_encode($manifest));
        $first = $this->service->get_status($job['export_id'], 'owner-a');
        $this->assertSame('failed', $first['status']);
        $this->assertSame($first, $this->service->get_status($job['export_id'], 'owner-a'));
        $this->assertFalse($this->service->generate($job['export_id']));
    }

    public function testWriterCleansSpoolsWhenRenderingThrows(): void
    {
        $path = $this->storage . '/interrupted.xlsx';
        $rows = array_fill(0, 20, (object) ['id' => 1, 'details' => [(object) ['name' => 'nested']]]);
        $calls = 0;
        try {
            (new Excel_workbook())->write($path, (object) ['data' => $rows], 'fixture', static function () use (&$calls) {
                if (++$calls > 50) {
                    throw new RuntimeException('Injected interruption');
                }
            });
            $this->fail('Expected interruption');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected interruption', $exception->getMessage());
        }
        $this->assertSame([], glob($path . '.work.*'));
    }

    public function testStorageNamespacesSeparateApplicationUrls(): void
    {
        $other = new Excel_export_fixture($this->storage, 'https://other-app.invalid/api/');
        $this->assertNotSame($this->service->directory(), $other->directory());
        $job = $this->queue();
        $this->assertNull($other->get_status($job['export_id'], 'owner-a'));
    }


    public static function real_sources(): array
    {
        return [
            ['Depreciation_entries', 'get_asset_depreciation_report_excel', 'depreciation_entry_item'],
            ['Billings', 'get_mobile_payment_excel', 'payment'],
            ['Cash_deposits', 'get_excel', 'cash_deposit'],
            ['Cash_deposits', 'get_report_excel', 'cash_deposit'],
            ['Account_types', 'get_excel', 'account_type'],
            ['Check_deposits', 'get_excel', 'check_deposit'],
            ['Credit_memos', 'get_excel', 'credit_memo'],
            ['Debit_memos', 'get_excel', 'debit_memo'],
            ['Facilities', 'get_excel', 'facility'],
            ['Non_cash_deposits', 'get_excel', 'non_cash_deposit'],
            ['Non_cash_deposits', 'get_report_excel', 'non_cash_deposit'],
        ];
    }

    /** @dataProvider real_sources */
    public function testRealBaseActionsAndExcelSnapshotsHaveIdenticalResponsesAndQueryCalls($class, $method, $model_property): void
    {
        $methods = ['_api_validation', '_permission_allowed', '_controller_short_name', '_excel_export_owner', '_excel_export_service'];
        if (method_exists('App\\Controllers\\' . $class, 'resolve_branch_models')) {
            $methods[] = 'resolve_branch_models';
        }
        $controller = $this->getMockBuilder('App\\Controllers\\' . $class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
        $payload = ['branch_id' => 2, 'cost_center_id' => 2, 'as_of_date' => '2026-10-19', 'status' => 'approved',
            'date_from' => '2026-10-01', 'date_to' => '2026-10-19', 'term' => 'fixture', 'page' => '2', 'data_size' => '7'];
        $this->configure($controller, $method, $payload);
        $controller->method('_api_validation')->willReturn(true);
        $controller->method('_permission_allowed')->willReturn(true);
        $controller->method('_controller_short_name')->willReturn($class);
        $controller->method('_excel_export_owner')->willReturn('owner-a');
        $controller->method('_excel_export_service')->willReturn($this->service);
        if (in_array('resolve_branch_models', $methods, true)) {
            $controller->method('resolve_branch_models')->willReturn(true);
        }
        $model = new class {
            public $calls = [];
            public function __call($method, $arguments) {
                $this->calls[] = [$method, $arguments];
                if ($method === 'count_details') { return 1; }
                if ($method === 'get_child_details') { return []; }
                return [['id' => '0012', 'transaction_id' => '44', 'name' => 'Mocked model data', 'amount' => '0.25', 'nullable' => null]];
            }
        };
        $this->property($controller, $model_property, $model);
        foreach (['cash_deposit_attachment', 'non_cash_deposit_attachment', 'facility_lab_test'] as $property) {
            if (property_exists($controller, $property)) {
                $this->property($controller, $property, $model);
            }
        }
        if (property_exists($controller, 'branch_master')) {
            $this->property($controller, 'branch_master', new class {
                public function select(...$arguments) { return (object) ['name' => 'Oakridge fixture']; }
            });
        }
        $base = Excel_export::base_method($class, $method);
        $response = $controller->{$base}();
        $this->assertSame(200, $response->getStatusCode());
        $expected = json_decode($response->getBody(), true);
        $calls = $model->calls;
        $model->calls = [];
        $queued_response = $controller->{$method}();
        $this->assertSame(200, $queued_response->getStatusCode());
        $id = json_decode($queued_response->getBody(), true)['export_id'];
        $this->assertSame($calls, $model->calls, 'Exporter must call the same queries exactly once, including original list/count calls.');
        $this->assertSame($expected, json_decode(file_get_contents($this->service->directory() . '/' . $id . '.snapshot.json'), true));
        $this->assertTrue($this->service->generate($id));
    }


    public static function real_report_sources(): array
    {
        return [['get_sales_tracker_excel'], ['sawt_report_excel'], ['uip_lost_excel'], ['uip_hmo_corporate_excel'], ['get_ppl_membership_corporate_excel'], ['get_sales_report_excel']];
    }

    /** @dataProvider real_report_sources */
    public function testRealReportTablesTotalsAndNestedResponsesMatchSnapshots($method): void
    {
        $controller = $this->getMockBuilder(App\Controllers\Reports::class)->disableOriginalConstructor()->onlyMethods([
            '_api_validation', '_permission_allowed', '_controller_short_name', '_excel_export_owner', '_excel_export_service', 'resolve_branch_models',
        ])->getMock();
        $payload = ['branch_id' => 2, 'date_from' => '2026-10-01', 'date_to' => '2026-10-19', 'page' => '2', 'data_size' => '7'];
        $this->configure($controller, $method, $payload);
        $this->property($controller, 'request', new class($payload) {
            private $payload;
            public function __construct($payload) { $this->payload = $payload; }
            public function getVar($key) { return $this->payload[$key] ?? null; }
        });
        $controller->method('_api_validation')->willReturn(true);
        $controller->method('_permission_allowed')->willReturn(true);
        $controller->method('_controller_short_name')->willReturn('Reports');
        $controller->method('_excel_export_owner')->willReturn('owner-a');
        $controller->method('_excel_export_service')->willReturn($this->service);
        $controller->method('resolve_branch_models')->willReturn(true);
        $model = new class {
            public $calls = [];
            public $db;
            public function __call($method, $arguments) {
                $this->calls[] = [$method, $arguments];
                if ($method === 'get_uip_hmo_bundle') {
                    return ['patient_category' => [['patient_category' => 'Acquired', 'transaction_year' => 2026, 'uip_count' => 1, 'sales' => 2.5]],
                        'account_type' => [['account_type' => 'HMO', 'transaction_year' => 2026, 'uip_count' => 1, 'sales' => 2.5]], 'account_name' => [['account_name' => 'A', 'sales' => 2.5]]];
                }
                if ($method === 'get_ppl_summary_bundle') {
                    return ['age_group' => [['age_group' => '20-39', 'patient_count' => 1, 'female' => 1, 'male' => 0]],
                        'patient_category' => [['patient_category' => 'Acquired', 'patient_count' => 1, 'female' => 1, 'male' => 0]], 'membership_type' => [['name' => 'PPL', 'count' => 1]]];
                }
                if ($method === 'get_uip_lost') {
                    return [['account_id' => null, 'transaction_type' => 'MOBILE', 'patient_count' => 1, 'sum_of_total_amount' => '2.50']];
                }
                if ($method === 'get_sawt') {
                    return ['size' => 1, 'data' => [['id' => 12, 'amount' => '2.50']]];
                }
                if ($method === 'get_by_period') { return [['file_name' => 'existing.xlsx']]; }
                if ($method === 'get_transaction_sales_details') { return [12 => [['amount' => '2.50']]]; }
                return [['id' => 12, 'name' => 'Model fixture', 'sae_in_charge_id' => null, 'customer_account_id' => null]];
            }
        };
        foreach (['request_model', 'report_clinic', 'relief', 'sawt_report_attachment', 'booking'] as $property) {
            $this->property($controller, $property, $model);
        }
        // A directly constructed Booking_all also receives a mocked connection; no database is contacted.
        $instances = new ReflectionProperty(CodeIgniter\Database\Config::class, 'instances');
        $instances->setAccessible(true);
        $previous = $instances->getValue();
        $db = $this->getMockBuilder(CodeIgniter\Database\BaseConnection::class)->disableOriginalConstructor()->onlyMethods(['query'])->getMockForAbstractClass();
        $db->method('query')->willReturn(new class {
            public function getResultArray() { return []; }
        });
        $model->db = $db;
        $instances->setValue(null, array_replace($previous, ['tests' => $db, 'default' => $db]));
        try {
            $base = Excel_export::base_method('Reports', $method);
            $response = $controller->{$base}();
            $this->assertSame(200, $response->getStatusCode());
            $expected = json_decode($response->getBody(), true);
            $calls = $model->calls;
            $model->calls = [];
            $response = $controller->{$method}();
            $this->assertSame(200, $response->getStatusCode());
            $id = json_decode($response->getBody(), true)['export_id'];
            $this->assertSame($calls, $model->calls);
            $this->assertSame($expected, json_decode(file_get_contents($this->service->directory() . '/' . $id . '.snapshot.json'), true));
            $this->assertTrue($this->service->generate($id));
            $this->assertNotEmpty($this->sheets($this->service->publications[0]['bytes']));
        } finally {
            $instances->setValue(null, $previous);
        }
    }


    public function testLocalPublicationUsesConfiguredApplicationDomainAndPublicPath(): void
    {
        $service = new Excel_export_fixture($this->storage);
        $job = $service->queue('Reports', 'get_fda_dashboard_excel', 'owner-a', ['data' => []]);
        $this->assertStringStartsWith(rtrim(config('App')->baseURL, '/') . '/assets/exports/excel/', $job['file_url']);
        $this->assertFileDoesNotExist($service->file_path($job));
        $this->assertSame('queued', $service->get_status($job['export_id'], 'owner-a')['status']);
        $manifest = json_decode(file_get_contents($service->directory() . '/' . $job['export_id'] . '.json'), true);
        $this->assertArrayNotHasKey('vm4_path', $manifest);
        $this->assertStringStartsWith('assets/exports/excel/', $manifest['relative_path']);
    }

    public function testWorkbookIsPublishedAtomicallyAndRetainedAfterTransientCleanup(): void
    {
        $job = $this->queue();
        $staged_hash = null;
        $this->service->during_publish = function ($staging, $current) use ($job, &$staged_hash) {
            $this->assertSame($job['export_id'], $current['export_id']);
            $this->assertFileDoesNotExist($this->service->file_path($job));
            $this->assertFileExists($staging);
            $this->assertSame(0600, fileperms($staging) & 0777);
            $this->assertSame('running', $this->service->get_status($job['export_id'], 'owner-a')['status']);
            $staged_hash = hash_file('sha256', $staging);
        };
        $this->assertTrue($this->service->generate($job['export_id']));
        $file = $this->service->file_path($job);
        $this->assertFileExists($file);
        $this->assertSame($staged_hash, hash_file('sha256', $file));
        $this->assertSame(0644, fileperms($file) & 0777);
        $this->assertSame(0755, fileperms(dirname($file)) & 0777);
        $this->assertSame([], glob(dirname($file) . '/.part_*.tmp'));
        $this->assertSame([$file], glob(dirname($file) . '/*'));
        $this->assertFileDoesNotExist($this->service->directory() . '/' . $job['export_id'] . '.xlsx');
        $this->assertFileDoesNotExist($this->service->directory() . '/' . $job['export_id'] . '.snapshot.json');
        $status = $this->service->get_status($job['export_id'], 'owner-a');
        $this->assertSame('ready', $status['status']);
        $this->assertSame($job['file_url'], $status['file_url']);
        $this->assertTrue($this->service->generate($job['export_id']));
        $this->assertCount(1, $this->service->publications);
        $this->assertFileExists($file, 'Duplicate workers must retain the completed workbook.');
    }

    public function testMissingPublishedWorkbookNoLongerReportsReady(): void
    {
        $job = $this->queue();
        $this->assertTrue($this->service->generate($job['export_id']));
        unlink($this->service->file_path($job));
        $status = $this->service->get_status($job['export_id'], 'owner-a');
        $this->assertSame('failed', $status['status']);
        $this->assertSame('Export file is no longer available.', $status['error']);
        $this->assertSame($job['file_url'], $status['file_url']);
        $this->assertFalse($this->service->generate($job['export_id']));
    }

    public function testRetentionPrunesExpiredWorkbookAndMetadataWhileKeepingOtherJobs(): void
    {
        $old = $this->queue();
        $fresh = $this->queue();
        $this->assertTrue($this->service->generate($old['export_id']));
        $this->assertTrue($this->service->generate($fresh['export_id']));
        $path = $this->service->directory() . '/' . $old['export_id'] . '.json';
        $manifest = json_decode(file_get_contents($path), true);
        $manifest['created_at'] = time() - 86401;
        file_put_contents($path, json_encode($manifest));
        $this->assertNull($this->service->get_status($old['export_id'], 'owner-a'));
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($this->service->file_path($old));
        $this->assertDirectoryDoesNotExist(dirname($this->service->file_path($old)));
        $this->assertSame('ready', $this->service->get_status($fresh['export_id'], 'owner-a')['status']);
        $this->assertFileExists($this->service->file_path($fresh));
    }

    public function testActualPublicationDirectoryFailureIsSanitized(): void
    {
        $job = $this->queue();
        mkdir($this->service->document_root(), 0755, true);
        file_put_contents($this->service->document_root() . '/assets', 'blocks directory creation');
        $this->assertFalse($this->service->generate($job['export_id']));
        $status = $this->service->get_status($job['export_id'], 'owner-a');
        $this->assertSame('failed', $status['status']);
        $this->assertSame('Export file publication failed.', $status['error']);
        $this->assertFileDoesNotExist($this->service->directory() . '/' . $job['export_id'] . '.xlsx');
    }

    public function testPublicationAndCleanupDoNotFollowSymlinkedDirectories(): void
    {
        $job = $this->queue();
        $outside = $this->storage . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/keep.xlsx', 'untouched');
        mkdir($this->service->document_root(), 0755, true);
        symlink($outside, $this->service->document_root() . '/assets');
        $this->assertFalse($this->service->generate($job['export_id']));
        $this->assertSame('failed', $this->service->get_status($job['export_id'], 'owner-a')['status']);
        $this->assertSame('untouched', file_get_contents($outside . '/keep.xlsx'));
        $this->assertSame([$outside . '/keep.xlsx'], glob($outside . '/*'));
    }

    public function testManifestCannotChangePromisedFilenameOrPublicationPath(): void
    {
        $job = $this->queue();
        $path = $this->service->directory() . '/' . $job['export_id'] . '.json';
        $manifest = json_decode(file_get_contents($path), true);
        $manifest['relative_path'] = '../outside.xlsx';
        file_put_contents($path, json_encode($manifest));
        $this->assertFalse($this->service->generate($job['export_id']));
        $this->assertSame('failed', $this->service->get_status($job['export_id'], 'owner-a')['status']);
        $this->assertFileDoesNotExist($this->service->file_path($job));
        $this->assertFileDoesNotExist($this->storage . '/outside.xlsx');
    }

    public function testIndependentJobsCanPublishWhileAnotherWorkerIsRunning(): void
    {
        $first = $this->queue();
        $second = $this->queue();
        $this->service->during_publish = function () use ($first, $second) {
            $this->service->during_publish = null;
            $this->assertSame('running', $this->service->get_status($first['export_id'], 'owner-a')['status']);
            $this->assertTrue($this->service->generate($second['export_id']));
            $this->assertFileExists($this->service->file_path($second));
            $this->assertFileDoesNotExist($this->service->file_path($first));
        };
        $this->assertTrue($this->service->generate($first['export_id']));
        $this->assertFileExists($this->service->file_path($first));
        $this->assertFileExists($this->service->file_path($second));
        $this->assertNotSame($first['file_url'], $second['file_url']);
    }

    private function queue(array $snapshot = []): array
    {
        return $this->service->queue('Reports', 'get_fda_dashboard_excel', 'owner-a', $snapshot ?: ['size' => 0, 'data' => []]);
    }

    public function testStatusIsolationAndDuplicateWorker(): void
    {
        $first = $this->queue();
        $second = $this->queue();
        $this->assertNotSame($first['export_id'], $second['export_id']);
        $this->assertNotSame($first['file_url'], $second['file_url']);
        $this->assertNull($this->service->get_status($first['export_id'], 'owner-b'));
        $this->assertNull($this->service->get_status('../not-an-id', 'owner-a'));
        $id = $first['export_id'];
        $this->service->during_publish = function () use ($id) {
            $this->assertFalse($this->service->generate($id), 'Exclusive worker lock prevents duplicate rendering/publication.');
            $this->assertSame('running', $this->service->get_status($id, 'owner-a')['status']);
            $this->assertFileExists($this->service->directory() . '/' . $id . '.snapshot.json');
        };
        $this->assertTrue($this->service->generate($id));
        $this->assertTrue($this->service->generate($id));
        $this->assertCount(1, $this->service->publications);
        $this->assertSame('queued', $this->service->get_status($second['export_id'], 'owner-a')['status']);
    }

    /** @dataProvider failures */
    public function testWorkerFailuresAreSanitizedAndCleaned($property): void
    {
        $job = $this->queue();
        $this->service->{$property} = true;
        $this->assertFalse($this->service->generate($job['export_id']));
        $status = $this->service->get_status($job['export_id'], 'owner-a');
        $this->assertSame('failed', $status['status']);
        $this->assertSame($job['file_url'], $status['file_url']);
        $this->assertStringNotContainsString('secret', json_encode($status));
        $this->assertStringNotContainsString('private', json_encode($status));
        $this->assertFileDoesNotExist($this->service->directory() . '/' . $job['export_id'] . '.snapshot.json');
        $this->assertFileDoesNotExist($this->service->directory() . '/' . $job['export_id'] . '.xlsx');
        $this->assertSame([], glob($this->service->directory() . '/*.work.*'));
        $this->assertFileDoesNotExist($this->service->file_path($job));
        $this->assertSame([], glob(dirname($this->service->file_path($job)) . '/.part_*.tmp'));
        $this->assertFalse($this->service->generate($job['export_id']), 'No automatic retry.');
    }

    public static function failures(): array
    {
        return [['render_failure'], ['publication_failure'], ['corrupt_copy'], ['rename_failure'], ['after_publish_failure'], ['invalid_workbook']];
    }

    public function testLaunchFailureCleansSnapshotAndDoesNotReturnAcceptedJob(): void
    {
        $this->service->launch_failure = true;
        try {
            $this->queue();
            $this->fail('Expected launch failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Export worker could not be started.', $exception->getMessage());
        }
        $this->assertSame([], glob($this->service->directory() . '/*.snapshot.json'));
        $this->assertCount(1, glob($this->service->directory() . '/*.json'));
    }

    public function testAbandonedJobsAndRetention(): void
    {
        $job = $this->queue();
        $this->service->alive = false;
        $this->assertSame('failed', $this->service->get_status($job['export_id'], 'owner-a')['status']);
        $this->service->alive = true;
        $running = $this->queue();
        $path = $this->service->directory() . '/' . $running['export_id'] . '.json';
        $manifest = json_decode(file_get_contents($path), true);
        $manifest['status'] = 'running';
        file_put_contents($path, json_encode($manifest));
        $this->assertSame('failed', $this->service->get_status($running['export_id'], 'owner-a')['status']);
        $manifest['created_at'] = time() - 86401;
        file_put_contents($path, json_encode($manifest));
        $this->assertNull($this->service->get_status($running['export_id'], 'owner-a'));
        $this->assertFileDoesNotExist($path);
    }

    public function testPublicStorageRejectedAndFilesArePrivate(): void
    {
        $this->queue();
        $this->assertSame(0700, fileperms($this->service->directory()) & 0777);
        foreach (glob($this->service->directory() . '/*.json') as $file) {
            $this->assertSame(0600, fileperms($file) & 0777);
        }
        $this->expectException(RuntimeException::class);
        new Excel_export(ROOTPATH . 'writable/exports', 'https://app.invalid/api/');
    }

    public function testMatchingCliRuntimeAndFixedRegistry(): void
    {
        $this->assertSame(PHP_BINARY, $this->service->matching_php());
        $this->assertNull(Excel_export::base_method('Reports', 'arbitrary_excel'));
        $this->assertNull(Excel_export::base_method('Cron', 'snapshot_item_inventory_excel'));
        $this->assertCount(18, self::sources());
        foreach (Excel_export::SOURCES as $class => $methods) {
            foreach ($methods as $method => $base) {
                $reflection = new ReflectionMethod('App\\Controllers\\' . $class, $method);
                $this->assertSame('App\\Controllers\\' . $class, $reflection->getDeclaringClass()->getName());
                $this->assertTrue($reflection->isPublic());
            }
        }
    }

    private function sheets(string $bytes): array
    {
        $path = $this->storage . '/inspect.xlsx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));
        $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $sheets = [];
        foreach ($workbook->sheets->sheet as $index => $sheet) {
            $number = count($sheets) + 1;
            $xml = $zip->getFromName('xl/worksheets/sheet' . $number . '.xml');
            $this->assertNotFalse(simplexml_load_string($xml));
            $sheets[] = ['name' => (string) $sheet['name'], 'xml' => $xml];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertNotFalse(simplexml_load_string($zip->getFromIndex($i)));
        }
        $zip->close();
        unlink($path);
        return $sheets;
    }

    public function testWorkbookPreservesValuesTypesNestedLinksAndVariableColumns(): void
    {
        $response = (object) ['size' => 2, 'summary' => (object) ['total' => 4.5], 'data' => [
            (object) ['__export_row_id' => 'source field', 'id' => '0012', 'formula' => '=HYPERLINK("bad")', 'zero' => 0,
                'nullable' => null, 'boolean' => false, 'unicode' => '漢字 🥼', 'text' => 'a_x000D_b' . "\r\x01",
                'child' => (object) ['amount' => 4.5], 'details' => [(object) ['name' => 'first'], (object) ['name' => 'second']]],
            (object) ['id' => '0', 'later_column' => '+SUM(A1)', 'huge_id' => PHP_INT_MAX, 'details' => []],
        ]];
        $path = $this->storage . '/book.xlsx';
        (new Excel_workbook())->write($path, $response, 'fixture');
        $sheets = $this->sheets(file_get_contents($path));
        $xml = implode('', array_column($sheets, 'xml'));
        $this->assertStringContainsString('___export_row_id', $xml);
        $this->assertStringContainsString('__export_row_id', $xml);
        $this->assertStringContainsString('later_column', $xml);
        $this->assertStringContainsString('=HYPERLINK(&quot;bad&quot;)', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">0012</t>', $xml);
        $this->assertStringContainsString('t="n"><v>0</v>', $xml);
        $this->assertStringContainsString('t="n"><v>4.5</v>', $xml);
        $this->assertStringContainsString('t="b"><v>0</v>', $xml);
        $this->assertStringContainsString('漢字 🥼', $xml);
        $this->assertStringContainsString('_x005F_x000D_', $xml);
        $this->assertStringContainsString('_x0001_', $xml);
        $this->assertStringContainsString('location="&apos;details_', $xml);
        $this->assertStringContainsString('__export_parent_id', $xml);
        $this->assertSame([], glob($path . '.work.*'));
        $this->assertStringContainsString((string) PHP_INT_MAX, $xml);
    }

    public function testContinuationSheetsAndLongTextAreLossless(): void
    {
        $long = str_repeat('😀 leading 0012 + =SUM(A1) ', 100);
        $data = [];
        for ($i = 0; $i < 5; $i++) {
            $data[] = (object) ['id' => $i, 'a' => 'a' . $i, 'b' => 'b' . $i, 'c' => 'c' . $i, 'd' => $i === 0 ? $long : null];
        }
        $path = $this->storage . '/continuations.xlsx';
        (new Excel_workbook(4, 6, 64))->write($path, (object) ['data' => $data], 'fixture');
        $sheets = $this->sheets(file_get_contents($path));
        $names = array_column($sheets, 'name');
        $this->assertContains('Data_1_c2', $names);
        $this->assertContains('Data_1_r2', $names);
        $parts = [];
        foreach ($sheets as $sheet) {
            $xml = simplexml_load_string($sheet['xml']);
            $this->assertLessThanOrEqual(4, count($xml->sheetData->row));
            foreach ($xml->sheetData->row as $row) {
                $this->assertLessThanOrEqual(6, count($row->c));
                foreach ($row->c as $cell) {
                    if (isset($cell->is->t)) {
                        $this->assertLessThanOrEqual(64, intdiv(strlen(mb_convert_encoding((string) $cell->is->t, 'UTF-16LE', 'UTF-8')), 2));
                    }
                }
            }
            // Long_text has horizontal continuation: text field is on column-part 2.
            if (str_starts_with($sheet['name'], 'Long_text_') && str_contains($sheet['name'], '_c2')) {
                foreach ($xml->sheetData->row as $index => $row) {
                    if ((string) $row['r'] !== '1') {
                        foreach ($row->c as $cell) {
                            if (preg_match('/^F/', (string) $cell['r'])) {
                                $parts[] = (string) $cell->is->t;
                            }
                        }
                    }
                }
            }
        }
        $this->assertStringContainsString($long, implode('', $parts));
        $this->assertSame([], glob($path . '.work.*'));
    }
}
