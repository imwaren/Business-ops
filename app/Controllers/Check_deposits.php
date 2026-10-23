<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Check_deposit;
use App\Models\Account_type;

class Check_deposits extends MYT_Controller
{
    protected $check_deposit;
    protected $account_type;

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->check_deposit = new Check_deposit();
        $this->account_type = new Account_type();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $check_deposit_id = $this->payload['check_deposit_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        [$page, $data_size] = list_page_params($this->payload);

        $data = $this->check_deposit->get_details($check_deposit_id, $status, $date_from, $date_to, $page, $data_size) ?: [];
        $size = $this->check_deposit->count_details($check_deposit_id, $status, $date_from, $date_to);

        if (!$data) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No check deposits found.']);
        }

        return $this->_create_response(SUCCESS, ['size' => $size, 'data' => $data]);
    }

    public function get_report()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $check_deposit_id = $this->payload['check_deposit_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        [$page, $data_size] = list_page_params($this->payload);

        $data = $this->check_deposit->get_report($check_deposit_id, $status, $date_from, $date_to, $page, $data_size) ?: [];
        $size = $this->check_deposit->count_report($check_deposit_id, $status, $date_from, $date_to);

        if (!$data) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No check deposit report found.']);
        }

        return $this->_create_response(SUCCESS, ['size' => $size, 'data' => $data]);
    }

    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $check_deposit_id = $this->payload['check_deposit_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $account_type_id = filter_var($this->payload['account_type_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$check_deposit_id || $status !== 'posted' || $account_type_id === false || $account_type_id <= 0) {
            return $this->_create_response(FAIL, ['check_deposit_id, account_type_id are required and status must be posted.']);
        }
        if (!$this->account_type->select('', ['id' => $account_type_id, 'is_deleted' => 0], 1)) {
            return $this->_create_response(FAIL, ['account_type_id is not found.']);
        }

        $this->db->transBegin();
        $check_deposit = $this->check_deposit->select('', [
            'id' => $check_deposit_id,
            'is_deleted' => 0,
        ], 1);

        if (!$check_deposit) {
            $this->db->transRollback();
            return $this->_create_response(FAIL_NOT_FOUND, ['check_deposit is not found.']);
        }
        if ($check_deposit->status === 'posted') {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['check_deposit is already posted.']);
        }

        $now = date('Y-m-d H:i:s');
        if (!$this->check_deposit->update(['id' => $check_deposit->id], [
            'status' => 'posted',
            'account_type_id' => $account_type_id,
            'posted_by' => $this->payload['requester'],
            'posted_on' => $now,
            'posted_remarks' => $this->payload['remarks'] ?? null,
            'updated_by' => $this->payload['requester'],
            'updated_on' => $now,
        ])) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to change check_deposit status.']);
        }

        $this->db->transCommit();

        return $this->_create_response(SUCCESS, [
            'check_deposit_id' => $check_deposit_id,
            'message' => 'check_deposit posted successfully.',
        ]);
    }
}
