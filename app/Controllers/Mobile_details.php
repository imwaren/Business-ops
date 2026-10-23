<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Mobile_detail;

class Mobile_details extends MYT_Controller
{
    protected $request;
    protected $mobile_detail;
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
        $this->mobile_detail = new Mobile_detail();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get mobile_details
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $mobile_detail_id = $this->payload['mobile_detail_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $mobile_details = $this->mobile_detail->get_details($mobile_detail_id, $type, $term, $page, $data_size);
        $size = count($this->mobile_detail->get_details($mobile_detail_id, $type, $term, null, null)?:[]);

        if(!$mobile_details) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No mobile_details found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $mobile_details
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create mobile_detail
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$mobile_detail_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add mobile_detail. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'mobile_detail_id' => $mobile_detail_id,
                'message'    => 'mobile_detail added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a mobile_detail
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->mobile_detail->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$mobile_detail_id = $this->mobile_detail->insert($values)) {
            $this->error_message = $this->mobile_detail->error();
            return false;
        }

        return $mobile_detail_id;
    }

    /**
     * Update mobile_detail
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $mobile_detail_id = $this->payload['mobile_detail_id'];
        $where = [
                    'id' => $mobile_detail_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$mobile_detail = $this->mobile_detail->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'mobile_detail is not found.'
            ];
        } elseif (!$this->_attempt_update($mobile_detail)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update mobile_detail.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'mobile_detail_id' => $mobile_detail_id,
                'message'    => 'mobile_detail updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($mobile_detail)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->mobile_detail->allowedFields is declared at the top of the model
        foreach($this->mobile_detail->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $mobile_detail->id];

        if (!$this->mobile_detail->update($where, $values)) {
            $this->error_message = $this->mobile_detail->error();
            return false;
        }
        return true;
    }

    /**
     * Delete mobile_details
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $mobile_detail_id = $this->payload['mobile_detail_id'];

        $where = ['id' => $mobile_detail_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$mobile_detail = $this->mobile_detail->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'mobile_detail is not found.',
            ];
        } elseif (!$this->_attempt_delete($mobile_detail_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'mobile_detail is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'mobile_detail is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($mobile_detail_id)
    {
        $where = ['id' => $mobile_detail_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->mobile_detail->update($where, $values)) {
            $this->error_message = $this->mobile_detail->error();
            return false;
        }

        return true;
    }
}
