<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Branch_master;

class Cash_advances extends MYT_Controller
{
    use \App\Core\Fund_routing;
    protected $request;
    protected $cash_advance;
    protected $liquidation;
    protected $liquidation_detail;
    protected $deposit_detail;
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

        $this->cash_advance = new \App\Models\Cash_advance($db);
        $this->liquidation = new \App\Models\Liquidation($db);
        $this->liquidation_detail = new \App\Models\Liquidation_detail($db);
        $this->deposit_detail = new \App\Models\Deposit_detail($db);

        if ($group === 'master') {
            $this->db = $db; // Transaction and scoped models must use the same connection.
            foreach (['cash_advance', 'liquidation', 'liquidation_detail', 'deposit_detail'] as $property) {
                if (method_exists($this->$property, 'with_shared_cost_centers')) {
                    $this->$property->with_shared_cost_centers($branch_id);
                }
            }
        }
        return true;
    }

    /**
     * Get cash_advances
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

        $cash_advance_id = $this->payload['cash_advance_id'] ?? null;
        $department_id = $this->payload['department_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $screen = $this->payload['screen'] ?? null;

        $cash_advances = $this->cash_advance->get_details($cash_advance_id, $department_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->cash_advance->get_details($cash_advance_id, $department_id, $status, $term, null, null)?:[]);

        if(!$cash_advances) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No cash_advances found.'
            ];
        } else {
            foreach ($cash_advances as $key => $cash_advance) {
                $requested_user = $this->user->select('id, first_name, last_name, middle_name, role_id', ['id' => $cash_advance['requested_by_id'], 'is_deleted' => 0], 1);
                $cash_advances[$key]['requested_by_name'] = $requested_user ? trim($requested_user->first_name . ' ' . $requested_user->last_name) : null;

                $cost_center = $this->branch_master->select('id, name, code', ['id' => $cash_advance['cost_center_id'], 'is_deleted' => 0], 1);
                $cash_advances[$key]['cost_center_name'] = $cost_center ? $cost_center->name : null;

                if (isset($screen) && $screen == 'report') {
                    $liquidations = $this->liquidation->get_details(null, null, $cash_advance['id'], 'approved')?:[];
                } else {
                    $liquidations = $this->liquidation->get_details(null, null, $cash_advance['id'])?:[];
                }
                if ($liquidations) {
                    foreach ($liquidations as $li => $liquidation) {
                        $liquidations[$li]['details'] = $this->liquidation_detail->get_details($liquidation['id'])?:[];
                    }
                }
                $cash_advances[$key]['liquidations'] = $liquidations;
                $cash_advances[$key]['deposit_details'] = $this->deposit_detail->get_details(null, $cash_advance['id'])?:[];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $cash_advances
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create cash_advance
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

        if (!$cash_advance_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add cash_advance. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'cash_advance_id' => $cash_advance_id,
                'message'    => 'cash_advance added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a cash_advance
     */
    private function _attempt_create()
    {
        $values = [
            'balance'               => $this->payload['amount'],
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->cash_advance->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        if (!$cash_advance_id = $this->cash_advance->insert($values)) {
            $this->error_message = $this->cash_advance->error();
            return false;
        }

        return $cash_advance_id;
    }

    /**
     * Update cash_advance
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

        $cash_advance_id = $this->payload['cash_advance_id'];
        $where = [
                    'id' => $cash_advance_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$cash_advance = $this->cash_advance->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'cash_advance is not found.'
            ];
        } elseif (!$this->_attempt_update($cash_advance)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update cash_advance.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'cash_advance_id' => $cash_advance_id,
                'message'    => 'cash_advance updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($cash_advance)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->cash_advance->allowedFields is declared at the top of the model
        foreach($this->cash_advance->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $cash_advance->id];

        if (!$this->cash_advance->update($where, $values)) {
            $this->error_message = $this->cash_advance->error();
            return false;
        }
        return true;
    }

    /**
     * Change status cash_advance
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
        $cash_advance_id = $this->payload['cash_advance_id'];
        $where = [
                    'id' => $cash_advance_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$cash_advance = $this->cash_advance->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'cash_advance is not found.'
            ];
        } elseif ($status && $cash_advance->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Cash_advance is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($cash_advance, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change cash_advance status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'cash_advance_id' => $cash_advance_id,
                'message'    => "cash_advance $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($cash_advance, $role_id)
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

        $where = ['id' => $cash_advance->id];

        if (!$this->cash_advance->update($where, $values)) {
            $this->error_message = $this->cash_advance->error();
            return false;
        }
        return true;
    }

    /**
     * Delete cash_advances
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
        $cash_advance_id = $this->payload['cash_advance_id'];

        $where = ['id' => $cash_advance_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$cash_advance = $this->cash_advance->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'cash_advance is not found.',
            ];
        } elseif (!$this->_attempt_delete($cash_advance_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'cash_advance is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'cash_advance is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($cash_advance_id)
    {
        $where = ['id' => $cash_advance_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->cash_advance->update($where, $values)) {
            $this->error_message = $this->cash_advance->error();
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
