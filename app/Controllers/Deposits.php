<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;

class Deposits extends MYT_Controller
{
    protected $request;
    protected $deposit;
    protected $deposit_detail;
    protected $user;
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
        $this->user = new User();
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

        $this->deposit = new \App\Models\Deposit($db);
        $this->deposit_detail = new \App\Models\Deposit_detail($db);

        return true;
    }

    /**
     * Get deposits
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

        $deposit_id = $this->payload['deposit_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $deposits = $this->deposit->get_details($deposit_id, $page, $data_size)?:[];
        $size = count($this->deposit->get_details($deposit_id, null, null)?:[]);

        if(!$deposits) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No deposits found.'
            ];
        } else {
            foreach ($deposits as $key => $deposit) {
                $deposits[$key]['details'] = $this->deposit_detail->get_details($deposit['id'])?:[];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $deposits
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create deposit
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

        $this->db->transBegin();

        if (!$deposit_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add deposit. ",
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'deposit already exists.';
            }
        } elseif (!$this->_attempt_create_details($deposit_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add deposit details. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'deposit_id' => $deposit_id,
                'message'    => 'deposit added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a deposit
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->deposit->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        if (!$deposit_id = $this->deposit->insert($values)) {
            $this->error_message = $this->deposit->error();
            return false;
        }

        return $deposit_id;
    }

    /**
     * Attempt to create deposit details
     */
    private function _attempt_create_details($deposit_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'deposit_id'        => $deposit_id,
                'cash_advance_id'   => array_key_exists('cash_advance_id', $detail) && $detail['cash_advance_id'] != '' ? $detail['cash_advance_id'] : null,
                'amount'            => array_key_exists('amount', $detail) && $detail['amount'] != '' ? $detail['amount'] : null,
                'added_by'          => $this->payload['requester'],
                'added_on'          => date('Y-m-d H:i:s'),
            ];

            if (!$this->deposit_detail->insert($values)) {
                $this->error_message = $this->deposit_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update deposit
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

        $deposit_id = $this->payload['deposit_id'];
        $where = [
                    'id' => $deposit_id,
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$deposit = $this->deposit->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'deposit is not found.'
            ];
        } elseif (!$this->_attempt_update($deposit)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update deposit.',
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } elseif (!$this->_attempt_update_details($deposit->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update deposit details. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'deposit_id' => $deposit_id,
                'message'    => 'deposit updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($deposit)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        foreach($this->deposit->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $where = ['id' => $deposit->id];

        if (!$this->deposit->update($where, $values)) {
            $this->error_message = $this->deposit->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update deposit details
     */
    private function _attempt_update_details($deposit_id)
    {
        $existing_details = $this->deposit_detail->select('id', ['deposit_id' => $deposit_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_details, 'id');

        $payload_ids = [];
        if (array_key_exists('details', $this->payload) && is_array($this->payload['details'])) {
            foreach ($this->payload['details'] as $detail) {
                if (!empty($detail['id'])) {
                    $payload_ids[] = (int)$detail['id'];
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
                if (!$this->deposit_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->deposit_detail->error();
                    return false;
                }
            }
        }

        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details'])) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'deposit_id'        => $deposit_id,
                'cash_advance_id'   => array_key_exists('cash_advance_id', $detail) && $detail['cash_advance_id'] != '' ? $detail['cash_advance_id'] : null,
                'amount'            => array_key_exists('amount', $detail) && $detail['amount'] != '' ? $detail['amount'] : null,
            ];

            if (!empty($detail['id'])) {
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->deposit_detail->update(['id' => $detail['id']], $values)) {
                    $this->error_message = $this->deposit_detail->error();
                    return false;
                }
            } else {
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->deposit_detail->insert($values)) {
                    $this->error_message = $this->deposit_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Delete deposits
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
        $deposit_id = $this->payload['deposit_id'];

        $where = ['id' => $deposit_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$deposit = $this->deposit->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'deposit is not found.',
            ];
        } elseif (!$this->_attempt_delete($deposit_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'deposit is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'deposit is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($deposit_id)
    {
        $where = ['id' => $deposit_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->deposit->update($where, $values)) {
            $this->error_message = $this->deposit->error();
            return false;
        }

        return true;
    }

    public function custom_validations($validation_name) {
        $is_valid = true;
        switch($validation_name) {
            case 'create' || 'update':
                $key = 'deposit_id';
                $id = array_key_exists($key, $this->payload)?$this->payload[$key]:null;
        }
        return $is_valid;
    }
}
