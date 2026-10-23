<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Controllers\Traits\Entity_consistency;

class Memberships extends MYT_Controller
{
    use Entity_consistency;

    protected $request;
    protected $membership;
    protected $membership_service;
    protected $membership_vaccine;
    protected $membership_other_health_service;
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
        $this->membership = new \App\Models\Membership();
        $this->membership_service = new \App\Models\Membership_service();
        $this->membership_vaccine = new \App\Models\Membership_vaccine();
        $this->membership_other_health_service = new \App\Models\Membership_other_health_service();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }
    
    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
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

        $this->membership = new \App\Models\Membership($db);
        $this->membership_service = new \App\Models\Membership_service($db);
        $this->membership_vaccine = new \App\Models\Membership_vaccine($db);
        $this->membership_other_health_service = new \App\Models\Membership_other_health_service($db);

        return true;
    }

    /**
     * Get memberships
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $membership_id = $this->payload['membership_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $memberships = $this->membership->get_details($membership_id, $status, $term, $page, $data_size)?:[];

        if(!$memberships) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No memberships found.'
            ];
        } else {
            foreach ($memberships as $key => $membership) {
                $memberships[$key]['vaccines'] = $this->membership_vaccine->get_vaccines($membership['id']) ?: [];
                $memberships[$key]['services'] = $this->membership_service->get_services($membership['id']) ?: [];
                $memberships[$key]['other_health_services'] = $this->membership_other_health_service->get_other_health_services($membership['id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->membership->get_details($membership_id, $status, $term, null, null)?:[]),
                'data' => $memberships
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create membership
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        // -----------------------------
        // 1️⃣ Check data consistency
        // -----------------------------

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->membership->select('id', ['is_deleted' => 0]) ?? []
        );

        // validate duplicate membership name
        if ($this->_has_duplicate('membership', ['name' => $this->payload['name']])) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Membership name ' . $this->payload['name'] . ' already exists.']);
        }

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
                $this->membership->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$membership_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add membership. ",
            ];
        } elseif (!$this->_attempt_create_vaccines($membership_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add membership vaccines. ",
            ];
        } elseif (!$this->_attempt_create_services($membership_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add membership services. ",
            ];
        } elseif (!$this->_attempt_create_other_health_services($membership_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add membership other health services. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'membership_id' => $membership_id,
                'message'    => 'membership added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a membership
     */
    private function _attempt_create()
    {
        $values = [
            'added_by' => $this->payload['requester'],
            'added_on' => date('Y-m-d H:i:s'),
        ];

        foreach ($this->membership->allowedFields as $field) {
            if (!array_key_exists($field, $this->payload)) {
                continue;
            }

            $values[$field] = $this->payload[$field] !== ''
                ? $this->payload[$field]
                : null;
        }

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            $membership_id = $this->membership->insert($values);

            if (!$membership_id) {
                $this->error_message = $this->membership->error();
                return false;
            }
        }

        return $membership_id;
    }

    /**
     * Attempt to create membership vaccines
     */
    private function _attempt_create_vaccines($membership_id)
    {
        if (!array_key_exists('vaccines', $this->payload) || !is_array($this->payload['vaccines']) || count($this->payload['vaccines']) == 0) {
            return true;
        }

        foreach ($this->payload['vaccines'] as $vaccine) {
            $values = [
                'membership_id'    => $membership_id,
                'vaccine_id'   => array_key_exists('vaccine_id', $vaccine) && $vaccine['vaccine_id'] != '' ? $vaccine['vaccine_id'] : null,
                'lab_test_id'   => array_key_exists('lab_test_id', $vaccine) && $vaccine['lab_test_id'] != '' ? $vaccine['lab_test_id'] : null,
                'frequency'   => array_key_exists('frequency', $vaccine) && $vaccine['frequency'] != '' ? $vaccine['frequency'] : 0,
                'percentage'   => array_key_exists('percentage', $vaccine) && $vaccine['percentage'] != '' ? $vaccine['percentage'] : 0,
                'is_free'   => array_key_exists('is_free', $vaccine) && $vaccine['is_free'] != '' ? $vaccine['is_free'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->membership_vaccine->insert($values)) {
                    $this->error_message = $this->membership_vaccine->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create membership services
     */
    private function _attempt_create_services($membership_id)
    {
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services']) || count($this->payload['services']) == 0) {
            return true;
        }

        foreach ($this->payload['services'] as $service) {
            $values = [
                'membership_id'    => $membership_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $service) && $service['lab_test_id'] != '' ? $service['lab_test_id'] : null,
                'frequency'   => array_key_exists('frequency', $service) && $service['frequency'] != '' ? $service['frequency'] : 0,
                'is_free'   => array_key_exists('is_free', $service) && $service['is_free'] != '' ? $service['is_free'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->membership_service->insert($values)) {
                    $this->error_message = $this->membership_service->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create other health services
     */
    private function _attempt_create_other_health_services($membership_id)
    {
        if (!array_key_exists('other_health_services', $this->payload) || !is_array($this->payload['other_health_services']) || count($this->payload['other_health_services']) == 0) {
            return true;
        }

        foreach ($this->payload['other_health_services'] as $other_health_service) {
            $values = [
                'membership_id'    => $membership_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $other_health_service) && $other_health_service['lab_test_id'] != '' ? $other_health_service['lab_test_id'] : null,
                'frequency'   => array_key_exists('frequency', $other_health_service) && $other_health_service['frequency'] != '' ? $other_health_service['frequency'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // Insert into other branches
            foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                $this->resolve_branch_models($branch_id);

                if (!$this->membership_other_health_service->insert($values)) {
                    $this->error_message = $this->membership_other_health_service->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update membership
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $membership_id = $this->payload['membership_id'];
        $where = [
                    'id' => $membership_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        // -----------------------------
        // 1️⃣ Check data consistency
        // -----------------------------

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->membership->select('id', ['is_deleted' => 0]) ?? []
        );

        // validate duplicate membership name
        if ($this->_has_duplicate('membership', ['name' => $this->payload['name']], $membership_id)) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, ['Membership name ' . $this->payload['name'] . ' already exists.']);
        }

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
                $this->membership->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$membership = $this->membership->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'membership is not found.'
            ];
        //validate status
        } elseif ($status && $membership->status == 'approved') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Cannot update an approved membership. ",
            ];
        } elseif (!$this->_attempt_update($membership)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update membership.',
            ];
        } elseif (!$this->_attempt_update_vaccines($membership->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update membership vaccines. ",
            ];
        } elseif (!$this->_attempt_update_services($membership->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update membership services. ",
            ];
        } elseif (!$this->_attempt_update_other_health_services($membership->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update membership other health services. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'membership_id' => $membership_id,
                'message'    => 'membership updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($membership)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->membership->allowedFields is declared at the top of the model
        foreach($this->membership->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $status = $this->payload['status'] ?? $membership->status;
        if ($status == 'approved') {
            $values['status'] = 'approved';
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $membership->id];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->membership->update($where, $values)) {
                $this->error_message = $this->membership->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to update membership vaccines
     */
    private function _attempt_update_vaccines($membership_id)
    {
        // 1. Get all current vaccine IDs for this membership
        $existing_vaccines = $this->membership_vaccine->select('id', ['membership_id' => $membership_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_vaccines, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('vaccines', $this->payload) && is_array($this->payload['vaccines'])) {
            foreach ($this->payload['vaccines'] as $vaccine) {
                if (!empty($vaccine['id'])) {
                    $payload_ids[] = (int)$vaccine['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_vaccine->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->membership_vaccine->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload vaccines (update existing or insert new)
        if (!array_key_exists('vaccines', $this->payload) || !is_array($this->payload['vaccines'])) {
            return true;
        }

        foreach ($this->payload['vaccines'] as $vaccine) {
            $values = [
                'membership_id'  => $membership_id,
                'vaccine_id' => array_key_exists('vaccine_id', $vaccine) && $vaccine['vaccine_id'] != '' ? $vaccine['vaccine_id'] : null,
                'lab_test_id'   => array_key_exists('lab_test_id', $vaccine) && $vaccine['lab_test_id'] != '' ? $vaccine['lab_test_id'] : null,
                'frequency'   => array_key_exists('frequency', $vaccine) && $vaccine['frequency'] != '' ? $vaccine['frequency'] : 0,
                'percentage' => array_key_exists('percentage', $vaccine) && $vaccine['percentage'] != '' ? $vaccine['percentage'] : 0,
                'is_free' => array_key_exists('is_free', $vaccine) && $vaccine['is_free'] != '' ? $vaccine['is_free'] : 0,
            ];

            if (!empty($vaccine['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_vaccine->update(['id' => $vaccine['id']], $values)) {
                        $this->error_message = $this->membership_vaccine->error();
                        return false;
                    }
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_vaccine->insert($values)) {
                        $this->error_message = $this->membership_vaccine->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update membership services
     */
    private function _attempt_update_services($membership_id)
    {
        // 1. Get all current service IDs for this membership
        $existing_services = $this->membership_service->select('id', ['membership_id' => $membership_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_services, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('services', $this->payload) && is_array($this->payload['services'])) {
            foreach ($this->payload['services'] as $service) {
                if (!empty($service['id'])) {
                    $payload_ids[] = (int)$service['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_service->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->membership_service->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload services (update existing or insert new)
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services'])) {
            return true;
        }

        foreach ($this->payload['services'] as $service) {
            $values = [
                'membership_id'  => $membership_id,
                'lab_test_id' => array_key_exists('lab_test_id', $service) && $service['lab_test_id'] != '' ? $service['lab_test_id'] : null,
                'frequency' => array_key_exists('frequency', $service) && $service['frequency'] != '' ? $service['frequency'] : 0,
                'is_free'   => array_key_exists('is_free', $service) && $service['is_free'] != '' ? $service['is_free'] : 0,
            ];

            if (!empty($service['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_service->update(['id' => $service['id']], $values)) {
                        $this->error_message = $this->membership_service->error();
                        return false;
                    }
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_service->insert($values)) {
                        $this->error_message = $this->membership_service->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update membership other_health_services
     */
    private function _attempt_update_other_health_services($membership_id)
    {
        // 1. Get all current other_health_service IDs for this membership
        $existing_other_health_services = $this->membership_other_health_service->select('id', ['membership_id' => $membership_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_other_health_services, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('other_health_services', $this->payload) && is_array($this->payload['other_health_services'])) {
            foreach ($this->payload['other_health_services'] as $other_health_service) {
                if (!empty($other_health_service['id'])) {
                    $payload_ids[] = (int)$other_health_service['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];
                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_other_health_service->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->membership_other_health_service->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload other_health_services (update existing or insert new)
        if (!array_key_exists('other_health_services', $this->payload) || !is_array($this->payload['other_health_services'])) {
            return true;
        }

        foreach ($this->payload['other_health_services'] as $other_health_service) {
            $values = [
                'membership_id'  => $membership_id,
                'lab_test_id' => array_key_exists('lab_test_id', $other_health_service) && $other_health_service['lab_test_id'] != '' ? $other_health_service['lab_test_id'] : null,
                'frequency' => array_key_exists('frequency', $other_health_service) && $other_health_service['frequency'] != '' ? $other_health_service['frequency'] : 0,
            ];

            if (!empty($other_health_service['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_other_health_service->update(['id' => $other_health_service['id']], $values)) {
                        $this->error_message = $this->membership_other_health_service->error();
                        return false;
                    }
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                // Insert into other branches
                foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
                    $this->resolve_branch_models($branch_id);

                    if (!$this->membership_other_health_service->insert($values)) {
                        $this->error_message = $this->membership_other_health_service->error();
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Change status membership
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $membership_id = $this->payload['membership_id'];
        $where = [
                    'id' => $membership_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$membership = $this->membership->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'membership is not found.'
            ];
        //validate status
        } elseif ($status && $membership->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Membership is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($membership)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change membership status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'membership_id' => $membership_id,
                'message'    => "membership $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($membership)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'] ?? $membership->status;
        if ($status == 'approved') {
            $values['status'] = 'approved';
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $membership->id];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->membership->update($where, $values)) {
                $this->error_message = $this->membership->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Delete memberships
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $membership_id = $this->payload['membership_id'];

        $where = ['id' => $membership_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$membership = $this->membership->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'membership is not found.',
            ];
        } elseif (!$this->_attempt_delete($membership_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'membership is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'membership is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($membership_id)
    {
        $where = ['id' => $membership_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        // Insert into other branches
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $branch_id) {
            $this->resolve_branch_models($branch_id);

            if (!$this->membership->update($where, $values)) {
                $this->error_message = $this->membership->error();
                return false;
            }
        }

        return true;
    }

    /**
    * Check duplicate
    */
    protected function _has_duplicate($table, $where, $id = null)
    {
        $id?($where['id <>'] = $id):null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }



    protected function _consistency_table(): string
    {
        return 'membership';
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
        return [
            'name', 'price', 'consultation', 'vaccine', 'meds', 'diagnostics',
            'days_duration', 'vaccine_discount', 'is_deleted',
        ];
    }

    protected function _consistency_child_tables(): array
    {
        return [
            ['table' => 'membership_vaccine', 'fk' => 'membership_id'],
            ['table' => 'membership_service', 'fk' => 'membership_id'],
            ['table' => 'membership_other_health_service', 'fk' => 'membership_id'],
        ];
    }
}
