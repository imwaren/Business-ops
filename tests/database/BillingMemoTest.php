<?php

use App\Models\Billing;
use App\Models\Credit_memo;
use App\Models\Debit_memo;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Billing_memo_controller;
use Tests\Support\Controllers\Credit_memo_controller;
use Tests\Support\Controllers\Debit_memo_controller;
use Tests\Support\Database\Billing_memo_fixture;

final class BillingMemoTest extends CIUnitTestCase
{
    private static $fixture_ready = false;
    private $memo_db;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (getenv('BILLING_MEMO_TEST_PORT')) {
            Billing_memo_fixture::create();
            self::$fixture_ready = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$fixture_ready) {
            $this->markTestSkipped('An explicit disposable billing memo container is required.');
        }
        Billing_memo_fixture::reset();
        $this->memo_db = Billing_memo_fixture::connection();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture_ready) {
            Billing_memo_fixture::destroy();
        }
        parent::tearDownAfterClass();
    }

    private function billing($id = 1)
    {
        $query = Billing_memo_fixture::$pdo->prepare('SELECT * FROM billing WHERE id=?');
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private function response($result, $status = 200)
    {
        $this->assertSame($status, $result['status'], json_encode($result));
        return $result['data'];
    }

    private function posting_counts()
    {
        $counts = [];
        foreach (['fixture_ledger', 'fixture_journal', 'fixture_journal_item'] as $table) {
            $counts[] = (int) Billing_memo_fixture::$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        }
        return $counts;
    }

    public function testCreditAndDebitAdjustBalanceAndKeepPaymentsAndGrandTotal()
    {
        $this->response((new Credit_memo_controller($this->memo_db))->change(1));
        $billing = $this->billing();
        $this->assertSame('699.75', $billing['balance']);
        $this->assertSame('100.25', $billing['credit_memo_total']);
        $this->assertSame('0.00', $billing['debit_memo_total']);
        $this->assertSame('open_bill', $billing['status']);
        $this->assertSame('7', (string) $billing['updated_by']);
        $this->assertNotNull($billing['updated_on']);
        $this->response((new Debit_memo_controller($this->memo_db))->change(1));
        $billing = $this->billing();
        $this->assertSame('750.50', $billing['balance']);
        $this->assertSame('50.75', $billing['debit_memo_total']);
        $this->assertSame('1000.00', $billing['grand_total']);
        $this->assertSame('200.00', (new Billing($this->memo_db))->get_details(1)[0]['paid_amount']);
        $this->assertSame([4, 2, 4], $this->posting_counts());
        $this->assertSame('151.00', Billing_memo_fixture::$pdo->query('SELECT SUM(debit) FROM fixture_ledger')->fetchColumn());
        $this->assertSame('151.00', Billing_memo_fixture::$pdo->query('SELECT SUM(credit) FROM fixture_ledger')->fetchColumn());
    }

    public function testNegativeBalanceClosesBillingAndDebitReopensIt()
    {
        Billing_memo_fixture::$pdo->exec('UPDATE credit_memo SET amount=900.25 WHERE id=1; UPDATE debit_memo SET amount=150.75 WHERE id=1');
        $this->response((new Credit_memo_controller($this->memo_db))->change(1));
        $this->assertSame('-100.25', $this->billing()['balance']);
        $this->assertSame('closed_bill', $this->billing()['status']);
        $this->response((new Debit_memo_controller($this->memo_db))->change(1));
        $this->assertSame('50.50', $this->billing()['balance']);
        $this->assertSame('open_bill', $this->billing()['status']);
    }

    public function testExactZeroAndFractionalAmountsAccumulateWithoutDrift()
    {
        Billing_memo_fixture::$pdo->exec('UPDATE credit_memo SET amount=799.99 WHERE id=1');
        Billing_memo_fixture::memo('credit_memo', ['id' => 2, 'amount' => '0.01']);
        $controller = new Credit_memo_controller($this->memo_db);
        $this->response($controller->change(1));
        $this->response($controller->change(2));
        $this->assertSame('0.00', $this->billing()['balance']);
        $this->assertSame('800.00', $this->billing()['credit_memo_total']);
        $this->assertSame('closed_bill', $this->billing()['status']);
    }

    /** @dataProvider unchanged_statuses */
    public function testOtherBillingStatusesArePreserved($status)
    {
        $query = Billing_memo_fixture::$pdo->prepare('UPDATE billing SET status=? WHERE id=1');
        $query->execute([$status]);
        $this->response((new Credit_memo_controller($this->memo_db))->change(1));
        $this->assertSame($status, $this->billing()['status']);
        $this->assertSame('699.75', $this->billing()['balance']);
    }

    public function unchanged_statuses()
    {
        return [['pending'], ['disapproved'], ['cancelled']];
    }

    public function testDisapprovalAndRepeatedOrInvalidTransitionsLeaveBillingUntouched()
    {
        $before = $this->billing();
        $credit = new Credit_memo_controller($this->memo_db);
        $debit = new Debit_memo_controller($this->memo_db);
        $this->response($credit->change(1, 'invalid'), 400);
        $this->response($credit->change(1, 'disapproved'));
        $this->response($debit->change(1, 'disapproved'));
        $this->response($credit->change(1), 400);
        $this->response($debit->change(1), 400);
        $this->assertSame($before, $this->billing());
        $this->assertSame([0, 0, 0], $this->posting_counts());
        $this->assertSame('7', (string) Billing_memo_fixture::$pdo->query('SELECT disapproved_by FROM credit_memo WHERE id=1')->fetchColumn());
    }

    public function testRepeatedApprovalCannotApplyAmountsOrPostAgain()
    {
        foreach ([new Credit_memo_controller($this->memo_db), new Debit_memo_controller($this->memo_db)] as $controller) {
            $this->response($controller->change(1));
            $before = $this->billing();
            $postings = $this->posting_counts();
            $this->response($controller->change(1), 400);
            $this->response($controller->change(1, 'disapproved'), 400);
            $this->assertSame($before, $this->billing());
            $this->assertSame($postings, $this->posting_counts());
        }
    }

    public function testInvalidStatusTypesCannotApproveWithoutUpdatingBilling()
    {
        $before = $this->billing();
        foreach ([new Credit_memo_controller($this->memo_db), new Debit_memo_controller($this->memo_db)] as $controller) {
            foreach ([true, false, 0, ['approved'], null] as $status) {
                $this->response($controller->change(1, $status), 400);
            }
        }
        $this->assertSame($before, $this->billing());
        $this->assertSame([0, 0, 0], $this->posting_counts());
    }

    public function testNegativeMemoAmountsCannotReduceAccumulatedTotals()
    {
        Billing_memo_fixture::$pdo->exec('UPDATE credit_memo SET amount=-10 WHERE id=1; UPDATE debit_memo SET amount=-10 WHERE id=1');
        $before = $this->billing();
        $this->response((new Credit_memo_controller($this->memo_db))->change(1), 400);
        $this->response((new Debit_memo_controller($this->memo_db))->change(1), 400);
        $this->assertSame($before, $this->billing());
        $this->assertSame([0, 0, 0], $this->posting_counts());
    }

    public function testMissingAndDeletedLinksCannotApprove()
    {
        $credit = new Credit_memo_controller($this->memo_db);
        $this->response($credit->change(999), 404);
        Billing_memo_fixture::$pdo->exec('UPDATE credit_memo SET is_deleted=1 WHERE id=1');
        $this->response($credit->change(1), 404);
        Billing_memo_fixture::$pdo->exec('UPDATE credit_memo SET is_deleted=0,billing_id=999 WHERE id=1');
        $this->response($credit->change(1), 400);
        Billing_memo_fixture::$pdo->exec('UPDATE credit_memo SET billing_id=1 WHERE id=1; UPDATE billing SET is_deleted=1 WHERE id=1');
        $this->response($credit->change(1), 400);
        $this->assertSame('pending', Billing_memo_fixture::$pdo->query('SELECT status FROM credit_memo WHERE id=1')->fetchColumn());
        $this->assertSame([0, 0, 0], $this->posting_counts());
        $this->assertSame('0.00', $this->billing()['credit_memo_total']);
    }

    public function testGuardedModelTransitionRejectsAnAlreadyApprovedRow()
    {
        $memo = new Credit_memo($this->memo_db);
        $this->memo_db->transBegin();
        $this->assertTrue($memo->update_pending_status(1, ['status' => 'approved']));
        $this->assertFalse($memo->update_pending_status(1, ['status' => 'approved']));
        $this->memo_db->transRollback();
        $this->assertSame([0, 0, 0], $this->posting_counts());
    }

    /** @dataProvider failing_updates */
    public function testBillingFailuresRollbackMemoAndPostingSideEffects($failure)
    {
        if ($failure === 'debug_query') {
            $this->memo_db = Billing_memo_fixture::connection(true);
        }
        $billing = new class($this->memo_db) extends Billing {
            public $failure;
            public function apply_memo($id, $amount, $type, $requester): bool
            {
                if ($this->failure === 'exception') {
                    throw new RuntimeException('Synthetic write failure');
                }
                return $this->db->query('UPDATE billing SET missing_fixture_column=1 WHERE id=?', [$id]) !== false;
            }
        };
        $billing->failure = $failure;
        $before = $this->billing();
        $this->response((new Credit_memo_controller($this->memo_db, null, $billing))->change(1), 400);
        $this->assertSame($before, $this->billing());
        $this->assertSame('pending', Billing_memo_fixture::$pdo->query('SELECT status FROM credit_memo WHERE id=1')->fetchColumn());
        $this->assertSame([0, 0, 0], $this->posting_counts());
    }

    public function failing_updates()
    {
        return [['query'], ['debug_query'], ['exception']];
    }

    /** @dataProvider commit_failures */
    public function testCommitFailureRollsBackBillingMemoAndPostings($failure)
    {
        $transaction_db = new class($this->memo_db, $failure) {
            private $db;
            private $failure;
            public function __construct($db, $failure) { $this->db = $db; $this->failure = $failure; }
            public function transBegin() { return $this->db->transBegin(); }
            public function transStatus() { return $this->failure !== 'status' && $this->db->transStatus(); }
            public function transCommit()
            {
                if ($this->failure === 'exception') {
                    throw new RuntimeException('Synthetic commit failure');
                }
                return false;
            }
            public function transRollback() { return $this->db->transRollback(); }
        };
        $before = $this->billing();
        $this->response((new Debit_memo_controller($this->memo_db, null, null, $transaction_db))->change(1), 400);
        $this->assertSame($before, $this->billing());
        $this->assertSame('pending', Billing_memo_fixture::$pdo->query('SELECT status FROM debit_memo WHERE id=1')->fetchColumn());
        $this->assertSame([0, 0, 0], $this->posting_counts());
    }

    public function commit_failures()
    {
        return [['false'], ['exception'], ['status']];
    }

    public function testDecimalOverflowRollsBackTheEntireApproval()
    {
        Billing_memo_fixture::$pdo->exec('UPDATE billing SET credit_memo_total=99999999999.99 WHERE id=1');
        $before = $this->billing();
        $this->response((new Credit_memo_controller($this->memo_db))->change(1), 400);
        $this->assertSame($before, $this->billing());
        $this->assertSame('pending', Billing_memo_fixture::$pdo->query('SELECT status FROM credit_memo WHERE id=1')->fetchColumn());
        $this->assertSame([0, 0, 0], $this->posting_counts());
    }

    public function testBillingGetBatchesApprovedMemosWithoutChangingHistory()
    {
        Billing_memo_fixture::memo('credit_memo', ['id' => 2, 'status' => 'approved', 'approved_by' => 7, 'branch_id' => 4]);
        Billing_memo_fixture::memo('credit_memo', ['id' => 3, 'status' => 'approved', 'approved_by' => 7, 'branch_id' => 2]);
        Billing_memo_fixture::memo('credit_memo', ['id' => 4, 'status' => 'approved', 'is_deleted' => 1]);
        Billing_memo_fixture::memo('credit_memo', ['id' => 5, 'status' => 'disapproved']);
        Billing_memo_fixture::memo('debit_memo', ['id' => 2, 'billing_id' => 2, 'status' => 'approved']);
        $before = $this->billing();
        $controller = new Billing_memo_controller($this->memo_db);
        $data = $this->response($controller->billings(['status' => 'approved', 'page' => 1, 'data_size' => 1]));
        $this->assertSame(2, $data['size']);
        $this->assertCount(1, $data['data']);
        $row = $this->response($controller->billings(['billing_id' => 1]))['data'][0];
        $this->assertSame([3, 2], array_map('intval', array_column($row['credit_memo'], 'id')));
        $this->assertSame([], $row['debit_memo']);
        $this->assertSame('Fixture Oakridge', $row['credit_memo'][0]['branch_name']);
        $this->assertSame('Fixture Consolacion', $row['credit_memo'][1]['branch_name']);
        $this->assertSame('INV-1', $row['credit_memo'][0]['invoice_no']);
        $this->assertSame('Fixture Q Approver', $row['credit_memo'][0]['approved_by_name']);
        $this->assertSame('Fixture debit', $row['credit_memo'][0]['debit_account_name']);
        $this->assertSame('0.00', $row['credit_memo_total']);
        $this->assertSame('200.00', $row['paid_amount']);
        $second = $this->response($controller->billings(['billing_id' => 2]))['data'][0];
        $this->assertSame([], $second['credit_memo']);
        $this->assertSame([2], array_map('intval', array_column($second['debit_memo'], 'id')));
        $this->assertSame($before, $this->billing());
        $this->response($controller->billings(['billing_id' => 999]), 404);
    }

    public function testMemoArraysAreNotLimitedToTheBillingPageSize()
    {
        for ($id = 2; $id <= 122; $id++) {
            Billing_memo_fixture::memo('credit_memo', ['id' => $id, 'status' => 'approved']);
        }
        $row = $this->response((new Billing_memo_controller($this->memo_db))->billings(['billing_id' => 1, 'page' => 1, 'data_size' => 1]))['data'][0];
        $this->assertCount(121, $row['credit_memo']);
        $this->assertSame('122', (string) $row['credit_memo'][0]['id']);
    }

    public function testCollectionsUseMemoAdjustedStartingBalance()
    {
        $this->response((new Credit_memo_controller($this->memo_db))->change(1));
        $this->response((new Debit_memo_controller($this->memo_db))->change(1));
        $payments = [
            ['id' => 2, 'billing_id' => 1, 'paid_date' => '2026-10-15', 'grand_total' => 80, 'wht_amount' => 20],
            ['id' => 1, 'billing_id' => 1, 'paid_date' => '2026-10-14', 'grand_total' => 90, 'wht_amount' => 10],
        ];
        $row = $this->response((new Billing_memo_controller($this->memo_db))->billings(['billing_id' => 1, 'screen' => 'collections'], $payments))['data'][0];
        $this->assertSame([750.5, 850.5], array_column($row['collections'], 'running_balance'));
        $this->assertSame('200.00', $row['paid_amount']);
        $this->assertSame('750.50', $row['balance']);
        $this->assertSame([2, 1], array_column($row['collections'], 'id'));
    }

    public function testOrdinaryBillingPayloadCannotSetMemoTotals()
    {
        $id = (new Billing_memo_controller($this->memo_db))->create_billing([
            'grand_total' => 12, 'credit_memo_total' => 999, 'debit_memo_total' => 888,
        ]);
        $this->assertNotFalse($id);
        $row = $this->billing($id);
        $this->assertSame('0.00', $row['credit_memo_total']);
        $this->assertSame('0.00', $row['debit_memo_total']);
        $this->assertSame('12.00', $row['balance']);
    }

    public function testBillingGetPreservesEmptyArraysAndBoundFilters()
    {
        $controller = new Billing_memo_controller($this->memo_db);
        $row = $this->response($controller->billings(['billing_id' => 1, 'term' => 'INV-1', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']))['data'][0];
        $this->assertSame([], $row['credit_memo']);
        $this->assertSame([], $row['debit_memo']);
        $this->assertSame('1000.00', $row['grand_total']);
        $this->response($controller->billings(['term' => "' OR 1=1 --"]), 404);
        $memo = new Credit_memo($this->memo_db);
        $this->assertSame([], $memo->get_details_for_billing_ids([]));
        $this->assertSame([], $memo->get_details_for_billing_ids([-1, 0]));
        $this->assertSame([], $memo->get_details(null, null, null, 'approved', null, null, null, null, []));
    }

    /** @dataProvider concurrency_cases */
    public function testConcurrentApprovalsApplyEachMemoOnce($requests, $expected_statuses, $balance, $credit_total, $debit_total)
    {
        Billing_memo_fixture::memo('credit_memo', ['id' => 2, 'amount' => '25.25']);
        $this->memo_db->transBegin();
        (new Billing($this->memo_db))->get_for_update(1);
        $workers = [];
        try {
            foreach ($requests as [$type, $id]) {
                $command = [PHP_BINARY, ROOTPATH.'tests/_support/Database/billing_memo_worker.php', Billing_memo_fixture::$schema, $type, (string) $id];
                $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $waiting = 0;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $query = Billing_memo_fixture::$pdo->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=? AND COMMAND='Query' AND INFO LIKE '%FOR UPDATE%'");
                $query->execute([Billing_memo_fixture::$schema]);
                $waiting = (int) $query->fetchColumn();
                if ($waiting >= 2) {
                    break;
                }
                usleep(50000);
            }
        } finally {
            $this->memo_db->transCommit();
        }
        $statuses = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $statuses[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status'];
        }
        $this->assertGreaterThanOrEqual(2, $waiting, 'Both approval requests must overlap while waiting on fixture locks. Statuses: '.json_encode($statuses));
        sort($statuses);
        $this->assertSame($expected_statuses, $statuses);
        $this->assertSame($balance, $this->billing()['balance']);
        $this->assertSame($credit_total, $this->billing()['credit_memo_total']);
        $this->assertSame($debit_total, $this->billing()['debit_memo_total']);
        $approved = count(array_filter($statuses, static function ($status) { return $status === 200; }));
        $this->assertSame([$approved * 2, $approved, $approved * 2], $this->posting_counts());
    }

    public function concurrency_cases()
    {
        return [
            'same memo' => [[['credit', 1], ['credit', 1]], [200, 400], '699.75', '100.25', '0.00'],
            'credit and debit' => [[['credit', 1], ['debit', 1]], [200, 200], '750.50', '100.25', '50.75'],
            'two credits' => [[['credit', 1], ['credit', 2]], [200, 200], '674.50', '125.50', '0.00'],
        ];
    }
}
