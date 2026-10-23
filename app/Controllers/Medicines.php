<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Medicine;
use App\Models\Medicine_master;
use App\Models\Medicine_inventory;
use App\Models\Medicine_stock_in;

class Medicines extends MYT_Controller
{
    protected $request;
    protected $medicine;
    protected $medicine_master;
    protected $medicine_inventory;
    protected $medicine_stock_in;
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
        $this->medicine = new Medicine();
        $this->medicine_master = new Medicine_master();
        $this->medicine_inventory = new Medicine_inventory();
        $this->medicine_stock_in = new Medicine_stock_in();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get medicines
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $medicine_id = $this->payload['medicine_id'] ?? null;
        $brand = $this->payload['brand'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $medicines = $this->medicine->get_details($medicine_id, $brand, $term, $page, $data_size)?:[];

        if(!$medicines) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No medicines found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->medicine->get_details($medicine_id, $brand, $term, null, null)?:[]),
                'data' => $medicines
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get medicines stock in
     */
    public function get_stock_in()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $medicine_id = $this->payload['medicine_id'] ?? null;
        $medicine_stock_in_id = $this->payload['medicine_stock_in_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $medicine_inventories = $this->medicine_stock_in->get_details($medicine_stock_in_id, $medicine_id, $term, $page, $data_size)?:[];

        if(!$medicine_inventories) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No medicine inventories found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->medicine_stock_in->get_details($medicine_stock_in_id, $medicine_id, $term, null, null)?:[]),
                'data' => $medicine_inventories
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create medicine
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$master_medicine_id = $this->_attempt_create_master()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add medicine in master. ",
            ];
        } elseif (!$medicine_id = $this->_attempt_create($master_medicine_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add medicine. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'medicine_id' => $medicine_id,
                'message'    => 'medicine added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a medicine_master
     */
    private function _attempt_create_master()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->medicine_master->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        $values['beginning_inventory'] = $this->payload['qty'] ?? 0;

        if (!$master_medicine_id = $this->medicine_master->insert($values)) {
            $this->error_message = $this->medicine_master->error();
            return false;
        }

        return $master_medicine_id;
    }

    /**
     * Attempt to create a medicine
     */
    private function _attempt_create($master_medicine_id)
    {
        $values = [
            'master_id'             => $master_medicine_id,
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->medicine->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        $values['beginning_inventory'] = $this->payload['qty'] ?? 0;

        if (!$medicine_id = $this->medicine->insert($values)) {
            $this->error_message = $this->medicine->error();
            return false;
        }

        return $medicine_id;
    }

    /**
     * Update medicine
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $medicine_id = $this->payload['medicine_id'];
        $where = [
                    'id' => $medicine_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$medicine = $this->medicine->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'medicine is not found.'
            ];
        } elseif (!$master_medicine_id = $this->_attempt_update($medicine)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update medicine.',
            ];
        } elseif (!$this->_attempt_update_master($master_medicine_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update medicine in master.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'medicine_id' => $medicine_id,
                'message'    => 'medicine updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($medicine)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->medicine->allowedFields is declared at the top of the model
        foreach($this->medicine->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $medicine->id];

        if (!$this->medicine->update($where, $values)) {
            $this->error_message = $this->medicine->error();
            return false;
        }

        return $medicine->master_id;
    }

    /**
     * Attempt update master
     */
    protected function _attempt_update_master($master_medicine_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->medicine->allowedFields is declared at the top of the model
        foreach($this->medicine_master->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $where = ['id' => $master_medicine_id];

        if (!$this->medicine_master->update($where, $values)) {
            $this->error_message = $this->medicine_master->error();
            return false;
        }

        return true;
    }

    /**
     * Stock In
     */
    public function stock_in()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $medicine_id = $this->payload['medicine_id'];
        $where = [
                    'id' => $medicine_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$medicine = $this->medicine->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'medicine is not found.'
            ];
        } elseif (!$this->_attempt_stock_in($medicine)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to stock in medicine.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'medicine_id' => $medicine_id,
                'message'    => 'medicine stocked in successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt stock in
     */
    protected function _attempt_stock_in($medicine)
    {
        $values = [
            'qty'           => $medicine->qty + $this->payload['qty'],
            'unit'          => $this->payload['unit'] ?? $medicine->unit,
            'min_level'          => $this->payload['min_level'] ?? $medicine->min_level,
            'max_level'          => $this->payload['max_level'] ?? $medicine->max_level,
            'critical_level'          => $this->payload['critical_level'] ?? $medicine->critical_level,
            'updated_by'    => $this->payload['requester'],
            'updated_on'    => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $medicine->id];

        if (!$this->medicine->update($where, $values)) {
            $this->error_message = $this->medicine->error();
            return false;
        }

        $where_master = ['id' => $medicine->master_id];

        if (!$this->medicine_master->update($where_master, $values)) {
            $this->error_message = $this->medicine_master->error();
            return false;
        }

        $stock_in_values = [
            'medicine_id'   => $medicine->id,
            'qty'           => $this->payload['qty'],
            'unit'          => $this->payload['unit'] ?? null,
            'remarks'       => $this->payload['remarks'] ?? null,
            'status'       => 'approved', // auto-approved for now
            'approved_by'   => $this->payload['requester'], // auto-approved for now
            'approved_on'   => date('Y-m-d H:i:s'), // auto-approved for now
            'added_by'      => $this->payload['requester'],
            'added_on'      => date('Y-m-d H:i:s')
        ];
        if (!$this->medicine_stock_in->insert($stock_in_values)) {
            $this->error_message = $this->medicine_stock_in->error();
            return false;
        }

        return true;
    }

    /**
     * Stock Out
     */
    public function stock_out()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $medicine_id = $this->payload['medicine_id'];
        $where = [
                    'id' => $medicine_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$medicine = $this->medicine->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'medicine is not found.'
            ];
        } elseif(!$existing_inventory = $this->medicine_inventory->select('', ['medicine_id' => $medicine->id, 'is_deleted' => 0], 1)) {
            $response_status = FAIL;
            $response = [
                'No existing inventory found.',
            ];
        } elseif($existing_inventory->qty < $this->payload['qty']) {
            $response_status = FAIL;
            $response = [
                'Insufficient inventory to stock out the requested quantity.',
            ];
        } elseif (!$this->_attempt_stock_out($medicine, $existing_inventory)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to stock out medicine.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'medicine_id' => $medicine_id,
                'message'    => 'medicine stocked out successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt stock out
     */
    protected function _attempt_stock_out($medicine, $existing_inventory)
    {
        $values = [
            'qty'           => $medicine->qty - $this->payload['qty'],
            'unit'          => $this->payload['unit'] ?? $medicine->unit,
            'min_level'          => $this->payload['min_level'] ?? $medicine->min_level,
            'max_level'          => $this->payload['max_level'] ?? $medicine->max_level,
            'critical_level'          => $this->payload['critical_level'] ?? $medicine->critical_level,
            'updated_by'    => $this->payload['requester'],
            'updated_on'    => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $medicine->id];

        if (!$this->medicine->update($where, $values)) {
            $this->error_message = $this->medicine->error();
            return false;
        }

        $where_master = ['id' => $medicine->master_id];

        if (!$this->medicine_master->update($where_master, $values)) {
            $this->error_message = $this->medicine_master->error();
            return false;
        }

        return true;
    }

    /**
     * Delete medicines
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $medicine_id = $this->payload['medicine_id'];

        $where = ['id' => $medicine_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$medicine = $this->medicine->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'medicine is not found.',
            ];
        } elseif (!$this->_attempt_delete($medicine_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'medicine is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'medicine is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($medicine_id)
    {
        $where = ['id' => $medicine_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->medicine->update($where, $values)) {
            $this->error_message = $this->medicine->error();
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
