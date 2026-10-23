<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Quotation;
use App\Models\Quotation_detail;
use App\Models\Quotation_service;
use App\Models\Quotation_service_package_detail;
use App\Models\Quotation_attachment;
use App\Models\Quotation_signed_moa;
use App\Models\Quotation_shift;
use App\Models\Quotation_consultation;
use App\Models\Quotation_history;
use App\Models\Costing;
use App\Models\Costing_consulta;
use App\Models\Costing_discount;
use App\Models\Costing_manpower;
use App\Models\Costing_manpower_cost;
use App\Models\Costing_mobilization;
use App\Models\Costing_shift;
use App\Models\User;
use App\Models\Role;
use App\Models\Request;
use App\Models\Package;
use App\Models\Package_detail;
use App\Models\Company;

class Quotations extends MYT_Controller
{
    protected $request;
    protected $quotation;
    protected $quotation_detail;
    protected $quotation_service;
    protected $quotation_service_package_detail;
    protected $quotation_attachment;
    protected $quotation_signed_moa;
    protected $quotation_shift;
    protected $quotation_consultation;
    protected $quotation_history;
    protected $costing;
    protected $costing_consulta;
    protected $costing_discount;
    protected $costing_manpower;
    protected $costing_manpower_cost;
    protected $costing_mobilization;
    protected $costing_shift;
    protected $user;
    protected $role;
    protected $request_model;
    protected $package;
    protected $package_detail;
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
        $this->quotation = new Quotation();
        $this->quotation_detail = new Quotation_detail();
        $this->quotation_service = new Quotation_service();
        $this->quotation_service_package_detail = new Quotation_service_package_detail();
        $this->quotation_attachment = new Quotation_attachment();
        $this->quotation_signed_moa = new Quotation_signed_moa();
        $this->quotation_shift = new Quotation_shift();
        $this->quotation_consultation = new Quotation_consultation();
        $this->quotation_history = new Quotation_history();
        $this->costing = new Costing();
        $this->costing_consulta = new Costing_consulta();
        $this->costing_discount = new Costing_discount();
        $this->costing_manpower = new Costing_manpower();
        $this->costing_manpower_cost = new Costing_manpower_cost();
        $this->costing_mobilization = new Costing_mobilization();
        $this->costing_shift = new Costing_shift();
        $this->user = new User();
        $this->role = new Role();
        $this->request_model = new Request();
        $this->package = new Package();
        $this->package_detail = new Package_detail();
        $this->company = new Company();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get quotations
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

        $quotation_id = $this->payload['quotation_id'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $screen = $this->payload['screen'] ?? null;
        $role_name = $this->payload['role_name'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $request_date_from = $this->payload['request_date_from'] ?? null;
        $request_date_to = $this->payload['request_date_to'] ?? null;
        $costing_date_from = $this->payload['costing_date_from'] ?? null;
        $costing_date_to = $this->payload['costing_date_to'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $quotations = $this->quotation->get_details($quotation_id, $company_id, $status, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $costing_date_from, $costing_date_to, $term, $page, $data_size, $request_added_by) ?: [];

        if (!$quotations) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No quotations found.'
            ];
        } else {
            $quotationIds = $this->_collect_ids($quotations, 'id');
            $userIds = array_values(array_unique(array_merge(
                $this->_collect_ids($quotations, 'added_by'),
                $this->_collect_ids($quotations, 'approved_by_sales'),
                $this->_collect_ids($quotations, 'approved_by_sales_manager'),
                $this->_collect_ids($quotations, 'approved_by_sales_director'),
                $this->_collect_ids($quotations, 'approved_by_finance_manager'),
                $this->_collect_ids($quotations, 'approved_by_finance_director'),
                $this->_collect_ids($quotations, 'approved_by')
            )));

            $requestIds = $this->_collect_ids($quotations, 'request_id');
            $requestsById = [];
            if ($requestIds) {
                foreach ($this->request_model->get_details($requestIds) ?: [] as $req) {
                    $requestsById[$req['id']] = $req;
                }
            }
            $userIds = array_values(array_unique(array_merge(
                $userIds,
                $this->_collect_ids(array_values($requestsById), 'added_by'),
                $this->_collect_ids(array_values($requestsById), 'approved_by')
            )));

            $usersById = $this->user->get_by_ids($userIds);
            $headIds = [];
            foreach ($usersById as $u) {
                if (!empty($u['immediate_head'])) {
                    $headIds[] = $u['immediate_head'];
                }
            }
            $missingHeadIds = array_values(array_diff(array_unique($headIds), array_keys($usersById)));
            if ($missingHeadIds) {
                $usersById += $this->user->get_by_ids($missingHeadIds);
            }

            $roleIds = $this->_collect_ids(array_values($usersById), 'role_id');
            $rolesById = $this->role->get_by_ids($roleIds);

            $detailsByQuotation = $this->_group_by($this->quotation_detail->get_details($quotationIds) ?: [], 'quotation_id');
            $shiftsByQuotation = $this->_group_by($this->quotation_shift->get_details($quotationIds) ?: [], 'quotation_id');
            $consultationsByQuotation = $this->_group_by($this->quotation_consultation->get_details($quotationIds) ?: [], 'quotation_id');
            $servicesByQuotation = $this->_group_by($this->quotation_service->get_details($quotationIds) ?: [], 'quotation_id');
            $historiesByQuotation = $this->_group_by($this->quotation_history->get_details($quotationIds) ?: [], 'source_id');
            $missingHistoryUserIds = array_values(array_diff(
                $this->_collect_grouped_ids($historiesByQuotation, 'added_by'),
                array_keys($usersById)
            ));
            if ($missingHistoryUserIds) {
                $usersById += $this->user->get_by_ids($missingHistoryUserIds);
            }

            $quotationServiceIds = [];
            foreach ($servicesByQuotation as $services) {
                foreach ($services as $service) {
                    if (($service['type'] ?? null) === 'package') {
                        $quotationServiceIds[] = $service['id'];
                    }
                }
            }
            $pkgByService = $this->_group_by(
                $this->quotation_service_package_detail->get_details(array_values(array_unique($quotationServiceIds))) ?: [],
                'quotation_service_id'
            );

            $costingIds = $this->_collect_ids($quotations, 'costing_id');
            $costingsById = [];
            if ($costingIds) {
                foreach ($this->costing->get_details($costingIds) ?: [] as $costingRow) {
                    $costingsById[$costingRow['id']] = $costingRow;
                }
            }
            $userIds = array_values(array_unique(array_merge(
                $userIds,
                $this->_collect_ids(array_values($costingsById), 'sae_in_charge_id')
            )));
            $missingSaeIds = array_values(array_diff(
                $this->_collect_ids(array_values($costingsById), 'sae_in_charge_id'),
                array_keys($usersById)
            ));
            if ($missingSaeIds) {
                $usersById += $this->user->get_by_ids($missingSaeIds);
            }

            $consultasByCosting = $this->_group_by($this->costing_consulta->get_details($costingIds) ?: [], 'costing_id');
            $discountsByCosting = $this->_group_by($this->costing_discount->get_details($costingIds) ?: [], 'costing_id');
            $manpowersByCosting = $this->_group_by($this->costing_manpower->get_details($costingIds) ?: [], 'costing_id');
            $manpowerCostsByCosting = $this->_group_by($this->costing_manpower_cost->get_details($costingIds) ?: [], 'costing_id');
            $mobilizationsByCosting = $this->_group_by($this->costing_mobilization->get_details($costingIds) ?: [], 'costing_id');
            $costingShiftsByCosting = $this->_group_by($this->costing_shift->get_details($costingIds) ?: [], 'costing_id');

            $customerAccountIds = $this->_collect_ids($quotations, 'customer_account_id');
            $customerAccountsById = [];
            if ($customerAccountIds) {
                foreach ($this->company->get_customer_accounts(implode(',', $customerAccountIds)) ?: [] as $account) {
                    $customerAccountsById[$account['company_id']][] = $account;
                }
            }

            $attachmentsByQuotation = [];
            $signedMoasByQuotation = [];
            if ($quotationIds) {
                $ph = implode(',', array_fill(0, count($quotationIds), '?'));
                $attachmentQuery = $this->quotation_attachment->db->query(
                    "SELECT * FROM quotation_attachment WHERE is_deleted = 0 AND quotation_id IN ($ph)",
                    $quotationIds
                );
                $attachmentsByQuotation = $this->_group_by($attachmentQuery ? $attachmentQuery->getResultArray() : [], 'quotation_id');

                $signedMoaQuery = $this->quotation_signed_moa->db->query(
                    "SELECT * FROM quotation_signed_moa WHERE is_deleted = 0 AND quotation_id IN ($ph)",
                    $quotationIds
                );
                $signedMoasByQuotation = $this->_group_by($signedMoaQuery ? $signedMoaQuery->getResultArray() : [], 'quotation_id');
            }

            foreach ($quotations as $key => $quotation) {
                $qid = $quotation['id'];
                $addedBy = $usersById[$quotation['added_by']] ?? null;
                $quotations[$key]['added_by_name'] = $addedBy['name'] ?? null;
                $quotations[$key]['added_by_role_name'] = ($addedBy && isset($rolesById[$addedBy['role_id']]))
                    ? $rolesById[$addedBy['role_id']]['name']
                    : null;
                $quotations[$key]['added_by_email'] = $addedBy['email'] ?? null;
                $quotations[$key]['added_by_mobile_no'] = $addedBy['mobile_no'] ?? null;
                $quotations[$key]['signature'] = $addedBy['signature'] ?? null;
                $quotations[$key]['signature_url'] = $addedBy
                    ? (VM4_ENDPOINT . 'assets/signatures/' . $addedBy['signature'])
                    : null;

                $immediateHead = null;
                if ($addedBy && !empty($addedBy['immediate_head']) && isset($usersById[$addedBy['immediate_head']])) {
                    $immediateHead = (object) $usersById[$addedBy['immediate_head']];
                    $immediateHead->signature_url = VM4_ENDPOINT . 'assets/signatures/' . ($immediateHead->signature ?? '');
                    $immediateHead->immediate_head_role_name = isset($rolesById[$immediateHead->role_id])
                        ? $rolesById[$immediateHead->role_id]['name']
                        : null;
                }
                $quotations[$key]['immediate_head'] = $immediateHead;

                $quotations[$key]['approved_by_sales_name'] = isset($usersById[$quotation['approved_by_sales']])
                    ? $usersById[$quotation['approved_by_sales']]['name']
                    : null;
                $quotations[$key]['approved_by_sales_manager_name'] = isset($usersById[$quotation['approved_by_sales_manager']])
                    ? $usersById[$quotation['approved_by_sales_manager']]['name']
                    : null;
                $quotations[$key]['approved_by_sales_director_name'] = isset($usersById[$quotation['approved_by_sales_director']])
                    ? $usersById[$quotation['approved_by_sales_director']]['name']
                    : null;
                $quotations[$key]['approved_by_finance_manager_name'] = isset($usersById[$quotation['approved_by_finance_manager']])
                    ? $usersById[$quotation['approved_by_finance_manager']]['name']
                    : null;
                $quotations[$key]['approved_by_finance_director_name'] = isset($usersById[$quotation['approved_by_finance_director']])
                    ? $usersById[$quotation['approved_by_finance_director']]['name']
                    : null;
                $quotations[$key]['approved_by_name'] = isset($usersById[$quotation['approved_by']])
                    ? $usersById[$quotation['approved_by']]['name']
                    : null;

                $requestData = isset($requestsById[$quotation['request_id']])
                    ? (object) $requestsById[$quotation['request_id']]
                    : null;
                if ($requestData) {
                    $reqAddedBy = $usersById[$requestData->added_by] ?? null;
                    $requestData->added_by_name = $reqAddedBy['name'] ?? null;
                    $requestData->added_by_role_name = ($reqAddedBy && isset($rolesById[$reqAddedBy['role_id']]))
                        ? $rolesById[$reqAddedBy['role_id']]['name']
                        : null;
                    $requestData->added_by_email = $reqAddedBy['email'] ?? null;
                    $requestData->signature = $reqAddedBy['signature'] ?? null;
                    $requestData->signature_url = $reqAddedBy
                        ? (VM4_ENDPOINT . 'assets/signatures/' . $reqAddedBy['signature'])
                        : null;

                    $reqImmediateHead = null;
                    if ($reqAddedBy && !empty($reqAddedBy['immediate_head']) && isset($usersById[$reqAddedBy['immediate_head']])) {
                        $reqImmediateHead = (object) $usersById[$reqAddedBy['immediate_head']];
                        $reqImmediateHead->signature_url = VM4_ENDPOINT . 'assets/signatures/' . ($reqImmediateHead->signature ?? '');
                        $reqImmediateHead->immediate_head_role_name = isset($rolesById[$reqImmediateHead->role_id])
                            ? $rolesById[$reqImmediateHead->role_id]['name']
                            : null;
                    }
                    $requestData->immediate_head = $reqImmediateHead;

                    $reqApprovedBy = $usersById[$requestData->approved_by] ?? null;
                    $requestData->approved_by_name = $reqApprovedBy['name'] ?? null;
                    $requestData->approved_by_role_name = ($reqApprovedBy && isset($rolesById[$reqApprovedBy['role_id']]))
                        ? $rolesById[$reqApprovedBy['role_id']]['name']
                        : null;
                }
                $quotations[$key]['request'] = $requestData;

                $quotations[$key]['details'] = $detailsByQuotation[$qid] ?? [];
                $quotations[$key]['shifts'] = $shiftsByQuotation[$qid] ?? [];
                $quotations[$key]['consultations'] = $consultationsByQuotation[$qid] ?? [];

                $services = $servicesByQuotation[$qid] ?? [];
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
                $quotations[$key]['services'] = $services;

                $costingRows = [];
                if (!empty($quotation['costing_id']) && isset($costingsById[$quotation['costing_id']])) {
                    $costingRows = [$costingsById[$quotation['costing_id']]];
                }
                foreach ($costingRows as $d_key => $costing) {
                    $cid = $costing['id'];
                    if (!empty($costing['sae_in_charge_id']) && isset($usersById[$costing['sae_in_charge_id']])) {
                        $costingRows[$d_key]['sae_in_charge_name'] = $usersById[$costing['sae_in_charge_id']]['name'];
                    }
                    $costingRows[$d_key]['consultas'] = $consultasByCosting[$cid] ?? [];
                    $costingRows[$d_key]['discounts'] = $discountsByCosting[$cid] ?? [];
                    $costingRows[$d_key]['manpowers'] = $manpowersByCosting[$cid] ?? [];
                    $costingRows[$d_key]['manpower_costs'] = $manpowerCostsByCosting[$cid] ?? [];
                    $costingRows[$d_key]['mobilizations'] = $mobilizationsByCosting[$cid] ?? [];
                    $costingRows[$d_key]['shifts'] = $costingShiftsByCosting[$cid] ?? [];
                }
                $quotations[$key]['costing'] = $costingRows;

                if (!empty($quotation['customer_account_id'])) {
                    $quotations[$key]['customer_account_details'] = $this->_customer_account_details($customerAccountsById, $quotation['customer_account_id']);
                }

                $quotations[$key]['attachments'] = $attachmentsByQuotation[$qid] ?? [];
                $quotations[$key]['signed_moas'] = $signedMoasByQuotation[$qid] ?? [];
                $quotations[$key]['histories'] = $historiesByQuotation[$qid] ?? [];
                $quotations[$key] = $this->_apply_reason_trails($quotations[$key], $quotations[$key]['histories'], $usersById);
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $this->quotation->get_count($quotation_id, $company_id, $status, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $costing_date_from, $costing_date_to, $term, $request_added_by),
                'data' => $quotations
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create quotation
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $version_no = $this->payload['version_no'] ?? null;

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!in_array($user->role_id, [4, 22, 19])) {
            $response_status = FAIL;
            $response = [
                "Only Business ops, Sales or Admin users can create quotations. ",
            ];
        } elseif (!$version_no || $version_no == null) {
            $response_status = FAIL;
            $response = [
                "Version no is required ",
            ];
        } elseif (!$quotation_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add quotation. ",
            ];
        } elseif (!$this->_attempt_create_details($quotation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add quotation details. ",
            ];
            // } elseif (!$this->_attempt_create_services($quotation_id)) {
            //     $this->db->transRollback();
            //     $response_status = FAIL;
            //     $response = [
            //         "Failed to add quotation services. ",
            //     ];
        } elseif (!$this->_attempt_create_shifts($quotation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add quotation shifts. ",
            ];
        } elseif (!$this->_attempt_create_consultations($quotation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add quotation consultations. ",
            ];
            // Switch true and false to make the attachment a mandatory field
        } elseif ($this->send_email_notif('request_moved_to_quotation', $quotation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } elseif (($this->request->getFileMultiple('attachments') ? true : false) && !$this->_upload_attachments($quotation_id, 'assets/quotations/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'quotation_id' => $quotation_id,
                'message'    => 'quotation added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a quotation
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach ($this->quotation->allowedFields as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$quotation_id = $this->quotation->insert($values)) {
            $this->error_message = $this->quotation->error();
            return false;
        }

        return $quotation_id;
    }

    /**
     * Attempt to create quotation details
     */
    private function _attempt_create_details($quotation_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'quotation_id'    => $quotation_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'branch_id'   => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
                'quantity' => array_key_exists('quantity', $detail) && $detail['quantity'] != '' ? $detail['quantity'] : null,
                'unit_price' => array_key_exists('unit_price', $detail) && $detail['unit_price'] != '' ? $detail['unit_price'] : null,
                'total_amount' => array_key_exists('total_amount', $detail) && $detail['total_amount'] != '' ? $detail['total_amount'] : null,
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
    private function _attempt_create_services($quotation_id)
    {
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services']) || count($this->payload['services']) == 0) {
            return true;
        }

        $keyToId = [];
        $indexToId = [];
        foreach ($this->payload['services'] as $index => $service) {
            $values = [
                'quotation_id'    => $quotation_id,
                'type'          => array_key_exists('type', $service) && $service['type'] != '' ? $service['type'] : null,
                'type_id'       => array_key_exists('type_id', $service) && $service['type_id'] != '' ? $service['type_id'] : null,
                'type_price'           => array_key_exists('type_price', $service) && $service['type_price'] != '' ? $service['type_price'] : null,
                'mark_up'      => array_key_exists('mark_up', $service) && $service['mark_up'] != '' ? $service['mark_up'] : null,
                'total_amount' => array_key_exists('total_amount', $service) && $service['total_amount'] != '' ? $service['total_amount'] : null,
                'branch_id'   => array_key_exists('branch_id', $service) && $service['branch_id'] != '' ? $service['branch_id'] : null,
                'charge_to'           => array_key_exists('charge_to', $service) && $service['charge_to'] != '' ? $service['charge_to'] : null,
                'charge_to_ids'           => array_key_exists('charge_to_ids', $service) && $service['charge_to_ids'] != '' ? $service['charge_to_ids'] : null,
                ...$this->_service_add_on_fields($service),
                'head_count'           => array_key_exists('head_count', $service) && $service['head_count'] != '' ? $service['head_count'] : null,
                'discount_amount'           => array_key_exists('discount_amount', $service) && $service['discount_amount'] != '' ? $service['discount_amount'] : null,
                'margin_amount'           => array_key_exists('margin_amount', $service) && $service['margin_amount'] != '' ? $service['margin_amount'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$serviceId = $this->quotation_service->insert($values)) {
                $this->error_message = $this->quotation_service->error();
                return false;
            }
            $indexToId[$index] = (string) $serviceId;
            service_line_register_ids($keyToId, $service, $serviceId);
        }

        return $this->_rewrite_service_line_package_ids($this->quotation_service, $this->payload['services'], $keyToId, $indexToId);
    }

    /**
     * Attempt to create quotation shifts
     */
    private function _attempt_create_shifts($quotation_id)
    {
        if (!array_key_exists('shifts', $this->payload) || !is_array($this->payload['shifts']) || count($this->payload['shifts']) == 0) {
            return true;
        }

        foreach ($this->payload['shifts'] as $shift) {
            $values = [
                'quotation_id' => $quotation_id,
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
     * Attempt to create quotation consultations
     */
    private function _attempt_create_consultations($quotation_id)
    {
        if (!array_key_exists('consultations', $this->payload) || !is_array($this->payload['consultations']) || count($this->payload['consultations']) == 0) {
            return true;
        }

        foreach ($this->payload['consultations'] as $consultation) {
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
     * Update quotation
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $quotation_id = $this->payload['quotation_id'] ?? null;
        $where = [
            'id' => $quotation_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$quotation_id) {
            $response_status = FAIL;
            $response = [
                'quotation_id is required.'
            ];
        } elseif (!$quotation = $this->quotation->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'quotation is not found.'
            ];
        } elseif (!in_array($user->role_id, [4, 22, 19, 20, 21, 23, 26])) {
            $response_status = FAIL;
            $response = [
                "Only Business ops, Sales, Sales Manager, Sales Director, Finance Manager, and Finance Director or Admin users can update quotations. ",
            ];
            //validate status
        } elseif ($status && $quotation->status == 'approved') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Cannot update an approved quotation. ",
            ];
        } elseif (!$this->_attempt_update($quotation)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update quotation.',
            ];
        } elseif (!$this->_attempt_update_details($quotation->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update quotation details. ",
            ];
        } elseif (!$this->_attempt_update_services($quotation->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update quotation services. ",
            ];
        } elseif (!$this->_attempt_update_shifts($quotation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update quotation shifts. ",
            ];
        } elseif (!$this->_attempt_update_consultations($quotation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update quotation consultations. ",
            ];
            // Attachments is deleted if no file is uploaded
        } elseif (!$this->_upload_attachments($quotation_id, 'assets/quotations/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'quotation_id' => $quotation_id,
                'message'    => 'quotation updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($quotation)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->quotation->allowedFields is declared at the top of the model
        foreach ($this->quotation->allowedFields as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $quotation->id];

        if (!$this->quotation->update($where, $values)) {
            $this->error_message = $this->quotation->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update quotation details
     */
    private function _attempt_update_details($quotation_id)
    {
        // 1. Get all current detail IDs for this quotation
        $existing_details = $this->quotation_detail->select('id', ['quotation_id' => $quotation_id, 'is_deleted' => 0]);

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
                if (!$this->quotation_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->quotation_detail->error();
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
                'quotation_id'  => $quotation_id,
                'lab_test_id' => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'branch_id' => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
                'quantity' => array_key_exists('quantity', $detail) && $detail['quantity'] != '' ? $detail['quantity'] : null,
                'unit_price' => array_key_exists('unit_price', $detail) && $detail['unit_price'] != '' ? $detail['unit_price'] : null,
                'total_amount' => array_key_exists('total_amount', $detail) && $detail['total_amount'] != '' ? $detail['total_amount'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->quotation_detail->update(['id' => $detail['id']], $values)) {
                    $this->error_message = $this->quotation_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->quotation_detail->insert($values)) {
                    $this->error_message = $this->quotation_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update quotation details
     */
    private function _attempt_update_services($quotation_id)
    {
        // 1. Get all current service IDs for this quotation
        $existing_services = $this->quotation_service->select('id', ['quotation_id' => $quotation_id, 'is_deleted' => 0]);

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
                if (!$this->quotation_service->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->quotation_service->error();
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
            ];

            if (!empty($service['id'])) {
                $quotation_service_id = $service['id'];
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->quotation_service->update(['id' => $service['id']], $values)) {
                    $this->error_message = $this->quotation_service->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$quotation_service_id = $this->quotation_service->insert($values)) {
                    $this->error_message = $this->quotation_service->error();
                    return false;
                }
            }
            $indexToId[$index] = (string) $quotation_service_id;
            service_line_register_ids($keyToId, $service, $quotation_service_id);

            $service_package_details = array_key_exists('details', $service) && is_array($service['details']) ? $service['details'] : [];

            if (count($service_package_details) > 0) {
                if (!$this->_attempt_update_service_package_details($quotation_service_id, $quotation_id, $service_package_details)) {
                    return false;
                }
            }
        }

        return $this->_rewrite_service_line_package_ids($this->quotation_service, $this->payload['services'], $keyToId, $indexToId);
    }

    /**
     * Attempt to update quotation service package details
     */
    private function _attempt_update_service_package_details($quotation_service_id, $quotation_id, $service_package_details)
    {
        // 1. Get all current service_package_details IDs for this quotation
        $existing_service_package_details = $this->quotation_service_package_detail->select('id', ['quotation_service_id' => $quotation_service_id, 'is_deleted' => 0]);

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
                if (!$this->quotation_service_package_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->quotation_service_package_detail->error();
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
                'quotation_service_id'    => $quotation_service_id,
                'quotation_id'    => $quotation_id,
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

                if (!$this->quotation_service_package_detail->update(['id' => $service_package_detail['id']], $values)) {
                    $this->error_message = $this->quotation_service_package_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->quotation_service_package_detail->insert($values)) {
                    $this->error_message = $this->quotation_service_package_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update quotation shifts
     */
    private function _attempt_update_shifts($quotation_id)
    {
        // 1. Get all current shift IDs for this quotation
        $existing_shifts = $this->quotation_shift->select('id', ['quotation_id' => $quotation_id, 'is_deleted' => 0]);

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
                if (!$this->quotation_shift->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->quotation_shift->error();
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
                'quotation_id'    => $quotation_id,
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

                if (!$this->quotation_shift->update(['id' => $shift['id']], $values)) {
                    $this->error_message = $this->quotation_shift->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->quotation_shift->insert($values)) {
                    $this->error_message = $this->quotation_shift->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update quotation consultations
     */
    private function _attempt_update_consultations($quotation_id)
    {
        // 1. Get all current consultation IDs for this quotation
        $existing_consultations = $this->quotation_consultation->select('id', ['quotation_id' => $quotation_id, 'is_deleted' => 0]);

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
                if (!$this->quotation_consultation->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->quotation_consultation->error();
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
            ];

            if (!empty($consultation['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->quotation_consultation->update(['id' => $consultation['id']], $values)) {
                    $this->error_message = $this->quotation_consultation->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->quotation_consultation->insert($values)) {
                    $this->error_message = $this->quotation_consultation->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update quotation
     */
    public function upload()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $quotation_id = $this->payload['quotation_id'];
        $where = [
            'id' => $quotation_id,
            'is_deleted' => 0
        ];

        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        if (!$quotation = $this->quotation->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'quotation is not found.'
            ];
            // Attachments is deleted if no file is uploaded
        } elseif (!$this->_upload_signed_moas($quotation_id, 'assets/quotations/signed_moa')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload signed_moa. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'quotation_id' => $quotation_id,
                'message'    => 'quotation updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    public function add_note()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $quotation_id = $this->payload['quotation_id'];
        $where = [
            'id' => $quotation_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        if (!$quotation = $this->quotation->select('', $where, 1)) {
            $response_status = FAIL;
            $response = [
                'Quotation not found.'
            ];
        } else {
            $note = $this->payload['note'];

            if (!$this->quotation->update(['id' => $quotation_id], ['note' => $note])) {
                $response_status = FAIL_NOT_FOUND;
                $response = [
                    'Failed to update the quotation.'
                ];
                return $this->_create_response($response_status, $response);
            }

            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'quotation_id' => $quotation_id,
                'message'    => 'quotation updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Change status quotation
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $quotation_id = $this->payload['quotation_id'];
        $is_per_service = $this->payload['is_per_service'] ?? 0;

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if ($is_per_service == 1) {
            //service-level approval
            if ($quotation_id == null || $quotation_id == '') {
                $response_status = FAIL;
                $response = [
                    'Quotation ID is required.'
                ];
            } elseif (!array_key_exists('services', $this->payload) || !is_array($this->payload['services'])) {
                $response_status = FAIL;
                $response = [
                    'Services data is required and must be an array.'
                ];
            } elseif (!$this->_validate_services_status($this->payload['services'])) {
                $response_status = FAIL;
                $response = [
                    'Each service must have a valid id and status (approved, disapproved, or returned).'
                ];
            } elseif (!$this->_attempt_update_services_status($quotation_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to change quotation services status.',
                ];
            } elseif (!$this->send_email_notif('quotation_status_update', $quotation_id, $status)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to send email notification.',
                ];
            } else {
                $this->db->transCommit();
                $response_status = SUCCESS;
                $response = [
                    'quotation_id' => $quotation_id,
                    'message'    => "Quotation services status updated successfully."
                ];
            }
        } else {
            //quotation-level approval
            if ($status == null || $status == '') {
                $response_status = FAIL;
                $response = [
                    'Status is required.'
                ];
            } elseif (is_array($status)) {
                $response_status = FAIL;
                $response = [
                    'Status must not be an array.'
                ];
            } elseif (!in_array($status, ['approved', 'disapproved', 'returned'])) {
                $response_status = FAIL;
                $response = [
                    'Invalid status value.'
                ];
            } elseif ($quotation_id == null || $quotation_id == '') {
                $response_status = FAIL;
                $response = [
                    'Quotation ID is required.'
                ];
            } elseif (!$quotation = $this->quotation->select('', ['id' => $quotation_id, 'is_deleted' => 0], 1)) {
                $response_status = FAIL_NOT_FOUND;
                $response = [
                    'quotation is not found.'
                ];
            } elseif (!in_array($user->role_id, [4, 19, 20, 21, /* 23, */ 26])) {
                $response_status = FAIL;
                $response = [
                    "Only Sales, Sales Manager, Sales Director, Finance Director or Admin users can change quotation status. ",
                ];
            // } elseif ($user->role_id == 20 && $status == 'approved' && $quotation->approved_by_sales == null) {
            //     $response_status = FAIL;
            //     $response = [
            //         "Sales Manager cannot approve a quotation that is not yet approved by Sales. ",
            //     ];
            } elseif ($user->role_id == 21 && $status == 'approved' && $quotation->approved_by_sales_manager == null) {
                $response_status = FAIL;
                $response = [
                    "Sales Director cannot approve a quotation that is not yet approved by Sales Manager. ",
                ];
            // } elseif ($user->role_id == 23 && $status == 'approved' && $quotation->approved_by_sales_director == null) {
            //     $response_status = FAIL;
            //     $response = [
            //         "Finance Manager cannot approve a quotation that is not yet approved by Sales Director. ",
            //     ];
            } elseif ($user->role_id == 26 && $status == 'approved' && $quotation->approved_by_sales_director == null) {
                $response_status = FAIL;
                $response = [
                    "Finance Director cannot approve a quotation that is not yet approved by Sales Director. ",
                ];
                //validate status
            } elseif ($status && $quotation->status == $status) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    "Quotation is already $status. ",
                ];
            } elseif (!$this->_attempt_update_status($quotation, $user->role_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to change quotation status.',
                ];
            } elseif (!$this->send_email_notif('quotation_status_update', $quotation_id, $status)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to send email notification.',
                ];
            } else {
                $this->db->transCommit();
                $response_status = SUCCESS;

                // Build correct success message
                if ($status == 'approved') {
                    switch ($user->role_id) {

                        case 19: // Sales
                            $msg = "Quotation approved by Sales. Sales Manager still needs to approve.";
                            break;

                        case 20: // Sales Manager
                            $msg = "Quotation approved by Sales Manager. Sales Director still needs to approve.";
                            break;

                        case 21: // Sales Director
                            $msg = "Quotation approved by Sales Director. Finance Director still needs to approve.";
                            break;

                        // case 23: // Finance Manager
                        //     $msg = "Quotation approved by Finance Manager. All required approvals completed.";
                        //     break;

                        case 26: // Finance Director
                            $msg = "Quotation approved by Finance Director. All required approvals completed.";
                            break;

                        case 4: // Admin
                            $msg = "Quotation approved by Admin.";
                            break;

                        default:
                            $msg = "Quotation approved successfully.";
                    }
                } else {
                    // For disapproved or other statuses
                    $msg = "Quotation $status successfully.";
                }

                $response = [
                    'quotation_id' => $quotation_id,
                    'message'      => $msg
                ];
            }
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($quotation, $role_id)
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
            if ($role_id == 19) {
                $values['approved_by_sales'] = $this->payload['requester'];
                $values['approved_on_sales'] = date('Y-m-d H:i:s');
                $values['approved_reason_sales'] = $this->payload['approved_reason'] ?? null;

                if (!$this->send_email_notif('contracts_for_approval_by_sales_manager', $quotation->id)) {
                    return false;
                }
            } elseif ($role_id == 20) {
                if ($quotation->approved_by_sales == null) {
                    $values['approved_by_sales'] = $this->payload['requester'];
                    $values['approved_on_sales'] = date('Y-m-d H:i:s');
                    $values['approved_reason_sales'] = $this->payload['approved_reason'] ?? null;
                }

                $values['approved_by_sales_manager'] = $this->payload['requester'];
                $values['approved_on_sales_manager'] = date('Y-m-d H:i:s');
                $values['approved_reason_sales_manager'] = $this->payload['approved_reason'] ?? null;

                if (!$this->send_email_notif('contracts_for_approval_by_sales_director', $quotation->id)) {
                    return false;
                }
            } elseif ($role_id == 21) {
                $values['approved_by_sales_director'] = $this->payload['requester'];
                $values['approved_on_sales_director'] = date('Y-m-d H:i:s');
                $values['approved_reason_sales_director'] = $this->payload['approved_reason'] ?? null;

                // if (!$this->send_email_notif('contracts_for_approval_by_finance_manager', $quotation->id)) {
                //     return false;
                // }
                if (!$this->send_email_notif('contracts_for_approval_by_finance_director', $quotation->id)) {
                    return false;
                }
            // } elseif ($role_id == 23) {
            //     $values['approved_by_finance_manager'] = $this->payload['requester'];
            //     $values['approved_on_finance_manager'] = date('Y-m-d H:i:s');
            //     $values['approved_reason_finance_manager'] = $this->payload['approved_reason'] ?? null;
            //     $values['status'] = 'approved';
            //
            //     if (!$this->send_email_notif('contracts_for_moa', $quotation->id)) {
            //         return false;
            //     }
            } elseif ($role_id == 26) {
                $values['approved_by_finance_director'] = $this->payload['requester'];
                $values['approved_on_finance_director'] = date('Y-m-d H:i:s');
                $values['approved_reason_finance_director'] = $this->payload['approved_reason'] ?? null;
                $values['status'] = 'approved';

                if (!$this->send_email_notif('contracts_for_moa', $quotation->id)) {
                    return false;
                }
            } elseif ($role_id == 4) { // Admin
                if ($quotation->approved_by_sales == null) {
                    $values['approved_by_sales'] = $this->payload['requester'];
                    $values['approved_on_sales'] = date('Y-m-d H:i:s');
                    $values['approved_reason_sales'] = $this->payload['approved_reason'] ?? null;

                    if (!$this->send_email_notif('contracts_for_approval_by_sales_manager', $quotation->id)) {
                        return false;
                    }
                } elseif ($quotation->approved_by_sales_manager == null) {
                    $values['approved_by_sales_manager'] = $this->payload['requester'];
                    $values['approved_on_sales_manager'] = date('Y-m-d H:i:s');
                    $values['approved_reason_sales_manager'] = $this->payload['approved_reason'] ?? null;
                    // Admin acting as Sales Manager

                    if (!$this->send_email_notif('contracts_for_approval_by_sales_director', $quotation->id)) {
                        return false;
                    }
                } elseif ($quotation->approved_by_sales_director == null) {
                    $values['approved_by_sales_director'] = $this->payload['requester'];
                    $values['approved_on_sales_director'] = date('Y-m-d H:i:s');
                    $values['approved_reason_sales_director'] = $this->payload['approved_reason'] ?? null;
                    // Admin acting as Sales Director

                    // if (!$this->send_email_notif('contracts_for_approval_by_finance_manager', $quotation->id)) {
                    //     return false;
                    // }
                    if (!$this->send_email_notif('contracts_for_approval_by_finance_director', $quotation->id)) {
                        return false;
                    }
                // } elseif ($quotation->approved_by_finance_manager == null) {
                //     $values['approved_by_finance_manager'] = $this->payload['requester'];
                //     $values['approved_on_finance_manager'] = date('Y-m-d H:i:s');
                //     $values['approved_reason_finance_manager'] = $this->payload['approved_reason'] ?? null;
                //     $values['status'] = 'approved';
                //     // Admin acting as Finance Manager
                //
                //     if (!$this->send_email_notif('contracts_for_moa', $quotation->id)) {
                //         return false;
                //     }
                } elseif ($quotation->approved_by_finance_director == null) {
                    $values['approved_by_finance_director'] = $this->payload['requester'];
                    $values['approved_on_finance_director'] = date('Y-m-d H:i:s');
                    $values['approved_reason_finance_director'] = $this->payload['approved_reason'] ?? null;
                    $values['status'] = 'approved';
                    // Admin acting as Finance Director

                    if (!$this->send_email_notif('contracts_for_moa', $quotation->id)) {
                        return false;
                    }
                }
            }
        } elseif ($status == 'disapproved') {
            $values['status'] = 'pending';
            $values['approved_by_sales'] = null;
            $values['approved_on_sales'] = null;
            $values['approved_by_sales_manager'] = null;
            $values['approved_on_sales_manager'] = null;
            $values['approved_by_sales_director'] = null;
            $values['approved_on_sales_director'] = null;
            // $values['approved_by_finance_manager'] = null;
            // $values['approved_on_finance_manager'] = null;
            $values['approved_by_finance_director'] = null;
            $values['approved_on_finance_director'] = null;
            $values['disapproved_reason'] = $this->payload['disapproved_reason'];
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'returned') {
            $values['status'] = 'returned';
            $values['approved_by_sales'] = null;
            $values['approved_on_sales'] = null;
            $values['approved_by_sales_manager'] = null;
            $values['approved_on_sales_manager'] = null;
            $values['approved_by_sales_director'] = null;
            $values['approved_on_sales_director'] = null;
            // $values['approved_by_finance_manager'] = null;
            // $values['approved_on_finance_manager'] = null;
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

        // change the accredition status of company to active if quotation is approved
        if (isset($values['status']) && $values['status'] == 'approved') {
            $company = $this->company->select('id, name, accreditation_status', ['id' => $quotation->company_id], 1);
            if ($company) {
                $company_values = [
                    'accreditation_status' => 'active',
                    'account_status' => 'active',
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->company->update(['id' => $company->id], $company_values)) {
                    $this->error_message = $this->company->error();
                    return false;
                }
            }
        }

        // change the quotation services status to pending if the quotation is disapproved
        if (isset($values['status']) && $values['status'] == 'disapproved') {
            $quotation_services = $this->quotation_service->select('id', ['quotation_id' => $quotation->id, 'is_deleted' => 0]);
            if ($quotation_services) {
                $quotation_service_values = [
                    'status' => 'pending',
                    'disapproved_by' => null,
                    'disapproved_on' => null,
                    'disapproved_reason' => null,
                    'approved_by' => null,
                    'approved_on' => null,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->quotation_service->update(['quotation_id' => $quotation->id], $quotation_service_values)) {
                    $this->error_message = $this->quotation_service->error();
                    return false;
                }
            }
        }

        $where = ['id' => $quotation->id];

        if (!$this->quotation->update($where, $values)) {
            $this->error_message = $this->quotation->error();
            return false;
        }

        if (
            in_array($status, ['disapproved', 'returned'], true)
            && !$this->_cascade_status_to_costing($quotation, $status)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Cascade quotation disapprove/return to linked costing (silent; no costing email).
     */
    protected function _cascade_status_to_costing($quotation, $status)
    {
        if (empty($quotation->costing_id)) {
            return true;
        }

        $now = date('Y-m-d H:i:s');
        $values = [
            'status' => $status,
            'approved_by_sales_manager' => null,
            'approved_on_sales_manager' => null,
            'approved_by_sales_director' => null,
            'approved_on_sales_director' => null,
            'updated_by' => $this->payload['requester'],
            'updated_on' => $now,
        ];

        if ($status === 'disapproved') {
            $values['disapproved_reason'] = $this->payload['disapproved_reason'];
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = $now;
        } else {
            $values['returned_reason'] = $this->payload['returned_reason'];
            $values['returned_by'] = $this->payload['requester'];
            $values['returned_on'] = $now;
        }

        if (!$this->costing->update(['id' => $quotation->costing_id, 'is_deleted' => 0], $values)) {
            $this->error_message = $this->costing->error();
            return false;
        }

        return true;
    }

    /**
     * Validate services status
     */
    protected function _validate_services_status($services)
    {
        foreach ($services as $service) {
            if (!array_key_exists('id', $service) || !array_key_exists('status', $service)) {
                return false; // Missing id or status
            }
            if (!in_array($service['status'], ['approved', 'disapproved', 'returned'])) {
                return false; // Invalid status value
            }
        }
        return true;
    }

    /**
     * Attempt update services status
     */
    protected function _attempt_update_services_status($quotation_id)
    {
        foreach ($this->payload['services'] as $service) {

            $values = [
                'status'        => $service['status'],
                'updated_by'    => $this->payload['requester'],
                'updated_on'    => date('Y-m-d H:i:s')
            ];

            if ($service['status'] == 'approved') {
                $values['approved_by'] = $this->payload['requester'];
                $values['approved_on'] = date('Y-m-d H:i:s');
            } elseif ($service['status'] == 'disapproved') {
                $values['disapproved_by'] = $this->payload['requester'];
                $values['disapproved_on'] = date('Y-m-d H:i:s');
                $values['disapproved_reason'] = array_key_exists('disapproved_reason', $service) ? $service['disapproved_reason'] : null;
            } elseif ($service['status'] == 'returned') {
                $values['returned_by'] = $this->payload['requester'];
                $values['returned_on'] = date('Y-m-d H:i:s');
                $values['returned_reason'] = array_key_exists('returned_reason', $service) ? $service['returned_reason'] : null;
            }

            if (!$this->quotation_service->update(['id' => $service['id'], 'quotation_id' => $quotation_id], $values)) {
                $this->error_message = $this->quotation_service->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Delete quotations
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $quotation_id = $this->payload['quotation_id'];

        $where = ['id' => $quotation_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$quotation = $this->quotation->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'quotation is not found.',
            ];
        } elseif (!$this->_attempt_delete($quotation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'quotation is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'quotation is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($quotation_id)
    {
        $where = ['id' => $quotation_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->quotation->update($where, $values)) {
            $this->error_message = $this->quotation->error();
            return false;
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_attachments($quotation_id, $path)
    {
        $files = $this->request->getFileMultiple('attachments');
        $file_path = $path . $quotation_id;

        $quotation_attachments = $this->quotation_attachment->select('', ['quotation_id' => $quotation_id, 'is_deleted' => 0]) ?: [];

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

                    if (empty($quotation_attachments)) {
                        $file->move($path, $file_name);
                        $data = [
                            'quotation_id' => $quotation_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->quotation_attachment->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($quotation_attachments as $attachment) {
                            $attachment = (array)$attachment;
                            $this->quotation_attachment->update(['id' => $attachment['id']], [
                                'is_deleted' => 1,
                                'updated_by' => $this->payload['requester'],
                                'updated_on' => date('Y-m-d H:i:s')
                            ]);
                            // Delete the existing file from the server
                            if (is_file($attachment['file_path'] . '/' . $attachment['file_name'])) {
                                unlink($attachment['file_path'] . '/' . $attachment['file_name']);
                            }
                        }

                        // then upload the new file
                        $file->move($path, $file_name);
                        $data = [
                            'quotation_id' => $quotation_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->quotation_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1
            if (!empty($quotation_attachments)) {
                foreach ($quotation_attachments as $attachment) {
                    $attachment = (array)$attachment;
                    $this->quotation_attachment->update(['id' => $attachment['id']], [
                        'is_deleted' => 1,
                        'updated_by' => $this->payload['requester'],
                        'updated_on' => date('Y-m-d H:i:s')
                    ]);
                    // Delete the existing file from the server
                    if (is_file($attachment['file_path'] . '/' . $attachment['file_name'])) {
                        unlink($attachment['file_path'] . '/' . $attachment['file_name']);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_signed_moas($quotation_id, $path)
    {
        $files = $this->request->getFileMultiple('signed_moas');
        $file_path = $path . $quotation_id;

        $quotation_signed_moas = $this->quotation_signed_moa->select('', ['quotation_id' => $quotation_id, 'is_deleted' => 0]) ?: [];

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

                    if (empty($quotation_signed_moas)) {
                        $file->move($path, $file_name);
                        $data = [
                            'quotation_id' => $quotation_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->quotation_signed_moa->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($quotation_signed_moas as $signed_moa) {
                            $signed_moa = (array)$signed_moa;
                            $this->quotation_signed_moa->update(['id' => $signed_moa['id']], [
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
                            'quotation_id' => $quotation_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->quotation_signed_moa->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1
            if (!empty($quotation_signed_moas)) {
                foreach ($quotation_signed_moas as $signed_moa) {
                    $signed_moa = (array)$signed_moa;
                    $this->quotation_signed_moa->update(['id' => $signed_moa['id']], [
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
    protected function send_email_notif($subject, $quotation_id, $status = null)
    {
        $requesterId = $this->payload['requester'];
        $fallbackEmails = resolve_user_fallback_emails($this->user, $requesterId);
        $site_url = ACCOUNTING_FRONT_END_URL . "/requests";
        $primaryEmails = [];

        $quotation = $this->quotation->select('added_by, request_id, costing_id, company_id, package_name', ['id' => $quotation_id, 'is_deleted' => 0], 1);
        $company_id = $quotation ? $quotation->company_id : null;
        $company = $this->company->select('name', ['id' => $company_id, 'is_deleted' => 0], 1);
        $company_name = $company ? $company->name : null;
        // $quotation_packages = $this->quotation_service->get_packages($quotation_id) ?: null;
        $quotation_packages = $quotation ? $quotation->package_name : null;
        $company_name_with_packages = $quotation_packages ? "$company_name - $quotation_packages" : $company_name;
        $idLabel = "Quotation #$quotation_id";

        if ($subject == 'contracts_for_approval_by_sales_manager') {
            if ($quotation && $quotation->added_by) {
                $headEmail = resolve_immediate_head_email($this->user, $quotation->added_by);
                if ($headEmail) {
                    $primaryEmails[] = $headEmail;
                }
            }

            $subjectLine = "Contract for Approval by Sales Manager: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A contract (Quotation ID: $quotation_id) is awaiting your approval as Sales Manager. Please review the contract details and approve or disapprove the contract accordingly.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'contracts_for_approval_by_sales_director') {
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

            $subjectLine = "Contract for Approval by Sales Director: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A contract (Quotation ID: $quotation_id) is awaiting your approval as Sales Director. Please review the contract details and approve or disapprove the contract accordingly.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
//         } elseif ($subject == 'contracts_for_approval_by_finance_manager') {
//             $primaryEmails = resolve_emails_by_role($this->user, [ROLE_FINANCE_MANAGER]);

//             $subjectLine = "Contract for Approval by Finance Manager: $company_name_with_packages (Quotation #$quotation_id)";
//             $message = "<!DOCTYPE html>
// <html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
// <body>
// <p>A contract (Quotation ID: $quotation_id) is awaiting your approval as Finance Manager. Please review the contract details and approve or disapprove the contract accordingly.</p>
// <p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
// <p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'contracts_for_approval_by_finance_director') {
            $primaryEmails = resolve_emails_by_role($this->user, [ROLE_FINANCE_DIRECTOR]);

            $subjectLine = "Contract for Approval by Finance Director: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A contract (Quotation ID: $quotation_id) is awaiting your approval as Finance Director. Please review the contract details and approve or disapprove the contract accordingly.</p>
<p><a href='$site_url' target='_blank' rel='noopener noreferrer'>Open Requests Screen</a></p>
<p>If the button does not work, copy and paste this URL into your browser:<br>$site_url</p>";
        } elseif ($subject == 'contracts_for_moa') {
            if ($quotation && $quotation->request_id) {
                $addedByEmail = resolve_request_added_by_email($this->request_model, $this->user, $quotation->request_id);
                if ($addedByEmail) {
                    $primaryEmails[] = $addedByEmail;
                }
            }

            $subjectLine = "Contract for MOA: $company_name_with_packages ($idLabel)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>A contract (Quotation ID: $quotation_id) has been approved and is awaiting MOA signing. Please review the contract details and upload the signed MOA accordingly.</p>";
        } elseif ($subject == 'request_moved_to_quotation') {
            $primaryEmails = [];
            $headEmail = resolve_immediate_head_email($this->user, $quotation->request_id);
            if ($headEmail) {
                $primaryEmails[] = $headEmail;
            }

            $subjectLine = "Your Request Has Been Moved to Quotation: $company_name_with_packages (Quotation #$quotation_id)";
            $message = "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body>
<p>This is to inform you that your request for <strong>$company_name_with_packages</strong> has been moved to the <strong>Quotation</strong> stage (Quotation #$quotation_id).</p>
<p>Please wait for further updates regarding your request.</p>";
        } elseif ($subject == 'quotation_status_update') {
            // Per-service updates may not carry an overall status: derive it from the services.
            if (!$status && !empty($this->payload['services']) && is_array($this->payload['services'])) {
                $serviceStatuses = array_column($this->payload['services'], 'status');
                if (in_array('disapproved', $serviceStatuses, true)) {
                    $status = 'disapproved';
                } elseif (in_array('approved', $serviceStatuses, true) && !in_array('returned', $serviceStatuses, true)) {
                    $status = 'approved';
                }
            }

            $isApproved = $status === 'approved';
            $isDisapproved = $status === 'disapproved';

            // Only the request creator is notified, and only on approve / disapprove.
            if (!$isApproved && !$isDisapproved) {
                return true;
            }

            $primaryEmails = [];
            $fallbackEmails = [];
            $creatorEmail = ($quotation && $quotation->request_id)
                ? resolve_request_added_by_email($this->request_model, $this->user, $quotation->request_id)
                : null;
            if ($creatorEmail) {
                $primaryEmails[] = $creatorEmail;
            } else {
                log_message('error', 'Quotation status email skipped: no email found for request creator (quotation #' . $quotation_id . ')');
                return true;
            }

            $actor = $this->user->select('role_id', ['id' => $requesterId, 'is_deleted' => 0], 1);
            $actorLabel = status_actor_role_label($actor ? $actor->role_id : null);

            $statusLabel = $isApproved ? 'Approved' : 'Disapproved';
            $statusMessage = $isApproved
                ? "Your request's quotation <strong>$company_name_with_packages</strong> (Quotation #$quotation_id) has been reviewed and <strong>approved</strong> by the $actorLabel."
                : "We regret to inform you that your request's quotation <strong>$company_name_with_packages</strong> (Quotation #$quotation_id) has been reviewed and <strong>disapproved</strong> by the $actorLabel. Please reach out for further details.";

            $reasonHtml = '';
            if ($isApproved) {
                $reasonHtml = build_status_reason_html('Approval message', $this->payload['approved_reason'] ?? null);
            } elseif ($isDisapproved) {
                $disapprovedReason = $this->payload['disapproved_reason'] ?? null;
                if (!$disapprovedReason && !empty($this->payload['services']) && is_array($this->payload['services'])) {
                    // Per-service disapproval: gather each service's reason
                    $serviceReasons = [];
                    foreach ($this->payload['services'] as $service) {
                        if (($service['status'] ?? null) === 'disapproved' && !empty($service['disapproved_reason'])) {
                            $serviceReasons[] = trim((string) $service['disapproved_reason']);
                        }
                    }
                    $disapprovedReason = implode("\n", array_unique($serviceReasons));
                }
                $reasonHtml = build_status_reason_html('Reason for disapproval', $disapprovedReason);
            }

            $subjectLine = "Your Quotation Has Been $statusLabel: $company_name_with_packages (Quotation #$quotation_id)";
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
        $id ? ($where['id <>'] = $id) : null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }
}