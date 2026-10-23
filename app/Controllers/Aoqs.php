<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\User;
use App\Models\Item;
use App\Models\Item_conversion;
use App\Models\Supplier;

class Aoqs extends MYT_Controller
{
    use \App\Core\Procurement_routing;
    use \App\Controllers\Traits\Approval_notifications;

    protected $request;
    protected $aoq;
    protected $aoq_detail;
    protected $purchase_request;
    protected $purchase_request_item;
    protected $purchase_order;
    protected $purchase_order_item;
    protected $branch_item;

    protected $branch_master;
    protected $user;
    protected $item;
    protected $item_conversion;
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
        $this->item = new Item();
        $this->item_conversion = new Item_conversion();
        $this->supplier = new Supplier();

        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $group = $this->fund_router()->procurement_group($branch_id);
        if ($group === null) { return false; }
        $db = db_connect($group);
        $this->db = $db;
        $this->approvalBranchId = $branch_id;

        $this->aoq = new \App\Models\Aoq($db);
        $this->aoq_detail = new \App\Models\Aoq_detail($db);
        $this->purchase_request = new \App\Models\Purchase_request($db);
        $this->purchase_request_item = new \App\Models\Purchase_request_item($db);
        $this->purchase_order = new \App\Models\Purchase_order($db);
        $this->purchase_order_item = new \App\Models\Purchase_order_item($db);
        $this->branch_item = new \App\Models\Branch_item($db);

        $this->scope_procurement_models($branch_id, ['aoq', 'aoq_detail', 'purchase_request', 'purchase_request_item', 'purchase_order', 'purchase_order_item', 'branch_item']);
        return true;
    }

    /**
     * Get aoqs
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $aoq_id = $this->payload['aoq_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

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

        $aoqs = $this->aoq->get_details($aoq_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->aoq->get_details($aoq_id, $status, $term, null, null)?:[]);

        if(!$aoqs) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No aoqs found.'
            ];
        } else {

            //append details to each aoq
            foreach ($aoqs as $key => $aoq) {
                $supplier = $this->supplier->select('name', ['id' => $aoq['supplier_id'], 'is_deleted' => 0], 1) ?: null;
                $recommended_bidder = $this->supplier->select('name', ['id' => $aoq['recommended_bidder_id'], 'is_deleted' => 0], 1) ?: null;
                $branch = $this->branch_master->select('name', ['id' => $aoq['branch_id'], 'is_deleted' => 0], 1) ?: null;
                $cost_center = $this->branch_master->select('name', ['id' => $aoq['cost_center_id'], 'is_deleted' => 0], 1) ?: null;
                $details = $this->aoq_detail->get_details($aoq['id']) ?: [];
                if  ($details) {
                    foreach ($details as $detail_key => $detail) {
                        $supplier = $this->supplier->select('name', ['id' => $detail['supplier_id'], 'is_deleted' => 0], 1) ?: null;
                        $details[$detail_key]['supplier_name'] = $supplier ? $supplier->name : null;
                    }
                }
                $recommended_user = $this->user->select('id, first_name, last_name, middle_name', ['id' => $aoq['recommended_by_id'], 'is_deleted' => 0], 1) ?: null;

                $aoqs[$key]['supplier_name'] = $supplier ? $supplier->name : null;
                $aoqs[$key]['recommended_bidder_name'] = $recommended_bidder ? $recommended_bidder->name : null;
                $aoqs[$key]['branch_name'] = $branch ? $branch->name : null;
                $aoqs[$key]['cost_center_name'] = $cost_center ? $cost_center->name : null;
                $aoqs[$key]['recommended_by_name'] = $recommended_user ? trim($recommended_user->first_name . ' ' .  $recommended_user->last_name) : null;
                $aoqs[$key]['details'] = $details;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $aoqs
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Create aoq
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

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

        $this->beginApprovalTransaction('aoq', $aoq_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $user = $this->user->select('id, first_name, last_name, middle_name, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$aoq_id = $this->_attempt_create()) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add aoq. ",
            ];
        } elseif (!$needed_date) {
            $response_status = FAIL;
            $response = [
                "Needed Date is required. ",
            ];
        } elseif (!$this->_attempt_create_details($aoq_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add aoq details. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('aoq', (int) $aoq_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'aoq_id' => $aoq_id,
                'message'    => 'aoq added successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a aoq
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->aoq->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$aoq_id = $this->aoq->insert($values)) {
            $this->error_message = $this->aoq->error();
            return false;
        }

        return $aoq_id;
    }

    /**
     * Attempt to create aoq details
     */
    private function _attempt_create_details($aoq_id)
    {
        if (method_exists($this->aoq_detail, 'with_shared_parent')) { $this->aoq_detail->with_shared_parent((int)$aoq_id); }
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'aoq_id'    => $aoq_id,
                'supplier_id'   => array_key_exists('supplier_id', $detail) && $detail['supplier_id'] != '' ? $detail['supplier_id'] : null,
                'name'   => array_key_exists('name', $detail) && $detail['name'] != '' ? $detail['name'] : null,
                'cost'   => array_key_exists('cost', $detail) && $detail['cost'] != '' ? $detail['cost'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->aoq_detail->insert($values)) {
                $this->error_message = $this->aoq_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update aoq
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

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

        $aoq_id = $this->payload['aoq_id'];
        $where = [
                    'id' => $aoq_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->beginApprovalTransaction('aoq', $aoq_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $user = $this->user->select('id, first_name, last_name, middle_name, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$aoq = $this->aoq->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'aoq is not found.'
            ];
        } elseif (!$needed_date) {
            $response_status = FAIL;
            $response = [
                "Needed Date is required. ",
            ];
        } elseif (!$this->_attempt_update($aoq)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to update aoq.',
            ];
        } elseif (!$this->_attempt_update_details($aoq->id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to update aoq details. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('aoq', (int) $aoq_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'aoq_id' => $aoq_id,
                'message'    => 'aoq updated successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($aoq)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->aoq->allowedFields is declared at the top of the model
        foreach($this->aoq->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $aoq->id];

        if (!$this->aoq->update($where, $values)) {
            $this->error_message = $this->aoq->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update aoq details
     */
    private function _attempt_update_details($aoq_id)
    {
        if (method_exists($this->aoq_detail, 'with_shared_parent')) { $this->aoq_detail->with_shared_parent((int)$aoq_id); }
        // 1. Get all current detail IDs for this aoq
        $existing_details = $this->aoq_detail->select('id', ['aoq_id' => $aoq_id, 'is_deleted' => 0]);

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
                if (!$this->aoq_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->aoq_detail->error();
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
                'aoq_id'  => $aoq_id,
                'supplier_id'   => array_key_exists('supplier_id', $detail) && $detail['supplier_id'] != '' ? $detail['supplier_id'] : null,
                'name'   => array_key_exists('name', $detail) && $detail['name'] != '' ? $detail['name'] : null,
                'cost'   => array_key_exists('cost', $detail) && $detail['cost'] != '' ? $detail['cost'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->aoq_detail->update(['id' => $detail['id']], $values)) {
                    $this->error_message = $this->aoq_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->aoq_detail->insert($values)) {
                    $this->error_message = $this->aoq_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status aoq
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

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

        $status = $this->payload['status'] ?? null;
        $aoq_id = $this->payload['aoq_id'];
        $where = [
                    'id' => $aoq_id, 
                    'is_deleted' => 0
                ];

        $this->beginApprovalTransaction('aoq', $aoq_id ?? null);

        $user = $this->user->select('id, first_name, last_name, middle_name, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$aoq = $this->aoq->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'aoq is not found.'
            ];
        } elseif (!$purchase_request = $this->purchase_request->select('', ['id' => $aoq->purchase_request_id, 'is_deleted' => 0], 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_request is not found.'
            ];
        } elseif ($status && $aoq->status == $status) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Aoq is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($aoq, $user->role_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to change aoq status.',
            ];
        // } elseif ($status == 'approved' && !$this->_attempt_generate_purchase_order($purchase_request)) {
        //     $this->rollbackApprovalTransaction();
        //     $response_status = FAIL;
        //     $response = [
        //         'Failed to generate purchase order.',
        //     ];
        } else {
            if (!$this->commitApprovalTransaction('aoq', (int) $aoq_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'aoq_id' => $aoq_id,
                'message'    => "aoq $status successfully."
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($aoq, $role_id)
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

        $where = ['id' => $aoq->id];

        if (!$this->aoq->update($where, $values)) {
            $this->error_message = $this->aoq->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt generate PO
     */
    protected function _attempt_generate_purchase_order($purchase_request)
    {
        $purchase_request = (array) $purchase_request;
        $is_canvassing = 0;
        $purchase_requests_items = $this->purchase_request_item->get_details($purchase_request['id']) ?: [];

        if ($purchase_requests_items) {
            //determine if is_canvassing and for_purhase_order
            foreach($purchase_requests_items AS $item) {
                if($item['is_canvassing'] == 1) {
                    $is_canvassing = 1;
                    $for_purhase_order = 0;
                    break;
                }
            }
        }

        if ($is_canvassing == 1) {
            $values = [
                'purchase_request_id'   => $purchase_request['id'],
                'added_by'              => $this->payload['requester'],
                'added_on'              => date('Y-m-d H:i:s'),
            ];
    
            foreach($this->purchase_order->allowedFields AS $field) {
                if(!array_key_exists($field, $purchase_request)) {
                    continue;
                }
                $values[$field] = $purchase_request[$field]!=''?$purchase_request[$field]:null;
            }
    
            if (!$purchase_order_id = $this->purchase_order->insert($values)) {
                $this->error_message = $this->purchase_order->error();
                return false;
            } elseif (!$this->number_generated_shared_po((int)$purchase_order_id,(int)$purchase_request['branch_id'])) {
                return false;
            } elseif (!$this->_attempt_generate_purchase_order_items($purchase_order_id, $purchase_requests_items)) {
                $this->error_message = $this->purchase_order->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to generate PO items
     */
    private function _attempt_generate_purchase_order_items($purchase_order_id, $purchase_requests_items)
    {
        if (!$purchase_requests_items) {
            return true;
        }

        foreach ($purchase_requests_items as $item) {
            $values = [
                'purchase_order_id'    => $purchase_order_id,
                'purchase_request_id'    => array_key_exists('purchase_request_id', $item) && $item['purchase_request_id'] != '' ? $item['purchase_request_id'] : null,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'cost_center_id'   => array_key_exists('cost_center_id', $item) && $item['cost_center_id'] != '' ? $item['cost_center_id'] : null,
                'expenditure_type'   => array_key_exists('expenditure_type', $item) && $item['expenditure_type'] != '' ? $item['expenditure_type'] : null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'qty'   => array_key_exists('qty', $item) && $item['qty'] != '' ? $item['qty'] : null,
                'unit'   => array_key_exists('unit', $item) && $item['unit'] != '' ? $item['unit'] : null,
                'discount'   => array_key_exists('discount', $item) && $item['discount'] != '' ? $item['discount'] : null,
                'amount'   => array_key_exists('amount', $item) && $item['amount'] != '' ? $item['amount'] : null,
                'price'   => array_key_exists('price', $item) && $item['price'] != '' ? $item['price'] : null,
                'remarks'   => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->purchase_order_item->insert($values)) {
                $this->error_message = $this->purchase_order_item->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Delete aoqs
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

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

        $response_status = SUCCESS;
        $aoq_id = $this->payload['aoq_id'];

        $where = ['id' => $aoq_id, 'is_deleted' => 0];

        $this->beginApprovalTransaction('aoq', $aoq_id ?? null);

        if (!$aoq = $this->aoq->select('', $where, 1)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'aoq is not found.',
            ];
        } elseif (!$this->_attempt_delete($aoq_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'aoq is not deleted.'
            ];
        } else {
            if (!$this->commitApprovalTransaction('aoq', (int) $aoq_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'message' => 'aoq is deleted.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($aoq_id)
    {
        $where = ['id' => $aoq_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->aoq->update($where, $values)) {
            $this->error_message = $this->aoq->error();
            return false;
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
