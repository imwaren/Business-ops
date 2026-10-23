<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;
use App\Models\Facility;
use App\Models\Facility_lab_test;

class Facilities extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $facility;
    protected $facility_lab_test;
    protected $helpers = ['text'];

    /** Queue an Excel snapshot of the corresponding JSON endpoint. */
    public function get_excel()
    {
        return $this->_queue_excel_export(__FUNCTION__);
    }

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
        $this->facility = new Facility();
        $this->facility_lab_test = new Facility_lab_test();
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
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->facility = new Facility($db);
        $this->facility_lab_test = new Facility_lab_test($db);

        return true;
    }

    /**
     * Get facilities
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $facility_id = $this->payload['facility_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $term = $this->payload['term'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;

        $facilities = $this->facility->get_details($facility_id, $page, $data_size, $term, $supplier_id) ?: [];
        $size = count($this->facility->get_details($facility_id, null, null, $term, $supplier_id) ?: []);

        if (!$facilities) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No facilities found.'
            ];
        } else {
            foreach ($facilities as $key => $facility) {
                $facilities[$key]['details'] = $this->facility_lab_test->get_details($facility['id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $facilities
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create facility
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->facility->select('id', ['is_deleted' => 0]) ?? []
        );

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        foreach ($branches as $branch_id => $branch_name) {
            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->facility->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$facility_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add facility. ",
            ];

            if ($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'facility already exists.';
            }
        } elseif (!$this->_attempt_create_details($facility_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add facility details. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'facility_id' => $facility_id,
                'message'    => 'facility added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a facility
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach ($this->facility->allowedFields as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        // Insert into MASTER first
        $this->resolve_branch_models(0);
        $facility_id = $this->facility->insert($values);
        if (!$facility_id) {
            $this->error_message = $this->facility->error();
            return false;
        }

        // Insert into other branches
        foreach ([1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->facility->insert($values)) {
                $this->error_message = $this->facility->error();
                return false;
            }
        }

        return $facility_id;
    }

    /**
     * Update facility
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $facility_id = $this->payload['facility_id'];
        $where = [
            'id' => $facility_id,
            'is_deleted' => 0
        ];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        if (!$facility = $this->facility->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'facility is not found.'
            ];
        } elseif (!$this->_attempt_update($facility)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update facility.',
            ];

            if ($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } elseif (!$this->_attempt_update_details($facility_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update facility details.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'facility_id' => $facility_id,
                'message'    => 'facility updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($facility)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        foreach ($this->facility->allowedFields as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field] != '' ? $this->payload[$field] : null;
        }

        $where = ['id' => $facility->id];

        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->facility->update($where, $values)) {
                $this->error_message = $this->facility->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Delete facilities
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $facility_id = $this->payload['facility_id'];

        $where = ['id' => $facility_id, 'is_deleted' => 0];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        if (!$facility = $this->facility->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'facility is not found.',
            ];
        } elseif (!$this->_attempt_delete($facility_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'facility is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'facility is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($facility_id)
    {
        $where = ['id' => $facility_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->facility->update($where, $values)) {
                $this->error_message = $this->facility->error();
                return false;
            }

            if (!$this->facility_lab_test->update(['facility_id' => $facility_id], $values)) {
                $this->error_message = $this->facility_lab_test->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create facility details
     */
    private function _attempt_create_details($facility_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'facility_id'   => $facility_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'rate'          => array_key_exists('rate', $detail) && $detail['rate'] != '' ? $detail['rate'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->facility_lab_test->insert($values)) {
                    $this->error_message = $this->facility_lab_test->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update facility details
     */
    private function _attempt_update_details($facility_id)
    {
        $this->resolve_branch_models(0);
        $existing_details = $this->facility_lab_test->select('id', ['facility_id' => $facility_id, 'is_deleted' => 0]) ?: [];

        $existing_ids = array_column($existing_details, 'id');

        $payload_ids = [];
        if (array_key_exists('details', $this->payload) && is_array($this->payload['details'])) {
            foreach ($this->payload['details'] as $detail) {
                if (!empty($detail['id'])) {
                    $payload_ids[] = (int) $detail['id'];
                }
            }
        }

        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];

                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->facility_lab_test->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->facility_lab_test->error();
                        return false;
                    }
                }
            }
        }

        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details'])) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'facility_id'   => $facility_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'rate'          => array_key_exists('rate', $detail) && $detail['rate'] != '' ? $detail['rate'] : null,
            ];

            if (!empty($detail['id'])) {
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->facility_lab_test->update(['id' => $detail['id']], $values)) {
                        $this->error_message = $this->facility_lab_test->error();
                        return false;
                    }
                }
            } else {
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->facility_lab_test->insert($values)) {
                        $this->error_message = $this->facility_lab_test->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Check duplicate
     */
    protected function _has_duplicate($table, $where, $id = null)
    {
        $id ? ($where['id <>'] = $id) : null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }

    public function custom_validations($validation_name)
    {
        return true;
    }

    protected function _consistency_table(): string
    {
        return 'facility';
    }

    protected function _consistency_duplicate_keys(): array
    {
        return ['name'];
    }

    protected function _consistency_label_fields(): array
    {
        return ['name'];
    }

    protected function _consistency_compare_fields(): array
    {
        return ['supplier_id', 'name', 'address', 'is_deleted'];
    }

    protected function _consistency_child_tables(): array
    {
        return [
            ['table' => 'facility_lab_test', 'fk' => 'facility_id'],
        ];
    }
}
