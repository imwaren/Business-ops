<?php

use App\Models\Branch_item_transfer;
use App\Models\Branch_item_transfer_item;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Transfer_batch_controller;

final class TransferBatchControllerTest extends CIUnitTestCase
{
    /** @dataProvider transaction_failures */
    public function testTransactionFailuresReturnFailureAndSkipSuccess($begin_ok, $expected_commits, $expected_rollbacks)
    {
        $db = new class($begin_ok) {
            public $commits = 0;
            public $rollbacks = 0;
            private $begin_ok;

            public function __construct($begin_ok) { $this->begin_ok = $begin_ok; }
            public function transBegin() { return $this->begin_ok; }
            public function transStatus() { return true; }
            public function transCommit() { $this->commits++; return false; }
            public function transRollback() { $this->rollbacks++; return true; }
        };
        $header = $this->getMockBuilder(Branch_item_transfer::class)->disableOriginalConstructor()
            ->onlyMethods(['select', 'insert'])->getMock();
        $header->method('select')->willReturn(null);
        $header->expects($begin_ok ? $this->once() : $this->never())->method('insert')->willReturn(1);
        $lines = $this->getMockBuilder(Branch_item_transfer_item::class)->disableOriginalConstructor()
            ->onlyMethods(['validate_quantities', 'insert'])->getMock();
        $lines->method('validate_quantities')->willReturn(true);
        $lines->method('insert')->willReturn(1);
        $controller = new class($db, $header, $lines) extends Transfer_batch_controller {
            public function __construct($db, $header, $lines)
            {
                $this->db = $db;
                $this->branch_item_transfer = $header;
                $this->branch_item_transfer_item = $lines;
            }
            protected function _load_transfer_models() {}
        };
        $result = $controller->submit(['branch_id_from' => 2, 'branch_id_to' => 3,
            'items' => [['item_id' => 1, 'branch_item_id' => 1, 'unit' => 11, 'transfer_qty' => 1, 'batch_no' => 12, 'receipt_note_item_expiry_date_id' => 1]]]);
        $this->assertSame(400, $result['status']);
        $this->assertSame($expected_commits, $db->commits);
        $this->assertSame($expected_rollbacks, $db->rollbacks);
    }

    public function testExpiryMetadataBatchesAndDeduplicatesIdsBySourceBranch()
    {
        $model = $this->getMockBuilder(Branch_item_transfer_item::class)->disableOriginalConstructor()
            ->onlyMethods(['get_receipt_expiry_details'])->getMock();
        $calls = [];
        $model->expects($this->exactly(2))->method('get_receipt_expiry_details')
            ->willReturnCallback(static function ($branch_id, $ids) use (&$calls) {
                $calls[$branch_id] = $ids;
                return array_map(static function ($id) use ($branch_id) {
                    return ['id' => $id, 'linked_item_id' => 1, 'batch_no' => '12', 'qty_balance' => (string) $branch_id];
                }, $ids);
            });
        $line = ['item_id' => 1, 'batch_no' => '12', 'receipt_note_item_expiry_date_id' => 1];
        $transfers = [
            ['branch_id_from' => 2, 'transfer_items' => [$line, $line]],
            ['branch_id_from' => 5, 'transfer_items' => [$line]],
            ['branch_id_from' => 2, 'transfer_items' => [array_replace($line, ['receipt_note_item_expiry_date_id' => 2])]],
        ];
        $result = $model->get_transfer_expiry_details($transfers);
        $this->assertSame([2 => [1, 2], 5 => [1]], $calls);
        $this->assertSame('2', $result[0]['transfer_items'][0]['receipt_note_item_expiry_date']['qty_balance']);
        $this->assertSame('5', $result[1]['transfer_items'][0]['receipt_note_item_expiry_date']['qty_balance']);
    }

    public function transaction_failures()
    {
        return ['begin failure' => [false, 0, 0], 'commit failure' => [true, 1, 1]];
    }
}
