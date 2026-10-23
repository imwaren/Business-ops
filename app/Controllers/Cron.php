<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Report;
use App\Models\Report_clinic;
use App\Controllers\Traits\Inventory_snapshot;

class Cron extends MYT_Controller
{
    use Inventory_snapshot;
    protected $request;
    protected $giftcheck;
    protected $promo;
    protected $voucher;
    protected $report;
    protected $report_clinic;
    protected $direct_payment;
    protected $journal_entry;
    protected $journal_entry_item;
    protected $branch_db;

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        $this->public_page = true;
        $this->cron_page   = true;
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        $this->giftcheck = new \App\Models\Giftcheck();
        $this->promo = new \App\Models\Promo();
        $this->voucher = new \App\Models\Voucher();
        $this->report = new \App\Models\Report();
        $this->report_clinic = new Report_clinic();
        $this->db = \Config\Database::connect();
    }
    
    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $dbMap = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->giftcheck = new \App\Models\Giftcheck();
        $this->promo = new \App\Models\Promo();
        $this->voucher = new \App\Models\Voucher();

        return true;
    }

    /**
     * Update validity date range status
     */
    public function update_validity_date_range_status()
    {
        $branches = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];

        foreach ($branches as $branch_id => $branch_name) {

            if (!$this->resolve_branch_models($branch_id)) {
                log_message('error', "Invalid branch: {$branch_name}");
                continue;
            }

            // Giftcheck
            if (!$this->giftcheck->update_validity_date_range_status()) {
                log_message('error', "Giftcheck failed on {$branch_name}");
                continue;
            }

            // Promo
            if (!$this->promo->update_validity_date_range_status()) {
                log_message('error', "Promo failed on {$branch_name}");
                continue;
            }

            // Voucher
            if (!$this->voucher->update_validity_date_range_status()) {
                log_message('error', "Voucher failed on {$branch_name}");
                continue;
            }
        }

        log_message('info', 'Cron Success : ' . date('Y-m-d H:i:s'));
        echo 'Cron executed successfully.';
    }

    /**
     * Post due prepaid amortization journal entries for paid direct payments.
     * Schedule daily or monthly: GET /cron/post_direct_payment_journal_entries
     */
    public function post_direct_payment_journal_entries()
    {
        $asOf = date('Y-m-d');
        $router = new \App\Libraries\Fund_cost_center();
        $branches = $router->groups();

        $posted = 0;
        $skipped = 0;
        $failed = 0;

        foreach (\App\Libraries\Fund_cost_center::storage_groups($branches) as $branch_name => $center_ids) {
            $branch_id = $center_ids[0];
            if (!$this->_resolve_prepaid_amortization_models($branch_id)) {
                log_message('error', "Direct payment amortization skipped invalid branch: {$branch_name}");
                continue;
            }

            if ($branch_name === 'master') { $this->direct_payment->with_shared_cost_centers($center_ids); }
            $direct_payments = $this->direct_payment->get_prepaid_for_amortization() ?: [];
            foreach ($direct_payments as $direct_payment) {
                $result = $this->_post_direct_payment_due_periods($direct_payment, $asOf, $branch_name === 'master' ? 0 : $branch_id);
                $posted += $result['posted'];
                $skipped += $result['skipped'];
                $failed += $result['failed'];
            }
        }

        $message = "Direct payment amortization cron posted {$posted} journal entries, skipped {$skipped}, failed {$failed}.";
        log_message('info', $message);
        echo $message;
    }

    /**
     * Load clinic models for prepaid amortization posting.
     */
    protected function _resolve_prepaid_amortization_models(int $branch_id)
    {
        $dbMap = (new \App\Libraries\Fund_cost_center())->groups();

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $this->branch_db = db_connect($dbMap[$branch_id]);
        $this->direct_payment = new \App\Models\Direct_payment($this->branch_db);
        $this->journal_entry = new \App\Models\Journal_entry($this->branch_db);
        $this->journal_entry_item = new \App\Models\Journal_entry_item($this->branch_db);

        return true;
    }

    /**
     * Insert due (date <= today) amortization journals that are not already logged.
     */
    protected function _post_direct_payment_due_periods(array $direct_payment, $asOf, $branch_id)
    {
        $result = [
            'posted' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $dates = $this->direct_payment->monthly_dates($direct_payment['duration_from'], $direct_payment['duration_to']);
        $n = count($dates);
        if ($n < 1) {
            return $result;
        }

        $debit_entries = $credit_entries = [];
        $allocation_total = 0;
        foreach ($direct_payment['amortization_entries'] ?? [] as $allocation) {
            $units = \App\Libraries\Apv_accounting::units($allocation['amount']);
            if ($units <= 0) { return $result; }
            $allocation_total += $units;
            $debit_entries[] = ['account_type_id' => $allocation['expense_account_type_id'],
                'debit' => $allocation['amount'], 'remarks' => null];
            $credit_entries[] = ['account_type_id' => $allocation['prepaid_account_type_id'],
                'credit' => $allocation['amount'], 'remarks' => null];
        }
        if (!$debit_entries || $allocation_total !== \App\Libraries\Apv_accounting::units($direct_payment['amount'])) {
            log_message('error', 'Invalid amortization allocations for direct payment #' . $direct_payment['id']);
            $result['failed']++;
            return $result;
        }

        $allocation_periods = [];
        foreach ($direct_payment['amortization_entries'] as $allocation) {
            $allocation_periods[] = $this->direct_payment->split_amount($allocation['amount'], $n);
        }

        foreach ($dates as $i => $period_date) {
            if ($period_date > $asOf) {
                continue;
            }

            if ($this->journal_entry_item->has_posted_period($direct_payment['id'], $period_date)) {
                $result['skipped']++;
                continue;
            }

            $period_debits = $debit_entries;
            $period_credits = $credit_entries;
            $period_units = 0;
            foreach ($allocation_periods as $index => $amounts) {
                $period_debits[$index]['debit'] = $amounts[$i];
                $period_credits[$index]['credit'] = $amounts[$i];
                $period_units += \App\Libraries\Apv_accounting::units($amounts[$i]);
            }
            if ($period_units === 0) { $result['skipped']++; continue; }
            $this->branch_db->transBegin();
            // Serialize workers on the source payment before the second period check.
            $this->branch_db->query('SELECT id FROM direct_payment WHERE id = ? FOR UPDATE', [$direct_payment['id']]);
            if ($this->journal_entry_item->has_posted_period($direct_payment['id'], $period_date)) {
                $this->branch_db->transRollback();
                $result['skipped']++;
                continue;
            }
            if (!$this->_insert_amortization_journal(
                $direct_payment,
                $period_date,
                $period_debits,
                $period_credits,
                \App\Libraries\Apv_accounting::decimal($period_units),
                $branch_id
            )) {
                $this->branch_db->transRollback();
                log_message('error', "Failed amortization journal for direct_payment {$direct_payment['id']} on {$period_date}");
                $result['failed']++;
                continue;
            }
            $this->branch_db->transCommit();
            $result['posted']++;
        }

        return $result;
    }

    /**
     * Insert a balanced pending journal then approve so the GL trigger posts once.
     */
    protected function _insert_amortization_journal(
        array $direct_payment,
        $period_date,
        array $debit_entries,
        array $credit_entries,
        $period_amount,
        $branch_id
    )
    {
        $now = date('Y-m-d H:i:s');
        $cost_center_id = (int) $branch_id === 0 ? 0 : ($direct_payment['cost_center_id'] ?: $branch_id);
        $remarks = 'Direct payment #' . $direct_payment['id'] . ' prepaid amortization ' . $period_date;
        $debit_period_amounts = $this->_allocate_direct_payment_period($debit_entries, 'debit', $period_amount);
        $credit_period_amounts = $this->_allocate_direct_payment_period($credit_entries, 'credit', $period_amount);
        if ($debit_period_amounts === false || $credit_period_amounts === false || (float) $period_amount <= 0) {
            return false;
        }
        $debit_amount = (float) $period_amount;
        $credit_amount = (float) $period_amount;

        $journal_entry_id = $this->journal_entry->insert([
            'cost_center_id' => $cost_center_id,
            'date' => $period_date,
            'remarks' => $remarks,
            'total_debit' => $debit_amount,
            'total_credit' => $credit_amount,
            'added_on' => $now,
        ]);
        if (!$journal_entry_id) {
            return false;
        }

        foreach ($debit_entries as $index => $entry) {
            $amount = (float) ($debit_period_amounts[$index] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            if (!$this->journal_entry_item->insert([
                'journal_entry_id' => $journal_entry_id,
                'account_type_id' => $entry['account_type_id'],
                'cost_center_id' => $cost_center_id,
                'debit' => $amount,
                'credit' => 0,
                'remarks' => $entry['remarks'] ?: $remarks,
                'direct_payment_id' => $direct_payment['id'],
                'added_on' => $now,
            ])) {
                return false;
            }
        }
        foreach ($credit_entries as $index => $entry) {
            $amount = (float) ($credit_period_amounts[$index] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            if (!$this->journal_entry_item->insert([
                'journal_entry_id' => $journal_entry_id,
                'account_type_id' => $entry['account_type_id'],
                'cost_center_id' => $cost_center_id,
                'debit' => 0,
                'credit' => $amount,
                'remarks' => $entry['remarks'] ?: $remarks,
                'direct_payment_id' => $direct_payment['id'],
                'added_on' => $now,
            ])) {
                return false;
            }
        }

        $updated = $this->journal_entry->update(
            ['id' => $journal_entry_id],
            [
                'status' => 'approved',
                'total_debit' => $debit_amount,
                'total_credit' => $credit_amount,
            ]
        );
        if (!$updated || (int) $branch_id !== 0) { return $updated; }
        // Master has no generic JE->GL hook. Post this completed amortization only.
        return (bool) $this->branch_db->query(
            "INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,remark,added_by,added_on,is_deleted,is_migrated)
             SELECT 0,'journal_entry',j.id,i.account_type_id,j.date,i.debit,i.credit,i.remarks,COALESCE(j.added_by,0),NOW(),0,0
             FROM journal_entry j JOIN journal_entry_item i ON i.journal_entry_id=j.id AND i.is_deleted=0
             WHERE j.id=? AND j.is_deleted=0 AND j.status='approved'", [$journal_entry_id]);

    }

    /** Period allocations are already exact, paired four-decimal amounts. */
    private function _allocate_direct_payment_period(array $entries, string $side, $period_amount)
    {
        $amounts = array_map(static fn($entry) => \App\Libraries\Apv_accounting::units($entry[$side]), $entries);
        if (array_sum($amounts) !== \App\Libraries\Apv_accounting::units($period_amount)) {
            return false;
        }
        return array_map([\App\Libraries\Apv_accounting::class, 'decimal'], $amounts);
    }

    /**
     * Safety-net medrecord rebuild (do not run every minute).
     * Preferred schedule: hourly (`0 * * * *`) or nightly (`0 23 * * *`).
     * Live pages refresh via reports/get_medrecord (background, today/yesterday only).
     *
     * Optional query/body: transaction_date=YYYY-MM-DD (or date), branch_id=1|2|3|4|7.
     * Explicit transaction_date rebuilds that day without the today/yesterday clamp.
     */
    public function get_medrecord()
    {
        $txnDate = $this->payload['transaction_date']
            ?? $this->payload['date']
            ?? $this->request->getGet('transaction_date')
            ?? $this->request->getGet('date')
            ?? null;
        $branchId = $this->payload['branch_id']
            ?? $this->request->getGet('branch_id')
            ?? null;
        $branchId = ($branchId !== null && $branchId !== '') ? (int) $branchId : null;

        $result = $this->report->refresh_medrecord(
            $branchId,
            $txnDate,
            $txnDate,
            false,
            $txnDate !== null
        );

        foreach ($result['ran'] as $ran) {
            log_message('info', "Medrecord report success on branch {$ran['branch_id']} date {$ran['date']}");
        }
        foreach ($result['skipped'] as $skipped) {
            $level = $skipped['status'] === 'failed' ? 'error' : 'info';
            log_message($level, "Medrecord report {$skipped['status']} on branch {$skipped['branch_id']} date {$skipped['date']}");
        }
    }

    /**
     * Get Daily Branch Sales Report (every 11pm)
     */
    public function get_daily_sales()
    {
        // Daily branch sales report mobile
        if (!$this->report->daily_branch_sales_report_mobile()) {
            log_message('error', "Daily branch sales report failed on mobile");
        } else {
            log_message('info', "Daily branch sales report success on mobile");
        }

        // Daily branch sales report oakridge
        if (!$this->report->daily_branch_sales_report_oakridge()) {
            log_message('error', "Daily branch sales report failed on oakridge");
        } else {
            log_message('info', "Daily branch sales report success on oakridge");
        }

        // Daily branch sales report mactan
        if (!$this->report->daily_branch_sales_report_mactan()) {
            log_message('error', "Daily branch sales report failed on mactan");
        } else {
            log_message('info', "Daily branch sales report success on mactan");
        }

        // Daily branch sales report consolacion
        if (!$this->report->daily_branch_sales_report_consolacion()) {
            log_message('error', "Daily branch sales report failed on consolacion");
        } else {
            log_message('info', "Daily branch sales report success on consolacion");
        }

        // Daily branch sales report mantlewood
        if (!$this->report->daily_branch_sales_report_mantlewood()) {
            log_message('error', "Daily branch sales report failed on mantlewood");
        } else {
            log_message('info', "Daily branch sales report success on mantlewood");
        }
    }

    /**
     * Get Patient TAT Report (every 11pm)
     */
    public function get_patient_tat()
    {
        // Patient TAT report mobile
        if (!$this->report->patient_tat_report_mobile()) {
            log_message('error', "Patient TAT report failed on mobile");
        } else {
            log_message('info', "Patient TAT report success on mobile");
        }

        // Patient TAT report oakridge
        if (!$this->report->patient_tat_report_oakridge()) {
            log_message('error', "Patient TAT report failed on oakridge");
        } else {
            log_message('info', "Patient TAT report success on oakridge");
        }

        // Patient TAT report mactan
        if (!$this->report->patient_tat_report_mactan()) {
            log_message('error', "Patient TAT report failed on mactan");
        } else {
            log_message('info', "Patient TAT report success on mactan");
        }

        // Patient TAT report consolacion
        if (!$this->report->patient_tat_report_consolacion()) {
            log_message('error', "Patient TAT report failed on consolacion");
        } else {
            log_message('info', "Patient TAT report success on consolacion");
        }

        // Patient TAT report mantlewood
        if (!$this->report->patient_tat_report_mantlewood()) {
            log_message('error', "Patient TAT report failed on mantlewood");
        } else {
            log_message('info', "Patient TAT report success on mantlewood");
        }
    }

    /**
     * Get PCP Clinic TAT Report (every 11pm)
     */
    public function get_pcp_clinic_tat()
    {
        // PCP Clinic TAT report mobile
        if (!$this->report->pcp_clinic_tat_report_mobile()) {
            log_message('error', "PCP Clinic TAT report failed on mobile");
        } else {
            log_message('info', "PCP Clinic TAT report success on mobile");
        }

        // PCP Clinic TAT report oakridge
        if (!$this->report->pcp_clinic_tat_report_oakridge()) {
            log_message('error', "PCP Clinic TAT report failed on oakridge");
        } else {
            log_message('info', "PCP Clinic TAT report success on oakridge");
        }

        // PCP Clinic TAT report mactan
        if (!$this->report->pcp_clinic_tat_report_mactan()) {
            log_message('error', "PCP Clinic TAT report failed on mactan");
        } else {
            log_message('info', "PCP Clinic TAT report success on mactan");
        }

        // PCP Clinic TAT report consolacion
        if (!$this->report->pcp_clinic_tat_report_consolacion()) {
            log_message('error', "PCP Clinic TAT report failed on consolacion");
        } else {
            log_message('info', "PCP Clinic TAT report success on consolacion");
        }

        // PCP Clinic TAT report mantlewood
        if (!$this->report->pcp_clinic_tat_report_mantlewood()) {
            log_message('error', "PCP Clinic TAT report failed on mantlewood");
        } else {
            log_message('info', "PCP Clinic TAT report success on mantlewood");
        }
    }

    /**
     * Get Specialist MD TAT Report (every 11pm)
     */
    public function get_specialist_md_tat()
    {
        // Specialist MD TAT report mobile
        if (!$this->report->specialist_md_tat_report_mobile()) {
            log_message('error', "Specialist MD TAT report failed on mobile");
        } else {
            log_message('info', "Specialist MD TAT report success on mobile");
        }

        // Specialist MD TAT report oakridge
        if (!$this->report->specialist_md_tat_report_oakridge()) {
            log_message('error', "Specialist MD TAT report failed on oakridge");
        } else {
            log_message('info', "Specialist MD TAT report success on oakridge");
        }

        // Specialist MD TAT report mactan
        if (!$this->report->specialist_md_tat_report_mactan()) {
            log_message('error', "Specialist MD TAT report failed on mactan");
        } else {
            log_message('info', "Specialist MD TAT report success on mactan");
        }

        // Specialist MD TAT report consolacion
        if (!$this->report->specialist_md_tat_report_consolacion()) {
            log_message('error', "Specialist MD TAT report failed on consolacion");
        } else {
            log_message('info', "Specialist MD TAT report success on consolacion");
        }

        // Specialist MD TAT report mantlewood
        if (!$this->report->specialist_md_tat_report_mantlewood()) {
            log_message('error', "Specialist MD TAT report failed on mantlewood");
        } else {
            log_message('info', "Specialist MD TAT report success on mantlewood");
        }
    }

    /**
     * Get Sales Census (every 1am)
     */
    public function get_sales_census()
    {
        // Sales census mobile
        if (!$this->report->sales_census_report()) {
            log_message('error', "Sales census report failed on mobile");
        } else {
            log_message('info', "Sales census report success on mobile");
        }
    }

    /**
     * Get Inter Branch Census (every 1am)
     */
    public function get_inter_branch_census()
    {
        if (!$this->report->inter_branch_census_report()) {
            log_message('error', "Inter branch census report failed");
        } else {
            log_message('info', "Inter branch census report success");
        }
    }

    /**
     * Get Service Census (every 1am)
     */
    public function get_service_census()
    {
        // Service census mobile
        if (!$this->report->service_census_report()) {
            log_message('error', "Service census report failed on mobile");
        } else {
            log_message('info', "Service census report success on mobile");
        }
    }

    /**
     * Get Daily Census (every 11am)
     */
    public function get_daily_census()
    {
        // Daily census mobile
        if (!$this->report->daily_census_mobile()) {
            log_message('error', "Daily census report failed on mobile");
        } else {
            log_message('info', "Daily census report success on mobile");
        }

        // Daily census oakridge
        if (!$this->report->daily_census_oakridge()) {
            log_message('error', "Daily census report failed on oakridge");
        } else {
            log_message('info', "Daily census report success on oakridge");
        }
        
        // Daily census mactan
        if (!$this->report->daily_census_mactan()) {
            log_message('error', "Daily census report failed on mactan");
        } else {
            log_message('info', "Daily census report success on mactan");
        }
        
        // Daily census consolacion
        if (!$this->report->daily_census_consolacion()) {
            log_message('error', "Daily census report failed on consolacion");
        } else {
            log_message('info', "Daily census report success on consolacion");
        }

        // Daily census mantlewood
        if (!$this->report->daily_census_mantlewood()) {
            log_message('error', "Daily census report failed on mantlewood");
        } else {
            log_message('info', "Daily census report success on mantlewood");
        }
    }

    /**
     * Refresh the consolidated UIP read model.
     *
     * Schedule hourly without parameters for a rolling three-day refresh.
     * Supply date_from/date_to for the one-time historical backfill.
     */
    public function refresh_uip()
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $dateFrom = $this->request->getVar('date_from') ?: date('Y-m-d', strtotime('-3 days'));
        $dateTo   = $this->request->getVar('date_to') ?: date('Y-m-d');

        foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $name => $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 400,
                    'message' => "{$name} must use YYYY-MM-DD format.",
                ]);
            }
        }

        if ($dateFrom > $dateTo) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 400,
                'message' => 'date_from must not be after date_to.',
            ]);
        }
        if ((new \DateTimeImmutable($dateFrom))->diff(new \DateTimeImmutable($dateTo))->days > 31) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 400,
                'message' => 'HTTP refresh is limited to 31 days. Use php spark uip:refresh for backfills.',
            ]);
        }

        try {
            $result = $this->report_clinic->refresh_uip_read_model($dateFrom, $dateTo);
            log_message('info', 'UIP read model refresh completed: ' . json_encode($result));

            return $this->response->setJSON([
                'status'  => 200,
                'message' => 'UIP read model refreshed successfully.',
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'UIP read model refresh failed: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'status'  => 500,
                'message' => 'UIP read model refresh failed. Check the application log.',
            ]);
        }
    }

    /**
     * Persist daily item inventory snapshots and monthly branch rollups.
     * Optional query/body: snapshot_date=YYYY-MM-DD (default: today).
     */
    public function snapshot_item_inventory()
    {
        $date = $this->payload['snapshot_date']
            ?? $this->request->getGet('snapshot_date')
            ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 400,
                'message' => 'Invalid snapshot_date. Use YYYY-MM-DD.',
            ]);
        }

        try {
            $counts = $this->_snapshot_item_inventory_for_date($date, true);
            log_message('info', 'Item inventory snapshot completed: ' . json_encode($counts));

            return $this->response->setJSON([
                'status'        => 200,
                'message'       => 'Item inventory snapshot saved.',
                'snapshot_date' => $date,
                'data'          => $counts,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Item inventory snapshot failed: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'status'  => 500,
                'message' => 'Item inventory snapshot failed. Check the application log.',
            ]);
        }
    }

    /**
     * Fallback for spark bir:generate. Query: bir_return_file_id=
     */
    public function generate_bir_return()
    {
        $id = (int) ($this->payload['bir_return_file_id']
            ?? $this->request->getGet('bir_return_file_id')
            ?? 0);

        if ($id <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 400,
                'message' => 'bir_return_file_id is required.',
            ]);
        }

        log_message('info', 'BIR return generation running via cron for id ' . $id);

        $ok = (new \App\Libraries\Bir_return_generator())->generate($id);
        if ($ok) {
            return $this->response->setJSON([
                'status'  => 200,
                'message' => 'BIR return generated.',
                'bir_return_file_id' => $id,
            ]);
        }

        return $this->response->setStatusCode(500)->setJSON([
            'status'  => 500,
            'message' => 'BIR return generation failed. Check the application log.',
            'bir_return_file_id' => $id,
        ]);
    }
}
