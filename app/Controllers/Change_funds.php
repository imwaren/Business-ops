<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Branch_master;

class Change_funds extends MYT_Controller
{
    use \App\Core\Fund_routing;
    protected $request;
    protected $change_fund;
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

        $this->change_fund = new \App\Models\Change_fund($db);

        if ($group === 'master') {
            $this->db = $db; // Transaction and scoped models must use the same connection.
            foreach (['change_fund'] as $property) {
                if (method_exists($this->$property, 'with_shared_cost_centers')) {
                    $this->$property->with_shared_cost_centers($branch_id);
                }
            }
        }
        return true;
    }

    /**
     * Get change_funds
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

        $change_fund_id = $this->payload['change_fund_id'] ?? null;
        $department_id = $this->payload['department_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $change_funds = $this->change_fund->get_details($change_fund_id, $department_id, $status, $date_from, $date_to, $term, $page, $data_size)?:[];
        $size = count($this->change_fund->get_details($change_fund_id, $department_id, $status, $date_from, $date_to, $term, null, null)?:[]);

        if(!$change_funds) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No change_funds found.'
            ];
        } else {
            foreach ($change_funds AS $key => $change_fund) {
                $requested_user = $this->user->select('id, first_name, last_name', ['id' => $change_fund['requested_by_id'], 'is_deleted' => 0], 1);
                $change_funds[$key]['requested_by_name'] = $requested_user ? $requested_user->first_name . ' ' . $requested_user->last_name : null;

                $branch = $this->branch_master->select('id, name', ['id' => $change_fund['branch_id'], 'is_deleted' => 0], 1);
                $change_funds[$key]['branch_name'] = $branch ? $branch->name : null;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $change_funds
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create change_fund
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

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$change_fund_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add change_fund. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'change_fund_id' => $change_fund_id,
                'message'    => 'change_fund added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a change_fund
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->change_fund->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$change_fund_id = $this->change_fund->insert($values)) {
            $this->error_message = $this->change_fund->error();
            return false;
        }

        return $change_fund_id;
    }

    /**
     * Update change_fund
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

        $change_fund_id = $this->payload['change_fund_id'];
        $where = [
                    'id' => $change_fund_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$change_fund = $this->change_fund->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'change_fund is not found.'
            ];
        } elseif (!$this->_attempt_update($change_fund)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update change_fund.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'change_fund_id' => $change_fund_id,
                'message'    => 'change_fund updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($change_fund)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->change_fund->allowedFields is declared at the top of the model
        foreach($this->change_fund->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $change_fund->id];

        if (!$this->change_fund->update($where, $values)) {
            $this->error_message = $this->change_fund->error();
            return false;
        }
        return true;
    }

    /**
     * Change status change_fund
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
        $change_fund_id = $this->payload['change_fund_id'];
        $where = [
                    'id' => $change_fund_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$change_fund = $this->change_fund->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'change_fund is not found.'
            ];
        } elseif ($status && $change_fund->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Change_fund is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($change_fund, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change change_fund status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'change_fund_id' => $change_fund_id,
                'message'    => "change_fund $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($change_fund, $role_id)
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

        $where = ['id' => $change_fund->id];

        if (!$this->change_fund->update($where, $values)) {
            $this->error_message = $this->change_fund->error();
            return false;
        }
        return true;
    }

    /**
     * Delete change_funds
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
        $change_fund_id = $this->payload['change_fund_id'];

        $where = ['id' => $change_fund_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$change_fund = $this->change_fund->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'change_fund is not found.',
            ];
        } elseif (!$this->_attempt_delete($change_fund_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'change_fund is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'change_fund is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($change_fund_id)
    {
        $where = ['id' => $change_fund_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->change_fund->update($where, $values)) {
            $this->error_message = $this->change_fund->error();
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
