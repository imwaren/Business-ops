<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Request;
use App\Models\Account_type;

class Depreciation_entries extends MYT_Controller
{
    protected $request;
    protected $depreciation_entry;
    protected $depreciation_entry_item;
    protected $accounts_payable_voucher;
    protected $receipt_note;
    protected $receipt_note_item;
    protected $receipt_note_additional_item;
    protected $asset;
    protected $account_type;
    protected $user;
    protected $request_model;
    protected $validation_error;
    protected $helpers = ['text'];

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_asset_depreciation_report_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

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
        $this->user = new User();
        $this->request_model = new Request();
        $this->account_type = new Account_type();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $dbMap = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->depreciation_entry = new \App\Models\Depreciation_entry($db);
        $this->depreciation_entry_item = new \App\Models\Depreciation_entry_item($db);
        $this->accounts_payable_voucher = new \App\Models\Accounts_payable_voucher($db);
        $this->receipt_note = new \App\Models\Receipt_note($db);
        $this->receipt_note_item = new \App\Models\Receipt_note_item($db);
        $this->receipt_note_additional_item = new \App\Models\Receipt_note_additional_item($db);
        $this->asset = new \App\Models\Asset($db);

        return true;
    }

    /**
     * Get depreciation_entries
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

        $depreciation_entry_id = $this->payload['depreciation_entry_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $depreciation_entries = $this->depreciation_entry->get_details($depreciation_entry_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->depreciation_entry->get_details($depreciation_entry_id, $status, $term, null, null)?:[]);

        if(!$depreciation_entries) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No depreciation_entries found.'
            ];
        } else {
            foreach ($depreciation_entries as $key => $depreciation_entry) {
                $depreciation_entries[$key]['items'] = $this->depreciation_entry_item->get_details($depreciation_entry['id']) ?: [];
                if ($depreciation_entry['accounts_payable_voucher_type'] == 'receipt_note') {
                    $depreciation_entries[$key]['receipt_note'] = $this->receipt_note->get_details($depreciation_entry['accounts_payable_voucher_type_id']) ?: [];
                    $depreciation_entries[$key]['receipt_note_items'] = $this->receipt_note_item->get_details($depreciation_entry['accounts_payable_voucher_type_id']) ?: [];
                    $depreciation_entries[$key]['receipt_note_additional_items'] = $this->receipt_note_additional_item->get_details($depreciation_entry['accounts_payable_voucher_type_id']) ?: [];
                }
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $depreciation_entries
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Manager view: one row per depreciation_entry_item with computed depreciation columns.
     */
    public function get_asset_depreciation_report()
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

        $as_of_date = $this->payload['as_of_date'] ?? date('Y-m-d');
        if (!$this->_is_valid_date($as_of_date)) {
            return $this->_create_response(
                FAIL,
                ['As of date must be a valid date (Y-m-d).']
            );
        }

        $depreciation_entry_id = $this->payload['depreciation_entry_id'] ?? null;
        $asset_id = $this->payload['asset_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $rows = $this->depreciation_entry_item->get_asset_depreciation_report(
            $as_of_date,
            $depreciation_entry_id,
            $asset_id,
            $status,
            $term,
            $page,
            $data_size
        ) ?: [];
        $size = count($this->depreciation_entry_item->get_asset_depreciation_report(
            $as_of_date,
            $depreciation_entry_id,
            $asset_id,
            $status,
            $term,
            null,
            null
        ) ?: []);

        if (!$rows) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No asset depreciation report found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'as_of_date' => $as_of_date,
                'data' => $rows
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create depreciation_entry
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

        if (($validation_error = $this->_validate_accounts_payable_voucher()) !== true) {
            return $this->_create_response(FAIL, [$validation_error]);
        }

        if (array_key_exists('items', $this->payload) && is_array($this->payload['items'])) {
            foreach ($this->payload['items'] as $item) {
                if (($validation_error = $this->_validate_item($item)) !== true) {
                    return $this->_create_response(FAIL, [$validation_error]);
                }
            }
        }

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$depreciation_entry_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add depreciation_entry. ",
            ];
        } elseif (!$this->_attempt_create_items($depreciation_entry_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $this->validation_error ?: "Failed to add depreciation_entry details. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'depreciation_entry_id' => $depreciation_entry_id,
                'message'    => 'depreciation_entry added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a depreciation_entry
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->depreciation_entry->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        if (!$depreciation_entry_id = $this->depreciation_entry->insert($values)) {
            $this->error_message = $this->depreciation_entry->error();
            return false;
        }

        return $depreciation_entry_id;
    }

    /**
     * Attempt to create depreciation_entry details
     */
    private function _attempt_create_items($depreciation_entry_id)
    {
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return true;
        }

        $total_debit = 0;
        $total_credit = 0;

        foreach ($this->payload['items'] as $item) {
            $values = $this->_build_item_values($item, $depreciation_entry_id);
            if ($values === false) {
                return false;
            }

            $values['added_by'] = $this->payload['requester'];
            $values['added_on'] = date('Y-m-d H:i:s');

            if (!$this->depreciation_entry_item->insert($values)) {
                $this->error_message = $this->depreciation_entry_item->error();
                return false;
            }

            $total_debit += $values['debit'];
            $total_credit += $values['credit'];
        }

        if (!$this->depreciation_entry->update(['id' => $depreciation_entry_id], ['total_debit' => $total_debit, 'total_credit' => $total_credit])) {
            $this->error_message = $this->depreciation_entry->error();
            return false;
        }

        return true;
    }

    /**
     * Update depreciation_entry
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

        if (($validation_error = $this->_validate_accounts_payable_voucher()) !== true) {
            return $this->_create_response(FAIL, [$validation_error]);
        }

        if (array_key_exists('items', $this->payload) && is_array($this->payload['items'])) {
            foreach ($this->payload['items'] as $item) {
                if (($validation_error = $this->_validate_item($item)) !== true) {
                    return $this->_create_response(FAIL, [$validation_error]);
                }
            }
        }

        $status = $this->payload['status'] ?? null;
        $depreciation_entry_id = $this->payload['depreciation_entry_id'];
        $where = [
                    'id' => $depreciation_entry_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$depreciation_entry = $this->depreciation_entry->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'depreciation_entry is not found.'
            ];
        //validate status
        } elseif ($status && $depreciation_entry->status == 'approved') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Cannot update an approved depreciation_entry. ",
            ];
        } elseif (!$this->_attempt_update($depreciation_entry)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update depreciation_entry.',
            ];
        } elseif (!$this->_attempt_update_items($depreciation_entry->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $this->validation_error ?: "Failed to update depreciation_entry details. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'depreciation_entry_id' => $depreciation_entry_id,
                'message'    => 'depreciation_entry updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($depreciation_entry)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        foreach($this->depreciation_entry->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $where = ['id' => $depreciation_entry->id];

        if (!$this->depreciation_entry->update($where, $values)) {
            $this->error_message = $this->depreciation_entry->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update depreciation_entry details
     */
    private function _attempt_update_items($depreciation_entry_id)
    {
        // 1. Get all current detail IDs for this depreciation_entry
        $existing_items = $this->depreciation_entry_item->select('id', ['depreciation_entry_id' => $depreciation_entry_id, 'is_deleted' => 0]);

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
                if (!$this->depreciation_entry_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->depreciation_entry_item->error();
                    return false;
                }
            }
        }

        // 4. Process payload details (update existing or insert new)
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items'])) {
            return true;
        }

        $total_debit = 0;
        $total_credit = 0;

        foreach ($this->payload['items'] as $item) {
            $existing = null;
            if (!empty($item['id'])) {
                $existing = $this->depreciation_entry_item->select('', ['id' => $item['id'], 'is_deleted' => 0], 1);
            }

            $values = $this->_build_item_values($item, $depreciation_entry_id, $existing);
            if ($values === false) {
                return false;
            }

            if (!empty($item['id'])) {
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->depreciation_entry_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->depreciation_entry_item->error();
                    return false;
                }
            } else {
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->depreciation_entry_item->insert($values)) {
                    $this->error_message = $this->depreciation_entry_item->error();
                    return false;
                }
            }

            $total_debit += $values['debit'];
            $total_credit += $values['credit'];
        }

        if (!$this->depreciation_entry->update(['id' => $depreciation_entry_id], ['total_debit' => $total_debit, 'total_credit' => $total_credit])) {
            $this->error_message = $this->depreciation_entry->error();
            return false;
        }

        return true;
    }

    /**
     * Change status depreciation_entry
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
        $depreciation_entry_id = $this->payload['depreciation_entry_id'];
        $where = [
                    'id' => $depreciation_entry_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$depreciation_entry = $this->depreciation_entry->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'depreciation_entry is not found.'
            ];
        //validate status
        } elseif ($status && $depreciation_entry->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Depreciation_entry is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($depreciation_entry, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change depreciation_entry status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'depreciation_entry_id' => $depreciation_entry_id,
                'message'    => "depreciation_entry status $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($depreciation_entry, $role_id)
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
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $depreciation_entry->id];

        if (!$this->depreciation_entry->update($where, $values)) {
            $this->error_message = $this->depreciation_entry->error();
            return false;
        }
        return true;
    }

    /**
     * Delete depreciation_entries
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
        $depreciation_entry_id = $this->payload['depreciation_entry_id'];

        $where = ['id' => $depreciation_entry_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$depreciation_entry = $this->depreciation_entry->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'depreciation_entry is not found.',
            ];
        } elseif (!$this->_attempt_delete($depreciation_entry_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'depreciation_entry is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'depreciation_entry is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($depreciation_entry_id)
    {
        $where = ['id' => $depreciation_entry_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->depreciation_entry->update($where, $values)) {
            $this->error_message = $this->depreciation_entry->error();
            return false;
        }

        return true;
    }

    /**
     * Validate optional accounts_payable_voucher_id on the branch DB.
     */
    protected function _validate_accounts_payable_voucher()
    {
        if (!array_key_exists('accounts_payable_voucher_id', $this->payload)) {
            return true;
        }

        $accounts_payable_voucher_id = $this->payload['accounts_payable_voucher_id'];
        if ($accounts_payable_voucher_id === null || $accounts_payable_voucher_id === '') {
            return true;
        }

        if (!$this->accounts_payable_voucher->select('', ['id' => $accounts_payable_voucher_id, 'is_deleted' => 0], 1)) {
            return 'Accounts payable voucher is not found.';
        }

        return true;
    }

    /**
     * Validate item fields when present.
     */
    protected function _validate_item($item)
    {
        $asset_id = $item['asset_id'] ?? null;
        if ($asset_id === '' || $asset_id === null) {
            $asset_id = null;
            if (!empty($item['id']) && $this->depreciation_entry_item) {
                $existing = $this->depreciation_entry_item->select('', ['id' => $item['id'], 'is_deleted' => 0], 1);
                if ($existing && !empty($existing->asset_id)) {
                    $asset_id = $existing->asset_id;
                }
            }
        }

        if ($asset_id) {
            if (!$this->asset || !$this->asset->select('', ['id' => $asset_id, 'is_deleted' => 0], 1)) {
                return 'Asset is not found.';
            }
        } else {
            $name = isset($item['name']) ? trim((string) $item['name']) : '';
            $item_id = $item['item_id'] ?? null;
            if ($name === '') {
                return 'Asset name is required when asset_id is not provided.';
            }
            if ($item_id === null || $item_id === '') {
                return 'item_id is required when asset_id is not provided.';
            }
        }

        if (array_key_exists('useful_life_months', $item) && $item['useful_life_months'] !== '' && $item['useful_life_months'] !== null) {
            if (!preg_match('/^\d+$/', (string) $item['useful_life_months']) || (int) $item['useful_life_months'] < 1) {
                return 'Useful life months must be a positive integer.';
            }
        }

        if (array_key_exists('start_date', $item) && $item['start_date'] !== '' && $item['start_date'] !== null) {
            if (!$this->_is_valid_date($item['start_date'])) {
                return 'Start date must be a valid date (Y-m-d).';
            }
        }

        if (array_key_exists('debit_account_type_id', $item) && $item['debit_account_type_id'] !== '' && $item['debit_account_type_id'] !== null) {
            if (!$this->_account_type_exists($item['debit_account_type_id'])) {
                return 'Debit account type is not found.';
            }
        }

        if (array_key_exists('credit_account_type_id', $item) && $item['credit_account_type_id'] !== '' && $item['credit_account_type_id'] !== null) {
            if (!$this->_account_type_exists($item['credit_account_type_id'])) {
                return 'Credit account type is not found.';
            }
        }

        if (array_key_exists('amount', $item) && $item['amount'] !== '' && $item['amount'] !== null) {
            if (!is_numeric($item['amount']) || (float) $item['amount'] < 0) {
                return 'Amount must be a non-negative number.';
            }
        }

        return true;
    }

    /**
     * Build item row values including computed end_date.
     */
    protected function _build_item_values($item, $depreciation_entry_id, $existing = null)
    {
        $asset_id = $this->_ensure_asset_id($item, $existing);
        if ($asset_id === false) {
            return false;
        }
        $item['asset_id'] = $asset_id;

        $start_date = $this->_item_field_value($item, 'start_date', $existing);
        $useful_life_months = $this->_item_field_value($item, 'useful_life_months', $existing);

        $debit = array_key_exists('debit', $item) && $item['debit'] != '' ? $item['debit'] : ($existing->debit ?? 0);
        $credit = array_key_exists('credit', $item) && $item['credit'] != '' ? $item['credit'] : ($existing->credit ?? 0);
        $amount = $this->_item_field_value($item, 'amount', $existing);

        if (($debit === 0 || $debit === '0' || $debit === null || $debit === '')
            && ($credit === 0 || $credit === '0' || $credit === null || $credit === '')
            && $amount !== null && $amount !== '') {
            $debit = $amount;
            $credit = $amount;
        }

        $values = [
            'depreciation_entry_id' => $depreciation_entry_id,
            'asset_id' => $this->_item_field_value($item, 'asset_id', $existing),
            'cost_center_id' => $this->_item_field_value($item, 'cost_center_id', $existing),
            'debit' => $debit != '' ? $debit : 0,
            'credit' => $credit != '' ? $credit : 0,
            'remarks' => $this->_item_field_value($item, 'remarks', $existing),
            'useful_life_months' => $useful_life_months,
            'start_date' => $start_date,
            'end_date' => $this->_compute_end_date($start_date, $useful_life_months),
            'debit_account_type_id' => $this->_item_field_value($item, 'debit_account_type_id', $existing),
            'credit_account_type_id' => $this->_item_field_value($item, 'credit_account_type_id', $existing),
            'amount' => $amount,
            'serial_number' => $this->_item_field_value($item, 'serial_number', $existing),
            'asset_tag_number' => $this->_item_field_value($item, 'asset_tag_number', $existing),
        ];

        return $values;
    }

    /**
     * Use payload asset_id, reuse a branch asset by item_id, or insert on this clinic DB only.
     */
    protected function _ensure_asset_id($item, $existing = null)
    {
        $asset_id = $this->_item_field_value($item, 'asset_id', $existing);
        if ($asset_id) {
            return $asset_id;
        }

        $item_id = $this->_item_field_value($item, 'item_id', $existing);
        if ($item_id && $this->asset) {
            $found = $this->asset->select('', ['item_id' => $item_id, 'is_deleted' => 0], 1);
            if ($found) {
                return (int) $found->id;
            }
        }

        $name = isset($item['name']) ? trim((string) $item['name']) : '';
        if ($name === '' || $item_id === null || $item_id === '') {
            $this->validation_error = 'Asset name and item_id are required when asset_id is not provided.';
            return false;
        }

        $useful_life_months = $this->_item_field_value($item, 'useful_life_months', $existing);
        $start_date = $this->_item_field_value($item, 'start_date', $existing);
        $amount = $this->_item_field_value($item, 'amount', $existing);
        $cost_center_id = $this->_item_field_value($item, 'cost_center_id', $existing)
            ?: ($this->payload['cost_center_id'] ?? null);

        $values = [
            'name' => $name,
            'item_id' => $item_id,
            'asset_group_id' => 2, //default lab machineries
            'cost_center_id' => $cost_center_id,
            'cost' => $amount !== null && $amount !== '' ? $amount : null,
            'useful_life_years' => $useful_life_months ? (int) floor(((int) $useful_life_months) / 12) : null,
            'as_of' => $start_date ?: null,
            'added_by' => $this->payload['requester'] ?? null,
            'added_on' => date('Y-m-d H:i:s'),
        ];

        $asset_id = $this->asset->insert($values);
        if (!$asset_id) {
            $this->validation_error = $this->asset->error() ?: 'Failed to add asset.';
            return false;
        }

        return (int) $asset_id;
    }

    protected function _item_field_value($item, $field, $existing = null)
    {
        if (array_key_exists($field, $item)) {
            return $item[$field] != '' ? $item[$field] : null;
        }

        if ($existing && isset($existing->$field)) {
            return $existing->$field != '' ? $existing->$field : null;
        }

        return null;
    }

    protected function _compute_end_date($start_date, $useful_life_months)
    {
        if (!$start_date || !$useful_life_months) {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $start_date);
        if (!$dt) {
            return null;
        }

        $dt->modify('+' . (int) $useful_life_months . ' months');
        return $dt->format('Y-m-d');
    }

    protected function _is_valid_date($date)
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }

    protected function _account_type_exists($account_type_id)
    {
        return (bool) $this->account_type->select('', ['id' => $account_type_id, 'is_deleted' => 0], 1);
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
