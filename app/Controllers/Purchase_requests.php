<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\User;
use App\Models\Item;
use App\Models\Item_conversion;
use App\Models\Supplier;

class Purchase_requests extends MYT_Controller
{
    use \App\Core\Procurement_routing;
    use \App\Controllers\Traits\Approval_notifications;

    protected $request;
    protected $aoq;
    protected $aoq_detail;
    protected $purchase_request;
    protected $purchase_request_item;
    protected $purchase_request_attachment;
    protected $purchase_order;
    protected $purchase_order_item;
    protected $branch_item;
    private $itemValidationError;

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
        $this->purchase_request_attachment = new \App\Models\Purchase_request_attachment($db);
        $this->purchase_order = new \App\Models\Purchase_order($db);
        $this->purchase_order_item = new \App\Models\Purchase_order_item($db);
        $this->branch_item = new \App\Models\Branch_item($db);

        $this->scope_procurement_models($branch_id, ['aoq', 'aoq_detail', 'purchase_request', 'purchase_request_item', 'purchase_request_attachment', 'purchase_order', 'purchase_order_item', 'branch_item']);
        return true;
    }

    /**
     * Get purchase_requests
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['cost_center_id'] ?? null;
        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Cost Center ID (branch_id) is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $purchase_request_id = $this->payload['purchase_request_id'] ?? null;
        $status    = $this->payload['status']    ?? null;
        $for_po    = $this->payload['for_po']    ?? null;
        $is_po     = $this->payload['is_po']     ?? null;
        $term      = $this->payload['term']      ?? null;
        $page      = $this->payload['page']      ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $purchase_requests = $this->purchase_request->get_details($purchase_request_id, $status, $term, $page, $data_size, $for_po, $is_po) ?: [];
        $size = $this->purchase_request->get_count($purchase_request_id, $status, $term, $for_po, $is_po);

        if (!$purchase_requests) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No purchase_requests found.'
            ];
        } else {
            foreach ($purchase_requests as $key => $purchase_request) {
                $is_canvassing = 0;
                $purchase_requests_items = $this->purchase_request_item->get_details($purchase_request['id']) ?: [];

                if ($purchase_requests_items) {
                    foreach ($purchase_requests_items as $i => $item) {
                        $item_detail = $this->item->select('id, name, kind, is_canvassing, is_batch', ['id' => $item['item_id'], 'is_deleted' => 0], 1);
                        if ($item_detail && $item_detail->is_canvassing == 1) {
                            $is_canvassing = 1;
                        }
                        $cost_center = null;
                        if (isset($item['cost_center_id']) && !empty($item['cost_center_id'])) {
                            $cost_center = $this->branch_master->select('name', ['id' => $item['cost_center_id'], 'is_deleted' => 0], 1) ?: null;
                        }
                        $delivery_branch = null;
                        if (isset($item['delivery_branch_id']) && !empty($item['delivery_branch_id'])) {
                            $delivery_branch = $this->branch_master->select('name', ['id' => $item['delivery_branch_id'], 'is_deleted' => 0], 1) ?: null;
                        }

                        $purchase_requests_item_conversion  = $this->item_conversion->select('id, conversion_type, qty, unit, min, max', ['id' => $item['unit'], 'item_id' => $item['item_id'], 'is_deleted' => 0], 1) ?: [];
                        $purchase_requests_items[$i]['min'] = $purchase_requests_item_conversion ? $purchase_requests_item_conversion->min : null;
                        $purchase_requests_items[$i]['max'] = $purchase_requests_item_conversion ? $purchase_requests_item_conversion->max : null;

                        $item_conversion  = $this->item_conversion->select('id, conversion_type, qty, unit', ['item_id' => $item['item_id'], 'is_deleted' => 0]) ?: [];
                        $inventory_branch=(int)$purchase_request['cost_center_id'];
                        $inventory_model=$this->branch_item;
                        if ($this->fund_router()->procurement_group((int)$branch_id)==='master') {
                            $inventory_branch=(int)($purchase_request['delivery_branch_id'] ?? 0);
                            $inventory_group=$this->fund_router()->procurement_group($inventory_branch);
                            $inventory_model=$inventory_group && $inventory_group!=='master'
                                ? new \App\Models\Branch_item(db_connect($inventory_group)) : null;
                        }
                        $branch_item_detail=$inventory_model ? $inventory_model->select('id, branch_id, item_id, beggining_qty, current_qty, unit', ['item_id'=>$item['item_id'],'branch_id'=>$inventory_branch,'unit'=>$item['unit'],'is_deleted'=>0],1) : null;

                        $purchase_requests_items[$i]['conversions']          = $item_conversion;
                        $purchase_requests_items[$i]['item_name']            = $item_detail ? $item_detail->name : null;
                        $purchase_requests_items[$i]['kind']                 = $item_detail ? $item_detail->kind : null;
                        $purchase_requests_items[$i]['is_batch']             = $item_detail ? $item_detail->is_batch : null;
                        $purchase_requests_items[$i]['current_qty']          = $branch_item_detail ? $branch_item_detail->current_qty : null;
                        $purchase_requests_items[$i]['is_canvassing']        = $item_detail ? $item_detail->is_canvassing : null;
                        $purchase_requests_items[$i]['cost_center_name']     = $cost_center ? $cost_center->name : null;
                        $purchase_requests_items[$i]['delivery_branch_name'] = $delivery_branch ? $delivery_branch->name : null;
                    }
                }

                $supplier      = $this->supplier->select('name', ['id' => $purchase_request['supplier_id'], 'is_deleted' => 0], 1) ?: null;
                $branch        = $this->branch_master->select('name', ['id' => $purchase_request['cost_center_id'], 'is_deleted' => 0], 1) ?: null;
                $approved_user = $this->user->select('id, first_name, last_name', ['id' => $purchase_request['approved_by'], 'is_deleted' => 0], 1) ?: null;

                $purchase_requests[$key]['approved_by_name'] = $approved_user ? $approved_user->first_name . ' ' . $approved_user->last_name : null;
                $purchase_requests[$key]['supplier_name']    = $supplier ? $supplier->name : null;
                $purchase_requests[$key]['branch_name']      = $branch ? $branch->name : null;
                $purchase_requests[$key]['is_canvassing']    = $is_canvassing;
                $purchase_requests[$key]['items']            = $purchase_requests_items;

                $purchase_requests[$key]['attachments']           = $this->purchase_request_attachment->select('id, file_name, file_url, mime, type', ['purchase_request_id' => $purchase_request['id'], 'type' => 'pr', 'is_deleted' => 0]) ?: [];
                $purchase_requests[$key]['quotation_attachments'] = $this->purchase_request_attachment->select('id, file_name, file_url, mime, type', ['purchase_request_id' => $purchase_request['id'], 'type' => 'quotation', 'is_deleted' => 0]) ?: [];
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => array_values($purchase_requests)
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Create purchase_request
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['cost_center_id'] ?? null;
        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Cost Center ID (branch_id) is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $this->beginApprovalTransaction('purchase_request', $purchase_request_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        // if (!in_array($user->role_id, [4,24,23,26,27])) {
        //     $response_status = FAIL;
        //     $response = [
        //         "Only requestor, Finance Manager, Finance Director, General Accountant or Admin users can create purchase_request. ",
        //     ];
        // } else
        if (!$needed_date) {
            $response_status = FAIL;
            $response = [
                "Needed Date is required. ",
            ];
        } elseif (!$this->_validate_items()) {
            $response_status = FAIL;
            $response = [
                $this->itemValidationError,
            ];
        } elseif (!$purchase_request_id = $this->_attempt_create()) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add purchase_request. ",
            ];
        } elseif (!$this->_attempt_create_items($purchase_request_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add purchase_request items. ",
            ];
        } elseif (!$this->_attempt_create_attachments($purchase_request_id, 'pr')) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add purchase_request attachments. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_request', (int) $purchase_request_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_request_id' => $purchase_request_id,
                'message'    => 'purchase_request added successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a purchase_request
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->purchase_request->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$purchase_request_id = $this->purchase_request->insert($values)) {
            $this->error_message = $this->purchase_request->error();
            return false;
        }

        return $purchase_request_id;
    }

    /**
     * Attempt to create purchase_request items
     */
    private function _attempt_create_items($purchase_request_id)
    {
        if (method_exists($this->purchase_request_item, 'with_shared_parent')) { $this->purchase_request_item->with_shared_parent((int)$purchase_request_id); }
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return true;
        }

        foreach ($this->payload['items'] as $item) {
            $values = [
                'purchase_request_id'    => $purchase_request_id,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'qty'   => array_key_exists('qty', $item) && $item['qty'] != '' ? $item['qty'] : null,
                'unit'   => array_key_exists('unit', $item) && $item['unit'] != '' ? $item['unit'] : null,
                'discount'   => array_key_exists('discount', $item) && $item['discount'] != '' ? $item['discount'] : null,
                'amount'   => array_key_exists('amount', $item) && $item['amount'] != '' ? $item['amount'] : null,
                'price'   => array_key_exists('price', $item) && $item['price'] != '' ? $item['price'] : null,
                'total'   => array_key_exists('total', $item) && $item['total'] != '' ? $item['total'] : null,
                'vat'   => array_key_exists('vat', $item) && $item['vat'] != '' ? $item['vat'] : null,
                'vat_type'   => array_key_exists('vat_type', $item) && $item['vat_type'] != '' ? $item['vat_type'] : null,
                'total_value'   => array_key_exists('total_value', $item) && $item['total_value'] != '' ? $item['total_value'] : null,
                'remarks'   => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->purchase_request_item->insert($values)) {
                $this->error_message = $this->purchase_request_item->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create purchase_request attachments
     */
    private function _attempt_create_attachments($purchase_request_id, $type)
    {
        $attachments = $this->request->getFileMultiple('attachments');

        if (empty($attachments)) {
            return true;
        }

        foreach ($attachments as $key => $attachment) {
            $file = $attachment;
            $file_path = 'assets/purchase_requests/' . $purchase_request_id . '/';

            $uploaded_url = null;

            if ($file && $file->isValid() && ! $file->hasMoved()) {
                $extension = $file->getExtension();
                $file_name = $file->getName();
                $mime_type = $file->getMimeType();

                $upload_result = upload_to_vm4($file, $file_path);
                if ($upload_result && ! empty($upload_result['success'])) {
                    $uploaded_url = VM4_ENDPOINT . $upload_result['path'];
                } else {
                    $this->_error = 'upload failed: ' . ($upload_result['error'] ?? 'unknown');
                    return false;
                }

                $values = [
                    'purchase_request_id' => $purchase_request_id,
                    'file_name' => $file_name,
                    'file_path' => $upload_result['path'],
                    'file_url' => $uploaded_url,
                    'mime' => $mime_type,
                    'type' => $type,
                    'added_by' => $this->payload['requester'],
                    'added_on' => date('Y-m-d H:i:s')
                ];

                if (!$this->purchase_request_attachment->insert($values)) {
                    $this->error_message = $this->purchase_request_attachment->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update purchase_request
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['cost_center_id'] ?? null;
        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Cost Center ID (branch_id) is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $purchase_request_id = $this->payload['purchase_request_id'];
        $where = [
                    'id' => $purchase_request_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->beginApprovalTransaction('purchase_request', $purchase_request_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$purchase_request = $this->purchase_request->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_request is not found.'
            ];
        } elseif (!$needed_date) {
            $response_status = FAIL;
            $response = [
                "Needed Date is required. ",
            ];
        } elseif (!$this->_validate_items()) {
            $response_status = FAIL;
            $response = [
                $this->itemValidationError,
            ];
        // } elseif (!in_array($user->role_id, [4,24,23,26,27])) {
        //     $response_status = FAIL;
        //     $response = [
        //         "Only requestor, Finance Manager, Finance Director, General Accountant or Admin users can create purchase_request. ",
        //     ];
        } elseif (!$this->_attempt_update($purchase_request)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to update purchase_request.',
            ];
        } elseif (!$this->_attempt_update_items($purchase_request->id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to update purchase_request items. ",
            ];
        } elseif (!$this->_attempt_update_attachments($purchase_request->id, 'pr')) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to update purchase_request attachments. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_request', (int) $purchase_request_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_request_id' => $purchase_request_id,
                'message'    => 'purchase_request updated successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($purchase_request)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->purchase_request->allowedFields is declared at the top of the model
        foreach($this->purchase_request->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $purchase_request->id];

        if (!$this->purchase_request->update($where, $values)) {
            $this->error_message = $this->purchase_request->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update purchase_request items
     */
    private function _attempt_update_items($purchase_request_id)
    {
        if (method_exists($this->purchase_request_item, 'with_shared_parent')) { $this->purchase_request_item->with_shared_parent((int)$purchase_request_id); }
        // 1. Get all current item IDs for this purchase_request
        $existing_items = $this->purchase_request_item->select('id', ['purchase_request_id' => $purchase_request_id, 'is_deleted' => 0]);

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
                if (!$this->purchase_request_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->purchase_request_item->error();
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
                'purchase_request_id'  => $purchase_request_id,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'qty'   => array_key_exists('qty', $item) && $item['qty'] != '' ? $item['qty'] : null,
                'unit'   => array_key_exists('unit', $item) && $item['unit'] != '' ? $item['unit'] : null,
                'discount'   => array_key_exists('discount', $item) && $item['discount'] != '' ? $item['discount'] : null,
                'amount'   => array_key_exists('amount', $item) && $item['amount'] != '' ? $item['amount'] : null,
                'price'   => array_key_exists('price', $item) && $item['price'] != '' ? $item['price'] : null,
                'total'   => array_key_exists('total', $item) && $item['total'] != '' ? $item['total'] : null,
                'vat'   => array_key_exists('vat', $item) && $item['vat'] != '' ? $item['vat'] : null,
                'vat_type'   => array_key_exists('vat_type', $item) && $item['vat_type'] != '' ? $item['vat_type'] : null,
                'total_value'   => array_key_exists('total_value', $item) && $item['total_value'] != '' ? $item['total_value'] : null,
                'remarks'   => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
            ];

            if (!empty($item['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->purchase_request_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->purchase_request_item->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->purchase_request_item->insert($values)) {
                    $this->error_message = $this->purchase_request_item->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update purchase_request attachments
     */
    private function _attempt_update_attachments($purchase_request_id, $type)
    {
        $attachments = $this->request->getFileMultiple('attachments');

        if (empty($attachments)) {
            return true;
        }

        $existing_attachments = $this->purchase_request_attachment->select('id', ['purchase_request_id' => $purchase_request_id, 'type' => $type, 'is_deleted' => 0]) ?: [];
        if ($existing_attachments) {
            //soft delete the existing attachments
            if (!$this->purchase_request_attachment->update(['purchase_request_id' => $purchase_request_id, 'type' => $type, 'is_deleted' => 1], ['updated_by' => $this->payload['requester'], 'updated_on' => date('Y-m-d H:i:s')])) {
                $this->error_message = $this->purchase_request_attachment->error();
                return false;
            }
        }

        //then call the _attempt_create_attachments function
        if (!$this->_attempt_create_attachments($purchase_request_id, $type)) {
            $this->error_message = $this->purchase_request_attachment->error();
            return false;
        }

        return true;
    }

    /**
     * Validate items (canvassing and non-canvassing must not be mixed)
     */
    private function _validate_items(): bool
    {
        $this->itemValidationError = null;
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return true;
        }

        $canvassing_count = 0;
        $non_canvassing_count = 0;

        foreach ($this->payload['items'] as $item) {
            $item_details = $this->item->select('id, is_canvassing', ['id' => $item['item_id'], 'is_deleted' => 0], 1);
            if ($item_details) {
                if ($item_details->is_canvassing == 1) {
                    $canvassing_count++;
                } else {
                    $non_canvassing_count++;
                }
            }
        }

        // canvassing and non-canvassing must not be mixed, so it should be either/or
        if ($canvassing_count > 0 && $non_canvassing_count > 0) {
            $this->itemValidationError = 'Canvassing and non-canvassing must not be mixed.';
            return false;
        }

        //if it is non-canvassing, supplier_id is required
        // if ($non_canvassing_count > 0 && empty($this->payload['supplier_id'])) {
        //     $this->itemValidationError = 'Supplier is required for non-canvassing items.';
        //     return false;
        // }

        return true;
    }

    /**
     * Update purchase_request quotation attachments
     */
    public function upload_quotation()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['cost_center_id'] ?? null;
        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Cost Center ID (branch_id) is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $purchase_request_id = $this->payload['purchase_request_id'];
        $where = [
                    'id' => $purchase_request_id, 
                    'is_deleted' => 0
                ];

        $this->beginApprovalTransaction('purchase_request', $purchase_request_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$purchase_request = $this->purchase_request->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_request is not found.'
            ];
        } elseif (!$this->_attempt_update_attachments($purchase_request->id, 'quotation')) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to upload quotation attachments. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_request', (int) $purchase_request_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_request_id' => $purchase_request_id,
                'message'    => 'quotation uploaded successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Change status purchase_request
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['cost_center_id'] ?? null;
        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Cost Center ID (branch_id) is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $status = $this->payload['status'] ?? null;
        $purchase_request_id = $this->payload['purchase_request_id'];
        $where = [
                    'id' => $purchase_request_id, 
                    'is_deleted' => 0
                ];

        $this->beginApprovalTransaction('purchase_request', $purchase_request_id ?? null);

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$purchase_request = $this->purchase_request->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_request is not found.'
            ];
        // } elseif (!in_array($user->role_id, [4,25,23,26,27])) {
        //     $response_status = FAIL;
        //     $response = [
        //         "Only Department Manager, Finance Manager, Finance Director, General Accountant or Admin users can change purchase_request status. ",
        //     ];
        //validate status
        } elseif ($status && $purchase_request->status == $status) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Purchase_request is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($purchase_request, $user->role_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to change purchase_request status.',
            ];
        // } elseif ($status == 'approved' && !$this->_attempt_generate_purchase_order($purchase_request)) {
        //     $this->rollbackApprovalTransaction();
        //     $response_status = FAIL;
        //     $response = [
        //         'Failed to generate purchase order.',
        //     ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_request', (int) $purchase_request_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_request_id' => $purchase_request_id,
                'message'    => "purchase_request $status successfully."
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($purchase_request, $role_id)
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
            $values['disapproved_reason'] = $this->payload['disapproved_reason'] ?? null;
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $purchase_request->id];

        if (!$this->purchase_request->update($where, $values)) {
            $this->error_message = $this->purchase_request->error();
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

        if ($is_canvassing == 0) {
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
     * Delete purchase_requests
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['cost_center_id'] ?? null;
        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Cost Center ID (branch_id) is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $response_status = SUCCESS;
        $purchase_request_id = $this->payload['purchase_request_id'];

        $where = ['id' => $purchase_request_id, 'is_deleted' => 0];

        $this->beginApprovalTransaction('purchase_request', $purchase_request_id ?? null);

        if (!$purchase_request = $this->purchase_request->select('', $where, 1)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_request is not found.',
            ];
        } elseif (!$this->_attempt_delete($purchase_request_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'purchase_request is not deleted.'
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_request', (int) $purchase_request_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'message' => 'purchase_request is deleted.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($purchase_request_id)
    {
        $where = ['id' => $purchase_request_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->purchase_request->update($where, $values)) {
            $this->error_message = $this->purchase_request->error();
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
