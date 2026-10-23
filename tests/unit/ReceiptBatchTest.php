<?php

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Receipt_batch_controller;

final class ReceiptBatchTest extends CIUnitTestCase
{
    /** @dataProvider batch_cases */
    public function testCsvMatchesExpiryBatches($csv, $expiry_batches, $valid, $normalized)
    {
        $expiry_dates = [];
        foreach ($expiry_batches as $batch_no) {
            $expiry_dates[] = ['qty' => '1', 'batch_no' => $batch_no];
        }
        $result = (new Receipt_batch_controller())->validate_batches([
            ['qty' => '20', 'batch_no' => $csv, 'expiry_dates' => $expiry_dates]
        ]);
        $this->assertSame($valid, $result['valid']);
        if ($valid) {
            $this->assertSame($normalized, $result['items'][0]['batch_no']);
        } else {
            $this->assertStringContainsString('items[0]', $result['error']);
        }
    }

    public function batch_cases()
    {
        return [
            'null' => [null, [null], true, null],
            'blank' => ['  ', [null], true, null],
            'zero' => ['0', [0], true, '0'],
            'integer parent' => [12, [12], true, '12'],
            'order' => ['13,12', [12, 13], true, '13,12'],
            'normalization' => [' 013,0012,013,,0 ', ['0012', '013', 0], true, '013,0012,0'],
            'repeated expiry batch' => ['12', [12, 12], true, '12'],
            'partial unlabelled' => ['12', [12, null], true, '12'],
            'mismatch' => ['12,13', [12], false, null],
            'missing parent' => [null, [12], false, null],
            'missing child' => ['12', [null], false, null],
            'invalid token' => ['12,x', [12], false, null],
            'negative' => ['-1', [-1], false, null],
            'decimal' => ['1.2', [1], false, null],
            'large numeric code' => ['2147483648', ['2147483648'], true, '2147483648'],
            'alphanumeric' => ['AB123', ['AB123'], true, 'AB123'],
            'punctuation and spaces' => [' LOT-2026/01 A.2 ', ['LOT-2026/01 A.2'], true, 'LOT-2026/01 A.2'],
            'case sensitive' => ['AB123', ['ab123'], false, null],
            'leading zero mismatch' => ['0012', [12], false, null],
            'distinct codes' => ['AB12,ab12,0012,12', ['12', '0012', 'ab12', 'AB12'], true, 'AB12,ab12,0012,12'],
            'unicode' => ['批次-001', ['批次-001'], true, '批次-001'],
            'max unicode length' => [str_repeat('漢', 255), [str_repeat('漢', 255)], true, str_repeat('漢', 255)],
            'too long' => [str_repeat('A', 256), [str_repeat('A', 256)], false, null],
            'csv too long' => [str_repeat('A', 128).','.str_repeat('B', 128), [str_repeat('A', 128), str_repeat('B', 128)], false, null],
            'control character' => ["AB\n12", ["AB\n12"], false, null],
            'invalid utf8' => ["AB\xFF12", ['AB12'], false, null],
            'comma in child' => ['AB12', ['AB,12'], false, null],
            'limit' => ['2147483647', ['2147483647'], true, '2147483647'],
            'child type' => ['12', [true], false, null],
            'parent type' => [[12], [12], false, null],
        ];
    }

    public function testQuantityAllocationAndIndexedErrors()
    {
        $controller = new Receipt_batch_controller();
        $this->assertFalse($controller->validate_batches([
            ['qty' => 2, 'batch_no' => '1', 'expiry_dates' => [['qty' => 3, 'batch_no' => 1]]]
        ])['valid']);
        $this->assertFalse($controller->validate_batches([
            ['qty' => 2, 'batch_no' => '1', 'expiry_dates' => [['qty' => -1, 'batch_no' => 1]]]
        ])['valid']);
        $result = $controller->validate_batches([
            ['qty' => 5, 'batch_no' => '1', 'expiry_dates' => [['qty' => 2, 'batch_no' => 1]]],
            ['qty' => 5, 'batch_no' => '2', 'expiry_dates' => [['qty' => 2, 'batch_no' => 3]]]
        ]);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('items[1]', $result['error']);
    }

    public function testOmittedFieldsRetainSavedBatchesAndNullClearsThem()
    {
        $item_model = new class {
            public function select($fields, $where, $limit)
            {
                return (int) $where['id'] === 1 && (int) $where['receipt_note_id'] === 7
                    ? (object) ['id' => 1, 'qty' => '10', 'batch_no' => '12,13'] : null;
            }
        };
        $expiry_model = new class {
            public function get_details($item_id)
            {
                return [['id' => 11, 'batch_no' => 12], ['id' => 12, 'batch_no' => 13]];
            }
        };
        $controller = new Receipt_batch_controller($item_model, $expiry_model);
        $result = $controller->validate_batches([
            ['id' => 1, 'qty' => 10, 'expiry_dates' => [['id' => 11, 'qty' => 3], ['id' => 12, 'qty' => 4]]]
        ], 7);
        $this->assertTrue($result['valid']);
        $this->assertSame('12,13', $result['items'][0]['batch_no']);
        $this->assertSame('12', $result['items'][0]['expiry_dates'][0]['batch_no']);
        $result = $controller->validate_batches([
            ['id' => 1, 'qty' => 10, 'batch_no' => null, 'expiry_dates' => [
                ['id' => 11, 'qty' => 3, 'batch_no' => null], ['id' => 12, 'qty' => 4, 'batch_no' => null]
            ]]
        ], 7);
        $this->assertTrue($result['valid']);
        $this->assertNull($result['items'][0]['batch_no']);
        $this->assertNull($result['items'][0]['expiry_dates'][0]['batch_no']);
        $this->assertFalse($controller->validate_batches([
            ['id' => 1, 'batch_no' => '12,13', 'expiry_dates' => [
                ['id' => 11, 'qty' => 1, 'batch_no' => 12], ['id' => 11, 'qty' => 1, 'batch_no' => 13]
            ]]
        ], 7)['valid']);
        $this->assertFalse($controller->validate_batches([['id' => 99]], 7)['valid']);
        $this->assertFalse($controller->validate_batches([
            ['id' => 1, 'expiry_dates' => [['id' => 99, 'qty' => 1]]]
        ], 7)['valid']);
    }

    public function testAuthenticationStillPrecedesReceiptWrites()
    {
        $controller = new Receipt_batch_controller();
        $controller->authenticated = false;
        $this->assertSame(['status' => 401], $controller->create());
        $this->assertSame(['status' => 401], $controller->update());
    }
}
