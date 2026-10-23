<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Branch_master;

class Petty_cash_funds extends MYT_Controller
{
    use \App\Core\Fund_routing;
    protected $request;
    protected $petty_cash;
    protected $replenishment;
    protected $user;
    protected $branch_master;
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
        $this->branch_master = new Branch_master();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $routing = $this->fund_router();
        $group = $routing->group($branch_id);
        if ($group === null) { return false; }
        $db = db_connect($group);

        $this->petty_cash = new \App\Models\Petty_cash($db);
        $this->replenishment = new \App\Models\Replenishment($db);

        if ($group === 'master') {
            $this->db = $db; // Transaction and scoped models must use the same connection.
            foreach (['petty_cash', 'replenishment'] as $property) {
                if (method_exists($this->$property, 'with_shared_cost_centers')) {
                    $this->$property->with_shared_cost_centers($branch_id);
                }
            }
        }
        return true;
    }

    /**
     * Get petty_cash_funds
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

        $petty_cash_id = $this->payload['petty_cash_id'] ?? null;
        $department_id = $this->payload['department_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $petty_cash_funds = $this->petty_cash->get_details($petty_cash_id, $department_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->petty_cash->get_details($petty_cash_id, $department_id, $status, $term, null, null)?:[]);

        if(!$petty_cash_funds) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No petty_cash_funds found.'
            ];
        } else {
            foreach ($petty_cash_funds as $key => $petty_cash_fund) {
                $branch = $this->branch_master->select('name', ['id' => $petty_cash_fund['cost_center_id']], 1) ?? null;
                $requested_user = $this->user->select('id, first_name, last_name', ['id' => $petty_cash_fund['requested_by_id'], 'is_deleted' => 0], 1) ?? null;

                $petty_cash_funds[$key]['requested_by_name'] = $requested_user ? $requested_user->first_name . ' ' . $requested_user->last_name : null;
                $petty_cash_funds[$key]['cost_center_name'] = $branch ? $branch->name : null;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $petty_cash_funds
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create petty_cash
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

        $requested_by_id = $this->payload['requested_by_id'] ?? null;
        if (!$requested_by_id) {
            return $this->_create_response(
                FAIL,
                ['Requested by ID is required.']
            );
        }

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if ($petty_cash = $this->petty_cash->select('', ['requested_by_id' => $requested_by_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Petty cash already exists. ',
            ];
        } elseif (!$petty_cash_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add petty_cash. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'petty_cash_id' => $petty_cash_id,
                'message'    => 'petty_cash added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a petty_cash
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->petty_cash->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $values['balance'] = $values['amount'];

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$petty_cash_id = $this->petty_cash->insert($values)) {
            $this->error_message = $this->petty_cash->error();
            return false;
        }

        return $petty_cash_id;
    }

    /**
     * Update petty_cash
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

        $petty_cash_id = $this->payload['petty_cash_id'];
        $where = [
                    'id' => $petty_cash_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$petty_cash = $this->petty_cash->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'petty_cash is not found.'
            ];
        } elseif (!$this->_attempt_update($petty_cash)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update petty_cash.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'petty_cash_id' => $petty_cash_id,
                'message'    => 'petty_cash updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($petty_cash)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->petty_cash->allowedFields is declared at the top of the model
        foreach($this->petty_cash->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $petty_cash->id];

        if (!$this->petty_cash->update($where, $values)) {
            $this->error_message = $this->petty_cash->error();
            return false;
        }
        return true;
    }

    /**
     * Change status petty_cash
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
        $petty_cash_id = $this->payload['petty_cash_id'];
        $where = [
                    'id' => $petty_cash_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$petty_cash = $this->petty_cash->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'petty_cash is not found.'
            ];
        } elseif ($status && $petty_cash->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Petty_cash is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($petty_cash, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change petty_cash status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'petty_cash_id' => $petty_cash_id,
                'message'    => "petty_cash $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($petty_cash, $role_id)
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
            $values['disapproved_reason'] = $this->payload['disapproved_reason'];
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $petty_cash->id];

        if (!$this->petty_cash->update($where, $values)) {
            $this->error_message = $this->petty_cash->error();
            return false;
        }
        return true;
    }

    /**
     * Delete petty_cash_funds
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
        $petty_cash_id = $this->payload['petty_cash_id'];

        $where = ['id' => $petty_cash_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$petty_cash = $this->petty_cash->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'petty_cash is not found.',
            ];
        } elseif (!$this->_attempt_delete($petty_cash_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'petty_cash is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'petty_cash is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($petty_cash_id)
    {
        $where = ['id' => $petty_cash_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->petty_cash->update($where, $values)) {
            $this->error_message = $this->petty_cash->error();
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
