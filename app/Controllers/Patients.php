<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Patient_master;

class Patients extends MYT_Controller
{
    protected $request;
    protected $patient_master;
    protected $patient_emr;
    protected $patient_diagnostic;
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
        $this->patient_master = new Patient_master();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }
    
    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $dbMap = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',

            10 => 'mobile_emr',
            12 => 'oakridge_emr',
            13 => 'mactan_emr',
            14 => 'consolacion_emr',
            17 => 'mantlewood_emr',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->patient_emr = new \App\Models\Patient_emr($db);
        $this->patient_diagnostic = new \App\Models\Patient_diagnostic($db);

        return true;
    }

    /**
     * Get patients
     */
    public function get()
    {
        $patient_id = $this->payload['patient_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $branch_ids = $this->_is_admin_role() ? null : $this->_requester_branch_ids();

        $patients = $this->patient_master->search_record($patient_id, $term, $page, $data_size, $branch_ids)?:[];
        $size = $this->patient_master->count_search_record($patient_id, $term, $branch_ids);

        if(!$patients) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No patients found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $patients
            ];
        }

        return $this->_create_response($response_status, $response, true);
    }

    /**
     * Change email consent
     */
    public function change_email_consent()
    {
        $patient_id = $this->payload['patient_id'] ?? null; //patient id from master db
        $opt = $this->payload['opt'] ?? null; //in or out

        if(!$patient = $this->patient_master->select('id', ['is_deleted' => 0, 'id' => $patient_id], 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No patients found.'
            ];
        } elseif (!$this->_attempt_update_email_consent_master($patient, $opt)) {
            $response_status = FAIL;
            $response = [
                'Failed to update email consent to master db.'
            ];
        } elseif (!$this->_attempt_update_email_consent_diagnostic($patient, $opt)) {
            $response_status = FAIL;
            $response = [
                'Failed to update email consent to diagnostic db.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'Email consent updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to update email consent
     */
    private function _attempt_update_email_consent_master($patient, $opt): bool
    {
        $values = [
            'is_email_consent' => $opt === 'in' ? 1 : 0,
            'updated_by' =>$this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (!$this->patient_master->update(['id' => $patient->id], $values)) {
            return false;
        }

        return true;
    }

    /**
     * Attempt to update email consent
     */
    private function _attempt_update_email_consent_diagnostic($patient, $opt): bool
    {
        $values = [
            'is_email_consent' => $opt === 'in' ? 1 : 0,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        //loop through all the diagnostic db and update the email consent
        foreach ([1,2,3,4,7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->patient_diagnostic->update(['master_id' => $patient->id], $values)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sync patients from the patient_temp table (master db).
     * - Existing patients (matched by patient_no, or by name + birthdate) are tagged as VIP across master, diagnostic, and emr dbs.
     * - Unmatched rows are inserted as new patients in master first (to get the master id), then in all diagnostic and emr dbs.
     */
    public function sync_patient_temp()
    {
        $patient_temp = new \App\Models\Patient_temp();

        $data_size = (int) ($this->payload['data_size'] ?? 50);

        $this->db->transBegin();

        $temp_patients = $patient_temp->get_unprocessed($data_size) ?: [];

        if (!$temp_patients) {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message'   => 'Nothing left to sync in patient_temp.',
                'updated'   => 0,
                'inserted'  => 0,
                'skipped'   => 0,
                'remaining' => 0,
            ];
        } elseif (($result = $this->_attempt_sync_patient_temp($patient_temp, $temp_patients)) === false) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to sync patient_temp. ' . $this->error_message,
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message'   => 'patient_temp batch synced successfully.',
                'updated'   => $result['updated'],
                'inserted'  => $result['inserted'],
                'skipped'   => $result['skipped'],
                'remaining' => $patient_temp->count_unprocessed(),
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to sync a batch of patient_temp rows to master, diagnostic, and emr dbs.
     * Each handled row is marked as processed so subsequent calls skip it.
     */
    private function _attempt_sync_patient_temp($patient_temp, $temp_patients)
    {
        $result = [
            'updated'  => 0,
            'inserted' => 0,
            'skipped'  => 0,
        ];

        foreach ($temp_patients as $temp_patient) {
            $patient_no = trim((string) ($temp_patient->patient_no ?? ''));
            $has_patient_no = $patient_no !== '' && strtoupper($patient_no) !== 'NULL' && $patient_no !== '0';

            // Update path: patient_no matches an existing master patient, tag as VIP only
            if ($has_patient_no && ($patient = $this->patient_master->select('id', ['patient_no' => $patient_no, 'is_deleted' => 0], 1))) {
                if (!$this->_attempt_mark_patient_vip($patient->id)) {
                    return false;
                }

                if (!$this->_attempt_mark_temp_processed($patient_temp, $temp_patient->id)) {
                    return false;
                }

                $result['updated']++;
                continue;
            }

            [$last_name, $first_name, $middle_name] = $this->_parse_patient_temp_name($temp_patient->patient_name ?? '');

            if (!$last_name || !$first_name) {
                // Mark unparseable rows (missing first or last name) as processed so they cannot wedge the loop
                if (!$this->_attempt_mark_temp_processed($patient_temp, $temp_patient->id)) {
                    return false;
                }

                $result['skipped']++;
                continue;
            }

            // Dedupe: same name + birthdate already in master (also handles duplicate patient_temp rows)
            $existing = $this->patient_master->get_patient_by_name(
                null,
                $first_name ? strtolower($first_name) : null,
                $last_name ? strtolower($last_name) : null,
                $middle_name ? strtolower($middle_name) : null,
                $temp_patient->birthdate ?? null
            );

            if ($existing) {
                if (!$this->_attempt_mark_patient_vip($existing->id)) {
                    return false;
                }

                if (!$this->_attempt_mark_temp_processed($patient_temp, $temp_patient->id)) {
                    return false;
                }

                $result['updated']++;
                continue;
            }

            // Insert path: master first to get the master id, then emr and diagnostic dbs
            $patient_values = [
                'branch_id'    => 0,
                'patient_no'   => $has_patient_no ? $patient_no : null,
                'is_vip'       => 1,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'middle_name'  => $middle_name,
                'birthdate'    => $temp_patient->birthdate ?? null,
                'sex'          => $temp_patient->gender ?? null,
                'contact_no'   => !empty($temp_patient->contact_no) ? $temp_patient->contact_no : 0,
                'civil_status' => null,
                'email'        => null,
                'added_by'     => $this->payload['requester'],
                'added_on'     => date('Y-m-d H:i:s'),
            ];

            $patient_encrypt_fields = ['first_name', 'last_name', 'middle_name', 'birthdate', 'contact_no', 'civil_status', 'email'];

            if (!$master_patient_id = $this->_insert_encrypt_generated_id($this->patient_master, $patient_values, $patient_encrypt_fields, 0)) {
                return false;
            }

            unset($patient_values['branch_id'], $patient_values['patient_no']);

            //insert to emr databases
            $patient_emr_values = array_merge(['master_patient_id' => $master_patient_id], $patient_values);

            foreach ([10,12,13,14,17] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if ($this->patient_emr->select('', ['master_patient_id' => $master_patient_id, 'is_deleted' => 0], 1)) {
                    continue;
                }

                if (!$this->_insert_encrypt_generated_id($this->patient_emr, $patient_emr_values, $patient_encrypt_fields, $branch_id)) {
                    return false;
                }
            }

            //insert to diagnostic databases
            $patient_diagnostic_values = array_merge(['master_id' => $master_patient_id], $patient_values);

            foreach ([1,2,3,4,7] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if ($this->patient_diagnostic->select('', ['master_id' => $master_patient_id, 'is_deleted' => 0], 1)) {
                    continue;
                }

                if (!$this->_insert_encrypt_generated_id($this->patient_diagnostic, $patient_diagnostic_values, $patient_encrypt_fields, $branch_id)) {
                    return false;
                }
            }

            if (!$this->_attempt_mark_temp_processed($patient_temp, $temp_patient->id)) {
                return false;
            }

            $result['inserted']++;
        }

        return $result;
    }

    /**
     * Attempt to mark a patient_temp row as processed so subsequent batches skip it
     */
    private function _attempt_mark_temp_processed($patient_temp, $temp_patient_id): bool
    {
        if (!$patient_temp->update(['id' => $temp_patient_id], ['is_processed' => 1])) {
            $this->error_message = $patient_temp->error();
            return false;
        }

        return true;
    }

    /**
     * Attempt to tag a patient as VIP in master, diagnostic, and emr dbs
     */
    private function _attempt_mark_patient_vip($master_patient_id): bool
    {
        $values = [
            'is_vip'     => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (!$this->patient_master->update(['id' => $master_patient_id], $values)) {
            $this->error_message = $this->patient_master->error();
            return false;
        }

        //loop through all the diagnostic dbs and tag the patient as VIP
        foreach ([1,2,3,4,7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->patient_diagnostic->update(['master_id' => $master_patient_id], $values)) {
                $this->error_message = $this->patient_diagnostic->error();
                return false;
            }
        }

        //loop through all the emr dbs and tag the patient as VIP
        foreach ([10,12,13,14,17] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->patient_emr->update(['master_patient_id' => $master_patient_id], $values)) {
                $this->error_message = $this->patient_emr->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Parse a patient_temp name in "LAST, FIRST MIDDLE" format.
     * The last word after the comma is treated as the middle name.
     */
    private function _parse_patient_temp_name($patient_name): array
    {
        $patient_name = trim((string) $patient_name);

        if ($patient_name === '') {
            return [null, null, null];
        }

        // No comma: name is in "FIRST [MIDDLE] LAST" order
        if (strpos($patient_name, ',') === false) {
            $tokens = preg_split('/\s+/', $patient_name, -1, PREG_SPLIT_NO_EMPTY);

            if (count($tokens) === 1) {
                return [$patient_name, null, null];
            }

            $last_name = array_pop($tokens);
            $middle_name = count($tokens) > 1 ? array_pop($tokens) : null;
            $first_name = implode(' ', $tokens);

            return [$last_name, $first_name, $middle_name];
        }

        [$last_name, $remainder] = array_map('trim', explode(',', $patient_name, 2));

        $tokens = preg_split('/\s+/', $remainder, -1, PREG_SPLIT_NO_EMPTY);

        if (count($tokens) > 1) {
            $middle_name = array_pop($tokens);
            $first_name = implode(' ', $tokens);
        } else {
            $first_name = $remainder !== '' ? $remainder : null;
            $middle_name = null;
        }

        return [$last_name, $first_name, $middle_name];
    }

}
