<?php

namespace App\Controllers;

use App\Core\MYT_Controller;

class Closed_months extends MYT_Controller
{
    protected $request;
    protected $closed_month;
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
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
        $this->closed_month = new \App\Models\Closed_month(db_connect('master'));
    }

    /**
     * Get closed_months from master.
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $closed_month_id = $this->payload['closed_month_id'] ?? null;
        $month = $this->payload['month'] ?? null;
        $year = $this->payload['year'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $closed_months = $this->closed_month->get_details($closed_month_id, $month, $year, $page, $data_size) ?: [];
        $size = count($this->closed_month->get_details($closed_month_id, $month, $year, null, null) ?: []);

        if (!$closed_months) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No closed_months found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $closed_months
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create closed_month on master.
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $month = $this->payload['month'] ?? null;
        $year = $this->payload['year'] ?? null;

        if ($month === null || $month === '') {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Month is required.']);
        }

        if ($year === null || $year === '') {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Year is required.']);
        }

        if (($validation_error = $this->_validate_month($month)) !== true) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$validation_error]);
        }

        if (($validation_error = $this->_validate_year($year)) !== true) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$validation_error]);
        }

        if ($this->closed_month->exists_for_period($month, $year)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Closed month already exists for this month and year.']);
        }

        if (!$closed_month_id = $this->_attempt_create()) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to add closed_month.']);
        }

        $this->db->transCommit();

        $queued = (new \App\Libraries\Bir_return_generator())->queue_for_closed_month(
            (int) $closed_month_id,
            (int) $year,
            (int) $month,
            $this->payload['requester'] ?? null
        );
        log_message('info', 'BIR PDF generation is running in the background for closed_month_id ' . $closed_month_id);

        return $this->_create_response(SUCCESS, [
            'closed_month_id' => $closed_month_id,
            'queued' => $queued,
            'message' => 'closed_month added successfully. BIR PDF generation is running in the background.',
        ]);
    }

    private function _attempt_create()
    {
        $values = [
            'month' => $this->payload['month'],
            'year' => $this->payload['year'],
            'added_by' => $this->payload['requester'],
            'added_on' => date('Y-m-d H:i:s'),
        ];

        if (!$closed_month_id = $this->closed_month->insert($values)) {
            $this->error_message = $this->closed_month->error();
            return false;
        }

        return $closed_month_id;
    }

    /**
     * Update closed_month on master.
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $closed_month_id = $this->payload['closed_month_id'] ?? null;
        $where = [
            'id' => $closed_month_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        if (!$closed_month = $this->closed_month->select('', $where, 1)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL_NOT_FOUND, ['closed_month is not found.']);
        }

        $month = array_key_exists('month', $this->payload) ? $this->payload['month'] : $closed_month->month;
        $year = array_key_exists('year', $this->payload) ? $this->payload['year'] : $closed_month->year;

        if (array_key_exists('month', $this->payload)) {
            if ($this->payload['month'] === null || $this->payload['month'] === '') {
                $this->db->transRollback();
                return $this->_create_response(FAIL, ['Month is required.']);
            }
            if (($validation_error = $this->_validate_month($month)) !== true) {
                $this->db->transRollback();
                return $this->_create_response(FAIL, [$validation_error]);
            }
        }

        if (array_key_exists('year', $this->payload)) {
            if ($this->payload['year'] === null || $this->payload['year'] === '') {
                $this->db->transRollback();
                return $this->_create_response(FAIL, ['Year is required.']);
            }
            if (($validation_error = $this->_validate_year($year)) !== true) {
                $this->db->transRollback();
                return $this->_create_response(FAIL, [$validation_error]);
            }
        }

        if ($this->closed_month->exists_for_period($month, $year, $closed_month_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Closed month already exists for this month and year.']);
        }

        if (!$this->_attempt_update($closed_month)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Failed to update closed_month.']);
        }

        $this->db->transCommit();
        return $this->_create_response(SUCCESS, [
            'closed_month_id' => $closed_month_id,
            'message' => 'closed_month updated successfully.'
        ]);
    }

    protected function _attempt_update($closed_month)
    {
        $values = [
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        foreach (['month', 'year'] as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $where = ['id' => $closed_month->id];

        if (!$this->closed_month->update($where, $values)) {
            $this->error_message = $this->closed_month->error();
            return false;
        }

        return true;
    }

    /**
     * Soft-delete closed_month on master.
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $closed_month_id = $this->payload['closed_month_id'] ?? null;
        $where = ['id' => $closed_month_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$this->closed_month->select('', $where, 1)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL_NOT_FOUND, ['closed_month is not found.']);
        }

        if (!$this->_attempt_delete($closed_month_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['closed_month is not deleted.']);
        }

        $this->db->transCommit();
        return $this->_create_response(SUCCESS, [
            'message' => 'closed_month is deleted.'
        ]);
    }

    protected function _attempt_delete($closed_month_id)
    {
        $where = ['id' => $closed_month_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (!$this->closed_month->update($where, $values)) {
            $this->error_message = $this->closed_month->error();
            return false;
        }

        return true;
    }

    protected function _validate_month($month)
    {
        if (!preg_match('/^\d+$/', (string) $month) || (int) $month < 1 || (int) $month > 12) {
            return 'Month must be between 1 and 12.';
        }

        return true;
    }

    protected function _validate_year($year)
    {
        if (!preg_match('/^\d{4}$/', (string) $year)) {
            return 'Year must be 4 digits.';
        }

        $year = (int) $year;
        if ($year < 1901 || $year > 2155) {
            return 'Year must be between 1901 and 2155.';
        }

        return true;
    }
}
