<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Company;
use App\Models\Company_package;
use App\Models\Customer_account;
use App\Models\Company_attachment;
use App\Models\Company_validity_date;
use App\Models\User_portal;

class Companies extends MYT_Controller
{
    protected $request;
    protected $company;
    protected $company_package;
    protected $customer_account;
    protected $company_attachment;
    protected $company_validity_date;
    protected $user_portal;
    protected $helpers = ['text'];
    private array $companyWriteError = [];

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
        $this->company = new Company();
        $this->company_package = new Company_package();
        $this->customer_account = new Customer_account();
        $this->company_attachment = new Company_attachment();
        $this->company_validity_date = new Company_validity_date();
        $this->user_portal = new User_portal();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get companies
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $company_id = $this->payload['company_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        $business_type = $this->payload['business_type'] ?? null;
        $accreditation_status = $this->payload['accreditation_status'] ?? null;
        $account_status = $this->payload['account_status'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $companies = $this->company->get_details($company_id, $type, $business_type, $accreditation_status, $account_status, $term, $page, $data_size)?:[];

        if(!$companies) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No companies found.'
            ];
        } else {
            foreach ($companies as $index => $company) {
                // Get company attachments
                $attachments = $this->company_attachment->select('', ['company_id' => $company['id'], 'is_deleted' => 0]) ?:[];
                $companies[$index]['attachments'] = $attachments;

                // Get company validity date
                $validity_dates = $this->company_validity_date->get_details($company['id']) ?:[];
                $companies[$index]['validity_dates'] = $validity_dates;

                // Get user portal
                $user_portal = $this->user_portal->select('', ['company_id' => $company['id'], 'is_deleted' => 0]) ?:[];
                $companies[$index]['user_portal'] = $user_portal;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->company->get_details($company_id, $type, $business_type, $accreditation_status, $account_status, $term, null, null)?:[]),
                'data' => $companies
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get customer_accounts
     */
    public function get_customer_account()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $company_id = $this->payload['company_id'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $customer_accounts = $this->customer_account->get_customer_account($company_id, $page, $data_size)?:[];

        if(!$customer_accounts) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No customer_account found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->customer_account->get_customer_account($company_id, null, null)?:[]),
                'data' => $customer_accounts
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get banks
     */
    public function get_bank()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $bank_id = $this->payload['bank_id'] ?? null;

        $banks = $this->company->get_bank($bank_id)?:[];

        if(!$banks) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No banks found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'data' => $banks
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create company
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$company_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;

            if ($this->_company_name_conflict()) {
                $response = ["Company name already exists."];
            } else {
                $response = ["Failed to add company. "];
            }
        } elseif (!$this->_attempt_create_customer_account($company_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add customer account. ",
            ];
        // Switch true and false to make the attachment a mandatory field
        } elseif(($this->request->getFileMultiple('attachments')?true:false) && !$this->_upload_attachments($company_id, 'assets/companies/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'company_id' => $company_id,
                'message'    => 'company added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a company
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->company->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        $year = date('Y');
        $companies = $this->company->get_existing_by_year($year) ?:[];
        $values['company_no'] = $year.str_pad(count($companies)+1, 5, '0', STR_PAD_LEFT);
        $values['registered_date'] = date('Y-m-d');

        return $this->_attempt_company_write(fn () => $this->company->insert($values));
    }

    /**
     * Attempt to create customer account
     */
    private function _attempt_create_customer_account($company_id)
    {
        $values = [
            'company_id'    => $company_id,
            'name'          => $this->payload['name'],
            'added_by'      => $this->payload['requester'],
            'added_on'      => date('Y-m-d H:i:s'),
        ];

        if (!$this->customer_account->insert($values)) {
            $this->error_message = $this->customer_account->error();
            return false;
        }

        return true;
    }

    /**
     * Attempt to create company packages
     */
    private function _attempt_create_packages($company_id)
    {
        if (!array_key_exists('packages', $this->payload) || !is_array($this->payload['packages']) || count($this->payload['packages']) == 0) {
            return true;
        }

        foreach ($this->payload['packages'] as $package) {
            $values = [
                'company_id'    => $company_id,
                'package_id'   => array_key_exists('package_id', $package) && $package['package_id'] != '' ? $package['package_id'] : null,
                'price'   => array_key_exists('price', $package) && $package['price'] != '' ? $package['price'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->company_package->insert($values)) {
                $this->error_message = $this->company_package->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update company
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $company_id = $this->payload['company_id'];
        $where = [
                    'id' => $company_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$company = $this->company->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'company is not found.'
            ];
        } elseif (!$this->_attempt_update($company)) {
            $this->db->transRollback();
            $response_status = FAIL;

            if ($this->_company_name_conflict()) {
                $response['Company name already exists.'];
            } else {
                $response = ['Failed to update company.'];
            }
        } elseif (!$this->_attempt_update_customer_account($company->id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update company customer account. ",
            ];
        // Attachments is deleted if no file is uploaded
        } elseif(!$this->_upload_attachments($company_id, 'assets/companies/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'company_id' => $company_id,
                'message'    => 'company updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to update customer account
     */
    private function _attempt_update_customer_account($company_id)
    {
        $where = ['company_id' => $company_id, 'is_deleted' => 0];

        if (!$customer_account = $this->customer_account->select('', $where, 1)) {
            return $this->_attempt_create_customer_account($company_id);
        }

        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('name', $this->payload)) {
            $values['name'] = $this->payload['name'] != '' ? $this->payload['name'] : null;
        }

        if (!$this->customer_account->update(['id' => $customer_account->id], $values)) {
            $this->error_message = $this->customer_account->error();
            return false;
        }

        return true;
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($company)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->company->allowedFields is declared at the top of the model
        foreach($this->company->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $company->id];

        return (bool) $this->_attempt_company_write(fn () => $this->company->update($where, $values));
    }

    /** Capture the model connection's error before the caller rolls back. */
    private function _attempt_company_write(callable $write)
    {
        $this->companyWriteError = [];
        $exception = null;
        try {
            $result = $write();
            if ($result) {
                return $result;
            }
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException | \mysqli_sql_exception $error) {
            $exception = $error;
        }

        $this->companyWriteError = $this->company->db_error();
        if ($exception !== null) {
            if (empty($this->companyWriteError['code'])) {
                $this->companyWriteError['code'] = ($exception->getPrevious() ?? $exception)->getCode();
            }
            if (empty($this->companyWriteError['message'])) {
                $this->companyWriteError['message'] = $exception->getMessage();
            }
        }
        $this->error_message = (string) ($this->companyWriteError['message'] ?? '');
        return false;
    }

    private function _company_name_conflict(): bool
    {
        return (int) ($this->companyWriteError['code'] ?? 0) === 1062
            && preg_match('/\bfor key\s+[\'"`](?:[^\'"`]+\.)?name[\'"`]/i',
                (string) ($this->companyWriteError['message'] ?? '')) === 1;
    }

    /**
     * Attempt to update company packages
     */
    private function _attempt_update_packages($company_id)
    {
        // 1. Get all current package IDs for this company
        $existing_packages = $this->company_package->select('id', ['company_id' => $company_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_packages, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('packages', $this->payload) && is_array($this->payload['packages'])) {
            foreach ($this->payload['packages'] as $package) {
                if (!empty($package['id'])) {
                    $payload_ids[] = (int)$package['id'];
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
                if (!$this->company_package->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->company_package->error();
                    return false;
                }
            }
        }

        // 4. Process payload packages (update existing or insert new)
        if (!array_key_exists('packages', $this->payload) || !is_array($this->payload['packages'])) {
            return true;
        }

        foreach ($this->payload['packages'] as $package) {
            $values = [
                'company_id'  => $company_id,
                'package_id'   => array_key_exists('package_id', $package) && $package['package_id'] != '' ? $package['package_id'] : null,
                'price'   => array_key_exists('price', $package) && $package['price'] != '' ? $package['price'] : 0,
            ];

            if (!empty($package['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->company_package->update(['id' => $package['id']], $values)) {
                    $this->error_message = $this->company_package->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->company_package->insert($values)) {
                    $this->error_message = $this->company_package->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Delete companies
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $company_id = $this->payload['company_id'];

        $where = ['id' => $company_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$company = $this->company->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'company is not found.',
            ];
        } elseif (!$this->_attempt_delete($company_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'company is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'company is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($company_id)
    {
        $where = ['id' => $company_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->company->update($where, $values)) {
            $this->error_message = $this->company->error();
            return false;
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_attachments($company_id, $path)
    {
        $files = $this->request->getFileMultiple('attachments');
        $file_path = $path.$company_id;

        $company_attachments = $this->company_attachment->select('', ['company_id' => $company_id, 'is_deleted' => 0]) ?: [];

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

                if ($file->isValid() && !in_array($file->getExtension(), ['pdf', 'png', 'jpeg', 'jpg', 'xls', 'xlsx', 'doc', 'docx'])) {
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

                    if(empty($company_attachments)) {
                        $file->move($path, $file_name);
                        $data = [
                            'company_id' => $company_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->company_attachment->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($company_attachments as $attachment) {
                            $attachment = (array)$attachment;
                            $this->company_attachment->update(['id' => $attachment['id']], [
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
                            'company_id' => $company_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->company_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1
            if (!empty($company_attachments)) {
                foreach ($company_attachments as $attachment) {
                    $attachment = (array)$attachment;
                    $this->company_attachment->update(['id' => $attachment['id']], [
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
