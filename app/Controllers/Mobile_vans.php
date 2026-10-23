<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Mobile_van;

class Mobile_vans extends MYT_Controller
{
    protected $request;
    protected $mobile_van;
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
        $this->mobile_van = new Mobile_van();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get mobile_vans
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $mobile_van_id = $this->payload['mobile_van_id'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $mobile_vans = $this->mobile_van->get_details($mobile_van_id, $branch_id, $term, $page, $data_size)?:[];
        $size = count($this->mobile_van->get_details($mobile_van_id, $branch_id, $term, null, null)?:[]);

        if(!$mobile_vans) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No mobile_vans found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $mobile_vans
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create mobile_van
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$mobile_van_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add mobile_van. ",
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'mobile_van already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'mobile_van_id' => $mobile_van_id,
                'message'    => 'mobile_van added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a mobile_van
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->mobile_van->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$mobile_van_id = $this->mobile_van->insert($values)) {
            $this->error_message = $this->mobile_van->error();
            return false;
        }

        return $mobile_van_id;
    }

    /**
     * Update mobile_van
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $mobile_van_id = $this->payload['mobile_van_id'];
        $where = [
                    'id' => $mobile_van_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$mobile_van = $this->mobile_van->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'mobile_van is not found.'
            ];
        } elseif (!$this->_attempt_update($mobile_van)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update mobile_van.',
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'mobile_van_id' => $mobile_van_id,
                'message'    => 'mobile_van updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($mobile_van)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->mobile_van->allowedFields is declared at the top of the model
        foreach($this->mobile_van->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $mobile_van->id];

        if (!$this->mobile_van->update($where, $values)) {
            $this->error_message = $this->mobile_van->error();
            return false;
        }
        return true;
    }

    /**
     * Delete mobile_vans
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $mobile_van_id = $this->payload['mobile_van_id'];

        $where = ['id' => $mobile_van_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$mobile_van = $this->mobile_van->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'mobile_van is not found.',
            ];
        } elseif (!$this->_attempt_delete($mobile_van_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'mobile_van is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'mobile_van is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($mobile_van_id)
    {
        $where = ['id' => $mobile_van_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->mobile_van->update($where, $values)) {
            $this->error_message = $this->mobile_van->error();
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
                $key = 'mobile_van_id';
                $id = array_key_exists($key, $this->payload)?$this->payload[$key]:null;

                // Put your custom validations here
                // If that validation can be applied to other modules, add it to MYT Custom Rules
        }
        return $is_valid;
    }
}
