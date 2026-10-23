<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Data_consent;
use App\Models\Endorsement_patient;
use App\Models\Endorsement;
use App\Models\Patient_master;
use App\Libraries\Patient_tat_service;

class Data_consents extends MYT_Controller
{
    use Entity_consistency;

    private $patient_tat_service    = null;
    protected $patient_master;
    protected $endorsement_patient;
    protected $endorsement;
    protected $request;
    protected $data_consent;
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
        $this->data_consent = new Data_consent();
        $this->endorsement_patient = new Endorsement_patient();
        $this->endorsement = new Endorsement();
        $this->patient_master = new Patient_master();
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
            7 => 'mantlewood'
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);
        $this->data_consent = new Data_consent($db);

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
            $this->data_consent->select('id', ['is_deleted' => 0]) ?? []
        );

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood'
        ];

        foreach ($branches as $branch_id => $branch_name) {
            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->data_consent->select('id', ['is_deleted' => 0]) ?? []
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

        $data_consent_id = $this->payload['data_consent_id'] ?? null;
        $first_name = $this->payload['first_name'] ?? null;
        $last_name = $this->payload['last_name'] ?? null;
        $middle_name = $this->payload['middle_name'] ?? null;
        $date_of_birth = $this->payload['date_of_birth'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $data_consents = $this->data_consent->get_details($data_consent_id, $first_name, $last_name, $middle_name, $date_of_birth, $term, $page, $data_size) ?: [];
        $size = count($this->data_consent->get_details($data_consent_id, $first_name, $last_name, $middle_name, $date_of_birth, $term, null, null) ?: []);

        if (!$data_consents) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No data consents found.'
            ];
        } else {
            foreach ($data_consents as $key => $record) {
                $data_consents[$key]['signature_url'] = !empty($record['signature'])
                    ? $record['signature']
                    : null;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $data_consents
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    public function create()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }
        $endorsement_id = $this->payload['endorsement_id'] ?? null;
        $first_name = $this->payload['first_name'] ?? null;
        $last_name = $this->payload['last_name'] ?? null;
        $middle_name = $this->payload['middle_name'] ?? null;
        $date_of_birth = $this->payload['date_of_birth'] ?? null;

        $this->db->transBegin();

        $consistency = $this->_check_branch_consistency();
        if ($consistency !== true) {
            $this->db->transRollback();

            return $this->_create_response(FAIL, [
                "Data inconsistency detected between master and {$consistency}."
            ]);
        }

        // RULES: Masterlist endorsements require the patient to already be on the endorsement.
        // with_walk_in = 1 allows a patient who is not on that list to still register.
        if ($endorsement_id && !$this->endorsement_patient->get_patient_by_endorsement($endorsement_id, $first_name, $last_name, $middle_name, $date_of_birth)) {
            $endorsement = $this->endorsement->get_details($endorsement_id);
            $allows_walk_in = (int) ($endorsement[0]['with_walk_in'] ?? 0) === 1;

            if (!$allows_walk_in) {
                $this->db->transRollback();
                return $this->_create_response(FAIL, [
                    "No patients found for the specified endorsement."
                ]);
            }
        }

        $patient_master = $this->patient_master->get_patient_by_name(
            $this->payload['patient_id'] ?? null,
            $this->payload['first_name'] ?? null,
            $this->payload['last_name'] ?? null,
            $this->payload['middle_name'] ?? null,
            $this->payload['date_of_birth'] ?? null,
            $this->payload['sex'] ?? null
        );

        if (!$data_consent_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add data consent. ",
            ];

            if ($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'data consent already exists.';
            }
        } elseif (($this->request->getFile('signature') ? true : false) && !$this->_upload_signature($data_consent_id, 'assets/data_consents/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload signature. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'data_consent_id' => $data_consent_id,
                'patient_master_id' => $patient_master ? $patient_master->id : null,
                'message'    => 'data consent added successfully.'
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

        foreach ($this->data_consent->allowedFields as $field) {
            if ($field === 'signature') {
                continue;
            }
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $this->resolve_branch_models(0);
        if (!$data_consent_id = $this->data_consent->insert($values)) {
            $this->error_message = $this->data_consent->error();
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

            if (!$this->data_consent->insert($values)) {
                $this->error_message = $this->data_consent->error();
                return false;
            }
        }

        return $data_consent_id;
    }

    public function update()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        $data_consent_id = $this->payload['data_consent_id'];
        $where = [
            'id' => $data_consent_id,
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

        if (!$data_consent = $this->data_consent->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'data consent is not found.'
            ];
        } elseif (!$this->_attempt_update($data_consent)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update data consent.',
            ];

            if ($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } elseif (($this->request->getFile('signature') ? true : false) && !$this->_upload_signature($data_consent_id, 'assets/data_consents/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload signature. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'data_consent_id' => $data_consent_id,
                'message'    => 'data consent updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _attempt_update($data_consent)
    {
        $values = [
            'updated_by'            => $this->payload['requester'] ?? 0,
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        foreach ($this->data_consent->allowedFields as $field) {
            if ($field === 'signature') {
                continue;
            }
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $where = ['id' => $data_consent->id];

        foreach ([0, 1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->data_consent->update($where, $values)) {
                $this->error_message = $this->data_consent->error();
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

        $data_consent_id = $this->payload['data_consent_id'];

        $where = ['id' => $data_consent_id, 'is_deleted' => 0];

        $this->db->transBegin();

        $this->resolve_branch_models(0);

        if (!$data_consent = $this->data_consent->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'data consent is not found.',
            ];
        } elseif (!$this->_attempt_delete($data_consent_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'data consent is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'data consent is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _attempt_delete($data_consent_id)
    {
        $where = ['id' => $data_consent_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'] ?? 0,
            'updated_on' => date('Y-m-d H:i:s')
        ];

        foreach ([0, 1, 2, 3, 4, 7] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->data_consent->update($where, $values)) {
                $this->error_message = $this->data_consent->error();
                return false;
            }
        }

        return true;
    }

    protected function _upload_signature($data_consent_id, $path)
    {
        $file = $this->request->getFile('signature');
        $file_path = $path . $data_consent_id . '/';

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

            if (!$this->data_consent->update(['id' => $data_consent_id], $values)) {
                $this->error_message = $this->data_consent->error();
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
                $key = 'data_consent_id';
                $id = array_key_exists($key, $this->payload) ? $this->payload[$key] : null;
        }
        return $is_valid;
    }


    protected function _consistency_table(): string
    {
        return 'data_consent';
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
            'first_name', 'last_name', 'middle_name', 'date_of_birth',
            'is_data_privay_consent', 'is_email_consent', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [];
    }
}
