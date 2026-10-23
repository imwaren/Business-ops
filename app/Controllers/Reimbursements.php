<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\User;
use App\Models\Supplier;

class Reimbursements extends MYT_Controller
{
    use \App\Core\Fund_routing;
    protected $request;
    protected $reimbursement;
    protected $reimbursement_item;
    protected $reimbursement_attachment;
    protected $replenishment;
    protected $replenishment_item;
    protected $replenishment_attachment;
    protected $branch_master;
    protected $user;
    protected $supplier;
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
        $this->branch_master = new Branch_master();
        $this->user = new User();
        $this->supplier = new Supplier();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $routing = $this->fund_router();
        $group = $routing->group($branch_id);
        if ($group === null) { return false; }
        $db = db_connect($group);

        $this->reimbursement = new \App\Models\Reimbursement($db);
        $this->reimbursement_item = new \App\Models\Reimbursement_item($db);
        $this->reimbursement_attachment = new \App\Models\Reimbursement_attachment($db);
        $this->replenishment = new \App\Models\Replenishment($db);
        $this->replenishment_item = new \App\Models\Replenishment_item($db);
        $this->replenishment_attachment = new \App\Models\Replenishment_attachment($db);

        if ($group === 'master') {
            $this->db = $db; // Transaction and scoped models must use the same connection.
            foreach (['reimbursement', 'reimbursement_item', 'reimbursement_attachment', 'replenishment', 'replenishment_item', 'replenishment_attachment'] as $property) {
                if (method_exists($this->$property, 'with_shared_cost_centers')) {
                    $this->$property->with_shared_cost_centers($branch_id);
                }
            }
        }
        return true;
    }

    /**
     * Get reimbursements
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $reimbursement_id = $this->payload['reimbursement_id'] ?? null;
        $department_id = $this->payload['department_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $reimbursements = $this->reimbursement->get_details($reimbursement_id, $department_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->reimbursement->get_details($reimbursement_id, $department_id, $status, $term, null, null)?:[]);

        if(!$reimbursements) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No reimbursements found.'
            ];
        } else {

            //append items to each reimbursement
            foreach ($reimbursements as $key => $reimbursement) {
                $liquidated_user = $this->user->select('id, first_name, last_name', ['id' => $reimbursement['liquidated_by_id'], 'is_deleted' => 0], 1) ?: null;
                $reimbursements[$key]['liquidated_by_name'] = $liquidated_user ? $liquidated_user->first_name . ' ' . $liquidated_user->last_name : null;
                $reimbursements_items = $this->reimbursement_item->get_details($reimbursement['id'])?:[];
                if ($reimbursements_items) {
                    foreach ($reimbursements_items as $rm_key => $reimbursements_item) {
                        $supplier = $this->supplier->select('id, name', ['id' => $reimbursements_item['supplier_id'], 'is_deleted' => 0], 1);
                        $reimbursements_items[$rm_key]['supplier_name'] = $supplier ? $supplier->name : null;
                    }
                }
                $reimbursements[$key]['items'] = $reimbursements_items;
                $reimbursements[$key]['attachments'] = $this->reimbursement_attachment->select('id, file_name, file_path, file_url, mime', ['reimbursement_id' => $reimbursement['id'], 'is_deleted' => 0])?:[];

                $cost_center = $this->branch_master->select('id, name, code', ['id' => $reimbursement['cost_center_id'], 'is_deleted' => 0], 1);
                $reimbursements[$key]['cost_center_name'] = $cost_center ? $cost_center->name : null;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $reimbursements
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get reimbursements and replenishments
     */
    public function get_all()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $reimbursement_id = $this->payload['reimbursement_id'] ?? null;
        $department_id = $this->payload['department_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $combines = $this->reimbursement->get_all_details($reimbursement_id, $department_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->reimbursement->get_all_details($reimbursement_id, $department_id, $status, $term, null, null)?:[]);

        if(!$combines) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No reimbursements/replenishments found.'
            ];
        } else {

            //append items to each reimbursement
            foreach ($combines as $key => $combine) {
                $liquidated_user = $this->user->select('id, first_name, last_name', ['id' => $combine['liquidated_by_id'], 'is_deleted' => 0], 1) ?: null;
                $combines[$key]['liquidated_by_name'] = $liquidated_user ? $liquidated_user->first_name . ' ' . $liquidated_user->last_name : null;

                $cost_center = $this->branch_master->select('name', ['id' => $combine['cost_center_id'], 'is_deleted' => 0], 1) ?: null;
                $combines[$key]['cost_center_name'] = $cost_center ? $cost_center->name : null;

                if ($combine['form_type'] == 'replenishment') {
                    $combines_items = $this->replenishment_item->get_details($combine['id'])?:[];
                    if ($combines_items) {
                        foreach ($combines_items as $rp_key => $combines_item) {
                            if ($combines_item['supplier_id'] != null) {
                                $supplier = $this->supplier->select('id, name', ['id' => $combines_item['supplier_id'], 'is_deleted' => 0], 1);
                                $combines_items[$rp_key]['supplier_name'] = $supplier ? $supplier->name : null;
                            } else {
                                $combines_items[$rp_key]['supplier_name'] = null;
                            }
                        }
                    }
                    $combines[$key]['items'] = $combines_items;
                    $combines[$key]['attachments'] = $this->replenishment_attachment->select('id, file_name, file_path, file_url, mime', ['replenishment_id' => $combine['id'], 'is_deleted' => 0])?:[];
                } elseif ($combine['form_type'] == 'reimbursement') {
                    $combines_items = $this->reimbursement_item->get_details($combine['id'])?:[];
                    if ($combines_items) {
                        foreach ($combines_items as $rm_key => $combines_item) {
                            if ($combines_item['supplier_id'] != null) {
                                $supplier = $this->supplier->select('id, name', ['id' => $combines_item['supplier_id'], 'is_deleted' => 0], 1);
                                $combines_items[$rm_key]['supplier_name'] = $supplier ? $supplier->name : null;
                            } else {
                                $combines_items[$rm_key]['supplier_name'] = null;
                            }
                        }
                    }
                    $combines[$key]['items'] = $combines_items;
                    $combines[$key]['attachments'] = $this->reimbursement_attachment->select('id, file_name, file_path, file_url, mime', ['reimbursement_id' => $combine['id'], 'is_deleted' => 0])?:[];
                }
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $combines
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create reimbursement
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $this->db->transBegin();

        $user = $this->user->select('id, role_id, immediate_head', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        $this->payload['status'] = $user->immediate_head == null ? 'approved' : 'pending';

        if (!$reimbursement_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add reimbursement. ",
            ];
        } elseif (!$this->_attempt_create_items($reimbursement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add reimbursement items. ",
            ];
        // Switch true and false to make the attachment a mandatory field
        } elseif(($this->request->getFileMultiple('attachments')?true:false) && !$this->_upload_attachments($reimbursement_id, 'assets/reimbursements/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } elseif (!$reimbursement = $this->reimbursement->select('id', ['id' => $reimbursement_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Reimbursement is not found.',
            ];
        } elseif ($user->immediate_head == null
                && !$this->_attempt_update_status($reimbursement, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update reimbursement status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'reimbursement_id' => $reimbursement_id,
                'message'    => $user->immediate_head == null ? 'reimbursement added and approved successfully.' : 'reimbursement added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a reimbursement
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->reimbursement->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$reimbursement_id = $this->reimbursement->insert($values)) {
            $this->error_message = $this->reimbursement->error();
            return false;
        }

        return $reimbursement_id;
    }

    /**
     * Attempt to create reimbursement items
     */
    private function _attempt_create_items($reimbursement_id)
    {
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return true;
        }

        foreach ($this->payload['items'] as $item) {
            $values = [
                'reimbursement_id'    => $reimbursement_id,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'supplier_id'   => array_key_exists('supplier_id', $item) && $item['supplier_id'] != '' ? $item['supplier_id'] : null,
                'invoice_no' => array_key_exists('invoice_no', $item) && $item['invoice_no'] != '' ? $item['invoice_no'] : null,
                'tin'   => array_key_exists('tin', $item) && $item['tin'] != '' ? $item['tin'] : null,
                'address'   => array_key_exists('address', $item) && $item['address'] != '' ? $item['address'] : null,
                'description'  => array_key_exists('description', $item) && $item['description'] != '' ? $item['description'] : null,
                'expense_date'  => array_key_exists('expense_date', $item) && $item['expense_date'] != '' ? $item['expense_date'] : null,
                'amount' => array_key_exists('amount', $item) && $item['amount'] != '' ? $item['amount'] : 0,
                'vat'  => array_key_exists('vat', $item) && $item['vat'] != '' ? $item['vat'] : null,
                'total_value' => array_key_exists('total_value', $item) && $item['total_value'] != '' ? $item['total_value'] : 0,
                'total' => array_key_exists('total', $item) && $item['total'] != '' ? $item['total'] : 0,
                'remarks' => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
                'is_vat' => array_key_exists('is_vat', $item) && $item['is_vat'] != '' ? $item['is_vat'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->reimbursement_item->insert($values)) {
                $this->error_message = $this->reimbursement_item->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update reimbursement
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required. Get it from the get API response.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $reimbursement_id = $this->payload['reimbursement_id'];
        $where = [
                    'id' => $reimbursement_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$reimbursement = $this->reimbursement->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'reimbursement is not found.'
            ];
        } elseif (!$this->_attempt_update($reimbursement)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update reimbursement.',
            ];
        } elseif (!$this->_attempt_update_items($reimbursement->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update reimbursement items. ",
            ];
        // Attachments is deleted if no file is uploaded
        } elseif(!$this->_upload_attachments($reimbursement_id, 'assets/reimbursements/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'reimbursement_id' => $reimbursement_id,
                'message'    => 'reimbursement updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($reimbursement)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->reimbursement->allowedFields is declared at the top of the model
        foreach($this->reimbursement->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $reimbursement->id];

        if (!$this->reimbursement->update($where, $values)) {
            $this->error_message = $this->reimbursement->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update reimbursement items
     */
    private function _attempt_update_items($reimbursement_id)
    {
        // 1. Get all current item IDs for this reimbursement
        $existing_items = $this->reimbursement_item->select('id', ['reimbursement_id' => $reimbursement_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_items, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('items', $this->payload) && is_array($this->payload['items'])) {
            foreach ($this->payload['items'] as $item) {
                if (!empty($item['id'])) {
                    $payload_ids[] = (int)$item['id'];
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
                if (!$this->reimbursement_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->reimbursement_item->error();
                    return false;
                }
            }
        }

        // 4. Process payload items (update existing or insert new)
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items'])) {
            return true;
        }

        foreach ($this->payload['items'] as $item) {
            $values = [
                'reimbursement_id'  => $reimbursement_id,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'supplier_id'   => array_key_exists('supplier_id', $item) && $item['supplier_id'] != '' ? $item['supplier_id'] : null,
                'invoice_no' => array_key_exists('invoice_no', $item) && $item['invoice_no'] != '' ? $item['invoice_no'] : null,
                'tin'   => array_key_exists('tin', $item) && $item['tin'] != '' ? $item['tin'] : null,
                'address'   => array_key_exists('address', $item) && $item['address'] != '' ? $item['address'] : null,
                'description'  => array_key_exists('description', $item) && $item['description'] != '' ? $item['description'] : null,
                'expense_date'  => array_key_exists('expense_date', $item) && $item['expense_date'] != '' ? $item['expense_date'] : null,
                'amount' => array_key_exists('amount', $item) && $item['amount'] != '' ? $item['amount'] : 0,
                'vat'  => array_key_exists('vat', $item) && $item['vat'] != '' ? $item['vat'] : null,
                'total_value' => array_key_exists('total_value', $item) && $item['total_value'] != '' ? $item['total_value'] : 0,
                'total' => array_key_exists('total', $item) && $item['total'] != '' ? $item['total'] : 0,
                'remarks' => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
                'is_vat' => array_key_exists('is_vat', $item) && $item['is_vat'] != '' ? $item['is_vat'] : 0,
            ];

            if (!empty($item['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->reimbursement_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->reimbursement_item->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->reimbursement_item->insert($values)) {
                    $this->error_message = $this->reimbursement_item->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status reimbursement
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required. Get it from the get API response.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $status = $this->payload['status'] ?? null;
        $reimbursement_id = $this->payload['reimbursement_id'];
        $where = [
                    'id' => $reimbursement_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$reimbursement = $this->reimbursement->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'reimbursement is not found.'
            ];
        } elseif ($status && $reimbursement->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Reimbursement is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($reimbursement, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change reimbursement status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'reimbursement_id' => $reimbursement_id,
                'message'    => "reimbursement $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($reimbursement, $role_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'approved') {
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
            $values['status'] = 'approved';
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_reason'] = $this->payload['disapproved_reason'];
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $reimbursement->id];

        if (!$this->reimbursement->update($where, $values)) {
            $this->error_message = $this->reimbursement->error();
            return false;
        }
        return true;
    }

    /**
     * Delete reimbursements
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required. Get it from the get API response.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $response_status = SUCCESS;
        $reimbursement_id = $this->payload['reimbursement_id'];

        $where = ['id' => $reimbursement_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$reimbursement = $this->reimbursement->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'reimbursement is not found.',
            ];
        } elseif (!$this->_attempt_delete($reimbursement_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'reimbursement is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'reimbursement is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($reimbursement_id)
    {
        $where = ['id' => $reimbursement_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->reimbursement->update($where, $values)) {
            $this->error_message = $this->reimbursement->error();
            return false;
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_attachments($reimbursement_id, $path)
    {
        $files = $this->request->getFileMultiple('attachments');
        $file_path = $path.$reimbursement_id;

        $reimbursement_attachments = $this->reimbursement_attachment->select('', ['reimbursement_id' => $reimbursement_id, 'is_deleted' => 0]) ?: [];

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

                if ($file->isValid() && !in_array($file->getExtension(), ['pdf', 'png', 'jpeg', 'jpg'])) {
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

                    if(empty($reimbursement_attachments)) {
                        $file->move($path, $file_name);
                        $data = [
                            'reimbursement_id' => $reimbursement_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->reimbursement_attachment->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($reimbursement_attachments as $attachment) {
                            $attachment = (array)$attachment;
                            $this->reimbursement_attachment->update(['id' => $attachment['id']], [
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
                            'reimbursement_id' => $reimbursement_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->reimbursement_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1
            if (!empty($reimbursement_attachments)) {
                foreach ($reimbursement_attachments as $attachment) {
                    $attachment = (array)$attachment;
                    $this->reimbursement_attachment->update(['id' => $attachment['id']], [
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
    * Check duplicate
    */
    protected function _has_duplicate($table, $where, $id = null)
    {
        $id?($where['id <>'] = $id):null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }
}
