<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Endorsement_patient;
use App\Models\Endorsement;
use App\Models\Informed_consent;
use App\Models\Patient_master;
use App\Models\Patient_diagnostic;
use App\Libraries\Patient_tat_service;

class Informed_consents extends MYT_Controller
{
    use Entity_consistency;

    private $patient_tat_service    = null;
    protected $request;
    protected $informed_consent;
    protected $endorsement_patient;
    protected $endorsement;
    protected $patient_master;
    protected $patient_diagnostic;
    protected $helpers = ['text'];

    public function initController($request, $response, $logger)
    {
        $this->public_page = true;
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
    }

    protected function _load_essentials()
    {
        $this->informed_consent = new Informed_consent();
        $this->endorsement_patient = new Endorsement_patient();
        $this->endorsement = new Endorsement();
        $this->patient_master = new Patient_master();
        $this->patient_diagnostic = new Patient_diagnostic();
        $this->patient_tat_service  = new Patient_tat_service();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id.
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
        $this->informed_consent = new Informed_consent($db);
        $this->patient_diagnostic = new Patient_diagnostic($db);
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
            $this->informed_consent->select('id', ['is_deleted' => 0]) ?? []
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
                $this->informed_consent->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                return $branch_name;
            }
        }

        return true;
    }

    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $informed_consent_id = $this->payload['informed_consent_id'] ?? null;
        $transaction_id = $this->payload['transaction_id'] ?? null;
        $first_name = $this->payload['first_name'] ?? null;
        $last_name = $this->payload['last_name'] ?? null;
        $middle_name = $this->payload['middle_name'] ?? null;
        $date_of_birth = $this->payload['date_of_birth'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $informed_consents = $this->informed_consent->get_details($informed_consent_id, $transaction_id, $first_name, $last_name, $middle_name, $date_of_birth, $term, $page, $data_size) ?: [];
        $size = count($this->informed_consent->get_details($informed_consent_id, $transaction_id, $first_name, $last_name, $middle_name, $date_of_birth, $term, null, null) ?: []);

        if (!$informed_consents) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No informed consents found.'
            ];
        } else {
            foreach ($informed_consents as $key => $record) {
                $informed_consents[$key]['signature_url'] = !empty($record['signature'])
                    ? $record['signature']
                    : null;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $informed_consents
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    public function create()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $this->db->transBegin();

        $consistency = $this->_check_branch_consistency();
        if ($consistency !== true) {
            $this->db->transRollback();

            return $this->_create_response(FAIL, [
                "Data inconsistency detected between master and {$consistency}."
            ]);
        }

        $master_patient_id = $this->payload['master_patient_id'] ?? null;
        $is_email_consent = $this->payload['is_email_consent'] ?? 0;
        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $first_name = $this->payload['first_name'] ?? null;
        $last_name = $this->payload['last_name'] ?? null;
        $middle_name = $this->payload['middle_name'] ?? null;
        $date_of_birth = $this->payload['date_of_birth'] ?? null;
        
        if ($endorsement_id) {
            $endorsement_patients = $this->endorsement_patient->get_patient_by_endorsement($endorsement_id, $first_name, $last_name, $middle_name, $date_of_birth);
            $endorsement = $this->endorsement->get_details($endorsement_id);
            $allows_walk_in = (int) ($endorsement[0]['with_walk_in'] ?? 0) === 1;

            // RULES: Skip the masterlist match when the endorsement accepts walk-ins.
            if (!$endorsement_patients && !$allows_walk_in) {
                $this->db->transRollback();
                return $this->_create_response(FAIL, [
                    "No patients found for the specified endorsement."
                ]);
            }

            if ($master_patient_id && $endorsement_patients) {
                $patient_ids = array_map(
                    'intval',
                    array_column(
                        array_map(static fn ($row) => (array) $row, $endorsement_patients),
                        'patient_id'
                    )
                );

                if (!in_array((int) $master_patient_id, $patient_ids, true) && !$allows_walk_in) {
                    $this->db->transRollback();
                    return $this->_create_response(FAIL, [
                        "Patient is not on the specified endorsement."
                    ]);
                }
            }
        }

        if (!$master_patient_id) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Master patient ID is required."
            ];
        } elseif (!$informed_consent_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add informed consent. ",
            ];

            if ($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'informed consent already exists.';
            }
        } elseif (!$this->_attempt_update_email_consent($master_patient_id, $is_email_consent)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add email consent."
            ];
        } elseif (($this->request->getFile('signature') ? true : false) && !$this->_upload_signature($informed_consent_id, 'assets/informed_consents/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload signature. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'informed_consent_id' => $informed_consent_id,
                'message'    => 'informed consent added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    private function _attempt_create()
    {
        $patient_master = $this->patient_master->get_patient_by_name(
            $this->payload['patient_id'] ?? null,
            $this->payload['first_name'] ?? null,
            $this->payload['last_name'] ?? null,
            $this->payload['middle_name'] ?? null,
            $this->payload['date_of_birth'] ?? null,
            $this->payload['sex'] ?? null
        );

        $values = [
            'added_by'              => $this->payload['requester'] ?? 0,
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach ($this->informed_consent->allowedFields as $field) {
            if ($field === 'signature') {
                continue;
            }
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $this->resolve_branch_models(0);
        if (!$informed_consent_id = $this->informed_consent->insert($values)) {
            $this->error_message = $this->informed_consent->error();
            return false;
        }

        $this->patient_tat_service = new Patient_tat_service(db_connect('mobile'));
        if (!$this->patient_tat_service->start_segment([
            'patient_master_id' => $patient_master->id ?? null,
            'queue_id'          => 0,
            'queue_no'          => null,
            'transaction_id'    => 0,
            'action_performed'  => Patient_tat_service::ACTION_KIOSK_WAIT,
            'source'            => Patient_tat_service::SOURCE_KIOSK,
            'section'           => 'csr',
            'added_by'          => $this->payload['requester'] ?? 0,
        ])) {
            $this->error_message = ['message' => 'Failed to start patient TAT segment.'];
            return false;
        }

        foreach ([1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->informed_consent->insert($values)) {
                $this->error_message = $this->informed_consent->error();
                return false;
            }
        }

        return $informed_consent_id;
    }

    private function _attempt_update_email_consent($master_patient_id, $is_email_consent)
    {
        $values = [
            'is_email_consent' => $is_email_consent,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        $where = ['master_id' => $master_patient_id];

        $this->resolve_branch_models(0);

        if (!$this->patient_master->update(['id' => $master_patient_id], $values)) {
            $this->error_message = $this->patient_master->error();
            return false;
        }

        foreach ([1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->patient_diagnostic->update($where, $values)) {
                $this->error_message = $this->patient_diagnostic->error();
                return false;
            }
        }

        return true;
    }

    public function update()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $informed_consent_id = $this->payload['informed_consent_id'];
        $where = [
            'id' => $informed_consent_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        $consistency = $this->_check_branch_consistency();
        if ($consistency !== true) {
            $this->db->transRollback();

            return $this->_create_response(FAIL, [
                "Data inconsistency detected between master and {$consistency}."
            ]);
        }

        $this->resolve_branch_models(0);

        $master_patient_id = $this->payload['master_patient_id'] ?? null;
        $is_email_consent = $this->payload['is_email_consent'] ?? 0;

        if (!$informed_consent = $this->informed_consent->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'informed consent is not found.'
            ];
        } elseif (!$master_patient_id) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Master patient ID is required."
            ];
        } elseif (!$this->_attempt_update($informed_consent)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update informed consent.',
            ];

            if ($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } elseif (!$this->_attempt_update_email_consent($master_patient_id, $is_email_consent)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add email consent."
            ];
        } elseif (($this->request->getFile('signature') ? true : false) && !$this->_upload_signature($informed_consent_id, 'assets/informed_consents/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload signature. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'informed_consent_id' => $informed_consent_id,
                'message'    => 'informed consent updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _attempt_update($informed_consent)
    {
        $values = [
            'updated_by'            => $this->payload['requester'] ?? 0,
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        foreach ($this->informed_consent->allowedFields as $field) {
            if ($field === 'signature') {
                continue;
            }
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $where = ['id' => $informed_consent->id];

        foreach ([0, 1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->informed_consent->update($where, $values)) {
                $this->error_message = $this->informed_consent->error();
                return false;
            }
        }

        return true;
    }

    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $informed_consent_id = $this->payload['informed_consent_id'];

        $where = ['id' => $informed_consent_id, 'is_deleted' => 0];

        $this->db->transBegin();

        $this->resolve_branch_models(0);

        if (!$informed_consent = $this->informed_consent->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'informed consent is not found.',
            ];
        } elseif (!$this->_attempt_delete($informed_consent_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'informed consent is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'informed consent is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _attempt_delete($informed_consent_id)
    {
        $where = ['id' => $informed_consent_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s')
        ];

        foreach ([0, 1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->informed_consent->update($where, $values)) {
                $this->error_message = $this->informed_consent->error();
                return false;
            }
        }

        return true;
    }

    protected function _upload_signature($informed_consent_id, $path)
    {
        $file = $this->request->getFile('signature');
        $file_path = $path . $informed_consent_id . '/';

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

        foreach ([0, 1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->informed_consent->update(['id' => $informed_consent_id], $values)) {
                $this->error_message = $this->informed_consent->error();
                return false;
            }
        }

        return true;
    }

    private function _normalize_column($column)
    {
        if (!is_string($column)) {
            return $column;
        }

        $column = trim($column);
        if ($column === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $column);
    }

    protected function _has_duplicate($table, $where, $id = null)
    {
        $id ? ($where['id <>'] = $id) : null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }

    public function custom_validations($validation_name)
    {
        $is_valid = true;
        switch ($validation_name) {
            case 'create' || 'update':
                $key = 'informed_consent_id';
                $id = array_key_exists($key, $this->payload) ? $this->payload[$key] : null;
        }
        return $is_valid;
    }


    protected function _consistency_table(): string
    {
        return 'informed_consent';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['first_name', 'last_name', 'middle_name', 'date_of_birth'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['first_name', 'last_name', 'middle_name', 'date_of_birth'];
    }

    protected function _consistency_compare_fields(): array
    {
        return [
            'transaction_id', 'first_name', 'last_name', 'middle_name',
            'date_of_birth', 'is_pregnant', 'guardian_name', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [];
    }
}
