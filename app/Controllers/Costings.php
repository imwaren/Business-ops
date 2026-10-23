<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
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
use App\Models\Quotation_service_package_detail;
use App\Models\Quotation_shift;
use App\Models\Quotation_consultation;
use App\Models\User;
use App\Models\Package;
use App\Models\Package_detail;
use App\Models\Request;
use App\Models\Branch_master;
use App\Models\Company;

class Costings extends MYT_Controller
{
    protected $request;
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
    protected $quotation_service_package_detail;
    protected $quotation_shift;
    protected $quotation_consultation;
    protected $user;
    protected $package;
    protected $package_detail;
    protected $request_model;
    protected $branch_master;
    protected $company;
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
        $this->quotation_service_package_detail = new Quotation_service_package_detail();
        $this->quotation_shift = new Quotation_shift();
        $this->quotation_consultation = new Quotation_consultation();
        $this->user = new User();
        $this->package = new Package();
        $this->package_detail = new Package_detail();
        $this->request_model = new Request();
        $this->branch_master = new Branch_master();
        $this->company = new Company();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get costings
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

        $costing_id = $this->payload['costing_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $screen = $this->payload['screen'] ?? null;
        $role_name = $this->payload['role_name'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $request_date_from = $this->payload['request_date_from'] ?? null;
        $request_date_to = $this->payload['request_date_to'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $costings = $this->costing->get_details($costing_id, $status, $company_id, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $term, $page, $data_size, $request_added_by)?:[];

        if(!$costings) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No costings found.'
            ];
        } else {
            $costingIds = $this->_collect_ids($costings, 'id');
            $userIds = $this->_collect_ids($costings, 'sae_in_charge_id');
            $branchIds = $this->_collect_ids($costings, 'preferred_branch_id');
            $customerAccountIds = $this->_collect_ids($costings, 'customer_account_id');
            $requestIds = $this->_collect_ids($costings, 'request_id');

            $usersById = $this->user->get_by_ids($userIds);
            $branchNamesById = $this->branch_master->get_names_by_ids($branchIds);
            $customerAccountsById = [];
            if ($customerAccountIds) {
                foreach ($this->company->get_customer_accounts(implode(',', $customerAccountIds)) ?: [] as $account) {
                    $customerAccountsById[$account['company_id']][] = $account;
                }
            }

            $requestsById = [];
            if ($requestIds) {
                foreach ($this->request_model->get_details($requestIds) ?: [] as $req) {
                    $requestsById[$req['id']] = $req;
                }
            }

            $detailsByCosting = $this->_group_by($this->costing_detail->get_details($costingIds) ?: [], 'costing_id');
            $servicesByCosting = $this->_group_by($this->costing_service->get_details($costingIds) ?: [], 'costing_id');
            $consultasByCosting = $this->_group_by($this->costing_consulta->get_details($costingIds) ?: [], 'costing_id');
            $discountsByCosting = $this->_group_by($this->costing_discount->get_details($costingIds) ?: [], 'costing_id');
            $manpowersByCosting = $this->_group_by($this->costing_manpower->get_details($costingIds) ?: [], 'costing_id');
            $manpowerCostsByCosting = $this->_group_by($this->costing_manpower_cost->get_details($costingIds) ?: [], 'costing_id');
            $mobilizationsByCosting = $this->_group_by($this->costing_mobilization->get_details($costingIds) ?: [], 'costing_id');
            $shiftsByCosting = $this->_group_by($this->costing_shift->get_details($costingIds) ?: [], 'costing_id');
            $consultationsByCosting = $this->_group_by($this->costing_consultation->get_details($costingIds) ?: [], 'costing_id');
            $historiesByCosting = $this->_group_by($this->costing_history->get_details($costingIds) ?: [], 'source_id');
            $missingHistoryUserIds = array_values(array_diff(
                $this->_collect_grouped_ids($historiesByCosting, 'added_by'),
                array_keys($usersById)
            ));
            if ($missingHistoryUserIds) {
                $usersById += $this->user->get_by_ids($missingHistoryUserIds);
            }

            $costingServiceIds = [];
            foreach ($servicesByCosting as $services) {
                foreach ($services as $service) {
                    if (($service['type'] ?? null) === 'package') {
                        $costingServiceIds[] = $service['id'];
                    }
                }
            }
            $pkgByService = $this->_group_by(
                $this->costing_service_package_detail->get_details(array_values(array_unique($costingServiceIds))) ?: [],
                'costing_service_id'
            );

            $manpowerCostIds = [];
            foreach ($manpowerCostsByCosting as $manpowerCosts) {
                foreach ($manpowerCosts as $mc) {
                    $manpowerCostIds[] = $mc['id'];
                }
            }
            $premiumPaysByManpowerCost = $this->_group_by(
                $this->costing_manpower_cost_premium_pay->get_details($costingIds, array_values(array_unique($manpowerCostIds))) ?: [],
                'costing_manpower_cost_id'
            );

            foreach ($costings as $key => $costing) {
                $cid = $costing['id'];
                if (!empty($costing['sae_in_charge_id']) && isset($usersById[$costing['sae_in_charge_id']])) {
                    $costings[$key]['sae_in_charge_name'] = $usersById[$costing['sae_in_charge_id']]['name'];
                }

                $preferredBranchId = $costing['preferred_branch_id'] ?? null;
                $costings[$key]['preferred_branch_name'] = ($preferredBranchId !== null && $preferredBranchId !== 'all' && isset($branchNamesById[$preferredBranchId]))
                    ? $branchNamesById[$preferredBranchId]
                    : null;

                if (!empty($costing['customer_account_id'])) {
                    $costings[$key]['customer_account_details'] = $this->_customer_account_details($customerAccountsById, $costing['customer_account_id']);
                }

                $costings[$key]['request'] = isset($requestsById[$costing['request_id']])
                    ? (object) $requestsById[$costing['request_id']]
                    : null;

                $costings[$key]['details'] = $detailsByCosting[$cid] ?? [];
                $services = $servicesByCosting[$cid] ?? [];
                foreach ($services as $c_key => $service) {
                    if (($service['type'] ?? null) === 'package') {
                        $services[$c_key]['details'] = $pkgByService[$service['id']] ?? [];
                    }
                }
                $costings[$key]['services'] = $services;
                $costings[$key]['consultas'] = $consultasByCosting[$cid] ?? [];
                $costings[$key]['discounts'] = $discountsByCosting[$cid] ?? [];
                $costings[$key]['manpowers'] = $manpowersByCosting[$cid] ?? [];
                $manpowerCosts = $manpowerCostsByCosting[$cid] ?? [];
                foreach ($manpowerCosts as $c_key => $manpower_cost) {
                    $manpowerCosts[$c_key]['premium_pays'] = $premiumPaysByManpowerCost[$manpower_cost['id']] ?? [];
                }
                $costings[$key]['manpower_costs'] = $manpowerCosts;
                $costings[$key]['mobilizations'] = $mobilizationsByCosting[$cid] ?? [];
                $costings[$key]['shifts'] = $shiftsByCosting[$cid] ?? [];
                $costings[$key]['consultations'] = $consultationsByCosting[$cid] ?? [];
                $costings[$key]['histories'] = $historiesByCosting[$cid] ?? [];
                $costings[$key] = $this->_apply_reason_trails($costings[$key], $costings[$key]['histories'], $usersById);
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $this->costing->get_count($costing_id, $status, $company_id, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $term, $request_added_by),
                'data' => $costings
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create costing
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $request_id = $this->payload['request_id'] ?? null;

        $this->db->transBegin();

        $package_name = $this->payload['package_name'] ?? null;
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!in_array($user->role_id, [4,19,22,32,20,21])) {
            $response_status = FAIL;
            $response = [
                "Only SAE, Business ops, Business Development Director, Sales Manager, Sales Director or Admin users can create costings. ",
            ];
        } elseif (!$request_id || $request_id == null) {
            $response_status = FAIL;
            $response = [
                "Request id is required ",
            ];
        } elseif ($package_name && $this->costing->validate_package_name($package_name)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Cannot generate duplicate package name. ",
            ];
        } elseif (!$costing_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing. ",
            ];
        } elseif (!$this->_attempt_generate_version_no($costing_id, $request_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to generate request version no. ",
            ];
        } elseif (!$this->_attempt_create_details($package_name)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing details. ",
            ];
        } elseif (!$this->_attempt_create_services($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing services. ",
            ];
        } elseif (!$this->_attempt_create_consultas($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing consultas. ",
            ];
        } elseif (!$this->_attempt_create_discounts($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing discounts. ",
            ];
        } elseif (!$this->_attempt_create_manpowers($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing manpowers. ",
            ];
        } elseif (!$this->_attempt_create_manpower_costs($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing manpower costs. ",
            ];
        } elseif (!$this->_attempt_create_mobilizations($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing mobilizations. ",
            ];
        } elseif (!$this->_attempt_create_shifts($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing shifts. ",
            ];
        } elseif (!$this->_attempt_create_consultations($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add costing consultations. ",
            ];
        } elseif (!$this->send_email_notif(
            $user->role_id == ROLE_SALES_DIRECTOR
                ? 'costing_for_approval_by_finance_director'
                : ($user->role_id == ROLE_SALES_MANAGER ? 'costing_for_approval_by_sales_director' : 'costing_for_approval_by_sales_manager'),
            $costing_id
        )) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send email notification. ",
            ];
        } elseif (!$this->send_email_notif('request_moved_to_costing', $costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to send email notification. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'costing_id' => $costing_id,
                'message'    => 'costing added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a costing
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->costing->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $creator = $this->user->select('role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        $now = date('Y-m-d H:i:s');
        if ($creator && $creator->role_id == ROLE_SALES_MANAGER) {
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = $now;
            $values['approved_by_sales_manager'] = $this->payload['requester'];
            $values['approved_on_sales_manager'] = $now;
        } elseif ($creator && $creator->role_id == ROLE_SALES_DIRECTOR) {
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = $now;
            $values['approved_by_sales_manager'] = $this->payload['requester'];
            $values['approved_on_sales_manager'] = $now;
            $values['approved_by_sales_director'] = $this->payload['requester'];
            $values['approved_on_sales_director'] = $now;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$costing_id = $this->costing->insert($values)) {
            $this->error_message = $this->costing->error();
            return false;
        }

        return $costing_id;
    }

    /**
     * Attempt generate version no
     */
    protected function _attempt_generate_version_no($costing_id, $request_id)
    {
        $version_no = 1;

        $prev_costing = $this->costing->get_latest_prev_costing($request_id)?:[];
        if ($prev_costing || !empty($prev_costing)) {
            $version_no = $prev_costing[0]['version_no'] + 1;

            // update request version no
            if (!$this->request_model->update(['id' => $request_id], ['version_no' => $version_no, 'updated_by' => $this->payload['requester'], 'updated_on' => date('Y-m-d H:i:s')])) {
                $this->error_message = $this->request_model->error();
                return false;
            }
        }

        // update costing version no
        if (!$this->costing->update(['id' => $costing_id], ['version_no' => $version_no])) {
            $this->error_message = $this->costing->error();
            return false;
        }

        return true;
    }

    /**
     * Attempt to create costing details
     */
    private function _attempt_create_details($costing_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'costing_id'    => $costing_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'branch_id'   => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
                'quantity' => array_key_exists('quantity', $detail) && $detail['quantity'] != '' ? $detail['quantity'] : null,
                'unit_price' => array_key_exists('unit_price', $detail) && $detail['unit_price'] != '' ? $detail['unit_price'] : null,
                'total_amount' => array_key_exists('total_amount', $detail) && $detail['total_amount'] != '' ? $detail['total_amount'] : null,
                'discount' => array_key_exists('discount', $detail) && $detail['discount'] != '' ? $detail['discount'] : null,
                'pro_rate_price' => array_key_exists('pro_rate_price', $detail) && $detail['pro_rate_price'] != '' ? $detail['pro_rate_price'] : null,
                'margin' => array_key_exists('margin', $detail) && $detail['margin'] != '' ? $detail['margin'] : null,
                'cost' => array_key_exists('cost', $detail) && $detail['cost'] != '' ? $detail['cost'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->costing_detail->insert($values)) {
                $this->error_message = $this->costing_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create costing services
     */
    private function _attempt_create_services($costing_id)
    {
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services']) || count($this->payload['services']) == 0) {
            return true;
        }

        $keyToId = [];
        $indexToId = [];
        foreach ($this->payload['services'] as $index => $service) {
            $values = [
                'costing_id'    => $costing_id,
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

            if (!$costing_service_id = $this->costing_service->insert($values)) {
                $this->error_message = $this->costing_service->error();
                return false;
            }
            $indexToId[$index] = (string) $costing_service_id;
            service_line_register_ids($keyToId, $service, $costing_service_id);

            $service_package_details = array_key_exists('details', $service) && is_array($service['details']) ? $service['details'] : [];

            if (count($service_package_details) > 0) {
                if (!$this->_attempt_create_service_package_details($costing_service_id, $costing_id, $service_package_details)) {
                    return false;
                }
            }
        }

        return $this->_rewrite_service_line_package_ids($this->costing_service, $this->payload['services'], $keyToId, $indexToId);
    }

    /**
     * Attempt to create costing service package details
     */
    private function _attempt_create_service_package_details($costing_service_id, $costing_id, $service_package_details)
    {
        foreach ($service_package_details as $service_package_detail) {
            $values = [
                'costing_service_id'    => $costing_service_id,
                'costing_id'    => $costing_id,
                'package_id'              => array_key_exists('package_id', $service_package_detail) && $service_package_detail['package_id'] != '' ? $service_package_detail['package_id'] : null,
                'lab_test_id'           => array_key_exists('lab_test_id', $service_package_detail) && $service_package_detail['lab_test_id'] != '' ? $service_package_detail['lab_test_id'] : null,
                'is_add_on'           => array_key_exists('is_add_on', $service_package_detail) && $service_package_detail['is_add_on'] != '' ? $service_package_detail['is_add_on'] : 0,
                'price'           => array_key_exists('price', $service_package_detail) && $service_package_detail['price'] != '' ? $service_package_detail['price'] : 0,
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

            if (!$this->costing_service_package_detail->insert($values)) {
                $this->error_message = $this->costing_service_package_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create costing consultas
     */
    private function _attempt_create_consultas($costing_id)
    {
        if (!array_key_exists('consultas', $this->payload) || !is_array($this->payload['consultas']) || count($this->payload['consultas']) == 0) {
            return true;
        }

        foreach ($this->payload['consultas'] as $consulta) {
            $values = [
                'costing_id'    => $costing_id,
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

            if (!$this->costing_consulta->insert($values)) {
                $this->error_message = $this->costing_consulta->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create costing discounts
     */
    private function _attempt_create_discounts($costing_id)
    {
        if (!array_key_exists('discounts', $this->payload) || !is_array($this->payload['discounts']) || count($this->payload['discounts']) == 0) {
            return true;
        }

        foreach ($this->payload['discounts'] as $discount) {
            $values = [
                'costing_id'    => $costing_id,
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

            if (!$this->costing_discount->insert($values)) {
                $this->error_message = $this->costing_discount->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create costing manpowers
     */
    private function _attempt_create_manpowers($costing_id)
    {
        if (!array_key_exists('manpowers', $this->payload) || !is_array($this->payload['manpowers']) || count($this->payload['manpowers']) == 0) {
            return true;
        }

        foreach ($this->payload['manpowers'] as $manpower) {
            $values = [
                'costing_id'    => $costing_id,
                'description'              => array_key_exists('description', $manpower) && $manpower['description'] != '' ? $manpower['description'] : null,
                'manpower_type'              => array_key_exists('manpower_type', $manpower) && $manpower['manpower_type'] != '' ? $manpower['manpower_type'] : null,
                'qty'         => array_key_exists('qty', $manpower) && $manpower['qty'] != '' ? $manpower['qty'] : 0,
                'daily_rate'    => array_key_exists('daily_rate', $manpower) && $manpower['daily_rate'] != '' ? $manpower['daily_rate'] : 0,
                'total_daily_rate'    => array_key_exists('total_daily_rate', $manpower) && $manpower['total_daily_rate'] != '' ? $manpower['total_daily_rate'] : 0,
                'meals'    => array_key_exists('meals', $manpower) && $manpower['meals'] != '' ? $manpower['meals'] : 0,
                'ingress_count'    => array_key_exists('ingress_count', $manpower) && $manpower['ingress_count'] != '' ? $manpower['ingress_count'] : 0,
                'rate'    => array_key_exists('rate', $manpower) && $manpower['rate'] != '' ? $manpower['rate'] : 0,
                'pax_per_hour'    => array_key_exists('pax_per_hour', $manpower) && $manpower['pax_per_hour'] != '' ? $manpower['pax_per_hour'] : 0,
                'minutes_per_pax'    => array_key_exists('minutes_per_pax', $manpower) && $manpower['minutes_per_pax'] != '' ? $manpower['minutes_per_pax'] : 0,
                'active_hours'    => array_key_exists('active_hours', $manpower) && $manpower['active_hours'] != '' ? $manpower['active_hours'] : 0,
                'suggested_qty'    => array_key_exists('suggested_qty', $manpower) && $manpower['suggested_qty'] != '' ? $manpower['suggested_qty'] : 0,
                'patient_per_shift'    => array_key_exists('patient_per_shift', $manpower) && $manpower['patient_per_shift'] != '' ? $manpower['patient_per_shift'] : 0,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];
            
            if (!$this->costing_manpower->insert($values)) {
                $this->error_message = $this->costing_manpower->error();
                return false;
            }
        }

        return true;
    }



    /**
     * Attempt to create costing manpower_costs
     */
    private function _attempt_create_manpower_costs($costing_id)
    {
        if (!array_key_exists('manpower_costs', $this->payload) || !is_array($this->payload['manpower_costs']) || count($this->payload['manpower_costs']) == 0) {
            return true;
        }

        foreach ($this->payload['manpower_costs'] as $manpower_cost) {
            $values = [
                'costing_id'    => $costing_id,
                'description'       => array_key_exists('description', $manpower_cost) && $manpower_cost['description'] != '' ? $manpower_cost['description'] : null,
                'shift_no'       => array_key_exists('shift_no', $manpower_cost) && $manpower_cost['shift_no'] != '' ? $manpower_cost['shift_no'] : 0,
                'basic_pay'         => array_key_exists('basic_pay', $manpower_cost) && $manpower_cost['basic_pay'] != '' ? $manpower_cost['basic_pay'] : 0,
                'premium_pay'       => array_key_exists('premium_pay', $manpower_cost) && $manpower_cost['premium_pay'] != '' ? $manpower_cost['premium_pay'] : 0,
                'premium_pay_amount'       => array_key_exists('premium_pay_amount', $manpower_cost) && $manpower_cost['premium_pay_amount'] != '' ? $manpower_cost['premium_pay_amount'] : 0,
                'premium_hrs'       => array_key_exists('premium_hrs', $manpower_cost) && $manpower_cost['premium_hrs'] != '' ? $manpower_cost['premium_hrs'] : 0,
                'ingress_hrs'       => array_key_exists('ingress_hrs', $manpower_cost) && $manpower_cost['ingress_hrs'] != '' ? $manpower_cost['ingress_hrs'] : 0,
                'total_premium_pay' => array_key_exists('total_premium_pay', $manpower_cost) && $manpower_cost['total_premium_pay'] != '' ? $manpower_cost['total_premium_pay'] : 0,
                'total'             => array_key_exists('total', $manpower_cost) && $manpower_cost['total'] != '' ? $manpower_cost['total'] : 0,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];
            
            if (!$manpower_cost_id = $this->costing_manpower_cost->insert($values)) {
                $this->error_message = $this->costing_manpower_cost->error();
                return false;
            }

            if (
                array_key_exists('premium_pays', $manpower_cost)
                && is_array($manpower_cost['premium_pays'])
                && count($manpower_cost['premium_pays']) > 0
            ) {
                foreach ($manpower_cost['premium_pays'] as $premium_pay) {
                    $premium_pay_values = [
                        'costing_id'    => $costing_id,
                        'costing_manpower_cost_id'    => $manpower_cost_id,
                        'premium_pay'    => $premium_pay['premium_pay'],
                        'premium_pay_amount'    => $premium_pay['premium_pay_amount'],
                        'premium_hrs'    => $premium_pay['premium_hrs'],
                        'ingress_hrs'    => $premium_pay['ingress_hrs'],
                        'total_premium_pay'    => $premium_pay['total_premium_pay'],
                        'total'    => $premium_pay['total'],
                        'added_by'          => $this->payload['requester'],
                        'added_on'          => date('Y-m-d H:i:s'),
                    ];

                    if (!$this->costing_manpower_cost_premium_pay->insert($premium_pay_values)) {
                        $this->error_message = $this->costing_manpower_cost_premium_pay->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create costing mobilizations
     */
    private function _attempt_create_mobilizations($costing_id)
    {
        if (!array_key_exists('mobilizations', $this->payload) || !is_array($this->payload['mobilizations']) || count($this->payload['mobilizations']) == 0) {
            return true;
        }

        foreach ($this->payload['mobilizations'] as $mobilization) {
            $values = [
                'costing_id'    => $costing_id,
                'description'              => array_key_exists('description', $mobilization) && $mobilization['description'] != '' ? $mobilization['description'] : null,
                'unit'         => array_key_exists('unit', $mobilization) && $mobilization['unit'] != '' ? $mobilization['unit'] : 0,
                'amount'    => array_key_exists('amount', $mobilization) && $mobilization['amount'] != '' ? $mobilization['amount'] : 0,
                'total'    => array_key_exists('total', $mobilization) && $mobilization['total'] != '' ? $mobilization['total'] : 0,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];
            
            if (!$this->costing_mobilization->insert($values)) {
                $this->error_message = $this->costing_mobilization->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create costing shifts
     */
    private function _attempt_create_shifts($costing_id)
    {
        if (!array_key_exists('shifts', $this->payload) || !is_array($this->payload['shifts']) || count($this->payload['shifts']) == 0) {
            return true;
        }

        foreach ($this->payload['shifts'] as $shift) {
            $values = [
                'costing_id' => $costing_id,
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

            if (!$this->costing_shift->insert($values)) {
                $this->error_message = $this->costing_shift->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create costing consultations
     */
    private function _attempt_create_consultations($costing_id)
    {
        if (!array_key_exists('consultations', $this->payload) || !is_array($this->payload['consultations']) || count($this->payload['consultations']) == 0) {
            return true;
        }

        foreach ($this->payload['consultations'] as $consultation) {
            $values = [
                'costing_id' => $costing_id,
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

            if (!$this->costing_consultation->insert($values)) {
                $this->error_message = $this->costing_consultation->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update costing
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $costing_id = $this->payload['costing_id'];
        $where = [
                    'id' => $costing_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $package_name = $this->payload['package_name'] ?? null;
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$costing = $this->costing->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'costing is not found.'
            ];
        } elseif (!in_array($user->role_id, [4,19,22,32,20,21])) {
            $response_status = FAIL;
            $response = [
                "Only SAE, Business ops, Business Development Director, Sales Manager, Sales Director or Admin users can create costings. ",
            ];
        } elseif ($package_name && $this->costing->validate_package_name($package_name, $costing->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Cannot generate duplicate package name. ",
            ];
        //validate status
        } elseif ($status && $costing->status == 'approved') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Cannot update an approved costing. ",
            ];
        } elseif (!$this->_attempt_update($costing)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update costing.',
            ];
        } elseif (!$this->_attempt_update_details($costing->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing details. ",
            ];
        } elseif (!$this->_attempt_update_services($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing services. ",
            ];
        } elseif (!$this->_attempt_update_consultas($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing consultas. ",
            ];
        } elseif (!$this->_attempt_update_discounts($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing discounts. ",
            ];
        } elseif (!$this->_attempt_update_manpowers($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing manpowers. ",
            ];
        } elseif (!$this->_attempt_update_manpower_costs($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing manpower costs. ",
            ];
        } elseif (!$this->_attempt_update_mobilizations($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing mobilizations. ",
            ];
        } elseif (!$this->_attempt_update_shifts($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing shifts. ",
            ];
        } elseif (!$this->_attempt_update_consultations($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update costing consultations. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'costing_id' => $costing_id,
                'message'    => 'costing updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($costing)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->costing->allowedFields is declared at the top of the model
        foreach($this->costing->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $costing->id];

        if (!$this->costing->update($where, $values)) {
            $this->error_message = $this->costing->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update costing details
     */
    private function _attempt_update_details($costing_id)
    {
        // 1. Get all current detail IDs for this costing
        $existing_details = $this->costing_detail->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

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
                if (!$this->costing_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_detail->error();
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
                'costing_id'  => $costing_id,
                'lab_test_id' => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'branch_id'   => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
                'quantity' => array_key_exists('quantity', $detail) && $detail['quantity'] != '' ? $detail['quantity'] : null,
                'unit_price' => array_key_exists('unit_price', $detail) && $detail['unit_price'] != '' ? $detail['unit_price'] : null,
                'total_amount' => array_key_exists('total_amount', $detail) && $detail['total_amount'] != '' ? $detail['total_amount'] : null,
                'discount' => array_key_exists('discount', $detail) && $detail['discount'] != '' ? $detail['discount'] : null,
                'pro_rate_price' => array_key_exists('pro_rate_price', $detail) && $detail['pro_rate_price'] != '' ? $detail['pro_rate_price'] : null,
                'margin' => array_key_exists('margin', $detail) && $detail['margin'] != '' ? $detail['margin'] : null,
                'cost' => array_key_exists('cost', $detail) && $detail['cost'] != '' ? $detail['cost'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_detail->update(['id' => $detail['id']], $values)) {
                    $this->error_message = $this->costing_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_detail->insert($values)) {
                    $this->error_message = $this->costing_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update costing details
     */
    private function _attempt_update_services($costing_id)
    {
        // 1. Get all current service IDs for this costing
        $existing_services = $this->costing_service->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

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
                if (!$this->costing_service->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_service->error();
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
                'costing_id'    => $costing_id,
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
                $costing_service_id = $service['id'];
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_service->update(['id' => $service['id']], $values)) {
                    $this->error_message = $this->costing_service->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$costing_service_id = $this->costing_service->insert($values)) {
                    $this->error_message = $this->costing_service->error();
                    return false;
                }
            }
            $indexToId[$index] = (string) $costing_service_id;
            service_line_register_ids($keyToId, $service, $costing_service_id);

            $service_package_details = array_key_exists('details', $service) && is_array($service['details']) ? $service['details'] : [];

            if (count($service_package_details) > 0) {
                if (!$this->_attempt_update_service_package_details($costing_service_id, $costing_id, $service_package_details)) {
                    return false;
                }
            }
        }

        return $this->_rewrite_service_line_package_ids($this->costing_service, $this->payload['services'], $keyToId, $indexToId);
    }

    /**
     * Attempt to update costing service package details
     */
    private function _attempt_update_service_package_details($costing_service_id, $costing_id, $service_package_details)
    {
        // 1. Get all current service_package_details IDs for this costing
        $existing_service_package_details = $this->costing_service_package_detail->select('id', ['costing_service_id' => $costing_service_id, 'is_deleted' => 0]);

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
                if (!$this->costing_service_package_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_service_package_detail->error();
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
                'costing_service_id'    => $costing_service_id,
                'costing_id'    => $costing_id,
                'package_id'              => array_key_exists('package_id', $service_package_detail) && $service_package_detail['package_id'] != '' ? $service_package_detail['package_id'] : null,
                'lab_test_id'           => array_key_exists('lab_test_id', $service_package_detail) && $service_package_detail['lab_test_id'] != '' ? $service_package_detail['lab_test_id'] : null,
                'is_add_on'           => array_key_exists('is_add_on', $service_package_detail) && $service_package_detail['is_add_on'] != '' ? $service_package_detail['is_add_on'] : 0,
                'price'           => array_key_exists('price', $service_package_detail) && $service_package_detail['price'] != '' ? $service_package_detail['price'] : 0,
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

                if (!$this->costing_service_package_detail->update(['id' => $service_package_detail['id']], $values)) {
                    $this->error_message = $this->costing_service_package_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_service_package_detail->insert($values)) {
                    $this->error_message = $this->costing_service_package_detail->error();
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Attempt to update costing consultas
     */
    private function _attempt_update_consultas($costing_id)
    {
        // 1. Get all current consulta IDs for this costing
        $existing_consultas = $this->costing_consulta->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

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
                if (!$this->costing_consulta->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_consulta->error();
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
                'costing_id'    => $costing_id,
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

                if (!$this->costing_consulta->update(['id' => $consulta['id']], $values)) {
                    $this->error_message = $this->costing_consulta->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_consulta->insert($values)) {
                    $this->error_message = $this->costing_consulta->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update costing discounts
     */
    private function _attempt_update_discounts($costing_id)
    {
        // 1. Get all current discount IDs for this costing
        $existing_discounts = $this->costing_discount->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

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
                if (!$this->costing_discount->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_discount->error();
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
                'costing_id'    => $costing_id,
                'type'              => array_key_exists('type', $discount) && $discount['type'] != '' ? $discount['type'] : null,
                'type_id'           => array_key_exists('type_id', $discount) && $discount['type_id'] != '' ? $discount['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $discount) && $discount['type_price'] != '' ? $discount['type_price'] : null,
                'discount'         => array_key_exists('discount', $discount) && $discount['discount'] != '' ? $discount['discount'] : 0,
                'discount_amount'         => array_key_exists('discount_amount', $discount) && $discount['discount_amount'] != '' ? $discount['discount_amount'] : 0,
                'branch_id'   => array_key_exists('branch_id', $consulta) && $consulta['branch_id'] != '' ? $consulta['branch_id'] : null,
                'total_amount'    => array_key_exists('total_amount', $discount) && $discount['total_amount'] != '' ? $discount['total_amount'] : 0,
            ];

            if (!empty($discount['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_discount->update(['id' => $discount['id']], $values)) {
                    $this->error_message = $this->costing_discount->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_discount->insert($values)) {
                    $this->error_message = $this->costing_discount->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update costing manpowers
     */
    private function _attempt_update_manpowers($costing_id)
    {
        // 1. Get all current manpower IDs for this costing
        $existing_manpowers = $this->costing_manpower->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_manpowers, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('manpowers', $this->payload) && is_array($this->payload['manpowers'])) {
            foreach ($this->payload['manpowers'] as $manpower) {
                if (!empty($manpower['id'])) {
                    $payload_ids[] = (int)$manpower['id'];
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
                if (!$this->costing_manpower->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_manpower->error();
                    return false;
                }
            }
        }

        // 4. Process payload manpowers (update existing or insert new)
        if (!array_key_exists('manpowers', $this->payload) || !is_array($this->payload['manpowers'])) {
            return true;
        }

        foreach ($this->payload['manpowers'] as $manpower) {
            $values = [
                'costing_id'    => $costing_id,
                'description'              => array_key_exists('description', $manpower) && $manpower['description'] != '' ? $manpower['description'] : null,
                'manpower_type'              => array_key_exists('manpower_type', $manpower) && $manpower['manpower_type'] != '' ? $manpower['manpower_type'] : null,
                'qty'         => array_key_exists('qty', $manpower) && $manpower['qty'] != '' ? $manpower['qty'] : 0,
                'daily_rate'    => array_key_exists('daily_rate', $manpower) && $manpower['daily_rate'] != '' ? $manpower['daily_rate'] : 0,
                'total_daily_rate'    => array_key_exists('total_daily_rate', $manpower) && $manpower['total_daily_rate'] != '' ? $manpower['total_daily_rate'] : 0,
                'meals'    => array_key_exists('meals', $manpower) && $manpower['meals'] != '' ? $manpower['meals'] : 0,
                'ingress_count'    => array_key_exists('ingress_count', $manpower) && $manpower['ingress_count'] != '' ? $manpower['ingress_count'] : 0,
                'rate'    => array_key_exists('rate', $manpower) && $manpower['rate'] != '' ? $manpower['rate'] : 0,
                'pax_per_hour'    => array_key_exists('pax_per_hour', $manpower) && $manpower['pax_per_hour'] != '' ? $manpower['pax_per_hour'] : 0,
                'minutes_per_pax'    => array_key_exists('minutes_per_pax', $manpower) && $manpower['minutes_per_pax'] != '' ? $manpower['minutes_per_pax'] : 0,
                'active_hours'    => array_key_exists('active_hours', $manpower) && $manpower['active_hours'] != '' ? $manpower['active_hours'] : 0,
                'suggested_qty'    => array_key_exists('suggested_qty', $manpower) && $manpower['suggested_qty'] != '' ? $manpower['suggested_qty'] : 0,
                'patient_per_shift'    => array_key_exists('patient_per_shift', $manpower) && $manpower['patient_per_shift'] != '' ? $manpower['patient_per_shift'] : 0,
            ];

            if (!empty($manpower['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_manpower->update(['id' => $manpower['id']], $values)) {
                    $this->error_message = $this->costing_manpower->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_manpower->insert($values)) {
                    $this->error_message = $this->costing_manpower->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update costing manpower_costs
     */
    private function _attempt_update_manpower_costs($costing_id)
    {
        // 1. Get all current manpower_cost IDs for this costing
        $existing_manpower_costs = $this->costing_manpower_cost->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_manpower_costs, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('manpower_costs', $this->payload) && is_array($this->payload['manpower_costs'])) {
            foreach ($this->payload['manpower_costs'] as $manpower_cost) {
                if (!empty($manpower_cost['id'])) {
                    $payload_ids[] = (int)$manpower_cost['id'];
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
                if (!$this->costing_manpower_cost->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_manpower_cost->error();
                    return false;
                }
            }
        }

        // 4. Process payload manpower_costs (update existing or insert new)
        if (!array_key_exists('manpower_costs', $this->payload) || !is_array($this->payload['manpower_costs'])) {
            return true;
        }

        foreach ($this->payload['manpower_costs'] as $manpower_cost) {
            $values = [
                'costing_id'        => $costing_id,
                'description'       => array_key_exists('description', $manpower_cost) && $manpower_cost['description'] != '' ? $manpower_cost['description'] : null,
                'shift_no'       => array_key_exists('shift_no', $manpower_cost) && $manpower_cost['shift_no'] != '' ? $manpower_cost['shift_no'] : 0,
                'basic_pay'         => array_key_exists('basic_pay', $manpower_cost) && $manpower_cost['basic_pay'] != '' ? $manpower_cost['basic_pay'] : 0,
                'premium_pay'       => array_key_exists('premium_pay', $manpower_cost) && $manpower_cost['premium_pay'] != '' ? $manpower_cost['premium_pay'] : 0,
                'premium_pay_amount'       => array_key_exists('premium_pay_amount', $manpower_cost) && $manpower_cost['premium_pay_amount'] != '' ? $manpower_cost['premium_pay_amount'] : 0,
                'premium_hrs'       => array_key_exists('premium_hrs', $manpower_cost) && $manpower_cost['premium_hrs'] != '' ? $manpower_cost['premium_hrs'] : 0,
                'ingress_hrs'       => array_key_exists('ingress_hrs', $manpower_cost) && $manpower_cost['ingress_hrs'] != '' ? $manpower_cost['ingress_hrs'] : 0,
                'total_premium_pay' => array_key_exists('total_premium_pay', $manpower_cost) && $manpower_cost['total_premium_pay'] != '' ? $manpower_cost['total_premium_pay'] : 0,
                'total'             => array_key_exists('total', $manpower_cost) && $manpower_cost['total'] != '' ? $manpower_cost['total'] : 0,
            ];

            if (!empty($manpower_cost['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_manpower_cost->update(['id' => $manpower_cost['id']], $values)) {
                    $this->error_message = $this->costing_manpower_cost->error();
                    return false;
                }
                $manpower_cost_id = $manpower_cost['id'];
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$manpower_cost_id = $this->costing_manpower_cost->insert($values)) {
                    $this->error_message = $this->costing_manpower_cost->error();
                    return false;
                }
            }

            if (
                array_key_exists('premium_pays', $manpower_cost)
                && is_array($manpower_cost['premium_pays'])
                && count($manpower_cost['premium_pays']) > 0
            ) {
                // 1. Get all current manpower_cost IDs for this costing
                $existing_premium_pays = $this->costing_manpower_cost_premium_pay->select('id', ['costing_id' => $costing_id, 'costing_manpower_cost_id' => $manpower_cost_id, 'is_deleted' => 0]);
                $existing_ids = array_column($existing_premium_pays, 'id');

                // 2. Collect IDs from payload
                $payload_ids = [];
                if (array_key_exists('premium_pays', $manpower_cost) && is_array($manpower_cost['premium_pays'])) {
                    foreach ($manpower_cost['premium_pays'] as $premium_pay) {
                        if (!empty($premium_pay['id'])) {
                            $payload_ids[] = (int)$premium_pay['id'];
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
                        if (!$this->costing_manpower_cost_premium_pay->update(['id' => $delete_id], $delete_values)) {
                            $this->error_message = $this->costing_manpower_cost_premium_pay->error();
                            return false;
                        }
                    }
                }

                // 4. Process payload premium_pays (update existing or insert new)
                foreach ($manpower_cost['premium_pays'] as $premium_pay) {
                    $premium_pay_values = [
                        'costing_id'    => $costing_id,
                        'costing_manpower_cost_id'    => $manpower_cost_id,
                        'premium_pay'    => $premium_pay['premium_pay'],
                        'premium_pay_amount'    => $premium_pay['premium_pay_amount'],
                        'premium_hrs'    => $premium_pay['premium_hrs'],
                        'ingress_hrs'    => $premium_pay['ingress_hrs'],
                        'total_premium_pay'    => $premium_pay['total_premium_pay'],
                        'total'    => $premium_pay['total'],
                    ];

                    if (!empty($premium_pay['id'])) {
                        $premium_pay_values['updated_by'] = $this->payload['requester'];
                        $premium_pay_values['updated_on'] = date('Y-m-d H:i:s');

                        if (!$this->costing_manpower_cost_premium_pay->update(['id' => $premium_pay['id']], $premium_pay_values)) {
                            $this->error_message = $this->costing_manpower_cost_premium_pay->error();
                            return false;
                        }
                    } else {
                        $premium_pay_values['added_by'] = $this->payload['requester'];
                        $premium_pay_values['added_on'] = date('Y-m-d H:i:s');
                        
                        if (!$this->costing_manpower_cost_premium_pay->insert($premium_pay_values)) {
                            $this->error_message = $this->costing_manpower_cost_premium_pay->error();
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update costing mobilizations
     */
    private function _attempt_update_mobilizations($costing_id)
    {
        // 1. Get all current mobilization IDs for this costing
        $existing_mobilizations = $this->costing_mobilization->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_mobilizations, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('mobilizations', $this->payload) && is_array($this->payload['mobilizations'])) {
            foreach ($this->payload['mobilizations'] as $mobilization) {
                if (!empty($mobilization['id'])) {
                    $payload_ids[] = (int)$mobilization['id'];
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
                if (!$this->costing_mobilization->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_mobilization->error();
                    return false;
                }
            }
        }

        // 4. Process payload mobilizations (update existing or insert new)
        if (!array_key_exists('mobilizations', $this->payload) || !is_array($this->payload['mobilizations'])) {
            return true;
        }

        foreach ($this->payload['mobilizations'] as $mobilization) {
            $values = [
                'costing_id'    => $costing_id,
                'description'              => array_key_exists('description', $mobilization) && $mobilization['description'] != '' ? $mobilization['description'] : null,
                'unit'         => array_key_exists('unit', $mobilization) && $mobilization['unit'] != '' ? $mobilization['unit'] : 0,
                'amount'    => array_key_exists('amount', $mobilization) && $mobilization['amount'] != '' ? $mobilization['amount'] : 0,
                'total'    => array_key_exists('total', $mobilization) && $mobilization['total'] != '' ? $mobilization['total'] : 0,
            ];

            if (!empty($mobilization['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_mobilization->update(['id' => $mobilization['id']], $values)) {
                    $this->error_message = $this->costing_mobilization->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_mobilization->insert($values)) {
                    $this->error_message = $this->costing_mobilization->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update costing shifts
     */
    private function _attempt_update_shifts($costing_id)
    {
        // 1. Get all current shift IDs for this costing
        $existing_shifts = $this->costing_shift->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

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
                if (!$this->costing_shift->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_shift->error();
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
                'costing_id'    => $costing_id,
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

                if (!$this->costing_shift->update(['id' => $shift['id']], $values)) {
                    $this->error_message = $this->costing_shift->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_shift->insert($values)) {
                    $this->error_message = $this->costing_shift->error();
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Attempt to update costing consultations
     */
    private function _attempt_update_consultations($costing_id)
    {
        // 1. Get all current consultation IDs for this costing
        $existing_consultations = $this->costing_consultation->select('id', ['costing_id' => $costing_id, 'is_deleted' => 0]);

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
                if (!$this->costing_consultation->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->costing_consultation->error();
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
                'costing_id' => $costing_id,
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

                if (!$this->costing_consultation->update(['id' => $consultation['id']], $values)) {
                    $this->error_message = $this->costing_consultation->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->costing_consultation->insert($values)) {
                    $this->error_message = $this->costing_consultation->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status costing
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $costing_id = $this->payload['costing_id'];
        $where = [
                    'id' => $costing_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$costing = $this->costing->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'costing is not found.'
            ];
        } elseif (!in_array($user->role_id, [4, 20, 21, 26])) {
            $response_status = FAIL;
            $response = [
                "Only Sales Manager, Sales Director, Finance Director or Admin users can change costing status. ",
            ];
        } elseif ($user->role_id == 21 && $status == 'approved' && $costing->approved_by_sales_manager == null) {
            $response_status = FAIL;
            $response = [
                "Sales Director cannot approve a costing that is not yet approved by Sales Manager. ",
            ];
        } elseif ($user->role_id == 26 && $status == 'approved' && $costing->approved_by_sales_director == null) {
            $response_status = FAIL;
            $response = [
                "Finance Director cannot approve a costing that is not yet approved by Sales Director. ",
            ];
        //validate status
        } elseif ($status && $costing->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Costing is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($costing, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change costing status.',
            ];
        } elseif (!$this->_attempt_create_quotation($costing)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create quotation from approved costing.',
            ];
        } elseif (!$this->send_email_notif('costing_status_update', $costing_id, null, null, $status)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create quotation from approved costing.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $msg = "costing $status successfully.";
            if ($status == 'approved') {
                if ($user->role_id == 20) {
                    $msg = "costing approved by Sales Manager. Sales Director still needs to approve";
                } elseif ($user->role_id == 21) {
                    $msg = "costing approved by Sales Director. Finance Director still needs to approve";
                } elseif ($user->role_id == 26) {
                    $msg = "costing approved by Finance Director.";
                }
            }
            $response = [
                'costing_id' => $costing_id,
                'message'    => $msg
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($costing, $role_id)
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

            if ($role_id == 20) {
                $values['approved_by_sales_manager'] = $this->payload['requester'];
                $values['approved_on_sales_manager'] = date('Y-m-d H:i:s');
                $values['approved_reason_sales_manager'] = $this->payload['approved_reason'] ?? null;

                if (!$this->send_email_notif('costing_for_approval_by_sales_director', $costing->id)) {
                    return false;
                }
            } elseif ($role_id == 21) {
                $values['approved_by_sales_director'] = $this->payload['requester'];
                $values['approved_on_sales_director'] = date('Y-m-d H:i:s');
                $values['approved_reason_sales_director'] = $this->payload['approved_reason'] ?? null;

                if (!$this->send_email_notif('costing_for_approval_by_finance_director', $costing->id)) {
                    return false;
                }
            } elseif ($role_id == 26) {
                $values['approved_by_finance_director'] = $this->payload['requester'];
                $values['approved_on_finance_director'] = date('Y-m-d H:i:s');
                $values['approved_reason_finance_director'] = $this->payload['approved_reason'] ?? null;
                $values['status'] = 'approved';
            } elseif ($role_id == 4) { // Admin
                if ($costing->approved_by_sales_manager == null) {
                    $values['approved_by_sales_manager'] = $this->payload['requester'];
                    $values['approved_on_sales_manager'] = date('Y-m-d H:i:s');
                    $values['approved_reason_sales_manager'] = $this->payload['approved_reason'] ?? null;
                    // Admin acting as Sales Manager

                    if (!$this->send_email_notif('costing_for_approval_by_sales_director', $costing->id)) {
                        return false;
                    }
                } elseif ($costing->approved_by_sales_director == null) {
                    $values['approved_by_sales_director'] = $this->payload['requester'];
                    $values['approved_on_sales_director'] = date('Y-m-d H:i:s');
                    $values['approved_reason_sales_director'] = $this->payload['approved_reason'] ?? null;
                    // Admin acting as Sales Director

                    if (!$this->send_email_notif('costing_for_approval_by_finance_director', $costing->id)) {
                        return false;
                    }
                } elseif ($costing->approved_by_finance_director == null) {
                    $values['approved_by_finance_director'] = $this->payload['requester'];
                    $values['approved_on_finance_director'] = date('Y-m-d H:i:s');
                    $values['approved_reason_finance_director'] = $this->payload['approved_reason'] ?? null;
                    $values['status'] = 'approved';
                    // Admin acting as Finance Director
                }
            } else {
                $values['status'] = 'approved';
            }
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['approved_by_sales_manager'] = null;
            $values['approved_on_sales_manager'] = null;
            $values['approved_by_sales_director'] = null;
            $values['approved_on_sales_director'] = null;
            $values['approved_by_finance_director'] = null;
            $values['approved_on_finance_director'] = null;
            $values['disapproved_reason'] = $this->payload['disapproved_reason'];
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'returned') {
            $values['status'] = 'returned';
            $values['approved_by_sales_manager'] = null;
            $values['approved_on_sales_manager'] = null;
            $values['approved_by_sales_director'] = null;
            $values['approved_on_sales_director'] = null;
            $values['approved_by_finance_director'] = null;
            $values['approved_on_finance_director'] = null;
            $values['returned_reason'] = $this->payload['returned_reason'];
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

        $where = ['id' => $costing->id];

        if (!$this->costing->update($where, $values)) {
            $this->error_message = $this->costing->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to create a quotation
     */
    private function _attempt_create_quotation($costing)
    {
        // convert to array temporarily
        $costing = (array) $costing;
        $costingId = $costing['id'];

        $update_costing = $this->costing->select('', ['id' => $costingId, 'is_deleted' => 0], 1);
        if (!$update_costing || $update_costing->status != 'approved') {
            return true;
        }

        $creator = $this->user->select('role_id', ['id' => $update_costing->added_by, 'is_deleted' => 0], 1);
        $creatorRoleId = $creator ? $creator->role_id : null;
        $now = date('Y-m-d H:i:s');
        $emailSubject = 'quotation_for_approval_by_sales';
        $quotationStamps = [];

        // if ($creatorRoleId == ROLE_SALES_DIRECTOR) {
        //     // $emailSubject = 'contracts_for_approval_by_finance_manager';
        //     $emailSubject = 'contracts_for_approval_by_finance_director';
        //     $quotationStamps = [
        //         'status' => 'pending',
        //         'approved_by' => $update_costing->approved_by_sales_director,
        //         'approved_on' => $now,
        //         'approved_by_sales' => $update_costing->approved_by_sales_director,
        //         'approved_on_sales' => $now,
        //         'approved_by_sales_manager' => $update_costing->approved_by_sales_director,
        //         'approved_on_sales_manager' => $now,
        //         'approved_by_sales_director' => $update_costing->approved_by_sales_director,
        //         'approved_on_sales_director' => $now,
        //     ];
        // } elseif ($creatorRoleId == ROLE_SALES_MANAGER && $update_costing->approved_by_sales_manager && $update_costing->approved_by_sales_director) {
        //     // $emailSubject = 'contracts_for_approval_by_finance_manager';
        //     $emailSubject = 'contracts_for_approval_by_finance_director';
        //     $quotationStamps = [
        //         'status' => 'pending',
        //         'approved_by' => $update_costing->approved_by_sales_manager,
        //         'approved_on' => $now,
        //         'approved_by_sales_manager' => $update_costing->approved_by_sales_manager,
        //         'approved_on_sales_manager' => $update_costing->approved_on_sales_manager,
        //         'approved_by_sales_director' => $update_costing->approved_by_sales_director,
        //         'approved_on_sales_director' => $update_costing->approved_on_sales_director,
        //     ];
        // }

        foreach($this->quotation->allowedFields AS $field) {
            if(!array_key_exists($field, $costing)) {
                continue;
            }
            $values[$field] = $costing[$field]!=''?$costing[$field]:null;
        }

        $values['costing_id'] = $costingId;
        $values['quotation_no'] = 'QT-' . date('Ymd') . '-' . random_string('alnum', 6);
        $values['added_by'] = $this->payload['requester'];
        $values['added_on'] = $now;

        // revert to object
        $costing = (object) $costing;

        if (!$quotation_id = $this->quotation->insert($values)) {
            $this->error_message = $this->quotation->error();
            return false;
        }

        if (!empty($quotationStamps)) {
            $quotationStamps['updated_by'] = $this->payload['requester'];
            $quotationStamps['updated_on'] = $now;
            if (!$this->quotation->update(['id' => $quotation_id], $quotationStamps)) {
                $this->error_message = $this->quotation->error();
                return false;
            }
        }

        if (!$this->_attempt_create_quotation_details($costing, $quotation_id)) {
            return false;
        } elseif (!$this->_attempt_create_quotation_services($costing, $quotation_id)) {
            return false;
        } elseif (!$this->_attempt_create_quotation_shifts($costing, $quotation_id)) {
            return false;
        } elseif (!$this->_attempt_create_quotation_consultations($costing, $quotation_id)) {
            return false;
        } elseif (!$this->send_email_notif($emailSubject, $costingId, $quotation_id, $update_costing->request_id)) {
            return false;
        }

        return $quotation_id;
    }
    
    /**
     * Attempt to create quotation details
     */
    private function _attempt_create_quotation_details($costing, $quotation_id)
    {
        $costing_details = $this->costing_detail->get_details($costing->id);
        if (!$costing_details || count($costing_details) == 0) {
            return true;
        }

        foreach ($costing_details as $detail) {
            $values = [
                'quotation_id'    => $quotation_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'quantity' => array_key_exists('quantity', $detail) && $detail['quantity'] != '' ? $detail['quantity'] : null,
                'unit_price' => array_key_exists('unit_price', $detail) && $detail['unit_price'] != '' ? $detail['unit_price'] : null,
                'total_amount' => array_key_exists('total_amount', $detail) && $detail['total_amount'] != '' ? $detail['total_amount'] : null,
                'discount' => array_key_exists('discount', $detail) && $detail['discount'] != '' ? $detail['discount'] : null,
                'pro_rate_price' => array_key_exists('pro_rate_price', $detail) && $detail['pro_rate_price'] != '' ? $detail['pro_rate_price'] : null,
                'margin' => array_key_exists('margin', $detail) && $detail['margin'] != '' ? $detail['margin'] : null,
                'cost' => array_key_exists('cost', $detail) && $detail['cost'] != '' ? $detail['cost'] : null,
                'branch_id' => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->quotation_detail->insert($values)) {
                $this->error_message = $this->quotation_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create quotation services
     */
    private function _attempt_create_quotation_services($costing, $quotation_id)
    {
        $costing_services = $this->costing_service->get_details($costing->id);
        if (!$costing_services || count($costing_services) == 0) {
            return true;
        }

        $sourceIdToDestId = [];
        foreach ($costing_services as $service) {
            $values = [
                'quotation_id'    => $quotation_id,
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

            if (!$quotation_service_id = $this->quotation_service->insert($values)) {
                $this->error_message = $this->quotation_service->error();
                return false;
            }
            if (!empty($service['id'])) {
                $sourceIdToDestId[(string) $service['id']] = (string) $quotation_service_id;
            }

            $service_package_details = $this->costing_service_package_detail->get_details($service['id']) ?: [];

            if (count($service_package_details) > 0) {
                if (!$this->_attempt_create_quotation_service_package_details($quotation_service_id, $quotation_id, $service_package_details)) {
                    return false;
                }
            }
        }

        return $this->_remap_copied_service_package_ids($this->quotation_service, $costing_services, $sourceIdToDestId);
    }

    /**
     * Attempt to create quotation service package details
     */
    private function _attempt_create_quotation_service_package_details($quotation_service_id, $quotation_id, $service_package_details)
    {
        foreach ($service_package_details as $service_package_detail) {
            $values = [
                'quotation_service_id'    => $quotation_service_id,
                'quotation_id'    => $quotation_id,
                'package_id'              => array_key_exists('package_id', $service_package_detail) && $service_package_detail['package_id'] != '' ? $service_package_detail['package_id'] : null,
                'lab_test_id'           => array_key_exists('lab_test_id', $service_package_detail) && $service_package_detail['lab_test_id'] != '' ? $service_package_detail['lab_test_id'] : null,
                'is_add_on'           => array_key_exists('is_add_on', $service_package_detail) && $service_package_detail['is_add_on'] != '' ? $service_package_detail['is_add_on'] : 0,
                'price'           => array_key_exists('price', $service_package_detail) && $service_package_detail['price'] != '' ? $service_package_detail['price'] : 0,
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

            if (!$this->quotation_service_package_detail->insert($values)) {
                $this->error_message = $this->quotation_service_package_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create quotation shifts
     */
    private function _attempt_create_quotation_shifts($costing, $quotation_id)
    {
        $costing_shifts = $this->costing_shift->get_details($costing->id);
        if (!$costing_shifts || count($costing_shifts) == 0) {
            return true;
        }

        foreach ($costing_shifts as $shift) {
            $values = [
                'quotation_id'    => $quotation_id,
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

            if (!$this->quotation_shift->insert($values)) {
                $this->error_message = $this->quotation_shift->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create quotation consultations from costing
     */
    private function _attempt_create_quotation_consultations($costing, $quotation_id)
    {
        $costing_id = is_array($costing) ? $costing['id'] : $costing->id;
        $costing_consultations = $this->costing_consultation->get_details($costing_id);
        if (!$costing_consultations || count($costing_consultations) == 0) {
            return true;
        }

        foreach ($costing_consultations as $consultation) {
            $values = [
                'quotation_id' => $quotation_id,
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

            if (!$this->quotation_consultation->insert($values)) {
                $this->error_message = $this->quotation_consultation->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Delete costings
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $costing_id = $this->payload['costing_id'];

        $where = ['id' => $costing_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$costing = $this->costing->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'costing is not found.',
            ];
        } elseif (!$this->_attempt_delete($costing_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'costing is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'costing is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($costing_id)
    {
        $where = ['id' => $costing_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->costing->update($where, $values)) {
            $this->error_message = $this->costing->error();
            return false;
        }

        return true;
    }

    /**
     * Send Test Email Result
     */
    protected function send_email_notif($subject, $costing_id, $quotation_id = null, $request_id = null, $status = null)
    {
        $requesterId = $this->payload['requester'];
        $fallbackEmails = resolve_user_fallback_emails($this->user, $requesterId);
        $site_url = ACCOUNTING_FRONT_END_URL . "/requests";
        $primaryEmails = [];

        if (!$request_id && $costing_id) {
            $costing = $this->costing->select('request_id, company_id, package_name, added_by', ['id' => $costing_id, 'is_deleted' => 0], 1);
            $request_id = $costing ? $costing->request_id : null;
            $company_id = $costing ? $costing->company_id : null;
            $company = $this->company->select('name', ['id' => $company_id, 'is_deleted' => 0], 1);
            $company_name = $company ? $company->name : null;
            // $costing_packages = $this->costing_service->get_packages($costing_id) ?: null;
            $costing_packages = $costing ? $costing->package_name : null;
            $company_name_with_packages = $costing_packages ? "$company_name - $costing_packages" : $company_name;
        }

        if ($quotation_id) {
            $quotation = $this->quotation->select('company_id, package_name', ['id' => $quotation_id, 'is_deleted' => 0], 1);
            $company_id = $quotation ? $quotation->company_id : null;
            $company = $this->company->select('name', ['id' => $company_id, 'is_deleted' => 0], 1);
            $company_name = $company ? $company->name : null;
            // $quotation_packages = $this->quotation_service->get_packages($quotation_id) ?: null;
            $quotation_packages = $quotation ? $quotation->package_name : null;
            $company_name_with_packages = $quotation_packages ? "$company_name - $quotation_packages" : $company_name;
        }

        if ($subject == 'costing_for_approval_by_sales_manager') {
            $actor = $this->user->select('id, role_id', ['id' => $requesterId, 'is_deleted' => 0], 1);
            if ($actor && $actor->role_id == ROLE_SALES_AE) {
                $headEmail = resolve_immediate_head_email($this->user, $requesterId);
                if ($headEmail) {
                    $primaryEmails[] = $headEmail;
                }
            }
            if (!$primaryEmails) {
                $primaryEmails = resolve_emails_by_role($this->user, [ROLE_SALES_MANAGER]);
            }

            $subjectLine = "Costing for Approval by Sales Manager: $company_name_with_packages (Costing #$costing_id)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A costing (Costing ID: $costing_id) is awaiting your approval as Sales Manager. Please review the costing details and approve or disapprove the costing accordingly.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'costing_for_approval_by_sales_director') {
            $actor = $this->user->select('id, role_id', ['id' => $requesterId, 'is_deleted' => 0], 1);
            if ($actor && $actor->role_id == ROLE_SALES_MANAGER) {
                $headEmail = resolve_immediate_head_email($this->user, $requesterId);
                if ($headEmail) {
                    $primaryEmails[] = $headEmail;
                }
            }
            if (!$primaryEmails) {
                $primaryEmails = resolve_emails_by_role($this->user, [ROLE_SALES_DIRECTOR]);
            }

            $subjectLine = "Costing for Approval by Sales Director: $company_name_with_packages (Costing #$costing_id)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A costing (Costing ID: $costing_id) is awaiting your approval as Sales Director. Please review the costing details and approve or disapprove the costing accordingly.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'costing_for_approval_by_finance_director') {
            $primaryEmails = resolve_emails_by_role($this->user, [ROLE_FINANCE_DIRECTOR]);

            $subjectLine = "Costing for Approval by Finance Director: $company_name_with_packages (Costing #$costing_id)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A costing (Costing ID: $costing_id) is awaiting your approval as Finance Director. Please review the costing details and approve or disapprove the costing accordingly.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'quotation_for_approval_by_sales') {
            if ($request_id) {
                $addedByEmail = resolve_request_added_by_email($this->request_model, $this->user, $request_id);
                if ($addedByEmail) {
                    $primaryEmails[] = $addedByEmail;
                }
            }

            $displayId = $quotation_id ?: $costing_id;
            $subjectLine = "Costing for Approval by Sales: $company_name_with_packages (Costing #$costing_id)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A quotation (Quotation ID: $displayId) is awaiting your approval as Sales. Please review the quotation details and approve or disapprove the quotation accordingly.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        // } elseif ($subject == 'contracts_for_approval_by_finance_manager') {
        //     $primaryEmails = resolve_emails_by_role($this->user, [ROLE_FINANCE_MANAGER]);
        //     $displayId = $quotation_id ?: $costing_id;
        //     $subjectLine = "Contract for Approval by Finance Manager: $company_name_with_packages (Quotation #$displayId)";
        //     $message = "<!DOCTYPE html>
        // <html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
        // <body>
        // <p>A contract (Quotation ID: $displayId) is awaiting your approval as Finance Manager. Please review the contract details and approve or disapprove the contract accordingly.</p>
        // <p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
        // <p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'contracts_for_approval_by_finance_director') {
            $primaryEmails = resolve_emails_by_role($this->user, [ROLE_FINANCE_DIRECTOR]);
            $displayId = $quotation_id ?: $costing_id;
            $subjectLine = "Contract for Approval by Finance Director: $company_name_with_packages (Quotation #$displayId)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A contract (Quotation ID: $displayId) is awaiting your approval as Finance Director. Please review the contract details and approve or disapprove the contract accordingly.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'request_moved_to_costing') {
            $primaryEmails = [];
            $headEmail = resolve_immediate_head_email($this->user, $requesterId);
            if ($headEmail) {
                $primaryEmails[] = $headEmail;
            }

            // Who approved the request
            $requestRow = $request_id
                ? $this->request_model->select('approved_by', ['id' => $request_id, 'is_deleted' => 0], 1)
                : null;
            $requestApprover = ($requestRow && $requestRow->approved_by)
                ? $this->user->select('role_id', ['id' => $requestRow->approved_by, 'is_deleted' => 0], 1)
                : null;
            $approvedByText = $requestApprover
                ? ' by the ' . status_actor_role_label($requestApprover->role_id)
                : '';

            // Who reviews the costing next, based on the role of the costing creator
            $costingCreator = $this->user->select('role_id', ['id' => $requesterId, 'is_deleted' => 0], 1);
            $costingCreatorRole = $costingCreator ? (int) $costingCreator->role_id : null;
            $requestApproverRole = $requestApprover ? (int) $requestApprover->role_id : null;
            if ($costingCreatorRole === ROLE_SALES_DIRECTOR || $requestApproverRole === ROLE_SALES_DIRECTOR) {
                $reviewerLabel = 'Finance Director';
            } elseif ($costingCreatorRole === ROLE_SALES_MANAGER || $requestApproverRole === ROLE_SALES_MANAGER) {
                $reviewerLabel = 'Sales Director';
            } else {
                $reviewerLabel = 'Sales Manager';
            }

            $subjectLine = "Your Request Has Been Proceeded to Costing: $company_name_with_packages (Request #$request_id)";
            $message = "<!DOCTYPE html>
        <html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
        <body>
        <p>Your request (Request ID: $request_id) has been approved{$approvedByText} and is now being processed for costing under Costing #$costing_id.</p>
        <p>You will be notified once the costing has been reviewed by the $reviewerLabel.</p>";
        } elseif ($subject == 'costing_status_update') {
            $isApproved = $status === 'approved';
            $isReturned = $status === 'returned';
            $isDisapproved = $status === 'disapproved';

            // Only the request creator is notified, and only on approve / disapprove.
            if (!$isApproved && !$isDisapproved) {
                return true;
            }

            $primaryEmails = [];
            $fallbackEmails = [];
            $creatorEmail = $request_id
                ? resolve_request_added_by_email($this->request_model, $this->user, $request_id)
                : null;
            if ($creatorEmail) {
                $primaryEmails[] = $creatorEmail;
            } else {
                log_message('error', 'Costing status email skipped: no email found for request creator (costing #' . $costing_id . ')');
                return true;
            }

            $actor = $this->user->select('role_id', ['id' => $requesterId, 'is_deleted' => 0], 1);
            $actorLabel = status_actor_role_label($actor ? $actor->role_id : null);

            $statusLabel = $isApproved ? 'Approved' : ($isReturned ? 'Returned' : 'Disapproved');
            $statusMessage = $isApproved
                ? "Your costing (Costing #$costing_id) has been reviewed and <strong>approved</strong> by the $actorLabel."
                : ($isReturned
                    ? "We regret to inform you that your costing (Costing #$costing_id) has been reviewed and <strong>returned</strong> by the $actorLabel. Please reach out for further details."
                    : "We regret to inform you that your costing (Costing #$costing_id) has been reviewed and <strong>disapproved</strong> by the $actorLabel. Please reach out for further details.");

            $reasonHtml = '';
            if ($isApproved) {
                $reasonHtml = build_status_reason_html('Approval message', $this->payload['approved_reason'] ?? null);
            } elseif ($isDisapproved) {
                $reasonHtml = build_status_reason_html('Reason for disapproval', $this->payload['disapproved_reason'] ?? null);
            }

            $subjectLine = "Your Costing Has Been $statusLabel: $company_name_with_packages (Costing #$costing_id)";
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