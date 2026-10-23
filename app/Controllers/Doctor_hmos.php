<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Doctor_hmo;

class Doctor_hmos extends MYT_Controller
{
    protected $request;
    protected $doctor_hmo;
    protected $helpers = ['text'];
    protected $error_message;

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        $this->_load_essentials();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        $this->doctor_hmo = new Doctor_hmo();
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
        $this->doctor_hmo = new Doctor_hmo($db);

        return true;
    }

    /**
     * Branch ids for syncing doctor HMO rows (master + branch_in).
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
     * Get doctor HMO accreditations
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $doctor_id = $this->payload['doctor_id'] ?? null;
        if (empty($doctor_id)) {
            return $this->_create_response(FAIL, [
                'doctor_id is required.',
            ]);
        }

        $this->resolve_branch_models(0);
        $rows = $this->doctor_hmo->get_by_doctor($doctor_id) ?: null;

        if (!$rows) {
            return $this->_create_response(FAIL_NOT_FOUND, [
                'No doctor HMO accreditations found.',
            ]);
        }

        return $this->_create_response(SUCCESS, [
            'size' => count($rows),
            'data' => $rows,
        ]);
    }

    /**
     * Sync doctor HMO accreditations
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $doctor_id = $this->payload['doctor_id'] ?? null;
        if (empty($doctor_id)) {
            return $this->_create_response(FAIL, [
                'doctor_id is required.',
            ]);
        }

        $company_ids = $this->_parse_company_ids();

        $this->db->transBegin();

        foreach ($this->_sync_branch_ids() as $branch_id) {
            if (!$this->resolve_branch_models((int) $branch_id)) {
                $this->db->transRollback();
                return $this->_create_response(FAIL, [
                    'Invalid branch_in for doctor HMO sync.',
                ]);
            }

            if (!$this->doctor_hmo->sync_for_doctor($doctor_id, $company_ids, $this->payload['requester'] ?? 0)) {
                $this->db->transRollback();
                return $this->_create_response(FAIL, [
                    'Failed to update doctor HMO accreditations.',
                ]);
            }
        }

        $this->db->transCommit();

        return $this->_create_response(SUCCESS, [
            'doctor_id' => $doctor_id,
            'message' => 'Doctor HMO accreditations updated successfully.',
        ]);
    }

    /**
     * Parse company_ids from payload (array or comma-separated string).
     */
    protected function _parse_company_ids(): array
    {
        if (!array_key_exists('company_ids', $this->payload)) {
            return [];
        }

        $raw = $this->payload['company_ids'];
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }

        return array_values(array_unique(array_filter(array_map('intval', (array) $raw))));
    }
}
