<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;

class Bank_service_fees extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $bank_service_fee;
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
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
        $this->resolve_branch_models(0);
    }

    /**
     * Bind bank_service_fee to a database connection.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $dbMap = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);
        $this->bank_service_fee = new \App\Models\Bank_service_fee($db);

        return true;
    }

    /**
     * Get bank_service_fees from master only.
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->resolve_branch_models(0);

        $bank_service_fee_id = $this->payload['bank_service_fee_id'] ?? null;
        $bank_id = $this->payload['bank_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $fees = $this->bank_service_fee->get_details($bank_service_fee_id, $bank_id, $page, $data_size) ?: [];
        $size = count($this->bank_service_fee->get_details($bank_service_fee_id, $bank_id, null, null) ?: []);

        if (!$fees) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No bank_service_fees found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $fees
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create bank_service_fee on master, then all other DBs.
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $bank_id = $this->payload['bank_id'] ?? null;
        $percentage = $this->payload['percentage'] ?? null;

        $this->db->transBegin();

        if (($consistency_error = $this->_assert_row_counts_consistent()) !== true) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$consistency_error]);
        }

        $this->resolve_branch_models(0);

        if ($bank_id == null || $bank_id === '') {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Bank ID is required.']);
        }

        if ($percentage === null || $percentage === '') {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Percentage is required.']);
        }

        if (!is_numeric($percentage) || (float) $percentage < 0) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Percentage must be a non-negative number.']);
        }

        if (!$this->bank_service_fee->bank_exists($bank_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Bank is not found.']);
        }

        $bank_service_fee_id = $this->_attempt_create();
        if (!$bank_service_fee_id) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add bank_service_fee.']);
        }

        $this->db->transCommit();
        return $this->_create_response(SUCCESS, [
            'bank_service_fee_id' => $bank_service_fee_id,
            'message' => 'bank_service_fee added successfully.'
        ]);
    }

    private function _attempt_create()
    {
        $values = [
            'bank_id' => $this->payload['bank_id'],
            'percentage' => $this->payload['percentage'],
        ];

        $this->resolve_branch_models(0);
        $bank_service_fee_id = $this->bank_service_fee->insert($values);
        if (!$bank_service_fee_id) {
            $this->error_message = $this->bank_service_fee->error();
            return false;
        }

        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->bank_service_fee->insert($values)) {
                $this->error_message = $this->bank_service_fee->error();
                return false;
            }
        }

        return $bank_service_fee_id;
    }

    /**
     * Update bank_service_fee on master, then all other DBs.
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $bank_service_fee_id = $this->payload['bank_service_fee_id'] ?? null;

        $this->db->transBegin();

        if (($consistency_error = $this->_assert_row_counts_consistent()) !== true) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$consistency_error]);
        }

        $this->resolve_branch_models(0);

        $where = [
            'id' => $bank_service_fee_id,
            'is_deleted' => 0
        ];

        if (!$this->bank_service_fee->select('', $where, 1)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL_NOT_FOUND, ['bank_service_fee is not found.']);
        }

        if (($validation_error = $this->_validate_update_payload()) !== true) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$validation_error]);
        }

        if (!$this->_attempt_update($bank_service_fee_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to update bank_service_fee.']);
        }

        $this->db->transCommit();
        return $this->_create_response(SUCCESS, [
            'bank_service_fee_id' => $bank_service_fee_id,
            'message' => 'bank_service_fee updated successfully.'
        ]);
    }

    protected function _validate_update_payload()
    {
        if (array_key_exists('bank_id', $this->payload) && $this->payload['bank_id'] !== '') {
            if (!$this->bank_service_fee->bank_exists($this->payload['bank_id'])) {
                return 'Bank is not found.';
            }
        }

        if (array_key_exists('percentage', $this->payload)) {
            if ($this->payload['percentage'] === '') {
                return 'Percentage is required.';
            }
            if (!is_numeric($this->payload['percentage']) || (float) $this->payload['percentage'] < 0) {
                return 'Percentage must be a non-negative number.';
            }
        }

        return true;
    }

    protected function _attempt_update($bank_service_fee_id)
    {
        $values = [];
        foreach (['bank_id', 'percentage'] as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        if (!$values) {
            return true;
        }

        $where = ['id' => $bank_service_fee_id];

        $this->resolve_branch_models(0);
        if (!$this->bank_service_fee->update($where, $values)) {
            $this->error_message = $this->bank_service_fee->error();
            return false;
        }

        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->bank_service_fee->update($where, $values)) {
                $this->error_message = $this->bank_service_fee->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Soft-delete bank_service_fee on master, then all other DBs.
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $bank_service_fee_id = $this->payload['bank_service_fee_id'] ?? null;

        $this->db->transBegin();

        if (($consistency_error = $this->_assert_row_counts_consistent()) !== true) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$consistency_error]);
        }

        $this->resolve_branch_models(0);

        $where = ['id' => $bank_service_fee_id, 'is_deleted' => 0];
        if (!$this->bank_service_fee->select('', $where, 1)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL_NOT_FOUND, ['bank_service_fee is not found.']);
        }

        if (!$this->_attempt_delete($bank_service_fee_id)) {
            $this->db->transRollback();
            $this->error_message = $this->bank_service_fee->error();
            return $this->_create_response(FAIL, ['bank_service_fee is not deleted.']);
        }

        $this->db->transCommit();
        return $this->_create_response(SUCCESS, [
            'message' => 'bank_service_fee is deleted.'
        ]);
    }

    protected function _attempt_delete($bank_service_fee_id)
    {
        $where = ['id' => $bank_service_fee_id];
        $values = ['is_deleted' => 1];

        $this->resolve_branch_models(0);
        if (!$this->bank_service_fee->update($where, $values)) {
            $this->error_message = $this->bank_service_fee->error();
            return false;
        }

        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->bank_service_fee->update($where, $values)) {
                $this->error_message = $this->bank_service_fee->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Fail writes when clinic/warehouse/pharmacy row counts diverge from master.
     */
    protected function _assert_row_counts_consistent()
    {
        $this->resolve_branch_models(0);
        $master_count = count(
            $this->bank_service_fee->select('id', ['is_deleted' => 0]) ?? []
        );

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        foreach ($branches as $branch_id => $branch_name) {
            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->bank_service_fee->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                return "Data inconsistency detected between master and {$branch_name}.";
            }
        }

        return true;
    }

    protected function _consistency_table(): string
    {
        return 'bank_service_fee';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['bank_id', 'percentage'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['bank_id', 'percentage'];
    }

    protected function _consistency_compare_fields(): array
    {
        return [
            'bank_id', 'percentage', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [];
    }
}
