<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Category;

class Categories extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $category;
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
        $this->category = new Category();
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

        $this->category = new \App\Models\Category($db);
        return true;
    }

    /**
     * Get categories
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $category_id = $this->payload['category_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        // Get all categories
        $categories = $this->category->get_details($category_id, null, $term, $page, $data_size) ?: [];
        $categories_data = $categories;

        // Build hierarchical structure
        // $categories_data = $this->_build_category_tree($categories);

        if(!$categories_data) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No categories found.'
            ];
        } else {
            foreach ($categories_data as &$category) {
                $category['subcategories'] = $this->category->get_details(null, $category['id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->category->get_details($category_id, null, $term, null, null) ?: []),
                'data' => $categories_data
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Build hierarchical category tree
     */
    private function _build_category_tree($categories, $parent_id = null)
    {
        $branch = [];
        
        foreach ($categories as $category) {
            $category_childs = $this->category->get_details(null, $category['category_id']) ?: [];
            foreach ($category_childs as $child) {
                $child['subcategories'] = $this->_build_category_tree($categories, $child['id']);
                $branch[] = $child;
                // if ($category['category_id'] == $category_childs) {
                //     $children = $this->_build_category_tree($categories, $category['id']);
                //     if ($children) {
                //         $category['subcategories'] = $children;
                //     }
                //     $branch[] = $category;
                // }
            }
        }
        
        return $branch;
    }

    /**
     * Create category
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->category->select('id', ['is_deleted' => 0]) ?? []
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
                $this->category->select('id', ['is_deleted' => 0]) ?? []
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
        if ($this->_has_duplicate('category', ['name' => $name, 'is_deleted' => 0])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Category $name already exists."
            ];
        } elseif (!$category_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add category. ",
            ];
        } elseif (!$this->_attempt_create_subcategories($category_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $this->error_message ?: "Failed to add category subcategories. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'category_id' => $category_id,
                'message'    => 'category added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a category
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->category->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        // 1️⃣ Insert into MASTER first
        $this->resolve_branch_models(0);
        $category_id = $this->category->insert($values);
        if (!$category_id) {
            $this->error_message = $this->category->error();
            return false;
        }

        // 2️⃣ Insert into other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->category->insert($values)) {
                $this->error_message = $this->category->error();
                return false;
            }
        }

        return $category_id;
    }
    
    /**
     * Attempt to create category subcategories // 2 LEVEL ONLY (category -> subcategories)
     */
    private function _attempt_create_subcategories($category_id)
    {
        if (!array_key_exists('subcategories', $this->payload) || !is_array($this->payload['subcategories']) || count($this->payload['subcategories']) == 0) {
            return true;
        }

        // Prevent duplicates within payload (case-insensitive, trimmed)
        $names_seen = [];
        foreach ($this->payload['subcategories'] as $subcategory) {
            $name_raw = array_key_exists('name', $subcategory) ? $subcategory['name'] : null;
            $name = $this->_normalize_name($name_raw);
            if ($name === null || $name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (array_key_exists($key, $names_seen)) {
                $this->error_message = "Duplicate subcategory name \"$name\".";
                return false;
            }
            $names_seen[$key] = true;
        }

        foreach ($this->payload['subcategories'] as $subcategory) {
            $name_raw = array_key_exists('name', $subcategory) ? $subcategory['name'] : null;
            $name = $this->_normalize_name($name_raw);

            // Prevent duplicates per category_id in DB
            if ($name !== null && $name !== '' && $this->_has_duplicate('category', ['category_id' => $category_id, 'name' => $name, 'is_deleted' => 0])) {
                $this->error_message = "Subcategory $name already exists.";
                return false;
            }

            $values = [
                'category_id'    => $category_id,
                'name'   => ($name !== null && $name !== '') ? $name : null,
                'remarks'   => array_key_exists('remarks', $subcategory) && $subcategory['remarks'] != '' ? $subcategory['remarks'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // 2️⃣ Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->category->insert($values)) {
                    $this->error_message = $this->category->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update category
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $category_id = $this->payload['category_id'];
        $where = [
                    'id' => $category_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->category->select('id', ['is_deleted' => 0]) ?? []
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
                $this->category->select('id', ['is_deleted' => 0]) ?? []
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
        if ($this->_has_duplicate('category', ['name' => $name, 'is_deleted' => 0], $category_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Category $name already exists."
            ];
        } elseif (!$category = $this->category->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Category is not found.'
            ];
        } elseif (!$this->_attempt_update($category)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update category.',
            ];
        } elseif (!$this->_attempt_update_subcategories($category_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $this->error_message ?: 'Failed to update category subcategories.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'category_id' => $category_id,
                'message'    => 'category updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($category)
    {
        $values = [
            'name'            => $this->payload['name'],
            'remarks'            => $this->payload['remarks'] ?? null,
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $category->id];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->category->update($where, $values)) {
                $this->error_message = $this->category->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to update category subcategories // 2 LEVEL ONLY (category -> subcategories)
     */
    private function _attempt_update_subcategories($category_id)
    {
        // Validate duplicates first before mutating/deleting anything
        if (array_key_exists('subcategories', $this->payload) && is_array($this->payload['subcategories'])) {
            $names_seen = [];
            foreach ($this->payload['subcategories'] as $subcategory) {
                $name_raw = array_key_exists('name', $subcategory) ? $subcategory['name'] : null;
                $name = $this->_normalize_name($name_raw);
                if ($name === null || $name === '') {
                    continue;
                }

                $key = mb_strtolower($name);
                if (array_key_exists($key, $names_seen)) {
                    $this->error_message = "Duplicate subcategory name \"$name\".";
                    return false;
                }
                $names_seen[$key] = true;

                $exclude_id = !empty($subcategory['id']) ? (int) $subcategory['id'] : null;
                $where = ['category_id' => $category_id, 'name' => $name, 'is_deleted' => 0];
                if ($exclude_id) {
                    $where['id <>'] = $exclude_id;
                }
                if ($this->category->select('', $where, 1)) {
                    $this->error_message = "Subcategory $name already exists.";
                    return false;
                }
            }
        }

        // 1. Get all current subcategory IDs for this category
        $existing_subcategories = $this->category->select('id, category_id', ['category_id' => $category_id, 'category_id IS NOT' => NULL, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_subcategories, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('subcategories', $this->payload) && is_array($this->payload['subcategories'])) {
            foreach ($this->payload['subcategories'] as $subcategory) {
                if (!empty($subcategory['id'])) {
                    $payload_ids[] = (int)$subcategory['id'];
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
        
                    if (!$this->category->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->category->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload subcategories (update existing or insert new)
        if (!array_key_exists('subcategories', $this->payload) || !is_array($this->payload['subcategories'])) {
            return true;
        }

        foreach ($this->payload['subcategories'] as $subcategory) {
            $name_raw = array_key_exists('name', $subcategory) ? $subcategory['name'] : null;
            $name = $this->_normalize_name($name_raw);
            $values = [
                'category_id'  => $category_id,
                'name' => ($name !== null && $name !== '') ? $name : null,
                'remarks' => array_key_exists('remarks', $subcategory) && $subcategory['remarks'] != '' ? $subcategory['remarks'] : 0,
            ];

            if (!empty($subcategory['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Update into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->category->update(['id' => $subcategory['id']], $values)) {
                        $this->error_message = $this->category->error();
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
        
                    if (!$this->category->insert($values)) {
                        $this->error_message = $this->category->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Delete categories
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $category_id = $this->payload['category_id'];

        $where = ['id' => $category_id, 'is_deleted' => 0];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->category->select('id', ['is_deleted' => 0]) ?? []
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
                $this->category->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$category = $this->category->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'category is not found.',
            ];
        } elseif (!$this->_attempt_delete($category_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'category is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'category is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($category_id)
    {
        $where = ['id' => $category_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // Update into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->category->update($where, $values)) {
                $this->error_message = $this->category->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize category/subcategory names.
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
        return 'category';
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
        return ['category_id', 'group_id', 'name', 'remarks', 'is_deleted'];
    }

    protected function _consistency_child_tables(): array
    {
        return [];
    }
}
