<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;

class Vouchers extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $voucher;
    protected $voucher_detail;
    protected $voucher_sequence;
    protected $voucher_holiday;
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
        $this->voucher = new \App\Models\Voucher();
        $this->voucher_detail = new \App\Models\Voucher_detail();
        $this->voucher_holiday = new \App\Models\Voucher_holiday();
        $this->voucher_sequence = new \App\Models\Voucher_sequence();
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

        $this->voucher = new \App\Models\Voucher($db);
        $this->voucher_detail = new \App\Models\Voucher_detail($db);
        $this->voucher_holiday = new \App\Models\Voucher_holiday($db);
        $this->voucher_sequence = new \App\Models\Voucher_sequence($db);

        return true;
    }

    /**
     * Get vouchers
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $voucher_id = $this->payload['voucher_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $vouchers = $this->voucher->get_details($voucher_id, $status, $term, $page, $data_size)?:[];

        if(!$vouchers) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No vouchers found.'
            ];
        } else {
            foreach ($vouchers as $key => $voucher) {
                $vouchers[$key]['holidays'] = $this->voucher_holiday->get_holidays($voucher['id']) ?: [];
                $vouchers[$key]['details'] = $this->voucher_detail->get_details($voucher['id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->voucher->get_details($voucher_id, $status, $term, null, null)?:[]),
                'data' => $vouchers
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create voucher
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
            $this->voucher->select('id', ['is_deleted' => 0]) ?? []
        );

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
                $this->voucher->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$this->payload['voucher_code_from'] || !$this->payload['voucher_code_to']) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Voucher code range is required. ",
            ];
        } elseif ($this->_has_duplicate('voucher', ['name' => $this->payload['name'], 'voucher_code_from' => $this->payload['voucher_code_from'], 'voucher_code_to' => $this->payload['voucher_code_to'], 'is_deleted' => 0])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Voucher ". $this->payload['name'] ." code range already exists. ",
            ];
        } elseif (!$voucher_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add voucher. ",
            ];
        } elseif (!$this->_attempt_create_details($voucher_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add voucher details. ",
            ];
        } elseif (!$this->_attempt_create_holidays($voucher_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add voucher holidays. ",
            ];
        } elseif (!$this->_attempt_create_sequences($voucher_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add voucher sequences. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'voucher_id' => $voucher_id,
                'message'    => 'voucher added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a voucher
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->voucher->allowedFields AS $field) {
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

            if (!$voucher_id = $this->voucher->insert($values)) {
                $this->error_message = $this->voucher->error();
                return false;
            }
        }

        return $voucher_id;
    }

    /**
     * Attempt to create voucher details
     */
    private function _attempt_create_details($voucher_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'voucher_id'    => $voucher_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->voucher_detail->insert($values)) {
                    $this->error_message = $this->voucher_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create voucher holidays
     */
    private function _attempt_create_holidays($voucher_id)
    {
        if (!array_key_exists('holidays', $this->payload) || !is_array($this->payload['holidays']) || count($this->payload['holidays']) == 0) {
            return true;
        }

        foreach ($this->payload['holidays'] as $holiday) {
            $values = [
                'voucher_id'    => $voucher_id,
                'date'   => array_key_exists('date', $holiday) && $holiday['date'] != '' ? $holiday['date'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->voucher_holiday->insert($values)) {
                    $this->error_message = $this->voucher_holiday->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create voucher sequences
     */
    private function _attempt_create_sequences($voucher_id)
    {
        if (!$this->payload['voucher_code_from'] || !$this->payload['voucher_code_to']) {
            return true; // no range provided
        }

        $from = (int)$this->payload['voucher_code_from'];
        $to   = (int)$this->payload['voucher_code_to'];

        if ($from <= 0 || $to <= 0 || $from > $to) {
            return true; // invalid range
        }

        $padLength = strlen((string)$to); // ← number of digits needed

        $chunkSize = 1000; // ✅ insert 1000 rows at a time
        $batch = [];

        for ($code = $from; $code <= $to; $code++) {
            $batch[] = [
                'voucher_id'   => $voucher_id,
                'voucher_code' => str_pad($code, $padLength, '0', STR_PAD_LEFT),
                'added_by'     => $this->payload['requester'],
                'added_on'     => date('Y-m-d H:i:s'),
            ];

            if (count($batch) >= $chunkSize) {
                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
    
                    if (!$this->voucher_sequence->insert_batch($batch)) {
                        $this->error_message = $this->voucher_sequence->error();
                        return false;
                    }
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->voucher_sequence->insert_batch($batch)) {
                    $this->error_message = $this->voucher_sequence->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update voucher
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $voucher_id = $this->payload['voucher_id'];
        $where = [
                    'id' => $voucher_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();
        
        // -----------------------------
        // 1️⃣ Check data consistency
        // -----------------------------

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->voucher->select('id', ['is_deleted' => 0]) ?? []
        );

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
                $this->voucher->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }
        if (!$voucher = $this->voucher->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'voucher is not found.'
            ];
        } elseif (!$this->payload['voucher_code_from'] || !$this->payload['voucher_code_to']) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Voucher code range is required. ",
            ];
        } elseif ($this->_has_duplicate('voucher', ['name' => $this->payload['name'], 'voucher_code_from' => $this->payload['voucher_code_from'], 'voucher_code_to' => $this->payload['voucher_code_to'], 'is_deleted' => 0], $voucher->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Voucher ". $this->payload['name'] ." code range already exists. ",
            ];
        } elseif (!$this->_attempt_update($voucher)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update voucher.',
            ];
        } elseif (!$this->_attempt_update_sequences($voucher->id, $voucher->voucher_code_from, $voucher->voucher_code_to)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $this->error_message ?: "Failed to update voucher sequences."
            ];        
        } elseif (!$this->_attempt_update_details($voucher->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update voucher details. ",
            ];
        } elseif (!$this->_attempt_update_holidays($voucher->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update voucher holidays. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'voucher_id' => $voucher_id,
                'message'    => 'voucher updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($voucher)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->voucher->allowedFields is declared at the top of the model
        foreach($this->voucher->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $voucher->id];

        // Update branches
        foreach ([0 ,1, 2, 3, 4, 7, 5, 6] as $branch_id) {

            $this->resolve_branch_models($branch_id);

            if (!$this->voucher->update($where, $values)) {
                $this->error_message = $this->voucher->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to update voucher details
     */
    private function _attempt_update_details($voucher_id)
    {
        // 1. Get all current detail IDs for this voucher
        $existing_details = $this->voucher_detail->select('id', ['voucher_id' => $voucher_id, 'is_deleted' => 0]);

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
                // Update branches
                foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
        
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->voucher_detail->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->voucher_detail->error();
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
                'voucher_id'  => $voucher_id,
                'lab_test_id' => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Update branches
                foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
        
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->voucher_detail->update(['id' => $detail['id']], $values)) {
                        $this->error_message = $this->voucher_detail->error();
                        return false;
                    }
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');
                
                // Update branches
                foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
        
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->voucher_detail->insert($values)) {
                        $this->error_message = $this->voucher_detail->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update voucher holidays
     */
    private function _attempt_update_holidays($voucher_id)
    {
        // 1. Get all current holiday IDs for this voucher
        $existing_holidays = $this->voucher_holiday->select('id', ['voucher_id' => $voucher_id, 'is_deleted' => 0]);

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
                
                // Update branches
                foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
        
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->voucher_holiday->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->voucher_holiday->error();
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
                'voucher_id'  => $voucher_id,
                'date'   => array_key_exists('date', $holiday) && $holiday['date'] != '' ? $holiday['date'] : null,
            ];

            if (!empty($holiday['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');
                
                // Update branches
                foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
        
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->voucher_holiday->update(['id' => $holiday['id']], $values)) {
                        $this->error_message = $this->voucher_holiday->error();
                        return false;
                    }
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');
                
                // Update branches
                foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
        
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->voucher_holiday->insert($values)) {
                        $this->error_message = $this->voucher_holiday->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update voucher sequences
     */
    private function _attempt_update_sequences($voucher_id, $old_from, $old_to)
    {
        if (!$this->payload['voucher_code_from'] || !$this->payload['voucher_code_to']) {
            return true; // no range provided
        }

        $new_from = (int)$this->payload['voucher_code_from'];
        $new_to   = (int)$this->payload['voucher_code_to'];

        if ($new_from <= 0 || $new_to <= 0 || $new_from > $new_to) {
            return true; // invalid range
        }

        $padLength = strlen((string)$new_to);

        // 1. If new_to > old_to → add missing sequences
        if ($new_to > $old_to) {
            $chunkSize = 1000;
            $batch = [];

            for ($code = $old_to + 1; $code <= $new_to; $code++) {
                $batch[] = [
                    'voucher_id'   => $voucher_id,
                    'voucher_code' => str_pad($code, $padLength, '0', STR_PAD_LEFT),
                    'added_by'     => $this->payload['requester'],
                    'added_on'     => date('Y-m-d H:i:s'),
                ];

                if (count($batch) >= $chunkSize) {
                    // Update branches
                    foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
            
                        $this->resolve_branch_models($branch_id);
            
                        if (!$this->voucher_sequence->insert_batch($batch)) {
                            $this->error_message = $this->voucher_sequence->error();
                            return false;
                        }
                    }
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                // Update branches
                foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
            
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->voucher_sequence->insert_batch($batch)) {
                        $this->error_message = $this->voucher_sequence->error();
                        return false;
                    }
                }
            }
        }

        // 2. If new_to < old_to → mark excess as deleted (but only if not redeemed)
        if ($new_to < $old_to) {
            // get not redeemed excess
            $excess = $this->voucher_sequence->get_range($voucher_id, $new_to, $old_to);

            if (count($excess) == 0) {
                return true; // nothing to delete
            }

            if (count($excess) > 0) {
                $this->error_message = "Not enough unredeemed rows to shrink sequence range. Some codes already redeemed.";
                return false;
            }

            // Mark as deleted
            $ids = array_column($excess, 'id');
            // Update branches
            foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
        
                $this->resolve_branch_models($branch_id);
    
                if (!$this->voucher_sequence->whereIn('id', $ids)->set(['is_deleted' => 1])->update()) {
                    $this->error_message = $this->voucher_sequence->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Delete vouchers
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $voucher_id = $this->payload['voucher_id'];

        $where = ['id' => $voucher_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$voucher = $this->voucher->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'voucher is not found.',
            ];
        } elseif (!$this->_attempt_delete($voucher_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'voucher is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'voucher is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($voucher_id)
    {
        $where = ['id' => $voucher_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // Update branches
        foreach ([0 ,1, 2, 3, 4, 5, 6, 7] as $branch_id) {
    
            $this->resolve_branch_models($branch_id);

            if (!$this->voucher->update($where, $values)) {
                $this->error_message = $this->voucher->error();
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
        return 'voucher';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['name', 'voucher_code_from', 'voucher_code_to'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['name', 'voucher_code_from', 'voucher_code_to'];
    }

    protected function _consistency_compare_fields(): array
    {
        return [
            'name', 'description', 'voucher_code_from', 'voucher_code_to', 'voucher_type',
            'valid_from', 'valid_to', 'amount', 'percentage', 'price',
            'max_purchase', 'time_from', 'time_to', 'days_of_week',
            'is_all', 'is_any_service', 'no_min', 'no_max', 'no_limit', 'any_day',
            'not_time_bound', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [
            ['table' => 'voucher_detail', 'fk' => 'voucher_id'],
            ['table' => 'voucher_holiday', 'fk' => 'voucher_id'],
            ['table' => 'voucher_sequence', 'fk' => 'voucher_id'],
        ];
    }
}
