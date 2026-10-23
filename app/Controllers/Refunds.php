<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Refund;
use App\Models\Refund_detail;
use App\Models\Patient_diagnostic;

class Refunds extends MYT_Controller
{
    protected $refund;
    protected $refund_detail;
    protected $helpers = ['text'];

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
    }

    protected function _load_essentials()
    {
        $this->refund = new Refund();
        $this->refund_detail = new Refund_detail();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    protected function branch_db_map(): array
    {
        return [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];
    }

    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $refund_id = $this->payload['refund_id'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? $this->payload['cost_center_id'] ?? null;
        $transaction_id = $this->payload['transaction_id'] ?? null;
        $payment_id = $this->payload['payment_id'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $term = $this->payload['term'] ?? null;
        $status = $this->payload['status'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;
            if (! isset($this->branch_db_map()[$branch_id])) {
                return $this->_create_response(FAIL, ['Invalid Branch ID.']);
            }
        } else {
            $branch_id = null;
        }

        $refunds = $this->refund->get_details(
            $refund_id,
            $branch_id,
            $transaction_id,
            $payment_id,
            $date_from,
            $date_to,
            $term,
            $status,
            $page,
            $data_size
        ) ?: [];
        $size = count($this->refund->get_details(
            $refund_id,
            $branch_id,
            $transaction_id,
            $payment_id,
            $date_from,
            $date_to,
            $term,
            $status,
            null,
            null
        ) ?: []);

        if (! $refunds) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No refunds found.']);
        }

        foreach ($refunds as $key => $refund) {
            $refunds[$key]['details'] = $this->refund_detail->get_details($refund['id']) ?: [];
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $refunds,
        ]);
    }

    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $refund_id = $this->payload['refund_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $cost_center_id = $this->payload['cost_center_id'] ?? $this->payload['branch_id'] ?? null;

        if (! $refund_id || ! $status) {
            return $this->_create_response(FAIL, ['refund_id and status are required.']);
        }

        if (! in_array($status, ['approved', 'disapproved'], true)) {
            return $this->_create_response(FAIL, ['status must be approved or disapproved.']);
        }

        $where = [
            'id' => $refund_id,
            'is_deleted' => 0,
        ];

        $this->db->transBegin();

        if (! $refund = $this->refund->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'refund is not found.',
            ];
        } elseif ($cost_center_id !== null && $cost_center_id !== ''
            && (int) $refund->branch_id !== (int) $cost_center_id) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'refund does not belong to this cost center.',
            ];
        } elseif ($refund->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Refund is already $status. ",
            ];
        } elseif (! $this->_attempt_update_status($refund)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change refund status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'refund_id' => $refund_id,
                'message'   => "refund $status successfully.",
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _attempt_update_status($refund)
    {
        $values = [
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
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
        } else {
            return false;
        }

        if (! $this->refund->update(['id' => $refund->id], $values)) {
            $this->error_message = $this->refund->error();
            return false;
        }

        return true;
    }

    protected function _patient_name($branch_id, $patient_id)
    {
        $db_map = $this->branch_db_map();
        if (! $patient_id || ! isset($db_map[(int) $branch_id])) {
            return null;
        }

        $patient = new Patient_diagnostic(db_connect($db_map[(int) $branch_id]));
        $row = $patient->get_patient_name($patient_id);

        return $row ? ($row->patient_name ?? null) : null;
    }
}
