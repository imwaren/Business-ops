<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Branch_master;
use App\Models\Group;
use App\Models\Supplier;

class Assets extends MYT_Controller
{

    protected $request;
    protected $asset;
    protected $asset_lab_test;
    protected $asset_lab_test_machine;
    protected $asset_reagent;
    protected $branch_master;
    protected $supplier;
    protected $group;
    protected $asset_db;
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
        $this->branch_master = new Branch_master();
        $this->supplier = new Supplier();
        $this->group = new Group();
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
        $this->asset_db = $db;

        $this->asset = new \App\Models\Asset($db);
        $this->asset_lab_test = new \App\Models\Asset_lab_test($db);
        $this->asset_lab_test_machine = new \App\Models\Asset_lab_test_machine($db);
        $this->asset_reagent = new \App\Models\Asset_reagent($db);

        return true;
    }

    /**
     * Get assets
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $asset_id = $this->payload['asset_id'] ?? null;
        $asset_group_id = $this->payload['asset_group_id'] ?? null;
        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $item_id = $this->payload['item_id'] ?? null;
        $parent_id = array_key_exists('parent_id', $this->payload) && $this->payload['parent_id'] !== ''
            ? $this->payload['parent_id']
            : null;
        if ($parent_id !== null && (!ctype_digit((string) $parent_id) || (int) $parent_id < 0)) {
            return $this->_create_response(FAIL, ['Invalid parent_id filter.']);
        }
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $assets = $this->asset->get_details($asset_id, $asset_group_id, $cost_center_id, $supplier_id, $term, $item_id, $page, $data_size, $parent_id)?:[];
        $size = count($this->asset->get_details($asset_id, $asset_group_id, $cost_center_id, $supplier_id, $term, $item_id, null, null, $parent_id)?:[]);

        if(!$assets) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No assets found.'
            ];
        } else {
            foreach ($assets as $key => $asset) {
                $supplier = $this->supplier->select('name', ['id' => $asset['supplier_id'], 'is_deleted' => 0], 1) ?: null;
                $assets[$key]['supplier_name'] = $supplier ? $supplier->name : null;
                
                $cost_center = $this->branch_master->select('name', ['id' => $asset['cost_center_id'], 'is_deleted' => 0], 1) ?: null;
                $assets[$key]['cost_center_name'] = $cost_center ? $cost_center->name : null;

                $asset_lab_tests = $this->asset_lab_test->get_details($asset['id']) ?: [];
                if ($asset_lab_tests) {
                    foreach ($asset_lab_tests as $lt_key => $asset_lab_test) {
                        $asset_lab_test_machines = $this->asset_lab_test_machine->get_details($asset_lab_test['id']) ?: [];
                        $asset_lab_tests[$lt_key]['machines'] = $asset_lab_test_machines;
                    }
                }
                $assets[$key]['lab_tests'] = $asset_lab_tests;
                $assets[$key]['reagents'] = $this->asset_reagent->get_details($asset['id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $assets
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create asset
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $name = $this->payload['name'] ?? null;

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        if (!$this->_validate_asset_relations(null, true)) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        $this->db->transBegin();

        if ($this->_has_duplicate('asset', ['name' => $name, 'is_deleted' => 0])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Asset $name already exists."
            ];
        } elseif (!$asset_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add asset. ",
            ];
        } elseif (!$this->_attempt_create_lab_tests($asset_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add asset lab tests. ",
            ];
        } elseif (!$this->_attempt_create_reagents($asset_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add asset reagents. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'asset_id' => $asset_id,
                'message'    => 'asset added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a asset
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->asset->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $asset_id = $this->asset->insert($values);
        if (!$asset_id) {
            $this->error_message = $this->asset->error();
            return false;
        }

        return $asset_id;
    }

    /**
     * Attempt to create asset lab_tests
     */
    private function _attempt_create_lab_tests($asset_id)
    {
        if (!array_key_exists('lab_tests', $this->payload) || !is_array($this->payload['lab_tests']) || count($this->payload['lab_tests']) == 0) {
            return true;
        }

        foreach ($this->payload['lab_tests'] as $lab_test) {
            $values = [
                'asset_id'    => $asset_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $lab_test) && $lab_test['lab_test_id'] != '' ? $lab_test['lab_test_id'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            $asset_lab_test_id = $this->asset_lab_test->insert($values);
            if (!$asset_lab_test_id) {
                $this->error_message = $this->asset_lab_test->error();
                return false;
            }

            if (array_key_exists('machines', $lab_test) && is_array($lab_test['machines'])) {
                foreach ($lab_test['machines'] as $machine) {
                    $machine_values = [
                        'asset_lab_test_id' => $asset_lab_test_id,
                        'asset_id'          => $asset_id,
                        'lab_test_id'       => array_key_exists('lab_test_id', $lab_test) && $lab_test['lab_test_id'] != '' ? $lab_test['lab_test_id'] : null,
                        'item_id'           => array_key_exists('item_id', $machine) && $machine['item_id'] != '' ? $machine['item_id'] : null,
                        'qty'               => array_key_exists('qty', $machine) && $machine['qty'] != '' ? $machine['qty'] : null,
                        'unit'              => array_key_exists('unit', $machine) && $machine['unit'] != '' ? $machine['unit'] : null,
                        'added_by'         => $this->payload['requester'],
                        'added_on'         => date('Y-m-d H:i:s'),
                    ];

                    if (!$this->asset_lab_test_machine->insert($machine_values)) {
                        $this->error_message = $this->asset_lab_test_machine->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create asset reagents
     */
    private function _attempt_create_reagents($asset_id)
    {
        if (!array_key_exists('reagents', $this->payload) || !is_array($this->payload['reagents']) || count($this->payload['reagents']) == 0) {
            return true;
        }

        foreach ($this->payload['reagents'] as $reagent) {
            $values = [
                'asset_id' => $asset_id,
                'item_id'  => array_key_exists('item_id', $reagent) && $reagent['item_id'] != '' ? $reagent['item_id'] : null,
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s'),
            ];

            if (!$this->asset_reagent->insert($values)) {
                $this->error_message = $this->asset_reagent->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update asset
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $name = $this->payload['name'] ?? null;
        $asset_id = $this->payload['asset_id'] ?? null;
        if (!$asset_id) {
            return $this->_create_response(FAIL, ['Asset ID is required.']);
        }
        $where = [
                    'id' => $asset_id, 
                    'is_deleted' => 0
                ];

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        if (!$asset = $this->asset->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'asset is not found.'
            ];
        } elseif (!$this->_validate_asset_relations($asset_id, false)) {
            $response_status = FAIL;
            $response = [$this->error_message];
        } else {
            $this->db->transBegin();
            if ($name !== null && $name !== '' && $this->_has_duplicate('asset', ['name' => $name, 'is_deleted' => 0], $asset_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = ["Asset $name already exists."];
            } elseif (!$this->_attempt_update($asset)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = ['Failed to update asset.'];

                if ($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                    $response[] = 'unique fields already exists.';
                }
            } elseif (array_key_exists('lab_tests', $this->payload) && !$this->_attempt_update_lab_tests($asset_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = ['Failed to update asset lab tests.'];
            } elseif (array_key_exists('reagents', $this->payload) && !$this->_attempt_update_reagents($asset_id)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = ['Failed to update asset reagents.'];
            } else {
                $this->db->transCommit();
                $response_status = SUCCESS;
                $response = [
                    'asset_id' => $asset_id,
                    'message' => 'asset updated successfully.'
                ];
            }
        }

        return $this->_create_response($response_status, $response);
    }

    /** Validate optional asset group and one-level parent relationships. */
    private function _validate_asset_relations($asset_id = null, bool $is_create = false): bool
    {
        if ($is_create || array_key_exists('group_id', $this->payload)) {
            $group_id = $this->payload['group_id'] ?? null;
            if ($group_id !== null && $group_id !== '' && (!ctype_digit((string) $group_id) || (int) $group_id < 1)) {
                $this->error_message = 'Invalid group_id.';
                return false;
            }
            if ($group_id !== null && $group_id !== '') {
                $group = $this->group->select('id', [
                    'id' => (int) $group_id,
                    'is_deleted' => 0,
                ], 1);
                if (!$group) {
                    $this->error_message = 'group_id must reference an active group.';
                    return false;
                }
            }
        }

        if (!$is_create && !array_key_exists('parent_id', $this->payload)) {
            return true;
        }

        $parent_id = $this->payload['parent_id'] ?? null;
        if ($parent_id === '') {
            $parent_id = null;
        }
        if ($parent_id === null) {
            return true;
        }
        if (!ctype_digit((string) $parent_id) || (int) $parent_id < 1) {
            $this->error_message = 'Invalid parent_id.';
            return false;
        }
        $parent_id = (int) $parent_id;
        if ($asset_id !== null && $parent_id === (int) $asset_id) {
            $this->error_message = 'An asset cannot be its own parent.';
            return false;
        }

        if ($asset_id !== null) {
            $children = $this->asset->select('id', [
                'parent_id' => (int) $asset_id,
                'is_deleted' => 0,
            ], 1);
            if ($children) {
                $this->error_message = 'An asset with children cannot be assigned a parent.';
                return false;
            }
        }

        $parent = $this->asset->select('id', [
            'id' => $parent_id,
            'is_deleted' => 0,
            'parent_id' => null,
        ], 1);
        if (!$parent) {
            $this->error_message = 'parent_id must reference an active root asset in the selected database.';
            return false;
        }
        return true;
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($asset)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->asset->allowedFields is declared at the top of the model
        foreach($this->asset->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $asset->id];

        if (!$this->asset->update($where, $values)) {
            $this->error_message = $this->asset->error();
            return false;
        }

        return true;
    }

    /**
     * Attempt to update asset lab_tests
     */
    private function _attempt_update_lab_tests($asset_id)
    {
        // 1. Get all current lab_test IDs for this asset
        $existing_lab_tests = $this->asset_lab_test->select('id', ['asset_id' => $asset_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_lab_tests, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('lab_tests', $this->payload) && is_array($this->payload['lab_tests'])) {
            foreach ($this->payload['lab_tests'] as $lab_test) {
                if (!empty($lab_test['id'])) {
                    $payload_ids[] = (int)$lab_test['id'];
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
                if (!$this->asset_lab_test->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->asset_lab_test->error();
                    return false;
                }
                if (!$this->asset_lab_test_machine->update(['asset_lab_test_id' => $delete_id], ['is_deleted' => 1, 'updated_by' => $this->payload['requester'], 'updated_on' => date('Y-m-d H:i:s')])) {
                    $this->error_message = $this->asset_lab_test_machine->error();
                    return false;
                }
            }
        }

        // 4. Process payload lab_tests (update existing or insert new)
        if (!array_key_exists('lab_tests', $this->payload) || !is_array($this->payload['lab_tests'])) {
            return true;
        }

        foreach ($this->payload['lab_tests'] as $lab_test) {
            $values = [
                'asset_id'  => $asset_id,
                'lab_test_id' => array_key_exists('lab_test_id', $lab_test) && $lab_test['lab_test_id'] != '' ? $lab_test['lab_test_id'] : null,
            ];

            if (!empty($lab_test['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->asset_lab_test->update(['id' => $lab_test['id']], $values)) {
                    $this->error_message = $this->asset_lab_test->error();
                    return false;
                }
                
                // Also update/insert associated machines
                if (array_key_exists('machines', $lab_test) && is_array($lab_test['machines'])) {
                    foreach ($lab_test['machines'] as $machine) {
                        $machine_values = [
                            'asset_lab_test_id' => $lab_test['id'],
                            'asset_id'          => $asset_id,
                            'lab_test_id'       => array_key_exists('lab_test_id', $lab_test) && $lab_test['lab_test_id'] != '' ? $lab_test['lab_test_id'] : null,
                            'item_id'           => array_key_exists('item_id', $machine) && $machine['item_id'] != '' ? $machine['item_id'] : null,
                            'qty'               => array_key_exists('qty', $machine) && $machine['qty'] != '' ? $machine['qty'] : null,
                            'unit'              => array_key_exists('unit', $machine) && $machine['unit'] != '' ? $machine['unit'] : null,
                        ];

                        if (!empty($machine['id'])) {
                            // update existing machine
                            $machine_values['updated_by'] = $this->payload['requester'];
                            $machine_values['updated_on'] = date('Y-m-d H:i:s');

                            if (!$this->asset_lab_test_machine->update(['id' => $machine['id']], $machine_values)) {
                                $this->error_message = $this->asset_lab_test_machine->error();
                                return false;
                            }
                        } else {
                            // insert new machine
                            $machine_values['added_by'] = $this->payload['requester'];
                            $machine_values['added_on'] = date('Y-m-d H:i:s');

                            if (!$this->asset_lab_test_machine->insert($machine_values)) {
                                $this->error_message = $this->asset_lab_test_machine->error();
                                return false;
                            }
                        }
                    }
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$new_asset_lab_test_id = $this->asset_lab_test->insert($values)) {
                    $this->error_message = $this->asset_lab_test->error();
                    return false;
                }

                // Also insert associated machines
                if (array_key_exists('machines', $lab_test) && is_array($lab_test['machines'])) {
                    foreach ($lab_test['machines'] as $machine) {
                        $machine_values = [
                            'asset_lab_test_id' => $new_asset_lab_test_id,
                            'asset_id'          => $asset_id,
                            'lab_test_id'       => array_key_exists('lab_test_id', $lab_test) && $lab_test['lab_test_id'] != '' ? $lab_test['lab_test_id'] : null,
                            'item_id'           => array_key_exists('item_id', $machine) && $machine['item_id'] != '' ? $machine['item_id'] : null,
                            'qty'               => array_key_exists('qty', $machine) && $machine['qty'] != '' ? $machine['qty'] : null,
                            'unit'              => array_key_exists('unit', $machine) && $machine['unit'] != '' ? $machine['unit'] : null,
                            'added_by'         => $this->payload['requester'],
                            'added_on'         => date('Y-m-d H:i:s'),
                        ];

                        if (!$this->asset_lab_test_machine->insert($machine_values)) {
                            $this->error_message = $this->asset_lab_test_machine->error();
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update asset reagents
     */
    private function _attempt_update_reagents($asset_id)
    {
        if (!array_key_exists('reagents', $this->payload) || !is_array($this->payload['reagents'])) {
            return true;
        }

        // 1. Get all current reagent IDs for this asset
        $existing_reagents = $this->asset_reagent->select('id', ['asset_id' => $asset_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_reagents ?: [], 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        foreach ($this->payload['reagents'] as $reagent) {
            if (!empty($reagent['id'])) {
                $payload_ids[] = (int)$reagent['id'];
            }
        }

        // 3. Mark missing ones as deleted (empty array deletes all)
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                if (!$this->asset_reagent->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->asset_reagent->error();
                    return false;
                }
            }
        }

        // 4. Process payload reagents (update existing or insert new)
        foreach ($this->payload['reagents'] as $reagent) {
            $values = [
                'asset_id' => $asset_id,
                'item_id'  => array_key_exists('item_id', $reagent) && $reagent['item_id'] != '' ? $reagent['item_id'] : null,
            ];

            if (!empty($reagent['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->asset_reagent->update(['id' => $reagent['id']], $values)) {
                    $this->error_message = $this->asset_reagent->error();
                    return false;
                }
            } else {
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->asset_reagent->insert($values)) {
                    $this->error_message = $this->asset_reagent->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Delete assets
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $asset_id = $this->payload['asset_id'];

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $where = ['id' => $asset_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$asset = $this->asset->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'asset is not found.',
            ];
        } elseif (!$this->_attempt_delete($asset_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'asset is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'asset is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($asset_id)
    {
        $where = ['id' => $asset_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->asset->update($where, $values)) {
            $this->error_message = $this->asset->error();
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

    public function custom_validations($validation_name) {
        $is_valid = true;
        switch($validation_name) {
            case 'create' || 'update':
                $key = 'asset_id';
                $id = array_key_exists($key, $this->payload)?$this->payload[$key]:null;

                // Put your custom validations here
                // If that validation can be applied to other modules, add it to MYT Custom Rules
        }
        return $is_valid;
    }
}
