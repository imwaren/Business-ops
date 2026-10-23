<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\User;
use App\Models\Role;
use App\Models\Item;
use App\Models\Item_conversion;
use App\Models\Supplier;

class Purchase_orders extends MYT_Controller
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
    protected $receipt_note;
    protected $receipt_note_item;
    protected $capital_expenditure;
    protected $branch_item;

    protected $branch_master;
    protected $user;
    protected $role;
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
        $this->role = new Role();
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
        $this->receipt_note = new \App\Models\Receipt_note($db);
        $this->receipt_note_item = new \App\Models\Receipt_note_item($db);
        $this->capital_expenditure = new \App\Models\Capital_expenditure($db);
        $this->branch_item = new \App\Models\Branch_item($db);

        $this->scope_procurement_models($branch_id, ['aoq', 'aoq_detail', 'purchase_request', 'purchase_request_item', 'purchase_order', 'purchase_order_item', 'receipt_note', 'receipt_note_item', 'capital_expenditure', 'branch_item']);
        return true;
    }

    /**
     * Get purchase_orders
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $purchase_order_id = $this->payload['purchase_order_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $for_po = $this->payload['for_po'] ?? null;
        $term = $this->payload['term'] ?? null;
        $screen = $this->payload['screen'] ?? null;
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

        $purchase_orders = $this->purchase_order->get_details($purchase_order_id, $supplier_id, $status, $term, $screen, $page, $data_size)?:[];
        $size = count($this->purchase_order->get_details($purchase_order_id, $supplier_id, $status, $term, $screen, null, null)?:[]);

        if(!$purchase_orders) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No purchase_orders found.'
            ];
        } else {

            //append items to each purchase_order
            foreach ($purchase_orders as $key => $purchase_order) {
                $supplier = $this->supplier->select('name, tin', ['id' => $purchase_order['supplier_id'], 'is_deleted' => 0], 1) ?: null;
                $branch = $this->branch_master->select('name', ['id' => $purchase_order['branch_id'], 'is_deleted' => 0], 1) ?: null;
                $delivery_branch = $this->branch_master->select('name', ['id' => $purchase_order['delivery_branch_id'], 'is_deleted' => 0], 1) ?: null;
                $user_adder = $this->user->select('id, first_name, last_name, role_id', ['id' => $purchase_order['added_by'], 'is_deleted' => 0], 1);
                $user_adder_role = $user_adder ? $this->role->select('id, name', ['id' => $user_adder->role_id, 'is_deleted' => 0], 1) : null;
                $user_approver_department_director = $this->user->select('id, first_name, last_name', ['id' => $purchase_order['approved_by_department_director'], 'is_deleted' => 0], 1);
                $user_approver_president = $this->user->select('id, first_name, last_name', ['id' => $purchase_order['approved_by_president'], 'is_deleted' => 0], 1);
                $capital_expenditure_data = $this->capital_expenditure->select('id, name', ['id' => $purchase_order['capital_expenditure_id'], 'is_deleted' => 0], 1);
                $user_buyer_assigned = $this->user->select('id, first_name, last_name', ['id' => $purchase_order['buyer_assigned'], 'is_deleted' => 0], 1);

                $purchase_order_items = $this->purchase_order_item->get_details($purchase_order['id'])?:[];
                if ($purchase_order_items) {
                    foreach ($purchase_order_items as $poi => $item) {
                        $item_detail = $this->item->select('name, kind, is_canvassing, is_batch', ['id' => $item['item_id']], 1) ?: null;
                        $cost_center = $this->branch_master->select('name', ['id' => $item['cost_center_id'], 'is_deleted' => 0], 1) ?: null;
                        $total_received_qty = 0;
                        $receipt_note_items = $this->receipt_note_item->select('qty, receipt_note_id', ['purchase_order_item_id' => $item['id'], 'purchase_order_id' => $purchase_order['id'], 'is_deleted' => 0]);
                        if ($receipt_note_items) {
                            foreach ($receipt_note_items as $rni) {
                                $receipt_note = $this->receipt_note->select('status', ['id' => $rni->receipt_note_id, 'is_deleted' => 0], 1) ?: null;
                                if ($receipt_note && $receipt_note->status == 'approved') {
                                    $total_received_qty += $rni->qty;
                                }
                            }
                        }
                        $item_conversion = $this->item_conversion->select('id, conversion_type, qty, unit', ['item_id' => $item['item_id'], 'is_deleted' => 0]) ?: [];

                        $purchase_order_items[$poi]['conversions'] = $item_conversion;
                        $purchase_order_items[$poi]['cost_center_name'] = $cost_center ? $cost_center->name : null;
                        $purchase_order_items[$poi]['item_name'] = $item_detail ? $item_detail->name : null;
                        $purchase_order_items[$poi]['item_kind'] = $item_detail ? $item_detail->kind : null;
                        $purchase_order_items[$poi]['is_batch'] = $item_detail ? $item_detail->is_batch : null;
                        $purchase_order_items[$poi]['is_canvassing'] = $item_detail ? $item_detail->is_canvassing : null;
                        $purchase_order_items[$poi]['received_qty'] = $total_received_qty;
                    }
                }

                $receipt_notes = $this->receipt_note->get_details(null, $purchase_order['id'])?:[];
                if ($receipt_notes) {
                    foreach ($receipt_notes as $rn => $receipt_note) {
                        $receipt_note_items = $this->receipt_note_item->get_details($receipt_note['id'])?:[];
                        if ($receipt_note_items) {
                            foreach ($receipt_note_items as $i => $item) {
                                $item_detail = $this->item->select('id, name, is_canvassing', ['id' => $item['item_id'], 'is_deleted' => 0], 1) ?: null;
                                $item_conversion = $this->item_conversion->select('id, conversion_type, qty, unit AS unit_name', ['item_id' => $item['item_id'], 'is_deleted' => 0]) ?: [];
                                $cost_center = $this->branch_master->select('name', ['id' => $item['cost_center_id'], 'is_deleted' => 0], 1) ?: null;
        
                                $receipt_note_items[$i]['cost_center_name'] = $cost_center ? $cost_center->name : null;
                                $receipt_note_items[$i]['item_name'] = $item_detail ? $item_detail->name : null;
                                $receipt_note_items[$i]['is_canvassing'] = $item_detail ? $item_detail->is_canvassing : null;
                                $receipt_note_items[$i]['conversions'] = $item_conversion;
                            }
                        }
                        $receipt_notes[$rn]['items'] = $receipt_note_items;
                    }
                }

                $purchase_orders[$key]['supplier_name'] = $supplier ? $supplier->name : null;
                $purchase_orders[$key]['supplier_tin'] = $supplier ? $supplier->tin : null;
                $purchase_orders[$key]['branch_name'] = $branch ? $branch->name : null;
                $purchase_orders[$key]['delivery_branch_name'] = $delivery_branch ? $delivery_branch->name : null;
                $purchase_orders[$key]['added_by_name'] = $user_adder ? $user_adder->first_name . ' ' . $user_adder->last_name : null;
                $purchase_orders[$key]['added_by_role'] = $user_adder_role ? $user_adder_role->name : null;
                $purchase_orders[$key]['approved_by_department_director_name'] = $user_approver_department_director ? $user_approver_department_director->first_name . ' ' . $user_approver_department_director->last_name : null;
                $purchase_orders[$key]['approved_by_president_name'] = $user_approver_president ? $user_approver_president->first_name . ' ' . $user_approver_president->last_name : null;
                $purchase_orders[$key]['capital_expenditure_name'] = $capital_expenditure_data ? $capital_expenditure_data->name : null;
                $purchase_orders[$key]['buyer_assigned_name'] = $user_buyer_assigned ? $user_buyer_assigned->first_name . ' ' . $user_buyer_assigned->last_name : null;
                $purchase_orders[$key]['items'] = $purchase_order_items;
                $purchase_orders[$key]['delivery_histories'] = $receipt_notes;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $purchase_orders
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Create purchase_order
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

        $this->beginApprovalTransaction('purchase_order', $purchase_order_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $branch = $this->branch_master->select('id, name, code', ['id' =>$branch_id, 'is_deleted' => 0], 1);

        if (!$needed_date) {
            $response_status = FAIL;
            $response = [
                "Needed Date is required. ",
            ];
        } elseif ($cost_error = $this->_items_require_cost($this->payload['items'] ?? [])) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                $cost_error,
            ];
        } elseif (!$purchase_order_id = $this->_attempt_create()) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add purchase_order. ",
            ];
        } elseif (!$this->_attempt_generate_po_number($purchase_order_id, $branch)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add purchase_order. ",
            ];
        } elseif (!$this->_attempt_create_items($purchase_order_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add purchase_order items. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_order', (int) $purchase_order_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_order_id' => $purchase_order_id,
                'message'    => 'purchase_order added successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Generate PO Number
     * Format: BRANCHCODE + YYYY + MM + DD + 6-digit series
     * Example: 20 2026 02 18 000001
     */
    protected function _attempt_generate_po_number($purchase_order_id, $branch)
    {
        $branch_code = $branch ? str_pad($branch->code, 2, '0', STR_PAD_LEFT) : 00;

        $year  = date('Y');
        $month = date('m');
        $day   = date('d');

        /**
         * Get last PO for current year only
         * This ensures series resets every year
         */
        $last_po = $this->purchase_order->get_last_po($branch_code, $year);

        if ($last_po) {
            $last_series = substr($last_po['po_number'], -6);
            $next_series = (int)$last_series + 1;
        } else {
            $next_series = 1;
        }

        if ($this->fund_router()->procurement_group((int)$this->payload['branch_id']) === 'master') {
            $next_series = \App\Models\Shared_procurement_number::next($this->db,$branch_code,(int)$year);
        }

        // Pad to 6 digits
        $last_transaction_series = str_pad($next_series, 6, '0', STR_PAD_LEFT);

        // Final PO number
        $po_number = $branch_code . $year . $month . $day . $last_transaction_series;

        $values = [
            'po_number'  => $po_number,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $purchase_order_id];

        if (!$this->purchase_order->update($where, $values)) {
            $this->error_message = $this->purchase_order->error();
            return false;
        }

        return true;
    }

    /**
     * Attempt to create a purchase_order
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->purchase_order->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$purchase_order_id = $this->purchase_order->insert($values)) {
            $this->error_message = $this->purchase_order->error();
            return false;
        }

        return $purchase_order_id;
    }

    /**
     * Attempt to create purchase_order items
     */
    private function _attempt_create_items($purchase_order_id)
    {
        if (method_exists($this->purchase_order_item, 'with_shared_parent')) { $this->purchase_order_item->with_shared_parent((int)$purchase_order_id); }
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return true;
        }

        foreach ($this->payload['items'] as $item) {
            $values = [
                'purchase_order_id'    => $purchase_order_id,
                'purchase_request_id'   => array_key_exists('purchase_request_id', $item) && $item['purchase_request_id'] != '' ? $item['purchase_request_id'] : null,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'cost_center_id'   => array_key_exists('cost_center_id', $item) && $item['cost_center_id'] != '' ? $item['cost_center_id'] : null,
                'expenditure_type'   => array_key_exists('expenditure_type', $item) && $item['expenditure_type'] != '' ? $item['expenditure_type'] : null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'qty'   => array_key_exists('qty', $item) && $item['qty'] != '' ? $item['qty'] : null,
                'unit'   => array_key_exists('unit', $item) && $item['unit'] != '' ? $item['unit'] : null,
                'cost'   => array_key_exists('cost', $item) && $item['cost'] != '' ? $item['cost'] : null,
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

            if (!$this->purchase_order_item->insert($values)) {
                $this->error_message = $this->purchase_order_item->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update purchase_order
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

        $purchase_order_id = $this->payload['purchase_order_id'];
        $where = [
                    'id' => $purchase_order_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->beginApprovalTransaction('purchase_order', $purchase_order_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$purchase_order = $this->purchase_order->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_order is not found.'
            ];
        }  elseif (!$needed_date) {
            $response_status = FAIL;
            $response = [
                "Needed Date is required. ",
            ];
        } elseif ($cost_error = $this->_items_require_cost($this->payload['items'] ?? [])) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                $cost_error,
            ];
        } elseif (!$this->_attempt_update($purchase_order)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to update purchase_order.',
            ];
        } elseif (!$this->_attempt_update_items($purchase_order->id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to update purchase_order items. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_order', (int) $purchase_order_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_order_id' => $purchase_order_id,
                'message'    => 'purchase_order updated successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($purchase_order)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->purchase_order->allowedFields is declared at the top of the model
        foreach($this->purchase_order->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $purchase_order->id];

        if (!$this->purchase_order->update($where, $values)) {
            $this->error_message = $this->purchase_order->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update purchase_order items
     */
    private function _attempt_update_items($purchase_order_id)
    {
        if (method_exists($this->purchase_order_item, 'with_shared_parent')) { $this->purchase_order_item->with_shared_parent((int)$purchase_order_id); }
        // 1. Get all current item IDs for this purchase_order
        $existing_items = $this->purchase_order_item->select('id', ['purchase_order_id' => $purchase_order_id, 'is_deleted' => 0]);

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
                if (!$this->purchase_order_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->purchase_order_item->error();
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
                'purchase_order_id'  => $purchase_order_id,
                'purchase_request_id'   => array_key_exists('purchase_request_id', $item) && $item['purchase_request_id'] != '' ? $item['purchase_request_id'] : null,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'cost_center_id'   => array_key_exists('cost_center_id', $item) && $item['cost_center_id'] != '' ? $item['cost_center_id'] : null,
                'expenditure_type'   => array_key_exists('expenditure_type', $item) && $item['expenditure_type'] != '' ? $item['expenditure_type'] : null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'qty'   => array_key_exists('qty', $item) && $item['qty'] != '' ? $item['qty'] : null,
                'unit'   => array_key_exists('unit', $item) && $item['unit'] != '' ? $item['unit'] : null,
                'cost'   => array_key_exists('cost', $item) && $item['cost'] != '' ? $item['cost'] : null,
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

                if (!$this->purchase_order_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->purchase_order_item->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->purchase_order_item->insert($values)) {
                    $this->error_message = $this->purchase_order_item->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status purchase_order
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
        $purchase_order_id = $this->payload['purchase_order_id'];
        $where = [
                    'id' => $purchase_order_id, 
                    'is_deleted' => 0
                ];

        $this->beginApprovalTransaction('purchase_order', $purchase_order_id ?? null);

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$purchase_order = $this->purchase_order->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_order is not found.'
            ];
        } elseif (!in_array($user->role_id, [4,28,29,23,26,27,30,38])) {
            $response_status = FAIL;
            $response = [
                "Only Department Director, President, Finance Manager, Finance Director, General Accountant, Area Manager, Inventory Officer or Admin users can change purchase_order status. ",
            ];
        //validate status
        } elseif ($status && $purchase_order->status == $status) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Purchase_order is already $status. ",
            ];
        } elseif ($status == 'approved' && $purchase_order->grand_total > PO_APPROVAL_AMOUNT_LIMIT && !in_array($user->role_id, [4,28,26]) && $purchase_order->approved_by_department_director == null) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Department Director/Finance Director must approve first before President/CEO can approve purchase_order greater than 50,000. ",
            ];
        } elseif (!$this->_attempt_update_status($purchase_order, $user->role_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to change purchase_order status.',
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_order', (int) $purchase_order_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_order_id' => $purchase_order_id,
                'message'    => ($status == 'approved' && $purchase_order->grand_total > PO_APPROVAL_AMOUNT_LIMIT && $purchase_order->approved_by_department_director == null) ? "purchase_order approved successfully and pending for President/CEO approval." : "purchase_order $status successfully."
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($purchase_order, $role_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'approved') {
            if ($purchase_order->grand_total > PO_APPROVAL_AMOUNT_LIMIT && in_array($role_id, [4,28,26]) && $purchase_order->approved_by_department_director == null) {
                $values['approved_by_department_director'] = $this->payload['requester'];
                $values['approved_on_department_director'] = date('Y-m-d H:i:s');
                // status remains pending until President/CEO approves
                $values['status'] = 'pending';
            } elseif ($purchase_order->grand_total > PO_APPROVAL_AMOUNT_LIMIT && in_array($role_id, [4,29]) && $purchase_order->approved_by_department_director != null) {
                $values['approved_by_president'] = $this->payload['requester'];
                $values['approved_on_president'] = date('Y-m-d H:i:s');
                $values['status'] = 'approved';
            } else {
                $values['approved_by_department_director'] = in_array($role_id, [4,28]) ? $this->payload['requester'] : null;
                $values['approved_on_department_director'] = in_array($role_id, [4,28]) ? date('Y-m-d H:i:s') : null;
                $values['approved_by_president'] = in_array($role_id, [4,29]) ? $this->payload['requester'] : null;
                $values['approved_on_president'] = in_array($role_id, [4,29]) ? date('Y-m-d H:i:s') : null;
                $values['approved_by'] = $this->payload['requester'];
                $values['approved_on'] = date('Y-m-d H:i:s');
                $values['status'] = 'approved';
            }
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_reason'] = $this->payload['disapproved_reason'] ?? null;
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $purchase_order->id];

        if (!$this->purchase_order->update($where, $values)) {
            $this->error_message = $this->purchase_order->error();
            return false;
        }
        return true;
    }

    /**
     * Update purchase_order
     */
    public function request_to_closed()
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

        $purchase_order_id = $this->payload['purchase_order_id'];
        $where = [
                    'id' => $purchase_order_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->beginApprovalTransaction('purchase_order', $purchase_order_id ?? null);

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$purchase_order = $this->purchase_order->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_order is not found.'
            ];
        } elseif ( $purchase_order->received_status != 'open') {
            $response_status = FAIL;
            $response = [
                "Only purchase_order with status 'open' can request to closed. ",
            ];
        } elseif ($purchase_order->is_closed_request == 1) {
            $response_status = FAIL;
            $response = [
                "Cannot request to closed if already requested. ",
            ];
        } elseif (!$this->_attempt_request_to_closed($purchase_order)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to update purchase_order.',
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_order', (int) $purchase_order_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_order_id' => $purchase_order_id,
                'message'    => 'purchase_order received_status request to close successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt request to closed
     */
    protected function _attempt_request_to_closed($purchase_order)
    {
        $values = [
            'is_closed_request'     => 1,
            'closed_request_status' => 'requested',
            'closed_request_by'     => $this->payload['requester'],
            'closed_request_on'     => date('Y-m-d H:i:s'),
            'closed_request_reason' => $this->payload['closed_request_reason'] ?? null,
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $purchase_order->id];

        if (!$this->purchase_order->update($where, $values)) {
            $this->error_message = $this->purchase_order->error();
            return false;
        }
        return true;
    }

    /**
     * Change status purchase_order
     */
    public function change_status_sent_multiple()
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

        $purchase_order_ids = $this->payload['purchase_order_ids'] ?? [];
        if (empty($purchase_order_ids) || !is_array($purchase_order_ids)) {
            return $this->_create_response(
                FAIL,
                ['Purchase order IDs are required.']
            );
        }

        $response_messages = [];

        foreach ($purchase_order_ids as $purchase_order_id) {
            $where = [
                'id' => $purchase_order_id,
                'is_deleted' => 0
            ];

            $values = [
                'is_sent' => 1,
                'sent_by' => $this->payload['requester'],
                'sent_on' => date('Y-m-d H:i:s'),
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s')
            ];

            $this->beginApprovalTransaction('purchase_order', $purchase_order_id ?? null);
            
            if (!$purchase_order = $this->purchase_order->select('', $where, 1)) {
                $response_messages[] = [
                    'purchase_order id ' . $purchase_order_id . ' is not found.'
                ];
            } elseif ($purchase_order->status != 'approved') {
                $response_messages[] = [
                    'purchase_order id ' . $purchase_order_id . ' is not approved.'
                ];
            } elseif ($purchase_order->is_sent == 1) {
                $response_messages[] = [
                    'purchase_order id ' . $purchase_order_id . ' is already sent.'
                ];
            } elseif (!$this->purchase_order->update(['id' => $purchase_order_id], $values)) {
                $this->rollbackApprovalTransaction();
                $response_messages[] = [
                    'purchase_order id ' . $purchase_order_id . ' failed to update status to sent.'
                ];
            } else {
                if (!$this->commitApprovalTransaction('purchase_order', (int) $purchase_order_id)) {
                    return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
                }
                $response_messages[] = [
                    'purchase_order id ' . $purchase_order_id . ' updated status to sent successfully.'
                ];
            }
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response(SUCCESS, $response_messages);
    }

    /**
     * Change received_status purchase_order
     */
    public function change_received_status()
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
        $purchase_order_id = $this->payload['purchase_order_id'];
        $where = [
                    'id' => $purchase_order_id, 
                    'is_deleted' => 0
                ];

        $this->beginApprovalTransaction('purchase_order', $purchase_order_id ?? null);

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$purchase_order = $this->purchase_order->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_order is not found.'
            ];
        } elseif ( $purchase_order->received_status != 'open') {
            $response_status = FAIL;
            $response = [
                "Only purchase_order with status 'open' can change received_status to closed. ",
            ];
        } elseif ($purchase_order->is_closed_request == 0) {
            $response_status = FAIL;
            $response = [
                "Cannot change received_status to closed if not requested as closed. ",
            ];
        //validate status
        } elseif ($purchase_order->received_status == 'closed') {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Purchase_order is already closed. ",
            ];
        } elseif (!$this->_attempt_update_received_status($purchase_order, $user->role_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to change purchase_order status.',
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_order', (int) $purchase_order_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'purchase_order_id' => $purchase_order_id,
                'message'    => "purchase_order received_status $status successfully."
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_received_status($purchase_order, $role_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'approved') {
            $values['received_status'] = 'closed';
            $values['closed_request_status'] = 'approved';
            $values['closed_request_approved_by'] = $this->payload['requester'];
            $values['closed_request_approved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'disapproved') {
            $values['is_closed_request'] = 0;
            $values['closed_request_status'] = 'disapproved';
            $values['closed_request_disapproved_reason'] = $this->payload['disapproved_reason'] ?? null;
            $values['closed_request_disapproved_by'] = $this->payload['requester'];
            $values['closed_request_disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $purchase_order->id];

        if (!$this->purchase_order->update($where, $values)) {
            $this->error_message = $this->purchase_order->error();
            return false;
        }
        return true;
    }

    /**
     * Delete purchase_orders
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
        $purchase_order_id = $this->payload['purchase_order_id'];

        $where = ['id' => $purchase_order_id, 'is_deleted' => 0];

        $this->beginApprovalTransaction('purchase_order', $purchase_order_id ?? null);

        if (!$purchase_order = $this->purchase_order->select('', $where, 1)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_order is not found.',
            ];
        } elseif (!$this->_attempt_delete($purchase_order_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'purchase_order is not deleted.'
            ];
        } else {
            if (!$this->commitApprovalTransaction('purchase_order', (int) $purchase_order_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'message' => 'purchase_order is deleted.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($purchase_order_id)
    {
        $where = ['id' => $purchase_order_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->purchase_order->update($where, $values)) {
            $this->error_message = $this->purchase_order->error();
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

    /**
     * Require cost on each PO line item.
     */
    protected function _items_require_cost($items): ?string
    {
        if (!is_array($items) || count($items) == 0) {
            return null;
        }

        foreach ($items as $item) {
            if (!array_key_exists('cost', $item) || $item['cost'] === '' || $item['cost'] === null) {
                return 'Cost is required for each item.';
            }
        }

        return null;
    }
}
