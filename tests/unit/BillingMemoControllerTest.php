<?php

use App\Models\Billing;
use App\Models\Credit_memo;
use App\Models\Debit_memo;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Billing_memo_controller;
use Tests\Support\Controllers\Credit_memo_controller;
use Tests\Support\Controllers\Debit_memo_controller;

final class BillingMemoControllerTest extends CIUnitTestCase
{
    /** @dataProvider memo_types */
    public function testApprovalRequiresAuthenticationBeforeAnyTransaction($controller_class, $model_class)
    {
        $db = new class {
            public function transBegin() { throw new RuntimeException('Unauthenticated transaction'); }
        };
        $memo = $this->getMockBuilder($model_class)->disableOriginalConstructor()->onlyMethods(['get_for_update'])->getMock();
        $memo->expects($this->never())->method('get_for_update');
        $billing = $this->getMockBuilder(Billing::class)->disableOriginalConstructor()->getMock();
        $controller = new $controller_class($db, $memo, $billing);
        $controller->authenticated = false;
        $this->assertSame(401, $controller->change(1)['status']);
    }

    /** @dataProvider memo_types */
    public function testBeginFailureDoesNotReadOrWriteMemo($controller_class, $model_class)
    {
        $db = new class {
            public function transBegin() { return false; }
        };
        $memo = $this->getMockBuilder($model_class)->disableOriginalConstructor()->onlyMethods(['get_for_update'])->getMock();
        $memo->expects($this->never())->method('get_for_update');
        $billing = $this->getMockBuilder(Billing::class)->disableOriginalConstructor()->getMock();
        $this->assertSame(400, (new $controller_class($db, $memo, $billing))->change(1)['status']);
    }

    /** @dataProvider memo_types */
    public function testReadFailureRollsBackAndDoesNotReportSuccess($controller_class, $model_class)
    {
        $db = new class {
            public $rollbacks = 0;
            public function transBegin() { return true; }
            public function transRollback() { $this->rollbacks++; return true; }
        };
        $memo = $this->getMockBuilder($model_class)->disableOriginalConstructor()->onlyMethods(['get_for_update'])->getMock();
        $memo->expects($this->once())->method('get_for_update')->with(1)->willReturn(false);
        $billing = $this->getMockBuilder(Billing::class)->disableOriginalConstructor()->getMock();
        $this->assertSame(400, (new $controller_class($db, $memo, $billing))->change(1)['status']);
        $this->assertSame(1, $db->rollbacks);
    }

    public function memo_types()
    {
        return [
            [Credit_memo_controller::class, Credit_memo::class],
            [Debit_memo_controller::class, Debit_memo::class],
        ];
    }

    public function testBillingGetRequiresAuthenticationBeforeQueries()
    {
        $billing = $this->getMockBuilder(Billing::class)->disableOriginalConstructor()->onlyMethods(['get_details'])->getMock();
        $billing->expects($this->never())->method('get_details');
        $credit = $this->getMockBuilder(Credit_memo::class)->disableOriginalConstructor()->getMock();
        $debit = $this->getMockBuilder(Debit_memo::class)->disableOriginalConstructor()->getMock();
        $controller = new Billing_memo_controller(null, $billing, $credit, $debit);
        $controller->authenticated = false;
        $this->assertSame(401, $controller->billings()['status']);
    }

    /** @dataProvider failing_memo_types */
    public function testBillingGetDoesNotSilentlyReplaceFailedMemoQueriesWithEmptyArrays($failing_type)
    {
        $billing = $this->getMockBuilder(Billing::class)->disableOriginalConstructor()->onlyMethods(['get_details', 'count_details'])->getMock();
        $billing->method('get_details')->willReturn([['id' => 4], ['id' => 9]]);
        $billing->method('count_details')->willReturn(2);
        $credit = $this->getMockBuilder(Credit_memo::class)->disableOriginalConstructor()->onlyMethods(['get_details_for_billing_ids'])->getMock();
        $debit = $this->getMockBuilder(Debit_memo::class)->disableOriginalConstructor()->onlyMethods(['get_details_for_billing_ids'])->getMock();
        $credit->expects($this->once())->method('get_details_for_billing_ids')->with([4, 9])->willReturn($failing_type === 'credit' ? false : []);
        $debit->expects($this->once())->method('get_details_for_billing_ids')->with([4, 9])->willReturn($failing_type === 'debit' ? false : []);
        $response = (new Billing_memo_controller(null, $billing, $credit, $debit))->billings();
        $this->assertSame(400, $response['status']);
        $this->assertSame(['Failed to get billing memos.'], $response['data']);
    }

    public function failing_memo_types()
    {
        return [['credit'], ['debit']];
    }

    public function testMemoArithmeticUsesDecimalBindingsAndRejectsInvalidTypes()
    {
        $db = new class {
            public $queries = [];
            public function query($sql, $binds) { $this->queries[] = [$sql, $binds]; return true; }
        };
        $model = new Billing($db);
        $this->assertTrue($model->apply_memo(4, '0.01', 'credit', 7));
        $this->assertStringContainsString('balance = balance - CAST(? AS DECIMAL(13,2))', $db->queries[0][0]);
        $this->assertStringContainsString('credit_memo_total = credit_memo_total + CAST(? AS DECIMAL(13,2))', $db->queries[0][0]);
        $this->assertSame(['0.01', '0.01', '0.01', 7], array_slice($db->queries[0][1], 0, 4));
        $this->assertSame(4, $db->queries[0][1][5]);
        $this->assertTrue($model->apply_memo(4, '10.99', 'debit', 7));
        $this->assertStringContainsString('balance = balance + CAST(? AS DECIMAL(13,2))', $db->queries[1][0]);
        foreach ([['-1', 'credit'], ['invalid', 'debit'], ['1', 'balance'], ['1', 'credit; DROP TABLE billing']] as [$amount, $type]) {
            $this->assertFalse($model->apply_memo(4, $amount, $type, 7));
        }
        $this->assertCount(2, $db->queries);
    }
}
