<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\User;
use App\Models\Item;
use App\Models\Item_conversion;
use App\Models\Supplier;
use App\Models\Account_type;

class Purchase_returns extends MYT_Controller
{
    use \App\Core\Procurement_routing;
    protected $request;
    protected $purchase_return;
    protected $purchase_return_item;
    protected $purchase_order;
    protected $receipt_note;
    protected $receipt_note_item;
    protected $branch_item;
    protected $branch_master;
    protected $user;
    protected $item;
    protected $item_conversion;
    protected $supplier;
    protected $account_type;
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
        $this->account_type = new Account_type();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper — clinic DBs 1, 2, 3, 4, 7 only.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $group = $this->fund_router()->procurement_group($branch_id);
        if ($group === null) { return false; }
        $db = db_connect($group);
        $this->db = $db;

        $this->purchase_return = new \App\Models\Purchase_return($db);
        $this->purchase_return_item = new \App\Models\Purchase_return_item($db);
        $this->purchase_order = new \App\Models\Purchase_order($db);
        $this->receipt_note = new \App\Models\Receipt_note($db);
        $this->receipt_note_item = new \App\Models\Receipt_note_item($db);
        $this->branch_item = new \App\Models\Branch_item($db);

        $this->scope_procurement_models($branch_id, ['purchase_return', 'purchase_return_item', 'purchase_order', 'receipt_note', 'receipt_note_item', 'branch_item']);
        return true;
    }

    /**
     * Get purchase_returns
     */
    public function get()
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

        $purchase_return_id = $this->payload['purchase_return_id'] ?? null;
        $receipt_note_id = $this->payload['receipt_note_id'] ?? null;
        $purchase_order_id = $this->payload['purchase_order_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $purchase_returns = $this->purchase_return->get_details($purchase_return_id, $receipt_note_id, $purchase_order_id, $status, $page, $data_size) ?: [];
        $size = count($this->purchase_return->get_details($purchase_return_id, $receipt_note_id, $purchase_order_id, $status, null, null) ?: []);

        if (!$purchase_returns) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No purchase_returns found.'
            ];
        } else {
            foreach ($purchase_returns as $key => $purchase_return) {
                $supplier = $this->supplier->select('name, tin, address', ['id' => $purchase_return['supplier_id'], 'is_deleted' => 0], 1) ?: null;
                $branch = $this->branch_master->select('name', ['id' => $purchase_return['branch_id'], 'is_deleted' => 0], 1) ?: null;
                $items = $this->purchase_return_item->get_details($purchase_return['id']) ?: [];
                if ($items) {
                    foreach ($items as $i => $item) {
                        $item_detail = $this->item->select('id, name', ['id' => $item['item_id'], 'is_deleted' => 0], 1) ?: null;
                        $cost_center = $this->branch_master->select('name', ['id' => $item['cost_center_id'], 'is_deleted' => 0], 1) ?: null;
                        $items[$i]['item_name'] = $item_detail ? $item_detail->name : null;
                        $items[$i]['cost_center_name'] = $cost_center ? $cost_center->name : null;
                    }
                }

                $purchase_returns[$key]['supplier_name'] = $supplier ? $supplier->name : null;
                $purchase_returns[$key]['branch_name'] = $branch ? $branch->name : null;
                $purchase_returns[$key]['items'] = $items;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $purchase_returns
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create purchase_return
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

        $receipt_note_id = $this->payload['receipt_note_id'] ?? null;
        $purchase_order_id = $this->payload['purchase_order_id'] ?? null;
        $items = $this->payload['items'] ?? [];

        $this->db->transBegin();

        if ($receipt_note_id == null || $receipt_note_id === '') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Receipt note ID is required.'
            ];
        } elseif ($purchase_order_id == null || $purchase_order_id === '') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Purchase order ID is required.'
            ];
        } elseif (!is_array($items) || count($items) == 0) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'At least one item is required.'
            ];
        } elseif (($validation_error = $this->_validate_receipt_note_purchase_order($receipt_note_id, $purchase_order_id)) !== true) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $validation_error
            ];
        } elseif (($item_error = $this->_validate_items($receipt_note_id, $items)) !== true) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $item_error
            ];
        } elseif ($this->_is_return_qty_exceeded($receipt_note_id, $items)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'One or more item quantities exceed the received quantity.'
            ];
        } elseif (!$purchase_return_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to add purchase_return.'
            ];
        } elseif (!$this->_attempt_create_items($purchase_return_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to add purchase_return items.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'purchase_return_id' => $purchase_return_id,
                'message' => 'purchase_return added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a purchase_return
     */
    private function _attempt_create()
    {
        $receipt_note = $this->receipt_note->select('', [
            'id' => $this->payload['receipt_note_id'],
            'is_deleted' => 0
        ], 1);

        $values = [
            'receipt_note_id' => $this->payload['receipt_note_id'],
            'purchase_order_id' => $this->payload['purchase_order_id'],
            'branch_id' => $this->payload['branch_id'],
            'supplier_id' => $receipt_note ? $receipt_note->supplier_id : null,
            'return_date' => array_key_exists('return_date', $this->payload) && $this->payload['return_date'] != '' ? $this->payload['return_date'] : date('Y-m-d'),
            'reason' => array_key_exists('reason', $this->payload) && $this->payload['reason'] != '' ? $this->payload['reason'] : null,
            'remarks' => array_key_exists('remarks', $this->payload) && $this->payload['remarks'] != '' ? $this->payload['remarks'] : null,
            'grand_total' => $this->_sum_item_amounts($this->payload['items'] ?? []),
            'status' => 'pending',
            'added_by' => $this->payload['requester'],
            'added_on' => date('Y-m-d H:i:s'),
        ];

        if (!$purchase_return_id = $this->purchase_return->insert($values)) {
            $this->error_message = $this->purchase_return->error();
            return false;
        }

        return $purchase_return_id;
    }

    /**
     * Attempt to create purchase_return items
     */
    private function _attempt_create_items($purchase_return_id)
    {
        if (method_exists($this->purchase_return_item, 'with_shared_parent')) { $this->purchase_return_item->with_shared_parent((int)$purchase_return_id); }
        foreach ($this->payload['items'] as $item) {
            $values = $this->_build_item_values($purchase_return_id, $item);
            $values['added_by'] = $this->payload['requester'];
            $values['added_on'] = date('Y-m-d H:i:s');

            if (!$this->purchase_return_item->insert($values)) {
                $this->error_message = $this->purchase_return_item->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update purchase_return
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

        $purchase_return_id = $this->payload['purchase_return_id'] ?? null;
        $where = [
            'id' => $purchase_return_id,
            'is_deleted' => 0
        ];

        $receipt_note_id = $this->payload['receipt_note_id'] ?? null;
        $purchase_order_id = $this->payload['purchase_order_id'] ?? null;
        $items = $this->payload['items'] ?? [];

        $this->db->transBegin();
        if ($this->fund_router()->procurement_group((int)$branch_id)==='master') {
            $this->db->query('SELECT id FROM purchase_return WHERE id=? AND branch_id=? FOR UPDATE',[$purchase_return_id,$branch_id]);
        }

        if (!$purchase_return = $this->purchase_return->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_return is not found.'
            ];
        } elseif ($purchase_return->status != 'pending') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Cannot update a purchase_return that is not pending.'
            ];
        } else {
            $receipt_note_id = $receipt_note_id ?: $purchase_return->receipt_note_id;
            $purchase_order_id = $purchase_order_id ?: $purchase_return->purchase_order_id;
            $items = is_array($items) && count($items) > 0 ? $items : null;

            if ($items === null) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'At least one item is required.'
                ];
            } elseif (($validation_error = $this->_validate_receipt_note_purchase_order($receipt_note_id, $purchase_order_id)) !== true) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    $validation_error
                ];
            } elseif (($item_error = $this->_validate_items($receipt_note_id, $items)) !== true) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    $item_error
                ];
            } elseif ($this->_is_return_qty_exceeded($receipt_note_id, $items, $purchase_return_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'One or more item quantities exceed the received quantity.'
                ];
            } elseif (!$this->_attempt_update($purchase_return, $receipt_note_id, $purchase_order_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to update purchase_return.'
                ];
            } elseif (!$this->_attempt_update_items($purchase_return->id, $receipt_note_id, $purchase_order_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to update purchase_return items.'
                ];
            } else {
                $this->db->transCommit();
                $response_status = SUCCESS;
                $response = [
                    'purchase_return_id' => $purchase_return_id,
                    'message' => 'purchase_return updated successfully.'
                ];
            }
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($purchase_return, $receipt_note_id, $purchase_order_id)
    {
        $receipt_note = $this->receipt_note->select('', ['id' => $receipt_note_id, 'is_deleted' => 0], 1);

        $values = [
            'receipt_note_id' => $receipt_note_id,
            'purchase_order_id' => $purchase_order_id,
            'supplier_id' => $receipt_note ? $receipt_note->supplier_id : $purchase_return->supplier_id,
            'grand_total' => $this->_sum_item_amounts($this->payload['items'] ?? []),
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        foreach (['return_date', 'reason', 'remarks'] as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        if (!$this->purchase_return->update(['id' => $purchase_return->id], $values)) {
            $this->error_message = $this->purchase_return->error();
            return false;
        }
        return true;
    }

    /**
     * Sync purchase_return items (soft-delete missing, upsert payload)
     */
    private function _attempt_update_items($purchase_return_id, $receipt_note_id, $purchase_order_id)
    {
        if (method_exists($this->purchase_return_item, 'with_shared_parent')) { $this->purchase_return_item->with_shared_parent((int)$purchase_return_id); }
        $existing_items = $this->purchase_return_item->select('id', ['purchase_return_id' => $purchase_return_id, 'is_deleted' => 0]) ?: [];
        $existing_ids = array_column($existing_items, 'id');

        $payload_ids = [];
        foreach ($this->payload['items'] as $item) {
            if (!empty($item['id'])) {
                $payload_ids[] = (int) $item['id'];
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
                if (!$this->purchase_return_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->purchase_return_item->error();
                    return false;
                }
            }
        }

        foreach ($this->payload['items'] as $item) {
            $values = $this->_build_item_values($purchase_return_id, $item, $receipt_note_id, $purchase_order_id);

            if (!empty($item['id'])) {
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->purchase_return_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->purchase_return_item->error();
                    return false;
                }
            } else {
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->purchase_return_item->insert($values)) {
                    $this->error_message = $this->purchase_return_item->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status purchase_return
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
        $purchase_return_id = $this->payload['purchase_return_id'] ?? null;
        $where = [
            'id' => $purchase_return_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        if (!$purchase_return = $this->purchase_return->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_return is not found.'
            ];
        } elseif (!in_array($status, ['approved', 'disapproved'])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Invalid status. Valid statuses are: approved, disapproved.',
            ];
        } elseif ($purchase_return->status != 'pending') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "purchase_return is already $purchase_return->status. ",
            ];
        } elseif ($status == 'approved' && !$this->_attempt_adjust_branch_item($purchase_return)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to adjust branch item quantity.',
            ];
        } elseif (!$this->_attempt_update_status($purchase_return)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change purchase_return status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'purchase_return_id' => $purchase_return_id,
                'message' => "purchase_return $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($purchase_return)
    {
        $values = [
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'approved') {
            $values['status'] = 'approved';
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
            $values['disapproved_reason'] = $this->payload['disapproved_reason'] ?? null;
        } else {
            return false;
        }

        if (!$this->purchase_return->update(['id' => $purchase_return->id], $values)) {
            $this->error_message = $this->purchase_return->error();
            return false;
        }
        return true;
    }

    /**
     * Deduct branch_item qty on approval (inverse of receipt note).
     */
    protected function _attempt_adjust_branch_item($purchase_return)
    {
        if ($this->fund_router()->procurement_group((int)$purchase_return->branch_id)==='master') {
            return $this->shared_procurement_inventory('purchase_return',$purchase_return);
        }
        $items = $this->purchase_return_item->get_details($purchase_return->id) ?: [];
        if (!$items) {
            return true;
        }

        foreach ($items as $item) {
            if (empty($item['item_id'])) {
                continue;
            }

            $conversions = $this->item_conversion->select('', [
                'item_id' => $item['item_id'],
                'is_deleted' => 0
            ]) ?: [];

            $source_conversion = null;
            foreach ($conversions as $conv) {
                if ($conv->id == $item['unit']) {
                    $source_conversion = $conv;
                    break;
                }
            }

            if (!$source_conversion) {
                continue;
            }

            foreach ($conversions as $conversion) {
                $dest_item = $this->branch_item->select('', [
                    'item_id' => $item['item_id'],
                    'branch_id' => $purchase_return->branch_id,
                    'unit' => $conversion->id,
                    'is_deleted' => 0
                ], 1);

                if (!$dest_item) {
                    $dest_item_id = $this->branch_item->insert([
                        'item_id' => $item['item_id'],
                        'branch_id' => $purchase_return->branch_id,
                        'beggining_qty' => 0,
                        'current_qty' => 0,
                        'unit' => $conversion->id,
                        'added_by' => $this->payload['requester'],
                        'added_on' => date('Y-m-d H:i:s')
                    ]);
                    $dest_item = $this->branch_item->select('', ['id' => $dest_item_id], 1);
                }

                if ($conversion->id == $item['unit']) {
                    $deduction = $item['qty'];
                } else {
                    $ratio = (float) $conversion->qty / (float) $source_conversion->qty;
                    $deduction = $item['qty'] * $ratio;
                }

                $new_qty = $dest_item->current_qty - $deduction;

                if (!$this->branch_item->update(['id' => $dest_item->id], [
                    'current_qty' => $new_qty,
                    'latest_activity' => 'Purchase Return',
                    'latest_activity_id' => $purchase_return->id,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ])) {
                    $this->error_message = $this->branch_item->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Delete purchase_return
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

        $purchase_return_id = $this->payload['purchase_return_id'] ?? null;
        $where = ['id' => $purchase_return_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$purchase_return = $this->purchase_return->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchase_return is not found.',
            ];
        } elseif ($purchase_return->status != 'pending') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Cannot delete a purchase_return that is not pending.',
            ];
        } elseif (!$this->_attempt_delete($purchase_return_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'purchase_return is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'purchase_return is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete header and items
     */
    protected function _attempt_delete($purchase_return_id)
    {
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->purchase_return->update(['id' => $purchase_return_id], $values)) {
            $this->error_message = $this->purchase_return->error();
            return false;
        }

        $items = $this->purchase_return_item->select('id', ['purchase_return_id' => $purchase_return_id, 'is_deleted' => 0]) ?: [];
        foreach ($items as $item) {
            if (!$this->purchase_return_item->update(['id' => $item->id], $values)) {
                $this->error_message = $this->purchase_return_item->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Receipt note must exist, be approved, and match purchase_order_id.
     */
    protected function _validate_receipt_note_purchase_order($receipt_note_id, $purchase_order_id)
    {
        $receipt_note = $this->receipt_note->select('', ['id' => $receipt_note_id, 'is_deleted' => 0], 1);
        if (!$receipt_note) {
            return 'Receipt note is not found.';
        }

        if ($receipt_note->status != 'approved') {
            return 'Cannot use a receipt_note that is not approved.';
        }

        if ((int) $receipt_note->purchase_order_id !== (int) $purchase_order_id) {
            return 'Purchase order does not belong to the given receipt note.';
        }

        if (!$this->purchase_order->select('', ['id' => $purchase_order_id, 'is_deleted' => 0], 1)) {
            return 'Purchase order is not found.';
        }

        return true;
    }

    /**
     * Each item must have receipt_note_item_id, account_type_id, qty; RN item must belong to RN.
     */
    protected function _validate_items($receipt_note_id, $items)
    {
        foreach ($items as $item) {
            if (empty($item['receipt_note_item_id'])) {
                return 'receipt_note_item_id is required on every item.';
            }
            if (!array_key_exists('account_type_id', $item) || $item['account_type_id'] === '' || $item['account_type_id'] === null) {
                return 'account_type_id is required on every item.';
            }
            if (!array_key_exists('qty', $item) || $item['qty'] === '' || $item['qty'] === null) {
                return 'qty is required on every item.';
            }
            if (!$this->account_type->select('', ['id' => $item['account_type_id'], 'is_deleted' => 0], 1)) {
                return 'Account type is not found.';
            }
            $rn_item = $this->receipt_note_item->select('', [
                'id' => $item['receipt_note_item_id'],
                'receipt_note_id' => $receipt_note_id,
                'is_deleted' => 0
            ], 1);
            if (!$rn_item) {
                return 'Receipt note item does not belong to the given receipt note.';
            }
        }

        return true;
    }

    /**
     * Return qty cannot exceed RN item qty minus other non-disapproved returns.
     */
    protected function _is_return_qty_exceeded($receipt_note_id, $items, $exclude_purchase_return_id = null)
    {
        foreach ($items as $item) {
            $receipt_note_item_id = $item['receipt_note_item_id'] ?? null;
            $qty = (float) ($item['qty'] ?? 0);

            $rn_item = $this->receipt_note_item->select('', [
                'id' => $receipt_note_item_id,
                'receipt_note_id' => $receipt_note_id,
                'is_deleted' => 0
            ], 1);
            if (!$rn_item) {
                return true;
            }

            $total_returned_qty = 0;
            $return_items = $this->purchase_return_item->select('', [
                'receipt_note_item_id' => $receipt_note_item_id,
                'is_deleted' => 0
            ]) ?: [];

            foreach ($return_items as $return_item) {
                if ($exclude_purchase_return_id && (int) $return_item->purchase_return_id === (int) $exclude_purchase_return_id) {
                    continue;
                }
                $header = $this->purchase_return->select('', [
                    'id' => $return_item->purchase_return_id,
                    'is_deleted' => 0
                ], 1);
                if ($header && $header->status != 'disapproved') {
                    $total_returned_qty += (float) $return_item->qty;
                }
            }

            if (($total_returned_qty + $qty) > (float) $rn_item->qty) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build item insert/update values, filling from receipt_note_item when omitted.
     */
    private function _build_item_values($purchase_return_id, $item, $receipt_note_id = null, $purchase_order_id = null)
    {
        $receipt_note_id = $receipt_note_id ?: $this->payload['receipt_note_id'];
        $purchase_order_id = $purchase_order_id ?: $this->payload['purchase_order_id'];

        $rn_item = $this->receipt_note_item->select('', [
            'id' => $item['receipt_note_item_id'],
            'is_deleted' => 0
        ], 1);

        $pick = function ($key, $fallback = null) use ($item, $rn_item) {
            if (array_key_exists($key, $item) && $item[$key] !== '') {
                return $item[$key];
            }
            if ($rn_item && isset($rn_item->$key) && $rn_item->$key !== '') {
                return $rn_item->$key;
            }
            return $fallback;
        };

        return [
            'purchase_return_id' => $purchase_return_id,
            'receipt_note_id' => $receipt_note_id,
            'receipt_note_item_id' => $item['receipt_note_item_id'],
            'purchase_order_id' => $purchase_order_id,
            'purchase_order_item_id' => $pick('purchase_order_item_id'),
            'account_type_id' => $item['account_type_id'],
            'cost_center_id' => $pick('cost_center_id'),
            'item_id' => $pick('item_id'),
            'qty' => $item['qty'],
            'unit' => $pick('unit'),
            'price' => $pick('price'),
            'amount' => $pick('amount'),
            'total' => $pick('total'),
            'vat' => $pick('vat'),
            'vat_type' => $pick('vat_type'),
            'total_value' => $pick('total_value'),
            'remarks' => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
        ];
    }

    private function _sum_item_amounts($items)
    {
        $total = 0;
        foreach ($items as $item) {
            if (isset($item['amount']) && $item['amount'] !== '') {
                $total += (float) $item['amount'];
            } elseif (isset($item['total']) && $item['total'] !== '') {
                $total += (float) $item['total'];
            }
        }
        return $total;
    }
}
