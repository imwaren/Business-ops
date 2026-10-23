<?php

use App\Models\Branch_item_transfer_item;
use App\Models\Receipt_note_item;
use App\Models\Receipt_note_item_expiry_date;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Receipt_batch_controller;
use Tests\Support\Controllers\Transfer_batch_controller;
use Tests\Support\Database\Receipt_batch_fixture;
use Tests\Support\Database\Transfer_accounting_fixture;

final class TransferBatchTest extends CIUnitTestCase
{
    private static $fixture_ready = false;
    private $controller;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (getenv('RECEIPT_BATCH_TEST_SOCKET')) {
            Receipt_batch_fixture::create();
            self::$fixture_ready = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$fixture_ready) {
            $this->markTestSkipped('Set RECEIPT_BATCH_TEST_SOCKET to an explicitly prepared socket-only fixture server.');
        }
        Receipt_batch_fixture::reset();
        $this->controller = new Transfer_batch_controller();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture_ready) {
            Receipt_batch_fixture::destroy();
        }
        parent::tearDownAfterClass();
    }

    private function line($qty = 1, $batch_no = 12, $unit = 11)
    {
        return ['item_id' => 1, 'branch_item_id' => $unit === 11 ? 1 : ($unit === 12 ? 2 : 3),
            'unit' => $unit, 'transfer_qty' => $qty, 'batch_no' => $batch_no];
    }

    private function submit($items, $branch_id_to = 3, $branch_id_from = 2)
    {
        // Existing scenarios exercise pre-linkage pooled rows; new linked submissions use submit() directly.
        return $this->controller->submit_legacy(['branch_id_from' => $branch_id_from, 'branch_id_to' => $branch_id_to,
            'transfer_date' => '2026-10-15', 'items' => $items]);
    }

    private function successful($result)
    {
        $this->assertSame(200, $result['status'], json_encode($result['data']));
        return $result['data'];
    }

    private function rejected($result, $message = '')
    {
        $this->assertSame(400, $result['status'], json_encode($result['data']));
        if ($message !== '') {
            $this->assertStringContainsString($message, json_encode($result['data']));
        }
    }

    private function line_ids($transfer_id)
    {
        return Receipt_batch_fixture::$pdo->query('SELECT id FROM branch_item_transfer_item WHERE branch_item_transfer_id='.(int) $transfer_id.' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function receive($transfer_id, $ids, $qtys)
    {
        return $this->controller->change(['transfer_id' => $transfer_id, 'status' => 'received',
            'transfer_item_ids' => $ids, 'received_qtys' => $qtys]);
    }

    public function testReceiptBatchStorageGetFieldsAndReplacement()
    {
        $db = db_connect('oakridge');
        $receipt = new Receipt_batch_controller(new Receipt_note_item($db), new Receipt_note_item_expiry_date($db));
        $result = $receipt->validate_batches([['item_id' => 1, 'qty' => 10, 'unit' => 11, 'batch_no' => ' 12,13,12 ',
            'expiry_dates' => [['qty' => 2, 'batch_no' => 12], ['qty' => 3, 'batch_no' => 12], ['qty' => 4, 'batch_no' => 13]]]]);
        $this->assertTrue($result['valid']);
        $this->assertTrue($receipt->save_items(1));
        $schema = Receipt_batch_fixture::$schemas[2];
        $id = (int) Receipt_batch_fixture::$pdo->query("SELECT MAX(id) FROM `$schema`.receipt_note_item")->fetchColumn();
        $row = Receipt_batch_fixture::$pdo->query("SELECT batch_no FROM `$schema`.receipt_note_item WHERE id=$id")->fetchColumn();
        $this->assertSame('12,13', $row);
        $expiries = (new Receipt_note_item_expiry_date($db))->get_details($id);
        $this->assertSame(['12', '12', '13'], array_column($expiries, 'batch_no'));
        // Render only the configured fixture master name for the real receipt GET query.
        $model = new class($db) extends Receipt_note_item {
            protected function _resolve_cross_db_sql(string $sql): string
            {
                return str_replace('{MASTER_DB}', Receipt_batch_fixture::$schemas[0], $sql);
            }
        };
        $this->assertSame('12,13', array_column($model->get_details(1), null, 'id')[$id]['batch_no']);
        $result = $receipt->validate_batches([['id' => $id, 'item_id' => 1, 'qty' => 10, 'unit' => 11,
            'batch_no' => '12', 'expiry_dates' => [['id' => $expiries[0]['id'], 'qty' => 2]]]], 1);
        $this->assertTrue($result['valid']);
        $this->assertTrue($receipt->save_items(1, true));
        $this->assertCount(1, (new Receipt_note_item_expiry_date($db))->get_details($id));
        $this->assertSame(2, (int) Receipt_batch_fixture::$pdo->query("SELECT COUNT(*) FROM `$schema`.receipt_note_item_expiry_date WHERE receipt_note_item_id=$id AND is_deleted=1")->fetchColumn());
    }

    public function testBatchQuantitiesUseExpiryRowsAndSourceBranches()
    {
        $model = new Branch_item_transfer_item(db_connect('master'));
        $receipts = $model->get_batch_receipts(1, 2, 12);
        $this->assertCount(3, $receipts);
        $total = 0;
        foreach ($receipts as $row) {
            $total += $row['qty'] / $row['conversion_qty'];
        }
        $this->assertEqualsWithDelta(7, $total, 0.00001);
        $this->assertSame([], $model->get_batch_receipts(1, 3, 12));
        $this->rejected($this->submit([$this->line(5, 13)]), 'batch 13');
        $this->rejected($this->submit([$this->line(11, null)]), 'source stock');
        $this->rejected($this->submit([$this->line(4), $this->line(4)]), 'batch 12');
        $this->rejected($this->submit([$this->line(1, '12,13')]));
        $this->rejected($this->submit([$this->line(1, '2147483648')]));
        $this->rejected($this->submit([$this->line(-1)]));
        $this->rejected($this->submit([$this->line(0)]));
        $bad = $this->line();
        $bad['branch_item_id'] = 4;
        $this->rejected($this->submit([$bad]), 'source branch');
        $this->successful($this->submit([$this->line(6)]));
        $this->rejected($this->submit([$this->line(2)], 4), 'batch 12');
    }

    public function testPartialReceiptsMoveOnlyDeltasAndRetainReservations()
    {
        $starting_rows = $this->successful($this->controller->report(['item_id' => 1, 'batch_no' => 12]))['data'];
        $transfer_id = $this->successful($this->submit([$this->line(6)]))['branch_item_transfer_id'];
        $ids = $this->line_ids($transfer_id);
        $this->successful($this->controller->change(['transfer_id' => $transfer_id, 'status' => 'approved']));
        $this->successful($this->controller->change(['transfer_id' => $transfer_id, 'status' => 'received',
            'item_ids' => [1], 'received_qtys' => [2]]));
        $pdo = Receipt_batch_fixture::$pdo;
        $this->assertSame('opened', $pdo->query("SELECT status FROM branch_item_transfer WHERE id=$transfer_id")->fetchColumn());
        $partial = $this->successful($this->controller->report(['item_id' => 1, 'batch_no' => 12]));
        $moves = array_values(array_filter($partial['data'], static function ($row) { return $row['activity'] === 'Transfer'; }));
        $partial_receipts = $starting_rows;
        $partial_receipts[0]['quantity'] = '1.0000';
        $this->assertSame($partial_receipts, array_values(array_filter($partial['data'], static function ($row) {
            return $row['activity'] === 'Receipt';
        })));
        $this->assertCount(1, $moves);
        $this->assertEquals(2, $moves[0]['quantity']);
        $this->rejected($this->submit([$this->line(2)], 4), 'batch 12');
        $history = $pdo->query('SELECT COUNT(*) FROM item_history')->fetchColumn();
        $this->rejected($this->receive($transfer_id, $ids, [2]), 'No additional');
        $this->assertSame($history, $pdo->query('SELECT COUNT(*) FROM item_history')->fetchColumn());
        $this->rejected($this->receive($transfer_id, $ids, [1]));
        $this->rejected($this->receive($transfer_id, $ids, [7]));
        $this->rejected($this->controller->change(['transfer_id' => $transfer_id, 'status' => 'disapproved']));
        $this->successful($this->receive($transfer_id, $ids, [6]));
        $completed = $this->successful($this->controller->report(['item_id' => 1, 'batch_no' => 12]));
        $completed_receipts = $starting_rows;
        foreach ($completed_receipts as $index => $row) {
            $completed_receipts[$index]['quantity'] = (int) $row['expiry_id'] === 2 ? '1.0000' : '0.0000';
        }
        $this->assertSame($completed_receipts, array_values(array_filter($completed['data'], static function ($row) {
            return $row['activity'] === 'Receipt';
        })));
        $completed_moves = array_values(array_filter($completed['data'], static function ($row) {
            return $row['activity'] === 'Transfer';
        }));
        $this->assertCount(1, $completed_moves);
        $this->assertEquals(6, $completed_moves[0]['quantity']);
        $this->assertSame($partial['size'], $completed['size']);
        foreach (Receipt_batch_fixture::$schemas as $branch_id => $schema) {
            $this->assertEquals($branch_id === 2 ? 4 : 10, $pdo->query("SELECT current_qty FROM `$schema`.branch_item WHERE id=1")->fetchColumn());
            $this->assertEquals($branch_id === 3 ? 6 : 0, $pdo->query("SELECT current_qty FROM `$schema`.branch_item WHERE id=4")->fetchColumn());
            $this->assertEquals(4, $pdo->query("SELECT latest_total FROM `$schema`.item_cost WHERE branch_id=2 ORDER BY id DESC LIMIT 1")->fetchColumn());
        }
        $this->assertSame('received', $pdo->query("SELECT status FROM branch_item_transfer WHERE id=$transfer_id")->fetchColumn());
        $this->assertNotNull($pdo->query("SELECT approved_on FROM branch_item_transfer WHERE id=$transfer_id")->fetchColumn());
        $this->rejected($this->receive($transfer_id, $ids, [6]));
        $line = $this->line(6);
        $line['branch_item_id'] = 4;
        $this->rejected($this->submit([$line], 4, 3), 'source batch stock');
    }

    public function testDifferentBatchesOfOneItemUseLineIds()
    {
        $transfer_id = $this->successful($this->submit([$this->line(20, 12, 12), $this->line(1, 13)]))['branch_item_transfer_id'];
        $this->rejected($this->controller->change(['transfer_id' => $transfer_id, 'status' => 'received',
            'item_ids' => [1], 'received_qtys' => [1]]), 'ambiguous');
        $ids = $this->line_ids($transfer_id);
        $this->rejected($this->receive($transfer_id, [99999], [1]), 'missing');
        $this->successful($this->receive($transfer_id, $ids, [20, 1]));
        $this->assertEquals(7, Receipt_batch_fixture::$pdo->query('SELECT current_qty FROM `'.Receipt_batch_fixture::$schemas[2].'`.branch_item WHERE id=1')->fetchColumn());
        $report = $this->successful($this->controller->report(['item_id' => 1, 'batch_no' => 13]));
        $this->assertSame(2, $report['size']);
        $this->assertSame(['Receipt', 'Transfer'], array_column($report['data'], 'activity'));
        $this->assertSame(['13', '13'], array_column($report['data'], 'batch_no'));
        $this->assertSame('Oakridge', $report['data'][1]['branch_name_from']);
        $this->assertSame('Mactan', $report['data'][1]['branch_name_to']);
    }

    public function testReportReturnsReceiptAllocationsAndReceivedBatchLinesWithMatchingPagination()
    {
        $this->assertSame(4, $this->successful($this->controller->report([]))['size']);
        $this->assertSame(4, $this->successful($this->controller->report(['item_id' => 1]))['size']);
        $this->assertSame(404, $this->controller->report(['item_id' => 2])['status']);
        $this->assertSame(3, $this->successful($this->controller->report(['batch_no' => 12]))['size']);

        $transfer_id = $this->successful($this->submit([$this->line(1), $this->line(2)]))['branch_item_transfer_id'];
        $ids = $this->line_ids($transfer_id);
        $this->assertSame(4, $this->successful($this->controller->report([]))['size']);
        $this->successful($this->receive($transfer_id, $ids, [1, 1]));
        $all = $this->successful($this->controller->report([]));
        $this->assertSame(5, $all['size']);
        $this->assertSame(['Receipt', 'Receipt', 'Receipt', 'Transfer', 'Receipt'], array_column($all['data'], 'activity'));
        $this->assertSame(['12', '12', '12', '12', '13'], array_column($all['data'], 'batch_no'));
        $this->assertEquals(2, $all['data'][3]['quantity']);
        $this->assertEquals(3, $all['data'][3]['transfer_qty']);
        $this->assertSame(implode(',', $ids), $all['data'][3]['transfer_item_id']);
        $filtered = $this->successful($this->controller->report(['item_id' => 1, 'batch_no' => 12]));
        $this->assertSame(4, $filtered['size']);
        $this->assertSame(array_values(array_filter($all['data'], static function ($row) {
            return $row['batch_no'] === '12';
        })), $filtered['data']);
        $page = $this->successful($this->controller->report(['page' => 2, 'data_size' => 1]));
        $this->assertSame(5, $page['size']);
        $this->assertSame([$all['data'][1]], $page['data']);
        $this->assertSame(404, $this->controller->report(['page' => 6, 'data_size' => 1])['status']);
        $this->assertSame(404, $this->controller->report(['batch_no' => 999])['status']);
        $this->assertSame(404, $this->controller->report(['item_id' => 3])['status']);
        $this->rejected($this->controller->report(['item_id' => 0]));
        $this->rejected($this->controller->report(['batch_no' => '12,13']));
        $this->rejected($this->controller->report(['page' => 0]));
    }

    public function testReportTextFiltersKindsAndItemMetadata()
    {
        $transfer_id = $this->successful($this->submit([$this->line(1)]))['branch_item_transfer_id'];
        $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [1]));
        $filters = ['brand' => 'Acme', 'generic' => 'Compound A', 'dosage' => '10 mg', 'form' => 'Tablet'];
        $pdo = Receipt_batch_fixture::$pdo;
        $pdo->exec("UPDATE item SET kind='pharmacy,laboratory' WHERE id=1");
        $report = $this->successful($this->controller->report($filters + ['kind' => 'other, laboratory, laboratory']));
        $this->assertSame(5, $report['size']);
        foreach ($filters as $field => $value) {
            $this->assertSame($value, $report['data'][0][$field]);
            $this->assertSame(404, $this->controller->report([$field => $value.' unknown'])['status']);
            $this->rejected($this->controller->report([$field => []]));
        }
        $this->assertSame('pharmacy,laboratory', $report['data'][0]['kind']);
        $this->assertSame(404, $this->controller->report(['kind' => 'pharm'])['status']);
        $this->assertSame(404, $this->controller->report(['brand' => "Acme' OR 1=1 --"])['status']);
        $this->assertSame($report, $this->successful($this->controller->report(['kind' => ' , '])));
        $this->assertSame($report, $this->successful($this->controller->report(['item_id' => ''])));
        $pdo->exec("UPDATE item SET kind='pharmacy_other' WHERE id=1");
        $this->assertSame(404, $this->controller->report(['kind' => 'pharmacy'])['status']);
        $pdo->exec('UPDATE branch_item_transfer_item SET is_deleted=1');
        $this->assertSame(4, $this->successful($this->controller->report([]))['size']);
        $pdo->exec('UPDATE branch_item_transfer_item SET is_deleted=0; UPDATE branch_item_transfer SET is_deleted=1');
        $this->assertSame(4, $this->successful($this->controller->report([]))['size']);
        $pdo->exec('UPDATE branch_item_transfer SET is_deleted=0; UPDATE item SET is_deleted=1 WHERE id=1');
        $this->assertSame(404, $this->controller->report([])['status']);
    }

    public function testReportAcrossItemsUsesStablePageBoundaries()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $pdo->exec("INSERT INTO branch_item_transfer(id,branch_id_from,branch_id_to,status,added_on)
            VALUES(9001,2,3,'received','2026-10-15 10:00:00');
            INSERT INTO branch_item_transfer_item(id,branch_item_transfer_id,item_id,unit,transfer_qty,received_qty,batch_no,added_on)
            VALUES(9001,9001,2,NULL,4,4,13,'2026-10-15 10:00:00'),
                (9002,9001,1,11,2,2,12,'2026-10-15 10:00:00');");
        $all = $this->successful($this->controller->report([]));
        $this->assertSame(['1', '1', '1', '1', '1', '2'], array_map('strval', array_column($all['data'], 'item_id')));
        $page = $this->successful($this->controller->report(['page' => 6, 'data_size' => 1]));
        $this->assertSame(6, $page['size']);
        $this->assertSame([$all['data'][5]], $page['data']);
        $this->assertSame(1, $this->successful($this->controller->report(['item_id' => 2]))['size']);
        $this->assertSame(1, $this->successful($this->controller->report(['dosage' => '0']))['size']);
        $this->assertSame($page['data'], $this->successful($this->controller->report(['brand' => 'Beta']))['data']);
    }

    public function testReportTermSearchesReceiptOnlyItemNamesAndSkipsEmptyTerms()
    {
        $all = $this->successful($this->controller->report([]));
        $this->assertSame(4, $all['size']);
        foreach ([null, '', '   ', "\t\n"] as $term) {
            $this->assertSame($all, $this->successful($this->controller->report(['term' => $term])));
        }
        foreach (['stock', '  stock  ', 'STOCK', 'Fixture stock item'] as $term) {
            $this->assertSame($all, $this->successful($this->controller->report(['term' => $term])));
        }
        foreach (['Acme', 'Compound A', 'pharmacy', 'not a fixture name'] as $term) {
            $this->assertSame(404, $this->controller->report(['term' => $term])['status']);
        }
    }

    public function testReportTermFiltersReceiptAndTransferRowsBeforePaginationAndCounting()
    {
        $transfer_id = $this->successful($this->submit([$this->line(1)]))['branch_item_transfer_id'];
        $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [1]));
        Receipt_batch_fixture::$pdo->exec("INSERT INTO branch_item_transfer(id,branch_id_from,branch_id_to,status,added_on)
            VALUES(9001,2,3,'received','2026-10-15 10:00:00');
            INSERT INTO branch_item_transfer_item(id,branch_item_transfer_id,item_id,unit,transfer_qty,received_qty,batch_no,added_on)
            VALUES(9001,9001,2,NULL,4,4,'12','2026-10-15 10:00:00')");
        $all = $this->successful($this->controller->report(['term' => 'stock']));
        $this->assertSame(5, $all['size']);
        $this->assertSame(['Receipt', 'Receipt', 'Receipt', 'Transfer', 'Receipt'], array_column($all['data'], 'activity'));
        $this->assertSame(['1'], array_values(array_unique(array_map('strval', array_column($all['data'], 'item_id')))));
        $model = new Branch_item_transfer_item(db_connect('master'));
        $this->assertSame($all['data'], $model->get_transfer_report(null, null, null, null, null, null, null, null, null, '  stock  '));
        $this->assertSame(5, $model->get_transfer_report_count(null, null, null, null, null, null, null, 'stock'));
        $filters = ['item_id' => 1, 'batch_no' => '12', 'brand' => 'Acme', 'generic' => 'Compound A',
            'dosage' => '10 mg', 'form' => 'Tablet', 'kind' => 'other,pharmacy', 'term' => 'stock'];
        $filtered = $this->successful($this->controller->report($filters));
        $this->assertSame(4, $filtered['size']);
        $this->assertSame(404, $this->controller->report(array_replace($filters, ['brand' => 'Beta']))['status']);
        $this->assertSame(404, $this->controller->report(array_replace($filters, ['term' => 'without']))['status']);
        foreach ([1, 2, 3] as $page) {
            $result = $this->successful($this->controller->report(['term' => 'stock', 'page' => $page, 'data_size' => 2]));
            $this->assertSame(5, $result['size']);
            $this->assertSame(array_slice($all['data'], ($page - 1) * 2, 2), $result['data']);
        }
        $this->assertSame(404, $this->controller->report(['term' => 'stock', 'page' => 4, 'data_size' => 2])['status']);
        $this->assertSame(6, $this->successful($this->controller->report([]))['size']);
    }

    public function testReportTermPreservesStringZeroAndBindsQuotedNames()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $this->assertSame(404, $this->controller->report(['term' => '0'])['status']);
        $pdo->exec("UPDATE item SET name='Batch 0 stock' WHERE id=1");
        try {
            $report = $this->successful($this->controller->report(['term' => '0']));
            $this->assertSame(4, $report['size']);
            $this->assertSame($report, $this->successful($this->controller->report(['term' => ' 0 '])));
            $pdo->exec("UPDATE item SET name='O''Brien stock' WHERE id=1");
            $this->assertSame(4, $this->successful($this->controller->report(['term' => "O'Brien"]))['size']);
            $this->assertSame(404, $this->controller->report(['term' => "' OR 1=1 --"])['status']);
        } finally {
            $pdo->exec("UPDATE item SET name='Fixture stock item' WHERE id=1");
        }
    }

    public function testReportTermRetainsExistingLikeWildcardBehavior()
    {
        $all = $this->successful($this->controller->report([]));
        foreach (['stock%', 'sto_k', '%stock%'] as $term) {
            $this->assertSame($all, $this->successful($this->controller->report(['term' => $term])));
        }
    }

    public function testReceiptStartingRowsRetainEveryAllocationWithoutInventoryOrTransfers()
    {
        Receipt_batch_fixture::$pdo->exec('DELETE FROM branch_item');
        $report = $this->successful($this->controller->report(['item_id' => 1]));
        $this->assertSame(4, $report['size']);
        $this->assertSame(['Receipt', 'Receipt', 'Receipt', 'Receipt'], array_column($report['data'], 'activity'));
        $this->assertSame(['1', '2', '4', '3'], array_map('strval', array_column($report['data'], 'expiry_id')));
        $this->assertSame(['12', '12', '12', '13'], array_column($report['data'], 'batch_no'));
        $this->assertEquals([3, 2, 20, 4], array_column($report['data'], 'quantity'));
        $this->assertSame(['box', 'box', 'piece', 'box'], array_column($report['data'], 'unit_name'));
        $this->assertSame(['2028-01-01', '2028-02-01', '2028-01-01', '2028-03-01'], array_column($report['data'], 'expiry_date'));
        foreach ($report['data'] as $row) {
            $this->assertSame('2', (string) $row['branch_id']);
            $this->assertSame('Oakridge', $row['branch_name']);
            $this->assertSame('1', (string) $row['receipt_note_id']);
            $this->assertNotNull($row['receipt_note_item_id']);
            $this->assertSame('2026-10-02', $row['movement_date']);
            $this->assertSame($row['quantity'], $row['beginning_qty']);
            foreach (['branch_item_id', 'branch_id_from', 'branch_name_from', 'branch_id_to',
                'branch_name_to', 'transfer_qty', 'transfer_id', 'transfer_item_id', 'current_qty'] as $field) {
                $this->assertNull($row[$field]);
            }
        }
    }

    public function testReceiptReportReturnsStoredBalancesIncludingZeroNullAndFourDecimals()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date
            SET qty_balance=CASE id WHEN 1 THEN 0 WHEN 2 THEN NULL WHEN 3 THEN 1.2345 ELSE 5.6789 END
            WHERE id<=4");
        $before = $this->expiry_balances(2);
        $report = $this->successful($this->controller->report(['item_id' => 1]));
        $this->assertSame(4, $report['size']);
        $this->assertSame(['0.0000', null, '5.6789', '1.2345'], array_column($report['data'], 'quantity'));
        $this->assertSame(['3.0000', '2.0000', '20.0000', '4.0000'], array_column($report['data'], 'beginning_qty'));
        $this->assertSame(['Receipt', 'Receipt', 'Receipt', 'Receipt'], array_column($report['data'], 'activity'));
        $filtered = $this->successful($this->controller->report(['batch_no' => '12', 'page' => 2, 'data_size' => 1]));
        $this->assertSame(3, $filtered['size']);
        $this->assertSame([$report['data'][1]], $filtered['data']);
        $this->assertSame($before, $this->expiry_balances(2));
    }

    public function testReceiptReportReadsEachSourceBranchOnceAndUsesItsUnitMetadata()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $warehouse = Receipt_batch_fixture::$schemas[5];
        foreach (Receipt_batch_fixture::$schemas as $branch_id => $schema) {
            if ($branch_id !== 0) {
                $pdo->exec("UPDATE `$schema`.receipt_note SET branch_id=$branch_id WHERE id=1;
                    UPDATE `$schema`.receipt_note_item_expiry_date SET qty_balance=$branch_id.1234 WHERE id=1");
            }
        }
        $pdo->exec("UPDATE `$warehouse`.item_conversion SET unit='warehouse box' WHERE id=11");
        try {
            $report = $this->successful($this->controller->report(['item_id' => 1]));
            $this->assertSame(28, $report['size']);
            $source_keys = [];
            foreach ($report['data'] as $row) {
                $source_keys[] = $row['branch_id'].':'.$row['expiry_id'];
                if ((int) $row['expiry_id'] === 1) {
                    $this->assertSame($row['branch_id'].'.1234', $row['quantity']);
                    $this->assertEquals(3, $row['beginning_qty']);
                }
                if ((int) $row['unit'] === 11) {
                    $this->assertSame((int) $row['branch_id'] === 5 ? 'warehouse box' : 'box', $row['unit_name']);
                }
            }
            $this->assertCount(28, array_unique($source_keys));
            $this->assertEquals([1, 2, 3, 4, 5, 6, 7], array_values(array_unique(array_column($report['data'], 'branch_id'))));
            $this->assertSame(21, $this->successful($this->controller->report(['batch_no' => '12']))['size']);
        } finally {
            $pdo->exec("UPDATE `$warehouse`.item_conversion SET unit='box' WHERE id=11");
        }
    }

    public function testReceiptReportExcludesInactiveAndMismatchedSources()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $schema = Receipt_batch_fixture::$schemas[2];
        $cases = [
            ['receipt_note_item_expiry_date SET is_deleted=1 WHERE id=1', 3],
            ['receipt_note_item SET is_deleted=1 WHERE id=1', 1],
            ["receipt_note SET status='pending' WHERE id=1", 0],
            ["receipt_note SET status='disapproved' WHERE id=1", 0],
            ['receipt_note SET is_deleted=1 WHERE id=1', 0],
            ['receipt_note_item_expiry_date SET receipt_note_id=2 WHERE id=1', 3],
            ['receipt_note_item_expiry_date SET batch_no=NULL WHERE id=1', 3],
            ["receipt_note_item_expiry_date SET batch_no='  ' WHERE id=1", 3],
        ];
        foreach ($cases as [$update, $expected_size]) {
            Receipt_batch_fixture::reset();
            $pdo->exec("UPDATE `$schema`.".$update);
            $result = $this->controller->report(['item_id' => 1]);
            if ($expected_size === 0) {
                $this->assertSame(404, $result['status']);
            } else {
                $this->assertSame($expected_size, $this->successful($result)['size']);
            }
        }
        Receipt_batch_fixture::reset();
        $pdo->exec('UPDATE item SET is_deleted=1 WHERE id=1');
        $this->assertSame(404, $this->controller->report([])['status']);
    }

    public function testReceiptReportPreservesZeroCaseAndLeadingZeroBatchCodes()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date
            SET batch_no=CASE id WHEN 1 THEN 'AB123' WHEN 2 THEN 'ab123' WHEN 3 THEN '0012' ELSE '0' END,
                qty=CASE WHEN id=4 THEN 0 ELSE qty END,
                qty_balance=CASE WHEN id=4 THEN 0 ELSE qty_balance END WHERE id<=4");
        foreach (['AB123', 'ab123', '0012', '0'] as $code) {
            $report = $this->successful($this->controller->report(['batch_no' => $code]));
            $this->assertSame(1, $report['size']);
            $this->assertSame($code, $report['data'][0]['batch_no']);
            if ($code === '0') {
                $this->assertEquals(0, $report['data'][0]['quantity']);
                $this->assertEquals(0, $report['data'][0]['beginning_qty']);
            }
        }
        foreach (['12', 'Ab123', 'AB123-other', "AB123' OR 1=1 --"] as $code) {
            $this->assertSame(404, $this->controller->report(['batch_no' => $code])['status']);
        }
    }

    public function testReceiptDatesAndTransfersUseStableCombinedOrderingAndPageCounts()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo->exec("UPDATE `$schema`.receipt_note SET receive_date='2026-10-15' WHERE id=1;
            UPDATE `$schema`.receipt_note SET status='approved',receive_date=NULL,added_on='2026-10-01 09:00:00' WHERE id=2;
            INSERT INTO branch_item_transfer(id,branch_id_from,branch_id_to,status,added_on)
                VALUES(9001,2,3,'opened','2026-10-15 10:00:00');
            INSERT INTO branch_item_transfer_item(id,branch_item_transfer_id,item_id,unit,transfer_qty,received_qty,batch_no,updated_on)
                VALUES(9001,9001,1,11,5,2,'12','2026-10-15 11:00:00'),
                    (9002,9001,2,NULL,1,1,'12','2026-10-15 12:00:00')");
        $all = $this->successful($this->controller->report(['batch_no' => '12']));
        $this->assertSame(6, $all['size']);
        $this->assertSame(['Receipt', 'Receipt', 'Receipt', 'Receipt', 'Transfer', 'Transfer'], array_column($all['data'], 'activity'));
        $this->assertSame('2026-10-01', $all['data'][0]['movement_date']);
        $this->assertSame('2028-01-01', $all['data'][0]['expiry_date']);
        $this->assertEquals(1000, $all['data'][0]['quantity']);
        $this->assertSame([null, null], array_column(array_slice($all['data'], 4), 'expiry_date'));
        $this->assertEquals([2, 1], array_column(array_slice($all['data'], 4), 'quantity'));
        $model = new Branch_item_transfer_item(db_connect('master'));
        $this->assertSame(6, $model->get_transfer_report_count(null, '12'));
        foreach ([1, 2, 3] as $page) {
            $result = $this->successful($this->controller->report(['batch_no' => '12', 'page' => $page, 'data_size' => 2]));
            $this->assertSame(6, $result['size']);
            $this->assertSame(array_slice($all['data'], ($page - 1) * 2, 2), $result['data']);
        }
        $this->assertSame(404, $this->controller->report(['batch_no' => '12', 'page' => 4, 'data_size' => 2])['status']);
    }

    public function testReceiptReportKeepsAllocationsWhenUnitMetadataIsUnavailable()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note_item SET unit=999 WHERE id=1");
        $report = $this->successful($this->controller->report(['batch_no' => '13']));
        $this->assertSame(1, $report['size']);
        $this->assertEquals(4, $report['data'][0]['quantity']);
        $this->assertSame('999', (string) $report['data'][0]['unit']);
        $this->assertNull($report['data'][0]['unit_name']);
        $this->assertNull($report['data'][0]['conversion_type']);
    }

    public function testReceiptReportQueryFailureIsAnErrorInsteadOfEmptyResults()
    {
        $schema = Receipt_batch_fixture::$schemas[5];
        Receipt_batch_fixture::$pdo->exec("RENAME TABLE `$schema`.receipt_note_item_expiry_date TO `$schema`.missing_report_expiry_fixture");
        try {
            $this->rejected($this->controller->report([]), 'Failed to load item transfer movements.');
        } finally {
            Receipt_batch_fixture::$pdo->exec("RENAME TABLE `$schema`.missing_report_expiry_fixture TO `$schema`.receipt_note_item_expiry_date");
        }
    }

    public function testDistinctItemFiltersUseMasterAndExcludeBlanksAndDeletedRows()
    {
        $expected = ['brand' => ['Acme', 'Beta'], 'generic' => ['Compound A', 'Compound B'],
            'dosage' => ['0', '10 mg'], 'form' => ['Capsule', 'Tablet']];
        $this->assertSame(['data' => $expected], $this->successful($this->controller->filters()));
        $oakridge = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        $pdo->exec("UPDATE `$oakridge`.item SET brand='Branch-only brand'");
        $this->assertSame(['data' => $expected], $this->successful($this->controller->filters(['branch_id' => 2])));
        $pdo->exec("UPDATE item SET brand='Acme',generic='  ',dosage=NULL,form='' WHERE id=2");
        $expected = ['brand' => ['Acme'], 'generic' => ['Compound A'], 'dosage' => ['10 mg'], 'form' => ['Tablet']];
        $this->assertSame(['data' => $expected], $this->successful($this->controller->filters()));
        $pdo->exec('UPDATE item SET is_deleted=1');
        $this->assertSame(['data' => ['brand' => [], 'generic' => [], 'dosage' => [], 'form' => []]],
            $this->successful($this->controller->filters()));
    }

    public function testBatchLookupUsesExpiryCutoffAndSelectedBranchOnly()
    {
        $payload = ['item_id' => 1, 'branch_id_from' => 2, 'date' => '2028-01-01'];
        $lookup = $this->successful($this->controller->batches($payload));
        $this->assertSame(4, $lookup['size']);
        $this->assertSame(['12', '12', '12', '13'], array_column($lookup['data'], 'batch_no'));
        $ids = array_column($lookup['data'], 'receipt_note_item_expiry_date_id');
        sort($ids, SORT_NUMERIC);
        $this->assertEquals([1, 2, 3, 4], $ids);
        foreach ($lookup['data'] as $row) {
            $this->assertSame($row['expiry_qty'], $row['expiry_qty_balance']);
            $this->assertGreaterThanOrEqual($payload['date'], $row['expiry_date']);
        }
        $this->assertSame($lookup, $this->successful($this->controller->batches(array_replace($payload, ['date' => '2027-12-31']))));
        $later = $this->successful($this->controller->batches(array_replace($payload, ['date' => '2028-03-01'])));
        $this->assertSame(1, $later['size']);
        $this->assertSame(['13'], array_column($later['data'], 'batch_no'));
        $this->assertSame(404, $this->controller->batches(array_replace($payload, ['date' => '2028-03-02']))['status']);
        foreach ([1, 3, 4, 5, 6, 7] as $branch_id) {
            $this->assertSame(404, $this->controller->batches(array_replace($payload, ['branch_id_from' => $branch_id]))['status']);
        }
        $pdo = Receipt_batch_fixture::$pdo;
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no=0 WHERE id=1;
            UPDATE `$schema`.receipt_note_item_expiry_date SET is_deleted=1 WHERE id IN(2,3);
            UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no=NULL WHERE id=4;
            UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no=99 WHERE id IN(5,6)");
        $zero = $this->successful($this->controller->batches($payload));
        $this->assertSame(1, $zero['size']);
        $this->assertSame(['0'], array_column($zero['data'], 'batch_no'));
        $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET is_deleted=1 WHERE id=1");
        $this->assertSame(404, $this->controller->batches($payload)['status']);
        $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET is_deleted=0 WHERE id=1;
            UPDATE `$schema`.receipt_note_item SET is_deleted=1 WHERE id=1");
        $this->assertSame(404, $this->controller->batches($payload)['status']);
        $pdo->exec("UPDATE `$schema`.receipt_note_item SET is_deleted=0;
            UPDATE `$schema`.item SET is_deleted=1 WHERE id=1");
        $this->assertSame(404, $this->controller->batches($payload)['status']);
        $pdo->exec("UPDATE `$schema`.item SET is_deleted=0 WHERE id=1;
            UPDATE `$schema`.receipt_note SET is_deleted=1 WHERE id=1");
        $this->assertSame(404, $this->controller->batches($payload)['status']);
    }

    public function testBatchLookupResolvesEveryConfiguredBranchAndTextOrder()
    {
        foreach (Receipt_batch_fixture::$schemas as $branch_id => $schema) {
            if ($branch_id === 0) {
                continue;
            }
            Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note SET branch_id=$branch_id WHERE id=1;
                UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no=2 WHERE id=3");
            $result = $this->successful($this->controller->batches([
                'item_id' => 1, 'branch_id_from' => $branch_id, 'date' => '2028-01-01'
            ]));
            $this->assertSame(4, $result['size']);
            $this->assertSame(['12', '12', '12', '2'], array_column($result['data'], 'batch_no'));
        }
    }

    public function testZeroBatchFractionalAndUnlabelledTransfers()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no=0 WHERE id IN (1,2)");
        $transfer_id = $this->successful($this->submit([$this->line(0.5, ' 0 ')]))['branch_item_transfer_id'];
        $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [0.5]));
        $report = $this->successful($this->controller->report(['item_id' => 1, 'batch_no' => 0]));
        $this->assertSame(3, $report['size']);
        $this->assertSame('0', $report['data'][0]['batch_no']);
        $transfer_id = $this->successful($this->submit([$this->line(0.5, null)], 4))['branch_item_transfer_id'];
        $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [0.5]));
        $rows = $this->successful($this->controller->report(['item_id' => 1]))['data'];
        $unlabelled = array_filter($rows, static function ($row) { return $row['activity'] === 'Transfer' && $row['batch_no'] === null; });
        $this->assertCount(0, $unlabelled);
    }

    public function testDisapprovalReleasesReservationsAndStaleStockFails()
    {
        $transfer_id = $this->successful($this->submit([$this->line(6)]))['branch_item_transfer_id'];
        $this->successful($this->controller->change(['transfer_id' => $transfer_id, 'status' => 'disapproved']));
        $transfer_id = $this->successful($this->submit([$this->line(7)]))['branch_item_transfer_id'];
        foreach (Receipt_batch_fixture::$schemas as $schema) {
            Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.branch_item SET current_qty=0 WHERE branch_id=2");
        }
        $before = Receipt_batch_fixture::$pdo->query('SELECT COUNT(*) FROM item_history')->fetchColumn();
        $this->rejected($this->receive($transfer_id, $this->line_ids($transfer_id), [1]), 'source stock');
        $this->assertSame($before, Receipt_batch_fixture::$pdo->query('SELECT COUNT(*) FROM item_history')->fetchColumn());
    }

    public function testMissingSourcesAndFailedInsertsDoNotSaveTransfers()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo->exec("RENAME TABLE `$schema`.receipt_note_item_expiry_date TO `$schema`.missing_expiry_fixture");
        try {
            $this->rejected($this->submit([$this->line()]), 'Failed to read receipt');
            $this->rejected($this->controller->batches([
                'item_id' => 1, 'branch_id_from' => 2, 'date' => '2028-01-01'
            ]), 'Failed to load item batches.');
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM branch_item_transfer')->fetchColumn());
        } finally {
            $pdo->exec("RENAME TABLE `$schema`.missing_expiry_fixture TO `$schema`.receipt_note_item_expiry_date");
            db_connect('master')->resetTransStatus();
        }
        $pdo->exec("CREATE TRIGGER fail_fixture_line BEFORE INSERT ON branch_item_transfer_item FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture insert failure'");
        try {
            $this->rejected($this->submit([$this->line()]));
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM branch_item_transfer')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM branch_item_transfer_item')->fetchColumn());
        } finally {
            $pdo->exec('DROP TRIGGER fail_fixture_line');
        }
    }

    public function testLateMirrorFailureReportsPartialWriteRisk()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $transfer_id = $this->successful($this->submit_linked([$this->linked_line()]))['branch_item_transfer_id'];
        $schema = Receipt_batch_fixture::$schemas[6];
        $balances = $this->expiry_balances(2);
        $pdo->exec("CREATE TRIGGER `$schema`.fail_fixture_mirror BEFORE INSERT ON `$schema`.item_cost FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture cost mirror failure'");
        try {
            $this->rejected($this->receive($transfer_id, $this->line_ids($transfer_id), [1]), 'Review cost mirrors');
            $this->assertEquals(0, $pdo->query('SELECT received_qty FROM branch_item_transfer_item WHERE branch_item_transfer_id='.$transfer_id)->fetchColumn());
            foreach (Receipt_batch_fixture::$schemas as $inventory_schema) {
                $this->assertEquals(10, $pdo->query("SELECT current_qty FROM `$inventory_schema`.branch_item WHERE id=1")->fetchColumn());
                $this->assertEquals(0, $pdo->query("SELECT current_qty FROM `$inventory_schema`.branch_item WHERE id=4")->fetchColumn());
            }
            $this->assertGreaterThan(3, (int) $pdo->query('SELECT COUNT(*) FROM `'.Receipt_batch_fixture::$schemas[1].'`.item_cost')->fetchColumn());
            $this->assertSame($balances, $this->expiry_balances(2));
        } finally {
            $pdo->exec("DROP TRIGGER `$schema`.fail_fixture_mirror");
        }
    }

    public function testConcurrentReservationsAndReceiptsStayIsolated()
    {
        $workers = [];
        foreach ([3, 4] as $branch_id) {
            $workers[] = $this->worker(['submit', $branch_id]);
        }
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = $this->worker_result($worker)['status'];
        }
        sort($statuses);
        $this->assertSame([200, 400], $statuses);
        Receipt_batch_fixture::reset();
        $transfer_id = $this->successful($this->submit([$this->line(6)]))['branch_item_transfer_id'];
        $ids = $this->line_ids($transfer_id);
        $workers = [$this->worker(['receive', $transfer_id, $ids[0], 2]), $this->worker(['receive', $transfer_id, $ids[0], 2])];
        $statuses = [];
        foreach ($workers as $worker) {
            $statuses[] = $this->worker_result($worker)['status'];
        }
        sort($statuses);
        $this->assertSame([200, 400], $statuses);
        $this->assertEquals(8, Receipt_batch_fixture::$pdo->query('SELECT current_qty FROM `'.Receipt_batch_fixture::$schemas[2].'`.branch_item WHERE id=1')->fetchColumn());
        Receipt_batch_fixture::reset();
        $transfer_id = $this->successful($this->submit([$this->line(6)]))['branch_item_transfer_id'];
        $ids = $this->line_ids($transfer_id);
        $workers = [$this->worker(['receive', $transfer_id, $ids[0], 2]), $this->worker(['receive', $transfer_id, $ids[0], 4])];
        foreach ($workers as $worker) {
            $this->assertContains($this->worker_result($worker)['status'], [200, 400]);
        }
        $this->assertEquals(6, Receipt_batch_fixture::$pdo->query('SELECT current_qty FROM `'.Receipt_batch_fixture::$schemas[2].'`.branch_item WHERE id=1')->fetchColumn());
        $this->assertEquals(10, Receipt_batch_fixture::$pdo->query('SELECT current_qty FROM branch_item WHERE id=1')->fetchColumn());
        $this->assertEquals(6, Receipt_batch_fixture::$pdo->query('SELECT latest_total FROM item_cost WHERE branch_id=2 ORDER BY id DESC LIMIT 1')->fetchColumn());
        $balances = array_column($this->expiry_balances(2), 'qty_balance');
        $this->assertEquals([0, 2, 4, 10, 1000, 1000], $balances);

    }

    private function worker($arguments)
    {
        $command = array_merge([PHP_BINARY, ROOTPATH.'tests/_support/Database/transfer_batch_worker.php'], array_map('strval', $arguments));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        return [$process, $pipes];
    }

    private function worker_result($worker)
    {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTextBatchReceiptStorageAndDistinctLookup()
    {
        $codes = ['AB123', 'ab123', '0012', '12', 'LOT-2026/01 A.2', '批次-001', '0'];
        $expiry_dates = [];
        foreach ($codes as $code) {
            $expiry_dates[] = ['date' => '2028-01-01', 'qty' => 1, 'batch_no' => $code];
        }
        $db = db_connect('oakridge');
        $receipt = new Receipt_batch_controller(new Receipt_note_item($db), new Receipt_note_item_expiry_date($db));
        $result = $receipt->validate_batches([[
            'item_id' => 1, 'qty' => 7, 'unit' => 11,
            'batch_no' => implode(',', $codes), 'expiry_dates' => $expiry_dates
        ]]);
        $this->assertTrue($result['valid'], $result['error'] ?? '');
        $this->assertTrue($receipt->save_items(1));
        $schema = Receipt_batch_fixture::$schemas[2];
        $saved = Receipt_batch_fixture::$pdo->query("SELECT id,batch_no FROM `$schema`.receipt_note_item ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(implode(',', $codes), $saved['batch_no']);
        $expiries = (new Receipt_note_item_expiry_date($db))->get_details($saved['id']);
        $this->assertSame($codes, array_column($expiries, 'batch_no'));
        sort($codes, SORT_STRING);
        $batches = $this->successful($this->controller->batches([
            'item_id' => 1, 'branch_id_from' => 2, 'date' => '2028-01-01'
        ]));
        $saved_ids = array_column($expiries, 'id');
        $saved_batches = array_values(array_filter($batches['data'], static function ($row) use ($saved_ids) {
            return in_array($row['receipt_note_item_expiry_date_id'], $saved_ids, true);
        }));
        $this->assertSame($codes, array_column($saved_batches, 'batch_no'));
        $this->assertSame(11, $batches['size']);
    }

    public function testTextBatchBalancesReservationsAndIncomingTransfersStaySeparate()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        $pdo->exec("UPDATE `$schema`.receipt_note_item SET batch_no='AB123,ab123,0012' WHERE id=1;
            UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no='AB123' WHERE id=1;
            UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no='ab123' WHERE id=2;
            UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no='0012' WHERE id=3");
        $codes = ['AB123', 'ab123', '0012', '12'];
        $this->rejected($this->submit([$this->line(1, '12tail')]), 'batch 12tail');
        $this->rejected($this->submit([$this->line(1.5, 'AB123'), $this->line(1.6, 'AB123')]), 'batch AB123');
        $lines = array_map(fn($code) => $this->line(1, $code), $codes);
        $transfer_id = $this->successful($this->submit($lines))['branch_item_transfer_id'];
        foreach (['AB123' => 2.5, 'ab123' => 1.5, '0012' => 3.5, '12' => 1.5] as $code => $qty) {
            $this->rejected($this->submit([$this->line($qty, (string) $code)], 4), 'batch '.$code);
        }
        $other = $this->successful($this->submit([$this->line(2, 'AB123'), $this->line(1, 'ab123')], 4))['branch_item_transfer_id'];
        $this->successful($this->controller->change(['transfer_id' => $other, 'status' => 'disapproved']));
        $ids = $this->line_ids($transfer_id);
        $this->successful($this->receive($transfer_id, $ids, [0.5, 0.5, 0.5, 0.5]));
        $this->rejected($this->submit([$this->line(2.5, 'AB123')], 4), 'batch AB123');
        $this->successful($this->receive($transfer_id, $ids, [1, 1, 1, 1]));
        $model = new Branch_item_transfer_item(db_connect('master'));
        $this->assertSame($codes, array_column($model->get_details($transfer_id), 'batch_no'));
        foreach ($codes as $code) {
            $report = $this->successful($this->controller->report(['item_id' => 1, 'batch_no' => $code]));
            $this->assertSame(2, $report['size']);
            $this->assertSame([$code, $code], array_column($report['data'], 'batch_no'));
            $this->assertSame(['Receipt', 'Transfer'], array_column($report['data'], 'activity'));
            $this->assertEquals(1, $report['data'][1]['quantity']);
        }
        $this->assertCount(1, $model->get_batch_receipts(1, 2, 12));
        $this->assertCount(1, $model->get_batch_receipts(1, 2, '0012'));
        $this->assertSame([], $model->get_transfer_report(1, 'AB123-other'));
        $incoming = $this->line(1.5, 'ab123');
        $incoming['branch_item_id'] = 4;
        $this->rejected($this->submit([$incoming], 4, 3), 'batch ab123');
        $incoming = $this->line(1, 'AB123');
        $incoming['branch_item_id'] = 4;
        $this->rejected($this->submit([$incoming], 4, 3), 'source batch stock');
        $this->assertSame(2, $this->successful($this->controller->report(['batch_no' => 'AB123']))['size']);
        $this->assertSame(2, $this->successful($this->controller->report(['batch_no' => 'ab123']))['size']);
    }

    private function expiry_balances($branch_id)
    {
        $schema = Receipt_batch_fixture::$schemas[$branch_id];
        return Receipt_batch_fixture::$pdo->query("SELECT id,qty,qty_balance,updated_by,updated_on
            FROM `$schema`.receipt_note_item_expiry_date ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testSourceBalanceReplacesOriginalQuantitiesAndHistoricalArithmetic()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET qty=100,qty_balance=0 WHERE id IN(1,2,4);
            UPDATE `$schema`.receipt_note_item_expiry_date SET qty_balance=1 WHERE id=1;
            INSERT INTO branch_item_transfer(id,branch_id_from,branch_id_to,status) VALUES(9001,2,3,'received'),(9002,3,2,'received');
            INSERT INTO branch_item_transfer_item(branch_item_transfer_id,item_id,unit,transfer_qty,received_qty,batch_no)
                VALUES(9001,1,11,5,5,'12'),(9002,1,11,8,8,'12')");
        $this->rejected($this->submit([$this->line(1.0001)]), 'source batch stock');
        $this->successful($this->submit([$this->line(1)]));
        $this->assertEquals(1, $this->expiry_balances(2)[0]['qty_balance']);
        $this->rejected($this->submit([$this->line(0.0001)], 4), 'source batch stock');
    }

    public function testNullZeroAndNegativeSourceBalancesNeverFallBackToReceiptQuantity()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        foreach (['NULL', '0', '-1'] as $balance) {
            $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET qty_balance=$balance WHERE id IN(1,2,4)");
            $message = $balance === '-1' ? 'Invalid source batch balance' : 'source batch stock';
            $this->rejected($this->submit([$this->line(0.0001)]), $message);
        }
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM branch_item_transfer')->fetchColumn());
    }

    public function testReceiptDeductionsFollowExpiryThenIdAndTouchOnlySourceBalances()
    {
        $before = [];
        foreach (Receipt_batch_fixture::$schemas as $branch_id => $schema) {
            $before[$branch_id] = $this->expiry_balances($branch_id);
        }
        $transfer_id = $this->successful($this->submit([$this->line(6)]))['branch_item_transfer_id'];
        $ids = $this->line_ids($transfer_id);
        $this->successful($this->controller->change(['transfer_id' => $transfer_id, 'status' => 'approved']));
        $this->assertSame($before[2], $this->expiry_balances(2));
        $this->successful($this->receive($transfer_id, $ids, [2]));
        $partial = $this->expiry_balances(2);
        $this->assertEquals(1, $partial[0]['qty_balance']);
        $this->assertEquals(20, $partial[3]['qty_balance']);
        $this->assertSame('67', (string) $partial[0]['updated_by']);
        $this->assertNotNull($partial[0]['updated_on']);
        $this->rejected($this->receive($transfer_id, $ids, [2]), 'No additional');
        $this->assertSame($partial, $this->expiry_balances(2));
        $this->successful($this->receive($transfer_id, $ids, [6]));
        $after = $this->expiry_balances(2);
        $this->assertEquals([0,1,4,0,1000,1000], array_column($after, 'qty_balance'));
        $this->assertSame(array_column($before[2], 'qty'), array_column($after, 'qty'));
        foreach ($before as $branch_id => $rows) {
            if ($branch_id !== 2) {
                $this->assertSame($rows, $this->expiry_balances($branch_id));
            }
        }
    }

    public function testFourDecimalBalancesAndUnbatchedReceipts()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no='0',qty_balance=0.0002 WHERE id=1");
        $transfer_id = $this->successful($this->submit([$this->line(0.0002, '0')]))['branch_item_transfer_id'];
        $ids = $this->line_ids($transfer_id);
        $this->successful($this->receive($transfer_id, $ids, [0.0001]));
        $this->assertSame('0.0001', $this->expiry_balances(2)[0]['qty_balance']);
        $this->successful($this->receive($transfer_id, $ids, [0.0002]));
        $this->assertSame('0.0000', $this->expiry_balances(2)[0]['qty_balance']);
        $before = $this->expiry_balances(2);
        $transfer_id = $this->successful($this->submit([$this->line(1, null)]))['branch_item_transfer_id'];
        $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [1]));
        $this->assertSame($before, $this->expiry_balances(2));
        $transfer_id = $this->successful($this->submit([$this->line(1, null)]))['branch_item_transfer_id'];
        Receipt_batch_fixture::$pdo->exec("UPDATE branch_item_transfer_item SET batch_no='   ' WHERE branch_item_transfer_id=$transfer_id");
        $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [1]));
        $this->assertSame($before, $this->expiry_balances(2));
    }

    public function testStaleBatchBalancesFailApprovalAndReceiptWithoutStockWrites()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        $transfer_id = $this->successful($this->submit([$this->line(6)]))['branch_item_transfer_id'];
        $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET qty_balance=0 WHERE id IN(1,2,4)");
        $before = $this->expiry_balances(2);
        $this->rejected($this->controller->change(['transfer_id' => $transfer_id, 'status' => 'approved']), 'source batch stock');
        $this->rejected($this->receive($transfer_id, $this->line_ids($transfer_id), [1]), 'source batch stock');
        $this->assertSame($before, $this->expiry_balances(2));
        $this->assertEquals(10, $pdo->query('SELECT current_qty FROM branch_item WHERE id=1')->fetchColumn());
        $this->assertEquals(0, $pdo->query('SELECT received_qty FROM branch_item_transfer_item WHERE branch_item_transfer_id='.$transfer_id)->fetchColumn());
    }

    public function testAllocationKeepsSmallRemaindersAcrossConvertedExpiryRows()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        $pdo->exec("UPDATE `$schema`.item_conversion SET qty=3 WHERE id=12;
            UPDATE `$schema`.receipt_note_item SET unit=12 WHERE id=1;
            UPDATE `$schema`.receipt_note_item_expiry_date SET qty_balance=2.9999 WHERE id=1");
        $db = db_connect('master');
        $db->transBegin();
        try {
            $model = new Branch_item_transfer_item($db);
            $this->assertTrue($model->deduct_batch_balance(1, 2, '12', 11, 1, 67), $model->get_quantity_error() ?? '');
            $db->transCommit();
        } finally {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            $pdo->exec("UPDATE `$schema`.item_conversion SET qty=10 WHERE id=12");
        }
        $balances = $this->expiry_balances(2);
        $this->assertSame('0.0000', $balances[0]['qty_balance']);
        $this->assertSame('19.9999', $balances[3]['qty_balance']);
    }

    public function testFailedBalanceWriteRollsBackEarlierAllocationsBeforeMirroring()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        $transfer_id = $this->successful($this->submit([$this->line(6)]))['branch_item_transfer_id'];
        $before = $this->expiry_balances(2);
        $pdo->exec("CREATE TRIGGER `$schema`.fail_fixture_balance BEFORE UPDATE ON `$schema`.receipt_note_item_expiry_date
            FOR EACH ROW BEGIN IF NEW.id=4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture balance failure'; END IF; END");
        try {
            $this->rejected($this->receive($transfer_id, $this->line_ids($transfer_id), [6]), 'Failed to update source batch balance');
            $this->assertSame($before, $this->expiry_balances(2));
            foreach (Receipt_batch_fixture::$schemas as $mirror) {
                $this->assertEquals(10, $pdo->query("SELECT current_qty FROM `$mirror`.branch_item WHERE id=1")->fetchColumn());
            }
            $this->assertEquals(0, $pdo->query('SELECT received_qty FROM branch_item_transfer_item WHERE branch_item_transfer_id='.$transfer_id)->fetchColumn());
        } finally {
            $pdo->exec("DROP TRIGGER `$schema`.fail_fixture_balance");
        }
    }

    public function testSourceStockValidationUsesLocalQuantitiesInsteadOfMasterCopies()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.branch_item SET current_qty=0 WHERE branch_id=2");
        $this->rejected($this->submit([$this->line(1, null)]), 'available source stock');
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.branch_item SET current_qty=beggining_qty WHERE branch_id=2;
            DELETE FROM branch_item WHERE branch_id=2");
        $this->successful($this->submit([$this->line(1, null)]));
    }

    private function inventory_snapshot($branch_id)
    {
        $schema = Receipt_batch_fixture::$schemas[$branch_id];
        $pdo = Receipt_batch_fixture::$pdo;
        return [
            'stock' => $pdo->query("SELECT * FROM `$schema`.branch_item ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'history' => $pdo->query("SELECT * FROM `$schema`.item_history ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function testReceiptUsesLocalIdsAndQuantitiesWithoutChangingOtherInventoryCopies()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $source = Receipt_batch_fixture::$schemas[2];
        $destination = Receipt_batch_fixture::$schemas[3];
        $pdo->exec("UPDATE branch_item SET current_qty=999;
            UPDATE `$source`.branch_item SET id=id+1000 WHERE branch_id=2;
            UPDATE `$destination`.branch_item SET id=id+2000,current_qty=CASE unit WHEN 11 THEN 2 WHEN 12 THEN 20 ELSE 2000 END WHERE branch_id=3");
        foreach (Receipt_batch_fixture::$schemas as $schema) {
            $pdo->exec("UPDATE `$schema`.item_cost SET latest_total=2,last_value=10 WHERE branch_id=3");
        }
        $before = [];
        foreach ([0, 1, 4, 5, 6, 7] as $branch_id) {
            $before[$branch_id] = $this->inventory_snapshot($branch_id);
        }
        $line = $this->line(3, null);
        $line['branch_item_id'] = 1001;
        $transfer_id = $this->successful($this->submit([$line]))['branch_item_transfer_id'];
        $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [3]));
        $this->assertEquals(7, $pdo->query("SELECT current_qty FROM `$source`.branch_item WHERE id=1001")->fetchColumn());
        $this->assertEquals(5, $pdo->query("SELECT current_qty FROM `$destination`.branch_item WHERE id=2004")->fetchColumn());
        foreach ($before as $branch_id => $snapshot) {
            $this->assertSame($snapshot, $this->inventory_snapshot($branch_id));
        }
        $this->assertEquals(3, $pdo->query("SELECT COUNT(*) FROM `$source`.item_history WHERE activity='Transfer Out' AND activity_id=$transfer_id")->fetchColumn());
        $this->assertEquals(3, $pdo->query("SELECT COUNT(*) FROM `$destination`.item_history WHERE activity='Transfer In' AND activity_id=$transfer_id")->fetchColumn());
    }

    public function testWarehouseToPharmacyBatchReceiptsUseTheirOwnStock()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $source = Receipt_batch_fixture::$schemas[5];
        $destination = Receipt_batch_fixture::$schemas[6];
        $pdo->exec("UPDATE `$source`.receipt_note SET branch_id=5 WHERE id=1;
            INSERT INTO `$source`.branch_item(id,item_id,branch_id,beggining_qty,current_qty,unit,added_by,added_on)
                VALUES(22428,1,5,6,6,11,67,NOW()),(22429,1,5,60,60,12,67,NOW()),(22430,1,5,6000,6000,13,67,NOW());
            INSERT INTO `$destination`.branch_item(id,item_id,branch_id,beggining_qty,current_qty,unit,added_by,added_on)
                VALUES(24001,1,6,3,3,11,67,NOW()),(24002,1,6,30,30,12,67,NOW()),(24003,1,6,3000,3000,13,67,NOW())");
        foreach (Receipt_batch_fixture::$schemas as $schema) {
            $pdo->exec("INSERT INTO `$schema`.item_cost VALUES(4,1,5,30,6,5,67,NOW(),0),(5,1,6,15,3,5,67,NOW(),0)");
        }
        $line = ['item_id' => 1, 'branch_item_id' => 22429, 'unit' => 12, 'transfer_qty' => 61, 'batch_no' => '12'];
        $this->rejected($this->submit([$line], 6, 5), 'available source stock');
        $line['transfer_qty'] = 50;
        $before = [];
        foreach ([0, 1, 2, 3, 4, 7] as $branch_id) {
            $before[$branch_id] = $this->inventory_snapshot($branch_id);
        }
        $transfer_id = $this->successful($this->submit([$line], 6, 5))['branch_item_transfer_id'];
        $ids = $this->line_ids($transfer_id);
        $this->successful($this->receive($transfer_id, $ids, [20]));
        $this->assertSame('opened', $pdo->query("SELECT status FROM branch_item_transfer WHERE id=$transfer_id")->fetchColumn());
        $this->assertEquals(40, $pdo->query("SELECT current_qty FROM `$source`.branch_item WHERE id=22429")->fetchColumn());
        $this->assertEquals(50, $pdo->query("SELECT current_qty FROM `$destination`.branch_item WHERE id=24002")->fetchColumn());
        $this->assertEquals(1, $this->expiry_balances(5)[0]['qty_balance']);
        $this->successful($this->receive($transfer_id, $ids, [50]));
        $this->assertEquals(10, $pdo->query("SELECT current_qty FROM `$source`.branch_item WHERE id=22429")->fetchColumn());
        $this->assertEquals(80, $pdo->query("SELECT current_qty FROM `$destination`.branch_item WHERE id=24002")->fetchColumn());
        $this->assertEquals([0,2,4,0,1000,1000], array_column($this->expiry_balances(5), 'qty_balance'));
        foreach ($before as $branch_id => $snapshot) {
            $this->assertSame($snapshot, $this->inventory_snapshot($branch_id));
        }
        $this->rejected($this->receive($transfer_id, $ids, [50]));
    }

    public function testMissingDestinationInventoryIsCreatedOnlyInItsDatabase()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $destination = Receipt_batch_fixture::$schemas[3];
        $pdo->exec("DELETE FROM `$destination`.branch_item WHERE branch_id=3");
        $before = [];
        foreach ([0, 1, 4, 5, 6, 7] as $branch_id) {
            $before[$branch_id] = $this->inventory_snapshot($branch_id);
        }
        $transfer_id = $this->successful($this->submit([$this->line()]))['branch_item_transfer_id'];
        $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [1]));
        $rows = $pdo->query("SELECT id,current_qty FROM `$destination`.branch_item WHERE branch_id=3 ORDER BY unit")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertEquals([10, 11, 12], array_column($rows, 'id'));
        $this->assertEquals([1, 10, 1000], array_column($rows, 'current_qty'));
        foreach ($before as $branch_id => $snapshot) {
            $this->assertSame($snapshot, $this->inventory_snapshot($branch_id));
        }
    }

    public function testDestinationWriteFailureRollsBackSourceStockHistoryAndBalances()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $destination = Receipt_batch_fixture::$schemas[3];
        $transfer_id = $this->successful($this->submit_linked([$this->linked_line()]))['branch_item_transfer_id'];
        $balances = $this->expiry_balances(2);
        $source_before = $this->inventory_snapshot(2);
        $destination_before = $this->inventory_snapshot(3);
        $pdo->exec("CREATE TRIGGER `$destination`.fail_fixture_destination BEFORE UPDATE ON `$destination`.branch_item
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture destination update failure'");
        try {
            $this->rejected($this->receive($transfer_id, $this->line_ids($transfer_id), [1]), 'Failed to update branch-local');
            $this->assertSame($source_before, $this->inventory_snapshot(2));
            $this->assertSame($destination_before, $this->inventory_snapshot(3));
            $this->assertSame($balances, $this->expiry_balances(2));
            $this->assertEquals(0, $pdo->query("SELECT received_qty FROM branch_item_transfer_item WHERE branch_item_transfer_id=$transfer_id")->fetchColumn());
        } finally {
            $pdo->exec("DROP TRIGGER `$destination`.fail_fixture_destination");
        }
    }

    public function testConcurrentReceiptsAllocateDistinctDestinationInventoryIds()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $transfers = [];
        foreach ([3, 4] as $branch_id) {
            $schema = Receipt_batch_fixture::$schemas[$branch_id];
            $pdo->exec("DELETE FROM `$schema`.branch_item WHERE branch_id=$branch_id");
            $transfer_id = $this->successful($this->submit([$this->line()], $branch_id))['branch_item_transfer_id'];
            $transfers[] = [$transfer_id, $this->line_ids($transfer_id)[0]];
        }
        $workers = [];
        foreach ($transfers as [$transfer_id, $line_id]) {
            $workers[] = $this->worker(['receive', $transfer_id, $line_id, 1]);
        }
        foreach ($workers as $worker) {
            $this->successful($this->worker_result($worker));
        }
        $ids = [];
        foreach ([3, 4] as $branch_id) {
            $schema = Receipt_batch_fixture::$schemas[$branch_id];
            $rows = $pdo->query("SELECT id,current_qty FROM `$schema`.branch_item WHERE branch_id=$branch_id ORDER BY unit")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertEquals([1,10,1000], array_column($rows, 'current_qty'));
            $ids = array_merge($ids, array_column($rows, 'id'));
        }
        $this->assertCount(6, array_unique($ids));
        $this->assertEquals(8, $pdo->query('SELECT current_qty FROM `'.Receipt_batch_fixture::$schemas[2].'`.branch_item WHERE id=1')->fetchColumn());
    }

    public function testDestinationInsertFailureRollsBackEarlierCreatedUnits()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $destination = Receipt_batch_fixture::$schemas[3];
        $pdo->exec("DELETE FROM `$destination`.branch_item WHERE branch_id=3");
        $source_before = $this->inventory_snapshot(2);
        $destination_before = $this->inventory_snapshot(3);
        $balances = $this->expiry_balances(2);
        $transfer_id = $this->successful($this->submit_linked([$this->linked_line()]))['branch_item_transfer_id'];
        $pdo->exec("CREATE TRIGGER `$destination`.fail_fixture_destination_insert BEFORE INSERT ON `$destination`.branch_item
            FOR EACH ROW BEGIN IF NEW.unit=12 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture destination insert failure'; END IF; END");
        try {
            $this->rejected($this->receive($transfer_id, $this->line_ids($transfer_id), [1]), 'Failed to create destination');
            $this->assertSame($source_before, $this->inventory_snapshot(2));
            $this->assertSame($destination_before, $this->inventory_snapshot(3));
            $this->assertSame($balances, $this->expiry_balances(2));
        } finally {
            $pdo->exec("DROP TRIGGER `$destination`.fail_fixture_destination_insert");
        }
    }

    public function testCommitFailureRollsBackBalancesAndReceivedQuantities()
    {
        $transfer_id = $this->successful($this->submit_linked([$this->linked_line()]))['branch_item_transfer_id'];
        $before = $this->expiry_balances(2);
        $source_before = $this->inventory_snapshot(2);
        $destination_before = $this->inventory_snapshot(3);
        $this->controller = new class extends Transfer_batch_controller {
            protected function _load_transfer_models()
            {
                parent::_load_transfer_models();
                $this->db = new class($this->db) {
                    private $connection;
                    public function __construct($connection) { $this->connection = $connection; }
                    public function transCommit() { return false; }
                    public function __call($method, $arguments) { return $this->connection->$method(...$arguments); }
                };
            }
        };
        $this->rejected($this->receive($transfer_id, $this->line_ids($transfer_id), [1]), 'Failed to commit');
        $this->assertSame($before, $this->expiry_balances(2));
        $this->assertSame($source_before, $this->inventory_snapshot(2));
        $this->assertSame($destination_before, $this->inventory_snapshot(3));
        $this->assertEquals(0, Receipt_batch_fixture::$pdo->query('SELECT received_qty FROM branch_item_transfer_item WHERE branch_item_transfer_id='.$transfer_id)->fetchColumn());
    }

    public function testQualifiedInventoryWritesPostOnlyInSourceAndDestinationAccounting()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        Transfer_accounting_fixture::install();
        try {
            $transfer_id = $this->successful($this->submit_linked([$this->linked_line()]))['branch_item_transfer_id'];
            $this->successful($this->receive($transfer_id, $this->line_ids($transfer_id), [1]));
            $accounting = [];
            foreach (Receipt_batch_fixture::$schemas as $branch_id => $schema) {
                $expected = in_array($branch_id, [2, 3], true) ? 3 : 0;
                foreach (['general_ledger', 'journal_entry', 'journal_entry_item'] as $table) {
                    $this->assertEquals($expected, $pdo->query("SELECT COUNT(*) FROM `$schema`.`$table` WHERE is_deleted=0")->fetchColumn());
                    $accounting[$branch_id][$table] = $pdo->query("SELECT * FROM `$schema`.`$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                }
                if (!$expected) {
                    continue;
                }
                $rows = $pdo->query("SELECT gl.branch_id,gl.account_type_id,gl.debit,gl.credit,ih.activity
                    FROM `$schema`.general_ledger AS gl JOIN `$schema`.item_history AS ih ON ih.id=gl.source_id
                    WHERE gl.is_deleted=0 ORDER BY ih.unit")->fetchAll(PDO::FETCH_ASSOC);
                $this->assertEquals([$branch_id, $branch_id, $branch_id], array_column($rows, 'branch_id'));
                $this->assertEquals([137, 137, 137], array_column($rows, 'account_type_id'));
                $this->assertEquals([5, 50, 5000], array_column($rows, $branch_id === 2 ? 'credit' : 'debit'));
                $this->assertEquals([0, 0, 0], array_column($rows, $branch_id === 2 ? 'debit' : 'credit'));
                $this->assertSame(array_fill(0, 3, $branch_id === 2 ? 'Transfer Out' : 'Transfer In'), array_column($rows, 'activity'));
                $this->assertEquals(3, $pdo->query("SELECT COUNT(*) FROM `$schema`.journal_entry
                    WHERE cost_center_id=$branch_id AND status='approved'")->fetchColumn());
            }
            $destination = Receipt_batch_fixture::$schemas[3];
            $transfer_id = $this->successful($this->submit_linked([$this->linked_line()]))['branch_item_transfer_id'];
            $source_before = $this->inventory_snapshot(2);
            $destination_before = $this->inventory_snapshot(3);
            $balances = $this->expiry_balances(2);
            $pdo->exec("CREATE TRIGGER `$destination`.fail_fixture_accounting_receipt BEFORE UPDATE ON `$destination`.branch_item
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture posting rollback'");
            try {
                $this->rejected($this->receive($transfer_id, $this->line_ids($transfer_id), [1]));
                $this->assertSame($source_before, $this->inventory_snapshot(2));
                $this->assertSame($destination_before, $this->inventory_snapshot(3));
                $this->assertSame($balances, $this->expiry_balances(2));
                foreach (Receipt_batch_fixture::$schemas as $branch_id => $schema) {
                    foreach ($accounting[$branch_id] as $table => $rows) {
                        $this->assertSame($rows, $pdo->query("SELECT * FROM `$schema`.`$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
                    }
                }
            } finally {
                $pdo->exec("DROP TRIGGER `$destination`.fail_fixture_accounting_receipt");
            }
        } finally {
            Transfer_accounting_fixture::remove();
        }
    }

    public function testInstallerConvertsIntegerValuesAndPreservesIndexesAndWiderColumns()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $master = Receipt_batch_fixture::$schemas[0];
        $pdo->exec("USE `$master`;
            INSERT INTO branch_item_transfer(id,branch_id_from,branch_id_to,status,added_on)
                VALUES(9001,2,3,'received','2026-10-15 10:00:00');
            INSERT INTO branch_item_transfer_item(id,branch_item_transfer_id,item_id,unit,transfer_qty,received_qty,batch_no)
                VALUES(9001,9001,1,11,1,1,'12'),(9002,9001,1,11,1,1,'0'),(9003,9001,1,11,1,1,NULL);
            ALTER TABLE branch_item_transfer_item MODIFY batch_no INT NULL");
        foreach (Receipt_batch_fixture::$schemas as $branch_id => $schema) {
            $pdo->exec("USE `$schema`;
                ALTER TABLE receipt_note_item MODIFY batch_no VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL;
                ALTER TABLE receipt_note_item_expiry_date MODIFY batch_no INT NULL");
            if ($branch_id === 2) {
                $pdo->exec("ALTER TABLE receipt_note_item MODIFY batch_no VARCHAR(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'DEFAULT-LOT' COMMENT 'Wide batch field';
                    ALTER TABLE receipt_note_item_expiry_date MODIFY batch_no VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT 'Wide expiry field'");
            }
            $tables = ['receipt_note_item', 'receipt_note_item_expiry_date'];
            if ($branch_id === 0) {
                $tables[] = 'branch_item_transfer_item';
            }
            $saved_rows = [];
            foreach ($tables as $table) {
                $rows = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$row) {
                    $row['batch_no'] = $row['batch_no'] === null ? null : (string) $row['batch_no'];
                }
                unset($row);
                $saved_rows[$table] = $rows;
            }
            $index_sql = "SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,NON_UNIQUE,INDEX_TYPE
                FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX";
            $indexes = $pdo->query($index_sql)->fetchAll(PDO::FETCH_ASSOC);
            Receipt_batch_fixture::install(file_get_contents(ROOTPATH.'sql/receipt_batches/install.sql'));
            Receipt_batch_fixture::install(file_get_contents(ROOTPATH.'sql/receipt_batches/install.sql'));
            $this->assertSame($indexes, $pdo->query($index_sql)->fetchAll(PDO::FETCH_ASSOC));
            foreach ($saved_rows as $table => $rows) {
                $this->assertSame($rows, $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
            }
            $columns = $pdo->query("SELECT TABLE_NAME,DATA_TYPE,COLLATION_NAME,CHARACTER_MAXIMUM_LENGTH,COLUMN_COMMENT,COLUMN_DEFAULT
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='batch_no'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                $this->assertSame('varchar', $column['DATA_TYPE']);
                $this->assertSame('utf8mb4_bin', $column['COLLATION_NAME']);
                $this->assertGreaterThanOrEqual(255, (int) $column['CHARACTER_MAXIMUM_LENGTH']);
                if ($branch_id === 2 && $column['TABLE_NAME'] === 'receipt_note_item') {
                    $this->assertSame(400, (int) $column['CHARACTER_MAXIMUM_LENGTH']);
                    $this->assertSame('Wide batch field', $column['COLUMN_COMMENT']);
                    $this->assertSame("'DEFAULT-LOT'", $column['COLUMN_DEFAULT']);
                }
                if ($branch_id === 2 && $column['TABLE_NAME'] === 'receipt_note_item_expiry_date') {
                    $this->assertSame(300, (int) $column['CHARACTER_MAXIMUM_LENGTH']);
                    $this->assertSame('Wide expiry field', $column['COLUMN_COMMENT']);
                }
            }
        }
    }

    public function testInstallerTypesRepeatabilityAndPreservedRows()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        foreach (Receipt_batch_fixture::$schemas as $index => $schema) {
            $pdo->exec("USE `$schema`");
            $before = $pdo->query('SELECT * FROM receipt_note_item_expiry_date ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            Receipt_batch_fixture::install(file_get_contents(ROOTPATH.'sql/receipt_batches/install.sql'));
            $this->assertSame($before, $pdo->query('SELECT * FROM receipt_note_item_expiry_date ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
            $columns = $pdo->query("SELECT TABLE_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$schema' AND COLUMN_NAME='batch_no'")->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame('varchar', $columns['receipt_note_item']);
            $this->assertSame('varchar', $columns['receipt_note_item_expiry_date']);
            if ($index === 0) {
                $this->assertSame('varchar', $columns['branch_item_transfer_item']);
            }
        }
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo->exec("USE `$schema`; ALTER TABLE receipt_note_item MODIFY batch_no VARCHAR(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL;
            ALTER TABLE receipt_note_item_expiry_date MODIFY batch_no VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL");
        try {
            Receipt_batch_fixture::install(file_get_contents(ROOTPATH.'sql/receipt_batches/install.sql'));
            $this->fail('Incompatible column type must stop the installer.');
        } catch (PDOException $error) {
            $this->assertStringContainsString('Incompatible batch_no', $error->getMessage());
            $this->assertSame('utf8mb4_general_ci', $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='receipt_note_item' AND COLUMN_NAME='batch_no'")->fetchColumn());
        } finally {
            $pdo->exec('ALTER TABLE receipt_note_item_expiry_date MODIFY batch_no VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
            Receipt_batch_fixture::install(file_get_contents(ROOTPATH.'sql/receipt_batches/install.sql'));
        }
    }

    private function linked_line($qty = 1, $expiry_id = 1, $batch_no = '12', $unit = 11)
    {
        return $this->line($qty, $batch_no, $unit) + ['receipt_note_item_expiry_date_id' => $expiry_id];
    }

    private function submit_linked($items, $to = 3, $from = 2)
    {
        return $this->controller->submit(['branch_id_from' => $from, 'branch_id_to' => $to,
            'transfer_date' => '2026-10-16', 'items' => $items]);
    }

    public function testNewBatchedSubmissionsRequireAnExplicitPositiveAllocation()
    {
        foreach ([null, '', ' ', 0, '0', -1, '1.2', [], true] as $id) {
            $this->rejected($this->submit_linked([$this->linked_line(1, $id)]), 'items[0].receipt_note_item_expiry_date_id');
        }
        $this->rejected($this->submit_linked([$this->line()]), 'items[0].receipt_note_item_expiry_date_id');
        $this->rejected($this->submit_linked([$this->linked_line(), $this->line()]), 'items[1].receipt_note_item_expiry_date_id');
        $this->assertEquals(0, Receipt_batch_fixture::$pdo->query('SELECT COUNT(*) FROM branch_item_transfer')->fetchColumn());
        $this->assertEquals(0, Receipt_batch_fixture::$pdo->query('SELECT COUNT(*) FROM branch_item_transfer_item')->fetchColumn());
    }

    public function testZeroBatchNeedsAnIdAndUnbatchedLinesMustSaveNull()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no='0' WHERE id=1");
        $this->rejected($this->submit_linked([$this->line(1, '0')]), 'receipt_note_item_expiry_date_id');
        $id = $this->successful($this->submit_linked([$this->linked_line(1, 1, '0')]))['branch_item_transfer_id'];
        $line = Receipt_batch_fixture::$pdo->query("SELECT * FROM branch_item_transfer_item WHERE branch_item_transfer_id=$id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('0', $line['batch_no']);
        $this->assertEquals(1, $line['receipt_note_item_expiry_date_id']);
        $this->rejected($this->submit_linked([$this->linked_line(1, 1, null)], 4), 'must be null');
        $id = $this->successful($this->submit_linked([$this->linked_line(1, '', null)], 4))['branch_item_transfer_id'];
        $this->assertNull(Receipt_batch_fixture::$pdo->query("SELECT receipt_note_item_expiry_date_id FROM branch_item_transfer_item WHERE branch_item_transfer_id=$id")->fetchColumn());
    }

    public function testAllocationMustMatchSourceItemBatchAndActiveApprovedReceipt()
    {
        foreach ([3, 5, 6, 99999] as $id) {
            $this->rejected($this->submit_linked([$this->linked_line(1, $id)]), 'items[0].receipt_note_item_expiry_date_id');
        }
        $pdo = Receipt_batch_fixture::$pdo;
        $schema = Receipt_batch_fixture::$schemas[2];
        foreach (["UPDATE `$schema`.receipt_note_item_expiry_date SET is_deleted=1 WHERE id=1",
            "UPDATE `$schema`.receipt_note_item SET is_deleted=1 WHERE id=1",
            "UPDATE `$schema`.receipt_note_item SET item_id=2 WHERE id=1",
            "UPDATE `$schema`.receipt_note_item SET receipt_note_id=2 WHERE id=1",
            "UPDATE `$schema`.receipt_note SET status='pending' WHERE id=1",
            "UPDATE `$schema`.receipt_note SET is_deleted=1 WHERE id=1",
            "UPDATE `$schema`.receipt_note SET branch_id=3 WHERE id=1"] as $sql) {
            Receipt_batch_fixture::reset();
            $pdo->exec($sql);
            $this->rejected($this->submit_linked([$this->linked_line()]), 'items[0].receipt_note_item_expiry_date_id');
            $this->assertEquals(0, $pdo->query('SELECT COUNT(*) FROM branch_item_transfer')->fetchColumn());
        }
    }

    public function testSelectedAllocationAggregatesRepeatedLinesAndCannotSpill()
    {
        $this->rejected($this->submit_linked([$this->linked_line(4)]), 'allocation 1');
        $this->rejected($this->submit_linked([$this->linked_line(2), $this->linked_line(2)]), 'allocation 1');
        $id = $this->successful($this->submit_linked([$this->linked_line(1), $this->linked_line(2)]))['branch_item_transfer_id'];
        $this->successful($this->receive($id, $this->line_ids($id), [1, 2]));
        $this->assertEquals([0, 2, 4, 20], array_map('floatval', Receipt_batch_fixture::$pdo->query('SELECT qty_balance FROM `'.Receipt_batch_fixture::$schemas[2].'`.receipt_note_item_expiry_date WHERE id<=4 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testLinkedReservationsUseAllocationAndConvertedReceiptUnits()
    {
        $id = $this->successful($this->submit_linked([$this->linked_line(20, 4, '12', 12)]))['branch_item_transfer_id'];
        $this->rejected($this->submit_linked([$this->linked_line(0.1, 4)], 4), 'allocation 4');
        $this->successful($this->receive($id, $this->line_ids($id), [5]));
        $schema = Receipt_batch_fixture::$schemas[2];
        $this->assertEquals(15, Receipt_batch_fixture::$pdo->query("SELECT qty_balance FROM `$schema`.receipt_note_item_expiry_date WHERE id=4")->fetchColumn());
        $this->successful($this->receive($id, $this->line_ids($id), [20]));
        $this->assertEquals(0, Receipt_batch_fixture::$pdo->query("SELECT qty_balance FROM `$schema`.receipt_note_item_expiry_date WHERE id=4")->fetchColumn());
        $this->assertEquals(3, Receipt_batch_fixture::$pdo->query("SELECT qty_balance FROM `$schema`.receipt_note_item_expiry_date WHERE id=1")->fetchColumn());
        $this->rejected($this->receive($id, $this->line_ids($id), [20]));
    }

    public function testLinkedTransfersStillProtectLegacyPooledReservations()
    {
        $this->successful($this->submit([$this->line(5)]));
        $this->rejected($this->submit_linked([$this->linked_line(3)], 4), 'source batch stock');
        $this->successful($this->submit_linked([$this->linked_line(2)], 4));
    }

    public function testLegacyReceiptsProtectOtherLinkedAllocations()
    {
        $legacy_id = $this->successful($this->submit([$this->line(4)]))['branch_item_transfer_id'];
        $linked_id = $this->successful($this->submit_linked([$this->linked_line(3)], 4))['branch_item_transfer_id'];
        $this->successful($this->receive($legacy_id, $this->line_ids($legacy_id), [4]));
        $schema = Receipt_batch_fixture::$schemas[2];
        $this->assertEquals(3, Receipt_batch_fixture::$pdo->query("SELECT qty_balance FROM `$schema`.receipt_note_item_expiry_date WHERE id=1")->fetchColumn());
        $this->successful($this->receive($linked_id, $this->line_ids($linked_id), [3]));
    }

    public function testMixedLegacyReceiptsProtectTheirOwnRemainingLinkedReservations()
    {
        $id = $this->successful($this->submit([$this->line(2), $this->linked_line(3)]))['branch_item_transfer_id'];
        $ids = $this->line_ids($id);
        $this->successful($this->receive($id, $ids, [2, 1]));
        $schema = Receipt_batch_fixture::$schemas[2];
        $this->assertEquals(2, Receipt_batch_fixture::$pdo->query("SELECT qty_balance FROM `$schema`.receipt_note_item_expiry_date WHERE id=1")->fetchColumn());
        $this->assertEquals(0, Receipt_batch_fixture::$pdo->query("SELECT qty_balance FROM `$schema`.receipt_note_item_expiry_date WHERE id=4")->fetchColumn());
        $this->successful($this->receive($id, [$ids[1]], [3]));
    }

    public function testLinksAndSelectedBalancesAreRevalidatedAtApprovalAndReceipt()
    {
        $id = $this->successful($this->submit_linked([$this->linked_line(3)]))['branch_item_transfer_id'];
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no='different' WHERE id=1");
        $this->rejected($this->controller->change(['transfer_id' => $id, 'status' => 'approved']), 'receipt_note_item_expiry_date_id');
        $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET batch_no='12' WHERE id=1");
        $this->successful($this->controller->change(['transfer_id' => $id, 'status' => 'approved']));
        $pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date SET qty_balance=1 WHERE id=1");
        $this->rejected($this->receive($id, $this->line_ids($id), [2]), 'allocation 1');
        $this->assertEquals(0, $pdo->query("SELECT received_qty FROM branch_item_transfer_item WHERE branch_item_transfer_id=$id")->fetchColumn());
        $this->assertEquals(10, $pdo->query("SELECT current_qty FROM `$schema`.branch_item WHERE id=1")->fetchColumn());
    }

    public function testExpiryMetadataUsesSourceBranchAndPreservesMissingLinks()
    {
        $id = $this->successful($this->submit_linked([$this->linked_line()]))['branch_item_transfer_id'];
        $schema = Receipt_batch_fixture::$schemas[5];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note SET branch_id=5 WHERE id=1;
            UPDATE `$schema`.receipt_note_item_expiry_date SET qty_balance=99 WHERE id=1");
        $legacy = $this->successful($this->submit([$this->line(1)], 4))['branch_item_transfer_id'];
        Receipt_batch_fixture::$pdo->exec("UPDATE branch_item_transfer SET branch_id_from=5 WHERE id=$legacy;
            UPDATE branch_item_transfer_item SET receipt_note_item_expiry_date_id=1 WHERE branch_item_transfer_id=$legacy");
        $data = $this->successful($this->controller->details())['data'];
        $data = array_column($data, null, 'id');
        $this->assertEquals(3, $data[$id]['transfer_items'][0]['receipt_note_item_expiry_date']['qty_balance']);
        $this->assertEquals(99, $data[$legacy]['transfer_items'][0]['receipt_note_item_expiry_date']['qty_balance']);
        $detail = $this->successful($this->controller->details(['transfer_id' => $id]))['data'][0]['transfer_items'][0];
        $this->assertEquals(1, $detail['receipt_note_item_expiry_date_id']);
        $this->assertSame('2028-01-01', $detail['receipt_note_item_expiry_date']['date']);
        $this->assertArrayNotHasKey('linked_item_id', $detail['receipt_note_item_expiry_date']);
        $this->successful($this->receive($id, $this->line_ids($id), [1]));
        $detail = $this->successful($this->controller->details(['transfer_id' => $id]))['data'][0]['transfer_items'][0];
        $this->assertEquals(2, $detail['receipt_note_item_expiry_date']['qty_balance']);
        Receipt_batch_fixture::$pdo->exec('UPDATE `'.Receipt_batch_fixture::$schemas[2].'`.receipt_note_item_expiry_date SET is_deleted=1 WHERE id=1');
        $detail = $this->successful($this->controller->details(['transfer_id' => $id]))['data'][0]['transfer_items'][0];
        $this->assertNull($detail['receipt_note_item_expiry_date']);
        $this->assertEquals(1, $detail['receipt_note_item_expiry_date_id']);
    }

    public function testExpiryMetadataNullForLegacyWrongItemBatchAndMissingRows()
    {
        $model = new Branch_item_transfer_item(db_connect('master'));
        $items = [$this->linked_line(), $this->line(), $this->linked_line(1, 9999),
            $this->linked_line(1, 1, 'wrong'), array_replace($this->linked_line(), ['item_id' => 2])];
        $result = $model->get_transfer_expiry_details([['branch_id_from' => 2, 'transfer_items' => $items]]);
        $this->assertNotNull($result[0]['transfer_items'][0]['receipt_note_item_expiry_date']);
        foreach (array_slice($result[0]['transfer_items'], 1) as $item) {
            $this->assertNull($item['receipt_note_item_expiry_date']);
        }
    }

    public function testMetadataQueryFailureRemainsAnError()
    {
        $id = $this->successful($this->submit_linked([$this->linked_line()]))['branch_item_transfer_id'];
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("RENAME TABLE `$schema`.receipt_note_item_expiry_date TO `$schema`.missing_expiry_fixture");
        try {
            $this->rejected($this->controller->details(['transfer_id' => $id]), 'Failed to read receipt expiry details');
        } finally {
            Receipt_batch_fixture::$pdo->exec("RENAME TABLE `$schema`.missing_expiry_fixture TO `$schema`.receipt_note_item_expiry_date");
        }
    }

    public function testConcurrentLinkedReservationsCannotOverbookAnAllocation()
    {
        $statuses = [];
        $workers = [$this->worker(['submit_linked', 3]), $this->worker(['submit_linked', 4])];
        foreach ($workers as $worker) {
            $statuses[] = $this->worker_result($worker)['status'];
        }
        sort($statuses);
        $this->assertSame([200, 400], $statuses);
        $this->assertEquals(1, Receipt_batch_fixture::$pdo->query('SELECT COUNT(*) FROM branch_item_transfer_item')->fetchColumn());
    }

    public function testLinkedCodesRemainCaseSensitiveAndKeepLeadingZeros()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date
            SET batch_no=CASE id WHEN 1 THEN 'AB123' WHEN 2 THEN 'ab123' WHEN 3 THEN '0012' ELSE '12' END");
        $this->rejected($this->submit_linked([$this->linked_line(1, 1, 'ab123')]), 'items[0]');
        $this->rejected($this->submit_linked([$this->linked_line(1, 3, '12')]), 'items[0]');
        $id = $this->successful($this->submit_linked([$this->linked_line(1, 1, 'AB123'),
            $this->linked_line(1, 2, 'ab123'), $this->linked_line(1, 3, '0012'), $this->linked_line(1, 4, '12')]))['branch_item_transfer_id'];
        $this->successful($this->receive($id, $this->line_ids($id), [1, 1, 1, 1]));
        $rows = $this->successful($this->controller->details(['transfer_id' => $id]))['data'][0]['transfer_items'];
        $this->assertSame(['AB123', 'ab123', '0012', '12'], array_column($rows, 'batch_no'));
        $this->assertSame(['AB123', 'ab123', '0012', '12'], array_column(array_column($rows, 'receipt_note_item_expiry_date'), 'batch_no'));
    }

    public function testLinkedBalanceFailureRollsBackEarlierSelectedDeductions()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        $pdo = Receipt_batch_fixture::$pdo;
        $id = $this->successful($this->submit_linked([$this->linked_line(), $this->linked_line(1, 4)]))['branch_item_transfer_id'];
        $before = $this->expiry_balances(2);
        $stock = $this->inventory_snapshot(2);
        $pdo->exec("CREATE TRIGGER `$schema`.fail_fixture_linked_balance BEFORE UPDATE ON `$schema`.receipt_note_item_expiry_date
            FOR EACH ROW BEGIN IF NEW.id=4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture selected balance failure'; END IF; END");
        try {
            $this->rejected($this->receive($id, $this->line_ids($id), [1, 1]), 'Failed to update source batch balance');
            $this->assertSame($before, $this->expiry_balances(2));
            $this->assertSame($stock, $this->inventory_snapshot(2));
            $this->assertEquals(0, $pdo->query("SELECT SUM(received_qty) FROM branch_item_transfer_item WHERE branch_item_transfer_id=$id")->fetchColumn());
            $this->assertSame('pending', $pdo->query("SELECT status FROM branch_item_transfer WHERE id=$id")->fetchColumn());
        } finally {
            $pdo->exec("DROP TRIGGER `$schema`.fail_fixture_linked_balance");
        }
    }

    private function seed_report_transfer($transfer_id, $line_id, $values = [])
    {
        $values += ['branch_id_from' => 2, 'branch_id_to' => 3, 'item_id' => 1, 'unit' => 11,
            'branch_item_id' => 1, 'transfer_qty' => '2.0000', 'received_qty' => '1.0000', 'batch_no' => '12',
            'receipt_note_item_expiry_date_id' => null, 'updated_on' => '2026-10-15 10:00:00',
            'status' => 'opened', 'is_deleted' => 0];
        $header = Receipt_batch_fixture::$pdo->prepare('INSERT IGNORE INTO branch_item_transfer
            (id,branch_id_from,branch_id_to,status,added_on) VALUES(?,?,?,?,?)');
        $header->execute([$transfer_id, $values['branch_id_from'], $values['branch_id_to'], $values['status'], '2026-10-15 09:00:00']);
        $fields = ['item_id', 'unit', 'branch_item_id', 'transfer_qty', 'received_qty', 'batch_no',
            'receipt_note_item_expiry_date_id', 'updated_on', 'is_deleted'];
        $query = Receipt_batch_fixture::$pdo->prepare('INSERT INTO branch_item_transfer_item
            (branch_item_transfer_id,id,'.implode(',', $fields).') VALUES('.implode(',', array_fill(0, count($fields) + 2, '?')).')');
        $query->execute(array_merge([$transfer_id, $line_id], array_map(static function ($field) use ($values) {
            return $values[$field];
        }, $fields)));
    }

    private function report_transfers($filters = [])
    {
        $report = $this->successful($this->controller->report($filters));
        return array_values(array_filter($report['data'], static function ($row) {
            return $row['activity'] === 'Transfer';
        }));
    }

    public function testTransferGroupsSumDecimalsAndCollectSortedDistinctReferencesAcrossDates()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note SET status='approved' WHERE id=2");
        $this->seed_report_transfer(10, 30, ['received_qty' => '1.2345', 'transfer_qty' => '2.0001', 'receipt_note_item_expiry_date_id' => 1]);
        $this->seed_report_transfer(2, 3, ['received_qty' => '2.3456', 'transfer_qty' => '3.0002',
            'receipt_note_item_expiry_date_id' => 2, 'updated_on' => '2026-10-12 10:00:00']);
        $this->seed_report_transfer(2, 4, ['received_qty' => '0.0001', 'transfer_qty' => '0.0001',
            'receipt_note_item_expiry_date_id' => 2, 'updated_on' => '2026-10-12 11:00:00']);
        $this->seed_report_transfer(3, 5, ['received_qty' => '4.0000', 'transfer_qty' => '4.0000',
            'receipt_note_item_expiry_date_id' => 5, 'updated_on' => '2026-10-16 10:00:00']);
        $row = $this->report_transfers()[0];
        $this->assertSame('7.5802', $row['quantity']);
        $this->assertSame('9.0004', $row['transfer_qty']);
        $this->assertNull($row['beginning_qty']);
        $this->assertNull($row['current_qty']);
        $this->assertSame('2,3,10', $row['transfer_id']);
        $this->assertSame('3,4,5,30', $row['transfer_item_id']);
        $this->assertSame('1,2', $row['receipt_note_id']);
        $this->assertSame('1,3', $row['receipt_note_item_id']);
        $this->assertSame('2026-10-12,2026-10-15,2026-10-16', $row['movement_date']);
        $this->assertEquals(1, $row['branch_item_id']);
        $this->assertSame('Fixture stock item', $row['item_name']);
        $this->assertSame('Oakridge', $row['branch_name_from']);
        $this->assertSame('Mactan', $row['branch_name_to']);
        $this->assertNull($row['expiry_id']);
        $this->assertNull($row['expiry_date']);
        $this->assertArrayNotHasKey('_receipt_expiry_id', $row);
    }

    public function testTransferGroupsKeepBranchesItemsExactBatchesAndUnitsSeparate()
    {
        Receipt_batch_fixture::$pdo->exec("INSERT INTO item_conversion VALUES(14,1,'half box','purchase',2,0)");
        $variants = [[], ['branch_id_from' => 5], ['branch_id_to' => 4],
            ['branch_id_from' => 3, 'branch_id_to' => 2], ['item_id' => 2], ['batch_no' => '0012'],
            ['batch_no' => 'AB123'], ['batch_no' => 'ab123'], ['batch_no' => '0'],
            ['unit' => 14], ['unit' => 12], ['unit' => 999]];
        foreach ($variants as $index => $values) {
            $this->seed_report_transfer(100 + $index, 200 + $index, $values);
        }
        $this->seed_report_transfer(100, 300, []);
        $this->seed_report_transfer(111, 301, ['unit' => 999]);
        $rows = $this->report_transfers();
        $this->assertCount(count($variants), $rows);
        $this->assertCount(2, array_filter($rows, static function ($row) { return (float) $row['quantity'] === 2.0; }));
        $this->assertSame('0', $this->report_transfers(['batch_no' => '0'])[0]['batch_no']);
        $this->assertCount(1, $this->report_transfers(['batch_no' => 'AB123']));
        $this->assertCount(1, $this->report_transfers(['batch_no' => 'ab123']));
        $this->assertCount(1, $this->report_transfers(['batch_no' => '0012']));
        $purchase = array_values(array_filter($rows, static function ($row) {
            return $row['conversion_type'] === 'purchase' && $row['batch_no'] === '12'
                && (int) $row['branch_id_from'] === 2 && (int) $row['branch_id_to'] === 3;
        }));
        $this->assertCount(2, $purchase);
        $this->assertEquals([11, 14], array_column($purchase, 'unit'));
    }

    public function testTransferGroupsKeepNullAndMixedInventoryMetadataHonest()
    {
        $this->seed_report_transfer(1, 1);
        $this->seed_report_transfer(2, 2, ['branch_item_id' => 99]);
        $row = $this->report_transfers()[0];
        $this->assertNull($row['branch_item_id']);
        $this->assertNull($row['beginning_qty']);
        $this->assertNull($row['current_qty']);
        $this->assertNull($row['receipt_note_id']);
        $this->assertNull($row['receipt_note_item_id']);
        Receipt_batch_fixture::$pdo->exec('UPDATE branch_item_transfer_item SET branch_item_id=NULL WHERE id=2');
        $this->assertNull($this->report_transfers()[0]['branch_item_id']);
    }

    public function testTransferReceiptReferencesUseOnlyValidSourceBranchAllocations()
    {
        $warehouse = Receipt_batch_fixture::$schemas[5];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$warehouse`.receipt_note SET branch_id=5 WHERE id=1;
            UPDATE `$warehouse`.receipt_note_item_expiry_date SET receipt_note_item_id=2 WHERE id=1");
        $this->seed_report_transfer(1, 1, ['receipt_note_item_expiry_date_id' => 1]);
        $this->seed_report_transfer(2, 2, ['branch_id_from' => 5, 'receipt_note_item_expiry_date_id' => 1]);
        $rows = array_column($this->report_transfers(), null, 'branch_id_from');
        $this->assertSame('1', $rows[2]['receipt_note_item_id']);
        $this->assertSame('2', $rows[5]['receipt_note_item_id']);
        foreach ([null, 3, 5, 6, 9999] as $index => $expiry_id) {
            $this->seed_report_transfer(10 + $index, 10 + $index, ['branch_id_to' => 4,
                'receipt_note_item_expiry_date_id' => $expiry_id]);
        }
        $invalid = array_values(array_filter($this->report_transfers(), static function ($row) {
            return (int) $row['branch_id_to'] === 4;
        }))[0];
        $this->assertEquals(5, $invalid['quantity']);
        $this->assertNull($invalid['receipt_note_id']);
        $this->assertNull($invalid['receipt_note_item_id']);
        $oakridge = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$oakridge`.receipt_note_item_expiry_date SET is_deleted=1 WHERE id=1");
        $rows = array_column(array_filter($this->report_transfers(), static function ($row) {
            return (int) $row['branch_id_to'] === 3;
        }), null, 'branch_id_from');
        $this->assertNull($rows[2]['receipt_note_item_id']);
        $this->assertSame('2', $rows[5]['receipt_note_item_id']);
    }

    public function testGroupedReportFiltersCountsPagesAndReceiptBalancesStayConsistent()
    {
        $this->seed_report_transfer(1, 1, ['updated_on' => '2026-10-15 10:00:00']);
        $this->seed_report_transfer(2, 2, ['updated_on' => '2026-10-01 10:00:00']);
        $this->seed_report_transfer(3, 3, ['branch_id_to' => 4, 'updated_on' => '2026-10-02 10:00:00']);
        $this->seed_report_transfer(4, 4, ['received_qty' => 0]);
        $this->seed_report_transfer(5, 5, ['is_deleted' => 1]);
        $this->seed_report_transfer(6, 6, ['batch_no' => null]);
        $this->seed_report_transfer(7, 7, ['item_id' => 3]);
        $this->seed_report_transfer(8, 8, []);
        Receipt_batch_fixture::$pdo->exec('UPDATE branch_item_transfer SET is_deleted=1 WHERE id=8');
        $filters = ['item_id' => 1, 'batch_no' => '12', 'brand' => 'Acme', 'generic' => 'Compound A',
            'dosage' => '10 mg', 'form' => 'Tablet', 'kind' => 'pharmacy,other', 'term' => 'stock'];
        $report = $this->successful($this->controller->report($filters));
        $this->assertSame(5, $report['size']);
        $this->assertSame(['Transfer', 'Receipt', 'Receipt', 'Receipt', 'Transfer'], array_column($report['data'], 'activity'));
        $this->assertSame('2026-10-01,2026-10-15', $report['data'][0]['movement_date']);
        $receipts = array_values(array_filter($report['data'], static function ($row) { return $row['activity'] === 'Receipt'; }));
        $this->assertEquals([3, 2, 20], array_column($receipts, 'quantity'));
        $this->assertSame(array_column($receipts, 'quantity'), array_column($receipts, 'beginning_qty'));
        foreach (range(1, 5) as $page) {
            $result = $this->successful($this->controller->report($filters + ['page' => $page, 'data_size' => 1]));
            $this->assertSame(5, $result['size']);
            $this->assertSame([$report['data'][$page - 1]], $result['data']);
        }
        $this->assertSame(404, $this->controller->report($filters + ['page' => 6, 'data_size' => 1])['status']);
        $this->assertSame(404, $this->controller->report(array_replace($filters, ['term' => 'without']))['status']);
    }

    public function testTransferReferenceListsAreNotTruncatedAndOnlyPageLinksAreLoaded()
    {
        for ($id = 1; $id <= 1100; $id++) {
            $this->seed_report_transfer(1, $id, ['receipt_note_item_expiry_date_id' => 1]);
        }
        $this->seed_report_transfer(2, 1101, ['branch_id_from' => 5,
            'receipt_note_item_expiry_date_id' => 1, 'updated_on' => '2026-10-16 10:00:00']);
        $model = new class(db_connect('master')) extends Branch_item_transfer_item {
            public $loaded_branches = [];
            public function get_receipt_expiry_details($branch_id, $ids)
            {
                $this->loaded_branches[] = (int) $branch_id;
                return parent::get_receipt_expiry_details($branch_id, $ids);
            }
        };
        $rows = $model->get_transfer_report(null, null, 4, 1);
        $this->assertCount(1, $rows);
        $this->assertSame('Transfer', $rows[0]['activity']);
        $this->assertSame(implode(',', range(1, 1100)), $rows[0]['transfer_item_id']);
        $this->assertGreaterThan(1024, strlen($rows[0]['transfer_item_id']));
        $this->assertSame('1', $rows[0]['transfer_id']);
        $this->assertSame('1', $rows[0]['receipt_note_id']);
        $this->assertSame('1100.0000', $rows[0]['quantity']);
        $this->assertSame([2], $model->loaded_branches);
        $this->assertSame(6, $model->get_transfer_report_count());
    }

    public function testReportOrdersExactBatchCodesBeforeDatesAndPagination()
    {
        $schema = Receipt_batch_fixture::$schemas[2];
        Receipt_batch_fixture::$pdo->exec("UPDATE `$schema`.receipt_note_item_expiry_date
            SET batch_no=CASE id WHEN 1 THEN 'AB123' WHEN 2 THEN 'ab123' WHEN 3 THEN '0012' ELSE '0' END WHERE id<=4");
        foreach ([['0', '2026-10-04'], ['0012', '2026-10-04'], ['10', '2026-09-01'],
            ['2', '2026-09-01'], ['AB123', '2026-10-02'], ['ab123', '2026-10-05']] as $index => [$batch_no, $date]) {
            $this->seed_report_transfer($index + 1, $index + 1, ['batch_no' => $batch_no, 'updated_on' => $date.' 10:00:00']);
        }
        $this->seed_report_transfer(7, 7, ['batch_no' => 'AB123', 'branch_id_to' => 4, 'updated_on' => '2026-10-01 10:00:00']);
        $this->seed_report_transfer(8, 8, ['batch_no' => 'AB123', 'updated_on' => '2026-10-10 10:00:00']);
        $report = $this->successful($this->controller->report([]));
        $this->assertSame(11, $report['size']);
        $this->assertSame(['0', '0', '0012', '0012', '10', '2', 'AB123', 'AB123', 'AB123', 'ab123', 'ab123'],
            array_column($report['data'], 'batch_no'));
        $this->assertSame(['Receipt', 'Transfer', 'Receipt', 'Transfer', 'Transfer', 'Transfer',
            'Transfer', 'Receipt', 'Transfer', 'Receipt', 'Transfer'], array_column($report['data'], 'activity'));
        $this->assertSame('2026-10-01', $report['data'][6]['movement_date']);
        $this->assertSame('2026-10-02', $report['data'][7]['movement_date']);
        $this->assertSame('2026-10-02,2026-10-10', $report['data'][8]['movement_date']);
        $this->assertSame('5,8', $report['data'][8]['transfer_item_id']);
        $this->assertEquals(2, $report['data'][8]['quantity']);
        $this->assertSame(2, $this->successful($this->controller->report(['batch_no' => '0']))['size']);
        $pages = [];
        foreach (range(1, 6) as $page) {
            $result = $this->successful($this->controller->report(['page' => $page, 'data_size' => 2]));
            $this->assertSame(11, $result['size']);
            $this->assertSame(array_slice($report['data'], ($page - 1) * 2, 2), $result['data']);
            array_push($pages, ...$result['data']);
        }
        $this->assertSame($report['data'], $pages);
        $this->assertSame(404, $this->controller->report(['page' => 7, 'data_size' => 2])['status']);
    }

    public function testTransferGroupMetadataQueryFailuresRemainErrors()
    {
        $this->seed_report_transfer(1, 1, ['receipt_note_item_expiry_date_id' => 1]);
        $model = new class(db_connect('master')) extends Branch_item_transfer_item {
            public function get_receipt_expiry_details($branch_id, $ids) { return false; }
        };
        $this->assertFalse($model->get_transfer_report());
    }

    public function testAuthenticationIsPreserved()
    {
        $this->controller->authenticated = false;
        $this->assertSame(401, $this->controller->submit([])['status']);
        $this->assertSame(401, $this->controller->change([])['status']);
        $this->assertSame(401, $this->controller->report(['item_id' => 1])['status']);
        $this->assertSame(401, $this->controller->filters()['status']);
        $this->assertSame(401, $this->controller->batches([])['status']);
    }
}
