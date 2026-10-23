<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Request;

class Journal_entries extends MYT_Controller
{
    protected $request;
    protected $journal_entry;
    protected $journal_entry_item;
    protected $user;
    protected $request_model;
    protected $helpers = ['text'];
    private $journal_router;
    private bool $write_succeeded = false;

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
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $group = $this->journal_router()->group($branch_id);
        if ($group === null) { return false; }
        $db = db_connect($group);
        $this->db = $db;
        $this->journal_entry = new \App\Models\Journal_entry($db);
        $this->journal_entry_item = new \App\Models\Journal_entry_item($db);
        if ($group === 'master') {
            $this->journal_entry->with_journal_cost_center($branch_id);
            $this->journal_entry_item->with_journal_cost_center($branch_id);
        }

        return true;
    }

    private function journal_router(): \App\Libraries\Fund_cost_center
    {
        return $this->journal_router ??= new \App\Libraries\Fund_cost_center();
    }

    public function create() { return $this->_run_write('create'); }
    public function update() { return $this->_run_write('update'); }
    public function change_status() { return $this->_run_write('change_status'); }
    public function delete($id = null) { return $this->_run_write('delete'); }

    private function _run_write(string $action)
    {
        if (($response = $this->_api_validation()) !== true) { return $response; }
        $center = $this->payload['cost_center_id'] ?? null;
        if ((!is_int($center) && !is_string($center)) || !ctype_digit((string) $center)
            || !$this->resolve_branch_models((int) $center)) {
            return $this->_create_response(FAIL, ['Invalid Cost Center ID.']);
        }
        $this->write_succeeded = false;
        $this->db->transBegin();
        try {
            if ($action !== 'create' && !$this->journal_entry->lock_for_write((int) ($this->payload['journal_entry_id'] ?? 0))) {
                $this->db->transRollback();
                return $this->_create_response(FAIL_NOT_FOUND, ['journal_entry is not found.']);
            }
            $method = '_write_' . $action;
            $response = $this->$method();
            if ($this->db->transStatus() === false) { throw new \RuntimeException('Journal transaction failed.'); }
            if (!$this->write_succeeded) {
                $this->db->transRollback();
                return $response;
            }
            if (!$this->db->transCommit()) { throw new \RuntimeException('Journal commit failed.'); }
            return $response;
        } catch (\Throwable $error) {
            $this->db->transRollback();
            log_message('error', 'Journal entry save failed: ' . $error->getMessage());
            return $this->_create_response(FAIL, ['Failed to save journal entry. No changes were committed.']);
        }
    }

    /**
     * Get journal_entries
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? $this->payload['cost_center_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '') {
            if ((!is_int($branch_id) && !is_string($branch_id)) || !preg_match('/^\d+$/', (string) $branch_id)) {
                return $this->_create_response(FAIL, ['Invalid Cost Center ID.']);
            }
            $branch_id = (int) $branch_id;
            if ($branch_id !== 0 && $this->journal_router()->group($branch_id) === null) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Cost Center ID.']
                );
            }
        } else {
            $branch_id = null;
        }

        $journal_db = $branch_id !== null ? \Config\Database::connect('master') : null;
        $this->journal_entry = new \App\Models\Journal_entry($journal_db);

        $journal_entry_id = $this->payload['journal_entry_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $date = $this->payload['date'] ?? null;
        if (is_array($date_from)) {
            $date_from = end($date_from);
        }
        if (is_array($date_to)) {
            $date_to = end($date_to);
        }
        if (is_array($date)) {
            $date = end($date);
        }
        if ($date_from === '') {
            $date_from = null;
        }
        if ($date_to === '') {
            $date_to = null;
        }
        if ($date === '') {
            $date = null;
        }
        if (!$date_from && !$date_to && $date) {
            $date_from = $date;
            $date_to = $date;
        }

        if (!$journal_entry_id) {
            if (!$page) {
                $page = 1;
            }
            if (!$data_size) {
                $data_size = 50;
            }
        }

        $journal_entries = $this->journal_entry->get_details(
            $journal_entry_id,
            $status,
            $term,
            $page,
            $data_size,
            $branch_id,
            $date_from,
            $date_to
        ) ?: [];
        $size = $journal_entry_id
            ? count($journal_entries)
            : $this->journal_entry->get_details_count(
                $journal_entry_id,
                $status,
                $term,
                $branch_id,
                $date_from,
                $date_to
            );

        if (!$journal_entries) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No journal_entries found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $journal_entries
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create journal_entry
     */
    private function _write_create()
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


        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$journal_entry_id = $this->_attempt_create()) {
            $response_status = FAIL;
            $response = [
                "Failed to add journal_entry. ",
            ];
        } elseif (!$this->_attempt_create_items($journal_entry_id)) {
            $response_status = FAIL;
            $response = [
                "Failed to add journal_entry details. ",
            ];
        } else {
            $this->write_succeeded = true;
            $response_status = SUCCESS;
            $response = [
                'journal_entry_id' => $journal_entry_id,
                'message'    => 'journal_entry added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a journal_entry
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->journal_entry->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$journal_entry_id = $this->journal_entry->insert($values)) {
            $this->error_message = $this->journal_entry->error();
            return false;
        }

        return $journal_entry_id;
    }

    /**
     * Attempt to create journal_entry details
     */
    private function _attempt_create_items($journal_entry_id)
    {
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return true;
        }

        $total_debit = 0;
        $total_credit = 0;

        foreach ($this->payload['items'] as $item) {
            $values = [
                'journal_entry_id'    => $journal_entry_id,
                'capital_expenditure_id'   => array_key_exists('capital_expenditure_id', $item) && $item['capital_expenditure_id'] != '' ? $item['capital_expenditure_id'] : null,
                'account_type_id' => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'debit' => array_key_exists('debit', $item) && $item['debit'] != '' ? $item['debit'] : 0,
                'credit' => array_key_exists('credit', $item) && $item['credit'] != '' ? $item['credit'] : 0,
                'remarks' => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
                'type' => array_key_exists('type', $item) && $item['type'] != '' ? $item['type'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->journal_entry_item->insert($values)) {
                $this->error_message = $this->journal_entry_item->error();
                return false;
            }

            $total_debit += $values['debit'];
            $total_credit += $values['credit'];
        }

        if (!$this->journal_entry->update(['id' => $journal_entry_id], ['total_debit' => $total_debit, 'total_credit' => $total_credit])) {
            $this->error_message = $this->journal_entry->error();
            return false;
        }

        return true;
    }

    /**
     * Update journal_entry
     */
    private function _write_update()
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
        $journal_entry_id = $this->payload['journal_entry_id'];
        $where = [
                    'id' => $journal_entry_id, 
                    'is_deleted' => 0
                ];


        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$journal_entry = $this->journal_entry->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'journal_entry is not found.'
            ];
        //validate status
        } elseif ($status && $journal_entry->status == 'approved') {
            $response_status = FAIL;
            $response = [
                "Cannot update an approved journal_entry. ",
            ];
        } elseif (!$this->_attempt_update($journal_entry)) {
            $response_status = FAIL;
            $response = [
                'Failed to update journal_entry.',
            ];
        } elseif (!$this->_attempt_update_items($journal_entry->id)) {
            $response_status = FAIL;
            $response = [
                "Failed to update journal_entry details. ",
            ];
        } else {
            $this->write_succeeded = true;
            $response_status = SUCCESS;
            $response = [
                'journal_entry_id' => $journal_entry_id,
                'message'    => 'journal_entry updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($journal_entry)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->journal_entry->allowedFields is declared at the top of the model
        foreach($this->journal_entry->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $journal_entry->id];

        if (!$this->journal_entry->update($where, $values)) {
            $this->error_message = $this->journal_entry->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update journal_entry details
     */
    private function _attempt_update_items($journal_entry_id)
    {
        $this->journal_entry_item->with_journal_parent((int) $journal_entry_id);
        // 1. Get all current detail IDs for this journal_entry
        $existing_items = $this->journal_entry_item->select('id', ['journal_entry_id' => $journal_entry_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_items, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('items', $this->payload) && is_array($this->payload['items'])) {
            foreach ($this->payload['items'] as $item) {
                if (!empty($item['id'])) {
                    if (!ctype_digit((string) $item['id']) || !in_array((int) $item['id'], array_map('intval', $existing_ids), true)) {
                        return false;
                    }
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
                if (!$this->journal_entry_item->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->journal_entry_item->error();
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
            $values = [
                'journal_entry_id'  => $journal_entry_id,
                'capital_expenditure_id'   => array_key_exists('capital_expenditure_id', $item) && $item['capital_expenditure_id'] != '' ? $item['capital_expenditure_id'] : null,
                'account_type_id' => array_key_exists('account_type_id', $item) && $item['account_type_id'] != '' ? $item['account_type_id'] : null,
                'debit' => array_key_exists('debit', $item) && $item['debit'] != '' ? $item['debit'] : 0,
                'credit' => array_key_exists('credit', $item) && $item['credit'] != '' ? $item['credit'] : 0,
                'remarks' => array_key_exists('remarks', $item) && $item['remarks'] != '' ? $item['remarks'] : null,
                'type' => array_key_exists('type', $item) && $item['type'] != '' ? $item['type'] : null,
            ];

            if (!empty($item['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->journal_entry_item->update(['id' => $item['id']], $values)) {
                    $this->error_message = $this->journal_entry_item->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->journal_entry_item->insert($values)) {
                    $this->error_message = $this->journal_entry_item->error();
                    return false;
                }
            }

            $total_debit += $values['debit'];
            $total_credit += $values['credit'];
        }

        if (!$this->journal_entry->update(['id' => $journal_entry_id], ['total_debit' => $total_debit, 'total_credit' => $total_credit])) {
            $this->error_message = $this->journal_entry->error();
            return false;
        }

        return true;
    }

    /**
     * Change status journal_entry
     */
    private function _write_change_status()
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
        $journal_entry_id = $this->payload['journal_entry_id'];
        $where = [
                    'id' => $journal_entry_id, 
                    'is_deleted' => 0
                ];


        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$journal_entry = $this->journal_entry->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'journal_entry is not found.'
            ];
        } elseif (!in_array($user->role_id, [27,23,26,4])) {
            $response_status = FAIL;
            $response = [
                "Only General Accountant, Finance Manager, Finance Director or Admin users can change journal_entry status. ",
            ];
        } elseif ($user->role_id == 23 && $status == 'approved' && $journal_entry->approved_by_general_accountant == null) {
            $response_status = FAIL;
            $response = [
                "Finance Manager cannot approve a journal_entry that is not yet approved by General Accountant. ",
            ];
        } elseif ($user->role_id == 26 && $status == 'approved' && $journal_entry->approved_by_finance_manager == null) {
            $response_status = FAIL;
            $response = [
                "Finance Director cannot approve a journal_entry that is not yet approved by Finance Manager. ",
            ];
        //validate status
        } elseif ($status && $journal_entry->status == $status) {
            $response_status = FAIL;
            $response = [
                "Journal_entry is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($journal_entry, $user->role_id)) {
            $response_status = FAIL;
            $response = [
                'Failed to change journal_entry status.',
            ];
        } else {
            $this->write_succeeded = true;
            $response_status = SUCCESS;
            $response = [
                'journal_entry_id' => $journal_entry_id,
                'message'    => ($user->role_id == 27 && $status == 'approved') ? "journal_entry approved by Finance Manager, General Accountant still needs to approve" : (($user->role_id == 23 && $status == 'approved') ? "journal_entry approved by Finance Director, Finance Manager still needs to approve" : "journal_entry $status successfully.")
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($journal_entry, $role_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'approved') {
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');

            if ($role_id == 27) {
                $values['approved_by_general_accountant'] = $this->payload['requester'];
                $values['approved_on_general_accountant'] = date('Y-m-d H:i:s');
            } elseif ($role_id == 23) {
                $values['approved_by_finance_manager'] = $this->payload['requester'];
                $values['approved_on_finance_manager'] = date('Y-m-d H:i:s');
            } elseif ($role_id == 26) {
                $values['approved_by_finance_director'] = $this->payload['requester'];
                $values['approved_on_finance_director'] = date('Y-m-d H:i:s');
            } elseif ($role_id == 4) { // Admin
                if ($journal_entry->approved_by_general_accountant == null) {
                    $values['approved_by_general_accountant'] = $this->payload['requester'];
                    $values['approved_on_general_accountant'] = date('Y-m-d H:i:s');
                    // Admin acting as General Accountant
                } elseif ($journal_entry->approved_by_finance_manager == null) {
                    $values['approved_by_finance_manager'] = $this->payload['requester'];
                    $values['approved_on_finance_manager'] = date('Y-m-d H:i:s');
                    // Admin acting as Finance Manager
                } elseif ($journal_entry->approved_by_finance_director == null) {
                    $values['approved_by_finance_director'] = $this->payload['requester'];
                    $values['approved_on_finance_director'] = date('Y-m-d H:i:s');
                    $values['status'] = 'approved';
                    // Admin acting as Finance Director
                }
            } else {
                $values['status'] = 'approved';
            }
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $journal_entry->id];

        if (!$this->journal_entry->update($where, $values)) {
            $this->error_message = $this->journal_entry->error();
            return false;
        }
        return true;
    }

    /**
     * Delete journal_entries
     */
    private function _write_delete($id = null)
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
        $journal_entry_id = $this->payload['journal_entry_id'];

        $where = ['id' => $journal_entry_id, 'is_deleted' => 0];


        if (!$journal_entry = $this->journal_entry->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'journal_entry is not found.',
            ];
        } elseif (!$this->_attempt_delete($journal_entry_id)) {
            $response_status = FAIL;
            $response = [
                'journal_entry is not deleted.'
            ];
        } else {
            $this->write_succeeded = true;
            $response_status = SUCCESS;
            $response = [
                'message' => 'journal_entry is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($journal_entry_id)
    {
        $where = ['id' => $journal_entry_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->journal_entry->update($where, $values)) {
            $this->error_message = $this->journal_entry->error();
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
