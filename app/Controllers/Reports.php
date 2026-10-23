<?php
namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\Company;
use App\Models\Doctor;
use App\Models\Endorsement;
use App\Models\Membership;
use App\Models\Report;
use App\Models\Report_clinic;
use App\Models\Request;
use App\Models\User;
use App\Models\User_portal;
use App\Models\Patient_journey;
use App\Libraries\Local_upload_file;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Reports extends MYT_Controller
{
    protected $request;
    protected $accounts_payable_voucher;
    protected $accounts_payable_voucher_payment;
    protected $accounts_payable_voucher_payment_detail;
    protected $receipt_note;
    protected $receipt_note_item;
    protected $petty_cash;
    protected $reimbursement;
    protected $reimbursement_item;
    protected $cash_advance;
    protected $liquidation;
    protected $liquidation_detail;
    protected $change_fund;
    protected $direct_payment;
    protected $purchase_order;
    protected $purchase_request;
    protected $replenishment;
    protected $replenishment_item;
    protected $account_type;
    protected $general_ledger;
    protected $user;
    protected $patient_journey;
    protected $billing;
    protected $billing_entry;
    protected $billing_payment;
    protected $billing_payment_entry;
    protected $collection_entries_service;
    protected $company;
    protected $branch_master;
    protected $doctor;
    protected $booking_doctors_fee;
    protected $booking;
    protected $request_model;
    protected $endorsement;
    protected $report;
    protected $report_clinic;
    protected $billed_and_sales;
    protected $collection_monitoring;
    protected $membership;
    protected $patient_diagnostic;
    protected $relief;
    protected $inventory_schedule;
    protected $sawt_report_attachment;
    protected $user_portal;
    protected $helpers = ['text'];
    private ?array $aged_payables_branch_groups = null;

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_sales_report_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_fda_dashboard_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_payment_terms_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_sales_tracker_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function sawt_report_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function uip_hmo_corporate_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function uip_lost_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_ppl_membership_corporate_excel()
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
        $this->accounts_payable_voucher                = new \App\Models\Accounts_payable_voucher();
        $this->accounts_payable_voucher_payment        = new \App\Models\Accounts_payable_voucher_payment();
        $this->accounts_payable_voucher_payment_detail = new \App\Models\Accounts_payable_voucher_payment_detail();
        $this->receipt_note                            = new \App\Models\Receipt_note();
        $this->receipt_note_item                       = new \App\Models\Receipt_note_item();
        $this->petty_cash                              = new \App\Models\Petty_cash();
        $this->reimbursement                           = new \App\Models\Reimbursement();
        $this->reimbursement_item                      = new \App\Models\Reimbursement_item();
        $this->cash_advance                            = new \App\Models\Cash_advance();
        $this->liquidation                             = new \App\Models\Liquidation();
        $this->liquidation_detail                      = new \App\Models\Liquidation_detail();
        $this->change_fund                             = new \App\Models\Change_fund();
        $this->direct_payment                          = new \App\Models\Direct_payment();
        $this->purchase_order                          = new \App\Models\Purchase_order();
        $this->purchase_request                        = new \App\Models\Purchase_request();
        $this->replenishment                           = new \App\Models\Replenishment();
        $this->replenishment_item                      = new \App\Models\Replenishment_item();
        $this->account_type                            = new \App\Models\Account_type();
        $this->general_ledger                          = new \App\Models\General_ledger();
        $this->billing                                 = new \App\Models\Billing();
        $this->billing_entry                           = new \App\Models\Billing_entry();
        $this->billing_payment                         = new \App\Models\Billing_payment();
        $this->billing_payment_entry                   = new \App\Models\Billing_payment_entry();
        $this->booking_doctors_fee                     = new \App\Models\Booking_doctors_fee();
        $this->booking                                 = new \App\Models\Booking();
        $this->user                                    = new User();
        $this->patient_journey                         = new Patient_journey();
        $this->company                                 = new Company();
        $this->branch_master                           = new Branch_master();
        $this->doctor                                  = new Doctor();
        $this->request_model                           = new Request();
        $this->endorsement                             = new Endorsement();
        $this->report                                  = new Report();
        $this->report_clinic                           = new Report_clinic();
        $this->membership                              = new Membership();
        $this->billed_and_sales                        = new \App\Models\Billed_and_sales();
        $this->collection_monitoring                   = new \App\Models\Collection_monitoring();
        $this->relief                                  = new \App\Models\Relief();
        $this->inventory_schedule                      = new \App\Models\Inventory_schedule();
        $this->sawt_report_attachment                  = new \App\Models\Sawt_report_attachment();
        $this->user_portal                             = new User_portal();
        $this->validation                              = \Config\Services::validation();
        $this->db                                      = \Config\Database::connect();
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

        if (! isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->accounts_payable_voucher                = new \App\Models\Accounts_payable_voucher($db);
        $this->accounts_payable_voucher_payment        = new \App\Models\Accounts_payable_voucher_payment($db);
        $this->accounts_payable_voucher_payment_detail = new \App\Models\Accounts_payable_voucher_payment_detail($db);
        $this->receipt_note                            = new \App\Models\Receipt_note($db);
        $this->receipt_note_item                       = new \App\Models\Receipt_note_item($db);
        $this->petty_cash                              = new \App\Models\Petty_cash($db);
        $this->reimbursement                           = new \App\Models\Reimbursement($db);
        $this->reimbursement_item                      = new \App\Models\Reimbursement_item($db);
        $this->cash_advance                            = new \App\Models\Cash_advance($db);
        $this->liquidation                             = new \App\Models\Liquidation($db);
        $this->liquidation_detail                      = new \App\Models\Liquidation_detail($db);
        $this->change_fund                             = new \App\Models\Change_fund($db);
        $this->direct_payment                          = new \App\Models\Direct_payment($db);
        $this->purchase_order                          = new \App\Models\Purchase_order($db);
        $this->purchase_request                        = new \App\Models\Purchase_request($db);
        $this->replenishment                           = new \App\Models\Replenishment($db);
        $this->replenishment_item                      = new \App\Models\Replenishment_item($db);
        $this->general_ledger                          = new \App\Models\General_ledger($db);
        $this->booking_doctors_fee                     = new \App\Models\Booking_doctors_fee($db);
        $this->booking                                 = new \App\Models\Booking($db);
        $this->report_clinic                           = new \App\Models\Report_clinic($db);
        $this->patient_diagnostic                      = new \App\Models\Patient_diagnostic($db);

        return $db;
    }

    /**
     * TAT reports accept a specific branch, or All (missing / empty / "all").
     * false means the payload named a branch that is not in the TAT set.
     */
    protected function _normalize_tat_branch_id()
    {
        $branch_id = $this->payload['branch_id'] ?? null;

        if ($branch_id === null || $branch_id === '' || strtolower((string) $branch_id) === 'all') {
            return null;
        }

        $branch_id = (int) $branch_id;
        if (! in_array($branch_id, [1, 2, 3, 4, 7], true)) {
            return false;
        }

        return $branch_id;
    }

    protected function _tat_branch_label($branch_id): string
    {
        $map = [
            1 => 'Mobile',
            2 => 'Oakridge',
            3 => 'Mactan',
            4 => 'Consolacion',
            7 => 'Mantlewood',
        ];

        return $map[(int) $branch_id] ?? '';
    }

    /**
     * Get patient journey
     */
    public function get_consent_report()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;
        $information_consent   = $this->payload['information_consent'] ?? null;
        $registration_consent   = $this->payload['registration_consent'] ?? null;
        $company_id    = $this->payload['company_id'] ?? null;
        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $term      = $this->payload['term'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $patient_consents = $this->patient_diagnostic->get_consent_report($date_from, $date_to, $information_consent, $registration_consent, $company_id, $endorsement_id, $term, $page, $data_size) ?: [];
        $size    = $this->patient_diagnostic->get_consent_report_count($date_from, $date_to, $information_consent, $registration_consent, $company_id, $endorsement_id, $term) ?: 0;

        if (! $patient_consents) {
            $response_status = FAIL_NOT_FOUND;
            $response        = [
                'No patient consents found.',
            ];
        } else {
            $response_status = SUCCESS;
            $response        = [
                'size' => $size,
                'data' => $patient_consents,
            ];
        }

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get corporate consent report from user_portal
     */
    public function get_corporate_consent()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $company_id = $this->payload['company_id'] ?? null;
        $date_from  = $this->payload['date_from'] ?? null;
        $date_to    = $this->payload['date_to'] ?? null;
        $term       = $this->payload['term'] ?? null;
        $page       = $this->payload['page'] ?? null;
        $data_size  = $this->payload['data_size'] ?? null;

        $corporate_consents = $this->user_portal->get_corporate_consent($company_id, $date_from, $date_to, $term, $page, $data_size) ?: [];
        $size = $this->user_portal->get_corporate_consent_count($company_id, $date_from, $date_to, $term) ?: 0;

        if (! $corporate_consents) {
            $response_status = FAIL_NOT_FOUND;
            $response        = [
                'No corporate consents found.',
            ];
        } else {
            $response_status = SUCCESS;
            $response        = [
                'size' => $size,
                'data' => $corporate_consents,
            ];
        }

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get patient journey
     */
    public function get_patient_journey()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->_normalize_tat_branch_id();
        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;
        $term      = $this->payload['term'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($branch_id === false) {
            return $this->_create_response(FAIL, ['Invalid Branch ID.']);
        }

        // The size is the number of transactions built below, so the patient count query this used
        // to run was answering a question the response no longer asks - and it cost a full extra
        // pass over the branch union to do it.
        $patients = $this->patient_journey->get_patient_journey_patient_page($branch_id, $date_from, $date_to, $term, $page, $data_size) ?: [];

        if (! $patients) {
            $response_status = FAIL_NOT_FOUND;
            $response        = [
                'No patient journeys found.',
            ];
        } else {
            $patient_ids = array_column($patients, 'patient_master_id');
            $tat_rows    = $this->patient_journey->get_patient_journey($branch_id, $date_from, $date_to, $term, null, null, $patient_ids) ?: [];

            $details_by_patient = [];
            foreach ($tat_rows as $row) {
                $details_by_patient[$row['patient_master_id']][] = $row;
            }

            $data = [];
            foreach ($patients as $patient) {
                $patient_id = $patient['patient_master_id'];
                $details    = $details_by_patient[$patient_id] ?? [];

                foreach ($this->_patient_journey_transactions($details) as $key => $transaction_rows) {
                    $steps      = $this->_build_patient_journey_steps($transaction_rows);
                    $waiting    = 0;
                    $processing = 0;

                    // The totals are read back off the steps so the row adds up to the breakdown
                    // the modal shows. Summing the raw entries instead measured a wait over its own
                    // entry rather than through to the moment the station picked the patient up.
                    foreach ($steps as $step) {
                        $waiting    += (float) ($step['waiting_time'] ?? 0);
                        $processing += (float) ($step['processing_time'] ?? 0);
                    }

                    $transaction_date = $this->_patient_journey_first_value($transaction_rows, 'transaction_date');
                    if ($transaction_date === '') {
                        $transaction_date = substr($this->_patient_journey_first_value($transaction_rows, 'time_start'), 0, 10);
                    }

                    $data[] = [
                        'row_key'                => $patient_id . '|' . $key,
                        'branch_id'              => $this->_patient_journey_first_value($transaction_rows, 'branch_id'),
                        'branch'                 => $this->_tat_branch_label($this->_patient_journey_first_value($transaction_rows, 'branch_id')),
                        'transaction_no'         => $this->_patient_journey_first_value($transaction_rows, 'transaction_no'),
                        'transaction_date'       => $transaction_date,
                        'patient_master_id'      => $patient_id,
                        'patient_name'           => $patient['patient_name'],
                        'total_waiting_time'     => round($waiting, 2),
                        'total_processing_time'  => round($processing, 2),
                        'details'                => $steps,
                    ];
                }
            }

            // Patient name ordering came from paging by patient. Now that a row is a transaction,
            // the list reads in the order the transactions happened.
            usort($data, static function ($a, $b) {
                $order = strcmp((string) $a['transaction_date'], (string) $b['transaction_date']);
                if ($order !== 0) {
                    return $order;
                }

                return strcmp((string) $a['transaction_no'], (string) $b['transaction_no']);
            });

            $response_status = SUCCESS;
            $response        = [
                'size'      => count($data),
                'date_from' => $date_from,
                'date_to'   => $date_to,
                'data'      => $data,
            ];
        }

        return $this->_create_response($response_status, $response, true);
    }

    public function get_tat_per_section()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->_normalize_tat_branch_id();
        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;
        $term      = $this->payload['term'] ?? null;

        if ($branch_id === false) {
            return $this->_create_response(FAIL, ['Invalid Branch ID.']);
        }

        // The response is one row per exam category, so the whole date range has to be averaged at
        // once - paging the patients first would make every average depend on the page requested.
        $tat_rows = $this->patient_journey->get_patient_journey($branch_id, $date_from, $date_to, $term) ?: [];
        $sections = $this->_build_tat_per_section($tat_rows);

        if (! $sections) {
            $response_status = FAIL_NOT_FOUND;
            $response        = [
                'No patient journeys found.',
            ];
        } else {
            $response_status = SUCCESS;
            $response        = [
                'size'      => count($sections),
                'date_from' => $date_from,
                'date_to'   => $date_to,
                'data'      => $sections,
            ];
        }

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Doctor TAT — average processing time per doctor from each branch EMR outpatient + queue_action.
     */
    public function get_doctor_tat()
    {
        $branch_id = $this->_normalize_tat_branch_id();
        if ($branch_id === false) {
            return $this->_create_response(
                FAIL,
                ['Invalid branch_id. Allowed values: all, 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
            );
        }

        $date_from   = $this->payload['date_from'] ?? null;
        $date_to     = $this->payload['date_to'] ?? null;
        $term        = $this->payload['term'] ?? null;
        $doctor_type = $this->payload['doctor_type'] ?? null;
        $doctor_id   = $this->payload['doctor_id'] ?? null;

        if (! $date_from || ! $date_to) {
            return $this->_create_response(FAIL, ['Date from and date to are required.']);
        }

        // Same averaging rules as EMR professional fee — skip empty / 00:00:00 samples.
        $averageProcessingTimeHms = static function (array $processingTimes): string {
            $totalProcessingSeconds = 0;
            $processingTimeCount    = 0;

            foreach ($processingTimes as $processingTime) {
                if (empty($processingTime) || $processingTime === '00:00:00') {
                    continue;
                }

                $parts = array_map('intval', explode(':', (string) $processingTime));
                $h     = $parts[0] ?? 0;
                $m     = $parts[1] ?? 0;
                $s     = $parts[2] ?? 0;

                $totalProcessingSeconds += ($h * 3600) + ($m * 60) + $s;
                $processingTimeCount++;
            }

            $avgSeconds = $processingTimeCount > 0 ? (int) round($totalProcessingSeconds / $processingTimeCount) : 0;

            return sprintf(
                '%02d:%02d:%02d',
                (int) floor($avgSeconds / 3600),
                (int) floor(($avgSeconds % 3600) / 60),
                $avgSeconds % 60
            );
        };

        $emrConnectionByBranch = [
            1 => 'mobile_emr',
            2 => 'oakridge_emr',
            3 => 'mactan_emr',
            4 => 'consolacion_emr',
            7 => 'mantlewood_emr',
        ];
        $branchIds = $branch_id !== null ? [$branch_id] : [1, 2, 3, 4, 7];

        // RULES: One row per master doctor; each outpatient visit contributes one queue_action processing_time.
        $allProcessingTimes = [];
        $byDoctor           = [];

        foreach ($branchIds as $id) {
            if (! isset($emrConnectionByBranch[$id])) {
                continue;
            }

            $outpatient = new \App\Models\Outpatient(db_connect($emrConnectionByBranch[$id]));
            $part       = $outpatient->get_doctor_tat_outpatient_rows(
                $date_from,
                $date_to,
                $doctor_id,
                $doctor_type,
                $term
            ) ?: [];

            foreach ($part as $row) {
                $doctorId = (int) ($row['doctor_id'] ?? 0);
                if ($doctorId <= 0) {
                    continue;
                }

                $processingTime = (string) ($row['processing_time'] ?? '');
                if (! isset($byDoctor[$doctorId])) {
                    $byDoctor[$doctorId] = [
                        'doctor_name'      => trim((string) ($row['doctor_name'] ?? '')),
                        'doctor_type'      => trim((string) ($row['doctor_type_name'] ?? '')),
                        'processing_times' => [],
                    ];
                }

                if ($processingTime !== '' && $processingTime !== '00:00:00') {
                    $byDoctor[$doctorId]['processing_times'][] = $processingTime;
                    $allProcessingTimes[]                      = $processingTime;
                }
            }
        }

        if (! $byDoctor) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No doctor TAT records found.'], true);
        }

        $rows = [];
        foreach ($byDoctor as $doctorId => $metric) {
            $doctorName = $metric['doctor_name'];
            if ($doctorName === '') {
                $doctorDetails = $this->doctor->get_details($doctorId) ?: [];
                $doctor        = $doctorDetails[0] ?? null;
                if ($doctor) {
                    $doctorName = trim(implode(' ', array_filter([
                        $doctor['first_name'] ?? '',
                        $doctor['middle_name'] ?? '',
                        $doctor['last_name'] ?? '',
                    ])));
                    if ($doctorName === '') {
                        $doctorName = trim((string) ($doctor['full_name'] ?? ''));
                    }
                    if ($metric['doctor_type'] === '') {
                        $metric['doctor_type'] = (string) ($doctor['type_name'] ?? '');
                    }
                }
            }

            if ($doctorName === '') {
                continue;
            }

            $rows[] = [
                'row_key'                 => 'doctor:' . $doctorId,
                'doctor_id'               => $doctorId,
                'doctor_name'             => $doctorName,
                'doctor_type'             => $metric['doctor_type'],
                'patient_count'           => count($metric['processing_times']),
                'average_processing_time' => $averageProcessingTimeHms($metric['processing_times']),
            ];
        }

        if (! $rows) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No doctor TAT records found.'], true);
        }

        usort($rows, static function ($a, $b) {
            return strcasecmp((string) $a['doctor_name'], (string) $b['doctor_name']);
        });

        return $this->_create_response(SUCCESS, [
            'size'                               => count($rows),
            'date_from'                          => $date_from,
            'date_to'                            => $date_to,
            'average_processing_time_per_doctor' => $averageProcessingTimeHms($allProcessingTimes),
            'data'                               => $rows,
        ], true);
    }

    public function get_daily_tat()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->_normalize_tat_branch_id();
        if ($branch_id === false) {
            return $this->_create_response(FAIL, ['Invalid Branch ID.']);
        }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;

        // The report summarises every test of the range at once, since paging would make each
        // average depend on the page requested. That makes an open ended range a full history scan,
        // so a date range is required rather than optional.
        if (! $date_from || ! $date_to) {
            return $this->_create_response(FAIL, ['Date from and date to are required.']);
        }

        $exam_rows = $this->report->get_daily_tat($branch_id, $date_from, $date_to) ?: [];
        $sections  = $this->_build_daily_tat_report($exam_rows);

        if (! $sections) {
            $response_status = FAIL_NOT_FOUND;
            $response        = [
                'No finalized tests found.',
            ];
        } else {
            $response_status = SUCCESS;
            $response        = [
                'size'      => count($sections),
                'date_from' => $date_from,
                'date_to'   => $date_to,
                'data'      => $sections,
            ];
        }

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Average waiting time per hour of the clinic day, for one stage of the patient journey.
     */
    public function get_hourly_tat()
    {
        return $this->_hourly_tat_response('waiting');
    }

    /**
     * Average processing time per hour of the clinic day, for one stage of the patient journey.
     */
    public function get_hourly_processing_tat()
    {
        return $this->_hourly_tat_response('processing');
    }

    /**
     * Shared payload and chart assembly for the hourly waiting and processing reports.
     */
    private function _hourly_tat_response(string $metric)
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->_normalize_tat_branch_id();
        if ($branch_id === false) {
            return $this->_create_response(FAIL, ['Invalid Branch ID.']);
        }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;

        // Every hour of the range is averaged at once, so an open ended range would read the whole
        // history to fill twelve bars.
        if (! $date_from || ! $date_to) {
            return $this->_create_response(FAIL, ['Date from and date to are required.']);
        }

        // RULES: Waiting is the gap between two stations, so it is picked as a transition. Processing
        // happens at one station, so it is picked as a station on its own.
        $stages = $metric === 'processing'
            ? $this->_hourly_tat_stations()
            : $this->_hourly_tat_stages();

        $stage = (string) ($this->payload['stage'] ?? '');
        if ($stage === '') {
            $stage = array_key_first($stages);
        }

        if (! isset($stages[$stage])) {
            return $this->_create_response(FAIL, ['Invalid stage.']);
        }

        $tat_rows = $this->patient_journey->get_patient_journey($branch_id, $date_from, $date_to) ?: [];
        $hours    = $this->_build_hourly_tat_report($tat_rows, $stages[$stage], $metric);

        // Every hour of the day is a bar, so an empty range is a flat chart rather than a missing
        // one. That makes this a SUCCESS with zeroes instead of a not found.
        $response = [
            'size'      => count($hours),
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'stage'     => $stage,
            'metric'    => $metric,
            'data'      => $hours,
        ];

        return $this->_create_response(SUCCESS, $response, true);
    }

    /**
     * The journey transitions the report can chart, keyed by the value the screen sends.
     *
     * RULES: These are the station pairs the Patient Journey walks a visit through - see
     * _patient_journey_stations - spelled the way _patient_journey_apply_transitions labels them,
     * because a step is matched to a stage on those labels.
     */
    private function _hourly_tat_stages(): array
    {
        return [
            'kiosk-csr'             => ['from' => 'kiosk',          'to' => 'csr'],

            // Everything the desk can send a patient to. Skip is an outcome marker, not a station,
            // so CSR → Skip is left off the picker.
            'csr-laboratory'        => ['from' => 'csr',            'to' => 'laboratory'],
            'csr-diagnostic'        => ['from' => 'csr',            'to' => 'diagnostic'],
            'csr-health'            => ['from' => 'csr',            'to' => 'health service'],
            'csr-pharmacy'          => ['from' => 'csr',            'to' => 'pharmacy'],
            'csr-emr-nurse'         => ['from' => 'csr',            'to' => 'emr nurse'],
            'csr-emr-doctor'        => ['from' => 'csr',            'to' => 'emr doctor'],

            // The exam sections hand off to each other without returning to the desk.
            'laboratory-laboratory' => ['from' => 'laboratory',     'to' => 'laboratory'],
            'laboratory-health'     => ['from' => 'laboratory',     'to' => 'health service'],
            'diagnostic-laboratory' => ['from' => 'diagnostic',     'to' => 'laboratory'],
            'diagnostic-diagnostic' => ['from' => 'diagnostic',     'to' => 'diagnostic'],
            'diagnostic-health'     => ['from' => 'diagnostic',     'to' => 'health service'],
            'health-laboratory'     => ['from' => 'health service', 'to' => 'laboratory'],
            'health-diagnostic'     => ['from' => 'health service', 'to' => 'diagnostic'],
            'health-health'         => ['from' => 'health service', 'to' => 'health service'],

            // Consultation, including the return to the desk. Done is an outcome marker, not a
            // station, so Nurse/Doctor → Done is left off the picker.
            'emr-nurse-doctor'      => ['from' => 'emr nurse',      'to' => 'emr doctor'],
            'emr-nurse-csr'         => ['from' => 'emr nurse',      'to' => 'csr'],
            'emr-doctor-nurse'      => ['from' => 'emr doctor',     'to' => 'emr nurse'],
        ];
    }

    /**
     * The stations the processing report can chart, keyed by the value the screen sends.
     *
     * RULES: Processing time is the serving entry's own span - see _patient_journey_step - and the
     * serving always belongs to the station the step arrived at. So a station is matched on the
     * destination alone, with no 'from', and every route into it counts towards the same bar. The
     * kiosk is absent on purpose: a kiosk step is closed by the csr serving, so its processing time
     * is the desk's, and it is already counted under CSR. Skip and Done are outcome markers, not
     * service stations, so they are left off the picker.
     */
    private function _hourly_tat_stations(): array
    {
        return [
            'csr'         => ['to' => 'csr'],
            'laboratory'  => ['to' => 'laboratory'],
            'diagnostic'  => ['to' => 'diagnostic'],
            'health'      => ['to' => 'health service'],
            'pharmacy'    => ['to' => 'pharmacy'],
            'emr-nurse'   => ['to' => 'emr nurse'],
            'emr-doctor'  => ['to' => 'emr doctor'],
        ];
    }

    /**
     * RULES: One bar per clock hour of the clinic day, holding the average of one stage.
     * 1. A sample is a journey step matching the stage. Waiting uses waiting_time (joining that
     *    queue through to the station starting) and is picked as a transition, so both ends have
     *    to match - see _hourly_tat_stages. Processing uses processing_time (station start through
     *    station end), which belongs to the station the step arrived at, so it is picked as a
     *    single station and only the destination has to match - see _hourly_tat_stations. A step
     *    that never closed has no value to average and is left out rather than counted as zero.
     * 2. Waiting lands in the hour the patient JOINED the queue (added_on). Processing lands in
     *    the hour the station STARTED (duration_from). Charting a wait at attend-time, or a
     *    process at join-time, would move the delay off the hour that caused it.
     * 3. The clinic day is fixed at 06:00 to 18:00 and every hour is returned, so an hour nobody
     *    came in reads as an empty bar instead of closing the gap between the hours either side of
     *    it. That is the same window the Consultation Census counts over - see _patientTatHoursSql.
     * 4. Steps are rebuilt per visit rather than read off patient_tat directly, because a wait or
     *    process only becomes measurable once it is paired with the entry that closed it - see
     *    _build_patient_journey_steps.
     */
    private function _build_hourly_tat_report(array $tat_rows, array $stage, string $metric = 'waiting'): array
    {
        $day_start = 6;
        $day_end   = 18;
        $is_processing = $metric === 'processing';
        $value_key     = $is_processing ? 'processing_time' : 'waiting_time';
        $bucket_key    = $is_processing ? 'duration_from' : 'added_on';
        $minutes_key   = $is_processing ? 'processing_minutes' : 'waiting_minutes';

        $by_patient = [];
        foreach ($tat_rows as $row) {
            $by_patient[(string) ($row['patient_master_id'] ?? '')][] = $row;
        }

        $totals = [];
        foreach ($by_patient as $details) {
            foreach ($this->_patient_journey_transactions($details) as $transaction_rows) {
                foreach ($this->_build_patient_journey_steps($transaction_rows) as $step) {
                    $from = strtolower(trim((string) ($step['source'] ?? '')));
                    $to   = strtolower(trim((string) ($step['source_to'] ?? '')));

                    // A station carries no 'from', so every route into it counts towards its bar.
                    if ($to !== $stage['to']) {
                        continue;
                    }

                    if (isset($stage['from']) && $from !== $stage['from']) {
                        continue;
                    }

                    if (($step[$value_key] ?? null) === null || empty($step[$bucket_key])) {
                        continue;
                    }

                    $bucketed = strtotime((string) $step[$bucket_key]);
                    if ($bucketed === false) {
                        continue;
                    }

                    $hour = (int) date('G', $bucketed);
                    if ($hour < $day_start || $hour >= $day_end) {
                        continue;
                    }

                    if (! isset($totals[$hour])) {
                        $totals[$hour] = ['total' => 0.0, 'count' => 0];
                    }

                    $totals[$hour]['total'] += (float) $step[$value_key];
                    $totals[$hour]['count']++;
                }
            }
        }

        $data = [];
        for ($hour = $day_start; $hour < $day_end; $hour++) {
            $count   = $totals[$hour]['count'] ?? 0;
            $average = $count === 0 ? 0.0 : $totals[$hour]['total'] / $count;

            $data[] = [
                'row_key'       => 'hour:' . $hour,
                'hour'          => $hour,
                'hour_label'    => date('g A', mktime($hour, 0, 0)) . ' - ' . date('g A', mktime($hour + 1, 0, 0)),
                $minutes_key    => round($average, 2),
                'patient_count' => $count,
            ];
        }

        return $data;
    }

    private function _patient_journey_duration_minutes($time_start, $time_end): float
    {
        if (empty($time_start) || empty($time_end)) {
            return 0.0;
        }

        $start = strtotime($time_start);
        $end   = strtotime($time_end);
        if ($start === false || $end === false) {
            return 0.0;
        }

        return round(max(0, ($end - $start) / 60), 2);
    }

    /**
     * First non-empty value of a column across one transaction's entries.
     *
     * RULES: transaction_no and transaction_date are only stamped on the entries that were logged
     * against a transaction. The kiosk entry that opens a journey is recorded before the patient
     * reaches the counter, so it carries neither, and the value has to be taken from whichever
     * later entry does.
     */
    private function _patient_journey_first_value(array $rows, string $key): string
    {
        foreach ($rows as $row) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Split one patient's entries into the separate visits they belong to.
     *
     * RULES: A visit is one transaction, so a patient who came in twice on the same day is two
     * groups. Mobile registration is logged as kiosk_wait with queue_id = 0 and transaction_id = 0
     * before the EMR creates a real queue, so those rows are attached to the next chronological
     * transaction of the same patient rather than standing as a visit of their own.
     */
    private function _patient_journey_transactions(array $details): array
    {
        usort($details, static function ($a, $b) {
            $order = strtotime((string) ($a['time_start'] ?? '')) <=> strtotime((string) ($b['time_start'] ?? ''));
            return $order !== 0 ? $order : ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        });

        $by_transaction       = [];
        $pending_pre_tx_kiosk = [];
        foreach ($details as $detail) {
            if ($this->_patient_journey_is_pre_transaction_kiosk($detail)) {
                $pending_pre_tx_kiosk[] = $detail;
                continue;
            }

            $transaction_id = $detail['transaction_id'] ?? null;
            $key            = ($transaction_id === null || $transaction_id === '' || (int) $transaction_id === 0)
                ? 'queue:' . ($detail['queue_id'] ?? '')
                : 'txn:' . $transaction_id;

            if ($pending_pre_tx_kiosk !== [] && strncmp($key, 'txn:', 4) === 0) {
                foreach ($pending_pre_tx_kiosk as $pending_row) {
                    $by_transaction[$key][] = $pending_row;
                }
                $pending_pre_tx_kiosk = [];
            }

            $by_transaction[$key][] = $detail;
        }

        if ($pending_pre_tx_kiosk !== []) {
            foreach ($pending_pre_tx_kiosk as $pending_row) {
                $by_transaction['kiosk:' . ($pending_row['id'] ?? count($by_transaction))][] = $pending_row;
            }
        }

        return $by_transaction;
    }

    /**
     * Mobile registration logged as kiosk_wait before a queue or transaction exists.
     *
     * RULES: Mobile was renamed to kiosk_wait, but it still writes queue_id = 0 and
     * transaction_id = 0 with no queue_no. Branch kiosk visits carry a real queue_id even when
     * queue_no was not stamped yet, so the zero IDs are what separates the two flows.
     */
    private function _patient_journey_is_pre_transaction_kiosk(array $row): bool
    {
        $action = strtolower(trim((string) ($row['action_performed'] ?? '')));
        if ($action !== 'kiosk_wait') {
            return false;
        }

        return (int) ($row['queue_id'] ?? 0) === 0
            && (int) ($row['transaction_id'] ?? 0) === 0;
    }

    /**
     * The stations a visit runs through, keyed by the action_performed prefix that opens the wait.
     *
     * RULES:
     * - serving: the prefix of the entry that closes that wait. It is not always the same station,
     *   because the queue is named after where the patient is waiting and the entry that closes it
     *   after who attended to them: the kiosk queue is closed by the csr desk, the emr queue by the
     *   nurse.
     * - from/to: how the step reads on the report. The csr desk is what dispatches a patient to the
     *   exam sections and to the consultation, so all of those read as coming from the csr, and the
     *   doctor is reached from the nurse. The trail is a tree rooted at the csr rather than one
     *   straight line, because the queues run side by side - a patient is put in the laboratory
     *   queue and the consultation queue off the same approval.
     * - section: the exam sections, which are treated as one pool when an entry cannot be matched
     *   inside its own prefix. See _build_patient_journey_steps.
     */
    private function _patient_journey_stations(): array
    {
        return [
            'kiosk'      => ['serving' => 'csr',        'from' => 'kiosk',     'to' => 'csr'],
            'lab'        => ['serving' => 'lab',        'from' => 'csr',       'to' => 'laboratory',      'section' => true],
            'diag'       => ['serving' => 'diag',       'from' => 'csr',       'to' => 'diagnostic',      'section' => true],
            'diagnostic' => ['serving' => 'diagnostic', 'from' => 'csr',       'to' => 'diagnostic',      'section' => true],
            'health'     => ['serving' => 'health',     'from' => 'csr',       'to' => 'health service',  'section' => true],
            'pharmacy'   => ['serving' => 'pharmacy',   'from' => 'csr',       'to' => 'pharmacy'],
            'emr'        => ['serving' => 'nurse',      'from' => 'csr',       'to' => 'emr nurse'],
            'doctor'     => ['serving' => 'doctor',     'from' => 'emr nurse', 'to' => 'emr doctor'],
        ];
    }

    /**
     * RULES: Turn the patient_tat entries of one transaction into the steps of the patient journey.
     * 1. A step is a wait paired with the serving that closed it, and the two are matched by
     *    station - see _patient_journey_stations. Pairing on whichever entry was logged next in
     *    time instead crosses the queues over, since they run side by side: a patient sent to the
     *    laboratory and to the consultation at once has the laboratory serving logged after the
     *    consultation wait, which reads as the consultation being served by the laboratory.
     * 2. Waiting time runs from joining the queue through to the moment the station started, i.e.
     *    the wait entry's start to the serving entry's start. Processing time is the serving
     *    entry's own span, which is also the window shown as the timestamp of the step.
     * 3. The exam sections fall back to being one pool: whatever a section cannot match inside its
     *    own prefix is matched against the other sections before being given up on, because the
     *    same exam is sometimes queued under one section and performed under another.
     * 4. A queue with no serving is still reported so an in-progress visit keeps its open steps,
     *    and an entry logged under an unknown station - a "skip" marker, say - stands on its own
     *    rather than being dropped.
     * 5. Steps read in the order the patient joined each queue, which is how the visit was lived.
     */
    private function _build_patient_journey_steps(array $details): array
    {
        $stations         = $this->_patient_journey_stations();
        $serving_stations = array_column($stations, 'serving');

        usort($details, static function ($a, $b) {
            $order = strtotime((string) ($a['time_start'] ?? '')) <=> strtotime((string) ($b['time_start'] ?? ''));
            return $order !== 0 ? $order : ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        });

        // The same wait is sometimes logged twice for one queue, once carrying the queue number and
        // once without, and the two copies are closed a few seconds apart. They are one wait, so
        // they are matched on the moment the patient joined the queue rather than on when the entry
        // was closed, and the copy holding the queue number is the one kept. Left in place the
        // other copy has no serving to pair with and surfaces as a step nobody ever attended to.
        // The category and exam stay in the key so two real exams starting together still count as
        // two.
        $unique = [];
        foreach ($details as $row) {
            $fingerprint = implode('|', [
                (string) ($row['action_performed'] ?? ''),
                (string) ($row['time_start'] ?? ''),
                (string) ($row['exam_category_id'] ?? ''),
                (string) ($row['exam_name'] ?? ''),
            ]);

            $kept = $unique[$fingerprint] ?? null;
            if ($kept === null) {
                $unique[$fingerprint] = $row;
                continue;
            }

            if (trim((string) ($kept['queue_no'] ?? '')) === '' && trim((string) ($row['queue_no'] ?? '')) !== '') {
                $unique[$fingerprint] = $row;
            }
        }

        // RULES: A skip marks the patient as called at the desk and not attended to, so when one is
        // present the kiosk row that opened the journey is the one that led to it - the visit reads
        // kiosk, then csr, then skip. With no skip anywhere, repeated kiosk rows are abandoned
        // re-queues and the last of them is the one that actually reached the desk. Which end of the
        // list to take is decided here and applied by the kiosk pairing below.
        $skipped = false;
        foreach ($unique as $row) {
            if (strpos(strtolower(trim((string) ($row['action_performed'] ?? ''))), 'skip') !== false) {
                $skipped = true;
                break;
            }
        }

        $waits    = [];
        $servings = [];
        $loose    = [];

        foreach ($unique as $row) {
            [$station, $phase] = $this->_tat_action_parts($row);

            if ($phase === 'wait' && isset($stations[$station])) {
                $waits[$station][] = $row;
            } elseif ($phase === 'serving' && in_array($station, $serving_stations, true)) {
                $servings[$station][] = $row;
            } else {
                $loose[] = $row;
            }
        }

        $steps           = [];
        $spare_waits     = [];
        $spare_servings  = [];

        foreach ($stations as $wait_station => $station) {
            $wait_rows    = $waits[$wait_station] ?? [];
            $serving_rows = $servings[$station['serving']] ?? [];

            if ($wait_station === 'kiosk') {
                $pre_tx_waits = [];
                $branch_waits = [];
                foreach ($wait_rows as $row) {
                    if ($this->_patient_journey_is_pre_transaction_kiosk($row)) {
                        $pre_tx_waits[] = $row;
                    } else {
                        $branch_waits[] = $row;
                    }
                }

                // Mobile kiosk closes on its own row before a branch queue exists, so it must not
                // consume a later csr_serving that belongs to a separate branch kiosk attempt.
                foreach ($pre_tx_waits as $row) {
                    $steps[] = $this->_patient_journey_step($station, $row, null);
                }

                // RULES: The journey has one branch kiosk row reported out of however many the
                // transaction carries. Which one depends on whether the patient was skipped:
                // with a skip, the first kiosk is the row that led to it, and the step has to read
                // kiosk then csr then skip. Without a skip, repeated kiosk rows are abandoned
                // re-queues, and measuring from the first would charge every abandoned attempt to the
                // waiting time - so the last is taken instead. Either way it is closed by the first
                // csr serving that did not start before it, since a serving earlier than the chosen
                // wait belongs to a different attempt.
                $entry_wait = $skipped
                    ? ($branch_waits[0] ?? null)
                    : (empty($branch_waits) ? null : $branch_waits[count($branch_waits) - 1]);

                $paired_serving = null;
                $paired_index   = null;
                foreach ($serving_rows as $index => $serving_row) {
                    if ($entry_wait !== null
                        && strtotime((string) ($serving_row['time_start'] ?? '')) < strtotime((string) ($entry_wait['time_start'] ?? ''))) {
                        continue;
                    }

                    $paired_serving = $serving_row;
                    $paired_index   = $index;
                    break;
                }

                if ($entry_wait !== null || $paired_serving !== null) {
                    $steps[] = $this->_patient_journey_step($station, $entry_wait, $paired_serving);
                }

                // A csr serving after the one that opened the visit is a return from the emr nurse,
                // so it stays a candidate for that transition instead of being dropped here.
                $left_over_waits    = [];
                $left_over_servings = array_slice($serving_rows, $paired_index === null ? 0 : $paired_index + 1);
            } else {
                // Both lists are already in time order, so a patient sent back to the same station
                // pairs first wait with first serving, second with second.
                $paired = min(count($wait_rows), count($serving_rows));
                for ($i = 0; $i < $paired; $i++) {
                    $steps[] = $this->_patient_journey_step($station, $wait_rows[$i], $serving_rows[$i]);
                }

                $left_over_waits    = array_slice($wait_rows, $paired);
                $left_over_servings = array_slice($serving_rows, $paired);
            }

            if (! empty($station['section'])) {
                foreach ($left_over_waits as $row) {
                    $spare_waits[] = ['station' => $station, 'row' => $row];
                }
                foreach ($left_over_servings as $row) {
                    $spare_servings[] = ['station' => $station, 'row' => $row];
                }

                continue;
            }

            foreach ($left_over_waits as $row) {
                $steps[] = $this->_patient_journey_step($station, $row, null);
            }
            foreach ($left_over_servings as $row) {
                $labels = $wait_station === 'kiosk'
                    ? ['from' => 'csr', 'to' => 'csr']
                    : $station;
                $steps[] = $this->_patient_journey_step($labels, null, $row);
            }
        }

        // The exam sections are one pool once their own prefix runs out of partners, because the
        // same exam is sometimes queued under one section and performed under another - an echo
        // waits as lab_wait and is scanned as diag_serving. Reported apart, the wait is measured
        // over its own one second entry instead of through to the scan, so the patient reads as
        // having waited no time at all for a scan they queued half an hour for. The step is named
        // after the serving, since that is where the exam was actually done.
        $sort_by_start = static function (array $a, array $b) {
            return strtotime((string) ($a['row']['time_start'] ?? '')) <=> strtotime((string) ($b['row']['time_start'] ?? ''));
        };
        usort($spare_waits, $sort_by_start);
        usort($spare_servings, $sort_by_start);

        foreach ($spare_waits as $i => $spare_wait) {
            $spare_serving = $spare_servings[$i] ?? null;
            $station       = $spare_serving !== null ? $spare_serving['station'] : $spare_wait['station'];

            $steps[] = $this->_patient_journey_step($station, $spare_wait['row'], $spare_serving['row'] ?? null);
        }

        foreach (array_slice($spare_servings, count($spare_waits)) as $spare_serving) {
            $steps[] = $this->_patient_journey_step($spare_serving['station'], null, $spare_serving['row']);
        }

        foreach ($loose as $row) {
            [$station, $phase] = $this->_tat_action_parts($row);
            $is_waiting        = $phase === 'wait';

            // A marker such as "skip" carries no underscore to split on, so the action itself names
            // the step: the patient was at the csr desk and was skipped.
            $steps[] = $this->_patient_journey_step(
                [
                    'from' => (string) ($row['source'] ?? ''),
                    'to'   => $station !== '' ? $station : strtolower(trim((string) ($row['action_performed'] ?? ''))),
                ],
                $is_waiting ? $row : null,
                $is_waiting ? null : $row
            );
        }

        usort($steps, static function ($a, $b) {
            $order = strtotime((string) $a['duration_from']) <=> strtotime((string) $b['duration_from']);
            return $order !== 0 ? $order : strcmp((string) $a['row_key'], (string) $b['row_key']);
        });

        $steps = $this->_patient_journey_apply_transitions($steps);

        // RULES: The modal reads as one chronological visit, so a later step's waiting time is the
        // gap from the previous visible step ending to this one starting. Measuring every row only
        // off its own queue reset serving-only rows to zero even when the patient plainly waited
        // between stations, e.g. 10:05:14 PM -> 10:06:43 PM. The first step keeps its own queue
        // based waiting value because there is no earlier visible step to bridge from.
        $previous_end = null;
        foreach ($steps as $index => &$step) {
            if ($index > 0 && ! empty($previous_end) && ! empty($step['duration_from'])) {
                $bridged_wait = $this->_patient_journey_duration_minutes($previous_end, $step['duration_from']);
                if ($bridged_wait !== null) {
                    $step['waiting_time'] = $bridged_wait;
                }
            }

            $step['step'] = $index + 1;
            $previous_end = ! empty($step['duration_to']) ? $step['duration_to'] : $previous_end;
        }
        unset($step);

        return $steps;
    }

    /**
     * Apply the report's allowed station-to-station paths to the chronological service events.
     */
    private function _patient_journey_apply_transitions(array $steps): array
    {
        $labels = [
            'kiosk'         => 'kiosk',
            'csr'           => 'csr',
            'skip'          => 'skip',
            'lab'           => 'laboratory',
            'diag'          => 'diagnostic',
            'health_service'=> 'health service',
            'pharmacy'      => 'pharmacy',
            'emr_nurse'     => 'emr nurse',
            'emr_doctor'    => 'emr doctor',
            'done'          => 'done',
        ];

        $destinationKeys = [
            'csr'             => 'csr',
            'skip'            => 'skip',
            'laboratory'      => 'lab',
            'lab'             => 'lab',
            'diagnostic'      => 'diag',
            'diag'            => 'diag',
            'health services' => 'health_service',
            'health service'  => 'health_service',
            'health'          => 'health_service',
            'pharmacy'        => 'pharmacy',
            'emr nurse'       => 'emr_nurse',
            'nurse'           => 'emr_nurse',
            'emr doctor'      => 'emr_doctor',
            'doctor'          => 'emr_doctor',
            'done'            => 'done',
        ];

        $kioskStep = null;
        $events    = [];
        foreach ($steps as $step) {
            $from = strtolower(trim((string) ($step['source'] ?? '')));
            $to   = strtolower(trim((string) ($step['source_to'] ?? '')));

            if ($from === 'kiosk' && $to === 'csr') {
                if ($kioskStep === null
                    || strtotime((string) ($step['duration_from'] ?? '')) < strtotime((string) ($kioskStep['duration_from'] ?? ''))) {
                    $kioskStep = $step;
                }
                continue;
            }

            $events[] = $step;
        }

        $result          = [];
        $current         = 'csr';
        $currentCategory = '';
        $currentExamName = '';

        if ($kioskStep !== null) {
            $kioskStep['source']             = $labels['kiosk'];
            $kioskStep['source_to']          = $labels['csr'];
            $kioskStep['exam_category_name'] = '';
            $result[] = $kioskStep;
        }

        foreach ($events as $step) {
            $rawDestination = strtolower(trim((string) ($step['source_to'] ?? '')));
            $destination    = $destinationKeys[$rawDestination] ?? null;
            if ($destination === null) {
                continue;
            }

            $source = null;
            switch ($destination) {
                case 'skip':
                    $source = 'csr';
                    break;
                case 'lab':
                    $source = in_array($current, ['csr', 'lab', 'diag', 'health_service'], true)
                        ? $current : 'csr';
                    break;
                case 'diag':
                    $source = in_array($current, ['csr', 'diag', 'health_service'], true)
                        ? $current : 'csr';
                    break;
                case 'health_service':
                    $source = in_array($current, ['csr', 'lab', 'diag', 'health_service'], true)
                        ? $current : 'csr';
                    break;
                case 'pharmacy':
                    $source = 'csr';
                    break;
                case 'emr_nurse':
                    $source = $current === 'emr_doctor' ? 'emr_doctor' : 'csr';
                    break;
                case 'emr_doctor':
                    $source = $current === 'emr_nurse' ? 'emr_nurse' : 'csr';
                    break;
                case 'csr':
                    // A later csr_serving is a visible return only after the nurse. Otherwise it is
                    // the serving attached to a suppressed duplicate kiosk row.
                    if ($current !== 'emr_nurse') {
                        continue 2;
                    }
                    $source = 'emr_nurse';
                    break;
                case 'done':
                    if (! in_array($current, ['emr_nurse', 'emr_doctor'], true)) {
                        continue 2;
                    }
                    $source = $current;
                    break;
            }

            $step['source']                  = $labels[$source];
            $step['source_to']               = $labels[$destination];
            $step['exam_category_name']      = in_array($source, ['lab', 'diag', 'health_service'], true)
                ? $currentCategory : '';
            $step['exam_name']               = in_array($source, ['lab', 'diag', 'health_service'], true)
                ? $currentExamName : '';

            $result[] = $step;

            // Skip is a CSR outcome, not a station the next service starts from.
            if ($destination !== 'skip' && $destination !== 'done') {
                $current = $destination;
                if (in_array($destination, ['lab', 'diag', 'health_service'], true)) {
                    $currentCategory = (string) ($step['exam_category_name_to'] ?? '');
                    $currentExamName = (string) ($step['exam_name_to'] ?? '');
                } else {
                    $currentCategory = '';
                    $currentExamName = '';
                }
            }
        }

        return $result;
    }

    /**
     * One display step, built from the wait that opened a queue and the serving that closed it.
     * Either side can be missing: a queue still being waited on has no serving yet, and an entry
     * logged under a station the journey does not know stands on its own.
     */
    private function _patient_journey_step(array $labels, ?array $wait, ?array $serving): array
    {
        $category = ($serving['exam_category_name'] ?? '') ?: ($wait['exam_category_name'] ?? '');

        // The timestamp column reads duration_from through duration_to, so processing time is that
        // same window. The end stamp comes from the serving row only - e.g. doctor_serving, not
        // doctor_wait. An open queue with no serving yet keeps duration_to empty. Mobile
        // kiosk_wait rows are the exception: they close on their own stamp with no csr_serving yet.
        $durationFrom = ($serving['time_start'] ?? '') ?: ($wait['time_start'] ?? '');

        if ($serving !== null) {
            $durationTo = $serving['time_end'] ?? '';
        } elseif ($wait !== null && $this->_patient_journey_is_pre_transaction_kiosk($wait)) {
            $durationTo = $wait['time_end'] ?? '';
        } else {
            $durationTo = '';
        }

        $processing = null;
        if ($durationFrom !== '' && $durationTo !== '') {
            $processing = $this->_patient_journey_duration_minutes($durationFrom, $durationTo);
        }

        $waiting = null;
        if ($wait !== null && $this->_tat_action_parts($wait)[1] === 'wait' && $serving !== null) {
            $waiting = $this->_patient_journey_duration_minutes($wait['time_start'] ?? null, $serving['time_start'] ?? null);
        } elseif ($serving !== null && $wait === null) {
            $waiting = 0.0;
        }

        return [
            'row_key'               => ($wait['id'] ?? '-') . '|' . ($serving['id'] ?? '-'),
            'transaction_no'        => ($serving['transaction_no'] ?? '') ?: ($wait['transaction_no'] ?? ''),
            'queue_no'              => ($serving['queue_no'] ?? '') ?: ($wait['queue_no'] ?? ''),
            'source'                => $labels['from'],
            'source_to'             => $labels['to'],
            'exam_category_name'    => $category,
            'exam_category_name_to' => $category,
            'exam_name'             => ($serving['exam_name'] ?? '') ?: ($wait['exam_name'] ?? ''),
            'exam_name_to'          => ($serving['exam_name'] ?? '') ?: ($wait['exam_name'] ?? ''),
            'added_on'              => ($wait['time_start'] ?? '') ?: ($serving['time_start'] ?? ''),
            'duration_from'         => $durationFrom,
            'duration_to'           => $durationTo,
            'waiting_time'          => $waiting,
            'processing_time'       => $processing,
        ];
    }

    /**
     * RULES: Average the three legs of every section, as shown on the TAT Per Section report.
     * 1. Rows are grouped by queue_id, because one queue is one pass of a patient through the
     *    branch and the kiosk entry of that queue is the only place the arrival time is recorded.
     * 2. The kiosk_wait entry is logged before any exam is known, so it carries no section. Its
     *    start time is therefore treated as the arrival time of the whole queue, and every section
     *    entry of that same queue is measured against it.
     * 3. For the same reason the category is resolved once per queue rather than per entry - the
     *    serving entry is the one that carries it, so all three legs of the queue are filed under
     *    the category_id found there. See _tat_section_bucket.
     * 4. Every exam section logs under its own prefix (lab_*, diag_*, health_*), so the legs below
     *    are read off whichever of those stations the entry belongs to - see _tat_section_streams.
     * 5. Three legs are measured per queue - see _tat_queue_legs:
     *    - patient entry to approval: kiosk_wait.time_start -> <section>_wait.time_start, i.e.
     *      arriving at the kiosk through the csr desk until the exam is approved and queued.
     *    - approval to laboratory/diagnostic: <section>_wait.time_start -> <section>_wait.time_end,
     *      i.e. how long that approved exam sat in the section queue before it was picked up.
     *    - laboratory/diagnostic to finalized: <section>_serving.time_start -> time_end, i.e. how
     *      long the section itself took, the serving entry closing when the result is finalized.
     * 6. A queue contributes one sample to its section, and only when all three legs can be
     *    measured. Averaging each leg over its own denominator instead would mix visits that are
     *    still in progress into columns they do happen to have, which reads as a total rather than
     *    an average. A queue that never reached a category is dropped for the same reason.
     */
    private function _build_tat_per_section(array $tat_rows): array
    {
        $queues = [];
        foreach ($tat_rows as $row) {
            $queue_id = (string) ($row['queue_id'] ?? '');
            if ($queue_id === '') {
                continue;
            }

            $queues[$queue_id][] = $row;
        }

        $totals = [];
        foreach ($queues as $rows) {
            $section = $this->_tat_section_bucket($rows);
            if ($section === null) {
                continue;
            }

            $legs = $this->_tat_queue_legs($rows);
            if ($legs === null) {
                continue;
            }

            [$key, $section_name] = $section;
            $branch_id            = (string) ($rows[0]['branch_id'] ?? '');
            $group_key            = $branch_id . '|' . $key;

            if (! isset($totals[$group_key])) {
                $totals[$group_key] = [
                    'section_name'   => $section_name,
                    'branch_id'      => $branch_id,
                    'branch'         => $this->_tat_branch_label($branch_id),
                    'entry_total'    => 0.0,
                    'approval_total' => 0.0,
                    'section_total'  => 0.0,
                    'queue_count'    => 0,
                ];
            }

            $totals[$group_key]['entry_total']    += $legs['entry'];
            $totals[$group_key]['approval_total'] += $legs['approval'];
            $totals[$group_key]['section_total']  += $legs['section'];
            $totals[$group_key]['queue_count']++;
        }

        $sections = [];
        foreach ($totals as $key => $total) {
            $count = $total['queue_count'];

            $sections[] = [
                'row_key'                            => $key,
                'branch_id'                          => $total['branch_id'],
                'branch'                             => $total['branch'],
                'section_name'                       => $total['section_name'],
                'patient_entry_to_approval'          => round($total['entry_total'] / $count, 2),
                'approval_to_laboratory_diagnostic'  => round($total['approval_total'] / $count, 2),
                'laboratory_diagnostic_to_finalized' => round($total['section_total'] / $count, 2),
                'transaction_count'                  => $count,
            ];
        }

        usort($sections, static function ($a, $b) {
            $order = strcmp((string) $a['branch'], (string) $b['branch']);
            if ($order !== 0) {
                return $order;
            }

            return strcmp((string) $a['section_name'], (string) $b['section_name']);
        });

        return $sections;
    }

    /**
     * RULES: Summarise how long each section takes to turn a test around, one row per section.
     * 1. The measured span runs from the start of processing to finalized_on of a single exam, so
     *    the report works off exam-level rows rather than patient_tat, which only knows queue steps.
     *    Imaging exams never get an in_process_on stamp, so the start falls back to completed_on and
     *    then approved_on - see get_daily_tat, which would otherwise drop XRAY from the report.
     * 2. A test that never reached a finalized stamp is left out entirely. It has no duration to
     *    average, and counting it as zero would understate every column.
     * 3. Rows are grouped on the exam's own CHILD category, so HEMATOLOGY and CLINICAL CHEMISTRY
     *    each get their own line. That is the opposite of the TAT Per Section report, which rolls
     *    the same categories up into their parent - see _tat_section_bucket.
     * 4. Median and the quartiles are read off the sorted durations by linear interpolation, the
     *    same way Excel's PERCENTILE.INC does, so the figures line up with the spreadsheet this
     *    report replaces.
     * 5. Tail is the number of tests that went past the standard TAT of their section, not past the
     *    section average, and the percentage is that tail over the tests counted. A section with no
     *    agreed standard cannot have a tail - see _daily_tat_standard_minutes.
     */
    private function _build_daily_tat_report(array $exam_rows): array
    {
        $sections = [];
        foreach ($exam_rows as $row) {
            $minutes = $this->_daily_tat_minutes(
                $row['tat_start_on'] ?? null,
                $row['tat_end_on'] ?? null
            );

            if ($minutes === null) {
                continue;
            }

            $category_id  = $row['category_id'] ?? null;
            $section_name = trim((string) ($row['department'] ?? ''));
            if ($section_name === '') {
                $section_name = trim((string) ($row['section_name'] ?? ''));
            }

            if ($section_name === '') {
                continue;
            }

            $key = (($row['branch_id'] ?? '') !== '' ? (string) $row['branch_id'] : '0')
                . '|'
                . (($category_id === null || $category_id === '')
                    ? 'name:' . strtolower($section_name)
                    : 'id:' . $category_id);

            if (! isset($sections[$key])) {
                $sections[$key] = [
                    'section'    => $section_name,
                    'branch_id'  => $row['branch_id'] ?? '',
                    'branch'     => $row['branch'] ?? $this->_tat_branch_label($row['branch_id'] ?? ''),
                    'durations'  => [],
                ];
            }

            $sections[$key]['durations'][] = $minutes;
        }

        $data = [];
        foreach ($sections as $key => $section) {
            $durations = $section['durations'];
            sort($durations);

            $count    = count($durations);
            $standard = $this->_daily_tat_standard_minutes($section['section']);
            $tail     = 0;

            if ($standard !== null) {
                foreach ($durations as $duration) {
                    if ($duration > $standard) {
                        $tail++;
                    }
                }
            }

            $data[] = [
                'row_key'      => $key,
                'branch_id'    => $section['branch_id'],
                'branch'       => $section['branch'],
                'section'      => $section['section'],
                'average_time' => $this->_daily_tat_hms(array_sum($durations) / $count),
                'median'       => $this->_daily_tat_hms($this->_daily_tat_percentile($durations, 0.5)),
                'quartile_1'   => $this->_daily_tat_hms($this->_daily_tat_percentile($durations, 0.25)),
                'quartile_3'   => $this->_daily_tat_hms($this->_daily_tat_percentile($durations, 0.75)),
                'standard_tat' => $standard === null ? null : $this->_daily_tat_hms($standard),
                'no_of_tests'  => $count,
                'tail'         => $tail,
                'percentage'   => number_format($tail / $count * 100, 1),
            ];
        }

        usort($data, static function ($a, $b) {
            $order = strcmp((string) $a['branch'], (string) $b['branch']);
            if ($order !== 0) {
                return $order;
            }

            return strcmp((string) $a['section'], (string) $b['section']);
        });

        return $data;
    }

    /**
     * The standard turnaround a section is held to, in minutes.
     *
     * RULES: These targets are agreed per section, not stored per exam - lab_test.tat is maintained
     * against individual exams and does not match them. A section that is not listed here has no
     * agreed target, so its Standard TAT is left blank and it reports no tail.
     */
    private function _daily_tat_standard_minutes(string $section_name): ?float
    {
        $standards = TAT_STANDARD_MINUTES;
        $key = strtoupper(trim($section_name));

        return isset($standards[$key]) ? (float) $standards[$key] : null;
    }

    /**
     * Minutes between two result stamps, or null when the test has not passed both of them.
     */
    private function _daily_tat_minutes($time_start, $time_end): ?float
    {
        if (empty($time_start) || empty($time_end)) {
            return null;
        }

        $start = strtotime($time_start);
        $end   = strtotime($time_end);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return ($end - $start) / 60;
    }

    /**
     * Percentile of an already sorted list, interpolating between the two samples the rank falls
     * between - the behaviour of Excel's PERCENTILE.INC and QUARTILE.
     */
    private function _daily_tat_percentile(array $sorted, float $percentile): float
    {
        $count = count($sorted);
        if ($count === 1) {
            return (float) $sorted[0];
        }

        $rank  = $percentile * ($count - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }

        return $sorted[$lower] + ($rank - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }

    /**
     * Minutes rendered as the hours:minutes:seconds the report is read in, e.g. 166.5 minutes ->
     * "02:46:30". Seconds are shown because a section that turns work around in under a minute
     * otherwise reads as "00:00" and cannot be told apart from one that did nothing.
     */
    private function _daily_tat_hms(float $minutes): string
    {
        $total = (int) round(max(0, $minutes) * 60);

        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }

    /**
     * The three leg durations of one queue in minutes, or null when the queue does not have all of
     * them.
     *
     * RULES: A queue is only counted once it is complete, i.e. it was stamped at the kiosk and both
     * its section wait and section serving entries have closed. An in-progress visit is dropped
     * rather than counted as a zero or averaged over a smaller denominator.
     * The kiosk entry must also fall on the same date as the section wait it is paired with: the
     * same queue_id can be picked up again on a later day, and pairing across days reports waiting
     * times measured in days instead of minutes.
     */
    private function _tat_queue_legs(array $rows): ?array
    {
        $kiosk_start = null;
        $wait        = null;
        $serving     = null;

        foreach ($rows as $row) {
            [$stream, $step] = $this->_tat_action_parts($row);
            $start = $row['time_start'] ?? null;
            $end   = $row['time_end'] ?? null;

            if ($stream === 'kiosk' && $step === 'wait') {
                // A queue should hold one kiosk entry, but keep the earliest if it was logged twice.
                if (! empty($start) && ($kiosk_start === null || strtotime($start) < strtotime($kiosk_start))) {
                    $kiosk_start = $start;
                }

                continue;
            }

            if (! in_array($stream, $this->_tat_section_streams(), true) || empty($start) || empty($end)) {
                continue;
            }

            // Rows arrive in time order, so the first closed entry of each step is the one to use.
            if ($step === 'wait' && $wait === null) {
                $wait = $row;
            } elseif ($step === 'serving' && $serving === null) {
                $serving = $row;
            }
        }

        if ($kiosk_start === null || $wait === null || $serving === null) {
            return null;
        }

        if (substr((string) $kiosk_start, 0, 10) !== substr((string) $wait['time_start'], 0, 10)) {
            return null;
        }

        return [
            'entry'    => $this->_patient_journey_duration_minutes($kiosk_start, $wait['time_start']),
            'approval' => $this->_patient_journey_duration_minutes($wait['time_start'], $wait['time_end']),
            'section'  => $this->_patient_journey_duration_minutes($serving['time_start'], $serving['time_end']),
        ];
    }

    /**
     * The action_performed prefixes that stand for an exam section, i.e. the stations the
     * "Laboratory/Diagnostic" columns cover. kiosk and csr are left out on purpose: they are the
     * desk steps a patient goes through before any exam is known, so they carry no category.
     */
    private function _tat_section_streams(): array
    {
        return ['lab', 'diag', 'diagnostic', 'health'];
    }

    /**
     * Splits action_performed into its station and step, e.g. "diag_serving" -> ["diag", "serving"].
     */
    private function _tat_action_parts(array $row): array
    {
        $action = strtolower(trim((string) ($row['action_performed'] ?? '')));
        $split  = strrpos($action, '_');

        if ($split === false) {
            return ['', ''];
        }

        return [substr($action, 0, $split), substr($action, $split + 1)];
    }

    /**
     * Bucket key and display name of the section a whole queue belongs to.
     *
     * RULES: The category is resolved once per queue and then reused by all three legs. Only the
     * serving entry reliably carries it - a queue is created before the exam is known, so the
     * kiosk and wait entries of that same queue are usually logged without one and would otherwise
     * each land in a bucket of their own. category_id is preferred as the key so two categories
     * that happen to share a name are not merged; a queue from a branch whose patient_tat has no
     * category_id at all falls back to grouping on the name. A queue that never reached a category
     * is not a section at all, so null is returned and the caller leaves it out of the report.
     *
     * This report groups on the PARENT category, so HEMATOLOGY and CLINICAL CHEMISTRY are reported
     * together as LABORATORY. A category with no parent is already a top level section and stands
     * on its own - the model resolves that fallback, see exam_category_parent_id.
     */
    private function _tat_section_bucket(array $rows): ?array
    {
        foreach ($rows as $row) {
            $category_id  = $row['exam_category_parent_id'] ?? null;
            $section_name = trim((string) ($row['exam_category_parent_name'] ?? ''));

            if ($category_id === null || $category_id === '' || $section_name === '') {
                continue;
            }

            return ['id:' . $category_id, $section_name];
        }

        foreach ($rows as $row) {
            $section_name = trim((string) ($row['exam_category_parent_name'] ?? ''));
            if ($section_name === '') {
                $section_name = trim((string) ($row['exam_category_name'] ?? ''));
            }

            if ($section_name !== '') {
                return ['name:' . strtolower($section_name), $section_name];
            }
        }

        return null;
    }

    /**
     * Get FPE report (dashboard or tracker tab).
     */
    public function get_fpe()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $tab     = $this->payload['tab'] ?? null;
        $allowed = $this->report->get_allowed_fpe_tabs();

        if (! $tab || ! in_array($tab, $allowed, true)) {
            return $this->_create_response(
                FAIL,
                ['Invalid or missing tab. Allowed values: ' . implode(', ', $allowed)]
            );
        }

        $dates = $this->_fpe_resolve_date_range();
        if ($dates instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $dates;
        }
        [$date_from, $date_to] = $dates;

        $data = $this->report->get_fpe($date_from, $date_to, $tab);

        if (empty($data)) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No FPE found.']);
        }

        return $this->_create_response(SUCCESS, [
            'tab'       => $tab,
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'data'      => $data,
        ]);
    }

    /**
     * UIP Patient Report
     */
    public function uip_patient()
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        [$date_from, $date_to] = $this->_normalizeUipDates(
            $this->request->getVar('date_from'),
            $this->request->getVar('date_to')
        );
        $source = strtolower((string) $this->request->getVar('source'));

        $data['date_from']        = $date_from;
        $data['date_to']          = $date_to;
        $data['data_as_of']       = $source === 'live' ? null : $this->report_clinic->get_uip_data_as_of();
        if ($source !== 'live' && $data['data_as_of'] === null) {
            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 503,
                'message' => 'UIP report data is not initialized. Run the UIP backfill first.',
            ]);
        }
        $data['patient_category'] = $source === 'live'
            ? $this->report_clinic->get_uip_patient_live($date_from, $date_to, null, null, null)
            : $this->report_clinic->get_uip_patient($date_from, $date_to, null, null, null);

        if (! empty($data)) {
            return $this->_create_response(SUCCESS, $data);
        } else {
            return $this->_create_response(FAIL_NOT_FOUND, ['reports not found.']);
        }
    }

    /**
     * UIP Age Report
     */
    public function uip_age()
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        [$date_from, $date_to] = $this->_normalizeUipDates(
            $this->request->getVar('date_from'),
            $this->request->getVar('date_to')
        );
        $source = strtolower((string) $this->request->getVar('source'));

        $data['date_from'] = $date_from;
        $data['date_to']   = $date_to;
        $data['data_as_of'] = $source === 'live' ? null : $this->report_clinic->get_uip_data_as_of();
        if ($source !== 'live' && $data['data_as_of'] === null) {
            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 503,
                'message' => 'UIP report data is not initialized. Run the UIP backfill first.',
            ]);
        }
        $data['age'] = $source === 'live'
            ? $this->report_clinic->get_uip_age_live($date_from, $date_to)
            : $this->report_clinic->get_uip_age($date_from, $date_to);

        if (! empty($data)) {
            return $this->_create_response(SUCCESS, $data);
        } else {
            return $this->_create_response(FAIL_NOT_FOUND, ['reports not found.']);
        }
    }

    /**
     * Get doctors
     */
    public function get_doctors()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $doctor_id = $this->payload['doctor_id'] ?? null;
        $term      = $this->payload['term'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $doctors = $this->doctor->get_details($doctor_id, $term, $page, $data_size) ?: [];
        $size    = count($this->doctor->get_details($doctor_id, $term, null, null) ?: []);

        if (! $doctors) {
            $response_status = FAIL_NOT_FOUND;
            $response        = [
                'No doctors found.',
            ];
        } else {
            $response_status = SUCCESS;
            $response        = [
                'size' => $size,
                'data' => $doctors,
            ];
        }

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * List of medical practitioners (PROF FEE / CERTIFICATION tabs).
     * Unions EMR user rows across clinic EMR DBs and enriches from master doctor.
     */
    public function get_list_of_medical_practitioners()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $tab     = $this->payload['tab'] ?? null;
        $allowed = $this->report->get_allowed_medical_practitioner_tabs();

        if (! $tab || ! in_array($tab, $allowed, true)) {
            return $this->_create_response(
                FAIL,
                ['Invalid or missing tab. Allowed values: ' . implode(', ', $allowed)]
            );
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;
            if (! in_array($branch_id, [1, 2, 3, 4, 7], true)) {
                return $this->_create_response(FAIL, ['Invalid Branch ID.']);
            }
        } else {
            $branch_id = null;
        }

        $status    = $this->payload['status'] ?? 'active';
        if ($status === '') {
            $status = 'active';
        }
        $term      = $this->payload['term'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $result = $this->report->get_list_of_medical_practitioners(
            $tab,
            $branch_id,
            $status,
            $term,
            $page,
            $data_size
        );

        if (empty($result['data'])) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No medical practitioners found.'], true);
        }

        return $this->_create_response(SUCCESS, [
            'tab'  => $tab,
            'size' => $result['size'],
            'data' => $result['data'],
        ], true);
    }

    /**
     * BIR RELIEF Summary List of Purchases (R_Purchases).
     * One aggregated row per vendor TIN from APV payments.
     */
    public function relief_purchase()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        [$ok, $payload] = $this->_validate_relief_request();
        if (! $ok) {
            return $payload;
        }

        $result = $this->relief->get_purchases(
            $payload['date_from'],
            $payload['date_to'],
            $payload['branch_id'],
            $payload['term']
        );

        if (empty($result['data'])) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No relief purchases found.'], true);
        }

        return $this->_create_response(SUCCESS, [
            'date_from' => $payload['date_from'],
            'date_to'   => $payload['date_to'],
            'size'      => $result['size'],
            'data'      => $result['data'],
        ], true);
    }

    /**
     * BIR RELIEF Summary List of Sales (R_Sales).
     * CSR payments plus billing collections, one row per client TIN.
     */
    public function relief_sales()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        [$ok, $payload] = $this->_validate_relief_request();
        if (! $ok) {
            return $payload;
        }

        $result = $this->relief->get_sales(
            $payload['date_from'],
            $payload['date_to'],
            $payload['branch_id'],
            $payload['term']
        );

        if (empty($result['data'])) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No relief sales found.'], true);
        }

        return $this->_create_response(SUCCESS, [
            'date_from' => $payload['date_from'],
            'date_to'   => $payload['date_to'],
            'size'      => $result['size'],
            'data'      => $result['data'],
        ], true);
    }

    /**
     * BIR SAWT (sawt_1702Q). Withholding on HMO/CORP/YAKAP collections.
     * Same dataset as relief_sales, one row per client TIN + ATC.
     */
    public function sawt_report()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        [$ok, $payload] = $this->_validate_relief_request();
        if (! $ok) {
            return $payload;
        }

        $result = $this->relief->get_sawt(
            $payload['date_from'],
            $payload['date_to'],
            $payload['branch_id'],
            $payload['term']
        );

        $attachments = $this->sawt_report_attachment->get_by_period(
            $payload['date_from'],
            $payload['date_to'],
            $payload['branch_id']
        );

        if (empty($result['data']) && empty($attachments)) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No SAWT rows found.'], true);
        }

        return $this->_create_response(SUCCESS, [
            'date_from'   => $payload['date_from'],
            'date_to'     => $payload['date_to'],
            'branch_id'   => $payload['branch_id'],
            'size'        => $result['size'],
            'data'        => $result['data'],
            'attachments' => $attachments,
        ], true);
    }

    /**
     * Upload a SAWT report attachment to VM4 for a reporting period.
     * POST multipart: date_from, date_to, optional branch_id, attachment (file).
     */
    public function upload_sawt_report_attachment()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        [$ok, $payload] = $this->_validate_relief_request();
        if (! $ok) {
            return $payload;
        }

        $file = $this->request->getFile('attachment');
        if (! $file || ! $file->isValid() || $file->hasMoved()) {
            return $this->_create_response(FAIL, ['attachment file is required and must be valid.']);
        }

        if (! in_array(strtolower($file->getExtension()), ['pdf', 'png', 'jpeg', 'jpg', 'xls', 'xlsx', 'csv'], true)) {
            return $this->_create_response(FAIL, ['Invalid file type. Allowed: pdf, png, jpeg, jpg, xls, xlsx, csv.']);
        }

        $max_file_size = 5 * 1024 * 1024;
        if ($file->getSize() > $max_file_size) {
            return $this->_create_response(FAIL, ['File exceeds 5 MB limit.']);
        }

        $branchSegment = $payload['branch_id'] !== null ? (string) $payload['branch_id'] : 'all';
        $file_path     = 'assets/sawt_reports/' . $payload['date_from'] . '_' . $payload['date_to'] . '/' . $branchSegment . '/';

        $upload_result = upload_to_vm4($file, $file_path);
        if (! $upload_result || empty($upload_result['success'])) {
            return $this->_create_response(FAIL, [
                'Upload failed: ' . ($upload_result['error'] ?? 'unknown error'),
            ]);
        }

        $uploaded_url = VM4_ENDPOINT . $upload_result['path'];

        $values = [
            'date_from'  => $payload['date_from'],
            'date_to'    => $payload['date_to'],
            'branch_id'  => $payload['branch_id'],
            'file_name'  => $file->getName(),
            'file_path'  => $upload_result['path'],
            'file_url'   => $uploaded_url,
            'mime'       => $file->getMimeType(),
            'added_by'   => $this->payload['requester'] ?? null,
            'added_on'   => date('Y-m-d H:i:s'),
        ];

        $attachment_id = $this->sawt_report_attachment->insert($values);
        if (! $attachment_id) {
            return $this->_create_response(FAIL, [
                'Failed to save attachment. ' . ($this->sawt_report_attachment->error() ?? ''),
            ]);
        }

        return $this->_create_response(SUCCESS, [
            'sawt_report_attachment_id' => $attachment_id,
            'file_url'                  => $uploaded_url,
            'message'                   => 'SAWT report attachment uploaded successfully.',
        ], true);
    }

    /**
     * BIR 1601-EQ Schedule 1 (_1601EQ_1). Withholding on APV supplier payments.
     * Same dataset as relief_purchase, one row per supplier TIN + ATC + invoice.
     * Route: GET reports/1601eq_schedule_1 (PHP method names cannot start with a digit).
     */
    public function eq_1601_schedule_1()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        [$ok, $payload] = $this->_validate_relief_request();
        if (! $ok) {
            return $payload;
        }

        $result = $this->relief->get_1601eq(
            $payload['date_from'],
            $payload['date_to'],
            $payload['branch_id'],
            $payload['term']
        );

        if (empty($result['data'])) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No 1601-EQ Schedule 1 rows found.'], true);
        }

        return $this->_create_response(SUCCESS, [
            'date_from' => $payload['date_from'],
            'date_to'   => $payload['date_to'],
            'size'      => $result['size'],
            'data'      => $result['data'],
        ], true);
    }

    /**
     * BIR 1604-E Schedule 3 (1604E_s3_v7). Same per-supplier APV withholding as 1601-EQ.
     * Route: GET reports/e_1601_schedule_2
     */
    public function e_1601_schedule_2()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        [$ok, $payload] = $this->_validate_relief_request();
        if (! $ok) {
            return $payload;
        }

        $result = $this->relief->get_1601eq(
            $payload['date_from'],
            $payload['date_to'],
            $payload['branch_id'],
            $payload['term']
        );

        if (empty($result['data'])) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No 1604-E Schedule 3 rows found.'], true);
        }

        return $this->_create_response(SUCCESS, [
            'date_from' => $payload['date_from'],
            'date_to'   => $payload['date_to'],
            'size'      => $result['size'],
            'data'      => $result['data'],
        ], true);
    }

    /**
     * Inventory Schedule (laboratory + clinic peso rollforward).
     */
    public function inventory_schedule()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        [$ok, $payload] = $this->_validate_inventory_schedule_request();
        if (! $ok) {
            return $payload;
        }

        $result = $this->inventory_schedule->get_schedule(
            $payload['date_from'],
            $payload['date_to'],
            $payload['branch_id'],
            $payload['term']
        );

        if ($this->inventory_schedule->is_empty($result)) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No inventory schedule rows found.'], true);
        }

        return $this->_create_response(SUCCESS, [
            'date_from'            => $payload['date_from'],
            'date_to'              => $payload['date_to'],
            'laboratory_supplies'  => $result['laboratory_supplies'],
            'clinic_supplies'      => $result['clinic_supplies'],
        ], true);
    }

    /**
     * Shared date/branch validation for RELIEF reports.
     *
     * @return array{0:bool,1:mixed}
     */
    protected function _validate_relief_request(): array
    {
        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;

        if (! $date_from || ! $date_to) {
            return [false, $this->_create_response(FAIL, ['date_from and date_to are required.'])];
        }

        if (
            ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_from)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)
        ) {
            return [false, $this->_create_response(FAIL, ['Invalid date. Use YYYY-MM-DD.'])];
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;
            if (! in_array($branch_id, [1, 2, 3, 4, 7], true)) {
                return [false, $this->_create_response(
                    FAIL,
                    ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
                )];
            }
        } else {
            $branch_id = null;
        }

        return [true, [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'branch_id' => $branch_id,
            'term'      => $this->payload['term'] ?? null,
        ]];
    }

    /**
     * Date/branch validation for inventory schedule (includes Mobile and Central Warehouse).
     *
     * @return array{0:bool,1:mixed}
     */
    protected function _validate_inventory_schedule_request(): array
    {
        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;

        if (! $date_from || ! $date_to) {
            return [false, $this->_create_response(FAIL, ['date_from and date_to are required.'])];
        }

        if (
            ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_from)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)
        ) {
            return [false, $this->_create_response(FAIL, ['Invalid date. Use YYYY-MM-DD.'])];
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;
            if (! in_array($branch_id, [1, 2, 3, 4, 5, 7], true)) {
                return [false, $this->_create_response(
                    FAIL,
                    ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 5 (warehouse), 7 (mantlewood).']
                )];
            }
        } else {
            $branch_id = null;
        }

        return [true, [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'branch_id' => $branch_id,
            'term'      => $this->payload['term'] ?? null,
        ]];
    }

    /**
     * Get Doctors Fee Report
     */
    public function get_doctors_fee()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        if (! $branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        // if (in_array($branch_id, [1, 3, 4, 5, 6, 7])) {
        //     return $this->_create_response(
        //         FAIL,
        //         ['Doctors Fee report is not available for this branch.']
        //     );
        // }

        if (! $this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $transaction_from = $this->payload['transaction_from'] ?? null;
        $transaction_to   = $this->payload['transaction_to'] ?? null;
        $doctor_id        = $this->payload['doctor_id'] ?? null;
        $type             = $this->payload['type'] ?? null;
        $term             = $this->payload['term'] ?? null;
        $page             = $this->payload['page'] ?? null;
        $data_size        = $this->payload['data_size'] ?? null;

        $doctors_fees = $this->booking_doctors_fee->get_doctors_fee($transaction_from, $transaction_to, $doctor_id, $type, $branch_id, $term, $page, $data_size);
        if ($doctors_fees) {
            // foreach ($doctors_fees as &$fee) {
                // $doctor_details     = $this->doctor->get_details($fee['doctor_id']);
                // $fee['doctor_name'] = $doctor_details ? implode(' ', array_filter([$doctor_details[0]['first_name'], $doctor_details[0]['middle_name'], $doctor_details[0]['last_name']])) : 'Unknown Doctor';
            // }
        }
        $size = count($this->booking_doctors_fee->get_doctors_fee($transaction_from, $transaction_to, $doctor_id, $type, $branch_id, $term, null, null) ?: []);

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $doctors_fees,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Doctors Fee Report Transaction
     */
    public function get_doctors_fee_transaction()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        if (! $branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        // if (in_array($branch_id, [1, 3, 4, 5, 6, 7])) {
        //     return $this->_create_response(
        //         FAIL,
        //         ['Doctors Fee report is not available for this branch.']
        //     );
        // }

        if (! $this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $transaction_from = $this->payload['transaction_from'] ?? null;
        $transaction_to   = $this->payload['transaction_to'] ?? null;
        $doctor_id        = $this->payload['doctor_id'] ?? null;
        $page             = $this->payload['page'] ?? null;
        $data_size        = $this->payload['data_size'] ?? null;

        $doctors = $this->booking_doctors_fee->get_doctors($transaction_from, $transaction_to, $doctor_id, $page, $data_size);
        $size    = count($this->booking_doctors_fee->get_doctors($transaction_from, $transaction_to, $doctor_id, $page, $data_size) ?: []);

        if ($doctors) {
            foreach ($doctors as &$doctor) {
                $doctor_details        = $this->doctor->get_details($doctor['doctor_id']);
                $doctor['doctor_name'] = $doctor_details ? implode(' ', array_filter([$doctor_details[0]['first_name'], $doctor_details[0]['middle_name'], $doctor_details[0]['last_name']])) : 'Unknown Doctor';

                $transactions           = $this->booking_doctors_fee->get_doctors_fee_transaction($transaction_from, $transaction_to, $doctor['doctor_id']) ?: [];
                $doctor['transactions'] = $transactions;
            }
        }

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $doctors,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Sales Tracker
     */
    public function get_sales_tracker()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $request_id       = $this->payload['request_id'] ?? null;
        $endorsement_id   = $this->payload['endorsement_id'] ?? null;
        $sae_in_charge_id = $this->payload['sae_in_charge_id'] ?? null;
        $page             = $this->payload['page'] ?? null;
        $data_size        = $this->payload['data_size'] ?? null;

        $requests = $this->request_model->get_sales_tracker_by_company($request_id, $sae_in_charge_id, $page, $data_size)?:[];
        $size = count($this->request_model->get_sales_tracker_by_company($request_id, $sae_in_charge_id, null, null)) ?: 0;

        if ($requests) {
            foreach ($requests as $key => $request) {
                if ($request['sae_in_charge_id']) {
                    // $sae_in_charge = $this->user->select('first_name, last_name', ['id' => $request['sae_in_charge_id'], 'is_deleted' => 0], 1) ?: null;
                    // $requests[$key]['sae_in_charge_name'] = $sae_in_charge ? $sae_in_charge->first_name . ' ' . $sae_in_charge->last_name : null;
                    $sae_in_charges = $this->user->get_sae_in_charges($request['sae_in_charge_id']) ?: null;
                    $requests[$key]['sae_in_charges'] = $sae_in_charges ?: [];
                }

                //get customer account details
                if ($request['customer_account_id']) {
                    $companies = $this->company->get_customer_accounts($request['customer_account_id']) ?: null;
                    $requests[$key]['customer_account_details'] = $companies ?: [];
                }

                //get booking details total sales
                $total_sales = 0;
                if (!empty($endorsement)) {
                    foreach ([1,2,3,4,7] as $branch_id) {

                    if ($this->resolve_branch_models($branch_id)) {

                            $booking_sales = $this->booking->get_for_sales($endorsement[0]['id']);

                            if (!empty($booking_sales)) {
                            $total_sales += $booking_sales[0]['booking_total_sales'];
                        }
                    }
                }
                }
                $requests[$key]['total_sales'] = $total_sales;
            }
        }

        // $endorsements = $this->endorsement->get_sales_tracker($endorsement_id, $sae_in_charge_id, $page, $data_size) ?: [];
        // $size         = count($this->endorsement->get_sales_tracker($endorsement_id, $sae_in_charge_id, null, null)) ?: 0;

        // if ($endorsements) {
        //     foreach ($endorsements as $key => $endorsement) {
        //         if ($endorsement['sae_in_charge_id']) {
        //             // $sae_in_charge = $this->user->select('first_name, last_name', ['id' => $endorsement['sae_in_charge_id'], 'is_deleted' => 0], 1) ?: null;
        //             // $endorsements[$key]['sae_in_charge_name'] = $sae_in_charge ? $sae_in_charge->first_name . ' ' . $sae_in_charge->last_name : null;
        //             $sae_in_charges                       = $this->user->get_sae_in_charges($endorsement['sae_in_charge_id']) ?: null;
        //             $endorsements[$key]['sae_in_charges'] = $sae_in_charges ?: [];
        //         }

        //         //get customer account details
        //         if ($endorsement['customer_account_id']) {
        //             $companies                                      = $this->company->get_customer_accounts($endorsement['customer_account_id']) ?: null;
        //             $endorsements[$key]['customer_account_details'] = $companies ?: [];
        //         }

        //         //get booking details total sales
        //         $total_sales = 0;
        //         foreach ([1, 2, 3, 4, 7] as $branch_id) {

        //             if ($this->resolve_branch_models($branch_id)) {

        //                 $booking_sales = $this->booking->get_for_sales($endorsement['endorsement_id']);

        //                 if (! empty($booking_sales)) {
        //                     $total_sales += $booking_sales[0]['booking_total_sales'];
        //                 }
        //             }
        //         }
        //         $endorsements[$key]['total_sales'] = $total_sales;
        //     }
        // }

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $requests,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Sales Account Tracking
     */
    public function get_sales_account_tracking()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $sales_person_id     = $this->payload['sales_person_id'] ?? null;
        $company_id          = $this->payload['company_id'] ?? null;
        $industry            = $this->payload['industry'] ?? null;            // (company.industry)
        $industry_category   = $this->payload['industry_category'] ?? null;   //line of business (company.industry_category)
        $business_type       = $this->payload['business_type'] ?? null;       // (company.business_type)
        $transaction_type    = $this->payload['transaction_type'] ?? null;    // (request.transaction_type) -> comma separated values
        $classification      = $this->payload['classification'] ?? null;      // (request.classification)
        $hmo_id              = $this->payload['hmo_id'] ?? null;              // (request.customer_account_id)
        $charge_to           = $this->payload['charge_to'] ?? null;           // (request.mode_of_payment) -> comma separated values
        $sae_in_charge_id    = $this->payload['sae_in_charge_id'] ?? null;    // (request.sae_in_charge_id)
        $preferred_branch_id = $this->payload['preferred_branch_id'] ?? null; // (request.preferred_branch_id) -> comma separated values
        $date_from           = $this->payload['date_from'] ?? null;
        $date_to             = $this->payload['date_to'] ?? null;
        $page                = $this->payload['page'] ?? null;
        $data_size           = $this->payload['data_size'] ?? null;
        $debug               = ! empty($this->payload['debug']);
        $tab                 = $this->payload['tab'] ?? null; // (request.tab)

        $filters = [
            'industry'            => $industry,
            'industry_category'   => $industry_category,
            'business_type'       => $business_type,
            'transaction_type'    => $transaction_type,
            'classification'      => $classification,
            'hmo_id'              => $hmo_id,
            'charge_to'           => $charge_to,
            'sae_in_charge_id'    => $sae_in_charge_id,
            'preferred_branch_id' => $preferred_branch_id,
        ];

        $requests = [];
        $size     = 0;

        if ($tab === 'dashboard') {
            $requests = $this->request_model->get_sales_tracker($sales_person_id, $company_id, $date_from, $date_to, $page, $data_size, $debug, $filters) ?: [];
            $size     = count($this->request_model->get_sales_tracker($sales_person_id, $company_id, $date_from, $date_to, null, null, $debug, $filters)) ?: 0;
        } elseif ($tab === 'tracker') {
            $requests = $this->request_model->get_sales_tracker_tracker($sales_person_id, $company_id, $date_from, $date_to, $page, $data_size, $debug, $filters) ?: [];
            $size     = $this->request_model->count_sales_tracker_tracker($sales_person_id, $company_id, $date_from, $date_to, $debug, $filters);
        } elseif ($tab === 'running') {
            $requests = $this->request_model->get_sales_tracker_running($sales_person_id, $company_id, $date_from, $date_to, $page, $data_size, $debug, $filters) ?: [];
            $size     = $this->request_model->count_sales_tracker_running($sales_person_id, $company_id, $date_from, $date_to, $debug, $filters);
        }

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $requests,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get billed and sales report by tab.
     *
     * Tabs: sales_and_billing, mode_of_payment, billing,
     * billing_consolacion, billing_mantlewood, billing_mactan,
     * billing_oakridge, billing_mobile
     *
     * Required payload: tab, date_from, date_to
     */
    public function get_billed_and_sales()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $tab                = $this->payload['tab'] ?? null;
        $date_from          = $this->payload['date_from'] ?? null;
        $date_to            = $this->payload['date_to'] ?? null;
        $company_id         = $this->payload['company_id'] ?? null;
        $sales_executive_id = $this->payload['sales_executive_id'] ?? null;
        $branch_id          = $this->payload['branch_id'] ?? null;
        $payment_term       = $this->payload['payment_term'] ?? null;
        $debug              = ! empty($this->payload['debug']);
        $allowed            = $this->billed_and_sales->get_allowed_tabs();

        if (! $tab || ! in_array($tab, $allowed, true)) {
            return $this->_create_response(
                FAIL,
                ['Invalid or missing tab. Allowed values: ' . implode(', ', $allowed)]
            );
        }

        if (! $date_from || ! $date_to) {
            return $this->_create_response(
                FAIL,
                ['date_from and date_to are required.']
            );
        }

        $normalized_branch_id    = null;
        $normalized_payment_term = null;
        if ($tab === 'mode_of_payment') {
            $normalized_branch_id = $this->billed_and_sales->normalize_mop_branch_id($branch_id);
            if ($normalized_branch_id === false) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
                );
            }

            $normalized_payment_term = $this->billed_and_sales->normalize_mop_payment_term($payment_term);
            if ($normalized_payment_term === false) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid payment_term. Allowed values: cash, debit, credit, hmo, corporate, yakap konsulta.']
                );
            }
        }

        $data = $this->billed_and_sales->get(
            $tab,
            $date_from,
            $date_to,
            $company_id,
            $sales_executive_id,
            $debug,
            $normalized_branch_id,
            $normalized_payment_term
        );

        if ($debug) {
            return $this->_create_response(SUCCESS, $data);
        }

        return $this->_create_response(SUCCESS, [
            'tab'       => $tab,
            'as_of'     => $date_to,
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'data'      => $data,
        ]);
    }

    /**
     * Aged payables report (detail / summary / analysis).
     */
    public function get_accounts_payable()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $payload = $this->_aged_payables_payload();
        if (! is_array($payload)) {
            return $payload;
        }

        return $this->_create_response(SUCCESS, $payload);
    }

    /**
     * Excel export of aged payables; uploaded to VM4.
     */
    public function get_accounts_payable_excel()
    {
        $payload = $this->_aged_payables_payload();
        if (! is_array($payload)) {
            return $payload;
        }

        $tab = $payload['tab'];
        $as_of_date = $payload['as_of'];
        $file_name = 'Aged_Payables_' . $tab . '_' . $as_of_date . '.xlsx';

        return $this->_ap_excel_to_vm4($file_name, $as_of_date, function ($sheet) use ($payload, $tab) {
            if ($tab === 'detail') {
                $this->_write_aged_payables_detail_sheet($sheet, $payload);
            } elseif ($tab === 'analysis') {
                $this->_write_aged_payables_analysis_sheet($sheet, $payload);
            } else {
                $this->_write_aged_payables_summary_sheet($sheet, $payload);
            }
        });
    }

    /**
     * Load and build the aged-payables payload used by JSON and Excel endpoints.
     *
     * @return array<string,mixed>|\CodeIgniter\HTTP\ResponseInterface
     */
    private function _aged_payables_payload()
    {
        $tab         = $this->payload['tab'] ?? null;
        $branch_id   = $this->payload['branch_id'] ?? null;
        $as_of_date  = $this->payload['as_of_date'] ?? date('Y-m-d');
        $allowedTabs = ['detail', 'summary', 'analysis'];
        $branchMap   = $this->_aged_payables_branch_map();
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $supplier_type = $this->payload['supplier_type'] ?? null;
        if (is_string($supplier_id)) { $supplier_id = trim($supplier_id); }
        if (is_string($supplier_type)) { $supplier_type = trim($supplier_type); }
        if ($supplier_id === '') { $supplier_id = null; }
        if ($supplier_type === '') { $supplier_type = null; }
        if ($supplier_type !== null && (!is_string($supplier_type)
            || !in_array($supplier_type, ['supplier', 'payor', 'patient'], true))) {
            return $this->_create_response(FAIL, ['Invalid supplier_type. Allowed values: supplier, payor, patient.']);
        }
        if ($supplier_id !== null) {
            if ((!is_int($supplier_id) && !is_string($supplier_id))
                || !ctype_digit((string) $supplier_id)
                || filter_var($supplier_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return $this->_create_response(FAIL, ['supplier_id must be a positive integer.']);
            }
            if ($supplier_type === null) {
                return $this->_create_response(FAIL, ['supplier_type is required when supplier_id is supplied.']);
            }
            $supplier_id = (int) $supplier_id;
        }


        if (! $tab || ! in_array($tab, $allowedTabs, true)) {
            return $this->_create_response(
                FAIL,
                ['Invalid or missing tab. Allowed values: ' . implode(', ', $allowedTabs)]
            );
        }

        if ($as_of_date && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $as_of_date)) {
            return $this->_create_response(
                FAIL,
                ['Invalid as_of_date. Use YYYY-MM-DD.']
            );
        }

        if ($branch_id !== null && $branch_id !== '') {
            if (!is_scalar($branch_id) || !ctype_digit((string) $branch_id)
                || !isset($branchMap[(int) $branch_id])) {
                return $this->_create_response(FAIL, ['Invalid branch_id. Select an active clinic or shared cost center.']);
            }
            $branchMap = [(int) $branch_id => $branchMap[(int) $branch_id]];
        }
        $rows = $this->accounts_payable_voucher->get_aged_payables_all_branches($branchMap, $as_of_date, $supplier_id, $supplier_type);

        $rows = $this->_fill_aged_payables_patient_names($rows);
        $rows = $this->_fill_aged_payables_invoice_references($rows);

        if (! $rows) {
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['No accounts payable found.']
            );
        }

        if ($tab === 'detail') {
            return $this->_build_aged_payables_detail($rows, $as_of_date);
        }

        if ($tab === 'summary') {
            return $this->_build_aged_payables_summary($rows, $as_of_date);
        }

        return $this->_build_aged_payables_analysis($rows, $as_of_date);
    }

    private function _ap_excel_to_vm4(string $file_name, string $as_of_date, callable $writer)
    {
        if (! class_exists(Spreadsheet::class)) {
            return $this->_create_response(FAIL, [
                'PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet:^1.29',
            ]);
        }

        $dir = WRITEPATH . 'accounts_payable/';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $this->_create_response(FAIL, ['Unable to create temporary Excel directory.']);
        }

        $local_path = $dir . $file_name;
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $writer($sheet);

        $xlsx = new Xlsx($spreadsheet);
        $xlsx->save($local_path);

        $vm4_path = 'assets/reports/accounts_payable/' . $as_of_date . '/';
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

    private function _write_aged_payables_header($sheet, string $title, string $as_of, string $lastCol): void
    {
        $as_of_label = date('j F Y', strtotime((string) $as_of));
        $sheet->setTitle($title);
        $sheet->setCellValue('A1', 'Clinica Prime Medical Center, Inc.');
        $sheet->setCellValue('A2', 'As of ' . $as_of_label);
        $sheet->setCellValue('A3', 'Ageing by due_date');
        $sheet->mergeCells('A1:' . $lastCol . '1');
        $sheet->mergeCells('A2:' . $lastCol . '2');
        $sheet->mergeCells('A3:' . $lastCol . '3');
        $sheet->getStyle('A1:A3')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $lastCol . '3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function _write_aged_payables_summary_sheet($sheet, array $payload): void
    {
        $this->_write_aged_payables_header($sheet, 'AP Summary', $payload['as_of'] ?? '', 'G');
        $headers = ['Contact', 'Suppliers Category', 'Current', '1 Month', '2 Months', '3 Months', 'Older'];
        $sheet->fromArray($headers, null, 'A5');
        $this->_ap_excel_style_header($sheet, 'A5:G5');

        $row = 6;
        $sheet->setCellValue('A' . $row, 'Aged Payables');
        $sheet->mergeCells('A' . $row . ':G' . $row);
        $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
        $row++;

        foreach ($payload['suppliers'] ?? [] as $supplier) {
            $sheet->setCellValue('A' . $row, $supplier['contact'] ?? '');
            $sheet->setCellValue('B' . $row, $supplier['suppliers_category'] ?? '');
            $sheet->setCellValue('C' . $row, (float) ($supplier['current'] ?? 0));
            $sheet->setCellValue('D' . $row, (float) ($supplier['1_month'] ?? 0));
            $sheet->setCellValue('E' . $row, (float) ($supplier['2_months'] ?? 0));
            $sheet->setCellValue('F' . $row, (float) ($supplier['3_months'] ?? 0));
            $sheet->setCellValue('G' . $row, (float) ($supplier['older'] ?? 0));
            $sheet->getStyle('C' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }

        $totals = $payload['totals'] ?? [];
        $sheet->setCellValue('A' . $row, 'Total Aged Payables');
        $this->_ap_excel_write_age_amounts($sheet, $row, $totals, 'C');
        $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('A' . $row, 'Total');
        $this->_ap_excel_write_age_amounts($sheet, $row, $totals, 'C');
        $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
        $row++;

        $pct = $payload['percentage_of_total'] ?? [];
        $sheet->setCellValue('A' . $row, 'Percentage of total');
        $sheet->setCellValue('C' . $row, (float) ($pct['current'] ?? 0));
        $sheet->setCellValue('D' . $row, (float) ($pct['1_month'] ?? 0));
        $sheet->setCellValue('E' . $row, (float) ($pct['2_months'] ?? 0));
        $sheet->setCellValue('F' . $row, (float) ($pct['3_months'] ?? 0));
        $sheet->setCellValue('G' . $row, (float) ($pct['older'] ?? 0));
        $sheet->getStyle('C' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('0.00%');
        $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function _write_aged_payables_detail_sheet($sheet, array $payload): void
    {
        $this->_write_aged_payables_header($sheet, 'AP Detail', $payload['as_of'] ?? '', 'K');
        $headers = ['Contact', 'Remarks', 'Invoice', 'Due Date', 'Current', '1 Month', '2 Months', '3 Months', 'Older', 'Total', 'Branch'];
        $sheet->fromArray($headers, null, 'A5');
        $this->_ap_excel_style_header($sheet, 'A5:K5');

        $row = 6;
        foreach ($payload['suppliers'] ?? [] as $supplier) {
            $sheet->setCellValue('A' . $row, $supplier['supplier_name'] ?? '');
            $sheet->setCellValue('E' . $row, (float) ($supplier['current'] ?? 0));
            $sheet->setCellValue('F' . $row, (float) ($supplier['1_month'] ?? 0));
            $sheet->setCellValue('G' . $row, (float) ($supplier['2_months'] ?? 0));
            $sheet->setCellValue('H' . $row, (float) ($supplier['3_months'] ?? 0));
            $sheet->setCellValue('I' . $row, (float) ($supplier['older'] ?? 0));
            $sheet->setCellValue('J' . $row, (float) ($supplier['total'] ?? 0));
            $sheet->getStyle('E' . $row . ':J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('A' . $row . ':K' . $row)->getFont()->setBold(true);
            $row++;

            foreach ($supplier['items'] ?? [] as $item) {
                $sheet->setCellValue('A' . $row, $supplier['supplier_name'] ?? '');
                $sheet->setCellValue('B' . $row, $item['remarks'] ?? '');
                $sheet->setCellValue('C' . $row, $item['invoice_reference'] ?? '');
                $sheet->setCellValue('D' . $row, $item['due_date'] ?? '');
                $sheet->setCellValue('E' . $row, (float) ($item['current'] ?? 0));
                $sheet->setCellValue('F' . $row, (float) ($item['1_month'] ?? 0));
                $sheet->setCellValue('G' . $row, (float) ($item['2_months'] ?? 0));
                $sheet->setCellValue('H' . $row, (float) ($item['3_months'] ?? 0));
                $sheet->setCellValue('I' . $row, (float) ($item['older'] ?? 0));
                $sheet->setCellValue('J' . $row, (float) ($item['total'] ?? 0));
                $sheet->setCellValue('K' . $row, $item['branch_name'] ?? '');
                $sheet->getStyle('E' . $row . ':J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                $row++;
            }
        }

        $totals = $payload['totals'] ?? [];
        $sheet->setCellValue('A' . $row, 'Total');
        $sheet->setCellValue('E' . $row, (float) ($totals['current'] ?? 0));
        $sheet->setCellValue('F' . $row, (float) ($totals['1_month'] ?? 0));
        $sheet->setCellValue('G' . $row, (float) ($totals['2_months'] ?? 0));
        $sheet->setCellValue('H' . $row, (float) ($totals['3_months'] ?? 0));
        $sheet->setCellValue('I' . $row, (float) ($totals['older'] ?? 0));
        $sheet->setCellValue('J' . $row, (float) ($totals['total'] ?? 0));
        $sheet->getStyle('E' . $row . ':J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A' . $row . ':K' . $row)->getFont()->setBold(true);

        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function _write_aged_payables_analysis_sheet($sheet, array $payload): void
    {
        $this->_write_aged_payables_header($sheet, 'AP Analysis', $payload['as_of'] ?? '', 'G');
        $headers = ['Group', 'Subgroup', '1 Month', '2 Months', '3 Months', 'Over 3 Months', 'Total'];
        $sheet->fromArray($headers, null, 'A5');
        $this->_ap_excel_style_header($sheet, 'A5:G5');

        $row = 6;
        foreach ($payload['groups'] ?? [] as $group) {
            $sheet->setCellValue('A' . $row, $group['group'] ?? '');
            $sheet->setCellValue('B' . $row, $group['subgroup'] ?? '');
            $sheet->setCellValue('C' . $row, (float) ($group['1_month'] ?? 0));
            $sheet->setCellValue('D' . $row, (float) ($group['2_months'] ?? 0));
            $sheet->setCellValue('E' . $row, (float) ($group['3_months'] ?? 0));
            $sheet->setCellValue('F' . $row, (float) ($group['over_3_months'] ?? 0));
            $sheet->setCellValue('G' . $row, (float) ($group['total'] ?? 0));
            $sheet->getStyle('C' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }

        $totals = $payload['totals'] ?? [];
        $sheet->setCellValue('A' . $row, 'Total');
        $sheet->setCellValue('C' . $row, (float) ($totals['1_month'] ?? 0));
        $sheet->setCellValue('D' . $row, (float) ($totals['2_months'] ?? 0));
        $sheet->setCellValue('E' . $row, (float) ($totals['3_months'] ?? 0));
        $sheet->setCellValue('F' . $row, (float) ($totals['over_3_months'] ?? 0));
        $sheet->setCellValue('G' . $row, (float) ($totals['total'] ?? 0));
        $sheet->getStyle('C' . $row . ':G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Subgroup totals');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        foreach ($payload['subgroup_totals'] ?? [] as $rollup) {
            $sheet->setCellValue('A' . $row, $rollup['group'] ?? '');
            $sheet->setCellValue('G' . $row, (float) ($rollup['total'] ?? 0));
            $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function _ap_excel_write_age_amounts($sheet, int $row, array $amounts, string $startCol): void
    {
        $cols = ['current', '1_month', '2_months', '3_months', 'older'];
        $colIndex = ord($startCol);
        foreach ($cols as $key) {
            $col = chr($colIndex);
            $sheet->setCellValue($col . $row, (float) ($amounts[$key] ?? 0));
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $colIndex++;
        }
    }

    private function _ap_excel_style_header($sheet, string $range): void
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

    /**
     * Collection Monitoring Report — collected amounts by branch and days overdue.
     */
    public function collection_monitoring_report()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $date_from         = $this->payload['date_from'] ?? null;
        $date_to           = $this->payload['date_to'] ?? null;
        $company_id        = $this->payload['company_id'] ?? null;
        $charge_to         = $this->payload['charge_to_id'] ?? null;
        $sae_in_charge_id  = $this->payload['sae_in_charge_id'] ?? null;

        if (!$date_from || !$date_to) {
            return $this->_create_response(
                FAIL,
                ['date_from and date_to are required.']
            );
        }

        $data = $this->collection_monitoring->get($date_from, $date_to, $company_id, $charge_to, $sae_in_charge_id);

        return $this->_create_response(SUCCESS, [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'data'      => $data,
        ]);
    }

    /**
     * Get Billing and Collection Summary
     */
    public function get_billing_and_collection_summary()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $billing_id = $this->payload['billing_id'] ?? null;
        $date_from  = $this->payload['date_from'] ?? null;
        $date_to    = $this->payload['date_to'] ?? null;
        $charge_to  = $this->payload['charge_to'] ?? null;
        [$page, $data_size] = list_page_params($this->payload);

        $summaries = $this->billing->get_billing_and_collection_summary($billing_id, $date_from, $date_to, $charge_to, $page, $data_size) ?: [];
        $size      = $this->billing->count_billing_and_collection_summary($billing_id, $date_from, $date_to, $charge_to);

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $summaries,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Billing invoices on master, optionally filtered by clinic branch_id.
     */
    public function get_billings()
    {
        [$valid_range, $date_range] = $this->_required_billing_report_date_range();
        if (! $valid_range) {
            return $date_range;
        }

        [$date_from, $date_to] = $date_range;
        $branch_id = $this->payload['branch_id'] ?? null;
        $charge_to = $this->payload['charge_to'] ?? $this->payload['charge_to_id'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        [$page, $data_size] = list_page_params($this->payload);

        if ($branch_id === '' || (int) $branch_id === 0) {
            $branch_id = null;
        }

        $billings = $this->billing->get_details(
            null,
            $date_from,
            $date_to,
            $company_id,
            $charge_to,
            $status,
            $page,
            $data_size,
            $term,
            $branch_id
        ) ?: [];
        $size = $this->billing->count_details(
            null,
            $date_from,
            $date_to,
            $company_id,
            $charge_to,
            $status,
            $term,
            $branch_id
        );

        if (!$billings) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No billings found.']);
        }

        $billings = $this->_attach_billing_report_names($billings);

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $billings,
        ]);
    }

    /**
     * Collections on master billing_payment, optionally filtered by clinic branch_id.
     */
    public function get_collections()
    {
        [$valid_range, $date_range] = $this->_required_billing_report_date_range();
        if (! $valid_range) {
            return $date_range;
        }

        [$date_from, $date_to] = $date_range;
        $billing_payment_id = $this->payload['billing_payment_id'] ?? null;
        if (is_string($billing_payment_id)) {
            $billing_payment_id = trim($billing_payment_id);
        }
        if ($billing_payment_id === '') {
            $billing_payment_id = null;
        }
        if ($billing_payment_id !== null) {
            if ((!is_int($billing_payment_id) && !is_string($billing_payment_id))
                || filter_var($billing_payment_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return $this->_create_response(FAIL, ['billing_payment_id must be a positive integer.']);
            }
            $billing_payment_id = (int) $billing_payment_id;
        }
        $branch_id = $this->payload['branch_id'] ?? null;
        $charge_to = $this->payload['charge_to'] ?? $this->payload['charge_to_id'] ?? null;
        $mop = $this->payload['mop'] ?? null;
        $date = $this->payload['date'] ?? null;
        [$page, $data_size] = list_page_params($this->payload);

        if ($branch_id === '' || (int) $branch_id === 0) {
            $branch_id = null;
        }

        $payments = $this->billing_payment->get_details(
            $billing_payment_id,
            $mop,
            $date,
            $charge_to,
            $page,
            $data_size,
            null,
            $branch_id,
            $date_from,
            $date_to
        ) ?: [];
        $size = $this->billing_payment->count_details($billing_payment_id, $mop, $date, $charge_to, null, $branch_id, $date_from, $date_to);

        if (!$payments) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No collections found.']);
        }

        $payments = $this->_attach_collection_report_names($payments);
        // if ($billing_payment_id !== null) {
            $entries_by_payment = $this->_collection_entries_service()->get_entries_by_payment_ids(array_column($payments, 'id'));
            foreach ($payments as $key => $payment) {
                $payments[$key]['entries'] = $entries_by_payment[(int) $payment['id']] ?? [];
            }
        // }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $payments,
        ]);
    }

    protected function _collection_entries_service(): \App\Libraries\Billing_collection_entries
    {
        if ($this->collection_entries_service === null) {
            $this->collection_entries_service = new \App\Libraries\Billing_collection_entries(
                $this->billing_payment_entry,
                $this->billing_entry,
                $this->branch_master,
                new \App\Models\Yakap_billing()
            );
        }
        return $this->collection_entries_service;
    }

    /** Generate an Excel link for the same filtered billing list. */
    public function get_billings_excel()
    {
        [$valid_range, $date_range] = $this->_required_billing_report_date_range();
        if (! $valid_range) {
            return $date_range;
        }
        [$date_from, $date_to] = $date_range;

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === '' || (int) $branch_id === 0) {
            $branch_id = null;
        }
        $charge_to = $this->payload['charge_to'] ?? $this->payload['charge_to_id'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;

        return $this->_export_billing_report_excel(
            'billings',
            $date_from,
            $date_to,
            function (int $page, int $batch_size) use ($date_from, $date_to, $company_id, $charge_to, $status, $term, $branch_id) {
                $rows = $this->billing->get_details(
                    null, $date_from, $date_to, $company_id, $charge_to, $status,
                    $page, $batch_size, $term, $branch_id
                );
                return $rows === false ? false : $this->_attach_billing_report_names($rows ?: []);
            }
        );
    }

    /** Generate an Excel link for the same filtered collection list. */
    public function get_collections_excel()
    {
        [$valid_range, $date_range] = $this->_required_billing_report_date_range();
        if (! $valid_range) {
            return $date_range;
        }
        [$date_from, $date_to] = $date_range;

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === '' || (int) $branch_id === 0) {
            $branch_id = null;
        }
        $charge_to = $this->payload['charge_to'] ?? $this->payload['charge_to_id'] ?? null;
        $mop = $this->payload['mop'] ?? null;
        $date = $this->payload['date'] ?? null;

        return $this->_export_billing_report_excel(
            'collections',
            $date_from,
            $date_to,
            function (int $page, int $batch_size) use ($mop, $date, $charge_to, $branch_id, $date_from, $date_to) {
                $rows = $this->billing_payment->get_details(
                    null, $mop, $date, $charge_to, $page, $batch_size, null,
                    $branch_id, $date_from, $date_to
                );
                return $rows === false ? false : $this->_attach_collection_report_names($rows ?: []);
            }
        );
    }

    private function _required_billing_report_date_range(): array
    {
        $date_from = trim((string) ($this->payload['date_from'] ?? ''));
        $date_to = trim((string) ($this->payload['date_to'] ?? ''));
        if ($date_from === '' || $date_to === '') {
            return [false, $this->_create_response(FAIL, ['date_from and date_to are required.'])];
        }

        foreach ([$date_from, $date_to] as $date) {
            $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
            $errors = \DateTime::getLastErrors();
            if (! $parsed || $parsed->format('Y-m-d') !== $date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                return [false, $this->_create_response(FAIL, ['Invalid date range. Use YYYY-MM-DD.'])];
            }
        }

        if ($date_from > $date_to) {
            return [false, $this->_create_response(FAIL, ['date_from must be on or before date_to.'])];
        }

        return [true, [$date_from, $date_to]];
    }

    private function _attach_billing_report_names(array $rows): array
    {
        $company_ids = array_merge(array_column($rows, 'company_id'), array_column($rows, 'charge_to'));
        $ids = array_values(array_unique(array_filter(array_map('intval', $company_ids))));
        $companies = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = $this->company->db->query(
                "SELECT id, name FROM company WHERE is_deleted = 0 AND id IN ($placeholders)",
                $ids
            );
            foreach ($query ? $query->getResult() : [] as $row) {
                $companies[(int) $row->id] = $row;
            }
        }

        foreach ($rows as $key => $billing) {
            $rows[$key]['company_name'] = $companies[(int) ($billing['company_id'] ?? 0)]->name ?? null;
            $rows[$key]['charge_to_name'] = $companies[(int) ($billing['charge_to'] ?? 0)]->name ?? null;
        }
        return $rows;
    }

    private function _attach_collection_report_names(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'charge_to')))));
        $companies = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = $this->company->db->query(
                "SELECT id, name FROM company WHERE is_deleted = 0 AND id IN ($placeholders)",
                $ids
            );
            foreach ($query ? $query->getResult() : [] as $row) {
                $companies[(int) $row->id] = $row;
            }
        }

        foreach ($rows as $key => $payment) {
            $rows[$key]['charge_to_name'] = $companies[(int) ($payment['charge_to'] ?? 0)]->name ?? null;
        }
        return $rows;
    }

    private function _export_billing_report_excel(string $report, string $date_from, string $date_to, callable $fetch_batch)
    {
        if (! class_exists(\ZipArchive::class)) {
            return $this->_create_response(FAIL, ['PHP ZIP extension is required to generate Excel files.']);
        }

        $file_name = ucfirst($report) . '_' . $date_from . '_' . $date_to . '_' . date('Ymd_His') . '.xlsx';
        $dir = WRITEPATH . 'billing_reports/';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $this->_create_response(FAIL, ['Unable to create temporary Excel directory.']);
        }

        $local_path = $dir . $file_name;
        $sheet_path = tempnam($dir, 'billing_report_sheet_');
        if ($sheet_path === false || ! ($sheet_file = fopen($sheet_path, 'wb'))) {
            return $this->_create_response(FAIL, ['Unable to create temporary Excel worksheet.']);
        }

        fwrite($sheet_file, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($sheet_file, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        $row_number = 1;
        $page = 1;
        // sql_limit_clause caps list queries at 100 rows per page.
        $batch_size = 100;
        $headers = null;

        do {
            $rows = $fetch_batch($page, $batch_size);
            if (! is_array($rows)) {
                fclose($sheet_file);
                @unlink($sheet_path);
                return $this->_create_response(FAIL, ['Unable to retrieve report rows.']);
            }
            if ($rows && $headers === null) {
                $headers = array_keys($rows[0]);
                fwrite($sheet_file, $this->_billing_excel_row_xml($headers, $row_number));
                $row_number++;
            }

            foreach ($rows as $row) {
                fwrite($sheet_file, $this->_billing_excel_row_xml($row, $row_number, $headers));
                $row_number++;
            }

            $page++;
        } while (count($rows) === $batch_size);

        if ($headers === null) {
            fclose($sheet_file);
            @unlink($sheet_path);
            return $this->_create_response(FAIL_NOT_FOUND, ['No ' . $report . ' found for the selected filters.']);
        }

        fwrite($sheet_file, '</sheetData></worksheet>');
        fclose($sheet_file);

        $zip = new \ZipArchive();
        if ($zip->open($local_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($sheet_path);
            return $this->_create_response(FAIL, ['Failed to create Excel workbook.']);
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . ucfirst($report) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');
        $zip->addFile($sheet_path, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($sheet_path);

        $vm4_path = 'assets/reports/billing_' . $report . '/' . $date_from . '_' . $date_to . '/';
        $upload = upload_to_vm4(
            new Local_upload_file($local_path, $file_name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            $vm4_path
        );
        if (is_file($local_path)) {
            @unlink($local_path);
        }
        if (! $upload || empty($upload['success']) || empty($upload['path'])) {
            return $this->_create_response(FAIL, [$upload['error'] ?? 'Failed to upload Excel file to VM4.']);
        }

        return $this->_create_response(SUCCESS, [
            'file_name' => $file_name,
            'file_url' => rtrim(VM4_ENDPOINT, '/') . '/' . ltrim($upload['path'], '/'),
        ]);
    }

    private function _billing_excel_row_xml(array $values, int $row_number, ?array $headers = null): string
    {
        $xml = '<row r="' . $row_number . '">';
        foreach (array_values($values) as $index => $value) {
            $cell = $this->_billing_excel_column_name($index + 1) . $row_number;
            if ($value === null || $value === '') {
                $xml .= '<c r="' . $cell . '"/>';
            } elseif (is_bool($value) || (is_numeric($value) && in_array($headers[$index] ?? '', [
                'grand_total', 'balance', 'paid_amount', 'amount', 'wht_amount', 'wht_percent',
            ], true))) {
                $xml .= '<c r="' . $cell . '"><v>' . (is_bool($value) ? (int) $value : $value) . '</v></c>';
            } else {
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value) ?? '';
                $text = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    }

    private function _billing_excel_column_name(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $column = intdiv($column - 1, 26);
        }
        return $name;
    }

    /**
     * Trace one CSR transaction and its related booking, payment, billing, GL, and JE rows.
     *
     * Required payload: branch_id, transaction_no.
     * Optional: patient_id, transaction_date (YYYY-MM-DD).
     */
    public function get_transaction_for_tracing()
    {
        $branch_id        = $this->payload['branch_id'] ?? null;
        $transaction_no   = $this->payload['transaction_no'] ?? null;
        $patient_id       = $this->payload['patient_id'] ?? null;
        $transaction_date = $this->payload['transaction_date'] ?? null;

        if (! $branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (! $this->resolve_branch_models((int) $branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        if ($transaction_no === null || $transaction_no === '') {
            return $this->_create_response(
                FAIL,
                ['transaction_no is required.']
            );
        }

        if ($patient_id === null || $patient_id === '') {
            $patient_id = null;
        }

        if ($transaction_date === null || $transaction_date === '') {
            $transaction_date = null;
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $transaction_date)) {
            return $this->_create_response(
                FAIL,
                ['Invalid transaction_date. Use YYYY-MM-DD.']
            );
        }

        $transaction_model = new \App\Models\Transaction($this->booking->db);
        $data              = $transaction_model->get_for_tracing(
            $transaction_no,
            $patient_id,
            $transaction_date,
            (int) $branch_id
        );

        if (! $data) {
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['Transaction not found.']
            );
        }

        return $this->_create_response(SUCCESS, $data, true);
    }

    /**
     * Get Sales Report (transactions)
     */
    public function get_sales_report()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        if (! $branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (! $this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $patient_id = $this->payload['patient_id'] ?? null;
        $billing_id = $this->payload['billing_id'] ?? null;
        $is_billed  = $this->payload['is_billed'] ?? null;
        $is_paid    = $this->payload['is_paid'] ?? null;
        $date_from  = $this->payload['date_from'] ?? null;
        $date_to    = $this->payload['date_to'] ?? null;
        $page       = $this->payload['page'] ?? null;
        $data_size  = $this->payload['data_size'] ?? null;

        $transactions = $this->booking->get_transaction_sales($patient_id, $billing_id, $is_billed, $is_paid, $date_from, $date_to, $page, $data_size) ?: [];
        $all_transactions = $this->booking->get_transaction_sales($patient_id, $billing_id, $is_billed, $is_paid, $date_from, $date_to, null, null) ?: [];
        $size             = count($all_transactions);

        $all_transaction_ids = array_column($all_transactions, 'id');
        $booking_all         = new \App\Models\Booking_all();
        $details_by_transaction = $this->booking->get_transaction_sales_details($all_transaction_ids);
        $discounts_by_transaction = $booking_all->get_transaction_discounts_on_connection(
            $this->booking->db,
            $all_transaction_ids
        );

        $total_net_sales = 0.0;
        foreach ($all_transactions as $row) {
            $transaction_id = (int) ($row['id'] ?? 0);
            $totals = $this->_sales_report_line_totals(
                $details_by_transaction[$transaction_id] ?? [],
                $discounts_by_transaction[$transaction_id] ?? []
            );
            $total_net_sales += $totals['grand_total'];
        }
        $total_net_sales = round($total_net_sales, 2);

        foreach ($transactions as &$transaction) {
            $transaction_id = (int) ($transaction['id'] ?? 0);
            $details        = $details_by_transaction[$transaction_id] ?? [];
            $discount       = $discounts_by_transaction[$transaction_id] ?? [];
            $totals         = $this->_sales_report_line_totals($details, $discount);
            $transaction['transaction_details'] = $details;
            $transaction['discount']            = $discount;
            $transaction['discount_total']      = $totals['discount_total'];
            $transaction['amount']              = $totals['amount'];
            $transaction['grand_total']         = $totals['grand_total'];
        }
        unset($transaction);

        $response_status = SUCCESS;
        $response        = [
            'size'    => $size,
            'data'    => $transactions,
            'summary' => [
                'total_net_sales' => $total_net_sales,
            ],
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * amount = sum(transaction_details.amount); grand_total = amount - discount_total.
     *
     * @param array<int, array> $details
     * @param array<int, array> $discount
     * @return array{amount: float, discount_total: float, grand_total: float}
     */
    private function _sales_report_line_totals(array $details, array $discount)
    {
        $amount = 0.0;
        foreach ($details as $line) {
            $amount += (float) ($line['amount'] ?? 0);
        }
        $discount_total = 0.0;
        foreach ($discount as $row) {
            if (! is_array($row)) {
                continue;
            }
            $discount_total += (float) ($row['total_discount'] ?? 0);
        }
        $amount         = round($amount, 2);
        $discount_total = round($discount_total, 2);

        return [
            'amount'         => $amount,
            'discount_total' => $discount_total,
            'grand_total'    => round(max(0, $amount - $discount_total), 2),
        ];
    }

    /**
     * Get mobile van transactions (mobile diagnostic DB only).
     */
    public function get_mobile_van_transaction()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $booking = new \App\Models\Booking(db_connect('mobile'));

        $mobile_van_id = $this->payload['mobile_van_id'] ?? null;
        $supplier_id   = $this->payload['supplier_id'] ?? null;
        $patient_id    = $this->payload['patient_id'] ?? null;
        $date_from     = $this->payload['date_from'] ?? null;
        $date_to       = $this->payload['date_to'] ?? null;
        $term          = $this->payload['term'] ?? null;
        $page          = $this->payload['page'] ?? null;
        $data_size     = $this->payload['data_size'] ?? null;

        $transactions = $booking->get_mobile_van_transaction(
            $mobile_van_id,
            $supplier_id,
            $patient_id,
            $date_from,
            $date_to,
            $term,
            $page,
            $data_size
        ) ?: [];
        $size = count($booking->get_mobile_van_transaction(
            $mobile_van_id,
            $supplier_id,
            $patient_id,
            $date_from,
            $date_to,
            $term,
            null,
            null
        ) ?: []);

        $transaction_ids        = array_column($transactions, 'id');
        $details_by_transaction = $booking->get_mobile_van_transaction_details(
            $transaction_ids,
            $mobile_van_id,
            $supplier_id
        );

        foreach ($transactions as &$transaction) {
            $transaction_id = (int) ($transaction['id'] ?? 0);
            $transaction['transaction_details'] = $details_by_transaction[$transaction_id] ?? [];
        }
        unset($transaction);

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $transactions,
        ], true);
    }

    /**
     * Get Daily Sales
     */
    public function get_daily_sales()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id  = $this->payload['branch_id'] ?? null;
        $date_from  = $this->payload['date_from'] ?? null;
        $date_to    = $this->payload['date_to'] ?? null;
        $sales_date = $this->payload['sales_date'] ?? null;
        $page       = $this->payload['page'] ?? null;
        $data_size  = $this->payload['data_size'] ?? null;

        $daily_sales = $this->report->get_daily_sales($branch_id, $date_from, $date_to, $sales_date, $page, $data_size) ?: [];
        $size        = count($this->report->get_daily_sales($branch_id, $date_from, $date_to, $sales_date, null, null) ?: []);

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $daily_sales,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Pending results and finalization performance per section.
     */
    public function get_pending_result()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->_normalize_tat_branch_id();
        if ($branch_id === false) {
            return $this->_create_response(FAIL, ['Invalid Branch ID.']);
        }

        // The report summarises whole sections at once, so an open ended range would scan the full
        // history to produce a handful of rows. A date range is required rather than optional.
        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;

        if (! $date_from || ! $date_to) {
            return $this->_create_response(FAIL, ['Date from and date to are required.']);
        }

        $section_rows = $this->report->get_pending_result($branch_id, $date_from, $date_to) ?: [];
        $sections     = $this->_build_pending_result($section_rows);

        if (! $sections) {
            $response_status = FAIL_NOT_FOUND;
            $response        = [
                'No exams found.',
            ];
        } else {
            $response_status = SUCCESS;
            $response        = [
                'size'      => count($sections),
                'date_from' => $date_from,
                'date_to'   => $date_to,
                'data'      => $sections,
            ];
        }

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Shape the aggregated section rows for the Pending Result report.
     *
     * RULES: The finalization rate is finalized over total exams, where finalized is the sum of the
     * early, on time and delayed counts. Every finalized exam falls into exactly one of those three
     * buckets, so a section's pending count plus the three always equals its total exams.
     */
    protected function _build_pending_result(array $rows): array
    {
        $sections = [];

        foreach ($rows as $row) {
            $total     = (int) ($row['total_exams'] ?? 0);
            $early     = (int) ($row['early'] ?? 0);
            $on_time   = (int) ($row['on_time'] ?? 0);
            $delay     = (int) ($row['delay'] ?? 0);
            $finalized = $early + $on_time + $delay;

            $sections[] = [
                'branch_id'         => $row['branch_id'] ?? '',
                'branch'            => $row['branch'] ?? '',
                'section'           => $row['section'] ?? '',
                'department'        => $row['department'] ?? '',
                'pending_results'   => (int) ($row['pending_results'] ?? 0),
                'tat'               => $this->_pending_result_hms($row['standard_minutes'] ?? null),
                'early'             => $early,
                'on_time'           => $on_time,
                'delay'             => $delay,
                'min_tat'           => $this->_pending_result_hrs_mins($row['min_minutes'] ?? null),
                'max_tat'           => $this->_pending_result_hrs_mins($row['max_minutes'] ?? null),
                'released'          => (int) ($row['released'] ?? 0),
                'emailed'           => (int) ($row['emailed'] ?? 0),
                'printed'           => (int) ($row['printed'] ?? 0),
                'delivered'         => (int) ($row['delivered'] ?? 0),
                'total_exams'       => $total,
                'finalization_rate' => $total > 0
                    ? number_format($finalized / $total * 100, 2)
                    : '0.00',
            ];
        }

        return $sections;
    }

    /**
     * Standard TAT as H:MM:SS, so 240 minutes reads "4:00:00".
     *
     * Hours are unpadded and may exceed two digits, since send out sections carry standards of
     * several days ("240:00:00").
     */
    private function _pending_result_hms($minutes): string
    {
        if ($minutes === null || $minutes === '') {
            return '';
        }

        $total = max(0, (int) round((float) ($minutes ?? 0)));

        return sprintf('%d:%02d:00', intdiv($total, 60), $total % 60);
    }

    /**
     * Measured TAT as "2 hrs 48 mins". A section with nothing finalized reads "0 hrs 0 mins".
     */
    private function _pending_result_hrs_mins($minutes): string
    {
        $total = max(0, (int) round((float) ($minutes ?? 0)));

        return sprintf('%d hrs %d mins', intdiv($total, 60), $total % 60);
    }

    /**
     * Get Patient TAT
     */
    public function get_patient_tat()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->_normalize_tat_branch_id();
        if ($branch_id === false) {
            return $this->_create_response(
                FAIL,
                ['Invalid branch_id. Allowed values: all, 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
            );
        }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;
        $tat_date  = $this->payload['tat_date'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $patient_tats = $this->report->get_patient_tat($branch_id, $date_from, $date_to, $tat_date) ?: [];
        $size         = count($patient_tats);

        if ($page && $data_size) {
            $page   = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $patient_tats = array_slice($patient_tats, $offset, (int) $data_size);
        }

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $patient_tats,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Service TAT (exam-level booking_details / booking_package_details).
     */
    public function get_service_tat()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->_normalize_tat_branch_id();
        if ($branch_id === false) {
            return $this->_create_response(
                FAIL,
                ['Invalid branch_id. Allowed values: all, 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
            );
        }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;
        $term      = $this->payload['term'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $rows = $this->report->get_service_tat($branch_id, $date_from, $date_to, $term, $page, $data_size) ?: [];
        $size = $this->report->get_service_tat_count($branch_id, $date_from, $date_to, $term);

        if (! $rows) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No service TAT records found.'], true);
        }

        $section_bounds = $this->_service_tat_section_bounds($branch_id, $date_from, $date_to);

        foreach ($rows as &$row) {
            $bound = $section_bounds[trim((string) ($row['section_name'] ?? ''))] ?? [];

            $row['min_released_time']               = $this->_service_tat_seconds_hms($bound['min_released'] ?? null);
            $row['max_finalized_time']              = $this->_service_tat_seconds_hms($bound['max_finalized'] ?? null);
            $row['reg_to_approve']                  = $this->_service_tat_duration_hms($row['registered_added_on'] ?? null, $row['approved_csr_transacted_on'] ?? null);
            $row['approve_to_in_process']           = $this->_service_tat_duration_hms($row['approved_csr_transacted_on'] ?? null, $row['in_process_result_status'] ?? null);
            $row['in_process_to_completed']         = $this->_service_tat_duration_hms($row['in_process_result_status'] ?? null, $row['completed_result_status'] ?? null);
            $row['completed_to_finalized']          = $this->_service_tat_duration_hms($row['completed_result_status'] ?? null, $row['finalized_result_status'] ?? null);
            $row['finalized_to_released']           = $this->_service_tat_duration_hms($row['finalized_result_status'] ?? null, $row['released_on'] ?? null);
            $row['in_process_to_finalize']          = $this->_service_tat_duration_hms($row['in_process_result_status'] ?? null, $row['finalized_result_status'] ?? null);
            $row['total_tat_in_process_to_finalize'] = $row['in_process_to_finalize'];
        }
        unset($row);

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $rows,
        ], true);
    }

    private function _service_tat_duration_hms($time_start, $time_end): ?string
    {
        if (empty($time_start) || empty($time_end)) {
            return null;
        }

        $start = strtotime($time_start);
        $end   = strtotime($time_end);
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return $this->_service_tat_seconds_hms($end - $start);
    }

    private function _service_tat_seconds_hms($seconds): ?string
    {
        if ($seconds === null || $seconds === '' || ! is_numeric($seconds)) {
            return null;
        }

        $seconds = (int) $seconds;
        if ($seconds < 0) {
            return null;
        }

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /**
     * Section wide release and finalize bounds for Service TAT, keyed by section name.
     *
     * RULES: The model hands back one row per section per union half. Min Released Time is the
     * fastest emailed_on from in_process_on. A section that shows up in both halves keeps the
     * smaller minimum and the larger maximum.
     */
    private function _service_tat_section_bounds($branch_id, $date_from, $date_to): array
    {
        $bounds = $this->report->get_service_tat_section_bounds($branch_id, $date_from, $date_to) ?: [];
        $merged = [];

        foreach ($bounds as $bound) {
            $section = trim((string) ($bound['section_name'] ?? ''));
            if ($section === '') {
                continue;
            }

            $released = [];
            if (isset($bound['min_emailed_seconds']) && $bound['min_emailed_seconds'] !== '') {
                $released[] = (int) $bound['min_emailed_seconds'];
            }

            $min_released  = $released === [] ? null : min($released);
            $max_finalized = isset($bound['max_finalized_seconds']) && $bound['max_finalized_seconds'] !== ''
                ? (int) $bound['max_finalized_seconds']
                : null;

            $current = $merged[$section] ?? ['min_released' => null, 'max_finalized' => null];

            if ($min_released !== null) {
                $current['min_released'] = $current['min_released'] === null
                    ? $min_released
                    : min($current['min_released'], $min_released);
            }

            if ($max_finalized !== null) {
                $current['max_finalized'] = $current['max_finalized'] === null
                    ? $max_finalized
                    : max($current['max_finalized'], $max_finalized);
            }

            $merged[$section] = $current;
        }

        return $merged;
    }

    /**
     * Get PCP Clinic Branch TAT
     */
    public function get_pcp_clinic_tat()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        if (! $branch_id) {
            return $this->_create_response(FAIL, ['Branch ID is required.']);
        }

        if (! in_array((int) $branch_id, [1, 2, 3, 4, 7], true)) {
            return $this->_create_response(
                FAIL,
                ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
            );
        }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;
        $tat_date  = $this->payload['tat_date'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $pcp_clinic_tats = $this->report->get_pcp_clinic_tat($branch_id, $date_from, $date_to, $tat_date) ?: [];
        $size            = count($pcp_clinic_tats);

        if ($page && $data_size) {
            $page   = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $pcp_clinic_tats = array_slice($pcp_clinic_tats, $offset, (int) $data_size);
        }

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $pcp_clinic_tats,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Specialist MD Branch TAT
     */
    public function get_specialist_md_tat()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        if (! $branch_id) {
            return $this->_create_response(FAIL, ['Branch ID is required.']);
        }

        if (! in_array((int) $branch_id, [1, 2, 3, 4, 7], true)) {
            return $this->_create_response(
                FAIL,
                ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
            );
        }

        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;
        $tat_date  = $this->payload['tat_date'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $specialist_md_tats = $this->report->get_specialist_md_tat($branch_id, $date_from, $date_to, $tat_date) ?: [];
        $size               = count($specialist_md_tats);

        if ($page && $data_size) {
            $page   = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $specialist_md_tats = array_slice($specialist_md_tats, $offset, (int) $data_size);
        }

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $specialist_md_tats,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Specialist MD Branch TAT
     */
    public function get_medrecord()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id      = $this->payload['branch_id'] ?? null;
        $company_id     = $this->payload['company_id'] ?? null;
        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $date_from      = $this->payload['date_from'] ?? null;
        $date_to        = $this->payload['date_to'] ?? null;
        $term        = $this->payload['term'] ?? null;
        $page           = $this->payload['page'] ?? null;
        $data_size      = $this->payload['data_size'] ?? null;

        $refresh = [
            'queued'   => false,
            'branches' => [],
            'dates'    => [],
        ];
        if ($page === null || $page === '' || (int) $page === 1) {
            $refresh = $this->report->queue_medrecord_refresh(
                ($branch_id !== null && $branch_id !== '') ? (int) $branch_id : null,
                $date_from,
                $date_to
            );
        }

        $medrecords = $this->report->get_medrecord($branch_id, $company_id, $endorsement_id, $date_from, $date_to, $term, $page, $data_size) ?: [];
        $size       = count($this->report->get_medrecord($branch_id, $company_id, $endorsement_id, $date_from, $date_to, $term, null, null) ?: []);

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $medrecords,
            'refresh' => [
                'queued'   => $refresh['queued'],
                'branches' => $refresh['branches'],
                'dates'    => $refresh['dates'],
            ],
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Force medrecord evaluation to done (for_evaluation = 2)
     */
    public function force_evaluate()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $medrecord_id = $this->payload['medrecord_id'] ?? null;
        if (! $medrecord_id) {
            return $this->_create_response(FAIL, ['medrecord_id is required.']);
        }

        $force_evaluate_by = $this->payload['requester'] ?? null;
        $updated           = $this->report->force_evaluate((int) $medrecord_id, $force_evaluate_by);
        if ($updated === false) {
            return $this->_create_response(FAIL_NOT_FOUND, ['Medrecord not found or update failed.']);
        }

        $response_status = SUCCESS;
        $response        = [
            'status' => 200,
            'message' => 'Medrecord evaluation forced successfully.',
            'data' => $updated,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Sales Census
     */
    public function get_sales_census()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id  = $this->payload['branch_id'] ?? null;
        $tat_date   = $this->payload['tat_date'] ?? null;
        $service_id = $this->payload['service_id'] ?? null;
        $parent_category_id = $this->payload['parent_category_id'] ?? null;
        $category_id = $this->payload['category_id'] ?? null;
        $group_id = $this->payload['group_id'] ?? null;
        $page       = $this->payload['page'] ?? null;
        $data_size  = $this->payload['data_size'] ?? null;

        $result = $this->report->get_sales_census($branch_id, $tat_date, $service_id, $parent_category_id, $category_id, $group_id, $page, $data_size);

        $response_status = SUCCESS;
        $response        = [
            'size' => $result ? $result['total'] : 0,
            'data' => $result ? $result['rows'] : [],
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Sendout Census
     */
    public function get_sendout_census()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id   = $this->payload['branch_id'] ?? null;
        $date        = $this->payload['date'] ?? null;
        $service_id  = $this->payload['service_id'] ?? null;
        $facility_id = $this->payload['facility_id'] ?? null;
        $term        = $this->payload['term'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $page        = $this->payload['page'] ?? null;
        $data_size   = $this->payload['data_size'] ?? null;

        $sendout_census = $this->report->get_sendout_census($branch_id, $date, $service_id, $facility_id, $term, $supplier_id, $page, $data_size) ?: [];
        $size           = count($this->report->get_sendout_census($branch_id, $date, $service_id, $facility_id, $term, $supplier_id, null, null) ?: []);

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $sendout_census,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Inter Branch Census
     */
    public function get_inter_branch_census()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id_from = $this->payload['branch_id_from'] ?? null;
        $branch_id_to   = $this->payload['branch_id_to'] ?? null;
        $tat_date       = $this->payload['tat_date'] ?? null;
        $service_id     = $this->payload['service_id'] ?? null;
        $page           = $this->payload['page'] ?? null;
        $data_size      = $this->payload['data_size'] ?? null;

        $inter_branch_census = $this->report->get_inter_branch_census($branch_id_from, $branch_id_to, $tat_date, $service_id, $page, $data_size) ?: [];
        $size                = count($this->report->get_inter_branch_census($branch_id_from, $branch_id_to, $tat_date, $service_id, null, null) ?: []);

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $inter_branch_census,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Sendout Census
     */
    public function get_service_census()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id  = $this->payload['branch_id'] ?? null;
        $tat_date   = $this->payload['tat_date'] ?? null;
        $service_id = $this->payload['service_id'] ?? null;
        $page       = $this->payload['page'] ?? null;
        $data_size  = $this->payload['data_size'] ?? null;

        // Fetch all for count, then slice for pagination in PHP
        $all  = $this->report->get_service_census($branch_id, $tat_date, $service_id, null, null) ?: [];
        $size = count($all);

        // Apply pagination manually if needed
        if ($page && $data_size) {
            $offset         = ($page - 1) * $data_size;
            $service_census = array_slice($all, $offset, $data_size);
        } else {
            $service_census = $all;
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $service_census,
        ]);
    }

    /**
     * Get Daily Census
     */
    public function get_daily_census()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'] ?? null;
        $date      = $this->payload['date'] ?? null;
        $parent_category_id = $this->payload['parent_category_id'] ?? null;
        $category_id = $this->payload['category_id'] ?? null;
        $group_id = $this->payload['group_id'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to   = $this->payload['date_to'] ?? null;
        $page      = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $daily_census = $this->report->get_daily_census($branch_id, $date, $date_from, $date_to, $parent_category_id, $category_id, $group_id, $page, $data_size) ?: [];
        $size         = count($this->report->get_daily_census($branch_id, $date, $date_from, $date_to, $parent_category_id, $category_id, $group_id, null, null) ?: []);

        $response_status = SUCCESS;
        $response        = [
            'size' => $size,
            'data' => $daily_census,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get MD DAY CENSUS AND SALES UPDATE
     */
    public function get_md_day_census_and_sales_update()
    {

        $date_filter = $this->request->getVar('date_filter');

        $data = $this->report->get_md_day_census_and_sales_update($date_filter) ?: [];

        $response_status = SUCCESS;
        $response        = [
            'data' => $data,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Get Yakap Census month end report
     */
    public function get_yakap_census_month_end_report()
    {
        $as_of_date = $this->payload['as_of_date'] ?? null;
        if ($as_of_date === null || $as_of_date === '') {
            $as_of_date = $this->payload['date_to'] ?? null;
        }
        if ($as_of_date === null || $as_of_date === '') {
            $as_of_date = date('Y-m-d');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $as_of_date)) {
            return $this->_create_response(
                FAIL,
                ['Invalid as_of_date. Use YYYY-MM-DD.']
            );
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;
            $branchConfig = config('Branch');
            if (! isset($branchConfig->branches[$branch_id])) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
                );
            }
        } else {
            $branch_id = null;
        }

        $data = $this->report->get_yakap_census_month_end_report($as_of_date, $branch_id) ?: [];

        $response_status = SUCCESS;
        $response        = [
            'data' => $data,
        ];

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Yakap Labs report — unique-FPE funnel per branch.
     * Filter: as_of (aliases as_of_date, date_to). Default today. Optional branch_id.
     */
    public function yakap_labs_report()
    {
        $as_of = $this->payload['as_of'] ?? null;
        if ($as_of === null || $as_of === '') {
            $as_of = $this->payload['as_of_date'] ?? null;
        }
        if ($as_of === null || $as_of === '') {
            $as_of = $this->payload['date_to'] ?? null;
        }
        if ($as_of === null || $as_of === '') {
            $as_of = date('Y-m-d');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $as_of)) {
            return $this->_create_response(
                FAIL,
                ['Invalid as_of. Use YYYY-MM-DD.']
            );
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            $branch_id    = (int) $branch_id;
            $branchConfig = config('Branch');
            if (! isset($branchConfig->branches[$branch_id])) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
                );
            }
        } else {
            $branch_id = null;
        }

        $data = $this->report->get_yakap_labs_report($as_of, $branch_id);

        return $this->_create_response(SUCCESS, $data, true);
    }

    /**
     * FDA dashboard — paginated dispensed-medication rows from diagnostic branches.
     */
    public function get_fda_dashboard()
    {
        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === '') {
            $branch_id = null;
        }

        if ($branch_id !== null && (! is_scalar($branch_id) || ! preg_match('/^\d+$/', (string) $branch_id) || ! in_array((int) $branch_id, [1, 2, 3, 4, 7], true))) {
            return $this->_create_response(
                FAIL,
                ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
            );
        }

        foreach (['date_from', 'date_to', 'patient_id', 'item_id', 'term', 'pharmacy_doctor_id'] as $field) {
            if (isset($this->payload[$field]) && ! is_scalar($this->payload[$field])) {
                return $this->_create_response(FAIL, ["{$field} must be a scalar value."]);
            }
        }

        $date_from = trim((string) ($this->payload['date_from'] ?? ''));
        $date_to = trim((string) ($this->payload['date_to'] ?? ''));
        if (($date_from === '') !== ($date_to === '')) {
            return $this->_create_response(FAIL, ['Both date_from and date_to are required when filtering by date.']);
        }
        foreach ([$date_from, $date_to] as $date) {
            if ($date !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                return $this->_create_response(FAIL, ['Dates must be valid and use YYYY-MM-DD format.']);
            }
        }

        $patient_id = trim((string) ($this->payload['patient_id'] ?? ''));
        $item_id = trim((string) ($this->payload['item_id'] ?? ''));
        foreach (['patient_id' => $patient_id, 'item_id' => $item_id] as $field => $value) {
            if ($value !== '' && ! preg_match('/^\d+$/', $value)) {
                return $this->_create_response(FAIL, ["{$field} must be a single numeric ID."]);
            }
        }
        $term = trim((string) ($this->payload['term'] ?? ''));
        $pharmacy_doctor_id = trim((string) ($this->payload['pharmacy_doctor_id'] ?? ''));
        if ($pharmacy_doctor_id !== '') {
            if (filter_var($pharmacy_doctor_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return $this->_create_response(FAIL, ['pharmacy_doctor_id must be a positive integer.']);
            }
            if ($branch_id === null) {
                return $this->_create_response(FAIL, ['branch_id is required when filtering by pharmacy_doctor_id.']);
            }
        }

        [$page, $data_size] = list_page_params($this->payload);
        $report = $this->report->get_fda_dashboard(
            $branch_id === null ? null : (int) $branch_id,
            $page,
            $data_size,
            $date_from === '' ? null : $date_from,
            $date_to === '' ? null : $date_to,
            $patient_id === '' ? null : (int) $patient_id,
            $item_id === '' ? null : (int) $item_id,
            $term === '' ? null : $term,
            $pharmacy_doctor_id === '' ? null : (int) $pharmacy_doctor_id
        );

        return $this->_create_response(SUCCESS, $report, true);
    }

    /**
     * Daily FDA sales grouped by branch, dispenser and item.
     */
    public function get_fda_sales()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        $patient_id = $this->payload['patient_id'] ?? null;
        $item_id = $this->payload['item_id'] ?? null;
        foreach (['branch_id', 'patient_id', 'item_id', 'page', 'data_size', 'page_size'] as $field) {
            $value = $this->payload[$field] ?? null;
            if ($value !== null && $value !== '' && ((!is_string($value) && !is_int($value))
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
                return $this->_create_response(FAIL, [$field.' must be a positive integer.']);
            }
        }
        if ($branch_id !== null && $branch_id !== '' && !in_array((int) $branch_id, [1, 2, 3, 4, 7], true)) {
            return $this->_create_response(FAIL, ['Invalid branch_id. Allowed values: 1, 2, 3, 4 and 7.']);
        }
        foreach (['date_from', 'date_to', 'term', 'brand', 'generic', 'dosage', 'form', 'kind'] as $field) {
            if (isset($this->payload[$field]) && !is_string($this->payload[$field])) {
                return $this->_create_response(FAIL, [$field.' must be a string or null.']);
            }
        }

        $date_from = trim($this->payload['date_from'] ?? '');
        $date_to = trim($this->payload['date_to'] ?? '');
        if (($date_from === '') !== ($date_to === '')) {
            return $this->_create_response(FAIL, ['Both date_from and date_to are required when filtering by date.']);
        }
        foreach ([$date_from, $date_to] as $date) {
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                return $this->_create_response(FAIL, ['Dates must be valid and use YYYY-MM-DD format.']);
            }
        }
        if ($date_from !== '' && $date_from > $date_to) {
            return $this->_create_response(FAIL, ['date_from must not be later than date_to.']);
        }

        $term = trim($this->payload['term'] ?? '');
        $brand = trim($this->payload['brand'] ?? '');
        $generic = trim($this->payload['generic'] ?? '');
        $dosage = trim($this->payload['dosage'] ?? '');
        $form = trim($this->payload['form'] ?? '');
        $kind = trim($this->payload['kind'] ?? '');
        [$page, $data_size] = list_page_params($this->payload);
        $response_status = SUCCESS;
        $response = $this->report->get_fda_sales(
            $branch_id === null || $branch_id === '' ? null : (int) $branch_id,
            $page,
            $data_size,
            $date_from === '' ? null : $date_from,
            $date_to === '' ? null : $date_to,
            $patient_id === null || $patient_id === '' ? null : (int) $patient_id,
            $item_id === null || $item_id === '' ? null : (int) $item_id,
            $term === '' ? null : $term,
            $brand === '' ? null : $brand,
            $generic === '' ? null : $generic,
            $dosage === '' ? null : $dosage,
            $form === '' ? null : $form,
            $kind === '' ? null : $kind
        );

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Active pharmacy doctors from available diagnostic branches.
     */
    public function get_pharmacy_doctor()
    {
        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === '') {
            $branch_id = null;
        }

        if ($branch_id !== null && (! is_scalar($branch_id) || ! preg_match('/^\d+$/', (string) $branch_id) || ! in_array((int) $branch_id, [1, 2, 3, 4, 7], true))) {
            return $this->_create_response(
                FAIL,
                ['Invalid branch_id. Allowed values: 1 (mobile), 2 (oakridge), 3 (mactan), 4 (consolacion), 7 (mantlewood).']
            );
        }

        $report = $this->report->get_pharmacy_doctor($branch_id === null ? null : (int) $branch_id);

        return $this->_create_response(SUCCESS, $report, true);
    }

    /**
     * Get FPE Dashboard (one row per branch).
     */
    public function get_fpe_dashboard()
    {
        $dates = $this->_fpe_resolve_date_range();
        if ($dates instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $dates;
        }
        [$date_from, $date_to] = $dates;

        $data = $this->report->get_fpe_dashboard($date_from, $date_to);

        return $this->_create_response(SUCCESS, $data, true);
    }

    /**
     * Get FPE Dashboard Tracker (one row per branch for the date range).
     */
    public function get_fpe_dashboard_tracker()
    {
        $dates = $this->_fpe_resolve_date_range();
        if ($dates instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $dates;
        }
        [$date_from, $date_to] = $dates;

        $data = $this->report->get_fpe_dashboard_tracker($date_from, $date_to);

        return $this->_create_response(SUCCESS, $data, true);
    }

    /**
     * FPE date range: date_from + date_to (same year).
     * Default date_to = today, date_from = Jan 1 of that year.
     * as_of_date / fpe_to alias date_to.
     *
     * @return array|\CodeIgniter\HTTP\ResponseInterface
     */
    private function _fpe_resolve_date_range()
    {
        $date_to = $this->payload['as_of_date']
            ?? $this->payload['date_to']
            ?? $this->payload['fpe_to']
            ?? null;
        if ($date_to === null || $date_to === '') {
            $date_to = date('Y-m-d');
        }

        $date_from = $this->payload['date_from'] ?? null;
        if ($date_from === null || $date_from === '') {
            $date_from = date('Y-01-01', strtotime((string) $date_to));
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_from)) {
            return $this->_create_response(FAIL, ['Invalid date_from. Use YYYY-MM-DD.']);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)) {
            return $this->_create_response(FAIL, ['Invalid date_to. Use YYYY-MM-DD.']);
        }

        $from_ts = strtotime((string) $date_from);
        $to_ts   = strtotime((string) $date_to);
        if (! $from_ts || ! $to_ts) {
            return $this->_create_response(FAIL, ['Invalid date_from or date_to.']);
        }

        $date_from = date('Y-m-d', $from_ts);
        $date_to   = date('Y-m-d', $to_ts);

        if ($date_from > $date_to) {
            return $this->_create_response(FAIL, ['date_from must be on or before date_to.']);
        }

        if (date('Y', $from_ts) !== date('Y', $to_ts)) {
            return $this->_create_response(FAIL, ['Date from and date to must be in the same year.']);
        }

        return [$date_from, $date_to];
    }

    /**
     * get uip lost
     */
    public function uip_lost()
    {
        [$date_from, $date_to] = $this->_normalizeUipDates(
            $this->request->getVar('date_from'),
            $this->request->getVar('date_to')
        );
        $account_type     = $this->request->getVar('account_type') ?: null;     // hmo or company (is_hmo)
        $transaction_type = $this->request->getVar('transaction_type') ?: null; // WALK-IN or MOBILE

        $rows = $this->report_clinic->get_uip_lost($date_from, $date_to, $transaction_type, $account_type);

        if (empty($rows)) {
            return $this->_create_response(FAIL_NOT_FOUND, ['reports not found.']);
        }

        // Get unique account_ids para i-fetch ang company info sa master db
        $account_ids = array_unique(array_filter(array_column($rows, 'account_id')));

        $companies = [];
        if (! empty($account_ids)) {

            $ids_in = implode(',', array_map('intval', $account_ids));

            $wherein = <<<EOT
is_deleted = 0
AND id IN ({$ids_in})
EOT;

            if ($account_type !== null) {
                $wherein .= <<<EOT

AND is_hmo = {$account_type}
EOT;
            }

            $company_rows = $this->company->select('id, company_name, is_hmo', $wherein);

            foreach ($company_rows as $c) {
                $companies[$c['id']] = $c;
            }
        }

        // Merge ug i-format final result
        $data         = [];
        $total_count  = 0;
        $total_amount = 0;

        foreach ($rows as $row) {
            $company = $companies[$row['account_id']] ?? null;

            // skip kung gi-filter ang account_type pero wala ni-match ang company
            if ($account_type !== null && ! $company) {
                continue;
            }

            $account_name = $company['company_name'] ?? 'N/A';

            $data[] = [
                'transaction_type'       => $row['transaction_type'],
                'charge_to_account_name' => $account_name,
                'account_name'           => $account_name,
                'patient_count'          => (int) $row['patient_count'],
                'sum_of_total_amount'    => (float) $row['sum_of_total_amount'],
            ];

            $total_count += (int) $row['patient_count'];
            $total_amount += (float) $row['sum_of_total_amount'];
        }

        $data[] = [
            'transaction_type'       => 'Total',
            'charge_to_account_name' => '',
            'account_name'           => '',
            'patient_count'          => $total_count,
            'sum_of_total_amount'    => $total_amount,
        ];

        return $this->_create_response(SUCCESS, $data);
    }

    /**
     * UIP HMO Corporate Report
     */
    public function uip_hmo_corporate()
    {
        $date_from = $this->request->getVar('date_from') ?: null;
        $date_to   = $this->request->getVar('date_to') ?: null;
        $age_group = $this->request->getVar('age_group') ?: null;

        $bundle = $this->report_clinic->get_uip_hmo_bundle($date_from, $date_to, $age_group);

        $patient_category_rows = $bundle['patient_category'];
        $account_type_rows     = $bundle['account_type'];
        $account_name_rows     = $bundle['account_name'];

        if (empty($patient_category_rows) && empty($account_type_rows) && empty($account_name_rows)) {
            return $this->_create_response(FAIL_NOT_FOUND, ['reports not found.']);
        }

        $data = [
            'patient_category' => $this->pivot_year_breakdown(
                $patient_category_rows,
                'patient_category',
                ['Acquired', 'Engaged', 'Lost', 'Retained', 'Returnee']
            ),
            'account_type'     => $this->pivot_year_breakdown(
                $account_type_rows,
                'account_type',
                ['CORPORATE', 'HMO', 'UNKNOWN']
            ),
            'account_name'     => $account_name_rows,
        ];

        return $this->_create_response(SUCCESS, $data);
    }

    /**
     * UIP PPL Report (summary tables only — detail via get_ppl_detail)
     */
    public function get_ppl_membership_corporate()
    {
        $date_from     = $this->request->getVar('date_from') ?: null;
        $date_to       = $this->request->getVar('date_to') ?: null;
        $age_group     = $this->request->getVar('age_group') ?: null;
        $charge_to     = $this->request->getVar('charge_to') ?: null;
        $membership_id = $this->request->getVar('membership_id') ?: null;

        $summary         = $this->report_clinic->get_ppl_summary_bundle($date_from, $date_to, $age_group, $charge_to, $membership_id);
        $age_group_rows  = $summary['age_group'];
        $category_rows   = $summary['patient_category'];
        $membership_type_data = $summary['membership_type'];

        // Table 2 - age group (backfill zero)
        $age_defaults   = ['0-19', '20-39', '40-59', '60 & UP'];
        $age_map        = array_column($age_group_rows, null, 'age_group');
        $age_group_data = [];
        foreach ($age_defaults as $ag) {
            $age_group_data[] = [
                'age_group'     => $ag,
                'patient_count' => (int) ($age_map[$ag]['patient_count'] ?? 0),
                'female'        => (int) ($age_map[$ag]['female'] ?? 0),
                'male'          => (int) ($age_map[$ag]['male'] ?? 0),
            ];
        }

        // Table 3 - patient category (backfill zero)
        $cat_defaults  = ['Acquired', 'Engaged', 'Lost', 'Retained', 'Returnee'];
        $cat_map       = array_column($category_rows, null, 'patient_category');
        $category_data = [];
        foreach ($cat_defaults as $cat) {
            $category_data[] = [
                'patient_category' => $cat,
                'patient_count'    => (int) ($cat_map[$cat]['patient_count'] ?? 0),
                'female'           => (int) ($cat_map[$cat]['female'] ?? 0),
                'male'             => (int) ($cat_map[$cat]['male'] ?? 0),
            ];
        }

        $data = [
            'age_group'        => $age_group_data,
            'patient_category' => $category_data,
            'membership_type'  => $membership_type_data,
        ];

        return $this->_create_response(SUCCESS, $data);
    }

    /**
     * UIP PPL Detail table (paginated — separate from summary to avoid large JSON payloads)
     */
    public function get_ppl_detail()
    {
        $date_from     = $this->request->getVar('date_from') ?: null;
        $date_to       = $this->request->getVar('date_to') ?: null;
        $age_group     = $this->request->getVar('age_group') ?: null;
        $charge_to     = $this->request->getVar('charge_to') ?: null;
        $membership_id = $this->request->getVar('membership_id') ?: null;
        $page      = $this->request->getVar('page') ?: 1;
        $data_size = $this->request->getVar('data_size');
        $data_size = ($data_size === null || $data_size === '') ? 100 : $data_size;

        $result = $this->report_clinic->get_ppl_detail($date_from, $date_to, $age_group, $charge_to, $membership_id, $page, $data_size);

        $detail = [];
        foreach ($result['rows'] as $row) {
            $detail[] = [
                'transaction_date'        => $row['transaction_date'],
                'patient_name'            => $row['patient_name'],
                'gender'                  => $row['gender'],
                'package_name'            => $row['package_name'],
                'account_name'            => $row['account_name'],
                'charged_to_account_name' => $row['charged_to_account_name'],
                'total_sales'             => (float) $row['total_sales'],
            ];
        }

        return $this->_respond_success([
            'size'      => $result['size'],
            'page'      => $result['page'],
            'data_size' => $result['data_size'],
            'detail'    => $detail,
        ]);
    }

    /**
     * Pivot year breakdown
     */
    private function pivot_year_breakdown($rows, $group_field, $default_keys = [])
    {
        $pivoted = [];
        $years   = [];

        // initialize tanan default keys with zero, bisan wala silay data
        foreach ($default_keys as $key) {
            $pivoted[$key] = [
                $group_field  => $key,
                'uip_total'   => 0,
                'sales_total' => 0,
                'by_year'     => [],
            ];
        }

        foreach ($rows as $row) {
            $key  = $row[$group_field];
            $year = $row['transaction_year'];

            if ($year) {
                $years[$year] = true;
            }

            if (! isset($pivoted[$key])) {
                $pivoted[$key] = [
                    $group_field  => $key,
                    'uip_total'   => 0,
                    'sales_total' => 0,
                    'by_year'     => [],
                ];
            }

            $pivoted[$key]['uip_total'] += (int) $row['uip_count'];
            $pivoted[$key]['sales_total'] += (float) $row['sales'];

            if ($year) {
                $pivoted[$key]['by_year'][$year] = [
                    'uip'   => (int) $row['uip_count'],
                    'sales' => (float) $row['sales'],
                ];
            }
        }

        ksort($years);
        $year_list = array_keys($years);

        $final = [];
        foreach ($pivoted as $key => $data) {
            $entry = [
                $group_field  => $key,
                'uip_total'   => $data['uip_total'],
                'sales_total' => $data['sales_total'],
            ];

            foreach ($year_list as $y) {
                $entry["uip_{$y}"]   = $data['by_year'][$y]['uip'] ?? 0;
                $entry["sales_{$y}"] = $data['by_year'][$y]['sales'] ?? 0;
            }

            $final[] = $entry;
        }

        return ['data' => $final, 'years' => $year_list];
    }

    /**
     * Keep UIP dates optional but never unbounded.
     * Both missing → current month 1st through today.
     * Only date_from → date_to = today.
     * Only date_to → date_from = first day of that month.
     *
     * @return array{0: string, 1: string}
     */
    private function _normalizeUipDates($date_from, $date_to): array
    {
        $from = is_string($date_from) ? trim($date_from) : $date_from;
        $to   = is_string($date_to) ? trim($date_to) : $date_to;

        if ($from === '' || $from === null) {
            $from = null;
        }
        if ($to === '' || $to === null) {
            $to = null;
        }

        $today = date('Y-m-d');

        if ($from === null && $to === null) {
            return [$today, $today];
        }else {
            $fromTs = strtotime((string) $from);
            $toTs   = strtotime((string) $to);
            $from   = $fromTs ? date('Y-m-d', $fromTs) : date('Y-m-01');
            $to     = $toTs ? date('Y-m-d', $toTs) : $today;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Consolidated balance sheet for date_from through date_to, with Jan-Dec months of that year.
     * tab=per_account_type — nested sheet; optional branch_id (omit to sum all clinics).
     * tab=breakdown — paged posted GL rows for one account_type_id.
     */
    public function get_balance_sheet()
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

        if (date('Y', $from_ts) !== date('Y', $to_ts)) {
            return $this->_create_response(FAIL, ['Date from and date to must be in the same year.']);
        }

        $year = (int) date('Y', $from_ts);
        $month_keys = $this->_balance_sheet_month_keys($year);

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
            return $this->_balance_sheet_breakdown($date_from, $date_to, $branch_id, $branch_ids);
        }

        $coa_rows = $this->account_type->get_balance_sheet_accounts() ?: [];
        $coa_by_id = [];
        foreach ($coa_rows as $row) {
            $coa_by_id[(int) $row['id']] = $row;
        }

        $sheet = $this->_build_balance_sheet_sheet(
            $date_from,
            $date_to,
            $branch_id,
            $branch_ids,
            $year,
            $month_keys,
            $coa_rows,
            $coa_by_id
        );

        $term = $this->_report_term();
        if ($term !== null) {
            $this->_filter_report_sheet_accounts($sheet, static function ($row) use ($term) {
                return stripos((string) ($row['code'] ?? ''), $term) !== false;
            });
        }

        return $this->_create_response(SUCCESS, ['tab' => 'per_account_type'] + $sheet);
    }

    /**
     * Paged posted GL rows for one account family.
     *
     * @param list<int> $branch_ids
     */
    private function _balance_sheet_breakdown(
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

        $sql_page = count($branch_ids) === 1;
        $entries = [];
        $size = 0;
        foreach ($branch_ids as $id) {
            if (! $this->resolve_branch_models((int) $id)) {
                continue;
            }
            $result = $this->general_ledger->get_posted_account_entries(
                $date_from,
                $date_to,
                $family_ids,
                $sql_page ? $page : null,
                $sql_page ? $data_size : null
            );
            $size += (int) ($result['size'] ?? 0);
            foreach ($result['rows'] as $row) {
                $entries[] = [
                    'id'              => (int) ($row['id'] ?? 0),
                    'branch_id'       => (int) ($row['branch_id'] ?? $id),
                    'source'          => $row['source'] ?? null,
                    'source_id'       => isset($row['source_id']) ? (int) $row['source_id'] : null,
                    'date'            => $row['date'] ?? null,
                    'debit'           => (float) ($row['debit'] ?? 0),
                    'credit'          => (float) ($row['credit'] ?? 0),
                    'remark'          => $row['remark'] ?? null,
                    'account_type_id' => (int) ($row['account_type_id'] ?? 0),
                    'account_code'    => $row['account_code'] ?? null,
                    'account_name'    => $row['account_name'] ?? null,
                ];
            }
        }

        if (! $sql_page) {
            usort($entries, static function ($a, $b) {
                $date_cmp = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
                if ($date_cmp !== 0) {
                    return $date_cmp;
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });
            $offset = ($page - 1) * $data_size;
            $entries = array_slice($entries, $offset, $data_size);
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
            'entries'         => $entries,
        ]);
    }

    /**
     * Nested Balance Sheet.xlsx (months + account lines).
     *
     * @param list<int> $branch_ids
     * @param array<int, string> $month_keys
     */
    private function _build_balance_sheet_sheet(
        string $date_from,
        string $date_to,
        $branch_id,
        array $branch_ids,
        int $year,
        array $month_keys,
        array $coa_rows,
        array $coa_by_id
    ): array {
        $raw_keys = $this->_balance_sheet_raw_keys($month_keys, $year);
        $value_keys = array_merge($month_keys, $raw_keys);
        $opening_raw_key = $this->_balance_sheet_opening_raw_key($year);
        $sheet = $this->_empty_balance_sheet($date_from, $date_to, $branch_id, $month_keys, $year);

        foreach ($coa_rows as $row) {
            $parent_id = $row['parent_id'] ?? null;
            $financial_statement = strtolower(trim((string) ($row['financial_statement'] ?? '')));
            if ($parent_id !== null && $parent_id !== '') {
                continue;
            }
            if ($financial_statement !== 'balance_sheet') {
                continue;
            }

            $line_key = $this->_map_balance_sheet_line($row);
            $seed = $this->_report_account_row(
                (int) $row['id'],
                $row['code'] ?? null,
                $row['name'] ?? null,
                0.0,
                $month_keys,
                false,
                null,
                null,
                null,
                $raw_keys
            );
            $this->_add_balance_sheet_account($sheet, $line_key, $seed, $value_keys, false);
        }

        foreach ($branch_ids as $id) {
            if (! $this->resolve_branch_models((int) $id)) {
                continue;
            }
            foreach ($this->general_ledger->get_posted_monthly_totals(sprintf('%04d-01-01', $year), $date_to) as $row) {
                $account_type_id = (int) ($row['account_type_id'] ?? 0);
                if ($account_type_id <= 0) {
                    continue;
                }
                if ((int) ($row['yr'] ?? 0) !== $year) {
                    continue;
                }
                $month_key = $this->_year_month_key($year, (int) ($row['mo'] ?? 0));
                if ($month_key === null) {
                    continue;
                }
                $this->_attach_balance_sheet_monthly_total(
                    $sheet,
                    $coa_by_id,
                    $coa_by_id[$account_type_id] ?? null,
                    $account_type_id,
                    (float) ($row['debit'] ?? 0),
                    (float) ($row['credit'] ?? 0),
                    $month_key,
                    $month_keys,
                    $raw_keys
                );
            }
            foreach ($this->general_ledger->get_posted_monthly_totals('1970-01-01', sprintf('%04d-12-31', $year - 1)) as $row) {
                $account_type_id = (int) ($row['account_type_id'] ?? 0);
                if ($account_type_id <= 0) {
                    continue;
                }
                $this->_attach_balance_sheet_opening_total(
                    $sheet,
                    $coa_by_id,
                    $coa_by_id[$account_type_id] ?? null,
                    $account_type_id,
                    (float) ($row['debit'] ?? 0),
                    (float) ($row['credit'] ?? 0),
                    $opening_raw_key,
                    $month_keys,
                    $raw_keys
                );
            }
        }

        $this->_snapshot_balance_sheet_raw_months($sheet, $month_keys);
        $this->_accumulate_balance_sheet_months(
            $sheet,
            $month_keys,
            (int) date('n', strtotime($date_to)),
            $opening_raw_key
        );
        $this->_finalize_balance_sheet_totals($sheet, $month_keys, $raw_keys);

        return $sheet;
    }

    /**
     * P&L OAK for date_from through date_to, with Jan-Dec months of that year.
     * tab=per_account_type — nested sheet; optional branch_id (omit to sum all clinics).
     * tab=breakdown — paged posted GL rows for one account_type_id.
     * tab=branch_summary — per-clinic columns (branch_id ignored).
     */
    public function get_profit_and_loss()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $tab = strtolower(trim((string) ($this->payload['tab'] ?? '')));
        if (! in_array($tab, ['per_account_type', 'breakdown', 'branch_summary'], true)) {
            return $this->_create_response(FAIL, [
                'tab is required. Valid values are: per_account_type, breakdown, branch_summary.',
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

        if (date('Y', $from_ts) !== date('Y', $to_ts)) {
            return $this->_create_response(FAIL, ['Date from and date to must be in the same year.']);
        }

        $year = (int) date('Y', $from_ts);
        $month_keys = $this->_balance_sheet_month_keys($year);

        $db_map = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            0 => 'default',
        ];

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($tab === 'branch_summary') {
            $branch_id = null;
            $branch_ids = array_keys($db_map);
        } elseif ($branch_id !== null && $branch_id !== '') {
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
            return $this->_profit_and_loss_breakdown($date_from, $date_to, $branch_id, $branch_ids);
        }

        $coa_rows = $this->account_type->get_profit_and_loss_accounts() ?: [];
        $coa_by_id = [];
        foreach ($coa_rows as $row) {
            $coa_by_id[(int) $row['id']] = $row;
        }

        if ($tab === 'branch_summary') {
            $summary = $this->_build_profit_and_loss_per_branch_summary(
                $date_from,
                $date_to,
                $year,
                $month_keys,
                $coa_rows,
                $coa_by_id
            );

            return $this->_create_response(SUCCESS, ['tab' => 'branch_summary'] + $summary);
        }

        $sheet = $this->_build_profit_and_loss_sheet(
            $date_from,
            $date_to,
            $branch_id,
            $branch_ids,
            $year,
            $month_keys,
            $coa_rows,
            $coa_by_id
        );

        $term = $this->_report_term();
        if ($term !== null) {
            $this->_filter_report_sheet_accounts($sheet, static function ($row) use ($term, $coa_by_id) {
                $id = (int) ($row['id'] ?? 0);
                $group_code = $row['group_code'] ?? ($coa_by_id[$id]['group_code'] ?? '');

                return stripos((string) $group_code, $term) !== false;
            });
        }

        return $this->_create_response(SUCCESS, ['tab' => 'per_account_type'] + $sheet);
    }

    /**
     * P&L sheet for a single branch and date range, used by BIR 1702 generation.
     */
    public function build_profit_and_loss_for_bir(string $date_from, string $date_to, int $branch_id): array
    {
        $from_ts = strtotime($date_from);
        $to_ts = strtotime($date_to);
        if (! $from_ts || ! $to_ts) {
            throw new \InvalidArgumentException('Invalid date_from or date_to.');
        }

        $date_from = date('Y-m-d', $from_ts);
        $date_to = date('Y-m-d', $to_ts);
        $year = (int) date('Y', $from_ts);
        $month_keys = $this->_balance_sheet_month_keys($year);

        $db_map = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            0 => 'default',
        ];
        if (! isset($db_map[$branch_id])) {
            throw new \InvalidArgumentException('Invalid Branch ID.');
        }

        $coa_rows = $this->account_type->get_profit_and_loss_accounts() ?: [];
        $coa_by_id = [];
        foreach ($coa_rows as $row) {
            $coa_by_id[(int) $row['id']] = $row;
        }

        return $this->_build_profit_and_loss_sheet(
            $date_from,
            $date_to,
            $branch_id,
            [$branch_id],
            $year,
            $month_keys,
            $coa_rows,
            $coa_by_id
        );
    }

    /**
     * GET /reports/get_1702_rt — year (month defaults to 12) or closed_month_id for December. Optional branch_id (default 2).
     */
    public function get_1702_rt()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        return $this->_get_1702_return('1702_rt');
    }

    /**
     * GET /reports/get_1702q — year+quarter (or month 3/6/9/12) or closed_month_id. Optional branch_id (default 2).
     */
    public function get_1702q()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        return $this->_get_1702_return('1702q');
    }

    protected function _get_1702_return(string $type)
    {
        $generator = new \App\Libraries\Bir_return_generator();
        $bir_return_file = new \App\Models\Bir_return_file(db_connect('master'));
        $closed_month_model = new \App\Models\Closed_month(db_connect('master'));
        $branch_master = new Branch_master(db_connect('master'));

        $branch_id = $this->payload['branch_id'] ?? null;
        $branch_id = ($branch_id === null || $branch_id === '')
            ? \App\Libraries\Bir_return_generator::DEFAULT_BRANCH_ID
            : (int) $branch_id;

        $db_map = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 7 => 7];
        if (! isset($db_map[$branch_id])) {
            return $this->_create_response(FAIL, ['Invalid Branch ID.']);
        }

        $closed_month_id = $this->payload['closed_month_id'] ?? null;
        $year = $this->payload['year'] ?? null;
        $month = $this->payload['month'] ?? null;
        $quarter = $this->payload['quarter'] ?? null;

        if ($closed_month_id !== null && $closed_month_id !== '') {
            $closed = $closed_month_model->select('', ['id' => $closed_month_id, 'is_deleted' => 0], 1);
            if (! $closed) {
                return $this->_create_response(FAIL_NOT_FOUND, ['closed_month is not found.']);
            }
            $year = (int) $closed->year;
            $month = (int) $closed->month;
            $closed_month_id = (int) $closed->id;
            $quarter = \App\Libraries\Bir_return_generator::quarter_from_month($month);
            if ($type === '1702_rt' && ! \App\Libraries\Bir_return_generator::is_rt_month($month)) {
                return $this->_create_response(FAIL, ['1702-RT is only generated for December (month 12).']);
            }
            if ($type === '1702q' && ! \App\Libraries\Bir_return_generator::is_quarter_month($month)) {
                return $this->_create_response(FAIL, ['1702Q is only generated for months 3, 6, 9, and 12.']);
            }
        } else {
            $closed_month_id = null;
            if ($year === null || $year === '') {
                return $this->_create_response(FAIL, ['year is required.']);
            }
            $year = (int) $year;
            if ($type === '1702_rt') {
                if ($month === null || $month === '') {
                    $month = \App\Libraries\Bir_return_generator::RT_MONTH;
                } else {
                    $month = (int) $month;
                    if (! \App\Libraries\Bir_return_generator::is_rt_month($month)) {
                        return $this->_create_response(FAIL, ['1702-RT is only generated for December (month 12).']);
                    }
                }
            } else {
                if (($quarter === null || $quarter === '') && ($month === null || $month === '')) {
                    return $this->_create_response(FAIL, ['quarter or month is required.']);
                }
                if ($quarter !== null && $quarter !== '') {
                    $quarter = (int) $quarter;
                } else {
                    $month = (int) $month;
                    if (! \App\Libraries\Bir_return_generator::is_quarter_month($month)) {
                        return $this->_create_response(FAIL, ['1702Q is only generated for months 3, 6, 9, and 12.']);
                    }
                    $quarter = \App\Libraries\Bir_return_generator::quarter_from_month($month);
                }
                if ($quarter < 1 || $quarter > 4) {
                    return $this->_create_response(FAIL, ['Quarter must be between 1 and 4.']);
                }
                $month = ($month === null || $month === '') ? ($quarter * 3) : (int) $month;
            }
        }

        $filters = [
            'branch_id' => $branch_id,
            'year' => $year,
        ];
        if ($type === '1702_rt') {
            $month = \App\Libraries\Bir_return_generator::RT_MONTH;
            $filters['month'] = $month;
        } else {
            $filters['quarter'] = $quarter;
        }

        [$date_from, $date_to] = \App\Libraries\Bir_return_generator::canonical_range(
            $type,
            $year,
            $quarter,
            $month
        );

        $row = $bir_return_file->find_return($type, $filters);
        if (! $row) {
            $id = $generator->insert_pending([
                'closed_month_id' => $closed_month_id,
                'type' => $type,
                'branch_id' => $branch_id,
                'year' => $year,
                'month' => $month,
                'quarter' => $type === '1702q' ? $quarter : null,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'added_by' => $this->payload['requester'] ?? null,
            ]);
            $generator->spawn((int) $id);
            $row = [
                'status' => 'pending',
                'type' => $type,
                'year' => $year,
                'month' => $month,
                'quarter' => $quarter,
                'branch_id' => $branch_id,
                'date_from' => $date_from,
                'date_to' => $date_to,
                'file_url' => null,
                'error_message' => null,
            ];
        }

        if (is_object($row)) {
            $row = (array) $row;
        }

        $stored_from = substr((string) ($row['date_from'] ?? ''), 0, 10);
        $stored_to = substr((string) ($row['date_to'] ?? ''), 0, 10);
        if ($stored_from !== $date_from || $stored_to !== $date_to) {
            $row_id = (int) ($row['id'] ?? 0);
            if ($row_id > 0) {
                $bir_return_file->update(['id' => $row_id], [
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                    'status' => 'pending',
                    'file_path' => null,
                    'file_url' => null,
                    'error_message' => null,
                    'updated_on' => date('Y-m-d H:i:s'),
                ]);
                $generator->spawn($row_id);
            }
            $row['status'] = 'pending';
            $row['date_from'] = $date_from;
            $row['date_to'] = $date_to;
            $row['file_url'] = null;
            $row['error_message'] = null;
        }

        $branch = $branch_master->select('', ['id' => $branch_id, 'is_deleted' => 0], 1);
        $status = $row['status'] ?? 'pending';

        if ($status === 'ready') {
            $streamed = $this->_stream_bir_pdf($row);
            if ($streamed !== null) {
                return $streamed;
            }

            return $this->_create_response(FAIL, ['Generated PDF is not available.']);
        }

        $payload = [
            'status' => $status,
            'type' => $row['type'] ?? $type,
            'year' => (int) ($row['year'] ?? $year),
            'branch_id' => (int) ($row['branch_id'] ?? $branch_id),
            'date_from' => $row['date_from'] ?? $date_from,
            'date_to' => $row['date_to'] ?? $date_to,
            'tin' => $branch->tin ?? null,
            'file_url' => $row['file_url'] ?? null,
            'error_message' => $row['error_message'] ?? null,
            'message' => $status === 'failed'
                ? 'BIR PDF generation failed.'
                : 'BIR PDF generation is running in the background.',
        ];
        if ($type === '1702q') {
            $payload['quarter'] = (int) ($row['quarter'] ?? $quarter);
        } else {
            $payload['month'] = (int) ($row['month'] ?? $month);
        }

        return $this->_create_response(SUCCESS, $payload);
    }

    protected function _stream_bir_pdf(array $row)
    {
        $body = null;
        $path = $row['file_path'] ?? null;
        if ($path && is_file($path)) {
            $body = file_get_contents($path);
        }

        if (($body === null || $body === false) && ! empty($row['file_url'])) {
            try {
                $fetched = Files::fetchFileContent($row['file_url']);
                $body = $fetched['content'] ?? null;
            } catch (\Throwable $e) {
                log_message('error', 'BIR PDF fetch failed: ' . $e->getMessage());
            }
        }

        if ($body === null || $body === false) {
            return null;
        }

        $filename = ($row['type'] ?? '1702') . '.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setHeader('X-File-Url', (string) ($row['file_url'] ?? ''))
            ->setBody($body);
    }

    /**
     * Paged posted GL rows for one P&L account family.
     *
     * @param list<int> $branch_ids
     */
    private function _profit_and_loss_breakdown(
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

        $sql_page = count($branch_ids) === 1;
        $entries = [];
        $size = 0;
        $total_debit = 0.0;
        $total_credit = 0.0;
        foreach ($branch_ids as $id) {
            if (! $this->resolve_branch_models((int) $id)) {
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
                $sql_page ? $page : null,
                $sql_page ? $data_size : null
            );
            $size += (int) ($result['size'] ?? 0);
            foreach ($result['rows'] as $row) {
                $entries[] = [
                    'id'              => (int) ($row['id'] ?? 0),
                    'branch_id'       => (int) ($row['branch_id'] ?? $id),
                    'source'          => $row['source'] ?? null,
                    'source_id'       => isset($row['source_id']) ? (int) $row['source_id'] : null,
                    'date'            => $row['date'] ?? null,
                    'debit'           => (float) ($row['debit'] ?? 0),
                    'credit'          => (float) ($row['credit'] ?? 0),
                    'remark'          => $row['remark'] ?? null,
                    'account_type_id' => (int) ($row['account_type_id'] ?? 0),
                    'account_code'    => $row['account_code'] ?? null,
                    'account_name'    => $row['account_name'] ?? null,
                ];
            }
        }

        if (! $sql_page) {
            usort($entries, static function ($a, $b) {
                $date_cmp = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
                if ($date_cmp !== 0) {
                    return $date_cmp;
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });
            $entries = $this->general_ledger->with_running_balance($entries);
            $offset = ($page - 1) * $data_size;
            $entries = array_slice($entries, $offset, $data_size);
        } else {
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
     * Detailed P&L OAK sheet (months + optional account lines).
     *
     * @param list<int> $branch_ids
     * @param array<int, string> $month_keys
     */
    private function _build_profit_and_loss_sheet(
        string $date_from,
        string $date_to,
        $branch_id,
        array $branch_ids,
        int $year,
        array $month_keys,
        array $coa_rows,
        array $coa_by_id,
        bool $merge_accounts = true
    ): array {
        $sheet = $this->_empty_profit_and_loss($date_from, $date_to, $branch_id, $month_keys);

        if ($merge_accounts) {
            foreach ($coa_rows as $row) {
                $parent_id = $row['parent_id'] ?? null;
                if ($parent_id !== null && $parent_id !== '') {
                    continue;
                }

                $line_key = $this->_map_profit_and_loss_line($row);
                $seed = $this->_with_report_group_code(
                    $this->_report_account_row(
                        (int) $row['id'],
                        $row['code'] ?? null,
                        $row['name'] ?? null,
                        0.0,
                        $month_keys
                    ),
                    $row['group_code'] ?? null
                );
                $this->_add_profit_and_loss_account($sheet, $line_key, $seed, $month_keys, false);
            }
        }

        foreach ($branch_ids as $id) {
            if (! $this->resolve_branch_models((int) $id)) {
                continue;
            }
            foreach ($this->general_ledger->get_posted_monthly_totals($date_from, $date_to) as $row) {
                $account_type_id = (int) ($row['account_type_id'] ?? 0);
                if ($account_type_id <= 0 || ! isset($coa_by_id[$account_type_id])) {
                    continue;
                }
                if ((int) ($row['yr'] ?? 0) !== $year) {
                    continue;
                }
                $month_key = $this->_year_month_key($year, (int) ($row['mo'] ?? 0));
                if ($month_key === null) {
                    continue;
                }
                $this->_attach_profit_and_loss_monthly_total(
                    $sheet,
                    $coa_by_id,
                    $coa_by_id[$account_type_id],
                    $account_type_id,
                    (float) ($row['debit'] ?? 0),
                    (float) ($row['credit'] ?? 0),
                    $month_key,
                    $month_keys,
                    $merge_accounts
                );
            }
        }

        $this->_finalize_profit_and_loss_totals($sheet, $month_keys);

        return $sheet;
    }

    /**
     * jan-2026 ... dec-2026 for the filter year.
     *
     * @return array<int, string>
     */
    private function _balance_sheet_month_keys(int $year): array
    {
        $keys = [];
        foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $name) {
            $keys[] = $name . '-' . $year;
        }

        return $keys;
    }

    private function _balance_sheet_opening_raw_key(int $year): string
    {
        return 'raw_' . $this->_year_month_key($year - 1, 12);
    }

    /**
     * raw_dec-{year-1} plus raw_jan-YYYY ... raw_dec-YYYY.
     *
     * @param array<int, string> $month_keys
     * @return list<string>
     */
    private function _balance_sheet_raw_keys(array $month_keys, int $year): array
    {
        $keys = [$this->_balance_sheet_opening_raw_key($year)];
        foreach ($month_keys as $key) {
            $keys[] = 'raw_' . $key;
        }

        return $keys;
    }

    /**
     * @param array<int, string> $month_keys
     * @return array<string, float>
     */
    private function _balance_sheet_zero_months(array $month_keys): array
    {
        $months = [];
        foreach ($month_keys as $key) {
            $months[$key] = 0.0;
        }

        return $months;
    }

    private function _balance_sheet_month_key($txn_date, int $year): ?string
    {
        $ts = strtotime((string) $txn_date);
        if (! $ts || (int) date('Y', $ts) !== $year) {
            return null;
        }

        return $this->_year_month_key($year, (int) date('n', $ts));
    }

    private function _year_month_key(int $year, int $month): ?string
    {
        if ($month < 1 || $month > 12) {
            return null;
        }

        $names = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

        return $names[$month - 1] . '-' . $year;
    }

    private function _report_term(): ?string
    {
        $term = trim((string) ($this->payload['term'] ?? ''));

        return $term === '' ? null : $term;
    }

    /**
     * Keep only matching rows in every nested accounts[] list.
     */
    private function _filter_report_sheet_accounts(array &$sheet, callable $keep): void
    {
        foreach ($sheet as $key => &$node) {
            if (! is_array($node)) {
                continue;
            }
            if ($key === 'accounts') {
                $sheet['accounts'] = array_values(array_filter($node, $keep));
                continue;
            }
            $this->_filter_report_sheet_accounts($node, $keep);
        }
        unset($node);
    }

    /**
     * Insert group_code after code so P&L account rows expose the search field.
     */
    private function _with_report_group_code(array $row, $group_code): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = $value;
            if ($key === 'code') {
                $out['group_code'] = $group_code;
            }
        }

        return $out;
    }

    /**
     * Account row with a stable key order: id, code, name, amount, months, optional type/type_id.
     *
     * @param array<int, string> $month_keys
     */
    private function _report_account_row(
        int $id,
        $code,
        $name,
        float $amount,
        array $month_keys,
        bool $is_type_breakdown = false,
        ?string $month_key = null,
        $type = null,
        $type_id = null,
        array $extra_keys = []
    ): array {
        $row = [
            'id'     => $id,
            'code'   => $code,
            'name'   => $name,
            'amount' => $amount,
        ];
        foreach ($month_keys as $key) {
            $row[$key] = 0.0;
        }
        foreach ($extra_keys as $key) {
            $row[$key] = 0.0;
        }
        if ($month_key !== null) {
            $row[$month_key] = $amount;
        }
        if ($is_type_breakdown) {
            $row['type'] = $type;
            $row['type_id'] = $type_id;
        }

        return $row;
    }

    /**
     * Empty Balance Sheet.xlsx skeleton with Jan-Dec month keys and raw_* columns.
     *
     * @param array<int, string> $month_keys
     */
    private function _empty_balance_sheet(string $date_from, string $date_to, $branch_id, array $month_keys, int $year): array
    {
        $zero_months = $this->_balance_sheet_zero_months($month_keys);
        foreach ($this->_balance_sheet_raw_keys($month_keys, $year) as $key) {
            $zero_months[$key] = 0.0;
        }
        $line = static function () use ($zero_months) {
            return array_merge(['amount' => 0.0], $zero_months, ['accounts' => []]);
        };
        $rollup = static function () use ($zero_months) {
            return array_merge(['amount' => 0.0], $zero_months);
        };

        return [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'branch_id' => $branch_id,
            'current_assets' => [
                'cash_and_cash_equivalents'    => $line(),
                'trade_and_other_receivables'  => $line(),
                'non_trade_receivable'         => $line(),
                'inventories'                  => $line(),
                'other_current_assets'         => $line(),
                'total'                        => $rollup(),
            ],
            'non_current_assets' => [
                'property_and_equipment_net' => $line(),
                'other_noncurrent_assets'    => $line(),
                'total'                      => $rollup(),
            ],
            'total_assets' => $rollup(),
            'current_liabilities' => [
                'trade_and_other_payables' => array_merge(
                    ['amount' => 0.0],
                    $zero_months,
                    [
                        'trade_payables'     => $line(),
                        'non_trade_payables' => $line(),
                        'payable_at_risk'    => $line(),
                    ]
                ),
                'short_term_borrowing'      => $line(),
                'other_current_liabilities' => $line(),
                'total'                     => $rollup(),
            ],
            'non_current_liabilities' => [
                'due_to_related_parties'       => $line(),
                'loans_payable'                => $line(),
                'other_noncurrent_liabilities' => $line(),
                'total'                        => $rollup(),
            ],
            'total_liabilities' => $rollup(),
            'equity' => [
                'share_capital'     => $line(),
                'retained_earnings' => $line(),
                'total'             => $rollup(),
            ],
            'total_liabilities_and_equity' => $rollup(),
        ];
    }

    /**
     * Map a leaf account's CIFI children to a Balance Sheet.xlsx line key.
     */
    private function _map_balance_sheet_line(?array $coa): string
    {
        if (! $coa) {
            return 'retained_earnings';
        }

        $financial_statement = strtolower(trim((string) ($coa['financial_statement'] ?? '')));
        if ($financial_statement === 'profit_and_loss') {
            return 'retained_earnings';
        }

        $line = strtoupper(trim((string) ($coa['line_name'] ?? '')));
        $detail = strtoupper(trim((string) ($coa['detail_name'] ?? '')));

        if ($line === 'CASH AND CASH EQUIVALENTS') {
            return 'cash_and_cash_equivalents';
        }
        if ($line === 'TRADE RECEIVABLES') {
            return 'trade_and_other_receivables';
        }
        if ($line === 'NON TRADE RECEIVABLE' || $line === 'RECEIVABLE - OTHERS') {
            return 'non_trade_receivable';
        }
        if ($line === 'INVENTORIES') {
            return 'inventories';
        }
        if (
            $line === 'OTHER CURRENT ASSETS'
            || $line === 'PREPAID TAXES'
            || $line === 'PREPAID EXPENSES'
        ) {
            return 'other_current_assets';
        }
        if (
            $line === 'PROPERTY AND EQUIPMENT, NET'
            || $line === 'PPE'
            || strpos($line, 'RIGHT-OF USE') === 0
            || strpos($detail, 'RIGHT-OF USE') === 0
        ) {
            return 'property_and_equipment_net';
        }
        if ($line === 'OTHER NONCURRENT ASSETS') {
            return 'other_noncurrent_assets';
        }
        if ($line === 'TRADE AND OTHER PAYABLES' || $line === 'TRADE PAYABLES') {
            if ($detail === 'PAYABLE - OTHERS') {
                return 'non_trade_payables';
            }
            if (in_array($detail, ['TAXES PAYABLE', 'PREMIUMS PAYABLE', 'PROVISION FOR TAX LIABILITY'], true)) {
                return 'payable_at_risk';
            }
            if ($detail === 'ADVANCES - OTHERS') {
                return 'due_to_related_parties';
            }

            return 'trade_payables';
        }
        if ($line === 'PAYABLE - OTHERS') {
            return $detail === 'ADVANCES - OTHERS' ? 'due_to_related_parties' : 'non_trade_payables';
        }
        if (in_array($line, ['TAXES PAYABLE', 'PREMIUMS PAYABLE', 'PROVISION FOR TAX LIABILITY'], true)) {
            return 'payable_at_risk';
        }
        if ($line === 'SHORT TERM BORROWING' || $line === 'SHORT TERM LOANS') {
            return 'short_term_borrowing';
        }
        if ($line === 'OTHER CURRENT LIABILITIES' || $line === 'LEASE LIABILITY - CURRENT') {
            return 'other_current_liabilities';
        }
        if ($line === 'LOANS PAYABLE' || $line === 'LONG TERM LOANS') {
            return 'loans_payable';
        }
        if ($line === 'OTHER NONCURRENT LIABILITIES') {
            return 'other_noncurrent_liabilities';
        }
        if ($line === 'PAID UP CAPITAL') {
            return 'share_capital';
        }
        if ($line === 'RETAINED EARNINGS/FUND BALANCE') {
            return 'retained_earnings';
        }

        $category = strtolower(trim((string) ($coa['category'] ?? '')));
        if ($category === 'current_asset' || $category === 'prepayment') {
            return 'other_current_assets';
        }
        if ($category === 'non_current_asset') {
            return 'other_noncurrent_assets';
        }
        if ($category === 'current_liability') {
            return 'other_current_liabilities';
        }
        if ($category === 'non_current_liability') {
            return 'other_noncurrent_liabilities';
        }
        if ($category === 'equity') {
            return 'share_capital';
        }

        return 'retained_earnings';
    }

    /**
     * Asset lines: debit - credit. Liability and equity lines: credit - debit.
     */
    private function _signed_balance_sheet_amount(string $line_key, float $debit, float $credit): float
    {
        $credit_lines = [
            'trade_payables',
            'non_trade_payables',
            'payable_at_risk',
            'short_term_borrowing',
            'other_current_liabilities',
            'due_to_related_parties',
            'loans_payable',
            'other_noncurrent_liabilities',
            'share_capital',
            'retained_earnings',
        ];

        if (in_array($line_key, $credit_lines, true)) {
            return $credit - $debit;
        }

        return $debit - $credit;
    }

    /**
     * Add signed amount and month columns from source onto a line or account.
     *
     * @param array<int, string> $month_keys
     */
    private function _apply_balance_sheet_amount(array &$target, array $source, array $month_keys): void
    {
        $target['amount'] = round(((float) ($target['amount'] ?? 0)) + (float) ($source['amount'] ?? 0), 4);
        $applied = [];
        foreach ($month_keys as $key) {
            $target[$key] = round(((float) ($target[$key] ?? 0)) + (float) ($source[$key] ?? 0), 4);
            $applied[$key] = true;
        }
        foreach ($source as $key => $value) {
            if (
                ! is_string($key)
                || strncmp($key, 'raw_', 4) !== 0
                || isset($applied[$key])
                || ! is_numeric($value)
            ) {
                continue;
            }
            $target[$key] = round(((float) ($target[$key] ?? 0)) + (float) $value, 4);
        }
    }

    /**
     * Attach an account amount onto the matching skeleton line.
     *
     * @param array<int, string> $month_keys
     */
    private function _add_balance_sheet_account(array &$sheet, string $line_key, array $account, array $month_keys, bool $add_to_total = true): void
    {
        $ref =& $this->_balance_sheet_line_ref($sheet, $line_key);

        if ($add_to_total) {
            $this->_apply_balance_sheet_amount($ref, $account, $month_keys);
        }
        $ref['accounts'][] = $account;
    }

    /**
     * Sum duplicate account types onto one row (no type/type_id).
     *
     * @param array<int, string> $month_keys
     */
    private function _merge_balance_sheet_account(array &$sheet, string $line_key, array $account, array $month_keys): void
    {
        $ref =& $this->_balance_sheet_line_ref($sheet, $line_key);
        $this->_apply_balance_sheet_amount($ref, $account, $month_keys);

        $account_id = (int) ($account['id'] ?? 0);
        foreach ($ref['accounts'] as &$entry) {
            if ((int) ($entry['id'] ?? 0) === $account_id) {
                $this->_apply_balance_sheet_amount($entry, $account, $month_keys);
                unset($entry);
                return;
            }
        }
        unset($entry);

        $ref['accounts'][] = $account;
    }

    /**
     * Drop the zero stub for an account id so activity rows are not duplicated.
     */
    private function _remove_balance_sheet_stub(array &$sheet, string $line_key, int $account_id): void
    {
        $ref =& $this->_balance_sheet_line_ref($sheet, $line_key);
        $kept = [];
        foreach ($ref['accounts'] as $entry) {
            $is_stub = (int) ($entry['id'] ?? 0) === $account_id
                && ($entry['type'] ?? null) === null
                && abs((float) ($entry['amount'] ?? 0)) < 0.00005;
            if (! $is_stub) {
                $kept[] = $entry;
            }
        }
        $ref['accounts'] = $kept;
    }

    /**
     * @return array
     */
    private function &_balance_sheet_line_ref(array &$sheet, string $line_key): array
    {
        $paths = [
            'cash_and_cash_equivalents'    => ['current_assets', 'cash_and_cash_equivalents'],
            'trade_and_other_receivables'  => ['current_assets', 'trade_and_other_receivables'],
            'non_trade_receivable'         => ['current_assets', 'non_trade_receivable'],
            'inventories'                  => ['current_assets', 'inventories'],
            'other_current_assets'         => ['current_assets', 'other_current_assets'],
            'property_and_equipment_net'   => ['non_current_assets', 'property_and_equipment_net'],
            'other_noncurrent_assets'      => ['non_current_assets', 'other_noncurrent_assets'],
            'trade_payables'               => ['current_liabilities', 'trade_and_other_payables', 'trade_payables'],
            'non_trade_payables'           => ['current_liabilities', 'trade_and_other_payables', 'non_trade_payables'],
            'payable_at_risk'              => ['current_liabilities', 'trade_and_other_payables', 'payable_at_risk'],
            'short_term_borrowing'         => ['current_liabilities', 'short_term_borrowing'],
            'other_current_liabilities'    => ['current_liabilities', 'other_current_liabilities'],
            'due_to_related_parties'       => ['non_current_liabilities', 'due_to_related_parties'],
            'loans_payable'                => ['non_current_liabilities', 'loans_payable'],
            'other_noncurrent_liabilities' => ['non_current_liabilities', 'other_noncurrent_liabilities'],
            'share_capital'                => ['equity', 'share_capital'],
            'retained_earnings'            => ['equity', 'retained_earnings'],
        ];

        if (! isset($paths[$line_key])) {
            $line_key = 'retained_earnings';
        }

        $ref =& $sheet;
        foreach ($paths[$line_key] as $segment) {
            $ref =& $ref[$segment];
        }

        return $ref;
    }

    /**
     * Convert period month columns to running as-of through $last_month; later months stay 0.
     * Running starts at raw_dec-{year-1}. raw_* keys are left as period / opening amounts.
     *
     * @param array<int, string> $month_keys
     */
    private function _accumulate_balance_sheet_months(
        array &$node,
        array $month_keys,
        int $last_month,
        string $opening_raw_key
    ): void {
        $has_months = true;
        foreach ($month_keys as $key) {
            if (! array_key_exists($key, $node)) {
                $has_months = false;
                break;
            }
        }
        if ($has_months) {
            $running = (float) ($node[$opening_raw_key] ?? 0);
            foreach ($month_keys as $i => $key) {
                $month = $i + 1;
                if ($month > $last_month) {
                    $node[$key] = 0.0;
                    continue;
                }
                $running += (float) ($node['raw_' . $key] ?? $node[$key] ?? 0);
                $node[$key] = round($running, 4);
            }
        }

        foreach ($node as &$child) {
            if (is_array($child)) {
                $this->_accumulate_balance_sheet_months($child, $month_keys, $last_month, $opening_raw_key);
            }
        }
        unset($child);
    }

    /**
     * Copy period year months onto raw_{month} before as-of rollup.
     *
     * @param array<int, string> $month_keys
     */
    private function _snapshot_balance_sheet_raw_months(array &$node, array $month_keys): void
    {
        $has_months = true;
        foreach ($month_keys as $key) {
            if (! array_key_exists($key, $node)) {
                $has_months = false;
                break;
            }
        }
        if ($has_months) {
            foreach ($month_keys as $key) {
                $node['raw_' . $key] = round((float) ($node[$key] ?? 0), 4);
            }
        }

        foreach ($node as &$child) {
            if (is_array($child)) {
                $this->_snapshot_balance_sheet_raw_months($child, $month_keys);
            }
        }
        unset($child);
    }

    /**
     * Sum amount, year months, and raw_* from child lines onto a total node.
     *
     * @param array<int, string> $month_keys
     * @param array<int, array> $parts
     * @param list<string> $raw_keys
     */
    private function _sum_balance_sheet_total(
        array &$target,
        array $parts,
        array $month_keys,
        array $raw_keys
    ): void {
        $this->_sum_profit_and_loss_parts($target, $parts, $month_keys);
        foreach ($raw_keys as $key) {
            $target[$key] = 0.0;
            foreach ($parts as $part) {
                $target[$key] = round(((float) $target[$key]) + (float) ($part[$key] ?? 0), 4);
            }
        }
    }

    /**
     * Roll line amounts into section and grand totals.
     *
     * @param array<int, string> $month_keys
     * @param list<string> $raw_keys
     */
    private function _finalize_balance_sheet_totals(array &$sheet, array $month_keys, array $raw_keys = []): void
    {
        $ca =& $sheet['current_assets'];
        $this->_sum_balance_sheet_total(
            $ca['total'],
            [
                $ca['cash_and_cash_equivalents'],
                $ca['trade_and_other_receivables'],
                $ca['non_trade_receivable'],
                $ca['inventories'],
                $ca['other_current_assets'],
            ],
            $month_keys,
            $raw_keys
        );

        $nca =& $sheet['non_current_assets'];
        $this->_sum_balance_sheet_total(
            $nca['total'],
            [$nca['property_and_equipment_net'], $nca['other_noncurrent_assets']],
            $month_keys,
            $raw_keys
        );

        $this->_sum_balance_sheet_total(
            $sheet['total_assets'],
            [$ca['total'], $nca['total']],
            $month_keys,
            $raw_keys
        );

        $tap =& $sheet['current_liabilities']['trade_and_other_payables'];
        $this->_sum_balance_sheet_total(
            $tap,
            [$tap['trade_payables'], $tap['non_trade_payables'], $tap['payable_at_risk']],
            $month_keys,
            $raw_keys
        );

        $cl =& $sheet['current_liabilities'];
        $this->_sum_balance_sheet_total(
            $cl['total'],
            [$tap, $cl['short_term_borrowing'], $cl['other_current_liabilities']],
            $month_keys,
            $raw_keys
        );

        $ncl =& $sheet['non_current_liabilities'];
        $this->_sum_balance_sheet_total(
            $ncl['total'],
            [$ncl['due_to_related_parties'], $ncl['loans_payable'], $ncl['other_noncurrent_liabilities']],
            $month_keys,
            $raw_keys
        );

        $this->_sum_balance_sheet_total(
            $sheet['total_liabilities'],
            [$cl['total'], $ncl['total']],
            $month_keys,
            $raw_keys
        );

        $eq =& $sheet['equity'];
        $this->_sum_balance_sheet_total(
            $eq['total'],
            [$eq['share_capital'], $eq['retained_earnings']],
            $month_keys,
            $raw_keys
        );

        $this->_sum_balance_sheet_total(
            $sheet['total_liabilities_and_equity'],
            [$sheet['total_liabilities'], $eq['total']],
            $month_keys,
            $raw_keys
        );
    }

    /**
     * Apply one monthly debit/credit total onto the balance sheet skeleton.
     * P&L and unknown account ids roll up to retained_earnings.
     *
     * @param array<int, string> $month_keys
     */
    private function _attach_balance_sheet_monthly_total(
        array &$sheet,
        array $coa_by_id,
        ?array $coa,
        int $account_type_id,
        float $debit,
        float $credit,
        ?string $month_key,
        array $month_keys,
        array $extra_keys = []
    ): void {
        $line_key = $this->_map_balance_sheet_line($coa);
        $signed = $this->_signed_balance_sheet_amount($line_key, $debit, $credit);
        $account = $this->_report_account_row(
            $account_type_id,
            is_array($coa) ? ($coa['code'] ?? null) : null,
            is_array($coa) ? ($coa['name'] ?? null) : null,
            round($signed, 4),
            $month_keys,
            false,
            $month_key,
            null,
            null,
            $extra_keys
        );

        $this->_remove_balance_sheet_account_stubs($sheet, $coa_by_id, $coa, $account_type_id, $line_key);
        $this->_merge_balance_sheet_account($sheet, $line_key, $account, $month_keys);
    }

    /**
     * Prior-year as-of onto amount and raw_dec-{year-1} only (not jan-YYYY).
     *
     * @param array<int, string> $month_keys
     * @param list<string> $extra_keys
     */
    private function _attach_balance_sheet_opening_total(
        array &$sheet,
        array $coa_by_id,
        ?array $coa,
        int $account_type_id,
        float $debit,
        float $credit,
        string $opening_raw_key,
        array $month_keys,
        array $extra_keys = []
    ): void {
        $line_key = $this->_map_balance_sheet_line($coa);
        $signed = round($this->_signed_balance_sheet_amount($line_key, $debit, $credit), 4);
        $account = $this->_report_account_row(
            $account_type_id,
            is_array($coa) ? ($coa['code'] ?? null) : null,
            is_array($coa) ? ($coa['name'] ?? null) : null,
            $signed,
            $month_keys,
            false,
            null,
            null,
            null,
            $extra_keys
        );
        $account[$opening_raw_key] = $signed;

        $this->_remove_balance_sheet_account_stubs($sheet, $coa_by_id, $coa, $account_type_id, $line_key);
        $this->_merge_balance_sheet_account($sheet, $line_key, $account, $month_keys);
    }

    /**
     * @param array<int, array> $coa_by_id
     */
    private function _remove_balance_sheet_account_stubs(
        array &$sheet,
        array $coa_by_id,
        ?array $coa,
        int $account_type_id,
        string $line_key
    ): void {
        $stub_ids = [$account_type_id];
        $parent_id = is_array($coa) ? ($coa['parent_id'] ?? null) : null;
        if ($parent_id !== null && $parent_id !== '') {
            $stub_ids[] = (int) $parent_id;
            $parent_coa = $coa_by_id[(int) $parent_id] ?? null;
            if ($parent_coa) {
                $this->_remove_balance_sheet_stub(
                    $sheet,
                    $this->_map_balance_sheet_line($parent_coa),
                    (int) $parent_id
                );
            }
        }
        foreach ($stub_ids as $stub_id) {
            $this->_remove_balance_sheet_stub($sheet, $line_key, (int) $stub_id);
        }
    }

    /**
     * Apply one monthly debit/credit total onto the P&L skeleton.
     *
     * @param array<int, string> $month_keys
     */
    private function _attach_profit_and_loss_monthly_total(
        array &$sheet,
        array $coa_by_id,
        array $coa,
        int $account_type_id,
        float $debit,
        float $credit,
        ?string $month_key,
        array $month_keys,
        bool $merge_accounts
    ): void {
        $line_key = $this->_map_profit_and_loss_line($coa);
        $signed = $this->_signed_profit_and_loss_amount($line_key, $debit, $credit);
        $account = $this->_with_report_group_code(
            $this->_report_account_row(
                $account_type_id,
                $coa['code'] ?? null,
                $coa['name'] ?? null,
                round($signed, 4),
                $month_keys,
                false,
                $month_key
            ),
            $coa['group_code'] ?? null
        );

        if (! $merge_accounts) {
            $ref =& $this->_profit_and_loss_line_ref($sheet, $line_key);
            $this->_apply_balance_sheet_amount($ref, $account, $month_keys);
            return;
        }

        $stub_ids = [$account_type_id];
        $parent_id = $coa['parent_id'] ?? null;
        if ($parent_id !== null && $parent_id !== '') {
            $stub_ids[] = (int) $parent_id;
            $parent_coa = $coa_by_id[(int) $parent_id] ?? null;
            if ($parent_coa) {
                $this->_remove_profit_and_loss_stub(
                    $sheet,
                    $this->_map_profit_and_loss_line($parent_coa),
                    (int) $parent_id
                );
            }
        }
        foreach ($stub_ids as $stub_id) {
            $this->_remove_profit_and_loss_stub($sheet, $line_key, (int) $stub_id);
        }

        $this->_merge_profit_and_loss_account($sheet, $line_key, $account, $month_keys);
    }

    /**
     * Empty P&L OAK skeleton with Jan-Dec month keys.
     *
     * @param array<int, string> $month_keys
     */
    private function _empty_profit_and_loss(string $date_from, string $date_to, $branch_id, array $month_keys): array
    {
        $zero_months = $this->_balance_sheet_zero_months($month_keys);
        $line = static function () use ($zero_months) {
            return array_merge(['amount' => 0.0], $zero_months, ['accounts' => []]);
        };
        $rollup = static function () use ($zero_months) {
            return array_merge(['amount' => 0.0], $zero_months);
        };

        return [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'branch_id' => $branch_id,
            'gross_revenues' => [
                'laboratory_diagnostics' => array_merge($rollup(), [
                    'lab_diag_tests' => $line(),
                    'send_out'       => $line(),
                ]),
                'new_projects'             => $line(),
                'yakap_fpe'                => $line(),
                'health_services'          => $line(),
                'consultations'            => $line(),
                'third_party_partnership'  => $line(),
                'total_gross_revenue'      => $rollup(),
                'discounts' => array_merge($line(), [
                    'prepaid_discount' => $line(),
                ]),
            ],
            'net_revenues' => $rollup(),
            'cost_of_sales' => [
                'laboratory_diagnostics' => array_merge($rollup(), [
                    'direct_materials'              => $line(),
                    'professional_fees_reader_fees' => $line(),
                    'outsourced_staff_and_services' => $line(),
                    'send_out_cost'                 => $line(),
                    'depreciation'                  => $line(),
                    'other_direct_costs'            => $line(),
                ]),
                'new_projects' => array_merge($rollup(), [
                    'animal_bite'              => $line(),
                    'clinicavax'               => $line(),
                    'yakap'                    => $line(),
                    'women_and_child_center'   => $line(),
                    'optometry'                => $line(),
                ]),
                'health_services'         => $line(),
                'consultations'           => $line(),
                'third_party_partnership' => $line(),
                'total'                   => $rollup(),
            ],
            'gross_margin' => $rollup(),
            'branch_operating_costs' => [
                'manpower_cost'            => $line(),
                'occupancy_cost'           => $line(),
                'it_and_communications'    => $line(),
                'depreciation'             => $line(),
                'other_branch_operating_costs' => array_merge($rollup(), [
                    'supplies_repairs_and_maintenance' => $line(),
                    'transportation'                   => $line(),
                    'security_and_janitorial'          => $line(),
                    'taxes_and_licenses'               => $line(),
                    'others'                           => $line(),
                ]),
                'total' => $rollup(),
            ],
            'bncm'  => $rollup(),
            'bcmda' => $rollup(),
            'general_and_admin_expenses' => [
                'manpower_cost'               => $line(),
                'management_fee_lhpi'         => $line(),
                'occupancy_cost'              => $line(),
                'depreciation'                => $line(),
                'it_and_communications'       => $line(),
                'marketing_and_representation'=> $line(),
                'other_general_and_admin_expenses' => array_merge($rollup(), [
                    'supplies_repairs_and_maintenance' => $line(),
                    'security_and_janitorial'          => $line(),
                    'transportation'                   => $line(),
                    'other_admin_expenses'             => $line(),
                ]),
                'total' => $rollup(),
            ],
            'income_from_operations' => $rollup(),
            'nopex'                  => $line(),
            'net_income'             => $rollup(),
            'nida'                   => $rollup(),
        ];
    }

    /**
     * Map a P&L leaf's CIFI children to a P&L OAK line key.
     */
    private function _map_profit_and_loss_line(?array $coa): string
    {
        if (! $coa) {
            return 'nopex';
        }

        $section = strtoupper(trim((string) ($coa['section_name'] ?? '')));
        $report = strtoupper(trim((string) ($coa['report_name'] ?? '')));
        $line = strtoupper(trim((string) ($coa['line_name'] ?? '')));
        $detail = strtoupper(trim((string) ($coa['detail_name'] ?? '')));

        if ($section === 'REVENUE') {
            if (in_array($report, ['LABORATORY', 'DIAGNOSTICS', 'LABORATOY/DIAGNOSTICS', 'LABORATORY/DIAGNOSTICS'], true)) {
                return 'lab_diag_tests';
            }
            if ($report === 'SEND OUT' || $report === 'SEND OUT COST') {
                return 'send_out';
            }
            if ($report === 'KONSULTA' || $detail === 'YAKAP-FPE') {
                return 'yakap_fpe';
            }
            if ($report === 'HEALTH SERVICES') {
                return 'health_services';
            }
            if ($report === 'CONSULTATIONS') {
                return 'consultations';
            }
            if ($report === 'THIRD-PARTY PARTNERSHIP') {
                return 'third_party_partnership';
            }
            if ($report === 'DISCOUNTS') {
                return 'discounts';
            }
            if ($report === 'PREPAID DISCOUNT') {
                return 'prepaid_discount';
            }

            return 'new_projects';
        }

        if ($section === 'COST OF SALES') {
            if ($report === 'DIRECT MATERIALS') {
                return 'cos_direct_materials';
            }
            if (
                $report === 'PROFESSIONAL FEES (READER FEES)'
                || $report === "PROFESSIONAL FEE (READER'S FEE)"
            ) {
                return 'cos_professional_fees';
            }
            if ($report === 'OUTSOURCED STAFF AND SERVICES') {
                return 'cos_outsourced';
            }
            if ($report === 'SEND OUT COST') {
                return 'cos_send_out';
            }
            if ($report === 'DEPRECIATION') {
                return 'cos_depreciation';
            }
            if ($report === 'HEALTH SERVICES') {
                return 'cos_health_services';
            }
            if ($report === 'CONSULTATIONS') {
                return 'cos_consultations';
            }
            if ($report === 'THIRD-PARTY PARTNERSHIP') {
                return 'cos_third_party';
            }
            if ($report === 'KONSULTA' || $report === 'YAKAP') {
                return 'cos_yakap';
            }
            if ($report === 'CLINICA VAXX') {
                return 'cos_clinicavax';
            }
            if ($report === 'OPTHALMOLOGY/OPTOMETRY' || $detail === 'OPTOMETRY') {
                return 'cos_optometry';
            }

            return 'cos_other_direct_costs';
        }

        if ($section === 'BRANCH OPERATING COSTS') {
            if ($report === 'MANPOWER COST' || $report === 'BRANCH OPERATING COSTS') {
                return 'boc_manpower_cost';
            }
            if ($report === 'OCCUPANCY COST' || $report === 'OCCUPANCY COSTS') {
                if (in_array($detail, ['SECURITY AND JANITORIAL', 'SECUTIRY AND JANITORIAL'], true)) {
                    return 'boc_security_and_janitorial';
                }

                return 'boc_occupancy_cost';
            }
            if ($report === 'IT AND COMMUNICATIONS') {
                return 'boc_it_and_communications';
            }
            if ($report === 'DEPRECIATION') {
                return 'boc_depreciation';
            }
            if ($report === 'OTHER BRANCH OPERATING COSTS') {
                if (strpos($detail, 'SUPPLIES') === 0) {
                    return 'boc_supplies_repairs_and_maintenance';
                }
                if (strpos($detail, 'TRANSPORT') === 0) {
                    return 'boc_transportation';
                }

                return 'boc_others';
            }
            if ($report === 'SUPPLIES, REPAIRS AND MAINTENANCE' || strpos($detail, 'SUPPLIES') === 0) {
                return 'boc_supplies_repairs_and_maintenance';
            }
            if ($report === 'TRANSPORTATION' || strpos($detail, 'TRANSPORT') === 0) {
                return 'boc_transportation';
            }
            if (
                $report === 'SECURITY AND JANITORIAL'
                || in_array($detail, ['SECURITY AND JANITORIAL', 'SECUTIRY AND JANITORIAL'], true)
            ) {
                return 'boc_security_and_janitorial';
            }
            if ($line === 'TAXES AND LICENSES' || $detail === 'TAXES AND LICENSES') {
                return 'boc_taxes_and_licenses';
            }

            return 'boc_others';
        }

        if (strpos($section, 'GENERAL') === 0) {
            if ($report === 'MANPOWER COST' || $report === 'MANPOWER COST & EXEC OFFICE') {
                return 'ga_manpower_cost';
            }
            if (
                $report === 'EXECUTIVE/ MANAGEMENT FEE'
                || $report === 'MANAGEMENT FEE - LHPI'
                || $report === 'MANAGEMENT FEE'
                || strpos($report, 'MANAGEMENT FEE') !== false
            ) {
                return 'ga_management_fee_lhpi';
            }
            if (
                $report === 'OCCUPANCY COST & DEPRECIATION'
                || $report === 'OCCUPANCY COST'
                || $report === 'OCCUPANCY COSTS'
            ) {
                return $line === 'DEPRECIATION' ? 'ga_depreciation' : 'ga_occupancy_cost';
            }
            if ($report === 'DEPRECIATION') {
                return 'ga_depreciation';
            }
            if ($report === 'IT AND COMMUNICATIONS') {
                return 'ga_it_and_communications';
            }
            if ($report === 'MARKETING AND REPRESENTATION') {
                return 'ga_marketing_and_representation';
            }
            if (
                $report === 'SUPPLIES, REPAIRS AND MAINTENANCE'
                || strpos($report, 'SUPPLIES') === 0
                || strpos($detail, 'SUPPLIES') === 0
            ) {
                return 'ga_supplies_repairs_and_maintenance';
            }
            if (
                $report === 'SECURITY AND JANITORIAL'
                || in_array($detail, ['SECURITY AND JANITORIAL', 'SECUTIRY AND JANITORIAL'], true)
            ) {
                return 'ga_security_and_janitorial';
            }
            if ($report === 'TRANSPORTATION' || strpos($detail, 'TRANSPORT') === 0) {
                return 'ga_transportation';
            }

            return 'ga_other_admin_expenses';
        }

        return 'nopex';
    }

    /**
     * Revenue and discount lines: credit - debit. Expense lines: debit - credit.
     */
    private function _signed_profit_and_loss_amount(string $line_key, float $debit, float $credit): float
    {
        $credit_lines = [
            'lab_diag_tests',
            'send_out',
            'new_projects',
            'yakap_fpe',
            'health_services',
            'consultations',
            'third_party_partnership',
            'discounts',
            'prepaid_discount',
        ];

        if (in_array($line_key, $credit_lines, true)) {
            return $credit - $debit;
        }

        return $debit - $credit;
    }

    /**
     * @param array<int, string> $month_keys
     */
    private function _add_profit_and_loss_account(array &$sheet, string $line_key, array $account, array $month_keys, bool $add_to_total = true): void
    {
        $ref =& $this->_profit_and_loss_line_ref($sheet, $line_key);

        if ($add_to_total) {
            $this->_apply_balance_sheet_amount($ref, $account, $month_keys);
        }
        if (! isset($ref['accounts']) || ! is_array($ref['accounts'])) {
            $ref['accounts'] = [];
        }
        $ref['accounts'][] = $account;
    }

    /**
     * @param array<int, string> $month_keys
     */
    private function _merge_profit_and_loss_account(array &$sheet, string $line_key, array $account, array $month_keys): void
    {
        $ref =& $this->_profit_and_loss_line_ref($sheet, $line_key);
        $this->_apply_balance_sheet_amount($ref, $account, $month_keys);

        if (! isset($ref['accounts']) || ! is_array($ref['accounts'])) {
            $ref['accounts'] = [];
        }

        $account_id = (int) ($account['id'] ?? 0);
        foreach ($ref['accounts'] as &$entry) {
            if ((int) ($entry['id'] ?? 0) === $account_id) {
                $this->_apply_balance_sheet_amount($entry, $account, $month_keys);
                unset($entry);
                return;
            }
        }
        unset($entry);

        $ref['accounts'][] = $account;
    }

    private function _remove_profit_and_loss_stub(array &$sheet, string $line_key, int $account_id): void
    {
        $ref =& $this->_profit_and_loss_line_ref($sheet, $line_key);
        if (! isset($ref['accounts']) || ! is_array($ref['accounts'])) {
            return;
        }

        $kept = [];
        foreach ($ref['accounts'] as $entry) {
            $is_stub = (int) ($entry['id'] ?? 0) === $account_id
                && ($entry['type'] ?? null) === null
                && abs((float) ($entry['amount'] ?? 0)) < 0.00005;
            if (! $is_stub) {
                $kept[] = $entry;
            }
        }
        $ref['accounts'] = $kept;
    }

    /**
     * @return array
     */
    private function &_profit_and_loss_line_ref(array &$sheet, string $line_key): array
    {
        $paths = [
            'lab_diag_tests'                       => ['gross_revenues', 'laboratory_diagnostics', 'lab_diag_tests'],
            'send_out'                             => ['gross_revenues', 'laboratory_diagnostics', 'send_out'],
            'new_projects'                         => ['gross_revenues', 'new_projects'],
            'yakap_fpe'                            => ['gross_revenues', 'yakap_fpe'],
            'health_services'                      => ['gross_revenues', 'health_services'],
            'consultations'                        => ['gross_revenues', 'consultations'],
            'third_party_partnership'              => ['gross_revenues', 'third_party_partnership'],
            'discounts'                            => ['gross_revenues', 'discounts'],
            'prepaid_discount'                     => ['gross_revenues', 'discounts', 'prepaid_discount'],
            'cos_direct_materials'                 => ['cost_of_sales', 'laboratory_diagnostics', 'direct_materials'],
            'cos_professional_fees'                => ['cost_of_sales', 'laboratory_diagnostics', 'professional_fees_reader_fees'],
            'cos_outsourced'                       => ['cost_of_sales', 'laboratory_diagnostics', 'outsourced_staff_and_services'],
            'cos_send_out'                         => ['cost_of_sales', 'laboratory_diagnostics', 'send_out_cost'],
            'cos_depreciation'                     => ['cost_of_sales', 'laboratory_diagnostics', 'depreciation'],
            'cos_other_direct_costs'               => ['cost_of_sales', 'laboratory_diagnostics', 'other_direct_costs'],
            'cos_animal_bite'                      => ['cost_of_sales', 'new_projects', 'animal_bite'],
            'cos_clinicavax'                       => ['cost_of_sales', 'new_projects', 'clinicavax'],
            'cos_yakap'                            => ['cost_of_sales', 'new_projects', 'yakap'],
            'cos_women_and_child'                  => ['cost_of_sales', 'new_projects', 'women_and_child_center'],
            'cos_optometry'                        => ['cost_of_sales', 'new_projects', 'optometry'],
            'cos_health_services'                  => ['cost_of_sales', 'health_services'],
            'cos_consultations'                    => ['cost_of_sales', 'consultations'],
            'cos_third_party'                      => ['cost_of_sales', 'third_party_partnership'],
            'boc_manpower_cost'                    => ['branch_operating_costs', 'manpower_cost'],
            'boc_occupancy_cost'                   => ['branch_operating_costs', 'occupancy_cost'],
            'boc_it_and_communications'            => ['branch_operating_costs', 'it_and_communications'],
            'boc_depreciation'                     => ['branch_operating_costs', 'depreciation'],
            'boc_supplies_repairs_and_maintenance' => ['branch_operating_costs', 'other_branch_operating_costs', 'supplies_repairs_and_maintenance'],
            'boc_transportation'                   => ['branch_operating_costs', 'other_branch_operating_costs', 'transportation'],
            'boc_security_and_janitorial'          => ['branch_operating_costs', 'other_branch_operating_costs', 'security_and_janitorial'],
            'boc_taxes_and_licenses'               => ['branch_operating_costs', 'other_branch_operating_costs', 'taxes_and_licenses'],
            'boc_others'                           => ['branch_operating_costs', 'other_branch_operating_costs', 'others'],
            'ga_manpower_cost'                     => ['general_and_admin_expenses', 'manpower_cost'],
            'ga_management_fee_lhpi'               => ['general_and_admin_expenses', 'management_fee_lhpi'],
            'ga_occupancy_cost'                    => ['general_and_admin_expenses', 'occupancy_cost'],
            'ga_depreciation'                      => ['general_and_admin_expenses', 'depreciation'],
            'ga_it_and_communications'             => ['general_and_admin_expenses', 'it_and_communications'],
            'ga_marketing_and_representation'      => ['general_and_admin_expenses', 'marketing_and_representation'],
            'ga_supplies_repairs_and_maintenance'  => ['general_and_admin_expenses', 'other_general_and_admin_expenses', 'supplies_repairs_and_maintenance'],
            'ga_security_and_janitorial'           => ['general_and_admin_expenses', 'other_general_and_admin_expenses', 'security_and_janitorial'],
            'ga_transportation'                    => ['general_and_admin_expenses', 'other_general_and_admin_expenses', 'transportation'],
            'ga_other_admin_expenses'              => ['general_and_admin_expenses', 'other_general_and_admin_expenses', 'other_admin_expenses'],
            'nopex'                                => ['nopex'],
        ];

        if (! isset($paths[$line_key])) {
            $line_key = 'nopex';
        }

        $ref =& $sheet;
        foreach ($paths[$line_key] as $segment) {
            $ref =& $ref[$segment];
        }

        return $ref;
    }

    /**
     * @param array<int, string> $month_keys
     * @param array<int, array> $parts
     */
    private function _sum_profit_and_loss_parts(array &$target, array $parts, array $month_keys): void
    {
        $target['amount'] = 0.0;
        foreach ($month_keys as $key) {
            $target[$key] = 0.0;
        }

        foreach ($parts as $part) {
            $target['amount'] = round($target['amount'] + (float) ($part['amount'] ?? 0), 4);
            foreach ($month_keys as $key) {
                $target[$key] = round(((float) $target[$key]) + (float) ($part[$key] ?? 0), 4);
            }
        }
    }

    /**
     * @param array<int, string> $month_keys
     * @param array<int, array> $add
     * @param array<int, array> $subtract
     */
    private function _combine_profit_and_loss_parts(
        array &$target,
        array $month_keys,
        array $add,
        array $subtract = []
    ): void {
        $this->_sum_profit_and_loss_parts($target, $add, $month_keys);
        foreach ($subtract as $part) {
            $target['amount'] = round($target['amount'] - (float) ($part['amount'] ?? 0), 4);
            foreach ($month_keys as $key) {
                $target[$key] = round(((float) $target[$key]) - (float) ($part[$key] ?? 0), 4);
            }
        }
    }

    /**
     * Scalar stay a float; rollup nodes use amount.
     */
    private function _pnl_node_amount($node): float
    {
        if (is_array($node)) {
            return (float) ($node['amount'] ?? 0);
        }

        return (float) ($node ?? 0);
    }

    /**
     * Roll P&L OAK line amounts into section and computed totals.
     *
     * @param array<int, string> $month_keys
     */
    private function _finalize_profit_and_loss_totals(array &$sheet, array $month_keys): void
    {
        $gr =& $sheet['gross_revenues'];
        $this->_sum_profit_and_loss_parts(
            $gr['laboratory_diagnostics'],
            [$gr['laboratory_diagnostics']['lab_diag_tests'], $gr['laboratory_diagnostics']['send_out']],
            $month_keys
        );
        $this->_sum_profit_and_loss_parts(
            $gr['discounts'],
            [$gr['discounts'], $gr['discounts']['prepaid_discount']],
            $month_keys
        );

        $this->_sum_profit_and_loss_parts(
            $gr['total_gross_revenue'],
            [
                $gr['laboratory_diagnostics'],
                $gr['new_projects'],
                $gr['yakap_fpe'],
                $gr['health_services'],
                $gr['consultations'],
                $gr['third_party_partnership'],
            ],
            $month_keys
        );

        $this->_sum_profit_and_loss_parts(
            $sheet['net_revenues'],
            [$gr['total_gross_revenue'], $gr['discounts']],
            $month_keys
        );

        $cos =& $sheet['cost_of_sales'];
        $lab_cos =& $cos['laboratory_diagnostics'];
        $this->_sum_profit_and_loss_parts(
            $lab_cos,
            [
                $lab_cos['direct_materials'],
                $lab_cos['professional_fees_reader_fees'],
                $lab_cos['outsourced_staff_and_services'],
                $lab_cos['send_out_cost'],
                $lab_cos['depreciation'],
                $lab_cos['other_direct_costs'],
            ],
            $month_keys
        );

        $np_cos =& $cos['new_projects'];
        $this->_sum_profit_and_loss_parts(
            $np_cos,
            [
                $np_cos['animal_bite'],
                $np_cos['clinicavax'],
                $np_cos['yakap'],
                $np_cos['women_and_child_center'],
                $np_cos['optometry'],
            ],
            $month_keys
        );

        $this->_sum_profit_and_loss_parts(
            $cos['total'],
            [
                $lab_cos,
                $np_cos,
                $cos['health_services'],
                $cos['consultations'],
                $cos['third_party_partnership'],
            ],
            $month_keys
        );

        $this->_combine_profit_and_loss_parts(
            $sheet['gross_margin'],
            $month_keys,
            [$sheet['net_revenues']],
            [$cos['total']]
        );

        $boc =& $sheet['branch_operating_costs'];
        $boc_other =& $boc['other_branch_operating_costs'];
        $this->_sum_profit_and_loss_parts(
            $boc_other,
            [
                $boc_other['supplies_repairs_and_maintenance'],
                $boc_other['transportation'],
                $boc_other['security_and_janitorial'],
                $boc_other['taxes_and_licenses'],
                $boc_other['others'],
            ],
            $month_keys
        );
        $this->_sum_profit_and_loss_parts(
            $boc['total'],
            [
                $boc['manpower_cost'],
                $boc['occupancy_cost'],
                $boc['it_and_communications'],
                $boc['depreciation'],
                $boc_other,
            ],
            $month_keys
        );

        $this->_combine_profit_and_loss_parts(
            $sheet['bncm'],
            $month_keys,
            [$sheet['gross_margin']],
            [$boc['total']]
        );
        $this->_sum_profit_and_loss_parts(
            $sheet['bcmda'],
            [$sheet['bncm'], $boc['depreciation'], $lab_cos['depreciation']],
            $month_keys
        );

        $ga =& $sheet['general_and_admin_expenses'];
        $ga_other =& $ga['other_general_and_admin_expenses'];
        $this->_sum_profit_and_loss_parts(
            $ga_other,
            [
                $ga_other['supplies_repairs_and_maintenance'],
                $ga_other['security_and_janitorial'],
                $ga_other['transportation'],
                $ga_other['other_admin_expenses'],
            ],
            $month_keys
        );
        $this->_sum_profit_and_loss_parts(
            $ga['total'],
            [
                $ga['manpower_cost'],
                $ga['management_fee_lhpi'],
                $ga['occupancy_cost'],
                $ga['depreciation'],
                $ga['it_and_communications'],
                $ga['marketing_and_representation'],
                $ga_other,
            ],
            $month_keys
        );

        $this->_combine_profit_and_loss_parts(
            $sheet['income_from_operations'],
            $month_keys,
            [$sheet['bncm']],
            [$ga['total']]
        );
        $this->_combine_profit_and_loss_parts(
            $sheet['net_income'],
            $month_keys,
            [$sheet['income_from_operations']],
            [$sheet['nopex']]
        );
        $this->_sum_profit_and_loss_parts(
            $sheet['nida'],
            [$sheet['net_income'], $boc['depreciation'], $lab_cos['depreciation']],
            $month_keys
        );
    }

    /**
     * P&L per branch summary: Mactan, Consolacion, Oakridge, Mobile, total, percent of net revenues.
     *
     * @param array<int, string> $month_keys
     */
    private function _build_profit_and_loss_per_branch_summary(
        string $date_from,
        string $date_to,
        int $year,
        array $month_keys,
        array $coa_rows,
        array $coa_by_id
    ): array {
        $clinics = [
            3 => 'mactan',
            4 => 'consolacion',
            2 => 'oakridge',
            1 => 'mobile',
            7 => 'mantlewood',
            0 => 'default',
        ];

        $summary = $this->_empty_profit_and_loss_per_branch($date_from, $date_to);

        foreach ($clinics as $id => $column) {
            $sheet = $this->_build_profit_and_loss_sheet(
                $date_from,
                $date_to,
                $id,
                [$id],
                $year,
                $month_keys,
                $coa_rows,
                $coa_by_id,
                false
            );
            $amounts = $this->_profit_and_loss_summary_amounts($sheet);
            foreach ($amounts as $path => $value) {
                $this->_set_pnl_summary_value($summary, $path, $column, (float) $value);
            }

            $census = ['total_census' => 0, 'konsulta_census' => 0];
            if ($id !== 0 && $this->resolve_branch_models($id)) {
                $census = $this->booking->get_profit_and_loss_census($date_from, $date_to);
            }
            $this->_set_pnl_summary_value($summary, 'total_census', $column, (float) $census['total_census']);
            $this->_set_pnl_summary_value($summary, 'konsulta_census', $column, (float) $census['konsulta_census']);
            $this->_set_pnl_summary_value(
                $summary,
                'census_excl_konsulta',
                $column,
                (float) ($census['total_census'] - $census['konsulta_census'])
            );
        }

        $this->_finalize_profit_and_loss_per_branch_summary($summary);

        return $summary;
    }

    private function _empty_profit_and_loss_per_branch(string $date_from, string $date_to): array
    {
        $cell = static function (bool $metric = false) {
            return [
                'mactan'       => 0.0,
                'consolacion'  => 0.0,
                'oakridge'     => 0.0,
                'mobile'       => 0.0,
                'mantlewood'   => 0.0,
                'default'      => 0.0,
                'total'        => 0.0,
                'percent'      => $metric ? null : 0.0,
            ];
        };

        return [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'total_census'          => $cell(true),
            'konsulta_census'       => $cell(true),
            'census_excl_konsulta'  => $cell(true),
            'ats'                   => $cell(true),
            'sales_per_sqm'         => $cell(true),
            'sqm'                   => $cell(true),
            'gross_revenues' => [
                'laboratory_diagnostics'  => $cell(),
                'new_projects'            => $cell(),
                'yakap_fpe'               => $cell(),
                'health_services'         => $cell(),
                'consultations'           => $cell(),
                'third_party_partnership' => $cell(),
                'total_gross_revenue'     => $cell(),
                'discounts'               => $cell(),
            ],
            'net_revenues' => $cell(),
            'cost_of_sales' => [
                'laboratory_diagnostics' => $cell(),
                'direct_materials'              => $cell(),
                'professional_fees_reader_fees' => $cell(),
                'outsourced_staff_and_services' => $cell(),
                'send_out_costs'                => $cell(),
                'depreciation'                  => $cell(),
                'new_projects'                  => $cell(),
                'animal_bite'                   => $cell(),
                'clinicavax'                    => $cell(),
                'cos_konsulta'                  => $cell(),
                'optometry'                     => $cell(),
                'women_and_child_center'        => $cell(),
                'health_services'               => $cell(),
                'consultations'                 => $cell(),
                'third_party_partnership'       => $cell(),
                'total'                         => $cell(),
            ],
            'gross_margin' => $cell(),
            'branch_operating_costs' => [
                'manpower_cost'         => $cell(),
                'occupancy_cost'        => $cell(),
                'it_and_communications' => $cell(),
                'depreciation'          => $cell(),
                'other'                 => $cell(),
                'total'                 => $cell(),
            ],
            'bncm'   => $cell(),
            'bncmda' => $cell(),
        ];
    }

    /**
     * Flatten a detailed P&L sheet to summary line amounts.
     *
     * @return array<string, float>
     */
    private function _profit_and_loss_summary_amounts(array $sheet): array
    {
        $gr = $sheet['gross_revenues'];
        $cos = $sheet['cost_of_sales'];
        $lab_cos = $cos['laboratory_diagnostics'];
        $np_cos = $cos['new_projects'];
        $boc = $sheet['branch_operating_costs'];

        return [
            'gross_revenues.laboratory_diagnostics'           => (float) $gr['laboratory_diagnostics']['amount'],
            'gross_revenues.new_projects'                     => (float) $gr['new_projects']['amount'],
            'gross_revenues.yakap_fpe'                        => (float) $gr['yakap_fpe']['amount'],
            'gross_revenues.health_services'                  => (float) $gr['health_services']['amount'],
            'gross_revenues.consultations'                    => (float) $gr['consultations']['amount'],
            'gross_revenues.third_party_partnership'          => (float) $gr['third_party_partnership']['amount'],
            'gross_revenues.total_gross_revenue'              => $this->_pnl_node_amount($gr['total_gross_revenue']),
            'gross_revenues.discounts'                        => (float) $gr['discounts']['amount'],
            'net_revenues'                                    => $this->_pnl_node_amount($sheet['net_revenues']),
            'cost_of_sales.laboratory_diagnostics'            => (float) $lab_cos['amount'],
            'cost_of_sales.direct_materials'                  => (float) $lab_cos['direct_materials']['amount'],
            'cost_of_sales.professional_fees_reader_fees'     => (float) $lab_cos['professional_fees_reader_fees']['amount'],
            'cost_of_sales.outsourced_staff_and_services'     => (float) $lab_cos['outsourced_staff_and_services']['amount'],
            'cost_of_sales.send_out_costs'                    => (float) $lab_cos['send_out_cost']['amount'],
            'cost_of_sales.depreciation'                      => (float) $lab_cos['depreciation']['amount'],
            'cost_of_sales.new_projects'                      => (float) $np_cos['amount'],
            'cost_of_sales.animal_bite'                       => (float) $np_cos['animal_bite']['amount'],
            'cost_of_sales.clinicavax'                        => (float) $np_cos['clinicavax']['amount'],
            'cost_of_sales.cos_konsulta'                      => (float) $np_cos['yakap']['amount'],
            'cost_of_sales.optometry'                         => (float) $np_cos['optometry']['amount'],
            'cost_of_sales.women_and_child_center'            => (float) $np_cos['women_and_child_center']['amount'],
            'cost_of_sales.health_services'                   => (float) $cos['health_services']['amount'],
            'cost_of_sales.consultations'                     => (float) $cos['consultations']['amount'],
            'cost_of_sales.third_party_partnership'           => (float) $cos['third_party_partnership']['amount'],
            'cost_of_sales.total'                             => $this->_pnl_node_amount($cos['total']),
            'gross_margin'                                    => $this->_pnl_node_amount($sheet['gross_margin']),
            'branch_operating_costs.manpower_cost'            => (float) $boc['manpower_cost']['amount'],
            'branch_operating_costs.occupancy_cost'           => (float) $boc['occupancy_cost']['amount'],
            'branch_operating_costs.it_and_communications'    => (float) $boc['it_and_communications']['amount'],
            'branch_operating_costs.depreciation'             => (float) $boc['depreciation']['amount'],
            'branch_operating_costs.other'                    => (float) $boc['other_branch_operating_costs']['amount'],
            'branch_operating_costs.total'                    => $this->_pnl_node_amount($boc['total']),
            'bncm'                                            => $this->_pnl_node_amount($sheet['bncm']),
            'bncmda'                                          => $this->_pnl_node_amount($sheet['bcmda']),
        ];
    }

    private function _set_pnl_summary_value(array &$summary, string $path, string $column, float $value): void
    {
        $ref =& $summary;
        foreach (explode('.', $path) as $segment) {
            $ref =& $ref[$segment];
        }
        $ref[$column] = round($value, 4);
    }

    private function _finalize_profit_and_loss_per_branch_summary(array &$summary): void
    {
        $columns = ['mactan', 'consolacion', 'oakridge', 'mobile', 'mantlewood', 'default'];
        $this->_walk_pnl_summary_cells($summary, function (array &$cell, bool $is_metric) use ($columns) {
            $total = 0.0;
            foreach ($columns as $column) {
                $total += (float) ($cell[$column] ?? 0);
            }
            $cell['total'] = round($total, 4);
        });

        $net_total = (float) $summary['net_revenues']['total'];
        $this->_walk_pnl_summary_cells($summary, function (array &$cell, bool $is_metric) use ($net_total) {
            if ($is_metric) {
                $cell['percent'] = null;
                return;
            }
            $cell['percent'] = $net_total == 0.0 ? 0.0 : round(((float) $cell['total']) / $net_total, 6);
        });

        foreach (array_merge($columns, ['total']) as $column) {
            $census_excl = (float) $summary['census_excl_konsulta'][$column];
            $net_rev = (float) $summary['net_revenues'][$column];
            $sqm = (float) $summary['sqm'][$column];
            $summary['ats'][$column] = $census_excl == 0.0 ? 0.0 : round($net_rev / $census_excl, 4);
            $summary['sales_per_sqm'][$column] = $sqm == 0.0 ? 0.0 : round($net_rev / $sqm, 4);
        }
        $summary['ats']['percent'] = null;
        $summary['sales_per_sqm']['percent'] = null;
        $summary['sqm']['percent'] = null;
    }

    /**
     * @param callable(array, bool): void $callback
     */
    private function _walk_pnl_summary_cells(array &$summary, callable $callback): void
    {
        $metric_keys = ['total_census', 'konsulta_census', 'census_excl_konsulta', 'ats', 'sales_per_sqm', 'sqm'];

        foreach ($summary as $key => &$value) {
            if (! is_array($value)) {
                continue;
            }
            if (array_key_exists('mactan', $value)) {
                $callback($value, in_array($key, $metric_keys, true));
                continue;
            }
            foreach ($value as &$child) {
                if (is_array($child) && array_key_exists('mactan', $child)) {
                    $callback($child, false);
                }
            }
            unset($child);
        }
        unset($value);
    }

    /**
     * @return array<int,string>
     */
    private function _aged_payables_branch_map(): array
    {
        return $this->aged_payables_branch_groups ??= (new \App\Libraries\Fund_cost_center())->groups();
    }

    private function _ap_money($value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    /**
     * Resolve patient display names using the same branch-aware helper as the APV list.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function _fill_aged_payables_patient_names(array $rows): array
    {
        $cache = [];
        $branchMap = $this->_aged_payables_branch_map();
        foreach ($rows as $i => $row) {
            if (($row['supplier_type'] ?? '') !== 'patient') {
                continue;
            }
            $branchId = (int) ($row['branch_id'] ?? 0);
            $patientId = (int) ($row['supplier_id'] ?? 0);
            $key = $branchId . ':' . $patientId;
            if (!array_key_exists($key, $cache)) {
                $cache[$key] = null;
                if ($patientId > 0 && isset($branchMap[$branchId])) {
                    $patient = new \App\Models\Patient_diagnostic(db_connect($branchMap[$branchId]));
                    $record = $patient->get_patient_name($patientId);
                    $cache[$key] = $record ? ($record->patient_name ?? null) : null;
                }
            }
            $rows[$i]['supplier_name'] = $cache[$key];
        }

        return $rows;
    }

    /**
     * Replace RFP fallback with child invoice_no values from the APV's branch DB.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function _fill_aged_payables_invoice_references(array $rows): array
    {
        $cache = [];
        $types = ['reimbursement', 'replenishment', 'liquidation'];

        foreach ($rows as $i => $row) {
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            if (! in_array($type, $types, true)) {
                continue;
            }

            $typeId   = (int) ($row['type_id'] ?? 0);
            $branchId = (int) ($row['branch_id'] ?? 0);
            if ($typeId <= 0) {
                continue;
            }

            $cacheKey = $branchId . ':' . $type . ':' . $typeId;
            if (! array_key_exists($cacheKey, $cache)) {
                if (!$this->_resolve_aged_payables_invoice_models($branchId)) {
                    $cache[$cacheKey] = [];
                } else {
                    $cache[$cacheKey] = $this->_aged_payables_child_invoice_nos($type, $typeId);
                }
            }

            if ($cache[$cacheKey]) {
                $rows[$i]['invoice_reference'] = implode(', ', $cache[$cacheKey]);
            }
        }
        
        return $rows;
    }

    /** Resolve only aged-payable invoice models; leave unrelated report routing intact. */
    private function _resolve_aged_payables_invoice_models(int $branchId): bool
    {
        $map = $this->_aged_payables_branch_map();
        if (!isset($map[$branchId])) { return false; }
        $db = db_connect($map[$branchId]);
        foreach (['reimbursement_item', 'replenishment_item', 'liquidation_detail'] as $property) {
            $class = 'App\\Models\\' . ucfirst($property);
            $this->$property = new $class($db);
            if ($map[$branchId] === 'master') {
                $this->$property->with_shared_cost_centers([$branchId]);
            }
        }
        return true;
    }

    /** @return array<int,string> */
    private function _aged_payables_child_invoice_nos(string $type, int $typeId): array
    {
        if ($type === 'reimbursement') {
            $items = $this->reimbursement_item->get_details($typeId) ?: [];
        } elseif ($type === 'replenishment') {
            $items = $this->replenishment_item->get_details($typeId) ?: [];
        } else {
            $items = $this->liquidation_detail->get_details($typeId) ?: [];
        }

        $invoices = [];
        foreach ($items as $item) {
            $invoiceNo = trim((string) ($item['invoice_no'] ?? ''));
            if ($invoiceNo !== '' && ! in_array($invoiceNo, $invoices, true)) {
                $invoices[] = $invoiceNo;
            }
        }

        return $invoices;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function _group_aged_payables_by_supplier(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = ($row['supplier_type'] ?? '') . ':' . ($row['supplier_id'] ?? '');
            if (($row['supplier_type'] ?? '') === 'patient') {
                $key .= ':' . ($row['branch_id'] ?? '');
            }
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'supplier_id'       => isset($row['supplier_id']) ? (int) $row['supplier_id'] : null,
                    'supplier_name'     => $row['supplier_name'] ?? null,
                    'supplier_type'     => $row['supplier_type'] ?? null,
                    'category'          => $row['supplier_category'] ?? null,
                    'current'           => 0.0,
                    '1_month'           => 0.0,
                    '2_months'          => 0.0,
                    '3_months'          => 0.0,
                    'older'             => 0.0,
                    'total'             => 0.0,
                    'items'             => [],
                ];
            }

            $current  = (float) ($row['current'] ?? 0);
            $one      = (float) ($row['1_month'] ?? 0);
            $two      = (float) ($row['2_months'] ?? 0);
            $three    = (float) ($row['3_months'] ?? 0);
            $older    = (float) ($row['older'] ?? 0);
            $lineTotal = $current + $one + $two + $three + $older;

            $groups[$key]['current']   += $current;
            $groups[$key]['1_month']   += $one;
            $groups[$key]['2_months']  += $two;
            $groups[$key]['3_months']  += $three;
            $groups[$key]['older']     += $older;
            $groups[$key]['total']     += $lineTotal;
            $groups[$key]['items'][] = [
                'id'                 => isset($row['id']) ? (int) $row['id'] : null,
                'type'               => $row['type'] ?? null,
                'type_id'            => isset($row['type_id']) ? (int) $row['type_id'] : null,
                'apv_date'           => $row['apv_date'] ?? null,
                'remarks'            => $row['remarks'] ?? null,
                'due_date'           => $row['due_date'] ?? null,
                'invoice_reference'  => $row['invoice_reference'] ?? null,
                'current'            => $this->_ap_money($current),
                '1_month'            => $this->_ap_money($one),
                '2_months'           => $this->_ap_money($two),
                '3_months'           => $this->_ap_money($three),
                'older'              => $this->_ap_money($older),
                'total'              => $this->_ap_money($lineTotal),
                'branch_id'          => isset($row['branch_id']) ? (int) $row['branch_id'] : null,
                'branch_name'        => $row['branch_name'] ?? null,
            ];
        }

        return $groups;
    }

    /**
     * @param array<string,float> $totals
     * @return array<string,string>
     */
    private function _aged_payables_percentages(array $totals): array
    {
        $grand = (float) ($totals['total'] ?? 0);
        $keys  = ['current', '1_month', '2_months', '3_months', 'older', 'total'];
        $out   = [];
        foreach ($keys as $key) {
            $out[$key] = $grand == 0.0 ? $this->_ap_money(0) : number_format(((float) $totals[$key]) / $grand, 6, '.', '');
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function _build_aged_payables_detail(array $rows, string $as_of_date): array
    {
        $groups = $this->_group_aged_payables_by_supplier($rows);
        $suppliers = [];
        $totals = [
            'current'  => 0.0,
            '1_month'  => 0.0,
            '2_months' => 0.0,
            '3_months' => 0.0,
            'older'    => 0.0,
            'total'    => 0.0,
        ];

        foreach ($groups as $group) {
            $totals['current']  += $group['current'];
            $totals['1_month']  += $group['1_month'];
            $totals['2_months'] += $group['2_months'];
            $totals['3_months'] += $group['3_months'];
            $totals['older']    += $group['older'];
            $totals['total']    += $group['total'];

            $suppliers[] = [
                'supplier_id'   => $group['supplier_id'],
                'supplier_name' => $group['supplier_name'],
                'supplier_type' => $group['supplier_type'],
                'category'      => $group['category'],
                'current'       => $this->_ap_money($group['current']),
                '1_month'       => $this->_ap_money($group['1_month']),
                '2_months'      => $this->_ap_money($group['2_months']),
                '3_months'      => $this->_ap_money($group['3_months']),
                'older'         => $this->_ap_money($group['older']),
                'total'         => $this->_ap_money($group['total']),
                'items'         => $group['items'],
            ];
        }

        return [
            'tab'                 => 'detail',
            'as_of'               => $as_of_date,
            'ageing_by'           => 'due_date',
            'suppliers'           => $suppliers,
            'totals'              => [
                'current'  => $this->_ap_money($totals['current']),
                '1_month'  => $this->_ap_money($totals['1_month']),
                '2_months' => $this->_ap_money($totals['2_months']),
                '3_months' => $this->_ap_money($totals['3_months']),
                'older'    => $this->_ap_money($totals['older']),
                'total'    => $this->_ap_money($totals['total']),
            ],
            'percentage_of_total' => $this->_aged_payables_percentages($totals),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function _build_aged_payables_summary(array $rows, string $as_of_date): array
    {
        $groups = $this->_group_aged_payables_by_supplier($rows);
        $suppliers = [];
        $totals = [
            'current'  => 0.0,
            '1_month'  => 0.0,
            '2_months' => 0.0,
            '3_months' => 0.0,
            'older'    => 0.0,
            'total'    => 0.0,
        ];

        foreach ($groups as $group) {
            if ((float) $group['total'] == 0.0) {
                continue;
            }

            $totals['current']  += $group['current'];
            $totals['1_month']  += $group['1_month'];
            $totals['2_months'] += $group['2_months'];
            $totals['3_months'] += $group['3_months'];
            $totals['older']    += $group['older'];
            $totals['total']    += $group['total'];

            $category = trim((string) ($group['category'] ?? ''));
            $suppliers[] = [
                'supplier_id'         => $group['supplier_id'],
                'supplier_type'       => $group['supplier_type'],
                'contact'             => $group['supplier_name'],
                'suppliers_category'  => $category !== '' ? $category : 'Uncategorized',
                'current'             => $this->_ap_money($group['current']),
                '1_month'             => $this->_ap_money($group['1_month']),
                '2_months'            => $this->_ap_money($group['2_months']),
                '3_months'            => $this->_ap_money($group['3_months']),
                'older'               => $this->_ap_money($group['older']),
                'total'               => $this->_ap_money($group['total']),
            ];
        }

        return [
            'tab'                 => 'summary',
            'as_of'               => $as_of_date,
            'ageing_by'           => 'due_date',
            'suppliers'           => $suppliers,
            'totals'              => [
                'current'  => $this->_ap_money($totals['current']),
                '1_month'  => $this->_ap_money($totals['1_month']),
                '2_months' => $this->_ap_money($totals['2_months']),
                '3_months' => $this->_ap_money($totals['3_months']),
                'older'    => $this->_ap_money($totals['older']),
                'total'    => $this->_ap_money($totals['total']),
            ],
            'percentage_of_total' => $this->_aged_payables_percentages($totals),
        ];
    }

    /**
     * @return array<int,array{group:string,subgroup:string}>
     */
    private function _aged_payable_analysis_groups(): array
    {
        return [
            ['group' => 'Direct Materials', 'subgroup' => 'Trade'],
            ['group' => 'Clinic Supplies', 'subgroup' => 'Trade'],
            ['group' => 'Office Supplies', 'subgroup' => 'Trade'],
            ['group' => 'Send Outs', 'subgroup' => 'Trade'],
            ['group' => 'Cost of Konsulta', 'subgroup' => 'Trade'],
            ['group' => 'Third Party', 'subgroup' => 'Trade'],
            ['group' => 'Occupancy', 'subgroup' => 'Non Trade'],
            ['group' => 'Security and Janitorial', 'subgroup' => 'Non Trade'],
            ['group' => 'Internet and Communications', 'subgroup' => 'Non Trade'],
            ['group' => "Doctor's PF", 'subgroup' => 'Non Trade'],
            ['group' => 'Reliever', 'subgroup' => 'Non Trade'],
            ['group' => 'Retainer', 'subgroup' => 'Non Trade'],
            ['group' => 'Patient Meals', 'subgroup' => 'Non Trade'],
            ['group' => 'Laundry', 'subgroup' => 'Non Trade'],
            ['group' => 'Cash Advances', 'subgroup' => 'Non Trade'],
            ['group' => 'Repairs', 'subgroup' => 'Non Trade'],
            ['group' => 'Rice Procurement', 'subgroup' => 'Non Trade'],
            ['group' => 'Utilities', 'subgroup' => 'Non Trade'],
            ['group' => 'Pest Control', 'subgroup' => 'Non Trade'],
            ['group' => 'Repairs and Maintenance', 'subgroup' => 'Non Trade'],
            ['group' => 'HMO', 'subgroup' => 'Non Trade'],
            ['group' => 'Replenishment', 'subgroup' => 'Non Trade'],
            ['group' => 'Gasoline', 'subgroup' => 'Non Trade'],
            ['group' => 'Other Professional Fee', 'subgroup' => 'Non Trade'],
            ['group' => 'CAPEX', 'subgroup' => 'CAPEX'],
            ['group' => 'RPT', 'subgroup' => 'Non Trade'],
            ['group' => 'At Risk', 'subgroup' => 'At Risk'],
            ['group' => 'Last and Final Pay', 'subgroup' => 'Payroll'],
            ['group' => 'Statutory', 'subgroup' => 'Statutory'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{group:string,subgroup:string}
     */
    private function _map_aged_payable_group(array $row): array
    {
        $type = strtolower((string) ($row['type'] ?? ''));
        if ($type === 'cash_advance') {
            return ['group' => 'Cash Advances', 'subgroup' => 'Non Trade'];
        }
        if ($type === 'replenishment') {
            return ['group' => 'Replenishment', 'subgroup' => 'Non Trade'];
        }

        $category = trim((string) ($row['supplier_category'] ?? ''));
        foreach ($this->_aged_payable_analysis_groups() as $def) {
            if ($category !== '' && strcasecmp($def['group'], $category) === 0) {
                return $def;
            }
        }

        return ['group' => 'Other Professional Fee', 'subgroup' => 'Non Trade'];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function _build_aged_payables_analysis(array $rows, string $as_of_date): array
    {
        $defs = $this->_aged_payable_analysis_groups();
        $buckets = [];
        foreach ($defs as $def) {
            $buckets[$def['group']] = [
                'group'          => $def['group'],
                'subgroup'       => $def['subgroup'],
                '1_month'        => 0.0,
                '2_months'       => 0.0,
                '3_months'       => 0.0,
                'over_3_months'  => 0.0,
                'total'          => 0.0,
            ];
        }

        foreach ($rows as $row) {
            $mapped = $this->_map_aged_payable_group($row);
            $key    = $mapped['group'];
            if (! isset($buckets[$key])) {
                $key = 'Other Professional Fee';
            }

            // Analysis has no separate current column: include it in the first bucket.
            $one   = (float) ($row['current'] ?? 0) + (float) ($row['1_month'] ?? 0);
            $two   = (float) ($row['2_months'] ?? 0);
            $three = (float) ($row['3_months'] ?? 0);
            $older = (float) ($row['older'] ?? 0);
            $line  = $one + $two + $three + $older;

            $buckets[$key]['1_month']       += $one;
            $buckets[$key]['2_months']      += $two;
            $buckets[$key]['3_months']      += $three;
            $buckets[$key]['over_3_months'] += $older;
            $buckets[$key]['total']         += $line;
        }

        $groups = [];
        $totals = [
            '1_month'       => 0.0,
            '2_months'      => 0.0,
            '3_months'      => 0.0,
            'over_3_months' => 0.0,
            'total'         => 0.0,
        ];
        $subgroupTotals = [
            'Trade'     => 0.0,
            'Non Trade' => 0.0,
            'CAPEX'     => 0.0,
            'At Risk'   => 0.0,
            'Statutory' => 0.0,
            'Payroll'   => 0.0,
        ];

        foreach ($defs as $def) {
            $bucket = $buckets[$def['group']];
            $totals['1_month']       += $bucket['1_month'];
            $totals['2_months']      += $bucket['2_months'];
            $totals['3_months']      += $bucket['3_months'];
            $totals['over_3_months'] += $bucket['over_3_months'];
            $totals['total']         += $bucket['total'];
            if (isset($subgroupTotals[$bucket['subgroup']])) {
                $subgroupTotals[$bucket['subgroup']] += $bucket['total'];
            }

            $groups[] = [
                'group'         => $bucket['group'],
                'subgroup'      => $bucket['subgroup'],
                '1_month'       => $this->_ap_money($bucket['1_month']),
                '2_months'      => $this->_ap_money($bucket['2_months']),
                '3_months'      => $this->_ap_money($bucket['3_months']),
                'over_3_months' => $this->_ap_money($bucket['over_3_months']),
                'total'         => $this->_ap_money($bucket['total']),
            ];
        }

        $grand = (float) $totals['total'];
        $rollup = [];
        foreach ($subgroupTotals as $name => $amount) {
            $rollup[] = [
                'group'   => $name,
                'total'   => $this->_ap_money($amount),
                'percent' => $grand == 0.0 ? $this->_ap_money(0) : number_format($amount / $grand, 6, '.', ''),
            ];
        }

        return [
            'tab'             => 'analysis',
            'as_of'           => $as_of_date,
            'ageing_by'       => 'due_date',
            'groups'          => $groups,
            'subgroup_totals' => $rollup,
            'totals'          => [
                '1_month'       => $this->_ap_money($totals['1_month']),
                '2_months'      => $this->_ap_money($totals['2_months']),
                '3_months'      => $this->_ap_money($totals['3_months']),
                'over_3_months' => $this->_ap_money($totals['over_3_months']),
                'total'         => $this->_ap_money($totals['total']),
            ],
        ];
    }

    /**
     * Truncate and re-seed clinic + master `bank` from CASH IN BANK account types.
     * Destructive. Does not truncate bank_service_fee (those bank_id values may become stale).
     */
    public function insert_bank()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $account_types = $this->_cash_in_bank_account_types();
        $banks = $this->_map_banks_from_account_types($account_types);
        if (! $banks) {
            return $this->_create_response(FAIL, ['No CASH IN BANK account types found.']);
        }

        $targets = [
            'master'       => 'default',
            'mobile'       => 'mobile',
            'oakridge'     => 'oakridge',
            'mactan'       => 'mactan',
            'consolacion'  => 'consolacion',
            'mantlewood'   => 'mantlewood',
        ];

        $truncated = [];
        $inserted = [];
        $warnings = [];

        foreach ($targets as $label => $group) {
            try {
                $db = db_connect($group);
                $result = $this->_seed_bank_table($db, $banks);
                $truncated[$label] = $result['truncated'];
                $inserted[$label] = $result['inserted'];
                if (! empty($result['warning'])) {
                    $warnings[$label] = $result['warning'];
                }
            } catch (\Throwable $e) {
                $truncated[$label] = 0;
                $inserted[$label] = [];
                $warnings[$label] = $e->getMessage();
            }
        }

        $response = [
            'truncated' => $truncated,
            'inserted'  => $inserted,
            'message'   => 'Bank table seeded from CASH IN BANK account types.',
        ];
        if ($warnings) {
            $response['warnings'] = $warnings;
            $response['message'] .= ' bank_service_fee.bank_id values may be stale after truncate.';
        } else {
            $response['message'] .= ' bank_service_fee was not truncated; bank_id values may be stale.';
        }

        return $this->_create_response(SUCCESS, $response);
    }

    /**
     * @return list<array{id:mixed,name:mixed}>
     */
    private function _cash_in_bank_account_types(): array
    {
        $sql = <<<EOT
SELECT account_type.id, account_type.name
FROM account_type
WHERE account_type.parent_id IS NULL
    AND account_type.is_deleted = 0
    AND account_type.name LIKE 'CASH IN BANK%'
ORDER BY account_type.id
EOT;
        $query = $this->db->query($sql);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * @param list<array{id:mixed,name:mixed}> $account_types
     * @return list<array{name:string,account_type_ids:string}>
     */
    private function _map_banks_from_account_types(array $account_types): array
    {
        $grouped = [];
        foreach ($account_types as $row) {
            $name = $this->_bank_name_from_account_type((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (! isset($grouped[$name])) {
                $grouped[$name] = [];
            }
            if (! in_array($id, $grouped[$name], true)) {
                $grouped[$name][] = $id;
            }
        }

        $banks = [];
        foreach ($grouped as $name => $ids) {
            $banks[] = [
                'name'             => $name,
                'account_type_ids' => implode(',', $ids),
            ];
        }

        return $banks;
    }

    private function _bank_name_from_account_type(string $account_name): string
    {
        $name = preg_replace('/^CASH IN BANK\s*-?\s*/i', '', $account_name) ?? '';
        $name = preg_replace('/#.*$/', '', $name) ?? $name;
        $name = preg_replace('/\s*-?\s*(OPEX|CAPEX)\s*$/i', '', $name) ?? $name;
        $name = preg_replace('/[\s\-]+$/', '', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);

        return $name;
    }

    /**
     * @param list<array{name:string,account_type_ids:string}> $banks
     * @return array{truncated:int,inserted:list<array>,warning:?string}
     */
    private function _seed_bank_table($db, array $banks): array
    {
        $exists = $db->query("SHOW TABLES LIKE 'bank'");
        if (! $exists || ! $exists->getResult()) {
            return [
                'truncated' => 0,
                'inserted'  => [],
                'warning'   => 'bank table not found',
            ];
        }

        $count_row = $db->query('SELECT COUNT(*) AS total FROM bank');
        $existing = (int) (($count_row ? $count_row->getRowArray()['total'] : 0) ?? 0);

        if ($existing > 0) {
            $db->query('SET FOREIGN_KEY_CHECKS=0');
            $db->query('TRUNCATE TABLE bank');
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }

        foreach ($banks as $bank) {
            $db->query(
                'INSERT INTO bank (name, account_type_ids, is_deleted) VALUES (?, ?, 0)',
                [$bank['name'], $bank['account_type_ids']]
            );
        }

        $inserted = $db->query('SELECT id, name, account_type_ids FROM bank WHERE is_deleted = 0 ORDER BY id');

        return [
            'truncated' => $existing,
            'inserted'  => $inserted ? $inserted->getResultArray() : [],
            'warning'   => $existing > 0 ? 'bank_service_fee.bank_id values may be stale' : null,
        ];
    }

}
