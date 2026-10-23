<?php
namespace App\Core;

namespace App\Models;

use App\Core\MYT_Model;
use App\Models\Billing;
use App\Models\Booking_all;
use App\Models\Company;
use App\Models\Yakap_billing;

class Billed_and_sales extends MYT_Model
{
    private $booking_all;
    private $company;

    public function __construct()
    {
        parent::__construct();
        $this->booking_all = new Booking_all();
        $this->company     = new Company();
    }

    public function get_allowed_tabs()
    {
        return [
            'sales_and_billing',
            'mode_of_payment',
            'billing',
            'billing_consolacion',
            'billing_mantlewood',
            'billing_mactan',
            'billing_oakridge',
            'billing_mobile',
        ];
    }

    public function get_tab_branch_key($tab)
    {
        $map = [
            'billing_consolacion' => 'consolacion',
            'billing_mactan'      => 'mactan',
            'billing_oakridge'    => 'oakridge',
            'billing_mobile'      => 'mobile',
            'billing_mantlewood'  => 'mantlewood',
        ];

        return $map[$tab] ?? null;
    }

    public function normalize_mop_branch_id($branch_id)
    {
        if ($branch_id === null || $branch_id === '') {
            return null;
        }

        $branch_id = (int) $branch_id;
        if (! isset($this->_branch_db_map()[$branch_id])) {
            return false;
        }

        return $branch_id;
    }

    public function normalize_mop_payment_term($payment_term)
    {
        if ($payment_term === null || $payment_term === '') {
            return null;
        }

        $term_labels = [
            'cash'           => 'cash',
            'debit'          => 'debit',
            'credit'         => 'credit',
            'c wallet'       => 'c wallet',
            'cwallet'        => 'c wallet',
            'company_wallet' => 'c wallet',
            'hmo'            => 'hmo',
            'corporate'      => 'corporate',
            'yakap konsulta' => 'yakap konsulta',
            'yakap'          => 'yakap konsulta',
            'konsulta'       => 'yakap konsulta',
        ];

        $key = strtolower(trim((string) $payment_term));
        if (isset($term_labels[$key])) {
            return $term_labels[$key];
        }

        $ewallet_labels = $this->_load_ewallet_labels();
        if (isset($ewallet_labels[$key])) {
            return $key;
        }

        return false;
    }

    /**
     * Lowercased ewallet name => display name from branch diagnostic DB(s).
     *
     * @return array<string, string>
     */
    private function _load_ewallet_labels($branch_id = null)
    {
        $labels     = [];
        $branch_map = $this->_branch_db_map();
        if ($branch_id !== null) {
            $branch_map = [
                (int) $branch_id => $branch_map[(int) $branch_id],
            ];
        }

        foreach ($branch_map as $meta) {
            $db = db_connect($meta['name']);
            foreach ($this->booking_all->get_ewallets_on_connection($db) as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $key = strtolower($name);
                if (! isset($labels[$key])) {
                    $labels[$key] = $name;
                }
            }
        }

        return $labels;
    }

    public function get($tab, $date_from, $date_to, $company_id = null, $sales_executive_id = null, $debug = false, $branch_id = null, $payment_term = null)
    {
        if ($tab === 'mode_of_payment') {
            $context = $this->_collect_mode_of_payment_context(
                $date_from,
                $date_to,
                $company_id,
                $sales_executive_id,
                $debug,
                $branch_id
            );
            if ($debug) {
                return $context;
            }

            return $this->_format_mode_of_payment($context, $date_to, $payment_term);
        }

        $context = $this->_collect_branch_context($date_from, $date_to, $company_id, $sales_executive_id, $debug);
        if ($debug) {
            return $context;
        }

        switch ($tab) {
            case 'sales_and_billing':
                return $this->_format_sales_and_billing($context, $date_to);
            case 'billing':
                return $this->_format_billing_all_branches($context, $date_to);
            default:
                $branch_key = $this->get_tab_branch_key($tab);
                if ($branch_key === null) {
                    return [];
                }

                return $this->_format_billing_single_branch($context, $date_to, $branch_key, $tab === 'billing_mobile');
        }
    }

    private function _branch_db_map()
    {
        return [
            1 => ['key' => 'mobile', 'name' => 'mobile'],
            2 => ['key' => 'oakridge', 'name' => 'oakridge'],
            3 => ['key' => 'mactan', 'name' => 'mactan'],
            4 => ['key' => 'consolacion', 'name' => 'consolacion'],
            7 => ['key' => 'mantlewood', 'name' => 'mantlewood'],
        ];
    }

    private function _branch_id_by_key()
    {
        $map = [];
        foreach ($this->_branch_db_map() as $branch_id => $meta) {
            $map[$meta['key']] = (int) $branch_id;
        }

        return $map;
    }

    private function _empty_branch_amounts()
    {
        $amounts = [];
        foreach ($this->_branch_db_map() as $meta) {
            $amounts[$meta['key']] = 0.0;
        }
        $amounts['total'] = 0.0;

        return $amounts;
    }

    private function _collect_branch_context($date_from, $date_to, $company_id, $sales_executive_id, $debug = false)
    {
        $sales_by_branch_charge       = [];
        $billed_by_branch_charge      = [];
        $billed_origin_by_branch      = [];
        $company_ids                  = [];
        $debug_output                 = [];
        $closed_bill_by_branch_charge = [];
        $closed_bill_origin_by_branch = [];

        foreach ($this->_branch_db_map() as $branch_id => $meta) {
            $branch_key = $meta['key'];
            $db         = db_connect($meta['name']);
            $billing    = new Billing();

            $standard_rows    = $this->booking_all->get_unbilled_sales_by_charge_on_connection($db, $date_from, $date_to, $company_id, $sales_executive_id);
            $copay_rows       = $this->booking_all->get_copay_sales_by_hmo_on_connection($db, $date_from, $date_to, $company_id, $sales_executive_id);
            $billed_rows      = $billing->get_billed_totals_by_charge($date_from, $date_to, true, $company_id, $sales_executive_id, 'billed');
            $closed_bill_rows = $billing->get_billed_totals_by_charge($date_from, $date_to, true, $company_id, $sales_executive_id, 'closed_bill');

            if ($debug) {
                $debug_output[] = [
                    'branch' => $branch_key,
                    'stage'  => 'unbilled_standard',
                    'count'  => count($standard_rows),
                ];
                $debug_output[] = [
                    'branch' => $branch_key,
                    'stage'  => 'unbilled_copay',
                    'count'  => count($copay_rows),
                ];
                $debug_output[] = [
                    'branch' => $branch_key,
                    'stage'  => 'billed',
                    'count'  => count($billed_rows),
                ];
                continue;
            }

            if (! isset($sales_by_branch_charge[$branch_key])) {
                $sales_by_branch_charge[$branch_key] = [];
            }

            foreach (array_merge($standard_rows, $copay_rows) as $row) {
                $charge_id = (int) ($row['charge_company_id'] ?? 0);
                if ($charge_id <= 0) {
                    continue;
                }
                $company_ids[] = $charge_id;
                if (! isset($sales_by_branch_charge[$branch_key][$charge_id])) {
                    $sales_by_branch_charge[$branch_key][$charge_id] = 0.0;
                }
                $sales_by_branch_charge[$branch_key][$charge_id] += (float) ($row['amount'] ?? 0);
            }

            if (! isset($billed_by_branch_charge[$branch_key])) {
                $billed_by_branch_charge[$branch_key] = [];
            }
            if (! isset($billed_origin_by_branch[$branch_key])) {
                $billed_origin_by_branch[$branch_key] = [];
            }

            foreach ($billed_rows as $row) {
                $charge_id  = (int) ($row['charge_company_id'] ?? 0);
                $origin_id  = (int) ($row['branch_id'] ?? $branch_id);
                if ($origin_id !== (int) $branch_id) {
                    continue;
                }
                $origin_key = $this->_branch_key_from_id($origin_id) ?? $branch_key;
                $amount     = -1 * abs((float) ($row['amount'] ?? 0));

                if ($charge_id <= 0) {
                    continue;
                }

                $company_ids[] = $charge_id;
                if (! isset($billed_by_branch_charge[$branch_key][$charge_id])) {
                    $billed_by_branch_charge[$branch_key][$charge_id] = 0.0;
                }
                $billed_by_branch_charge[$branch_key][$charge_id] += $amount;

                if (! isset($billed_origin_by_branch[$branch_key][$origin_key])) {
                    $billed_origin_by_branch[$branch_key][$origin_key] = [];
                }
                if (! isset($billed_origin_by_branch[$branch_key][$origin_key][$charge_id])) {
                    $billed_origin_by_branch[$branch_key][$origin_key][$charge_id] = 0.0;
                }
                $billed_origin_by_branch[$branch_key][$origin_key][$charge_id] += $amount;
            }

            foreach ($closed_bill_rows as $row) {
                $charge_id  = (int) ($row['charge_company_id'] ?? 0);
                $origin_id  = (int) ($row['branch_id'] ?? $branch_id);
                if ($origin_id !== (int) $branch_id) {
                    continue;
                }
                $origin_key = $this->_branch_key_from_id($origin_id) ?? $branch_key;
                $amount     = -1 * abs((float) ($row['amount'] ?? 0));

                if ($charge_id <= 0) {
                    continue;
                }

                $company_ids[] = $charge_id;
                if (! isset($closed_bill_by_branch_charge[$branch_key][$charge_id])) {
                    $closed_bill_by_branch_charge[$branch_key][$charge_id] = 0.0;
                }
                $closed_bill_by_branch_charge[$branch_key][$charge_id] += $amount;

                if (! isset($closed_bill_origin_by_branch[$branch_key][$origin_key])) {
                    $closed_bill_origin_by_branch[$branch_key][$origin_key] = [];
                }
                if (! isset($closed_bill_origin_by_branch[$branch_key][$origin_key][$charge_id])) {
                    $closed_bill_origin_by_branch[$branch_key][$origin_key][$charge_id] = 0.0;
                }
                $closed_bill_origin_by_branch[$branch_key][$origin_key][$charge_id] += $amount;
            }
        }

        if ($debug) {
            return ['debug' => $debug_output];
        }

        $company_map = $this->_fetch_company_map($company_ids);

        return [
            'sales_by_branch_charge'       => $sales_by_branch_charge,
            'billed_by_branch_charge'      => $billed_by_branch_charge,
            'billed_origin_by_branch'      => $billed_origin_by_branch,
            'company_map'                  => $company_map,
            'closed_bill_by_branch_charge' => $closed_bill_by_branch_charge,
            'closed_bill_origin_by_branch' => $closed_bill_origin_by_branch,
        ];
    }

    private function _branch_key_from_id($branch_id)
    {
        foreach ($this->_branch_db_map() as $id => $meta) {
            if ((int) $id === (int) $branch_id) {
                return $meta['key'];
            }
        }

        return null;
    }

    private function _fetch_company_map(array $company_ids)
    {
        $rows = $this->company->get_by_ids($company_ids) ?: [];
        $map  = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    private function _classify_account_type(array $company = null, $company_name = '')
    {
        $name = strtoupper((string) ($company['name'] ?? $company_name));
        if (str_contains($name, 'KONSULTA')) {
            return 'konsulta';
        }
        if (str_contains($name, 'YAKAP')) {
            return 'yakap';
        }
        if (! empty($company['is_hmo']) && (int) $company['is_hmo'] === 1) {
            return 'hmo';
        }

        return 'corporate';
    }

    private function _company_name(array $company_map, $charge_id)
    {
        $charge_id = (int) $charge_id;
        if (isset($company_map[$charge_id]['name'])) {
            return $company_map[$charge_id]['name'];
        }

        return 'UNKNOWN';
    }

    private function _sum_branch_map(array $branch_values)
    {
        $result = $this->_empty_branch_amounts();
        foreach ($branch_values as $branch_key => $value) {
            if (! isset($result[$branch_key])) {
                continue;
            }
            $result[$branch_key] = round((float) $value, 2);
            $result['total'] += $result[$branch_key];
        }
        $result['total'] = round($result['total'], 2);

        return $result;
    }

    private function _calc_unbilled_pct(array $sales, array $unbilled)
    {
        $pct = $this->_empty_branch_amounts();
        foreach ($sales as $key => $sales_value) {
            if ($key === 'total') {
                $pct['total'] = $sales['total'] > 0 ? round($unbilled['total'] / $sales['total'], 4) : 0;
                continue;
            }
            $pct[$key] = $sales_value > 0 ? round($unbilled[$key] / $sales_value, 4) : 0;
        }

        return $pct;
    }

    private function _format_sales_and_billing(array $context, $as_of)
    {
        $overall_sales       = $this->_empty_branch_amounts();
        $overall_billed      = $this->_empty_branch_amounts();
        $hmo_sales           = $this->_empty_branch_amounts();
        $hmo_billed          = $this->_empty_branch_amounts();
        $corporate_sales     = $this->_empty_branch_amounts();
        $corporate_billed    = $this->_empty_branch_amounts();
        $overall_closed_bill = $this->_empty_branch_amounts();

        foreach ($this->_branch_db_map() as $meta) {
            $branch_key = $meta['key'];
            foreach ($context['sales_by_branch_charge'][$branch_key] ?? [] as $charge_id => $amount) {
                $company = $context['company_map'][$charge_id] ?? null;
                $bucket  = $this->_classify_account_type($company);
                $overall_sales[$branch_key] += $amount;
                if ($bucket === 'hmo') {
                    $hmo_sales[$branch_key] += $amount;
                } elseif ($bucket === 'corporate') {
                    $corporate_sales[$branch_key] += $amount;
                }
            }

            foreach ($context['billed_by_branch_charge'][$branch_key] ?? [] as $charge_id => $amount) {
                $company = $context['company_map'][$charge_id] ?? null;
                $bucket  = $this->_classify_account_type($company);
                $overall_billed[$branch_key] += $amount;
                if ($bucket === 'hmo') {
                    $hmo_billed[$branch_key] += $amount;
                } elseif ($bucket === 'corporate') {
                    $corporate_billed[$branch_key] += $amount;
                }
            }

            foreach ($context['closed_bill_by_branch_charge'][$branch_key] ?? [] as $charge_id => $amount) {
                // $company     = $context['company_map'][$charge_id] ?? null;
                // $bucket      = $this->_classify_account_type($company);
                $overall_billed[$branch_key] += $amount;
                // if ($bucket === 'hmo') {
                //     $hmo_billed[$branch_key] += $amount;
                // } elseif ($bucket === 'corporate') {
                //     $corporate_billed[$branch_key] += $amount;
                // }
            }
        }

        foreach (['overall_sales', 'overall_billed', 'hmo_sales', 'hmo_billed', 'corporate_sales', 'corporate_billed'] as $var) {
            $$var = $this->_sum_branch_map($$var);
        }

        $overall_unbilled   = $this->_sum_branch_map($this->_combine_branch_maps($overall_sales, $overall_billed));
        $hmo_unbilled       = $this->_sum_branch_map($this->_combine_branch_maps($hmo_sales, $hmo_billed));
        $corporate_unbilled = $this->_sum_branch_map($this->_combine_branch_maps($corporate_sales, $corporate_billed));

        return [
            'as_of'     => $as_of,
            'overall'   => [
                'sales'        => $overall_sales,
                'billed'       => $overall_billed,
                'unbilled'     => $overall_unbilled,
                'unbilled_pct' => $this->_calc_unbilled_pct($overall_sales, $overall_unbilled),
                'collected'    => $overall_closed_bill,
            ],
            'hmo'       => [
                'sales'        => $hmo_sales,
                'billed'       => $hmo_billed,
                'unbilled'     => $hmo_unbilled,
                'unbilled_pct' => $this->_calc_unbilled_pct($hmo_sales, $hmo_unbilled),
                'collected'    => $overall_closed_bill,
            ],
            'corporate' => [
                'sales'        => $corporate_sales,
                'billed'       => $corporate_billed,
                'unbilled'     => $corporate_unbilled,
                'unbilled_pct' => $this->_calc_unbilled_pct($corporate_sales, $corporate_unbilled),
                'collected'    => $overall_closed_bill,
            ],
        ];
    }

    private function _combine_branch_maps(array $left, array $right)
    {
        $result = [];
        foreach ($left as $key => $value) {
            $result[$key] = (float) $value + (float) ($right[$key] ?? 0);
        }

        return $result;
    }

    private function _collect_mode_of_payment_context($date_from, $date_to, $company_id, $sales_executive_id, $debug = false, $branch_id = null)
    {
        $allocations_by_branch = [];
        $yakap_by_branch       = [];
        $ewallet_labels        = [];
        $debug_output          = [];
        $branch_config         = new \Config\Branch();

        $branch_map = $this->_branch_db_map();
        if ($branch_id !== null) {
            $branch_map = [
                (int) $branch_id => $branch_map[(int) $branch_id],
            ];
        }

        foreach ($branch_map as $map_branch_id => $meta) {
            $branch_key = $meta['key'];
            $db         = db_connect($meta['name']);
            $raw_rows   = $this->booking_all->get_mode_of_payment_lines_on_connection(
                $db,
                $date_from,
                $date_to,
                $company_id,
                $sales_executive_id
            );
            $payment_only_rows = $this->booking_all->get_mode_of_payment_payment_only_on_connection(
                $db,
                $date_from,
                $date_to,
                $company_id,
                $sales_executive_id
            );
            if (! empty($payment_only_rows)) {
                $raw_rows = array_merge($raw_rows, $payment_only_rows);
            }

            $ewallet_map = [];
            foreach ($this->booking_all->get_ewallets_on_connection($db) as $ewallet) {
                $name = trim((string) ($ewallet['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $key = strtolower($name);
                $ewallet_map[$key] = $key;
                if (! isset($ewallet_labels[$key])) {
                    $ewallet_labels[$key] = $name;
                }
            }

            $transaction_ids = [];
            $booking_ids     = [];
            foreach ($raw_rows as $row) {
                $tid = (int) ($row['transaction_id'] ?? 0);
                if ($tid > 0) {
                    $transaction_ids[] = $tid;
                }
                $bid = (int) ($row['booking_id'] ?? 0);
                if ($bid > 0) {
                    $booking_ids[] = $bid;
                }
            }
            $discounts_by_transaction = $this->booking_all->get_transaction_discounts_on_connection($db, $transaction_ids);
            $medicines_by_booking = $this->booking_all->get_booking_medicines_on_connection($db, $booking_ids);
            $allocations_by_branch[$branch_key] = $this->_allocate_mode_of_payment_lines(
                $raw_rows,
                $discounts_by_transaction,
                $medicines_by_booking,
                $ewallet_map
            );

            $emr_db_name = $branch_config->getEmrDB($map_branch_id);
            $yakap_rows  = [];
            // philhealth_report has no company/charge_to; company filter zeros Yakap.
            if (! $company_id && $emr_db_name) {
                $emr_db     = db_connect($emr_db_name);
                $yakap_rows = $this->booking_all->get_yakap_konsulta_lines_on_connection($emr_db, $date_from, $date_to);
            }
            $yakap_by_branch[$branch_key] = $yakap_rows;

            if ($debug) {
                $debug_output[] = [
                    'branch'      => $branch_key,
                    'raw_count'   => count($raw_rows),
                    'alloc_count' => count($allocations_by_branch[$branch_key]),
                    'yakap_count' => count($yakap_rows),
                    'ewallets'    => array_values($ewallet_map),
                ];
            }
        }

        if ($debug) {
            return ['debug' => $debug_output];
        }

        return [
            'allocations_by_branch' => $allocations_by_branch,
            'yakap_by_branch'       => $yakap_by_branch,
            'ewallet_labels'        => $ewallet_labels,
        ];
    }

    /**
     * Classify and proportionally split booking_details amounts across payment methods.
     *
     * @param array<int, array> $discounts_by_transaction
     * @param array<int, array> $medicines_by_booking
     * @return array<int, array{term: string, amount: float, detail: array}>
     */
    private function _allocate_mode_of_payment_lines(
        array $raw_rows,
        array $discounts_by_transaction = [],
        array $medicines_by_booking = [],
        array $ewallet_map = []
    ) {
        $by_detail = [];
        foreach ($raw_rows as $row) {
            $bd_id = (int) ($row['booking_details_id'] ?? 0);
            if ($bd_id > 0) {
                $by_detail[$bd_id][] = $row;
                continue;
            }

            // Payment-only fallback (consultation / fully transferred-out): group by transaction.
            $tid = (int) ($row['transaction_id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $by_detail['pay_' . $tid][] = $row;
        }

        $allocated = [];
        foreach ($by_detail as $rows) {
            $base            = $rows[0];
            $detail_amount   = (float) ($base['amount'] ?? 0);
            $transaction_id  = (int) ($base['transaction_id'] ?? 0);
            $booking_id      = (int) ($base['booking_id'] ?? 0);
            $base_detail     = [
                'transaction_id'             => $base['transaction_id'] ?? null,
                'patient_id'                 => $base['patient_id'] ?? null,
                'patient_name'               => trim((string) ($base['patient_name'] ?? '')),
                'transaction_type'           => $base['transaction_type'] ?? null,
                'assessment_type'            => $base['assessment_type'] ?? null,
                'transaction_no'             => $base['transaction_no'] ?? null,
                'type'                       => $base['type'] ?? null,
                'test_id'                    => $base['test_id'] ?? null,
                'test_name'                  => $base['test_name'] ?? null,
                'group_id'                   => $base['group_id'] ?? null,
                'group_name'                 => $base['group_name'] ?? null,
                'status'                     => $base['status'] ?? null,
                'charge_to'                  => $base['charge_to'] ?? null,
                'company_name'               => $base['company_name'] ?? null,
                'booking_company_name'       => $base['booking_company_name'] ?? null,
                'booking_details_booking_id' => $base['booking_details_id'] ?? ($base['booking_id'] ?? null),
                'is_billed_externally'       => isset($base['is_billed_externally'])
                    ? (int) $base['is_billed_externally']
                    : null,
                'payment_details'            => [],
                'discount'                   => $transaction_id > 0
                    ? ($discounts_by_transaction[$transaction_id] ?? [])
                    : [],
                'booking_medicines'          => $booking_id > 0
                    ? ($medicines_by_booking[$booking_id] ?? [])
                    : [],
                'grand_total'                => isset($base['transaction_grand_total'])
                    ? round((float) $base['transaction_grand_total'], 2)
                    : null,
                'transaction_grand_total'    => isset($base['transaction_grand_total'])
                    ? round((float) $base['transaction_grand_total'], 2)
                    : null,
                'package_id'                 => $base['package_id'] ?? null,
            ];

            if ($this->_is_yakap_charge_to($base['charge_to'] ?? null)) {
                $detail           = $base_detail;
                $detail['payment_details'] = $this->_billing_payment_details_from_rows($rows);
                $detail['amount'] = round($detail_amount, 2);
                $allocated[]      = [
                    'term'   => 'yakap konsulta',
                    'amount' => $detail_amount,
                    'detail' => $detail,
                ];
                continue;
            }

            // External bill with charge_to always goes to HMO/Corporate (ignore payment methods).
            if ($this->_is_external_bill_line($base)) {
                $term = ((int) ($base['is_hmo'] ?? 0) === 1) ? 'hmo' : 'corporate';
                $detail           = $base_detail;
                $detail['payment_details'] = $this->_billing_payment_details_from_rows($rows);
                $detail['amount'] = round($detail_amount, 2);
                $allocated[]      = [
                    'term'   => $term,
                    'amount' => $detail_amount,
                    'detail' => $detail,
                ];
                continue;
            }

            $pmm_rows = [];
            foreach ($rows as $row) {
                $pmm_id = (int) ($row['pmm_id'] ?? 0);
                if ($pmm_id > 0) {
                    $pmm_rows[$pmm_id] = $row;
                }
            }

            if (! empty($pmm_rows)) {
                $pmm_total = (float) ($base['payment_pmm_total'] ?? 0);
                if ($pmm_total <= 0) {
                    $pmm_total = 0.0;
                    foreach ($pmm_rows as $row) {
                        $pmm_total += (float) ($row['pmm_amount'] ?? 0);
                    }
                }

                foreach ($pmm_rows as $row) {
                    $term = $this->_payment_method_term($row, $ewallet_map);
                    if ($term === null) {
                        continue;
                    }
                    $share = $pmm_total > 0
                        ? ((float) ($row['pmm_amount'] ?? 0) / $pmm_total)
                        : 0.0;
                    $amount              = $detail_amount * $share;
                    $detail              = $base_detail;
                    $detail['payment_details'] = [
                        [
                            'payment_id'             => $row['payment_id'] ?? null,
                            'pmm_id'                 => $row['pmm_id'] ?? null,
                            'type'                   => $row['pmm_type'] ?? null,
                            'other_payment_details'  => $row['other_payment_details'] ?? null,
                            'charge_to'              => $row['payment_charge_to'] ?? null,
                            'charge_to_name'         => $row['payment_charge_to_name'] ?? null,
                            'amount'                 => isset($row['pmm_amount'])
                                ? round((float) $row['pmm_amount'], 2)
                                : null,
                        ],
                    ];
                    $detail['amount']    = round($amount, 2);
                    $allocated[]         = [
                        'term'   => $term,
                        'amount' => $amount,
                        'detail' => $detail,
                    ];
                }
                continue;
            }

            $term = $this->_fallback_payment_term($base);
            if ($term === null) {
                continue;
            }
            $detail           = $base_detail;
            $detail['amount'] = round($detail_amount, 2);
            $allocated[]      = [
                'term'   => $term,
                'amount' => $detail_amount,
                'detail' => $detail,
            ];
        }

        foreach ($allocated as &$item) {
            $term   = $item['term'] ?? null;
            $detail = $item['detail'] ?? [];
            $company_name = $term === 'c wallet'
                ? ($detail['booking_company_name'] ?? '')
                : ($detail['company_name'] ?? '');
            $detail['patient_name'] = $this->_mop_patient_name_with_company(
                $detail['patient_name'] ?? '',
                $term,
                $company_name
            );
            unset($detail['booking_company_name']);
            $item['detail'] = $detail;
        }
        unset($item);

        return $allocated;
    }

    private function _mop_patient_name_with_company($patient_name, $term, $company_name)
    {
        $patient_name = trim((string) $patient_name);
        $company_name = trim((string) $company_name);
        if (
            $patient_name === ''
            || $company_name === ''
            || ! in_array($term, ['c wallet', 'hmo', 'corporate'], true)
        ) {
            return $patient_name;
        }

        return $patient_name . ' (' . $company_name . ')';
    }

    private function _is_external_bill_line(array $row)
    {
        if ((int) ($row['is_billed_externally'] ?? 0) !== 1) {
            return false;
        }

        $charge_to = $row['charge_to'] ?? null;

        return ! ($charge_to === null || $charge_to === '' || (int) $charge_to === 0);
    }

    private function _billing_payment_details_from_rows(array $rows)
    {
        $details = [];
        foreach ($rows as $row) {
            $billing_entry_id = (int) ($row['billing_entry_id'] ?? 0);
            $billing_id       = (int) ($row['billing_id'] ?? 0);
            if ($billing_entry_id <= 0 || $billing_id <= 0) {
                continue;
            }
            if (isset($details[$billing_entry_id])) {
                continue;
            }
            $details[$billing_entry_id] = [
                'billing_entry_id'       => $billing_entry_id,
                'billing_id'             => $billing_id,
                'branch_id'              => $row['billing_entry_branch_id'] ?? null,
                'company_id'             => $row['billing_entry_company_id'] ?? null,
                'booking_id'             => $row['billing_entry_booking_id'] ?? ($row['booking_id'] ?? null),
                'transaction_id'         => $row['billing_entry_transaction_id'] ?? ($row['transaction_id'] ?? null),
                'billing_entry_amount'   => isset($row['billing_entry_amount'])
                    ? round((float) $row['billing_entry_amount'], 2)
                    : null,
                'billing_entry_balance'  => isset($row['billing_entry_balance'])
                    ? round((float) $row['billing_entry_balance'], 2)
                    : null,
                'billing_entry_status'   => $row['billing_entry_status'] ?? null,
                'charge_to'              => $row['billing_charge_to'] ?? null,
                'due_date'               => $row['due_date'] ?? null,
                'billing_status'         => $row['billing_status'] ?? null,
                'grand_total'            => isset($row['grand_total'])
                    ? round((float) $row['grand_total'], 2)
                    : null,
                'invoice_id'             => $row['invoice_id'] ?? null,
                'invoice_status'         => $row['invoice_status'] ?? null,
                'date_from'              => $row['date_from'] ?? null,
                'date_to'                => $row['date_to'] ?? null,
            ];
        }

        return array_values($details);
    }

    private function _payment_method_term(array $row, array $ewallet_map = [])
    {
        $type = strtolower((string) ($row['pmm_type'] ?? ''));
        if ($type === 'cash') {
            return 'cash';
        }
        if ($type === 'card') {
            $other = strtolower((string) ($row['other_payment_details'] ?? ''));
            if (str_contains($other, 'debit')) {
                return 'debit';
            }

            return 'credit';
        }
        if ($type === 'company_wallet') {
            return 'c wallet';
        }
        if ($type === 'others' || $type === 'other') {
            $other = strtolower(trim((string) ($row['other_payment_details'] ?? '')));
            if ($other !== '' && isset($ewallet_map[$other])) {
                return $ewallet_map[$other];
            }

            return null;
        }

        return null;
    }

    private function _is_yakap_charge_to($charge_to)
    {
        return $charge_to !== null && $charge_to !== '' && (int) $charge_to === Yakap_billing::COMPANY_ID;
    }

    private function _fallback_payment_term(array $row)
    {
        $charge_to = $row['charge_to'] ?? null;
        if ($this->_is_yakap_charge_to($charge_to)) {
            return 'yakap konsulta';
        }
        if ($charge_to === null || $charge_to === '' || (int) $charge_to === 0) {
            return 'cash';
        }

        if ((int) ($row['is_billed_externally'] ?? 0) === 1) {
            return ((int) ($row['is_hmo'] ?? 0) === 1) ? 'hmo' : 'corporate';
        }

        return 'cash';
    }

    /**
     * Collapse per-booking_details mop detail rows into one object per transaction_id.
     *
     * @param array<int, array> $details
     * @return array<int, array>
     */
    private function _group_mop_details_by_transaction(array $details)
    {
        $grouped = [];

        foreach ($details as $detail) {
            $transaction_id = (string) ($detail['transaction_id'] ?? '');
            if ($transaction_id === '' || $transaction_id === '0') {
                $transaction_id = 'line_' . (string) ($detail['booking_details_booking_id'] ?? uniqid('', true));
            }

            if (! isset($grouped[$transaction_id])) {
                $grouped[$transaction_id] = [
                    'transaction_id'      => $detail['transaction_id'] ?? null,
                    'patient_id'          => $detail['patient_id'] ?? null,
                    'patient_name'        => $detail['patient_name'] ?? null,
                    'transaction_no'      => $detail['transaction_no'] ?? null,
                    'status'              => $detail['status'] ?? null,
                    'payment_details'     => $detail['payment_details'] ?? [],
                    'discount'            => $detail['discount'] ?? [],
                    'booking_medicines'   => [],
                    'transaction_details' => [],
                    'amount'              => 0.0,
                    'grand_total'         => isset($detail['grand_total'])
                        ? round((float) $detail['grand_total'], 2)
                        : null,
                    'transaction_grand_total' => $this->_mop_transaction_grand_total($detail),
                    '_medicine_map'       => [],
                    '_payment_map'        => [],
                ];

                foreach ($detail['payment_details'] ?? [] as $payment) {
                    $pmm_id = (int) ($payment['pmm_id'] ?? $payment['payment_id'] ?? 0);
                    $pkey   = $pmm_id > 0 ? (string) $pmm_id : md5(json_encode($payment));
                    $grouped[$transaction_id]['_payment_map'][$pkey] = $payment;
                }
            } else {
                if (
                    ($grouped[$transaction_id]['grand_total'] === null || $grouped[$transaction_id]['grand_total'] === '')
                    && isset($detail['grand_total'])
                ) {
                    $grouped[$transaction_id]['grand_total'] = round((float) $detail['grand_total'], 2);
                }
                if ($grouped[$transaction_id]['transaction_grand_total'] === null) {
                    $grouped[$transaction_id]['transaction_grand_total'] = $this->_mop_transaction_grand_total($detail);
                }
            }

            $line_amount = (float) ($detail['amount'] ?? 0);
            $package_id  = $detail['package_id'] ?? null;
            $test_id     = $detail['test_id'] ?? null;
            $line_type   = strtolower((string) ($detail['type'] ?? ''));
            $keep_line   = $test_id !== null && $test_id !== '' && (string) $test_id !== '0';
            if ($line_type === 'consultation') {
                $keep_line = true;
            }
            if (
                $keep_line
                && ($package_id === null || $package_id === '' || (int) $package_id === 0)
            ) {
                $grouped[$transaction_id]['transaction_details'][] = [
                    'transaction_type'           => $detail['transaction_type'] ?? null,
                    'assessment_type'            => $detail['assessment_type'] ?? null,
                    'type'                       => $detail['type'] ?? null,
                    'test_id'                    => $detail['test_id'] ?? null,
                    'test_name'                  => $detail['test_name'] ?? null,
                    'group_id'                   => $detail['group_id'] ?? null,
                    'group_name'                 => $detail['group_name'] ?? null,
                    'status'                     => $detail['status'] ?? null,
                    'charge_to'                  => $detail['charge_to'] ?? null,
                    'company_name'               => $detail['company_name'] ?? null,
                    'booking_details_booking_id' => $detail['booking_details_booking_id'] ?? null,
                    'is_billed_externally'       => $detail['is_billed_externally'] ?? null,
                    'amount'                     => round($line_amount, 2),
                ];
            }
            $grouped[$transaction_id]['amount'] += $line_amount;

            foreach ($detail['booking_medicines'] ?? [] as $medicine) {
                $medicine_id = (int) ($medicine['id'] ?? 0);
                if ($medicine_id > 0) {
                    $grouped[$transaction_id]['_medicine_map'][$medicine_id] = $medicine;
                } else {
                    $grouped[$transaction_id]['_medicine_map'][] = $medicine;
                }
            }

            foreach ($detail['payment_details'] ?? [] as $payment) {
                $pmm_id = (int) ($payment['pmm_id'] ?? $payment['payment_id'] ?? 0);
                $pkey   = $pmm_id > 0 ? (string) $pmm_id : md5(json_encode($payment));
                $grouped[$transaction_id]['_payment_map'][$pkey] = $payment;
            }

            if (empty($grouped[$transaction_id]['discount']) && ! empty($detail['discount'])) {
                $grouped[$transaction_id]['discount'] = $detail['discount'];
            }
        }

        $result = [];
        foreach ($grouped as $row) {
            $result[] = [
                'transaction_id'      => $row['transaction_id'],
                'patient_id'          => $row['patient_id'],
                'patient_name'        => $row['patient_name'],
                'transaction_no'      => $row['transaction_no'],
                'status'              => $row['status'],
                'payment_details'     => array_values($row['_payment_map'] ?? []),
                'discount'            => $row['discount'] ?? [],
                'discount_total'      => $this->_mop_discount_total($row['discount'] ?? []),
                'booking_medicines'   => array_values($row['_medicine_map'] ?? []),
                'transaction_details' => $row['transaction_details'] ?? [],
                'amount'              => round((float) $row['amount'], 2),
                'grand_total'         => isset($row['grand_total'])
                    ? round((float) $row['grand_total'], 2)
                    : null,
                'transaction_grand_total' => $row['transaction_grand_total'] !== null
                    ? round((float) $row['transaction_grand_total'], 2)
                    : null,
            ];
        }

        return $result;
    }

    /**
     * Tender terms (cash/card/ewallet) use payment_details.amount for amount and grand_total.
     *
     * @param array<int, array> $grouped
     * @return array<int, array>
     */
    private function _apply_tender_payment_amounts(array $grouped)
    {
        foreach ($grouped as &$txn) {
            $payments = $txn['payment_details'] ?? [];
            if ($payments === []) {
                continue;
            }
            $paid = 0.0;
            foreach ($payments as $payment) {
                $paid += (float) ($payment['amount'] ?? 0);
            }
            $paid                 = round($paid, 2);
            $txn['amount']        = $paid;
            $txn['grand_total']   = $paid;
        }
        unset($txn);

        return $grouped;
    }

    /**
     * HMO/Corporate breakdown must use the term allocation, not transaction.grand_total.
     *
     * @param array<int, array> $grouped
     * @return array<int, array>
     */
    private function _apply_allocated_term_grand_totals(array $grouped)
    {
        foreach ($grouped as &$txn) {
            $txn['grand_total'] = round((float) ($txn['amount'] ?? 0), 2);
        }
        unset($txn);

        return $grouped;
    }

    private function _format_mode_of_payment(array $context, $as_of, $payment_term = null)
    {
        $term_labels = [
            'cash'           => 'Cash',
            'debit'          => 'Debit',
            'credit'         => 'Credit',
            'c wallet'       => 'C Wallet',
            'hmo'            => 'HMO',
            'corporate'      => 'Corporate',
            'yakap konsulta' => 'Yakap Konsulta',
        ];

        // Append dynamic ewallet terms after fixed terms (zeros if unused).
        foreach ($context['ewallet_labels'] ?? [] as $ewallet_key => $ewallet_name) {
            $term_labels[$ewallet_key] = $ewallet_name;
        }

        $branch_keys = array_map(static fn($meta) => $meta['key'], $this->_branch_db_map());

        $rows_data = [];
        foreach ($term_labels as $term_key => $label) {
            $rows_data[$term_key] = [
                'payment_term' => $label,
            ];
            foreach ($branch_keys as $branch_key) {
                $rows_data[$term_key][$branch_key]              = 0.0;
                $rows_data[$term_key][$branch_key . '_details'] = [];
            }
            $rows_data[$term_key]['total'] = 0.0;
            $rows_data[$term_key]['sob']   = 0.0;
        }

        foreach ($branch_keys as $branch_key) {
            foreach ($context['allocations_by_branch'][$branch_key] ?? [] as $item) {
                $term = $item['term'] ?? null;
                if (! isset($rows_data[$term])) {
                    continue;
                }
                $amount = (float) ($item['amount'] ?? 0);
                $rows_data[$term][$branch_key] += $amount;
                $rows_data[$term]['total'] += $amount;
                if (! empty($item['detail'])) {
                    $rows_data[$term][$branch_key . '_details'][] = $item['detail'];
                }
            }

            if ($payment_term !== null && $payment_term !== 'yakap konsulta') {
                    continue;
                }

            foreach ($context['yakap_by_branch'][$branch_key] ?? [] as $yakap_row) {
                $amount = (float) ($yakap_row['amount'] ?? 680);
                $rows_data['yakap konsulta'][$branch_key] += $amount;
                $rows_data['yakap konsulta']['total'] += $amount;
                $rows_data['yakap konsulta'][$branch_key . '_details'][] = [
                    'transaction_no' => $yakap_row['transaction_no'] ?? null,
                    'patient_id'     => $yakap_row['patient_id'] ?? null,
                    'patient_name'   => trim((string) ($yakap_row['patient_name'] ?? '')),
                    'amount'         => round($amount, 2),
                ];
            }
        }

        $tender_terms = array_fill_keys(
            array_merge(['cash', 'debit', 'credit', 'c wallet'], array_keys($context['ewallet_labels'] ?? [])),
            true
        );

        $rows              = [];
        $term_index_by_key = [];
        foreach ($rows_data as $term_key => $row) {
            $ordered = [
                'payment_term' => $row['payment_term'],
            ];

            $grouped_by_branch = [];
            foreach ($branch_keys as $branch_key) {
                $grouped = $this->_group_mop_details_by_transaction(
                    $row[$branch_key . '_details'] ?? []
                );
                if (isset($tender_terms[$term_key])) {
                    $grouped = $this->_apply_tender_payment_amounts($grouped);
                } elseif (in_array($term_key, ['hmo', 'corporate'], true)) {
                    $grouped = $this->_apply_allocated_term_grand_totals($grouped);
                }
                $grouped = $this->_finalize_mop_detail_amounts($grouped, $term_key);
                $ordered[$branch_key]           = 0.0;
                $grouped_by_branch[$branch_key] = $grouped;
            }

            $ordered['total'] = 0.0;
            $ordered['sob']   = 0;
            foreach ($branch_keys as $branch_key) {
                $ordered[$branch_key . '_details'] = $grouped_by_branch[$branch_key];
            }
            $term_index_by_key[$term_key] = count($rows);
            $rows[]                       = $ordered;
        }

        $diagnostic_term_keys = [];
        foreach (array_keys($term_labels) as $term_key) {
            if ($term_key !== 'yakap konsulta') {
                $diagnostic_term_keys[] = $term_key;
            }
        }

        foreach ($rows as $i => $row) {
            $row_total = 0.0;
            foreach ($branch_keys as $branch_key) {
                $branch_total = 0.0;
                foreach ($row[$branch_key . '_details'] ?? [] as $txn) {
                    $branch_total += (float) ($txn['grand_total'] ?? $txn['amount'] ?? 0);
                }
                $rows[$i][$branch_key] = round($branch_total, 2);
                $row_total += $branch_total;
            }
            $rows[$i]['total'] = round($row_total, 2);
        }

        if ($payment_term !== null && isset($term_index_by_key[$payment_term])) {
            foreach ($term_index_by_key as $term_key => $idx) {
                if ($term_key === $payment_term) {
                    continue;
                }
                foreach ($branch_keys as $branch_key) {
                    $rows[$idx][$branch_key]                = 0.0;
                    $rows[$idx][$branch_key . '_details']   = [];
                }
                $rows[$idx]['total'] = 0.0;
            }
        }

        $diagnostic_total = 0.0;
        foreach ($diagnostic_term_keys as $term_key) {
            if (! isset($term_index_by_key[$term_key])) {
                continue;
            }
            $diagnostic_total += (float) ($rows[$term_index_by_key[$term_key]]['total'] ?? 0);
        }
        $diagnostic_total = round($diagnostic_total, 2);

        foreach ($rows as &$row) {
            $row['sob'] = $diagnostic_total > 0 ? round($row['total'] / $diagnostic_total, 4) : 0;
        }
        unset($row);

        $discount_by_transaction = [];
        foreach ($term_index_by_key as $idx) {
            $row = $rows[$idx];
            foreach ($branch_keys as $branch_key) {
                foreach ($row[$branch_key . '_details'] ?? [] as $txn) {
                    $tid = (int) ($txn['transaction_id'] ?? 0);
                    if ($tid <= 0) {
                        continue;
                    }
                    $value = (float) ($txn['discount_total'] ?? 0);
                    if (! isset($discount_by_transaction[$tid]) || ($discount_by_transaction[$tid] <= 0 && $value > 0)) {
                        $discount_by_transaction[$tid] = $value;
                    }
                }
            }
        }
        $total_discount = round(array_sum($discount_by_transaction), 2);

        return [
            'as_of'   => $as_of,
            'rows'    => $rows,
            'summary' => [
                'total'           => $diagnostic_total,
                'per_sales_total' => $diagnostic_total,
                'variance'        => 0,
                'total_net_sales' => $diagnostic_total,
                'total_discount'  => $total_discount,
            ],
        ];
    }

    /**
     * amount = sum(transaction_details.amount).
     * Discount applies only on tender/e-wallet terms; HMO, Corporate, Yakap, and C Wallet have none.
     * Non-yakap terms drop charge_to 3210 lines. EMR yakap rows without details stay as-is.
     *
     * @param array<int, array> $grouped
     * @return array<int, array>
     */
    private function _finalize_mop_detail_amounts(array $grouped, $term_key)
    {
        $is_yakap          = $term_key === 'yakap konsulta';
        $is_c_wallet       = $term_key === 'c wallet';
        $discount_on_term  = ! in_array($term_key, ['hmo', 'corporate', 'yakap konsulta', 'c wallet'], true);
        foreach ($grouped as &$txn) {
            $details = [];
            foreach ($txn['transaction_details'] ?? [] as $line) {
                if (! $is_yakap && $this->_is_yakap_charge_to($line['charge_to'] ?? null)) {
                    continue;
                }
                $details[] = $line;
            }
            $txn['transaction_details'] = $details;

            if ($details === [] && ($is_yakap || $is_c_wallet)) {
                $amount = round((float) ($txn['amount'] ?? 0), 2);
            } else {
                $amount = 0.0;
                foreach ($details as $line) {
                    $amount += (float) ($line['amount'] ?? 0);
                }
                $amount = round($amount, 2);
            }

            $txn['amount'] = $amount;
            if ($discount_on_term) {
                $discount              = $this->_mop_discount_total($txn['discount'] ?? []);
                $txn['discount_total'] = $discount;
                $txn['grand_total']    = round(max(0, $amount - $discount), 2);
            } else {
                $txn['discount']       = [];
                $txn['discount_total'] = 0.0;
                $txn['grand_total']    = $amount;
            }
        }
        unset($txn);

        return $grouped;
    }

    /**
     * Sum of payment_discount.total_discount for a transaction. Nested details[] are the same rows.
     *
     * @param mixed $discount
     */
    private function _mop_discount_total($discount)
    {
        if (! is_array($discount)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($discount as $row) {
            if (! is_array($row)) {
                continue;
            }
            $total += (float) ($row['total_discount'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Net sales after discount: max(0, transaction_grand_total - discount_total).
     *
     * @param array<string, mixed> $row
     */
    private function _mop_net_total(array $row)
    {
        $gross = $this->_mop_transaction_grand_total($row);
        if ($gross === null) {
            return null;
        }

        $discount = array_key_exists('discount_total', $row) && $row['discount_total'] !== null && $row['discount_total'] !== ''
            ? (float) $row['discount_total']
            : $this->_mop_discount_total($row['discount'] ?? []);

        return round(max(0, $gross - $discount), 2);
    }

    /**
     * Original transaction.grand_total, independent of tender/HMO display overwrites.
     *
     * @param array<string, mixed> $row
     */
    private function _mop_transaction_grand_total(array $row)
    {
        if (array_key_exists('transaction_grand_total', $row) && $row['transaction_grand_total'] !== null && $row['transaction_grand_total'] !== '') {
            return round((float) $row['transaction_grand_total'], 2);
        }
        if (array_key_exists('grand_total', $row) && $row['grand_total'] !== null && $row['grand_total'] !== '') {
            return round((float) $row['grand_total'], 2);
        }

        return null;
    }

    /**
     * Scale each transaction's diagnostic term amounts to pre-discount gross
     * and grand_total to net after discount.
     *
     * @param array<int, array>    $rows
     * @param array<int, string>   $branch_keys
     * @param array<int, string>   $diagnostic_term_keys
     * @param array<string, int>   $term_index_by_key
     */
    private function _reconcile_mop_terms_to_grand_total(
        array &$rows,
        array $branch_keys,
        array $diagnostic_term_keys,
        array $term_index_by_key
    ) {
        foreach ($branch_keys as $branch_key) {
            $by_tid = [];
            foreach ($diagnostic_term_keys as $term_key) {
                if (! isset($term_index_by_key[$term_key])) {
                    continue;
                }
                $row_idx = $term_index_by_key[$term_key];
                foreach ($rows[$row_idx][$branch_key . '_details'] ?? [] as $detail_idx => $txn) {
                    $tid = (int) ($txn['transaction_id'] ?? 0);
                    if ($tid <= 0) {
                        continue;
                    }
                    $by_tid[$tid][] = [
                        'row_idx'    => $row_idx,
                        'detail_idx' => $detail_idx,
                        'amount'     => (float) ($txn['amount'] ?? 0),
                        'gross'      => $this->_mop_transaction_grand_total($txn),
                        'net'        => $this->_mop_net_total($txn),
                    ];
                }
            }

            foreach ($by_tid as $parts) {
                $gross   = null;
                $net     = null;
                $raw_sum = 0.0;
                foreach ($parts as $part) {
                    $raw_sum += $part['amount'];
                    if ($gross === null && $part['gross'] !== null) {
                        $gross = (float) $part['gross'];
                    }
                    if ($net === null && $part['net'] !== null) {
                        $net = (float) $part['net'];
                    }
                }
                if ($raw_sum <= 0) {
                    continue;
                }
                if ($gross === null) {
                    $gross = $raw_sum;
                }
                if ($net === null) {
                    $net = $gross;
                }

                $amount_shares      = $this->_mop_scale_shares($parts, $raw_sum, $gross);
                $grand_total_shares = $this->_mop_scale_shares($parts, $raw_sum, $net);
                foreach ($parts as $j => $part) {
                    $rows[$part['row_idx']][$branch_key . '_details'][$part['detail_idx']]['amount']      = $amount_shares[$j];
                    $rows[$part['row_idx']][$branch_key . '_details'][$part['detail_idx']]['grand_total'] = $grand_total_shares[$j];
                }
            }
        }
    }

    /**
     * Partition $target across parts using $raw_sum as weights. Last part gets the remainder.
     *
     * @param array<int, array{amount: float}> $parts
     * @return array<int, float>
     */
    private function _mop_scale_shares(array $parts, float $raw_sum, float $target)
    {
        $shares    = [];
        $allocated = 0.0;
        $last      = count($parts) - 1;
        $scale     = $raw_sum > 0 ? ($target / $raw_sum) : 0.0;
        foreach ($parts as $j => $part) {
            if ($j === $last) {
                $shares[$j] = round($target - $allocated, 2);
            } else {
                $shares[$j] = round($part['amount'] * $scale, 2);
                $allocated += $shares[$j];
            }
        }

        return $shares;
    }

    private function _build_company_metric_rows(array $context, $branch_filter = null)
    {
        $company_rows = [];

        $branch_keys = $branch_filter
        ? [$branch_filter]
        : array_map(static fn($meta) => $meta['key'], $this->_branch_db_map());

        foreach ($branch_keys as $branch_key) {
            $all_charge_ids = array_unique(array_merge(
                array_keys($context['sales_by_branch_charge'][$branch_key] ?? []),
                array_keys($context['billed_by_branch_charge'][$branch_key] ?? [])
            ));

            foreach ($all_charge_ids as $charge_id) {
                $name = strtoupper($this->_company_name($context['company_map'], $charge_id));
                if (! isset($company_rows[$name])) {
                    $company_rows[$name] = [
                        'ct_company_name' => $name,
                        'per_dsmr_sales'  => [],
                        'per_billing'     => [],
                        'variance'        => [],
                    ];
                }

                $company      = $context['company_map'][$charge_id] ?? null;
                $account_type = $this->_classify_account_type($company, $name);
                if (in_array($account_type, ['yakap', 'konsulta'], true)) {
                    continue;
                }

                $bucket = $account_type === 'hmo' ? 'hmo' : 'corporate';
                $sales  = (float) ($context['sales_by_branch_charge'][$branch_key][$charge_id] ?? 0);
                $billed = (float) ($context['billed_by_branch_charge'][$branch_key][$charge_id] ?? 0);

                foreach (['per_dsmr_sales', 'per_billing', 'variance'] as $section) {
                    if (! isset($company_rows[$name][$section][$branch_key])) {
                        $company_rows[$name][$section][$branch_key] = [
                            'hmo'       => 0.0,
                            'corporate' => 0.0,
                            'subtotal'  => 0.0,
                        ];
                    }
                }

                $company_rows[$name]['per_dsmr_sales'][$branch_key][$bucket] += $sales;
                $company_rows[$name]['per_billing'][$branch_key][$bucket] += $billed;
                $company_rows[$name]['variance'][$branch_key][$bucket] += $sales + $billed;

                foreach (['per_dsmr_sales', 'per_billing', 'variance'] as $section) {
                    $company_rows[$name][$section][$branch_key]['subtotal'] =
                        $company_rows[$name][$section][$branch_key]['hmo']
                         + $company_rows[$name][$section][$branch_key]['corporate'];
                }
            }
        }

        foreach ($company_rows as &$row) {
            $row['per_dsmr_sales']['grand_total'] = 0.0;
            $row['per_billing']['grand_total']    = 0.0;
            $row['variance']['grand_total']       = 0.0;
            foreach ($branch_keys as $branch_key) {
                $row['per_dsmr_sales']['grand_total'] += $row['per_dsmr_sales'][$branch_key]['subtotal'] ?? 0;
                $row['per_billing']['grand_total'] += $row['per_billing'][$branch_key]['subtotal'] ?? 0;
                $row['variance']['grand_total'] += $row['variance'][$branch_key]['subtotal'] ?? 0;
            }
            foreach (['per_dsmr_sales', 'per_billing', 'variance'] as $section) {
                $row[$section]['grand_total'] = round($row[$section]['grand_total'], 2);
                foreach ($branch_keys as $branch_key) {
                    if (! isset($row[$section][$branch_key])) {
                        continue;
                    }
                    foreach (['hmo', 'corporate', 'subtotal'] as $metric) {
                        $row[$section][$branch_key][$metric] = round((float) $row[$section][$branch_key][$metric], 2);
                    }
                }
            }
        }
        unset($row);

        ksort($company_rows);

        return array_values($company_rows);
    }

    private function _build_branch_summary(array $context, $branch_key)
    {
        $summary = [
            'per_dsmr_sales' => ['hmo' => 0.0, 'corporate' => 0.0, 'subtotal' => 0.0],
            'per_billing'    => ['hmo' => 0.0, 'corporate' => 0.0, 'subtotal' => 0.0],
            'variance'       => ['hmo' => 0.0, 'corporate' => 0.0, 'subtotal' => 0.0],
        ];

        foreach ($context['sales_by_branch_charge'][$branch_key] ?? [] as $charge_id => $amount) {
            $company = $context['company_map'][$charge_id] ?? null;
            $bucket  = $this->_classify_account_type($company) === 'hmo' ? 'hmo' : 'corporate';
            $summary['per_dsmr_sales'][$bucket] += $amount;
        }

        foreach ($context['billed_by_branch_charge'][$branch_key] ?? [] as $charge_id => $amount) {
            $company = $context['company_map'][$charge_id] ?? null;
            $bucket  = $this->_classify_account_type($company) === 'hmo' ? 'hmo' : 'corporate';
            $summary['per_billing'][$bucket] += $amount;
        }

        foreach (['hmo', 'corporate'] as $bucket) {
            $summary['variance'][$bucket] = $summary['per_dsmr_sales'][$bucket] + $summary['per_billing'][$bucket];
        }
        $summary['per_dsmr_sales']['subtotal'] = $summary['per_dsmr_sales']['hmo'] + $summary['per_dsmr_sales']['corporate'];
        $summary['per_billing']['subtotal']    = $summary['per_billing']['hmo'] + $summary['per_billing']['corporate'];
        $summary['variance']['subtotal']       = $summary['variance']['hmo'] + $summary['variance']['corporate'];

        foreach ($summary as $section => $metrics) {
            foreach ($metrics as $metric => $value) {
                $summary[$section][$metric] = round((float) $value, 2);
            }
        }

        return $summary;
    }

    private function _build_all_branch_summary(array $context)
    {
        $summary = [
            'per_dsmr_sales' => [
                'hmo'       => $this->_empty_branch_amounts(),
                'corporate' => $this->_empty_branch_amounts(),
            ],
            'per_billing'    => [
                'hmo'       => $this->_empty_branch_amounts(),
                'corporate' => $this->_empty_branch_amounts(),
            ],
            'variance'       => [
                'hmo'       => $this->_empty_branch_amounts(),
                'corporate' => $this->_empty_branch_amounts(),
            ],
        ];

        foreach ($this->_branch_db_map() as $meta) {
            $branch_key     = $meta['key'];
            $branch_summary = $this->_build_branch_summary($context, $branch_key);
            foreach (['hmo', 'corporate'] as $bucket) {
                $summary['per_dsmr_sales'][$bucket][$branch_key] = $branch_summary['per_dsmr_sales'][$bucket];
                $summary['per_billing'][$bucket][$branch_key]    = $branch_summary['per_billing'][$bucket];
                $summary['variance'][$bucket][$branch_key]       = $branch_summary['variance'][$bucket];
            }
        }

        foreach (['per_dsmr_sales', 'per_billing', 'variance'] as $section) {
            foreach (['hmo', 'corporate'] as $bucket) {
                $summary[$section][$bucket] = $this->_sum_branch_map($summary[$section][$bucket]);
                unset($summary[$section][$bucket]['total']);
                $summary[$section][$bucket]['subtotal'] = round(
                    array_sum([
                        $summary[$section][$bucket]['consolacion'] ?? 0,
                        $summary[$section][$bucket]['mactan'] ?? 0,
                        $summary[$section][$bucket]['oakridge'] ?? 0,
                        $summary[$section][$bucket]['mobile'] ?? 0,
                        $summary[$section][$bucket]['mantlewood'] ?? 0,
                    ]),
                    2
                );
            }
            $summary[$section]['grand_total'] = round(
                $summary[$section]['hmo']['subtotal'] + $summary[$section]['corporate']['subtotal'],
                2
            );
        }

        return $summary;
    }

    private function _format_billing_all_branches(array $context, $as_of)
    {
        return [
            'as_of'     => $as_of,
            'summary'   => $this->_build_all_branch_summary($context),
            'companies' => $this->_build_company_metric_rows($context),
        ];
    }

    private function _format_billing_single_branch(array $context, $as_of, $branch_key, $include_origin = false)
    {
        $companies = [];
        foreach ($this->_build_company_metric_rows($context, $branch_key) as $company_row) {
            $single = [
                'ct_company_name' => $company_row['ct_company_name'],
                'per_dsmr_sales'  => $company_row['per_dsmr_sales'][$branch_key] ?? [
                    'hmo' => 0.0, 'corporate' => 0.0, 'subtotal' => 0.0,
                ],
                'per_billing'     => $company_row['per_billing'][$branch_key] ?? [
                    'hmo' => 0.0, 'corporate' => 0.0, 'subtotal' => 0.0,
                ],
                'variance'        => $company_row['variance'][$branch_key] ?? [
                    'hmo' => 0.0, 'corporate' => 0.0, 'subtotal' => 0.0,
                ],
            ];

            if ($include_origin) {
                $single['per_billing_by_origin'] = $this->_build_origin_billing_for_company(
                    $context,
                    $branch_key,
                    $company_row['ct_company_name']
                );
            }

            $companies[] = $single;
        }

        $payload = [
            'as_of'     => $as_of,
            'branch'    => $branch_key,
            'summary'   => $this->_build_branch_summary($context, $branch_key),
            'companies' => $companies,
        ];

        if ($include_origin) {
            $payload['summary']['per_billing_by_origin'] = $this->_build_origin_billing_summary($context, $branch_key);
        }

        return $payload;
    }

    private function _build_origin_billing_summary(array $context, $reporting_branch)
    {
        $origins = ['consolacion', 'mactan', 'oakridge', 'mobile', 'mantlewood'];
        $summary = [];
        foreach ($origins as $origin_key) {
            $summary[$origin_key] = ['hmo' => 0.0, 'corporate' => 0.0, 'subtotal' => 0.0];
            foreach ($context['billed_origin_by_branch'][$reporting_branch][$origin_key] ?? [] as $charge_id => $amount) {
                $company = $context['company_map'][$charge_id] ?? null;
                $bucket  = $this->_classify_account_type($company) === 'hmo' ? 'hmo' : 'corporate';
                $summary[$origin_key][$bucket] += $amount;
            }
            $summary[$origin_key]['subtotal'] = round(
                $summary[$origin_key]['hmo'] + $summary[$origin_key]['corporate'],
                2
            );
            $summary[$origin_key]['hmo']       = round($summary[$origin_key]['hmo'], 2);
            $summary[$origin_key]['corporate'] = round($summary[$origin_key]['corporate'], 2);
        }

        return $summary;
    }

    private function _build_origin_billing_for_company(array $context, $reporting_branch, $company_name)
    {
        $origins = ['consolacion', 'mactan', 'oakridge', 'mobile', 'mantlewood'];
        $result  = [];
        $target  = strtoupper($company_name);

        foreach ($origins as $origin_key) {
            $result[$origin_key] = ['hmo' => 0.0, 'corporate' => 0.0, 'subtotal' => 0.0];
            foreach ($context['billed_origin_by_branch'][$reporting_branch][$origin_key] ?? [] as $charge_id => $amount) {
                if (strtoupper($this->_company_name($context['company_map'], $charge_id)) !== $target) {
                    continue;
                }
                $company = $context['company_map'][$charge_id] ?? null;
                $bucket  = $this->_classify_account_type($company) === 'hmo' ? 'hmo' : 'corporate';
                $result[$origin_key][$bucket] += $amount;
            }
            $result[$origin_key]['subtotal'] = round(
                $result[$origin_key]['hmo'] + $result[$origin_key]['corporate'],
                2
            );
            $result[$origin_key]['hmo']       = round($result[$origin_key]['hmo'], 2);
            $result[$origin_key]['corporate'] = round($result[$origin_key]['corporate'], 2);
        }

        return $result;
    }
}
