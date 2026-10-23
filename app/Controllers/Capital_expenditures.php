<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;

class Capital_expenditures extends MYT_Controller
{
    protected $request;
    protected $capital_expenditure;
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
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->capital_expenditure = new \App\Models\Capital_expenditure($db);

        return true;
    }

    /**
     * Get capital_expenditures
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

        $capital_expenditure_id = $this->payload['capital_expenditure_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $capital_expenditures = $this->capital_expenditure->get_details($capital_expenditure_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->capital_expenditure->get_details($capital_expenditure_id, $status, $term, null, null)?:[]);

        if(!$capital_expenditures) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No capital_expenditures found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $capital_expenditures
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create capital_expenditure
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

        if (!$capital_expenditure_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add capital_expenditure. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'capital_expenditure_id' => $capital_expenditure_id,
                'message'    => 'capital_expenditure added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a capital_expenditure
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->capital_expenditure->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$capital_expenditure_id = $this->capital_expenditure->insert($values)) {
            $this->error_message = $this->capital_expenditure->error();
            return false;
        }

        return $capital_expenditure_id;
    }

    /**
     * Update capital_expenditure
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

        $capital_expenditure_id = $this->payload['capital_expenditure_id'];
        $where = [
                    'id' => $capital_expenditure_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$capital_expenditure = $this->capital_expenditure->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'capital_expenditure is not found.'
            ];
        } elseif (!$this->_attempt_update($capital_expenditure)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update capital_expenditure.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'capital_expenditure_id' => $capital_expenditure_id,
                'message'    => 'capital_expenditure updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($capital_expenditure)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->capital_expenditure->allowedFields is declared at the top of the model
        foreach($this->capital_expenditure->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $capital_expenditure->id];

        if (!$this->capital_expenditure->update($where, $values)) {
            $this->error_message = $this->capital_expenditure->error();
            return false;
        }
        return true;
    }

    /**
     * Change status capital_expenditure
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
        $capital_expenditure_id = $this->payload['capital_expenditure_id'];
        $where = [
                    'id' => $capital_expenditure_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$capital_expenditure = $this->capital_expenditure->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'capital_expenditure is not found.'
            ];
        } elseif ($status && $capital_expenditure->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Change_fund is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($capital_expenditure, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change capital_expenditure status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'capital_expenditure_id' => $capital_expenditure_id,
                'message'    => "capital_expenditure $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($capital_expenditure, $role_id)
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

        $where = ['id' => $capital_expenditure->id];

        if (!$this->capital_expenditure->update($where, $values)) {
            $this->error_message = $this->capital_expenditure->error();
            return false;
        }
        return true;
    }

    /**
     * Delete capital_expenditures
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
        $capital_expenditure_id = $this->payload['capital_expenditure_id'];

        $where = ['id' => $capital_expenditure_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$capital_expenditure = $this->capital_expenditure->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'capital_expenditure is not found.',
            ];
        } elseif (!$this->_attempt_delete($capital_expenditure_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'capital_expenditure is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'capital_expenditure is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($capital_expenditure_id)
    {
        $where = ['id' => $capital_expenditure_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->capital_expenditure->update($where, $values)) {
            $this->error_message = $this->capital_expenditure->error();
            return false;
        }

        return true;
    }
}
