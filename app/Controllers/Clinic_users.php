<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Clinic_user;
use App\Models\User_clinic;
use App\Models\Professional_staff;
use App\Models\Role;
use App\Models\Doctor;
use Config\Branch;

class Clinic_users extends MYT_Controller
{
    protected $request;
    protected $clinic_user;
    protected $user_clinic;
    protected $professional_staff;
    protected $role;
    protected $helpers = ['text'];
    protected $error_message;
    protected $clinic_dbs = [];

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

        $this->clinic_user = new Clinic_user();
        $this->user_clinic = new User_clinic();
        $this->professional_staff = new Professional_staff();
        $this->role = new Role();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Clinic diagnostic DB map (selectable branches only).
     */
    protected function _clinic_map(): array
    {
        return [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            6 => 'pharmacy',
            7 => 'mantlewood',
        ];
    }

    /**
     * Clinic DB map including master (0).
     */
    protected function _clinic_map_all(): array
    {
        return [0 => 'master'] + $this->_clinic_map();
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

        $allowed = array_keys($this->_clinic_map());
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
     * Resolve clinic_user against a diagnostic branch, or 0 for master.
     */
    protected function resolve_clinic_models($branch_id)
    {
        $map = $this->_clinic_map_all();
        $branch_id = (int) $branch_id;

        if (!isset($map[$branch_id])) {
            return false;
        }

        $db = $this->clinic_dbs[$branch_id] ?? db_connect($map[$branch_id]);
        $this->clinic_user = new Clinic_user($db);

        if ($branch_id !== 0) {
            $this->professional_staff = new Professional_staff($db);
            $this->user_clinic = new User_clinic($db);
        } else {
            $this->professional_staff = new Professional_staff(db_connect('master'));
        }

        return true;
    }

    /**
     * Master-catalog professional_staff row (signature/license for list/form when
     * branch copies are missing or not yet synced).
     */
    protected function _master_professional_staff_model(): Professional_staff
    {
        return new Professional_staff(db_connect('master'));
    }

    protected function _master_staff_by_id($professional_staff_id): array
    {
        if (empty($professional_staff_id)) {
            return ['email' => null, 'license_no' => null, 'signature' => null];
        }

        $staff = $this->_master_professional_staff_model()->select(
            '',
            ['id' => $professional_staff_id, 'is_deleted' => 0],
            1
        );

        if (!$staff) {
            return ['email' => null, 'license_no' => null, 'signature' => null];
        }

        return [
            'email' => $staff->email ?? null,
            'license_no' => $staff->lic_no ?? null,
            'signature' => $staff->signature ?? null,
        ];
    }

    /**
     * RULES: Master clinic_user.professional_staff_id must point at the real
     * professional_staff.id on master so get() can load signature/license even
     * when branch user_clinic rows do not exist yet.
     */
    protected function _link_master_professional_staff($clinic_user_id, array $staff_values): bool
    {
        if (!$clinic_user_id) {
            return false;
        }

        if (!$this->resolve_clinic_models(0)) {
            $this->error_message = 'Invalid master clinic connection.';
            return false;
        }

        $main = $this->clinic_user->select('', ['id' => $clinic_user_id, 'is_deleted' => 0], 1);
        if (!$main) {
            $this->error_message = 'Clinic user not found on master.';
            return false;
        }

        $master_ps = $this->_master_professional_staff_model();
        $ps_id = $main->professional_staff_id ?? null;

        if ($ps_id && !$master_ps->select('', ['id' => $ps_id, 'is_deleted' => 0], 1)) {
            $ps_id = null;
        }

        if (!$ps_id) {
            $contact_email = $staff_values['email'] ?? null;
            $lic_no = $staff_values['lic_no'] ?? null;
            if ($contact_email && ($existing = $master_ps->find_live_by_email($contact_email))) {
                $ps_id = $existing->id;
            }
            if (!$ps_id && $lic_no) {
                $existing = $master_ps->select('', ['lic_no' => $lic_no, 'is_deleted' => 0], 1);
                if ($existing) {
                    $ps_id = $existing->id;
                }
            }
        }

        if ($ps_id) {
            if ((int) ($main->professional_staff_id ?? 0) !== (int) $ps_id) {
                if (!$this->clinic_user->update(['id' => $clinic_user_id], ['professional_staff_id' => $ps_id])) {
                    $this->error_message = $this->_model_error($this->clinic_user);
                    log_message('error', 'Failed to link master professional staff id: ' . print_r($this->error_message, true));
                    return false;
                }
            }
            $master_staff_values = $staff_values;
            if (!empty($master_staff_values['email'])
                && $master_ps->find_live_by_email_excluding_id($master_staff_values['email'], (int) $ps_id)) {
                unset($master_staff_values['email']);
            }

            if (!empty($master_staff_values) && !$master_ps->update(['id' => $ps_id], $master_staff_values)) {
                $this->error_message = $this->_model_error($master_ps);
                log_message('error', 'Failed to update master professional staff: ' . print_r($this->error_message, true));
                return false;
            }
        } else {
            if (!empty($staff_values['email']) && ($existing = $master_ps->find_live_by_email($staff_values['email']))) {
                $ps_id = (int) $existing->id;
            }

            if (!$ps_id && !$ps_id = $master_ps->insert($staff_values)) {
                $this->error_message = $this->_model_error($master_ps);
                log_message('error', 'Failed to insert master professional staff: ' . print_r($this->error_message, true));
                return false;
            }

            if (!$this->clinic_user->update(['id' => $clinic_user_id], ['professional_staff_id' => $ps_id])) {
                $this->error_message = $this->_model_error($this->clinic_user);
                log_message('error', 'Failed to link master professional staff id: ' . print_r($this->error_message, true));
                return false;
            }
        }

        return true;
    }

    protected function _update_master_professional_staff_signature($clinic_user_id, array $values): bool
    {
        if (!$clinic_user_id || empty($values)) {
            return true;
        }

        if (!$this->resolve_clinic_models(0)) {
            $this->error_message = 'Invalid master clinic connection.';
            return false;
        }

        $main = $this->clinic_user->select('', ['id' => $clinic_user_id, 'is_deleted' => 0], 1);
        if (!$main || empty($main->professional_staff_id)) {
            return $this->_link_master_professional_staff($clinic_user_id, $values);
        }

        $master_ps = $this->_master_professional_staff_model();
        if (!$master_ps->select('', ['id' => $main->professional_staff_id, 'is_deleted' => 0], 1)) {
            return $this->_link_master_professional_staff($clinic_user_id, $values);
        }

        if (!$master_ps->update(['id' => $main->professional_staff_id], $values)) {
            $this->error_message = $this->_model_error($master_ps);
            return false;
        }

        return true;
    }

    /**
     * Begin transactions on clinic connections that will be written.
     */
    protected function _clinic_trans_begin(array $branch_ids): bool
    {
        $this->clinic_dbs = [];
        $map = $this->_clinic_map_all();

        foreach ($branch_ids as $branch_id) {
            if (!isset($map[$branch_id])) {
                return false;
            }
            $db = db_connect($map[$branch_id]);
            $db->transBegin();
            $this->clinic_dbs[$branch_id] = $db;
        }

        return true;
    }

    protected function _clinic_trans_commit()
    {
        foreach ($this->clinic_dbs as $db) {
            $db->transCommit();
        }
        $this->clinic_dbs = [];
    }

    protected function _clinic_trans_rollback()
    {
        foreach ($this->clinic_dbs as $db) {
            $db->transRollback();
        }
        $this->clinic_dbs = [];
    }

    /**
     * error() only reports a caught DB exception; when insert/update fails
     * due to model-level validation, the real reason is in errors().
     */
    protected function _model_error($model)
    {
        try {
            $errors = $model->errors();
            if (!empty($errors)) {
                return $errors;
            }
        } catch (\Throwable $e) {
            // No validation rules were run on this model; fall through to error().
        }

        return $model->error();
    }

    /**
     * clinic_user_id / user_id / id from payload.
     */
    protected function _payload_user_id()
    {
        // 'professional_staff_id' is what the Professional Staff form sends
        // back (it's actually just echoing the master clinic_user id it
        // received from get(), not a real professional_staff.id).
        foreach (['id', 'clinic_user_id', 'user_id', 'professional_staff_id'] as $key) {
            if (!empty($this->payload[$key])) {
                return $this->payload[$key];
            }
        }

        return null;
    }

    /**
     * Branch ids from payload.
     */
    protected function _payload_branch_ids()
    {
        $raw = $this->payload['branch_in'] ?? $this->payload['branch_id'] ?? null;
        return $this->_parse_branch_ids($raw);
    }

    /**
     * Branch-local doctor.id for a master doctor_id.
     *
     * RULES: clinic_user.doctor_id holds the master id, but a branch
     * user.doctor_id references that branch's own doctor table, where the same
     * doctor can sit under a different id. doctor.master_doctor_id is the link
     * between them, so the master id is translated per branch and never copied
     * across as-is. Returns null when the branch keeps no doctor table
     * (warehouse, pharmacy) or has no row for that doctor.
     */
    protected function _branch_doctor_id($master_doctor_id, int $branch_id)
    {
        if (empty($master_doctor_id)) {
            return null;
        }

        if ($branch_id === 0) {
            return (int) $master_doctor_id;
        }

        $map = $this->_clinic_map_all();
        if (!isset($map[$branch_id])) {
            return null;
        }

        $db = $this->clinic_dbs[$branch_id] ?? db_connect($map[$branch_id]);
        if (!$db->tableExists('doctor')) {
            return null;
        }

        $row = (new Doctor($db))->select('id', [
            'master_doctor_id' => $master_doctor_id,
            'is_deleted' => 0,
        ], 1);

        if (!$row) {
            return null;
        }

        return (int) (is_object($row) ? $row->id : $row['id']);
    }

    /**
     * A doctor-linked clinic user must not be given a branch the doctor is not
     * assigned to.
     *
     * RULES: the branch copy carries the branch-local doctor id resolved by
     * _branch_doctor_id(). A branch the doctor does not work at has no doctor
     * row to resolve, so that column would silently stay NULL and the break
     * would only surface later, in the branch EMR, when something tries to
     * resolve that login to a doctor. Rejecting here is the only place it can
     * be caught.
     *
     * @return true|string true when valid, otherwise the message to respond with.
     */
    protected function _validate_doctor_branches($doctor_id, array $branch_ids)
    {
        if (empty($doctor_id)) {
            return true;
        }

        $doctor = (new Doctor())->select('', ['id' => $doctor_id, 'is_deleted' => 0], 1);
        if (!$doctor) {
            return 'Invalid doctor. Doctor not found.';
        }

        $doctor_branches = array_filter(array_map(
            'intval',
            explode(',', (string) ($doctor->branch_in ?? ''))
        ));

        $missing = array_diff($branch_ids, $doctor_branches);
        if (empty($missing)) {
            return true;
        }

        $branchConfig = new Branch();
        $names = [];
        foreach ($missing as $branch_id) {
            $names[] = $branchConfig->getBranchName($branch_id) ?: $branch_id;
        }

        return 'Dr. ' . ($doctor->full_name ?? $doctor_id) . ' is not assigned to '
            . implode(', ', $names) . '. Add the branch to the doctor first, or remove it here.';
    }

    /**
     * Combined name from payload (first/middle/last, full_name, or name).
     */
    protected function _payload_name(): ?string
    {
        if (!empty($this->payload['full_name'])) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $this->payload['full_name']));
            return $name !== '' ? $name : null;
        }

        if (!empty($this->payload['name'])) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $this->payload['name']));
            return $name !== '' ? $name : null;
        }

        $first = $this->payload['first_name'] ?? '';
        $middle = $this->payload['middle_name'] ?? '';
        $last = $this->payload['last_name'] ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last));

        return $name !== '' ? $name : null;
    }

    /**
     * Username from payload. Spaces are stripped and case folded so "Jane Doe"
     * and "janedoe" cannot both be stored as different logins.
     */
    protected function _payload_username(): ?string
    {
        $username = $this->payload['username'] ?? null;
        if ($username === null || $username === '') {
            return null;
        }

        $username = strtolower(preg_replace('/\s+/', '', (string) $username));

        return $username !== '' ? $username : null;
    }

    /**
     * Contact email from payload. Distinct from clinic_user.email, which holds the login username.
     */
    protected function _payload_contact_email(): ?string
    {
        $email = $this->payload['email'] ?? null;
        if ($email === null || $email === '') {
            return null;
        }

        $email = strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }

    /**
     * Status from payload, or null when it is absent or not one this module
     * recognises. The list filter sends an empty string for "All", so an
     * unrecognised value has to mean "do not filter" rather than "match
     * nothing", otherwise the list would silently come back empty.
     */
    protected function _payload_status(): ?string
    {
        $status = strtolower(trim((string) ($this->payload['status'] ?? '')));

        return in_array($status, ['active', 'inactive'], true) ? $status : null;
    }

    /**
     * Live master row by email (current connection must be master, id 0).
     */
    protected function _find_by_email($email)
    {
        if (!$email) {
            return null;
        }

        return $this->clinic_user->select('', ['email' => $email, 'is_deleted' => 0], 1) ?: null;
    }

    /**
     * Contact email for professional_staff — never the branch login username.
     */
    protected function _branch_professional_staff_contact_email(array $staff_values = []): ?string
    {
        $contact = $this->_payload_contact_email();
        if ($contact) {
            return $contact;
        }

        if (!empty($staff_values['email']) && strpos((string) $staff_values['email'], '@') !== false) {
            return strtolower(trim((string) $staff_values['email']));
        }

        return null;
    }

    /**
     * Resolve branch user on update (login may differ from master; staff contact may be the stable key).
     */
    protected function _find_branch_user_for_update($current_login, $new_login, $contact_email = null, $lic_no = null)
    {
        foreach (array_unique(array_filter([$current_login, $new_login])) as $login) {
            $row = $this->user_clinic->find_live_by_email($login);
            if ($row) {
                return $row;
            }
        }

        if ($lic_no) {
            $row = $this->user_clinic->find_live_by_staff_license($lic_no);
            if ($row) {
                return $row;
            }
        }

        // RULES: Only match professional_staff by contact email (contains @). Login usernames must not
        // be used as staff email keys — legacy rows may have login stored there and would mis-link users.
        $staff_email_keys = array_unique(array_filter([$contact_email], static function ($e) {
            return $e && strpos((string) $e, '@') !== false;
        }));

        foreach ($staff_email_keys as $lookup_email) {
            $staff = $this->professional_staff->find_live_by_email($lookup_email);
            if (!$staff) {
                continue;
            }

            $row = $this->user_clinic->select(
                '',
                ['professional_staff_id' => $staff->id, 'is_deleted' => 0],
                1
            );
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Update flow only: insert or update branch user without duplicating login/staff rows.
     */
    protected function _upsert_branch_user_on_update(array $staff_values, array $user_clinic_values)
    {
        $login = $user_clinic_values['email'] ?? null;
        $row = $this->_find_branch_user_for_update(
            $login,
            $login,
            $this->_branch_professional_staff_contact_email($staff_values),
            $staff_values['lic_no'] ?? null
        );

        if ($row) {
            return $this->_update_branch_user($row, $staff_values, $user_clinic_values) ? $row->id : false;
        }

        $staff_values = array_filter(array_merge($staff_values, [
            'email' => $this->_branch_professional_staff_contact_email($staff_values),
        ]), static function ($v) {
            return $v !== null && $v !== '';
        });

        $existing_staff = $this->professional_staff->find_live_by_email($staff_values['email'] ?? null);
        if (!$existing_staff && !empty($staff_values['lic_no'])) {
            $existing_staff = $this->professional_staff->select(
                '',
                ['lic_no' => $staff_values['lic_no'], 'is_deleted' => 0],
                1
            ) ?: null;
        }

        $professional_staff_id = null;
        if ($existing_staff) {
            $professional_staff_id = (int) $existing_staff->id;
        } else {
            $any_staff = $this->professional_staff->find_by_email($staff_values['email'] ?? null);
            if (!$any_staff && !empty($staff_values['lic_no'])) {
                $any_staff = $this->professional_staff->select('', ['lic_no' => $staff_values['lic_no']], 1) ?: null;
            }
            if ($any_staff) {
                $professional_staff_id = (int) $any_staff->id;
                if ((int) ($any_staff->is_deleted ?? 0) === 1) {
                    $reactivate = array_merge($staff_values, ['is_deleted' => 0]);
                    if (!$this->professional_staff->update(['id' => $professional_staff_id], $reactivate)) {
                        $this->error_message = $this->_model_error($this->professional_staff);
                        return false;
                    }
                }
            } elseif (!$professional_staff_id = $this->professional_staff->insert($staff_values)) {
                $this->error_message = $this->_model_error($this->professional_staff);
                return false;
            }
        }

        $user_clinic_values['professional_staff_id'] = $professional_staff_id;

        $any_user = $login ? $this->user_clinic->find_by_email($login) : null;
        if ($any_user) {
            if ((int) ($any_user->is_deleted ?? 0) === 1) {
                $user_clinic_values['is_deleted'] = 0;
            }

            return $this->_update_branch_user($any_user, $staff_values, $user_clinic_values)
                ? (int) $any_user->id
                : false;
        }

        if (!$user_clinic_id = $this->user_clinic->insert($user_clinic_values)) {
            $this->error_message = $this->_model_error($this->user_clinic);
            return false;
        }

        return $user_clinic_id;
    }

    /**
     * Insert professional_staff + user_clinic rows on the currently
     * resolved branch connection. Returns the user_clinic id, or false.
     */
    protected function _insert_branch_user(array $staff_values, array $user_clinic_values)
    {
        if (!$professional_staff_id = $this->professional_staff->insert($staff_values)) {
            $this->error_message = $this->_model_error($this->professional_staff);
            log_message('error', 'Failed to insert professional staff on branch: ' . print_r($this->error_message, true));
            return false;
        }

        $user_clinic_values['professional_staff_id'] = $professional_staff_id;

        if (!$user_clinic_id = $this->user_clinic->insert($user_clinic_values)) {
            $this->error_message = $this->_model_error($this->user_clinic);
            log_message('error', 'Failed to insert user on branch: ' . print_r($this->error_message, true));
            return false;
        }

        return $user_clinic_id;
    }

    /**
     * Ensure user_clinic on the current branch has a live professional_staff row.
     * Updates existing staff when found; otherwise inserts and links the id.
     *
     * @return int|null|false professional_staff id, null when nothing to create, false on error
     */
    protected function _ensure_branch_professional_staff_id($row, array $staff_values = [])
    {
        $ps_id = !empty($row->professional_staff_id) ? (int) $row->professional_staff_id : null;

        if ($ps_id) {
            $staff_row = $this->professional_staff->select(
                '',
                ['id' => $ps_id, 'is_deleted' => 0],
                1
            );
            if ($staff_row) {
                $update_staff = $staff_values;
                if (!empty($update_staff['email'])) {
                    $owner = $this->professional_staff->find_live_by_email($update_staff['email']);
                    if ($owner && (int) $owner->id !== (int) $ps_id) {
                        $ps_id = (int) $owner->id;
                        if ((int) ($row->professional_staff_id ?? 0) !== $ps_id) {
                            if (!$this->user_clinic->update(['id' => $row->id], ['professional_staff_id' => $ps_id])) {
                                $this->error_message = $this->_model_error($this->user_clinic);
                                return false;
                            }
                        }
                        unset($update_staff['email']);
                    }
                }

                if (!empty($update_staff) && !$this->professional_staff->update(['id' => $ps_id], $update_staff)) {
                    $this->error_message = $this->_model_error($this->professional_staff);
                    log_message('error', 'Failed to update professional staff on branch: ' . print_r($this->error_message, true));
                    return false;
                }

                return $ps_id;
            }
        }

        $insert_values = array_filter(array_merge([
            'name' => $row->name ?? null,
            'email' => $this->_branch_professional_staff_contact_email($staff_values),
        ], $staff_values), static function ($v) {
            return $v !== null && $v !== '';
        });

        if (empty($insert_values['name']) && empty($insert_values['email'])) {
            return null;
        }

        $existing_staff = $this->professional_staff->find_live_by_email($insert_values['email'] ?? null);
        if (!$existing_staff && !empty($insert_values['lic_no'])) {
            $existing_staff = $this->professional_staff->select(
                '',
                ['lic_no' => $insert_values['lic_no'], 'is_deleted' => 0],
                1
            ) ?: null;
        }

        if ($existing_staff) {
            $new_id = (int) $existing_staff->id;
            if (!empty($staff_values)) {
                $update_staff = $staff_values;
                if (!empty($update_staff['email'])) {
                    $owner = $this->professional_staff->find_live_by_email($update_staff['email']);
                    if ($owner && (int) $owner->id !== $new_id) {
                        unset($update_staff['email']);
                    }
                }
                if (!empty($update_staff) && !$this->professional_staff->update(['id' => $new_id], $update_staff)) {
                    $this->error_message = $this->_model_error($this->professional_staff);
                    return false;
                }
            }
        } elseif (!$new_id = $this->professional_staff->insert($insert_values)) {
            $this->error_message = $this->_model_error($this->professional_staff);
            log_message('error', 'Failed to insert professional staff on branch: ' . print_r($this->error_message, true));
            return false;
        }

        if (!$this->user_clinic->update(['id' => $row->id], ['professional_staff_id' => $new_id])) {
            $this->error_message = $this->_model_error($this->user_clinic);
            log_message('error', 'Failed to link branch professional staff id: ' . print_r($this->error_message, true));
            return false;
        }

        return (int) $new_id;
    }

    /**
     * Update the user_clinic row (and its linked professional_staff row,
     * if staff fields are provided) on the currently resolved branch
     * connection.
     */
    protected function _update_branch_user($row, array $staff_values, array $user_clinic_values)
    {
        $login = $user_clinic_values['email'] ?? null;
        if ($login) {
            $canonical = $this->user_clinic->find_live_by_email($login);
            if ($canonical) {
                $row = $canonical;
            }
        }

        $resolved = $this->_ensure_branch_professional_staff_id($row, $staff_values);
        if ($resolved === false) {
            return false;
        }

        $update_user = $user_clinic_values;
        if (!empty($update_user['email'])) {
            $owner = $this->user_clinic->find_live_by_email($update_user['email']);
            if ($owner && (int) $owner->id !== (int) $row->id) {
                unset($update_user['email']);
            }
        }

        if (!$this->user_clinic->update(['id' => $row->id], $update_user)) {
            $this->error_message = $this->_model_error($this->user_clinic);
            log_message('error', 'Failed to update user on branch: ' . print_r($this->error_message, true));
            return false;
        }

        return true;
    }

    /**
     * Shape master rows: branch_in plus branch_names for the list/form.
     * Also attaches license_no, which only lives on the branch-level
     * professional_staff table, not on master.
     */
    protected function _map_master_rows(array $rows): array
    {
        $branchConfig = new Branch();
        $users = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $branch_ids = array_filter(
                array_map('trim', explode(',', (string) ($row['branch_in'] ?? ''))),
                static function ($id) {
                    return $id !== '';
                }
            );

            $branch_names = [];
            foreach ($branch_ids as $branch_id) {
                $name = $branchConfig->getBranchName((int) $branch_id);
                if ($name) {
                    $branch_names[] = $name;
                }
            }

            $row['branch_in'] = implode(',', $branch_ids);
            $row['branch_id'] = $row['branch_in'];
            $row['branch_names'] = implode(', ', $branch_names);
            $row['full_name'] = $row['name'] ?? null;
            $login = $row['email'] ?? null;
            $row['username'] = $row['username'] ?? $login;

            if (empty($row['professional_staff_id']) && !empty($row['name'])) {
                $existing = $this->_master_professional_staff_model()->select(
                    '',
                    ['name' => $row['name'], 'is_deleted' => 0],
                    1
                );
                if ($existing) {
                    $row['professional_staff_id'] = $existing->id;
                }
            }

            $staff = $this->_branch_staff($login, $branch_ids);
            if (empty($staff['signature']) && !empty($row['professional_staff_id'])) {
                $master_staff = $this->_master_staff_by_id($row['professional_staff_id']);
                foreach (['email', 'license_no', 'signature'] as $field) {
                    if (empty($staff[$field]) && !empty($master_staff[$field])) {
                        $staff[$field] = $master_staff[$field];
                    }
                }
            }
            $row['email'] = $staff['email'] ?? null;
            $row['license_no'] = $staff['license_no'] ?? null;
            $row['signature'] = $staff['signature'] ?? null;
            $row['signature_url'] = $staff['signature'] ?? null;
            unset($row['password'], $row['token']);
            $users[] = $row;
        }

        // Restore the master connection since resolving branches above
        // moved $this->clinic_user etc. onto branch connections.
        $this->resolve_clinic_models(0);

        return $users;
    }

    /**
     * license_no and signature live on professional_staff in each branch DB,
     * not on master. Look them up via the user's first selected branch.
     */
    protected function _branch_staff($email, array $branch_ids): array
    {
        if (!$email || empty($branch_ids)) {
            return ['email' => null, 'license_no' => null, 'signature' => null];
        }

        $branch_id = (int) reset($branch_ids);
        if (!$this->resolve_clinic_models($branch_id)) {
            return ['email' => null, 'license_no' => null, 'signature' => null];
        }

        $row = $this->user_clinic->find_live_by_email($email);
        if (!$row || empty($row->professional_staff_id)) {
            return ['email' => null, 'license_no' => null, 'signature' => null];
        }

        $staff = $this->professional_staff->select('', ['id' => $row->professional_staff_id, 'is_deleted' => 0], 1);

        return [
            'email' => $staff->email ?? null,
            'license_no' => $staff->lic_no ?? null,
            'signature' => $staff->signature ?? null,
        ];
    }

    /**
     * Get clinic users from master.
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        if (!$this->resolve_clinic_models(0)) {
            return $this->_create_response(FAIL, ['Failed to connect to master database.']);
        }

        $page = (int) ($this->payload['page'] ?? 1);
        $data_size = (int) ($this->payload['data_size'] ?? 10);
        $user_id = $this->_payload_user_id();
        $role_id = $this->payload['role_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $status = $this->_payload_status();
        $branch_id = $this->payload['branch_id'] ?? null;

        $rows = $this->clinic_user->get_details($user_id, $role_id ?: null, $term, $status, null, null) ?: [];

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
        $rows = array_slice($rows, $offset, $data_size > 0 ? $data_size : null);
        $users = $this->_map_master_rows($rows);

        if (!$users) {
            return $this->_create_response(FAIL_NOT_FOUND, [
                'No clinic users found.'
            ]);
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $users
        ]);
    }

    /**
     * Create clinic user on master and each selected branch DB.
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $username = $this->_payload_username();
        // clinic_user.email is the login column - persist username there, not the email address.
        $email = $username;
        $name = $this->_payload_name();
        $branch_ids = $this->_payload_branch_ids();
        $role_id = $this->payload['role_id'] ?? null;
        $password = $this->payload['password'] ?? null;

        if (!$this->resolve_clinic_models(0)) {
            return $this->_create_response(FAIL, ['Failed to connect to master database.']);
        }

        if (!$username) {
            return $this->_create_response(FAIL, ['Username is required.']);
        }
        if (!$name) {
            return $this->_create_response(FAIL, ['Name is required.']);
        }
        if ($branch_ids === false) {
            return $this->_create_response(FAIL, ['Invalid or missing branches.']);
        }
        if ($role_id === null || $role_id === '') {
            return $this->_create_response(FAIL, ['Role is required.']);
        }
        if (!$this->role->select('', ['id' => $role_id, 'is_deleted' => 0], 1)) {
            return $this->_create_response(FAIL, ['Invalid role.']);
        }
        $doctor_check = $this->_validate_doctor_branches($this->payload['doctor_id'] ?? null, $branch_ids);
        if ($doctor_check !== true) {
            return $this->_create_response(FAIL, [$doctor_check]);
        }
        if (!$password) {
            return $this->_create_response(FAIL, ['Password is required.']);
        }
        if ($this->clinic_user->validate_email($email)) {
            return $this->_create_response(FAIL, ['Username already exists.']);
        }

        $all_write_ids = array_merge([0], $branch_ids);
        if (!$this->_clinic_trans_begin($all_write_ids)) {
            return $this->_create_response(FAIL, ['Failed to connect to branch clinic databases.']);
        }

        if (!$clinic_user_id = $this->_attempt_create($email, $username, $name, $branch_ids, $role_id, $password)) {
            $this->_clinic_trans_rollback();
            $response = ['Failed to create clinic user.'];
            if (!empty($this->error_message)) {
                if (is_string($this->error_message)) {
                    $response[] = $this->error_message;
                } elseif (is_array($this->error_message)) {
                    $response = array_merge($response, array_values($this->error_message));
                }
            }
            return $this->_create_response(FAIL, $response);
        }

        if (!$this->_upload_signature($clinic_user_id, $email, $branch_ids, 'assets/professional_staff/')) {
            $this->_clinic_trans_rollback();
            return $this->_create_response(FAIL, [
                'Failed to upload signature. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ]);
        }

        if (!$this->request->getFile('signature')) {
            if (!$this->_apply_doctor_signature_to_staff(
                $email,
                $branch_ids,
                $this->payload['doctor_id'] ?? null,
                true
            )) {
                $this->_clinic_trans_rollback();
                return $this->_create_response(FAIL, ['Failed to copy doctor signature.']);
            }
        }

        $this->_clinic_trans_commit();
        return $this->_create_response(SUCCESS, [
            'clinic_user_id' => $clinic_user_id,
            'message' => 'Clinic user created successfully.'
        ]);
    }

    /**
     * Insert master row, then the same user on each selected clinic DB
     * (professional_staff + user_clinic - see _insert_branch_user()).
     */
    private function _attempt_create($email, $username, $name, array $branch_ids, $role_id, $password)
    {
        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? 0;
        $hashed = app_password_hash($password);
        $branch_in = implode(',', $branch_ids);

        $staff_values = [
            'name' => $name,
            'email' => $this->_payload_contact_email(),
            'lic_no' => $this->payload['lic_no'] ?? $this->payload['license_no'] ?? null,
            'description' => $this->payload['description'] ?? null,
        ];

        $values = [
            'doctor_id' => !empty($this->payload['doctor_id']) ? $this->payload['doctor_id'] : null,
            'professional_staff_id' => !empty($this->payload['professional_staff_id']) ? $this->payload['professional_staff_id'] : null,
            'name' => $name,
            'email' => $email,
            'password' => $hashed,
            'role_id' => $role_id,
            'lane' => $this->payload['lane'] ?? null,
            'branch_in' => $branch_in,
            'status' => 'active',
            'is_first_login' => 1,
            'added_by' => $requester,
            'added_on' => $now,
        ];

        if (!$this->resolve_clinic_models(0)) {
            $this->error_message = 'Invalid master clinic connection.';
            log_message('error', 'Failed to connect to master clinic database.');
            return false;
        }

        if (!$clinic_user_id = $this->clinic_user->insert($values)) {
            $this->error_message = $this->_model_error($this->clinic_user);
            log_message('error', 'Failed to insert clinic user on master: ' . print_r($this->error_message, true));
            return false;
        }

        $branch_values = [
            'name' => $name,
            'email' => $email,
            'password' => $hashed,
            'role_id' => $role_id,
            'lane' => $this->payload['lane'] ?? null,
            'status' => 'active',
            'is_first_login' => 1,
            'added_by' => $requester,
            'added_on' => $now,
        ];

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_clinic_models($branch_id)) {
                $this->error_message = 'Invalid branch for clinic user.';
                log_message('error', 'Failed to connect to branch clinic database.');
                return false;
            }

            $branch_values['doctor_id'] = $this->_branch_doctor_id(
                $values['doctor_id'],
                (int) $branch_id
            );

            if (!$this->_insert_branch_user($staff_values, $branch_values)) {
                return false;
            }
        }

        if (!$this->_link_master_professional_staff($clinic_user_id, $staff_values)) {
            return false;
        }

        return $clinic_user_id;
    }

    /**
     * Update clinic user; add/remove branch copies as needed.
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $user_id = $this->_payload_user_id();
        if (!$user_id) {
            return $this->_create_response(FAIL, ['clinic_user_id is required.']);
        }

        if (!$this->resolve_clinic_models(0)) {
            return $this->_create_response(FAIL, ['Failed to connect to master database.']);
        }

        $main = $this->clinic_user->select('', ['id' => $user_id, 'is_deleted' => 0], 1)
            ?: $this->clinic_user->select('', ['email' => $user_id, 'is_deleted' => 0], 1);
        if (!$main) {
            return $this->_create_response(FAIL_NOT_FOUND, ['Clinic user is not found.']);
        }

        $username = $this->_payload_username() ?: ($main->username ?? $main->email ?? null);
        $email = $username ?: ($main->email ?? null);
        $name = $this->_payload_name();
        $branch_ids = $this->_payload_branch_ids();
        $role_id = $this->payload['role_id'] ?? null;

        if ($branch_ids === false) {
            return $this->_create_response(FAIL, ['Invalid or missing branches.']);
        }
        if ($role_id !== null && $role_id !== '' && !$this->role->select('', ['id' => $role_id, 'is_deleted' => 0], 1)) {
            return $this->_create_response(FAIL, ['Invalid role.']);
        }
        // An update that only changes branches still has to satisfy the doctor
        // already on the row, so fall back to it when the payload omits one.
        $doctor_id = array_key_exists('doctor_id', $this->payload)
            ? $this->payload['doctor_id']
            : ($main->doctor_id ?? null);
        $doctor_check = $this->_validate_doctor_branches($doctor_id, $branch_ids);
        if ($doctor_check !== true) {
            return $this->_create_response(FAIL, [$doctor_check]);
        }
        if ($email && $this->clinic_user->validate_email($email, $main->id)) {
            return $this->_create_response(FAIL, ['Username already exists.']);
        }

        $all_write_ids = array_merge([0], array_map('intval', array_keys($this->_clinic_map())));
        if (!$this->_clinic_trans_begin($all_write_ids)) {
            return $this->_create_response(FAIL, ['Failed to connect to branch clinic databases.']);
        }

        if (!$this->_attempt_update($main, $email, $username, $name, $branch_ids, $role_id)) {
            $this->_clinic_trans_rollback();
            $response = ['Failed to update clinic user.'];
            if (!empty($this->error_message)) {
                if (is_string($this->error_message)) {
                    $response[] = $this->error_message;
                } elseif (is_array($this->error_message)) {
                    $response = array_merge($response, array_values($this->error_message));
                }
            }
            return $this->_create_response(FAIL, $response);
        }

        if (!$this->_upload_signature($main->id, $email, $branch_ids, 'assets/professional_staff/')) {
            $this->_clinic_trans_rollback();
            return $this->_create_response(FAIL, [
                'Failed to upload signature. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ]);
        }

        if (!$this->request->getFile('signature')) {
            $payload_doctor_id = array_key_exists('doctor_id', $this->payload)
                ? ($this->payload['doctor_id'] !== '' ? $this->payload['doctor_id'] : null)
                : ($main->doctor_id ?? null);
            $doctor_changed = array_key_exists('doctor_id', $this->payload)
                && (int) ($this->payload['doctor_id'] ?: 0) !== (int) ($main->doctor_id ?? 0);

            if (!$this->_apply_doctor_signature_to_staff(
                $email,
                $branch_ids,
                $payload_doctor_id,
                !$doctor_changed
            )) {
                $this->_clinic_trans_rollback();
                return $this->_create_response(FAIL, ['Failed to copy doctor signature.']);
            }
        }

        $this->_clinic_trans_commit();
        return $this->_create_response(SUCCESS, [
            'clinic_user_id' => $main->id,
            'message' => 'Clinic user updated successfully.'
        ]);
    }

    /**
     * Update master, then insert/update selected branches and soft-delete
     * removed ones. Branch-level rows live in professional_staff +
     * user_clinic (see User_clinic::find_live_by_email(), _insert_branch_user(),
     * _update_branch_user()) - mirrors what create() does, never touches
     * clinic_user on a branch connection.
     */
    protected function _attempt_update($main, $email, $username, $name, array $branch_ids, $role_id)
    {
        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? 0;
        $current_email = $main->email;
        $branch_in = implode(',', $branch_ids);

        // Master (clinic_user) update values - branch_in only exists here.
        $master_values = [
            'email' => $email,
            'branch_in' => $branch_in,
            'updated_by' => $requester,
            'updated_on' => $now,
        ];

        // professional_staff_id from the form is clinic_user id (see _payload_user_id()); real FK is set in _link_master_professional_staff().
        foreach (['doctor_id', 'role_id', 'lane'] as $field) {
            if (array_key_exists($field, $this->payload)) {
                $master_values[$field] = $this->payload[$field] !== '' ? $this->payload[$field] : null;
            }
        }
        if ($name) {
            $master_values['name'] = $name;
        }
        if (!empty($this->payload['password'])) {
            $master_values['password'] = app_password_hash($this->payload['password']);
            $master_values['is_first_login'] = 0;
        }

        if (!$this->resolve_clinic_models(0)) {
            $this->error_message = 'Invalid master clinic connection.';
            return false;
        }

        if (!$this->clinic_user->update(['id' => $main->id], $master_values)) {
            $this->error_message = $this->_model_error($this->clinic_user);
            return false;
        }

        // Branch (professional_staff + user_clinic) update/insert values -
        // no branch_in: that column is master-only. doctor_id is set per branch
        // inside the loop, translated from the master id.
        $staff_values = array_filter([
            'name' => $name ?: $master_values['name'] ?? ($main->name ?? null),
            'email' => $this->_payload_contact_email(),
            'lic_no' => $this->payload['lic_no'] ?? $this->payload['license_no'] ?? null,
            'description' => $this->payload['description'] ?? null,
        ], static function ($v) {
            return $v !== null;
        });

        $branch_update_values = [
            'email' => $email,
            'name' => $master_values['name'] ?? ($main->name ?? null),
            'role_id' => $master_values['role_id'] ?? ($main->role_id ?? null),
            'lane' => $master_values['lane'] ?? ($main->lane ?? null),
            'updated_by' => $requester,
            'updated_on' => $now,
        ];
        if (isset($master_values['password'])) {
            $branch_update_values['password'] = $master_values['password'];
            $branch_update_values['is_first_login'] = 0;
        }

        // A branch added to an existing user gets its copy created here, so it has to
        // carry the status the master already holds - otherwise deactivating a user and
        // then giving them a new branch would hand them an active login on that branch.
        $branch_insert_values = [
            'name' => $master_values['name'] ?? ($main->name ?? null),
            'email' => $email,
            'password' => $master_values['password'] ?? ($main->password ?? null),
            'role_id' => $master_values['role_id'] ?? ($main->role_id ?? null),
            'lane' => $master_values['lane'] ?? ($main->lane ?? null),
            'status' => $main->status ?? 'active',
            'is_first_login' => isset($master_values['password']) ? 0 : 1,
            'added_by' => $requester,
            'added_on' => $now,
        ];

        $master_doctor_id = array_key_exists('doctor_id', $master_values)
            ? $master_values['doctor_id']
            : ($main->doctor_id ?? null);

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_clinic_models($branch_id)) {
                $this->error_message = 'Invalid branch for clinic user.';
                return false;
            }

            $branch_doctor_id = $this->_branch_doctor_id($master_doctor_id, (int) $branch_id);
            $branch_update_values['doctor_id'] = $branch_doctor_id;
            $branch_insert_values['doctor_id'] = $branch_doctor_id;

            $lic_no = $staff_values['lic_no'] ?? null;
            $row = $this->_find_branch_user_for_update(
                $current_email,
                $email,
                $this->_payload_contact_email(),
                $lic_no
            );

            if ($row) {
                if (!$this->_update_branch_user($row, $staff_values, $branch_update_values)) {
                    return false;
                }
            } elseif (!$this->_upsert_branch_user_on_update($staff_values, $branch_insert_values)) {
                return false;
            }
        }

        $suffix = date('YmdHis');
        $soft_delete = [
            'is_deleted' => 1,
            'updated_by' => $requester,
            'updated_on' => $now,
        ];

        foreach ($this->_clinic_map() as $map_branch_id => $connection) {
            if (in_array((int) $map_branch_id, $branch_ids, true)) {
                continue;
            }

            if (!$this->resolve_clinic_models($map_branch_id)) {
                continue;
            }

            $row = $this->user_clinic->find_live_by_email($current_email);
            if (!$row) {
                continue;
            }

            $delete_values = $soft_delete;
            if (!empty($row->email)) {
                $delete_values['email'] = $row->email . '_' . $suffix;
            }

            if (!$this->user_clinic->update(['id' => $row->id], $delete_values)) {
                $this->error_message = $this->_model_error($this->user_clinic);
                return false;
            }
        }

        if (!$this->_link_master_professional_staff($main->id, $staff_values)) {
            return false;
        }

        return true;
    }

    /**
     * Master catalog doctor signature URL (Business Ops doctor module).
     */
    protected function _master_doctor_signature_url($master_doctor_id): ?string
    {
        if (empty($master_doctor_id)) {
            return null;
        }

        $doctor = (new Doctor())->select('', ['id' => $master_doctor_id, 'is_deleted' => 0], 1);
        if (!$doctor || empty($doctor->signature)) {
            return null;
        }

        return (string) $doctor->signature;
    }

    /**
     * RULES: Doctor-linked roles prefilled from doctors/get; when no new file is uploaded,
     * reuse the doctor's stored signature on branch professional_staff rows.
     */
    protected function _apply_doctor_signature_to_staff(
        $email,
        array $branch_ids,
        $master_doctor_id,
        $only_if_empty = true
    ): bool {
        $signature_url = $this->_master_doctor_signature_url($master_doctor_id);
        if (!$signature_url || !$email) {
            return true;
        }

        $values = ['signature' => $signature_url];

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_clinic_models($branch_id)) {
                $this->error_message = 'Invalid branch for clinic user.';
                return false;
            }

            $row = $this->user_clinic->find_live_by_email($email);
            if (!$row) {
                continue;
            }

            $ps_id = $this->_ensure_branch_professional_staff_id($row);
            if ($ps_id === false) {
                return false;
            }
            if (!$ps_id) {
                continue;
            }

            if ($only_if_empty) {
                $staff = $this->professional_staff->select(
                    '',
                    ['id' => $ps_id, 'is_deleted' => 0],
                    1
                );
                if ($staff && !empty($staff->signature)) {
                    continue;
                }
            }

            if (!$this->professional_staff->update(['id' => $ps_id], $values)) {
                $this->error_message = $this->_model_error($this->professional_staff);
                return false;
            }
        }

        if (!$this->resolve_clinic_models(0)) {
            return false;
        }

        $main = $this->clinic_user->select('', ['email' => $email, 'is_deleted' => 0], 1);
        if ($main && !$this->_update_master_professional_staff_signature($main->id, $values)) {
            return false;
        }

        return true;
    }

    /**
     * Upload signature via Common.php upload_to_vm4, then write the URL onto every
     * branch professional_staff row for this user. Master clinic_user has no signature
     * column.
     *
     * RULES: A missing file is success, not a failure - signature is optional, and an
     * update that only changes name/role must not wipe or require one. The same file is
     * written to every selected branch so a staff member signing results at Oakridge
     * and Mactan has the same image on both. A file that fails type, size, or VM4
     * upload rolls the whole create/update back.
     */
    protected function _upload_signature($clinic_user_id, $email, array $branch_ids, $path)
    {
        $file = $this->request->getFile('signature') ?? null;
        if (!$file) {
            return true;
        }

        $file_path = $path . $clinic_user_id . '/';

        if (!$file->isValid() || $file->hasMoved()) {
            return false;
        }

        if (!in_array(strtolower($file->getExtension()), ['pdf', 'png', 'jpeg', 'jpg'], true)) {
            return false;
        }

        $max_file_size = 5 * 1024 * 1024;
        if ($file->getSize() > $max_file_size) {
            return false;
        }

        $upload_result = upload_to_vm4($file, $file_path);
        if (!$upload_result || empty($upload_result['success'])) {
            $this->error_message = 'upload failed: ' . ($upload_result['error'] ?? 'unknown');
            return false;
        }

        $uploaded_url = VM4_ENDPOINT . $upload_result['path'];

        $values = [
            'signature' => $uploaded_url,
        ];

        foreach ($branch_ids as $branch_id) {
            if (!$this->resolve_clinic_models($branch_id)) {
                $this->error_message = 'Invalid branch for clinic user.';
                return false;
            }

            $row = $this->user_clinic->find_live_by_email($email);
            if (!$row) {
                continue;
            }

            $ps_id = $this->_ensure_branch_professional_staff_id($row);
            if ($ps_id === false) {
                return false;
            }
            if (!$ps_id) {
                continue;
            }

            if (!$this->professional_staff->update(['id' => $ps_id], $values)) {
                $this->error_message = $this->_model_error($this->professional_staff);
                return false;
            }
        }

        if (!$this->resolve_clinic_models(0)) {
            return false;
        }

        $main = $this->clinic_user->select('', ['email' => $email, 'is_deleted' => 0], 1);
        if ($main && !$this->_update_master_professional_staff_signature($main->id, $values)) {
            return false;
        }

        return true;
    }

    /**
     * Activate or deactivate a clinic user on master and every branch copy.
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $user_id = $this->_payload_user_id();
        if (!$user_id) {
            return $this->_create_response(FAIL, ['clinic_user_id is required.']);
        }

        $status = $this->_payload_status();
        if ($status === null) {
            return $this->_create_response(FAIL, ['Invalid status. Allowed values: active, inactive.']);
        }

        if (!$this->resolve_clinic_models(0)) {
            return $this->_create_response(FAIL, ['Failed to connect to master database.']);
        }

        $main = $this->clinic_user->select('', ['id' => $user_id, 'is_deleted' => 0], 1)
            ?: $this->clinic_user->select('', ['email' => $user_id, 'is_deleted' => 0], 1);
        if (!$main) {
            return $this->_create_response(FAIL_NOT_FOUND, ['Clinic user is not found.']);
        }

        if (strtolower((string) ($main->status ?? '')) === $status) {
            return $this->_create_response(FAIL, ["Clinic user is already {$status}."]);
        }

        $all_write_ids = array_merge([0], array_map('intval', array_keys($this->_clinic_map())));
        if (!$this->_clinic_trans_begin($all_write_ids)) {
            return $this->_create_response(FAIL, ['Failed to connect to branch clinic databases.']);
        }

        if (!$this->_attempt_change_status($main, $status)) {
            $this->_clinic_trans_rollback();
            $response = ['Failed to update clinic user status.'];
            if (!empty($this->error_message)) {
                if (is_string($this->error_message)) {
                    $response[] = $this->error_message;
                } elseif (is_array($this->error_message)) {
                    $response = array_merge($response, array_values($this->error_message));
                }
            }
            return $this->_create_response(FAIL, $response);
        }

        $this->_clinic_trans_commit();
        return $this->_create_response(SUCCESS, [
            'clinic_user_id' => $main->id,
            'status' => $status,
            'message' => "Clinic user {$status} successfully."
        ]);
    }

    /**
     * Set status on the master row and on every branch copy of the user.
     *
     * Branch copies live in user_clinic keyed by email, the same way update()
     * and delete() reach them - branch_in is not consulted, because a branch
     * dropped from the user still holds a row that must not stay active.
     */
    protected function _attempt_change_status($main, string $status): bool
    {
        $values = [
            'status' => $status,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (!$this->resolve_clinic_models(0)) {
            $this->error_message = 'Invalid master clinic connection.';
            return false;
        }

        if (!$this->clinic_user->update(['id' => $main->id], $values)) {
            $this->error_message = $this->_model_error($this->clinic_user);
            return false;
        }

        foreach ($this->_clinic_map() as $branch_id => $connection) {
            if (!$this->resolve_clinic_models($branch_id)) {
                continue;
            }

            $row = $this->user_clinic->find_live_by_email($main->email);
            if (!$row) {
                continue;
            }

            if (!$this->user_clinic->update(['id' => $row->id], $values)) {
                $this->error_message = $this->_model_error($this->user_clinic);
                return false;
            }
        }

        return true;
    }

    /**
     * Soft-delete clinic user on master and every branch DB.
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $user_id = $this->_payload_user_id() ?? $id;
        if (!$user_id) {
            return $this->_create_response(FAIL, ['clinic_user_id is required.']);
        }

        if (!$this->resolve_clinic_models(0)) {
            return $this->_create_response(FAIL, ['Failed to connect to master database.']);
        }

        $main = $this->clinic_user->select('', ['id' => $user_id, 'is_deleted' => 0], 1)
            ?: $this->clinic_user->select('', ['email' => $user_id, 'is_deleted' => 0], 1);
        if (!$main) {
            return $this->_create_response(FAIL_NOT_FOUND, ['Clinic user is not found.']);
        }

        $all_write_ids = array_merge([0], array_map('intval', array_keys($this->_clinic_map())));
        if (!$this->_clinic_trans_begin($all_write_ids)) {
            return $this->_create_response(FAIL, ['Failed to connect to branch clinic databases.']);
        }

        if (!$this->_attempt_delete($main->email)) {
            $this->_clinic_trans_rollback();
            return $this->_create_response(FAIL, [
                'Clinic user is not deleted.'
            ]);
        }

        $this->_clinic_trans_commit();
        return $this->_create_response(SUCCESS, [
            'message' => 'Clinic user is deleted.'
        ]);
    }

    /**
     * Soft-delete by email on master (clinic_user) and every branch DB
     * (user_clinic) - mirrors the model split used by create()/update().
     */
    protected function _attempt_delete($email)
    {
        $suffix = date('YmdHis');
        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? 0;
        $deleted = false;

        if (!$this->resolve_clinic_models(0)) {
            $this->error_message = 'Invalid master clinic connection.';
            return false;
        }

        $master_row = $this->_find_by_email($email);
        if ($master_row) {
            $values = [
                'is_deleted' => 1,
                'updated_by' => $requester,
                'updated_on' => $now,
            ];
            if (!empty($master_row->email)) {
                $values['email'] = $master_row->email . '_' . $suffix;
            }

            if (!$this->clinic_user->update(['id' => $master_row->id], $values)) {
                $this->error_message = $this->_model_error($this->clinic_user);
                return false;
            }
            $deleted = true;
        }

        foreach ($this->_clinic_map() as $branch_id => $connection) {
            if (!$this->resolve_clinic_models($branch_id)) {
                continue;
            }

            $row = $this->user_clinic->find_live_by_email($email);
            if (!$row) {
                continue;
            }

            $values = [
                'is_deleted' => 1,
                'updated_by' => $requester,
                'updated_on' => $now,
            ];
            if (!empty($row->email)) {
                $values['email'] = $row->email . '_' . $suffix;
            }

            if (!$this->user_clinic->update(['id' => $row->id], $values)) {
                $this->error_message = $this->_model_error($this->user_clinic);
                return false;
            }
            $deleted = true;
        }

        return $deleted;
    }
}