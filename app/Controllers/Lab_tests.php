<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;

use App\Models\Item;
use App\Models\Item_conversion;
use App\Models\Branch_master;

class Lab_tests extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $lab_test;
    protected $lab_test_material;
    protected $lab_test_hmo;
    protected $lab_test_machine;

    protected $package;
    protected $package_detail;

    protected $item;
    protected $item_conversion;
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
        $this->lab_test = new \App\Models\Lab_test();
        $this->lab_test_material = new \App\Models\Lab_test_material();
        $this->lab_test_hmo = new \App\Models\Lab_test_hmo();
        $this->lab_test_machine = new \App\Models\Lab_test_machine();

        $this->package = new \App\Models\Package();
        $this->package_detail = new \App\Models\Package_detail();

        $this->item = new Item();
        $this->item_conversion = new Item_conversion();
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

        $this->lab_test = new \App\Models\Lab_test($db);
        $this->lab_test_material = new \App\Models\Lab_test_material($db);
        $this->lab_test_hmo = new \App\Models\Lab_test_hmo($db);
        $this->lab_test_machine = new \App\Models\Lab_test_machine($db);

        $this->package = new \App\Models\Package($db);
        $this->package_detail = new \App\Models\Package_detail($db);

        return true;
    }

    /**
     * Get lab_tests
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $lab_test_id = $this->payload['lab_test_id'] ?? null;
        $is_consulta = $this->payload['is_consulta'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $category_id = $this->payload['category_id'] ?? null;
        $parent_category_id = $this->payload['parent_category_id'] ?? null;
        $group_id = $this->payload['group_id'] ?? null;
        $gender = $this->payload['gender'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $lab_tests = $this->lab_test->get_details($lab_test_id, $is_consulta, $branch_id, $category_id, $parent_category_id, $group_id, $gender, $term, $page, $data_size)?:[];

        if(!$lab_tests) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No lab_tests found.'
            ];
        } else {
            $labTestIds = $this->_collect_ids($lab_tests, 'id');

            $materialsByLabTest = $this->_group_by($this->lab_test_material->get_materials($labTestIds) ?: [], 'lab_test_id');
            $machinesByLabTest = $this->_group_by($this->lab_test_machine->get_machines($labTestIds) ?: [], 'lab_test_id');
            $hmosByLabTest = $this->_group_by($this->lab_test_hmo->get_hmos($labTestIds) ?: [], 'lab_test_id');

            $itemIds = [];
            foreach ($materialsByLabTest as $materials) {
                foreach ($materials as $material) {
                    if (!empty($material['item_id'])) {
                        $itemIds[] = $material['item_id'];
                    }
                }
            }
            foreach ($machinesByLabTest as $machines) {
                foreach ($machines as $machine) {
                    if (!empty($machine['item_id'])) {
                        $itemIds[] = $machine['item_id'];
                    }
                }
            }
            $conversionsByItem = $this->_group_by(
                $this->item_conversion->get_details(array_values(array_unique($itemIds))) ?: [],
                'item_id'
            );

            foreach ($lab_tests as $key => $lab_test) {
                $lid = $lab_test['id'];

                $lab_test_materials = $materialsByLabTest[$lid] ?? [];
                foreach ($lab_test_materials as $mkey => $material) {
                    $conversions = $conversionsByItem[$material['item_id']] ?? [];
                    $lab_test_materials[$mkey]['conversions'] = array_map(static function ($c) {
                        return [
                            'id' => $c['id'],
                            'conversion_type' => $c['conversion_type'],
                            'qty' => $c['qty'],
                            'unit' => $c['unit'],
                        ];
                    }, $conversions);
                }

                $lab_test_machines = $machinesByLabTest[$lid] ?? [];
                foreach ($lab_test_machines as $mkey => $machine) {
                    $conversions = $conversionsByItem[$machine['item_id']] ?? [];
                    $lab_test_machines[$mkey]['conversions'] = array_map(static function ($c) {
                        return [
                            'id' => $c['id'],
                            'conversion_type' => $c['conversion_type'],
                            'qty' => $c['qty'],
                            'unit' => $c['unit'],
                        ];
                    }, $conversions);
                }

                $lab_tests[$key]['materials'] = $lab_test_materials;
                $lab_tests[$key]['hmo_prices'] = $hmosByLabTest[$lid] ?? [];
                $lab_tests[$key]['machines'] = $lab_test_machines;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $this->lab_test->get_count($lab_test_id, $is_consulta, $branch_id, $category_id, $parent_category_id, $group_id, $gender, $term),
                'data' => $lab_tests
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get lab_tests
     */
    public function search()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $lab_test_id = $this->payload['lab_test_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $lab_tests = $this->lab_test->search_details($lab_test_id, $term, $page, $data_size)?:[];

        if(!$lab_tests) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No lab_tests found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $this->lab_test->search_details_count($lab_test_id, $term),
                'data' => $lab_tests
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create lab_test
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->lab_test->select('id', ['is_deleted' => 0]) ?? []
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
                $this->lab_test->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        $name = $this->_normalize_name($this->payload['name'] ?? null);
        $this->payload['name'] = $name;
        // RULES: names must be unique among non-deleted lab tests (checked on master after branch sync).
        $this->resolve_branch_models(0);
        if ($this->_has_duplicate('lab_test', ['name' => $name, 'is_deleted' => 0])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Lab test $name already exists."
            ];
        }elseif (!$lab_test_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add lab_test. ",
            ];
        } elseif (!$this->_attempt_create_materials($lab_test_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add lab_test materials. ",
            ];
        } elseif (!$this->_attempt_create_hmos($lab_test_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add lab_test hmos. ",
            ];
        } elseif (!$this->_attempt_create_machines($lab_test_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add lab_test machines. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'lab_test_id' => $lab_test_id,
                'message'    => 'lab_test added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a lab_test
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->lab_test->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';
        
        // 1️⃣ Insert into MASTER first
        $this->resolve_branch_models(0);
        $lab_test_id = $this->lab_test->insert($values);
        if (!$lab_test_id) {
            $this->error_message = $this->lab_test->error();
            return false;
        }

        // 2️⃣ Insert into other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->lab_test->insert($values)) {
                $this->error_message = $this->lab_test->error();
                return false;
            }
        }

        return $lab_test_id;
    }

    /**
     * Attempt to create lab_test materials
     */
    private function _attempt_create_materials($lab_test_id)
    {
        if (!array_key_exists('materials', $this->payload) || !is_array($this->payload['materials']) || count($this->payload['materials']) == 0) {
            return true;
        }

        foreach ($this->payload['materials'] as $material) {
            if (!array_key_exists('item_id', $material) || $material['item_id'] == '') {
                continue;
            }

            $values = [
                'lab_test_id'    => $lab_test_id,
                'branch_id'      => $this->payload['branch_id'],
                'item_id'   => array_key_exists('item_id', $material) && $material['item_id'] != '' ? $material['item_id'] : null,
                'qty'   => array_key_exists('qty', $material) && $material['qty'] != '' ? $material['qty'] : 0,
                'unit'   => array_key_exists('unit', $material) && $material['unit'] != '' ? $material['unit'] : 0,
                'redo_for_payment'   => array_key_exists('redo_for_payment', $material) && $material['redo_for_payment'] != '' ? $material['redo_for_payment'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->lab_test_material->insert($values)) {
                    $this->error_message = $this->lab_test_material->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create lab_test hmos
     */
    private function _attempt_create_hmos($lab_test_id)
    {
        if (!array_key_exists('hmos', $this->payload) || !is_array($this->payload['hmos']) || count($this->payload['hmos']) == 0) {
            return true;
        }

        foreach ($this->payload['hmos'] as $hmo) {
            $values = [
                'lab_test_id'    => $lab_test_id,
                'branch_id'      => $this->payload['branch_id'],
                'company_id'   => array_key_exists('company_id', $hmo) && $hmo['company_id'] != '' ? $hmo['company_id'] : null,
                'price'   => array_key_exists('price', $hmo) && $hmo['price'] != '' ? $hmo['price'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->lab_test_hmo->insert($values)) {
                    $this->error_message = $this->lab_test_hmo->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create lab_test machines
     */
    private function _attempt_create_machines($lab_test_id)
    {
        if (!array_key_exists('machines', $this->payload) || !is_array($this->payload['machines']) || count($this->payload['machines']) == 0) {
            return true;
        }

        foreach ($this->payload['machines'] as $machine) {
            $values = [
                'lab_test_id'    => $lab_test_id,
                'branch_id'      => $this->payload['branch_id'],
                'item_id'   => array_key_exists('item_id', $machine) && $machine['item_id'] != '' ? $machine['item_id'] : null,
                'asset_id' => array_key_exists('asset_id', $machine) && $machine['asset_id'] != '' ? $machine['asset_id'] : null,
                'qty'   => array_key_exists('qty', $machine) && $machine['qty'] != '' ? $machine['qty'] : 0,
                'unit'   => array_key_exists('unit', $machine) && $machine['unit'] != '' ? $machine['unit'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->lab_test_machine->insert($values)) {
                    $this->error_message = $this->lab_test_machine->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update lab_test
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $lab_test_id = $this->payload['lab_test_id'];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->lab_test->select('id', ['is_deleted' => 0]) ?? []
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
                $this->lab_test->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        $name = $this->_normalize_name($this->payload['name'] ?? null);
        $this->payload['name'] = $name;
        // RULES: names must be unique among non-deleted lab tests (exclude current row on update).
        $this->resolve_branch_models(0);
        if ($this->_has_duplicate('lab_test', ['name' => $name, 'is_deleted' => 0], $lab_test_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Lab test $name already exists."
            ];
        } elseif (!$lab_test = $this->lab_test->select('', ['id' => $lab_test_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'lab_test is not found.',
            ];
        } elseif (!$this->_attempt_update($lab_test)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update lab_test.',
            ];
        } elseif (!$this->_attempt_update_package_details($lab_test_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update lab_test package details.',
            ];
        } elseif (!$this->_attempt_update_materials($lab_test->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update lab_test materials. ",
            ];
        } elseif (!$this->_attempt_update_hmos($lab_test->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update lab_test hmos. ",
            ];
        } elseif (!$this->_attempt_update_machines($lab_test->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update lab_test machines. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'lab_test_id' => $lab_test_id,
                'message'    => 'lab_test updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($lab_test)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->lab_test->allowedFields is declared at the top of the model
        foreach($this->lab_test->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $lab_test->id];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->lab_test->update($where, $values)) {
                $this->error_message = $this->lab_test->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to update lab_test package details
     */
    private function _attempt_update_package_details($lab_test_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->lab_test->allowedFields is declared at the top of the model
        foreach($this->package_detail->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->package_detail->update(['lab_test_id' => $lab_test_id], $values)) {
                $this->error_message = $this->package_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to update lab_test materials
     */
    private function _attempt_update_materials($lab_test_id)
    {
        // 1. Get all current material IDs for this lab_test
        $this->resolve_branch_models(0);
        $existing_materials = $this->lab_test_material->select('id', ['lab_test_id' => $lab_test_id, 'is_deleted' => 0]);

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

                // Update into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->lab_test_material->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->lab_test_material->error();
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
                'lab_test_id'  => $lab_test_id,
                'branch_id'      => $this->payload['branch_id'],
                'item_id'   => array_key_exists('item_id', $material) && $material['item_id'] != '' ? $material['item_id'] : null,
                'qty'   => array_key_exists('qty', $material) && $material['qty'] != '' ? $material['qty'] : 0,
                'unit'   => array_key_exists('unit', $material) && $material['unit'] != '' ? $material['unit'] : 0,
                'redo_for_payment'   => array_key_exists('redo_for_payment', $material) && $material['redo_for_payment'] != '' ? $material['redo_for_payment'] : null,
            ];

            if (!empty($material['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->lab_test_material->update(['id' => $material['id']], $values)) {
                        $this->error_message = $this->lab_test_material->error();
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
        
                    if (!$this->lab_test_material->insert($values)) {
                        $this->error_message = $this->lab_test_material->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update lab_test hmos
     */
    private function _attempt_update_hmos($lab_test_id)
    {
        // 1. Get all current hmo IDs for this lab_test
        $this->resolve_branch_models(0);
        $existing_hmos = $this->lab_test_hmo->select('id', ['lab_test_id' => $lab_test_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_hmos, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('hmos', $this->payload) && is_array($this->payload['hmos'])) {
            foreach ($this->payload['hmos'] as $hmo) {
                if (!empty($hmo['id'])) {
                    $payload_ids[] = (int)$hmo['id'];
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
        
                    if (!$this->lab_test_hmo->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->lab_test_hmo->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload hmos (update existing or insert new)
        if (!array_key_exists('hmos', $this->payload) || !is_array($this->payload['hmos'])) {
            return true;
        }

        foreach ($this->payload['hmos'] as $hmo) {
            $values = [
                'lab_test_id'  => $lab_test_id,
                'branch_id'      => $this->payload['branch_id'],
                'company_id'   => array_key_exists('company_id', $hmo) && $hmo['company_id'] != '' ? $hmo['company_id'] : null,
                'price'   => array_key_exists('price', $hmo) && $hmo['price'] != '' ? $hmo['price'] : 0,
            ];

            if (!empty($hmo['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->lab_test_hmo->update(['id' => $hmo['id']], $values)) {
                        $this->error_message = $this->lab_test_hmo->error();
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
        
                    if (!$this->lab_test_hmo->insert($values)) {
                        $this->error_message = $this->lab_test_hmo->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update lab_test machines
     */
    private function _attempt_update_machines($lab_test_id)
    {
        // 1. Get all current machine IDs for this lab_test
        $this->resolve_branch_models(0);
        $existing_machines = $this->lab_test_machine->select('id', ['lab_test_id' => $lab_test_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_machines, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('machines', $this->payload) && is_array($this->payload['machines'])) {
            foreach ($this->payload['machines'] as $machine) {
                if (!empty($machine['id'])) {
                    $payload_ids[] = (int)$machine['id'];
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
        
                    if (!$this->lab_test_machine->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->lab_test_machine->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload machines (update existing or insert new)
        if (!array_key_exists('machines', $this->payload) || !is_array($this->payload['machines'])) {
            return true;
        }

        foreach ($this->payload['machines'] as $machine) {
            $values = [
                'lab_test_id'  => $lab_test_id,
                'branch_id'      => $this->payload['branch_id'],
                'item_id'   => array_key_exists('item_id', $machine) && $machine['item_id'] != '' ? $machine['item_id'] : null,
                'asset_id' => array_key_exists('asset_id', $machine) && $machine['asset_id'] != '' ? $machine['asset_id'] : null,
                'qty'   => array_key_exists('qty', $machine) && $machine['qty'] != '' ? $machine['qty'] : 0,
                'unit'   => array_key_exists('unit', $machine) && $machine['unit'] != '' ? $machine['unit'] : 0,
            ];

            if (!empty($machine['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->lab_test_machine->update(['id' => $machine['id']], $values)) {
                        $this->error_message = $this->lab_test_machine->error();
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
        
                    if (!$this->lab_test_machine->insert($values)) {
                        $this->error_message = $this->lab_test_machine->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Delete lab_tests
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;

        $branch_id = $this->payload['branch_id'] ?? null;
        $lab_test_id = $this->payload['lab_test_id'];

        $this->db->transBegin();

        if (!$lab_test = $this->lab_test->select('', ['id' => $lab_test_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'lab_test is not found.',
            ];
        } elseif (!$this->_attempt_delete($lab_test_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'lab_test is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'lab_test is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($lab_test_id)
    {
        $where = ['id' => $lab_test_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->lab_test->update($where, $values)) {
                $this->error_message = $this->lab_test->error();
                return false;
            }
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


    protected function _consistency_table(): string
    {
        return 'lab_test';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['name'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['name'];
    }

    protected function _consistency_compare_fields(): array
    {
        return [
            'branch_id', 'name', 'category_id', 'group_id', 'vaccine_type',
            'price', 'hmo_price', 'is_consulta', 'is_hmo', 'is_post_service',
            'pathologist_fee', 'consultation_fee', 'procedure_fee', 'reading_fee',
            'clinic_based_cost', 'theoretical_cost', 'critical_value', 'rerun_value',
            'remarks', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [
            ['table' => 'lab_test_material', 'fk' => 'lab_test_id'],
            ['table' => 'lab_test_hmo', 'fk' => 'lab_test_id'],
            ['table' => 'lab_test_machine', 'fk' => 'lab_test_id'],
        ];
    }
}
