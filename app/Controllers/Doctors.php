<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Consultation;
use App\Models\Consultation_doctor;
use App\Models\Doctor;
use App\Models\Doctor_hmo;
use App\Models\Role_emr;
use App\Models\Specialization_emr;
use App\Models\User;
use App\Models\User_emr;

class Doctors extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $doctor;
    protected $consultation;
    protected $consultation_doctor;
    protected $doctor_hmo;
    protected $user;
    protected $user_emr;
    protected $role_emr;
    protected $specialization_emr;
    protected $helpers = ['text'];
    protected $error_message;

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

        $this->doctor = new Doctor();
        $this->consultation = new Consultation();
        $this->consultation_doctor = new Consultation_doctor();
        $this->doctor_hmo = new Doctor_hmo();
        $this->user = new User();
        $this->user_emr = new User_emr();
        $this->role_emr = new Role_emr();
        $this->specialization_emr = new Specialization_emr();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Resolve diagnostic models for a branch database.
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
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);
        $this->doctor = new Doctor($db);
        $this->consultation = new Consultation($db);
        $this->consultation_doctor = new Consultation_doctor($db);
        $this->doctor_hmo = new Doctor_hmo($db);

        return true;
    }

    /**
     * Branch ids for syncing doctor child rows (master + branch_in).
     */
    protected function _sync_branch_ids(): array
    {
        $branch_in = $this->payload['branch_in'] ?? null;
        if ($branch_in === null || $branch_in === '') {
            return [0, 1, 2, 3, 4, 7];
        }

        $parts = array_filter(array_map('trim', explode(',', (string) $branch_in)), static function ($part) {
            return $part !== '';
        });

        return array_values(array_unique(array_merge([0], $parts)));
    }

    /**
     * Locate a doctor row on the current diagnostic connection.
     * Master is keyed by id. Branch DBs are keyed by master_doctor_id.
     */
    protected function _doctor_where($master_id, int $branch_id): array
    {
        return $branch_id === 0
            ? ['id' => $master_id]
            : ['master_doctor_id' => $master_id];
    }

    /**
     * Local doctor.id on the current diagnostic connection.
     * Call after resolve_branch_models(). False if the branch has no row.
     */
    protected function _local_doctor_id($master_id, int $branch_id)
    {
        if ($branch_id === 0) {
            return (int) $master_id;
        }

        $row = $this->doctor->select('id', [
            'master_doctor_id' => $master_id,
            'is_deleted' => 0,
        ], 1);

        if (!$row) {
            return false;
        }

        return (int) (is_object($row) ? $row->id : $row['id']);
    }

    /**
     * EMR connection map keyed by diagnostic branch id.
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
     * Parse comma-separated branch_in into unique valid branch IDs.
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
    protected function _normalize_emr_branch_ids(array $branch_ids): array
    {
        $normalized = [];
        foreach ($branch_ids as $id) {
            $id = (int) $id;
            if (in_array($id, [5, 6], true)) {
                $id = 2;
            }
            $normalized[] = $id;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Resolve EMR user model from a single diagnostic branch id.
     */
    protected function resolve_emr_models($branch_id)
    {
        if (in_array($branch_id, [5, 6])) {
            $branch_id = 2; //default to oakridge for warehouse and pharmacy
        }

        $emrMap = $this->_emr_map();
        $branch_id = (int) $branch_id;

        if (!isset($emrMap[$branch_id])) {
            return false;
        }

        $db = db_connect($emrMap[$branch_id]);
        $this->user_emr = new User_emr($db);

        return true;
    }

    /**
     * Check row count consistency between master and branch databases.
     * @return true|string true on success, branch name on mismatch
     */
    protected function _check_branch_consistency()
    {
        $this->resolve_branch_models(0);
        $master_count = count(
            $this->doctor->select('id', ['is_deleted' => 0]) ?? []
        );

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];

        foreach ($branches as $branch_id => $branch_name) {
            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->doctor->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                return $branch_name;
            }
        }

        return true;
    }

    /**
     * Validate type (role) and specializations against Oakridge EMR.
     * @return true|string error message
     */
    protected function _validate_type_and_specialization()
    {
        if (array_key_exists('type', $this->payload) && $this->payload['type'] !== '' && $this->payload['type'] !== null) {
            if (!$this->role_emr->exists_by_id($this->payload['type'])) {
                return 'Invalid type. Role not found in EMR.';
            }
        }

        if (array_key_exists('specializations', $this->payload) && $this->payload['specializations'] !== '' && $this->payload['specializations'] !== null) {
            if (!$this->specialization_emr->exists_by_id($this->payload['specializations'])) {
                return 'Invalid specializations. Specialization not found in EMR.';
            }
        }

        return true;
    }

    /**
     * Get doctors
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->resolve_branch_models(0);

        $page = (int) ($this->payload['page'] ?? 1);
        $data_size = (int) ($this->payload['data_size'] ?? 10);
        $doctor_id = $this->payload['doctor_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $status = $this->payload['status'] ?? null;
        $specialization_id = $this->payload['specialization_id'] ?? null;
        $role_id = $this->payload['role_id'] ?? $this->payload['type_id'] ?? $this->payload['type'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;

        $rows = $this->doctor->get_details($doctor_id, $status, $specialization_id, $term, $role_id, null, null) ?: [];

        if ($branch_id !== null && $branch_id !== '') {
            $filter_branch = (int) $branch_id;
            $rows = array_values(array_filter($rows, static function ($row) use ($filter_branch) {
                $row = (array) $row;
                $branch_in = array_map('intval', array_filter(array_map('trim', explode(',', (string) ($row['branch_in'] ?? '')))));

                return in_array($filter_branch, $branch_in, true);
            }));
        }

        $size = count($rows);
        $offset = max(0, ($page - 1) * $data_size);
        $doctors = array_slice($rows, $offset, $data_size > 0 ? $data_size : null);

        if (!$doctors) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No doctors found'
            ];
        } else {
            foreach ($doctors as $key => $doctor) {
                $consultations = $this->consultation_doctor->get_by_doctor($doctor['id']) ?: [];
                $hmos = $this->doctor_hmo->get_by_doctor($doctor['id']) ?: [];
                $doctors[$key]['consultations'] = $consultations;
                $doctors[$key]['hmos'] = $hmos;
                $doctors[$key]['company_ids'] = array_column($hmos, 'company_id');
                $doctors[$key]['signature_url'] = !empty($doctor['signature'])
                    ? $doctor['signature']
                    : null;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $doctors
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * List specializations from Oakridge EMR.
     */
    public function get_specilization()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $specialization_id = $this->payload['specialization_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $rows = $this->specialization_emr->get_details($specialization_id, $term, $page, $data_size) ?: null;
        $size = count($this->specialization_emr->get_details($specialization_id, $term, null, null) ?: []) ?: 0;

        if (!$rows) {
            return $this->_create_response(FAIL_NOT_FOUND, [
                'No specializations found.'
            ]);
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $rows
        ]);
    }

    /**
     * List roles (doctor types) from Oakridge EMR.
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
                'No types found.'
            ]);
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $rows
        ]);
    }

    /**
     * List consultation fee names from master for the learning field.
     */
    public function get_consultations()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->resolve_branch_models(0);

        $consultation_id = $this->payload['consultation_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $rows = $this->consultation->get_details($consultation_id, $term, $page, $data_size) ?: [];
        $size = count($this->consultation->get_details($consultation_id, $term, null, null) ?: []) ?: 0;

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $rows
        ]);
    }

    /**
     * Check duplicate
     */
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
        $is_valid = true;
        switch ($name) {
            case 'create' || 'update':
                $key = 'doctor_id';
                $id = array_key_exists($key, $this->payload) ? $this->payload[$key] : null;
                if ($this->_has_duplicate('doctor', 'username', $this->payload['username'], $id)) {
                    $this->validation_errors[] = 'Doctor is duplicate.';
                    $is_valid = false;
                }
        }
        return $is_valid;
    }

    /**
     * Delete doctors
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $doctor_id = $this->payload['doctor_id'];
        $where = ['id' => $doctor_id, 'is_deleted' => 0];

        $this->db->transBegin();

        $this->resolve_branch_models(0);

        if (!$doctor = $this->doctor->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'doctor not found.'
            ];
        } elseif (!$this->_attempt_delete($doctor)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'doctor not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'status' => 200,
                'message' => 'doctor deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete across diagnostic DBs, master user, EMR user, consultations.
     */
    protected function _attempt_delete($doctor)
    {
        $doctor_id = $doctor->id;
        $suffix = date('YmdHis');
        $audit = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        $doctor_values = array_merge($audit, [
            'username' => $doctor->username . '_' . $suffix,
        ]);

        $consultation_values = $audit;

        foreach ([0, 1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            $local_doctor_id = $this->_local_doctor_id($doctor_id, (int) $branch_id);
            if (!$local_doctor_id) {
                continue;
            }

            if (!$this->doctor->update($this->_doctor_where($doctor_id, (int) $branch_id), $doctor_values)) {
                $this->error_message = $this->doctor->error();
                return false;
            }

            $consultation_rows = $this->consultation_doctor->select('id', [
                'doctor_id' => $local_doctor_id,
                'is_deleted' => 0
            ]) ?: [];

            foreach ($consultation_rows as $row) {
                $row_id = is_object($row) ? $row->id : $row['id'];
                if (!$this->consultation_doctor->update(['id' => $row_id], $consultation_values)) {
                    $this->error_message = $this->consultation_doctor->error();
                    return false;
                }
            }

            $hmo_rows = $this->doctor_hmo->select('id', [
                'doctor_id' => $local_doctor_id,
                'is_deleted' => 0,
            ]) ?: [];

            foreach ($hmo_rows as $row) {
                $row_id = is_object($row) ? $row->id : $row['id'];
                if (!$this->doctor_hmo->update(['id' => $row_id], $audit)) {
                    $this->error_message = $this->doctor_hmo->error();
                    return false;
                }
            }
        }

        $master_user = $this->user->select('', [
            'email' => $doctor->username,
            'is_deleted' => 0
        ], 1);

        if ($master_user) {
            $master_values = array_merge($audit, [
                'email' => $doctor->username . '_' . $suffix,
            ]);

            if (!$this->user->update(['id' => $master_user->id], $master_values)) {
                $this->error_message = $this->user->error();
                return false;
            }
        }

        $emr_connections = [
            'mobile_emr',
            'oakridge_emr',
            'mactan_emr',
            'consolacion_emr',
            'mantlewood_emr',
        ];

        foreach ($emr_connections as $emr_connection) {
            $this->user_emr = new User_emr(db_connect($emr_connection));

            $emr_user = $this->user_emr->select('', [
                'master_doctor_id' => $doctor_id,
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

        return true;
    }

    /**
     * Update doctor
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        if (!$this->_validate_consultation_shares()) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        $license_no = $this->payload['license_no'] ?? null;

        $doctor_id = $this->payload['doctor_id'];
        $where = [
            'id' => $doctor_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        // $consistency = $this->_check_branch_consistency();
        // if ($consistency !== true) {
        //     $this->db->transRollback();
        //     return $this->_create_response(FAIL, [
        //         "Data inconsistency detected between master and {$consistency}."
        //     ]);
        // }

        $type_check = $this->_validate_type_and_specialization();
        if ($type_check !== true) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$type_check]);
        }

        $this->resolve_branch_models(0);

        if (!$doctor = $this->doctor->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'doctor not found.'
            ];
        } elseif ($this->doctor->validate_username(strtolower(preg_replace('/\s+/', '', $this->payload['username'])), $doctor_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Username already exists!'
            ];
        } elseif ($license_no ? $this->doctor->validate_license_no($license_no, $doctor_id) : false) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'License number already exists!'
            ];
        } elseif ($this->doctor->validate_full_name(
            (isset($this->payload['first_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['first_name'])) : null),
            (isset($this->payload['middle_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['middle_name'])) : null),
            (isset($this->payload['last_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['last_name'])) : null),
            $doctor_id
        )) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Doctor ' . (isset($this->payload['first_name']) ? $this->payload['first_name'] : '') . ' ' . (isset($this->payload['middle_name']) ? $this->payload['middle_name'] : '') . ' ' . (isset($this->payload['last_name']) ? $this->payload['last_name'] : '') . ' already exists!'
            ];
        } elseif (!$this->_attempt_update($doctor)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update doctor.'
            ];
        } elseif (!$this->_attempt_update_master_user($doctor)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update master user.'
            ];
        } elseif (!$this->_attempt_update_emr_user($doctor)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update EMR user.'
            ];
        } elseif ($this->_has_duplicate_consultation_fees()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Consultation fee with the same name and amount already exists.'
            ];
        } elseif (!$this->_attempt_update_consultations($doctor_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update consultations.'
            ];
        } elseif (!$this->_attempt_sync_hmos($doctor_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update doctor HMO accreditations.',
                $this->error_message
            ];
        } elseif (!$this->_upload_signature($doctor_id, 'assets/doctors/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload signature. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'doctor_id' => $doctor_id,
                'message' => 'doctor updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update doctor on all diagnostic DBs.
     */
    protected function _attempt_update($doctor)
    {
        $values = [
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('password', $this->payload) && $this->payload['password'] !== '') {
            $this->payload['password'] = app_password_hash($this->payload['password']);
        }

        $not_allowed = ['token', 'token_expiry'];
        foreach ($this->doctor->allowedFields as $field) {
            if (in_array($field, $not_allowed) || $field === 'signature' || $field === 'master_doctor_id') {
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

        foreach ($this->_sync_branch_ids() as $branch_id) {
            $branch_id = (int) $branch_id;
            $this->resolve_branch_models($branch_id);
            $where = $this->_doctor_where($doctor->id, $branch_id);

            if ($this->doctor->select('id', $where, 1)) {
                if (!$this->doctor->update($where, $values)) {
                    $this->error_message = $this->doctor->error();
                    return false;
                }
                continue;
            }

            if ($branch_id === 0) {
                $this->error_message = $this->doctor->error();
                return false;
            }

            $insert = $values;
            $insert['master_doctor_id'] = $doctor->id;
            $insert['added_by'] = $this->payload['requester'] ?? ($doctor->added_by ?? 0);
            $insert['added_on'] = date('Y-m-d H:i:s');
            foreach ($this->doctor->allowedFields as $field) {
                if ($field === 'master_doctor_id' || array_key_exists($field, $insert)) {
                    continue;
                }
                if (isset($doctor->$field)) {
                    $insert[$field] = $doctor->$field;
                }
            }

            if (!$this->doctor->insert($insert)) {
                $this->error_message = $this->doctor->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update master user linked by doctor username/email.
     */
    protected function _attempt_update_master_user($doctor)
    {
        $master_user = $this->user->select('', [
            'email' => $doctor->username,
            'is_deleted' => 0
        ], 1);

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
        if (array_key_exists('username', $this->payload) && $this->payload['username'] !== '') {
            $values['email'] = $this->payload['username'];
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
     * Update EMR users linked by master_doctor_id for each branch_in ID.
     */
    protected function _attempt_update_emr_user($doctor)
    {
        $branch_in = $this->payload['branch_in'] ?? $doctor->branch_in ?? null;
        $branch_ids = $this->_parse_branch_ids($branch_in);
        if ($branch_ids === false) {
            return true;
        }
        $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);

        $first = $this->payload['first_name'] ?? $doctor->first_name ?? '';
        $middle = $this->payload['middle_name'] ?? $doctor->middle_name ?? '';
        $last = $this->payload['last_name'] ?? $doctor->last_name ?? '';
        $full_name = trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last));

        $type = $this->payload['type'] ?? $doctor->type ?? null;
        $role_name = ($type !== null && $type !== '') ? $this->role_emr->get_name_by_id($type) : null;

        $update_values = [
            'full_name' => $full_name !== '' ? $full_name : null,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('username', $this->payload) && $this->payload['username'] !== '') {
            $update_values['username'] = $this->payload['username'];
        }
        if (array_key_exists('password', $this->payload) && $this->payload['password'] !== '' && $this->payload['password'] !== null) {
            $update_values['password'] = $this->payload['password'];
        }
        if ($type !== null && $type !== '') {
            $update_values['role_id'] = $type;
            $update_values['role_name'] = $role_name;
        }

        foreach (['doctor_fee', 'ptr_no', 'license_no', 's2_no', 'specializations'] as $field) {
            if (array_key_exists($field, $this->payload)) {
                $update_values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
            }
        }

        $insert_values = [
            'master_doctor_id' => $doctor->id,
            'full_name' => $full_name !== '' ? $full_name : null,
            'username' => $this->payload['username'] ?? $doctor->username ?? null,
            'password' => $this->payload['password'] ?? $doctor->password ?? null,
            'role_id' => $type,
            'role_name' => $role_name,
            'signature' => $doctor->signature ?? null,
            'doctor_fee' => $this->payload['doctor_fee'] ?? $doctor->doctor_fee ?? 0,
            'ptr_no' => $this->payload['ptr_no'] ?? $doctor->ptr_no ?? null,
            'license_no' => $this->payload['license_no'] ?? $doctor->license_no ?? null,
            's2_no' => $this->payload['s2_no'] ?? $doctor->s2_no ?? null,
            'specializations' => $this->payload['specializations'] ?? $doctor->specializations ?? null,
            'status' => 'active',
            'activated_by' => $this->payload['requester'] ?? 0,
            'activated_on' => date('Y-m-d H:i:s'),
            'added_by' => $this->payload['requester'] ?? 0,
            'added_on' => date('Y-m-d H:i:s'),
        ];

        foreach (['doctor_fee', 'ptr_no', 'license_no', 's2_no', 'specializations'] as $field) {
            if (array_key_exists($field, $this->payload)) {
                $insert_values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
            }
        }

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_emr_models($branch_id)) {
                $this->error_message = 'Invalid branch_in for EMR user.';
                return false;
            }

            $emr_user = $this->user_emr->select('', [
                'master_doctor_id' => $doctor->id,
                'is_deleted' => 0
            ], 1);

            if ($emr_user) {
                if (!$this->user_emr->update(['id' => $emr_user->id], $update_values)) {
                    $this->error_message = $this->user_emr->error();
                    return false;
                }
            } else {
                if (!$this->user_emr->insert($insert_values)) {
                    $this->error_message = $this->user_emr->error();
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

        foreach ($this->_emr_map() as $map_branch_id => $emr_connection) {
            if (in_array((int) $map_branch_id, $branch_ids, true)) {
                continue;
            }

            $this->user_emr = new User_emr(db_connect($emr_connection));
            $emr_user = $this->user_emr->select('', [
                'master_doctor_id' => $doctor->id,
                'is_deleted' => 0
            ], 1);

            if (!$emr_user) {
                continue;
            }

            $delete_values = $soft_delete;
            if (!empty($emr_user->username)) {
                $delete_values['username'] = $emr_user->username . '_' . $suffix;
            }

            if (!$this->user_emr->update(['id' => $emr_user->id], $delete_values)) {
                $this->error_message = $this->user_emr->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Create doctor
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        if (!$this->_validate_consultation_shares()) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        $this->db->transBegin();

        $license_no = $this->payload['license_no'] ?? null;

        // $consistency = $this->_check_branch_consistency();
        // if ($consistency !== true) {
        //     $this->db->transRollback();
        //     return $this->_create_response(FAIL, [
        //         "Data inconsistency detected between master and {$consistency}."
        //     ]);
        // }

        $type_check = $this->_validate_type_and_specialization();
        if ($type_check !== true) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$type_check]);
        }

        $this->resolve_branch_models(0);

        if ($this->doctor->select('', ['username' => $this->payload['username'], 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Username already exists!'
            ];
        } elseif ($this->doctor->validate_username(strtolower(preg_replace('/\s+/', '', $this->payload['username'])))) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Username already exists!'
            ];
        } elseif ($license_no ? $this->doctor->validate_license_no($license_no) : false) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'License number already exists!'
            ];
        } elseif ($this->doctor->validate_full_name(
            (isset($this->payload['first_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['first_name'])) : null),
            (isset($this->payload['middle_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['middle_name'])) : null),
            (isset($this->payload['last_name']) ? strtolower(preg_replace('/\s+/', '', $this->payload['last_name'])) : null)
        )) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Doctor ' . (isset($this->payload['first_name']) ? $this->payload['first_name'] : '') . ' ' . (isset($this->payload['middle_name']) ? $this->payload['middle_name'] : '') . ' ' . (isset($this->payload['last_name']) ? $this->payload['last_name'] : '') . ' already exists!'
            ];
        } elseif (!$doctor_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create doctor.'
            ];
        } elseif (!$this->_attempt_create_master_user($doctor_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create master user.'
            ];
            if (!empty($this->error_message) && is_string($this->error_message)) {
                $response[] = $this->error_message;
            }
        } elseif (!$this->_attempt_create_emr_user($doctor_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create EMR user.'
            ];
            if (!empty($this->error_message) && is_string($this->error_message)) {
                $response[] = $this->error_message;
            }
        } elseif ($this->_has_duplicate_consultation_fees()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Consultation fee with the same name and amount already exists.'
            ];
        } elseif (!$this->_attempt_create_consultations($doctor_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create consultations.'
            ];
        } elseif (!$this->_attempt_sync_hmos($doctor_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create doctor HMO accreditations.'
            ];
        } elseif (!$this->_upload_signature($doctor_id, 'assets/doctors/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload signature. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'doctor_id' => $doctor_id,
                'message' => 'doctor created successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a doctor on all diagnostic DBs.
     */
    private function _attempt_create()
    {
        $values = [
            'added_by' => $this->payload['requester'],
            'added_on' => date('Y-m-d H:i:s'),
            'status' => 'active',
            'activated_by' => $this->payload['requester'] ?? 0,
            'activated_on' => date('Y-m-d H:i:s'),
        ];

        $this->payload['password'] = app_password_hash($this->payload['password']);

        $not_allowed = ['token', 'token_expiry', 'status', 'activated_by', 'activated_on', 'inactivated_by', 'inactivated_on'];
        foreach ($this->doctor->allowedFields as $field) {
            if (in_array($field, $not_allowed) || $field === 'signature' || $field === 'master_doctor_id') {
                continue;
            }
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $this->resolve_branch_models(0);
        if (!$doctor_id = $this->doctor->insert($values)) {
            $this->error_message = $this->doctor->error();
            return false;
        }

        if (!$this->doctor->update(['id' => $doctor_id], ['master_doctor_id' => $doctor_id])) {
            $this->error_message = $this->doctor->error();
            return false;
        }

        $branch_values = $values;
        $branch_values['master_doctor_id'] = $doctor_id;

        foreach ($this->_sync_branch_ids() as $branch_id) {
            $branch_id = (int) $branch_id;
            if ($branch_id === 0) {
                continue;
            }

            $this->resolve_branch_models($branch_id);

            if (!$this->doctor->insert($branch_values)) {
                $this->error_message = $this->doctor->error();
                return false;
            }
        }

        return $doctor_id;
    }

    /**
     * Create master user for doctor (role_id = 41).
     */
    private function _attempt_create_master_user($doctor_id)
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
            'role_id' => 41,
            'branch_id' => $this->payload['branch_in'] ?? null,
            'added_by' => $this->payload['requester'] ?? 0,
            'added_on' => date('Y-m-d H:i:s'),
        ];

        if (!$this->user->insert($values)) {
            $this->error_message = $this->user->error();
            return false;
        }

        return true;
    }

    /**
     * Create EMR users on each branch_in-mapped EMR database.
     */
    private function _attempt_create_emr_user($doctor_id)
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

        $type = $this->payload['type'] ?? null;
        $role_name = $type ? $this->role_emr->get_name_by_id($type) : null;

        $values = [
            'master_doctor_id' => $doctor_id,
            'full_name' => $full_name !== '' ? $full_name : null,
            'username' => $this->payload['username'] ?? null,
            'password' => $this->payload['password'] ?? null,
            'role_id' => $type,
            'role_name' => $role_name,
            'signature' => null,
            'doctor_fee' => $this->payload['doctor_fee'] ?? 0,
            'ptr_no' => $this->payload['ptr_no'] ?? null,
            'license_no' => $this->payload['license_no'] ?? null,
            's2_no' => $this->payload['s2_no'] ?? null,
            'specializations' => $this->payload['specializations'] ?? null,
            'status' => 'active',
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

            if (!$this->user_emr->insert($values)) {
                $this->error_message = $this->user_emr->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize optional consultation values without treating zero as empty.
     */
    protected function _normalize_consultation_value($value)
    {
        return $value === null || (is_string($value) && trim($value) === '') ? null : $value;
    }

    /**
     * Check all consultation shares before any doctor or mirrored writes.
     */
        protected function _validate_consultation_shares(): bool
    {
        if (!isset($this->payload['consultations']) || !is_array($this->payload['consultations'])) {
            return true;
        }

        foreach ($this->payload['consultations'] as $index => $item) {
            if (!is_array($item)) {
                $this->error_message = "consultations[{$index}] must be an array.";
                return false;
            }

            foreach (['doctor_share', 'discount_doctor_share'] as $field) {
                $value = $this->_normalize_consultation_value($item[$field] ?? null);
                if ($value === null) {
                    continue;
                }

                if (!is_numeric($value) || (float) $value < 0) {
                    $this->error_message = "consultations[{$index}]: {$field} must be a number not below 0.";
                    return false;
                }
            }
        }

        return true;
    }

    protected function _consultation_share_fields($item, $amount): array
    {
        $doctor_share = $this->_normalize_consultation_value($item['doctor_share'] ?? null);
        $discount_doctor_share = $this->_normalize_consultation_value($item['discount_doctor_share'] ?? null);
        $has_amount = $amount !== null && is_numeric($amount);

        return [
            'doctor_share' => $doctor_share,
            'cpci_share' => $has_amount && $doctor_share !== null
                ? round((float) $amount - (float) $doctor_share, 2)
                : null,
            'discount_doctor_share' => $discount_doctor_share,
            'discount_cpci_share' => $has_amount && $discount_doctor_share !== null
                ? round((float) $amount * 0.8 - (float) $discount_doctor_share, 2)
                : null,
        ];
    }

    /**
     * Create consultation_doctor rows on all diagnostic DBs.
     */
    private function _attempt_create_consultations($doctor_id)
    {
        if (!array_key_exists('consultations', $this->payload) || !is_array($this->payload['consultations'])) {
            return true;
        }

        foreach ($this->payload['consultations'] as $item) {
            $fee_name = $this->_normalize_fee_name($item['fee_name'] ?? null);
            if ($fee_name === '') {
                continue;
            }

            $amount = array_key_exists('amount', $item) && $item['amount'] !== '' ? $item['amount'] : null;
            $shares = $this->_consultation_share_fields($item, $amount);

            foreach ($this->_sync_branch_ids() as $branch_id) {
                $branch_id = (int) $branch_id;
                $this->resolve_branch_models($branch_id);

                $local_doctor_id = $this->_local_doctor_id($doctor_id, $branch_id);
                if (!$local_doctor_id) {
                    $this->error_message = 'Doctor not found on branch.';
                    return false;
                }

                $consultation_id = $this->_resolve_consultation_id($fee_name);
                if (!$consultation_id) {
                    return false;
                }

                $values = array_merge([
                    'doctor_id' => $local_doctor_id,
                    'consultation_id' => $consultation_id,
                    'fee_name' => $fee_name,
                    'amount' => $amount,
                ], $shares, [
                    'added_by' => $this->payload['requester'] ?? 0,
                    'added_on' => date('Y-m-d H:i:s'),
                ]);

                if (!$this->consultation_doctor->insert($values)) {
                    $this->error_message = $this->consultation_doctor->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Packages-style sync for consultations on update.
     */
        private function _attempt_update_consultations($doctor_id)
    {
        if (!array_key_exists('consultations', $this->payload)) {
            return true;
        }

        if (!is_array($this->payload['consultations'])) {
            return false;
        }

        $requester = $this->payload['requester'] ?? 0;

        foreach ($this->_sync_branch_ids() as $branch_id) {
            $branch_id = (int) $branch_id;
            $this->resolve_branch_models($branch_id);

            $local_doctor_id = $this->_local_doctor_id($doctor_id, $branch_id);
            if (!$local_doctor_id) {
                $this->error_message = 'Doctor not found on branch.';
                return false;
            }

            $existing = [];
            $rows = $this->consultation_doctor->select('', [
                'doctor_id' => $local_doctor_id,
                'is_deleted' => 0
            ]) ?: [];

            foreach ($rows as $row) {
                $row = (array) $row;
                $existing[(int) $row['consultation_id']][] = (int) $row['id'];
            }

            foreach ($this->payload['consultations'] as $item) {
                $fee_name = $this->_normalize_fee_name($item['fee_name'] ?? null);
                if ($fee_name === '') {
                    continue;
                }

                $amount = array_key_exists('amount', $item) && $item['amount'] !== '' ? $item['amount'] : null;
                $shares = $this->_consultation_share_fields($item, $amount);

                $consultation_id = $this->_resolve_consultation_id($fee_name);
                if (!$consultation_id) {
                    return false;
                }

                $values = [
                    'doctor_id' => $local_doctor_id,
                    'consultation_id' => $consultation_id,
                    'fee_name' => $fee_name,
                    'amount' => $amount,
                ];

                $key = (int) $consultation_id;

                if (!empty($existing[$key])) {
                    $row_id = array_shift($existing[$key]);
                    $values = array_merge($values, $shares, [
                        'updated_by' => $requester,
                        'updated_on' => date('Y-m-d H:i:s'),
                    ]);

                    if (!$this->consultation_doctor->update(['id' => $row_id], $values)) {
                        $this->error_message = $this->consultation_doctor->error();
                        return false;
                    }
                } else {
                    $values = array_merge($values, $shares, [
                        'added_by' => $requester,
                        'added_on' => date('Y-m-d H:i:s'),
                    ]);

                    if (!$this->consultation_doctor->insert($values)) {
                        $this->error_message = $this->consultation_doctor->error();
                        return false;
                    }
                }
            }

            foreach ($existing as $ids) {
                foreach ($ids as $row_id) {
                    if (!$this->consultation_doctor->update(['id' => $row_id], [
                        'is_deleted' => 1,
                        'updated_by' => $requester,
                        'updated_on' => date('Y-m-d H:i:s'),
                    ])) {
                        $this->error_message = $this->consultation_doctor->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Sync doctor_hmo rows from payload company_ids when hmo_member is enabled.
     */
    private function _attempt_sync_hmos($doctor_id)
    {
        if (
            !array_key_exists('company_ids', $this->payload)
            && !array_key_exists('hmo_member', $this->payload)
        ) {
            return true;
        }

        $company_ids = [];
        $hmo_member = $this->payload['hmo_member'] ?? null;

        if (in_array($hmo_member, ['1', 1, 'yes', 'Yes'], true)) {
            $raw = $this->payload['company_ids'] ?? [];
            if (is_string($raw)) {
                $raw = array_filter(array_map('trim', explode(',', $raw)));
            }
            $company_ids = array_values(array_unique(array_filter(array_map('intval', (array) $raw))));
        }

        foreach ($this->_sync_branch_ids() as $branch_id) {
            $branch_id = (int) $branch_id;
            $this->resolve_branch_models($branch_id);

            $local_doctor_id = $this->_local_doctor_id($doctor_id, $branch_id);
            if (!$local_doctor_id) {
                continue;
            }

            if (!$this->doctor_hmo->sync_for_doctor($local_doctor_id, $company_ids, $this->payload['requester'] ?? 0)) {
                $this->error_message = 'Branch ' . $branch_id . ' - ' . $this->doctor_hmo->sync_error();
                return false;
            }
        }

        return true;
    }

    /**
     * Collapse extra spaces so fee names match existing consultation rows.
     */
    private function _normalize_fee_name($fee_name)
    {
        return preg_replace('/\s+/', ' ', trim((string) $fee_name)) ?? '';
    }

    /**
     * True when the payload has two consultation rows with the same name and amount.
     */
    private function _has_duplicate_consultation_fees()
    {
        if (!array_key_exists('consultations', $this->payload) || !is_array($this->payload['consultations'])) {
            return false;
        }

        $seen = [];
        foreach ($this->payload['consultations'] as $item) {
            $fee_name = strtolower($this->_normalize_fee_name($item['fee_name'] ?? null));
            if ($fee_name === '') {
                continue;
            }

            if (!array_key_exists('amount', $item) || $item['amount'] === '' || $item['amount'] === null) {
                continue;
            }

            if (!is_numeric($item['amount'])) {
                continue;
            }

            $key = $fee_name . '|' . (string) (0 + $item['amount']);
            if (isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }

        return false;
    }

    /**
     * Find or create consultation by name on the current diagnostic connection.
     */
    private function _resolve_consultation_id($fee_name)
    {
        $fee_name = $this->_normalize_fee_name($fee_name);
        $existing = $this->consultation->find_by_name($fee_name);
        if ($existing) {
            return is_object($existing) ? $existing->id : $existing['id'];
        }

        $consultation_id = $this->consultation->insert([
            'name' => $fee_name,
        ]);

        if (!$consultation_id) {
            $this->error_message = $this->consultation->error();
            return false;
        }

        return $consultation_id;
    }

    /**
     * Upload signature to VM4 and sync URL to diagnostic doctor rows + EMR user.
     */
    protected function _upload_signature($doctor_id, $path)
    {
        $file = $this->request->getFile('signature') ?? null;
        if (!$file) {
            //not required
            return true;
        }

        $file_path = $path . $doctor_id . '/';

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return false;
        }

        if (!in_array($file->getExtension(), ['pdf', 'png', 'jpeg', 'jpg'])) {
            return false;
        }

        $max_file_size = 5 * 1024 * 1024;
        if ($file->getSize() > $max_file_size) {
            return false;
        }

        $upload_result = upload_to_vm4($file, $file_path);
        if (!$upload_result || empty($upload_result['success'])) {
            $this->_error = 'upload failed: ' . ($upload_result['error'] ?? 'unknown');
            return false;
        }

        $uploaded_url = VM4_ENDPOINT . $upload_result['path'];

        $values = [
            'signature'  => $uploaded_url,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        foreach ($this->_sync_branch_ids() as $branch_id) {
            $branch_id = (int) $branch_id;
            $this->resolve_branch_models($branch_id);

            if (!$this->doctor->update($this->_doctor_where($doctor_id, $branch_id), $values)) {
                $this->error_message = $this->doctor->error();
                return false;
            }
        }

        $this->resolve_branch_models(0);
        $doctor = $this->doctor->select('', ['id' => $doctor_id, 'is_deleted' => 0], 1);
        $branch_ids = $this->_parse_branch_ids(
            $this->payload['branch_in'] ?? ($doctor->branch_in ?? null)
        );

        if ($branch_ids !== false) {
            $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);
            $emr_signature = [
                'signature'  => $uploaded_url,
                'updated_by' => $this->payload['requester'] ?? 0,
                'updated_on' => date('Y-m-d H:i:s'),
            ];

            $branch_in = explode(',', $this->payload['branch_in']);
            foreach (array_merge([0], $branch_in) as $branch_id) {
                if (!$this->resolve_emr_models($branch_id)) {
                    continue;
                }

                $emr_user = $this->user_emr->select('', [
                    'master_doctor_id' => $doctor_id,
                    'is_deleted' => 0
                ], 1);

                if ($emr_user) {
                    if (!$this->user_emr->update(['id' => $emr_user->id], $emr_signature)) {
                        $this->error_message = $this->user_emr->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Change doctor status (active / inactive) across diagnostic + EMR DBs.
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $doctor_id = $this->payload['doctor_id'];
        $where = [
            'id' => $doctor_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();
        $this->resolve_branch_models(0);

        if (!$doctor = $this->doctor->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'doctor not found.'
            ];
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Invalid status. Allowed values: active, inactive.'
            ];
        } elseif ($status && ($doctor->status ?? null) == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Doctor is already {$status}."
            ];
        } elseif (!$this->_attempt_update_status($doctor)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change doctor status.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'doctor_id' => $doctor_id,
                'message' => "doctor {$status} successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt status update on all diagnostic doctor rows + branch_in EMR users.
     */
    protected function _attempt_update_status($doctor)
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

        foreach ($this->_sync_branch_ids() as $branch_id) {
            $branch_id = (int) $branch_id;
            $this->resolve_branch_models($branch_id);

            if (!$this->doctor->update($this->_doctor_where($doctor->id, $branch_id), $values)) {
                $this->error_message = $this->doctor->error();
                return false;
            }
        }

        $branch_ids = $this->_parse_branch_ids($doctor->branch_in ?? null);
        if ($branch_ids === false) {
            return true;
        }
        $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_emr_models($branch_id)) {
                continue;
            }

            $emr_user = $this->user_emr->select('', [
                'master_doctor_id' => $doctor->id,
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

    /**
     * Update doctor password (master diagnostic DB).
     */
    public function change_password()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $doctor_id = $this->payload['doctor_id'];
        $where = ['id' => $doctor_id, 'is_deleted' => 0];

        $this->db->transBegin();
        $this->resolve_branch_models(0);

        if (!$doctor = $this->doctor->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Doctor not found.'
            ];
        } elseif (!$this->_attempt_change_password($doctor)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change passowrd.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'doctor_id' => $doctor_id,
                'message' => 'Password changed successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt change password on all diagnostic DBs + linked users.
     */
    protected function _attempt_change_password($doctor)
    {
        $hashed = app_password_hash($this->payload['password']);
        $values = [
            'password' => $hashed,
            'is_changed' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        foreach ($this->_sync_branch_ids() as $branch_id) {
            $branch_id = (int) $branch_id;
            $this->resolve_branch_models($branch_id);

            if (!$this->doctor->update($this->_doctor_where($doctor->id, $branch_id), $values)) {
                return false;
            }
        }

        $master_user = $this->user->select('', [
            'email' => $doctor->username,
            'is_deleted' => 0
        ], 1);

        if ($master_user) {
            $this->user->update(['id' => $master_user->id], [
                'password' => $hashed,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s'),
            ]);
        }

        $branch_ids = $this->_parse_branch_ids($doctor->branch_in ?? null);
        if ($branch_ids !== false) {
            $branch_ids = $this->_normalize_emr_branch_ids($branch_ids);
            foreach ($branch_ids as $branch_id) {
                if (!$this->resolve_emr_models($branch_id)) {
                    continue;
                }

                $emr_user = $this->user_emr->select('', [
                    'master_doctor_id' => $doctor->id,
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

        return true;
    }


    protected function _consistency_table(): string
    {
        return 'doctor';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['username'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['username', 'full_name'];
    }

    protected function _consistency_compare_fields(): array
    {
        return [
            'first_name', 'middle_name', 'last_name', 'full_name', 'username',
            'branch_in', 'license_no', 'ptr_no', 's2_no', 'type', 'specializations',
            'other_services', 'affiliated', 'vat_type', 'vat', 'wht_percentage',
            'contact_no', 'email', 'suffix', 'birthdate', 'description', 'tin_no',
            'phic_no', 'consult_pf_fee', 'consult_pf_share', 'profit_sharing',
            'status', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [];
    }
}