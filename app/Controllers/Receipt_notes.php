<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\User;
use App\Models\Role;
use App\Models\Item;
use App\Models\Item_conversion;
use App\Models\Supplier;
use App\Controllers\Traits\Weighted_cost;

class Receipt_notes extends MYT_Controller
{
    use \App\Core\Procurement_routing;
    use Weighted_cost;
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
    protected $receipt_note_additional_item;
    protected $receipt_note_attachment;
    protected $receipt_note_item_expiry_date;
    protected $capital_expenditure;
    protected $branch_item;
    protected $item_cost;

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
        $this->item_cost = new \App\Models\Item_cost();
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
        $this->receipt_note_additional_item = new \App\Models\Receipt_note_additional_item($db);
        $this->receipt_note_attachment = new \App\Models\Receipt_note_attachment($db);
        $this->receipt_note_item_expiry_date = new \App\Models\Receipt_note_item_expiry_date($db);
        $this->capital_expenditure = new \App\Models\Capital_expenditure($db);
        $this->branch_item = new \App\Models\Branch_item($db);
        $this->item = new \App\Models\Item($db);
        $this->item_conversion = new \App\Models\Item_conversion($db);
        $this->item_cost = new \App\Models\Item_cost($db);

        $this->scope_procurement_models($branch_id, ['aoq', 'aoq_detail', 'purchase_request', 'purchase_request_item', 'purchase_order', 'purchase_order_item', 'receipt_note', 'receipt_note_item', 'receipt_note_additional_item', 'receipt_note_attachment', 'receipt_note_item_expiry_date', 'capital_expenditure', 'branch_item', 'item', 'item_conversion', 'item_cost']);
        return true;
    }

    /**
     * Clinical supervisors may only access receipt notes in their assigned branches.
     */
    protected function _requester_can_access_branch($branch_id): bool
    {
        if ((int) ($this->requester_user->role_id ?? 0) !== 2) {
            return true;
        }

        $assigned_branch_ids = array_filter(array_map(
            'intval',
            explode(',', (string) ($this->requester_user->branch_id ?? ''))
        ));

        return in_array((int) $branch_id, $assigned_branch_ids, true);
    }

    protected function _is_pharmacy_role(): bool
    {
        return in_array((int) ($this->requester_user->role_id ?? 0), [ROLE_PHARMACIST, ROLE_PHARMACY_MANAGER], true);
    }

    protected function _validate_pharmacy_items($items): ?string
    {
        if (!$this->_is_pharmacy_role() || !is_array($items) || !$items) {
            return null;
        }

        foreach ($items as $index => $item) {
            $item_id = is_array($item) ? ($item['item_id'] ?? null) : null;
            $item_row = $item_id
                ? $this->item->select('id, kind', ['id' => $item_id, 'is_deleted' => 0], 1)
                : null;
            $kinds = array_map(
                static fn($kind) => strtolower(trim($kind)),
                explode(',', (string) ($item_row->kind ?? ''))
            );

            if (!$item_row || !in_array('pharmacy', $kinds, true)) {
                return 'Receipt note item at index ' . $index . ' must reference an active pharmacy item.';
            }
        }

        return null;
    }

    /**
     * Get receipt_notes
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $receipt_note_id = $this->payload['receipt_note_id'] ?? null;
        $purchase_order_id = $this->payload['purchase_order_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
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

        if (!$this->_requester_can_access_branch($branch_id)) {
            return $this->_create_response(FAIL, ['You are not assigned to this branch.']);
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $pharmacy_only = $this->_is_pharmacy_role();
        $receipt_notes = $this->receipt_note->get_details($receipt_note_id, $purchase_order_id, $status, $term, $page, $data_size, $supplier_id, $pharmacy_only)?:[];
        $size = count($this->receipt_note->get_details($receipt_note_id, $purchase_order_id, $status, $term, null, null, $supplier_id, $pharmacy_only)?:[]);

        if(!$receipt_notes) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No receipt_notes found.'
            ];
        } else {

            //append items to each receipt_note
            foreach ($receipt_notes as $key => $receipt_note) {
                $supplier = $this->supplier->select('name, tin, address', ['id' => $receipt_note['supplier_id'], 'is_deleted' => 0], 1) ?: null;
                $branch = $this->branch_master->select('name', ['id' => $receipt_note['branch_id'], 'is_deleted' => 0], 1) ?: null;
                $receipt_note_items = $this->receipt_note_item->get_details($receipt_note['id'])?:[];
                if ($receipt_note_items) {
                    foreach ($receipt_note_items as $i => $item) {
                        $item_detail = $this->item->select('id, name, is_canvassing, is_batch', ['id' => $item['item_id'], 'is_deleted' => 0], 1) ?: null;
                        $item_conversion = $this->item_conversion->select('id, conversion_type, qty, unit', ['item_id' => $item['item_id'], 'is_deleted' => 0]) ?: [];
                        $cost_center = $this->branch_master->select('name', ['id' => $item['cost_center_id'], 'is_deleted' => 0], 1) ?: null;

                        $receipt_note_items[$i]['cost_center_name'] = $cost_center ? $cost_center->name : null;
                        $receipt_note_items[$i]['item_name'] = $item_detail ? $item_detail->name : null;
                        $receipt_note_items[$i]['is_canvassing'] = $item_detail ? $item_detail->is_canvassing : null;
                        $receipt_note_items[$i]['is_batch'] = $item_detail ? $item_detail->is_batch : null;
                        $receipt_note_items[$i]['conversions'] = $item_conversion;
                        $receipt_note_items[$i]['expiry_dates'] = $this->receipt_note_item_expiry_date->get_details($item['id']) ?: [];
                    }
                }

                $receipt_note_additional_items = $this->receipt_note_additional_item->get_details($receipt_note['id'])?:[];
                if ($receipt_note_additional_items) {
                    foreach ($receipt_note_additional_items as $ai => $additional_item) {
                        $additional_item_detail = $this->item->select('id, name, is_canvassing', ['id' => $additional_item['item_id'], 'is_deleted' => 0], 1) ?: null;
                        $additional_item_conversion = $this->item_conversion->select('id, conversion_type, qty, unit', ['item_id' => $additional_item['item_id'], 'is_deleted' => 0]) ?: [];
                        $additional_item_cost_center = $this->branch_master->select('name', ['id' => $receipt_note['branch_id'], 'is_deleted' => 0], 1) ?: null;

                        $receipt_note_additional_items[$ai]['cost_center_name'] = $additional_item_cost_center ? $additional_item_cost_center->name : null;
                        $receipt_note_additional_items[$ai]['item_name'] = $additional_item_detail ? $additional_item_detail->name : null;
                        $receipt_note_additional_items[$ai]['is_canvassing'] = $additional_item_detail ? $additional_item_detail->is_canvassing : null;
                        $receipt_note_additional_items[$ai]['conversions'] = $additional_item_conversion;
                    }
                }

                $receipt_notes[$key]['supplier_name'] = $supplier ? $supplier->name : null;
                $receipt_notes[$key]['supplier_tin'] = $supplier ? $supplier->tin : null;
                $receipt_notes[$key]['supplier_address'] = $supplier ? $supplier->address : null;
                $receipt_notes[$key]['branch_name'] = $branch ? $branch->name : null;
                $receipt_notes[$key]['items'] = $receipt_note_items;
                $receipt_notes[$key]['additional_items'] = $receipt_note_additional_items;
                $receipt_notes[$key]['dr_attachments'] = $this->receipt_note_attachment->select('', ['receipt_note_id' => $receipt_note['id'], 'is_deleted' => 0, 'type' => 'dr']) ?: [];
                $receipt_notes[$key]['si_attachments'] = $this->receipt_note_attachment->select('', ['receipt_note_id' => $receipt_note['id'], 'is_deleted' => 0, 'type' => 'si']) ?: [];
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $receipt_notes
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Create receipt_note
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

        if (!$this->_requester_can_access_branch($branch_id)) {
            return $this->_create_response(FAIL, ['You are not assigned to this branch.']);
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        if ($validation_error = $this->_validate_pharmacy_items($this->payload['items'] ?? [])) {
            return $this->_create_response(FAIL, [$validation_error]);
        }

        if (!$this->_attempt_validate_item_batches()) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        $supplier_id = $this->payload['supplier_id'] ?? null;
        if (!$supplier_id) {
            return $this->_create_response(
                FAIL,
                ['Supplier ID is required.']
            );
        }

        $this->beginApprovalTransaction('receipt_note', $receipt_note_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        $purchase_order_id = $this->payload['purchase_order_id'] ?? null;

        if (!$purchase_order = $this->purchase_order->select('', ['id' => $purchase_order_id, 'is_deleted' => 0], 1)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_order is not found.'
            ];
        }  elseif (!$needed_date) {
            $response_status = FAIL;
            $response = [
                "Needed Date is required. ",
            ];
        } elseif ($purchase_order->status != 'approved') {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Cannot create receipt_note for a purchase_order that is not approved.'
            ];
        } elseif ($cost_error = $this->_items_require_cost($this->payload['items'] ?? [])) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                $cost_error,
            ];
        } elseif (!$receipt_note_id = $this->_attempt_create()) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add receipt_note. ",
            ];
        } elseif ($this->_is_items_qty_exceeded($purchase_order_id, $this->payload['items'] ?? [])) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "One or more item quantities exceed the ordered quantity. ",
            ];
        } elseif (!$this->_attempt_create_items($receipt_note_id, $purchase_order)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add receipt_note items. ",
            ];
        } elseif (!$this->_attempt_create_additional_items($receipt_note_id, $purchase_order)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add receipt_note additional items. ",
            ];
        // } elseif (!$this->_update_po_items_received_status($purchase_order)) {
        //     $this->rollbackApprovalTransaction();
        //     $response_status = FAIL;
        //     $response = [
        //         "Failed to update purchase order received status. ",
        //     ];
        } elseif (!$this->_attempt_create_attachments($receipt_note_id)) { //commented out for UAT only
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to add receipt_note attachments. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('receipt_note', (int) $receipt_note_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'receipt_note_id' => $receipt_note_id,
                'message'    => 'receipt_note added successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Validate receipt item batches before saving the document or its details.
     */
    private function _attempt_validate_item_batches($receipt_note_id = null)
    {
        $items = $this->payload['items'] ?? [];
        if (!is_array($items)) {
            $this->error_message = 'items must be an array.';
            return false;
        }

        $seen_item_ids = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $this->error_message = 'items['.$index.'] must be an object.';
                return false;
            }

            $saved_item = null;
            $saved_expiry_dates = [];
            if ($receipt_note_id && !empty($item['id'])) {
                if ((!is_int($item['id']) && !is_string($item['id'])) || !ctype_digit((string) $item['id'])
                    || isset($seen_item_ids[(int) $item['id']])) {
                    $this->error_message = 'Invalid or repeated receipt item ID at items['.$index.'].';
                    return false;
                }
                $seen_item_ids[(int) $item['id']] = true;
                $saved_item = $this->receipt_note_item->select('', [
                    'id' => $item['id'], 'receipt_note_id' => $receipt_note_id, 'is_deleted' => 0
                ], 1);
                if (!$saved_item) {
                    $this->error_message = 'Invalid receipt item at items['.$index.'].';
                    return false;
                }
                $saved_expiry_dates = $this->receipt_note_item_expiry_date->get_details($item['id']);
                if ($saved_expiry_dates === false) {
                    $this->error_message = 'Failed to read receipt item expiry dates.';
                    return false;
                }
                $saved_expiry_dates = array_column($saved_expiry_dates, null, 'id');
            }

            $batch_csv = array_key_exists('batch_no', $item) ? $item['batch_no'] : ($saved_item->batch_no ?? null);
            if (is_int($batch_csv) && $batch_csv >= 0) {
                $batch_csv = (string) $batch_csv;
            }
            if ($batch_csv !== null && (!is_string($batch_csv) || !mb_check_encoding($batch_csv, 'UTF-8')
                || preg_match('/\p{Cc}/u', $batch_csv))) {
                $this->error_message = 'items['.$index.'].batch_no must be a comma-separated string or null.';
                return false;
            }
            $batch_numbers = [];
            foreach (explode(',', $batch_csv ?? '') as $value) {
                if (trim($value) === '') {
                    continue;
                }
                if (!$this->_normalize_batch_no($value, $batch_no)) {
                    $this->error_message = 'Invalid batch number at items['.$index.'].batch_no.';
                    return false;
                }
                $batch_numbers['batch:'.$batch_no] = $batch_no;
            }
            $batch_csv = $batch_numbers ? implode(',', $batch_numbers) : null;
            if ($batch_csv !== null && mb_strlen($batch_csv, 'UTF-8') > 255) {
                $this->error_message = 'items['.$index.'].batch_no must not exceed 255 characters.';
                return false;
            }

            $expiry_dates = $item['expiry_dates'] ?? [];
            if (!is_array($expiry_dates)) {
                $this->error_message = 'items['.$index.'].expiry_dates must be an array.';
                return false;
            }
            $expiry_batches = [];
            $expiry_qty = 0;
            $seen_expiry_ids = [];
            foreach ($expiry_dates as $expiry_index => $expiry) {
                $field = 'items['.$index.'].expiry_dates['.$expiry_index.']';
                if (!is_array($expiry)) {
                    $this->error_message = $field.' must be an object.';
                    return false;
                }
                $saved_expiry = null;
                if (!empty($expiry['id'])) {
                    if ((!is_int($expiry['id']) && !is_string($expiry['id'])) || !ctype_digit((string) $expiry['id'])
                        || isset($seen_expiry_ids[(int) $expiry['id']])) {
                        $this->error_message = $field.'.id is invalid or repeated.';
                        return false;
                    }
                    $seen_expiry_ids[(int) $expiry['id']] = true;
                    $saved_expiry = $saved_expiry_dates[$expiry['id']] ?? null;
                    if (!$saved_expiry) {
                        $this->error_message = $field.'.id does not belong to this receipt item.';
                        return false;
                    }
                }
                $value = array_key_exists('batch_no', $expiry) ? $expiry['batch_no'] : ($saved_expiry['batch_no'] ?? null);
                if (!$this->_normalize_batch_no($value, $batch_no)) {
                    $this->error_message = $field.'.batch_no must be a batch code of up to 255 characters or null.';
                    return false;
                }
                $qty = $expiry['qty'] ?? 0;
                $qty = $qty === '' ? 0 : $qty;
                if (!is_numeric($qty) || (float) $qty < 0 || !is_finite((float) $qty)) {
                    $this->error_message = $field.'.qty must be a non-negative quantity.';
                    return false;
                }
                $expiry_qty += (float) $qty;
                if ($batch_no !== null) {
                    $expiry_batches['batch:'.$batch_no] = $batch_no;
                }
                $expiry_dates[$expiry_index]['batch_no'] = $batch_no;
            }
            $qty = array_key_exists('qty', $item) ? $item['qty'] : ($saved_item->qty ?? 0);
            $qty = $qty === '' || $qty === null ? 0 : $qty;
            if (!is_numeric($qty) || !is_finite((float) $qty) || (float) $qty < 0 || $expiry_qty > (float) $qty + 0.0000001) {
                $this->error_message = 'items['.$index.'] expiry quantities must not exceed its non-negative qty.';
                return false;
            }
            sort($batch_numbers, SORT_STRING);
            sort($expiry_batches, SORT_STRING);
            if ($batch_numbers !== $expiry_batches) {
                $this->error_message = 'items['.$index.'].batch_no must match its expiry date batch numbers.';
                return false;
            }
            $items[$index]['batch_no'] = $batch_csv;
            $items[$index]['expiry_dates'] = $expiry_dates;
        }

        $this->payload['items'] = $items;
        return true;
    }

    /**
     * Normalize a nullable batch code without changing case or leading zeros.
     */
    private function _normalize_batch_no($value, &$batch_no)
    {
        $batch_no = null;
        if ($value === null) {
            return true;
        }
        if (is_int($value) && $value >= 0) {
            $value = (string) $value;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/\p{Cc}/u', $value)) {
            return false;
        }
        $value = trim($value, ' ');
        if ($value === '') {
            return true;
        }
        if (str_contains($value, ',') || mb_strlen($value, 'UTF-8') > 255) {
            return false;
        }
        $batch_no = $value;
        return true;
    }

    /**
     * Attempt to create a receipt_note
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->receipt_note->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$receipt_note_id = $this->receipt_note->insert($values)) {
            $this->error_message = $this->receipt_note->error();
            return false;
        }

        return $receipt_note_id;
    }

    /**
     * Attempt to create receipt_note items
     */
    private function _attempt_create_items($receipt_note_id, $purchase_order)
    {
        if (method_exists($this->receipt_note_item, 'with_shared_parent')) { $this->receipt_note_item->with_shared_parent((int)$receipt_note_id); }
        if (method_exists($this->receipt_note_additional_item, 'with_shared_parent')) { $this->receipt_note_additional_item->with_shared_parent((int)$receipt_note_id); }
        if (method_exists($this->receipt_note_item_expiry_date, 'with_shared_parent')) { $this->receipt_note_item_expiry_date->with_shared_parent((int)$receipt_note_id); }
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return true;
        }

        foreach ($this->payload['items'] as $item) {
            $values = [
                'receipt_note_id'    => $receipt_note_id,
                'purchase_order_id'    => $purchase_order->id,
                'purchase_order_item_id'   => array_key_exists('purchase_order_item_id', $item) && $item['purchase_order_item_id'] != '' ? $item['purchase_order_item_id'] : null,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'cost_center_id'   => array_key_exists('cost_center_id', $item) && $item['cost_center_id'] != '' ? $item['cost_center_id'] : null,
                'expenditure_type'   => array_key_exists('expenditure_type', $item) && $item['expenditure_type'] != '' ? $item['expenditure_type'] : null,
                'batch_no' => $item['batch_no'] ?? null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'ordered_qty'   => array_key_exists('ordered_qty', $item) && $item['ordered_qty'] != '' ? $item['ordered_qty'] : null,
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

            if (!$receipt_note_item_id = $this->receipt_note_item->insert($values)) {
                $this->error_message = $this->receipt_note_item->error();
                return false;
            }

            if (!$this->_attempt_save_item_expiry_dates($receipt_note_id, $receipt_note_item_id, $item['expiry_dates'] ?? [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create receipt_note additional items
     */
    private function _attempt_create_additional_items($receipt_note_id, $purchase_order)
    {
        if (method_exists($this->receipt_note_item, 'with_shared_parent')) { $this->receipt_note_item->with_shared_parent((int)$receipt_note_id); }
        if (method_exists($this->receipt_note_additional_item, 'with_shared_parent')) { $this->receipt_note_additional_item->with_shared_parent((int)$receipt_note_id); }
        if (method_exists($this->receipt_note_item_expiry_date, 'with_shared_parent')) { $this->receipt_note_item_expiry_date->with_shared_parent((int)$receipt_note_id); }
        if (!array_key_exists('additional_items', $this->payload) || !is_array($this->payload['additional_items']) || count($this->payload['additional_items']) == 0) {
            return true;
        }

        foreach ($this->payload['additional_items'] as $item) {
            $values = [
                'receipt_note_id'    => $receipt_note_id,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'qty'   => array_key_exists('qty', $item) && $item['qty'] != '' ? $item['qty'] : null,
                'unit'   => array_key_exists('unit', $item) && $item['unit'] != '' ? $item['unit'] : null,
                'remarks'   => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->receipt_note_additional_item->insert($values)) {
                $this->error_message = $this->receipt_note_additional_item->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create receipt_note attachments
     */
    private function _attempt_create_attachments($receipt_note_id)
    {
        $attachments = $this->request->getFileMultiple('attachments');
        $attachment_types = $this->payload['attachment_types'] ?? [];

        if (empty($attachments) || empty($attachment_types)) {
            return true;
        }

        foreach ($attachments as $key => $attachment) {
            $file = $attachment;
            $file_type = $attachment_types[$key] ?? null;
            $file_path = 'assets/receipt_notes/' . $receipt_note_id . '/';

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
                    'receipt_note_id' => $receipt_note_id,
                    'type' => $file_type,
                    'file_name' => $file_name,
                    'file_path' => $upload_result['path'],
                    'file_url' => $uploaded_url,
                    'mime' => $mime_type,
                    'added_by' => $this->payload['requester'],
                    'added_on' => date('Y-m-d H:i:s')
                ];

                if (!$this->receipt_note_attachment->insert($values)) {
                    $this->error_message = $this->receipt_note_attachment->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update receipt_note
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

        $screen = $this->payload['screen'] ?? 'receipt_note';
        if ($screen !== 'apv' && $this->_is_pharmacy_role() && array_key_exists('items', $this->payload)) {
            if ($validation_error = $this->_validate_pharmacy_items($this->payload['items'])) {
                return $this->_create_response(FAIL, [$validation_error]);
            }
        }

        $supplier_id = $this->payload['supplier_id'] ?? null;
        if (!$supplier_id) {
            return $this->_create_response(
                FAIL,
                ['Supplier ID is required.']
            );
        }

        $receipt_note_id = $this->payload['receipt_note_id'];
        $where = [
                    'id' => $receipt_note_id, 
                    'is_deleted' => 0
                ];
                
        if ($screen !== 'apv' && !$this->_attempt_validate_item_batches($receipt_note_id)) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        $type = $this->payload['type'] ?? null;

        $this->beginApprovalTransaction('receipt_note', $receipt_note_id ?? null);

        $needed_date = $this->payload['needed_date'] ?? null;
        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);
        $purchase_order_id = $this->payload['purchase_order_id'] ?? null;

        if (!$purchase_order = $this->purchase_order->select('', ['id' => $purchase_order_id, 'is_deleted' => 0], 1)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_order is not found.'
            ];
        }  elseif (!$needed_date) {
            $response_status = FAIL;
            $response = [
                "Needed Date is required. ",
            ];
        } elseif ($purchase_order->status != 'approved') {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Cannot update receipt_note for a purchase_order that is not approved.'
            ];
        } elseif ($cost_error = $this->_items_require_cost($this->payload['items'] ?? [])) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                $cost_error,
            ];
        } elseif (!$receipt_note = $this->receipt_note->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'receipt_note is not found.'
            ];
        } elseif ((int) $receipt_note->branch_id !== (int) $branch_id) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'The selected branch does not match the receipt_note branch.'
            ];
        } elseif (!$this->_requester_can_access_branch($receipt_note->branch_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'You are not assigned to this branch.'
            ];
        } elseif ($screen != 'apv' && $this->_is_items_qty_exceeded($purchase_order_id, $this->payload['items'] ?? [])) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "One or more item quantities exceed the ordered quantity. ",
            ];
        } elseif (!$this->_attempt_update($receipt_note)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to update receipt_note.',
            ];
        } elseif ($screen != 'apv' && !$this->_attempt_update_items($receipt_note->id, $purchase_order)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to update receipt_note items. ",
            ];
        } elseif ($screen != 'apv' && !$this->_attempt_update_additional_items($receipt_note->id, $purchase_order)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to update receipt_note additional items. ",
            ];
        // } elseif ($screen != 'apv' && !$this->_update_po_items_received_status($purchase_order)) {
        //     $this->rollbackApprovalTransaction();
        //     $response_status = FAIL;
        //     $response = [
        //         "Failed to update purchase order received status. ",
        //     ];
        } elseif ($screen != 'apv' && !$this->_attempt_update_attachments($receipt_note_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to update receipt_note attachments. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('receipt_note', (int) $receipt_note_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'receipt_note_id' => $receipt_note_id,
                'message'    => 'receipt_note updated successfully.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($receipt_note)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->receipt_note->allowedFields is declared at the top of the model
        foreach($this->receipt_note->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $receipt_note->id];

        if (!$this->receipt_note->update($where, $values)) {
            $this->error_message = $this->receipt_note->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update receipt_note items
     */
    private function _attempt_update_items($receipt_note_id, $purchase_order)
    {
        if (method_exists($this->receipt_note_item, 'with_shared_parent')) { $this->receipt_note_item->with_shared_parent((int)$receipt_note_id); }
        if (method_exists($this->receipt_note_additional_item, 'with_shared_parent')) { $this->receipt_note_additional_item->with_shared_parent((int)$receipt_note_id); }
        if (method_exists($this->receipt_note_item_expiry_date, 'with_shared_parent')) { $this->receipt_note_item_expiry_date->with_shared_parent((int)$receipt_note_id); }
        // 1. Get all current item IDs for this receipt_note
        $existing_items = $this->receipt_note_item->select('id', ['receipt_note_id' => $receipt_note_id, 'is_deleted' => 0]);

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
                if (!$this->receipt_note_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->receipt_note_item->error();
                    return false;
                }

                if (!$this->_attempt_soft_delete_item_expiry_dates($delete_id)) {
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
                'receipt_note_id'  => $receipt_note_id,
                'purchase_order_id'    => $purchase_order->id,
                'purchase_order_item_id'   => array_key_exists('purchase_order_item_id', $item) && $item['purchase_order_item_id'] != '' ? $item['purchase_order_item_id'] : null,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'cost_center_id'   => array_key_exists('cost_center_id', $item) && $item['cost_center_id'] != '' ? $item['cost_center_id'] : null,
                'expenditure_type'   => array_key_exists('expenditure_type', $item) && $item['expenditure_type'] != '' ? $item['expenditure_type'] : null,
                'batch_no' => $item['batch_no'] ?? null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'ordered_qty'   => array_key_exists('ordered_qty', $item) && $item['ordered_qty'] != '' ? $item['ordered_qty'] : null,
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

                if (!$this->receipt_note_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->receipt_note_item->error();
                    return false;
                }
                $receipt_note_item_id = $item['id'];
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$receipt_note_item_id = $this->receipt_note_item->insert($values)) {
                    $this->error_message = $this->receipt_note_item->error();
                    return false;
                }
            }

            if (!$this->_attempt_sync_item_expiry_dates($receipt_note_id, $receipt_note_item_id, $item['expiry_dates'] ?? [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Soft-delete all expiry dates for a receipt note item
     */
    private function _attempt_soft_delete_item_expiry_dates($receipt_note_item_id)
    {
        $existing_expiry_dates = $this->receipt_note_item_expiry_date->select('id', [
            'receipt_note_item_id' => $receipt_note_item_id,
            'is_deleted' => 0
        ]) ?: [];

        foreach ($existing_expiry_dates as $expiry) {
            $delete_values = [
                'is_deleted' => 1,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s')
            ];
            if (!$this->receipt_note_item_expiry_date->update(['id' => $expiry->id], $delete_values)) {
                $this->error_message = $this->receipt_note_item_expiry_date->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Sync expiry dates for a receipt note item (soft-delete missing, then upsert)
     */
    private function _attempt_sync_item_expiry_dates($receipt_note_id, $receipt_note_item_id, $expiry_dates)
    {
        $existing_expiry_dates = $this->receipt_note_item_expiry_date->select('id', [
            'receipt_note_item_id' => $receipt_note_item_id,
            'is_deleted' => 0
        ]) ?: [];
        $existing_ids = array_column($existing_expiry_dates, 'id');

        $payload_ids = [];
        if (is_array($expiry_dates)) {
            foreach ($expiry_dates as $expiry) {
                if (!empty($expiry['id'])) {
                    $payload_ids[] = (int)$expiry['id'];
                }
            }
        }

        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->receipt_note_item_expiry_date->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->receipt_note_item_expiry_date->error();
                    return false;
                }
            }
        }

        return $this->_attempt_save_item_expiry_dates($receipt_note_id, $receipt_note_item_id, $expiry_dates);
    }

    /**
     * Insert or update expiry dates for a receipt note item
     */
    private function _attempt_save_item_expiry_dates($receipt_note_id, $receipt_note_item_id, $expiry_dates)
    {
        if (empty($expiry_dates) || !is_array($expiry_dates)) {
            return true;
        }

        foreach ($expiry_dates as $expiry) {
            $values = [
                'receipt_note_id' => $receipt_note_id,
                'receipt_note_item_id' => $receipt_note_item_id,
                'batch_no' => $expiry['batch_no'] ?? null,
                'date' => array_key_exists('date', $expiry) && $expiry['date'] != '' ? $expiry['date'] : null,
                'qty' => array_key_exists('qty', $expiry) && $expiry['qty'] != '' ? $expiry['qty'] : 0,
                'qty_balance' => array_key_exists('qty', $expiry) && $expiry['qty'] != '' ? $expiry['qty'] : 0, //default to qty for new records, will be updated later if needed
            ];

            if (!empty($expiry['id'])) {
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->receipt_note_item_expiry_date->update(['id' => $expiry['id']], $values)) {
                    $this->error_message = $this->receipt_note_item_expiry_date->error();
                    return false;
                }
            } else {
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->receipt_note_item_expiry_date->insert($values)) {
                    $this->error_message = $this->receipt_note_item_expiry_date->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update receipt_note additional items
     */
    private function _attempt_update_additional_items($receipt_note_id, $purchase_order)
    {
        if (method_exists($this->receipt_note_item, 'with_shared_parent')) { $this->receipt_note_item->with_shared_parent((int)$receipt_note_id); }
        if (method_exists($this->receipt_note_additional_item, 'with_shared_parent')) { $this->receipt_note_additional_item->with_shared_parent((int)$receipt_note_id); }
        if (method_exists($this->receipt_note_item_expiry_date, 'with_shared_parent')) { $this->receipt_note_item_expiry_date->with_shared_parent((int)$receipt_note_id); }
        // 1. Get all current item IDs for this receipt_note
        $existing_additional_items = $this->receipt_note_additional_item->select('id', ['receipt_note_id' => $receipt_note_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_additional_items, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('additional_items', $this->payload) && is_array($this->payload['additional_items'])) {
            foreach ($this->payload['additional_items'] as $item) {
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
                if (!$this->receipt_note_additional_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->receipt_note_additional_item->error();
                    return false;
                }
            }
        }

        // 4. Process payload items (update existing or insert new)
        if (!array_key_exists('additional_items', $this->payload) || !is_array($this->payload['additional_items'])) {
            return true;
        }

        foreach ($this->payload['additional_items'] as $item) {
            $values = [
                'receipt_note_id'  => $receipt_note_id,
                'account_type_id'   => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'item_id'   => array_key_exists('item_id', $item) && $item['item_id'] != '' ? $item['item_id'] : null,
                'qty'   => array_key_exists('qty', $item) && $item['qty'] != '' ? $item['qty'] : null,
                'unit'   => array_key_exists('unit', $item) && $item['unit'] != '' ? $item['unit'] : null,
                'remarks'   => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
            ];

            if (!empty($item['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->receipt_note_additional_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->receipt_note_additional_item->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->receipt_note_additional_item->insert($values)) {
                    $this->error_message = $this->receipt_note_additional_item->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update receipt_note attachments
     */
    private function _attempt_update_attachments($receipt_note_id)
    {
        $attachments = $this->request->getFileMultiple('attachments');
        $attachment_types = $this->payload['attachment_types'] ?? [];

        if (empty($attachments) || empty($attachment_types)) {
            return true;
        }

        $existing_attachments = $this->receipt_note_attachment->select('id', ['receipt_note_id' => $receipt_note_id, 'is_deleted' => 0]) ?: [];
        if ($existing_attachments) {
            //soft delete the existing attachments
            if (!$this->receipt_note_attachment->update(['receipt_note_id' => $receipt_note_id, 'is_deleted' => 1], ['updated_by' => $this->payload['requester'], 'updated_on' => date('Y-m-d H:i:s')])) {
                $this->error_message = $this->receipt_note_attachment->error();
                return false;
            }
        }

        //then call the _attempt_create_attachments function
        if (!$this->_attempt_create_attachments($receipt_note_id)) {
            $this->error_message = $this->receipt_note_attachment->error();
            return false;
        }

        return true;
    }

    /**
     * Items quantity exceeded
     */
    protected function _is_items_qty_exceeded($purchase_order_id, $items)
    {
        foreach ($items as $item) {
            $purchase_order_item_id = $item['purchase_order_item_id'] ?? null;
            $qty = $item['qty'] ?? 0;

            if ($purchase_order_item_id) {
                // Get ordered quantity from purchase order item
                $purchase_order_item = $this->purchase_order_item->select('', ['id' => $purchase_order_item_id, 'purchase_order_id' => $purchase_order_id, 'is_deleted' => 0], 1);
                if ($purchase_order_item) {
                    $ordered_qty = $purchase_order_item->qty;

                    // Calculate total received quantity for this item in existing receipt notes
                    $total_received_qty = 0;
                    $receipt_note_items = $this->receipt_note_item->select('receipt_note_id, qty', ['purchase_order_item_id' => $purchase_order_item_id, 'purchase_order_id' => $purchase_order_id, 'is_deleted' => 0]);
                    if ($receipt_note_items) {
                        foreach ($receipt_note_items as $rni) {
                            $receipt_note_data = $this->receipt_note->select('', ['id' => $rni->receipt_note_id, 'is_deleted' => 0], 1);
                            if ($receipt_note_data && $receipt_note_data->status != 'disapproved') {
                                $total_received_qty += $rni->qty;
                            }
                        }
                    }

                    // Check if adding the new qty exceeds the ordered quantity
                    if (($total_received_qty + $qty) > $ordered_qty) {
                        return true; // Exceeded
                    }
                }
            }
        }

        return false; // Not exceeded
    }

    /**
     * PO items received status
     */
    protected function _update_po_items_received_status($purchase_order)
    {
        $recieved_item_status = 'closed';
        $purchase_order_items = $this->purchase_order_item->get_details($purchase_order->id)?:[];
        if ($purchase_order_items) {
            foreach ($purchase_order_items as $i => $item) {
                $total_received_qty = 0;
                $receipt_note_items = $this->receipt_note_item->select('id, receipt_note_id, qty', ['purchase_order_item_id' => $item['id'], 'purchase_order_id' => $purchase_order->id, 'is_deleted' => 0]);
                if ($receipt_note_items) {
                    foreach ($receipt_note_items as $rni) {
                        $receipt_note_data = $this->receipt_note->select('', ['id' => $rni->receipt_note_id, 'is_deleted' => 0], 1);
                        if ($receipt_note_data && $receipt_note_data->status == 'approved') {
                            $total_received_qty += $rni->qty;
                        }
                    }
                }

                //handle recieved_item_status
                if ($total_received_qty < $item['ordered_qty']) {
                    $recieved_item_status = 'open';
                }
            }
        }

        // Update received status
        if (!$this->purchase_order->update(['id' => $purchase_order->id], ['received_status' => $recieved_item_status])) {
            $this->error_message = $this->purchase_order->error();
            return false;
        }

        return true;
    }


    /**
     * Change status receipt_note
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $receipt_note_id = $this->payload['receipt_note_id'];
        $where = [
                    'id' => $receipt_note_id, 
                    'is_deleted' => 0
                ];

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

        $this->beginApprovalTransaction('receipt_note', $receipt_note_id ?? null);

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$receipt_note = $this->receipt_note->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'receipt_note is not found.'
            ];
        } elseif (!$purchase_order = $this->purchase_order->select('', ['id' => $receipt_note->purchase_order_id, 'is_deleted' => 0], 1)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_order is not found.'
            ];
        } elseif ($status && $receipt_note->status == $status) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Receipt_note is already $status. ",
            ];
        } elseif ($status == 'approved' && !$this->_attempt_adjust_branch_item($receipt_note)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to adjust branch item quantity.',
            ];
        } elseif ($status == 'approved' && !$this->_attempt_adjust_branch_additional_item($receipt_note)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to adjust branch additional item quantity.',
            ];
        } elseif ($status == 'approved' && !$this->_attempt_apply_receipt_weighted_cost($receipt_note)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to update item weighted cost.',
            ];
        } elseif (!$this->_attempt_update_status($receipt_note, $user->role_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'Failed to change receipt_note status.',
            ];
        } elseif ($status == 'approved' && !$this->_update_po_items_received_status($purchase_order)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                "Failed to update purchase order received status. ",
            ];
        } else {
            if (!$this->commitApprovalTransaction('receipt_note', (int) $receipt_note_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'receipt_note_id' => $receipt_note_id,
                'message'    => "receipt_note $status successfully."
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($receipt_note, $role_id)
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

        $where = ['id' => $receipt_note->id];

        if (!$this->receipt_note->update($where, $values)) {
            $this->error_message = $this->receipt_note->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt adjust branch item
     */
    protected function _attempt_adjust_branch_item($receipt_note)
    {
        if ($this->fund_router()->procurement_group((int)$receipt_note->branch_id)==='master') {
            return $this->shared_procurement_inventory('receipt_note',$receipt_note);
        }
        $receipt_note_items = $this->receipt_note_item->get_details($receipt_note->id)?:[];
        // Only adjust if the reciept note has items is approved
        if (!$receipt_note_items) {
            return true;
        }

        // Only adjust if the reciept note is approved
        if ($this->payload['status'] != 'approved') {
            return true;
        }

        foreach ($receipt_note_items as $receipt_note_item) {

            // 1. Get all conversions
            $conversions = $this->item_conversion->select('', [
                'item_id' => $receipt_note_item['item_id'],
                'is_deleted' => 0
            ]);

            // 2. Find source conversion (the unit being transferred)
            $source_conversion = null;
            foreach ($conversions as $conv) {
                if ($conv->id == $receipt_note_item['unit']) {
                    $source_conversion = $conv;
                    break;
                }
            }

            // 3. Process DESTINATION branch (add)
            foreach ($conversions as $conversion) {
                // Get or create destination branch_item
                $dest_item = $this->branch_item->select('', [
                    'item_id' => $receipt_note_item['item_id'],
                    'branch_id' => $receipt_note->branch_id,
                    'unit' => $conversion->id,
                    'is_deleted' => 0
                ], 1);

                if (!$dest_item) {
                    $dest_item_id = $this->branch_item->insert([
                        'item_id' => $receipt_note_item['item_id'],
                        'branch_id' => $receipt_note->branch_id,
                        'beggining_qty' => 0,
                        'current_qty' => 0,
                        'unit' => $conversion->id,
                        'added_by' => $this->payload['requester'],
                        'added_on' => date('Y-m-d H:i:s')
                    ]);
                    $dest_item = $this->branch_item->select('', ['id' => $dest_item_id], 1);
                }

                // Calculate addition (same logic as deduction)
                if ($conversion->id == $receipt_note_item['unit']) {
                    $addition = $receipt_note_item['qty'];
                } else {
                    $ratio = (float)$conversion->qty / (float)$source_conversion->qty;
                    $addition = $receipt_note_item['qty'] * $ratio;
                }

                $new_qty = $dest_item->current_qty + $addition;

                $this->branch_item->update(['id' => $dest_item->id], [
                    'current_qty' => $new_qty,
                    'latest_activity' => 'Receipt Note',
                    'latest_activity_id' => $receipt_note->id,
                    'updated_by' => $this->payload['user_id'] ?? null,
                    'updated_on' => date('Y-m-d H:i:s')
                ]);
            }

        }

        return true;
    }

    /**
     * Attempt adjust branch additional item
     */
    protected function _attempt_adjust_branch_additional_item($receipt_note)
    {
        if ($this->fund_router()->procurement_group((int)$receipt_note->branch_id)==='master') { return true; }
        $receipt_note_additional_items = $this->receipt_note_additional_item->get_details($receipt_note->id)?:[];
        // Only adjust if the reciept note has items is approved
        if (!$receipt_note_additional_items) {
            return true;
        }

        // Only adjust if the reciept note is approved
        if ($this->payload['status'] != 'approved') {
            return true;
        }

        foreach ($receipt_note_additional_items as $receipt_note_additional_item) {

            // 1. Get all conversions
            $conversions = $this->item_conversion->select('', [
                'item_id' => $receipt_note_additional_item['item_id'],
                'is_deleted' => 0
            ]);

            // 2. Find source conversion (the unit being transferred)
            $source_conversion = null;
            foreach ($conversions as $conv) {
                if ($conv->id == $receipt_note_additional_item['unit']) {
                    $source_conversion = $conv;
                    break;
                }
            }   

            // 3. Process DESTINATION branch (add)
            foreach ($conversions as $conversion) {
                // Get or create destination branch_item
                $dest_item = $this->branch_item->select('', [
                    'item_id' => $receipt_note_additional_item['item_id'],
                    'branch_id' => $receipt_note->branch_id,
                    'unit' => $conversion->id,
                    'is_deleted' => 0
                ], 1);

                if (!$dest_item) {
                    $dest_item_id = $this->branch_item->insert([
                        'item_id' => $receipt_note_additional_item['item_id'],
                        'branch_id' => $receipt_note->branch_id,
                        'beggining_qty' => 0,
                        'current_qty' => 0,
                        'unit' => $conversion->id,
                        'added_by' => $this->payload['requester'],
                        'added_on' => date('Y-m-d H:i:s')
                    ]);
                    $dest_item = $this->branch_item->select('', ['id' => $dest_item_id], 1);
                }

                // Calculate addition (same logic as deduction)
                if ($conversion->id == $receipt_note_additional_item['unit']) {
                    $addition = $receipt_note_additional_item['qty'];
                } else {
                    $ratio = (float)$conversion->qty / (float)$source_conversion->qty;
                    $addition = $receipt_note_additional_item['qty'] * $ratio;
                }

                $new_qty = $dest_item->current_qty + $addition;

                $this->branch_item->update(['id' => $dest_item->id], [
                    'current_qty' => $new_qty,
                    'latest_activity' => 'Receipt Note',
                    'latest_activity_id' => $receipt_note->id,
                    'updated_by' => $this->payload['user_id'] ?? null,
                    'updated_on' => date('Y-m-d H:i:s')
                ]);
            }

        }

        return true;
    }

    /**
     * Delete receipt_notes
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
        $receipt_note_id = $this->payload['receipt_note_id'];

        $where = ['id' => $receipt_note_id, 'is_deleted' => 0];

        $this->beginApprovalTransaction('receipt_note', $receipt_note_id ?? null);

        if (!$receipt_note = $this->receipt_note->select('', $where, 1)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'receipt_note is not found.',
            ];
        } elseif (!$this->_attempt_delete($receipt_note_id)) {
            $this->rollbackApprovalTransaction();
            $response_status = FAIL;
            $response = [
                'receipt_note is not deleted.'
            ];
        } else {
            if (!$this->commitApprovalTransaction('receipt_note', (int) $receipt_note_id)) {
                return $this->_create_response(FAIL, ['Failed to save the document and approval reminders.']);
            }
            $response_status = SUCCESS;
            $response = [
                'message' => 'receipt_note is deleted.'
            ];
        }

        $this->rollbackApprovalTransaction();
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($receipt_note_id)
    {
        $where = ['id' => $receipt_note_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->receipt_note->update($where, $values)) {
            $this->error_message = $this->receipt_note->error();
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
     * Require cost on each receipt note line item.
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

    /**
     * Recalculate WAC and insert item_cost trail on approval.
     */
    protected function _attempt_apply_receipt_weighted_cost($receipt_note)
    {
        if ($this->fund_router()->procurement_group((int)$receipt_note->branch_id)==='master') { return true; }
        $receipt_note_items = $this->receipt_note_item->get_details($receipt_note->id) ?: [];
        if (!$receipt_note_items) {
            return true;
        }

        $branch_id = (int)$receipt_note->branch_id;

        foreach ($receipt_note_items as $receipt_note_item) {
            $qty = (float)($receipt_note_item['qty'] ?? 0);
            if ($qty == 0.0) {
                continue;
            }

            $unit_cost = (float)($receipt_note_item['cost'] ?? 0);
            if ($unit_cost == 0.0 && isset($receipt_note_item['price'])) {
                $unit_cost = (float)$receipt_note_item['price'];
            }

            if (!$this->_apply_receipt_weighted_cost(
                (int)$receipt_note_item['item_id'],
                $branch_id,
                $qty,
                $receipt_note_item['unit'] ?? null,
                $unit_cost
            )) {
                return false;
            }
        }

        return true;
    }
}
