<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;

class Giftchecks extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $giftcheck;
    protected $giftcheck_detail;
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
        $this->giftcheck = new \App\Models\Giftcheck();
        $this->giftcheck_detail = new \App\Models\Giftcheck_detail();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }
    
    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $dbMap = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->giftcheck = new \App\Models\Giftcheck($db);
        $this->giftcheck_detail = new \App\Models\Giftcheck_detail($db);

        return true;
    }

    /**
     * Get giftchecks
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $giftcheck_id = $this->payload['giftcheck_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $giftchecks = $this->giftcheck->get_details($giftcheck_id, $status, $term, $page, $data_size)?:[];

        if(!$giftchecks) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No giftchecks found.'
            ];
        } else {
            foreach ($giftchecks as $key => $giftcheck) {
                $giftchecks[$key]['details'] = $this->giftcheck_detail->get_details($giftcheck['id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->giftcheck->get_details($giftcheck_id, $status, $term, null, null)?:[]),
                'data' => $giftchecks
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create giftcheck
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->giftcheck->select('id', ['is_deleted' => 0]) ?? []
        );

        // validate duplicate giftcheck code
        if ($this->_has_duplicate('giftcheck', ['giftcheck_code' => $this->payload['giftcheck_code']])) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Giftcheck code '. $this->payload['giftcheck_code'] . ' already exists.']);
        }

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        foreach ($branches as $branch_id => $branch_name) {

            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->giftcheck->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$giftcheck_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add giftcheck. ",
            ];
        } elseif (!$this->_attempt_create_details($giftcheck_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add giftcheck details. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'giftcheck_id' => $giftcheck_id,
                'message'    => 'giftcheck added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a giftcheck
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->giftcheck->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        // 2️⃣ Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$giftcheck_id = $this->giftcheck->insert($values)) {
                $this->error_message = $this->giftcheck->error();
                return false;
            }
        }

        return $giftcheck_id;
    }

    /**
     * Attempt to create giftcheck details
     */
    private function _attempt_create_details($giftcheck_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'giftcheck_id'    => $giftcheck_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->giftcheck_detail->insert($values)) {
                    $this->error_message = $this->giftcheck_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update giftcheck
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $giftcheck_id = $this->payload['giftcheck_id'];
        $where = [
                    'id' => $giftcheck_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->giftcheck->select('id', ['is_deleted' => 0]) ?? []
        );

        // validate duplicate giftcheck code
        if ($this->_has_duplicate('giftcheck', ['giftcheck_code' => $this->payload['giftcheck_code']], $giftcheck_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Giftcheck code ' . $this->payload['giftcheck_code'] . ' already exists.']);
        }

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        foreach ($branches as $branch_id => $branch_name) {

            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->giftcheck->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$giftcheck = $this->giftcheck->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'giftcheck is not found.'
            ];
        } elseif (!$this->_attempt_update($giftcheck)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update giftcheck.',
            ];
        } elseif (!$this->_attempt_update_details($giftcheck->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update giftcheck details. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'giftcheck_id' => $giftcheck_id,
                'message'    => 'giftcheck updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($giftcheck)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->giftcheck->allowedFields is declared at the top of the model
        foreach($this->giftcheck->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $giftcheck->id];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->giftcheck->update($where, $values)) {
                $this->error_message = $this->giftcheck->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to update giftcheck details
     */
    private function _attempt_update_details($giftcheck_id)
    {
        // 1. Get all current detail IDs for this giftcheck
        $existing_details = $this->giftcheck_detail->select('id', ['giftcheck_id' => $giftcheck_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_details, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('details', $this->payload) && is_array($this->payload['details'])) {
            foreach ($this->payload['details'] as $detail) {
                if (!empty($detail['id'])) {
                    $payload_ids[] = (int)$detail['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->giftcheck_detail->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->giftcheck_detail->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload details (update existing or insert new)
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details'])) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'giftcheck_id'  => $giftcheck_id,
                'lab_test_id' => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->giftcheck_detail->update(['id' => $detail['id']], $values)) {
                        $this->error_message = $this->giftcheck_detail->error();
                        return false;
                    }
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->giftcheck_detail->insert($values)) {
                        $this->error_message = $this->giftcheck_detail->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Delete giftchecks
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $giftcheck_id = $this->payload['giftcheck_id'];

        $where = ['id' => $giftcheck_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$giftcheck = $this->giftcheck->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'giftcheck is not found.',
            ];
        } elseif (!$this->_attempt_delete($giftcheck_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'giftcheck is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'giftcheck is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($giftcheck_id)
    {
        $where = ['id' => $giftcheck_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->giftcheck->update($where, $values)) {
                $this->error_message = $this->giftcheck->error();
                return false;
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


    protected function _consistency_table(): string
    {
        return 'giftcheck';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['giftcheck_code'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['giftcheck_code', 'description'];
    }

    protected function _consistency_compare_fields(): array
    {
        return [
            'description', 'giftcheck_code', 'valid_from', 'valid_to',
            'amount', 'is_all', 'is_any_service', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [
            ['table' => 'giftcheck_detail', 'fk' => 'giftcheck_id'],
        ];
    }
}
