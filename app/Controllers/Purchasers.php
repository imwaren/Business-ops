<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Purchaser;

class Purchasers extends MYT_Controller
{
    protected $request;
    protected $purchaser;
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
        $this->purchaser = new Purchaser();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get purchasers
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $purchaser_id = $this->payload['purchaser_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $purchasers = $this->purchaser->get_details($purchaser_id, $term, $page, $data_size);
        $size = count($this->purchaser->get_details($purchaser_id, $term, null, null)?:[]);

        if(!$purchasers) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No purchasers found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $purchasers
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create purchaser
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$purchaser_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add purchaser. ",
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'purchaser already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'purchaser_id' => $purchaser_id,
                'message'    => 'purchaser added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a purchaser
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->purchaser->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$purchaser_id = $this->purchaser->insert($values)) {
            $this->error_message = $this->purchaser->error();
            return false;
        }

        return $purchaser_id;
    }

    /**
     * Update purchaser
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        var_dump($this->payload); die();

        $purchaser_id = $this->payload['purchaser_id'];
        $where = [
                    'id' => $purchaser_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$purchaser = $this->purchaser->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchaser is not found.'
            ];
        } elseif (!$this->_attempt_update($purchaser)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update purchaser.',
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'purchaser already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'purchaser_id' => $purchaser_id,
                'message'    => 'purchaser updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($purchaser)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->purchaser->allowedFields is declared at the top of the model
        foreach($this->purchaser->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $purchaser->id];

        if (!$this->purchaser->update($where, $values)) {
            $this->error_message = $this->purchaser->error();
            return false;
        }
        return true;
    }

    /**
     * Delete purchasers
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $purchaser_id = $this->payload['purchaser_id'];

        $where = ['id' => $purchaser_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$purchaser = $this->purchaser->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'purchaser is not found.',
            ];
        } elseif (!$this->_attempt_delete($purchaser_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'purchaser is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'purchaser is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($purchaser_id)
    {
        $where = ['id' => $purchaser_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->purchaser->update($where, $values)) {
            $this->error_message = $this->purchaser->error();
            return false;
        }

        return true;
    }
}
