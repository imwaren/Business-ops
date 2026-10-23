<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Role;

class Users extends MYT_Controller
{
    protected $request;
    protected $user;
    protected $role;
    protected $helpers = ['text'];

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->_load_essentials();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        // Preload any models, libraries, etc, here.
        // $this->user = new User();

        helper('encryption_helper');

        // E.g.: $this->session = \Config\Services::session();
        $this->user = new User();
        $this->role = new Role();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get users
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $user_id = $this->payload['user_id'] ?? null;
        $role_id = $this->payload['role_id'] ?? null; //comma separated string
        $branch_id = $this->payload['branch_id'] ?? null;
        $term = $this->payload['term'] ?? null;

        if ($role_id) {
            $role_ids = explode(',', $role_id);
        } else {
            $role_ids = null;
        }

        $users = $this->user->get_details($user_id, $role_ids, $branch_id, $term, $page, $data_size)?:null;
        $size = count($this->user->get_details($user_id, $role_ids, $branch_id, $term, null, null))?:0;

        if(!$users) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No users found'
            ];
        } else {
            foreach($users as $key => $user) {
                $immediate_head = $this->user->select('', ['id' => $user['immediate_head'], 'is_deleted' => 0], 1) ?: null;
                if($immediate_head) {
                    $signature_url = VM4_ENDPOINT . '/uploads/assets/signatures/' . $immediate_head->signature;
                    $immediate_head->signature_url = $signature_url;
                    $immediate_head = strip_sensitive_user_fields($immediate_head);
                }
                $users[$key]['immediate_head'] = $immediate_head;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => strip_sensitive_user_fields($users)
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
    * Check duplicate
    */
    protected function _has_duplicate($table, $field, $value, $id = null)
    {

        $where = [
            $field => $value,
            'is_deleted' => 0
        ];

        $id?($where['id <>'] = $id):null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }

    public function custom_validations($name) {
        $is_valid = true;
        switch($name) {
            case 'create' || 'update':
                $key = 'user_id';
                $id = array_key_exists($key, $this->payload)?$this->payload[$key]:null;
                if($this->_has_duplicate('user', 'username', $this->payload['username'], $id)) {
                    $this->validation_errors[] = 'User is duplicate.';
                    $is_valid = false;
                }
        }
        return $is_valid;
    }

    /**
     * Delete users
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
        $user_id = $this->payload['user_id'];

        $where = ['id' => $user_id, 'is_deleted' => 0];

        if (!$user = $this->user->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'user not found.'
            ];
        } elseif (!$this->_attempt_delete($user_id)) {
            $response_status = SUCCESS;
            $response = [
                'user not deleted.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'status' => 200,
                'message' => 'user deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($user_id)
    {
        // $db = \Config\Database::connect();
        $this->db->transBegin();

        $where = ['id' => $user_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->user->update($where, $values)) {
            $this->db->transRollback();
            return false;
        }

        if ($this->user_subject->select('', ['user_id' => $user_id, 'is_deleted' => 0], 1)) {
            if (!$this->user_subject->update(['user_id' => $user_id], $values)) {
                $this->db->transRollback();
                return false;
            }
        }

        $this->db->transCommit();

        return true;
    }

    /**
     * Update user
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }

        $user_id = $this->payload['user_id'];
        $where = [
                    'id' => $user_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$user = $this->user->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'user not found.'
            ];
        } elseif ($this->user->validate_email(strtolower(preg_replace('/\s+/', '', $this->payload['email'])), $user_id)) {
            $response_status = FAIL;
            $response = [
                'Email already exists!'
            ];
        } elseif (!$this->_attempt_update($user)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update user.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'user_id' => $user_id,
                'message'    => 'user updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($user)
    {
        $role_id = $this->payload['role_id'] ?? $user->role_id;
        $role = $this->role->select('', ['id' => $role_id, 'is_deleted' => 0], 1);

        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        if(array_key_exists('password', $this->payload)) {
            $this->payload['password'] = app_password_hash($this->payload['password']);
        }

        // This iterates over each necessary field
        // $this->user->allowedFields is declared at the top of the model
        $not_allowed = ['token', 'token_expiry', 'branch_id', 'role_id'];
        foreach($this->user->allowedFields AS $field) {
            if(in_array($field, $not_allowed)) {
                continue;
            }
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $user->id];

        if (!$this->user->update($where, $values))
            return false;
        return true;
    }

    /**
     * change password user
     */
    public function change_password()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $is_changed = $this->payload['is_changed'] ?? null;
        $user_id = $this->payload['user_id'];
        if (($response = $this->_require_self_or_admin($user_id)) !== true) {
            return $response;
        }
        if (! $this->_validation_check('users_change_password')) {
            return $this->_create_response(FAIL, $this->validation_errors);
        }
        $where = [
                    'id' => $user_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$user = $this->user->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'user not found.'
            ];
        } elseif ($is_changed == 1 && $user->is_changed == 1) {
            $response_status = FAIL;
            $response = [
                'Password is already changed.'
            ];
        } elseif (!$this->payload['password']) {
            $response_status = FAIL;
            $response = [
                'Password is required.'
            ];
        } elseif (!$this->_attempt_change_password($user)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change password.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'user_id' => $user_id,
                'message'    => 'Password changed successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt change password
     */
    protected function _attempt_change_password($user)
    {
        $values = [
            'password' => app_password_hash($this->payload['password']),
            'is_changed' => 1,
        ];

        $where = ['id' => $user->id];
        if (!$this->user->update($where, $values)) {
            return false;
        }
        return true;
    }

    /**
     * Create user
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }
        if (! $this->_validation_check('users_create')) {
            return $this->_create_response(FAIL, $this->validation_errors);
        }

        $this->db->transBegin();

        if ($this->user->select('', ['email' => $this->payload['email'], 'is_deleted' => 0], 1)) {
            $response_status = FAIL;
            $response = [
                'Email already exists!'
            ];
        } elseif ($this->user->validate_email(strtolower(preg_replace('/\s+/', '', $this->payload['email'])))) {
            $response_status = FAIL;
            $response = [
                'Email already exists!'
            ];
        } elseif (!$user_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create user.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'user_id' => $user_id,
                'message'    => 'user created successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a user
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'    => $this->payload['requester'],
            'added_on'    => date('Y-m-d H:i:s'),
        ];

        $this->payload['password'] = app_password_hash($this->payload['password']);

        $not_allowed = ['token', 'token_expiry', 'branch_id'];
        foreach($this->user->allowedFields AS $field) {
            if(in_array($field, $not_allowed)) {
                continue;
            }
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        if (!$user_id = $this->user->insert($values)) {
            return false;
        }

        return $user_id;
    }

}

