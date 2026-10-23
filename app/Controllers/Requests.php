<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Request;
use App\Models\Request_detail;
use App\Models\Request_service;
use App\Models\Request_shift;
use App\Models\Request_consultation;
use App\Models\Request_history;
use App\Models\Package;
use App\Models\Package_detail;
use App\Models\User;
use App\Models\Branch_master;
use App\Models\Company;
use App\Models\Specialization_emr;
use App\Models\Costing;
use App\Models\Costing_detail;
use App\Models\Costing_service;
use App\Models\Costing_consulta;
use App\Models\Costing_discount;
use App\Models\Costing_manpower;
use App\Models\Costing_manpower_cost;
use App\Models\Costing_manpower_cost_premium_pay;
use App\Models\Costing_mobilization;
use App\Models\Costing_shift;
use App\Models\Costing_consultation;
use App\Models\Costing_service_package_detail;
use App\Models\Costing_history;
use App\Models\Quotation;
use App\Models\Quotation_detail;
use App\Models\Quotation_service;
use App\Models\Quotation_shift;
use App\Models\Quotation_consultation;
use App\Models\Endorsement;
use App\Models\Endorsement_service;
use App\Models\Endorsement_shift;
use App\Models\Endorsement_consultation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Requests extends MYT_Controller
{
    protected $request;
    protected $request_model;
    protected $request_detail;
    protected $request_service;
    protected $request_shift;
    protected $request_consultation;
    protected $request_history;
    protected $package;
    protected $package_detail;
    protected $user;
    protected $branch_master;
    protected $company;
    protected $specialization_emr;
    protected $costing;
    protected $costing_detail;
    protected $costing_service;
    protected $costing_consulta;
    protected $costing_discount;
    protected $costing_manpower;
    protected $costing_manpower_cost;
    protected $costing_manpower_cost_premium_pay;
    protected $costing_mobilization;
    protected $costing_shift;
    protected $costing_consultation;
    protected $costing_service_package_detail;
    protected $costing_history;
    protected $quotation;
    protected $quotation_detail;
    protected $quotation_service;
    protected $quotation_shift;
    protected $quotation_consultation;
    protected $endorsement;
    protected $endorsement_service;
    protected $endorsement_shift;
    protected $endorsement_consultation;
    protected $helpers = ['text', 'email_notification'];

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
        $this->request_model = new Request();
        $this->request_detail = new Request_detail();
        $this->request_service = new Request_service();
        $this->request_shift = new Request_shift();
        $this->request_consultation = new Request_consultation();
        $this->request_history = new Request_history();
        $this->package = new Package();
        $this->package_detail = new Package_detail();
        $this->user = new User();
        $this->branch_master = new Branch_master();
        $this->company = new Company();
        $this->specialization_emr = new Specialization_emr();
        $this->costing = new Costing();
        $this->costing_detail = new Costing_detail();
        $this->costing_service = new Costing_service();
        $this->costing_consulta = new Costing_consulta();
        $this->costing_discount = new Costing_discount();
        $this->costing_manpower = new Costing_manpower();
        $this->costing_manpower_cost = new Costing_manpower_cost();
        $this->costing_manpower_cost_premium_pay = new Costing_manpower_cost_premium_pay();
        $this->costing_mobilization = new Costing_mobilization();
        $this->costing_shift = new Costing_shift();
        $this->costing_consultation = new Costing_consultation();
        $this->costing_service_package_detail = new Costing_service_package_detail();
        $this->costing_history = new Costing_history();
        $this->quotation = new Quotation();
        $this->quotation_detail = new Quotation_detail();
        $this->quotation_service = new Quotation_service();
        $this->quotation_shift = new Quotation_shift();
        $this->quotation_consultation = new Quotation_consultation();
        $this->endorsement = new Endorsement();
        $this->endorsement_service = new Endorsement_service();
        $this->endorsement_shift = new Endorsement_shift();
        $this->endorsement_consultation = new Endorsement_consultation();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get requests
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        $request_added_by = (int) $user->role_id === 19
            ? (int) $user->id
            : null;

        $request_id = $this->payload['request_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $screen = $this->payload['screen'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $requests = $this->request_model->get_details($request_id, $status, $company_id, $screen, $date_from, $date_to, $term, $page, $data_size, $request_added_by)?:[];

        if(!$requests) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No requests found.'
            ];
        } else {
            $requestIds = $this->_collect_ids($requests, 'id');
            $userIds = $this->_collect_ids($requests, 'sae_in_charge_id');
            $branchIds = $this->_collect_ids($requests, 'preferred_branch_id');
            $customerAccountIds = $this->_collect_ids($requests, 'customer_account_id');

            $detailsByRequest = $this->_group_by($this->request_detail->get_details($requestIds) ?: [], 'request_id');
            $servicesByRequest = $this->_group_by($this->request_service->get_details($requestIds) ?: [], 'request_id');
            $shiftsByRequest = $this->_group_by($this->request_shift->get_details($requestIds) ?: [], 'request_id');
            $consultationsByRequest = $this->_group_by($this->request_consultation->get_details($requestIds) ?: [], 'request_id');
            $historiesByRequest = $this->_group_by($this->request_history->get_details($requestIds) ?: [], 'source_id');

            $packageIds = [];
            foreach ($servicesByRequest as $services) {
                foreach ($services as $service) {
                    if (($service['type'] ?? null) === 'package' && !empty($service['type_id'])) {
                        $packageIds[] = $service['type_id'];
                    }
                }
            }
            $packageDetailsByPackage = $this->_group_by($this->package_detail->get_details(array_values(array_unique($packageIds))) ?: [], 'package_id');

            $lastCostings = $this->costing->get_last_details($requestIds) ?: [];
            $lastCostingsByRequest = $this->_group_by($lastCostings, 'request_id');
            $costingIds = $this->_collect_ids($lastCostings, 'id');
            $userIds = array_values(array_unique(array_merge(
                $userIds,
                $this->_collect_ids($lastCostings, 'sae_in_charge_id'),
                $this->_collect_grouped_ids($historiesByRequest, 'added_by')
            )));
            $branchIds = array_values(array_unique(array_merge($branchIds, $this->_collect_ids($lastCostings, 'preferred_branch_id'))));
            $customerAccountIds = array_values(array_unique(array_merge($customerAccountIds, $this->_collect_ids($lastCostings, 'customer_account_id'))));

            $usersById = $this->user->get_by_ids($userIds);
            $branchNamesById = $this->branch_master->get_names_by_ids($branchIds);
            $customerAccountsById = [];
            if ($customerAccountIds) {
                foreach ($this->company->get_customer_accounts(implode(',', $customerAccountIds)) ?: [] as $account) {
                    $customerAccountsById[$account['company_id']][] = $account;
                }
            }

            $costingDetailsByCosting = $this->_group_by($this->costing_detail->get_details($costingIds) ?: [], 'costing_id');
            $costingServicesByCosting = $this->_group_by($this->costing_service->get_details($costingIds) ?: [], 'costing_id');
            $costingConsultasByCosting = $this->_group_by($this->costing_consulta->get_details($costingIds) ?: [], 'costing_id');
            $costingDiscountsByCosting = $this->_group_by($this->costing_discount->get_details($costingIds) ?: [], 'costing_id');
            $costingManpowersByCosting = $this->_group_by($this->costing_manpower->get_details($costingIds) ?: [], 'costing_id');
            $costingManpowerCostsByCosting = $this->_group_by($this->costing_manpower_cost->get_details($costingIds) ?: [], 'costing_id');
            $costingMobilizationsByCosting = $this->_group_by($this->costing_mobilization->get_details($costingIds) ?: [], 'costing_id');
            $costingShiftsByCosting = $this->_group_by($this->costing_shift->get_details($costingIds) ?: [], 'costing_id');
            $costingConsultationsByCosting = $this->_group_by($this->costing_consultation->get_details($costingIds) ?: [], 'costing_id');
            $costingHistoriesByCosting = $this->_group_by($this->costing_history->get_details($costingIds) ?: [], 'source_id');
            $missingHistoryUserIds = array_values(array_diff(
                $this->_collect_grouped_ids($costingHistoriesByCosting, 'added_by'),
                array_keys($usersById)
            ));
            if ($missingHistoryUserIds) {
                $usersById += $this->user->get_by_ids($missingHistoryUserIds);
            }

            $costingServiceIds = [];
            foreach ($costingServicesByCosting as $services) {
                foreach ($services as $service) {
                    if (($service['type'] ?? null) === 'package') {
                        $costingServiceIds[] = $service['id'];
                    }
                }
            }
            $costingPkgByService = $this->_group_by(
                $this->costing_service_package_detail->get_details(array_values(array_unique($costingServiceIds))) ?: [],
                'costing_service_id'
            );

            $manpowerCostIds = [];
            foreach ($costingManpowerCostsByCosting as $manpowerCosts) {
                foreach ($manpowerCosts as $mc) {
                    $manpowerCostIds[] = $mc['id'];
                }
            }
            $premiumPaysByManpowerCost = $this->_group_by(
                $this->costing_manpower_cost_premium_pay->get_details($costingIds, array_values(array_unique($manpowerCostIds))) ?: [],
                'costing_manpower_cost_id'
            );

            foreach ($requests as $key => $request) {
                $rid = $request['id'];
                if (!empty($request['sae_in_charge_id']) && isset($usersById[$request['sae_in_charge_id']])) {
                    $requests[$key]['sae_in_charge_name'] = $usersById[$request['sae_in_charge_id']]['name'];
                }

                $preferredBranchId = $request['preferred_branch_id'] ?? null;
                $requests[$key]['preferred_branch_name'] = ($preferredBranchId !== null && $preferredBranchId !== 'all' && isset($branchNamesById[$preferredBranchId]))
                    ? $branchNamesById[$preferredBranchId]
                    : null;

                if (!empty($request['customer_account_id'])) {
                    $requests[$key]['customer_account_details'] = $this->_customer_account_details($customerAccountsById, $request['customer_account_id']);
                }

                $requests[$key]['details'] = $detailsByRequest[$rid] ?? [];
                $services = $servicesByRequest[$rid] ?? [];
                foreach ($services as $s => $service) {
                    if (($service['type'] ?? null) === 'package') {
                        $services[$s]['details'] = $packageDetailsByPackage[$service['type_id']] ?? [];
                    }
                }
                $requests[$key]['services'] = $services;
                $requests[$key]['shifts'] = $shiftsByRequest[$rid] ?? [];
                $requests[$key]['consultations'] = $consultationsByRequest[$rid] ?? [];
                $requests[$key]['histories'] = $historiesByRequest[$rid] ?? [];
                $requests[$key] = $this->_apply_reason_trails($requests[$key], $requests[$key]['histories'], $usersById);

                $costings = $lastCostingsByRequest[$rid] ?? [];
                foreach ($costings as $lc => $costing) {
                    $cid = $costing['id'];
                    if (!empty($costing['sae_in_charge_id']) && isset($usersById[$costing['sae_in_charge_id']])) {
                        $costings[$lc]['sae_in_charge_name'] = $usersById[$costing['sae_in_charge_id']]['name'];
                    }

                    $cBranchId = $costing['preferred_branch_id'] ?? null;
                    $costings[$lc]['preferred_branch_name'] = ($cBranchId !== null && $cBranchId !== 'all' && isset($branchNamesById[$cBranchId]))
                        ? $branchNamesById[$cBranchId]
                        : null;

                    if (!empty($costing['customer_account_id'])) {
                        $costings[$lc]['customer_account_details'] = $this->_customer_account_details($customerAccountsById, $costing['customer_account_id']);
                    }

                    $costings[$lc]['details'] = $costingDetailsByCosting[$cid] ?? [];
                    $cServices = $costingServicesByCosting[$cid] ?? [];
                    foreach ($cServices as $c_key => $service) {
                        if (($service['type'] ?? null) === 'package') {
                            $cServices[$c_key]['details'] = $costingPkgByService[$service['id']] ?? [];
                        }
                    }
                    $costings[$lc]['services'] = $cServices;
                    $costings[$lc]['consultas'] = $costingConsultasByCosting[$cid] ?? [];
                    $costings[$lc]['discounts'] = $costingDiscountsByCosting[$cid] ?? [];
                    $costings[$lc]['manpowers'] = $costingManpowersByCosting[$cid] ?? [];
                    $manpowerCosts = $costingManpowerCostsByCosting[$cid] ?? [];
                    foreach ($manpowerCosts as $c_key => $manpower_cost) {
                        $manpowerCosts[$c_key]['premium_pays'] = $premiumPaysByManpowerCost[$manpower_cost['id']] ?? [];
                    }
                    $costings[$lc]['manpower_costs'] = $manpowerCosts;
                    $costings[$lc]['mobilizations'] = $costingMobilizationsByCosting[$cid] ?? [];
                    $costings[$lc]['shifts'] = $costingShiftsByCosting[$cid] ?? [];
                    $costings[$lc]['consultations'] = $costingConsultationsByCosting[$cid] ?? [];
                    $costings[$lc]['histories'] = $costingHistoriesByCosting[$cid] ?? [];
                    $costings[$lc] = $this->_apply_reason_trails($costings[$lc], $costings[$lc]['histories'], $usersById);
                }
                $requests[$key]['last_costings'] = $costings;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $this->request_model->get_count($request_id, $status, $company_id, $screen, $date_from, $date_to, $term, $request_added_by),
                'data' => $requests
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create request
     */
        public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }
 
        $this->db->transBegin();
 
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
 
        if (!$request_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add request. ",
            ];
        } elseif (!in_array($user->role_id, [4,19,20,21])) {
            $response_status = FAIL;
            $response = [
                "Only Sales, Sales Manager, Sales Director, or Admin users can create requests. ",
            ];
        } elseif (!$this->_attempt_create_details($request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add request details. ",
            ];
        } elseif (!$this->_attempt_create_services($request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add request services. ",
            ];
        } elseif (!$this->_attempt_create_shifts($request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add request shifts. ",
            ];
        } elseif (!$this->_attempt_create_consultations($request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add request consultations. ",
            ];
        } elseif (!$this->_send_created_notifications($request_id, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send email notification. ",
            ];
        } elseif (in_array($user->role_id, [ROLE_SALES_MANAGER, ROLE_SALES_DIRECTOR]) && !$this->send_email_notif('request_for_costing', $request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send email notification. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'request_id' => $request_id,
                'message' => in_array($user->role_id, [20, 21])
                ? 'request automatically approved and now for costing.'
                : 'request added successfully.'
            ];
        }
 
        return $this->_create_response($response_status, $response);
    }

    private function _send_created_notifications($request_id, $role_id)
    {
        $results = [];

        if (!in_array($role_id, [ROLE_SALES_MANAGER, ROLE_SALES_DIRECTOR])) {
            $results['request_for_approval'] = $this->send_email_notif('request_for_approval', $request_id);
        }

        $results['created_request'] = $this->send_email_notif('created_request', $request_id);

        return !in_array(false, $results, true);
    }

    /**
     * Attempt to create a request
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->request_model->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $creator = $this->user->select('role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        if ($creator && in_array($creator->role_id, [ROLE_SALES_MANAGER, ROLE_SALES_DIRECTOR])) {
            $values['status'] = 'approved';
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
            $values['approved_reason'] = $this->payload['approved_reason'] ?? null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$request_id = $this->request_model->insert($values)) {
            $this->error_message = $this->request_model->error();
            return false;
        }

        return $request_id;
    }

    /**
     * Attempt to create request details
     */
    private function _attempt_create_details($request_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'request_id'    => $request_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'branch_id'   => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->request_detail->insert($values)) {
                $this->error_message = $this->request_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create request services
     */
    private function _attempt_create_services($request_id)
    {
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services']) || count($this->payload['services']) == 0) {
            return true;
        }

        $keyToId = [];
        $indexToId = [];
        foreach ($this->payload['services'] as $index => $service) {
            $values = [
                'request_id'    => $request_id,
                'type'              => array_key_exists('type', $service) && $service['type'] != '' ? $service['type'] : null,
                'type_id'           => array_key_exists('type_id', $service) && $service['type_id'] != '' ? $service['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $service) && $service['type_price'] != '' ? $service['type_price'] : null,
                'type_cost'           => array_key_exists('type_cost', $service) && $service['type_cost'] != '' ? $service['type_cost'] : null,
                'branch_id'           => array_key_exists('branch_id', $service) && $service['branch_id'] != '' ? $service['branch_id'] : null,
                'charge_to'           => array_key_exists('charge_to', $service) && $service['charge_to'] != '' ? $service['charge_to'] : null,
                'charge_to_ids'           => array_key_exists('charge_to_ids', $service) && $service['charge_to_ids'] != '' ? $service['charge_to_ids'] : null,
                ...$this->_service_add_on_fields($service),
                'head_count'           => array_key_exists('head_count', $service) && $service['head_count'] != '' ? $service['head_count'] : null,
                'discount_amount'           => array_key_exists('discount_amount', $service) && $service['discount_amount'] != '' ? $service['discount_amount'] : null,
                'margin_amount'           => array_key_exists('margin_amount', $service) && $service['margin_amount'] != '' ? $service['margin_amount'] : null,
                'is_guaranteed'           => array_key_exists('is_guaranteed', $service) && $service['is_guaranteed'] != '' ? $service['is_guaranteed'] : 0,
                'guaranteed_headcount'           => array_key_exists('guaranteed_headcount', $service) && $service['guaranteed_headcount'] != '' ? $service['guaranteed_headcount'] : null,
                'pre_negotiated_rate'           => array_key_exists('pre_negotiated_rate', $service) && $service['pre_negotiated_rate'] != '' ? $service['pre_negotiated_rate'] : null,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];

            if (!$serviceId = $this->request_service->insert($values)) {
                $this->error_message = $this->request_service->error();
                return false;
            }
            $indexToId[$index] = (string) $serviceId;
            service_line_register_ids($keyToId, $service, $serviceId);
        }

        return $this->_rewrite_service_line_package_ids($this->request_service, $this->payload['services'], $keyToId, $indexToId);
    }

    /**
     * Attempt to create request shifts
     */
    private function _attempt_create_shifts($request_id)
    {
        if (!array_key_exists('shifts', $this->payload) || !is_array($this->payload['shifts']) || count($this->payload['shifts']) == 0) {
            return true;
        }

        foreach ($this->payload['shifts'] as $shift) {
            $values = [
                'request_id' => $request_id,
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

            if (!$this->request_shift->insert($values)) {
                $this->error_message = $this->request_shift->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create request consultations
     */
    private function _attempt_create_consultations($request_id)
    {
        if (!array_key_exists('consultations', $this->payload) || !is_array($this->payload['consultations']) || count($this->payload['consultations']) == 0) {
            return true;
        }

        foreach ($this->payload['consultations'] as $consultation) {
            $values = [
                'request_id' => $request_id,
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

            if (!$this->request_consultation->insert($values)) {
                $this->error_message = $this->request_consultation->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Activate an inactive company linked to the saved request.
     */
    private function _attempt_activate_company()
    {
        $company_id = $this->payload['company_id'] ?? null;
        if (empty($company_id) || !is_numeric($company_id) || $company_id != null) {
            return true;
        }

        $company = $this->company->select('id, account_status', ['id' => $company_id, 'is_deleted' => 0], 1);
        if (!$company || $company->account_status !== 'inactive') {
            return true;
        }

        $where = [
            'id' => $company->id,
            'is_deleted' => 0,
            'account_status' => 'inactive',
        ];
        $values = [
            'account_status' => 'active',
            'accreditation_status' => 'active',
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];
        if (!$this->company->update($where, $values)) {
            $this->error_message = $this->company->error();
            return false;
        }

        return true;
    }

    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $request_id = $this->payload['request_id'];
        $where = [
                    'id' => $request_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$request = $this->request_model->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'request is not found.'
            ];
        } elseif (!in_array($user->role_id, [4,19])) {
            $response_status = FAIL;
            $response = [
                "Only Sales or Admin users can update requests. ",
            ];
        //validate status
        } elseif ($status && $request->status == 'approved') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Cannot update an approved request. ",
            ];
        } elseif (!$this->_attempt_update($request)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update request.',
            ];
        } elseif (!$this->_attempt_update_details($request->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update request details. ",
            ];
        } elseif (!$this->_attempt_update_services($request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update request services. ",
            ];
        } elseif (!$this->_attempt_update_shifts($request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update request shifts. ",
            ];
        } elseif (!$this->_attempt_update_consultations($request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update request consultations. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'request_id' => $request_id,
                'message'    => 'request updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($request)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->request_model->allowedFields is declared at the top of the model
        foreach($this->request_model->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $request->id];

        if (!$this->request_model->update($where, $values)) {
            $this->error_message = $this->request_model->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update request details
     */
    private function _attempt_update_details($request_id)
    {
        // 1. Get all current detail IDs for this request
        $existing_details = $this->request_detail->select('id', ['request_id' => $request_id, 'is_deleted' => 0]);

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
                if (!$this->request_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->request_detail->error();
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
                'request_id'  => $request_id,
                'lab_test_id' => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'branch_id'   => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->request_detail->update(['id' => $detail['id']], $values)) {
                    $this->error_message = $this->request_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->request_detail->insert($values)) {
                    $this->error_message = $this->request_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update request details
     */
    private function _attempt_update_services($request_id)
    {
        // 1. Get all current service IDs for this request
        $existing_services = $this->request_service->select('id', ['request_id' => $request_id, 'is_deleted' => 0]);

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
                if (!$this->request_service->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->request_service->error();
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
                'request_id'    => $request_id,
                'type'              => array_key_exists('type', $service) && $service['type'] != '' ? $service['type'] : null,
                'type_id'           => array_key_exists('type_id', $service) && $service['type_id'] != '' ? $service['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $service) && $service['type_price'] != '' ? $service['type_price'] : null,
                'type_cost'           => array_key_exists('type_cost', $service) && $service['type_cost'] != '' ? $service['type_cost'] : null,
                'branch_id'           => array_key_exists('branch_id', $service) && $service['branch_id'] != '' ? $service['branch_id'] : null,
                'charge_to'           => array_key_exists('charge_to', $service) && $service['charge_to'] != '' ? $service['charge_to'] : null,
                'charge_to_ids'           => array_key_exists('charge_to_ids', $service) && $service['charge_to_ids'] != '' ? $service['charge_to_ids'] : null,
                ...$this->_service_add_on_fields($service),
                'head_count'           => array_key_exists('head_count', $service) && $service['head_count'] != '' ? $service['head_count'] : null,
                'discount_amount'           => array_key_exists('discount_amount', $service) && $service['discount_amount'] != '' ? $service['discount_amount'] : null,
                'margin_amount'           => array_key_exists('margin_amount', $service) && $service['margin_amount'] != '' ? $service['margin_amount'] : null,
                'is_guaranteed'           => array_key_exists('is_guaranteed', $service) && $service['is_guaranteed'] != '' ? $service['is_guaranteed'] : 0,
                'guaranteed_headcount'           => array_key_exists('guaranteed_headcount', $service) && $service['guaranteed_headcount'] != '' ? $service['guaranteed_headcount'] : null,
                'pre_negotiated_rate'           => array_key_exists('pre_negotiated_rate', $service) && $service['pre_negotiated_rate'] != '' ? $service['pre_negotiated_rate'] : null,
            ];

            if (!empty($service['id'])) {
                $serviceId = $service['id'];
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->request_service->update(['id' => $service['id']], $values)) {
                    $this->error_message = $this->request_service->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$serviceId = $this->request_service->insert($values)) {
                    $this->error_message = $this->request_service->error();
                    return false;
                }
            }
            $indexToId[$index] = (string) $serviceId;
            service_line_register_ids($keyToId, $service, $serviceId);
        }

        return $this->_rewrite_service_line_package_ids($this->request_service, $this->payload['services'], $keyToId, $indexToId);
    }

    /**
     * Attempt to update request shifts
     */
    private function _attempt_update_shifts($request_id)
    {
        // 1. Get all current shift IDs for this request
        $existing_shifts = $this->request_shift->select('id', ['request_id' => $request_id, 'is_deleted' => 0]);

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
                if (!$this->request_shift->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->request_shift->error();
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
                'request_id'    => $request_id,
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

                if (!$this->request_shift->update(['id' => $shift['id']], $values)) {
                    $this->error_message = $this->request_shift->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->request_shift->insert($values)) {
                    $this->error_message = $this->request_shift->error();
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Attempt to update request consultations
     */
    private function _attempt_update_consultations($request_id)
    {
        // 1. Get all current consultation IDs for this request
        $existing_consultations = $this->request_consultation->select('id', ['request_id' => $request_id, 'is_deleted' => 0]);

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
                if (!$this->request_consultation->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->request_consultation->error();
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
                'request_id' => $request_id,
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

                if (!$this->request_consultation->update(['id' => $consultation['id']], $values)) {
                    $this->error_message = $this->request_consultation->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->request_consultation->insert($values)) {
                    $this->error_message = $this->request_consultation->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status request
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $request_id = $this->payload['request_id'];
        $where = [
                    'id' => $request_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$request = $this->request_model->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'request is not found.'
            ];
        } elseif (!in_array($user->role_id, [4,19,20,21])) {
            $response_status = FAIL;
            $response = [
                "Only Sales, Sales Manager, Sales Director, or Admin users can change request status. ",
            ];
        //validate status
        } elseif ($status && $request->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Request is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($request, $status)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change request status.',
            ];
        } else {
            if ($status == 'approved') {
                if (!$this->send_email_notif('request_for_costing', $request_id)) {
                    $this->db->transRollback();
                    $response_status = FAIL;
                    $response = [
                        "Failed to send email notification.",
                    ];
                    return $this->_create_response($response_status, $response);
                }
            }
            if (!$this->send_email_notif('request_status_update', $request_id, $status)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    "Failed to send email notification.",
                ];
                return $this->_create_response($response_status, $response);
            }

            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'request_id' => $request_id,
                'message'    => "request $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($request, $status)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        if ($status == 'approved') {
            $values['status'] = 'approved';
            $values['approved_reason'] = $this->payload['approved_reason'] ?? null;
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_reason'] = $this->payload['disapproved_reason'] ?? null;
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'returned') {
            $values['status'] = 'returned';
            $values['returned_reason'] = $this->payload['returned_reason'] ?? null;
            $values['returned_by'] = $this->payload['requester'];
            $values['returned_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'archived') {
            $values['status'] = 'archived';
            $values['archived_reason'] = $this->payload['archived_reason'] ?? null;
            $values['archived_by'] = $this->payload['requester'];
            $values['archived_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $request->id];

        if (!$this->request_model->update($where, $values)) {
            $this->error_message = $this->request_model->error();
            return false;
        }
        return true;
    }

    /**
     * Delete requests
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $request_id = $this->payload['request_id'];

        $where = ['id' => $request_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$request = $this->request_model->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'request is not found.',
            ];
        } elseif (!$this->_attempt_delete($request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'request is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'request is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($request_id)
    {
        $where = ['id' => $request_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->request_model->update($where, $values)) {
            $this->error_message = $this->request_model->error();
            return false;
        }

        return true;
    }

    /**
     * Send Test Email Result
     */
        protected function send_email_notif($subject, $request_id, $status = null)
    {
        $requesterId = $this->payload['requester'];
        $user = $this->user->select('id, role_id, email, immediate_head', ['id' => $requesterId, 'is_deleted' => 0], 1);
        if (!$user) {
            return false;
        }
 
        $fallbackEmails = resolve_user_fallback_emails($this->user, $requesterId);
        $site_url = ACCOUNTING_FRONT_END_URL . "/requests";
        $request = $this->_request_email_context($request_id);
 
        if ($subject == 'request_for_approval') {
            $primaryEmails = [];
            $fallbackEmails = [];
            $creatorEmail = !empty($user->email)
                ? $user->email
                : resolve_request_added_by_email($this->request_model, $this->user, $request_id);
            if ($creatorEmail) {
                $primaryEmails[] = $creatorEmail;
            } else {
                log_message('error', 'Request for approval email skipped: no email found for creator of request #' . $request_id);
                return true;
            }
 
            $subjectLine = $this->_request_email_subject('Request for Approval', $request_id, $request);
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A new request (Request ID: $request_id) has been submitted and is awaiting your approval. Please review the request details and take the necessary action.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'request_for_costing') {
            $primaryEmails = resolve_emails_by_role($this->user, [ROLE_BDO]);
 
            $subjectLine = $this->_request_email_subject('Request for Costing', $request_id, $request);
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A request (Request ID: $request_id) has been approved and is awaiting costing. Please review the request details and provide the necessary costing information.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'request_status_update') {
            $isApproved = $status === 'approved';
            $isReturned = $status === 'returned';
            $isDisapproved = $status === 'disapproved';

            // The request creator is only notified when the request is approved or disapproved.
            if (!$isApproved && !$isDisapproved) {
                return true;
            }

            // Recipient is the request creator only (no approver/fallback copies).
            $primaryEmails = [];
            $fallbackEmails = [];
            $creatorEmail = resolve_request_added_by_email($this->request_model, $this->user, $request_id);
            if ($creatorEmail) {
                $primaryEmails[] = $creatorEmail;
            } else {
                log_message('error', 'Request status email skipped: no email found for creator of request #' . $request_id);
                return true;
            }

            $actor = $this->user->select('role_id', ['id' => $requesterId, 'is_deleted' => 0], 1);
            $actorLabel = status_actor_role_label($actor ? $actor->role_id : null);

            $statusLabel = $isApproved ? 'Approved' : 'Disapproved';
            $statusMessage = $isApproved
                ? "Your request has been reviewed and <strong>approved</strong> by the $actorLabel."
                : "We regret to inform you that your request has been reviewed and <strong>disapproved</strong> by the $actorLabel. Please reach out to your supervisor for further details.";

            $reasonHtml = '';
            if ($isApproved) {
                $reasonHtml = build_status_reason_html('Approval message', $this->payload['approved_reason'] ?? null);
            } elseif ($isDisapproved) {
                $reasonHtml = build_status_reason_html('Reason for disapproval', $this->payload['disapproved_reason'] ?? null);
            }

            $subjectLine = 'Your Request #' . $request_id . ' Has Been ' . $statusLabel;
            $message = "<!DOCTYPE html>
        <html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
        <body>
        <p>$statusMessage</p>
        $reasonHtml";
        } elseif ($subject == 'created_request') {
            // Confirmation goes to the person who created the request
            $primaryEmails = [];
            $fallbackEmails = [];
            $creatorEmail = !empty($user->email)
                ? $user->email
                : resolve_request_added_by_email($this->request_model, $this->user, $request_id);
            if ($creatorEmail) {
                $primaryEmails[] = $creatorEmail;
            } else {
                log_message('error', 'Created request email skipped: no email found for creator of request #' . $request_id);
                return true;
            }

            $subjectLine = $this->_request_email_subject('You Created a Request', $request_id, $request);
            $message = "<!DOCTYPE html>
        <html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
        <body>
        <p>You created a request (Request ID: $request_id). It has been submitted successfully and is now awaiting approval.</p>
        <p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
        <p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } else {
            return false;
        }
 
        return dispatch_email_notification($subjectLine, $message, $primaryEmails ?? [], $fallbackEmails);
    }
 
    /**
     * Company, package and SAE in-charge details used by request emails
     */
    private function _request_email_context($request_id)
    {
        $rows = $this->request_model->get_details($request_id, null, null, null, null, null, null, null, null) ?: [];
        $request = $rows ? reset($rows) : [];
 
        if (!$request) {
            $row = $this->request_model->select('id, sae_in_charge_id', ['id' => $request_id, 'is_deleted' => 0], 1);
            $request = $row ? ['sae_in_charge_id' => $row->sae_in_charge_id] : [];
        }
 
        $packageNames = [];
        foreach ($this->request_service->get_details([$request_id]) ?: [] as $service) {
            if (($service['type'] ?? null) !== 'package') {
                continue;
            }
 
            $name = trim((string) ($service['type_name'] ?? ''));
            if ($name === '' && !empty($service['type_id'])) {
                $package = $this->package->select('id, name', ['id' => $service['type_id']], 1);
                $name = $package ? trim((string) $package->name) : '';
            }
 
            if ($name !== '') {
                $packageNames[$name] = $name;
            }
        }
 
        $request['package_name'] = implode(', ', $packageNames);
 
        return $request;
    }
 
    /**
     * "{Prefix}: Company Name - Package Name (Request #{id})"
     */
    private function _request_email_subject($prefix, $request_id, $request)
    {
        $label = implode(' - ', array_filter([
            trim((string) ($request['company_name'] ?? '')),
            trim((string) ($request['package_name'] ?? '')),
        ]));
 
        if ($label === '') {
            return $prefix . ': Request #' . $request_id;
        }
 
        return $prefix . ': ' . $label . ' (Request #' . $request_id . ')';
    }

    /**
     * Send Test Email Result
     */
    public function send_test_email()
    {

        $subject      = 'Test Email';

        $site_url = ACCOUNTING_FRONT_END_URL . "/requests";

        // HTML body
        $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A request (Request ID: 1) has been approved and is awaiting costing. Please review the request details and provide the necessary costing information.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";

        $to = $this->request->getVar('email');

        //call the email helper function
        if (send_email($subject, $message, $to)) {
            $response_status = SUCCESS;
            $response = [
                'message' => 'Test email sent successfully.'
            ];
        } else {
            $response_status = FAIL;
            $response = [
                'message' => 'Failed to send test email.'
            ];
        }
    }

    /**
     * Download Excel template for bulk Request→Costing→Quotation→Endorsement create.
     * GET requests/download_bulk_template
     */
    public function download_bulk_template()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        if (!class_exists(Spreadsheet::class)) {
            return $this->_create_response(FAIL, [
                'PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet:^1.29',
            ]);
        }

        $spreadsheet = new Spreadsheet();

        // Instructions
        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['Request Bulk Upload Template'],
            [''],
            ['1. Fill the Request sheet (1 data row). Required-ish: company_id, customer_account_id, date, sae_in_charge_id.'],
            ['2. Fill Costing / Quotation / Endorsement sheets for entity-specific fields (1 data row each). Shared fields blank on Costing are copied from Request.'],
            ['3. Add rows on Services, Details, Shifts as needed. Leave unused rows blank.'],
            ['4. Consultations sheet is prefilled with all specializations from doctors/get_specilization.'],
            ['   Fill company_id, fee_amount, doctor_share only for rows you need. Rows with empty fee_amount are skipped on upload.'],
            ['   Do not change specialization_id / specialization_name.'],
            ['5. Upload via POST requests/upload_bulk with multipart field "file".'],
            ['6. Upload creates one approved Request → Costing → Quotation → Endorsement chain.'],
        ], null, 'A1');
        $instructions->getColumnDimension('A')->setWidth(120);

        $this->_bulk_write_header_sheet($spreadsheet, 'Request', $this->request_model->allowedFields, true);
        $this->_bulk_write_header_sheet($spreadsheet, 'Costing', [
            'package_name', 'service_package', 'other_service_package', 'ape_date', 'hours_per_day',
            'pre_negotiated_rate', 'admin_fee', 'grand_total', 'remarks',
            'service_duration_date_from', 'service_duration_date_to',
        ], true);
        $this->_bulk_write_header_sheet($spreadsheet, 'Quotation', [
            'version_no', 'effectivity_date_from', 'effectivity_date_to', 'quotation_no',
            'remarks', 'note', 'signatory', 'signatory_designation', 'grand_total',
        ], true);
        $this->_bulk_write_header_sheet($spreadsheet, 'Endorsement', [
            'branch_id', 'mobile_branch_id', 'type', 'endorsement_date', 'transaction_type',
            'address', 'preferred_branch_id', 'mode_of_releasing', 'results_tat', 'email', 'time',
            'head_count', 'payment_terms', 'date_from', 'date_to', 'contract_date_from', 'contract_date_to',
            'package_name', 'effectivity_date_from', 'effectivity_date_to',
            'service_duration_date_from', 'service_duration_date_to', 'remarks', 'note',
        ], true);

        $this->_bulk_write_header_sheet($spreadsheet, 'Services', [
            'key', 'type', 'type_id', 'type_price', 'type_cost', 'branch_id', 'charge_to', 'charge_to_ids', 'package_ids',
            'head_count', 'discount_amount', 'margin_amount', 'is_guaranteed', 'pre_negotiated_rate',
            'margin', 'cost', 'pro_rate_price', 'discount', 'sales', 'admin_fee', 'direct_cost',
            'manpower_cost', 'mobilization_cost', 'total_cost', 'estimated_margin', 'total_amount',
            'theoretical_cost',
        ], false);
        $this->_bulk_write_header_sheet($spreadsheet, 'Details', [
            'lab_test_id', 'branch_id', 'quantity', 'unit_price', 'total_amount',
            'discount', 'pro_rate_price', 'margin', 'cost',
        ], false);
        $this->_bulk_write_header_sheet($spreadsheet, 'Shifts', [
            'name', 'start_shift', 'end_shift', 'registration_cut_off',
            'specimen_submission_cut_off', 'total_hours', 'break', 'ingress',
        ], false);

        // Consultations prefilled from EMR specializations
        $consultSheet = $spreadsheet->createSheet();
        $consultSheet->setTitle('Consultations');
        $consultHeaders = ['specialization_id', 'specialization_name', 'company_id', 'fee_amount', 'doctor_share'];
        $consultSheet->fromArray($consultHeaders, null, 'A1');
        $specs = $this->specialization_emr->get_details() ?: [];
        $row = 2;
        foreach ($specs as $spec) {
            $consultSheet->fromArray([
                $spec['id'] ?? null,
                $spec['name'] ?? null,
                '',
                '',
                '',
            ], null, 'A' . $row);
            $row++;
        }
        $consultSheet->freezePane('A2');
        foreach (range('A', 'E') as $col) {
            $consultSheet->getColumnDimension($col)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $excelOutput = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="request_bulk_template.xlsx"')
            ->setHeader('Cache-Control', 'max-age=0')
            ->setBody($excelOutput);
    }

    /**
     * Upload Excel and create approved Request→Costing→Quotation→Endorsement.
     * POST requests/upload_bulk (multipart field: file)
     */
    public function upload_bulk()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        if (!class_exists(IOFactory::class)) {
            return $this->_create_response(FAIL, [
                'PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet:^1.29',
            ]);
        }

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        if (!$user || !in_array($user->role_id, [4, 19, 20, 21])) {
            return $this->_create_response(FAIL, [
                'Only Sales, Sales Manager, Sales Director, or Admin users can bulk upload.',
            ]);
        }

        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->_create_response(FAIL, [
                'Valid Excel file (.xlsx) is required in field "file".',
            ]);
        }

        $ext = strtolower($file->getClientExtension());
        if (!in_array($ext, ['xlsx', 'xls'])) {
            return $this->_create_response(FAIL, [
                'Only .xlsx or .xls files are allowed.',
            ]);
        }

        try {
            $spreadsheet = IOFactory::load($file->getTempName());
            $sheets = $this->_bulk_parse_workbook($spreadsheet);
        } catch (\Throwable $e) {
            return $this->_create_response(FAIL, [
                'Failed to read Excel file: ' . $e->getMessage(),
            ]);
        }

        $requestRow = $sheets['Request'][0] ?? [];
        $costingRow = $sheets['Costing'][0] ?? [];
        $quotationRow = $sheets['Quotation'][0] ?? [];
        $endorsementRow = $sheets['Endorsement'][0] ?? [];
        $services = $sheets['Services'] ?? [];
        $details = $sheets['Details'] ?? [];
        $shifts = $sheets['Shifts'] ?? [];
        $consultations = $this->_bulk_filter_consultations($sheets['Consultations'] ?? []);

        $this->payload = array_merge($this->payload, $requestRow);
        $this->payload['details'] = $details;
        $this->payload['services'] = $services;
        $this->payload['shifts'] = $shifts;
        $this->payload['consultations'] = $consultations;

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'];

        $this->db->transBegin();

        if (!$request_id = $this->_bulk_create_request($requester, $now)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to create request. ' . ($this->error_message ?? '')]);
        } elseif (!$this->_attempt_create_details($request_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add request details.']);
        } elseif (!$this->_attempt_create_services($request_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add request services.']);
        } elseif (!$this->_attempt_create_shifts($request_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add request shifts.']);
        } elseif (!$this->_attempt_create_consultations($request_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add request consultations.']);
        } elseif (!$costing_id = $this->_bulk_create_costing($request_id, $requestRow, $costingRow, $requester, $now)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to create costing. ' . ($this->error_message ?? '')]);
        } elseif (!$this->_bulk_create_costing_nested($costing_id, $details, $services, $shifts, $consultations, $requester, $now)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add costing nested rows. ' . ($this->error_message ?? '')]);
        } elseif (!$quotation_id = $this->_bulk_create_quotation($request_id, $costing_id, $requestRow, $costingRow, $quotationRow, $requester, $now)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to create quotation. ' . ($this->error_message ?? '')]);
        } elseif (!$this->_bulk_create_quotation_nested($quotation_id, $details, $services, $shifts, $consultations, $requester, $now)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add quotation nested rows. ' . ($this->error_message ?? '')]);
        } elseif (!$endorsement_id = $this->_bulk_create_endorsement($request_id, $costing_id, $quotation_id, $requestRow, $endorsementRow, $requester, $now)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to create endorsement. ' . ($this->error_message ?? '')]);
        } elseif (!$this->_bulk_create_endorsement_nested($endorsement_id, $services, $shifts, $consultations, $requester, $now)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add endorsement nested rows. ' . ($this->error_message ?? '')]);
        }

        $this->db->transCommit();
        return $this->_create_response(SUCCESS, [
            'request_id' => $request_id,
            'costing_id' => $costing_id,
            'quotation_id' => $quotation_id,
            'endorsement_id' => $endorsement_id,
            'message' => 'Bulk upload created and approved successfully.',
        ]);
    }

    private function _bulk_write_header_sheet($spreadsheet, $title, $headers, $singleDataRow)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray(array_values($headers), null, 'A1');
        if ($singleDataRow) {
            $empty = array_fill(0, count($headers), '');
            $sheet->fromArray($empty, null, 'A2');
        }
        $sheet->freezePane('A2');
        for ($i = 1; $i <= count($headers); $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function _bulk_parse_workbook($spreadsheet)
    {
        $result = [];
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $name = $sheet->getTitle();
            if ($name === 'Instructions') {
                continue;
            }
            $rows = $sheet->toArray(null, true, true, false);
            if (!$rows || count($rows) < 1) {
                $result[$name] = [];
                continue;
            }
            $headers = array_map(function ($h) {
                return is_string($h) ? trim($h) : $h;
            }, $rows[0]);
            $data = [];
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $assoc = [];
                $hasValue = false;
                foreach ($headers as $idx => $key) {
                    if ($key === null || $key === '') {
                        continue;
                    }
                    $val = array_key_exists($idx, $row) ? $row[$idx] : null;
                    if (is_string($val)) {
                        $val = trim($val);
                    }
                    if ($val !== null && $val !== '') {
                        $hasValue = true;
                    }
                    $assoc[$key] = $val === '' ? null : $val;
                }
                if ($hasValue) {
                    $data[] = $assoc;
                }
            }
            $result[$name] = $data;
        }
        return $result;
    }

    private function _bulk_filter_consultations($rows)
    {
        $out = [];
        foreach ($rows as $row) {
            $fee = $row['fee_amount'] ?? null;
            if ($fee === null || $fee === '') {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    private function _bulk_cell($row, $key, $default = null)
    {
        if (!is_array($row) || !array_key_exists($key, $row) || $row[$key] === '' || $row[$key] === null) {
            return $default;
        }
        return $row[$key];
    }

    private function _bulk_merge_parent_fields($allowedFields, $primary, $fallback = [])
    {
        $values = [];
        foreach ($allowedFields as $field) {
            if (is_array($primary) && array_key_exists($field, $primary) && $primary[$field] !== '' && $primary[$field] !== null) {
                $values[$field] = $primary[$field];
            } elseif (is_array($fallback) && array_key_exists($field, $fallback) && $fallback[$field] !== '' && $fallback[$field] !== null) {
                $values[$field] = $fallback[$field];
            }
        }
        return $values;
    }

    private function _bulk_create_request($requester, $now)
    {
        $values = [
            'added_by' => $requester,
            'added_on' => $now,
            'status' => 'approved',
            'approved_by' => $requester,
            'approved_on' => $now,
        ];
        foreach ($this->request_model->allowedFields as $field) {
            if (!array_key_exists($field, $this->payload) || $this->payload[$field] === '') {
                continue;
            }
            $values[$field] = $this->payload[$field];
        }
        if (!$request_id = $this->request_model->insert($values)) {
            $this->error_message = $this->request_model->error();
            return false;
        }
        return $request_id;
    }

    private function _bulk_create_costing($request_id, $requestRow, $costingRow, $requester, $now)
    {
        $values = $this->_bulk_merge_parent_fields($this->costing->allowedFields, $costingRow, $requestRow);
        $values['request_id'] = $request_id;
        $values['version_no'] = $this->_bulk_cell($costingRow, 'version_no', 1);
        $values['added_by'] = $requester;
        $values['added_on'] = $now;
        $values['status'] = 'approved';
        $values['approved_by'] = $requester;
        $values['approved_on'] = $now;
        $values['approved_by_sales_manager'] = $requester;
        $values['approved_on_sales_manager'] = $now;
        $values['approved_by_sales_director'] = $requester;
        $values['approved_on_sales_director'] = $now;

        if (!$costing_id = $this->costing->insert($values)) {
            $this->error_message = $this->costing->error();
            return false;
        }
        return $costing_id;
    }

    private function _bulk_create_costing_nested($costing_id, $details, $services, $shifts, $consultations, $requester, $now)
    {
        foreach ($details as $detail) {
            $values = [
                'costing_id' => $costing_id,
                'lab_test_id' => $this->_bulk_cell($detail, 'lab_test_id'),
                'branch_id' => $this->_bulk_cell($detail, 'branch_id'),
                'quantity' => $this->_bulk_cell($detail, 'quantity'),
                'unit_price' => $this->_bulk_cell($detail, 'unit_price'),
                'total_amount' => $this->_bulk_cell($detail, 'total_amount'),
                'discount' => $this->_bulk_cell($detail, 'discount'),
                'pro_rate_price' => $this->_bulk_cell($detail, 'pro_rate_price'),
                'margin' => $this->_bulk_cell($detail, 'margin'),
                'cost' => $this->_bulk_cell($detail, 'cost'),
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$this->costing_detail->insert($values)) {
                $this->error_message = $this->costing_detail->error();
                return false;
            }
        }

        $keyToId = [];
        $indexToId = [];
        foreach ($services as $index => $service) {
            $values = [
                'costing_id' => $costing_id,
                'type' => $this->_bulk_cell($service, 'type'),
                'type_id' => $this->_bulk_cell($service, 'type_id'),
                'type_price' => $this->_bulk_cell($service, 'type_price'),
                'margin' => $this->_bulk_cell($service, 'margin', 0),
                'cost' => $this->_bulk_cell($service, 'cost', $this->_bulk_cell($service, 'type_cost', 0)),
                'pro_rate_price' => $this->_bulk_cell($service, 'pro_rate_price', 0),
                'discount' => $this->_bulk_cell($service, 'discount', 0),
                'branch_id' => $this->_bulk_cell($service, 'branch_id'),
                'charge_to' => $this->_bulk_cell($service, 'charge_to'),
                'charge_to_ids' => $this->_bulk_cell($service, 'charge_to_ids'),
                ...$this->_service_add_on_fields($service),
                'head_count' => $this->_bulk_cell($service, 'head_count'),
                'discount_amount' => $this->_bulk_cell($service, 'discount_amount'),
                'margin_amount' => $this->_bulk_cell($service, 'margin_amount'),
                'sales' => $this->_bulk_cell($service, 'sales'),
                'admin_fee' => $this->_bulk_cell($service, 'admin_fee'),
                'direct_cost' => $this->_bulk_cell($service, 'direct_cost'),
                'manpower_cost' => $this->_bulk_cell($service, 'manpower_cost'),
                'mobilization_cost' => $this->_bulk_cell($service, 'mobilization_cost'),
                'total_cost' => $this->_bulk_cell($service, 'total_cost'),
                'estimated_margin' => $this->_bulk_cell($service, 'estimated_margin'),
                'pre_negotiated_rate' => $this->_bulk_cell($service, 'pre_negotiated_rate'),
                'is_guaranteed' => $this->_bulk_cell($service, 'is_guaranteed', 0),
                'theoretical_cost' => $this->_bulk_cell($service, 'theoretical_cost'),
                'total_amount' => $this->_bulk_cell($service, 'total_amount', 0),
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$serviceId = $this->costing_service->insert($values)) {
                $this->error_message = $this->costing_service->error();
                return false;
            }
            $indexToId[$index] = (string) $serviceId;
            service_line_register_ids($keyToId, $service, $serviceId);
        }
        if (!$this->_rewrite_service_line_package_ids($this->costing_service, $services, $keyToId, $indexToId)) {
            return false;
        }

        foreach ($shifts as $shift) {
            $values = [
                'costing_id' => $costing_id,
                'name' => $this->_bulk_cell($shift, 'name'),
                'start_shift' => $this->_bulk_cell($shift, 'start_shift'),
                'end_shift' => $this->_bulk_cell($shift, 'end_shift'),
                'registration_cut_off' => $this->_bulk_cell($shift, 'registration_cut_off'),
                'specimen_submission_cut_off' => $this->_bulk_cell($shift, 'specimen_submission_cut_off'),
                'total_hours' => $this->_bulk_cell($shift, 'total_hours', 0),
                'break' => $this->_bulk_cell($shift, 'break'),
                'ingress' => $this->_bulk_cell($shift, 'ingress'),
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$this->costing_shift->insert($values)) {
                $this->error_message = $this->costing_shift->error();
                return false;
            }
        }

        foreach ($consultations as $consultation) {
            $companyId = $this->_bulk_cell($consultation, 'company_id');
            $values = [
                'costing_id' => $costing_id,
                'company_id' => $companyId,
                'specialization_id' => $this->_bulk_cell($consultation, 'specialization_id'),
                'fee_amount' => $this->_bulk_cell($consultation, 'fee_amount', 0),
                'doctor_share' => $this->_bulk_cell($consultation, 'doctor_share', 0),
                'doctor_share_amount' => $this->_bulk_cell($consultation, 'doctor_share_amount', 0),
                'cpci_share' => $this->_bulk_cell($consultation, 'cpci_share', 0),
                // 'discount_doctor_share' => $this->_bulk_cell($consultation, 'discount_doctor_share', 0),
                // 'discount_cpci_share' => $this->_bulk_cell($consultation, 'discount_cpci_share', 0),
                'ahmopi_fee' => $this->_bulk_cell($consultation, 'ahmopi_fee', 0),
                'pahmoc_fee' => $this->_bulk_cell($consultation, 'pahmoc_fee', 0),
                'charge_to' => 'company',
                'charge_to_ids' => $companyId,
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$this->costing_consultation->insert($values)) {
                $this->error_message = $this->costing_consultation->error();
                return false;
            }
        }

        return true;
    }

    private function _bulk_create_quotation($request_id, $costing_id, $requestRow, $costingRow, $quotationRow, $requester, $now)
    {
        $fallback = array_merge($requestRow, $costingRow);
        $values = $this->_bulk_merge_parent_fields($this->quotation->allowedFields, $quotationRow, $fallback);
        $values['request_id'] = $request_id;
        $values['costing_id'] = $costing_id;
        $values['version_no'] = $this->_bulk_cell($quotationRow, 'version_no', 1);
        $values['quotation_no'] = $this->_bulk_cell($quotationRow, 'quotation_no', 'QT-' . date('Ymd') . '-' . random_string('alnum', 6));
        if (empty($values['contact_no']) && !empty($fallback['contact_number'])) {
            $values['contact_no'] = $fallback['contact_number'];
        }
        if (empty($values['contact_email']) && !empty($fallback['email'])) {
            $values['contact_email'] = $fallback['email'];
        }
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

        if (!$quotation_id = $this->quotation->insert($values)) {
            $this->error_message = $this->quotation->error();
            return false;
        }
        return $quotation_id;
    }

    private function _bulk_create_quotation_nested($quotation_id, $details, $services, $shifts, $consultations, $requester, $now)
    {
        foreach ($details as $detail) {
            $values = [
                'quotation_id' => $quotation_id,
                'lab_test_id' => $this->_bulk_cell($detail, 'lab_test_id'),
                'branch_id' => $this->_bulk_cell($detail, 'branch_id'),
                'quantity' => $this->_bulk_cell($detail, 'quantity'),
                'unit_price' => $this->_bulk_cell($detail, 'unit_price'),
                'total_amount' => $this->_bulk_cell($detail, 'total_amount'),
                'discount' => $this->_bulk_cell($detail, 'discount'),
                'pro_rate_price' => $this->_bulk_cell($detail, 'pro_rate_price'),
                'margin' => $this->_bulk_cell($detail, 'margin'),
                'cost' => $this->_bulk_cell($detail, 'cost'),
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$this->quotation_detail->insert($values)) {
                $this->error_message = $this->quotation_detail->error();
                return false;
            }
        }

        $keyToId = [];
        $indexToId = [];
        foreach ($services as $index => $service) {
            $values = [
                'quotation_id' => $quotation_id,
                'type' => $this->_bulk_cell($service, 'type'),
                'type_id' => $this->_bulk_cell($service, 'type_id'),
                'type_price' => $this->_bulk_cell($service, 'type_price'),
                'margin' => $this->_bulk_cell($service, 'margin', 0),
                'cost' => $this->_bulk_cell($service, 'cost', $this->_bulk_cell($service, 'type_cost', 0)),
                'pro_rate_price' => $this->_bulk_cell($service, 'pro_rate_price', 0),
                'discount' => $this->_bulk_cell($service, 'discount', 0),
                'branch_id' => $this->_bulk_cell($service, 'branch_id'),
                'charge_to' => $this->_bulk_cell($service, 'charge_to'),
                'charge_to_ids' => $this->_bulk_cell($service, 'charge_to_ids'),
                ...$this->_service_add_on_fields($service),
                'head_count' => $this->_bulk_cell($service, 'head_count'),
                'discount_amount' => $this->_bulk_cell($service, 'discount_amount'),
                'margin_amount' => $this->_bulk_cell($service, 'margin_amount'),
                'sales' => $this->_bulk_cell($service, 'sales'),
                'admin_fee' => $this->_bulk_cell($service, 'admin_fee'),
                'direct_cost' => $this->_bulk_cell($service, 'direct_cost'),
                'manpower_cost' => $this->_bulk_cell($service, 'manpower_cost'),
                'mobilization_cost' => $this->_bulk_cell($service, 'mobilization_cost'),
                'total_cost' => $this->_bulk_cell($service, 'total_cost'),
                'estimated_margin' => $this->_bulk_cell($service, 'estimated_margin'),
                'pre_negotiated_rate' => $this->_bulk_cell($service, 'pre_negotiated_rate'),
                'is_guaranteed' => $this->_bulk_cell($service, 'is_guaranteed', 0),
                'theoretical_cost' => $this->_bulk_cell($service, 'theoretical_cost'),
                'total_amount' => $this->_bulk_cell($service, 'total_amount', 0),
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$serviceId = $this->quotation_service->insert($values)) {
                $this->error_message = $this->quotation_service->error();
                return false;
            }
            $indexToId[$index] = (string) $serviceId;
            service_line_register_ids($keyToId, $service, $serviceId);
        }
        if (!$this->_rewrite_service_line_package_ids($this->quotation_service, $services, $keyToId, $indexToId)) {
            return false;
        }

        foreach ($shifts as $shift) {
            $values = [
                'quotation_id' => $quotation_id,
                'name' => $this->_bulk_cell($shift, 'name'),
                'start_shift' => $this->_bulk_cell($shift, 'start_shift'),
                'end_shift' => $this->_bulk_cell($shift, 'end_shift'),
                'registration_cut_off' => $this->_bulk_cell($shift, 'registration_cut_off'),
                'specimen_submission_cut_off' => $this->_bulk_cell($shift, 'specimen_submission_cut_off'),
                'total_hours' => $this->_bulk_cell($shift, 'total_hours', 0),
                'break' => $this->_bulk_cell($shift, 'break'),
                'ingress' => $this->_bulk_cell($shift, 'ingress'),
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$this->quotation_shift->insert($values)) {
                $this->error_message = $this->quotation_shift->error();
                return false;
            }
        }

        foreach ($consultations as $consultation) {
            $companyId = $this->_bulk_cell($consultation, 'company_id');
            $values = [
                'quotation_id' => $quotation_id,
                'company_id' => $companyId,
                'specialization_id' => $this->_bulk_cell($consultation, 'specialization_id'),
                'fee_amount' => $this->_bulk_cell($consultation, 'fee_amount', 0),
                'doctor_share' => $this->_bulk_cell($consultation, 'doctor_share', 0),
                'doctor_share_amount' => $this->_bulk_cell($consultation, 'doctor_share_amount', 0),
                'cpci_share' => $this->_bulk_cell($consultation, 'cpci_share', 0),
                // 'discount_doctor_share' => $this->_bulk_cell($consultation, 'discount_doctor_share', 0),
                // 'discount_cpci_share' => $this->_bulk_cell($consultation, 'discount_cpci_share', 0),
                'ahmopi_fee' => $this->_bulk_cell($consultation, 'ahmopi_fee', 0),
                'pahmoc_fee' => $this->_bulk_cell($consultation, 'pahmoc_fee', 0),
                'charge_to' => 'company',
                'charge_to_ids' => $companyId,
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$this->quotation_consultation->insert($values)) {
                $this->error_message = $this->quotation_consultation->error();
                return false;
            }
        }

        return true;
    }

    private function _bulk_create_endorsement($request_id, $costing_id, $quotation_id, $requestRow, $endorsementRow, $requester, $now)
    {
        $values = $this->_bulk_merge_parent_fields($this->endorsement->allowedFields, $endorsementRow, $requestRow);
        $values['request_id'] = $request_id;
        $values['costing_id'] = $costing_id;
        $values['quotation_id'] = $quotation_id;
        $values['added_by'] = $requester;
        $values['added_on'] = $now;
        $values['status'] = 'approved';
        $values['approved_by'] = $requester;
        $values['approved_on'] = $now;

        if (!$endorsement_id = $this->endorsement->insert($values)) {
            $this->error_message = $this->endorsement->error();
            return false;
        }
        return $endorsement_id;
    }

    private function _bulk_create_endorsement_nested($endorsement_id, $services, $shifts, $consultations, $requester, $now)
    {
        $keyToId = [];
        $indexToId = [];
        foreach ($services as $index => $service) {
            $values = [
                'endorsement_id' => $endorsement_id,
                'type' => $this->_bulk_cell($service, 'type'),
                'type_id' => $this->_bulk_cell($service, 'type_id'),
                'type_price' => $this->_bulk_cell($service, 'type_price'),
                'margin' => $this->_bulk_cell($service, 'margin', 0),
                'cost' => $this->_bulk_cell($service, 'cost', $this->_bulk_cell($service, 'type_cost', 0)),
                'pro_rate_price' => $this->_bulk_cell($service, 'pro_rate_price', 0),
                'discount' => $this->_bulk_cell($service, 'discount', 0),
                'branch_id' => $this->_bulk_cell($service, 'branch_id'),
                'charge_to' => $this->_bulk_cell($service, 'charge_to'),
                'charge_to_ids' => $this->_bulk_cell($service, 'charge_to_ids'),
                ...$this->_service_add_on_fields($service),
                'head_count' => $this->_bulk_cell($service, 'head_count'),
                'discount_amount' => $this->_bulk_cell($service, 'discount_amount'),
                'margin_amount' => $this->_bulk_cell($service, 'margin_amount'),
                'sales' => $this->_bulk_cell($service, 'sales'),
                'admin_fee' => $this->_bulk_cell($service, 'admin_fee'),
                'direct_cost' => $this->_bulk_cell($service, 'direct_cost'),
                'manpower_cost' => $this->_bulk_cell($service, 'manpower_cost'),
                'mobilization_cost' => $this->_bulk_cell($service, 'mobilization_cost'),
                'total_cost' => $this->_bulk_cell($service, 'total_cost'),
                'estimated_margin' => $this->_bulk_cell($service, 'estimated_margin'),
                'pre_negotiated_rate' => $this->_bulk_cell($service, 'pre_negotiated_rate'),
                'is_guaranteed' => $this->_bulk_cell($service, 'is_guaranteed', 0),
                'theoretical_cost' => $this->_bulk_cell($service, 'theoretical_cost'),
                'total_amount' => $this->_bulk_cell($service, 'total_amount', 0),
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$serviceId = $this->endorsement_service->insert($values)) {
                $this->error_message = $this->endorsement_service->error();
                return false;
            }
            $indexToId[$index] = (string) $serviceId;
            service_line_register_ids($keyToId, $service, $serviceId);
        }
        if (!$this->_rewrite_service_line_package_ids($this->endorsement_service, $services, $keyToId, $indexToId)) {
            return false;
        }

        foreach ($shifts as $shift) {
            $values = [
                'endorsement_id' => $endorsement_id,
                'name' => $this->_bulk_cell($shift, 'name'),
                'start_shift' => $this->_bulk_cell($shift, 'start_shift'),
                'end_shift' => $this->_bulk_cell($shift, 'end_shift'),
                'registration_cut_off' => $this->_bulk_cell($shift, 'registration_cut_off'),
                'specimen_submission_cut_off' => $this->_bulk_cell($shift, 'specimen_submission_cut_off'),
                'total_hours' => $this->_bulk_cell($shift, 'total_hours', 0),
                'break' => $this->_bulk_cell($shift, 'break'),
                'ingress' => $this->_bulk_cell($shift, 'ingress'),
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$this->endorsement_shift->insert($values)) {
                $this->error_message = $this->endorsement_shift->error();
                return false;
            }
        }

        foreach ($consultations as $consultation) {
            $companyId = $this->_bulk_cell($consultation, 'company_id');
            $values = [
                'endorsement_id' => $endorsement_id,
                'company_id' => $companyId,
                'specialization_id' => $this->_bulk_cell($consultation, 'specialization_id'),
                'fee_amount' => $this->_bulk_cell($consultation, 'fee_amount', 0),
                'doctor_share' => $this->_bulk_cell($consultation, 'doctor_share', 0),
                'doctor_share_amount' => $this->_bulk_cell($consultation, 'doctor_share_amount', 0),
                'cpci_share' => $this->_bulk_cell($consultation, 'cpci_share', 0),
                // 'discount_doctor_share' => $this->_bulk_cell($consultation, 'discount_doctor_share', 0),
                // 'discount_cpci_share' => $this->_bulk_cell($consultation, 'discount_cpci_share', 0),
                'ahmopi_fee' => $this->_bulk_cell($consultation, 'ahmopi_fee', 0),
                'pahmoc_fee' => $this->_bulk_cell($consultation, 'pahmoc_fee', 0),
                'charge_to' => 'company',
                'charge_to_ids' => $companyId,
                'added_by' => $requester,
                'added_on' => $now,
            ];
            if (!$this->endorsement_consultation->insert($values)) {
                $this->error_message = $this->endorsement_consultation->error();
                return false;
            }
        }

        return true;
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
        $id?($where['id <>'] = $id):null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }
}