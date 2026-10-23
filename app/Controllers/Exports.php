<?php

namespace App\Controllers;

use App\Core\MYT_Controller;

class Exports extends MYT_Controller
{
    /** Get status only within the authenticated export-owner context. */
    public function get_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $export_id = $this->payload['export_id'] ?? null;
        if (!is_string($export_id) || !preg_match('/\A[a-f0-9]{32}\z/D', $export_id)) {
            return $this->_create_response(FAIL, ['export_id must be a valid export ID.']);
        }

        try {
            $owner = $this->_excel_export_owner();
            if ($owner === null) {
                return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Invalid export owner.']);
            }
            $service = $this->_excel_export_service();
            $service->prune();
            $job = $service->owned_job($export_id, $owner);
            if (!$job) {
                return $this->_create_response(FAIL_NOT_FOUND, ['Export is not found.']);
            }
            foreach ([$job['method'], \App\Libraries\Excel_export::base_method($job['controller'], $job['method'])] as $method) {
                if (!$method) {
                    return $this->_create_response(FAIL_NOT_FOUND, ['Export is not found.']);
                }
                if (($response = $this->_permission_allowed_for($job['controller'], $method)) !== true) {
                    return $response;
                }
            }
            $response = $service->get_status($export_id, $owner);
            $response_status = $response ? SUCCESS : FAIL_NOT_FOUND;
            $response = $response ?: ['Export is not found.'];
        } catch (\Throwable $exception) {
            $response_status = FAIL_SERVER_ERROR;
            $response = ['Unable to read Excel export status.'];
        }

        return $this->_create_response($response_status, $response);
    }
}
