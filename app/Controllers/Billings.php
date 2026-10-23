<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Company;
use App\Models\Branch_master;
use App\Models\Patient_master;
use App\Models\Endorsement;
use App\Models\Endorsement_detail;
use App\Models\Endorsement_service;
use App\Models\Endorsement_service_package_detail;
use App\Models\Endorsement_attachment;
use App\Models\Endorsement_signed_moa;
use App\Models\Endorsement_patient;
use App\Models\User;
use App\Models\Booking_all;
use App\Models\Billing;
use App\Models\Billing_entry;
use App\Models\Credit_memo;
use App\Models\Debit_memo;
use App\Models\Billing_payment;
use App\Models\Billing_payment_deposit;
use App\Models\Billing_payment_entry;
use App\Models\Check_deposit;
use App\Models\Yakap_billing;

class Billings extends MYT_Controller
{
    protected $request;
    protected $company;
    protected $branch_master;
    protected $patient_master;
    protected $endorsement;
    protected $endorsement_detail;
    protected $endorsement_service;
    protected $endorsement_service_package_detail;
    protected $endorsement_attachment;
    protected $endorsement_signed_moa;
    protected $endorsement_patient;
    protected $user;
    protected $booking_all;
    protected $helpers = ['text'];
    
    //use for dynamic model resolution
    protected $billing;
    protected $billing_entry;
    protected $credit_memo;
    protected $debit_memo;
    protected $billing_payment;
    protected $billing_payment_deposit;
    protected $billing_payment_entry;
    protected $collection_entries_service;
    protected $check_deposit;
    protected $yakap_billing;
    protected $booking;
    protected $booking_detail;
    protected $booking_medicine;
    protected $payment;
    protected $payment_multiple_method;

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_mobile_payment_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

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
        $this->company = new Company();
        $this->branch_master = new Branch_master();
        $this->patient_master = new Patient_master();
        $this->endorsement = new Endorsement();
        $this->endorsement_detail = new Endorsement_detail();
        $this->endorsement_service = new Endorsement_service();
        $this->endorsement_service_package_detail = new Endorsement_service_package_detail();
        $this->endorsement_attachment = new Endorsement_attachment();
        $this->endorsement_signed_moa = new Endorsement_signed_moa();
        $this->endorsement_patient = new Endorsement_patient();
        $this->user = new User();
        $this->booking_all = new Booking_all();
        $this->billing = new Billing();
        $this->billing_entry = new Billing_entry();
        $this->credit_memo = new Credit_memo();
        $this->debit_memo = new Debit_memo();
        $this->billing_payment = new Billing_payment();
        $this->billing_payment_deposit = new Billing_payment_deposit();
        $this->billing_payment_entry = new Billing_payment_entry();
        $this->check_deposit = new Check_deposit();
        $this->yakap_billing = new Yakap_billing();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Maps branch_id (master) to CodeIgniter database group names (per-branch clinic DB).
     */
    protected function branch_db_map(): array
    {
        return [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];
    }

    /**
     * Clinic DBs only. Master has no booking table.
     */
    protected function clinic_branch_db_map(): array
    {
        $map = $this->branch_db_map();
        unset($map[0]);

        return $map;
    }

    /**
     * Missing / empty / 0 branch_id means all clinic branches.
     */
    protected function normalize_clinic_branch_id($raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        $id = (int) $raw;

        return $id === 0 ? null : $id;
    }

    /**
     * Resolve branch clinic models (booking/payment) for the given branch_id.
     * Billing tables live on master and are not rebound here.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        if ($branch_id === 0) {
            return false;
        }

        $dbMap = $this->clinic_branch_db_map();

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->booking = new \App\Models\Booking($db);
        $this->booking_detail = new \App\Models\Booking_detail($db);
        $this->booking_medicine = new \App\Models\Booking_medicine($db);
        $this->payment = new \App\Models\Payment($db);
        $this->payment_multiple_method = new \App\Models\Payment_multiple_method($db);

        return true;
    }

    /**
     * @param array<int|string> $ids
     * @return array<int, object>
     */
    protected function _companies_by_ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->company->db->query(
            "SELECT id, name, address, tin_no FROM company WHERE is_deleted = 0 AND id IN ($placeholders)",
            $ids
        );
        $map = [];
        foreach ($query ? $query->getResult() : [] as $row) {
            $map[(int) $row->id] = $row;
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    protected function _branch_names(): array
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }
        $names = [];
        $rows = $this->branch_master->select('id, name', ['is_deleted' => 0]);
        foreach ($rows ?: [] as $row) {
            $names[(int) $row->id] = $row->name;
        }

        return $names;
    }

    protected function _billing_branch_name_from_entries(array $entries, array $branch_names): string
    {
        $fallback_id = 2;
        $fallback_label = 'Clinica Prime Oakridge';

        if ($entries === []) {
            return $branch_names[$fallback_id] ?? $fallback_label;
        }

        $seen = [];
        $names = [];
        foreach ($entries as $entry) {
            $branch_id = (int) ($entry['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                $branch_id = $fallback_id;
            }
            if (isset($seen[$branch_id])) {
                continue;
            }
            $seen[$branch_id] = true;
            if ($branch_id === $fallback_id) {
                $names[] = $branch_names[$fallback_id] ?? $fallback_label;
                continue;
            }
            $name = $branch_names[$branch_id] ?? null;
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return $branch_names[$fallback_id] ?? $fallback_label;
        }

        return implode(', ', $names);
    }

    protected function _collection_entries_service(): \App\Libraries\Billing_collection_entries
    {
        if ($this->collection_entries_service === null) {
            $this->collection_entries_service = new \App\Libraries\Billing_collection_entries(
                $this->billing_payment_entry,
                $this->billing_entry,
                $this->branch_master,
                $this->yakap_billing,
                function (int $branch_id): ?array {
                    if (!$this->resolve_branch_models($branch_id)) {
                        return null;
                    }
                    return ['booking' => $this->booking, 'booking_detail' => $this->booking_detail];
                }
            );
        }
        return $this->collection_entries_service;
    }

    protected function _empty_payment_summary(): array
    {
        return \App\Libraries\Billing_collection_entries::empty_payment_summary();
    }

    protected function _decorate_billing_entry(array $entry, $charge_to = null, $include_clinic_extras = true): array
    {
        if (!$include_clinic_extras) {
            return $this->_collection_entries_service()->decorate_billing_entry($entry, $charge_to);
        }
        $branch_names = $this->_branch_names();
        $entry['branch_name'] = $branch_names[(int) ($entry['branch_id'] ?? 0)] ?? null;

        $booking_id = (int) ($entry['booking_id'] ?? 0);
        $has_clinic = $booking_id > 0 && $this->resolve_branch_models((int) ($entry['branch_id'] ?? 0));

        if ($has_clinic) {
            $enrichment = $this->_enrich_entry_from_branch($entry);
        } else {
            $unmatched = $entry;
            $unmatched['booking_id'] = 0;
            $enrichment = $this->_enrich_entry_from_branch($unmatched);
        }
        $entry['patient_id'] = $enrichment['patient_id'];
        $entry['patient_name'] = $enrichment['patient_name'];
        $entry['transaction_no'] = $enrichment['transaction_no'];

        if (!$has_clinic) {
            $entry['booking_details'] = [];
            $entry['booking_medicines'] = [];
            $entry['payment_details'] = [];
            $entry['payment_summary'] = $this->_empty_payment_summary();
            return $entry;
        }

        $entry['booking_details'] = $this->_entry_booking_details($entry, $charge_to ?? ($entry['charge_to'] ?? null));
        $entry['booking_medicines'] = [];
        $entry['payment_details'] = [];
        $entry['payment_summary'] = $this->_empty_payment_summary();

        $entry['booking_medicines'] = $this->booking_medicine->get_details($booking_id) ?: [];
        $total_vat_ex = 0;
        $total_vat_amount = 0;
        foreach ($entry['booking_medicines'] as $booking_medicine) {
            $total_vat_ex += $booking_medicine['vat_ex'] ?? 0;
            $total_vat_amount += $booking_medicine['vat_amount'] ?? 0;
        }
        $entry['_vat_ex'] = $total_vat_ex;
        $entry['_vat_amount'] = $total_vat_amount;

        $payment_details = !empty($entry['transaction_id'])
            ? ($this->payment->get_details($entry['transaction_id'], $entry['company_id']) ?: [])
            : [];
        $summary = $this->_empty_payment_summary();
        foreach ($payment_details as $payment_detail) {
            $summary['total_withholding_tax'] += $payment_detail['withholding_tax'] ?? 0;
            $summary['total_withholding_percent'] += $payment_detail['withholding_percent'] ?? 0;
            $summary['total_discount'] += $payment_detail['discount'] ?? 0;
            $summary['total_subtotal'] += $payment_detail['subtotal'] ?? 0;
            $summary['total_grand_total'] += $payment_detail['grand_total'] ?? 0;
        }
        $entry['payment_details'] = $payment_details;
        $entry['payment_summary'] = $summary;

        return $entry;
    }

    /**
     * Get billings
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $billing_id = $this->payload['billing_id'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $charge_to = $this->payload['charge_to_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $screen = $this->payload['screen'] ?? null;
        $term = $this->payload['term'] ?? null;
        if ($term === '') {
            $term = null;
        }
        [$page, $data_size] = list_page_params($this->payload);

        $billings = $this->billing->get_details($billing_id, $date_from, $date_to, $company_id, $charge_to, $status, $page, $data_size, $term, null, $screen) ?: [];
        $size = $this->billing->count_details($billing_id, $date_from, $date_to, $company_id, $charge_to, $status, $term, null, $screen);
        $billing_ids = array_map('intval', array_column($billings, 'id'));
        $credit_memos = $this->credit_memo->get_details_for_billing_ids($billing_ids);
        $debit_memos = $this->debit_memo->get_details_for_billing_ids($billing_ids);

        if (!$billings) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No billings found.'
            ];
        } elseif ($credit_memos === false || $debit_memos === false) {
            $response_status = FAIL;
            $response = [
                'Failed to get billing memos.'
            ];
        } else {
            $company_ids = [];
            foreach ($billings as $billing) {
                $company_ids[] = $billing['company_id'] ?? null;
                $company_ids[] = $billing['charge_to'] ?? null;
            }
            $collections_by_billing = [];
            if ($screen === 'collections') {
                foreach ($this->billing_payment->get_details_for_billing_ids($billing_ids) as $payment) {
                    $company_ids[] = $payment['charge_to'] ?? null;
                    $bid = (int) ($payment['billing_id'] ?? 0);
                    unset($payment['billing_id']);
                    $collections_by_billing[$bid][] = $payment;
                }
            }
            $companies = $this->_companies_by_ids($company_ids);
            $packed = $this->billing_entry->get_details_for_billing_ids($billing_ids);
            $entries_by_billing = [];
            foreach ($packed['entries'] as $entry) {
                $entries_by_billing[(int) $entry['billing_id']][] = $entry;
            }

            $memo_children = [];
            foreach (['credit', 'debit'] as $kind) {
                $model = $kind === 'credit'
                    ? new \App\Models\Credit_memo_entries($this->db)
                    : new \App\Models\Debit_memo_entries($this->db);
                foreach ($model->for_billings($billing_ids) as $row) {
                    $memo_children[$kind][(int) $row['billing_entry_id']][] = $row;
                }
            }

            $branch_names = $this->_branch_names();
            $credit_memos_by_billing = [];
            foreach ($credit_memos as $memo) {
                $credit_memos_by_billing[(int) $memo['billing_id']][] = $memo;
            }
            $debit_memos_by_billing = [];
            foreach ($debit_memos as $memo) {
                $debit_memos_by_billing[(int) $memo['billing_id']][] = $memo;
            }

            foreach ($billings as $key => $billing) {
                $total_vat_ex = 0;
                $total_vat_amount = 0;
                $decorated = [];
                foreach ($entries_by_billing[(int) $billing['id']] ?? [] as $entry) {
                    $entry = $this->_decorate_billing_entry($entry, $billing['charge_to'], true);
                    $entry['credit_memo_entries'] = $memo_children['credit'][(int) $entry['id']] ?? [];
                    $entry['debit_memo_entries'] = $memo_children['debit'][(int) $entry['id']] ?? [];
                    $entry['credit_memo_total'] = \App\Libraries\Memo_allocations::total($entry['credit_memo_entries']);
                    $entry['debit_memo_total'] = \App\Libraries\Memo_allocations::total($entry['debit_memo_entries']);
                    $total_vat_ex += $entry['_vat_ex'] ?? 0;
                    $total_vat_amount += $entry['_vat_amount'] ?? 0;
                    unset($entry['_vat_ex'], $entry['_vat_amount']);
                    $decorated[] = $entry;
                }
                $raw_entries = $entries_by_billing[(int) $billing['id']] ?? [];
                $billings[$key]['branch_name'] = $this->_billing_branch_name_from_entries($raw_entries, $branch_names);
                $billings[$key]['entries'] = $decorated;
                $billings[$key]['credit_memo'] = $credit_memos_by_billing[(int) $billing['id']] ?? [];
                $billings[$key]['debit_memo'] = $debit_memos_by_billing[(int) $billing['id']] ?? [];

                $company = $companies[(int) ($billing['company_id'] ?? 0)] ?? null;
                $billings[$key]['company_name'] = $company?->name;
                $billings[$key]['company_address'] = $company?->address;
                $billings[$key]['company_tin_no'] = $company?->tin_no;
                $charge_to_row = $companies[(int) ($billing['charge_to'] ?? 0)] ?? null;
                $billings[$key]['charge_to_name'] = $charge_to_row?->name;
                $billings[$key]['charge_to_address'] = $charge_to_row?->address;
                $billings[$key]['charge_to_tin_no'] = $charge_to_row?->tin_no;
                $billings[$key]['total_vat_ex'] = $total_vat_ex;
                $billings[$key]['total_vat_amount'] = $total_vat_amount;

                if ($screen === 'collections') {
                    $row_collections = $collections_by_billing[(int) $billing['id']] ?? [];
                    foreach ($row_collections as $c_key => $payment) {
                        $pay_charge = $companies[(int) ($payment['charge_to'] ?? 0)] ?? null;
                        $row_collections[$c_key]['charge_to_name'] = $pay_charge?->name;
                    }
                    $billing_total = (float) ($billing['grand_total'] ?? 0)
                        - (float) ($billing['credit_memo_total'] ?? 0)
                        + (float) ($billing['debit_memo_total'] ?? 0);
                    $billings[$key]['collections'] = $this->_apply_collection_running_balance($row_collections, $billing_total);
                }
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $billings
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Add running_balance to each collection of a billing.
     * Starts from the billing total after memos and subtracts (wht_amount + grand_total) of each
     * collection in chronological order (paid_date, id). The original array order is preserved.
     *
     * @param list<array<string, mixed>> $collections
     * @return list<array<string, mixed>>
     */
    protected function _apply_collection_running_balance(array $collections, float $billing_total): array
    {
        $order = array_keys($collections);
        usort($order, static function ($a, $b) use ($collections) {
            return [$collections[$a]['paid_date'] ?? '', (int) ($collections[$a]['id'] ?? 0)]
                <=> [$collections[$b]['paid_date'] ?? '', (int) ($collections[$b]['id'] ?? 0)];
        });

        $balance = $billing_total;
        foreach ($order as $idx) {
            $applied = (float) ($collections[$idx]['wht_amount'] ?? 0) + (float) ($collections[$idx]['grand_total'] ?? 0);
            $balance = round($balance - $applied, 2);
            $collections[$idx]['running_balance'] = $balance;
        }

        return $collections;
    }

    /**
     * Enrich billing entry with branch-local booking/patient/transaction fields.
     */
    protected function _enrich_entry_from_branch(array $entry): array
    {
        return $this->_collection_entries_service()->enrich_billing_entry($entry, $this->booking);
    }

    /**
     * Booking details for a billing entry. YAKAP tranches have no single CSR booking.
     */
    protected function _entry_booking_details(array $entry, $charge_to = null): array
    {
        return $this->_collection_entries_service()->entry_booking_details($entry, $charge_to, $this->booking_detail);
    }

    /**
     * Normalize a YAKAP comma-separated list for storage.
     *
     * @param array<int,mixed>|string|null $value
     */
    protected function _yakap_list_value($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                $parts[] = (string) $item;
            }
            return $parts ? implode(',', $parts) : null;
        }

        return (string) $value;
    }

    /**
     * Attach company name for each row's charge_to.
     */
    protected function _attach_charge_to_names(array $transactions): array
    {
        $names = [];

        foreach ($transactions as $key => $transaction) {
            $charge_to_id = $transaction['charge_to'] ?? null;
            if (!$charge_to_id) {
                $transactions[$key]['charge_to_name'] = null;
                continue;
            }

            $id = (int) $charge_to_id;
            if (!array_key_exists($id, $names)) {
                $row = $this->company->select('id, name', ['id' => $id, 'is_deleted' => 0], 1);
                $names[$id] = $row ? $row->name : null;
            }

            $transactions[$key]['charge_to_name'] = $names[$id];
        }

        return $transactions;
    }

    /**
     * When branch_id is omitted, leftover floaters are the union of each clinic's list.
     *
     * @param array<int, array<string, mixed>> $transactions
     * @param array<int|string>|int|string|null $charge_to
     * @param array<int, string> $branch_map
     * @return array<int, array<string, mixed>>
     */
    protected function _append_endorsement_floaters_by_branch(
        array $transactions,
        $company_id,
        $charge_to,
        $branch_id,
        $endorsement_id,
        array $branch_map
    ): array {
        if ($branch_id !== null) {
            return $this->_append_endorsement_floaters(
                $transactions,
                $company_id,
                $charge_to,
                $branch_id,
                $endorsement_id,
                $branch_map
            );
        }

        $bookings = $transactions;
        $merged_floaters = [];
        $seen = [];
        foreach ($branch_map as $map_branch_id => $groupName) {
            $clinic_bookings = array_values(array_filter($bookings, static function ($row) use ($map_branch_id) {
                return (int) ($row['branch_id'] ?? 0) === (int) $map_branch_id;
            }));
            $with = $this->_append_endorsement_floaters(
                $clinic_bookings,
                $company_id,
                $charge_to,
                (int) $map_branch_id,
                $endorsement_id,
                [$map_branch_id => $groupName]
            );
            foreach ($with as $row) {
                if (empty($row['is_floating'])) {
                    continue;
                }
                $eid = $row['endorsement_id'] ?? '';
                if (!empty($row['master_patient_id'])) {
                    $key = $eid . '|p:' . $row['master_patient_id'];
                    $row['branch_id'] = (int) $map_branch_id;
                    $row['branch_name'] = $groupName;
                } else {
                    if ((int) $map_branch_id !== 2) {
                        continue;
                    }
                    $key = $eid . '|f:' . ($row['floating_patient_no'] ?? '');
                    $row['branch_id'] = 2;
                    $row['branch_name'] = $branch_map[2] ?? $groupName;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged_floaters[] = $row;
            }
        }

        return array_merge($bookings, $merged_floaters);
    }

    /**
     * Append remaining guaranteed-headcount floaters after billed visits are hidden.
     *
     * @param array<int, array<string, mixed>> $transactions
     * @param array<int|string>|int|string|null $charge_to
     * @param array<int, string>|null $branch_map
     * @return array<int, array<string, mixed>>
     */
    protected function _append_endorsement_floaters(
        array $transactions,
        $company_id,
        $charge_to,
        $branch_id = null,
        $endorsement_id = null,
        $branch_map = null
    ): array {
        $endorsement_ids = [];
        $existing_patients = [];

        foreach ($transactions as $transaction) {
            $channel = $transaction['billing_channel'] ?? '';
            if ($channel === 'tranche_1' || $channel === 'tranche_2' || $channel === 'yakap_fpe') {
                continue;
            }

            $row_endorsement_id = $transaction['endorsement_id'] ?? null;
            if (!$row_endorsement_id) {
                continue;
            }

            $endorsement_ids[$row_endorsement_id] = $row_endorsement_id;
            $patient_name = $transaction['patient_name'] ?? null;
            if ($patient_name) {
                $existing_patients[$row_endorsement_id][$patient_name] = true;
            }
        }

        $lookup_ids = $this->endorsement_service->get_endorsement_ids_for_billing(
            $company_id,
            $charge_to,
            $branch_id,
            $endorsement_id
        );
        foreach ($lookup_ids as $lookup_id) {
            $endorsement_ids[$lookup_id] = $lookup_id;
        }

        if (!$endorsement_ids) {
            return $transactions;
        }

        $service_booked_names = [];
        $service_booked_count = [];
        $map = $branch_map ?: $this->clinic_branch_db_map();
        // Booked/billed names are global for this endorsement + charge_to.
        // A branch filter must not resurrect them as leftover floaters.
        $booked_scan_map = $this->clinic_branch_db_map();

        foreach ($booked_scan_map as $map_branch_id => $groupName) {
            if (!$this->resolve_branch_models((int) $map_branch_id)) {
                continue;
            }

            $booked_rows = $this->booking_detail->get_booked_for_endorsements(
                array_values($endorsement_ids),
                $charge_to
            );
            foreach ($booked_rows as $booked) {
                $booked_endorsement_id = $booked['endorsement_id'] ?? null;
                $detail_type = $booked['type'] ?? null;
                $detail_type_id = $booked['test_id'] ?? null;
                $patient_name = $booked['patient_name'] ?? null;
                if (!$booked_endorsement_id || !$detail_type || !$detail_type_id || !$patient_name) {
                    continue;
                }
                if ($detail_type === 'lab') {
                    $detail_type = 'lab_test';
                }
                if (!isset($service_booked_names[$booked_endorsement_id][$detail_type][$detail_type_id][$patient_name])) {
                    $service_booked_names[$booked_endorsement_id][$detail_type][$detail_type_id][$patient_name] = true;
                    if (!isset($service_booked_count[$booked_endorsement_id][$detail_type][$detail_type_id])) {
                        $service_booked_count[$booked_endorsement_id][$detail_type][$detail_type_id] = 0;
                    }
                    $service_booked_count[$booked_endorsement_id][$detail_type][$detail_type_id]++;
                }
                $existing_patients[$booked_endorsement_id][$patient_name] = true;
            }
        }

        $charge_ids = [];
        if (is_array($charge_to)) {
            foreach ($charge_to as $id) {
                if ($id === null || $id === '' || $id === '0') {
                    continue;
                }
                $charge_ids[] = (int) $id;
            }
            $charge_ids = array_values(array_unique($charge_ids));
        } elseif ($charge_to !== null && $charge_to !== '' && $charge_to !== '0') {
            $charge_ids[] = (int) $charge_to;
        }

        $matches_charge_to = static function ($charge_value, $charge_to_ids) use ($charge_ids) {
            if (!$charge_ids) {
                return true;
            }
            $has_charge = ($charge_value !== null && $charge_value !== '' && $charge_value !== '0')
                || ($charge_to_ids !== null && $charge_to_ids !== '');
            if (!$has_charge) {
                return true;
            }
            foreach ($charge_ids as $id) {
                if ($charge_value !== null && $charge_value !== '' && (int) $charge_value === $id) {
                    return true;
                }
                if ($charge_to_ids === null || $charge_to_ids === '') {
                    continue;
                }
                foreach (explode(',', (string) $charge_to_ids) as $part) {
                    $part = trim($part);
                    if ($part !== '' && (int) $part === $id) {
                        return true;
                    }
                }
            }
            return false;
        };

        $endorsement_company_ids = [];
        foreach ($endorsement_ids as $lookup_endorsement_id) {
            $endorsement_row = $this->endorsement->select('company_id', [
                'id' => $lookup_endorsement_id,
                'is_deleted' => 0,
            ], 1);
            $endorsement_company_ids[$lookup_endorsement_id] = $endorsement_row
                ? $endorsement_row->company_id
                : $company_id;
        }

        $resolve_charge_to_ids = static function ($charge_value, $charge_to_ids, $row_endorsement_id) use ($endorsement_company_ids) {
            if (
                strtolower((string) $charge_value) === 'company'
                && ($charge_to_ids === null || $charge_to_ids === '')
            ) {
                return $endorsement_company_ids[$row_endorsement_id] ?? null;
            }

            return $charge_to_ids;
        };

        $services = $this->endorsement_service->get_details(array_values($endorsement_ids)) ?: [];
        if ($charge_ids) {
            $services = array_values(array_filter($services, static function ($service) use ($matches_charge_to, $resolve_charge_to_ids) {
                $effective_ids = $resolve_charge_to_ids(
                    $service['charge_to'] ?? null,
                    $service['charge_to_ids'] ?? null,
                    $service['endorsement_id'] ?? null
                );
                return $matches_charge_to($service['charge_to'] ?? null, $effective_ids);
            }));
        }

        $billed_by_endorsement = $this->billing_entry->get_billed_floaters_grouped(
            $company_id,
            $charge_to,
            null,
            array_values($endorsement_ids)
        );

        $endorsement_patients = $this->endorsement_patient->get_patient_for_billing($endorsement_ids) ?: [];
        $named = [];
        foreach ($endorsement_patients as $ep) {
            $ep_type = $ep->type ?? null;
            $ep_type_id = $ep->type_id ?? null;
            $patient_id = $ep->patient_id ?? null;
            if (!$ep_type || !$ep_type_id || $patient_id === null || $patient_id === '' || $patient_id === '0') {
                continue;
            }
            $effective_ep_ids = $resolve_charge_to_ids(
                $ep->charge_to ?? null,
                $ep->charge_to_ids ?? null,
                $ep->endorsement_id ?? null
            );
            if (!$matches_charge_to($ep->charge_to ?? null, $effective_ep_ids)) {
                continue;
            }

            $patient_id = (int) $patient_id;
            $billed_masters = $billed_by_endorsement[(int) $ep->endorsement_id]['master_patient_ids'] ?? [];
            if (in_array($patient_id, $billed_masters, true)) {
                continue;
            }

            $ep_name = $ep->patient_name ?? null;
            if (isset($service_booked_names[$ep->endorsement_id][$ep_type][$ep_type_id][$ep_name])) {
                continue;
            }

            if (!isset($named[$ep->endorsement_id][$patient_id])) {
                $named[$ep->endorsement_id][$patient_id] = [
                    'patient_name' => $ep_name,
                    'charge_to' => $ep->charge_to_ids !== null && $ep->charge_to_ids !== ''
                        ? (int) $ep->charge_to_ids
                        : null,
                    'total_amount' => 0.0,
                    'booking_details' => [],
                    'service_keys' => [],
                ];
            }

            $named[$ep->endorsement_id][$patient_id]['total_amount'] += (float) ($ep->type_price ?? 0);
            $named[$ep->endorsement_id][$patient_id]['booking_details'][] = [
                'id' => null,
                'result_status' => null,
                'result_approval' => null,
                'type' => $ep_type,
                'test_id' => (string) $ep_type_id,
                'amount' => $ep->total_amount ?? null,
                'pro_rate_price' => $ep->type_price ?? null,
                'type_name' => $ep->type_name ?? null,
                'endorsement_id' => (string) $ep->endorsement_id,
            ];
            $named[$ep->endorsement_id][$patient_id]['service_keys'][$ep_type . '|' . $ep_type_id] = true;
        }

        $synthetic_indexes = [];
        $floating_index_by_endorsement = [];
        $processed_service_keys = [];
        $emitted_named = [];

        $next_floating_no = static function ($row_endorsement_id) use (
            &$floating_index_by_endorsement,
            $billed_by_endorsement
        ) {
            $eid = (int) $row_endorsement_id;
            $billed_nos = [];
            foreach ($billed_by_endorsement[$eid]['nos'] ?? [] as $no) {
                $billed_nos[$no] = true;
            }
            if (!isset($floating_index_by_endorsement[$eid])) {
                $floating_index_by_endorsement[$eid] = 0;
            }
            do {
                $floating_index_by_endorsement[$eid]++;
            } while (isset($billed_nos[$floating_index_by_endorsement[$eid]]));

            return $floating_index_by_endorsement[$eid];
        };

        $add_floater = function (
            $row_endorsement_id,
            $patient_name,
            $type_price,
            $master_patient_id,
            $charge_to_id,
            array $booking_details
        ) use (
            &$transactions,
            &$existing_patients,
            &$synthetic_indexes,
            $company_id,
            $next_floating_no,
            $branch_id,
            $map
        ) {
            if ($master_patient_id) {
                $dedupe_key = 'p:' . $master_patient_id;
                if (isset($synthetic_indexes[$row_endorsement_id][$dedupe_key])) {
                    $idx = $synthetic_indexes[$row_endorsement_id][$dedupe_key];
                    $transactions[$idx]['total_amount'] += $type_price;
                    if ($booking_details) {
                        $transactions[$idx]['booking_details'] = array_merge(
                            $transactions[$idx]['booking_details'],
                            $booking_details
                        );
                    }
                    return true;
                }
            }

            if ($patient_name && isset($existing_patients[$row_endorsement_id][$patient_name])) {
                return false;
            }

            $floating_patient_no = $next_floating_no($row_endorsement_id);
            if ($master_patient_id === null) {
                $patient_name = 'Floating Patient ' . $floating_patient_no;
            }
            $dedupe_key = $master_patient_id
                ? 'p:' . $master_patient_id
                : 'f:' . $floating_patient_no;
            $clinic_map = $this->clinic_branch_db_map();
            if ($master_patient_id === null) {
                $row_branch_id = 2;
                $row_branch_name = $clinic_map[2] ?? ($map[2] ?? 'oakridge');
            } else {
                $row_branch_id = $branch_id !== null && $branch_id !== '' ? (int) $branch_id : null;
                $row_branch_name = ($row_branch_id !== null && isset($map[$row_branch_id]))
                    ? $map[$row_branch_id]
                    : ($row_branch_id !== null && isset($clinic_map[$row_branch_id]) ? $clinic_map[$row_branch_id] : null);
            }
            $row = [
                'transaction_id' => 0,
                'booking_id' => 0,
                'transaction_date' => null,
                'company_id' => $company_id,
                'charge_to' => $charge_to_id,
                'status' => null,
                'transaction_no' => null,
                'patient_name' => $patient_name,
                'endorsement_id' => $row_endorsement_id,
                'total_amount' => $type_price,
                'booking_details' => $booking_details,
                'is_floating' => 1,
                'floating_patient_no' => $floating_patient_no,
                'master_patient_id' => $master_patient_id,
                'branch_id' => $row_branch_id,
                'branch_name' => $row_branch_name,
            ];

            $transactions[] = $row;
            $synthetic_indexes[$row_endorsement_id][$dedupe_key] = count($transactions) - 1;
            $existing_patients[$row_endorsement_id][$patient_name] = true;

            return true;
        };

        $emit_named = function ($row_endorsement_id, $patient_id) use (
            &$named,
            &$emitted_named,
            $add_floater
        ) {
            if (isset($emitted_named[$row_endorsement_id][$patient_id])) {
                return true;
            }
            if (!isset($named[$row_endorsement_id][$patient_id])) {
                return false;
            }

            $info = $named[$row_endorsement_id][$patient_id];
            $added = $add_floater(
                $row_endorsement_id,
                $info['patient_name'],
                $info['total_amount'],
                $patient_id,
                $info['charge_to'],
                $info['booking_details']
            );
            if ($added) {
                $emitted_named[$row_endorsement_id][$patient_id] = true;
            }

            return $added;
        };

        foreach ($services as $service) {
            $row_endorsement_id = $service['endorsement_id'];
            $type = $service['type'];
            $type_id = $service['type_id'];
            $processed_service_keys[$row_endorsement_id . '|' . $type . '|' . $type_id] = true;

            $guaranteed_headcount = $service['guaranteed_headcount'] ?? null;
            $pro_rate_price = (float) ($service['pro_rate_price'] ?? 0);
            $booked = (int) ($service_booked_count[$row_endorsement_id][$type][$type_id] ?? 0);
            $anon_charge_to = $endorsement_company_ids[$row_endorsement_id] ?? $company_id;

            $candidates_named = [];
            foreach ($named[$row_endorsement_id] ?? [] as $patient_id => $info) {
                if (!isset($info['service_keys'][$type . '|' . $type_id])) {
                    continue;
                }
                $candidates_named[$patient_id] = $info;
            }

            if ($guaranteed_headcount !== null && $guaranteed_headcount !== '' && (float) $guaranteed_headcount != 0) {
                $billed_count = count($billed_by_endorsement[(int) $row_endorsement_id]['nos'] ?? []);
                $slots = max(0, (float) $guaranteed_headcount - $booked - $billed_count);
                $named_added = 0;

                foreach ($candidates_named as $patient_id => $info) {
                    if ($named_added >= $slots) {
                        break;
                    }
                    if ($emit_named($row_endorsement_id, $patient_id)) {
                        $named_added++;
                    }
                }

                $anon_details = [
                    [
                        'id' => null,
                        'result_status' => null,
                        'result_approval' => null,
                        'type' => $type,
                        'test_id' => (string) $type_id,
                        'amount' => $service['total_amount'] ?? null,
                        'pro_rate_price' => $service['pro_rate_price'] ?? null,
                        'type_name' => $service['type_name'] ?? null,
                        'endorsement_id' => (string) $row_endorsement_id,
                    ],
                ];

                $remaining_float = (int) floor($slots - $named_added);
                $allow_anonymous = ($branch_id === null || $branch_id === '' || (int) $branch_id === 2);
                $added_float = 0;
                while ($allow_anonymous && $added_float < $remaining_float) {
                    $add_floater(
                        $row_endorsement_id,
                        null,
                        $pro_rate_price,
                        null,
                        $anon_charge_to,
                        $anon_details
                    );
                    $added_float++;
                }
            } else {
                foreach ($candidates_named as $patient_id => $info) {
                    $emit_named($row_endorsement_id, $patient_id);
                }
            }
        }

        foreach ($named as $row_endorsement_id => $patients) {
            foreach ($patients as $patient_id => $info) {
                $has_unprocessed = false;
                foreach ($info['service_keys'] as $service_key => $unused) {
                    if (!isset($processed_service_keys[$row_endorsement_id . '|' . $service_key])) {
                        $has_unprocessed = true;
                        break;
                    }
                }
                if ($has_unprocessed) {
                    $emit_named($row_endorsement_id, $patient_id);
                }
            }
        }

        return $transactions;
    }

    /**
     * Get soa
     */
    public function get_soa()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $billing_id = $this->payload['billing_id'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $charge_to = $this->payload['charge_to'] ?? null;
        $status = $this->payload['status'] ?? null;
        $entry_page = filter_var($this->payload['entry_page'] ?? null, FILTER_VALIDATE_INT);
        $entry_data_size = filter_var($this->payload['entry_data_size'] ?? null, FILTER_VALIDATE_INT);
        if ($entry_page === false || $entry_page < 1 || $entry_data_size === false || $entry_data_size < 1) {
            return $this->_create_response(FAIL, ['entry_page and entry_data_size are required positive integers.']);
        }
        $entry_data_size = min($entry_data_size, 100);
        [$page, $data_size] = list_page_params($this->payload);
        $billing_soas = $this->billing->get_soa($billing_id, $date_from, $date_to, $company_id, $charge_to, $status, $page, $data_size) ?: [];
        $size = $this->billing->count_soa($billing_id, $date_from, $date_to, $company_id, $charge_to, $status);

        if (!$billing_soas) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No billing soa found.'
            ];
        } else {
            $company_ids = [];
            $all_billing_ids = [];
            foreach ($billing_soas as $billing_soa) {
                $company_ids[] = $billing_soa['company_id'] ?? null;
                $company_ids[] = $billing_soa['charge_to'] ?? null;
                foreach (array_filter(array_map('intval', explode(',', (string) ($billing_soa['billing_ids'] ?? '')))) as $grouped_billing_id) {
                    $all_billing_ids[] = $grouped_billing_id;
                }
            }
            $companies = $this->_companies_by_ids($company_ids);
            $packed = $this->billing_entry->get_details_for_billing_ids($all_billing_ids, null, $entry_page, $entry_data_size);
            $entries_by_group = [];
            foreach ($packed['entries'] as $entry) {
                $key = ($entry['company_id'] ?? '') . ':' . ($entry['charge_to'] ?? '');
                $entries_by_group[$key][] = $this->_decorate_billing_entry($entry, $entry['charge_to'] ?? null, false);
            }

            foreach ($billing_soas as $key => $billing_soa) {
                $company = $companies[(int) ($billing_soa['company_id'] ?? 0)] ?? null;
                $billing_soas[$key]['company_name'] = $company?->name;
                $billing_soas[$key]['company_address'] = $company?->address;
                $charge_to_row = $companies[(int) ($billing_soa['charge_to'] ?? 0)] ?? null;
                $billing_soas[$key]['charge_to_name'] = $charge_to_row?->name;
                $group_key = ($billing_soa['company_id'] ?? '') . ':' . ($billing_soa['charge_to'] ?? '');
                $billing_soas[$key]['billing_entry_count'] = $packed['counts'][$group_key] ?? 0;
                $billing_soas[$key]['billing_entries'] = $entries_by_group[$group_key] ?? [];
                $totals = $packed['totals'][$group_key] ?? [
                    'grand_total' => 0.0,
                    'paid_amount' => 0.0,
                    'balance' => 0.0,
                ];
                $billing_soas[$key]['grand_total'] = round($totals['grand_total'], 2);
                $billing_soas[$key]['paid_amount'] = round($totals['paid_amount'], 2);
                $billing_soas[$key]['balance'] = round($totals['balance'], 2);
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $billing_soas
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get mobile payment
     */
    public function get_mobile_payment()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $payment_id = $this->payload['payment_id'] ?? null;
        $transaction_id = $this->payload['transaction_id'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $invoice_status = $this->payload['invoice_status'] ?? null;
        $mobile_van_id = $this->payload['mobile_van_id'] ?? null;
        $mode_of_payment = $this->payload['mode_of_payment'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if (!$this->resolve_branch_models(1)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $mobile_payments = $this->payment->get_payment($payment_id, $transaction_id, $date_from, $date_to, $invoice_status, $mobile_van_id, $mode_of_payment, $term, $page, $data_size)?:[];
        $size = count($this->payment->get_payment($payment_id, $transaction_id, $date_from, $date_to, $invoice_status, $mobile_van_id, $mode_of_payment, $term, null, null)?:[]);

        if(!$mobile_payments) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No billing soa found.'
            ];
        } else {
            foreach($mobile_payments as $key => $mobile_payment) {
                $transaction = $this->payment->get_transaction($mobile_payment['transaction_id']) ?: [];
                $mobile_payments[$key]['transaction'] = $transaction[0] ?? null;

                $payment_multiple_method = $this->payment->get_payment_multiple_method($mobile_payment['id']) ?: [];
                $mobile_payments[$key]['payment_multiple_method'] = $payment_multiple_method;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $mobile_payments
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Change status of a mobile payment_multiple_method (posted / unposted).
     * Always uses the mobile clinic DB.
     */
    public function change_status_mobile_payment_method()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        if (!$this->resolve_branch_models(1)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $status = $this->payload['status'] ?? null;
        $payment_multiple_method_id = $this->payload['payment_multiple_method_id'] ?? null;
        $where = [
            'id' => $payment_multiple_method_id,
            'is_deleted' => 0
        ];

        $mobile_db = db_connect('mobile');
        $mobile_db->transBegin();

        if (!$payment_multiple_method_id) {
            $mobile_db->transRollback();
            $response_status = FAIL;
            $response = [
                'payment_multiple_method_id is required.'
            ];
        } elseif (!in_array($status, ['posted', 'unposted'], true)) {
            $mobile_db->transRollback();
            $response_status = FAIL;
            $response = [
                'Invalid status. Valid statuses are: posted, unposted.',
            ];
        } elseif (!$payment_multiple_method = $this->payment_multiple_method->select('', $where, 1)) {
            $mobile_db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'payment_multiple_method is not found.'
            ];
        } elseif ($payment_multiple_method->status == $status) {
            $mobile_db->transRollback();
            $response_status = FAIL;
            $response = [
                "payment_multiple_method is already $status.",
            ];
        } elseif (!$this->_attempt_update_mobile_payment_method_status($payment_multiple_method)) {
            $mobile_db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change payment_multiple_method status.',
            ];
        } else {
            $mobile_db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'payment_multiple_method_id' => $payment_multiple_method_id,
                'message' => "payment_multiple_method $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update mobile payment_multiple_method status
     */
    protected function _attempt_update_mobile_payment_method_status($payment_multiple_method)
    {
        $status = $this->payload['status'];
        $values = [
            'status' => $status,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if ($status === 'posted') {
            $values['posted_by'] = $this->payload['requester'];
            $values['posted_on'] = date('Y-m-d H:i:s');
        } else {
            $values['posted_by'] = null;
            $values['posted_on'] = null;
        }

        $where = ['id' => $payment_multiple_method->id];

        if (!$this->payment_multiple_method->update($where, $values)) {
            $this->error_message = $this->payment_multiple_method->error();
            return false;
        }

        return true;
    }

    /**
     * Update payment
     */
    public function update_payment()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        if (!$this->resolve_branch_models(1)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $payment_id = $this->payload['payment_id'];
        $where = [
                    'id' => $payment_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$payment = $this->payment->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'payment is not found.'
            ];
        } elseif (!$this->_attempt_update_payment($payment)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update payment.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'payment_id' => $payment_id,
                'message'    => 'payment updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update_payment($payment)
    {
        $values = [
            'invoice_id'            => $this->payload['invoice_id'] ?? null,
            'invoice_status'            => $this->payload['invoice_status'] ?? null,
            'print_counter' => ($this->payload['invoice_status'] == 'printed') ? $payment->print_counter + 1 : $payment->print_counter,
            'printed_by'            => $this->payload['requester'],
            'printed_on'            => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $payment->id];

        if (!$this->payment->update($where, $values)) {
            $this->error_message = $this->payment->error();
            return false;
        }
        return true;
    }

    /** Sort rows by resolved patient name, placing missing names last. */
    protected function _sort_patient_name_rows(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $left_name = trim((string) ($left['patient_name'] ?? ''));
            $right_name = trim((string) ($right['patient_name'] ?? ''));
            if ($left_name === '' || $right_name === '') {
                if ($left_name === '' && $right_name !== '') {
                    return 1;
                }
                if ($right_name === '' && $left_name !== '') {
                    return -1;
                }
            }

            return strcasecmp($left_name, $right_name);
        });

        return $rows;
    }

    /**
     * Get billing entries
     */
    public function get_entry()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $billing_entry_id = $this->payload['billing_entry_id'] ?? null;
        $billing_id = $this->payload['billing_id'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $charge_to = $this->payload['charge_to'] ?? $this->payload['charge_to_id'] ?? null;
        $term = trim((string) ($this->payload['term'] ?? ''));
        $screen = ($this->payload['screen'] ?? null) === 'credit_memo' ? 'credit_memo' : 'collection';
        $with_invoice = $this->payload['with_invoice'] ?? null;
        [$page, $data_size] = list_page_params($this->payload);

        $billing_entries = $this->billing_entry->get_details($billing_entry_id, $billing_id, $company_id, $charge_to, $screen, $with_invoice, $page, $data_size, true, false, $term) ?: [];
        $size = $this->billing_entry->count_details($billing_entry_id, $billing_id, $company_id, $charge_to, $screen, $with_invoice, $term);

        if (!$billing_entries) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No billing_entries found.'
            ];
        } else {
            foreach ($billing_entries as $key => $billing_entry) {
                $billing_entries[$key] = $this->_decorate_billing_entry($billing_entry, $billing_entry['charge_to'] ?? null, false);
                unset($billing_entries[$key]['_patient_name_sort']);
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $billing_entries
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get transactions
     */
    public function get_transaction()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $company_id = $this->payload['company_id'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $charge_to = $this->payload['charge_to_id'] ?? null;
        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $branch_id = $this->normalize_clinic_branch_id($this->payload['branch_id'] ?? null);
        $term = $this->payload['term'] ?? null;
        if ($term === '') {
            $term = null;
        }

        $branch_map = $this->clinic_branch_db_map();

        if ($branch_id !== null) {
            if (!$this->resolve_branch_models($branch_id)) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Branch ID.']
                );
            }
            $branch_map = array_intersect_key($branch_map, [$branch_id => true]);
        }

        $transactions = $this->booking_all->get_transaction_all_branches(
            $branch_map,
            $company_id,
            $date_from,
            $date_to,
            $charge_to,
            $endorsement_id
        ) ?: [];

        /**
         * Attach booking details
         */
        foreach ($transactions as $key => $transaction) {
            if (!$this->resolve_branch_models($transaction['branch_id'])) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Branch ID for transaction ' . $transaction['booking_id'] . '.']
                );
            }

            $channel = $transaction['billing_channel'] ?? '';
            if ($channel === 'tranche_1' || $channel === 'yakap_fpe') {
                $booking_details = Yakap_billing::tranche_1_booking_details();
            } elseif ($channel === 'tranche_2') {
                $booking_details = Yakap_billing::tranche_2_booking_details();
            } elseif ($transaction['billing_channel'] == 'consultation') {
                $booking_details = [
                    [
                    'id' => null,
                    'result_status' => null,
                    'result_approval' => null,
                    'type' => null,
                    'test_id' => null,
                    'type_name' => 'Consultation',
                    ]
                ];
            } elseif ($transaction['billing_channel'] == 'company_wallet') {
                $booking_details = [
                    [
                    'id' => null,
                    'result_status' => null,
                    'result_approval' => null,
                    'type' => null,
                    'test_id' => null,
                    'type_name' => 'Company Wallet',
                    ]
                ];
            } else {
                $row_charge_to = $channel == 'company_wallet'
                    ? null
                    : ($transaction['charge_to'] ?? $charge_to);
                $booking_details = $this->booking_detail->get_details($transaction['booking_id'], $row_charge_to) ?: [];
            }
            $transactions[$key]['booking_details'] = $booking_details;
        }

        $transactions = $this->_append_endorsement_floaters_by_branch(
            $transactions,
            $company_id,
            $charge_to,
            $branch_id,
            $endorsement_id,
            $branch_map
        );
        $transactions = $this->_attach_charge_to_names($transactions);

        if ($term !== null) {
            $needle = (string) $term;
            $transactions = array_values(array_filter($transactions, static function ($row) use ($needle) {
                return stripos((string) ($row['patient_name'] ?? ''), $needle) !== false;
            }));
        }

        $transactions = $this->_sort_patient_name_rows($transactions);

        if (!$transactions) {
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['No transactions found.']
            );
        }

        $response = [
            'data' => $transactions
        ];

        return $this->_create_response(SUCCESS, $response);
    }

    /**
     * Get transactions
     */
    public function get_all_transaction()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $company_id = $this->payload['company_id'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $charge_to = $this->payload['charge_to_id']
            ?? $this->payload['charge_to']
            ?? null;
        if ($charge_to === '' || $charge_to === '0') {
            $charge_to = null;
        }
        $charge_to_ids = $this->payload['charge_to_ids'] ?? null;
        if (is_string($charge_to_ids) && $charge_to_ids !== '') {
            $charge_to_ids = array_filter(array_map('trim', explode(',', $charge_to_ids)));
        } elseif (empty($charge_to_ids)) {
            $charge_to_ids = $charge_to ? [(int) $charge_to] : null;
        }
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $term = $this->payload['term'] ?? null;
        if ($term === '') {
            $term = null;
        }
        $branch_id = $this->normalize_clinic_branch_id($this->payload['branch_id'] ?? null);

        $branch_map = $this->clinic_branch_db_map();

        if ($branch_id !== null) {
            if (!$this->resolve_branch_models($branch_id)) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Branch ID.']
                );
            }
            $branch_map = array_intersect_key($branch_map, [$branch_id => true]);
        }

        $pack = $this->booking_all->get_all_transaction_all_branches(
            $branch_map,
            $company_id,
            $date_from,
            $date_to,
            $charge_to_ids,
            null,
            null,
            $term
        );
        $transactions = $pack['data'];
        $size = $pack['size'];

        $before = count($transactions);
        $transactions = $this->_append_endorsement_floaters_by_branch(
            $transactions,
            $company_id,
            $charge_to_ids ?: $charge_to,
            $branch_id,
            null,
            $branch_map
        );
        $size += count($transactions) - $before;
        $transactions = $this->_attach_charge_to_names($transactions);
        $transactions = $this->_sort_patient_name_rows($transactions);

        if ($page && $data_size) {
            $page = max(1, (int) $page);
            $data_size = max(1, (int) $data_size);
            $transactions = array_slice($transactions, ($page - 1) * $data_size, $data_size);
        }

        if ($transactions) {
            foreach ($transactions as $key => $transaction) {
                if (!$this->resolve_branch_models((int) ($transaction['branch_id'] ?? 0))) {
                    return $this->_create_response(
                        FAIL,
                        ['Invalid Branch ID for transaction ' . ($transaction['booking_id'] ?? '') . '.']
                    );
                }

                $channel = $transaction['billing_channel'] ?? '';
                if ($channel === 'tranche_1' || $channel === 'yakap_fpe') {
                    $booking_details = Yakap_billing::tranche_1_booking_details();
                } elseif ($channel === 'tranche_2') {
                    $booking_details = Yakap_billing::tranche_2_booking_details();
                } elseif ($channel === 'consultation' || $channel === 'company_wallet') {
                    $booking_details = [[
                        'id' => null,
                        'result_status' => null,
                        'result_approval' => null,
                        'type' => null,
                        'test_id' => null,
                        'type_name' => $channel === 'consultation' ? 'Consultation' : 'Company Wallet',
                    ]];
                } else {
                    $row_charge_to = $channel === 'company_wallet'
                        ? null
                        : ($transaction['charge_to'] ?? $charge_to);
                    $booking_details = $this->booking_detail->get_details($transaction['booking_id'] ?? null, $row_charge_to) ?: [];
                }
                $transactions[$key]['booking_details'] = $booking_details;
            }
        } else {
            $transactions = [];
        }

        if (!$transactions) {
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['No transactions found.']
            );
        }

        $response = [
            'size' => $size,
            'data' => $transactions
        ];

        return $this->_create_response(SUCCESS, $response);
    }

    /**
     * Get billing collection
     */
    public function get_collection()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $billing_payment_id = $this->payload['billing_payment_id'] ?? null;
        $mop = $this->payload['mop'] ?? null;
        $date = $this->payload['date'] ?? null;
        $charge_to = $this->payload['charge_to'] ?? null;
        [$page, $data_size] = list_page_params($this->payload);
        $is_unidentified = $this->payload['is_unidentified'] ?? null;
        if ($is_unidentified === '') {
            $is_unidentified = null;
        }

        $billing_payments = $this->billing_payment->get_details($billing_payment_id, $mop, $date, $charge_to, $page, $data_size, $is_unidentified) ?: [];
        $size = $this->billing_payment->count_details($billing_payment_id, $mop, $date, $charge_to, $is_unidentified);

        if (!$billing_payments) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No billing_payments found.'
            ];
        } else {
            $payment_ids = array_map('intval', array_column($billing_payments, 'id'));
            $companies = $this->_companies_by_ids(array_column($billing_payments, 'charge_to'));
            $entries_by_payment = $this->_collection_entries_service()->get_entries_by_payment_ids($payment_ids);
            $deposits = $this->billing_payment_deposit->get_details(null, $payment_ids) ?: [];
            $deposits_by_payment = [];
            foreach ($deposits as $deposit) {
                $deposits_by_payment[(int) $deposit['billing_payment_id']][] = $deposit;
            }
            $deposited_totals = $this->billing_payment_deposit->get_deposited_totals($payment_ids);

            foreach ($billing_payments as $key => $billing_payment) {
                $pid = (int) $billing_payment['id'];
                $sub_total = 0;
                $entries = $entries_by_payment[$pid] ?? [];
                foreach ($entries as $entry) {
                    $sub_total += $entry['amount'] ?? 0;
                }
                $charge = $companies[(int) ($billing_payment['charge_to'] ?? 0)] ?? null;
                $deposited_amount = $deposited_totals[$pid] ?? 0.0;
                $grand_total = (float) ($billing_payment['grand_total'] ?? 0);
                $billing_payments[$key]['charge_to_name'] = $charge?->name;
                $billing_payments[$key]['sub_total'] = $sub_total;
                $billing_payments[$key]['entries'] = $entries;
                $billing_payments[$key]['deposits'] = $deposits_by_payment[$pid] ?? [];
                $billing_payments[$key]['deposited_amount'] = $deposited_amount;
                $billing_payments[$key]['remaining_to_deposit'] = $grand_total - $deposited_amount;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $billing_payments
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create billing
     */
    public function get_tranche1()
    {
        return $this->_yakap_period_response(1);
    }

    public function get_tranche2()
    {
        return $this->_yakap_period_response(2);
    }

    protected function _yakap_period_response(int $tranche)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }
        if (($response = $this->_permission_allowed('get_transaction')) !== true) {
            return $response;
        }
        try {
            [$branch, $year] = \App\Models\Yakap_period_billing::validatePeriod($this->payload);
            $model = new \App\Models\Yakap_period_billing();
            return $this->_create_response(SUCCESS, $model->preview($branch, $year, $tranche));
        } catch (\InvalidArgumentException $error) {
            return $this->_create_response(FAIL, [$error->getMessage()]);
        } catch (\Throwable $error) {
            log_message('error', 'YAKAP preview failed: ' . $error->getMessage());
            return $this->_create_response(FAIL_SERVER_ERROR, ['Unable to calculate YAKAP billing. ' . ($error instanceof \RuntimeException ? $error->getMessage() : '')]);
        }
    }

    public function create_yakap_billing()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }
        if (($response = $this->_permission_allowed('create')) !== true) {
            return $response;
        }
        try {
            return $this->_create_response(SUCCESS, (new \App\Models\Yakap_period_billing())->createPeriod($this->payload));
        } catch (\InvalidArgumentException $error) {
            return $this->_create_response(FAIL, [$error->getMessage()]);
        } catch (\Throwable $error) {
            log_message('error', 'YAKAP creation failed: ' . $error->getMessage());
            return $this->_create_response(FAIL_SERVER_ERROR, ['Unable to create YAKAP billing.']);
        }
    }

    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $hasYakap = Yakap_billing::is_yakap($this->payload['company_id'] ?? null, $this->payload['charge_to'] ?? null);
        foreach (($this->payload['entries'] ?? []) as $entry) {
            $hasYakap = $hasYakap || Yakap_billing::tranche_from_entry($entry) !== null
                || Yakap_billing::is_yakap($entry['company_id'] ?? null, $entry['charge_to'] ?? null);
        }
        if ($hasYakap) {
            return $this->_create_response(FAIL, ['Use create_yakap_billing for YAKAP tranche billings.']);
        }

        $this->db->transBegin();

        if (!$billing_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add billing. ",
            ];
        } elseif (!$this->_attempt_create_entries($billing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add billing entries. " . ($this->error_message ?? ''),
            ];
        } elseif (!$this->_attempt_update_booking_billing($billing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update booking. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'billing_id' => $billing_id,
                'message'    => 'billing added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a billing
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->billing->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        $values['balance'] = $this->payload['grand_total'] ?? 0;
        $values['credit_memo_total'] = 0;
        $values['debit_memo_total'] = 0;
        if ((float) ($values['grand_total'] ?? 0) >= 50000) {
            $values['status'] = 'pending';
            $values['approved_by'] = null;
            $values['approved_on'] = null;
        } else {
            $values['status'] = 'approved';
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
        }

        if (!$billing_id = $this->billing->insert($values)) {
            $this->error_message = $this->billing->error();
            return false;
        }

        return $billing_id;
    }

    /**
     * Attempt to create billing entries
     */
    private function _attempt_create_entries($billing_id)
    {
        if (!array_key_exists('entries', $this->payload) || !is_array($this->payload['entries']) || count($this->payload['entries']) == 0) {
            return true;
        }

        foreach ($this->payload['entries'] as $entry) {
            if (!isset($entry['branch_id']) || $entry['branch_id'] === '' || $entry['branch_id'] === null) {
                $this->error_message = 'Branch ID is required for each billing entry.';
                return false;
            }

            $values = [
                'billing_id'    => $billing_id,
                'branch_id'    => $entry['branch_id'],
                'company_id'   => array_key_exists('company_id', $entry) && $entry['company_id'] != '' ? $entry['company_id'] : null,
                'booking_id'   => array_key_exists('booking_id', $entry) && $entry['booking_id'] != '' ? $entry['booking_id'] : null,
                'transaction_id'   => array_key_exists('transaction_id', $entry) && $entry['transaction_id'] != '' ? $entry['transaction_id'] : null,
                'endorsement_id'   => array_key_exists('endorsement_id', $entry) && $entry['endorsement_id'] != '' ? $entry['endorsement_id'] : null,
                'master_patient_id' => array_key_exists('master_patient_id', $entry) && $entry['master_patient_id'] != '' ? $entry['master_patient_id'] : null,
                'amount'   => array_key_exists('amount', $entry) && $entry['amount'] != ''
                    ? $entry['amount']
                    : ($entry['total_amount'] ?? 0),
                'balance'   => array_key_exists('amount', $entry) && $entry['amount'] != ''
                    ? $entry['amount']
                    : ($entry['total_amount'] ?? 0),
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            $yakap_tranche = Yakap_billing::tranche_from_entry($entry);
            if ($yakap_tranche) {
                $values['booking_id'] = null;
                $values['transaction_id'] = null;
                $values['master_patient_id'] = null;
                $values['as_of_date'] = $entry['as_of_date']
                    ?? $entry['transaction_date']
                    ?? null;
                $values['yakap_tranche'] = $yakap_tranche;
                $values['yakap_patient_ids'] = $this->_yakap_list_value(
                    $entry['yakap_patient_ids'] ?? ($entry['patient_id'] ?? null)
                );
                $values['yakap_master_patient_ids'] = $this->_yakap_list_value(
                    $entry['yakap_master_patient_ids'] ?? ($entry['master_patient_id'] ?? null)
                );
                $values['yakap_patient_names'] = $this->_yakap_list_value(
                    $entry['yakap_patient_names'] ?? ($entry['patient_name'] ?? null)
                );
            } else {
                $is_floater = ($values['booking_id'] === 0 || $values['booking_id'] === '0')
                    && ($values['transaction_id'] === 0 || $values['transaction_id'] === '0');
                if ($is_floater) {
                    $floating_patient_no = $entry['floating_patient_no'] ?? null;
                    if ($floating_patient_no === null || $floating_patient_no === '') {
                        $this->error_message = 'floating_patient_no is required when booking_id and transaction_id are 0.';
                        return false;
                    }
                    $endorsement_id = $entry['endorsement_id'] ?? ($values['endorsement_id'] ?? null);
                    if ($endorsement_id === null || $endorsement_id === '') {
                        $this->error_message = 'endorsement_id is required when booking_id and transaction_id are 0.';
                        return false;
                    }
                    $master_patient_id = $entry['master_patient_id'] ?? ($entry['patient_id'] ?? null);
                    if ($master_patient_id !== null && $master_patient_id !== '') {
                        $values['master_patient_id'] = $master_patient_id;
                    } else {
                        $values['master_patient_id'] = null;
                    }
                    $values['booking_id'] = 0;
                    $values['transaction_id'] = 0;
                    $values['endorsement_id'] = (int) $endorsement_id;
                    $values['floating_patient_no'] = (int) $floating_patient_no;
                }
            }

            if (!$this->billing_entry->insert($values)) {
                $this->error_message = $this->billing_entry->error();
                return false;
            }
        }

        return true;
    }
    
    /**
     * Attempt update bookings
     */
    private function _attempt_update_booking_billing($billing_id)
    {
        foreach ($this->payload['entries'] as $entry) {
            if (empty($entry['booking_id']) || strpos((string) $entry['booking_id'], ',') !== false) {
                continue;
            }

            if (!$this->resolve_branch_models((int) $entry['branch_id'])) {
                $this->error_message = 'Invalid Branch ID for booking ' . ($entry['booking_id'] ?? '') . '.';
                return false;
            }

            $booking = (array) $this->booking->select('id, paid_amount, payment_status, grand_total', ['id' => $entry['booking_id'], 'is_deleted' => 0], 1);

            $values = [
                'billing_id'  => $billing_id,
                'updated_by'      => $this->payload['requester'],
                'updated_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->booking->update(['id' => $booking['id']], $values)) {
                $this->error_message = $this->booking->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update billing
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $billing_id = $this->payload['billing_id'];
        try {
            (new \App\Models\Yakap_period_billing())->assertMetadataUpdate((int) $billing_id, $this->payload);
        } catch (\InvalidArgumentException $error) {
            return $this->_create_response(FAIL, [$error->getMessage()]);
        }
        $where = [
                    'id' => $billing_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$billing = $this->billing->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'billing is not found.'
            ];
        } elseif (!$this->_attempt_update($billing)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update billing.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'billing_id' => $billing_id,
                'message'    => 'billing updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($billing)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        foreach (['invoice_id', 'invoice_status', 'invoice_no', 'invoice_date'] as $field) {
            if (array_key_exists($field, $this->payload)) {
                $value = $this->payload[$field];
                $values[$field] = $value === '' || $value === null ? null : $value;
            }
        }

        $where = ['id' => $billing->id];

        if (!$this->billing->update($where, $values)) {
            $this->error_message = $this->billing->error();
            return false;
        }
        return true;
    }

    /**
     * Collect billing
     */
    public function collect()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $charge_to = $this->payload['charge_to'] ?? null;
        $billing_id = $this->payload['billing_id'] ?? null;
        $grand_total = $this->payload['grand_total'] ?? 0;

        $billing_ids = $this->billing->normalize_billing_ids($billing_id);

        if (!$billing_id && !$charge_to) {
            $response_status = FAIL;
            $response = [
                'billing_id and charge_to are required.'
            ];
        } elseif ($billing_ids !== null && !$billing_ids) {
            $response_status = FAIL;
            $response = [
                'Invalid billing_id.'
            ];
        } elseif ($grand_total <= 0) {
            $response_status = FAIL;
            $response = [
                'grand_total must be greater than 0.'
            ];
        } elseif ($billing_ids === null || !$charge_to) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'billing that is associated with the charge_to is not found.'
            ];
        } elseif (count($billings = $this->billing->get_by_ids_and_charge_to($billing_ids, $charge_to)) !== count($billing_ids)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'billing that is associated with the charge_to is not found.'
            ];
        } elseif (array_filter($billings, fn($billing) => !$this->billing->is_approved_for_collection($billing))) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Billing must be approved before collection.'
            ];
        } elseif (!$billing_payment_id = $this->_attempt_collect()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add collection. ",
            ];
        } elseif (!array_key_exists('entries', $this->payload) || !is_array($this->payload['entries']) || count($this->payload['entries']) == 0) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "No billing entries to collect. ",
            ];
        } elseif (!$this->_attempt_collect_entries($billing_payment_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add collection entries. ",
            ];
        } elseif (!$this->_attempt_update_bookings()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update bookings. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message'    => 'collection added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Record a bank collection before charge_to / billing entries are known.
     */
    public function collect_unidentified()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $grand_total = $this->payload['grand_total'] ?? 0;
        $mop = $this->payload['mop'] ?? null;
        $date = $this->payload['date'] ?? null;
        $charge_to = $this->payload['charge_to'] ?? null;
        $entries = $this->payload['entries'] ?? null;

        if ($charge_to !== null && $charge_to !== '' && $charge_to !== 0 && $charge_to !== '0') {
            $response_status = FAIL;
            $response = [
                'charge_to must not be sent for an unidentified collection.',
            ];
        } elseif (is_array($entries) && count($entries) > 0) {
            $response_status = FAIL;
            $response = [
                'entries must not be sent for an unidentified collection.',
            ];
        } elseif ($grand_total <= 0) {
            $response_status = FAIL;
            $response = [
                'grand_total must be greater than 0.',
            ];
        } elseif (!$mop) {
            $response_status = FAIL;
            $response = [
                'mop is required.',
            ];
        } elseif (!$date) {
            $response_status = FAIL;
            $response = [
                'date is required.',
            ];
        } else {
            $this->payload['charge_to'] = null;
            $this->payload['is_unidentified'] = 1;
            if (empty($this->payload['paid_date'])) {
                $this->payload['paid_date'] = $date;
            }

            $this->db->transBegin();

            if (!$billing_payment_id = $this->_attempt_collect()) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to add unidentified collection. ' . ($this->error_message ?? ''),
                ];
            } else {
                $this->db->transCommit();
                $response_status = SUCCESS;
                $response = [
                    'billing_payment_id' => $billing_payment_id,
                    'message' => 'unidentified collection added successfully.',
                ];
            }
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Apply charge_to and billing entries to an unidentified collection (up to grand_total - wht_amount).
     */
    public function identify_collection()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $billing_payment_id = $this->payload['billing_payment_id'] ?? null;
        $charge_to = $this->payload['charge_to'] ?? null;
        $entries = $this->payload['entries'] ?? null;

        $this->db->transBegin();

        if (!$billing_payment_id || !$charge_to) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'billing_payment_id and charge_to are required.',
            ];
        } elseif (!is_array($entries) || count($entries) == 0) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'entries are required.',
            ];
        } elseif (!$payment = $this->billing_payment->select('', ['id' => $billing_payment_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'collection not found.',
            ];
        } elseif ((int) ($payment->is_unidentified ?? 0) !== 1) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'collection is not unidentified.',
            ];
        } elseif (($entry_error = $this->_validate_identify_entries($entries, $charge_to, $payment)) !== true) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $entry_error,
            ];
        } elseif (($split_result = $this->_split_unidentified_remainder(
            (int) $billing_payment_id,
            $payment,
            $this->_sum_identify_entry_amounts($entries)
        )) === false) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to split unidentified collection remainder. ' . ($this->error_message ?? ''),
            ];
        } elseif (!$this->_attempt_collect_entries($billing_payment_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to add collection entries. ' . ($this->error_message ?? ''),
            ];
        } elseif (!$this->_attempt_update_collect($billing_payment_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to identify collection. ' . ($this->billing_payment->error() ?? ''),
            ];
        } elseif (!$this->_attempt_update_bookings()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update bookings. ' . ($this->error_message ?? ''),
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'billing_payment_id' => $billing_payment_id,
                'message' => 'collection identified successfully.',
            ];
            if (is_int($split_result)) {
                $response['leftover_billing_payment_id'] = $split_result;
            }
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _identifiable_collection_net($payment): float
    {
        return max(0, (float) ($payment->grand_total ?? 0) - (float) ($payment->wht_amount ?? 0));
    }

    private function _sum_identify_entry_amounts(array $entries): float
    {
        $sum = 0.0;
        foreach ($entries as $entry) {
            $sum += isset($entry['amount']) ? (float) $entry['amount'] : 0;
        }

        return $sum;
    }

    /**
     * When identify applies less than the full net amount, shrink the original payment and
     * replicate the remainder as a new unidentified billing_payment.
     *
     * @return int|null|false null = no split needed; int = new leftover payment id; false = error
     */
    private function _split_unidentified_remainder(int $billing_payment_id, $payment, float $applied_net)
    {
        $identifiable_net = $this->_identifiable_collection_net($payment);
        if (round($applied_net, 4) >= round($identifiable_net, 4)) {
            return null;
        }
        if ($identifiable_net <= 0) {
            return null;
        }

        $leftover_net = $identifiable_net - $applied_net;
        $wht = (float) ($payment->wht_amount ?? 0);
        $wht_identified = round($wht * ($applied_net / $identifiable_net), 4);
        $wht_leftover = round($wht - $wht_identified, 4);

        $identified_grand = round($applied_net + $wht_identified, 4);
        $leftover_grand = round($leftover_net + $wht_leftover, 4);
        $orig_grand = round((float) $payment->grand_total, 4);
        if (round($identified_grand + $leftover_grand, 4) !== $orig_grand) {
            $leftover_grand = round($orig_grand - $identified_grand, 4);
            $wht_leftover = round($leftover_grand - $leftover_net, 4);
        }

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'];

        if (!$this->billing_payment->update(['id' => $billing_payment_id], [
            'grand_total' => $identified_grand,
            'wht_amount' => $wht_identified,
            'updated_by' => $requester,
            'updated_on' => $now,
        ])) {
            $this->error_message = $this->billing_payment->error();
            return false;
        }

        $clone = [
            'added_by' => $requester,
            'added_on' => $now,
            'charge_to' => null,
            'is_unidentified' => 1,
            'grand_total' => $leftover_grand,
            'wht_amount' => $wht_leftover,
        ];
        foreach ($this->billing_payment->allowedFields as $field) {
            if (array_key_exists($field, $clone)) {
                continue;
            }
            if (!property_exists($payment, $field)) {
                continue;
            }
            $clone[$field] = $payment->$field !== '' ? $payment->$field : null;
        }

        if (!$leftover_payment_id = $this->billing_payment->insert($clone)) {
            $this->error_message = $this->billing_payment->error();
            return false;
        }

        $deposits = $this->billing_payment_deposit->get_details(null, $billing_payment_id) ?: [];
        foreach ($deposits as $deposit) {
            $amount = (float) ($deposit['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $leftover_amount = round($amount * ($leftover_net / $identifiable_net), 4);
            $identified_amount = round($amount - $leftover_amount, 4);

            if (!$this->billing_payment_deposit->update(['id' => $deposit['id']], [
                'amount' => $identified_amount,
            ])) {
                $this->error_message = $this->billing_payment_deposit->error();
                return false;
            }

            if ($leftover_amount <= 0) {
                continue;
            }

            if (!$this->billing_payment_deposit->insert([
                'billing_payment_id' => $leftover_payment_id,
                'account_type_id' => $deposit['account_type_id'],
                'amount' => $leftover_amount,
                'added_by' => $requester,
                'added_on' => $now,
            ])) {
                $this->error_message = $this->billing_payment_deposit->error();
                return false;
            }
        }

        return (int) $leftover_payment_id;
    }

    /**
     * Validation for identify_collection (entry sum must not exceed net of WHT).
     *
     * @return true|string
     */
    private function _validate_identify_entries(array $entries, $charge_to, $payment)
    {
        $sum = 0.0;
        foreach ($entries as $entry) {
            $billing_entry_id = $entry['billing_entry_id'] ?? null;
            $amount = isset($entry['amount']) ? (float) $entry['amount'] : 0;
            if (!$billing_entry_id) {
                return 'billing_entry_id is required for each entry.';
            }
            if ($amount <= 0) {
                return 'each entry amount must be greater than 0.';
            }

            $billing_entry = $this->billing_entry->select('', ['id' => $billing_entry_id, 'is_deleted' => 0], 1);
            if (!$billing_entry) {
                return 'billing entry ' . $billing_entry_id . ' is not found.';
            }

            $billing = $this->billing->select('id, status, approved_by, approved_on', [
                'id' => $billing_entry->billing_id,
                'charge_to' => $charge_to,
                'is_deleted' => 0,
            ], 1);
            if (!$billing) {
                return 'billing entry ' . $billing_entry_id . ' does not belong to charge_to ' . $charge_to . '.';
            }
            if (!$this->billing->is_approved_for_collection((array) $billing)) {
                return 'billing entry ' . $billing_entry_id . ' belongs to a billing that is not approved.';
            }

            $sum += $amount;
        }

        $identifiable_net = $this->_identifiable_collection_net($payment);
        if (round($sum, 4) > round($identifiable_net, 4)) {
            return 'entry amounts must not exceed the collection amount net of withholding (grand_total - wht_amount).';
        }

        return true;
    }

    /**
     * Deposit a cash/check collection (billing_payment) to an account type.
     * Multiple partial deposits are allowed; the sum cannot exceed grand_total.
     */
    public function deposit()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $collection_id = $this->payload['collection_id'] ?? null;
        $account_type_id = $this->payload['account_type_id'] ?? null;
        $amount = isset($this->payload['amount']) ? (float) $this->payload['amount'] : null;
        $remaining = 0;

        if (!$collection_id || !$account_type_id || $amount === null) {
            $response_status = FAIL;
            $response = [
                'collection_id, account_type_id, and amount are required.',
            ];
        } elseif (!$collection = $this->billing_payment->select('', ['id' => $collection_id, 'is_deleted' => 0], 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'collection not found.',
            ];
        } elseif (!in_array(strtolower((string) $collection->mop), ['cash', 'check'], true)) {
            $response_status = FAIL;
            $response = [
                'Only cash or check collections can be deposited.',
            ];
        } elseif ($amount <= 0) {
            $response_status = FAIL;
            $response = [
                'amount must be greater than 0.',
            ];
        } elseif ($amount > ($remaining = (float) $collection->grand_total - $this->billing_payment_deposit->get_deposited_total($collection_id))) {
            $response_status = FAIL;
            $response = [
                'amount cannot exceed remaining depositable amount of ' . $remaining . '.',
            ];
        } elseif (!$deposit_id = $this->billing_payment_deposit->insert([
            'billing_payment_id' => $collection_id,
            'account_type_id'    => $account_type_id,
            'amount'             => $amount,
            'added_by'           => $this->payload['requester'],
            'added_on'           => date('Y-m-d H:i:s'),
        ])) {
            $response_status = FAIL;
            $response = [
                'Failed to add deposit.',
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'deposit_id' => $deposit_id,
                'remaining'  => $remaining - $amount,
                'message'    => 'deposit added successfully.',
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to collect a billing
     */
    private function _attempt_collect()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->billing_payment->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$billing_payment_id = $this->billing_payment->insert($values)) {
            $this->error_message = $this->billing_payment->error();
            return false;
        }

        if (strtolower(trim((string) ($values['mop'] ?? ''))) === 'check') {
            $check_deposit_values = [
                'billing_payment_id' => $billing_payment_id,
                'amount' => max(0, (float) ($values['grand_total'] ?? 0) - (float) ($values['wht_amount'] ?? 0)),
                'check_no' => $values['check_no'] ?? null,
                'check_date' => $values['check_date'] ?? null,
                'bank_name' => $values['bank_name'] ?? null,
                'reference_no' => $values['reference_no'] ?? null,
                'paid_date' => $values['paid_date'] ?? $values['date'] ?? date('Y-m-d'),
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s'),
            ];

            if (!$this->check_deposit->insert($check_deposit_values)) {
                $this->error_message = $this->check_deposit->error();
                return false;
            }
        }

        return $billing_payment_id;
    }

    /**
     * Attempt to collect billing entries
     */
    private function _attempt_collect_entries($billing_payment_id)
    {
        foreach ($this->payload['entries'] as $entry) {
            $billing_entry_id = $entry['billing_entry_id'] ?? null;
            if (!$billing_entry_id) {
                $this->error_message = 'billing_entry_id is required for each entry.';
                return false;
            }

            $billing_entry = $this->billing_entry->select('', ['id' => $billing_entry_id, 'is_deleted' => 0], 1);
            if (!$billing_entry) {
                $this->error_message = 'Billing entry ' . $billing_entry_id . ' was not found.';
                return false;
            }

            $billing = $this->billing->select('id, status, approved_by, approved_on', [
                'id' => $billing_entry->billing_id,
                'is_deleted' => 0,
            ], 1);
            if (!$billing || !$this->billing->is_approved_for_collection((array) $billing)) {
                $this->error_message = 'Billing entry ' . $billing_entry_id . ' belongs to a billing that is not approved.';
                return false;
            }

            $values = [
                'billing_payment_id'    => $billing_payment_id,
                'billing_entry_id' => $billing_entry_id,
                'amount'   => array_key_exists('amount', $entry) && $entry['amount'] != '' ? $entry['amount'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->billing_payment_entry->insert($values)) {
                $this->error_message = $this->billing_payment_entry->error();
                return false;
            }

            //update billing entry balance
            $billing_entry = (array) $billing_entry;
            $billing_entry_values = [
                'balance'  => max(0, $billing_entry['balance'] - $entry['amount']),
                'status'   => ($billing_entry['balance'] - $entry['amount']) <= 0 ? 'closed_bill' : 'open_bill',
                'updated_by'      => $this->payload['requester'],
                'updated_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->billing_entry->update(['id' => $billing_entry['id']], $billing_entry_values)) {
                $this->error_message = $this->billing_entry->error();
                return false;
            }

            //update billing balance
            $billing = (array) $this->billing->select('', ['id' => $billing_entry['billing_id'], 'is_deleted' => 0], 1);
            $billing_values = [
                'balance'  => max(0, $billing['balance'] - $entry['amount']),
                'status'   => ($billing['balance'] - $entry['amount']) <= 0 ? 'closed_bill' : 'open_bill',
                'updated_by'      => $this->payload['requester'],
                'updated_on'      => date('Y-m-d H:i:s'),
            ];
            if (!$this->billing->update(['id' => $billing['id']], $billing_values)) {
                $this->error_message = $this->billing->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Soft-delete the automated journal_entry for a billing_payment.
     * Must run in its own statement before the payment UPDATE trigger.
     */
    private function _clear_billing_payment_journal($billing_payment_id)
    {
        $now = date('Y-m-d H:i:s');
        $item_ok = $this->db->query(
            "UPDATE journal_entry_item
            INNER JOIN journal_entry
                ON journal_entry.id = journal_entry_item.journal_entry_id
                AND journal_entry.source = 'billing_payment'
                AND journal_entry.source_id = ?
                AND journal_entry.is_deleted = 0
            SET journal_entry_item.is_deleted = 1,
                journal_entry_item.updated_on = ?
            WHERE journal_entry_item.is_deleted = 0",
            [$billing_payment_id, $now]
        );
        if ($item_ok === false) {
            $this->error_message = $this->db->error()['message'] ?? 'Failed to clear journal entry items.';
            return false;
        }

        $header_ok = $this->db->query(
            "UPDATE journal_entry
            SET is_deleted = 1,
                updated_on = ?
            WHERE source = 'billing_payment'
                AND source_id = ?
                AND is_deleted = 0",
            [$now, $billing_payment_id]
        );
        if ($header_ok === false) {
            $this->error_message = $this->db->error()['message'] ?? 'Failed to clear journal entry.';
            return false;
        }

        return true;
    }

    /**
     * Attempt to update collect
     */
    private function _attempt_update_collect($billing_payment_id)
    {
        if (!$this->_clear_billing_payment_journal($billing_payment_id)) {
            return false;
        }

        $values = [
            'updated_by'              => $this->payload['requester'],
            'updated_on'              => date('Y-m-d H:i:s'),
            'is_unidentified'         => 0,
        ];
        if (isset($this->payload['charge_to']) && $this->payload['charge_to'] !== '' && $this->payload['charge_to'] !== null) {
            $values['charge_to'] = $this->payload['charge_to'];
        }

        if (!$this->billing_payment->update(['id' => $billing_payment_id], $values)) {
            $this->error_message = $this->billing_payment->error();
            return false;
        }

        return true;
    }

    /**
     * Attempt update bookings
     */
    private function _attempt_update_bookings()
    {
        foreach ($this->payload['entries'] as $entry) {
            $billing_entry = $this->billing_entry->select('', ['id' => $entry['billing_entry_id'], 'is_deleted' => 0], 1);
            if (!$billing_entry || !$this->resolve_branch_models((int) $billing_entry->branch_id)) {
                // $this->error_message = 'Invalid Branch ID for billing entry ' . ($entry['billing_entry_id'] ?? '') . '.';
                // return false;
                continue;
            }

            if (empty($billing_entry->booking_id)) {
                continue;
            }

            $booking = (array) $this->booking->select('id, paid_amount, payment_status, grand_total', ['id' => $billing_entry->booking_id, 'is_deleted' => 0], 1);
            if (empty($booking['id'])) {
                continue;
            }

            $paid_amount = (float) ($booking['paid_amount'] ?? 0);
            $grand_total = (float) ($booking['grand_total'] ?? 0);
            $entry_amount = (float) ($entry['amount'] ?? 0);

            $values = [
                'payment_status'  => $paid_amount + $entry_amount >= $grand_total ? 'paid' : 'partial',
                'paid_amount'     => $paid_amount + $entry_amount,
                'updated_by'      => $this->payload['requester'],
                'updated_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->booking->update(['id' => $booking['id']], $values)) {
                $this->error_message = $this->booking->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Change status billing
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $billing_id = $this->payload['billing_id'] ?? null;
        $where = [
                    'id' => $billing_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, first_name, last_name, middle_name, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$billing = $this->billing->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'billing is not found.'
            ];
        } elseif (!$entries = $this->billing_entry->get_details(null, $billing_id) ?: []) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "No billing entries found for this billing. ",
            ];
        } elseif (!$user || !in_array($user->role_id, [4,23,26])) {
            $response_status = FAIL;
            $response = [
                "Finance Manager, Finance Director or Admin users can change billing status. ",
            ];
        } elseif ($status == 'for_cancellation' && $billing->is_for_cancellation == 1) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Billing is already for cancellation. ",
            ];
        } elseif ($status && $status == 'cancelled' && $billing->is_for_cancellation == 0) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Billing must be for cancellation before it can be cancelled. ",
            ];
        } elseif ($status === 'approved' && ($billing->status !== 'pending' || (float) $billing->grand_total < 50000)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Only pending billings with a grand_total of at least 50,000 can be approved.'
            ];
        } elseif ($status && $billing->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Billing is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($billing, $entries, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change billing status.',
            ];
        } elseif ($status == 'cancelled' && !$this->_attempt_update_revert_booking_billing($billing->id, $entries)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to revert booking billing. ',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'billing_id' => $billing_id,
                'message'    => "billing $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($billing, $entries, $role_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'for_cancellation') {
            $values['for_cancellation_by'] = $this->payload['requester'];
            $values['for_cancellation_on'] = date('Y-m-d H:i:s');
            $values['is_for_cancellation'] = 1;
        } elseif ($status == 'cancelled') {
            $values['status'] = 'cancelled';
            $values['cancel_reason'] = $this->payload['cancel_reason'] ?? null;
            $values['cancelled_by'] = $this->payload['requester'];
            $values['cancelled_on'] = date('Y-m-d H:i:s');
        } elseif ($status === 'approved') {
            $values['status'] = 'approved';
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $billing->id];

        if (!$this->billing->update($where, $values)) {
            $this->error_message = $this->billing->error();
            return false;
        }

        return true;
    }

    /**
     * Attempt update revert bookings
     */
    private function _attempt_update_revert_booking_billing($billing_id, $entries)
    {
        foreach ($entries as $entry) {
            if (!$this->resolve_branch_models((int) $entry['branch_id'])) {
                $this->error_message = 'Invalid Branch ID for billing entry ' . ($entry['id'] ?? '') . '.';
                return false;
            }

            $booking = (array) $this->booking->select('id', ['id' => $entry['booking_id'], 'is_deleted' => 0], 1);
            if (!$booking) {
                continue;
            }

            $values = [
                'billing_id'  => null,
                'updated_by'      => $this->payload['requester'],
                'updated_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->booking->update(['id' => $booking['id']], $values)) {
                $this->error_message = $this->booking->error();
                return false;
            }
        }

        return true;
    }
}
