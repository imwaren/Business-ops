<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;

class Account_types extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $account_type;
    protected $helpers = ['text'];

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

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
        $this->account_type = new \App\Models\Account_type();
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

        $this->account_type = new \App\Models\Account_type($db);

        return true;
    }

    /**
     * Get account_types
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $is_input_tax = $this->payload['is_input_tax'] ?? null;
        $account_type_id = $this->payload['account_type_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $is_subtype_cash_in_banks = $this->payload['is_subtype_cash_in_banks'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $account_types = $this->account_type->get_details($account_type_id, null, $is_input_tax, $status, $is_subtype_cash_in_banks, $term, $page, $data_size)?:[];

        if(!$account_types) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No chart of account found.'
            ];
        } else {
            foreach ($account_types as $key => $account_type) {
                $account_types_item_details = $this->account_type->get_child_details(null, $account_type['id']);
                $account_types[$key]['account_type_subtypes'] = $this->_build_nested_subtypes($account_types_item_details, $account_type['id']);
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->account_type->get_details($account_type_id, null, $is_input_tax, $status, null, $term, null, null)?:[]),
                'data' => $account_types
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Build nested subtypes
     */
    private function _build_nested_subtypes($subtypes, $parent_id = null)
    {
        $nested = [];

        foreach ($subtypes as $subtype) {
            if ($subtype['parent_id'] == $parent_id) {
                $children = $this->_build_nested_subtypes($subtypes, $subtype['id']);
                if ($children) {
                    $subtype['subtypes'] = $children;
                }
                $nested[] = $subtype;
            }
        }

        return $nested;
    }

    /**
     * Get all account_types
     */
    public function get_last()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $is_input_tax = $this->payload['is_input_tax'] ?? null;

        $account_types = $this->account_type->get_details(null, null, $is_input_tax, null, null, null, null)?:[];

        if (!$account_types) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No chart of account found.'
            ];
        } else {
            foreach ($account_types as $key => $account_type) {
                $account_types_item_details = $this->account_type->get_child_details(null, $account_type['id']);
                if (empty($account_types_item_details)) {
                    $last_types = [$account_type];
                } else {
                    $last_types = $this->_get_last_subtypes($account_types_item_details, $account_type['id']);
                }

                $account_types[$key]['last_types'] = $last_types;
            }

            $response_status = SUCCESS;
            $response = [
                'data' => $account_types
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get last subtypes (those without any further subtypes)
     */
    private function _get_last_subtypes($subtypes, $parent_id = null)
    {
        $last_subtypes = [];

        foreach ($subtypes as $subtype) {
            if ($subtype['parent_id'] == $parent_id) {
                // Recursively check if there are any children
                $children = $this->_get_last_subtypes($subtypes, $subtype['id']);
                if (empty($children)) {
                    // If there are no children, this is a "last" subtype
                    $last_subtypes[] = $subtype;
                } else {
                    // If there are children, add their last subtypes instead
                    $last_subtypes = array_merge($last_subtypes, $children);
                }
            }
        }

        return $last_subtypes;
    }

    /**
     * Create account_type
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
            $this->account_type->select('id', ['is_deleted' => 0]) ?? []
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
                $this->account_type->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$account_type_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add chart of account. ",
            ];
        } elseif (isset($this->payload['subtypes']) && !empty($this->payload['subtypes']) && !$this->_attempt_create_items(null, $account_type_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add chart of account sub accounts. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'account_type_id' => $account_type_id,
                'message'    => 'chart of account added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a account_type
     */
    private function _attempt_create()
    {
        $values = [
            'name' => $this->payload['name'],
            'category' => $this->payload['category'],
            'nature' => $this->payload['nature'],
            'financial_statement' => $this->payload['financial_statement'],
            'status' => $this->payload['status'],
            'group_code' => $this->payload['group_code'] ?? null,
            'code' => $this->payload['code'] ?? null,
            'tax_code' => $this->payload['tax_code'] ?? null,
            'description' => $this->payload['description'] ?? null,
            'is_dashboard' => $this->payload['is_dashboard'] ?? 0,
            'is_expense_claims' => $this->payload['is_expense_claims'] ?? 0,
            'is_enable_payments' => $this->payload['is_enable_payments'] ?? 0,
            'added_by' => $this->payload['requester'],
            'added_on' => date('Y-m-d H:i:s')
        ];

        // 1️⃣ Insert into MASTER first
        $this->resolve_branch_models(0);
        $account_type_id = $this->account_type->insert($values);
        if (!$account_type_id) {
            $this->error_message = $this->account_type->error();
            return false;
        }

        // 2️⃣ Insert into other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->account_type->insert($values)) {
                $this->error_message = $this->account_type->error();
                return false;
            }
        }

        return $account_type_id;
    }

    /**
     * Generate account type items recursively
     */
    protected function _attempt_create_items($subtypes = null, $parent_subtype_id = null)
    {
        $category = $this->payload['category'];
        $nature = $this->payload['nature'];
        $financial_statement = $this->payload['financial_statement'];
        $status = $this->payload['status'];
        $description = $this->payload['description'] ?? null;
        $is_dashboard = $this->payload['is_dashboard'] ?? 0;
        $is_expense_claims = $this->payload['is_expense_claims'] ?? 0;
        $is_enable_payments = $this->payload['is_enable_payments'] ?? 0;

        if (is_null($subtypes)) {
            $subtypes = $this->payload['subtypes']; // Expecting a nested array
        }

        foreach ($subtypes as $subtype) {
            $values = [
                'parent_id' => $parent_subtype_id,
                'name' => $subtype['name'],
                'group_code' => $subtype['group_code'] ?? null,
                'code' => $subtype['code'] ?? null,
                'no' => $subtype['no'] ?? null,
                'tax_code' => $subtype['tax_code'] ?? null,
                'category' => $category,
                'nature' => $nature,
                'financial_statement' => $financial_statement,
                'status' => $status,
                'description' => $description,
                'is_dashboard' => $is_dashboard,
                'is_expense_claims' => $is_expense_claims,
                'is_enable_payments' => $is_enable_payments,
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s')
            ];

            // 1️⃣ Insert into MASTER first
            $this->resolve_branch_models(0);
            $subtype_id = $this->account_type->insert($values);
            if (!$subtype_id) {
                $this->error_message = $this->account_type->error();
                return false;
            }

            // 2️⃣ Insert into other branches
            foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->account_type->insert($values)) {
                    $this->error_message = $this->account_type->error();
                    return false;
                }
            }
            
            if (isset($subtype['subtypes'])) {
                if (!$this->_attempt_create_items($subtype['subtypes'], $subtype_id)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update account_type
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $account_type_id = $this->payload['account_type_id'];
        $where = [
                    'id' => $account_type_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        // -----------------------------
        // 1️⃣ Check data consistency
        // -----------------------------

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->account_type->select('id', ['is_deleted' => 0]) ?? []
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
                $this->account_type->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }
        
        if (!$account_type = $this->account_type->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'chart of account is not found.'
            ];
        } elseif (!$this->_attempt_update($account_type)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update chart of account.',
            ];
        } elseif (isset($this->payload['subtypes']) && !empty($this->payload['subtypes']) && !$this->_attempt_update_items(null, $account_type_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update account_type subtypes. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'account_type_id' => $account_type_id,
                'message'    => 'chart of account updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($account_type)
    {
        $values = [
            'name' => $this->payload['name'],
            'category' => $this->payload['category'],
            'nature' => $this->payload['nature'],
            'financial_statement' => $this->payload['financial_statement'],
            'status' => $this->payload['status'],
            'group_code' => $this->payload['group_code'] ?? $account_type->group_code,
            'code' => $this->payload['code'] ?? $account_type->code,
            'tax_code' => $this->payload['tax_code'] ?? $account_type->tax_code,
            'description' => $this->payload['description'] ?? $account_type->description,
            'is_dashboard' => $this->payload['is_dashboard'] ?? $account_type->is_dashboard,
            'is_expense_claims' => $this->payload['is_expense_claims'] ?? $account_type->is_expense_claims,
            'is_enable_payments' => $this->payload['is_enable_payments'] ?? $account_type->is_enable_payments,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $account_type->id];

        // 1️⃣ Update MASTER first
        $this->resolve_branch_models(0);

        if (!$this->account_type->update($where, $values)) {
            $this->error_message = $this->account_type->error();
            return false;
        }

        // 2️⃣ Update other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {

            $this->resolve_branch_models($branch_id);

            if (!$this->account_type->update($where, $values)) {
                $this->error_message = $this->account_type->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to update account type items (subtypes)
     */
    protected function _attempt_update_items($subtypes = null, $parent_subtype_id = null)
    {
        $category = $this->payload['category'];
        $nature = $this->payload['nature'];
        $financial_statement = $this->payload['financial_statement'];
        $status = $this->payload['status'];
        $description = $this->payload['description'] ?? null;
        $is_dashboard = $this->payload['is_dashboard'] ?? 0;
        $is_expense_claims = $this->payload['is_expense_claims'] ?? 0;
        $is_enable_payments = $this->payload['is_enable_payments'] ?? 0;

        if (is_null($subtypes)) {
            $subtypes = $this->payload['subtypes'] ?? [];
        }

        // 1. Get all current items for this account type
        $existing_items = $this->account_type->select('id', [
            'parent_id' => $parent_subtype_id,
            'is_deleted' => 0
        ]);
        $existing_ids = array_column($existing_items, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        foreach ($subtypes as $subtype) {
            if (!empty($subtype['id'])) {
                $payload_ids[] = (int)$subtype['id'];
            }
        }

        // 3. Delete missing ones (set is_deleted = 1)
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];

                // 1️⃣ Update MASTER first
                $this->resolve_branch_models(0);
        
                if (!$this->account_type->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->account_type->error();
                    return false;
                }
        
                // 2️⃣ Update other branches
                foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
        
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->account_type->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->account_type->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload (update existing or insert new)
        foreach ($subtypes as $subtype) {
            $values = [
                'parent_id' => $parent_subtype_id,
                'name' => $subtype['name'],
                'group_code' => $subtype['group_code'] ?? null,
                'code' => $subtype['code'] ?? null,
                'no' => $subtype['no'] ?? null,
                'tax_code' => $subtype['tax_code'] ?? null,
                'category' => $category,
                'nature' => $nature,
                'financial_statement' => $financial_statement,
                'status' => $status,
                'description' => $description,
                'is_dashboard' => $is_dashboard,
                'is_expense_claims' => $is_expense_claims,
                'is_enable_payments' => $is_enable_payments,
            ];

            if (!empty($subtype['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // 1️⃣ Update MASTER first
                $this->resolve_branch_models(0);
        
                if (!$this->account_type->update(['id' => $subtype['id']], $values)) {
                    $this->error_message = $this->account_type->error();
                    return false;
                }
        
                // 2️⃣ Update other branches
                foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
        
                    $this->resolve_branch_models($branch_id);
        
                    if (!$this->account_type->update(['id' => $subtype['id']], $values)) {
                        $this->error_message = $this->account_type->error();
                        return false;
                    }
                }

                $subtype_id = $subtype['id'];
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                // 1️⃣ Insert into MASTER first
                $this->resolve_branch_models(0);
                $subtype_id = $this->account_type->insert($values);
                if (!$subtype_id) {
                    $this->error_message = $this->account_type->error();
                    return false;
                }

                // 2️⃣ Insert into other branches
                foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->account_type->insert($values)) {
                        $this->error_message = $this->account_type->error();
                        return false;
                    }
                }
            }

            // recursive call for children
            if (isset($subtype['subtypes'])) {
                if (!$this->_attempt_update_items($subtype['subtypes'], $subtype_id)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Delete account_types
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $account_type_id = $this->payload['account_type_id'];

        $where = ['id' => $account_type_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$account_type = $this->account_type->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'chart of account is not found.',
            ];
        } elseif (!$this->_attempt_delete($account_type_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'chart of account is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'chart of account is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($account_type_id)
    {
        $where = ['id' => $account_type_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // 1️⃣ Update MASTER first
        $this->resolve_branch_models(0);

        if (!$this->account_type->update($where, $values)) {
            $this->error_message = $this->account_type->error();
            return false;
        }

        // 2️⃣ Update other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {

            $this->resolve_branch_models($branch_id);

            if (!$this->account_type->update($where, $values)) {
                $this->error_message = $this->account_type->error();
                return false;
            }
        }

        return true;
    }


    protected function _consistency_table(): string
    {
        return 'account_type';
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
            'parent_id', 'name', 'category', 'nature', 'financial_statement',
            'status', 'code', 'tax_code', 'description', 'is_dashboard',
            'is_expense_claims', 'is_enable_payments', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [];
    }
}
