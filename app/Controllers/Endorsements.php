<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Endorsement;
use App\Models\Endorsement_detail;
use App\Models\Endorsement_service;
use App\Models\Endorsement_service_package_detail;
use App\Models\Endorsement_attachment;
use App\Models\Endorsement_signed_moa;
use App\Models\Endorsement_patient;
use App\Models\Endorsement_shift;
use App\Models\Endorsement_consultation;
use App\Models\Endorsement_revision;
use App\Models\Endorsement_history;
use App\Models\Endorsement_discount;
use App\Models\Endorsement_consulta;
use App\Models\Endorsement_employer;
use App\Models\Patient_master;
use App\Models\Patient_emr;
use App\Models\Patient_diagnostic;
use App\Models\Branch_master;
use App\Models\User;
use App\Models\Package;
use App\Models\Package_detail;
use App\Models\Quotation;
use App\Models\Quotation_service;
use App\Models\Quotation_service_package_detail;
use App\Models\Quotation_shift;
use App\Models\Quotation_consultation;
use App\Models\Costing;
use App\Models\Costing_service;
use App\Models\Costing_service_package_detail;
use App\Models\Costing_shift;
use App\Models\Costing_consultation;
use App\Models\Costing_discount;
use App\Models\Costing_consulta;
use App\Models\Request as Request_model;
use App\Models\Request_service;
use App\Models\Request_shift;
use App\Models\Request_consultation;
use App\Models\Company;

class Endorsements extends MYT_Controller
{
    protected $request;
    protected $endorsement;
    protected $endorsement_detail;
    protected $endorsement_service;
    protected $endorsement_service_package_detail;
    protected $endorsement_attachment;
    protected $endorsement_signed_moa;
    protected $endorsement_patient;
    protected $endorsement_shift;
    protected $endorsement_consultation;
    protected $endorsement_revision;
    protected $endorsement_history;
    protected $endorsement_discount;
    protected $endorsement_consulta;
    protected $endorsement_employer;
    protected $patient_master;
    protected $branch_master;
    protected $user;
    protected $package;
    protected $package_detail;
    protected $quotation;
    protected $quotation_service;
    protected $quotation_service_package_detail;
    protected $quotation_shift;
    protected $quotation_consultation;
    protected $costing;
    protected $costing_service;
    protected $costing_service_package_detail;
    protected $costing_shift;
    protected $costing_consultation;
    protected $costing_discount;
    protected $costing_consulta;
    protected $request_model;
    protected $request_service;
    protected $request_shift;
    protected $request_consultation;
    protected $company;
    protected $helpers = ['text', 'email_notification'];

    protected $billing;
    protected $billing_entry;
    protected $billing_payment;
    protected $billing_payment_entry;
    protected $booking;
    protected $booking_detail;
    protected $payment;

    protected $patient_emr;
    protected $patient_diagnostic;
    protected $patientBranchModels = [];

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
        $this->endorsement = new Endorsement();
        $this->endorsement_detail = new Endorsement_detail();
        $this->endorsement_service = new Endorsement_service();
        $this->endorsement_service_package_detail = new Endorsement_service_package_detail();
        $this->endorsement_attachment = new Endorsement_attachment();
        $this->endorsement_signed_moa = new Endorsement_signed_moa();
        $this->endorsement_patient = new Endorsement_patient();
        $this->endorsement_shift = new Endorsement_shift();
        $this->endorsement_consultation = new Endorsement_consultation();
        $this->endorsement_revision = new Endorsement_revision();
        $this->endorsement_history = new Endorsement_history();
        $this->endorsement_discount = new Endorsement_discount();
        $this->endorsement_consulta = new Endorsement_consulta();
        $this->endorsement_employer = new Endorsement_employer();
        $this->patient_master = new Patient_master();
        $this->branch_master = new Branch_master();
        $this->user = new User();
        $this->package = new Package();
        $this->package_detail = new Package_detail();
        $this->quotation = new Quotation();
        $this->quotation_service = new Quotation_service();
        $this->quotation_service_package_detail = new Quotation_service_package_detail();
        $this->quotation_shift = new Quotation_shift();
        $this->quotation_consultation = new Quotation_consultation();
        $this->costing = new Costing();
        $this->costing_service = new Costing_service();
        $this->costing_service_package_detail = new Costing_service_package_detail();
        $this->costing_shift = new Costing_shift();
        $this->costing_consultation = new Costing_consultation();
        $this->costing_discount = new Costing_discount();
        $this->costing_consulta = new Costing_consulta();
        $this->request_model = new Request_model();
        $this->request_service = new Request_service();
        $this->request_shift = new Request_shift();
        $this->request_consultation = new Request_consultation();
        $this->company = new Company();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $dbMap = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',

            10 => 'mobile_emr',
            12 => 'oakridge_emr',
            13 => 'mactan_emr',
            14 => 'consolacion_emr',
            17 => 'mantlewood_emr',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->billing = new \App\Models\Billing($db);
        $this->billing_entry = new \App\Models\Billing_entry($db);
        $this->billing_payment = new \App\Models\Billing_payment($db);
        $this->billing_payment_entry = new \App\Models\Billing_payment_entry($db);
        $this->booking = new \App\Models\Booking($db);
        $this->booking_detail = new \App\Models\Booking_detail($db);
        $this->payment = new \App\Models\Payment($db);

        $this->patient_emr = new \App\Models\Patient_emr($db);
        $this->patient_diagnostic = new \App\Models\Patient_diagnostic($db);

        return true;
    }

    /**
     * Get endorsements
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $transaction_type = $this->payload['transaction_type'] ?? null;
        if ($transaction_type !== null && !is_string($transaction_type)) {
            return $this->_create_response(FAIL, ['transaction_type must be a string.']);
        }

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        $request_added_by = (int) $user->role_id === 19
            ? (int) $user->id
            : null;

        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $is_pending_revision = $this->payload['is_pending_revision'] ?? null;
        $is_force_evaluate = $this->payload['is_force_evaluate'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $endorsements = $this->endorsement->get_details($endorsement_id, $status, $company_id, $branch_id, $is_pending_revision, $term, $page, $data_size, $is_force_evaluate, $request_added_by, $transaction_type) ?: [];
        $size = $this->endorsement->get_count($endorsement_id, $status, $company_id, $branch_id, $is_pending_revision, $term, $is_force_evaluate, $request_added_by, $transaction_type);

        if (!$endorsements) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No endorsements found.'
            ];
        } else {
            $endorsementIds = $this->_collect_ids($endorsements, 'id');
            $branchIds = $this->_collect_ids($endorsements, 'branch_id');
            $quotationIds = $this->_collect_ids($endorsements, 'quotation_id');

            $detailsByEndorsement = $this->_group_by($this->endorsement_detail->get_details($endorsementIds) ?: [], 'endorsement_id');
            $servicesByEndorsement = $this->_group_by($this->endorsement_service->get_details($endorsementIds) ?: [], 'endorsement_id');
            $discountsByEndorsement = $this->_group_by($this->endorsement_discount->get_details($endorsementIds) ?: [], 'endorsement_id');
            $shiftsByEndorsement = $this->_group_by($this->endorsement_shift->get_details($endorsementIds) ?: [], 'endorsement_id');
            $consultationsByEndorsement = $this->_group_by($this->endorsement_consultation->get_details($endorsementIds) ?: [], 'endorsement_id');
            $patientsByEndorsement = $this->_group_by($this->endorsement_patient->get_details($endorsementIds) ?: [], 'endorsement_id');
            $historiesByEndorsement = $this->_group_by($this->endorsement_history->get_details($endorsementIds) ?: [], 'source_id');
            $employersByEndorsement = $this->_group_by($this->endorsement_employer->get_details($endorsementIds) ?: [], 'endorsement_id');

            $patientIds = [];
            foreach ($detailsByEndorsement as $details) {
                foreach ($details as $detail) {
                    if (!empty($detail['patient_id'])) {
                        $patientIds[] = $detail['patient_id'];
                    }
                }
            }
            $patientNamesById = $this->patient_master->get_patient_names_by_ids(array_values(array_unique($patientIds)));
            $branchNamesById = $this->branch_master->get_names_by_ids($branchIds);

            $endorsementServiceIds = [];
            foreach ($servicesByEndorsement as $services) {
                foreach ($services as $service) {
                    if (($service['type'] ?? null) === 'package') {
                        $endorsementServiceIds[] = $service['id'];
                    }
                }
            }
            $pkgByService = $this->_group_by(
                $this->endorsement_service_package_detail->get_details(array_values(array_unique($endorsementServiceIds))) ?: [],
                'endorsement_service_id'
            );

            $costingIdByQuotation = [];
            if ($quotationIds) {
                $ph = implode(',', array_fill(0, count($quotationIds), '?'));
                $quotationQuery = $this->quotation->db->query(
                    "SELECT id, costing_id FROM quotation WHERE is_deleted = 0 AND id IN ($ph)",
                    $quotationIds
                );
                foreach (($quotationQuery ? $quotationQuery->getResultArray() : []) as $qRow) {
                    $costingIdByQuotation[$qRow['id']] = $qRow['costing_id'];
                }
            }
            $costingIds = array_values(array_unique(array_filter(array_values($costingIdByQuotation), static function ($v) {
                return $v !== null && $v !== '';
            })));
            $costingsById = [];
            if ($costingIds) {
                foreach ($this->costing->get_details($costingIds) ?: [] as $costingRow) {
                    $costingsById[$costingRow['id']] = $costingRow;
                }
            }
            $saeIds = array_values(array_unique(array_merge(
                $this->_collect_ids(array_values($costingsById), 'sae_in_charge_id'),
                $this->_collect_grouped_ids($historiesByEndorsement, 'added_by')
            )));
            $usersById = $this->user->get_by_ids($saeIds);
            $customerAccountIds = $this->_collect_ids(array_values($costingsById), 'customer_account_id');
            $customerAccountsById = [];
            if ($customerAccountIds) {
                foreach ($this->company->get_customer_accounts(implode(',', $customerAccountIds)) ?: [] as $account) {
                    $customerAccountsById[$account['company_id']][] = $account;
                }
            }

            $requirementsByEndorsementVersion = [];
            $signedMoasByEndorsement = [];
            if ($endorsementIds) {
                $ph = implode(',', array_fill(0, count($endorsementIds), '?'));
                $attachmentQuery = $this->endorsement_attachment->db->query(
                    "SELECT id, file_name, file_label, file_path, file_url, mime, endorsement_id, version_no
                     FROM endorsement_attachment
                     WHERE is_deleted = 0 AND endorsement_id IN ($ph)",
                    $endorsementIds
                );
                foreach (($attachmentQuery ? $attachmentQuery->getResultArray() : []) as $row) {
                    $requirementsByEndorsementVersion[$row['endorsement_id']][$row['version_no']][] = [
                        'id' => $row['id'],
                        'file_name' => $row['file_name'],
                        'file_label' => $row['file_label'],
                        'file_path' => $row['file_path'],
                        'file_url' => $row['file_url'],
                        'mime' => $row['mime'],
                    ];
                }

                $signedMoaQuery = $this->endorsement_signed_moa->db->query(
                    "SELECT id, file_name, file_path, file_url, mime, endorsement_id
                     FROM endorsement_signed_moa
                     WHERE is_deleted = 0 AND endorsement_id IN ($ph)",
                    $endorsementIds
                );
                foreach (($signedMoaQuery ? $signedMoaQuery->getResultArray() : []) as $row) {
                    $signedMoasByEndorsement[$row['endorsement_id']][] = [
                        'id' => $row['id'],
                        'file_name' => $row['file_name'],
                        'file_path' => $row['file_path'],
                        'file_url' => $row['file_url'],
                        'mime' => $row['mime'],
                    ];
                }
            }

            $transacted = [];
            $remaining = $endorsementIds;
            foreach ([1, 2, 3, 4, 7] as $branchDbId) {
                if (!$remaining) {
                    break;
                }
                if (!$this->resolve_branch_models($branchDbId)) {
                    continue;
                }
                $found = $this->booking_detail->get_endorsement_ids_with_bookings($remaining);
                foreach ($found as $eid) {
                    $transacted[$eid] = 1;
                }
                $remaining = array_values(array_diff($remaining, $found));
            }

            $revisionsByEndorsement = [];
            if ($is_pending_revision != null && $is_pending_revision == 1) {
                $revisionsByEndorsement = $this->_group_by(
                    $this->endorsement_revision->get_details($endorsementIds, null, 'pending') ?: [],
                    'endorsement_id'
                );
            }

            foreach ($endorsements as $key => $endorsement) {
                $eid = $endorsement['id'];

                $details = $detailsByEndorsement[$eid] ?? [];
                foreach ($details as $dkey => $detail) {
                    $details[$dkey]['patient_name'] = !empty($detail['patient_id'])
                        ? ($patientNamesById[$detail['patient_id']] ?? null)
                        : null;
                }
                $endorsements[$key]['details'] = $details;

                $employers = $employersByEndorsement[$eid] ?? [];
                $endorsements[$key]['employers'] = $employers;

                $endorsements[$key]['branch_name'] = isset($branchNamesById[$endorsement['branch_id']])
                    ? $branchNamesById[$endorsement['branch_id']]
                    : null;

                $services = $servicesByEndorsement[$eid] ?? [];
                foreach ($services as $c_key => $service) {
                    if (!empty($service['charge_to_ids_joined']) && !empty($service['charge_to_names'])) {
                        $ids = explode('|', $service['charge_to_ids_joined']);
                        $names = explode('|', $service['charge_to_names']);
                        $chargeToArray = [];
                        foreach ($ids as $index => $id) {
                            $chargeToArray[] = [
                                'id' => (int) $id,
                                'name' => $names[$index] ?? ''
                            ];
                        }
                        $services[$c_key]['charge_to_names'] = $chargeToArray;
                    } else {
                        $services[$c_key]['charge_to_names'] = [];
                    }
                    if (($service['type'] ?? null) === 'package') {
                        $services[$c_key]['details'] = $pkgByService[$service['id']] ?? [];
                    }
                }
                $endorsements[$key]['services'] = $services;
                $endorsements[$key]['discounts'] = $discountsByEndorsement[$eid] ?? [];

                $costings = [];
                $costingId = $costingIdByQuotation[$endorsement['quotation_id']] ?? null;
                if ($costingId && isset($costingsById[$costingId])) {
                    $costings = [$costingsById[$costingId]];
                    foreach ($costings as $c_key => $costing) {
                        if (!empty($costing['sae_in_charge_id']) && isset($usersById[$costing['sae_in_charge_id']])) {
                            $costings[$c_key]['sae_in_charge_name'] = $usersById[$costing['sae_in_charge_id']]['name'];
                        }
                        if (!empty($costing['customer_account_id'])) {
                            $costings[$c_key]['customer_account_details'] = $this->_customer_account_details($customerAccountsById, $costing['customer_account_id']);
                        }
                    }
                }
                $endorsements[$key]['costing'] = $costings;

                $endorsements[$key]['requirements'] = $requirementsByEndorsementVersion[$eid][$endorsement['version_no']] ?? [];
                $endorsements[$key]['signed_moas'] = $signedMoasByEndorsement[$eid] ?? [];
                $endorsements[$key]['shifts'] = $shiftsByEndorsement[$eid] ?? [];
                $endorsements[$key]['consultations'] = $consultationsByEndorsement[$eid] ?? [];
                $endorsements[$key]['patients'] = $patientsByEndorsement[$eid] ?? [];
                $endorsements[$key]['is_transacted'] = $transacted[$eid] ?? 0;

                if ($is_pending_revision != null && $is_pending_revision == 1) {
                    $revisions = $revisionsByEndorsement[$eid] ?? [];
                    foreach ($revisions as $r_key => $revision) {
                        $revisions[$r_key]['data_received'] = json_decode($revision['data_received'], true);
                        $revisions[$r_key]['requirements'] = $requirementsByEndorsementVersion[$revision['endorsement_id']][$revision['version_no']] ?? [];
                    }
                    $endorsements[$key]['revisions'] = $revisions;
                }
                $endorsements[$key]['histories'] = $historiesByEndorsement[$eid] ?? [];
                $endorsements[$key] = $this->_apply_reason_trails($endorsements[$key], $endorsements[$key]['histories'], $usersById);
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $endorsements
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get endorsements patients
     */
    public function get_patients()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $endorsement_patients = $this->endorsement_patient->get_patients($endorsement_id, $type, $term, $page, $data_size) ?: [];
        $size = count($this->endorsement_patient->get_patients($endorsement_id, $type, $term, null, null) ?: []);

        if (!$endorsement_patients) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No endorsement patients found.'
            ];
        } else {
            foreach ($endorsement_patients as $key => $endorsement_patient) {
                $endorsement_patients[$key]['services'] = $this->endorsement_patient->get_patient_services($endorsement_patient['endorsement_id'], $endorsement_patient['patient_id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $endorsement_patients
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get endorsements services
     */
    public function get_services()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        $type_id = $this->payload['type_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $endorsement_services = $this->endorsement_service->get_services($endorsement_id, $company_id, $type, $type_id, $page, $data_size) ?: [];
        $size = count($this->endorsement_service->get_services($endorsement_id, $company_id, $type, $type_id, null, null) ?: []);

        if (!$endorsement_services) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No endorsement services found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $endorsement_services
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get endorsements services
     */
    public function get_charge_to()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $charge_to      = $this->payload['charge_to'] ?? null;
        $company_id     = $this->payload['company_id'] ?? null;

        // if (!$endorsement_id) { removing this condition the endorsement_id will be optional
        //     $response_status = FAIL;
        //     $response = [
        //         'Endorsement ID is required.'
        //     ];
        //     return $this->_create_response($response_status, $response);
        // }

        $data = $this->endorsement_service->get_charge_to($endorsement_id, $charge_to, $company_id) ?: [];

        if (!$data) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No endorsement charge to found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'data' => $data
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create endorsement
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        if (!$quotation = $this->quotation->select('request_id, costing_id', ['id' => $this->payload['quotation_id'], 'is_deleted' => 0], 1) ?: null) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Quotation not found.'
            ];
        } elseif (!$endorsement_id = $this->_attempt_create($quotation)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement. ",
            ];
        } elseif (!$this->_attempt_create_details($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement details. ",
            ];
        } elseif (!$this->_attempt_create_services($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement services. ",
            ];
        } elseif (!$this->_attempt_create_discounts($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement discounts. ",
            ];
        } elseif (!$this->_attempt_create_consultas($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement consultas. ",
            ];
        } elseif (!$this->_attempt_create_shifts($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement shifts. ",
            ];
        } elseif (!$this->_attempt_create_consultations($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement consultations. ",
            ];
        } elseif (!$this->_attempt_create_employers($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement employers. ",
            ];
        } elseif (!$this->_attempt_create_patients($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add endorsement patients. ",
            ];
            // Switch true and false to make the attachment a mandatory field
        } elseif (($this->request->getFileMultiple('attachments') ? true : false) && !$this->_upload_attachments($endorsement_id, 1, 'assets/endorsements/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } elseif (!$this->send_email_notif('endorsement_for_approval', $endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send endorsement for approval email notification. ",
            ];
        } elseif (!$this->send_email_notif('request_moved_to_endorsement', $endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send endorsement for approval email notification. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'endorsement_id' => $endorsement_id,
                'message'    => 'endorsement added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Backfill request, costing, and quotation from endorsements encoded without those IDs.
     * Process a limited batch so the request does not 504.
     */
    public function auto_generate_request_costing_quotation()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $limit = (int) ($this->payload['limit'] ?? 10);
        if ($limit < 1) {
            $limit = 10;
        }
        if ($limit > 25) {
            $limit = 25;
        }

        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $endorsements = $this->endorsement->get_missing_request_costing_quotation($limit, $endorsement_id) ?: [];

        if (!$endorsements) {
            return $this->_create_response(SUCCESS, [
                'processed' => 0,
                'succeeded' => [],
                'failed' => [],
                'remaining' => $this->endorsement->count_missing_request_costing_quotation(),
                'message' => 'No endorsements need request, costing, or quotation generation.',
            ]);
        }

        $succeeded = [];
        $failed = [];

        foreach ($endorsements as $endorsement) {
            $this->db->transBegin();
            $result = $this->_attempt_generate_chain_from_endorsement($endorsement);
            if ($result === false) {
                $this->db->transRollback();
                $failed[] = [
                    'endorsement_id' => $endorsement['id'] ?? null,
                    'error' => $this->error_message ?: 'Failed to generate request, costing, or quotation.',
                ];
                continue;
            }

            $this->db->transCommit();
            $succeeded[] = $result;
        }

        return $this->_create_response(SUCCESS, [
            'processed' => count($endorsements),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'remaining' => $this->endorsement->count_missing_request_costing_quotation(),
            'message' => 'Generated request, costing, and quotation for ' . count($succeeded) . ' endorsement(s).',
        ]);
    }

    /**
     * Attempt to create a endorsement
     */
    private function _attempt_create($quotation)
    {
        $values = [
            'request_id'            => $quotation->request_id,
            'costing_id'            => $quotation->costing_id,
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach ($this->endorsement->allowedFields as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        // Manually add/modify the fields here


        if (!$endorsement_id = $this->endorsement->insert($values)) {
            $this->error_message = $this->endorsement->error();
            return false;
        }

        return $endorsement_id;
    }

    /**
     * Attempt to create endorsement details
     */
    private function _attempt_create_details($endorsement_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'patient_id'   => array_key_exists('patient_id', $detail) && $detail['patient_id'] != '' ? $detail['patient_id'] : null,
                'beneficiary_role'   => array_key_exists('beneficiary_role', $detail) && $detail['beneficiary_role'] != '' ? $detail['beneficiary_role'] : null,
                'assigned_to'   => array_key_exists('assigned_to', $detail) && $detail['assigned_to'] != '' ? $detail['assigned_to'] : null,
                'scheduled_date'   => array_key_exists('scheduled_date', $detail) && $detail['scheduled_date'] != '' ? $detail['scheduled_date'] : null,
                'remark'   => array_key_exists('remark', $detail) && $detail['remark'] != '' ? $detail['remark'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->endorsement_detail->insert($values)) {
                $this->error_message = $this->endorsement_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create endorsement employers
     */
    private function _attempt_create_employers($endorsement_id)
    {
        if (!array_key_exists('employers', $this->payload) || !is_array($this->payload['employers']) || count($this->payload['employers']) == 0) {
            return true;
        }

        foreach ($this->payload['employers'] as $employer) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'name'   => array_key_exists('name', $employer) && $employer['name'] != '' ? $employer['name'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->endorsement_employer->insert($values)) {
                $this->error_message = $this->endorsement_employer->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create endorsement services
     */
    private function _attempt_create_services($endorsement_id)
    {
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services']) || count($this->payload['services']) == 0) {
            return true;
        }

        $keyToId = [];
        $indexToId = [];
        foreach ($this->payload['services'] as $index => $service) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'type'              => array_key_exists('type', $service) && $service['type'] != '' ? $service['type'] : null,
                'type_id'           => array_key_exists('type_id', $service) && $service['type_id'] != '' ? $service['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $service) && $service['type_price'] != '' ? $service['type_price'] : null,
                'margin'         => array_key_exists('margin', $service) && $service['margin'] != '' ? $service['margin'] : 0,
                'cost'         => array_key_exists('cost', $service) && $service['cost'] != '' ? $service['cost'] : 0,
                'pro_rate_price'         => array_key_exists('pro_rate_price', $service) && $service['pro_rate_price'] != '' ? $service['pro_rate_price'] : 0,
                'discount'         => array_key_exists('discount', $service) && $service['discount'] != '' ? $service['discount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $service) && $service['branch_id'] != '' ? $service['branch_id'] : null,
                'charge_to'           => array_key_exists('charge_to', $service) && $service['charge_to'] != '' ? $service['charge_to'] : null,
                'charge_to_ids'           => array_key_exists('charge_to_ids', $service) && $service['charge_to_ids'] != '' ? $service['charge_to_ids'] : null,
                ...$this->_service_add_on_fields($service),
                'head_count'           => array_key_exists('head_count', $service) && $service['head_count'] != '' ? $service['head_count'] : null,
                'discount_amount'           => array_key_exists('discount_amount', $service) && $service['discount_amount'] != '' ? $service['discount_amount'] : null,
                'margin_amount'           => array_key_exists('margin_amount', $service) && $service['margin_amount'] != '' ? $service['margin_amount'] : null,
                'sales'           => array_key_exists('sales', $service) && $service['sales'] != '' ? $service['sales'] : null,
                'admin_fee'           => array_key_exists('admin_fee', $service) && $service['admin_fee'] != '' ? $service['admin_fee'] : null,
                'direct_cost'           => array_key_exists('direct_cost', $service) && $service['direct_cost'] != '' ? $service['direct_cost'] : null,
                'manpower_cost'           => array_key_exists('manpower_cost', $service) && $service['manpower_cost'] != '' ? $service['manpower_cost'] : null,
                'mobilization_cost'           => array_key_exists('mobilization_cost', $service) && $service['mobilization_cost'] != '' ? $service['mobilization_cost'] : null,
                'total_cost'           => array_key_exists('total_cost', $service) && $service['total_cost'] != '' ? $service['total_cost'] : null,
                'estimated_margin'          => array_key_exists('estimated_margin', $service) && $service['estimated_margin'] != '' ? $service['estimated_margin'] : null,
                'direct_cost_amount_total'          => array_key_exists('direct_cost_amount_total', $service) && $service['direct_cost_amount_total'] != '' ? $service['direct_cost_amount_total'] : null,
                'manpower_cost_amount_total'          => array_key_exists('manpower_cost_amount_total', $service) && $service['manpower_cost_amount_total'] != '' ? $service['manpower_cost_amount_total'] : null,
                'mobilization_cost_amount_total'          => array_key_exists('mobilization_cost_amount_total', $service) && $service['mobilization_cost_amount_total'] != '' ? $service['mobilization_cost_amount_total'] : null,
                'total_cost_amount_total'          => array_key_exists('total_cost_amount_total', $service) && $service['total_cost_amount_total'] != '' ? $service['total_cost_amount_total'] : null,
                'estimated_margin_amount_total'          => array_key_exists('estimated_margin_amount_total', $service) && $service['estimated_margin_amount_total'] != '' ? $service['estimated_margin_amount_total'] : null,
                'estimated_revenue'          => array_key_exists('estimated_revenue', $service) && $service['estimated_revenue'] != '' ? $service['estimated_revenue'] : null,
                'pre_negotiated_rate'          => array_key_exists('pre_negotiated_rate', $service) && $service['pre_negotiated_rate'] != '' ? $service['pre_negotiated_rate'] : null,
                'is_guaranteed'          => array_key_exists('is_guaranteed', $service) && $service['is_guaranteed'] != '' ? $service['is_guaranteed'] : 0,
                'guaranteed_headcount'          => array_key_exists('guaranteed_headcount', $service) && $service['guaranteed_headcount'] != '' ? $service['guaranteed_headcount'] : null,
                'theoretical_cost'          => array_key_exists('theoretical_cost', $service) && $service['theoretical_cost'] != '' ? $service['theoretical_cost'] : null,
                'total_amount'    => array_key_exists('total_amount', $service) && $service['total_amount'] != '' ? $service['total_amount'] : 0,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];

            if (!$endorsement_service_id = $this->endorsement_service->insert($values)) {
                $this->error_message = $this->endorsement_service->error();
                return false;
            }
            $indexToId[$index] = (string) $endorsement_service_id;
            service_line_register_ids($keyToId, $service, $endorsement_service_id);

            $service_package_details = array_key_exists('details', $service) && is_array($service['details']) ? $service['details'] : [];

            if (count($service_package_details) > 0) {
                if (!$this->_attempt_create_service_package_details($endorsement_service_id, $endorsement_id, $service_package_details)) {
                    return false;
                }
            }
        }

        return $this->_rewrite_service_line_package_ids($this->endorsement_service, $this->payload['services'], $keyToId, $indexToId);
    }

    /**
     * Attempt to create endorsement discounts
     */
    private function _attempt_create_discounts($endorsement_id)
    {
        if (!array_key_exists('discounts', $this->payload) || !is_array($this->payload['discounts']) || count($this->payload['discounts']) == 0) {
            return true;
        }

        foreach ($this->payload['discounts'] as $discount) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'type'              => array_key_exists('type', $discount) && $discount['type'] != '' ? $discount['type'] : null,
                'type_id'           => array_key_exists('type_id', $discount) && $discount['type_id'] != '' ? $discount['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $discount) && $discount['type_price'] != '' ? $discount['type_price'] : null,
                'discount'         => array_key_exists('discount', $discount) && $discount['discount'] != '' ? $discount['discount'] : 0,
                'discount_amount'         => array_key_exists('discount_amount', $discount) && $discount['discount_amount'] != '' ? $discount['discount_amount'] : 0,
                'total_amount'    => array_key_exists('total_amount', $discount) && $discount['total_amount'] != '' ? $discount['total_amount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $discount) && $discount['branch_id'] != '' ? $discount['branch_id'] : null,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];

            if (!$this->endorsement_discount->insert($values)) {
                $this->error_message = $this->endorsement_discount->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create endorsement consultas
     */
    private function _attempt_create_consultas($endorsement_id)
    {
        if (!array_key_exists('consultas', $this->payload) || !is_array($this->payload['consultas']) || count($this->payload['consultas']) == 0) {
            return true;
        }

        foreach ($this->payload['consultas'] as $consulta) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'type'              => array_key_exists('type', $consulta) && $consulta['type'] != '' ? $consulta['type'] : null,
                'type_id'           => array_key_exists('type_id', $consulta) && $consulta['type_id'] != '' ? $consulta['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $consulta) && $consulta['type_price'] != '' ? $consulta['type_price'] : null,
                'mark_up'         => array_key_exists('mark_up', $consulta) && $consulta['mark_up'] != '' ? $consulta['mark_up'] : 0,
                'mark_up_amount'         => array_key_exists('mark_up_amount', $consulta) && $consulta['mark_up_amount'] != '' ? $consulta['mark_up_amount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $consulta) && $consulta['branch_id'] != '' ? $consulta['branch_id'] : null,
                'head_count'  => consulta_head_count($consulta),
                'total_amount'    => array_key_exists('total_amount', $consulta) && $consulta['total_amount'] != '' ? $consulta['total_amount'] : 0,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];

            if (!$this->endorsement_consulta->insert($values)) {
                $this->error_message = $this->endorsement_consulta->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create endorsement service package details
     */
    private function _attempt_create_service_package_details($endorsement_service_id, $endorsement_id, $service_package_details)
    {
        foreach ($service_package_details as $service_package_detail) {
            $values = [
                'endorsement_service_id'    => $endorsement_service_id,
                'endorsement_id'    => $endorsement_id,
                'package_id'              => array_key_exists('package_id', $service_package_detail) && $service_package_detail['package_id'] != '' ? $service_package_detail['package_id'] : null,
                'lab_test_id'           => array_key_exists('lab_test_id', $service_package_detail) && $service_package_detail['lab_test_id'] != '' ? $service_package_detail['lab_test_id'] : null,
                'is_add_on'           => array_key_exists('is_add_on', $service_package_detail) && $service_package_detail['is_add_on'] != '' ? $service_package_detail['is_add_on'] : 0,
                'price'           => array_key_exists('price', $service_package_detail) && $service_package_detail['price'] != '' ? $service_package_detail['price'] : null,
                'margin'         => array_key_exists('margin', $service_package_detail) && $service_package_detail['margin'] != '' ? $service_package_detail['margin'] : 0,
                'cost'         => array_key_exists('cost', $service_package_detail) && $service_package_detail['cost'] != '' ? $service_package_detail['cost'] : 0,
                'pro_rate_price'         => array_key_exists('pro_rate_price', $service_package_detail) && $service_package_detail['pro_rate_price'] != '' ? $service_package_detail['pro_rate_price'] : 0,
                'discount'         => array_key_exists('discount', $service_package_detail) && $service_package_detail['discount'] != '' ? $service_package_detail['discount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $service_package_detail) && $service_package_detail['branch_id'] != '' ? $service_package_detail['branch_id'] : null,
                'charge_to'           => array_key_exists('charge_to', $service_package_detail) && $service_package_detail['charge_to'] != '' ? $service_package_detail['charge_to'] : null,
                'head_count'           => array_key_exists('head_count', $service_package_detail) && $service_package_detail['head_count'] != '' ? $service_package_detail['head_count'] : null,
                'discount_amount'           => array_key_exists('discount_amount', $service_package_detail) && $service_package_detail['discount_amount'] != '' ? $service_package_detail['discount_amount'] : null,
                'margin_amount'           => array_key_exists('margin_amount', $service_package_detail) && $service_package_detail['margin_amount'] != '' ? $service_package_detail['margin_amount'] : null,
                'sales'           => array_key_exists('sales', $service_package_detail) && $service_package_detail['sales'] != '' ? $service_package_detail['sales'] : null,
                'admin_fee'           => array_key_exists('admin_fee', $service_package_detail) && $service_package_detail['admin_fee'] != '' ? $service_package_detail['admin_fee'] : null,
                'direct_cost'           => array_key_exists('direct_cost', $service_package_detail) && $service_package_detail['direct_cost'] != '' ? $service_package_detail['direct_cost'] : null,
                'manpower_cost'           => array_key_exists('manpower_cost', $service_package_detail) && $service_package_detail['manpower_cost'] != '' ? $service_package_detail['manpower_cost'] : null,
                'mobilization_cost'           => array_key_exists('mobilization_cost', $service_package_detail) && $service_package_detail['mobilization_cost'] != '' ? $service_package_detail['mobilization_cost'] : null,
                'total_cost'           => array_key_exists('total_cost', $service_package_detail) && $service_package_detail['total_cost'] != '' ? $service_package_detail['total_cost'] : null,
                'estimated_margin'          => array_key_exists('estimated_margin', $service_package_detail) && $service_package_detail['estimated_margin'] != '' ? $service_package_detail['estimated_margin'] : null,
                'direct_cost_amount_total'          => array_key_exists('direct_cost_amount_total', $service_package_detail) && $service_package_detail['direct_cost_amount_total'] != '' ? $service_package_detail['direct_cost_amount_total'] : null,
                'manpower_cost_amount_total'          => array_key_exists('manpower_cost_amount_total', $service_package_detail) && $service_package_detail['manpower_cost_amount_total'] != '' ? $service_package_detail['manpower_cost_amount_total'] : null,
                'mobilization_cost_amount_total'          => array_key_exists('mobilization_cost_amount_total', $service_package_detail) && $service_package_detail['mobilization_cost_amount_total'] != '' ? $service_package_detail['mobilization_cost_amount_total'] : null,
                'total_cost_amount_total'          => array_key_exists('total_cost_amount_total', $service_package_detail) && $service_package_detail['total_cost_amount_total'] != '' ? $service_package_detail['total_cost_amount_total'] : null,
                'estimated_margin_amount_total'          => array_key_exists('estimated_margin_amount_total', $service_package_detail) && $service_package_detail['estimated_margin_amount_total'] != '' ? $service_package_detail['estimated_margin_amount_total'] : null,
                'estimated_revenue'          => array_key_exists('estimated_revenue', $service_package_detail) && $service_package_detail['estimated_revenue'] != '' ? $service_package_detail['estimated_revenue'] : null,
                'pre_negotiated_rate'          => array_key_exists('pre_negotiated_rate', $service_package_detail) && $service_package_detail['pre_negotiated_rate'] != '' ? $service_package_detail['pre_negotiated_rate'] : null,
                'theoretical_cost'          => array_key_exists('theoretical_cost', $service_package_detail) && $service_package_detail['theoretical_cost'] != '' ? $service_package_detail['theoretical_cost'] : null,
                'total_amount'    => array_key_exists('total_amount', $service_package_detail) && $service_package_detail['total_amount'] != '' ? $service_package_detail['total_amount'] : 0,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];

            if (!$this->endorsement_service_package_detail->insert($values)) {
                $this->error_message = $this->endorsement_service_package_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create endorsement shifts
     */
    private function _attempt_create_shifts($endorsement_id)
    {
        if (!array_key_exists('shifts', $this->payload) || !is_array($this->payload['shifts']) || count($this->payload['shifts']) == 0) {
            return true;
        }

        foreach ($this->payload['shifts'] as $shift) {
            $values = [
                'endorsement_id' => $endorsement_id,
                'name' => array_key_exists('name', $shift) && $shift['name'] != '' ? $shift['name'] : null,
                'start_shift' => array_key_exists('start_shift', $shift) && $shift['start_shift'] != '' ? $shift['start_shift'] : null,
                'end_shift' => array_key_exists('end_shift', $shift) && $shift['end_shift'] != '' ? $shift['end_shift'] : null,
                'registration_cut_off' => array_key_exists('registration_cut_off', $shift) && $shift['registration_cut_off'] != '' ? $shift['registration_cut_off'] : null,
                'specimen_submission_cut_off' => array_key_exists('specimen_submission_cut_off', $shift) && $shift['specimen_submission_cut_off'] != '' ? $shift['specimen_submission_cut_off'] : null,
                'total_hours' => array_key_exists('total_hours', $shift) && $shift['total_hours'] != '' ? $shift['total_hours'] : 0,
                'break' => array_key_exists('break', $shift) && $shift['break'] != '' ? $shift['break'] : null,
                'ingress' => array_key_exists('ingress', $shift) && $shift['ingress'] != '' ? $shift['ingress'] : null,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];

            if (!$this->endorsement_shift->insert($values)) {
                $this->error_message = $this->endorsement_shift->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create endorsement consultations
     */
    private function _attempt_create_consultations($endorsement_id)
    {
        if (!array_key_exists('consultations', $this->payload) || !is_array($this->payload['consultations']) || count($this->payload['consultations']) == 0) {
            return true;
        }

        foreach ($this->payload['consultations'] as $consultation) {
            $values = [
                'endorsement_id' => $endorsement_id,
                'company_id' => array_key_exists('company_id', $consultation) && $consultation['company_id'] != '' ? $consultation['company_id'] : null,
                'specialization_id' => array_key_exists('specialization_id', $consultation) && $consultation['specialization_id'] != '' ? $consultation['specialization_id'] : null,
                'fee_amount' => array_key_exists('fee_amount', $consultation) && $consultation['fee_amount'] != '' ? $consultation['fee_amount'] : 0,
                'doctor_share' => array_key_exists('doctor_share', $consultation) && $consultation['doctor_share'] != '' ? $consultation['doctor_share'] : 0,
                'doctor_share_amount' => array_key_exists('doctor_share_amount', $consultation) && $consultation['doctor_share_amount'] != '' ? $consultation['doctor_share_amount'] : 0,
                'cpci_share' => array_key_exists('cpci_share', $consultation) && $consultation['cpci_share'] !== '' && $consultation['cpci_share'] !== null ? $consultation['cpci_share'] : 0,
                // 'discount_doctor_share' => array_key_exists('discount_doctor_share', $consultation) && $consultation['discount_doctor_share'] !== '' && $consultation['discount_doctor_share'] !== null ? $consultation['discount_doctor_share'] : 0,
                // 'discount_cpci_share' => array_key_exists('discount_cpci_share', $consultation) && $consultation['discount_cpci_share'] !== '' && $consultation['discount_cpci_share'] !== null ? $consultation['discount_cpci_share'] : 0,
                'ahmopi_fee' => array_key_exists('ahmopi_fee', $consultation) && $consultation['ahmopi_fee'] != '' ? $consultation['ahmopi_fee'] : 0,
                'pahmoc_fee' => array_key_exists('pahmoc_fee', $consultation) && $consultation['pahmoc_fee'] != '' ? $consultation['pahmoc_fee'] : 0,
                'charge_to' => array_key_exists('charge_to', $consultation) && $consultation['charge_to'] != '' ? $consultation['charge_to'] : 'company',
                'charge_to_ids' => array_key_exists('company_id', $consultation) && $consultation['company_id'] != '' ? $consultation['company_id'] : null,
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s'),
            ];

            if (!$this->endorsement_consultation->insert($values)) {
                $this->error_message = $this->endorsement_consultation->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create endorsement patients
     */
    private function _attempt_create_patients($endorsement_id)
    {
        if (!array_key_exists('patients', $this->payload) || !is_array($this->payload['patients']) || count($this->payload['patients']) == 0) {
            return true;
        }

        foreach ($this->payload['patients'] as $patient) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'patient_id'              => array_key_exists('patient_id', $patient) && $patient['patient_id'] != '' ? $patient['patient_id'] : null,
                'type'              => array_key_exists('type', $patient) && $patient['type'] != '' ? $patient['type'] : null,
                'type_id'           => array_key_exists('type_id', $patient) && $patient['type_id'] != '' ? $patient['type_id'] : null,
                'with_consultation'           => array_key_exists('with_consultation', $patient) && $patient['with_consultation'] != '' ? $patient['with_consultation'] : 0,
                'type_price'           => array_key_exists('type_price', $patient) && $patient['type_price'] != '' ? $patient['type_price'] : 0,
                'mark_up'           => array_key_exists('mark_up', $patient) && $patient['mark_up'] != '' ? $patient['mark_up'] : 0,
                'total_amount'           => array_key_exists('total_amount', $patient) && $patient['total_amount'] != '' ? $patient['total_amount'] : 0,
                'charge_to'           => array_key_exists('charge_to', $patient) && $patient['charge_to'] != '' ? $patient['charge_to'] : null,
                'charge_to_ids'           => array_key_exists('charge_to_ids', $patient) && $patient['charge_to_ids'] != '' ? $patient['charge_to_ids'] : null,
                'branch_id'           => array_key_exists('branch_id', $patient) && $patient['branch_id'] != '' ? $patient['branch_id'] : null,
                'mobile_van_id'           => array_key_exists('mobile_van_id', $patient) && $patient['mobile_van_id'] != '' ? $patient['mobile_van_id'] : null,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];

            if (!$this->endorsement_patient->insert($values)) {
                $this->error_message = $this->endorsement_patient->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update endorsement
     */
    public function revise()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $endorsement_id = $this->payload['endorsement_id'];
        $where = [
            'id' => $endorsement_id,
            'is_deleted' => 0
        ];

        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        if (!$endorsement = $this->endorsement->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'endorsement is not found.'
            ];
            //validate if there is a pending revision for the endorsement
        } elseif ($endorsement->is_pending_revision == 1) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'There is already a pending revision for this endorsement. Please resolve the pending revision before saving a new revision. '
            ];
        } elseif (!$this->_attempt_create_revisions($endorsement)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to submit endorsement revision. ",
            ];
            // Attachments is deleted if no file is uploaded
        } elseif (!$this->_upload_attachments($endorsement_id, $endorsement->version_no + 1, 'assets/endorsements/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } elseif (!$this->send_email_notif('endorsement_revision_for_approval', $endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send endorsement revision for approval email notification. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'endorsement_id' => $endorsement_id,
                'message'    => 'endorsement submitted for revision.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt create revisions
     */
    protected function _attempt_create_revisions($endorsement)
    {
        $values = [
            'endorsement_id'    => $endorsement->id,
            'version_no'        => $endorsement->version_no + 1,
            'data_received'     => json_encode($this->payload),
            'added_by'          => $this->payload['requester'],
            'added_on'          => date('Y-m-d H:i:s')
        ];

        if (!$this->endorsement_revision->insert($values)) {
            $this->error_message = $this->endorsement_revision->error();
            return false;
        }

        if (!$this->endorsement->update(['id' => $endorsement->id], ['is_pending_revision' => 1])) {
            $this->error_message = $this->endorsement->error();
            return false;
        }
        return true;
    }

    /**
     * Revise change status endorsement
     */
    public function revise_change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $endorsement_id = $this->payload['endorsement_id'];
        $where = [
            'id' => $endorsement_id,
            'is_pending_revision' => 1,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        if (!$endorsement = $this->endorsement->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'endorsement is not found or endorsement is not pending for revision.'
            ];
        } elseif (!$endorsement_revision = $this->endorsement_revision->select('', ['endorsement_id' => $endorsement_id, 'version_no' => $endorsement->version_no + 1, 'status' => 'pending', 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Revision record not found. Please save a revision before changing endorsement status. '
            ];
            //validate status
        } elseif ($status && $endorsement_revision->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Revision is already $status. ",
            ];
        } elseif (!$this->_attempt_update_endorsement($endorsement_revision, $status)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update endorsement revision.'
            ];
        } elseif (!$this->_attempt_update_revision_status($endorsement_revision, $status)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change revision status.'
            ];
        } elseif ($status == 'approved' && !$this->send_email_notif('endorsement_revision_approved', $endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send endorsement revision approved email notification. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'endorsement_id' => $endorsement_id,
                'message'    => "endorsement revision $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update endorsement revision
     */
    protected function _attempt_update_endorsement($endorsement_revision, $status)
    {
        if ($status != 'approved') {
            return true;
        }

        //call the update function to update the endorsement with the data(data_recieved column) from the revision
        $payload_backup = $this->payload;
        $this->payload = json_decode($endorsement_revision->data_received, true);
        $this->payload['requester'] = $payload_backup['requester'];
        $this->payload['token'] = $payload_backup['token'];
        $update_response = $this->update();
        $this->payload = $payload_backup;
        if ($update_response->getStatusCode() != 200) {
            $this->error_message = 'Failed to update endorsement with revision data. ' . $update_response->getBody();
            return false;
        }

        //update endorsement's version_no to the revision's version_no
        $values = [
            'is_pending_revision' => 0,
            'version_no' => $endorsement_revision->version_no
        ];
        $where = ['id' => $endorsement_revision->endorsement_id];
        if (!$this->endorsement->update($where, $values)) {
            $this->error_message = $this->endorsement->error();
            return false;
        }


        return true;
    }

    /**
     * Attempt update revision status
     */
    protected function _attempt_update_revision_status($endorsement_revision, $status)
    {
        $values = [
            'status'        => $status,
            'approved_by'    => $status == 'approved' ? $this->payload['requester'] : null,
            'approved_on'    => $status == 'approved' ? date('Y-m-d H:i:s') : null,
            'disapproved_by' => $status == 'disapproved' ? $this->payload['requester'] : null,
            'disapproved_on' => $status == 'disapproved' ? date('Y-m-d H:i:s') : null,
            'returned_by'    => $status == 'returned' ? $this->payload['requester'] : null,
            'returned_on'    => $status == 'returned' ? date('Y-m-d H:i:s') : null,
            'updated_by'    => $this->payload['requester'],
            'updated_on'    => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $endorsement_revision->id];

        if (!$this->endorsement_revision->update($where, $values)) {
            $this->error_message = $this->endorsement_revision->error();
            return false;
        }

        if ($status == 'disapproved' || $status == 'returned') {
            // If disapproved/returned, also update endorsement to not pending revision
            $endorsement_values = [
                'is_pending_revision' => 0
            ];
            $endorsement_where = ['id' => $endorsement_revision->endorsement_id];
            if (!$this->endorsement->update($endorsement_where, $endorsement_values)) {
                $this->error_message = $this->endorsement->error();
                return false;
            }

            //delete the attachments uploaded for the revision since the revision is disapproved/returned
            if (!$this->endorsement_attachment->update(['endorsement_id' => $endorsement_revision->endorsement_id, 'version_no' => $endorsement_revision->version_no], ['is_deleted' => 1, 'updated_by' => $this->payload['requester'], 'updated_on' => date('Y-m-d H:i:s')])) {
                $this->error_message = $this->endorsement_attachment->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update mobile van ids of endorsement
     */
    public function update_mobile_van_ids()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $endorsement_id = $this->payload['endorsement_id'];
        $where = [
            'id' => $endorsement_id,
            'is_deleted' => 0
        ];

        $mobile_van_ids = $this->payload['mobile_van_ids'] ?? null;

        $this->db->transBegin();


        if (!$mobile_van_ids) {
            $response_status = FAIL;
            $response = [
                'Mobile van ids is required.'
            ];
        } elseif (!$endorsement = $this->endorsement->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'endorsement is not found.'
            ];
        } elseif (!$this->_attempt_update_mobile_van($endorsement, $mobile_van_ids)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update endorsement.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'endorsement_id' => $endorsement_id,
                'message'    => 'endorsement updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update_mobile_van($endorsement, $mobile_van_ids)
    {
        $values = [
            'mobile_van_ids'        => $mobile_van_ids,
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $endorsement->id];

        if (!$this->endorsement->update($where, $values)) {
            $this->error_message = $this->endorsement->error();
            return false;
        }
        return true;
    }

    /**
     * Update endorsement
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $endorsement_id = $this->payload['endorsement_id'];
        $where = [
            'id' => $endorsement_id,
            'is_deleted' => 0
        ];

        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        if (!$endorsement = $this->endorsement->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'endorsement is not found.'
            ];
        } elseif (!$this->_attempt_update($endorsement)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update endorsement.',
            ];
        } elseif (!$this->_attempt_update_consultations($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update endorsement consultations. ",
            ];
        } elseif (!$this->_attempt_update_employers($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update endorsement employers. ",
            ];
            // } elseif (!$this->_attempt_update_details($endorsement->id)) {
            //     $this->db->transRollback();
            //     $response_status = FAIL;
            //     $response = [
            //         "Failed to update endorsement details. ",
            //     ];
            // } elseif (!$this->_attempt_update_services($endorsement_id)) {
            //     $this->db->transRollback();
            //     $response_status = FAIL;
            //     $response = [
            //         "Failed to update endorsement services. ",
            //     ];
            // } elseif (!$this->_attempt_update_discounts($endorsement_id)) {
            //     $this->db->transRollback();
            //     $response_status = FAIL;
            //     $response = [
            //         "Failed to update endorsement discounts. ",
            //     ];
            // } elseif (!$this->_attempt_update_consultas($endorsement_id)) {
            //     $this->db->transRollback();
            //     $response_status = FAIL;
            //     $response = [
            //         "Failed to update endorsement consultas. ",
            //     ];
            // } elseif (!$this->_attempt_update_shifts($endorsement_id)) {
            //     $this->db->transRollback();
            //     $response_status = FAIL;
            //     $response = [
            //         "Failed to update endorsement shifts. ",
            //     ];
            // } elseif (!$this->_attempt_update_patients($endorsement_id)) {
            //     $this->db->transRollback();
            //     $response_status = FAIL;
            //     $response = [
            //         "Failed to update endorsement patients. ",
            //     ];
            // Attachments is deleted if no file is uploaded
            // } elseif(!$this->_upload_attachments($endorsement_id, 'assets/endorsements/')) {
            //     $this->db->transRollback();
            //     $response_status = FAIL;
            //     $response = [
            //         'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            //     ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'endorsement_id' => $endorsement_id,
                'message'    => 'endorsement updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($endorsement)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->endorsement->allowedFields is declared at the top of the model
        foreach ($this->endorsement->allowedFields as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $endorsement->id];

        if (!$this->endorsement->update($where, $values)) {
            $this->error_message = $this->endorsement->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update endorsement details
     */
    private function _attempt_update_details($endorsement_id)
    {
        // 1. Get all current detail IDs for this endorsement
        $existing_details = $this->endorsement_detail->select('id', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_details, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('details', $this->payload) && is_array($this->payload['details'])) {
            foreach ($this->payload['details'] as $detail) {
                if (!empty($detail['id'])) {
                    $payload_ids[] = (int)$detail['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_detail->error();
                    return false;
                }
            }
        }

        // 4. Process payload details (update existing or insert new)
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details'])) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'endorsement_id'  => $endorsement_id,
                'patient_id'   => array_key_exists('patient_id', $detail) && $detail['patient_id'] != '' ? $detail['patient_id'] : null,
                'beneficiary_role'   => array_key_exists('beneficiary_role', $detail) && $detail['beneficiary_role'] != '' ? $detail['beneficiary_role'] : null,
                'assigned_to'   => array_key_exists('assigned_to', $detail) && $detail['assigned_to'] != '' ? $detail['assigned_to'] : null,
                'scheduled_date'   => array_key_exists('scheduled_date', $detail) && $detail['scheduled_date'] != '' ? $detail['scheduled_date'] : null,
                'remark'   => array_key_exists('remark', $detail) && $detail['remark'] != '' ? $detail['remark'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_detail->update(['id' => $detail['id']], $values)) {
                    $this->error_message = $this->endorsement_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_detail->insert($values)) {
                    $this->error_message = $this->endorsement_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update endorsement employers
     */
    private function _attempt_update_employers($endorsement_id)
    {
        // 1. Get all current employer IDs for this endorsement
        $existing_employers = $this->endorsement_employer->select('id', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_employers, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('employers', $this->payload) && is_array($this->payload['employers'])) {
            foreach ($this->payload['employers'] as $employer) {
                if (!empty($employer['id'])) {
                    $payload_ids[] = (int)$employer['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_employer->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_employer->error();
                    return false;
                }
            }
        }

        // 4. Process payload employers (update existing or insert new)
        if (!array_key_exists('employers', $this->payload) || !is_array($this->payload['employers'])) {
            return true;
        }

        foreach ($this->payload['employers'] as $employer) {
            $values = [
                'endorsement_id'  => $endorsement_id,
                'name'   => array_key_exists('name', $employer) && $employer['name'] != '' ? $employer['name'] : null,
            ];

            if (!empty($employer['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_employer->update(['id' => $employer['id']], $values)) {
                    $this->error_message = $this->endorsement_employer->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_employer->insert($values)) {
                    $this->error_message = $this->endorsement_employer->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update endorsement details
     */
    private function _attempt_update_services($endorsement_id)
    {
        // 1. Get all current service IDs for this endorsement
        $existing_services = $this->endorsement_service->select('id', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_services, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('services', $this->payload) && is_array($this->payload['services'])) {
            foreach ($this->payload['services'] as $service) {
                if (!empty($service['id'])) {
                    $payload_ids[] = (int)$service['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_service->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_service->error();
                    return false;
                }
            }
        }

        // 4. Process payload services (update existing or insert new)
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services'])) {
            return true;
        }

        $keyToId = [];
        $indexToId = [];
        foreach ($this->payload['services'] as $index => $service) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'type'              => array_key_exists('type', $service) && $service['type'] != '' ? $service['type'] : null,
                'type_id'           => array_key_exists('type_id', $service) && $service['type_id'] != '' ? $service['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $service) && $service['type_price'] != '' ? $service['type_price'] : null,
                'margin'         => array_key_exists('margin', $service) && $service['margin'] != '' ? $service['margin'] : 0,
                'cost'         => array_key_exists('cost', $service) && $service['cost'] != '' ? $service['cost'] : 0,
                'pro_rate_price'         => array_key_exists('pro_rate_price', $service) && $service['pro_rate_price'] != '' ? $service['pro_rate_price'] : 0,
                'discount'         => array_key_exists('discount', $service) && $service['discount'] != '' ? $service['discount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $service) && $service['branch_id'] != '' ? $service['branch_id'] : null,
                'charge_to'           => array_key_exists('charge_to', $service) && $service['charge_to'] != '' ? $service['charge_to'] : null,
                'charge_to_ids'           => array_key_exists('charge_to_ids', $service) && $service['charge_to_ids'] != '' ? $service['charge_to_ids'] : null,
                ...$this->_service_add_on_fields($service),
                'head_count'           => array_key_exists('head_count', $service) && $service['head_count'] != '' ? $service['head_count'] : null,
                'discount_amount'           => array_key_exists('discount_amount', $service) && $service['discount_amount'] != '' ? $service['discount_amount'] : null,
                'margin_amount'           => array_key_exists('margin_amount', $service) && $service['margin_amount'] != '' ? $service['margin_amount'] : null,
                'sales'           => array_key_exists('sales', $service) && $service['sales'] != '' ? $service['sales'] : null,
                'admin_fee'           => array_key_exists('admin_fee', $service) && $service['admin_fee'] != '' ? $service['admin_fee'] : null,
                'direct_cost'           => array_key_exists('direct_cost', $service) && $service['direct_cost'] != '' ? $service['direct_cost'] : null,
                'manpower_cost'           => array_key_exists('manpower_cost', $service) && $service['manpower_cost'] != '' ? $service['manpower_cost'] : null,
                'mobilization_cost'           => array_key_exists('mobilization_cost', $service) && $service['mobilization_cost'] != '' ? $service['mobilization_cost'] : null,
                'total_cost'           => array_key_exists('total_cost', $service) && $service['total_cost'] != '' ? $service['total_cost'] : null,
                'estimated_margin'          => array_key_exists('estimated_margin', $service) && $service['estimated_margin'] != '' ? $service['estimated_margin'] : null,
                'direct_cost_amount_total'          => array_key_exists('direct_cost_amount_total', $service) && $service['direct_cost_amount_total'] != '' ? $service['direct_cost_amount_total'] : null,
                'manpower_cost_amount_total'          => array_key_exists('manpower_cost_amount_total', $service) && $service['manpower_cost_amount_total'] != '' ? $service['manpower_cost_amount_total'] : null,
                'mobilization_cost_amount_total'          => array_key_exists('mobilization_cost_amount_total', $service) && $service['mobilization_cost_amount_total'] != '' ? $service['mobilization_cost_amount_total'] : null,
                'total_cost_amount_total'          => array_key_exists('total_cost_amount_total', $service) && $service['total_cost_amount_total'] != '' ? $service['total_cost_amount_total'] : null,
                'estimated_margin_amount_total'          => array_key_exists('estimated_margin_amount_total', $service) && $service['estimated_margin_amount_total'] != '' ? $service['estimated_margin_amount_total'] : null,
                'estimated_revenue'          => array_key_exists('estimated_revenue', $service) && $service['estimated_revenue'] != '' ? $service['estimated_revenue'] : null,
                'pre_negotiated_rate'          => array_key_exists('pre_negotiated_rate', $service) && $service['pre_negotiated_rate'] != '' ? $service['pre_negotiated_rate'] : null,
                'is_guaranteed'          => array_key_exists('is_guaranteed', $service) && $service['is_guaranteed'] != '' ? $service['is_guaranteed'] : 0,
                'guaranteed_headcount'          => array_key_exists('guaranteed_headcount', $service) && $service['guaranteed_headcount'] != '' ? $service['guaranteed_headcount'] : null,
                'theoretical_cost'          => array_key_exists('theoretical_cost', $service) && $service['theoretical_cost'] != '' ? $service['theoretical_cost'] : null,
                'total_amount'    => array_key_exists('total_amount', $service) && $service['total_amount'] != '' ? $service['total_amount'] : 0,
            ];

            if (!empty($service['id'])) {
                $endorsement_service_id = $service['id'];
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_service->update(['id' => $service['id']], $values)) {
                    $this->error_message = $this->endorsement_service->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$endorsement_service_id = $this->endorsement_service->insert($values)) {
                    $this->error_message = $this->endorsement_service->error();
                    return false;
                }
            }
            $indexToId[$index] = (string) $endorsement_service_id;
            service_line_register_ids($keyToId, $service, $endorsement_service_id);

            $service_package_details = array_key_exists('details', $service) && is_array($service['details']) ? $service['details'] : [];

            if (count($service_package_details) > 0) {
                if (!$this->_attempt_update_service_package_details($endorsement_service_id, $endorsement_id, $service_package_details)) {
                    return false;
                }
            }
        }

        return $this->_rewrite_service_line_package_ids($this->endorsement_service, $this->payload['services'], $keyToId, $indexToId);
    }

    /**
     * Attempt to update endorsement discounts
     */
    private function _attempt_update_discounts($endorsement_id)
    {
        // 1. Get all current discount IDs for this endorsement
        $existing_discounts = $this->endorsement_discount->select('id', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_discounts, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('discounts', $this->payload) && is_array($this->payload['discounts'])) {
            foreach ($this->payload['discounts'] as $discount) {
                if (!empty($discount['id'])) {
                    $payload_ids[] = (int)$discount['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_discount->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_discount->error();
                    return false;
                }
            }
        }

        // 4. Process payload discounts (update existing or insert new)
        if (!array_key_exists('discounts', $this->payload) || !is_array($this->payload['discounts'])) {
            return true;
        }

        foreach ($this->payload['discounts'] as $discount) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'type'              => array_key_exists('type', $discount) && $discount['type'] != '' ? $discount['type'] : null,
                'type_id'           => array_key_exists('type_id', $discount) && $discount['type_id'] != '' ? $discount['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $discount) && $discount['type_price'] != '' ? $discount['type_price'] : null,
                'discount'         => array_key_exists('discount', $discount) && $discount['discount'] != '' ? $discount['discount'] : 0,
                'discount_amount'         => array_key_exists('discount_amount', $discount) && $discount['discount_amount'] != '' ? $discount['discount_amount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $discount) && $discount['branch_id'] != '' ? $discount['branch_id'] : null,
                'total_amount'    => array_key_exists('total_amount', $discount) && $discount['total_amount'] != '' ? $discount['total_amount'] : 0,
            ];

            if (!empty($discount['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_discount->update(['id' => $discount['id']], $values)) {
                    $this->error_message = $this->endorsement_discount->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_discount->insert($values)) {
                    $this->error_message = $this->endorsement_discount->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update endorsement consultas
     */
    private function _attempt_update_consultas($endorsement_id)
    {
        // 1. Get all current consulta IDs for this endorsement
        $existing_consultas = $this->endorsement_consulta->select('id', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_consultas, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('consultas', $this->payload) && is_array($this->payload['consultas'])) {
            foreach ($this->payload['consultas'] as $consulta) {
                if (!empty($consulta['id'])) {
                    $payload_ids[] = (int)$consulta['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_consulta->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_consulta->error();
                    return false;
                }
            }
        }

        // 4. Process payload consultas (update existing or insert new)
        if (!array_key_exists('consultas', $this->payload) || !is_array($this->payload['consultas'])) {
            return true;
        }

        foreach ($this->payload['consultas'] as $consulta) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'type'              => array_key_exists('type', $consulta) && $consulta['type'] != '' ? $consulta['type'] : null,
                'type_id'           => array_key_exists('type_id', $consulta) && $consulta['type_id'] != '' ? $consulta['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $consulta) && $consulta['type_price'] != '' ? $consulta['type_price'] : null,
                'mark_up'         => array_key_exists('mark_up', $consulta) && $consulta['mark_up'] != '' ? $consulta['mark_up'] : 0,
                'mark_up_amount'         => array_key_exists('mark_up_amount', $consulta) && $consulta['mark_up_amount'] != '' ? $consulta['mark_up_amount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $consulta) && $consulta['branch_id'] != '' ? $consulta['branch_id'] : null,
                'head_count'  => consulta_head_count($consulta),
                'total_amount'    => array_key_exists('total_amount', $consulta) && $consulta['total_amount'] != '' ? $consulta['total_amount'] : 0,
            ];

            if (!empty($consulta['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_consulta->update(['id' => $consulta['id']], $values)) {
                    $this->error_message = $this->endorsement_consulta->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_consulta->insert($values)) {
                    $this->error_message = $this->endorsement_consulta->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update endorsement service package details
     */
    private function _attempt_update_service_package_details($endorsement_service_id, $endorsement_id, $service_package_details)
    {
        // 1. Get all current service_package_details IDs for this endorsement
        $existing_service_package_details = $this->endorsement_service_package_detail->select('id', ['endorsement_service_id' => $endorsement_service_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_service_package_details, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (is_array($service_package_details)) {
            foreach ($service_package_details as $service_package_detail) {
                if (!empty($service_package_detail['id'])) {
                    $payload_ids[] = (int)$service_package_detail['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_service_package_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_service_package_detail->error();
                    return false;
                }
            }
        }

        // 4. Process payload service_package_details (update existing or insert new)
        if (!is_array($service_package_details)) {
            return true;
        }

        foreach ($service_package_details as $service_package_detail) {
            $values = [
                'endorsement_service_id'    => $endorsement_service_id,
                'endorsement_id'    => $endorsement_id,
                'package_id'              => array_key_exists('package_id', $service_package_detail) && $service_package_detail['package_id'] != '' ? $service_package_detail['package_id'] : null,
                'lab_test_id'           => array_key_exists('lab_test_id', $service_package_detail) && $service_package_detail['lab_test_id'] != '' ? $service_package_detail['lab_test_id'] : null,
                'is_add_on'           => array_key_exists('is_add_on', $service_package_detail) && $service_package_detail['is_add_on'] != '' ? $service_package_detail['is_add_on'] : 0,
                'price'           => array_key_exists('price', $service_package_detail) && $service_package_detail['price'] != '' ? $service_package_detail['price'] : null,
                'margin'         => array_key_exists('margin', $service_package_detail) && $service_package_detail['margin'] != '' ? $service_package_detail['margin'] : 0,
                'cost'         => array_key_exists('cost', $service_package_detail) && $service_package_detail['cost'] != '' ? $service_package_detail['cost'] : 0,
                'pro_rate_price'         => array_key_exists('pro_rate_price', $service_package_detail) && $service_package_detail['pro_rate_price'] != '' ? $service_package_detail['pro_rate_price'] : 0,
                'discount'         => array_key_exists('discount', $service_package_detail) && $service_package_detail['discount'] != '' ? $service_package_detail['discount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $service_package_detail) && $service_package_detail['branch_id'] != '' ? $service_package_detail['branch_id'] : null,
                'charge_to'           => array_key_exists('charge_to', $service_package_detail) && $service_package_detail['charge_to'] != '' ? $service_package_detail['charge_to'] : null,
                'head_count'           => array_key_exists('head_count', $service_package_detail) && $service_package_detail['head_count'] != '' ? $service_package_detail['head_count'] : null,
                'discount_amount'           => array_key_exists('discount_amount', $service_package_detail) && $service_package_detail['discount_amount'] != '' ? $service_package_detail['discount_amount'] : null,
                'margin_amount'           => array_key_exists('margin_amount', $service_package_detail) && $service_package_detail['margin_amount'] != '' ? $service_package_detail['margin_amount'] : null,
                'sales'           => array_key_exists('sales', $service_package_detail) && $service_package_detail['sales'] != '' ? $service_package_detail['sales'] : null,
                'admin_fee'           => array_key_exists('admin_fee', $service_package_detail) && $service_package_detail['admin_fee'] != '' ? $service_package_detail['admin_fee'] : null,
                'direct_cost'           => array_key_exists('direct_cost', $service_package_detail) && $service_package_detail['direct_cost'] != '' ? $service_package_detail['direct_cost'] : null,
                'manpower_cost'           => array_key_exists('manpower_cost', $service_package_detail) && $service_package_detail['manpower_cost'] != '' ? $service_package_detail['manpower_cost'] : null,
                'mobilization_cost'           => array_key_exists('mobilization_cost', $service_package_detail) && $service_package_detail['mobilization_cost'] != '' ? $service_package_detail['mobilization_cost'] : null,
                'total_cost'           => array_key_exists('total_cost', $service_package_detail) && $service_package_detail['total_cost'] != '' ? $service_package_detail['total_cost'] : null,
                'estimated_margin'          => array_key_exists('estimated_margin', $service_package_detail) && $service_package_detail['estimated_margin'] != '' ? $service_package_detail['estimated_margin'] : null,
                'direct_cost_amount_total'          => array_key_exists('direct_cost_amount_total', $service_package_detail) && $service_package_detail['direct_cost_amount_total'] != '' ? $service_package_detail['direct_cost_amount_total'] : null,
                'manpower_cost_amount_total'          => array_key_exists('manpower_cost_amount_total', $service_package_detail) && $service_package_detail['manpower_cost_amount_total'] != '' ? $service_package_detail['manpower_cost_amount_total'] : null,
                'mobilization_cost_amount_total'          => array_key_exists('mobilization_cost_amount_total', $service_package_detail) && $service_package_detail['mobilization_cost_amount_total'] != '' ? $service_package_detail['mobilization_cost_amount_total'] : null,
                'total_cost_amount_total'          => array_key_exists('total_cost_amount_total', $service_package_detail) && $service_package_detail['total_cost_amount_total'] != '' ? $service_package_detail['total_cost_amount_total'] : null,
                'estimated_margin_amount_total'          => array_key_exists('estimated_margin_amount_total', $service_package_detail) && $service_package_detail['estimated_margin_amount_total'] != '' ? $service_package_detail['estimated_margin_amount_total'] : null,
                'estimated_revenue'          => array_key_exists('estimated_revenue', $service_package_detail) && $service_package_detail['estimated_revenue'] != '' ? $service_package_detail['estimated_revenue'] : null,
                'pre_negotiated_rate'          => array_key_exists('pre_negotiated_rate', $service_package_detail) && $service_package_detail['pre_negotiated_rate'] != '' ? $service_package_detail['pre_negotiated_rate'] : null,
                'theoretical_cost'          => array_key_exists('theoretical_cost', $service_package_detail) && $service_package_detail['theoretical_cost'] != '' ? $service_package_detail['theoretical_cost'] : null,
                'total_amount'    => array_key_exists('total_amount', $service_package_detail) && $service_package_detail['total_amount'] != '' ? $service_package_detail['total_amount'] : 0,
            ];

            if (!empty($service_package_detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_service_package_detail->update(['id' => $service_package_detail['id']], $values)) {
                    $this->error_message = $this->endorsement_service_package_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_service_package_detail->insert($values)) {
                    $this->error_message = $this->endorsement_service_package_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update endorsement shifts
     */
    private function _attempt_update_shifts($endorsement_id)
    {
        // 1. Get all current shift IDs for this endorsement
        $existing_shifts = $this->endorsement_shift->select('id', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_shifts, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('shifts', $this->payload) && is_array($this->payload['shifts'])) {
            foreach ($this->payload['shifts'] as $shift) {
                if (!empty($shift['id'])) {
                    $payload_ids[] = (int)$shift['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_shift->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_shift->error();
                    return false;
                }
            }
        }

        // 4. Process payload shifts (update existing or insert new)
        if (!array_key_exists('shifts', $this->payload) || !is_array($this->payload['shifts'])) {
            return true;
        }

        foreach ($this->payload['shifts'] as $shift) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'name' => array_key_exists('name', $shift) && $shift['name'] != '' ? $shift['name'] : null,
                'start_shift' => array_key_exists('start_shift', $shift) && $shift['start_shift'] != '' ? $shift['start_shift'] : null,
                'end_shift' => array_key_exists('end_shift', $shift) && $shift['end_shift'] != '' ? $shift['end_shift'] : null,
                'registration_cut_off' => array_key_exists('registration_cut_off', $shift) && $shift['registration_cut_off'] != '' ? $shift['registration_cut_off'] : null,
                'specimen_submission_cut_off' => array_key_exists('specimen_submission_cut_off', $shift) && $shift['specimen_submission_cut_off'] != '' ? $shift['specimen_submission_cut_off'] : null,
                'total_hours' => array_key_exists('total_hours', $shift) && $shift['total_hours'] != '' ? $shift['total_hours'] : 0,
                'break' => array_key_exists('break', $shift) && $shift['break'] != '' ? $shift['break'] : null,
                'ingress' => array_key_exists('ingress', $shift) && $shift['ingress'] != '' ? $shift['ingress'] : null,
            ];

            if (!empty($shift['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_shift->update(['id' => $shift['id']], $values)) {
                    $this->error_message = $this->endorsement_shift->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_shift->insert($values)) {
                    $this->error_message = $this->endorsement_shift->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update endorsement consultations
     */
    private function _attempt_update_consultations($endorsement_id)
    {
        // 1. Get all current consultation IDs for this endorsement
        $existing_consultations = $this->endorsement_consultation->select('id', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_consultations, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('consultations', $this->payload) && is_array($this->payload['consultations'])) {
            foreach ($this->payload['consultations'] as $consultation) {
                if (!empty($consultation['id'])) {
                    $payload_ids[] = (int)$consultation['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_consultation->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_consultation->error();
                    return false;
                }
            }
        }

        // 4. Process payload consultations (update existing or insert new)
        if (!array_key_exists('consultations', $this->payload) || !is_array($this->payload['consultations'])) {
            return true;
        }

        foreach ($this->payload['consultations'] as $consultation) {
            $values = [
                'endorsement_id' => $endorsement_id,
                'company_id' => array_key_exists('company_id', $consultation) && $consultation['company_id'] != '' ? $consultation['company_id'] : null,
                'specialization_id' => array_key_exists('specialization_id', $consultation) && $consultation['specialization_id'] != '' ? $consultation['specialization_id'] : null,
                'fee_amount' => array_key_exists('fee_amount', $consultation) && $consultation['fee_amount'] != '' ? $consultation['fee_amount'] : 0,
                'doctor_share' => array_key_exists('doctor_share', $consultation) && $consultation['doctor_share'] != '' ? $consultation['doctor_share'] : 0,
                'doctor_share_amount' => array_key_exists('doctor_share_amount', $consultation) && $consultation['doctor_share_amount'] != '' ? $consultation['doctor_share_amount'] : 0,
                'cpci_share' => array_key_exists('cpci_share', $consultation) && $consultation['cpci_share'] !== '' && $consultation['cpci_share'] !== null ? $consultation['cpci_share'] : 0,
                // 'discount_doctor_share' => array_key_exists('discount_doctor_share', $consultation) && $consultation['discount_doctor_share'] !== '' && $consultation['discount_doctor_share'] !== null ? $consultation['discount_doctor_share'] : 0,
                // 'discount_cpci_share' => array_key_exists('discount_cpci_share', $consultation) && $consultation['discount_cpci_share'] !== '' && $consultation['discount_cpci_share'] !== null ? $consultation['discount_cpci_share'] : 0,
                'ahmopi_fee' => array_key_exists('ahmopi_fee', $consultation) && $consultation['ahmopi_fee'] != '' ? $consultation['ahmopi_fee'] : 0,
                'pahmoc_fee' => array_key_exists('pahmoc_fee', $consultation) && $consultation['pahmoc_fee'] != '' ? $consultation['pahmoc_fee'] : 0,
                'charge_to' => array_key_exists('charge_to', $consultation) && $consultation['charge_to'] != '' ? $consultation['charge_to'] : 'company',
                'charge_to_ids' => array_key_exists('company_id', $consultation) && $consultation['company_id'] != '' ? $consultation['company_id'] : null,
            ];

            if (!empty($consultation['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_consultation->update(['id' => $consultation['id']], $values)) {
                    $this->error_message = $this->endorsement_consultation->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_consultation->insert($values)) {
                    $this->error_message = $this->endorsement_consultation->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update endorsement details
     */
    private function _attempt_update_patients($endorsement_id)
    {
        // 1. Get all current patient IDs for this endorsement
        $existing_patients = $this->endorsement_patient->select('id', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_patients, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('patients', $this->payload) && is_array($this->payload['patients'])) {
            foreach ($this->payload['patients'] as $patient) {
                if (!empty($patient['id'])) {
                    $payload_ids[] = (int)$patient['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_patient->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->endorsement_patient->error();
                    return false;
                }
            }
        }

        // 4. Process payload patients (update existing or insert new)
        if (!array_key_exists('patients', $this->payload) || !is_array($this->payload['patients'])) {
            return true;
        }

        foreach ($this->payload['patients'] as $patient) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'patient_id'              => array_key_exists('patient_id', $patient) && $patient['patient_id'] != '' ? $patient['patient_id'] : null,
                'type'              => array_key_exists('type', $patient) && $patient['type'] != '' ? $patient['type'] : null,
                'type_id'           => array_key_exists('type_id', $patient) && $patient['type_id'] != '' ? $patient['type_id'] : null,
                'with_consultation'           => array_key_exists('with_consultation', $patient) && $patient['with_consultation'] != '' ? $patient['with_consultation'] : 0,
                'type_price'           => array_key_exists('type_price', $patient) && $patient['type_price'] != '' ? $patient['type_price'] : 0,
                'mark_up'           => array_key_exists('mark_up', $patient) && $patient['mark_up'] != '' ? $patient['mark_up'] : 0,
                'total_amount'           => array_key_exists('total_amount', $patient) && $patient['total_amount'] != '' ? $patient['total_amount'] : 0,
                'charge_to'           => array_key_exists('charge_to', $patient) && $patient['charge_to'] != '' ? $patient['charge_to'] : null,
                'charge_to_ids'           => array_key_exists('charge_to_ids', $patient) && $patient['charge_to_ids'] != '' ? $patient['charge_to_ids'] : null,
                'branch_id'           => array_key_exists('branch_id', $patient) && $patient['branch_id'] != '' ? $patient['branch_id'] : null,
                'mobile_van_id'           => array_key_exists('mobile_van_id', $patient) && $patient['mobile_van_id'] != '' ? $patient['mobile_van_id'] : null,
            ];

            if (!empty($patient['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_patient->update(['id' => $patient['id']], $values)) {
                    $this->error_message = $this->endorsement_patient->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_patient->insert($values)) {
                    $this->error_message = $this->endorsement_patient->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update endorsement
     */
    public function upload()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $endorsement_id = $this->payload['endorsement_id'];
        $where = [
            'id' => $endorsement_id,
            'is_deleted' => 0
        ];

        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        if (!$endorsement = $this->endorsement->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'endorsement is not found.'
            ];
            // Attachments is deleted if no file is uploaded
        } elseif (!$this->_upload_signed_moas($endorsement_id, 'assets/endorsements/signed_moa')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload signed_moa. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'endorsement_id' => $endorsement_id,
                'message'    => 'endorsement updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Change status endorsement
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $endorsement_id = $this->payload['endorsement_id'];
        $where = [
            'id' => $endorsement_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$endorsement = $this->endorsement->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'endorsement is not found.'
            ];
        } elseif (!in_array($user->role_id, [4, 20, 21])) {
            $response_status = FAIL;
            $response = [
                "Only Sales Manager, Sales Director or Admin users can change endorsement status. ",
            ];
            //validate status
        } elseif ($status && ($endorsement->status == $status) || ($status == 'force_evaluate' && $endorsement->is_force_evaluate == 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Endorsement is already $status. ",
            ];
        } elseif ($status && ($endorsement->status == 'approved') && !$this->send_email_notif('endorsement_revision_approved', $endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send endorsement revision approved email notification. ",
            ];
        } elseif (!$this->_attempt_update_status($endorsement, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change endorsement status.',
            ];
        } elseif ($status == 'approved' && $endorsement->status != 'approved' && !$this->send_email_notif('endorsement_approved', $endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send endorsement approved email notification. ",
            ];
        } elseif (in_array($status, ['approved', 'disapproved'], true) && !$this->send_email_notif('endorsement_status_update', $endorsement_id, $status)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send endorsement status email notification. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'endorsement_id' => $endorsement_id,
                'message'    => "endorsement $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($endorsement, $role_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'approved') {
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
            $values['approved_reason'] = $this->payload['approved_reason'] ?? null;
            $values['status'] = 'approved';
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_reason'] = $this->payload['disapproved_reason'];
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'returned') {
            $values['status'] = 'returned';
            $values['returned_reason'] = $this->payload['returned_reason'];
            $values['returned_by'] = $this->payload['requester'];
            $values['returned_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'archived') {
            $values['status'] = 'archived';
            $values['archived_reason'] = $this->payload['archived_reason'] ?? null;
            $values['archived_by'] = $this->payload['requester'];
            $values['archived_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'force_evaluate') {
            $values['is_force_evaluate'] = 1;
            $values['force_evaluate_reason'] = $this->payload['force_evaluate_reason'] ?? null;
            $values['force_evaluate_by'] = $this->payload['requester'];
            $values['force_evaluate_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $endorsement->id];

        if (!$this->endorsement->update($where, $values)) {
            $this->error_message = $this->endorsement->error();
            return false;
        }
        return true;
    }

    /**
     * Delete endorsements
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $endorsement_id = $this->payload['endorsement_id'];

        $where = ['id' => $endorsement_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$endorsement = $this->endorsement->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'endorsement is not found.',
            ];
        } elseif (!$this->_attempt_delete($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'endorsement is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'endorsement is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($endorsement_id)
    {
        $where = ['id' => $endorsement_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->endorsement->update($where, $values)) {
            $this->error_message = $this->endorsement->error();
            return false;
        }

        return true;
    }

    /**
     * Create endorsement
     */
    public function upload_patients()
    {
        set_time_limit(300);

        $fileError = $this->_load_upload_patients_from_file();
        if ($fileError !== true) {
            return $this->_create_response(FAIL, [$fileError]);
        }

        $endorsement_id = $this->payload['endorsement_id'];
        $where = [
            'id' => $endorsement_id,
            'is_deleted' => 0
        ];

        $upload_type = $this->payload['upload_type'] ?? null;

        $this->db->transBegin();

        $endorsement_patients = $this->endorsement_patient->select('', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0], 1) ?: [];

        if (!$endorsement = $this->endorsement->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'endorsement is not found.'
            ];
        } elseif (!array_key_exists('patients', $this->payload) || !is_array($this->payload['patients']) || count($this->payload['patients']) == 0) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "No patients to upload. ",
            ];
        } elseif (!$this->_attempt_validate_patient_services($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (!$upload_context = $this->_attempt_prepare_patient_upload($endorsement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (!$upload_result = $this->_attempt_upload_patients($endorsement_id, $upload_type, $upload_context)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to upload endorsement patients. ",
            ];
        } else {
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                $this->db->transRollback();
                return $this->_create_response(FAIL, ['Failed to upload endorsement patients. ']);
            }

            $warnings = [];
            if (!$this->_attempt_upload_patients_worklists($endorsement_id, $upload_result['endorsement_patient_ids'])) {
                log_message('error', 'Upload patients worklist failed for endorsement ' . $endorsement_id);
                $warnings[] = 'worklist';
            }
            if (empty($endorsement_patients) && !$this->send_email_notif('upload_patients', $endorsement_id)) {
                log_message('error', 'Upload patients email notification failed for endorsement ' . $endorsement_id);
                $warnings[] = 'email';
            }

            $response_status = SUCCESS;
            $response = [
                'endorsement_id' => $endorsement_id,
                'message'    => 'endorsement patients uploaded successfully.'
            ];
            if ($warnings) {
                $response['warnings'] = $warnings;
            }
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Read patients array from multipart patient_file (.txt JSON).
     * Falls back to payload['patients'] when no file is sent.
     *
     * @return true|string
     */
    private function _load_upload_patients_from_file()
    {
        $file = $this->request->getFile('patient_file');

        if ($file && $file->isValid() && !$file->hasMoved()) {
            $ext = strtolower((string) $file->getClientExtension());
            $mime = strtolower((string) $file->getClientMimeType());
            if ($ext !== 'txt' && $mime !== 'text/plain') {
                return 'patient_file must be a .txt file.';
            }

            $raw = file_get_contents($file->getTempName());
            if ($raw === false || trim($raw) === '') {
                return 'No patients to upload. ';
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return 'patient_file must contain a JSON patients array.';
            }

            if (isset($decoded['patients']) && is_array($decoded['patients'])) {
                $this->payload['patients'] = $decoded['patients'];
            } elseif (isset($decoded[0]) && is_array($decoded[0])) {
                $this->payload['patients'] = $decoded;
            } else {
                return 'patient_file must contain a JSON patients array.';
            }

            return true;
        }

        if (array_key_exists('patients', $this->payload) && is_array($this->payload['patients'])) {
            return true;
        }

        return 'No patients to upload. ';
    }

    private function _positive_patient_selection_id($value): ?int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        return $id === false ? null : $id;
    }

    /** Validate the complete selection list before any patient writes. */
    private function _attempt_validate_patient_services($endorsement_id, $patient_id = null): bool
    {
        $services = $this->endorsement_service->select('id, type, type_id', [
            'endorsement_id' => $endorsement_id, 'is_deleted' => 0,
        ]) ?: [];
        $services_by_id = [];
        foreach ($services as $service) {
            $service = (object) $service;
            $services_by_id[(int) $service->id] = $service;
        }

        $existing_ids = [];
        if ($patient_id !== null) {
            $existing_rows = $this->endorsement_patient->select('id', [
                'endorsement_id' => $endorsement_id, 'patient_id' => $patient_id, 'is_deleted' => 0,
            ]) ?: [];
            foreach ($existing_rows as $row) {
                $row = (object) $row;
                $existing_ids[(int) $row->id] = true;
            }
        }

        $normalized_patients = [];
        $seen_ids = [];
        foreach ($this->payload['patients'] as $index => $patient) {
            $service_id = is_array($patient) ? $this->_positive_patient_selection_id($patient['endorsement_service_id'] ?? null) : null;
            if ($service_id === null) {
                $this->error_message = "patients[$index].endorsement_service_id must be a positive integer.";
                return false;
            }
            if (!isset($services_by_id[$service_id])) {
                $this->error_message = "patients[$index].endorsement_service_id must reference an active service in this endorsement.";
                return false;
            }
            $service = $services_by_id[$service_id];
            foreach (['type', 'type_id'] as $field) {
                if (array_key_exists($field, $patient) && $patient[$field] !== null && $patient[$field] !== '') {
                    if (!is_scalar($patient[$field]) || (string) $patient[$field] !== (string) $service->$field) {
                        $this->error_message = "patients[$index].$field does not match endorsement_service_id.";
                        return false;
                    }
                }
                $patient[$field] = $service->$field;
            }
            $patient['endorsement_service_id'] = $service_id;

            if ($patient_id !== null && isset($patient['id']) && $patient['id'] !== '') {
                $row_id = $this->_positive_patient_selection_id($patient['id']);
                if ($row_id === null || !isset($existing_ids[$row_id]) || isset($seen_ids[$row_id])) {
                    $this->error_message = "patients[$index].id must identify a distinct active row for this endorsement and patient.";
                    return false;
                }
                $seen_ids[$row_id] = true;
                $patient['id'] = $row_id;
            }
            $normalized_patients[$index] = $patient;
        }
        $this->payload['patients'] = $normalized_patients;
        return true;
    }

    /** Resolve existing identities and reject ambiguous legacy adoption before fan-out. */
    private function _attempt_prepare_patient_upload($endorsement_id)
    {
        $identities = [];
        foreach ($this->payload['patients'] as $patient) {
            $identities[] = [
                'first_name' => $patient['patient_first_name'] ?? '',
                'last_name' => $patient['patient_last_name'] ?? '',
                'middle_name' => $patient['patient_middle_name'] ?? '',
                'birthdate' => $patient['patient_birthdate'] ?? '',
            ];
        }
        $master_by_key = [];
        foreach ($this->patient_master->get_patients_by_identities($identities) as $row) {
            $this->_index_patient_master_row($master_by_key, $row);
        }

        $existing_rows = $this->endorsement_patient->select('', [
            'endorsement_id' => $endorsement_id, 'is_deleted' => 0,
        ]) ?: [];
        $existing_by_key = [];
        $legacy_rows_by_key = [];
        foreach ($existing_rows as $row) {
            $row = (object) $row;
            if ((int) ($row->is_add_on ?? 0) === 1) {
                continue;
            }
            $legacy_key = $this->_endorsement_patient_match_key(
                $row->patient_id, $row->type, $row->type_id,
                $row->branch_id, $row->charge_to, $row->charge_to_ids
            );
            if ((int) $row->endorsement_service_id === 0) {
                $legacy_rows_by_key[$legacy_key][] = $row;
            } else {
                $match_key = $this->_endorsement_patient_match_key(
                    $row->patient_id, $row->type, $row->type_id,
                    $row->branch_id, $row->charge_to, $row->charge_to_ids, $row->endorsement_service_id
                );
                $existing_by_key[$match_key] = $row;
            }
        }

        $variants_by_key = [];
        $adoption_keys = [];
        foreach ($this->payload['patients'] as $patient) {
            $identity_key = $this->_patient_identity_key(
                $patient['patient_first_name'] ?? '', $patient['patient_last_name'] ?? '',
                $patient['patient_middle_name'] ?? '', $patient['patient_birthdate'] ?? ''
            );
            if (!isset($master_by_key[$identity_key])) {
                continue;
            }
            $patient_id = $master_by_key[$identity_key]->id;
            $values = [
                $patient_id, $patient['type'], $patient['type_id'],
                $this->_payload_optional($patient, 'branch_id'),
                $this->_payload_optional($patient, 'charge_to'),
                $this->_payload_optional($patient, 'charge_to_ids'),
            ];
            $legacy_key = $this->_endorsement_patient_match_key(...$values);
            $match_key = $this->_endorsement_patient_match_key(...array_merge($values, [$patient['endorsement_service_id']]));
            $variants_by_key[$legacy_key][$patient['endorsement_service_id']] = true;
            if (!isset($existing_by_key[$match_key]) && isset($legacy_rows_by_key[$legacy_key])) {
                $adoption_keys[$legacy_key] = true;
            }
        }
        foreach ($adoption_keys as $legacy_key => $_) {
            if (count($legacy_rows_by_key[$legacy_key]) !== 1 || count($variants_by_key[$legacy_key]) !== 1) {
                $this->error_message = 'Ambiguous legacy patient selection. Use update_patient with explicit patient-row and service IDs.';
                return false;
            }
        }
        return compact('master_by_key', 'existing_by_key', 'legacy_rows_by_key');
    }

    /**
     * Attempt to upload endorsement patients
     */
    private function _attempt_upload_patients($endorsement_id, $upload_type, array $upload_context)
    {
        $patients = $this->payload['patients'] ?? [];
        if (!is_array($patients) || !$patients) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'];
        $encryptFields = ['first_name', 'last_name', 'middle_name', 'birthdate', 'contact_no', 'civil_status', 'email'];

        $masterByKey = $upload_context['master_by_key'];

        $resolved = [];
        $patient_ids = [];
        foreach ($patients as $patient) {
            $first = $patient['patient_first_name'] ?? '';
            $last = $patient['patient_last_name'] ?? '';
            $middle = $patient['patient_middle_name'] ?? '';
            $dob = $patient['patient_birthdate'] ?? '';
            $identityKey = $this->_patient_identity_key($first, $last, $middle, $dob);

            if (isset($masterByKey[$identityKey])) {
                $masterId = $masterByKey[$identityKey]->id;
            } else {
                $patient_values = [
                    'branch_id'     => $patient['branch_id'],
                    'first_name'    => $first,
                    'last_name'     => $last,
                    'middle_name'   => $middle,
                    'birthdate'     => $dob,
                    'sex'           => $patient['patient_sex'],
                    'civil_status'  => $patient['patient_civil_status'],
                    'email'         => $patient['patient_email'] ?? null,
                    'contact_no'    => 0,
                    'added_by'      => $requester,
                    'added_on'      => $now,
                ];

                if (!$masterId = $this->_insert_encrypt_generated_id($this->patient_master, $patient_values, $encryptFields, 0)) {
                    return false;
                }
                // if (!$masterId = $this->patient_master->insert_encrypt($patient_values, true, $encryptFields)) {
                //     $this->error_message = $this->patient_master->error();
                //     return false;
                // }

                $this->_index_patient_master_row($masterByKey, (object) [
                    'id' => $masterId,
                    'first_name' => $first,
                    'last_name' => $last,
                    'middle_name' => $middle,
                    'birthdate' => $dob,
                ]);
            }

            $patient_ids[] = $masterId;
            $resolved[] = [
                'patient' => $patient,
                'id' => $masterId,
            ];
        }

        $uniqueMasters = [];
        foreach ($resolved as $item) {
            $masterId = $item['id'];
            if (isset($uniqueMasters[$masterId])) {
                continue;
            }
            $patient = $item['patient'];
            $shared = [
                'first_name'    => $patient['patient_first_name'],
                'last_name'     => $patient['patient_last_name'],
                'middle_name'   => $patient['patient_middle_name'] ?? '',
                'birthdate'     => $patient['patient_birthdate'],
                'sex'           => $patient['patient_sex'],
                'civil_status'  => $patient['patient_civil_status'],
                'email'         => $patient['patient_email'] ?? null,
                'contact_no'    => 0,
                'added_by'      => $requester,
                'added_on'      => $now,
            ];
            $uniqueMasters[$masterId] = [
                'emr' => array_merge(['master_patient_id' => $masterId], $shared),
                'diagnostic' => array_merge(['master_id' => $masterId], $shared),
            ];
        }

        if (!$this->_fanout_patients_to_branches($uniqueMasters, $encryptFields)) {
            return false;
        }

        $existing_by_key = $upload_context['existing_by_key'];
        $legacy_rows_by_key = $upload_context['legacy_rows_by_key'];

        $selected_rows = [];
        $rows_to_insert = [];

        foreach ($resolved as $item) {
            $patient = $item['patient'];
            $patient_id = $item['id'];
            $endorsement_service_id = $patient['endorsement_service_id'];

            $type = $this->_payload_optional($patient, 'type');
            $type_id = $this->_payload_optional($patient, 'type_id');
            $branch_id = $this->_payload_optional($patient, 'branch_id');
            $charge_to = $this->_payload_optional($patient, 'charge_to');
            $charge_to_ids = $this->_payload_optional($patient, 'charge_to_ids');
            $mobile_van_id = $this->_payload_optional($patient, 'mobile_van_id');
            $legacy_key = $this->_endorsement_patient_match_key($patient_id, $type, $type_id, $branch_id, $charge_to, $charge_to_ids);
            $match_key = $this->_endorsement_patient_match_key($patient_id, $type, $type_id, $branch_id, $charge_to, $charge_to_ids, $endorsement_service_id);

            // A repeated object in this upload represents the same selection.
            if (isset($selected_rows[$match_key])) {
                continue;
            }

            $existing_patient = $existing_by_key[$match_key] ?? ($legacy_rows_by_key[$legacy_key][0] ?? null);
            if ($existing_patient) {
                $update_values = [
                    'endorsement_service_id' => $endorsement_service_id,
                    'mobile_van_id' => $mobile_van_id,
                    'updated_by' => $requester,
                    'updated_on' => $now,
                ];
                if (!$this->endorsement_patient->update([
                    'id' => $existing_patient->id,
                    'endorsement_id' => $endorsement_id,
                    'endorsement_service_id' => $existing_patient->endorsement_service_id,
                    'is_deleted' => 0,
                ], $update_values)) {
                    $this->error_message = $this->endorsement_patient->error();
                    return false;
                }
                $selected_rows[$match_key] = array_merge((array) $existing_patient, $update_values);
                $existing_by_key[$match_key] = (object) $selected_rows[$match_key];
                unset($legacy_rows_by_key[$legacy_key]);
                continue;
            }

            if (
                $type == 'lab_test'
                && $type_id == '524'
                && ($patient['patient_sex'] == 'MALE' || $patient['patient_sex'] == 'male' || $patient['patient_sex'] == 'Male')
            ) {
                continue;
            }

            $values = [
                'endorsement_id'    => $endorsement_id,
                'endorsement_service_id' => $endorsement_service_id,
                'patient_id'        => $patient_id,
                'type'              => $type,
                'type_id'           => $type_id,
                'with_consultation' => $this->_payload_optional($patient, 'with_consultation', 0),
                'type_price'        => $this->_payload_optional($patient, 'type_price', 0),
                'mark_up'           => $this->_payload_optional($patient, 'mark_up', 0),
                'total_amount'      => $this->_payload_optional($patient, 'total_amount', 0),
                'charge_to'         => $charge_to,
                'charge_to_ids'     => $charge_to_ids,
                'package_ids'       => null,
                'is_add_on'         => 0,
                'branch_id'         => $branch_id,
                'mobile_van_id'     => $mobile_van_id,
                'added_by'          => $requester,
                'added_on'          => $now,
            ];

            $rows_to_insert[] = $values;
            $selected_rows[$match_key] = $values;
        }

        if ($rows_to_insert && !$this->endorsement_patient->insert_batch($rows_to_insert)) {
            $this->error_message = $this->endorsement_patient->error();
            return false;
        }

        $saved_rows = $this->endorsement_patient->get_selected_upload_rows($endorsement_id, array_values($selected_rows));
        if ($saved_rows === false) {
            $this->error_message = $this->endorsement_patient->error();
            return false;
        }

        $selected_ids = [];
        foreach ($saved_rows as $row) {
            $match_key = $this->_endorsement_patient_match_key(
                $row['patient_id'], $row['type'], $row['type_id'],
                $row['branch_id'], $row['charge_to'], $row['charge_to_ids'], $row['endorsement_service_id']
            );
            if (isset($selected_rows[$match_key]['id']) && (string) $selected_rows[$match_key]['id'] !== (string) $row['id']) {
                continue;
            }
            // Existing historical duplicates are left intact, but sent only once.
            if (isset($selected_rows[$match_key]) && !isset($selected_ids[$match_key])) {
                $selected_ids[$match_key] = $selected_rows[$match_key]['id'] ?? $row['id'];
            }
        }
        if (count($selected_ids) !== count($selected_rows)) {
            $this->error_message = 'Failed to resolve uploaded endorsement patient selections.';
            return false;
        }

        return [
            'patient_ids' => array_values(array_unique($patient_ids)),
            'endorsement_patient_ids' => array_values($selected_ids),
        ];
    }

    /**
     * Fan-out master patients to EMR and diagnostic branch DBs.
     */
    private function _fanout_patients_to_branches(array $uniqueMasters, array $encryptFields): bool
    {
        if (!$uniqueMasters) {
            return true;
        }

        $masterIds = array_keys($uniqueMasters);

        foreach ([10, 12, 13, 14, 17] as $branch_id) {
            $model = $this->_patient_branch_model($branch_id, 'emr');
            if (!$model) {
                return false;
            }
            $existing = $model->get_existing_master_patient_ids($masterIds);
            $toInsert = [];
            foreach ($uniqueMasters as $id => $data) {
                if (!isset($existing[$id]) && !isset($existing[(string) $id])) {
                    $toInsert[] = $data['emr'];
                }
            }
            if ($toInsert && !$model->insert_batch_encrypt($toInsert, $encryptFields)) {
                $this->error_message = $model->error();
                return false;
            }
        }

        foreach ([1, 2, 3, 4, 7] as $branch_id) {
            $model = $this->_patient_branch_model($branch_id, 'diagnostic');
            if (!$model) {
                return false;
            }
            $existing = $model->get_existing_master_ids($masterIds);
            $toInsert = [];
            foreach ($uniqueMasters as $id => $data) {
                if (!isset($existing[$id]) && !isset($existing[(string) $id])) {
                    $toInsert[] = $data['diagnostic'];
                }
            }
            if ($toInsert && !$model->insert_batch_encrypt($toInsert, $encryptFields)) {
                $this->error_message = $model->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Cache Patient_emr / Patient_diagnostic for a branch without rebuilding billing models.
     */
    private function _patient_branch_model(int $branch_id, string $type)
    {
        $cacheKey = $type . '_' . $branch_id;
        if (isset($this->patientBranchModels[$cacheKey])) {
            return $this->patientBranchModels[$cacheKey];
        }

        $dbMap = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            10 => 'mobile_emr',
            12 => 'oakridge_emr',
            13 => 'mactan_emr',
            14 => 'consolacion_emr',
            17 => 'mantlewood_emr',
        ];

        if (!isset($dbMap[$branch_id])) {
            return null;
        }

        $db = db_connect($dbMap[$branch_id]);
        $model = $type === 'emr' ? new Patient_emr($db) : new Patient_diagnostic($db);
        $this->patientBranchModels[$cacheKey] = $model;

        return $model;
    }

    private function _patient_identity_key($first, $last, $middle, $birthdate): string
    {
        return strtolower(trim((string) $first) . '|' . trim((string) $last) . '|' . trim((string) $middle) . '|' . trim((string) $birthdate));
    }

    private function _index_patient_master_row(array &$map, $row): void
    {
        $row = is_object($row) ? $row : (object) $row;
        $fullKey = $this->_patient_identity_key($row->first_name ?? '', $row->last_name ?? '', $row->middle_name ?? '', $row->birthdate ?? '');
        $map[$fullKey] = $row;
        $noMiddleKey = $this->_patient_identity_key($row->first_name ?? '', $row->last_name ?? '', '', $row->birthdate ?? '');
        if (!isset($map[$noMiddleKey])) {
            $map[$noMiddleKey] = $row;
        }
    }

    private function _endorsement_patient_match_key($patient_id, $type, $type_id, $branch_id, $charge_to, $charge_to_ids, $endorsement_service_id = 0): string
    {
        return implode('|', [
            (string) $patient_id,
            (string) $type,
            (string) $type_id,
            (string) $branch_id,
            (string) $charge_to,
            (string) $charge_to_ids,
            (string) $endorsement_service_id,
        ]);
    }

    private function _payload_optional(array $patient, string $key, $default = null)
    {
        return array_key_exists($key, $patient) && $patient[$key] != '' ? $patient[$key] : $default;
    }

    /**
     * Attempt to upload endorsement worklists
     */
    private function _attempt_upload_patients_worklists($endorsement_id, array $endorsement_patient_ids)
    {
        $endorsement = $this->endorsement->select('', ['id' => $endorsement_id, 'is_deleted' => 0], 1);
        if (!$endorsement) {
            return false;
        }

        $scheduled_dt = date('Y-m-d', strtotime($endorsement->contract_date_from)) . ' 12:00:00';

        if (!$endorsement_patient_ids) {
            return true;
        }

        $patients = $this->endorsement_patient->get_details_for_worklist(
            $endorsement_id, null, null, null, $endorsement_patient_ids
        );
        if ($patients === false) {
            return false;
        }
        if (empty($patients)) {
            return true;
        }

        $worklist_groups = [];
        foreach ($patients as $patient) {
            if ($patient['first_name'] == null || $patient['last_name'] == null) {
                $patient_data = $this->patient_master->get_patient_by_name($patient['patient_id']);
                $patient['first_name'] = $patient_data->first_name;
                $patient['last_name'] = $patient_data->last_name;
                $patient['middle_name'] = $patient_data->middle_name;
                $patient['sex'] = $patient_data->sex;
                $patient['birthdate'] = $patient_data->birthdate;
            }

            $key = $patient['type'] . '_' . $patient['type_id'] . '_' . $patient['charge_to_ids'];
            if (!isset($worklist_groups[$key])) {
                $worklist_groups[$key] = [
                    'endorsement_id' => (string) $endorsement_id,
                    'origin'         => $this->payload['origin'] ?? 'emr',
                    'type_name'      => $patient['type_name'],
                    'type_id'        => (string) $patient['type_id'],
                    'type'           => $patient['type'],
                    'charge_to_id'   => (string) $patient['charge_to_ids'],
                    'worklist'       => [],
                ];
            }
            $worklist_groups[$key]['worklist'][] = [
                'procedure_description' => $patient['type_name'],
                'scheduled_dt'          => $scheduled_dt,
                'patient_id'            => $patient['patient_id'],
                'first_name'            => $patient['first_name'],
                'last_name'             => $patient['last_name'],
                'middle_name'           => ($patient['middle_name'] !== '' && $patient['middle_name'] !== null) ? $patient['middle_name'] : null,
                'birthdate'             => $patient['birthdate'],
                'sex'                   => ($patient['sex'] !== '' && $patient['sex'] !== null) ? strtoupper($patient['sex']) : null,
                'station_aet'           => DICOM_STATION_AET,
            ];
        }

        $worklist_data = array_values($worklist_groups);
        $temp_file     = sys_get_temp_dir() . '/worklist_' . $endorsement_id . '_' . time() . '.txt';
        file_put_contents($temp_file, json_encode($worklist_data));

        $log_values = [
            'controller'    => 'Endorsements',
            'method'        => '_attempt_upload_patients_worklists',
            'ip_address'    => $this->request->getIPAddress(),
            'data_received' => json_encode(['endorsement_id' => $endorsement_id, 'worklist_count' => count($worklist_data)]),
            'requested_by'  => $this->payload['requester'],
            'requested_on'  => date('Y-m-d H:i:s'),
        ];
        $api_log_id = $this->api_log->insert($log_values);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => DICOM_UPLOAD_WORKLIST_URL,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'requester'      => $this->payload['requester'],
                'endorsement_id' => (string) $endorsement_id,
                'file'           => new \CURLFile($temp_file, 'text/plain', 'worklist.txt'),
                'mobile_van_id'  => $patients[0]['mobile_van_id'] ?? null,
                'origin'         => $this->payload['origin'] ?? 'emr',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json, text/plain, */*',
                'api-key: ' . DICOM_API_KEY,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response   = curl_exec($curl);
        $http_code  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);

        @unlink($temp_file);

        $response_values = [
            'api_log_id'   => $api_log_id,
            'response'     => $curl_error ?: $response,
            'status'       => $curl_error ? 500 : $http_code,
            'responded_on' => date('Y-m-d H:i:s'),
        ];
        $this->api_response->insert($response_values);

        if ($curl_error) {
            log_message('error', 'DICOM worklist upload error: ' . $curl_error);
            return false;
        }

        return $http_code >= 200 && $http_code < 300;
    }

    /**
     * Attempt to manual upload update delete patients
     */
    private function _attempt_manual_upload_update_delete_patients($endorsement_id)
    {
        $endorsement_patients = $this->endorsement_patient->select('', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0], 1) ?: [];

        if (!empty($endorsement_patients)) {
            $delete_values = [
                'is_deleted' => 1,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s')
            ];
            if (!$this->endorsement_patient->update(['endorsement_id' => $endorsement_id], $delete_values)) {
                $this->error_message = $this->endorsement_patient->error();
                return false;
            }
        }

        return true;
    }

    /**
     * UPDATE Patient
     */
    public function update_patient()
    {
        $this->db->transBegin();

        $endorsement_id = $this->request->getVar('endorsement_id');
        $endorsement_patient_id = $this->request->getVar('endorsement_patient_id');
        $patient_id = $this->request->getVar('patient_id');

        if (!$endorsement_id || !$endorsement_patient_id || !$patient_id) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Endorsement ID, Endorsement patient ID, and Patient ID are required.'
            ];
        } elseif (!isset($this->payload['patients']) || !is_array($this->payload['patients'])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Patients array is required.'
            ];
        } elseif (!$endorsement = $this->endorsement->select('', ['is_deleted' => 0, 'id' => $endorsement_id], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Endorsement does not exists.'
            ];
        } elseif (!$patient = $this->patient_master->select('', ['is_deleted' => 0, 'id' => $patient_id], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Patient does not exists.'
            ];
        } elseif (!$endorsement_patient = $this->endorsement_patient->select('', ['is_deleted' => 0, 'id' => $endorsement_patient_id, 'endorsement_id' => $endorsement_id, 'patient_id' => $patient_id], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Endorsement patient does not exists.'
            ];
        } elseif (!$this->_attempt_validate_patient_services($endorsement_id, $patient_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (!$this->_attempt_update_patient($patient)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update patient.'
            ];
        } elseif (!$this->_attempt_update_endorsement_patient($endorsement_id, $patient_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update endorsement patient.'
            ];
        } elseif (!$this->db->transStatus() || !$this->db->transCommit()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = ['Failed to update endorsement patient.'];
        } else {
            $response_status = SUCCESS;
            $response = [
                'message' => 'Patient updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _attempt_update_patient($patient)
    {
        $where = [
            'id' => $patient->id
        ];

        $fieldsToEncrypt = [
            'first_name',
            'last_name',
            'middle_name',
            'birthdate',
            'civil_status',
            'email',
        ];

        $data = [
            'first_name'             => $this->request->getVar('first_name'),
            'last_name'              => $this->request->getVar('last_name'),
            'middle_name'            => $this->request->getVar('middle_name'),
            'birthdate'              => date('Y-m-d', strtotime($this->request->getVar('birthdate'))),
            'sex'                    => $this->request->getVar('sex'),
            'email'                  => $this->request->getVar('email'),
            'civil_status'           => $this->request->getVar('civil_status'),
            'updated_by'             => $this->request->getVar('requester'),
            'updated_on'             => date('Y-m-d H:i:s'),
        ];

        if (!$this->patient_master->update_encrypt($where, $data, $fieldsToEncrypt)) {
            return false;
        }

        //update diagnostics patient table
        $diagnostics_patient_where = [
            'master_id' => $patient->id
        ];
        $diagnostics_patient_data = [
            'first_name'             => $this->request->getVar('first_name'),
            'last_name'              => $this->request->getVar('last_name'),
            'middle_name'            => $this->request->getVar('middle_name'),
            'birthdate'              => date('Y-m-d', strtotime($this->request->getVar('birthdate'))),
            'sex'                    => $this->request->getVar('sex'),
            'email'                  => $this->request->getVar('email'),
            'civil_status'           => $this->request->getVar('civil_status'),
            'updated_by'             => $this->request->getVar('requester'),
            'updated_on'             => date('Y-m-d H:i:s'),
        ];

        //loop through the patient_diagnostic database from 1 to 7 then insert the values
        foreach ([1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->patient_diagnostic->update_encrypt($diagnostics_patient_where, $diagnostics_patient_data, $fieldsToEncrypt)) {
                return false;
            }
        }

        //update emr patient table
        $emr_patient_where = [
            'master_patient_id' => $patient->id
        ];
        $emr_patient_data = [
            'first_name'             => $this->request->getVar('first_name'),
            'last_name'              => $this->request->getVar('last_name'),
            'middle_name'            => $this->request->getVar('middle_name'),
            'birthdate'              => date('Y-m-d', strtotime($this->request->getVar('birthdate'))),
            'sex'                    => $this->request->getVar('sex'),
            'email'                  => $this->request->getVar('email'),
            'civil_status'           => $this->request->getVar('civil_status'),
            'updated_by'             => $this->request->getVar('requester'),
            'updated_on'             => date('Y-m-d H:i:s'),
        ];

        //loop through the patient_emr database from 10 to 12 and 17 then insert the values
        foreach ([10, 12, 13, 14, 17] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->patient_emr->update_encrypt($emr_patient_where, $emr_patient_data, $fieldsToEncrypt)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to update endorsement details
     */
    private function _attempt_update_endorsement_patient($endorsement_id, $patient_id)
    {
        // 1. Get all current patient IDs for this endorsement
        $existing_patients = $this->endorsement_patient->select('id', ['endorsement_id' => $endorsement_id, 'patient_id' => $patient_id, 'is_deleted' => 0]) ?: [];

        $existing_ids = array_column($existing_patients, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('patients', $this->payload) && is_array($this->payload['patients'])) {
            foreach ($this->payload['patients'] as $patient) {
                if (!empty($patient['id'])) {
                    $payload_ids[] = (int)$patient['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->endorsement_patient->update([
                    'id' => $delete_id, 'endorsement_id' => $endorsement_id,
                    'patient_id' => $patient_id, 'is_deleted' => 0,
                ], $delete_values)) {
                    $this->error_message = $this->endorsement_patient->error();
                    return false;
                }
            }
        }

        // 4. Process payload patients (update existing or insert new)
        if (!array_key_exists('patients', $this->payload) || !is_array($this->payload['patients'])) {
            return true;
        }

        foreach ($this->payload['patients'] as $patient) {
            $values = [
                'endorsement_id'    => $endorsement_id,
                'endorsement_service_id' => $patient['endorsement_service_id'],
                'patient_id'        => $patient_id,
                'type'              => array_key_exists('type', $patient) && $patient['type'] != '' ? $patient['type'] : null,
                'type_id'           => array_key_exists('type_id', $patient) && $patient['type_id'] != '' ? $patient['type_id'] : null,
                'with_consultation'           => array_key_exists('with_consultation', $patient) && $patient['with_consultation'] != '' ? $patient['with_consultation'] : 0,
                'type_price'           => array_key_exists('type_price', $patient) && $patient['type_price'] != '' ? $patient['type_price'] : 0,
                'mark_up'           => array_key_exists('mark_up', $patient) && $patient['mark_up'] != '' ? $patient['mark_up'] : 0,
                'total_amount'           => array_key_exists('total_amount', $patient) && $patient['total_amount'] != '' ? $patient['total_amount'] : 0,
                'charge_to'           => array_key_exists('charge_to', $patient) && $patient['charge_to'] != '' ? $patient['charge_to'] : null,
                'charge_to_ids'           => array_key_exists('charge_to_ids', $patient) && $patient['charge_to_ids'] != '' ? $patient['charge_to_ids'] : null,
                'branch_id'           => array_key_exists('branch_id', $patient) && $patient['branch_id'] != '' ? $patient['branch_id'] : null,
                'mobile_van_id'           => array_key_exists('mobile_van_id', $patient) && $patient['mobile_van_id'] != '' ? $patient['mobile_van_id'] : null,
            ];

            if (!empty($patient['id'])) {
                $endorsement_patient_id = $patient['id'];
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->endorsement_patient->update([
                    'id' => $patient['id'], 'endorsement_id' => $endorsement_id,
                    'patient_id' => $patient_id, 'is_deleted' => 0,
                ], $values)) {
                    $this->error_message = $this->endorsement_patient->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$endorsement_patient_id = $this->endorsement_patient->insert($values)) {
                    $this->error_message = $this->endorsement_patient->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_attachments($endorsement_id, $version_no, $path)
    {
        $files = $this->request->getFileMultiple('attachments');
        $file_path = $path . $endorsement_id;

        $endorsement_attachments = $this->endorsement_attachment->select('', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]) ?: [];

        if (!empty($files)) {
            $path = $file_path . '/';

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
                write_file($path . 'index.html', 'Directory access is forbidden.');
            }

            foreach ($files as $i => $file) {
                $sourcePath = $file->getPath();
                $destinationPath = $path;
                $unique_name = $unique_name = uniqid() . '_' . time();
                $file_label = $this->payload['attachment_labels'][$i] ?? '';

                if ($file->isValid() && !in_array($file->getExtension(), ['pdf', 'png', 'jpeg', 'jpg', 'doc', 'docx', 'xls', 'xlsx', 'csv'])) {
                    return false;
                }

                $file_name = $file->getName();
                $max_file_size = 5 * 1024 * 1024; // 5 MB in bytes

                // if ($file->getSize() > $max_file_size) {
                //     return false;
                // }

                //var_dump($file->isValid() && !$file->hasMoved()); die();

                if ($file->isValid() && !$file->hasMoved()) {
                    //$file_name = $file->getName();
                    $extension = $file->getExtension();
                    $file_name = $unique_name . '.' . $extension;
                    $mime_type = $file->getMimeType();

                    if (empty($endorsement_attachments)) {
                        $file->move($path, $file_name);
                        $data = [
                            'endorsement_id' => $endorsement_id,
                            'version_no' => $version_no,
                            'file_name' => $file_name,
                            'file_label' => $file_label,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->endorsement_attachment->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        // foreach ($endorsement_attachments as $attachment) {
                        //     $attachment = (array)$attachment;
                        //     $this->endorsement_attachment->update(['id' => $attachment['id']], [
                        //         'is_deleted' => 1,
                        //         'updated_by' => $this->payload['requester'],
                        //         'updated_on' => date('Y-m-d H:i:s')
                        //     ]);
                        //     // Delete the existing file from the server
                        //     if (is_file($attachment['file_path'] . '/' . $attachment['file_name'])) {
                        //         unlink($attachment['file_path'] . '/' . $attachment['file_name']);
                        //     }
                        // }

                        // then upload the new file
                        $file->move($path, $file_name);
                        $data = [
                            'endorsement_id' => $endorsement_id,
                            'version_no' => $version_no,
                            'file_name' => $file_name,
                            'file_label' => $file_label,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->endorsement_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1
            if (!empty($endorsement_attachments)) {
                foreach ($endorsement_attachments as $attachment) {
                    $attachment = (array)$attachment;
                    // $this->endorsement_attachment->update(['id' => $attachment['id']], [
                    //     'is_deleted' => 1,
                    //     'updated_by' => $this->payload['requester'],
                    //     'updated_on' => date('Y-m-d H:i:s')
                    // ]);
                    // // Delete the existing file from the server
                    // if (is_file($attachment['file_path'] . '/' . $attachment['file_name'])) {
                    //     unlink($attachment['file_path'] . '/' . $attachment['file_name']);
                    // }
                    if ($attachment['version_no'] != $version_no) {
                        $data = [
                            'endorsement_id' => $attachment['endorsement_id'],
                            'version_no' => $version_no,
                            'file_name' => $attachment['file_name'],
                            'file_label' => $attachment['file_label'],
                            'file_path' => $attachment['file_path'],
                            'file_url' => $attachment['file_url'],
                            'mime' => $attachment['mime'],
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->endorsement_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_signed_moas($endorsement_id, $path)
    {
        $files = $this->request->getFileMultiple('signed_moas');
        $file_path = $path . $endorsement_id;

        $endorsement_signed_moas = $this->endorsement_signed_moa->select('', ['endorsement_id' => $endorsement_id, 'is_deleted' => 0]) ?: [];

        if (!empty($files)) {
            $path = $file_path . '/';

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
                write_file($path . 'index.html', 'Directory access is forbidden.');
            }

            foreach ($files as $i => $file) {
                $sourcePath = $file->getPath();
                $destinationPath = $path;
                $unique_name = $unique_name = uniqid() . '_' . time();

                if ($file->isValid() && !in_array($file->getExtension(), ['pdf', 'png', 'jpeg', 'jpg', 'xls', 'xlsx', 'doc', 'docx'])) {
                    return false;
                }

                $file_name = $file->getName();
                $max_file_size = 5 * 1024 * 1024; // 5 MB in bytes

                // if ($file->getSize() > $max_file_size) {
                //     return false;
                // }

                //var_dump($file->isValid() && !$file->hasMoved()); die();

                if ($file->isValid() && !$file->hasMoved()) {
                    //$file_name = $file->getName();
                    $extension = $file->getExtension();
                    $file_name = $unique_name . '.' . $extension;
                    $mime_type = $file->getMimeType();

                    if (empty($endorsement_signed_moas)) {
                        $file->move($path, $file_name);
                        $data = [
                            'endorsement_id' => $endorsement_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->endorsement_signed_moa->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($endorsement_signed_moas as $signed_moa) {
                            $signed_moa = (array)$signed_moa;
                            $this->endorsement_signed_moa->update(['id' => $signed_moa['id']], [
                                'is_deleted' => 1,
                                'updated_by' => $this->payload['requester'],
                                'updated_on' => date('Y-m-d H:i:s')
                            ]);
                            // Delete the existing file from the server
                            if (is_file($signed_moa['file_path'] . '/' . $signed_moa['file_name'])) {
                                unlink($signed_moa['file_path'] . '/' . $signed_moa['file_name']);
                            }
                        }

                        // then upload the new file
                        $file->move($path, $file_name);
                        $data = [
                            'endorsement_id' => $endorsement_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->endorsement_signed_moa->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1
            if (!empty($endorsement_signed_moas)) {
                foreach ($endorsement_signed_moas as $signed_moa) {
                    $signed_moa = (array)$signed_moa;
                    $this->endorsement_signed_moa->update(['id' => $signed_moa['id']], [
                        'is_deleted' => 1,
                        'updated_by' => $this->payload['requester'],
                        'updated_on' => date('Y-m-d H:i:s')
                    ]);
                    // Delete the existing file from the server
                    if (is_file($signed_moa['file_path'] . '/' . $signed_moa['file_name'])) {
                        unlink($signed_moa['file_path'] . '/' . $signed_moa['file_name']);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Send Test Email Result
     */
    protected function send_email_notif($subject, $endorsement_id, $status = null)
    {
        $requesterId = $this->payload['requester'];
        $fallbackEmails = resolve_user_fallback_emails($this->user, $requesterId);
        $site_url = ACCOUNTING_FRONT_END_URL . "/endorsement-view?id=" . $endorsement_id;
        $primaryEmails = [];

        $endorsement = $this->endorsement->select('added_by, request_id, quotation_id, costing_id, company_id, package_name', ['id' => $endorsement_id, 'is_deleted' => 0], 1);
        $company_id = $endorsement ? $endorsement->company_id : null;
        $company = $this->company->select('name', ['id' => $company_id, 'is_deleted' => 0], 1);
        $company_name = $company ? $company->name : null;
        // $endorsement_packages = $this->endorsement_service->get_packages($endorsement_id) ?: null;
        $endorsement_packages = $endorsement ? $endorsement->package_name : null;
        $company_name_with_packages = $endorsement_packages ? "$company_name - $endorsement_packages" : $company_name;
        $idLabel = ($endorsement && $endorsement->costing_id) ? "Costing #{$endorsement->costing_id}" : "Endorsement #$endorsement_id";

        if (in_array($subject, ['endorsement_for_approval', 'endorsement_revision_for_approval'])) {
            $primaryEmails = resolve_emails_by_role($this->user, [ROLE_SALES_MANAGER, ROLE_SALES_DIRECTOR]);
        } elseif ($subject == 'endorsement_approved' || $subject == 'endorsement_revision_approved') {
            $primaryEmails[] = ENDORSEMENT_CORPORATE_EMAIL;
        } elseif ($subject == 'endorsement_status_update') {
            // Approved / disapproved: only the request creator is notified
            $fallbackEmails = [];
            if ($status !== 'approved' && $status !== 'disapproved') {
                return true;
            }
            $creatorEmail = ($endorsement && $endorsement->request_id)
                ? resolve_request_added_by_email($this->request_model, $this->user, $endorsement->request_id)
                : null;
            if (!$creatorEmail) {
                log_message('error', 'Endorsement status email skipped: no email found for request creator (endorsement #' . $endorsement_id . ')');
                return true;
            }
            $primaryEmails[] = $creatorEmail;
        } elseif ($subject == 'upload_patients') {
            $primaryEmails = resolve_emails_by_role($this->user, [ROLE_IT]);
            $fallbackEmails = [ENDORSEMENT_CORPORATE_EMAIL];
        }

        $approvalReasonHtml = build_status_reason_html('Approval message', $this->payload['approved_reason'] ?? null);

        if ($subject == 'endorsement_for_approval') {
            $subjectLine = "Endorsement for Approval: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A new endorsement (Endorsement ID: $endorsement_id) has been submitted and is awaiting your approval. Please review the endorsement details and take the necessary action.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Endorsements Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'endorsement_revision_for_approval') {
            $subjectLine = "Endorsement Revision for Approval: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>An endorsement revision (Endorsement ID: $endorsement_id) has been submitted and is awaiting your approval. Please review the endorsement revision details and take the necessary action.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Endorsements Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'endorsement_approved') {
            $subjectLine = "Endorsement Approved: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>An endorsement (Endorsement ID: $endorsement_id) has been approved. Please review the endorsement details and upload patients accordingly.</p>
$approvalReasonHtml
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Endorsements Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'endorsement_revision_approved') {
            $subjectLine = "Endorsement Revision Approved: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>An endorsement revision (Endorsement ID: $endorsement_id) has been approved. Please review the endorsement details and take the necessary action.</p>
$approvalReasonHtml
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Endorsements Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'upload_patients') {
            $subjectLine = "Upload Patients: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>An endorsement has been fully set up and is now ready for operation.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Endorsements Screen</a></p>
<p>Access the Endorsements screen using the link below:<br>$site_url</p>";
        } elseif ($subject == 'request_moved_to_endorsement') {
            $subjectLine = "Your Request Has Been Moved to Endorsement: $company_name_with_packages (Endorsement #$endorsement_id)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>An endorsement has been fully set up and is now ready for operation.</p>";
        } elseif ($subject == 'endorsement_status_update') {
            $isApproved = $status === 'approved';
            $actor = $this->user->select('role_id', ['id' => $requesterId, 'is_deleted' => 0], 1);
            $actorLabel = status_actor_role_label($actor ? $actor->role_id : null);
            $statusLabel = $isApproved ? 'Approved' : 'Disapproved';
            $statusMessage = $isApproved
                ? "Your request's endorsement (Endorsement ID: $endorsement_id) has been reviewed and <strong>approved</strong> by the $actorLabel."
                : "We regret to inform you that your request's endorsement (Endorsement ID: $endorsement_id) has been reviewed and <strong>disapproved</strong> by the $actorLabel. Please reach out for further details.";
            $reasonHtml = $isApproved
                ? $approvalReasonHtml
                : build_status_reason_html('Reason for disapproval', $this->payload['disapproved_reason'] ?? null);

            $subjectLine = "Your Endorsement Has Been $statusLabel: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>$statusMessage</p>
$reasonHtml";
        } else {
            return false;
        }

        return dispatch_email_notification($subjectLine, $message, $primaryEmails, $fallbackEmails);
    }

    /**
     * Generate missing request / costing / quotation from one endorsement's available data.
     */
    private function _attempt_generate_chain_from_endorsement(array $endorsement)
    {
        $endorsementId = (int) $endorsement['id'];
        $requester = $this->payload['requester'];
        $now = date('Y-m-d H:i:s');

        $requestId = $this->_is_missing_id($endorsement['request_id'] ?? null) ? null : (int) $endorsement['request_id'];
        $costingId = $this->_is_missing_id($endorsement['costing_id'] ?? null) ? null : (int) $endorsement['costing_id'];
        $quotationId = $this->_is_missing_id($endorsement['quotation_id'] ?? null) ? null : (int) $endorsement['quotation_id'];

        $services = $this->endorsement_service->get_details($endorsementId) ?: [];
        $shifts = $this->endorsement_shift->get_details($endorsementId) ?: [];
        $consultations = $this->endorsement_consultation->get_details($endorsementId) ?: [];
        $discounts = $this->endorsement_discount->get_details($endorsementId) ?: [];
        $consultas = $this->endorsement_consulta->get_details($endorsementId) ?: [];

        if ($requestId === null) {
            $requestId = $this->_generate_request_from_endorsement($endorsement, $services, $shifts, $consultations, $requester, $now);
            if (!$requestId) {
                return false;
            }
        }

        if ($costingId === null) {
            $costingId = $this->_generate_costing_from_endorsement($endorsement, $requestId, $services, $shifts, $consultations, $discounts, $consultas, $requester, $now);
            if (!$costingId) {
                return false;
            }
        }

        if ($quotationId === null) {
            $quotationId = $this->_generate_quotation_from_endorsement($endorsement, $requestId, $costingId, $services, $shifts, $consultations, $requester, $now);
            if (!$quotationId) {
                return false;
            }
        }

        if (!$this->endorsement->update(['id' => $endorsementId], [
            'request_id' => $requestId,
            'costing_id' => $costingId,
            'quotation_id' => $quotationId,
            'updated_by' => $requester,
            'updated_on' => $now,
        ])) {
            $this->error_message = $this->endorsement->error();
            return false;
        }

        return [
            'endorsement_id' => $endorsementId,
            'request_id' => $requestId,
            'costing_id' => $costingId,
            'quotation_id' => $quotationId,
        ];
    }

    private function _generate_request_from_endorsement(array $endorsement, array $services, array $shifts, array $consultations, $requester, $now)
    {
        $values = $this->_copy_overlapping_fields($this->request_model->allowedFields, $endorsement);
        if (!empty($endorsement['head_count'])) {
            $values['estimated_headcount'] = $endorsement['head_count'];
        }
        $values['added_by'] = $requester;
        $values['added_on'] = $now;
        $values['status'] = 'approved';
        $values['approved_by'] = $requester;
        $values['approved_on'] = $now;

        if (!$requestId = $this->request_model->insert($values)) {
            $this->error_message = $this->request_model->error() ?: 'Failed to create request.';
            return false;
        }

        if (!$this->_generate_request_services($requestId, $services, $requester, $now)) {
            return false;
        }
        if (!$this->_generate_request_shifts($requestId, $shifts, $requester, $now)) {
            return false;
        }
        if (!$this->_generate_request_consultations($requestId, $consultations, $requester, $now)) {
            return false;
        }

        return $requestId;
    }

    private function _generate_costing_from_endorsement(array $endorsement, $requestId, array $services, array $shifts, array $consultations, array $discounts, array $consultas, $requester, $now)
    {
        $values = $this->_copy_overlapping_fields($this->costing->allowedFields, $endorsement);
        if (!empty($endorsement['head_count'])) {
            $values['estimated_headcount'] = $endorsement['head_count'];
        }
        $values['request_id'] = $requestId;
        $values['version_no'] = $endorsement['version_no'] ?? 1;
        $values['added_by'] = $requester;
        $values['added_on'] = $now;
        $values['status'] = 'approved';
        $values['approved_by'] = $requester;
        $values['approved_on'] = $now;
        $values['approved_by_sales_manager'] = $requester;
        $values['approved_on_sales_manager'] = $now;
        $values['approved_by_sales_director'] = $requester;
        $values['approved_on_sales_director'] = $now;

        if (!$costingId = $this->costing->insert($values)) {
            $this->error_message = $this->costing->error() ?: 'Failed to create costing.';
            return false;
        }

        if (!$this->_generate_costing_services($costingId, $services, $requester, $now)) {
            return false;
        }
        if (!$this->_generate_costing_shifts($costingId, $shifts, $requester, $now)) {
            return false;
        }
        if (!$this->_generate_costing_consultations($costingId, $consultations, $requester, $now)) {
            return false;
        }
        if (!$this->_generate_costing_discounts($costingId, $discounts, $requester, $now)) {
            return false;
        }
        if (!$this->_generate_costing_consultas($costingId, $consultas, $requester, $now)) {
            return false;
        }

        return $costingId;
    }

    private function _generate_quotation_from_endorsement(array $endorsement, $requestId, $costingId, array $services, array $shifts, array $consultations, $requester, $now)
    {
        $values = $this->_copy_overlapping_fields($this->quotation->allowedFields, $endorsement);
        if (!empty($endorsement['head_count'])) {
            $values['head_count_total'] = $endorsement['head_count'];
        }
        if (empty($values['contact_email']) && !empty($endorsement['email'])) {
            $values['contact_email'] = $endorsement['email'];
        }
        $values['request_id'] = $requestId;
        $values['costing_id'] = $costingId;
        $values['version_no'] = $endorsement['version_no'] ?? 1;
        $values['quotation_no'] = 'QT-' . date('Ymd') . '-' . random_string('alnum', 6);
        $values['added_by'] = $requester;
        $values['added_on'] = $now;
        $values['status'] = 'approved';
        $values['approved_by'] = $requester;
        $values['approved_on'] = $now;
        $values['approved_by_sales'] = $requester;
        $values['approved_on_sales'] = $now;
        $values['approved_by_sales_manager'] = $requester;
        $values['approved_on_sales_manager'] = $now;
        $values['approved_by_sales_director'] = $requester;
        $values['approved_on_sales_director'] = $now;
        $values['approved_by_finance_manager'] = $requester;
        $values['approved_on_finance_manager'] = $now;

        if (!$quotationId = $this->quotation->insert($values)) {
            $this->error_message = $this->quotation->error() ?: 'Failed to create quotation.';
            return false;
        }

        if (!$this->_generate_quotation_services($quotationId, $services, $requester, $now)) {
            return false;
        }
        if (!$this->_generate_quotation_shifts($quotationId, $shifts, $requester, $now)) {
            return false;
        }
        if (!$this->_generate_quotation_consultations($quotationId, $consultations, $requester, $now)) {
            return false;
        }

        return $quotationId;
    }

    private function _generate_request_services($requestId, array $services, $requester, $now)
    {
        $sourceIdToDestId = [];
        foreach ($services as $service) {
            $values = [
                'request_id' => $requestId,
                'type' => $this->_generate_cell($service, 'type'),
                'type_id' => $this->_generate_cell($service, 'type_id'),
                'type_price' => $this->_generate_cell($service, 'type_price'),
                'type_cost' => $this->_generate_cell($service, 'cost', $this->_generate_cell($service, 'type_cost')),
                'branch_id' => $this->_generate_cell($service, 'branch_id'),
                'charge_to' => $this->_generate_cell($service, 'charge_to'),
                'charge_to_ids' => $this->_generate_cell($service, 'charge_to_ids'),
                ...$this->_service_add_on_fields($service),
                'head_count' => $this->_generate_cell($service, 'head_count'),
                'discount_amount' => $this->_generate_cell($service, 'discount_amount'),
                'margin_amount' => $this->_generate_cell($service, 'margin_amount'),
                'pre_negotiated_rate' => $this->_generate_cell($service, 'pre_negotiated_rate'),
                'is_guaranteed' => $this->_generate_cell($service, 'is_guaranteed', 0),
                'guaranteed_headcount' => $this->_generate_cell($service, 'guaranteed_headcount'),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$destId = $this->request_service->insert($values)) {
                $this->error_message = $this->request_service->error() ?: 'Failed to create request service.';
                return false;
            }
            if (!empty($service['id'])) {
                $sourceIdToDestId[(string) $service['id']] = (string) $destId;
            }
        }

        return $this->_remap_copied_service_package_ids($this->request_service, $services, $sourceIdToDestId);
    }

    private function _generate_request_shifts($requestId, array $shifts, $requester, $now)
    {
        foreach ($shifts as $shift) {
            $values = [
                'request_id' => $requestId,
                'name' => $this->_generate_cell($shift, 'name'),
                'start_shift' => $this->_generate_cell($shift, 'start_shift'),
                'end_shift' => $this->_generate_cell($shift, 'end_shift'),
                'registration_cut_off' => $this->_generate_cell($shift, 'registration_cut_off'),
                'specimen_submission_cut_off' => $this->_generate_cell($shift, 'specimen_submission_cut_off'),
                'total_hours' => $this->_generate_cell($shift, 'total_hours', 0),
                'break' => $this->_generate_cell($shift, 'break'),
                'ingress' => $this->_generate_cell($shift, 'ingress'),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->request_shift->insert($values)) {
                $this->error_message = $this->request_shift->error() ?: 'Failed to create request shift.';
                return false;
            }
        }

        return true;
    }

    private function _generate_request_consultations($requestId, array $consultations, $requester, $now)
    {
        foreach ($consultations as $consultation) {
            $companyId = $this->_generate_cell($consultation, 'company_id');
            $values = [
                'request_id' => $requestId,
                'company_id' => $companyId,
                'specialization_id' => $this->_generate_cell($consultation, 'specialization_id'),
                'fee_amount' => $this->_generate_cell($consultation, 'fee_amount', 0),
                'doctor_share' => $this->_generate_cell($consultation, 'doctor_share', 0),
                'doctor_share_amount' => $this->_generate_cell($consultation, 'doctor_share_amount', 0),
                'cpci_share' => $this->_generate_cell($consultation, 'cpci_share', 0),
                // 'discount_doctor_share' => $this->_generate_cell($consultation, 'discount_doctor_share', 0),
                // 'discount_cpci_share' => $this->_generate_cell($consultation, 'discount_cpci_share', 0),
                'ahmopi_fee' => $this->_generate_cell($consultation, 'ahmopi_fee', 0),
                'pahmoc_fee' => $this->_generate_cell($consultation, 'pahmoc_fee', 0),
                'charge_to' => $this->_generate_cell($consultation, 'charge_to', 'company'),
                'charge_to_ids' => $this->_generate_cell($consultation, 'charge_to_ids', $companyId),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->request_consultation->insert($values)) {
                $this->error_message = $this->request_consultation->error() ?: 'Failed to create request consultation.';
                return false;
            }
        }

        return true;
    }

    private function _generate_costing_services($costingId, array $services, $requester, $now)
    {
        $sourceIdToDestId = [];
        foreach ($services as $service) {
            $values = [
                'costing_id' => $costingId,
                'type' => $this->_generate_cell($service, 'type'),
                'type_id' => $this->_generate_cell($service, 'type_id'),
                'type_price' => $this->_generate_cell($service, 'type_price'),
                'margin' => $this->_generate_cell($service, 'margin', 0),
                'cost' => $this->_generate_cell($service, 'cost', 0),
                'pro_rate_price' => $this->_generate_cell($service, 'pro_rate_price', 0),
                'discount' => $this->_generate_cell($service, 'discount', 0),
                'branch_id' => $this->_generate_cell($service, 'branch_id'),
                'charge_to' => $this->_generate_cell($service, 'charge_to'),
                'charge_to_ids' => $this->_generate_cell($service, 'charge_to_ids'),
                ...$this->_service_add_on_fields($service),
                'head_count' => $this->_generate_cell($service, 'head_count'),
                'discount_amount' => $this->_generate_cell($service, 'discount_amount'),
                'margin_amount' => $this->_generate_cell($service, 'margin_amount'),
                'sales' => $this->_generate_cell($service, 'sales'),
                'admin_fee' => $this->_generate_cell($service, 'admin_fee'),
                'direct_cost' => $this->_generate_cell($service, 'direct_cost'),
                'manpower_cost' => $this->_generate_cell($service, 'manpower_cost'),
                'mobilization_cost' => $this->_generate_cell($service, 'mobilization_cost'),
                'total_cost' => $this->_generate_cell($service, 'total_cost'),
                'estimated_margin' => $this->_generate_cell($service, 'estimated_margin'),
                'direct_cost_amount_total' => $this->_generate_cell($service, 'direct_cost_amount_total'),
                'manpower_cost_amount_total' => $this->_generate_cell($service, 'manpower_cost_amount_total'),
                'mobilization_cost_amount_total' => $this->_generate_cell($service, 'mobilization_cost_amount_total'),
                'total_cost_amount_total' => $this->_generate_cell($service, 'total_cost_amount_total'),
                'estimated_margin_amount_total' => $this->_generate_cell($service, 'estimated_margin_amount_total'),
                'estimated_revenue' => $this->_generate_cell($service, 'estimated_revenue'),
                'pre_negotiated_rate' => $this->_generate_cell($service, 'pre_negotiated_rate'),
                'is_guaranteed' => $this->_generate_cell($service, 'is_guaranteed', 0),
                'guaranteed_headcount' => $this->_generate_cell($service, 'guaranteed_headcount'),
                'theoretical_cost' => $this->_generate_cell($service, 'theoretical_cost'),
                'total_amount' => $this->_generate_cell($service, 'total_amount', 0),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$costingServiceId = $this->costing_service->insert($values)) {
                $this->error_message = $this->costing_service->error() ?: 'Failed to create costing service.';
                return false;
            }
            if (!empty($service['id'])) {
                $sourceIdToDestId[(string) $service['id']] = (string) $costingServiceId;
            }

            if (($service['type'] ?? null) === 'package' && !empty($service['id'])) {
                $packageDetails = $this->endorsement_service_package_detail->get_details($service['id']) ?: [];
                if (!$this->_generate_costing_service_package_details($costingServiceId, $costingId, $packageDetails, $requester, $now)) {
                    return false;
                }
            }
        }

        return $this->_remap_copied_service_package_ids($this->costing_service, $services, $sourceIdToDestId);
    }

    private function _generate_costing_service_package_details($costingServiceId, $costingId, array $packageDetails, $requester, $now)
    {
        foreach ($packageDetails as $detail) {
            $values = [
                'costing_service_id' => $costingServiceId,
                'costing_id' => $costingId,
                'package_id' => $this->_generate_cell($detail, 'package_id'),
                'lab_test_id' => $this->_generate_cell($detail, 'lab_test_id'),
                'is_add_on' => $this->_generate_cell($detail, 'is_add_on', 0),
                'price' => $this->_generate_cell($detail, 'price', 0),
                'margin' => $this->_generate_cell($detail, 'margin', 0),
                'cost' => $this->_generate_cell($detail, 'cost', 0),
                'pro_rate_price' => $this->_generate_cell($detail, 'pro_rate_price', 0),
                'discount' => $this->_generate_cell($detail, 'discount', 0),
                'branch_id' => $this->_generate_cell($detail, 'branch_id'),
                'charge_to' => $this->_generate_cell($detail, 'charge_to'),
                'head_count' => $this->_generate_cell($detail, 'head_count'),
                'discount_amount' => $this->_generate_cell($detail, 'discount_amount'),
                'margin_amount' => $this->_generate_cell($detail, 'margin_amount'),
                'sales' => $this->_generate_cell($detail, 'sales'),
                'admin_fee' => $this->_generate_cell($detail, 'admin_fee'),
                'direct_cost' => $this->_generate_cell($detail, 'direct_cost'),
                'manpower_cost' => $this->_generate_cell($detail, 'manpower_cost'),
                'mobilization_cost' => $this->_generate_cell($detail, 'mobilization_cost'),
                'total_cost' => $this->_generate_cell($detail, 'total_cost'),
                'estimated_margin' => $this->_generate_cell($detail, 'estimated_margin'),
                'direct_cost_amount_total' => $this->_generate_cell($detail, 'direct_cost_amount_total'),
                'manpower_cost_amount_total' => $this->_generate_cell($detail, 'manpower_cost_amount_total'),
                'mobilization_cost_amount_total' => $this->_generate_cell($detail, 'mobilization_cost_amount_total'),
                'total_cost_amount_total' => $this->_generate_cell($detail, 'total_cost_amount_total'),
                'estimated_margin_amount_total' => $this->_generate_cell($detail, 'estimated_margin_amount_total'),
                'estimated_revenue' => $this->_generate_cell($detail, 'estimated_revenue'),
                'pre_negotiated_rate' => $this->_generate_cell($detail, 'pre_negotiated_rate'),
                'theoretical_cost' => $this->_generate_cell($detail, 'theoretical_cost'),
                'total_amount' => $this->_generate_cell($detail, 'total_amount', 0),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->costing_service_package_detail->insert($values)) {
                $this->error_message = $this->costing_service_package_detail->error() ?: 'Failed to create costing service package detail.';
                return false;
            }
        }

        return true;
    }

    private function _generate_costing_shifts($costingId, array $shifts, $requester, $now)
    {
        foreach ($shifts as $shift) {
            $values = [
                'costing_id' => $costingId,
                'name' => $this->_generate_cell($shift, 'name'),
                'start_shift' => $this->_generate_cell($shift, 'start_shift'),
                'end_shift' => $this->_generate_cell($shift, 'end_shift'),
                'registration_cut_off' => $this->_generate_cell($shift, 'registration_cut_off'),
                'specimen_submission_cut_off' => $this->_generate_cell($shift, 'specimen_submission_cut_off'),
                'total_hours' => $this->_generate_cell($shift, 'total_hours', 0),
                'break' => $this->_generate_cell($shift, 'break'),
                'ingress' => $this->_generate_cell($shift, 'ingress'),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->costing_shift->insert($values)) {
                $this->error_message = $this->costing_shift->error() ?: 'Failed to create costing shift.';
                return false;
            }
        }

        return true;
    }

    private function _generate_costing_consultations($costingId, array $consultations, $requester, $now)
    {
        foreach ($consultations as $consultation) {
            $companyId = $this->_generate_cell($consultation, 'company_id');
            $values = [
                'costing_id' => $costingId,
                'company_id' => $companyId,
                'specialization_id' => $this->_generate_cell($consultation, 'specialization_id'),
                'fee_amount' => $this->_generate_cell($consultation, 'fee_amount', 0),
                'doctor_share' => $this->_generate_cell($consultation, 'doctor_share', 0),
                'doctor_share_amount' => $this->_generate_cell($consultation, 'doctor_share_amount', 0),
                'cpci_share' => $this->_generate_cell($consultation, 'cpci_share', 0),
                // 'discount_doctor_share' => $this->_generate_cell($consultation, 'discount_doctor_share', 0),
                // 'discount_cpci_share' => $this->_generate_cell($consultation, 'discount_cpci_share', 0),
                'ahmopi_fee' => $this->_generate_cell($consultation, 'ahmopi_fee', 0),
                'pahmoc_fee' => $this->_generate_cell($consultation, 'pahmoc_fee', 0),
                'charge_to' => $this->_generate_cell($consultation, 'charge_to', 'company'),
                'charge_to_ids' => $this->_generate_cell($consultation, 'charge_to_ids', $companyId),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->costing_consultation->insert($values)) {
                $this->error_message = $this->costing_consultation->error() ?: 'Failed to create costing consultation.';
                return false;
            }
        }

        return true;
    }

    private function _generate_costing_discounts($costingId, array $discounts, $requester, $now)
    {
        foreach ($discounts as $discount) {
            $values = [
                'costing_id' => $costingId,
                'type' => $this->_generate_cell($discount, 'type'),
                'type_id' => $this->_generate_cell($discount, 'type_id'),
                'type_price' => $this->_generate_cell($discount, 'type_price'),
                'discount' => $this->_generate_cell($discount, 'discount', 0),
                'discount_amount' => $this->_generate_cell($discount, 'discount_amount', 0),
                'total_amount' => $this->_generate_cell($discount, 'total_amount', 0),
                'branch_id' => $this->_generate_cell($discount, 'branch_id'),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->costing_discount->insert($values)) {
                $this->error_message = $this->costing_discount->error() ?: 'Failed to create costing discount.';
                return false;
            }
        }

        return true;
    }

    private function _generate_costing_consultas($costingId, array $consultas, $requester, $now)
    {
        foreach ($consultas as $consulta) {
            $values = [
                'costing_id' => $costingId,
                'type' => $this->_generate_cell($consulta, 'type'),
                'type_id' => $this->_generate_cell($consulta, 'type_id'),
                'type_price' => $this->_generate_cell($consulta, 'type_price'),
                'mark_up' => $this->_generate_cell($consulta, 'mark_up', 0),
                'mark_up_amount' => $this->_generate_cell($consulta, 'mark_up_amount', 0),
                'branch_id' => $this->_generate_cell($consulta, 'branch_id'),
                'head_count' => $this->_generate_cell($consulta, 'head_count', $this->_generate_cell($consulta, 'headcount')),
                'total_amount' => $this->_generate_cell($consulta, 'total_amount', 0),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->costing_consulta->insert($values)) {
                $this->error_message = $this->costing_consulta->error() ?: 'Failed to create costing consulta.';
                return false;
            }
        }

        return true;
    }

    private function _generate_quotation_services($quotationId, array $services, $requester, $now)
    {
        $sourceIdToDestId = [];
        foreach ($services as $service) {
            $values = [
                'quotation_id' => $quotationId,
                'type' => $this->_generate_cell($service, 'type'),
                'type_id' => $this->_generate_cell($service, 'type_id'),
                'type_price' => $this->_generate_cell($service, 'type_price'),
                'margin' => $this->_generate_cell($service, 'margin', 0),
                'cost' => $this->_generate_cell($service, 'cost', 0),
                'pro_rate_price' => $this->_generate_cell($service, 'pro_rate_price', 0),
                'discount' => $this->_generate_cell($service, 'discount', 0),
                'branch_id' => $this->_generate_cell($service, 'branch_id'),
                'charge_to' => $this->_generate_cell($service, 'charge_to'),
                'charge_to_ids' => $this->_generate_cell($service, 'charge_to_ids'),
                ...$this->_service_add_on_fields($service),
                'head_count' => $this->_generate_cell($service, 'head_count'),
                'discount_amount' => $this->_generate_cell($service, 'discount_amount'),
                'margin_amount' => $this->_generate_cell($service, 'margin_amount'),
                'sales' => $this->_generate_cell($service, 'sales'),
                'admin_fee' => $this->_generate_cell($service, 'admin_fee'),
                'direct_cost' => $this->_generate_cell($service, 'direct_cost'),
                'manpower_cost' => $this->_generate_cell($service, 'manpower_cost'),
                'mobilization_cost' => $this->_generate_cell($service, 'mobilization_cost'),
                'total_cost' => $this->_generate_cell($service, 'total_cost'),
                'estimated_margin' => $this->_generate_cell($service, 'estimated_margin'),
                'direct_cost_amount_total' => $this->_generate_cell($service, 'direct_cost_amount_total'),
                'manpower_cost_amount_total' => $this->_generate_cell($service, 'manpower_cost_amount_total'),
                'mobilization_cost_amount_total' => $this->_generate_cell($service, 'mobilization_cost_amount_total'),
                'total_cost_amount_total' => $this->_generate_cell($service, 'total_cost_amount_total'),
                'estimated_margin_amount_total' => $this->_generate_cell($service, 'estimated_margin_amount_total'),
                'estimated_revenue' => $this->_generate_cell($service, 'estimated_revenue'),
                'pre_negotiated_rate' => $this->_generate_cell($service, 'pre_negotiated_rate'),
                'is_guaranteed' => $this->_generate_cell($service, 'is_guaranteed', 0),
                'guaranteed_headcount' => $this->_generate_cell($service, 'guaranteed_headcount'),
                'theoretical_cost' => $this->_generate_cell($service, 'theoretical_cost'),
                'total_amount' => $this->_generate_cell($service, 'total_amount', 0),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$quotationServiceId = $this->quotation_service->insert($values)) {
                $this->error_message = $this->quotation_service->error() ?: 'Failed to create quotation service.';
                return false;
            }
            if (!empty($service['id'])) {
                $sourceIdToDestId[(string) $service['id']] = (string) $quotationServiceId;
            }

            if (($service['type'] ?? null) === 'package' && !empty($service['id'])) {
                $packageDetails = $this->endorsement_service_package_detail->get_details($service['id']) ?: [];
                if (!$this->_generate_quotation_service_package_details($quotationServiceId, $quotationId, $packageDetails, $requester, $now)) {
                    return false;
                }
            }
        }

        return $this->_remap_copied_service_package_ids($this->quotation_service, $services, $sourceIdToDestId);
    }

    private function _generate_quotation_service_package_details($quotationServiceId, $quotationId, array $packageDetails, $requester, $now)
    {
        foreach ($packageDetails as $detail) {
            $values = [
                'quotation_service_id' => $quotationServiceId,
                'quotation_id' => $quotationId,
                'package_id' => $this->_generate_cell($detail, 'package_id'),
                'lab_test_id' => $this->_generate_cell($detail, 'lab_test_id'),
                'is_add_on' => $this->_generate_cell($detail, 'is_add_on', 0),
                'price' => $this->_generate_cell($detail, 'price', 0),
                'margin' => $this->_generate_cell($detail, 'margin', 0),
                'cost' => $this->_generate_cell($detail, 'cost', 0),
                'pro_rate_price' => $this->_generate_cell($detail, 'pro_rate_price', 0),
                'discount' => $this->_generate_cell($detail, 'discount', 0),
                'branch_id' => $this->_generate_cell($detail, 'branch_id'),
                'charge_to' => $this->_generate_cell($detail, 'charge_to'),
                'head_count' => $this->_generate_cell($detail, 'head_count'),
                'discount_amount' => $this->_generate_cell($detail, 'discount_amount'),
                'margin_amount' => $this->_generate_cell($detail, 'margin_amount'),
                'sales' => $this->_generate_cell($detail, 'sales'),
                'admin_fee' => $this->_generate_cell($detail, 'admin_fee'),
                'direct_cost' => $this->_generate_cell($detail, 'direct_cost'),
                'manpower_cost' => $this->_generate_cell($detail, 'manpower_cost'),
                'mobilization_cost' => $this->_generate_cell($detail, 'mobilization_cost'),
                'total_cost' => $this->_generate_cell($detail, 'total_cost'),
                'estimated_margin' => $this->_generate_cell($detail, 'estimated_margin'),
                'direct_cost_amount_total' => $this->_generate_cell($detail, 'direct_cost_amount_total'),
                'manpower_cost_amount_total' => $this->_generate_cell($detail, 'manpower_cost_amount_total'),
                'mobilization_cost_amount_total' => $this->_generate_cell($detail, 'mobilization_cost_amount_total'),
                'total_cost_amount_total' => $this->_generate_cell($detail, 'total_cost_amount_total'),
                'estimated_margin_amount_total' => $this->_generate_cell($detail, 'estimated_margin_amount_total'),
                'estimated_revenue' => $this->_generate_cell($detail, 'estimated_revenue'),
                'pre_negotiated_rate' => $this->_generate_cell($detail, 'pre_negotiated_rate'),
                'theoretical_cost' => $this->_generate_cell($detail, 'theoretical_cost'),
                'total_amount' => $this->_generate_cell($detail, 'total_amount', 0),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->quotation_service_package_detail->insert($values)) {
                $this->error_message = $this->quotation_service_package_detail->error() ?: 'Failed to create quotation service package detail.';
                return false;
            }
        }

        return true;
    }

    private function _generate_quotation_shifts($quotationId, array $shifts, $requester, $now)
    {
        foreach ($shifts as $shift) {
            $values = [
                'quotation_id' => $quotationId,
                'name' => $this->_generate_cell($shift, 'name'),
                'start_shift' => $this->_generate_cell($shift, 'start_shift'),
                'end_shift' => $this->_generate_cell($shift, 'end_shift'),
                'registration_cut_off' => $this->_generate_cell($shift, 'registration_cut_off'),
                'specimen_submission_cut_off' => $this->_generate_cell($shift, 'specimen_submission_cut_off'),
                'total_hours' => $this->_generate_cell($shift, 'total_hours', 0),
                'break' => $this->_generate_cell($shift, 'break'),
                'ingress' => $this->_generate_cell($shift, 'ingress'),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->quotation_shift->insert($values)) {
                $this->error_message = $this->quotation_shift->error() ?: 'Failed to create quotation shift.';
                return false;
            }
        }

        return true;
    }

    private function _generate_quotation_consultations($quotationId, array $consultations, $requester, $now)
    {
        foreach ($consultations as $consultation) {
            $companyId = $this->_generate_cell($consultation, 'company_id');
            $values = [
                'quotation_id' => $quotationId,
                'company_id' => $companyId,
                'specialization_id' => $this->_generate_cell($consultation, 'specialization_id'),
                'fee_amount' => $this->_generate_cell($consultation, 'fee_amount', 0),
                'doctor_share' => $this->_generate_cell($consultation, 'doctor_share', 0),
                'doctor_share_amount' => $this->_generate_cell($consultation, 'doctor_share_amount', 0),
                'cpci_share' => $this->_generate_cell($consultation, 'cpci_share', 0),
                // 'discount_doctor_share' => $this->_generate_cell($consultation, 'discount_doctor_share', 0),
                // 'discount_cpci_share' => $this->_generate_cell($consultation, 'discount_cpci_share', 0),
                'ahmopi_fee' => $this->_generate_cell($consultation, 'ahmopi_fee', 0),
                'pahmoc_fee' => $this->_generate_cell($consultation, 'pahmoc_fee', 0),
                'charge_to' => $this->_generate_cell($consultation, 'charge_to', 'company'),
                'charge_to_ids' => $this->_generate_cell($consultation, 'charge_to_ids', $companyId),
                'added_by' => $requester,
                'added_on' => $now,
            ];

            if (!$this->quotation_consultation->insert($values)) {
                $this->error_message = $this->quotation_consultation->error() ?: 'Failed to create quotation consultation.';
                return false;
            }
        }

        return true;
    }

    private function _copy_overlapping_fields(array $allowedFields, array $source): array
    {
        $skip = ['id', 'request_id', 'costing_id', 'quotation_id', 'status', 'added_by', 'added_on', 'updated_by', 'updated_on'];
        $values = [];
        foreach ($allowedFields as $field) {
            if (in_array($field, $skip, true)) {
                continue;
            }
            if (!array_key_exists($field, $source) || $source[$field] === '' || $source[$field] === null) {
                continue;
            }
            $values[$field] = $source[$field];
        }
        return $values;
    }

    private function _generate_cell($row, $key, $default = null)
    {
        if (!is_array($row) || !array_key_exists($key, $row) || $row[$key] === '' || $row[$key] === null) {
            return $default;
        }
        return $row[$key];
    }

    private function _is_missing_id($value): bool
    {
        return $value === null || $value === '' || (int) $value === 0;
    }

    private function _service_add_on_fields(array $service): array
    {
        return service_line_pending_add_on_fields();
    }

    /**
    * Check duplicate
    */
    protected function _has_duplicate($table, $where, $id = null)
    {
        $id ? ($where['id <>'] = $id) : null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }
}