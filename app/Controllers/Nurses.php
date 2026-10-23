<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Nurse;
use App\Models\Role_emr;
use App\Models\User;
use App\Models\User_emr;
use Config\Branch;

class Nurses extends MYT_Controller
{
    protected $request;
    protected $nurse;
    protected $user;
    protected $user_emr;
    protected $role_emr;
    protected $helpers = ['text'];
    protected $error_message;

    public const MASTER_ROLE_ID = 42;
    public const EMR_ROLE_ID = 2;

    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->_load_essentials();
    }

    protected function _load_essentials()
    {
        helper('encryption_helper');

        $this->nurse = new Nurse();
        $this->user = new User();
        $this->user_emr = new User_emr();
        $this->role_emr = new Role_emr();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Selectable EMR branch ids (branch copies live on each EMR database; master holds the catalog row).
     */
    protected function _emr_branch_ids(): array
    {
        return array_map('intval', array_keys($this->_emr_map()));
    }

    /**
     * Master DB holds the catalog nurse row (branch_in lists EMR branches).
     */
    protected function resolve_master_nurse_model(): bool
    {
        $this->nurse = new Nurse(db_connect('master'));

        return true;
    }

    /**
     * Resolve Nurse (and EMR user) models for a branch EMR connection.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $emrMap = $this->_emr_map();
        if (!isset($emrMap[$branch_id])) {
            return false;
        }

        $db = db_connect($emrMap[$branch_id]);
        $this->nurse = new Nurse($db);
        $this->user_emr = new User_emr($db);

        return true;
    }

    protected function _emr_map(): array
    {
        return [
            1 => 'mobile_emr',
            2 => 'oakridge_emr',
            3 => 'mactan_emr',
            4 => 'consolacion_emr',
            7 => 'mantlewood_emr',
        ];
    }

    /**
     * Parse comma-separated branch_in into unique valid EMR branch ids.
     * Warehouse (5) and pharmacy (6) are ignored — nurses are not saved there.
     * @return array|false
     */
    protected function _parse_branch_ids($branch_in)
    {
        if ($branch_in === null || $branch_in === '') {
            return false;
        }

        $allowed = array_merge(array_keys($this->_emr_map()), [5, 6]);
        $parts = array_filter(array_map('trim', explode(',', (string) $branch_in)), static function ($part) {
            return $part !== '';
        });

        if (empty($parts)) {
            return false;
        }

        $ids = [];
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return false;
            }
            $id = (int) $part;
            if (!in_array($id, $allowed, true)) {
                return false;
            }
            $ids[] = $id;
        }

        $ids = array_values(array_filter($ids, static function ($id) {
            return !in_array($id, [5, 6], true);
        }));

        if (empty($ids)) {
            return false;
        }

        return array_values(array_unique($ids));
    }

    protected function _normalize_emr_branch_ids(array $branch_ids): array
    {
        return array_values(array_unique(array_map('intval', $branch_ids)));
    }

    protected function resolve_emr_models($branch_id)
    {
        return $this->resolve_branch_models((int) $branch_id);
    }

    /**
     * Live row on the currently resolved branch's nurse model, by username.
     */
    protected function _find_nurse_by_username($username)
    {
        if (!$username) {
            return null;
        }

        $row = $this->nurse->select('', ['username' => $username, 'is_deleted' => 0], 1);
        if ($row === false || $row === null) {
            return null;
        }

        return $row;
    }

    /**
     * Branch-local nurse.id (requires resolve_branch_models for that branch).
     */
    protected function _branch_nurse_id($username): ?int
    {
        $row = $this->_find_nurse_by_username($username);
        if (!$row || empty($row->id)) {
            return null;
        }

        return (int) $row->id;
    }

    protected function _nurse_username_exists_for_create($username, array $branch_ids): bool
    {
        $this->resolve_master_nurse_model();
        if ($this->_find_nurse_by_username($username)) {
            return true;
        }

        foreach ($this->_normalize_emr_branch_ids($branch_ids) as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                continue;
            }
            if ($this->_find_nurse_by_username($username)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nurse rows exist on each branch EMR database; search by username
     * across connections. First match is the anchor for defaults.
     */
    protected function _find_nurse_anywhere($username)
    {
        if (!$username) {
            return null;
        }

        $this->resolve_master_nurse_model();
        if ($row = $this->_find_nurse_by_username($username)) {
            return $row;
        }

        foreach ($this->_emr_branch_ids() as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                continue;
            }
            if ($row = $this->_find_nurse_by_username($username)) {
                return $row;
            }
        }

        return null;
    }

    protected function _normalize_license_no($license_no): ?string
    {
        if ($license_no === null || $license_no === '') {
            return null;
        }

        $trimmed = trim((string) $license_no);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Master catalog license (Business Ops list / update form source).
     */
    protected function _catalog_license_for_username($username): ?string
    {
        if (!$username) {
            return null;
        }

        $this->resolve_master_nurse_model();
        $row = $this->_find_nurse_by_username($username);
        if (!$row) {
            return null;
        }

        return $this->_normalize_license_no($row->license_no ?? null);
    }

    /**
     * RULES: On update, only re-check license when the value changes on the master catalog row.
     * Unchanged license skips validation so legacy duplicate data does not block other field edits.
     */
    protected function _license_changed_on_update($username, $new_license): bool
    {
        $new = $this->_normalize_license_no($new_license);
        $current = $this->_catalog_license_for_username($username);

        if ($current === null && $new === null) {
            return false;
        }

        return $new !== $current;
    }

    /**
     * RULES: One person has nurse rows on master and each EMR branch (same username, same license).
     * Only another username counts as a duplicate.
     *
     * @return string|null conflicting username
     */
    protected function _license_conflict_username($license_no, $username): ?string
    {
        $license_no = $this->_normalize_license_no($license_no);
        if (!$license_no || !$username) {
            return null;
        }

        $this->resolve_master_nurse_model();
        $conflict = $this->nurse->find_username_with_license_other_than($license_no, $username);
        if ($conflict) {
            return $conflict;
        }

        foreach ($this->_emr_branch_ids() as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                continue;
            }
            $conflict = $this->nurse->find_username_with_license_other_than($license_no, $username);
            if ($conflict) {
                return $conflict;
            }
        }

        return null;
    }

    protected function _full_name_taken_by_other_nurse($first_name, $middle_name, $last_name, $username): bool
    {
        if (!$username) {
            return false;
        }

        $this->resolve_master_nurse_model();
        if ($this->nurse->validate_full_name_other_username($first_name, $middle_name, $last_name, $username)) {
            return true;
        }

        foreach ($this->_emr_branch_ids() as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                continue;
            }
            if ($this->nurse->validate_full_name_other_username($first_name, $middle_name, $last_name, $username)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Master user is created for Business Ops login; EMR user rows link via master_doctor_id.
     */
    protected function _find_master_user($username)
    {
        if (!$username) {
            return null;
        }

        return $this->user->select('', ['email' => $username, 'is_deleted' => 0], 1) ?: null;
    }

    /**
     * Build a full insert row for a branch the nurse doesn't have a row in
     * yet: allowed fields default to the anchor row's current values,
     * overridden by whatever this request is explicitly changing.
     */
    protected function _nurse_insert_values($nurse, array $overrides): array
    {
        $insert = [];
        foreach ($this->nurse->allowedFields as $field) {
            $insert[$field] = array_key_exists($field, $overrides) ? $overrides[$field] : ($nurse->$field ?? null);
        }

        $requester = $this->payload['requester'] ?? 0;
        $now = date('Y-m-d H:i:s');

        $insert['added_by'] = $requester;
        $insert['added_on'] = $now;
        $insert['status'] = $nurse->status ?? 'active';
        $insert['activated_by'] = $nurse->activated_by ?? $requester;
        $insert['activated_on'] = $nurse->activated_on ?? $now;
        $insert['is_deleted'] = 0;

        return $insert;
    }

    /**
     * Single nurse by username (master then EMR branches), or a listing from
     * master (optionally filtered by branch_id in branch_in).
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $username = $this->payload['username'] ?? null;

        if ($username) {
            $nurse = $this->_find_nurse_anywhere($username);

            if (!$nurse) {
                return $this->_create_response(FAIL_NOT_FOUND, ['No nurses found']);
            }

            return $this->_create_response(SUCCESS, [
                'size' => 1,
                'data' => [$nurse]
            ]);
        }

        $branch_id = $this->payload['branch_id'] ?? null;

        $page = (int) ($this->payload['page'] ?? 1);
        $data_size = (int) ($this->payload['data_size'] ?? 10);
        $term = $this->payload['term'] ?? null;
        $status = $this->payload['status'] ?? null;

        if (!$this->resolve_master_nurse_model()) {
            return $this->_create_response(FAIL, ['Failed to connect to master database.']);
        }

        $rows = $this->nurse->get_details(null, $status, $term, null, null) ?: [];

        if ($branch_id !== null && $branch_id !== '') {
            $filter_branch = (int) $branch_id;
            $rows = array_values(array_filter($rows, static function ($row) use ($filter_branch) {
                $row = (array) $row;
                $branch_in = array_map('intval', array_filter(array_map('trim', explode(',', (string) ($row['branch_in'] ?? '')))));
                return in_array($filter_branch, $branch_in, true);
            }));
        }

        $nurses = $this->_map_master_rows($rows);
        $size = count($nurses);
        $offset = max(0, ($page - 1) * $data_size);
        $nurses = array_slice($nurses, $offset, $data_size > 0 ? $data_size : null);

        if (!$nurses) {
            return $this->_create_response(FAIL_NOT_FOUND, [
                'No nurses found'
            ]);
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $nurses
        ]);
    }

    protected function _map_master_rows(array $rows): array
    {
        $branchConfig = new Branch();
        $users = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $branch_in = array_filter(
                array_map('trim', explode(',', (string) ($row['branch_in'] ?? ''))),
                static function ($id) {
                    return $id !== '';
                }
            );
            $branch_names = [];
            foreach ($branch_in as $branch_id) {
                $name = $branchConfig->getBranchName((int) $branch_id);
                if ($name) {
                    $branch_names[] = $name;
                }
            }

            $username = $row['username'] ?? '';

            $users[] = [
                'id' => $username !== '' ? $username : ($row['id'] ?? null),
                'full_name' => $row['full_name'] ?? null,
                'contact_no' => $row['contact_no'] ?? null,
                'email' => $row['email'] ?? null,
                'username' => $row['username'] ?? null,
                'license_no' => $row['license_no'] ?? null,
                'status' => $row['status'] ?? null,
                'branch_in' => implode(',', $branch_in),
                'branch_id' => implode(',', $branch_in),
                'branch_names' => implode(', ', $branch_names),
            ];
        }

        usort($users, static function ($a, $b) {
            return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
        });

        return $users;
    }

    protected function _has_duplicate($table, $field, $value, $id = null)
    {
        $where = [
            $field => $value,
            'is_deleted' => 0
        ];

        $id ? ($where['id <>'] = $id) : null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }

    public function custom_validations($name)
    {
        return true;
    }

    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $username = $this->payload['username'] ?? null;
        if (!$username) {
            return $this->_create_response(FAIL, ['username is required.']);
        }

        $this->db->transBegin();

        $nurse = $this->_find_nurse_anywhere($username);

        if (!$nurse) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'nurse not found.'
            ];
        } elseif (!$this->_attempt_delete($nurse)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'nurse not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'status' => 200,
                'message' => 'nurse deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Soft-delete nurse rows on every EMR branch, the master user, and EMR user rows.
     */
    protected function _attempt_delete($nurse)
    {
        $username = $nurse->username;
        $suffix = date('YmdHis');
        $audit = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        $this->resolve_master_nurse_model();
        $row = $this->_find_nurse_by_username($username);
        if ($row) {
            $values = array_merge($audit, ['username' => $row->username . '_' . $suffix]);
            if (!$this->nurse->update(['id' => $row->id], $values)) {
                $this->error_message = $this->nurse->error();
                return false;
            }
        }

        foreach ($this->_emr_branch_ids() as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                continue;
            }
            $row = $this->_find_nurse_by_username($username);
            if (!$row) {
                continue;
            }

            $values = array_merge($audit, ['username' => $row->username . '_' . $suffix]);
            if (!$this->nurse->update(['id' => $row->id], $values)) {
                $this->error_message = $this->nurse->error();
                return false;
            }
        }

        $master_user = $this->_find_master_user($username);

        if ($master_user) {
            $master_values = array_merge($audit, [
                'email' => $username . '_' . $suffix,
            ]);

            if (!$this->user->update(['id' => $master_user->id], $master_values)) {
                $this->error_message = $this->user->error();
                return false;
            }

            foreach ($this->_emr_map() as $emr_connection) {
                $this->user_emr = new User_emr(db_connect($emr_connection));

                $emr_user = $this->user_emr->select('', [
                    'username' => $username,
                    'is_deleted' => 0
                ], 1);

                if (!$emr_user) {
                    continue;
                }

                $emr_values = $audit;
                if (!empty($emr_user->username)) {
                    $emr_values['username'] = $emr_user->username . '_' . $suffix;
                }

                if (!$this->user_emr->update(['id' => $emr_user->id], $emr_values)) {
                    $this->error_message = $this->user_emr->error();
                    return false;
                }
            }
        }

        return true;
    }

    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $username = $this->payload['username'] ?? null;
        if (!$username) {
            return $this->_create_response(FAIL, ['username is required.']);
        }

        $branch_ids = $this->_parse_branch_ids($this->payload['branch_in'] ?? null);
        if ($branch_ids === false) {
            return $this->_create_response(FAIL, ['Invalid or missing branches.']);
        }
        $this->payload['branch_in'] = implode(',', $branch_ids);

        $this->db->transBegin();

        $nurse = $this->_find_nurse_anywhere($username);

        if (!$nurse) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'nurse not found.'
            ];
        } elseif (
            $this->_license_changed_on_update($nurse->username, $this->payload['license_no'] ?? null)
            && ($license_conflict = $this->_license_conflict_username(
                $this->payload['license_no'] ?? null,
                $nurse->username
            ))
        ) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'License number already exists! (used by ' . $license_conflict . ')'
            ];
        } elseif ($this->_full_name_taken_by_other_nurse(
            (isset($this->payload['first_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['first_name'])) : null),
            (isset($this->payload['middle_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['middle_name'])) : null),
            (isset($this->payload['last_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['last_name'])) : null),
            $nurse->username
        )) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Nurse ' . (isset($this->payload['first_name']) ? $this->payload['first_name'] : '') . ' ' . (isset($this->payload['middle_name']) ? $this->payload['middle_name'] : '') . ' ' . (isset($this->payload['last_name']) ? $this->payload['last_name'] : '') . ' already exists!'
            ];
        } elseif (!$this->_attempt_update($nurse, $branch_ids)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update nurse.'
            ];
        } elseif (!$this->_attempt_update_master_user($nurse)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update master user.'
            ];
        } elseif (!$this->_attempt_update_emr_user($nurse)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update EMR user.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'username' => $username,
                'message' => 'nurse updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Update or insert nurse rows on selected EMR branches; soft-delete
     * copies on branches no longer selected. `username` is never changed here.
     */
    protected function _attempt_update($nurse, array $branch_ids)
    {
        $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);

        $values = [
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('password', $this->payload) && $this->payload['password'] !== '') {
            $this->payload['password'] = app_password_hash($this->payload['password']);
        }

        $not_allowed = ['token', 'token_expiry', 'status', 'activated_by', 'activated_on', 'inactivated_by', 'inactivated_on', 'username'];
        foreach ($this->nurse->allowedFields as $field) {
            if (in_array($field, $not_allowed)) {
                continue;
            }
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            if ($field === 'password' && ($this->payload[$field] === '' || $this->payload[$field] === null)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $current_username = $nurse->username;

        $this->resolve_master_nurse_model();
        $master_row = $this->_find_nurse_by_username($current_username);
        if ($master_row) {
            if (!$this->nurse->update(['id' => $master_row->id], $values)) {
                $this->error_message = $this->nurse->error();
                return false;
            }
        } else {
            $result = $this->nurse->insert($this->_nurse_insert_values($nurse, $values));
            if ($result === false) {
                $this->error_message = $this->nurse->error();
                return false;
            }
        }

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                $this->error_message = 'Branch database unavailable.';
                return false;
            }

            $row = $this->_find_nurse_by_username($current_username);
            if ($row) {
                if (!$this->nurse->update(['id' => $row->id], $values)) {
                    $this->error_message = $this->nurse->error();
                    return false;
                }
            } else {
                $result = $this->nurse->insert($this->_nurse_insert_values($nurse, $values));
                if ($result === false) {
                    $this->error_message = $this->nurse->error();
                    return false;
                }
            }
        }

        $suffix = date('YmdHis');
        $soft_delete = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        foreach ($this->_emr_branch_ids() as $branch_id) {
            if (in_array($branch_id, $branch_ids, true)) {
                continue;
            }

            $this->resolve_branch_models($branch_id);
            $row = $this->_find_nurse_by_username($current_username);
            if (!$row) {
                continue;
            }

            $delete_values = $soft_delete;
            if (!empty($row->username)) {
                $delete_values['username'] = $row->username . '_' . $suffix;
            }

            if (!$this->nurse->update(['id' => $row->id], $delete_values)) {
                $this->error_message = $this->nurse->error();
                return false;
            }
        }

        return true;
    }

    protected function _attempt_update_master_user($nurse)
    {
        $master_user = $this->_find_master_user($nurse->username);

        if (!$master_user) {
            return true;
        }

        $values = [
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('first_name', $this->payload)) {
            $values['first_name'] = $this->payload['first_name'] != '' ? $this->payload['first_name'] : null;
        }
        if (array_key_exists('middle_name', $this->payload)) {
            $values['middle_name'] = $this->payload['middle_name'] != '' ? $this->payload['middle_name'] : null;
        }
        if (array_key_exists('last_name', $this->payload)) {
            $values['last_name'] = $this->payload['last_name'] != '' ? $this->payload['last_name'] : null;
        }
        if (array_key_exists('branch_in', $this->payload) && $this->payload['branch_in'] !== '') {
            $values['branch_id'] = $this->payload['branch_in'];
        }
        if (array_key_exists('password', $this->payload) && $this->payload['password'] !== '' && $this->payload['password'] !== null) {
            $values['password'] = $this->payload['password'];
        }

        if (!$this->user->update(['id' => $master_user->id], $values)) {
            $this->error_message = $this->user->error();
            return false;
        }

        return true;
    }

    /**
     * EMR user rows are linked via the master user's id (master_doctor_id).
     */
    protected function _attempt_update_emr_user($nurse)
    {
        $master_user = $this->_find_master_user($nurse->username);

        if (!$master_user) {
            return true;
        }

        $branch_in = $this->payload['branch_in'] ?? $nurse->branch_in ?? null;
        $branch_ids = $this->_parse_branch_ids($branch_in);

        if ($branch_ids === false) {
            return true;
        }

        $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);

        $first = $this->payload['first_name'] ?? $nurse->first_name ?? '';
        $middle = $this->payload['middle_name'] ?? $nurse->middle_name ?? '';
        $last = $this->payload['last_name'] ?? $nurse->last_name ?? '';

        $full_name = trim(
            preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last)
        );

        $role_name = $this->role_emr->get_name_by_id(self::EMR_ROLE_ID);

        /*
        * Values used when updating an existing EMR user
        */
        $update_values = [
            'full_name' => $full_name !== '' ? $full_name : null,
            'role_id' => self::EMR_ROLE_ID,
            'role_name' => $role_name,
            'status' => 'active',
            'is_deleted' => 0,
            'master_doctor_id' => null,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (
            array_key_exists('password', $this->payload) &&
            $this->payload['password'] !== '' &&
            $this->payload['password'] !== null
        ) {
            $update_values['password'] = $this->payload['password'];
        }

        if (array_key_exists('license_no', $this->payload)) {
            $update_values['license_no'] =
                $this->payload['license_no'] != ''
                    ? $this->payload['license_no']
                    : null;
        }

        /*
        * Values used when creating a completely new EMR user
        */
        $insert_values = [
            'master_doctor_id' => null,
            'full_name' => $full_name !== '' ? $full_name : null,
            'username' => $nurse->username,
            'password' => $this->payload['password'] ?? $nurse->password ?? null,
            'role_id' => self::EMR_ROLE_ID,
            'role_name' => $role_name,
            'license_no' => $this->payload['license_no'] ?? $nurse->license_no ?? null,
            'status' => 'active',
            'is_deleted' => 0,
            'activated_by' => $this->payload['requester'] ?? 0,
            'activated_on' => date('Y-m-d H:i:s'),
            'added_by' => $this->payload['requester'] ?? 0,
            'added_on' => date('Y-m-d H:i:s'),
        ];

        foreach ($branch_ids as $branch_id) {

            if (!$this->resolve_emr_models($branch_id)) {
                $this->error_message = 'Invalid branch_in for EMR user.';
                return false;
            }

            $nurse_id = $this->_branch_nurse_id($nurse->username);
            if ($nurse_id === null) {
                $this->error_message = 'Nurse row not found for EMR user on branch ' . $branch_id . '.';
                return false;
            }

            $branch_update = array_merge($update_values, [
                'nurse_id' => $nurse_id,
                'master_doctor_id' => null,
            ]);
            $branch_insert = array_merge($insert_values, [
                'nurse_id' => $nurse_id,
            ]);

            $emr_user = $this->user_emr->select('', [
                'username' => $nurse->username
            ], 1);

            if ($emr_user) {
                if (!$this->user_emr->update(
                    ['id' => $emr_user->id],
                    $branch_update
                )) {
                    $this->error_message = $this->user_emr->error();
                    return false;
                }

            } else {
                $result = $this->user_emr->insert($branch_insert);

                if ($result === false) {
                    $this->error_message = $this->user_emr->error();
                    return false;
                }
            }
        }

        /*
        * Soft-delete EMR users that are no longer selected.
        */
        $suffix = date('YmdHis');

        $soft_delete = [
            'is_deleted' => 1,
            'status' => 'inactive',
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        foreach ($this->_emr_map() as $map_branch_id => $emr_connection) {

            if (in_array((int) $map_branch_id, $branch_ids, true)) {
                continue;
            }

            $this->user_emr = new User_emr(
                db_connect($emr_connection)
            );

            $emr_user = $this->user_emr->select('', [
                'username' => $nurse->username,
                'is_deleted' => 0
            ], 1);

            if (!$emr_user) {
                continue;
            }

            $delete_values = $soft_delete;

            if (!empty($emr_user->username)) {
                $delete_values['username'] =
                    $emr_user->username . '_' . $suffix;
            }

            if (!$this->user_emr->update(
                ['id' => $emr_user->id],
                $delete_values
            )) {
                $this->error_message = $this->user_emr->error();
                return false;
            }
        }

        return true;
    }

    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_ids = $this->_parse_branch_ids($this->payload['branch_in'] ?? null);
        if ($branch_ids === false) {
            return $this->_create_response(FAIL, ['Invalid or missing branches.']);
        }
        $this->payload['branch_in'] = implode(',', $branch_ids);

        $username = $this->payload['username'] ?? null;
        if (!$username) {
            return $this->_create_response(FAIL, ['Username is required.']);
        }

        $this->db->transBegin();

        $response_status = null;
        $response = null;

        if ($this->_find_master_user($username)) {
            $response_status = FAIL;
            $response = ['Username already exists!'];
        } elseif ($this->_nurse_username_exists_for_create($username, $branch_ids)) {
            $response_status = FAIL;
            $response = ['Username already exists!'];
        } else {
            $first = isset($this->payload['first_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['first_name'])) : null;
            $middle = isset($this->payload['middle_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['middle_name'])) : null;
            $last = isset($this->payload['last_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['last_name'])) : null;
            $license_no = $this->payload['license_no'] ?? null;

            $this->resolve_master_nurse_model();
            if ($license_no && $this->nurse->validate_license_no($license_no)) {
                $response_status = FAIL;
                $response = ['License number already exists!'];
            } elseif ($this->nurse->validate_full_name($first, $middle, $last)) {
                $response_status = FAIL;
                $response = [
                    'Nurse ' . ($this->payload['first_name'] ?? '') . ' ' . ($this->payload['middle_name'] ?? '') . ' ' . ($this->payload['last_name'] ?? '') . ' already exists!'
                ];
            } else {
                foreach ($this->_emr_branch_ids() as $branch_id) {
                    if (!$this->resolve_branch_models($branch_id)) {
                        $response_status = FAIL;
                        $response = ['Branch database unavailable.'];
                        break;
                    }

                    if ($license_no && $this->nurse->validate_license_no($license_no)) {
                        $response_status = FAIL;
                        $response = ['License number already exists!'];
                        break;
                    }

                    if ($this->nurse->validate_full_name($first, $middle, $last)) {
                        $response_status = FAIL;
                        $response = [
                            'Nurse ' . ($this->payload['first_name'] ?? '') . ' ' . ($this->payload['middle_name'] ?? '') . ' ' . ($this->payload['last_name'] ?? '') . ' already exists!'
                        ];
                        break;
                    }
                }
            }
        }

        if ($response_status !== null) {
            $this->db->transRollback();
            return $this->_create_response($response_status, $response);
        }

        if (!$this->_attempt_create($branch_ids)) {
            $this->db->transRollback();
            $response = ['Failed to create nurse.'];
            if (!empty($this->error_message) && is_string($this->error_message)) {
                $response[] = $this->error_message;
            }
            return $this->_create_response(FAIL, $response);
        }

        $master_user_id = $this->_attempt_create_master_user();
        if ($master_user_id === false) {
            $this->db->transRollback();
            $response = ['Failed to create master user.'];
            if (!empty($this->error_message) && is_string($this->error_message)) {
                $response[] = $this->error_message;
            }
            return $this->_create_response(FAIL, $response);
        }

        if (!$this->_attempt_create_emr_user($master_user_id)) {
            $this->db->transRollback();
            $response = ['Failed to create EMR user.'];
            if (!empty($this->error_message) && is_string($this->error_message)) {
                $response[] = $this->error_message;
            }
            return $this->_create_response(FAIL, $response);
        }

        $this->db->transCommit();
        return $this->_create_response(SUCCESS, [
            'username' => $username,
            'message' => 'nurse created successfully.'
        ]);
    }

    /**
     * Insert master catalog row, then a nurse row on each selected EMR branch.
     */
    private function _attempt_create(array $branch_ids)
    {
        $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);

        $values = [
            'added_by' => $this->payload['requester'],
            'added_on' => date('Y-m-d H:i:s'),
            'status' => 'active',
            'activated_by' => $this->payload['requester'] ?? 0,
            'activated_on' => date('Y-m-d H:i:s'),
        ];

        $this->payload['password'] = app_password_hash($this->payload['password']);

        $not_allowed = ['token', 'token_expiry', 'status', 'activated_by', 'activated_on', 'inactivated_by', 'inactivated_on'];
        foreach ($this->nurse->allowedFields as $field) {
            if (in_array($field, $not_allowed)) {
                continue;
            }
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $this->resolve_master_nurse_model();
        $result = $this->nurse->insert($values);
        if ($result === false) {
            $this->error_message = $this->nurse->error();
            return false;
        }

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                $this->error_message = 'Branch database unavailable.';
                return false;
            }

            $result = $this->nurse->insert($values);
            if ($result === false) {
                $this->error_message = $this->nurse->error();
                return false;
            }
        }

        return true;
    }

    /**
     * @return int|false master user id, or false on failure
     */
    private function _attempt_create_master_user()
    {
        $email = $this->payload['username'] ?? null;
        if (!$email) {
            $this->error_message = 'Username is required for master user.';
            return false;
        }

        if ($this->user->select('', ['email' => $email, 'is_deleted' => 0], 1)) {
            $this->error_message = 'Master user email already exists.';
            return false;
        }

        $values = [
            'first_name' => $this->payload['first_name'] ?? null,
            'middle_name' => $this->payload['middle_name'] ?? null,
            'last_name' => $this->payload['last_name'] ?? null,
            'email' => $email,
            'password' => $this->payload['password'],
            'role_id' => self::MASTER_ROLE_ID,
            'branch_id' => $this->payload['branch_in'] ?? null,
            'added_by' => $this->payload['requester'] ?? 0,
            'added_on' => date('Y-m-d H:i:s'),
        ];

        $master_user_id = $this->user->insert($values);
        if ($master_user_id === false) {
            $this->error_message = $this->user->error();
            return false;
        }

        return $master_user_id;
    }

    /**
     * EMR branch `user` row (not master): one per selected branch, linked via nurse_id.
     */
    private function _attempt_create_emr_user($master_user_id)
    {
        $branch_ids = $this->_parse_branch_ids($this->payload['branch_in'] ?? null);
        if ($branch_ids === false) {
            $this->error_message = 'Invalid branch_in for EMR user.';
            return false;
        }
        $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);

        $first = $this->payload['first_name'] ?? '';
        $middle = $this->payload['middle_name'] ?? '';
        $last = $this->payload['last_name'] ?? '';
        $full_name = trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last));
        $role_name = $this->role_emr->get_name_by_id(self::EMR_ROLE_ID);
        $username = $this->payload['username'] ?? null;

        $insert_values = [
            'master_doctor_id' => null,
            'full_name' => $full_name !== '' ? $full_name : null,
            'username' => $username,
            'password' => $this->payload['password'] ?? null,
            'role_id' => self::EMR_ROLE_ID,
            'role_name' => $role_name,
            'license_no' => $this->payload['license_no'] ?? null,
            'status' => 'active',
            'activated_by' => $this->payload['requester'] ?? 0,
            'activated_on' => date('Y-m-d H:i:s'),
            'added_by' => $this->payload['requester'] ?? 0,
            'added_on' => date('Y-m-d H:i:s'),
        ];

        $update_values = [
            'master_doctor_id' => null,
            'full_name' => $full_name !== '' ? $full_name : null,
            'username' => $username,
            'role_id' => self::EMR_ROLE_ID,
            'role_name' => $role_name,
            'license_no' => $this->payload['license_no'] ?? null,
            'status' => 'active',
            'is_deleted' => 0,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];
        if (!empty($this->payload['password'])) {
            $update_values['password'] = $this->payload['password'];
        }

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_emr_models($branch_id)) {
                $this->error_message = 'Invalid branch_in for EMR user.';
                return false;
            }

            $nurse_id = $this->_branch_nurse_id($username);
            if ($nurse_id === null) {
                $this->error_message = 'Nurse row not found for EMR user on branch ' . $branch_id . '.';
                return false;
            }

            $branch_insert = array_merge($insert_values, ['nurse_id' => $nurse_id]);
            $branch_update = array_merge($update_values, ['nurse_id' => $nurse_id]);

            $emr_user = $username
                ? $this->user_emr->select('', ['username' => $username], 1)
                : null;

            if ($emr_user) {
                if (!$this->user_emr->update(['id' => $emr_user->id], $branch_update)) {
                    $this->error_message = $this->user_emr->error();
                    return false;
                }
            } elseif (!$this->user_emr->insert($branch_insert)) {
                $this->error_message = $this->user_emr->error();
                return false;
            }
        }

        return true;
    }

    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $username = $this->payload['username'] ?? null;
        if (!$username) {
            return $this->_create_response(FAIL, ['username is required.']);
        }

        $this->db->transBegin();

        $nurse = $this->_find_nurse_anywhere($username);

        if (!$nurse) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'nurse not found.'
            ];
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Invalid status. Allowed values: active, inactive.'
            ];
        } elseif ($status && ($nurse->status ?? null) == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Nurse is already {$status}."
            ];
        } elseif (!$this->_attempt_update_status($nurse)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change nurse status.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'username' => $username,
                'message' => "nurse {$status} successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Update status on every EMR nurse row and linked EMR user rows.
     */
    protected function _attempt_update_status($nurse)
    {
        $status = $this->payload['status'];
        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? 0;

        $values = [
            'status' => $status,
            'updated_by' => $requester,
            'updated_on' => $now,
        ];

        if ($status === 'active') {
            $values['activated_by'] = $requester;
            $values['activated_on'] = $now;
        } elseif ($status === 'inactive') {
            $values['inactivated_by'] = $requester;
            $values['inactivated_on'] = $now;
        } else {
            return false;
        }

        $this->resolve_master_nurse_model();
        $row = $this->_find_nurse_by_username($nurse->username);
        if ($row && !$this->nurse->update(['id' => $row->id], $values)) {
            $this->error_message = $this->nurse->error();
            return false;
        }

        foreach ($this->_emr_branch_ids() as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                continue;
            }
            $row = $this->_find_nurse_by_username($nurse->username);
            if (!$row) {
                continue;
            }
            if (!$this->nurse->update(['id' => $row->id], $values)) {
                $this->error_message = $this->nurse->error();
                return false;
            }
        }

        $master_user = $this->_find_master_user($nurse->username);
        if (!$master_user) {
            return true;
        }

        $branch_ids = $this->_parse_branch_ids($nurse->branch_in ?? null);
        if ($branch_ids === false) {
            return true;
        }
        $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_emr_models($branch_id)) {
                continue;
            }

            $emr_user = $this->user_emr->select('', [
                'username' => $nurse->username,
                'is_deleted' => 0
            ], 1);

            if (!$emr_user) {
                continue;
            }

            if (!$this->user_emr->update(['id' => $emr_user->id], $values)) {
                $this->error_message = $this->user_emr->error();
                return false;
            }
        }

        return true;
    }

    public function change_password()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $username = $this->payload['username'] ?? null;
        if (!$username) {
            return $this->_create_response(FAIL, ['username is required.']);
        }

        $this->db->transBegin();

        $nurse = $this->_find_nurse_anywhere($username);

        if (!$nurse) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Nurse not found.'
            ];
        } elseif (!$this->_attempt_change_password($nurse)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change passowrd.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'username' => $username,
                'message' => 'Password changed successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Change password on EMR nurse rows, master user, and linked EMR user rows.
     */
    protected function _attempt_change_password($nurse)
    {
        $hashed = app_password_hash($this->payload['password']);
        $values = [
            'password' => $hashed,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        $this->resolve_master_nurse_model();
        $row = $this->_find_nurse_by_username($nurse->username);
        if ($row && !$this->nurse->update(['id' => $row->id], $values)) {
            return false;
        }

        foreach ($this->_emr_branch_ids() as $branch_id) {
            if (!$this->resolve_branch_models($branch_id)) {
                continue;
            }
            $row = $this->_find_nurse_by_username($nurse->username);
            if (!$row) {
                continue;
            }
            if (!$this->nurse->update(['id' => $row->id], $values)) {
                return false;
            }
        }

        $master_user = $this->_find_master_user($nurse->username);

        if ($master_user) {
            $this->user->update(['id' => $master_user->id], [
                'password' => $hashed,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s'),
            ]);

            $branch_ids = $this->_parse_branch_ids($nurse->branch_in ?? null);
            if ($branch_ids !== false) {
                $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);
                foreach ($branch_ids as $branch_id) {
                    if (!$this->resolve_emr_models($branch_id)) {
                        continue;
                    }

                    $emr_user = $this->user_emr->select('', [
                        'username' => $nurse->username,
                        'is_deleted' => 0
                    ], 1);

                    if ($emr_user) {
                        $this->user_emr->update(['id' => $emr_user->id], [
                            'password' => $hashed,
                            'updated_by' => $this->payload['requester'],
                            'updated_on' => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
        }

        return true;
    }
}