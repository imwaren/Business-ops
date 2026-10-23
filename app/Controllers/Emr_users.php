<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Emr_user;
use App\Models\User_emr;
use App\Models\Role_emr;
use Config\Branch;

class Emr_users extends MYT_Controller
{
    protected $request;
    protected $emr_user;
    protected $user_emr;
    protected $role_emr;
    protected $helpers = ['text'];
    protected $error_message;
    protected $emr_dbs = [];
    protected $active_branch_id = null;

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
        helper('encryption_helper');

        $this->emr_user = new Emr_user();
        $this->user_emr = new User_emr();
        $this->role_emr = new Role_emr();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * EMR connection map keyed by diagnostic branch id (selectable branches only).
     */
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
     * EMR connection map including the master/tag DB as branch 0, mirroring
     * Doctors::resolve_branch_models()'s dbMap pattern.
     */
    protected function _emr_map_all(): array
    {
        return [0 => 'master'] + $this->_emr_map();
    }

    /**
     * Parse comma-separated branch_id / branch_in into unique valid branch IDs.
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

        return array_values(array_unique($ids));
    }

    /**
     * Remap warehouse (5) and pharmacy (6) to Oakridge EMR (2), then unique.
     */
    protected function _normalize_emr_branch_ids(array $branch_in): array
    {
        $normalized = [];
        foreach ($branch_in as $id) {
            $id = (int) $id;
            if (in_array($id, [5, 6], true)) {
                $id = 2;
            }
            $normalized[] = $id;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Resolve EMR user model from a single diagnostic branch id, or 0 for master.
     */
    protected function resolve_emr_models($branch_id)
    {
        if (in_array($branch_id, [5, 6], true)) {
            $branch_id = 2;
        }

        $emrMap = $this->_emr_map_all();
        $branch_id = (int) $branch_id;

        if (!isset($emrMap[$branch_id])) {
            return false;
        }

        $db = $this->emr_dbs[$branch_id] ?? db_connect($emrMap[$branch_id]);
        $this->emr_user = new Emr_user($db);
        $this->user_emr = new User_emr($db);
        $this->active_branch_id = $branch_id;

        return true;
    }

    /**
     * Master DB (0) uses the emr_user table via Emr_user; every real branch
     * only has a user table, via User_emr — pick whichever the last
     * resolve_emr_models() call actually resolved to.
     */
    protected function _current_model()
    {
        return $this->active_branch_id === 0 ? $this->emr_user : $this->user_emr;
    }

    /**
     * Begin transactions on EMR connections that will be written, including master (0).
     */
    protected function _emr_trans_begin(array $branch_in): bool
    {
        $this->emr_dbs = [];
        $emrMap = $this->_emr_map_all();

        foreach ($branch_in as $branch_id) {
            if (!isset($emrMap[$branch_id])) {
                return false;
            }
            $db = db_connect($emrMap[$branch_id]);
            $db->transBegin();
            $this->emr_dbs[$branch_id] = $db;
        }

        return true;
    }

    protected function _emr_trans_commit()
    {
        foreach ($this->emr_dbs as $db) {
            $db->transCommit();
        }
        $this->emr_dbs = [];
    }

    protected function _emr_trans_rollback()
    {
        foreach ($this->emr_dbs as $db) {
            $db->transRollback();
        }
        $this->emr_dbs = [];
    }

    /**
     * Username from payload (username or email).
     */
    protected function _payload_username(): ?string
    {
        $username = $this->payload['username'] ?? $this->payload['email'] ?? null;
        if ($username === null || $username === '') {
            return null;
        }

        return strtolower(preg_replace('/\s+/', '', (string) $username));
    }

    /**
     * Full name from payload (full_name or first/middle/last).
     */
    protected function _payload_full_name(): ?string
    {
        if (!empty($this->payload['full_name'])) {
            $full_name = trim(preg_replace('/\s+/', ' ', (string) $this->payload['full_name']));
            return $full_name !== '' ? $full_name : null;
        }

        $first = $this->payload['first_name'] ?? '';
        $middle = $this->payload['middle_name'] ?? '';
        $last = $this->payload['last_name'] ?? '';
        $full_name = trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last));

        return $full_name !== '' ? $full_name : null;
    }

    /**
     * Mobile number from payload, if provided.
     */
    protected function _payload_mobile_no(): ?string
    {
        $value = $this->payload['mobile_no'] ?? null;
        return ($value !== null && $value !== '') ? (string) $value : null;
    }

    /**
     * Split stored full_name for the existing form fields.
     */
    protected function _split_full_name($full_name): array
    {
        $parts = preg_split('/\s+/', trim((string) $full_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first_name = $parts[0] ?? '';
        $last_name = count($parts) > 1 ? array_pop($parts) : '';
        array_shift($parts);
        $middle_name = implode(' ', $parts);

        return [
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
        ];
    }

    /**
     * Branch ids from payload (branch_id or branch_in).
     */
    protected function _payload_branch_ids()
    {
        $raw = $this->payload['branch_id'] ?? $this->payload['branch_in'] ?? null;
        $ids = $this->_parse_branch_ids($raw);
        if ($ids === false) {
            return false;
        }

        return $this->_normalize_emr_branch_ids($ids);
    }

    /**
     * Find username by id or username, checked against the master DB only.
     * Every EMR user always has a master tag row (see _attempt_create), so
     * master is authoritative here — no need to touch branch DBs at all.
     */
    protected function _find_username($user_id): ?string
    {
        if ($this->resolve_emr_models(0)) {
            $row = $this->emr_user->select('', ['id' => $user_id, 'is_deleted' => 0], 1)
                ?: $this->emr_user->select('', ['username' => $user_id, 'is_deleted' => 0], 1);
            if ($row && !empty($row->username)) {
                return $row->username;
            }
        }

        return is_string($user_id) && $user_id !== '' && !is_numeric($user_id) ? $user_id : null;
    }

    /**
     * Find a live row by username on the current EMR model (master or branch,
     * whichever resolve_emr_models() last resolved to).
     */
    protected function _find_by_username($username)
    {
        if (!$username) {
            return null;
        }

        return $this->_current_model()->select('', ['username' => $username, 'is_deleted' => 0], 1) ?: null;
    }

    /**
     * Username already used, checked against the master DB only (see _find_username).
     */
    protected function _username_exists($username, $exclude_username = null): bool
    {
        if (!$this->resolve_emr_models(0)) {
            return false;
        }

        $row = $this->_find_by_username($username);
        if (!$row) {
            return false;
        }

        return !($exclude_username && strcasecmp($row->username, $exclude_username) === 0);
    }

    /**
     * EMR roles (same source as doctors/get_type).
     */
    public function get_type()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $role_id = $this->payload['role_id'] ?? ($this->payload['type_id'] ?? null);
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $rows = $this->role_emr->get_details($role_id, $term, $page, $data_size) ?: null;
        $size = count($this->role_emr->get_details($role_id, $term, null, null) ?: []) ?: 0;

        if (!$rows) {
            return $this->_create_response(FAIL_NOT_FOUND, [
                'No roles found.'
            ]);
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $rows
        ]);
    }

    /**
     * Get EMR users from the master DB, shaped for the frontend
     * (split full_name, branch_in -> branch_id/branch_names).
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        if (!$this->resolve_emr_models(0)) {
            return $this->_create_response(FAIL, ['Failed to connect to master EMR database.']);
        }

        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $user_id = $this->payload['user_id'] ?? null;
        $role_id = $this->payload['role_id'] ?? null;
        $term = $this->payload['term'] ?? null;

        $rows = $this->emr_user->get_details($user_id, $role_id ?: null, $term) ?: [];
        $users = $this->_map_master_rows($rows);
        $size = count($users);

        if ($page && $data_size) {
            $offset = ((int) $page - 1) * (int) $data_size;
            $users = array_slice($users, $offset, (int) $data_size);
        }

        if (!$users) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No EMR users found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $users
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Shape master rows for the frontend: split full_name into
     * first/middle/last, translate branch_in into branch_id/branch_names.
     */
    protected function _map_master_rows(array $rows): array
    {
        $branchConfig = new Branch();
        $users = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $names = $this->_split_full_name($row['full_name'] ?? '');

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
                'username' => $username,
                'email' => $username,
                'full_name' => $row['full_name'] ?? null,
                'first_name' => $names['first_name'],
                'middle_name' => $names['middle_name'],
                'last_name' => $names['last_name'],
                'mobile_no' => $row['mobile_no'] ?? null,
                'role_id' => $row['role_id'] ?? null,
                'role_name' => $row['role_name'] ?? null,
                'status' => $row['status'] ?? null,
                'branch_in' => implode(',', $branch_in),
                'branch_id' => implode(',', $branch_in), // alias kept for any other consumer still reading branch_id
                'branch_names' => implode(', ', $branch_names),
            ];
        }

        usort($users, static function ($a, $b) {
            return strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? '');
        });

        return $users;
    }

    /**
     * Create EMR user on the master DB (branch 0) and each selected branch DB.
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $username = $this->_payload_username();
        $full_name = $this->_payload_full_name();
        $mobile_no = $this->_payload_mobile_no();
        $branch_in = $this->_payload_branch_ids();
        $role_id = $this->payload['role_id'] ?? null;
        $password = $this->payload['password'] ?? null;

        if (!$username) {
            return $this->_create_response(FAIL, ['Username is required.']);
        }
        if (!$full_name) {
            return $this->_create_response(FAIL, ['Full name is required.']);
        }
        if ($branch_in === false) {
            return $this->_create_response(FAIL, ['Invalid or missing branches.']);
        }
        if ($role_id === null || $role_id === '') {
            return $this->_create_response(FAIL, ['Role is required.']);
        }
        if (!$this->role_emr->exists_by_id($role_id)) {
            return $this->_create_response(FAIL, ['Invalid role. Role not found in EMR.']);
        }
        if (!$password) {
            return $this->_create_response(FAIL, ['Password is required.']);
        }
        if ($this->_username_exists($username)) {
            return $this->_create_response(FAIL, ['Username already exists.']);
        }

        $all_write_ids = array_merge([0], $branch_in);
        if (!$this->_emr_trans_begin($all_write_ids)) {
            return $this->_create_response(FAIL, ['Failed to connect to branch EMR databases.']);
        }

        if (!$this->_attempt_create($username, $full_name, $mobile_no, $branch_in, $role_id, $password)) {
            $this->_emr_trans_rollback();
            $response_status = FAIL;
            $response = [
                'Failed to create EMR user.'
            ];
            if (!empty($this->error_message) && is_string($this->error_message)) {
                $response[] = $this->error_message;
            }
        } else {
            $this->_emr_trans_commit();
            $response_status = SUCCESS;
            $response = [
                'user_id' => $username,
                'message' => 'EMR user created successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Insert the master tag row (branch 0) then the same user row into each
     * selected branch DB, resolving every write through resolve_emr_models()
     * the way Doctors::_attempt_create() resolves every branch through
     * resolve_branch_models().
     */
    private function _attempt_create($username, $full_name, $mobile_no, array $branch_in, $role_id, $password)
    {
        $role_name = $this->role_emr->get_name_by_id($role_id);
        $hashed = app_password_hash($password);
        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? 0;

        $base_values = [
            'master_doctor_id' => null,
            'full_name' => $full_name,
            'username' => $username,
            'password' => $hashed,
            'role_id' => $role_id,
            'role_name' => $role_name,
            'status' => 'active',
            'activated_by' => $requester,
            'activated_on' => $now,
            'added_by' => $requester,
            'added_on' => $now,
        ];

        foreach (array_merge([0], $branch_in) as $branch_id) {
            if (!$this->resolve_emr_models($branch_id)) {
                $this->error_message = 'Invalid branch for EMR user.';
                return false;
            }

            $values = $base_values;
            if ($branch_id === 0) {
                $values['branch_in'] = implode(',', $branch_in);
                if (!$this->emr_user->insert($values)) {
                    $this->error_message = $this->emr_user->error();
                    return false;
                }
                continue;
            }

            if (!$this->user_emr->insert($values)) {
                $this->error_message = $this->user_emr->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update EMR user; add/remove branch copies as needed, keep master (0) tag row in sync.
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $user_id = $this->payload['user_id'] ?? null;
        if (!$user_id) {
            return $this->_create_response(FAIL, ['user_id is required.']);
        }

        $current_username = $this->_find_username($user_id);
        if (!$current_username) {
            return $this->_create_response(FAIL_NOT_FOUND, ['EMR user is not found.']);
        }

        $username = $this->_payload_username() ?: $current_username;
        $full_name = $this->_payload_full_name();
        $mobile_no = $this->_payload_mobile_no();
        $branch_in = $this->_payload_branch_ids();
        $role_id = $this->payload['role_id'] ?? null;

        if ($branch_in === false) {
            return $this->_create_response(FAIL, ['Invalid or missing branches.']);
        }
        if ($role_id !== null && $role_id !== '' && !$this->role_emr->exists_by_id($role_id)) {
            return $this->_create_response(FAIL, ['Invalid role. Role not found in EMR.']);
        }
        if (strcasecmp($username, $current_username) !== 0 && $this->_username_exists($username, $current_username)) {
            return $this->_create_response(FAIL, ['Username already exists.']);
        }

        $all_write_ids = array_merge([0], array_map('intval', array_keys($this->_emr_map())));
        if (!$this->_emr_trans_begin($all_write_ids)) {
            return $this->_create_response(FAIL, ['Failed to connect to branch EMR databases.']);
        }

        if (!$this->_attempt_update($current_username, $username, $full_name, $mobile_no, $branch_in, $role_id)) {
            $this->_emr_trans_rollback();
            $response_status = FAIL;
            $response = [
                'Failed to update EMR user.'
            ];
            if (!empty($this->error_message) && is_string($this->error_message)) {
                $response[] = $this->error_message;
            }
        } else {
            $this->_emr_trans_commit();
            $response_status = SUCCESS;
            $response = [
                'user_id' => $username,
                'message' => 'EMR user updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Update the master tag row (branch 0) — same resolve-then-write shape as branch writes.
     */
    protected function _attempt_update_main($current_username, $username, $full_name, $mobile_no, array $branch_in, $role_id)
    {
        if (!$this->resolve_emr_models(0)) {
            $this->error_message = 'Invalid master EMR connection.';
            return false;
        }

        $main_row = $this->_find_by_username($current_username);
        if (!$main_row) {
            return true;
        }

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? 0;

        $values = [
            'username' => $username,
            'branch_in' => implode(',', $branch_in),
            'updated_by' => $requester,
            'updated_on' => $now,
        ];
        if ($full_name) {
            $values['full_name'] = $full_name;
        }
        if ($role_id !== null && $role_id !== '') {
            $values['role_id'] = $role_id;
            $values['role_name'] = $this->role_emr->get_name_by_id($role_id);
        }
        if (!empty($this->payload['password'])) {
            $values['password'] = app_password_hash($this->payload['password']);
        }

        if (!$this->emr_user->update(['id' => $main_row->id], $values)) {
            $this->error_message = $this->emr_user->error();
            return false;
        }

        return true;
    }

    /**
     * Update existing branch rows, insert into newly selected branches, soft-delete removed ones.
     */
    protected function _attempt_update($current_username, $username, $full_name, $mobile_no, array $branch_in, $role_id)
    {
        if (!$this->_attempt_update_main($current_username, $username, $full_name, $mobile_no, $branch_in, $role_id)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? 0;
        $role_name = ($role_id !== null && $role_id !== '') ? $this->role_emr->get_name_by_id($role_id) : null;

        $update_values = [
            'username' => $username,
            'updated_by' => $requester,
            'updated_on' => $now,
        ];
        if ($full_name) {
            $update_values['full_name'] = $full_name;
        }
        if ($role_id !== null && $role_id !== '') {
            $update_values['role_id'] = $role_id;
            $update_values['role_name'] = $role_name;
        }
        if (!empty($this->payload['password'])) {
            $update_values['password'] = app_password_hash($this->payload['password']);
        }

        $source = $this->_load_source_row($current_username);
        $insert_values = [
            'master_doctor_id' => $source->master_doctor_id ?? null,
            'full_name' => $full_name ?: ($source->full_name ?? null),
            'username' => $username,
            'password' => $update_values['password'] ?? ($source->password ?? null),
            'role_id' => $role_id !== null && $role_id !== '' ? $role_id : ($source->role_id ?? null),
            'role_name' => $role_name ?: ($source->role_name ?? null),
            'signature' => $source->signature ?? null,
            'doctor_fee' => $source->doctor_fee ?? 0,
            'ptr_no' => $source->ptr_no ?? null,
            'license_no' => $source->license_no ?? null,
            's2_no' => $source->s2_no ?? null,
            'specializations' => $source->specializations ?? null,
            'status' => $source->status ?? 'active',
            'activated_by' => $source->activated_by ?? $requester,
            'activated_on' => $source->activated_on ?? $now,
            'added_by' => $requester,
            'added_on' => $now,
        ];

        foreach ($branch_in as $branch_id) {
            if (!$this->resolve_emr_models($branch_id)) {
                $this->error_message = 'Invalid branch for EMR user.';
                return false;
            }

            $model = $this->_current_model();
            $emr_user = $this->_find_by_username($current_username);
            if ($emr_user) {
                if (!$model->update(['id' => $emr_user->id], $update_values)) {
                    $this->error_message = $model->error();
                    return false;
                }
            } else {
                if (!$model->insert($insert_values)) {
                    $this->error_message = $model->error();
                    return false;
                }
            }
        }

        $suffix = date('YmdHis');
        $soft_delete = [
            'is_deleted' => 1,
            'updated_by' => $requester,
            'updated_on' => $now,
        ];

        foreach ($this->_emr_map() as $map_branch_id => $emr_connection) {
            if (in_array((int) $map_branch_id, $branch_in, true)) {
                continue;
            }

            if (!$this->resolve_emr_models($map_branch_id)) {
                continue;
            }
            $model = $this->_current_model();
            $emr_user = $this->_find_by_username($current_username);
            if (!$emr_user) {
                continue;
            }

            $delete_values = $soft_delete;
            if (!empty($emr_user->username)) {
                $delete_values['username'] = $emr_user->username . '_' . $suffix;
            }

            if (!$model->update(['id' => $emr_user->id], $delete_values)) {
                $this->error_message = $model->error();
                return false;
            }
        }

        return true;
    }

    /**
     * First matching row used as a template when inserting into a new branch.
     */
    protected function _load_source_row($username)
    {
        foreach (array_keys($this->_emr_map()) as $branch_id) {
            if (!$this->resolve_emr_models($branch_id)) {
                continue;
            }
            $row = $this->_find_by_username($username);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Soft-delete EMR user on the master DB (branch 0) and every branch DB.
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $user_id = $this->payload['user_id'] ?? $id;
        if (!$user_id) {
            return $this->_create_response(FAIL, ['user_id is required.']);
        }

        $username = $this->_find_username($user_id);
        if (!$username) {
            return $this->_create_response(FAIL_NOT_FOUND, ['EMR user is not found.']);
        }

        $all_write_ids = array_merge([0], array_map('intval', array_keys($this->_emr_map())));
        if (!$this->_emr_trans_begin($all_write_ids)) {
            return $this->_create_response(FAIL, ['Failed to connect to branch EMR databases.']);
        }

        if (!$this->_attempt_delete($username)) {
            $this->_emr_trans_rollback();
            $response_status = FAIL;
            $response = [
                'EMR user is not deleted.'
            ];
        } else {
            $this->_emr_trans_commit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'EMR user is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Soft-delete by username on master (0) and all branch DBs, same resolve-then-write shape throughout.
     */
    protected function _attempt_delete($username)
    {
        $suffix = date('YmdHis');
        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? 0;
        $deleted = false;

        foreach (array_merge([0], array_keys($this->_emr_map())) as $branch_id) {
            if (!$this->resolve_emr_models($branch_id)) {
                continue;
            }

            $model = $this->_current_model();
            $emr_user = $this->_find_by_username($username);
            if (!$emr_user) {
                continue;
            }

            $values = [
                'is_deleted' => 1,
                'updated_by' => $requester,
                'updated_on' => $now,
            ];
            if (!empty($emr_user->username)) {
                $values['username'] = $emr_user->username . '_' . $suffix;
            }

            if (!$model->update(['id' => $emr_user->id], $values)) {
                $this->error_message = $model->error();
                return false;
            }
            $deleted = true;
        }

        return $deleted;
    }
}