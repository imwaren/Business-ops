<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Asset_group;

class Asset_groups extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $asset_group;
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
        $this->asset_group = new \App\Models\Asset_group();
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

        $this->asset_group = new \App\Models\Asset_group($db);

        return true;
    }

    /**
     * Get asset_groups
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $asset_group_id = $this->payload['asset_group_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $asset_groups = $this->asset_group->get_details($asset_group_id, $term, $page, $data_size)?:[];
        $size = count($this->asset_group->get_details($asset_group_id, $term, null, null)?:[]);

        if(!$asset_groups) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No asset_groups found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $asset_groups
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create asset_group
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $name = $this->payload['name'];

        $this->db->transBegin();

        // -----------------------------
        // 1️⃣ Check data consistency
        // -----------------------------

        // Get master count first
        $this->resolve_branch_models(0);
        $master_count = count($this->asset_group->select('id', ['is_deleted' => 0]) ?? []);

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
            $branch_count = count($this->asset_group
                ->select('id', ['is_deleted' => 0]) ?? []);

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        // -----------------------------
        // 2️⃣ Check duplicate (master only)
        // -----------------------------

        $this->resolve_branch_models(0);

        if ($this->_has_duplicate('asset_group', [
            'name' => $name,
            'is_deleted' => 0
        ])) {
            $this->db->transRollback();

            return $this->_create_response(FAIL, [
                "Asset group {$name} already exists."
            ]);
        }

        // -----------------------------
        // 3️⃣ Attempt insert
        // -----------------------------

        if (!$asset_group_id = $this->_attempt_create()) {
            $this->db->transRollback();

            return $this->_create_response(FAIL, [
                "Failed to add asset_group."
            ]);
        }

        // -----------------------------
        // 4️⃣ Success
        // -----------------------------

        $this->db->transCommit();

        return $this->_create_response(SUCCESS, [
            'asset_group_id' => $asset_group_id,
            'message' => 'Asset group added successfully.'
        ]);
    }

    /**
     * Attempt to create a asset_group
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->asset_group->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        // 1️⃣ Insert into MASTER first
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->asset_group->insert($values)) {
                $this->error_message = $this->asset_group->error();
                return false;
            }
        }

        // 2️⃣ Insert into other branches
        $this->resolve_branch_models(0);
        $asset_group_id = $this->asset_group->insert($values);

        if (!$asset_group_id) {
            $this->error_message = $this->asset_group->error();
            return false;
        }

        return $asset_group_id;
    }

    /**
     * Update asset_group
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $name = $this->payload['name'];
        $asset_group_id = $this->payload['asset_group_id'];
        $where = [
                    'id' => $asset_group_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        // -----------------------------
        // 1️⃣ Check data consistency
        // -----------------------------

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->asset_group->select('id', ['is_deleted' => 0]) ?? []
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
                $this->asset_group->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$asset_group = $this->asset_group->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'asset_group is not found.'
            ];
        } elseif ($this->_has_duplicate('asset_group', ['name' => $name, 'is_deleted' => 0], $asset_group_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Asset group $name already exists."
            ];
        } elseif (!$this->_attempt_update($asset_group)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update asset_group.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'asset_group_id' => $asset_group_id,
                'message'    => 'asset_group updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($asset_group)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->asset_group->allowedFields is declared at the top of the model
        foreach($this->asset_group->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $asset_group->id];

        // 1️⃣ Update MASTER first
        $this->resolve_branch_models(0);

        if (!$this->asset_group->update($where, $values)) {
            $this->error_message = $this->asset_group->error();
            return false;
        }

        // 2️⃣ Update other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {

            $this->resolve_branch_models($branch_id);

            if (!$this->asset_group->update($where, $values)) {
                $this->error_message = $this->asset_group->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Delete asset_groups
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $asset_group_id = $this->payload['asset_group_id'];

        $where = ['id' => $asset_group_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$asset_group = $this->asset_group->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'asset_group is not found.',
            ];
        } elseif (!$this->_attempt_delete($asset_group_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'asset_group is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'asset_group is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($asset_group_id)
    {
        $where = ['id' => $asset_group_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // 1️⃣ Update MASTER first
        $this->resolve_branch_models(0);

        if (!$this->asset_group->update($where, $values)) {
            $this->error_message = $this->asset_group->error();
            return false;
        }

        // 2️⃣ Update other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {

            $this->resolve_branch_models($branch_id);

            if (!$this->asset_group->update($where, $values)) {
                $this->error_message = $this->asset_group->error();
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

    public function custom_validations($validation_name) {
        $is_valid = true;
        switch($validation_name) {
            case 'create' || 'update':
                $key = 'asset_group_id';
                $id = array_key_exists($key, $this->payload)?$this->payload[$key]:null;

                // Put your custom validations here
                // If that validation can be applied to other modules, add it to MYT Custom Rules
        }
        return $is_valid;
    }


    protected function _consistency_table(): string
    {
        return 'asset_group';
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
        return ['name', 'is_deleted'];
    }

    protected function _consistency_child_tables(): array
    {
        return [];
    }
}
