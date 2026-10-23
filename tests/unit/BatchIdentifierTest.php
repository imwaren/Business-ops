<?php

use App\Controllers\Items;
use App\Controllers\Receipt_notes;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Receipt_batch_controller;
use Tests\Support\Controllers\Transfer_batch_controller;

final class BatchIdentifierTest extends CIUnitTestCase
{
    /** @dataProvider batch_values */
    public function testReceiptAndTransferNormalizationAgree($input, $valid, $expected)
    {
        foreach ([[Receipt_notes::class, new Receipt_batch_controller(), '_normalize_batch_no'],
            [Items::class, new Transfer_batch_controller(), '_normalize_transfer_batch_no']] as [$class, $controller, $method]) {
            $normalized = null;
            $arguments = [$input, &$normalized];
            $result = (new ReflectionMethod($class, $method))->invokeArgs($controller, $arguments);
            $this->assertSame($valid, $result);
            $this->assertSame($expected, $normalized);
        }
    }

    public function batch_values()
    {
        return [
            'null' => [null, true, null],
            'blank' => ['  ', true, null],
            'integer zero' => [0, true, '0'],
            'integer compatibility' => [12, true, '12'],
            'leading zeros' => [' 0012 ', true, '0012'],
            'uppercase' => ['AB123', true, 'AB123'],
            'lowercase' => ['ab123', true, 'ab123'],
            'separators and spaces' => [' LOT-2026/01 A.2 ', true, 'LOT-2026/01 A.2'],
            'unicode' => ['批次-001', true, '批次-001'],
            'unicode limit' => [str_repeat('漢', 255), true, str_repeat('漢', 255)],
            'large digits' => ['999999999999999999999', true, '999999999999999999999'],
            'punctuation code' => ['-1.5', true, '-1.5'],
            'overlength' => [str_repeat('A', 256), false, null],
            'comma' => ['A,B', false, null],
            'tab' => ["A\tB", false, null],
            'newline' => ["A\nB", false, null],
            'null byte' => ["A\0B", false, null],
            'unicode control' => ["A\u{0085}B", false, null],
            'invalid utf8' => ["A\xFFB", false, null],
            'float' => [1.5, false, null],
            'boolean' => [true, false, null],
            'array' => [[], false, null],
            'negative integer' => [-1, false, null],
        ];
    }
}
