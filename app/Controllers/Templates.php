<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Template;
use App\Models\Template_attachment;

class Templates extends MYT_Controller
{
    protected $request;
    protected $template;
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
        $this->template = new Template();
        $this->template_attachment = new Template_attachment();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get templates
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $template_id = $this->payload['template_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $templates = $this->template->get_details($template_id, $page, $data_size)?:[];
        $size = count($this->template->get_details($template_id, null, null)?:[]);

        if(!$templates) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No templates found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $templates
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create template
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$template_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add template. ",
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'template already exists.';
            }
        // Switch true and false to make the attachment a mandatory field
        } else if(($this->request->getFileMultiple('attachments')?true:false) && !$this->_upload_attachments($template_id, 'assets/templates/')) {
            $this->db->transRollback();
            $response = $this->fail(['response' => 'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.', 'status' => 'error']);
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'template_id' => $template_id,
                'message'    => 'template added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a template
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->template->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$template_id = $this->template->insert($values)) {
            $this->error_message = $this->template->error();
            return false;
        }

        return $template_id;
    }

    /**
     * Update template
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $template_id = $this->payload['template_id'];
        $where = [
                    'id' => $template_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$template = $this->template->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'template is not found.'
            ];
        } elseif (!$this->_attempt_update($template)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update template.',
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        // Attachments is deleted if no file is uploaded
        } elseif(!$this->_upload_attachments($template_id, 'assets/templates/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'template_id' => $template_id,
                'message'    => 'template updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($template)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->template->allowedFields is declared at the top of the model
        foreach($this->template->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $template->id];

        if (!$this->template->update($where, $values)) {
            $this->error_message = $this->template->error();
            return false;
        }
        return true;
    }

    /**
     * Delete templates
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $template_id = $this->payload['template_id'];

        $where = ['id' => $template_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$template = $this->template->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'template is not found.',
            ];
        } elseif (!$this->_attempt_delete($template_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'template is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'template is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($template_id)
    {
        $where = ['id' => $template_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->template->update($where, $values)) {
            $this->error_message = $this->template->error();
            return false;
        }

        return true;
    }

    /**
     * Create multiple templates
     */
    public function create_multiple()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$template_id = $this->_attempt_generate_multiple()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to create templates.'
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'Duplcate error. Make sure unique fields have unique values.';
            }

        } elseif ($template_id === "validation_error") {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = $this->validation_errors;
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'template_id' => $template_id,
                'message'    => 'templates created successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to update multiple with foreign key
     * Update means updating an existing input, Create means inserting a new input, Generate combines the two
     * You can use this function to create items of template
     */
    private function _attempt_generate_multiple($template_id = null, $table = 'template')
    {
        // Count determines the number of name
        $field_name = $this->$table->allowedFields[1];
        $count = (array_key_exists($field_name, $this->payload) && $this->payload[$field_name]) ? count($this->payload[$field_name]) : 0;

        if($count === 0) {
            $this->error_message = ["No " . $table . " added."];
            return false;
        }

        $data = [];
        for($i = 0; $i < $count; $i++) {
            $values = [
                'added_by'              => $this->payload['requester'],
                'added_on'              => date('Y-m-d H:i:s'),
            ];

            foreach($this->$table->allowedFields AS $field) {
                if(!array_key_exists($field, $this->payload)) {
                    continue;
                }

                if($field === 'template_id') {
                    $values[$field] = $this->payload['template_id']?:null;
                } else {
                    $values[$field] = $this->payload[$field][$i]?:null;
                }
            }

            // Manually add/modify the fields here
            // $values['field_name'] => 'value';

            $values[$table . '_id'] = (array_key_exists($table . '_id', $this->payload) && $this->payload[$table . '_id'] && array_key_exists($i, $this->payload[$table . '_id'])) ? $this->payload[$table . '_id'][$i] : null;
            $data[] = $values;
        }

        foreach($data AS $data_val) {
            foreach($this->$table->allowedFields AS $field) {
                if(!array_key_exists($field, $this->payload)) {
                    continue;
                }
                $this->payload[$field] = $data_val[$field];
            }
            $this->payload[$table . '_id'] = $data_val[$table . '_id'];

            if(array_key_exists($table . '_id', $this->payload) && $this->payload[$table . '_id']) {
                if (!$this->_validation_check($table . 's_update')) {
                    return 'validation_error';
                }

                if (!$this->_attempt_update($this->payload[$table . '_id'], $table)) {
                    return false;
                }
            } else {
                if (!$this->_validation_check($table . 's_create')) {
                    return 'validation_error';
                }

                if (!$this->_attempt_create($table)) {
                    return false;
                }
            }

        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_attachments($template_id, $path)
    {
        $files = $this->request->getFileMultiple('attachments');
        $file_path = $path.$template_id;

        $template_attachments = $this->template_attachment->select('', ['template_id' => $template_id, 'is_deleted' => 0]) ?:[];

        if (!empty($files)) {
            $path = $file_path . '/';

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
                write_file($path . 'index.html', 'Directory access is forbidden.');
            }

            foreach ($files as $i => $file) {
                $sourcePath = $file->getPath();
                $destinationPath = $path;
                $unique_name = $unique_name = uniqid() . '_' . time();

                if ($file->isValid() && !in_array($file->getExtension(), ['pdf', 'png', 'jpeg', 'jpg'])) {
                    return false;
                }

                $file_name = $file->getName();
                $max_file_size = 5 * 1024 * 1024; // 5 MB in bytes
            
                // if ($file->getSize() > $max_file_size) {
                //     return false;
                // }

                //var_dump($file->isValid() && !$file->hasMoved()); die();

                if ($file->isValid() && !$file->hasMoved()) {
                    //$file_name = $file->getName();
                    $extension = $file->getExtension();
                    $file_name = $unique_name . '.' . $extension;
                    $mime_type = $file->getMimeType();

                    if(empty($template_attachments)) {
                        $file->move($path, $file_name);
                        $data = [
                            'template_id' => $template_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->template_attachment->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($template_attachments as $attachment) {
                            $attachment = (array)$attachment;
                            $this->template_attachment->update(['id' => $attachment['id']], [
                                'is_deleted' => 1,
                                'updated_by' => $this->payload['requester'],
                                'updated_on' => date('Y-m-d H:i:s')
                            ]);
                            // Delete the existing file from the server
                            if (is_file($attachment['file_path'] . '/' . $attachment['file_name'])) {
                                unlink($attachment['file_path'] . '/' . $attachment['file_name']);
                            }
                        }

                        // then upload the new file
                        $file->move($path, $file_name);
                        $data = [
                            'template_id' => $template_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->template_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
            foreach ($template_attachments as $attachment) {
                $attachment = (array)$attachment;
                $this->template_attachment->update(['id' => $attachment['id']], [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ]);
                // Delete the existing file from the server
                if (is_file($attachment['file_path'] . '/' . $attachment['file_name'])) {
                    unlink($attachment['file_path'] . '/' . $attachment['file_name']);
                }
            }
        }

        return true;
    }

    /**
     * Attempt to create template details
     */
    private function _attempt_create_details($template_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'template_id'    => $template_id,
                'lab_test_id'   => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'is_optional'   => array_key_exists('is_optional', $detail) && $detail['is_optional'] != '' ? $detail['is_optional'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->template_detail->insert($values)) {
                $this->error_message = $this->template_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to update template details
     */
    private function _attempt_update_details($template_id)
    {
        // 1. Get all current detail IDs for this template
        $existing_details = $this->template_detail->select('id', ['template_id' => $template_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_details, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('details', $this->payload) && is_array($this->payload['details'])) {
            foreach ($this->payload['details'] as $detail) {
                if (!empty($detail['id'])) {
                    $payload_ids[] = (int)$detail['id'];
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
                if (!$this->template_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->template_detail->error();
                    return false;
                }
            }
        }

        // 4. Process payload details (update existing or insert new)
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details'])) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'template_id'  => $template_id,
                'lab_test_id' => array_key_exists('lab_test_id', $detail) && $detail['lab_test_id'] != '' ? $detail['lab_test_id'] : null,
                'is_optional' => array_key_exists('is_optional', $detail) && $detail['is_optional'] != '' ? $detail['is_optional'] : 0,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->template_detail->update(['id' => $detail['id']], $values)) {
                    $this->error_message = $this->template_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->template_detail->insert($values)) {
                    $this->error_message = $this->template_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt Upload
     */
    protected function _attempt_upload($patient_id = null, $patient_master_id = null)
    {
        $file = $this->request->getFile('attachment');
        $path = 'assets/patients/' . $patient_master_id . '/';

        $uploaded_url = null;

        if ($file && $file->isValid() && ! $file->hasMoved()) {

            // Collect patient info
            $first  = preg_replace('/[^a-zA-Z0-9]/', '', $this->request->getVar('first_name'));
            $middle = preg_replace('/[^a-zA-Z0-9]/', '', $this->request->getVar('middle_name'));
            $last   = preg_replace('/[^a-zA-Z0-9]/', '', $this->request->getVar('last_name'));

            // Build filename: lastname_firstname_middlename.ext
            $ext      = $file->getExtension();
            $filename = strtolower($last . '_' . $first . '_' . $middle) . date('Ymdhis') . '.' . $ext;

            $fieldsToEncrypt = ['file_url'];

            // Upload file if exists
            $upload_result = $this->_upload_to_vm4($file, 'assets/patients/' . $patient_master_id . '/');
            if ($upload_result && ! empty($upload_result['success'])) {
                $uploaded_url = VM4_ENDPOINT . $upload_result['path'];
            } else {
                $this->_error = 'upload failed: ' . ($upload_result['error'] ?? 'unknown');
                return false;
            }

            $values = [
                'file_url' => $uploaded_url,
            ];

            if (! $this->patient_master->update_encrypt(['id' => $patient_master_id], $values, $fieldsToEncrypt)) {
                return false;
            }

            if (! $this->patient->update_encrypt(['id' => $patient_id], $values, $fieldsToEncrypt)) {
                return false;
            }
        }

        return true;
    }


    /**
     * Upload to VM4
     */
    protected function _upload_to_vm4($file, $path)
    {
        $endpoint = VM4_ENDPOINT . 'upload.php';

        // ----------------------------------------------------
        // 1) QUICK ENDPOINT CHECK (HEAD request)
        // ----------------------------------------------------
        $check = curl_init();
        curl_setopt_array($check, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        curl_exec($check);
        $httpCode = curl_getinfo($check, CURLINFO_HTTP_CODE);
        $error    = curl_errno($check);
        curl_close($check);

        if ($error || $httpCode < 200 || $httpCode >= 500) {
            // log endpoint unreachable
            log_message('error', "VM4 endpoint unreachable or down: HTTP {$httpCode}, cURL Error: {$error}");

            return [
                'success' => false,
                'error'   => 'Endpoint not reachable or down (HTTP ' . $httpCode . ')',
            ];
        }

        // ----------------------------------------------------
        // 2) PERFORM THE FILE UPLOAD (your original code)
        // ----------------------------------------------------
        $curl = curl_init();

        $postData = [
            'file' => new \CURLFile(
                $file->getTempName(),
                $file->getClientMimeType(),
                $file->getName()
            ),
            'path' => $path,
        ];

        curl_setopt_array($curl, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . VM4_SECRET_TOKEN,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response    = curl_exec($curl);
        $uploadError = curl_error($curl);
        curl_close($curl);

        if ($uploadError) {
            // KEEP YOUR LOG MESSAGE
            log_message('error', 'CURL upload error: ' . $uploadError);

            return [
                'success' => false,
                'error'   => $uploadError,
            ];
        }

        return json_decode($response, true);
    }

    /**
     * Normalize column.
     */
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

    /**
    * Check duplicate
    */
    protected function _has_duplicate($table, $where, $id = null)
    {
        $id?($where['id <>'] = $id):null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }

    public function custom_validations($validation_name) {
        $is_valid = true;
        switch($validation_name) {
            case 'create' || 'update':
                $key = 'template_id';
                $id = array_key_exists($key, $this->payload)?$this->payload[$key]:null;

                // Put your custom validations here
                // If that validation can be applied to other modules, add it to MYT Custom Rules
        }
        return $is_valid;
    }
}
