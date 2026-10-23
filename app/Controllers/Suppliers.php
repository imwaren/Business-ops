<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Supplier;
use App\Models\Supplier_machine;
use App\Models\Supplier_reference;
use App\Models\Supplier_representative;
use App\Models\Supplier_representative_attachment;
use App\Models\Supplier_product;
use App\Models\Supplier_service;
use App\Models\Supplier_contact;
use App\Models\Supplier_bank;
use App\Models\Supplier_attachment;
use App\Models\User;

class Suppliers extends MYT_Controller
{
    protected $request;
    protected $supplier;
    protected $supplier_machine;
    protected $supplier_reference;
    protected $supplier_representative;
    protected $supplier_representative_attachment;
    protected $supplier_product;
    protected $supplier_service;
    protected $supplier_contact;
    protected $supplier_bank;
    protected $supplier_attachment;
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
        $this->supplier = new Supplier();
        $this->supplier_machine = new Supplier_machine();
        $this->supplier_reference = new Supplier_reference();
        $this->supplier_representative = new Supplier_representative();
        $this->supplier_representative_attachment = new Supplier_representative_attachment();
        $this->supplier_product = new Supplier_product();
        $this->supplier_service = new Supplier_service();
        $this->supplier_contact = new Supplier_contact();
        $this->supplier_bank = new Supplier_bank();
        $this->supplier_attachment = new Supplier_attachment();
        $this->user = new User();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get suppliers
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $supplier_id = $this->payload['supplier_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $is_other_supplier = $this->payload['is_other_supplier'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $screen = $this->payload['screen'] ?? null;

        if ($screen && $screen == 'apv') {
            $suppliers = $this->supplier->get_suppliers($supplier_id, $status, $is_other_supplier, $term)?:[];
            $payors = $this->user->get_payors(null, null, null, $term)?:[];

            $suppliers = array_merge($suppliers, $payors);
            if(!$suppliers) {
                $response_status = FAIL_NOT_FOUND;
                $response = [
                    'No suppliers found.'
                ];
            } else {
                $response_status = SUCCESS;
                $response = [
                    'data' => $suppliers
                ];
            }
        } else {
            $suppliers = $this->supplier->get_details($supplier_id, $status, $is_other_supplier, $term, $page, $data_size)?:[];

            if(!$suppliers) {
                $response_status = FAIL_NOT_FOUND;
                $response = [
                    'No suppliers found.'
                ];
            } else {
                foreach ($suppliers as $key => $supplier) {
                    $supplier_machines = $this->supplier_machine->get_details(null, $supplier['id'], null, null)?:[];
                    $supplier_references = $this->supplier_reference->get_details(null, $supplier['id'], null, null)?:[];
                    $supplier_representatives = $this->supplier_representative->get_details(null, $supplier['id'], null, null)?:[];
                    if ($supplier_representatives) {
                        foreach ($supplier_representatives as $rep_key => $supplier_representative) {
                            $supplier_representative_attachments = $this->supplier_representative_attachment->select('id, file_name, file_path, file_url, mime', ['supplier_representative_id' => $supplier_representative['id'], 'is_deleted' => 0], 1) ?: null;
                            $supplier_representatives[$rep_key]['signature'] = $supplier_representative_attachments;
                        }
                    }
                    $supplier_products = $this->supplier_product->get_details(null, $supplier['id'], null, null)?:[];
                    $supplier_services = $this->supplier_service->get_details(null, $supplier['id'], null, null)?:[];
                    $supplier_contacts = $this->supplier_contact->get_details(null, $supplier['id'], null, null)?:[];
                    $supplier_banks = $this->supplier_bank->get_details(null, $supplier['id'], null, null)?:[];

                    $suppliers[$key]['machines'] = $supplier_machines;
                    $suppliers[$key]['references'] = $supplier_references;
                    $suppliers[$key]['representatives'] = $supplier_representatives;
                    $suppliers[$key]['products'] = $supplier_products;
                    $suppliers[$key]['services'] = $supplier_services;
                    $suppliers[$key]['contacts'] = $supplier_contacts;
                    $suppliers[$key]['banks'] = $supplier_banks;
                    $suppliers[$key]['attachments'] = $this->supplier_attachment->select('id, file_name, file_path, file_url, mime', ['supplier_id' => $supplier['id'], 'is_deleted' => 0])?:[];
                }
                $response_status = SUCCESS;
                $response = [
                    'size' => count($this->supplier->get_details($supplier_id, $status, $is_other_supplier, $term, null, null)?:[]),
                    'data' => $suppliers
                ];
            }
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create supplier
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $tin = $this->payload['tin'] ?? null;

        if ($tin && $this->_has_duplicate('supplier', ['tin' => $tin, 'is_deleted' => 0])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Supplier with TIN $tin already exists. ",
            ];
        } elseif (!$supplier_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add supplier. ",
            ];
        // Switch true and false to make the attachment a mandatory field
        } elseif(($this->request->getFileMultiple('attachments')?true:false) && !$this->_upload_attachments($supplier_id, 'assets/suppliers/')) {
            $this->db->transRollback();
            $response = $this->fail(['response' => 'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.', 'status' => 'error']);
        } elseif (!$this->_attempt_create_machines($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add supplier machines. ",
            ];
        } elseif (!$this->_attempt_create_references($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add supplier references. ",
            ];
        } elseif (!$this->_attempt_create_representatives($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add supplier representatives. ",
            ];
        } elseif (!$this->_attempt_create_products($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add supplier products. ",
            ];
        } elseif (!$this->_attempt_create_services($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add supplier services. ",
            ];
        } elseif (!$this->_attempt_create_contacts($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add supplier contacts. ",
            ];
        } elseif (!$this->_attempt_create_banks($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add supplier banks. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'supplier_id' => $supplier_id,
                'message'    => 'supplier added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a supplier
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->supplier->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$supplier_id = $this->supplier->insert($values)) {
            $this->error_message = $this->supplier->error();
            return false;
        }

        return $supplier_id;
    }

    /**
     * Attempt to create a machines
     */
    private function _attempt_create_machines($supplier_id)
    {
        if (!array_key_exists('machines', $this->payload) || !is_array($this->payload['machines']) || count($this->payload['machines']) == 0) {
            return true;
        }

        foreach ($this->payload['machines'] as $machine) {
            $values = [
                'supplier_id'   => $supplier_id,
                'type'     => $machine['type'] ?? null,
                'description'     => $machine['description'] ?? null,
                'machinery'     => $machine['machinery'] ?? null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->supplier_machine->insert($values)) {
                $this->error_message = $this->supplier_machine->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to create a references
     */
    private function _attempt_create_references($supplier_id)
    {
        if (!array_key_exists('references', $this->payload) || !is_array($this->payload['references']) || count($this->payload['references']) == 0) {
            return true;
        }

        foreach ($this->payload['references'] as $reference) {
            $values = [
                'supplier_id'   => $supplier_id,
                'name'          => $reference['name'] ?? null,
                'address'       => $reference['address'] ?? null,
                'contact'       => $reference['contact'] ?? null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->supplier_reference->insert($values)) {
                $this->error_message = $this->supplier_reference->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to create a representatives
     */
    private function _attempt_create_representatives($supplier_id)
    {
        // Get uploaded files
        $files = $this->request->getFiles();

        if (!array_key_exists('representatives', $this->payload) || !is_array($this->payload['representatives']) || count($this->payload['representatives']) == 0) {
            return true;
        }

        foreach ($this->payload['representatives'] as $index => $representative) {
            $values = [
                'supplier_id'       => $supplier_id,
                'transaction_name'  => $representative['transaction_name'] ?? null,
                'employee'          => $representative['employee'] ?? null,
                'position'          => $representative['position'] ?? null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$supplier_representative_id = $this->supplier_representative->insert($values)) {
                $this->error_message = $this->supplier_representative->error();
                return false;
            // Switch true and false to make the attachment a mandatory field
            } elseif($files && array_key_exists('representatives', $files) && array_key_exists($index, $files['representatives']) &&
                ($files['representatives'][$index]['signature']?true:false) && !$this->_upload_supplier_representative_attachment($supplier_representative_id, $files['representatives'][$index]['signature'], 'assets/supplier_representatives/')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to create a products
     */
    private function _attempt_create_products($supplier_id)
    {
        if (!array_key_exists('products', $this->payload) || !is_array($this->payload['products']) || count($this->payload['products']) == 0) {
            return true;
        }

        foreach ($this->payload['products'] as $product) {
            $values = [
                'supplier_id'   => $supplier_id,
                'product_lines_carried'          => $product['product_lines_carried'] ?? null,
                'brand'       => $product['brand'] ?? null,
                'products_exclusively_carried'       => $product['products_exclusively_carried'] ?? null,
                'brand_principal'       => $product['brand_principal'] ?? null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->supplier_product->insert($values)) {
                $this->error_message = $this->supplier_product->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to create a services
     */
    private function _attempt_create_services($supplier_id)
    {
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services']) || count($this->payload['services']) == 0) {
            return true;
        }

        foreach ($this->payload['services'] as $service) {
            $values = [
                'supplier_id'   => $supplier_id,
                'services_offered'          => $service['services_offered'] ?? null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->supplier_service->insert($values)) {
                $this->error_message = $this->supplier_service->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to create a contacts
     */
    private function _attempt_create_contacts($supplier_id)
    {
        if (!array_key_exists('contacts', $this->payload) || !is_array($this->payload['contacts']) || count($this->payload['contacts']) == 0) {
            return true;
        }

        foreach ($this->payload['contacts'] as $contact) {
            $values = [
                'supplier_id'   => $supplier_id,
                'contact_person'          => $contact['contact_person'] ?? null,
                'contact_no'          => $contact['contact_no'] ?? null,
                'email'          => $contact['email'] ?? null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->supplier_contact->insert($values)) {
                $this->error_message = $this->supplier_contact->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to create a banks
     */
    private function _attempt_create_banks($supplier_id)
    {
        if (!array_key_exists('banks', $this->payload) || !is_array($this->payload['banks']) || count($this->payload['banks']) == 0) {
            return true;
        }

        foreach ($this->payload['banks'] as $bank) {
            $values = [
                'supplier_id'   => $supplier_id,
                'name'          => $bank['name'] ?? null,
                'bank_branch'          => $bank['bank_branch'] ?? null,
                'account_name'          => $bank['account_name'] ?? null,
                'account_no'          => $bank['account_no'] ?? null,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->supplier_bank->insert($values)) {
                $this->error_message = $this->supplier_bank->error();
                return false;
            }
        }
        return true;
    }

    /**
     * Update supplier
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $supplier_id = $this->payload['supplier_id'];
        $where = [
                    'id' => $supplier_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $tin = $this->payload['tin'] ?? null;

        if ($tin && $this->_has_duplicate('supplier', ['tin' => $tin, 'is_deleted' => 0], $supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Supplier with TIN $tin already exists. ",
            ];
        } elseif (!$supplier = $this->supplier->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'supplier is not found.'
            ];
        } elseif (!$this->_attempt_update($supplier)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update supplier.',
            ];
        // Attachments is deleted if no file is uploaded
        } elseif(!$this->_upload_attachments($supplier_id, 'assets/suppliers/')) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to upload attachments. Make sure you have the correct file type, and file does not exceed 5 megabytes.'
            ];
        } elseif (!$this->_attempt_update_machines($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update supplier machines. ",
            ];
        } elseif (!$this->_attempt_update_references($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update supplier references. ",
            ];
        } elseif (!$this->_attempt_update_representatives($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update supplier representatives. ",
            ];
        } elseif (!$this->_attempt_update_products($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update supplier products. ",
            ];
        } elseif (!$this->_attempt_update_services($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update supplier services. ",
            ];
        } elseif (!$this->_attempt_update_contacts($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update supplier contacts. ",
            ];
        } elseif (!$this->_attempt_update_banks($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update supplier banks. ",
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'supplier_id' => $supplier_id,
                'message'    => 'supplier updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($supplier)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->supplier->allowedFields is declared at the top of the model
        foreach($this->supplier->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $supplier->id];

        if (!$this->supplier->update($where, $values)) {
            $this->error_message = $this->supplier->error();
            return false;
        }
        return true;
    }

    /**
     * Attempt to update supplier machines
     */
    private function _attempt_update_machines($supplier_id)
    {
        // 1. Get all current machine IDs for this supplier
        $existing_machines = $this->supplier_machine->select('id', ['supplier_id' => $supplier_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_machines, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('machines', $this->payload) && is_array($this->payload['machines'])) {
            foreach ($this->payload['machines'] as $machine) {
                if (!empty($machine['id'])) {
                    $payload_ids[] = (int)$machine['id'];
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
                if (!$this->supplier_machine->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->supplier_machine->error();
                    return false;
                }
            }
        }

        // 4. Process payload machines (update existing or insert new)
        if (!array_key_exists('machines', $this->payload) || !is_array($this->payload['machines'])) {
            return true;
        }

        foreach ($this->payload['machines'] as $machine) {
            $values = [
                'supplier_id'  => $supplier_id,
                'type'     => $machine['type'] ?? null,
                'description'     => $machine['description'] ?? null,
                'machinery'     => $machine['machinery'] ?? null,
            ];

            if (!empty($machine['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_machine->update(['id' => $machine['id']], $values)) {
                    $this->error_message = $this->supplier_machine->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_machine->insert($values)) {
                    $this->error_message = $this->supplier_machine->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update supplier references
     */
    private function _attempt_update_references($supplier_id)
    {
        // 1. Get all current reference IDs for this supplier
        $existing_references = $this->supplier_reference->select('id', ['supplier_id' => $supplier_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_references, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('references', $this->payload) && is_array($this->payload['references'])) {
            foreach ($this->payload['references'] as $reference) {
                if (!empty($reference['id'])) {
                    $payload_ids[] = (int)$reference['id'];
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
                if (!$this->supplier_reference->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->supplier_reference->error();
                    return false;
                }
            }
        }

        // 4. Process payload references (update existing or insert new)
        if (!array_key_exists('references', $this->payload) || !is_array($this->payload['references'])) {
            return true;
        }

        foreach ($this->payload['references'] as $reference) {
            $values = [
                'supplier_id'   => $supplier_id,
                'name'          => $reference['name'] ?? null,
                'address'       => $reference['address'] ?? null,
                'contact'       => $reference['contact'] ?? null,
            ];

            if (!empty($reference['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_reference->update(['id' => $reference['id']], $values)) {
                    $this->error_message = $this->supplier_reference->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_reference->insert($values)) {
                    $this->error_message = $this->supplier_reference->error();
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Attempt to update supplier representatives
     */
    private function _attempt_update_representatives($supplier_id)
    {
        // 1. Get all current representative IDs for this supplier
        $existing_representatives = $this->supplier_representative->select('id', ['supplier_id' => $supplier_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_representatives, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('representatives', $this->payload) && is_array($this->payload['representatives'])) {
            foreach ($this->payload['representatives'] as $representative) {
                if (!empty($representative['id'])) {
                    $payload_ids[] = (int)$representative['id'];
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
                if (!$this->supplier_representative->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->supplier_representative->error();
                    return false;
                }
            }
        }

        // 4. Process payload representatives (update existing or insert new)
        if (!array_key_exists('representatives', $this->payload) || !is_array($this->payload['representatives'])) {
            return true;
        }

        // Get uploaded files
        $files = $this->request->getFiles();

        foreach ($this->payload['representatives'] as $index => $representative) {
            $values = [
                'supplier_id'       => $supplier_id,
                'transaction_name'  => $representative['transaction_name'] ?? null,
                'employee'          => $representative['employee'] ?? null,
                'position'          => $representative['position'] ?? null,
            ];

            if (!empty($representative['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_representative->update(['id' => $representative['id']], $values)) {
                    $this->error_message = $this->supplier_representative->error();
                    return false;
                // Switch true and false to make the attachment a mandatory field
                } elseif(((isset($files['representatives'][$index]['signature']) && $files['representatives'][$index]['signature'])) && !$this->_upload_supplier_representative_attachment($representative['id'], $files['representatives'][$index]['signature'], 'assets/supplier_representatives/')) {
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$supplier_representative_id = $this->supplier_representative->insert($values)) {
                    $this->error_message = $this->supplier_representative->error();
                    return false;
                // Switch true and false to make the attachment a mandatory field
                } elseif(((isset($files['representatives'][$index]['signature']) && $files['representatives'][$index]['signature'])) && !$this->_upload_supplier_representative_attachment($supplier_representative_id, $files['representatives'][$index]['signature'], 'assets/supplier_representatives/')) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Attempt to update supplier products
     */
    private function _attempt_update_products($supplier_id)
    {
        // 1. Get all current product IDs for this supplier
        $existing_products = $this->supplier_product->select('id', ['supplier_id' => $supplier_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_products, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('products', $this->payload) && is_array($this->payload['products'])) {
            foreach ($this->payload['products'] as $product) {
                if (!empty($product['id'])) {
                    $payload_ids[] = (int)$product['id'];
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
                if (!$this->supplier_product->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->supplier_product->error();
                    return false;
                }
            }
        }

        // 4. Process payload products (update existing or insert new)
        if (!array_key_exists('products', $this->payload) || !is_array($this->payload['products'])) {
            return true;
        }

        foreach ($this->payload['products'] as $product) {
            $values = [
                'supplier_id'  => $supplier_id,
                'product_lines_carried'          => $product['product_lines_carried'] ?? null,
                'brand'       => $product['brand'] ?? null,
                'products_exclusively_carried'       => $product['products_exclusively_carried'] ?? null,
                'brand_principal'       => $product['brand_principal'] ?? null,
            ];

            if (!empty($product['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_product->update(['id' => $product['id']], $values)) {
                    $this->error_message = $this->supplier_product->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_product->insert($values)) {
                    $this->error_message = $this->supplier_product->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update supplier services
     */
    private function _attempt_update_services($supplier_id)
    {
        // 1. Get all current service IDs for this supplier
        $existing_services = $this->supplier_service->select('id', ['supplier_id' => $supplier_id, 'is_deleted' => 0]);

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
                if (!$this->supplier_service->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->supplier_service->error();
                    return false;
                }
            }
        }

        // 4. Process payload services (update existing or insert new)
        if (!array_key_exists('services', $this->payload) || !is_array($this->payload['services'])) {
            return true;
        }

        foreach ($this->payload['services'] as $service) {
            $values = [
                'supplier_id'  => $supplier_id,
                'services_offered'          => $service['services_offered'] ?? null,
            ];

            if (!empty($service['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_service->update(['id' => $service['id']], $values)) {
                    $this->error_message = $this->supplier_service->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_service->insert($values)) {
                    $this->error_message = $this->supplier_service->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update supplier contacts
     */
    private function _attempt_update_contacts($supplier_id)
    {
        // 1. Get all current contact IDs for this supplier
        $existing_contacts = $this->supplier_contact->select('id', ['supplier_id' => $supplier_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_contacts, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('contacts', $this->payload) && is_array($this->payload['contacts'])) {
            foreach ($this->payload['contacts'] as $contact) {
                if (!empty($contact['id'])) {
                    $payload_ids[] = (int)$contact['id'];
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
                if (!$this->supplier_contact->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->supplier_contact->error();
                    return false;
                }
            }
        }

        // 4. Process payload contacts (update existing or insert new)
        if (!array_key_exists('contacts', $this->payload) || !is_array($this->payload['contacts'])) {
            return true;
        }

        foreach ($this->payload['contacts'] as $contact) {
            $values = [
                'supplier_id'  => $supplier_id,
                'contact_person'          => $contact['contact_person'] ?? null,
                'contact_no'          => $contact['contact_no'] ?? null,
                'email'          => $contact['email'] ?? null,
            ];

            if (!empty($contact['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_contact->update(['id' => $contact['id']], $values)) {
                    $this->error_message = $this->supplier_contact->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_contact->insert($values)) {
                    $this->error_message = $this->supplier_contact->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update supplier banks
     */
    private function _attempt_update_banks($supplier_id)
    {
        // 1. Get all current bank IDs for this supplier
        $existing_banks = $this->supplier_bank->select('id', ['supplier_id' => $supplier_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_banks, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('banks', $this->payload) && is_array($this->payload['banks'])) {
            foreach ($this->payload['banks'] as $bank) {
                if (!empty($bank['id'])) {
                    $payload_ids[] = (int)$bank['id'];
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
                if (!$this->supplier_bank->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->supplier_bank->error();
                    return false;
                }
            }
        }

        // 4. Process payload banks (update existing or insert new)
        if (!array_key_exists('banks', $this->payload) || !is_array($this->payload['banks'])) {
            return true;
        }

        foreach ($this->payload['banks'] as $bank) {
            $values = [
                'supplier_id'  => $supplier_id,
                'name'          => $bank['name'] ?? null,
                'bank_branch'          => $bank['bank_branch'] ?? null,
                'account_name'          => $bank['account_name'] ?? null,
                'account_no'          => $bank['account_no'] ?? null,
            ];

            if (!empty($bank['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_bank->update(['id' => $bank['id']], $values)) {
                    $this->error_message = $this->supplier_bank->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->supplier_bank->insert($values)) {
                    $this->error_message = $this->supplier_bank->error();
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Change status supplier
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $supplier_id = $this->payload['supplier_id'];
        $where = [
                    'id' => $supplier_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$supplier = $this->supplier->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'supplier is not found.'
            ];
        //validate status
        } elseif ($status && $supplier->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Supplier is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($supplier, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change supplier status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'supplier_id' => $supplier_id,
                'message'    => "supplier $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($supplier, $role_id)
    {
        $values = [
            'is_reconciled'            => $this->payload['is_reconciled'] ?? $supplier->is_reconciled,
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
            $values['disapproved_reason'] = $this->payload['disapproved_reason'] ?? null;
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'pending_blacklist') {
            $values['status'] = 'pending_blacklist';
            $values['blacklisted_reason'] = $this->payload['blacklisted_reason'] ?? null;
            $values['pending_blacklist_by'] = $this->payload['requester'];
            $values['pending_blacklist_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'pending_reconcile') {
            $values['status'] = 'pending_reconcile';
            $values['pending_reconcile_by'] = $this->payload['requester'];
            $values['pending_reconcile_on'] = date('Y-m-d H:i:s');
        } elseif ($status == 'blacklisted') {
            $values['status'] = 'blacklisted';
            $values['blacklisted_reason'] = $this->payload['blacklisted_reason'] ?? null;
            $values['blacklisted_by'] = $this->payload['requester'];
            $values['blacklisted_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $supplier->id];

        if (!$this->supplier->update($where, $values)) {
            $this->error_message = $this->supplier->error();
            return false;
        }
        return true;
    }

    /**
     * Delete suppliers
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $supplier_id = $this->payload['supplier_id'];

        $where = ['id' => $supplier_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$supplier = $this->supplier->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'supplier is not found.',
            ];
        } elseif (!$this->_attempt_delete($supplier_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'supplier is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'supplier is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($supplier_id)
    {
        $where = ['id' => $supplier_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->supplier->update($where, $values)) {
            $this->error_message = $this->supplier->error();
            return false;
        }

        return true;
    }

    /**
     * Upload Attachments
     */
    public function _upload_attachments($supplier_id, $path)
    {
        $files = $this->request->getFileMultiple('attachments');
        $file_path = $path.$supplier_id;

        $supplier_attachments = $this->supplier_attachment->select('', ['supplier_id' => $supplier_id, 'is_deleted' => 0]) ?:[];

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

                    if(empty($supplier_attachments)) {
                        $file->move($path, $file_name);
                        $data = [
                            'supplier_id' => $supplier_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->supplier_attachment->insert($data)) {
                            return false;
                        }
                    } else {
                        // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                        foreach ($supplier_attachments as $attachment) {
                            $attachment = (array)$attachment;
                            $this->supplier_attachment->update(['id' => $attachment['id']], [
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
                            'supplier_id' => $supplier_id,
                            'file_name' => $file_name,
                            'file_path' => $file_path,
                            'file_url' => base_url($file_path . '/' . $file_name),
                            'mime' => $mime_type,
                            'added_by' => $this->payload['requester'],
                            'added_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->supplier_attachment->insert($data)) {
                            return false;
                        }
                    }
                }
            }
        } else {
            // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
            foreach ($supplier_attachments as $attachment) {
                $attachment = (array)$attachment;
                $this->supplier_attachment->update(['id' => $attachment['id']], [
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
     * Upload Attachments
     */
    public function _upload_supplier_representative_attachment($supplier_representative_id, $file, $path,)
    {
        $file_path = $path.$supplier_representative_id;
        $path = $file_path . '/';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            write_file($path . 'index.html', 'Directory access is forbidden.');
        }
            
        $sourcePath = $file->getPath();
        $destinationPath = $path;
        $unique_name = $unique_name = uniqid() . '_' . time();

        if ($file->isValid() && !in_array($file->getExtension(), ['pdf', 'png', 'jpeg', 'jpg'])) {
            return false;
        }

        $file_name = $file->getName();
        $max_file_size = 5 * 1024 * 1024; // 5 MB in bytes

        if ($file->isValid() && !$file->hasMoved()) {
            //$file_name = $file->getName();
            $extension = $file->getExtension();
            $file_name = $unique_name . '.' . $extension;
            $mime_type = $file->getMimeType();

            $where = [
                'supplier_representative_id' => $supplier_representative_id,
                // 'file_name' => $file_name,
                'is_deleted' => 0
            ];

            $supplier_representative_attachments = $this->supplier_representative_attachment->select('', $where) ?:[];

            if(empty($supplier_representative_attachments)) {
                $file->move($path, $file_name);
                $data = [
                    'supplier_representative_id' => $supplier_representative_id,
                    'file_name' => $file_name,
                    'file_path' => $file_path,
                    'file_url' => base_url($file_path . '/' . $file_name),
                    'mime' => $mime_type,
                    'added_by' => $this->payload['requester'],
                    'added_on' => date('Y-m-d H:i:s')
                ];

                if (!$this->supplier_representative_attachment->insert($data)) {
                    return false;
                }
            } else {
                // File already exists in the database, unset the file and make the record is_deleted = 1 and insert the newly uploaded file
                foreach ($supplier_representative_attachments as $attachment) {
                    $attachment = (array)$attachment;
                    $this->supplier_representative_attachment->update(['id' => $attachment['id']], [
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
                    'supplier_representative_id' => $supplier_representative_id,
                    'file_name' => $file_name,
                    'file_path' => $file_path,
                    'file_url' => base_url($file_path . '/' . $file_name),
                    'mime' => $mime_type,
                    'added_by' => $this->payload['requester'],
                    'added_on' => date('Y-m-d H:i:s')
                ];

                if (!$this->supplier_representative_attachment->insert($data)) {
                    return false;
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
