<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Role;

class Roles extends MYT_Controller
{
    protected $request;
    protected $role;
    protected $helpers = ['text'];

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
        $this->payload = $this->request->getVar();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        $this->role = new Role();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get roles
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $role_id = $this->payload['role_id'] ?? null;

        $roles = $this->role->get_details($role_id)?:[];

        if(!$roles) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No roles found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'data' => $roles
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Delete roles
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $role_id = $this->payload['role_id'];

        $where = ['id' => $role_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$role = $this->role->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'role is not found.',
            ];
        } elseif (!$this->_attempt_delete($role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'role is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'role is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($role_id)
    {
        $where = ['id' => $role_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->role->update($where, $values)) {
            $this->error_message = $this->role->error();
            return false;
        }

        return true;
    }

    /**
     * Update role
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }

        $role_id = $this->payload['role_id'];
        $where = [
                    'id' => $role_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$role = $this->role->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'role is not found.'
            ];
        } elseif (!$this->_attempt_update($role)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update role.',
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'role_id' => $role_id,
                'message'    => 'role updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($role)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->role->allowedFields is declared at the top of the model
        foreach($this->role->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $role->id];

        if (!$this->role->update($where, $values)) {
            $this->error_message = $this->role->error();
            return false;
        }
        return true;
    }

    /**
     * Create role
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$role_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add role. ",
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'role already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'role_id' => $role_id,
                'message'    => 'role added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a role
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->role->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$role_id = $this->role->insert($values)) {
            $this->error_message = $this->role->error();
            return false;
        }

        return $role_id;
    }

    /**
     * Create multiple roles
     */
    public function create_multiple()
    {
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }
        $this->db->transBegin();

        if (!$role_id = $this->_attempt_generate_multiple()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create roles.'
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'Duplcate error. Make sure unique fields have unique values.';
            }

        } elseif ($role_id === "validation_error") {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = $this->validation_errors;
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'role_id' => $role_id,
                'message'    => 'roles created successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to update multiple with foreign key
     * Update means updating an existing input, Create means inserting a new input, Generate combines the two
     * You can use this function to create roles of role
     */
    private function _attempt_generate_multiple($role_id = null, $table = 'role')
    {
        // Count determines the number of name
        $field_name = $this->$table->allowedFields[1];
        $count = (array_key_exists($field_name, $this->payload) && $this->payload[$field_name]) ? count($this->payload[$field_name]) : 0;

        if($count === 0) {
            $this->error_message = ["No " . $table . " added."];
            return false;
        }

        $data = [];
        for($i = 0; $i < $count; $i++) {
            $values = [
                'added_by'              => $this->payload['requester'],
                'added_on'              => date('Y-m-d H:i:s'),
            ];

            foreach($this->$table->allowedFields AS $field) {
                if(!array_key_exists($field, $this->payload)) {
                    continue;
                }

                if($field === 'role_id') {
                    $values[$field] = $this->payload['role_id']?:null;
                } else {
                    $values[$field] = $this->payload[$field][$i]?:null;
                }
            }

            // Manually add/modify the fields here
            // $values['field_name'] => 'value';

            $values[$table . '_id'] = (array_key_exists($table . '_id', $this->payload) && $this->payload[$table . '_id'] && array_key_exists($i, $this->payload[$table . '_id'])) ? $this->payload[$table . '_id'][$i] : null;
            $data[] = $values;
        }

        foreach($data AS $data_val) {
            foreach($this->$table->allowedFields AS $field) {
                if(!array_key_exists($field, $this->payload)) {
                    continue;
                }
                $this->payload[$field] = $data_val[$field];
            }
            $this->payload[$table . '_id'] = $data_val[$table . '_id'];

            if(array_key_exists($table . '_id', $this->payload) && $this->payload[$table . '_id']) {
                if (!$this->_validation_check($table . 's_update')) {
                    return 'validation_error';
                }

                if (!$this->_attempt_update($this->payload[$table . '_id'], $table)) {
                    return false;
                }
            } else {
                if (!$this->_validation_check($table . 's_create')) {
                    return 'validation_error';
                }

                if (!$this->_attempt_create($table)) {
                    return false;
                }
            }

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

    public function custom_validations($validation_name) {
        $is_valid = true;
        switch($validation_name) {
            case 'create' || 'update':
                $key = 'role_id';
                $id = array_key_exists($key, $this->payload)?$this->payload[$key]:null;

                // Put your custom validations here
                // If that validation can be applied to other modules, add it to MYT Custom Rules
        }
        return $is_valid;
    }
}
