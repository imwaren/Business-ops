<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch_master;
use App\Models\Doctor;
use App\Models\Request;
use App\Models\Endorsement;
use App\Models\Yakap_billing;
use App\Libraries\Local_upload_file;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Financials extends MYT_Controller
{
    protected $request;
    protected $accounts_payable_voucher;
    protected $accounts_payable_voucher_payment;
    protected $accounts_payable_voucher_payment_detail;
    protected $receipt_note;
    protected $receipt_note_item;
    protected $petty_cash;
    protected $reimbursement;
    protected $cash_advance;
    protected $liquidation;
    protected $change_fund;
    protected $general_ledger;
    protected $account_type;
    protected $user;
    protected $billing;
    protected $billing_entry;
    protected $billing_payment;
    protected $billing_payment_entry;
    protected $company;
    protected $branch_master;
    protected $doctor;
    protected $booking_doctors_fee;
    protected $booking;
    protected $booking_all;
    protected $request_model;
    protected $endorsement;
    protected $helpers = ['text'];

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        $this->accounts_payable_voucher = new \App\Models\Accounts_payable_voucher();
        $this->accounts_payable_voucher_payment = new \App\Models\Accounts_payable_voucher_payment();
        $this->accounts_payable_voucher_payment_detail = new \App\Models\Accounts_payable_voucher_payment_detail();
        $this->receipt_note = new \App\Models\Receipt_note();
        $this->receipt_note_item = new \App\Models\Receipt_note_item();
        $this->petty_cash = new \App\Models\Petty_cash();
        $this->reimbursement = new \App\Models\Reimbursement();
        $this->cash_advance = new \App\Models\Cash_advance();
        $this->liquidation = new \App\Models\Liquidation();
        $this->change_fund = new \App\Models\Change_fund();
        $this->general_ledger = new \App\Models\General_ledger();
        $this->account_type = new \App\Models\Account_type();
        $this->billing = new \App\Models\Billing();
        $this->billing_entry = new \App\Models\Billing_entry();
        $this->billing_payment = new \App\Models\Billing_payment();
        $this->billing_payment_entry = new \App\Models\Billing_payment_entry();
        $this->booking_doctors_fee = new \App\Models\Booking_doctors_fee();
        $this->booking = new \App\Models\Booking();
        $this->booking_all = new \App\Models\Booking_all();
        $this->user = new User();
        $this->company = new Company();
        $this->branch_master = new Branch_master();
        $this->doctor = new Doctor();
        $this->request_model = new Request();
        $this->endorsement = new Endorsement();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        if ($branch_id === 0) {
            $this->general_ledger = new \App\Models\General_ledger(db_connect('default'));
            return true;
        }

        $dbMap = [
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

        $this->accounts_payable_voucher = new \App\Models\Accounts_payable_voucher($db);
        $this->accounts_payable_voucher_payment = new \App\Models\Accounts_payable_voucher_payment($db);
        $this->accounts_payable_voucher_payment_detail = new \App\Models\Accounts_payable_voucher_payment_detail($db);
        $this->receipt_note = new \App\Models\Receipt_note($db);
        $this->receipt_note_item = new \App\Models\Receipt_note_item($db);
        $this->petty_cash = new \App\Models\Petty_cash($db);
        $this->reimbursement = new \App\Models\Reimbursement($db);
        $this->cash_advance = new \App\Models\Cash_advance($db);
        $this->liquidation = new \App\Models\Liquidation($db);
        $this->change_fund = new \App\Models\Change_fund($db);
        $this->general_ledger = new \App\Models\General_ledger($db);
        $this->billing = new \App\Models\Billing($db);
        $this->billing_entry = new \App\Models\Billing_entry($db);
        $this->billing_payment = new \App\Models\Billing_payment($db);
        $this->billing_payment_entry = new \App\Models\Billing_payment_entry($db);
        $this->booking_doctors_fee = new \App\Models\Booking_doctors_fee($db);
        $this->booking = new \App\Models\Booking($db);

        return true;
    }

    /**
     * Connect only General_ledger for GL report loops.
     */
    protected function resolve_gl_model(int $branch_id): bool
    {
        if ($branch_id === 0) {
            $this->general_ledger = new \App\Models\General_ledger(db_connect('default'));
            return true;
        }

        $dbMap = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];

        if (! isset($dbMap[$branch_id])) {
            return false;
        }

        $this->general_ledger = new \App\Models\General_ledger(db_connect($dbMap[$branch_id]));

        return true;
    }

    /**
     * Posted general ledger report.
     * tab=per_account_type — monthly + YTD totals per COA leaf.
     * tab=breakdown — paged rows for one account_type_id.
     */
    public function get_general_ledger()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $tab = strtolower(trim((string) ($this->payload['tab'] ?? '')));
        if (! in_array($tab, ['per_account_type', 'breakdown'], true)) {
            return $this->_create_response(FAIL, [
                'tab is required. Valid values are: per_account_type, breakdown.',
            ]);
        }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        if (! $date_from || ! $date_to) {
            return $this->_create_response(FAIL, ['date_from and date_to are required.']);
        }

        $from_ts = strtotime((string) $date_from);
        $to_ts = strtotime((string) $date_to);
        if (! $from_ts || ! $to_ts) {
            return $this->_create_response(FAIL, ['Invalid date_from or date_to.']);
        }

        $date_from = date('Y-m-d', $from_ts);
        $date_to = date('Y-m-d', $to_ts);
        if ($date_from > $date_to) {
            return $this->_create_response(FAIL, ['date_from must be on or before date_to.']);
        }

        $from_month = new \DateTime(date('Y-m-01', $from_ts));
        $to_month = new \DateTime(date('Y-m-01', $to_ts));
        $month_span = ((int) $to_month->format('Y') - (int) $from_month->format('Y')) * 12
            + ((int) $to_month->format('n') - (int) $from_month->format('n'))
            + 1;
        if ($month_span > 36) {
            return $this->_create_response(FAIL, ['date range cannot exceed 36 months.']);
        }

        $db_map = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            0 => 'default',
        ];

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;
            if (! isset($db_map[$branch_id])) {
                return $this->_create_response(FAIL, ['Invalid Branch ID.']);
            }
            $branch_ids = [$branch_id];
        } else {
            $branch_id = null;
            $branch_ids = array_keys($db_map);
        }

        if ($tab === 'breakdown') {
            return $this->_general_ledger_breakdown($date_from, $date_to, $branch_id, $branch_ids);
        }

        return $this->_general_ledger_per_account_type($date_from, $date_to, $branch_id, $branch_ids);
    }

    /**
     * @param list<int> $branch_ids
     */
    private function _general_ledger_per_account_type(
        string $date_from,
        string $date_to,
        $branch_id,
        array $branch_ids
    ) {
        $columns = $this->_gl_period_columns($date_from, $date_to);
        $coa_rows = $this->account_type->get_general_ledger_sheet_accounts() ?: [];
        $trial_rows = $this->account_type->get_trial_balance_accounts() ?: [];

        $leaf_id_by_account_id = [];
        foreach ($trial_rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $parent_id = $row['parent_id'] ?? null;
            $leaf_id_by_account_id[$id] = ($parent_id === null || $parent_id === '')
                ? $id
                : (int) $parent_id;
        }

        $zero = [];
        foreach ($columns as $column) {
            if (($column['type'] ?? '') === 'month') {
                $zero[$column['key'] . '_debit'] = 0.0;
                $zero[$column['key'] . '_credit'] = 0.0;
            }
            $zero[$column['key']] = 0.0;
        }

        $accounts = [];
        foreach ($coa_rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $accounts[$id] = [
                'account_type_id'      => $id,
                'group_code'           => $row['group_code'] ?? null,
                'account_number'       => $row['code'] ?? null,
                'account_description'  => $row['name'] ?? null,
                'category'             => $row['group_name'] ?? null,
                'account_type'         => $this->_gl_sheet_account_type_label($row),
                'nature'               => $row['nature'] ?? null,
                'values'               => $zero,
            ];
        }

        $all_branch = $branch_id === null || count($branch_ids) > 1;
        if ($all_branch) {
            $clinic_ids = [];
            foreach ($branch_ids as $id) {
                $id = (int) $id;
                if ($id === 0 || ! $this->resolve_gl_model($id)) {
                    continue;
                }
                $clinic_ids[] = $id;
                $this->_gl_apply_monthly_totals(
                    $accounts,
                    $leaf_id_by_account_id,
                    $this->general_ledger->get_posted_monthly_totals($date_from, $date_to, true)
                );
            }
            if ($this->resolve_gl_model(0)) {
                $this->_gl_apply_monthly_totals(
                    $accounts,
                    $leaf_id_by_account_id,
                    $this->general_ledger->get_posted_monthly_totals_master_merged(
                        $date_from,
                        $date_to,
                        $clinic_ids
                    )
                );
            }
        } else {
            foreach ($branch_ids as $id) {
                if (! $this->resolve_gl_model((int) $id)) {
                    continue;
                }
                $this->_gl_apply_monthly_totals(
                    $accounts,
                    $leaf_id_by_account_id,
                    $this->general_ledger->get_posted_monthly_totals($date_from, $date_to)
                );
            }
        }

        foreach ($accounts as &$account) {
            foreach ($columns as $column) {
                if ($column['type'] !== 'ytd') {
                    continue;
                }
                $year = (int) $column['year'];
                $ytd = 0.0;
                foreach ($columns as $month_col) {
                    if ($month_col['type'] !== 'month' || (int) $month_col['year'] !== $year) {
                        continue;
                    }
                    $ytd += (float) ($account['values'][$month_col['key']] ?? 0);
                }
                $account['values'][$column['key']] = round($ytd, 4);
            }
            foreach ($columns as $column) {
                $key = $column['key'];
                if (array_key_exists($key, $account['values'])) {
                    $account['values'][$key] = round(abs((float) $account['values'][$key]), 4);
                }
            }
            unset($account['nature']);
        }
        unset($account);

        $list = array_values($accounts);
        $term = $this->_coa_term();
        if ($term !== null) {
            $list = array_values(array_filter($list, function ($row) use ($term) {
                return $this->_coa_matches_term($term, $row['group_code'] ?? '', $row['account_number'] ?? '');
            }));
        }
        usort($list, function ($a, $b) {
            return $this->_coa_group_code_cmp(
                $a['group_code'] ?? '',
                $a['account_number'] ?? '',
                $b['group_code'] ?? '',
                $b['account_number'] ?? ''
            );
        });

        return $this->_create_response(SUCCESS, [
            'tab'       => 'per_account_type',
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'branch_id' => $branch_id,
            'columns'   => $columns,
            'accounts'  => $list,
        ]);
    }

    /**
     * @param list<int> $branch_ids
     */
    private function _general_ledger_breakdown(
        string $date_from,
        string $date_to,
        $branch_id,
        array $branch_ids
    ) {
        $account_type_id = $this->payload['account_type_id'] ?? null;
        if ($account_type_id === null || $account_type_id === '') {
            return $this->_create_response(FAIL, ['account_type_id is required when tab is breakdown.']);
        }

        $family_ids = $this->account_type->get_account_type_family_ids($account_type_id);
        if (! $family_ids) {
            return $this->_create_response(FAIL, ['Account type is not found.']);
        }

        $page = $this->payload['page'] ?? 1;
        $data_size = $this->payload['data_size'] ?? 100;
        $page = max(1, (int) $page);
        $data_size = min(200, max(1, (int) $data_size));

        $coa = $this->account_type->select('', ['id' => $family_ids[0], 'is_deleted' => 0], 1);
        $account = [
            'account_type_id' => (int) $family_ids[0],
            'code'            => $coa->code ?? null,
            'name'            => $coa->name ?? null,
        ];

        $all_branch = $branch_id === null || count($branch_ids) > 1;
        $entries = [];
        $size = 0;
        $total_debit = 0.0;
        $total_credit = 0.0;
        if ($all_branch) {
            $fetch_limit = min(2000, $page * $data_size);
            $clinic_ids = [];
            foreach ($branch_ids as $id) {
                $id = (int) $id;
                if ($id === 0 || ! $this->resolve_gl_model($id)) {
                    continue;
                }
                $clinic_ids[] = $id;
                $period = $this->general_ledger->get_posted_account_period_totals(
                    $date_from,
                    $date_to,
                    $family_ids,
                    true
                );
                $total_debit = round($total_debit + (float) ($period['debit'] ?? 0), 4);
                $total_credit = round($total_credit + (float) ($period['credit'] ?? 0), 4);
                $result = $this->general_ledger->get_posted_account_entries(
                    $date_from,
                    $date_to,
                    $family_ids,
                    null,
                    null,
                    true,
                    $fetch_limit
                );
                $size += (int) ($result['size'] ?? 0);
                foreach ($result['rows'] as $row) {
                    $entries[] = $this->_gl_breakdown_entry($row, $id, $account);
                }
            }
            if ($this->resolve_gl_model(0)) {
                $period = $this->general_ledger->get_posted_account_period_totals_master_merged(
                    $date_from,
                    $date_to,
                    $family_ids,
                    $clinic_ids
                );
                $total_debit = round($total_debit + (float) ($period['debit'] ?? 0), 4);
                $total_credit = round($total_credit + (float) ($period['credit'] ?? 0), 4);
                $result = $this->general_ledger->get_posted_account_entries_master_merged(
                    $date_from,
                    $date_to,
                    $family_ids,
                    $clinic_ids,
                    $fetch_limit
                );
                $size += (int) ($result['size'] ?? 0);
                foreach ($result['rows'] as $row) {
                    $entries[] = $this->_gl_breakdown_entry($row, 0, $account);
                }
            }
            usort($entries, static function ($a, $b) {
                $date_cmp = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
                if ($date_cmp !== 0) {
                    return $date_cmp;
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });
            $entries = $this->general_ledger->with_running_balance($entries);
            $entries = array_slice($entries, ($page - 1) * $data_size, $data_size);
        } else {
            foreach ($branch_ids as $id) {
                if (! $this->resolve_gl_model((int) $id)) {
                    continue;
                }
                $period = $this->general_ledger->get_posted_account_period_totals(
                    $date_from,
                    $date_to,
                    $family_ids
                );
                $total_debit = round($total_debit + (float) ($period['debit'] ?? 0), 4);
                $total_credit = round($total_credit + (float) ($period['credit'] ?? 0), 4);
                $result = $this->general_ledger->get_posted_account_entries(
                    $date_from,
                    $date_to,
                    $family_ids,
                    $page,
                    $data_size
                );
                $size += (int) ($result['size'] ?? 0);
                foreach ($result['rows'] as $row) {
                    $entries[] = $this->_gl_breakdown_entry($row, (int) $id, $account);
                }
            }
            $offset = ($page - 1) * $data_size;
            $start = $this->general_ledger->get_posted_account_running_before(
                $date_from,
                $date_to,
                $family_ids,
                $offset
            );
            $entries = $this->general_ledger->with_running_balance($entries, $start);
        }

        return $this->_create_response(SUCCESS, [
            'tab'             => 'breakdown',
            'date_from'       => $date_from,
            'date_to'         => $date_to,
            'branch_id'       => $branch_id,
            'account_type_id' => (int) $account_type_id,
            'account'         => $account,
            'page'            => $page,
            'data_size'       => $data_size,
            'size'            => $size,
            'total_debit'     => $total_debit,
            'total_credit'    => $total_credit,
            'entries'         => $entries,
        ]);
    }

    /**
     * Month columns for the range, with a YTD column after each calendar year (and after the last month).
     *
     * @return list<array{key:string,label:string,type:string,year:int,month?:int}>
     */
    private function _gl_period_columns(string $date_from, string $date_to): array
    {
        $columns = [];
        $cursor = new \DateTime(date('Y-m-01', strtotime($date_from)));
        $end = new \DateTime(date('Y-m-01', strtotime($date_to)));

        while ($cursor <= $end) {
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            $columns[] = [
                'key'   => $cursor->format('Y-m'),
                'label' => $cursor->format('M-y'),
                'type'  => 'month',
                'year'  => $year,
                'month' => $month,
            ];

            $is_last = $cursor->format('Y-m') === $end->format('Y-m');
            if ($month === 12 || $is_last) {
                $columns[] = [
                    'key'   => 'ytd_' . $year,
                    'label' => 'YTD ' . $year,
                    'type'  => 'ytd',
                    'year'  => $year,
                ];
            }

            $cursor->modify('+1 month');
        }

        return $columns;
    }

    private function _coa_term(): ?string
    {
        $term = trim((string) ($this->payload['term'] ?? ''));

        return $term === '' ? null : $term;
    }

    private function _coa_matches_term(string $term, $group_code, $code): bool
    {
        return stripos((string) $group_code, $term) !== false
            || stripos((string) $code, $term) !== false;
    }

    /**
     * Natural ascending group_code then code; empty/NULL group codes last.
     */
    private function _coa_group_code_cmp($a_group, $a_code, $b_group, $b_code): int
    {
        $a_empty = $a_group === null || $a_group === '';
        $b_empty = $b_group === null || $b_group === '';
        if ($a_empty !== $b_empty) {
            return $a_empty ? 1 : -1;
        }

        $group = strnatcasecmp((string) $a_group, (string) $b_group);
        if ($group !== 0) {
            return $group;
        }

        return strnatcasecmp((string) $a_code, (string) $b_code);
    }

    private function _gl_sheet_account_type_label(array $row): string
    {
        $financial_statement = strtolower(trim((string) ($row['financial_statement'] ?? '')));
        if ($financial_statement === 'profit_and_loss') {
            return 'P&L';
        }
        if ($financial_statement === 'balance_sheet') {
            return 'BS';
        }

        return $financial_statement !== '' ? strtoupper($financial_statement) : '';
    }

    /**
     * @param array<int, array> $accounts
     * @param array<int, int> $leaf_id_by_account_id
     * @param list<array> $rows
     */
    private function _gl_apply_monthly_totals(array &$accounts, array $leaf_id_by_account_id, array $rows): void
    {
        foreach ($rows as $row) {
            $posted_id = (int) ($row['account_type_id'] ?? 0);
            $leaf_id = $leaf_id_by_account_id[$posted_id] ?? $posted_id;
            if (! isset($accounts[$leaf_id])) {
                continue;
            }
            $year = (int) ($row['yr'] ?? 0);
            $month = (int) ($row['mo'] ?? 0);
            $key = sprintf('%04d-%02d', $year, $month);
            if (! array_key_exists($key, $accounts[$leaf_id]['values'])) {
                continue;
            }
            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);
            $accounts[$leaf_id]['values'][$key . '_debit'] = round(
                (float) $accounts[$leaf_id]['values'][$key . '_debit'] + $debit,
                4
            );
            $accounts[$leaf_id]['values'][$key . '_credit'] = round(
                (float) $accounts[$leaf_id]['values'][$key . '_credit'] + $credit,
                4
            );
            $amount = $this->_gl_signed_amount(
                $accounts[$leaf_id]['nature'] ?? null,
                $debit,
                $credit
            );
            $accounts[$leaf_id]['values'][$key] = round(
                (float) $accounts[$leaf_id]['values'][$key] + $amount,
                4
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array{account_type_id:int, code:mixed, name:mixed} $account
     * @return array<string, mixed>
     */
    private function _gl_breakdown_entry(array $row, int $branch_id, array $account): array
    {
        return [
            'id'              => (int) ($row['id'] ?? 0),
            'branch_id'       => (int) ($row['branch_id'] ?? $branch_id),
            'source'          => $row['source'] ?? null,
            'source_id'       => isset($row['source_id']) ? (int) $row['source_id'] : null,
            'date'            => $row['date'] ?? null,
            'debit'           => (float) ($row['debit'] ?? 0),
            'credit'          => (float) ($row['credit'] ?? 0),
            'remark'          => $row['remark'] ?? null,
            'account_type_id' => (int) ($row['account_type_id'] ?? 0),
            'account_code'    => $row['account_code'] ?? ($account['code'] ?? null),
            'account_name'    => $row['account_name'] ?? ($account['name'] ?? null),
        ];
    }

    private function _gl_signed_amount($nature, float $debit, float $credit): float
    {
        if (strtolower(trim((string) $nature)) === 'credit') {
            return $credit - $debit;
        }

        return $debit - $credit;
    }

    /**
     * Indirect statement of cash flows. Month columns are YTD from date_from.
     * Optional branch_id; omit to sum all clinic branches.
     */
    public function get_cash_flow()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        if (! $date_from || ! $date_to) {
            return $this->_create_response(FAIL, ['date_from and date_to are required.']);
        }

        $from_ts = strtotime((string) $date_from);
        $to_ts = strtotime((string) $date_to);
        if (! $from_ts || ! $to_ts) {
            return $this->_create_response(FAIL, ['Invalid date_from or date_to.']);
        }

        $date_from = date('Y-m-d', $from_ts);
        $date_to = date('Y-m-d', $to_ts);
        if ($date_from > $date_to) {
            return $this->_create_response(FAIL, ['date_from must be on or before date_to.']);
        }

        $from_month = new \DateTime(date('Y-m-01', $from_ts));
        $to_month = new \DateTime(date('Y-m-01', $to_ts));
        $month_span = ((int) $to_month->format('Y') - (int) $from_month->format('Y')) * 12
            + ((int) $to_month->format('n') - (int) $from_month->format('n'))
            + 1;
        if ($month_span > 36) {
            return $this->_create_response(FAIL, ['date range cannot exceed 36 months.']);
        }

        $db_map = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            0 => 'default',
        ];

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;
            if (! isset($db_map[$branch_id])) {
                return $this->_create_response(FAIL, ['Invalid Branch ID.']);
            }
            $branch_ids = [$branch_id];
        } else {
            $branch_id = null;
            $branch_ids = array_keys($db_map);
        }

        $columns = $this->_cash_flow_month_columns($date_from, $date_to);
        $month_keys = array_column($columns, 'key');
        $coa_by_id = $this->_cash_flow_coa_by_id();
        $activity = [];
        $opening_cash = 0.0;

        foreach ($branch_ids as $id) {
            if (! $this->resolve_branch_models((int) $id)) {
                continue;
            }
            foreach ($this->general_ledger->get_posted_monthly_totals($date_from, $date_to) as $row) {
                $account_type_id = (int) ($row['account_type_id'] ?? 0);
                if ($account_type_id <= 0) {
                    continue;
                }
                $key = sprintf('%04d-%02d', (int) ($row['yr'] ?? 0), (int) ($row['mo'] ?? 0));
                if (! in_array($key, $month_keys, true)) {
                    continue;
                }
                $this->_cash_flow_apply_posted(
                    $activity,
                    $coa_by_id[$account_type_id] ?? null,
                    $key,
                    (float) ($row['debit'] ?? 0),
                    (float) ($row['credit'] ?? 0)
                );
            }
            foreach ($this->general_ledger->get_posted_account_totals_before($date_from) as $row) {
                $account_type_id = (int) ($row['account_type_id'] ?? 0);
                $coa = $coa_by_id[$account_type_id] ?? null;
                if (! $coa || ! $this->_cash_flow_is_cash_account($coa)) {
                    continue;
                }
                $opening_cash += $this->_gl_signed_amount(
                    $coa['nature'] ?? null,
                    (float) ($row['debit'] ?? 0),
                    (float) ($row['credit'] ?? 0)
                );
            }
        }

        $ytd = $this->_cash_flow_running_totals($activity, $month_keys);
        $sheet = $this->_cash_flow_build_sheet($ytd, $month_keys, $opening_cash);

        return $this->_create_response(SUCCESS, [
            'date_from'       => $date_from,
            'date_to'         => $date_to,
            'branch_id'       => $branch_id,
            'columns'         => $columns,
            'operating'       => $sheet['operating'],
            'investing'       => $sheet['investing'],
            'financing'       => $sheet['financing'],
            'net_increase'    => $sheet['net_increase'],
            'beginning_cash'  => $sheet['beginning_cash'],
            'ending_cash'     => $sheet['ending_cash'],
        ]);
    }

    /**
     * @return list<array{key:string,label:string,year:int,month:int}>
     */
    private function _cash_flow_month_columns(string $date_from, string $date_to): array
    {
        $columns = [];
        $cursor = new \DateTime(date('Y-m-01', strtotime($date_from)));
        $end = new \DateTime(date('Y-m-01', strtotime($date_to)));
        while ($cursor <= $end) {
            $columns[] = [
                'key'   => $cursor->format('Y-m'),
                'label' => $cursor->format('M-Y'),
                'year'  => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
            ];
            $cursor->modify('+1 month');
        }

        return $columns;
    }

    /**
     * @return array<int, array>
     */
    private function _cash_flow_coa_by_id(): array
    {
        $coa_by_id = [];
        foreach ($this->account_type->get_balance_sheet_accounts() ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $coa_by_id[$id] = $row;
            }
        }
        foreach ($this->account_type->get_profit_and_loss_accounts() ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (isset($coa_by_id[$id])) {
                $coa_by_id[$id] = array_merge($coa_by_id[$id], $row);
            } else {
                $coa_by_id[$id] = $row;
            }
        }

        return $coa_by_id;
    }

    private function _cash_flow_apply_posted(
        array &$activity,
        ?array $coa,
        string $month_key,
        float $debit,
        float $credit
    ): void {
        if (! $coa) {
            return;
        }

        foreach ($this->_cash_flow_map($coa) as $hit) {
            $line = $hit[0];
            $mode = $hit[1];
            if ($mode === 'cash') {
                continue;
            }
            if ($mode === 'wc') {
                $amount = $credit - $debit;
            } elseif ($mode === 'pnl_out') {
                $amount = - $this->_gl_signed_amount($coa['nature'] ?? null, $debit, $credit);
            } else {
                $amount = $this->_gl_signed_amount($coa['nature'] ?? null, $debit, $credit);
                if ($mode === 'pnl_adj' && in_array($line, [
                    'gain_sale_ppe',
                    'unrealized_fx_gain',
                    'gain_insurance',
                    'interest_income',
                    'gain_reversal_bad_debts',
                ], true)) {
                    $amount = -$amount;
                }
            }
            $activity[$line][$month_key] = round(($activity[$line][$month_key] ?? 0) + $amount, 4);
        }
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private function _cash_flow_map(array $coa): array
    {
        $fs = strtolower(trim((string) ($coa['financial_statement'] ?? '')));
        $hay = $this->_cash_flow_haystack($coa);
        $line = strtoupper(trim((string) ($coa['line_name'] ?? '')));
        $detail = strtoupper(trim((string) ($coa['detail_name'] ?? '')));

        if ($fs === 'profit_and_loss') {
            $hits = [['income_before_tax', 'pnl']];
            $adj = $this->_cash_flow_pnl_adjustment($hay);
            if ($adj) {
                $hits[] = [$adj, 'pnl_adj'];
            }
            if ($this->_cash_flow_has($hay, ['DIVIDEND']) && $this->_cash_flow_has($hay, ['RECEIV'])) {
                $hits[] = ['dividends_received', 'pnl'];
            }
            if ($this->_cash_flow_has($hay, ['DIVIDEND']) && $this->_cash_flow_has($hay, ['PAID'])) {
                $hits[] = ['dividends_paid', 'pnl_out'];
            }
            if (
                $this->_cash_flow_has($hay, ['INSURANCE'])
                && $this->_cash_flow_has($hay, ['PROCEED', 'CLAIM'])
                && ! $this->_cash_flow_has($hay, ['GAIN'])
            ) {
                $hits[] = ['proceeds_insurance', 'pnl'];
            }

            return $hits;
        }

        if ($this->_cash_flow_is_cash_account($coa)) {
            return [['cash', 'cash']];
        }
        if ($this->_cash_flow_has($hay, ['TRUST RECEIPT'])) {
            return [['trust_receipts', 'wc']];
        }
        if ($this->_cash_flow_has($hay, ['SECURITY DEPOSIT'])) {
            return [['security_deposits', 'wc']];
        }
        if ($this->_cash_flow_has($hay, ['INVESTMENT']) && ! $this->_cash_flow_has($hay, ['PPE'])) {
            return [['investments', 'wc']];
        }
        if ($line === 'TRADE RECEIVABLES') {
            return [['trade_receivables', 'wc']];
        }
        if ($line === 'NON TRADE RECEIVABLE' || $line === 'RECEIVABLE - OTHERS') {
            return [['other_receivables', 'wc']];
        }
        if ($line === 'INVENTORIES') {
            return [['inventories', 'wc']];
        }
        if (
            $line === 'PROPERTY AND EQUIPMENT, NET'
            || $line === 'PPE'
            || strpos($line, 'RIGHT-OF USE') === 0
            || strpos($detail, 'RIGHT-OF USE') === 0
        ) {
            return [['ppe_net', 'wc']];
        }
        if ($line === 'TRADE AND OTHER PAYABLES' || $line === 'TRADE PAYABLES') {
            return [['trade_payables', 'wc']];
        }
        if ($line === 'PAYABLE - OTHERS') {
            return $detail === 'ADVANCES - OTHERS'
                ? [['advances_stockholders', 'wc']]
                : [['trade_payables', 'wc']];
        }
        if (in_array($line, ['TAXES PAYABLE', 'PREMIUMS PAYABLE', 'PROVISION FOR TAX LIABILITY'], true)) {
            return [['trade_payables', 'wc']];
        }
        if (
            $line === 'SHORT TERM BORROWING'
            || $line === 'SHORT TERM LOANS'
            || $line === 'LONG TERM LOANS'
            || $line === 'LOANS PAYABLE'
        ) {
            return [['bank_loans', 'wc']];
        }
        if ($line === 'OTHER CURRENT ASSETS') {
            return [['prepaid_and_oca', 'wc']];
        }
        if ($line === 'OTHER CURRENT LIABILITIES' || $line === 'LEASE LIABILITY - CURRENT') {
            return [['other_current_liabilities', 'wc']];
        }
        if ($line === 'OTHER NONCURRENT LIABILITIES') {
            return [['other_ncl', 'wc']];
        }
        if ($line === 'PAID UP CAPITAL') {
            return $this->_cash_flow_has($hay, ['PREFERRED'])
                ? [['preferred_shares', 'wc']]
                : [['common_shares', 'wc']];
        }
        if ($line === 'PREPAID TAXES' || $line === 'PREPAID EXPENSES') {
            return [['prepaid_and_oca', 'wc']];
        }
        if ($line === 'RETAINED EARNINGS/FUND BALANCE') {
            return [];
        }

        $category = strtolower(trim((string) ($coa['category'] ?? '')));
        if ($category === 'current_asset' || $category === 'prepayment') {
            return [['prepaid_and_oca', 'wc']];
        }

        return [];
    }

    private function _cash_flow_is_cash_account(array $coa): bool
    {
        $line = strtoupper(trim((string) ($coa['line_name'] ?? '')));

        return $line === 'CASH AND CASH EQUIVALENTS';
    }

    private function _cash_flow_pnl_adjustment(string $hay): ?string
    {
        if ($this->_cash_flow_has($hay, ['REVERSAL']) && $this->_cash_flow_has($hay, ['BAD DEBT', 'IMPAIRMENT'])) {
            return 'gain_reversal_bad_debts';
        }
        if ($this->_cash_flow_has($hay, ['BAD DEBT', 'IMPAIRMENT'])) {
            return 'bad_debt_expense';
        }
        if ($this->_cash_flow_has($hay, ['OBSOLETE']) && $this->_cash_flow_has($hay, ['INVENTORY'])) {
            return 'loss_obsolete_inventory';
        }
        if (
            $this->_cash_flow_has($hay, ['LOSS'])
            && $this->_cash_flow_has($hay, ['SALE', 'DISPOSAL'])
            && $this->_cash_flow_has($hay, ['PPE', 'PROPERTY'])
        ) {
            return 'loss_sale_ppe';
        }
        if (
            $this->_cash_flow_has($hay, ['GAIN'])
            && $this->_cash_flow_has($hay, ['SALE', 'DISPOSAL'])
            && $this->_cash_flow_has($hay, ['PPE', 'PROPERTY'])
        ) {
            return 'gain_sale_ppe';
        }
        if (
            $this->_cash_flow_has($hay, ['UNREALIZED'])
            && $this->_cash_flow_has($hay, ['LOSS'])
            && $this->_cash_flow_has($hay, ['FOREIGN', 'FOREX', ' FX ', 'EXCHANGE'])
        ) {
            return 'unrealized_fx_loss';
        }
        if (
            $this->_cash_flow_has($hay, ['UNREALIZED'])
            && $this->_cash_flow_has($hay, ['GAIN'])
            && $this->_cash_flow_has($hay, ['FOREIGN', 'FOREX', ' FX ', 'EXCHANGE'])
        ) {
            return 'unrealized_fx_gain';
        }
        if ($this->_cash_flow_has($hay, ['INSURANCE']) && $this->_cash_flow_has($hay, ['GAIN'])) {
            return 'gain_insurance';
        }
        if ($this->_cash_flow_has($hay, ['RETIREMENT'])) {
            return 'retirement_cost';
        }
        if (
            $this->_cash_flow_has($hay, ['INTEREST INCOME'])
            || (
                $this->_cash_flow_has($hay, ['INTEREST'])
                && $this->_cash_flow_has($hay, ['INCOME'])
                && ! $this->_cash_flow_has($hay, ['EXPENSE'])
            )
        ) {
            return 'interest_income';
        }
        if (
            $this->_cash_flow_has($hay, ['INTEREST EXPENSE'])
            || ($this->_cash_flow_has($hay, ['INTEREST']) && $this->_cash_flow_has($hay, ['EXPENSE']))
        ) {
            return 'interest_expense';
        }
        if ($this->_cash_flow_has($hay, ['DEPRECIATION', 'AMORTIZATION'])) {
            return 'depreciation';
        }

        return null;
    }

    private function _cash_flow_haystack(array $coa): string
    {
        $parts = [
            $coa['name'] ?? '',
            $coa['code'] ?? '',
            $coa['line_name'] ?? '',
            $coa['detail_name'] ?? '',
            $coa['section_name'] ?? '',
            $coa['report_name'] ?? '',
        ];

        return ' ' . strtoupper(trim(implode(' ', $parts))) . ' ';
    }

    /**
     * @param list<string> $needles
     */
    private function _cash_flow_has(string $hay, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (strpos($hay, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, float>> $activity
     * @param list<string> $month_keys
     * @return array<string, array<string, float>>
     */
    private function _cash_flow_running_totals(array $activity, array $month_keys): array
    {
        $ytd = [];
        $running = [];
        foreach ($month_keys as $key) {
            foreach ($activity as $line => $months) {
                $running[$line] = ($running[$line] ?? 0.0) + (float) ($months[$key] ?? 0);
                $ytd[$line][$key] = round($running[$line], 4);
            }
        }

        return $ytd;
    }

    /**
     * @param array<string, array<string, float>> $ytd
     * @param list<string> $month_keys
     */
    private function _cash_flow_build_sheet(array $ytd, array $month_keys, float $opening_cash): array
    {
        $val = function (string $line) use ($ytd, $month_keys): array {
            $values = [];
            foreach ($month_keys as $key) {
                $values[$key] = round((float) ($ytd[$line][$key] ?? 0), 4);
            }

            return $values;
        };
        $line = static function (string $label, array $values): array {
            return ['label' => $label, 'values' => $values];
        };
        $sum = function (array $parts) use ($month_keys): array {
            $values = [];
            foreach ($month_keys as $key) {
                $total = 0.0;
                foreach ($parts as $part) {
                    $total += (float) ($part[$key] ?? 0);
                }
                $values[$key] = round($total, 4);
            }

            return $values;
        };

        $income = $val('income_before_tax');
        $depreciation = $val('depreciation');
        $interest_expense = $val('interest_expense');
        $interest_income = $val('interest_income');
        $adjustments = [
            $depreciation,
            $interest_expense,
            $val('retirement_cost'),
            $val('loss_sale_ppe'),
            $val('unrealized_fx_loss'),
            $val('bad_debt_expense'),
            $val('loss_obsolete_inventory'),
            $val('gain_sale_ppe'),
            $val('unrealized_fx_gain'),
            $val('gain_insurance'),
            $interest_income,
            $val('gain_reversal_bad_debts'),
        ];
        $operating_before_wc = $sum(array_merge([$income], $adjustments));
        $interest_received = [];
        $interest_paid_operating = [];
        $interest_paid_financing = [];
        $ppe_acquisitions = [];
        $beginning = [];
        foreach ($month_keys as $key) {
            $interest_received[$key] = round(- (float) ($interest_income[$key] ?? 0), 4);
            $interest_paid_operating[$key] = 0.0;
            $interest_paid_financing[$key] = round(- (float) ($interest_expense[$key] ?? 0), 4);
            $ppe_acquisitions[$key] = round(
                (float) ($ytd['ppe_net'][$key] ?? 0) - (float) ($depreciation[$key] ?? 0),
                4
            );
            $beginning[$key] = round($opening_cash, 4);
        }

        $wc = [
            $val('trade_receivables'),
            $val('other_receivables'),
            $val('inventories'),
            $val('prepaid_and_oca'),
            $val('trade_payables'),
            $val('other_current_liabilities'),
            $val('trust_receipts'),
            $val('other_ncl'),
        ];
        $net_operating = $sum(array_merge([$operating_before_wc], $wc, [$interest_received, $interest_paid_operating]));
        $net_investing = $sum([
            $val('investments'),
            $val('security_deposits'),
            $ppe_acquisitions,
            $val('proceeds_insurance'),
            $val('dividends_received'),
        ]);
        $net_financing = $sum([
            $val('bank_loans'),
            $val('common_shares'),
            $val('preferred_shares'),
            $val('advances_stockholders'),
            $interest_paid_financing,
            $val('dividends_paid'),
        ]);
        $net_increase = $sum([$net_operating, $net_investing, $net_financing]);
        $ending = $sum([$beginning, $net_increase]);

        return [
            'operating' => [
                'income_before_tax'          => $line('Income (loss) before income tax', $income),
                'depreciation'               => $line('Depreciation and amortization', $depreciation),
                'interest_expense'           => $line('Interest expense', $interest_expense),
                'retirement_cost'            => $line('Retirement Cost', $val('retirement_cost')),
                'loss_sale_ppe'              => $line('Loss on sale or disposal of PPE', $val('loss_sale_ppe')),
                'unrealized_fx_loss'         => $line('Unrealized loss on foreign exchange', $val('unrealized_fx_loss')),
                'bad_debt_expense'           => $line('Bad debt expense or impairment loss', $val('bad_debt_expense')),
                'loss_obsolete_inventory'    => $line('Loss on obsolete inventory', $val('loss_obsolete_inventory')),
                'gain_sale_ppe'              => $line('Gain on sale or disposal of PPE', $val('gain_sale_ppe')),
                'unrealized_fx_gain'         => $line('Unrealized gain on foreign exchange', $val('unrealized_fx_gain')),
                'gain_insurance'             => $line('Gain on insurance claims', $val('gain_insurance')),
                'interest_income'            => $line('Interest income', $interest_income),
                'gain_reversal_bad_debts'    => $line('Gain on reversal of bad debts expense', $val('gain_reversal_bad_debts')),
                'operating_before_wc'        => $line('Operating gain (loss) before working capital changes', $operating_before_wc),
                'trade_receivables'          => $line('Trade receivables', $val('trade_receivables')),
                'other_receivables'          => $line('Other receivables', $val('other_receivables')),
                'inventories'                => $line('Inventories', $val('inventories')),
                'prepaid_and_oca'            => $line('Prepaid expenses and other current assets', $val('prepaid_and_oca')),
                'trade_payables'             => $line('Trade payables and accrued expenses', $val('trade_payables')),
                'other_current_liabilities'  => $line('Other current liabilities', $val('other_current_liabilities')),
                'trust_receipts'             => $line('Trust Receipts Loans', $val('trust_receipts')),
                'other_ncl'                  => $line('Other noncurrent liabilities', $val('other_ncl')),
                'interest_received'          => $line('Interest received', $interest_received),
                'interest_paid'              => $line('Interest paid', $interest_paid_operating),
                'net'                       => $line('Net cash provided by operating activities', $net_operating),
            ],
            'investing' => [
                'investments'         => $line('Investments', $val('investments')),
                'security_deposits'   => $line('Security deposits', $val('security_deposits')),
                'ppe_acquisitions'    => $line('Acquisitions/additions to PPE', $ppe_acquisitions),
                'proceeds_insurance'  => $line('Proceeds from insurance claim', $val('proceeds_insurance')),
                'dividends_received'  => $line('Dividends received', $val('dividends_received')),
                'net'                 => $line('Net cash provided by (used in) investing activities', $net_investing),
            ],
            'financing' => [
                'bank_loans'             => $line('Net proceeds (repayment) of bank notes/loans', $val('bank_loans')),
                'common_shares'          => $line('Common Shares', $val('common_shares')),
                'preferred_shares'       => $line('Preferred Shares', $val('preferred_shares')),
                'advances_stockholders'  => $line('Advances from stockholders', $val('advances_stockholders')),
                'interest_paid'          => $line('Interest paid', $interest_paid_financing),
                'dividends_paid'         => $line('Dividends paid', $val('dividends_paid')),
                'net'                    => $line('Net cash provided by financing activities', $net_financing),
            ],
            'net_increase'   => $line('NET INCREASE (DECREASE) IN CASH AND CASH EQUIVALENTS', $net_increase),
            'beginning_cash' => $line('CASH AND CASH EQUIVALENTS AT BEGINNING OF PERIOD', $beginning),
            'ending_cash'    => $line('CASH AND CASH EQUIVALENTS AT END OF PERIOD', $ending),
        ];
    }

    /**
     * Full chart of accounts with period and YTD debit/credit from posted GL rows.
     */
    public function get_trial_balance()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        if (! $date_from || ! $date_to) {
            return $this->_create_response(FAIL, ['date_from and date_to are required.']);
        }

        $from_ts = strtotime((string) $date_from);
        $to_ts = strtotime((string) $date_to);
        if (! $from_ts || ! $to_ts) {
            return $this->_create_response(FAIL, ['Invalid date_from or date_to.']);
        }

        $date_from = date('Y-m-d', $from_ts);
        $date_to = date('Y-m-d', $to_ts);
        if ($date_from > $date_to) {
            return $this->_create_response(FAIL, ['date_from must be on or before date_to.']);
        }

        $db_map = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            0 => 'default',
        ];

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;
            if (! isset($db_map[$branch_id])) {
                return $this->_create_response(FAIL, ['Invalid Branch ID.']);
            }
            $branch_ids = [$branch_id];
        } else {
            $branch_id = null;
            $branch_ids = array_keys($db_map);
        }

        $coa_rows = $this->account_type->get_trial_balance_accounts() ?: [];
        $leaves = [];
        $leaf_id_by_account_id = [];
        foreach ($coa_rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $parent_id = $row['parent_id'] ?? null;
            if ($parent_id === null || $parent_id === '') {
                $leaves[$id] = $row;
                $leaf_id_by_account_id[$id] = $id;
            } else {
                $leaf_id_by_account_id[$id] = (int) $parent_id;
            }
        }

        $zero_amounts = $this->_trial_balance_zero_amounts();
        $accounts = [];
        foreach ($leaves as $id => $row) {
            $accounts[$id] = array_merge([
                'id'           => $id,
                'code'         => $row['code'] ?? null,
                'group_code'   => $row['group_code'] ?? null,
                'name'         => $row['name'] ?? null,
                'account_type' => $this->_trial_balance_account_type_label($row),
            ], $zero_amounts);
        }

        $year = (int) date('Y', $to_ts);
        $today = date('Y-m-d');
        $current_year = (int) date('Y');
        if ($year < $current_year) {
            $ytd_from = sprintf('%04d-01-01', $year);
            $ytd_to = sprintf('%04d-12-31', $year);
        } else {
            $ytd_from = sprintf('%04d-01-01', $current_year);
            $ytd_to = $today;
        }

        $all_branch = $branch_id === null || count($branch_ids) > 1;
        if ($all_branch) {
            $clinic_ids = [];
            foreach ($branch_ids as $id) {
                $id = (int) $id;
                if ($id === 0 || ! $this->resolve_gl_model($id)) {
                    continue;
                }
                $clinic_ids[] = $id;
                $this->_add_trial_balance_activity(
                    $accounts,
                    $this->general_ledger->get_posted_monthly_totals($date_from, $date_to, true),
                    $leaf_id_by_account_id,
                    $year
                );
                $this->_add_trial_balance_ytd(
                    $accounts,
                    $this->general_ledger->get_posted_account_totals($ytd_from, $ytd_to, true),
                    $leaf_id_by_account_id
                );
            }
            if ($this->resolve_gl_model(0)) {
                $this->_add_trial_balance_activity(
                    $accounts,
                    $this->general_ledger->get_posted_monthly_totals_master_merged(
                        $date_from,
                        $date_to,
                        $clinic_ids
                    ),
                    $leaf_id_by_account_id,
                    $year
                );
                $this->_add_trial_balance_ytd(
                    $accounts,
                    $this->general_ledger->get_posted_account_totals_master_merged(
                        $ytd_from,
                        $ytd_to,
                        $clinic_ids
                    ),
                    $leaf_id_by_account_id
                );
            }
        } else {
            foreach ($branch_ids as $id) {
                if (! $this->resolve_gl_model((int) $id)) {
                    continue;
                }
                $this->_add_trial_balance_activity(
                    $accounts,
                    $this->general_ledger->get_posted_monthly_totals($date_from, $date_to),
                    $leaf_id_by_account_id,
                    $year
                );
                $this->_add_trial_balance_ytd(
                    $accounts,
                    $this->general_ledger->get_posted_account_totals($ytd_from, $ytd_to),
                    $leaf_id_by_account_id
                );
            }
        }

        $list = array_values($accounts);
        $term = $this->_coa_term();
        if ($term !== null) {
            $list = array_values(array_filter($list, function ($row) use ($term) {
                return $this->_coa_matches_term($term, $row['group_code'] ?? '', $row['code'] ?? '');
            }));
        }
        usort($list, function ($a, $b) {
            return $this->_coa_group_code_cmp(
                $a['group_code'] ?? '',
                $a['code'] ?? '',
                $b['group_code'] ?? '',
                $b['code'] ?? ''
            );
        });

        $total = $zero_amounts;
        foreach ($list as $row) {
            foreach ($total as $key => $_) {
                $total[$key] += (float) ($row[$key] ?? 0);
            }
        }
        foreach ($total as $key => $amount) {
            $total[$key] = round($amount, 4);
        }

        return $this->_create_response(SUCCESS, [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'branch_id' => $branch_id,
            'accounts'  => $list,
            'total'     => $total,
        ]);
    }

    /**
     * @param array<int, array> $accounts
     * @param array<int, array> $rows
     * @param array<int, int> $leaf_id_by_account_id
     */
    private function _add_trial_balance_activity(
        array &$accounts,
        array $rows,
        array $leaf_id_by_account_id,
        int $year
    ): void {
        $month_names = $this->_trial_balance_month_names();
        foreach ($rows as $row) {
            $posted_id = (int) ($row['account_type_id'] ?? 0);
            if ($posted_id <= 0) {
                continue;
            }
            $leaf_id = $leaf_id_by_account_id[$posted_id] ?? null;
            if ($leaf_id === null || ! isset($accounts[$leaf_id])) {
                continue;
            }

            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);

            $row_year = (int) ($row['yr'] ?? 0);
            $month_index = (int) ($row['mo'] ?? 0) - 1;
            if ($row_year !== $year || ! isset($month_names[$month_index])) {
                continue;
            }
            $month = $month_names[$month_index];
            $accounts[$leaf_id]['debit_' . $month] = round(
                (float) $accounts[$leaf_id]['debit_' . $month] + $debit,
                4
            );
            $accounts[$leaf_id]['credit_' . $month] = round(
                (float) $accounts[$leaf_id]['credit_' . $month] + $credit,
                4
            );
        }
    }

    /**
     * @param array<int, array> $accounts
     * @param array<int, array> $rows
     * @param array<int, int> $leaf_id_by_account_id
     */
    private function _add_trial_balance_ytd(
        array &$accounts,
        array $rows,
        array $leaf_id_by_account_id
    ): void {
        foreach ($rows as $row) {
            $posted_id = (int) ($row['account_type_id'] ?? 0);
            if ($posted_id <= 0) {
                continue;
            }
            $leaf_id = $leaf_id_by_account_id[$posted_id] ?? null;
            if ($leaf_id === null || ! isset($accounts[$leaf_id])) {
                continue;
            }

            $accounts[$leaf_id]['debit_ytd'] = round(
                (float) $accounts[$leaf_id]['debit_ytd'] + (float) ($row['debit'] ?? 0),
                4
            );
            $accounts[$leaf_id]['credit_ytd'] = round(
                (float) $accounts[$leaf_id]['credit_ytd'] + (float) ($row['credit'] ?? 0),
                4
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function _trial_balance_month_names(): array
    {
        return ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    }

    /**
     * @return array<string, float>
     */
    private function _trial_balance_zero_amounts(): array
    {
        $amounts = [];
        foreach ($this->_trial_balance_month_names() as $name) {
            $amounts['debit_' . $name] = 0.0;
            $amounts['credit_' . $name] = 0.0;
        }
        $amounts['debit_ytd'] = 0.0;
        $amounts['credit_ytd'] = 0.0;

        return $amounts;
    }

    /**
     * Excel-style Account Type label for a COA leaf.
     */
    private function _trial_balance_account_type_label(array $row): string
    {
        $financial_statement = strtolower(trim((string) ($row['financial_statement'] ?? '')));
        if ($financial_statement === 'profit_and_loss') {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $nature = strtolower(trim((string) ($row['nature'] ?? '')));
            $category = strtolower(trim((string) ($row['category'] ?? '')));
            $group_code = strtoupper(trim((string) ($row['group_code'] ?? '')));
            if ($nature === 'credit') {
                if ($category === 'income' && (strpos($group_code, '9') === 0 || strpos($code, '700') === 0)) {
                    return 'Other Income';
                }

                return 'Revenue';
            }
            if (strpos($code, '500-8') === 0) {
                return 'Depreciation';
            }
            if ($category === 'cost_of_sales') {
                return 'Expense';
            }

            return 'Expense';
        }

        $category = trim((string) ($row['category'] ?? ''));
        if ($category === '') {
            return '';
        }

        return ucwords(str_replace('_', ' ', $category));
    }

    /**
     * Get accounts_receivable
     */
    public function get_accounts_receivable()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === '' || $branch_id === '0' || $branch_id === 0) {
            $branch_id = null;
        } elseif ($branch_id !== null) {
            $branch_id = (int) $branch_id;
            if (!in_array($branch_id, [1, 2, 3, 4, 7], true)) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Branch ID.']
                );
            }
        }
        $company_id = $this->payload['company_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $type = $this->payload['type'] ?? null;
        if ($type !== null && $type !== '') {
            $type = strtolower(trim((string) $type));
            if (! in_array($type, ['hmo', 'corporate', 'ar_at_risk', 'yakap_ar', 'ar_unbilled'], true)) {
                return $this->_create_response(FAIL, [
                    'Invalid type. Allowed values: hmo, corporate, ar_at_risk, yakap_ar, ar_unbilled.',
                ]);
            }
        } else {
            $type = null;
        }

        $as_of_date = $this->payload['as_of_date'] ?? date('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $as_of_date)) {
            return $this->_create_response(FAIL, ['Invalid as_of_date. Use YYYY-MM-DD.']);
        }
        $customers = $this->_build_accounts_receivable_customers($as_of_date, $branch_id, $company_id, $type);
        if (! $customers) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No accounts receivable found.']);
        }

        return $this->_create_response(SUCCESS, [
            'customers' => $customers,
        ]);
    }

    /**
     * Topsheet AR summary: HMO, Corporate, AR At Risk, Yakap AR, AR Unbilled.
     */
    public function get_accounts_receivable_summary()
    {
        $as_of_date = $this->payload['as_of_date'] ?? date('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $as_of_date)) {
            return $this->_create_response(FAIL, ['Invalid as_of_date. Use YYYY-MM-DD.']);
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === '' || $branch_id === '0' || $branch_id === 0) {
            $branch_id = null;
        } elseif ($branch_id !== null) {
            $branch_id = (int) $branch_id;
            if (! in_array($branch_id, [1, 2, 3, 4, 7], true)) {
                return $this->_create_response(FAIL, ['Invalid Branch ID.']);
            }
        }

        $payload = $this->_build_accounts_receivable_summary($as_of_date, $branch_id);

        return $this->_create_response(SUCCESS, $payload);
    }

    /**
     * Excel export of AR summary; uploaded to VM4.
     */
    public function get_accounts_receivable_summary_excel()
    {
        $as_of_date = $this->payload['as_of_date'] ?? date('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $as_of_date)) {
            return $this->_create_response(FAIL, ['Invalid as_of_date. Use YYYY-MM-DD.']);
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === '' || $branch_id === '0' || $branch_id === 0) {
            $branch_id = null;
        } elseif ($branch_id !== null) {
            $branch_id = (int) $branch_id;
            if (! in_array($branch_id, [1, 2, 3, 4, 7], true)) {
                return $this->_create_response(FAIL, ['Invalid Branch ID.']);
            }
        }

        $payload  = $this->_build_accounts_receivable_summary($as_of_date, $branch_id);
        $file_name = 'AR_Summary_' . $as_of_date . '.xlsx';

        return $this->_ar_excel_to_vm4($file_name, $as_of_date, function ($sheet) use ($payload) {
            $this->_write_accounts_receivable_summary_sheet($sheet, $payload);
        });
    }

    /**
     * Excel export of AR detail; uploaded to VM4.
     */
    public function get_accounts_receivable_excel()
    {
        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === '' || $branch_id === '0' || $branch_id === 0) {
            $branch_id = null;
        } elseif ($branch_id !== null) {
            $branch_id = (int) $branch_id;
            if (! in_array($branch_id, [1, 2, 3, 4, 7], true)) {
                return $this->_create_response(FAIL, ['Invalid Branch ID.']);
            }
        }

        $company_id = $this->payload['company_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        if ($type !== null && $type !== '') {
            $type = strtolower(trim((string) $type));
            if (! in_array($type, ['hmo', 'corporate', 'ar_at_risk', 'yakap_ar', 'ar_unbilled'], true)) {
                return $this->_create_response(FAIL, [
                    'Invalid type. Allowed values: hmo, corporate, ar_at_risk, yakap_ar, ar_unbilled.',
                ]);
            }
        } else {
            $type = null;
        }

        $as_of_date = $this->payload['as_of_date'] ?? date('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $as_of_date)) {
            return $this->_create_response(FAIL, ['Invalid as_of_date. Use YYYY-MM-DD.']);
        }

        $customers = $this->_build_accounts_receivable_customers($as_of_date, $branch_id, $company_id, $type);
        if (! $customers) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No accounts receivable found.']);
        }

        $file_name = 'AR_' . ($type ?: 'all') . '_' . $as_of_date . '.xlsx';

        return $this->_ar_excel_to_vm4($file_name, $as_of_date, function ($sheet) use ($customers, $as_of_date, $type) {
            $this->_write_accounts_receivable_sheet($sheet, $customers, $as_of_date, $type);
        });
    }

    /**
     * Get accounts_payable
     */
    public function get_accounts_payable()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Branch ID.']
                );
            }
        }

        $date = date('Y-m-d'); //current date for aging calculation

        $filteredCustomers = [];
        
        // Initialize totals variables
        $totalCurrent = 0;
        $total1to30_days = 0;
        $total31to60_days = 0;
        $total61to90_days = 0;
        $totalAbove90_days = 0;
        $grandTotal = 0;

        if (!$customers = $this->company->get_customer($company_id)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No accounts payable found.'
            ];
        } else {
            foreach ($customers as $i => $customer) {

                if ($branch_id) {
                    $billings = $this->billing->get_billing($customer['charge_to']);
                } else {
                    $billings = [];
                    foreach ([1,2,3,4,7] as $branch_id) {
                        if ($this->resolve_branch_models($branch_id)) {
                            $billings = array_merge($billings, $this->billing->get_billing($customer['charge_to']) ?? []);
                        }
                    }
                }

                $customerCurrent = 0;
                $customer1to30_days = 0;
                $customer31to60_days = 0;
                $customer61to90_days = 0;
                $customerAbove90_days = 0;
                $customerTotal = 0;
                $filteredProjects = [];

                foreach ($billings as $j => $billing) {
                    if ($branch_id) {
                        $entries = $this->billing->get_billing_sales_entry($billing['id'], $date);
                    } else {
                        $entries = [];
                        foreach ([1,2,3,4,7] as $branch_id) {
                            if ($this->resolve_branch_models($branch_id)) {
                                $entries = array_merge($billings, $this->billing->get_billing_sales_entry($billing['id'], $date, $billing['branch_id']) ?? []);
                            }
                        }
                    }

                    if (empty($entries)) continue; // Skip billings with no entries

                    $billingData = [
                        'current' => 0,
                        '1-30_days' => 0,
                        '31-60_days' => 0,
                        '61-90_days' => 0,
                        'above_90_days' => 0,
                        'total' => 0,
                        'entries' => $entries
                    ];

                    foreach ($entries as $entry) {
                        $billingData['current'] += number_format($entry['current'], 4, '.', '');
                        $billingData['1-30_days'] += number_format($entry['1-30_days'], 4, '.', '');
                        $billingData['31-60_days'] += number_format($entry['31-60_days'], 4, '.', '');
                        $billingData['61-90_days'] += number_format($entry['61-90_days'], 4, '.', '');
                        $billingData['above_90_days'] += number_format($entry['above_90_days'], 4, '.', '');
                        $billingData['total'] += number_format(($entry['current'] + $entry['1-30_days'] + $entry['31-60_days'] + $entry['61-90_days'] + $entry['above_90_days']), 4, '.', '');
                    }

                    $customerCurrent += $billingData['current'];
                    $customer1to30_days += $billingData['1-30_days'];
                    $customer31to60_days += $billingData['31-60_days'];
                    $customer61to90_days += $billingData['61-90_days'];
                    $customerAbove90_days += $billingData['above_90_days'];
                    $customerTotal += $billingData['total'];
                    if ($billingData['total'] != 0) {
                        $filteredProjects[] = array_merge($billing, $billingData);
                    }
                }

                if (!empty($filteredProjects)) {
                    $customer['current'] = number_format($customerCurrent, 4, '.', '');
                    $customer['1-30_days'] = number_format($customer1to30_days, 4, '.', '');
                    $customer['31-60_days'] = number_format($customer31to60_days, 4, '.', '');
                    $customer['61-90_days'] = number_format($customer61to90_days, 4, '.', '');
                    $customer['above_90_days'] = number_format($customerAbove90_days, 4, '.', '');
                    $customer['total'] = number_format($customerTotal, 4, '.', '');
                    $customer['billings'] = $filteredProjects;
                    $filteredCustomers[] = $customer;
                    
                    // Accumulate totals for the summary row
                    $totalCurrent += $customerCurrent;
                    $total1to30_days += $customer1to30_days;
                    $total31to60_days += $customer31to60_days;
                    $total61to90_days += $customer61to90_days;
                    $totalAbove90_days += $customerAbove90_days;
                    $grandTotal += $customerTotal;
                }
            }

            // Add the Totals row
            $totalsCustomer = [
                'customer_name' => 'Totals',
                'current' => number_format($totalCurrent, 4, '.', ''),
                '1-30_days' => number_format($total1to30_days, 4, '.', ''),
                '31-60_days' => number_format($total31to60_days, 4, '.', ''),
                '61-90_days' => number_format($total61to90_days, 4, '.', ''),
                'above_90_days' => number_format($totalAbove90_days, 4, '.', ''),
                'total' => number_format($grandTotal, 4, '.', ''),
                'billings' => [] // Empty billings array for totals
            ];

            // Add totals to the customers array
            $filteredCustomers[] = $totalsCustomer;

            $response_status = SUCCESS;
            $response = [
                'customers' => $filteredCustomers,
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    private function _build_accounts_receivable_customers($as_of_date, $branch_id, $company_id, $type): array
    {
        if ($type === 'ar_unbilled') {
            return $this->_accounts_receivable_unbilled($as_of_date, $branch_id, $company_id);
        }

        $customers = $this->company->get_customer($company_id, null, null, $type);
        if (! $customers) {
            return [];
        }

        $filteredCustomers = [];
        $totalCurrent = 0;
        $total1to30_days = 0;
        $total31to60_days = 0;
        $total61to90_days = 0;
        $totalAbove90_days = 0;
        $grandTotal = 0;

        foreach ($customers as $customer) {
            $billings = $this->billing->get_billing($customer['charge_to'], $branch_id) ?: [];

            $customerCurrent = 0;
            $customer1to30_days = 0;
            $customer31to60_days = 0;
            $customer61to90_days = 0;
            $customerAbove90_days = 0;
            $customerTotal = 0;
            $filteredProjects = [];

            foreach ($billings as $billing) {
                $entries = $this->billing->get_billing_sales_entry($billing['id'], $as_of_date, $branch_id) ?: [];
                if (empty($entries)) {
                    continue;
                }

                $billingData = [
                    'current' => 0,
                    '1-30_days' => 0,
                    '31-60_days' => 0,
                    '61-90_days' => 0,
                    'above_90_days' => 0,
                    'total' => 0,
                    'entries' => $entries,
                ];

                foreach ($entries as $entry) {
                    $billingData['current'] += number_format($entry['current'], 4, '.', '');
                    $billingData['1-30_days'] += number_format($entry['1-30_days'], 4, '.', '');
                    $billingData['31-60_days'] += number_format($entry['31-60_days'], 4, '.', '');
                    $billingData['61-90_days'] += number_format($entry['61-90_days'], 4, '.', '');
                    $billingData['above_90_days'] += number_format($entry['above_90_days'], 4, '.', '');
                    $billingData['total'] += number_format(($entry['current'] + $entry['1-30_days'] + $entry['31-60_days'] + $entry['61-90_days'] + $entry['above_90_days']), 4, '.', '');
                }

                if ($type === 'hmo' || $type === 'corporate') {
                    $billingData['above_90_days'] = 0;
                    $billingData['total'] = $billingData['current'] + $billingData['1-30_days'] + $billingData['31-60_days'] + $billingData['61-90_days'];
                } elseif ($type === 'ar_at_risk') {
                    $billingData['current'] = 0;
                    $billingData['1-30_days'] = 0;
                    $billingData['31-60_days'] = 0;
                    $billingData['61-90_days'] = 0;
                    $billingData['total'] = $billingData['above_90_days'];
                }

                $customerCurrent += $billingData['current'];
                $customer1to30_days += $billingData['1-30_days'];
                $customer31to60_days += $billingData['31-60_days'];
                $customer61to90_days += $billingData['61-90_days'];
                $customerAbove90_days += $billingData['above_90_days'];
                $customerTotal += $billingData['total'];
                if ($billingData['total'] != 0) {
                    $filteredProjects[] = array_merge($billing, $billingData);
                }
            }

            if (! empty($filteredProjects)) {
                $customer['current'] = number_format($customerCurrent, 4, '.', '');
                $customer['1-30_days'] = number_format($customer1to30_days, 4, '.', '');
                $customer['31-60_days'] = number_format($customer31to60_days, 4, '.', '');
                $customer['61-90_days'] = number_format($customer61to90_days, 4, '.', '');
                $customer['above_90_days'] = number_format($customerAbove90_days, 4, '.', '');
                $customer['total'] = number_format($customerTotal, 4, '.', '');
                $customer['billings'] = $filteredProjects;
                $filteredCustomers[] = $customer;

                $totalCurrent += $customerCurrent;
                $total1to30_days += $customer1to30_days;
                $total31to60_days += $customer31to60_days;
                $total61to90_days += $customer61to90_days;
                $totalAbove90_days += $customerAbove90_days;
                $grandTotal += $customerTotal;
            }
        }

        if (! $filteredCustomers) {
            return [];
        }

        usort($filteredCustomers, static function ($a, $b) {
            return (float) ($b['total'] ?? 0) <=> (float) ($a['total'] ?? 0);
        });

        $filteredCustomers[] = [
            'customer_name' => 'Totals',
            'current' => number_format($totalCurrent, 4, '.', ''),
            '1-30_days' => number_format($total1to30_days, 4, '.', ''),
            '31-60_days' => number_format($total31to60_days, 4, '.', ''),
            '61-90_days' => number_format($total61to90_days, 4, '.', ''),
            'above_90_days' => number_format($totalAbove90_days, 4, '.', ''),
            'total' => number_format($grandTotal, 4, '.', ''),
            'billings' => [],
        ];

        return $filteredCustomers;
    }

    /**
     * @return array{as_of:string, groups:array<int, array>}
     */
    private function _build_accounts_receivable_summary($as_of_date, $branch_id = null): array
    {
        $as_of_ts    = strtotime((string) $as_of_date);
        $year_start  = date('Y-01-01', $as_of_ts);
        $month_start = date('Y-m-01', $as_of_ts);
        $days        = ((int) date('z', $as_of_ts)) + 1;

        $outstanding = [
            'hmo'       => ['total' => 0.0, 'above_90' => 0.0],
            'corporate' => ['total' => 0.0, 'above_90' => 0.0],
            'yakap'     => ['total' => 0.0, 'above_90' => 0.0],
        ];
        foreach ($this->billing->get_ar_outstanding_by_company($as_of_date, $branch_id) as $row) {
            $bucket = $this->_ar_summary_bucket($row['company_id'] ?? null, $row['type'] ?? null, $row['classification'] ?? null);
            $outstanding[$bucket]['total'] += (float) ($row['total'] ?? 0);
            $outstanding[$bucket]['above_90'] += (float) ($row['above_90'] ?? 0);
        }

        $hmo_amount   = $outstanding['hmo']['total'] - $outstanding['hmo']['above_90'];
        $corp_amount  = $outstanding['corporate']['total'] - $outstanding['corporate']['above_90'];
        $at_risk      = $outstanding['hmo']['above_90'] + $outstanding['corporate']['above_90'];
        $yakap_amount = $outstanding['yakap']['total'];
        $unbilled     = $this->_ar_summary_unbilled($year_start, $as_of_date, $branch_id);
        $grand_total  = $hmo_amount + $corp_amount + $at_risk + $yakap_amount + $unbilled;

        $today = $this->_ar_summary_collections_by_bucket($as_of_date, $as_of_date, $branch_id);
        $mtd   = $this->_ar_summary_collections_by_bucket($month_start, $as_of_date, $branch_id);
        $ytd   = $this->_ar_summary_collections_by_bucket($year_start, $as_of_date, $branch_id);
        $sales = $this->_ar_summary_credit_sales_by_bucket($year_start, $as_of_date, $branch_id);

        return [
            'as_of'  => $as_of_date,
            'groups' => [
                $this->_ar_summary_row(1, 'HMO', $hmo_amount, $this->_ar_summary_days_sales($hmo_amount, $sales['hmo'], $ytd['hmo'], $days), $today['hmo'], $mtd['hmo'], $grand_total),
                $this->_ar_summary_row(2, 'Corporate', $corp_amount, $this->_ar_summary_days_sales($corp_amount, $sales['corporate'], $ytd['corporate'], $days), $today['corporate'], $mtd['corporate'], $grand_total),
                $this->_ar_summary_row(3, 'AR At Risk', $at_risk, null, null, null, $grand_total),
                $this->_ar_summary_row(4, 'Yakap AR', $yakap_amount, $this->_ar_summary_days_sales($yakap_amount, $sales['yakap'], $ytd['yakap'], $days), $today['yakap'], $mtd['yakap'], $grand_total),
                $this->_ar_summary_row(5, 'AR Unbilled', $unbilled, null, null, null, $grand_total),
                $this->_ar_summary_row(null, 'TOTAL', $grand_total, null, $today['hmo'] + $today['corporate'] + $today['yakap'], $mtd['hmo'] + $mtd['corporate'] + $mtd['yakap'], $grand_total),
            ],
        ];
    }

    /**
     * @return 'hmo'|'corporate'|'yakap'
     */
    private function _ar_summary_bucket($company_id, $type = null, $classification = null): string
    {
        if (Yakap_billing::is_yakap($company_id, $company_id)) {
            return 'yakap';
        }

        $type           = strtolower(trim((string) $type));
        $classification = strtolower(trim((string) $classification));
        if ($type === 'hmo' && $classification === 'philhealth') {
            return 'yakap';
        }
        if ($type === 'hmo') {
            return 'hmo';
        }

        return 'corporate';
    }

    /**
     * @return array{hmo:float, corporate:float, yakap:float}
     */
    private function _ar_summary_empty_buckets(): array
    {
        return ['hmo' => 0.0, 'corporate' => 0.0, 'yakap' => 0.0];
    }

    /**
     * @return array{hmo:float, corporate:float, yakap:float}
     */
    private function _ar_summary_collections_by_bucket($date_from, $date_to, $branch_id = null): array
    {
        $buckets = $this->_ar_summary_empty_buckets();
        foreach ($this->billing->get_collections_by_company($date_from, $date_to, $branch_id) as $row) {
            $bucket = $this->_ar_summary_bucket($row['company_id'] ?? null, $row['type'] ?? null, $row['classification'] ?? null);
            $buckets[$bucket] += (float) ($row['amount'] ?? 0);
        }

        return $buckets;
    }

    /**
     * @return array{hmo:float, corporate:float, yakap:float}
     */
    private function _ar_summary_credit_sales_by_bucket($date_from, $date_to, $branch_id = null): array
    {
        $buckets = $this->_ar_summary_empty_buckets();
        $with_branch = $branch_id !== null;
        $rows = $this->billing->get_billed_totals_by_charge($date_from, $date_to, $with_branch);
        $company_ids = [];
        foreach ($rows as $row) {
            if ($branch_id && (int) ($row['branch_id'] ?? 0) !== (int) $branch_id) {
                continue;
            }
            $company_ids[] = (int) ($row['charge_company_id'] ?? 0);
        }

        $companies = [];
        foreach ($this->company->get_by_ids($company_ids) as $company) {
            $companies[(int) $company['id']] = $company;
        }

        foreach ($rows as $row) {
            if ($branch_id && (int) ($row['branch_id'] ?? 0) !== (int) $branch_id) {
                continue;
            }
            $company_id = (int) ($row['charge_company_id'] ?? 0);
            $company    = $companies[$company_id] ?? [];
            $bucket     = $this->_ar_summary_bucket($company_id, $company['type'] ?? null, $company['classification'] ?? null);
            $buckets[$bucket] += (float) ($row['amount'] ?? 0);
        }

        return $buckets;
    }

    /**
     * Unbilled AR per charge company (excludes Yakap).
     *
     * @return array<int, float>
     */
    private function _ar_summary_unbilled_by_company($date_from, $date_to, $branch_id = null): array
    {
        $db_map = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];
        $branch_ids = $branch_id ? [$branch_id] : array_keys($db_map);
        $by_company = [];

        foreach ($branch_ids as $id) {
            if (! isset($db_map[$id])) {
                continue;
            }
            $db = db_connect($db_map[$id]);
            $rows = array_merge(
                $this->booking_all->get_unbilled_sales_by_charge_on_connection($db, $date_from, $date_to),
                $this->booking_all->get_copay_sales_by_hmo_on_connection($db, $date_from, $date_to)
            );
            foreach ($rows as $row) {
                $company_id = (int) ($row['charge_company_id'] ?? 0);
                if ($company_id <= 0 || $company_id === Yakap_billing::COMPANY_ID) {
                    continue;
                }
                $by_company[$company_id] = ($by_company[$company_id] ?? 0.0) + (float) ($row['amount'] ?? 0);
            }
        }

        $companies = [];
        foreach ($this->company->get_by_ids(array_keys($by_company)) as $company) {
            $companies[(int) $company['id']] = $company;
        }

        $filtered = [];
        foreach ($by_company as $company_id => $amount) {
            $company = $companies[$company_id] ?? [];
            if ($this->_ar_summary_bucket($company_id, $company['type'] ?? null, $company['classification'] ?? null) === 'yakap') {
                continue;
            }
            if ($amount == 0.0) {
                continue;
            }
            $filtered[$company_id] = $amount;
        }

        return $filtered;
    }

    private function _ar_summary_unbilled($date_from, $date_to, $branch_id = null): float
    {
        return array_sum($this->_ar_summary_unbilled_by_company($date_from, $date_to, $branch_id));
    }

    private function _accounts_receivable_unbilled($as_of_date, $branch_id = null, $company_id = null)
    {
        $year_start = date('Y-01-01', strtotime((string) $as_of_date));
        $by_company = $this->_ar_summary_unbilled_by_company($year_start, $as_of_date, $branch_id);
        if ($company_id) {
            $company_id = (int) $company_id;
            $by_company = array_key_exists($company_id, $by_company)
                ? [$company_id => $by_company[$company_id]]
                : [];
        }

        if (! $by_company) {
            return [];
        }

        $names = [];
        foreach ($this->company->get_by_ids(array_keys($by_company)) as $company) {
            $names[(int) $company['id']] = $company['name'] ?? '';
        }

        $filteredCustomers = [];
        $grandTotal = 0.0;
        foreach ($by_company as $charge_to => $amount) {
            $filteredCustomers[] = [
                'charge_to'      => $charge_to,
                'customer_name'  => $names[$charge_to] ?? '',
                'current'        => number_format($amount, 4, '.', ''),
                '1-30_days'      => number_format(0, 4, '.', ''),
                '31-60_days'     => number_format(0, 4, '.', ''),
                '61-90_days'     => number_format(0, 4, '.', ''),
                'above_90_days'  => number_format(0, 4, '.', ''),
                'total'          => number_format($amount, 4, '.', ''),
                'billings'       => [],
            ];
            $grandTotal += $amount;
        }

        usort($filteredCustomers, static function ($a, $b) {
            return (float) ($b['total'] ?? 0) <=> (float) ($a['total'] ?? 0);
        });

        $filteredCustomers[] = [
            'customer_name' => 'Totals',
            'current'       => number_format($grandTotal, 4, '.', ''),
            '1-30_days'     => number_format(0, 4, '.', ''),
            '31-60_days'    => number_format(0, 4, '.', ''),
            '61-90_days'    => number_format(0, 4, '.', ''),
            'above_90_days' => number_format(0, 4, '.', ''),
            'total'         => number_format($grandTotal, 4, '.', ''),
            'billings'      => [],
        ];

        return $filteredCustomers;
    }

    private function _ar_summary_days_sales(float $ending, float $credit_sales, float $collections_ytd, int $days): ?string
    {
        if ($credit_sales == 0.0) {
            return null;
        }

        $beginning = max(0.0, $ending - $credit_sales + $collections_ytd);

        return number_format((($ending + $beginning) / 2) / $credit_sales * $days, 1, '.', '');
    }

    private function _ar_summary_row($no, string $group, float $amount, $days_sales, $collections_today, $collections_mtd, float $grand_total): array
    {
        return [
            'no'                => $no,
            'group'             => $group,
            'amount'            => number_format($amount, 2, '.', ''),
            'days_sales'        => $days_sales,
            'sob'               => $grand_total == 0.0 ? null : number_format($amount / $grand_total * 100, 1, '.', ''),
            'collections_today' => $collections_today === null ? null : number_format((float) $collections_today, 2, '.', ''),
            'collections_mtd'   => $collections_mtd === null ? null : number_format((float) $collections_mtd, 2, '.', ''),
        ];
    }

    private function _ar_excel_to_vm4(string $file_name, string $as_of_date, callable $writer)
    {
        if (! class_exists(Spreadsheet::class)) {
            return $this->_create_response(FAIL, [
                'PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet:^1.29',
            ]);
        }

        $dir = WRITEPATH . 'accounts_receivable/';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $this->_create_response(FAIL, ['Unable to create temporary Excel directory.']);
        }

        $local_path = $dir . $file_name;
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $writer($sheet);

        $xlsx = new Xlsx($spreadsheet);
        $xlsx->save($local_path);

        $vm4_path = 'assets/financials/accounts_receivable/' . $as_of_date . '/';
        $upload = upload_to_vm4(
            new Local_upload_file($local_path, $file_name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            $vm4_path
        );

        if (is_file($local_path)) {
            @unlink($local_path);
        }

        if (! $upload || empty($upload['success']) || empty($upload['path'])) {
            return $this->_create_response(FAIL, [
                $upload['error'] ?? 'Failed to upload Excel file to VM4.',
            ]);
        }

        $file_url = rtrim(VM4_ENDPOINT, '/') . '/' . ltrim($upload['path'], '/');

        return $this->_create_response(SUCCESS, [
            'file_name' => $file_name,
            'file_url'  => $file_url,
        ]);
    }

    private function _write_accounts_receivable_summary_sheet($sheet, array $payload): void
    {
        $as_of = $payload['as_of'] ?? '';
        $sheet->setTitle('AR Summary');
        $sheet->setCellValue('A1', 'Receivables');
        $sheet->setCellValue('A2', 'As of ' . date('F j, Y', strtotime((string) $as_of)));
        $sheet->mergeCells('A1:G1');
        $sheet->mergeCells('A2:G2');

        $headers = ['No', 'Group of', 'Amount', 'Days Sales', 'SOB', 'Collections Today', 'Collections MTD'];
        $sheet->fromArray($headers, null, 'A4');
        $this->_ar_excel_style_header($sheet, 'A4:G4');

        $row = 5;
        foreach ($payload['groups'] ?? [] as $group) {
            $is_total = ($group['group'] ?? '') === 'TOTAL';
            $sheet->setCellValue('A' . $row, $group['no']);
            $sheet->setCellValue('B' . $row, $group['group'] ?? '');
            $sheet->setCellValue('C' . $row, (float) ($group['amount'] ?? 0));
            $sheet->setCellValue('D' . $row, $group['days_sales'] === null ? null : (float) $group['days_sales']);
            $sheet->setCellValue('E' . $row, $group['sob'] === null ? null : ((float) $group['sob'] / 100));
            $sheet->setCellValue('F' . $row, $group['collections_today'] === null ? null : (float) $group['collections_today']);
            $sheet->setCellValue('G' . $row, $group['collections_mtd'] === null ? null : (float) $group['collections_mtd']);
            $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('0.0');
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('0.0%');
            $sheet->getStyle('F' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($is_total) {
                $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
            }
            $row++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('A1:A2')->getFont()->setBold(true);
        $sheet->getStyle('A1:G2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function _write_accounts_receivable_sheet($sheet, array $customers, string $as_of_date, $type): void
    {
        $title = $type ? ('AR ' . str_replace('_', ' ', $type)) : 'Accounts Receivable';
        $sheet->setTitle('AR');
        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', 'As of ' . date('F j, Y', strtotime($as_of_date)));
        $sheet->mergeCells('A1:G1');
        $sheet->mergeCells('A2:G2');

        $headers = ['Customer', 'Current', '1 to 30 Days', '31 to 60 Days', '61 to 90 Days', 'Above 90 Days', 'Total'];
        $sheet->fromArray($headers, null, 'A4');
        $this->_ar_excel_style_header($sheet, 'A4:G4');

        $row = 5;
        foreach ($customers as $customer) {
            $is_total = ($customer['customer_name'] ?? '') === 'Totals';
            $sheet->setCellValue('A' . $row, $customer['customer_name'] ?? '');
            $sheet->setCellValue('B' . $row, (float) ($customer['current'] ?? 0));
            $sheet->setCellValue('C' . $row, (float) ($customer['1-30_days'] ?? 0));
            $sheet->setCellValue('D' . $row, (float) ($customer['31-60_days'] ?? 0));
            $sheet->setCellValue('E' . $row, (float) ($customer['61-90_days'] ?? 0));
            $sheet->setCellValue('F' . $row, (float) ($customer['above_90_days'] ?? 0));
            $sheet->setCellValue('G' . $row, (float) ($customer['total'] ?? 0));
            $sheet->getStyle('B' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($is_total) {
                $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
            }
            $row++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('A1:A2')->getFont()->setBold(true);
        $sheet->getStyle('A1:G2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function _ar_excel_style_header($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E79'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
    }
}
