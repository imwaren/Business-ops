<?php

use App\Models\Branch_master;
use App\Models\Item_inventory_daily;
use App\Models\Item_inventory_monthly;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Inventory_report_controller;

final class ItemInventoryReportExcelTest extends CIUnitTestCase
{
    private $state;
    private $controller;
    private $initial_files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initial_files = glob(WRITEPATH.'item_inventory_reports/*') ?: [];
        $this->state = (object) [
            'rows' => [$this->row(1)], 'snapshot' => '2026-10-15', 'calls' => [],
            'snapshot_calls' => [], 'monthly' => [], 'monthly_calls' => [], 'fail_page' => null,
        ];
        $state = $this->state;
        $daily = $this->getMockBuilder(Item_inventory_daily::class)->disableOriginalConstructor()
            ->onlyMethods(['latest_date_in_range', 'get_breakdown'])->getMock();
        $daily->method('latest_date_in_range')->willReturnCallback(function (...$arguments) use ($state) {
            $state->snapshot_calls[] = $arguments;
            return $state->snapshot;
        });
        $daily->method('get_breakdown')->willReturnCallback(function (...$arguments) use ($state) {
            $state->calls[] = $arguments;
            if ($arguments[10] ?? false) {
                return count($state->rows);
            }
            $page = $arguments[5];
            $size = $arguments[6];
            if ($page !== null && $page === $state->fail_page) {
                return false;
            }
            return $page === null && $size === null ? $state->rows
                : array_slice($state->rows, (max(1, (int) $page) - 1) * $size, $size);
        });
        $monthly = $this->getMockBuilder(Item_inventory_monthly::class)->disableOriginalConstructor()
            ->onlyMethods(['get_months_in_range'])->getMock();
        $monthly->method('get_months_in_range')->willReturnCallback(function (...$arguments) use ($state) {
            $state->monthly_calls[] = $arguments;
            return $state->monthly;
        });
        $branches = $this->getMockBuilder(Branch_master::class)->disableOriginalConstructor()
            ->onlyMethods(['select'])->getMock();
        $branches->method('select')->willReturnCallback(function ($fields, $where, $limit = null) {
            $names = [1 => 'Mobile', 2 => 'Oakridge', 3 => 'Mactan', 4 => 'Consolacion',
                5 => 'Warehouse 倉庫 & Supply', 6 => 'Pharmacy', 7 => 'Mantlewood'];
            if ($limit === 1) {
                return (object) ['name' => $names[$where['id']]];
            }
            $rows = [];
            foreach ($names as $id => $name) {
                $rows[] = (object) compact('id', 'name');
            }
            return $rows;
        });
        $this->controller = new Inventory_report_controller($daily, $monthly, $branches);
    }

    protected function tearDown(): void
    {
        foreach ($this->controller->local_paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertSame($this->initial_files, glob(WRITEPATH.'item_inventory_reports/*') ?: []);
        parent::tearDown();
    }

    private function row($id)
    {
        return [
            'snapshot_date' => '2026-10-15', 'branch_id' => '5', 'item_id' => (string) $id,
            'item_name' => sprintf('Item %03d', $id), 'item_brand' => 'Fixture brand', 'type' => 'stock',
            'kind' => 'pharmacy', 'selling_price' => '10.25', 'philhealth_medicine_type' => 'regular',
            'unit_name' => 'Box', 'current_qty' => '2.5000', 'weighted_cost' => '4.1234', 'ending_cost' => '10.3085',
        ];
    }

    private function payload($changes = [])
    {
        return array_replace(['tab' => 'breakdown', 'branch_id' => 5,
            'date_from' => '2026-10-01', 'date_to' => '2026-10-16'], $changes);
    }

    private function exported($payload = null)
    {
        $response = $this->controller->export($payload ?? $this->payload());
        $this->assertSame(200, $response['status'], json_encode($response));
        $this->assertSame(['file_name', 'file_url'], array_keys($response['data']));
        $this->assertStringEndsWith('.xlsx', $response['data']['file_name']);
        $this->assertSame(rtrim(VM4_ENDPOINT, '/').'/assets/mock/inventory.xlsx', $response['data']['file_url']);
        $upload = end($this->controller->uploads);
        $this->assertSame($response['data']['file_name'], $upload['file_name']);
        foreach ($upload['parts'] as $xml) {
            $document = new DOMDocument();
            $this->assertTrue($document->loadXML($xml));
        }
        return $upload;
    }

    private function worksheet($upload)
    {
        $document = new DOMDocument();
        $document->loadXML($upload['parts']['xl/worksheets/sheet1.xml']);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return $xpath;
    }

    private function cell($xpath, $reference)
    {
        return $xpath->evaluate('string(//s:c[@r="'.$reference.'"]/s:v | //s:c[@r="'.$reference.'"]/s:is/s:t)');
    }

    public function testBreakdownIncludesReportFieldsSnapshotAndNumericStyles()
    {
        $json = $this->controller->report($this->payload());
        $upload = $this->exported();
        $sheet = $this->worksheet($upload);
        $this->assertSame('Inventory Breakdown', $this->cell($sheet, 'A1'));
        $this->assertStringContainsString('Warehouse 倉庫 & Supply (5)', $this->cell($sheet, 'A3'));
        $this->assertStringContainsString('Snapshot: '.$json['data']['snapshot_date'], $this->cell($sheet, 'A3'));
        $this->assertSame('Item Name', $this->cell($sheet, 'D5'));
        $row = $json['data']['data'][0];
        foreach (array_values($row) as $index => $value) {
            $this->assertSame($value, $this->cell($sheet, chr(65 + $index).'6'));
        }
        foreach (['B6' => '0', 'C6' => '0', 'H6' => '4', 'K6' => '3', 'L6' => '3', 'M6' => '4'] as $cell => $style) {
            $this->assertSame($style, $sheet->evaluate('string(//s:c[@r="'.$cell.'"]/@s)'));
            $this->assertSame('', $sheet->evaluate('string(//s:c[@r="'.$cell.'"]/@t)'));
        }
        $this->assertSame('A5:M6', $sheet->evaluate('string(//s:autoFilter/@ref)'));
        $this->assertSame('A6', $sheet->evaluate('string(//s:pane/@topLeftCell)'));
        $this->assertSame('assets/reports/item_inventory/breakdown/2026-10-01_2026-10-16/', $upload['vm4_path']);
    }

    public function testAllMatchingRowsAreExportedBeyondPaginationBoundaries()
    {
        $this->state->rows = array_map(fn($id) => $this->row($id), range(1, 205));
        $upload = $this->exported($this->payload(['page' => 999, 'data_size' => 1, 'type' => 'stock',
            'kind' => 'pharmacy,laboratory', 'term' => "Item's", 'conversion_type' => 'usage', 'philhealth_medicine_type' => 'regular,yakap']));
        $this->assertSame([[5, '2026-10-01', '2026-10-16']], $this->state->snapshot_calls);
        $this->assertSame([5], $this->controller->resolved_branches);
        $this->assertSame([
            [5, '2026-10-15', 'stock', 'pharmacy,laboratory', "Item's", 1, 100, 'usage', 'regular,yakap', false, false],
            [5, '2026-10-15', 'stock', 'pharmacy,laboratory', "Item's", 2, 100, 'usage', 'regular,yakap', false, false],
            [5, '2026-10-15', 'stock', 'pharmacy,laboratory', "Item's", 3, 100, 'usage', 'regular,yakap', false, false],
        ], $this->state->calls);
        $sheet = $this->worksheet($upload);
        foreach ([1, 100, 101, 200, 201, 205] as $id) {
            $this->assertSame((string) $id, $this->cell($sheet, 'C'.($id + 5)));
        }
        $this->assertSame(205, (int) $sheet->evaluate('count(//s:row[@r >= 6])'));
        $this->assertSame('A5:M210', $sheet->evaluate('string(//s:autoFilter/@ref)'));
    }

    public function testExactHundredRowBatchFetchesAnEmptyNextPage()
    {
        $this->state->rows = array_map(fn($id) => $this->row($id), range(1, 100));
        $sheet = $this->worksheet($this->exported());
        $this->assertCount(2, $this->state->calls);
        $this->assertSame(100, (int) $sheet->evaluate('count(//s:row[@r >= 6])'));
    }

    public function testJsonPaginationAndResponseFieldsRemainUnchanged()
    {
        $this->state->rows = array_map(fn($id) => $this->row($id), range(1, 205));
        $response = $this->controller->report($this->payload(['page' => 2, 'data_size' => 20]));
        $this->assertSame(200, $response['status']);
        $this->assertSame(['tab', 'snapshot_date', 'date_from', 'date_to', 'size', 'data'], array_keys($response['data']));
        $this->assertSame(205, $response['data']['size']);
        $this->assertSame(array_slice($this->state->rows, 20, 20), $response['data']['data']);
        $this->assertSame([2, 20], array_slice($this->state->calls[0], 5, 2));
        $this->assertSame([null, null], array_slice($this->state->calls[1], 5, 2));
        $this->assertSame([], $this->controller->uploads);
    }

    public function testPerBranchExportMatchesJsonMonthColumnsBranchOrderAndTotals()
    {
        $this->state->monthly = [
            ['snapshot_month' => '2026-09-01', 'branch_id' => 5, 'ending_cost' => '123.4567'],
            ['snapshot_month' => '2026-10-01', 'branch_id' => 5, 'ending_cost' => '2.0000'],
            ['snapshot_month' => '2026-10-01', 'branch_id' => 4, 'ending_cost' => '0.2500'],
        ];
        $payload = $this->payload(['tab' => 'per_branch', 'date_from' => '2026-09-01', 'date_to' => '2026-10-31']);
        $report = $this->controller->report($payload)['data'];
        $sheet = $this->worksheet($this->exported($payload));
        $this->assertSame('Inventory per Branch', $this->cell($sheet, 'A1'));
        $this->assertSame('September', $this->cell($sheet, 'C5'));
        $this->assertSame('October', $this->cell($sheet, 'D5'));
        foreach ($report['branches'] as $index => $branch) {
            $row = $index + 6;
            $this->assertSame((string) $branch['branch_id'], $this->cell($sheet, 'A'.$row));
            $this->assertSame($branch['branch_name'], $this->cell($sheet, 'B'.$row));
            $this->assertEquals($branch['September'], $this->cell($sheet, 'C'.$row));
            $this->assertEquals($branch['October'], $this->cell($sheet, 'D'.$row));
        }
        $this->assertSame('Total', $this->cell($sheet, 'B13'));
        $this->assertEquals($report['total']['September'], $this->cell($sheet, 'C13'));
        $this->assertEquals($report['total']['October'], $this->cell($sheet, 'D13'));
        $this->assertSame('A5:D12', $sheet->evaluate('string(//s:autoFilter/@ref)'));
        $this->assertSame([], $this->state->calls);
        $this->assertSame([0, 0], $this->controller->resolved_branches);
    }

    public function testEmptyMonthlyDataStillExportsSevenZeroBranchesAndTotals()
    {
        $sheet = $this->worksheet($this->exported($this->payload(['tab' => 'per_branch'])));
        for ($row = 6; $row <= 13; $row++) {
            $this->assertSame('0', $this->cell($sheet, 'C'.$row));
        }
    }

    public function testManyMonthsUseValidColumnsBeyondZ()
    {
        $sheet = $this->worksheet($this->exported($this->payload([
            'tab' => 'per_branch', 'date_from' => '2024-01-01', 'date_to' => '2027-04-30',
        ])));
        $this->assertSame('January', $this->cell($sheet, 'AA5'));
        $this->assertSame('April', $this->cell($sheet, 'AP5'));
        $this->assertSame('0', $this->cell($sheet, 'AP13'));
    }

    public function testFormulaLikeDescriptionsUnicodeNullsAndZeroStaySafe()
    {
        $this->state->rows[0] = array_replace($this->row(1), [
            'item_name' => '=HYPERLINK("https://example.invalid", "漢字 & <test>")',
            'item_brand' => '+SUM(1,2)', 'type' => '-1+1', 'kind' => '@SUM(1,2)',
            'unit_name' => '0012', 'philhealth_medicine_type' => null, 'selling_price' => '0',
        ]);
        $sheet = $this->worksheet($this->exported());
        foreach (['D6' => 'item_name', 'E6' => 'item_brand', 'F6' => 'type', 'G6' => 'kind', 'J6' => 'unit_name'] as $cell => $field) {
            $this->assertSame($this->state->rows[0][$field], $this->cell($sheet, $cell));
            $this->assertSame('inlineStr', $sheet->evaluate('string(//s:c[@r="'.$cell.'"]/@t)'));
        }
        $this->assertSame('0', $this->cell($sheet, 'H6'));
        $this->assertSame('', $this->cell($sheet, 'I6'));
        $this->assertSame(0, (int) $sheet->evaluate('count(//s:f)'));
    }

    /** @dataProvider invalid_payloads */
    public function testValidationMatchesJsonAndSkipsUpload($changes, $expected_message)
    {
        $payload = $this->payload($changes);
        $json = $this->controller->report($payload);
        $excel = $this->controller->export($payload);
        $this->assertSame(['status' => 400, 'data' => [$expected_message]], $json);
        $this->assertSame($json, $excel);
        $this->assertSame([], $this->controller->local_paths);
        $this->assertSame([], $this->controller->uploads);
    }

    public function invalid_payloads()
    {
        return [
            'tab' => [['tab' => null], 'tab is required. Allowed values: breakdown, per_branch.'],
            'missing dates' => [['date_from' => null], 'date_from and date_to are required.'],
            'date format' => [['date_to' => '16-10-2026'], 'Invalid date. Use YYYY-MM-DD.'],
            'reversed dates' => [['date_from' => '2026-11-01'], 'date_from must not be after date_to.'],
            'branch' => [['branch_id' => null], 'Branch ID is required.'],
            'master branch' => [['branch_id' => '0'], 'Branch ID is required.'],
            'unknown branch' => [['branch_id' => 8], 'Invalid Branch ID.'],
            'conversion' => [['conversion_type' => 'unknown'], 'Invalid conversion_type. Allowed values: purchase, clinic, usage.'],
        ];
    }

    public function testNoSnapshotAndNoMatchingItemsRetainNotFoundResponses()
    {
        $this->state->snapshot = null;
        $response = $this->controller->export($this->payload());
        $this->assertSame(['status' => 404, 'data' => ['No inventory snapshot found for the selected dates.']], $response);
        $this->state->snapshot = '2026-10-15';
        $this->state->rows = [];
        $response = $this->controller->export($this->payload());
        $this->assertSame(['status' => 404, 'data' => ['No inventory found.']], $response);
        $this->assertSame([], $this->controller->uploads);
    }

    public function testAuthenticationFailuresSkipReportQueriesAndFiles()
    {
        $this->controller->authenticated = false;
        $this->assertSame(401, $this->controller->export($this->payload())['status']);
        $this->assertSame(401, $this->controller->report($this->payload())['status']);
        $this->assertSame([], $this->state->calls);
        $this->assertSame([], $this->controller->resolved_branches);
        $this->assertSame([], $this->controller->local_paths);
    }

    public function testQueryFailuresDoNotUploadPartialWorkbooks()
    {
        $this->state->fail_page = 1;
        $response = $this->controller->export($this->payload());
        $this->assertSame(['status' => 400, 'data' => ['Unable to retrieve inventory report rows.']], $response);
        $this->assertSame([], $this->controller->local_paths);
        $this->state->rows = array_map(fn($id) => $this->row($id), range(1, 101));
        $this->state->fail_page = 2;
        $response = $this->controller->export($this->payload());
        $this->assertSame(['status' => 400, 'data' => ['Unable to retrieve inventory report rows.']], $response);
        $this->assertCount(1, $this->controller->local_paths);
        $this->assertSame([], $this->controller->uploads);
    }

    public function testGenerationFailureCleansPartialFilesAndSkipsUpload()
    {
        $this->controller->generation_failure = true;
        $response = $this->controller->export($this->payload());
        $this->assertSame(['status' => 400, 'data' => ['Failed to generate or upload inventory report Excel file.']], $response);
        $this->assertSame([], $this->controller->uploads);
        $this->assertCount(1, $this->controller->local_paths);
    }

    public function testUploadFailureAndExceptionCleanTemporaryFiles()
    {
        foreach ([false, ['success' => false, 'error' => 'Fixture upload failure'], new RuntimeException('Fixture upload exception')] as $result) {
            $this->controller->upload_result = $result;
            $response = $this->controller->export($this->payload());
            $this->assertSame(400, $response['status']);
            $this->assertArrayNotHasKey('file_url', $response['data']);
        }
        $this->assertCount(3, $this->controller->uploads);
    }

    public function testTodayExportUsesLiveRowsOnEveryPageAndSkipsSnapshotLookup()
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $this->state->snapshot = null;
        $this->state->rows = array_map(function ($id) use ($today) {
            return array_replace($this->row($id), ['snapshot_date' => $today]);
        }, range(1, 205));
        $upload = $this->exported($this->payload(['date_from'=>$today, 'date_to'=>$today]));
        $this->assertSame([], $this->state->snapshot_calls);
        $sheet = $this->worksheet($upload);
        $this->assertSame('205', $this->cell($sheet, 'C210'));
        $this->assertCount(4, $this->state->calls);
        foreach ($this->state->calls as $call) {
            $this->assertSame($today, $call[1]);
            $this->assertTrue($call[9]);
        }
        $this->assertTrue($this->state->calls[1][10]);
    }

    public function testSimultaneousExportNamesCannotOverwriteOneAnother()
    {
        $first = $this->exported();
        $second = $this->exported();
        $this->assertNotSame($first['file_name'], $second['file_name']);
        $this->assertNotSame($first['local_path'], $second['local_path']);
    }
}
