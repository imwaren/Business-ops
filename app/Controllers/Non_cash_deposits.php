<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Branch_master;

//NON-CASH PAYMENT Module
class Non_cash_deposits extends MYT_Controller
{
    protected $request;
    protected $non_cash_deposit;
    protected $non_cash_deposit_attachment;
    protected $bank_service_fee;
    protected $user;
    protected $branch_master;
    protected $helpers = ['text'];

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_report_excel()
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
        $this->branch_master = new Branch_master();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
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

        $this->non_cash_deposit = new \App\Models\Non_cash_deposit($db);
        $this->non_cash_deposit_attachment = new \App\Models\Non_cash_deposit_attachment($db);
        $this->bank_service_fee = new \App\Models\Bank_service_fee($db);

        return true;
    }

    /**
     * Get non_cash_deposits
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

        $non_cash_deposit_id = $this->payload['non_cash_deposit_id'] ?? null;
        $mop = $this->payload['mop'] ?? null;
        $status = $this->payload['status'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $non_cash_deposits = $this->non_cash_deposit->get_details($non_cash_deposit_id, $mop, $status, $date_from, $date_to, $page, $data_size)?:[];
        $size = count($this->non_cash_deposit->get_details($non_cash_deposit_id, $mop, $status, $date_from, $date_to, null, null)?:[]);

        if(!$non_cash_deposits) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No non_cash_deposits found.'
            ];
        } else {
            foreach ($non_cash_deposits AS $index => $non_cash_deposit) {
                $non_cash_deposits[$index]['attachments'] = $this->non_cash_deposit_attachment->select('', ['non_cash_deposit_id' => $non_cash_deposit['id'], 'is_deleted' => 0]) ?: [];
                $non_cash_deposits[$index]['branch_id'] = $branch_id;
                $non_cash_deposits[$index]['branch_name'] = $this->branch_master->select('', ['id' => $branch_id], 1)->name;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $non_cash_deposits
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get report
     */
    public function get_report()
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

        $non_cash_deposit_id = $this->payload['non_cash_deposit_id'] ?? null;
        $mop = $this->payload['mop'] ?? null;
        $status = $this->payload['status'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $non_cash_deposits = $this->non_cash_deposit->get_report($non_cash_deposit_id, $mop, $status, $date_from, $date_to, $page, $data_size)?:[];
        $size = count($this->non_cash_deposit->get_report($non_cash_deposit_id, $mop, $status, $date_from, $date_to, null, null)?:[]);

        if(!$non_cash_deposits) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No non_cash_deposits report found.'
            ];
        } else {
            foreach ($non_cash_deposits as $key => $non_cash_deposit) {
                $non_cash_deposits[$key]['branch_id'] = $branch_id;
                $non_cash_deposits[$key]['branch_name'] = $this->branch_master->select('', ['id' => $branch_id], 1)->name;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $non_cash_deposits
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Change status non_cash_deposit
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
        $non_cash_deposit_id = $this->payload['non_cash_deposit_id'];
        $where = [
                    'id' => $non_cash_deposit_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$non_cash_deposit = $this->non_cash_deposit->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'non_cash_deposit is not found.'
            ];
        } elseif (!in_array($status, ['posted', 'reviewed'])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Invalid status. Valid statuses are: posted, reviewed.',
            ];
        } elseif ($status && ((in_array($status, ['reviewed', 'unreviewed']) && $non_cash_deposit->status == $status) || ($status == 'reviewed' && $non_cash_deposit->is_reviewed == 1))) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "non_cash_deposit is already $status. ",
            ];
        } elseif ($status == 'posted' && ($fee_error = $this->_validate_and_snapshot_fee($non_cash_deposit)) !== true) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $fee_error,
            ];
        } elseif (!$this->_attempt_update_status($non_cash_deposit)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change non_cash_deposit status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'non_cash_deposit_id' => $non_cash_deposit_id,
                'message'    => "non_cash_deposit $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($non_cash_deposit)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'posted') {
            $values['status'] = $status;
            $values['posted_by'] = $this->payload['requester'];
            $values['posted_on'] = date('Y-m-d H:i:s');
            $values['posted_remarks'] = $this->payload['remarks'] ?? null;
        } elseif ($status == 'reviewed') {
            $values['is_reviewed'] = 1;
            $values['reviewed_by'] = $this->payload['requester'];
            $values['reviewed_on'] = date('Y-m-d H:i:s');
            $values['reviewed_remarks'] = $this->payload['remarks'] ?? null;
        }

        $where = ['id' => $non_cash_deposit->id];

        if (!$this->non_cash_deposit->update($where, $values)) {
            $this->error_message = $this->non_cash_deposit->error();
            return false;
        }
        return true;
    }

    /**
     * Require a valid bank_service_fee before posting and snapshot its percentage.
     */
    protected function _validate_and_snapshot_fee($non_cash_deposit)
    {
        $fee_id = $non_cash_deposit->bank_service_fee_id ?? null;
        if (array_key_exists('bank_service_fee_id', $this->payload) && $this->payload['bank_service_fee_id'] != '') {
            $fee_id = $this->payload['bank_service_fee_id'];
        }

        if ($fee_id == null || $fee_id === '') {
            return 'Bank service fee is required.';
        }

        $fee = $this->bank_service_fee->select('', ['id' => $fee_id, 'is_deleted' => 0], 1);
        if (!$fee) {
            return 'Bank service fee is not found.';
        }

        $percentage = $non_cash_deposit->bank_service_fee_percentage ?? null;
        if ($percentage === null || $percentage === '') {
            $percentage = $fee->percentage;
        }

        $values = [
            'bank_service_fee_id' => $fee_id,
            'bank_service_fee_percentage' => $percentage,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (!$this->non_cash_deposit->update(['id' => $non_cash_deposit->id], $values)) {
            $this->error_message = $this->non_cash_deposit->error();
            return 'Failed to save bank service fee on non_cash_deposit.';
        }

        return true;
    }
}
