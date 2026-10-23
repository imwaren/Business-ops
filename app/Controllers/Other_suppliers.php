<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Other_supplier;

class Other_suppliers extends MYT_Controller
{
    protected $request;
    protected $other_supplier;
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
        $this->other_supplier = new Other_supplier();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get suppliers
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $other_supplier_id = $this->payload['other_supplier_id'] ?? null;
        $tin_no = $this->payload['tin_no'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $suppliers = $this->other_supplier->get_details($other_supplier_id, $tin_no, $term, $page, $data_size)?:[];

        if(!$suppliers) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No suppliers found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->other_supplier->get_details($other_supplier_id, $tin_no, $term, null, null)?:[]),
                'data' => $suppliers
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create other_supplier
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();
        $tin_no = $this->payload['tin_no'] ?? null;

        $name = $this->_normalize_name($this->payload['name'] ?? null);
        $this->payload['name'] = $name;
        if ($this->_has_duplicate('other_supplier', ['name' => $name, 'is_deleted' => 0])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Other supplier $name already exists."
            ];
        } elseif (!$tin_no) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "TIN number is required."
            ];
        } elseif ($this->_has_duplicate('other_supplier', ['tin_no' => $tin_no, 'is_deleted' => 0])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Other supplier with TIN number $tin_no already exists."
            ];
        } elseif (!$other_supplier_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add other_supplier. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'other_supplier_id' => $other_supplier_id,
                'message'    => 'other_supplier added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a other_supplier
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->other_supplier->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$other_supplier_id = $this->other_supplier->insert($values)) {
            $this->error_message = $this->other_supplier->error();
            return false;
        }

        return $other_supplier_id;
    }

    /**
     * Update other_supplier
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $other_supplier_id = $this->payload['other_supplier_id'];
        $where = [
                    'id' => $other_supplier_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();
        $tin_no = $this->payload['tin_no'] ?? null;

        $name = $this->_normalize_name($this->payload['name'] ?? null);
        $this->payload['name'] = $name;
        if ($this->_has_duplicate('other_supplier', ['name' => $name, 'is_deleted' => 0], $other_supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Other supplier $name already exists."
            ];
        } elseif (!$tin_no) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "TIN number is required."
            ];
        } elseif ($this->_has_duplicate('other_supplier', ['tin_no' => $tin_no, 'is_deleted' => 0], $other_supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Other supplier with TIN number $tin_no already exists."
            ];
        } elseif (!$other_supplier = $this->other_supplier->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'other_supplier is not found.'
            ];
        } elseif (!$this->_attempt_update($other_supplier)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update other_supplier.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'other_supplier_id' => $other_supplier_id,
                'message'    => 'other_supplier updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($other_supplier)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->other_supplier->allowedFields is declared at the top of the model
        foreach($this->other_supplier->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $other_supplier->id];

        if (!$this->other_supplier->update($where, $values)) {
            $this->error_message = $this->other_supplier->error();
            return false;
        }
        return true;
    }

    /**
     * Delete suppliers
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $other_supplier_id = $this->payload['other_supplier_id'];

        $where = ['id' => $other_supplier_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$other_supplier = $this->other_supplier->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'other_supplier is not found.',
            ];
        } elseif (!$this->_attempt_delete($other_supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'other_supplier is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'other_supplier is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($other_supplier_id)
    {
        $where = ['id' => $other_supplier_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->other_supplier->update($where, $values)) {
            $this->error_message = $this->other_supplier->error();
            return false;
        }

        return true;
    }

    /**
     * Normalize names.
     */
    private function _normalize_name($name)
    {
        if (!is_string($name)) {
            return $name;
        }

        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $name);
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
