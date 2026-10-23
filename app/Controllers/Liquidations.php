<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Supplier;

class Liquidations extends MYT_Controller
{
    use \App\Core\Fund_routing;
    protected $request;
    protected $liquidation;
    protected $liquidation_detail;
    protected $liquidation_attachment;
    protected $cash_advance;
    protected $accounts_payable_voucher;
    protected $user;
    protected $supplier;
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
        $this->supplier = new Supplier();
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

        $this->liquidation = new \App\Models\Liquidation($db);
        $this->liquidation_detail = new \App\Models\Liquidation_detail($db);
        $this->liquidation_attachment = new \App\Models\Liquidation_attachment($db);
        $this->cash_advance = new \App\Models\Cash_advance($db);
        $this->accounts_payable_voucher = new \App\Models\Accounts_payable_voucher($db);

        if ($group === 'master') {
            $this->db = $db; // Transaction and scoped models must use the same connection.
            foreach (['liquidation', 'liquidation_detail', 'liquidation_attachment', 'cash_advance', 'accounts_payable_voucher'] as $property) {
                if (method_exists($this->$property, 'with_shared_cost_centers')) {
                    $this->$property->with_shared_cost_centers($branch_id);
                }
            }
        }
        return true;
    }

    /**
     * Get liquidations
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

        $liquidation_id = $this->payload['liquidation_id'] ?? null;
        $department_id = $this->payload['department_id'] ?? null;
        $cash_advance_id = $this->payload['cash_advance_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $liquidations = $this->liquidation->get_details($liquidation_id, $department_id, $cash_advance_id, $status, $term, $page, $data_size)?:[];
        $size = count($this->liquidation->get_details($liquidation_id, $department_id, $cash_advance_id, $status, $term, null, null)?:[]);

        if(!$liquidations) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No liquidations found.'
            ];
        } else {

            //append details to each liquidation
            foreach ($liquidations as $key => $liquidation) {
                $liquidated_user = $this->user->select('id, first_name, last_name', ['id' => $liquidation['liquidated_by_id'], 'is_deleted' => 0], 1);

                $liquidations[$key]['liquidated_by_name'] = $liquidated_user ? $liquidated_user->first_name . ' ' . $liquidated_user->last_name : null;
                $liquidations[$key]['details'] = $this->liquidation_detail->get_details($liquidation['id'])?:[];
                if (!empty($liquidations[$key]['details'])) {
                    foreach ($liquidations[$key]['details'] as $detail_key => $detail) {
                        if (!empty($detail['supplier_id'])) {
                            $supplier = $this->supplier->select('id, name', ['id' => $detail['supplier_id'], 'is_deleted' => 0], 1);
                            $liquidations[$key]['details'][$detail_key]['supplier_name'] = $supplier ? $supplier->name : null;
                        } else {
                            $liquidations[$key]['details'][$detail_key]['supplier_name'] = null;
                        }
                    }
                }
                $liquidations[$key]['attachments'] = $this->liquidation_attachment->select('id, file_name, file_path, file_url, mime', ['liquidation_id' => $liquidation['id'], 'is_deleted' => 0])?:[];

                $excess_apv = $this->accounts_payable_voucher->get_details(null, 'excess', $liquidation['id'])?:[];
                $liquidations[$key]['excess_apv'] = $excess_apv;

                $liquidation_apv = $this->accounts_payable_voucher->get_details(null, 'liquidation', $liquidation['id'])?:[];
                $liquidations[$key]['liquidation_apv'] = $liquidation_apv;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $liquidations
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create liquidation
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

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$liquidation_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add liquidation. ",
            ];
        } elseif (!$this->_attempt_create_details($liquidation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add liquidation details. ",
            ];
        // Switch true and false to make the attachment a mandatory field
        } elseif(($this->request->getFileMultiple('attachments')?true:false) && !$this->_upload_attachments($liquidation_id, 'assets/liquidations/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'liquidation_id' => $liquidation_id,
                'message'    => 'liquidation added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a liquidation
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->liquidation->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$liquidation_id = $this->liquidation->insert($values)) {
            $this->error_message = $this->liquidation->error();
            return false;
        }

        return $liquidation_id;
    }

    /**
     * Attempt to create liquidation details
     */
    private function _attempt_create_details($liquidation_id)
    {
        if (!array_key_exists('details', $this->payload) || !is_array($this->payload['details']) || count($this->payload['details']) == 0) {
            return true;
        }

        foreach ($this->payload['details'] as $detail) {
            $values = [
                'liquidation_id'    => $liquidation_id,
                'invoice_no' => array_key_exists('invoice_no', $detail) && $detail['invoice_no'] != '' ? $detail['invoice_no'] : null,
                'expense_date'   => array_key_exists('expense_date', $detail) && $detail['expense_date'] != '' ? $detail['expense_date'] : null,
                'description'   => array_key_exists('description', $detail) && $detail['description'] != '' ? $detail['description'] : null,
                'account_type_id'   => array_key_exists('account_type_id', $detail) && $detail['account_type_id'] != '' ? $detail['account_type_id'] : null,
                'amount'   => array_key_exists('amount', $detail) && $detail['amount'] != '' ? $detail['amount'] : null,
                'supplier_id'   => array_key_exists('supplier_id', $detail) && $detail['supplier_id'] != '' ? $detail['supplier_id'] : null,
                'tin'   => array_key_exists('tin', $detail) && $detail['tin'] != '' ? $detail['tin'] : null,
                'is_vat'   => array_key_exists('is_vat', $detail) && $detail['is_vat'] != '' ? $detail['is_vat'] : 0,
                'address'   => array_key_exists('address', $detail) && $detail['address'] != '' ? $detail['address'] : null,
                'vat'   => array_key_exists('vat', $detail) && $detail['vat'] != '' ? $detail['vat'] : null,
                'total'   => array_key_exists('total', $detail) && $detail['total'] != '' ? $detail['total'] : null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->liquidation_detail->insert($values)) {
                $this->error_message = $this->liquidation_detail->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update liquidation
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

        $liquidation_id = $this->payload['liquidation_id'];
        $where = [
                    'id' => $liquidation_id, 
                    'is_deleted' => 0
                ];
                
        $type = $this->payload['type'] ?? null;

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$liquidation = $this->liquidation->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'liquidation is not found.'
            ];
        } elseif (!$this->_attempt_update($liquidation)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update liquidation.',
            ];
        } elseif (!$this->_attempt_update_details($liquidation->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update liquidation details. ",
            ];
        // Attachments is deleted if no file is uploaded
        } elseif(!$this->_upload_attachments($liquidation_id, 'assets/liquidations/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } elseif (!$this->_attempt_update_cash_advance($liquidation->status, $liquidation->cash_advance_id, $liquidation->total)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'insufficient cash advance balance.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'liquidation_id' => $liquidation_id,
                'message'    => 'liquidation updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($liquidation)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->liquidation->allowedFields is declared at the top of the model
        foreach($this->liquidation->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $liquidation->id];

        if (!$this->liquidation->update($where, $values)) {
            $this->error_message = $this->liquidation->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update liquidation details
     */
    private function _attempt_update_details($liquidation_id)
    {
        // 1. Get all current detail IDs for this liquidation
        $existing_details = $this->liquidation_detail->select('id', ['liquidation_id' => $liquidation_id, 'is_deleted' => 0]);

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
                if (!$this->liquidation_detail->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->liquidation_detail->error();
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
                'liquidation_id'  => $liquidation_id,
                'invoice_no' => array_key_exists('invoice_no', $detail) && $detail['invoice_no'] != '' ? $detail['invoice_no'] : null,
                'expense_date'   => array_key_exists('expense_date', $detail) && $detail['expense_date'] != '' ? $detail['expense_date'] : null,
                'description'   => array_key_exists('description', $detail) && $detail['description'] != '' ? $detail['description'] : null,
                'account_type_id'   => array_key_exists('account_type_id', $detail) && $detail['account_type_id'] != '' ? $detail['account_type_id'] : null,
                'amount'   => array_key_exists('amount', $detail) && $detail['amount'] != '' ? $detail['amount'] : null,
                'supplier_id'   => array_key_exists('supplier_id', $detail) && $detail['supplier_id'] != '' ? $detail['supplier_id'] : null,
                'tin'   => array_key_exists('tin', $detail) && $detail['tin'] != '' ? $detail['tin'] : null,
                'is_vat'   => array_key_exists('is_vat', $detail) && $detail['is_vat'] != '' ? $detail['is_vat'] : 0,
                'address'   => array_key_exists('address', $detail) && $detail['address'] != '' ? $detail['address'] : null,
                'vat'   => array_key_exists('vat', $detail) && $detail['vat'] != '' ? $detail['vat'] : null,
                'total'   => array_key_exists('total', $detail) && $detail['total'] != '' ? $detail['total'] : null,
            ];

            if (!empty($detail['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->liquidation_detail->update(['id' => $detail['id']], $values)) {
                    $this->error_message = $this->liquidation_detail->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->liquidation_detail->insert($values)) {
                    $this->error_message = $this->liquidation_detail->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status liquidation
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
        $liquidation_id = $this->payload['liquidation_id'];
        $where = [
                    'id' => $liquidation_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$liquidation = $this->liquidation->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'liquidation is not found.'
            ];
        } elseif ($status && $liquidation->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Liquidation is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($liquidation, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change liquidation status.',
            ];
        } elseif (!$this->_attempt_update_cash_advance($status, $liquidation->cash_advance_id, $liquidation->total)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'insufficient cash advance balance.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'liquidation_id' => $liquidation_id,
                'message'    => "liquidation $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($liquidation, $role_id)
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

        $where = ['id' => $liquidation->id];

        if (!$this->liquidation->update($where, $values)) {
            $this->error_message = $this->liquidation->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt update cash advance
     */
    protected function _attempt_update_cash_advance($status, $cash_advance_id, $total) {
        if ($status == 'approved') {
            $cash_advance = $this->cash_advance->select('balance', ['id' => $cash_advance_id, 'is_deleted' => 0], 1);
            if (!$cash_advance) {
                return false;
            }

            $balance = $cash_advance->balance - $total;
            // if ($balance < 0) {
            //     return false;
            // }

            $values = [
                'balance' => $balance,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s')
            ];
            if (!$this->cash_advance->update(['id' => $cash_advance_id], $values)) {
                $this->error_message = $this->cash_advance->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Delete liquidations
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
        $liquidation_id = $this->payload['liquidation_id'];

        $where = ['id' => $liquidation_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$liquidation = $this->liquidation->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'liquidation is not found.',
            ];
        } elseif (!$this->_attempt_delete($liquidation_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'liquidation is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'liquidation is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($liquidation_id)
    {
        $where = ['id' => $liquidation_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->liquidation->update($where, $values)) {
            $this->error_message = $this->liquidation->error();
            return false;
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_attachments($liquidation_id, $path)
    {
        $files = $this->request->getFileMultiple('attachments');
        $file_path = $path.$liquidation_id;

        $liquidation_attachments = $this->liquidation_attachment->select('', ['liquidation_id' => $liquidation_id, 'is_deleted' => 0]) ?:[];

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

                    if(empty($liquidation_attachments)) {
                        $file->move($path, $file_name);
                        $data = [
                            'liquidation_id' => $liquidation_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->liquidation_attachment->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($liquidation_attachments as $attachment) {
                            $attachment = (array)$attachment;
                            $this->liquidation_attachment->update(['id' => $attachment['id']], [
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
                            'liquidation_id' => $liquidation_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->liquidation_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
            if (!empty($liquidation_attachments)) {
                foreach ($liquidation_attachments as $attachment) {
                    $attachment = (array)$attachment;
                    $this->liquidation_attachment->update(['id' => $attachment['id']], [
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
}
