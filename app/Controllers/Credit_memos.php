<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Credit_memo;
use App\Models\Billing;
use App\Models\Billing_entry;
use App\Models\Account_type;
use App\Models\Branch_master;
use App\Models\User;

class Credit_memos extends MYT_Controller
{
    protected $memo_entries;
    protected $validated_memo_entries;
    protected $request;
    protected $credit_memo;
    protected $billing;
    protected $billing_entry;
    protected $account_type;
    protected $branch_master;
    protected $user;
    protected $helpers = ['text'];
    protected $valid_branch_ids = [1, 2, 3, 4, 7];

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_excel()
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
        $this->credit_memo = new Credit_memo();
        $this->billing = new Billing();
        $this->billing_entry = new Billing_entry();
        $this->account_type = new Account_type();
        $this->branch_master = new Branch_master();
        $this->user = new User();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
        $this->memo_entries = new \App\Models\Credit_memo_entries($this->db);
    }

    /**
     * Get credit_memos
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $credit_memo_id = $this->payload['credit_memo_id'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $billing_id = $this->payload['billing_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $credit_memos = $this->credit_memo->get_details($credit_memo_id, $branch_id, $billing_id, $status, $date_from, $date_to, $page, $data_size) ?: [];
        $size = count($this->credit_memo->get_details($credit_memo_id, $branch_id, $billing_id, $status, $date_from, $date_to, null, null) ?: []);

        if (!$credit_memos) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No credit_memos found.'
            ];
        } else {
            $credit_memos = (new \App\Libraries\Memo_entries_response($this->db))->enrich(
                $credit_memos, $this->memo_entries, 'credit_memo'
            );
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $credit_memos
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create credit_memo
     */
    public function create()
    {
        try {
            if (($response = $this->_api_validation()) !== true) {
                return $response;
            }

            $billing_id = $this->payload['billing_id'] ?? null;
            $branch_id = $this->payload['branch_id'] ?? null;
            $reason = $this->payload['reason'] ?? null;
            $amount = $this->payload['amount'] ?? null;
            $debit_account_type_id = $this->payload['debit_account_type_id'] ?? null;

            if (!$this->db->transBegin()) {
                throw new \RuntimeException('Failed to begin memo transaction.');
            }

            if ($billing_id == null || $billing_id === '') {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Billing ID is required.'
                ];
            } elseif ($branch_id == null || $branch_id === '') {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Branch ID is required.'
                ];
            } elseif ($reason == null || $reason === '') {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Reason is required.'
                ];
            } elseif ($amount == null || $amount === '') {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Amount is required.'
                ];
            } elseif ($debit_account_type_id == null || $debit_account_type_id === '') {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Debit account type ID is required.'
                ];
            } elseif (($validation_error = $this->_validate_billing_branch($billing_id, $branch_id)) !== true) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    $validation_error
                ];
            } elseif (!$this->_account_type_exists($debit_account_type_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Debit account type is not found.'
                ];
            } elseif (!$trade_receivable_id = $this->_get_trade_receivable_account_type_id()) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Trade receivable account type is not found.'
                ];
            } elseif (($validation_error = $this->_validate_memo_entries()) !== true) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [$validation_error];
            } elseif (!$credit_memo_id = $this->_attempt_create($trade_receivable_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to add credit_memo.'
                ];
            } elseif (!$this->_attempt_generate_control_no($credit_memo_id, $branch_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to generate credit_memo control number.'
                ];
            } else {
                if (!$this->db->transStatus() || !$this->db->transCommit()) {
                    throw new \RuntimeException('Failed to save memo.');
                }
                $response_status = SUCCESS;
                $response = [
                    'credit_memo_id' => $credit_memo_id,
                    'message' => 'credit_memo added successfully.'
                ];
            }

            return $this->_create_response($response_status, $response);

        } catch (\Throwable $exception) {
            $this->db->transRollback();
            log_message('error', 'Memo write failed: {message}', ['message' => $exception->getMessage()]);
            return $this->_create_response(FAIL, ['Failed to save memo.']);
        }
    }

    /**
     * Attempt to create a credit_memo
     */
    private function _attempt_create($trade_receivable_id)
    {
        $values = [
            'billing_id' => $this->payload['billing_id'],
            'branch_id' => $this->payload['branch_id'],
            'debit_account_type_id' => $this->payload['debit_account_type_id'],
            'credit_account_type_id' => $trade_receivable_id,
            'date_issued' => date('Y-m-d'),
            'reason' => $this->payload['reason'],
            'amount' => $this->payload['amount'],
            'remarks' => array_key_exists('remarks', $this->payload) && $this->payload['remarks'] != '' ? $this->payload['remarks'] : null,
            'status' => 'pending',
            'added_by' => $this->payload['requester'],
            'added_on' => date('Y-m-d H:i:s'),
        ];

        if (!$credit_memo_id = $this->credit_memo->insert($values)) {
            $this->error_message = $this->credit_memo->error();
            return false;
        }

        if ($this->validated_memo_entries !== null) {
            $this->memo_entries->replace_rows($credit_memo_id, $this->validated_memo_entries, $this->payload['requester']);
        }
        return $credit_memo_id;
    }

    /**
     * Update credit_memo
     */
    public function update()
    {
        try {
            if (($response = $this->_api_validation()) !== true) {
                return $response;
            }

            $credit_memo_id = $this->payload['credit_memo_id'] ?? null;

            if (!$this->db->transBegin()) {
                throw new \RuntimeException('Failed to begin memo transaction.');
            }

            if (!$credit_memo = $this->credit_memo->get_for_update($credit_memo_id)) {
                $this->db->transRollback();
                $response_status = FAIL_NOT_FOUND;
                $response = [
                    'credit_memo is not found.'
                ];
            } elseif ($credit_memo->status != 'pending') {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Cannot update a credit_memo that is not pending.'
                ];
            } elseif (($validation_error = $this->_validate_update_payload($credit_memo)) !== true) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    $validation_error
                ];
            } elseif (($validation_error = $this->_validate_memo_entries($credit_memo)) !== true) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [$validation_error];
            } elseif (!$this->_attempt_update($credit_memo)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Failed to update credit_memo.'
                ];
            } else {
                if (!$this->db->transStatus() || !$this->db->transCommit()) {
                    throw new \RuntimeException('Failed to save memo.');
                }
                $response_status = SUCCESS;
                $response = [
                    'credit_memo_id' => $credit_memo_id,
                    'message' => 'credit_memo updated successfully.'
                ];
            }

            return $this->_create_response($response_status, $response);

        } catch (\Throwable $exception) {
            $this->db->transRollback();
            log_message('error', 'Memo write failed: {message}', ['message' => $exception->getMessage()]);
            return $this->_create_response(FAIL, ['Failed to save memo.']);
        }
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($credit_memo)
    {
        $values = [
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        $updatable = ['billing_id', 'branch_id', 'debit_account_type_id', 'reason', 'amount', 'remarks'];
        foreach ($updatable as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $where = ['id' => $credit_memo->id];

        if (!$this->credit_memo->update($where, $values)) {
            $this->error_message = $this->credit_memo->error();
            return false;
        }
        if (array_key_exists('memo_entries', $this->payload)) {
            $this->memo_entries->replace_rows($credit_memo->id, $this->validated_memo_entries, $this->payload['requester']);
        }
        return true;
    }

    /**
     * Change status credit_memo
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $credit_memo_id = $this->payload['credit_memo_id'] ?? null;
        if (!$this->db->transBegin()) {
            $response_status = FAIL;
            $response = [
                'Failed to change credit_memo status.',
            ];
        } elseif (!$credit_memo = $this->credit_memo->get_for_update($credit_memo_id)) {
            $this->db->transRollback();
            $response_status = $credit_memo === false ? FAIL : FAIL_NOT_FOUND;
            $response = [
                $credit_memo === false ? 'Failed to load credit_memo.' : 'credit_memo is not found.'
            ];
        } elseif (!in_array($status, ['approved', 'disapproved'], true)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Invalid status. Valid statuses are: approved, disapproved.',
            ];
        } elseif ($credit_memo->status != 'pending') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "credit_memo is already $credit_memo->status. ",
            ];
        } elseif (!$this->_attempt_update_status($credit_memo)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change credit_memo status.',
            ];
        } elseif (!$this->_attempt_commit_status()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change credit_memo status.',
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'credit_memo_id' => $credit_memo_id,
                'message' => "credit_memo $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($credit_memo)
    {
        $values = [
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status === 'approved') {
            $values['status'] = 'approved';
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
        } elseif ($status === 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        try {
            if ($status === 'approved' && !$this->billing->get_for_update($credit_memo->billing_id)) {
                $this->error_message = 'Billing is not found.';
                return false;
            }

            $allocations = $status === 'approved'
                ? \App\Libraries\Memo_allocations::approval_rows($this->memo_entries, $credit_memo)
                : [];

            if (!$this->credit_memo->update_pending_status($credit_memo->id, $values)) {
                $this->error_message = $this->credit_memo->error();
                return false;
            }

            if ($status === 'approved' && !$this->billing->apply_memo($credit_memo->billing_id, $credit_memo->amount, 'credit', $this->payload['requester'])) {
                $this->error_message = $this->billing->error();
                return false;
            }
            foreach ($allocations as $allocation) {
                if (!$this->billing_entry->apply_memo_allocation($allocation, 'credit', $this->payload['requester'])) {
                    $this->error_message = 'Failed to apply memo allocation.';
                    return false;
                }
            }
        } catch (\Throwable $error) {
            $this->error_message = $error->getMessage();
            return false;
        }

        return true;
    }

    protected function _attempt_commit_status()
    {
        try {
            return $this->db->transStatus() && $this->db->transCommit();
        } catch (\Throwable $error) {
            $this->error_message = $error->getMessage();
            return false;
        }
    }

    /**
     * Delete credit_memo
     */
    public function delete($id = null)
    {
        try {
            if (($response = $this->_api_validation()) !== true) {
                return $response;
            }

            $credit_memo_id = $this->payload['credit_memo_id'] ?? null;

            if (!$this->db->transBegin()) {
                throw new \RuntimeException('Failed to begin memo transaction.');
            }

            if (!$credit_memo = $this->credit_memo->get_for_update($credit_memo_id)) {
                $this->db->transRollback();
                $response_status = FAIL_NOT_FOUND;
                $response = [
                    'credit_memo is not found.',
                ];
            } elseif ($credit_memo->status != 'pending') {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'Cannot delete a credit_memo that is not pending.',
                ];
            } elseif (!$this->_attempt_delete($credit_memo_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    'credit_memo is not deleted.'
                ];
            } else {
                if (!$this->db->transStatus() || !$this->db->transCommit()) {
                    throw new \RuntimeException('Failed to save memo.');
                }
                $response_status = SUCCESS;
                $response = [
                    'message' => 'credit_memo is deleted.'
                ];
            }

            return $this->_create_response($response_status, $response);

        } catch (\Throwable $exception) {
            $this->db->transRollback();
            log_message('error', 'Memo write failed: {message}', ['message' => $exception->getMessage()]);
            return $this->_create_response(FAIL, ['Failed to save memo.']);
        }
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($credit_memo_id)
    {
        $where = ['id' => $credit_memo_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->credit_memo->update($where, $values)) {
            $this->error_message = $this->credit_memo->error();
            return false;
        }

        $this->memo_entries->retire($credit_memo_id, $this->payload['requester']);
        return true;
    }

    /**
     * Generate control number: CM + padded branch code + YYYYMMDD + 6-digit yearly series
     */
    protected function _attempt_generate_control_no($credit_memo_id, $branch_id)
    {
        $branch = $this->branch_master->select('', ['id' => $branch_id, 'is_deleted' => 0], 1);
        $branch_code = $branch ? str_pad($branch->code, 2, '0', STR_PAD_LEFT) : '00';

        $year = date('Y');
        $month = date('m');
        $day = date('d');

        $last = $this->credit_memo->get_last_control_no($branch_code, $year);

        if ($last && !empty($last['control_no'])) {
            $last_series = substr($last['control_no'], -6);
            $next_series = (int) $last_series + 1;
        } else {
            $next_series = 1;
        }

        $control_no = 'CM' . $branch_code . $year . $month . $day . str_pad($next_series, 6, '0', STR_PAD_LEFT);

        $values = [
            'control_no' => $control_no,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->credit_memo->update(['id' => $credit_memo_id], $values)) {
            $this->error_message = $this->credit_memo->error();
            return false;
        }

        return true;
    }

    /**
     * Validate billing exists and has a billing_entry for the given branch.
     */
    protected function _validate_billing_branch($billing_id, $branch_id)
    {
        if (!in_array((int) $branch_id, $this->valid_branch_ids, true)) {
            return 'Invalid Branch ID.';
        }

        if (!$this->billing->select('', ['id' => $billing_id, 'is_deleted' => 0], 1)) {
            return 'Billing is not found.';
        }

        if (!$this->billing_entry->select('', [
            'billing_id' => $billing_id,
            'branch_id' => $branch_id,
            'is_deleted' => 0
        ], 1)) {
            return 'Billing does not belong to the given branch.';
        }

        return true;
    }

    /**
     * Validate fields on update when they are present or when billing/branch/debit change.
     */
    protected function _validate_update_payload($credit_memo)
    {
        $billing_id = array_key_exists('billing_id', $this->payload) && $this->payload['billing_id'] != ''
            ? $this->payload['billing_id']
            : $credit_memo->billing_id;
        $branch_id = array_key_exists('branch_id', $this->payload) && $this->payload['branch_id'] != ''
            ? $this->payload['branch_id']
            : $credit_memo->branch_id;

        if (($validation_error = $this->_validate_billing_branch($billing_id, $branch_id)) !== true) {
            return $validation_error;
        }

        if (array_key_exists('debit_account_type_id', $this->payload) && $this->payload['debit_account_type_id'] != '') {
            if (!$this->_account_type_exists($this->payload['debit_account_type_id'])) {
                return 'Debit account type is not found.';
            }
        }

        if (array_key_exists('reason', $this->payload) && $this->payload['reason'] === '') {
            return 'Reason is required.';
        }

        if (array_key_exists('amount', $this->payload) && $this->payload['amount'] === '') {
            return 'Amount is required.';
        }

        return true;
    }

    protected function _account_type_exists($account_type_id)
    {
        return (bool) $this->account_type->select('', ['id' => $account_type_id, 'is_deleted' => 0], 1);
    }

    protected function _get_trade_receivable_account_type_id()
    {
        $sql = <<<EOT
SELECT id
FROM account_type
WHERE code = ?
AND parent_id IS NULL
AND is_deleted = 0
LIMIT 1
EOT;
        $query = $this->db->query($sql, ['102-113']);
        $row = $query ? $query->getRow() : null;
        return $row ? $row->id : null;
    }

    protected function _validate_memo_entries($memo = null)
    {
        $this->validated_memo_entries = null;
        $supplied = array_key_exists('memo_entries', $this->payload);
        $rows = $supplied ? $this->payload['memo_entries'] : ($memo ? $this->memo_entries->active_rows($memo->id) : []);
        if (!$supplied && !$rows) {
            return true;
        }
        try {
            $this->validated_memo_entries = \App\Libraries\Memo_allocations::validate(
                $this->memo_entries, $rows,
                array_key_exists('amount', $this->payload) ? $this->payload['amount'] : $memo->amount,
                array_key_exists('billing_id', $this->payload) ? $this->payload['billing_id'] : $memo->billing_id,
                array_key_exists('branch_id', $this->payload) ? $this->payload['branch_id'] : $memo->branch_id
            );
            return true;
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
    }
}
