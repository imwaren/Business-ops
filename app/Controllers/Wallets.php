<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Wallet;
use App\Models\Wallet_history;
use App\Models\Company;
use App\Models\Company_validity_date;
use App\Models\Patient_master;

class Wallets extends MYT_Controller
{
    protected $request;
    protected $wallet;
    protected $wallet_history;
    protected $company;
    protected $company_validity_date;
    protected $patient_master;
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
        $this->wallet = new Wallet();
        $this->wallet_history = new Wallet_history();
        $this->company = new Company();
        $this->company_validity_date = new Company_validity_date();
        $this->patient_master = new Patient_master();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Get wallets
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $wallet_id = $this->payload['wallet_id'] ?? null;
        $patient_id = $this->payload['patient_id'] ?? null;
        $company_id = $this->payload['company_id'] ?? null;
        $valid_from = $this->payload['valid_from'] ?? null;
        $valid_to = $this->payload['valid_to'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $wallets = $this->wallet->get_details($wallet_id, $patient_id, $company_id, $valid_from, $valid_to, $page, $data_size)?:[];
        $size = count($this->wallet->get_details($wallet_id, $patient_id, $company_id, $valid_from, $valid_to, null, null)?:[]);

        if(!$wallets) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No wallets found.'
            ];
        } else {
            foreach ($wallets as $key => $wallet) {
                $wallet_history = $this->wallet_history->get_details($wallet['id'])?:[];
                $wallets[$key]['history'] = $wallet_history;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $wallets
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get company wallets
     */
    public function get_by_company()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $company_id = $this->payload['company_id'] ?? null;
        $valid_from = $this->payload['valid_from'] ?? null;
        $valid_to = $this->payload['valid_to'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $company_wallets = $this->wallet->get_by_company($company_id, $valid_from, $valid_to, $page, $data_size)?:[];
        $size = count($this->wallet->get_by_company($company_id, $valid_from, $valid_to, null, null)?:[]);

        if(!$company_wallets) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No company wallets found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $company_wallets
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create wallet
     */
    public function generate()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $patient_ids = $this->payload['patient_ids'] ?? [];
        $company_id = $this->payload['company_id'];
        $amount = $this->payload['amount'] ?? null;
        $valid_from = $this->payload['valid_from'] ?? null;
        $valid_to = $this->payload['valid_to'] ?? null;
        $type = $this->payload['type'] ?? null; // 'upload' or 'manual'
        $patients = $this->payload['patients'] ?? [];

        $this->db->transBegin();

        if (!$company_id) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Company_id is required. ",
            ];
        } elseif ($type != 'upload' && $type != 'manual') {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Type is required and should be 'upload' or 'manual'. ",
            ];
        } elseif (!$valid_from || !$valid_to || !strtotime($valid_from) || !strtotime($valid_to) || strtotime($valid_from) > strtotime($valid_to)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Valid_from and valid_to are required, should be valid dates, and valid_from should be before valid_to. ",
            ];
        } elseif (!$amount || !is_numeric($amount) || $amount <= 0) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Amount is required and should be a positive number. ",
            ];
        } elseif (!$company = $this->company->select('', ['id' => $company_id], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Company with the given company_id does not exist. ",
            ];
        } elseif (!$this->_ensure_company_validity_period($company, $amount, $valid_from, $valid_to)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                $this->error_message,
            ];
        } elseif ($type == 'upload' && (!array_key_exists('patients', $this->payload) || !is_array($this->payload['patients']) || count($this->payload['patients']) == 0)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "No patients to upload. ",
            ];
        } elseif ($type == 'upload' && !$patient_ids = $this->_get_patient_ids($patients)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to get patient_ids. ",
            ];
        } elseif (!$patient_ids || !is_array($patient_ids) || count($patient_ids) == 0) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "At least one patient_id is required in an array format. ",
            ];
        } elseif (!$this->_attempt_update_wallets($company_id, $patient_ids, $amount, $valid_from, $valid_to, $type)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to update company wallets. " . $this->error_message,
            ];
        } else {
            $message = $this->_validate_patient_ids($company_id, $valid_from, $valid_to, $patient_ids);

            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message'    => $message ?: "Company wallets have been successfully generated. ",
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Match an existing company validity period, or seed/renew one via company update.
     */
    private function _ensure_company_validity_period(&$company, $amount, $valid_from, $valid_to)
    {
        $company_id = $company->id;
        $exact_match_where = [
            'company_id' => $company_id,
            'wallet_validity_date_from' => $valid_from,
            'wallet_validity_date_to' => $valid_to,
            'is_deleted' => 0,
        ];

        $company_validity = $this->company_validity_date->select('', $exact_match_where, 1);

        if (!$company_validity) {
            $overlapping = $this->company_validity_date->find_overlapping($company_id, $valid_from, $valid_to);
            if ($overlapping) {
                $this->error_message = "Valid_from and valid_to dates overlap with the company's existing wallet validity date range of " . $overlapping->wallet_validity_date_from . " to " . $overlapping->wallet_validity_date_to . ". ";
                return false;
            }

            $values = [
                'consumable_amount_per_employee' => $amount,
                'wallet_validity_date_from' => $valid_from,
                'wallet_validity_date_to' => $valid_to,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s'),
            ];

            if (!$this->company->update(['id' => $company_id], $values)) {
                $this->error_message = $this->company->error();
                return false;
            }

            $company = $this->company->select('', ['id' => $company_id], 1);
            $company_validity = $this->company_validity_date->select('', $exact_match_where, 1);

            if (!$company_validity) {
                $this->error_message = "Company does not have a validity date configuration. ";
                return false;
            }
        }

        $period_limit = $company_validity->consumable_amount_per_employee;
        if ($amount > $period_limit) {
            $this->error_message = "Amount exceeds the company's consumable_amount_per_employee limit of " . $period_limit . ". ";
            return false;
        }

        return $company_validity;
    }

    /**
     * Get patient IDs
     */
    private function _get_patient_ids($patients)
    {
        $patient_ids = [];
        foreach ($patients as $patient) {
            $patient_data = $this->patient_master->get_patient_by_name(null, $patient['patient_first_name'], $patient['patient_last_name'], $patient['patient_middle_name'], $patient['patient_birthdate'], null);
            if (!$patient_data) {
                //insert
                $patient_values = [
                    'branch_id'            => $patient['branch_id'],
                    'first_name'                 => $patient['patient_first_name'],
                    'last_name'             => $patient['patient_last_name'],
                    'middle_name'               => $patient['patient_middle_name'] ?? '',
                    'birthdate'               => $patient['patient_birthdate'],
                    'sex'               => $patient['patient_sex'],
                    'civil_status'               => $patient['patient_civil_status'],
                    'email'               => $patient['patient_email'] ?? null,
                    'contact_no'               => 0,
                    'added_by'              => $this->payload['requester'],
                    'added_on'              => date('Y-m-d H:i:s'),
                ];
        
                if (!$new_patient_data_id = $this->_insert_encrypt_generated_id($this->patient_master, $patient_values, ['first_name', 'last_name', 'middle_name', 'birthdate', 'contact_no', 'civil_status', 'email'], isset($patient['branch_id']) ? (int) $patient['branch_id'] : 0)) {
                    return false;
                }

                $patient_data = $this->patient_master->get_patient_by_name($new_patient_data_id);
            }
            
            if ($patient_data) {
                $patient_ids[] = $patient_data->id;
            }
        }

        return $patient_ids;
    }

    /**
    * Validate patient IDs
    */
    private function _validate_patient_ids($company_id, $valid_from, $valid_to, $patient_ids)
    {
        $message = [];
        foreach ($patient_ids as $patient_id) {
            $patient = $this->patient_master->get_patient_name($patient_id);
            $patient_name = $patient ? $patient->patient_name : "ID " . $patient_id;

            $wallet = $this->wallet->get_details(null, $patient_id, $company_id, $valid_from, $valid_to);
            if ($wallet) {
                if ($wallet[0]['amount'] != $wallet[0]['remaining_balance']) {
                    $message[] = "Patient $patient_name has an active wallet with a remaining balance of " . $wallet[0]['remaining_balance'] . ".";
                } else {
                    $message[] = "Patient $patient_name's wallet was successfully updated.";
                }
            } else {
                $message[] = "Patient $patient_name's wallet was successfully created.";
            }
        }

        if (!empty($message)) {
            return $message;
        }

        return false;
    }

    /**
     * Attempt to update company wallets
     */
    private function _attempt_update_wallets($company_id, $patient_ids, $amount, $valid_from, $valid_to, $type)
    {
        if ($type == 'manual') {
            // 1. Get all current wallet IDs for this company
            $existing_wallets = $this->wallet->select('id', ['company_id' => $company_id, 'valid_from' => $valid_from, 'valid_to' => $valid_to, 'is_deleted' => 0]);

            $existing_ids = array_column($existing_wallets, 'id');

            // 2. Collect IDs from payload
            $payload_ids = [];
            if (is_array($patient_ids)) {
                foreach ($patient_ids as $patient_id) {
                    $wallet = $this->wallet->select('id', ['company_id' => $company_id, 'patient_id' => $patient_id, 'valid_from' => $valid_from, 'valid_to' => $valid_to, 'is_deleted' => 0], 1);
                    if ($wallet) {
                        $payload_ids[] = (int)$wallet->id;
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
                    if (!$this->wallet->update(['id' => $delete_id], $delete_values)) {
                        $this->error_message = $this->wallet->error();
                        return false;
                    }
                }
            }
        }

        // 4. Process payload wallets (update existing or insert new)
        foreach ($patient_ids as $patient_id) {
            $values = [
                'company_id'    => $company_id,
                'patient_id'    => $patient_id,
                'valid_from'    => $valid_from,
                'valid_to'      => $valid_to,
            ];

            $wallet = $this->wallet->select('id, amount', ['company_id' => $company_id, 'patient_id' => $patient_id, 'valid_from' => $valid_from, 'valid_to' => $valid_to, 'is_deleted' => 0], 1);
            if ($wallet) {
                // update existing
                if ($wallet->amount != $amount) {
                    $values['last_transaction'] = 'wallet_update';
                    $values['last_transaction_id'] = $wallet->id;
                }
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->wallet->update(['id' => $wallet->id], $values)) {
                    $this->error_message = $this->wallet->error();
                    return false;
                }
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');
                $values['amount'] = $amount;
                $values['remaining_balance'] = $amount;

                if (!$this->wallet->insert($values)) {
                    $this->error_message = $this->wallet->error();
                    return false;
                }
            }
        }
        
        return true;
    }
}
