<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Package;
use App\Models\Package_detail;
use App\Models\Package_material;

use App\Models\Item;
use App\Models\Item_conversion;
use App\Models\Branch_master;

class Packages extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $package;
    protected $package_detail;
    protected $package_material;

    protected $item;
    protected $item_conversion;
    protected $branch_master;
    protected $helpers = ['text'];
    protected $validation;
    protected $db;

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
        $this->package = new \App\Models\Package();
        $this->package_detail = new \App\Models\Package_detail();
        $this->package_material = new \App\Models\Package_material();

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

        $this->package = new \App\Models\Package($db);
        $this->package_detail = new \App\Models\Package_detail($db);
        $this->package_material = new \App\Models\Package_material($db);

        return true;
    }

    /**
     * Get packages
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $package_id = $this->payload['package_id'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $package_status = $this->payload['package_status'] ?? null;
        $type = $this->payload['type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $packages = $this->package->get_details($package_id, $branch_id, $package_status, $type, $term, $page, $data_size)?:[];

        if(!$packages) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No packages found.'
            ];
        } else {
            $packageIds = $this->_collect_ids($packages, 'id');

            $detailsByPackage = $this->_group_by($this->package_detail->get_details($packageIds) ?: [], 'package_id');
            $materialsByPackage = $this->_group_by($this->package_material->get_materials($packageIds) ?: [], 'package_id');

            $itemIds = [];
            foreach ($materialsByPackage as $materials) {
                foreach ($materials as $material) {
                    if (!empty($material['item_id'])) {
                        $itemIds[] = $material['item_id'];
                    }
                }
            }
            $conversionsByItem = $this->_group_by(
                $this->item_conversion->get_details(array_values(array_unique($itemIds))) ?: [],
                'item_id'
            );

            foreach ($packages as $key => $package) {
                $pid = $package['id'];
                $packages[$key]['details'] = $detailsByPackage[$pid] ?? [];

                $package_materials = $materialsByPackage[$pid] ?? [];
                foreach ($package_materials as $mkey => $material) {
                    $conversions = $conversionsByItem[$material['item_id']] ?? [];
                    $package_materials[$mkey]['conversions'] = array_map(static function ($c) {
                        return [
                            'id' => $c['id'],
                            'conversion_type' => $c['conversion_type'],
                            'qty' => $c['qty'],
                            'unit' => $c['unit'],
                        ];
                    }, $conversions);
                }
                $packages[$key]['materials'] = $package_materials;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $this->package->get_count($package_id, $branch_id, $package_status, $type, $term),
                'data' => $packages
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Search packages
     */
    public function search()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $package_id = $this->payload['package_id'] ?? null;
        $package_type = $this->payload['package_type'] ?? null;
        $package_status = $this->payload['package_status'] ?? null;
        $type = $this->payload['type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $packages = $this->package->search_details($package_id, $package_type, $package_status, $type, $term, $page, $data_size)?:[];

        if(!$packages) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No packages found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $this->package->search_details_count($package_id, $package_type, $package_status, $type, $term),
                'data' => $packages
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create package
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
            $this->package->select('id', ['is_deleted' => 0]) ?? []
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
                $this->package->select('id', ['is_deleted' => 0]) ?? []
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
        if ($this->_has_duplicate('package', ['name' => $name, 'package_status' => 'ACTIVE', 'is_deleted' => 0])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Package $name already exists."
            ];
        } elseif (!$package_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add package. ",
            ];
        } elseif (!$this->_attempt_create_details($package_id)) {
            $this->_rollback_new_package_all_branches($package_id);
            $this->db->transRollback();
            $response_status = FAIL;
            $response = $this->_package_failure_messages('Failed to add package details.');
        } elseif (!$this->_attempt_create_materials($package_id)) {
            $this->_rollback_new_package_all_branches($package_id);
            $this->db->transRollback();
            $response_status = FAIL;
            $response = $this->_package_failure_messages('Failed to add package materials.');
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'package_id' => $package_id,
                'message'    => 'package added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a package
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->package->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';
        
        // 1️⃣ Insert into MASTER first
        $this->resolve_branch_models(0);
        $package_id = $this->package->insert($values);
        if (!$package_id) {
            $this->error_message = $this->package->error();
            return false;
        }

        // 2️⃣ Insert into other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->package->insert($values)) {
                $this->error_message = $this->package->error();
                return false;
            }
        }

        return $package_id;
    }

    /**
     * RULES: labtest_count is assigned server-side (never from the client).
     * Group lines by lab_test_id + branch_id in payload order.
     * - Same test repeated on that branch (duplicates) → 0, 1, 2, …
     * - Test appears only once in that group (not duplicated) → 1
     */
    private function _apply_labtest_counts(array $details): array
    {
        $group_indices = [];

        foreach ($details as $index => $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $lab_test_id = $detail['lab_test_id'] ?? null;
            if ($lab_test_id === null || $lab_test_id === '') {
                unset($details[$index]['labtest_count']);
                continue;
            }

            $branch_id = $detail['branch_id'] ?? '';
            $key = (string) $lab_test_id . '|' . (string) $branch_id;
            if (!isset($group_indices[$key])) {
                $group_indices[$key] = [];
            }
            $group_indices[$key][] = $index;
        }

        foreach ($group_indices as $indices) {
            $count = count($indices);
            foreach ($indices as $ordinal => $index) {
                // Duplicates start at 0; a single line for this test/branch starts at 1.
                $details[$index]['labtest_count'] = $count > 1 ? $ordinal : 1;
            }
        }

        return $details;
    }

    /**
     * Attempt to create package details
     */
    private function _attempt_create_details($package_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        $details = $this->_apply_labtest_counts($this->payload['details']);

        foreach ($details as $detail) {
            try {
                if (!$this->_insert_package_detail_all_branches($package_id, $detail)) {
                    return false;
                }
            } catch (\Throwable $e) {
                log_message('error', 'Package detail insert: ' . $e->getMessage());
                $this->error_message = $e->getMessage();

                return false;
            }
        }

        return true;
    }

    /**
     * Insert one package detail row on master and all branch databases.
     */
    private function _insert_package_detail_all_branches($package_id, array $detail): bool
    {
        $values = [
            'package_id'    => $package_id,
            'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
            'frequency'   => array_key_exists('frequency', $detail) && $detail['frequency'] != '' ? $detail['frequency'] : null,
            'pathologist_fee'   => array_key_exists('pathologist_fee', $detail) && $detail['pathologist_fee'] != '' ? $detail['pathologist_fee'] : null,
            'consultation_fee'   => array_key_exists('consultation_fee', $detail) && $detail['consultation_fee'] != '' ? $detail['consultation_fee'] : null,
            'procedure_fee'   => array_key_exists('procedure_fee', $detail) && $detail['procedure_fee'] != '' ? $detail['procedure_fee'] : null,
            'reading_fee'   => array_key_exists('reading_fee', $detail) && $detail['reading_fee'] != '' ? $detail['reading_fee'] : null,
            'theoretical_cost'  => array_key_exists('theoretical_cost', $detail) && $detail['theoretical_cost'] != '' ? $detail['theoretical_cost'] : null,
            'clinic_based_cost'  => array_key_exists('clinic_based_cost', $detail) && $detail['clinic_based_cost'] != '' ? $detail['clinic_based_cost'] : null,
            'retail_price'  => array_key_exists('retail_price', $detail) && $detail['retail_price'] != '' ? $detail['retail_price'] : null,
            'pro_rate_price'  => array_key_exists('pro_rate_price', $detail) && $detail['pro_rate_price'] != '' ? $detail['pro_rate_price'] : null,
            'discount'  => array_key_exists('discount', $detail) && $detail['discount'] != '' ? $detail['discount'] : null,
            'estimated_revenue'  => array_key_exists('estimated_revenue', $detail) && $detail['estimated_revenue'] != '' ? $detail['estimated_revenue'] : null,
            'direct_cost'  => array_key_exists('direct_cost', $detail) && $detail['direct_cost'] != '' ? $detail['direct_cost'] : null,
            'estimated_margin'  => array_key_exists('estimated_margin', $detail) && $detail['estimated_margin'] != '' ? $detail['estimated_margin'] : null,
            'labtest_count'  => array_key_exists('labtest_count', $detail) && $detail['labtest_count'] !== '' && $detail['labtest_count'] !== null
                ? (int) $detail['labtest_count']
                : null,
            'branch_id'   => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
            'added_by'      => $this->payload['requester'],
            'added_on'      => date('Y-m-d H:i:s'),
        ];

        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->package_detail->insert($values)) {
                $this->error_message = $this->package_detail->error();

                return false;
            }
        }

        return true;
    }

    /**
     * RULES: Package create uses separate DB connections per branch; default transRollback does not undo them.
     * Soft-delete header and any detail rows written so far when a later step fails.
     */
    private function _rollback_new_package_all_branches(int $package_id): void
    {
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);
            $this->package->update(['id' => $package_id], $values);
            $this->package_detail->update(['package_id' => $package_id], $values);
        }
    }

    /**
     * @return list<string>
     */
    private function _package_failure_messages(string $prefix): array
    {
        $messages = [trim($prefix)];
        if (!empty($this->error_message)) {
            $messages[] = is_string($this->error_message)
                ? $this->error_message
                : json_encode($this->error_message);
        }

        return $messages;
    }

    /**
     * Attempt to create package materials
     */
    private function _attempt_create_materials($package_id)
    {
        if (!array_key_exists('materials', $this->payload) || !is_array($this->payload['materials']) || count($this->payload['materials']) == 0) {
            return true;
        }

        foreach ($this->payload['materials'] as $material) {
            if (!array_key_exists('item_id', $material) || $material['item_id'] == '') {
                continue;
            }

            $values = [
                'package_id'    => $package_id,
                'branch_id'      => $this->payload['branch_id'],
                'item_id'   => array_key_exists('item_id', $material) && $material['item_id'] != '' ? $material['item_id'] : null,
                'qty'   => array_key_exists('qty', $material) && $material['qty'] != '' ? $material['qty'] : 0,
                'unit'   => array_key_exists('unit', $material) && $material['unit'] != '' ? $material['unit'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->package_material->insert($values)) {
                    $this->error_message = $this->package_material->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update package
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $package_id = $this->payload['package_id'];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->package->select('id', ['is_deleted' => 0]) ?? []
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
                $this->package->select('id', ['is_deleted' => 0]) ?? []
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
        if ($this->_has_duplicate('package', ['name' => $name, 'package_status' => 'ACTIVE', 'is_deleted' => 0], $package_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Package $name already exists."
            ];
        } elseif (!$package = $this->package->select('', ['id' => $package_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'package is not found.',
            ];
        } elseif (!$this->_attempt_update($package)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update package.',
            ];
        } elseif (!$this->_attempt_update_details($package->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update package details. ",
            ];
        } elseif (!$this->_attempt_update_materials($package->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update package materials. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'package_id' => $package_id,
                'message'    => 'package updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($package)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->package->allowedFields is declared at the top of the model
        foreach($this->package->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $package->id];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->package->update($where, $values)) {
                $this->error_message = $this->package->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to update package details
     */
    private function _attempt_update_details($package_id)
    {
        // 1. Get all current detail IDs for this package
        $existing_details = $this->package_detail->select('id', ['package_id' => $package_id, 'is_deleted' => 0]);

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
        
                    if (!$this->package_detail->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->package_detail->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload details (update existing or insert new)
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details'])) {
            return true;
        }

        $details = $this->_apply_labtest_counts($this->payload['details']);

        foreach ($details as $detail) {
            $values = [
                'package_id'  => $package_id,
                'lab_test_id' => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'frequency'   => array_key_exists('frequency', $detail) && $detail['frequency'] != '' ? $detail['frequency'] : null,
                'pathologist_fee'   => array_key_exists('pathologist_fee', $detail) && $detail['pathologist_fee'] != '' ? $detail['pathologist_fee'] : null,
                'consultation_fee'   => array_key_exists('consultation_fee', $detail) && $detail['consultation_fee'] != '' ? $detail['consultation_fee'] : null,
                'procedure_fee'   => array_key_exists('procedure_fee', $detail) && $detail['procedure_fee'] != '' ? $detail['procedure_fee'] : null,
                'reading_fee'   => array_key_exists('reading_fee', $detail) && $detail['reading_fee'] != '' ? $detail['reading_fee'] : null,
                'theoretical_cost'  => array_key_exists('theoretical_cost', $detail) && $detail['theoretical_cost'] != '' ? $detail['theoretical_cost'] : null,
                'clinic_based_cost'  => array_key_exists('clinic_based_cost', $detail) && $detail['clinic_based_cost'] != '' ? $detail['clinic_based_cost'] : null,
                'retail_price'  => array_key_exists('retail_price', $detail) && $detail['retail_price'] != '' ? $detail['retail_price'] : null,
                'pro_rate_price'  => array_key_exists('pro_rate_price', $detail) && $detail['pro_rate_price'] != '' ? $detail['pro_rate_price'] : null,
                'discount'  => array_key_exists('discount', $detail) && $detail['discount'] != '' ? $detail['discount'] : null,
                'estimated_revenue'  => array_key_exists('estimated_revenue', $detail) && $detail['estimated_revenue'] != '' ? $detail['estimated_revenue'] : null,
                'direct_cost'  => array_key_exists('direct_cost', $detail) && $detail['direct_cost'] != '' ? $detail['direct_cost'] : null,
                'estimated_margin'  => array_key_exists('estimated_margin', $detail) && $detail['estimated_margin'] != '' ? $detail['estimated_margin'] : null,
                'labtest_count'  => array_key_exists('labtest_count', $detail) && $detail['labtest_count'] !== '' && $detail['labtest_count'] !== null
                    ? (int) $detail['labtest_count']
                    : null,
                'branch_id'   => array_key_exists('branch_id', $detail) && $detail['branch_id'] != '' ? $detail['branch_id'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->package_detail->update(['id' => $detail['id']], $values)) {
                        $this->error_message = $this->package_detail->error();
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
        
                    if (!$this->package_detail->insert($values)) {
                        $this->error_message = $this->package_detail->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }
    
    /**
     * Attempt to update package materials
     */
    private function _attempt_update_materials($package_id)
    {
        // 1. Get all current material IDs for this package
        $existing_materials = $this->package_material->select('id', ['package_id' => $package_id, 'is_deleted' => 0]);

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
        
                    if (!$this->package_material->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->package_material->error();
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
                'package_id'  => $package_id,
                'branch_id'      => $this->payload['branch_id'],
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
        
                    if (!$this->package_material->update(['id' => $material['id']], $values)) {
                        $this->error_message = $this->package_material->error();
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
        
                    if (!$this->package_material->insert($values)) {
                        $this->error_message = $this->package_material->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Delete packages
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;

        $branch_id = $this->payload['branch_id'] ?? null;
        $package_id = $this->payload['package_id'];

        $this->db->transBegin();

        if (!$branch_id || $branch_id == '' || $branch_id == 0) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "branch_id is required to specify which branch the package belongs. ",
            ];
        } elseif (!$package = $this->package->select('', ['id' => $package_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'package is not found.',
            ];
        } elseif (!$this->_attempt_delete($package_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'package is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'package is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($package_id)
    {
        $where = ['id' => $package_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->package->update($where, $values)) {
                $this->error_message = $this->package->error();
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
        return 'package';
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
            'branch_id', 'name', 'price', 'type', 'package_status', 'package_type',
            'remarks', 'total_theoretical_cost', 'clinic_based_cost_total',
            'retail_price_total', 'pro_rate_price_total', 'discount_total',
            'margin_total', 'sales_total', 'estimated_revenue_total',
            'direct_cost_total', 'estimated_margin_total', 'reading_fee_total',
            'procedure_fee_total', 'consultation_fee_total', 'pathologist_fee_total',
            'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [
            ['table' => 'package_detail', 'fk' => 'package_id'],
            ['table' => 'package_material', 'fk' => 'package_id'],
        ];
    }
}
