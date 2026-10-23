<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Department;

class Departments extends MYT_Controller
{
    protected $request;
    protected $department;
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
        $this->department = new Department();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get departments
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $department_id = $this->payload['department_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $departments = $this->department->get_details($department_id, $page, $data_size)?:[];
        $size = count($this->department->get_details($department_id, null, null)?:[]);

        if(!$departments) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No departments found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $departments
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create department
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$department_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add department. ",
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'department already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'department_id' => $department_id,
                'message'    => 'department added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a department
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->department->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$department_id = $this->department->insert($values)) {
            $this->error_message = $this->department->error();
            return false;
        }

        return $department_id;
    }

    /**
     * Update department
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $department_id = $this->payload['department_id'];
        $where = [
                    'id' => $department_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$department = $this->department->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'department is not found.'
            ];
        } elseif (!$this->_attempt_update($department)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update department.',
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'department_id' => $department_id,
                'message'    => 'department updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($department)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->department->allowedFields is declared at the top of the model
        foreach($this->department->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $department->id];

        if (!$this->department->update($where, $values)) {
            $this->error_message = $this->department->error();
            return false;
        }
        return true;
    }

    /**
     * Delete departments
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $department_id = $this->payload['department_id'];

        $where = ['id' => $department_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$department = $this->department->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'department is not found.',
            ];
        } elseif (!$this->_attempt_delete($department_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'department is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'department is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($department_id)
    {
        $where = ['id' => $department_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->department->update($where, $values)) {
            $this->error_message = $this->department->error();
            return false;
        }

        return true;
    }

    /**
     * Normalize column.
     */
    private function _normalize_column($column)
    {
        if (!is_string($column)) {
            return $column;
        }

        $column = trim($column);
        if ($column === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $column);
    }

    /**
    * Check duplicate
    */
    protected function _has_duplicate($table, $where, $id = null)
    {
        $id?($where['id <>'] = $id):null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }

    public function custom_validations($validation_name) {
        $is_valid = true;
        switch($validation_name) {
            case 'create' || 'update':
                $key = 'department_id';
                $id = array_key_exists($key, $this->payload)?$this->payload[$key]:null;

                // Put your custom validations here
                // If that validation can be applied to other modules, add it to MYT Custom Rules
        }
        return $is_valid;
    }
}
