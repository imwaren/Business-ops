<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\User;
use App\Models\Supplier;

class Replenishments extends MYT_Controller
{
    use \App\Core\Fund_routing;
    protected $request;
    protected $reimbursement;
    protected $reimbursement_item;
    protected $reimbursement_attachment;
    protected $replenishment;
    protected $replenishment_item;
    protected $replenishment_attachment;
    protected $petty_cash;
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
        $this->petty_cash = new \App\Models\Petty_cash($db);

        if ($group === 'master') {
            $this->db = $db; // Transaction and scoped models must use the same connection.
            foreach (['reimbursement', 'reimbursement_item', 'reimbursement_attachment', 'replenishment', 'replenishment_item', 'replenishment_attachment', 'petty_cash'] as $property) {
                if (method_exists($this->$property, 'with_shared_cost_centers')) {
                    $this->$property->with_shared_cost_centers($branch_id);
                }
            }
        }
        return true;
    }

    /**
     * Get replenishments
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

        $replenishment_id = $this->payload['replenishment_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $replenishments = $this->replenishment->get_details($replenishment_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->replenishment->get_details($replenishment_id, $status, $term, null, null)?:[]);

        if(!$replenishments) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No replenishments found.'
            ];
        } else {

            //append items to each replenishment
            foreach ($replenishments as $key => $replenishment) {
                $liquidated_user = $this->user->select('id, first_name, last_name', ['id' => $replenishment['liquidated_by_id'], 'is_deleted' => 0], 1) ?: null;
                $replenishments[$key]['liquidated_by_name'] = $liquidated_user ? $liquidated_user->first_name . ' ' . $liquidated_user->last_name : null;
                $replenishments[$key]['items'] = $this->replenishment_item->get_details($replenishment['id'])?:[];
                if (!empty($replenishments[$key]['items'])) {
                    foreach ($replenishments[$key]['items'] as $item_key => $item) {
                        if ($item['supplier_id']) {
                            $supplier = $this->supplier->select('id, name', ['id' => $item['supplier_id'], 'is_deleted' => 0], 1);
                            $replenishments[$key]['items'][$item_key]['supplier_name'] = $supplier ? $supplier->name : null;
                        } else {
                            $replenishments[$key]['items'][$item_key]['supplier_name'] = null;
                        }
                    }
                }
                $replenishments[$key]['attachments'] = $this->replenishment_attachment->select('id, file_name, file_path, file_url, mime', ['replenishment_id' => $replenishment['id'], 'is_deleted' => 0])?:[];

                $cost_center = $this->branch_master->select('id, name, code', ['id' => $replenishment['cost_center_id'], 'is_deleted' => 0], 1);
                $replenishments[$key]['cost_center_name'] = $cost_center ? $cost_center->name : null;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $replenishments
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create replenishment
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

        $liquidated_by_id = $this->payload['liquidated_by_id'] ?? null;
        if (!$liquidated_by_id) {
            return $this->_create_response(
                FAIL,
                ['Liquidated by ID is required.']
            );
        }

        $this->db->transBegin();

        $user = $this->user->select('id, role_id, immediate_head', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        $liquidated_by_user = $this->user->select('id, first_name, last_name', ['id' => $liquidated_by_id, 'is_deleted' => 0], 1);

        $this->payload['status'] = $user->immediate_head == null ? 'approved' : 'pending';

        if (!$petty_cash = $this->petty_cash->select('', ['requested_by_id' => $liquidated_by_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'No petty cash to replenish for ' . $liquidated_by_user->first_name . ' ' . $liquidated_by_user->last_name . '. ',
            ];
        } elseif (!$replenishment_id = $this->_attempt_create($petty_cash)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add replenishment. ",
            ];
        } elseif (!$this->_attempt_create_items($replenishment_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add replenishment items. ",
            ];
        // Switch true and false to make the attachment a mandatory field
        } elseif(($this->request->getFileMultiple('attachments')?true:false) && !$this->_upload_attachments($replenishment_id, 'assets/replenishments/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } elseif (!$replenishment = $this->replenishment->select('id', ['id' => $replenishment_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Replenishment is not found.',
            ];
        } elseif ($user->immediate_head == null
                && !$this->_attempt_update_status($replenishment, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update replenishment status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'replenishment_id' => $replenishment_id,
                'message'    => 'replenishment added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a replenishment
     */
    private function _attempt_create($petty_cash)
    {
        $values = [
            'petty_cash_id'         => $petty_cash->id,
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->replenishment->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$replenishment_id = $this->replenishment->insert($values)) {
            $this->error_message = $this->replenishment->error();
            return false;
        }

        return $replenishment_id;
    }

    /**
     * Attempt to create replenishment items
     */
    private function _attempt_create_items($replenishment_id)
    {
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return true;
        }

        foreach ($this->payload['items'] as $item) {
            $values = [
                'replenishment_id'    => $replenishment_id,
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

            if (!$this->replenishment_item->insert($values)) {
                $this->error_message = $this->replenishment_item->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update replenishment
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

        $liquidated_by_id = $this->payload['liquidated_by_id'] ?? null;
        if (!$liquidated_by_id) {
            return $this->_create_response(
                FAIL,
                ['Liquidated by ID is required.']
            );
        }

        $replenishment_id = $this->payload['replenishment_id'];
        $where = [
                    'id' => $replenishment_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        $liquidated_by_user = $this->user->select('id, first_name, last_name', ['id' => $liquidated_by_id, 'is_deleted' => 0], 1);

        if (!$replenishment = $this->replenishment->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'replenishment is not found.'
            ];
        } elseif (!$petty_cash = $this->petty_cash->select('', ['requested_by_id' => $liquidated_by_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'No petty cash to replenish for ' . $liquidated_by_user->first_name . ' ' . $liquidated_by_user->last_name . '. ',
            ];
        } elseif (!$this->_attempt_update($replenishment, $petty_cash)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update replenishment.',
            ];
        } elseif (!$this->_attempt_update_items($replenishment->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update replenishment items. ",
            ];
        // Switch true and false to make the attachment a mandatory field
        } elseif(($this->request->getFileMultiple('attachments')?true:false) && !$this->_upload_attachments($replenishment_id, 'assets/replenishments/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'replenishment_id' => $replenishment_id,
                'message'    => 'replenishment updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($replenishment, $petty_cash)
    {
        $values = [
            'petty_cash_id'         => $petty_cash->id,
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->replenishment->allowedFields is declared at the top of the model
        foreach($this->replenishment->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $replenishment->id];

        if (!$this->replenishment->update($where, $values)) {
            $this->error_message = $this->replenishment->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update replenishment items
     */
    private function _attempt_update_items($replenishment_id)
    {
        // 1. Get all current item IDs for this replenishment
        $existing_items = $this->replenishment_item->select('id', ['replenishment_id' => $replenishment_id, 'is_deleted' => 0]);

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
                if (!$this->replenishment_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->replenishment_item->error();
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
                'replenishment_id'  => $replenishment_id,
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

                if (!$this->replenishment_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->replenishment_item->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->replenishment_item->insert($values)) {
                    $this->error_message = $this->replenishment_item->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status replenishment
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
        $replenishment_id = $this->payload['replenishment_id'];
        $where = [
                    'id' => $replenishment_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$replenishment = $this->replenishment->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'replenishment is not found.'
            ];
        } elseif (!$petty_cash = $this->petty_cash->select('', ['id' => $replenishment->petty_cash_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'No petty cash to replenish. ',
            ];
        } elseif ($petty_cash->balance < $replenishment->grand_total) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Petty cash balance is not enough to replenish. Balance: ' . $petty_cash->balance . ' Total: ' . $replenishment->grand_total . '. ',
            ];
        } elseif ($status && $replenishment->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Replenishment is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($replenishment, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change replenishment status.',
            ];
        } elseif (!$this->_attempt_update_petty_cash($petty_cash, $replenishment)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update petty cash. ',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'replenishment_id' => $replenishment_id,
                'message'    => "replenishment $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($replenishment, $role_id)
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

        $where = ['id' => $replenishment->id];

        if (!$this->replenishment->update($where, $values)) {
            $this->error_message = $this->replenishment->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt update petty cash
     */
    protected function _attempt_update_petty_cash($petty_cash, $replenishment)
    {
        $values = [
            'balance' => $petty_cash->balance - $replenishment->grand_total,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->petty_cash->update(['id' => $petty_cash->id], $values)) {
            $this->error_message = $this->petty_cash->error();
            return false;
        }
        return true;
    }

    /**
     * Delete replenishments
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
        $replenishment_id = $this->payload['replenishment_id'];

        $where = ['id' => $replenishment_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$replenishment = $this->replenishment->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'replenishment is not found.',
            ];
        } elseif (!$this->_attempt_delete($replenishment_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'replenishment is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'replenishment is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($replenishment_id)
    {
        $where = ['id' => $replenishment_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->replenishment->update($where, $values)) {
            $this->error_message = $this->replenishment->error();
            return false;
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_attachments($replenishment_id, $path)
    {
        $files = $this->request->getFileMultiple('attachments');
        $file_path = $path.$replenishment_id;

        $replenishment_attachments = $this->replenishment_attachment->select('', ['replenishment_id' => $replenishment_id, 'is_deleted' => 0]) ?:[];

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

                    if(empty($replenishment_attachments)) {
                        $file->move($path, $file_name);
                        $data = [
                            'replenishment_id' => $replenishment_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->replenishment_attachment->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($replenishment_attachments as $attachment) {
                            $attachment = (array)$attachment;
                            $this->replenishment_attachment->update(['id' => $attachment['id']], [
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
                            'replenishment_id' => $replenishment_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->replenishment_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1
            if (!empty($replenishment_attachments)) {
                foreach ($replenishment_attachments as $attachment) {
                    $attachment = (array)$attachment;
                    $this->replenishment_attachment->update(['id' => $attachment['id']], [
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
