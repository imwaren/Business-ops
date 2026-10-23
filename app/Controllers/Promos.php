<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Branch_master;

class Promos extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $promo;
    protected $promo_detail;
    protected $promo_holiday;
    protected $promo_branch;
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
        $this->promo = new \App\Models\Promo();
        $this->promo_detail = new \App\Models\Promo_detail();
        $this->promo_holiday = new \App\Models\Promo_holiday();
        $this->promo_branch = new \App\Models\Promo_branch();
        $this->branch_master = new Branch_master();
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

        $this->promo = new \App\Models\Promo($db);
        $this->promo_detail = new \App\Models\Promo_detail($db);
        $this->promo_holiday = new \App\Models\Promo_holiday($db);
        $this->promo_branch = new \App\Models\Promo_branch($db);

        return true;
    }

    /**
     * Get promos
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $promo_id = $this->payload['promo_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $promos = $this->promo->get_details($promo_id, $status, $term, $page, $data_size)?:[];

        if(!$promos) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No promos found.'
            ];
        } else {
            foreach ($promos as $key => $promo) {
                $promos[$key]['branches'] = $this->promo_branch->get_branches($promo['id']) ?: [];
                foreach ($promos[$key]['branches'] as $bkey => $branch) {
                    if ($branch['branch_id']) {
                        $branch_info = $this->branch_master->select('name', ['id' => $branch['branch_id'], 'is_deleted' => 0], 1);
                        $promos[$key]['branches'][$bkey]['branch_name'] = $branch_info ? $branch_info->name : null;
                    } else {
                        $promos[$key]['branches'][$bkey]['branch_name'] = null;
                    }
                }
                $promos[$key]['holidays'] = $this->promo_holiday->get_holidays($promo['id']) ?: [];
                $promos[$key]['details'] = $this->promo_detail->get_details($promo['id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->promo->get_details($promo_id, $status, $term, null, null)?:[]),
                'data' => $promos
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create promo
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        // -----------------------------
        // 1️⃣ Check data consistency
        // -----------------------------

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->promo->select('id', ['is_deleted' => 0]) ?? []
        );

        // validate duplicate promo code
        if ($this->_has_duplicate('promo', ['code' => $this->payload['code']])) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Promo code '. $this->payload['code'] . ' already exists.']);
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
                $this->promo->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$promo_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add promo. ",
            ];
        } elseif (!$this->_attempt_create_details($promo_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add promo details. ",
            ];
        } elseif (!$this->_attempt_create_holidays($promo_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add promo holidays. ",
            ];
        } elseif (!$this->_attempt_create_branches($promo_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add promo branches. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'promo_id' => $promo_id,
                'message'    => 'promo added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a promo
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->promo->allowedFields AS $field) {
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

            if (!$promo_id = $this->promo->insert($values)) {
                $this->error_message = $this->promo->error();
                return false;
            }
        }

        return $promo_id;
    }

    /**
     * Attempt to create promo details
     */
    private function _attempt_create_details($promo_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'promo_id'    => $promo_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];
            
            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->promo_detail->insert($values)) {
                    $this->error_message = $this->promo_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create promo holidays
     */
    private function _attempt_create_holidays($promo_id)
    {
        if (!array_key_exists('holidays', $this->payload) || !is_array($this->payload['holidays']) || count($this->payload['holidays']) == 0) {
            return true;
        }

        foreach ($this->payload['holidays'] as $holiday) {
            $values = [
                'promo_id'    => $promo_id,
                'date'   => array_key_exists('date', $holiday) && $holiday['date'] != '' ? $holiday['date'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];
            
            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->promo_holiday->insert($values)) {
                    $this->error_message = $this->promo_holiday->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create promo branches
     */
    private function _attempt_create_branches($promo_id)
    {
        if (!array_key_exists('branches', $this->payload) || !is_array($this->payload['branches']) || count($this->payload['branches']) == 0) {
            return true;
        }

        foreach ($this->payload['branches'] as $branch) {
            $values = [
                'promo_id'    => $promo_id,
                'branch_id'   => array_key_exists('branch_id', $branch) && $branch['branch_id'] != '' ? $branch['branch_id'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];
            
            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->promo_branch->insert($values)) {
                    $this->error_message = $this->promo_branch->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update promo
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $promo_id = $this->payload['promo_id'];
        $where = [
                    'id' => $promo_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        // -----------------------------
        // 1️⃣ Check data consistency
        // -----------------------------

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->promo->select('id', ['is_deleted' => 0]) ?? []
        );

        // validate duplicate promo code
        if ($this->_has_duplicate('promo', ['code' => $this->payload['code']], $promo_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Promo code ' . $this->payload['code'] . ' already exists.']);
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
                $this->promo->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$promo = $this->promo->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'promo is not found.'
            ];
        } elseif (!$this->_attempt_update($promo)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update promo.',
            ];
        } elseif (!$this->_attempt_update_details($promo->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update promo details. ",
            ];
        } elseif (!$this->_attempt_update_holidays($promo->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update promo holidays. ",
            ];
        } elseif (!$this->_attempt_update_branches($promo->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update promo branches. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'promo_id' => $promo_id,
                'message'    => 'promo updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($promo)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->promo->allowedFields is declared at the top of the model
        foreach($this->promo->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $promo->id];
            
        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->promo->update($where, $values)) {
                $this->error_message = $this->promo->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to update promo details
     */
    private function _attempt_update_details($promo_id)
    {
        // 1. Get all current detail IDs for this promo
        $existing_details = $this->promo_detail->select('id', ['promo_id' => $promo_id, 'is_deleted' => 0]);

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
        
                    if (!$this->promo_detail->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->promo_detail->error();
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
                'promo_id'  => $promo_id,
                'lab_test_id' => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');
            
                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->promo_detail->update(['id' => $detail['id']], $values)) {
                        $this->error_message = $this->promo_detail->error();
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
        
                    if (!$this->promo_detail->insert($values)) {
                        $this->error_message = $this->promo_detail->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update promo holidays
     */
    private function _attempt_update_holidays($promo_id)
    {
        // 1. Get all current holiday IDs for this promo
        $existing_holidays = $this->promo_holiday->select('id', ['promo_id' => $promo_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_holidays, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('holidays', $this->payload) && is_array($this->payload['holidays'])) {
            foreach ($this->payload['holidays'] as $holiday) {
                if (!empty($holiday['id'])) {
                    $payload_ids[] = (int)$holiday['id'];
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
        
                    if (!$this->promo_holiday->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->promo_holiday->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload holidays (update existing or insert new)
        if (!array_key_exists('holidays', $this->payload) || !is_array($this->payload['holidays'])) {
            return true;
        }

        foreach ($this->payload['holidays'] as $holiday) {
            $values = [
                'promo_id'  => $promo_id,
                'date'   => array_key_exists('date', $holiday) && $holiday['date'] != '' ? $holiday['date'] : null,
            ];

            if (!empty($holiday['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');
                
                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->promo_holiday->update(['id' => $holiday['id']], $values)) {
                        $this->error_message = $this->promo_holiday->error();
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
        
                    if (!$this->promo_holiday->insert($values)) {
                        $this->error_message = $this->promo_holiday->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update promo branches
     */
    private function _attempt_update_branches($promo_id)
    {
        // 1. Get all current branch IDs for this promo
        $existing_branches = $this->promo_branch->select('id', ['promo_id' => $promo_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_branches, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('branches', $this->payload) && is_array($this->payload['branches'])) {
            foreach ($this->payload['branches'] as $branch) {
                if (!empty($branch['id'])) {
                    $payload_ids[] = (int)$branch['id'];
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
        
                    if (!$this->promo_branch->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->promo_branch->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload branches (update existing or insert new)
        if (!array_key_exists('branches', $this->payload) || !is_array($this->payload['branches'])) {
            return true;
        }

        foreach ($this->payload['branches'] as $branch) {
            $values = [
                'promo_id'  => $promo_id,
                'branch_id'   => array_key_exists('branch_id', $branch) && $branch['branch_id'] != '' ? $branch['branch_id'] : null,
            ];

            if (!empty($branch['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');
                
                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->promo_branch->update(['id' => $branch['id']], $values)) {
                        $this->error_message = $this->promo_branch->error();
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
        
                    if (!$this->promo_branch->insert($values)) {
                        $this->error_message = $this->promo_branch->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Delete promos
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $promo_id = $this->payload['promo_id'];

        $where = ['id' => $promo_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$promo = $this->promo->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'promo is not found.',
            ];
        } elseif (!$this->_attempt_delete($promo_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'promo is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'promo is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($promo_id)
    {
        $where = ['id' => $promo_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];
                
        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->promo->update($where, $values)) {
                $this->error_message = $this->promo->error();
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
        return 'promo';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['name', 'code'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['name', 'code'];
    }

    protected function _consistency_compare_fields(): array
    {
        return [
            'name', 'description', 'code', 'type', 'valid_from', 'valid_to',
            'amount', 'percentage', 'price', 'minimum_purchase', 'max_purchase',
            'time_from', 'time_to', 'days_of_week', 'is_all', 'is_any_service',
            'redemption_value', 'no_min', 'no_max', 'no_limit', 'any_day',
            'not_time_bound', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [
            ['table' => 'promo_detail', 'fk' => 'promo_id'],
            ['table' => 'promo_holiday', 'fk' => 'promo_id'],
            ['table' => 'promo_branch', 'fk' => 'promo_id'],
        ];
    }
}
