<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Direct_payment_attachment;
use App\Libraries\Apv_accounting;

class Direct_payments extends MYT_Controller
{
    use \App\Core\Fund_routing;
    protected $request;
    protected $direct_payment;
    protected $direct_payment_attachment;
    protected $branch_db;
    protected $user;
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
        $this->user = new User();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $routing = $this->fund_router();
        $group = $routing->group($branch_id);
        if ($group === null) { return false; }
        $db = db_connect($group);
        $this->branch_db = $db;

        $this->direct_payment = new \App\Models\Direct_payment($db);
        $this->direct_payment_attachment = new Direct_payment_attachment($db);

        if ($group === 'master') {
            $this->db = $db; // Transaction and scoped models must use the same connection.
            foreach (['direct_payment', 'direct_payment_attachment'] as $property) {
                if (method_exists($this->$property, 'with_shared_cost_centers')) {
                    $this->$property->with_shared_cost_centers($branch_id);
                }
            }
        }
        return true;
    }

    /**
     * Get direct_payments
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $direct_payment_id = $this->payload['direct_payment_id'] ?? null;
        $department_id = $this->payload['department_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $direct_payments = $this->direct_payment->get_details($direct_payment_id, $department_id, $status, $date_from, $date_to, $term, $page, $data_size)?:[];
        $size = count($this->direct_payment->get_details($direct_payment_id, $department_id, $status, $date_from, $date_to, $term, null, null)?:[]);

        if(!$direct_payments) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No direct_payments found.'
            ];
        } else {
            foreach ($direct_payments AS $key => $direct_payment) {
                $requested_user = $this->user->select('id, first_name, last_name', ['id' => $direct_payment['requested_by_id'], 'is_deleted' => 0], 1);
                $direct_payments[$key]['requested_by_name'] = $requested_user ? $requested_user->first_name . ' ' . $requested_user->last_name : null;
                $direct_payments[$key]['attachments'] = $this->direct_payment_attachment->get_details(null, $direct_payment['id']) ?: [];
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $direct_payments
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create direct_payment
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        if (($error = $this->_validate_header_amount(true)) !== null) {
            return $this->_create_response(FAIL, [$error]);
        }

        if (($validation_error = $this->_validate_duration()) !== true) {
            return $this->_create_response(
                FAIL,
                [$validation_error]
            );
        }

        $attachments = $this->_get_valid_attachment_files();
        if ($attachments === false) {
            return $this->_create_response(FAIL, [
                $this->error_message ?: 'Attachments must be valid PDF, JPG, JPEG, or PNG files no larger than 5 MB each.',
            ]);
        }

        $this->branch_db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$direct_payment_id = $this->_attempt_create()) {
            $this->branch_db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add direct_payment. ",
            ];
        } elseif (!$this->_store_attachments($direct_payment_id, $attachments, false)) {
            $this->branch_db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload or save direct_payment attachments. ' . ($this->error_message ?? ''),
            ];
        } else {
            $this->branch_db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'direct_payment_id' => $direct_payment_id,
                'message'    => 'direct_payment added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a direct_payment
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->direct_payment->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $values['amount'] = Apv_accounting::decimal(Apv_accounting::units($this->payload['amount']));

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$direct_payment_id = $this->direct_payment->insert($values)) {
            $this->error_message = $this->direct_payment->error();
            return false;
        }

        return $direct_payment_id;
    }

    private function _validate_header_amount(bool $required): ?string
    {
        if (array_key_exists('entries', $this->payload)) {
            return 'Accounting entries belong on the accounts payable voucher, not the direct payment.';
        }
        if (!$required && !array_key_exists('amount', $this->payload)) { return null; }
        try {
            if (Apv_accounting::units($this->payload['amount'] ?? 0) <= 0) {
                return 'A positive amount is required.';
            }
        } catch (\InvalidArgumentException $error) {
            return $error->getMessage();
        }
        return null;
    }

    /**
     * Validate optional multipart attachments using the APV upload rules.
     * @return array|false
     */
    private function _get_valid_attachment_files()
    {
        $files = $this->request->getFileMultiple('attachments');
        if ($files === null || $files === false || $files === []) {
            return [];
        }
        if (!is_array($files)) {
            $this->error_message = 'attachments must be uploaded as an array.';
            return false;
        }

        $valid_files = [];
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $allowed_mime_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/x-png'];
        $max_file_size = 5 * 1024 * 1024;

        foreach ($files as $file) {
            if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (!$file->isValid() || $file->hasMoved()) {
                $this->error_message = 'One or more attachments could not be uploaded.';
                return false;
            }
            if (!in_array(strtolower($file->getExtension()), $allowed_extensions, true)) {
                $this->error_message = 'Attachments must be PDF, JPG, JPEG, or PNG files.';
                return false;
            }
            if (!in_array(strtolower((string) $file->getMimeType()), $allowed_mime_types, true)) {
                $this->error_message = 'Attachment contents must be valid PDF, JPG, JPEG, or PNG files.';
                return false;
            }
            if ($file->getSize() > $max_file_size) {
                $this->error_message = 'Each attachment must be no larger than 5 MB.';
                return false;
            }
            $valid_files[] = $file;
        }

        return $valid_files;
    }

    /**
     * Upload to VM4 and save metadata. On update, replace active metadata only
     * after all new files have uploaded and been saved successfully.
     */
    private function _store_attachments($direct_payment_id, array $files, bool $replace_existing): bool
    {
        if (!$files) {
            return true;
        }

        $existing = $replace_existing
            ? ($this->direct_payment_attachment->get_details(null, $direct_payment_id) ?: [])
            : [];
        $upload_batch = date('YmdHis') . '_' . bin2hex(random_bytes(5));
        $path = 'assets/direct_payments/' . (int) $this->payload['cost_center_id'] . '/' . (int) $direct_payment_id . '/' . $upload_batch . '/';

        foreach ($files as $file) {
            $upload = upload_to_vm4($file, $path);
            if (!$upload || empty($upload['success']) || empty($upload['path'])) {
                $this->error_message = 'VM4 upload failed: ' . ($upload['error'] ?? 'unknown error');
                return false;
            }

            $values = [
                'direct_payment_id' => $direct_payment_id,
                'file_name' => $file->getName(),
                'file_path' => $upload['path'],
                'file_url' => rtrim(VM4_ENDPOINT, '/') . '/' . ltrim($upload['path'], '/'),
                'mime' => $file->getMimeType(),
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s'),
            ];

            if (!$this->direct_payment_attachment->insert($values)) {
                $this->error_message = $this->direct_payment_attachment->error();
                return false;
            }
        }

        if ($replace_existing) {
            foreach ($existing as $attachment) {
                if (!$this->direct_payment_attachment->update(
                    ['id' => $attachment['id'], 'is_deleted' => 0],
                    [
                        'is_deleted' => 1,
                        'updated_by' => $this->payload['requester'],
                        'updated_on' => date('Y-m-d H:i:s'),
                    ]
                )) {
                    $this->error_message = $this->direct_payment_attachment->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update direct_payment
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required. Get it from the get API response.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $attachments = $this->_get_valid_attachment_files();
        if ($attachments === false) {
            return $this->_create_response(FAIL, [
                $this->error_message ?: 'Attachments must be valid PDF, JPG, JPEG, or PNG files no larger than 5 MB each.',
            ]);
        }

        $direct_payment_id = $this->payload['direct_payment_id'];
        $where = [
                    'id' => $direct_payment_id, 
                    'is_deleted' => 0
                ];
                
        if (($error = $this->_validate_header_amount(false)) !== null) {
            return $this->_create_response(FAIL, [$error]);
        }

        $this->branch_db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$direct_payment = $this->direct_payment->select('', $where, 1)) {
            $this->branch_db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'direct_payment is not found.'
            ];
        } elseif (($validation_error = $this->_validate_duration($direct_payment)) !== true) {
            $this->branch_db->transRollback();
            $response_status = FAIL;
            $response = [
                $validation_error
            ];
        } elseif (!$this->_attempt_update($direct_payment)) {
            $this->branch_db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update direct_payment.',
            ];
        } elseif (!$this->_store_attachments($direct_payment->id, $attachments, true)) {
            $this->branch_db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to replace direct_payment attachments. ' . ($this->error_message ?? ''),
            ];
        } else {
            $this->branch_db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'direct_payment_id' => $direct_payment_id,
                'message'    => 'direct_payment updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($direct_payment)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->direct_payment->allowedFields is declared at the top of the model
        foreach($this->direct_payment->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }


        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $direct_payment->id];

        if (!$this->direct_payment->update($where, $values)) {
            $this->error_message = $this->direct_payment->error();
            return false;
        }
        return true;
    }

    /**
     * Change status direct_payment
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required. Get it from the get API response.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $status = $this->payload['status'] ?? null;
        $direct_payment_id = $this->payload['direct_payment_id'];
        $where = [
                    'id' => $direct_payment_id, 
                    'is_deleted' => 0
                ];

        $this->branch_db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$direct_payment = $this->direct_payment->select('', $where, 1)) {
            $this->branch_db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'direct_payment is not found.'
            ];
        } elseif ($status && $direct_payment->status == $status) {
            $this->branch_db->transRollback();
            $response_status = FAIL;
            $response = [
                "Direct_payment is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($direct_payment, $user->role_id)) {
            $this->branch_db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change direct_payment status.',
            ];
        } else {
            $this->branch_db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'direct_payment_id' => $direct_payment_id,
                'message'    => "direct_payment $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($direct_payment, $role_id)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'approved') {
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
            $values['status'] = 'approved';
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_reason'] = $this->payload['disapproved_reason'];
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $direct_payment->id];

        if (!$this->direct_payment->update($where, $values)) {
            $this->error_message = $this->direct_payment->error();
            return false;
        }
        return true;
    }

    /**
     * Delete direct_payments
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        if (!$cost_center_id) {
            return $this->_create_response(
                FAIL,
                ['Cost center ID is required. Get it from the get API response.']
            );
        }

        if (!$this->resolve_branch_models($cost_center_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $response_status = SUCCESS;
        $direct_payment_id = $this->payload['direct_payment_id'];

        $where = ['id' => $direct_payment_id, 'is_deleted' => 0];

        $this->branch_db->transBegin();

        if (!$direct_payment = $this->direct_payment->select('', $where, 1)) {
            $this->branch_db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'direct_payment is not found.',
            ];
        } elseif (!$this->_attempt_delete($direct_payment_id)) {
            $this->branch_db->transRollback();
            $response_status = FAIL;
            $response = [
                'direct_payment is not deleted.'
            ];
        } else {
            $this->branch_db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'direct_payment is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($direct_payment_id)
    {
        $where = ['id' => $direct_payment_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->direct_payment->update($where, $values)) {
            $this->error_message = $this->direct_payment->error();
            return false;
        }

        return true;
    }

    /**
     * Validate optional prepaid duration and required accounts when duration is set.
     */
    protected function _validate_duration($existing = null)
    {
        $duration_from = $this->_duration_field('duration_from', $existing);
        $duration_to = $this->_duration_field('duration_to', $existing);

        if ($duration_from === null && $duration_to === null) {
            return true;
        }

        if ($duration_from === null || $duration_to === null) {
            return 'duration_from and duration_to are both required when setting a duration.';
        }

        if (!$this->_is_valid_date($duration_from)) {
            return 'duration_from must be a valid date (Y-m-d).';
        }

        if (!$this->_is_valid_date($duration_to)) {
            return 'duration_to must be a valid date (Y-m-d).';
        }

        if ($duration_from > $duration_to) {
            return 'duration_from must not be after duration_to.';
        }

        $min_to = $this->direct_payment->add_month_clamped($duration_from, 1);
        if ($min_to === null || $duration_to < $min_to) {
            return 'duration_from and duration_to must cover at least one month.';
        }

        return true;
    }

    protected function _duration_field($field, $existing = null)
    {
        if (array_key_exists($field, $this->payload)) {
            return $this->payload[$field] !== '' && $this->payload[$field] !== null
                ? $this->payload[$field]
                : null;
        }

        if ($existing && isset($existing->$field) && $existing->$field !== '' && $existing->$field !== null) {
            return $existing->$field;
        }

        return null;
    }

    protected function _is_valid_date($date)
    {
        $dt = \DateTime::createFromFormat('!Y-m-d', (string) $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }

    /**
    * Check duplicate
    */
    protected function _has_duplicate($table, $where, $id = null)
    {
        $id?($where['id <>'] = $id):null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }
}
