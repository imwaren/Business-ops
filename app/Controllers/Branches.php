<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;

class Branches extends MYT_Controller
{
    protected $request;
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
        $this->branch_master = new Branch_master();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get branches
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $branches = $this->branch_master->get_details($branch_id, $type, $term, $page, $data_size)?:[];

        if(!$branches) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No branches found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                // 'size' => count($this->branch_master->get_details($branch_id, $type, $term, null, null)?:[]),
                'data' => $branches
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create branch
     */
    public function create()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $this->db->transBegin();

        if (!$branch_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add branch. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'branch_id' => $branch_id,
                'message'    => 'branch added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a branch
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->branch_master->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$branch_id = $this->branch_master->insert($values)) {
            $this->error_message = $this->branch_master->error();
            return false;
        }

        return $branch_id;
    }

    /**
     * Update branch
     */
    public function update()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $branch_id = $this->payload['branch_id'];
        $where = [
                    'id' => $branch_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$branch = $this->branch_master->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'branch is not found.'
            ];
        } elseif (!$this->_attempt_update($branch)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update branch.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'branch_id' => $branch_id,
                'message'    => 'branch updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($branch)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->branch_master->allowedFields is declared at the top of the model
        foreach($this->branch_master->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $branch->id];

        if (!$this->branch_master->update($where, $values)) {
            $this->error_message = $this->branch_master->error();
            return false;
        }
        return true;
    }

    /**
     * Delete branches
     */
    public function delete($id = null)
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $response_status = SUCCESS;
        $branch_id = $this->payload['branch_id'];

        $where = ['id' => $branch_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$branch = $this->branch_master->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'branch is not found.',
            ];
        } elseif (!$this->_attempt_delete($branch_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'branch is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'branch is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($branch_id)
    {
        $where = ['id' => $branch_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->branch_master->update($where, $values)) {
            $this->error_message = $this->branch_master->error();
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
