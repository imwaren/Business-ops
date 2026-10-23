<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Item_conversion;

class Vaccines extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $vaccine;
    protected $vaccine_material;
    protected $item_conversion;
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
        $this->vaccine = new \App\Models\Vaccine();
        $this->vaccine_material = new \App\Models\Vaccine_material();
        $this->item_conversion = new Item_conversion();
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

        $this->vaccine = new \App\Models\Vaccine($db);
        $this->vaccine_material = new \App\Models\Vaccine_material($db);

        return true;
    }

    /**
     * Get vaccines
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $vaccine_id = $this->payload['vaccine_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $vaccines = $this->vaccine->get_details($vaccine_id, $term, $page, $data_size)?:[];

        if(!$vaccines) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No vaccines found.'
            ];
        } else {
            foreach ($vaccines as $key => $vaccine) {
                $materials = $this->vaccine_material->get_materials($vaccine['id'])?:[];
                if ($materials) {
                    foreach ($materials as $mkey => $material) {
                        $item_conversion = $this->item_conversion->select('id, conversion_type, qty, unit', ['item_id' => $material['item_id'], 'is_deleted' => 0]) ?: [];

                        $materials[$mkey]['conversions'] = $item_conversion;
                    }
                }
                $vaccines[$key]['materials'] = $materials;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->vaccine->get_details($vaccine_id, $term, null, null)?:[]),
                'data' => $vaccines
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create vaccine
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->vaccine->select('id', ['is_deleted' => 0]) ?? []
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
                $this->vaccine->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$vaccine_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add vaccine. ",
            ];
        } elseif (!$this->_attempt_create_materials($vaccine_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add vaccine materials. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'vaccine_id' => $vaccine_id,
                'message'    => 'vaccine added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a vaccine
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->vaccine->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';
        
        // 1️⃣ Insert into MASTER first
        $this->resolve_branch_models(0);
        $vaccine_id = $this->vaccine->insert($values);
        if (!$vaccine_id) {
            $this->error_message = $this->vaccine->error();
            return false;
        }

        // 2️⃣ Insert into other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->vaccine->insert($values)) {
                $this->error_message = $this->vaccine->error();
                return false;
            }
        }

        return $vaccine_id;
    }

    /**
     * Attempt to create vaccine materials
     */
    private function _attempt_create_materials($vaccine_id)
    {
        if (!array_key_exists('materials', $this->payload) || !is_array($this->payload['materials']) || count($this->payload['materials']) == 0) {
            return true;
        }

        foreach ($this->payload['materials'] as $material) {
            if (!array_key_exists('item_id', $material) || $material['item_id'] == '') {
                continue;
            }
            
            $values = [
                'vaccine_id'    => $vaccine_id,
                'item_id'   => array_key_exists('item_id', $material) && $material['item_id'] != '' ? $material['item_id'] : null,
                'qty'   => array_key_exists('qty', $material) && $material['qty'] != '' ? $material['qty'] : 0,
                'unit'   => array_key_exists('unit', $material) && $material['unit'] != '' ? $material['unit'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->vaccine_material->insert($values)) {
                    $this->error_message = $this->vaccine_material->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update vaccine
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $vaccine_id = $this->payload['vaccine_id'];
        $where = [
                    'id' => $vaccine_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->vaccine->select('id', ['is_deleted' => 0]) ?? []
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
                $this->vaccine->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$vaccine = $this->vaccine->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'vaccine is not found.'
            ];
        } elseif (!$this->_attempt_update($vaccine)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update vaccine.',
            ];
        } elseif (!$this->_attempt_update_materials($vaccine->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update vaccine materials. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'vaccine_id' => $vaccine_id,
                'message'    => 'vaccine updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($vaccine)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->vaccine->allowedFields is declared at the top of the model
        foreach($this->vaccine->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $vaccine->id];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->vaccine->update($where, $values)) {
                $this->error_message = $this->vaccine->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to update vaccine materials
     */
    private function _attempt_update_materials($vaccine_id)
    {
        // 1. Get all current material IDs for this vaccine
        $existing_materials = $this->vaccine_material->select('id', ['vaccine_id' => $vaccine_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_materials, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('materials', $this->payload) && is_array($this->payload['materials'])) {
            foreach ($this->payload['materials'] as $material) {
                if (!empty($material['id'])) {
                    $payload_ids[] = (int)$material['id'];
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
        
                    if (!$this->vaccine_material->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->vaccine_material->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload materials (update existing or insert new)
        if (!array_key_exists('materials', $this->payload) || !is_array($this->payload['materials'])) {
            return true;
        }

        foreach ($this->payload['materials'] as $material) {
            $values = [
                'vaccine_id'  => $vaccine_id,
                'item_id'   => array_key_exists('item_id', $material) && $material['item_id'] != '' ? $material['item_id'] : null,
                'qty'   => array_key_exists('qty', $material) && $material['qty'] != '' ? $material['qty'] : 0,
                'unit'   => array_key_exists('unit', $material) && $material['unit'] != '' ? $material['unit'] : 0,
            ];

            if (!empty($material['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->vaccine_material->update(['id' => $material['id']], $values)) {
                        $this->error_message = $this->vaccine_material->error();
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
        
                    if (!$this->vaccine_material->insert($values)) {
                        $this->error_message = $this->vaccine_material->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Delete vaccines
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $vaccine_id = $this->payload['vaccine_id'];

        $where = ['id' => $vaccine_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$vaccine = $this->vaccine->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'vaccine is not found.',
            ];
        } elseif (!$this->_attempt_delete($vaccine_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'vaccine is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'vaccine is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($vaccine_id)
    {
        $where = ['id' => $vaccine_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->vaccine->update($where, $values)) {
                $this->error_message = $this->vaccine->error();
                return false;
            }
        }

        // Mark related materials as deleted
        $materials = $this->vaccine_material->select('id', ['vaccine_id' => $vaccine_id, 'is_deleted' => 0]);
        foreach ($materials as $material) {
            $material_where = ['id' => $material['id']];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);
    
                if (!$this->vaccine_material->update($material_where, $values)) {
                    $this->error_message = $this->vaccine_material->error();
                    return false;
                }
            }
        }

        return true;
    }


    protected function _consistency_table(): string
    {
        return 'vaccine';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['vax', 'shot', 'type', 'generic', 'brand'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['vax', 'shot', 'type', 'generic', 'brand'];
    }

    protected function _consistency_compare_fields(): array
    {
        return [
            'vax', 'shot', 'type', 'generic', 'brand',
            'cost_price', 'description', 'remarks', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [
            ['table' => 'vaccine_material', 'fk' => 'vaccine_id'],
        ];
    }
}
