<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Supplier;
use App\Models\Accounts_payable_voucher;
use App\Models\Accounts_payable_voucher_attachment;
use App\Models\Accounts_payable_voucher_payment;
use App\Models\Accounts_payable_voucher_payment_attachment;
use App\Models\Accounts_payable_voucher_payment_detail;
use App\Models\Accounts_payable_voucher_entry;
use App\Models\Accounts_payable_voucher_amortization_entry;
use App\Libraries\Apv_accounting;

class Accounts_payable_vouchers extends MYT_Controller
{
    use \App\Core\Fund_routing;
    protected $request;
    protected $accounts_payable_voucher;
    protected $accounts_payable_voucher_attachment;
    protected $accounts_payable_voucher_payment;
    protected $accounts_payable_voucher_payment_attachment;
    protected $accounts_payable_voucher_payment_detail;
    protected $receipt_note;
    protected $receipt_note_item;
    protected $receipt_note_additional_item;
    protected $refund;
    protected $refund_detail;
    protected $petty_cash;
    protected $reimbursement;
    protected $replenishment;
    protected $cash_advance;
    protected $liquidation;
    protected $change_fund;
    protected $direct_payment;
    protected $voucher_entries;
    protected $amortization_entries;
    protected $branch_db;
    private $prepared_entries;
    private $prepared_allocations;
    private $accounting_saved = false;
    private $legacy_update_without_entries = false;
    protected $aoq;
    protected $purchase_order;
    protected $purchase_request;
    protected $send_out;
    protected $send_out_entry;
    protected $journal_entry;
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
        $this->accounts_payable_voucher_payment = new Accounts_payable_voucher_payment();
        $this->accounts_payable_voucher_payment_attachment = new Accounts_payable_voucher_payment_attachment();
        $this->refund = new \App\Models\Refund();
        $this->refund_detail = new \App\Models\Refund_detail();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Maps branch_id (master) to CodeIgniter database group names (per-branch clinic DB).
     */
    protected function branch_db_map(): array
    {
        return $this->fund_router()->groups();
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

        $this->accounts_payable_voucher = new \App\Models\Accounts_payable_voucher($db);
        $this->accounts_payable_voucher_attachment = new Accounts_payable_voucher_attachment($db);
        // $this->accounts_payable_voucher_payment = new \App\Models\Accounts_payable_voucher_payment($db); //--> transfer to master db
        $this->accounts_payable_voucher_payment_detail = new \App\Models\Accounts_payable_voucher_payment_detail($db);
        $this->receipt_note = new \App\Models\Receipt_note($db);
        $this->receipt_note_item = new \App\Models\Receipt_note_item($db);
        $this->receipt_note_additional_item = new \App\Models\Receipt_note_additional_item($db);
        $this->petty_cash = new \App\Models\Petty_cash($db);
        $this->reimbursement = new \App\Models\Reimbursement($db);
        $this->replenishment = new \App\Models\Replenishment($db);
        $this->cash_advance = new \App\Models\Cash_advance($db);
        $this->liquidation = new \App\Models\Liquidation($db);
        $this->change_fund = new \App\Models\Change_fund($db);
        $this->direct_payment = new \App\Models\Direct_payment($db);
        $this->voucher_entries = new Accounts_payable_voucher_entry($db);
        $this->amortization_entries = new Accounts_payable_voucher_amortization_entry($db);
        $this->aoq = new \App\Models\Aoq($db);
        $this->purchase_order = new \App\Models\Purchase_order($db);
        $this->purchase_request = new \App\Models\Purchase_request($db);
        $this->send_out = new \App\Models\Send_out($db);
        $this->send_out_entry = new \App\Models\Send_out_entry($db);
        $this->journal_entry = new \App\Models\Journal_entry($db);

        if ($group === 'master') {
            $this->db = $db; // Transaction and scoped models must use the same connection.
            foreach (['accounts_payable_voucher', 'accounts_payable_voucher_attachment', 'accounts_payable_voucher_payment_detail', 'receipt_note', 'receipt_note_item', 'receipt_note_additional_item', 'petty_cash', 'reimbursement', 'replenishment', 'cash_advance', 'liquidation', 'change_fund', 'direct_payment', 'voucher_entries', 'amortization_entries', 'aoq', 'purchase_order', 'purchase_request', 'send_out', 'send_out_entry', 'journal_entry'] as $property) {
                if (method_exists($this->$property, 'with_shared_cost_centers')) {
                    $this->$property->with_shared_cost_centers($branch_id);
                }
            }
        }
        return true;
    }

    /**
     * Get accounts_payable_vouchers
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $cost_center_id = $this->payload['cost_center_id'] ?? null;
        $has_branch = $cost_center_id !== null && $cost_center_id !== '';
        if ($has_branch && (!is_scalar($cost_center_id)
            || !ctype_digit((string) $cost_center_id)
            || !$this->resolve_branch_models((int) $cost_center_id))) {
            return $this->_create_response(
                FAIL,
                ['Invalid Cost Center ID.']
            );
        }

        $accounts_payable_voucher_id = $this->payload['accounts_payable_voucher_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $type = $this->payload['type'] ?? null;
        $type_id = $this->payload['type_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $supplier_type = $this->payload['supplier_type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $screen = $this->payload['screen'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($supplier_id && !$supplier_type) {
            return $this->_create_response(
                FAIL,
                ['Supplier type is required if supplier_id is provided.']
            );
        }

        if ($has_branch) {
            $accounts_payable_vouchers = $this->accounts_payable_voucher->get_details($accounts_payable_voucher_id, $type, $type_id, $status, $supplier_id, $supplier_type, $screen, $term, $page, $data_size) ?: [];
            $size = count($this->accounts_payable_voucher->get_details($accounts_payable_voucher_id, $type, $type_id, $status, $supplier_id, $supplier_type, $screen, $term, null, null) ?: []);
            foreach ($accounts_payable_vouchers as &$row) {
                $row['branch_id'] = (int) $cost_center_id;
            }
            unset($row);
        } else {
            $pack = (new Accounts_payable_voucher())->get_all_details_all_branches(
                $this->branch_db_map(), $accounts_payable_voucher_id, $type, $type_id,
                $status, $supplier_id, $supplier_type, $screen, $term, $page, $data_size
            );
            $accounts_payable_vouchers = $pack['data'];
            $size = $pack['size'];
            foreach ($accounts_payable_vouchers as &$row) {
                $row['branch_id'] = (int) $row['cost_center_id'];
            }
            unset($row);
        }

        $branch_names = [];
        foreach ((new \App\Models\Branch_master(db_connect('master')))->get_details() ?: [] as $branch) {
            $branch_names[(int) $branch['id']] = $branch['name'];
        }
        $apvs_by_branch = [];
        foreach ($accounts_payable_vouchers as $row) {
            $apvs_by_branch[$row['branch_id']][] = $row;
        }
        $journal_entries_by_branch = [];
        $entries_by_branch = [];
        $allocations_by_branch = [];
        foreach ($apvs_by_branch as $branch_id => $branch_apvs) {
            $this->resolve_branch_models((int) $branch_id);
            $movements = $this->journal_entry->get_apv_movements($branch_apvs, (int) $branch_id, $this->fund_router()->group((int) $branch_id) === 'master');
            if ($movements === false) {
                return $this->_create_response(FAIL, ['Failed to retrieve APV journal entries.']);
            }
            $journal_entries_by_branch[$branch_id] = $movements;
            $ids = array_column($branch_apvs, 'id');
            $entries_by_branch[$branch_id] = $this->voucher_entries->get_for_vouchers($ids);
            $allocations_by_branch[$branch_id] = $this->amortization_entries->get_for_vouchers($ids);
        }

        if(!$accounts_payable_vouchers) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No accounts_payable_vouchers found.'
            ];
        } else {
            if ($accounts_payable_voucher_id != null) { //return details array if apv id is provided
                foreach ($accounts_payable_vouchers as $key => $accounts_payable_voucher) {
                    $branch_id = (int) $accounts_payable_voucher['branch_id'];
                    $this->resolve_branch_models($branch_id);
                    $accounts_payable_vouchers[$key]['branch_name'] = $branch_names[$branch_id]
                        ?? ucwords(str_replace('_', ' ', $this->branch_db_map()[$branch_id]));
                    $accounts_payable_vouchers[$key]['journal_entries'] =
                        $journal_entries_by_branch[$branch_id][(int) $accounts_payable_voucher['id']] ?? [];

                    $accounts_payable_vouchers[$key]['entries'] = $entries_by_branch[$branch_id][(int) $accounts_payable_voucher['id']] ?? [];
                    $accounts_payable_vouchers[$key]['amortization_entries'] = $allocations_by_branch[$branch_id][(int) $accounts_payable_voucher['id']] ?? [];

                    $supplier_name = null;
                    if ($accounts_payable_voucher['supplier_type'] == 'supplier') {
                        $supplier = $this->supplier->select('name', ['id' => $accounts_payable_voucher['supplier_id'], 'is_deleted' => 0], 1);
                        $supplier_name = $supplier ? $supplier->name : null;
                    } elseif ($accounts_payable_voucher['supplier_type'] == 'payor') {
                        $payor = $this->user->select('first_name, last_name, middle_name', ['id' => $accounts_payable_voucher['supplier_id'], 'is_deleted' => 0], 1);
                        $supplier_name = $payor ? $payor->first_name . ' ' . $payor->last_name . ' ' . $payor->middle_name : null;
                    } elseif ($accounts_payable_voucher['supplier_type'] == 'patient') {
                        $supplier_name = $this->_patient_supplier_name(
                            $branch_id,
                            $accounts_payable_voucher['supplier_id']
                        );
                    }

                    $accounts_payable_vouchers[$key]['supplier_name'] = $supplier_name;
                    $accounts_payable_vouchers[$key]['attachments'] = $this->accounts_payable_voucher_attachment->get_details(
                        null,
                        $accounts_payable_voucher['id']
                    ) ?: [];

                    if ($accounts_payable_voucher['type'] == 'receipt_note') {
                        $accounts_payable_vouchers[$key]['receipt_note'] = $this->receipt_note->get_details($accounts_payable_voucher['type_id']) ?: [];
                        $accounts_payable_vouchers[$key]['receipt_note_items'] = $this->receipt_note_item->get_details($accounts_payable_voucher['type_id']) ?: [];
                        $accounts_payable_vouchers[$key]['receipt_note_additional_items'] = $this->receipt_note_additional_item->get_details($accounts_payable_voucher['type_id']) ?: [];
                    }

                    $this->_attach_send_out_to_apv($accounts_payable_vouchers[$key]);
                    $this->_attach_refund_to_apv($accounts_payable_vouchers[$key]);
                }
            } else {
                foreach ($accounts_payable_vouchers as $key => $accounts_payable_voucher) {
                    $supplier_name = null;
                    if ($accounts_payable_voucher['supplier_type'] == 'supplier') {
                        $supplier = $this->supplier->select('name', ['id' => $accounts_payable_voucher['supplier_id'], 'is_deleted' => 0], 1);
                        $supplier_name = $supplier ? $supplier->name : null;
                    } elseif ($accounts_payable_voucher['supplier_type'] == 'payor') {
                        $payor = $this->user->select('first_name, last_name, middle_name', ['id' => $accounts_payable_voucher['supplier_id'], 'is_deleted' => 0], 1);
                        $supplier_name = $payor ? $payor->first_name . ' ' . $payor->last_name . ' ' . $payor->middle_name : null;
                    } elseif ($accounts_payable_voucher['supplier_type'] == 'patient') {
                        $supplier_name = $this->_patient_supplier_name(
                            $branch_id,
                            $accounts_payable_voucher['supplier_id']
                        );
                    }

                    $accounts_payable_vouchers[$key]['supplier_name'] = $supplier_name;
                }
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $accounts_payable_vouchers
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get accounts_payable_vouchers
     */
    public function get_all()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $accounts_payable_voucher_id = $this->payload['accounts_payable_voucher_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $type = $this->payload['type'] ?? null;
        $type_id = $this->payload['type_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $supplier_type = $this->payload['supplier_type'] ?? null;
        $screen = $this->payload['screen'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($supplier_id && !$supplier_type) {
            return $this->_create_response(
                FAIL,
                ['Supplier type is required if supplier_id is provided.']
            );
        }

        $pack = (new Accounts_payable_voucher())->get_all_details_all_branches(
            $this->branch_db_map(),
            $accounts_payable_voucher_id,
            $type,
            $type_id,
            $status,
            $supplier_id,
            $supplier_type,
            $screen,
            $term,
            $page,
            $data_size
        );
        $accounts_payable_vouchers = $pack['data'];
        $size = $pack['size'];

        if(!$accounts_payable_vouchers) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No accounts_payable_vouchers found.'
            ];
        } else {
            foreach ($accounts_payable_vouchers as $key => $accounts_payable_voucher) {
                $supplier_name = null;
                if ($accounts_payable_voucher['supplier_type'] == 'supplier') {
                    $supplier = $this->supplier->select('name', ['id' => $accounts_payable_voucher['supplier_id']], 1);
                    $supplier_name = $supplier ? $supplier->name : null;
                } elseif ($accounts_payable_voucher['supplier_type'] == 'payor') {
                    $payor = $this->user->select('first_name, last_name, middle_name', ['id' => $accounts_payable_voucher['supplier_id']], 1);
                    $supplier_name = $payor ? $payor->first_name . ' ' . $payor->last_name . ' ' . $payor->middle_name : null;
                } elseif ($accounts_payable_voucher['supplier_type'] == 'patient') {
                    $supplier_name = $this->_patient_supplier_name(
                        $accounts_payable_voucher['cost_center_id'] ?? null,
                        $accounts_payable_voucher['supplier_id']
                    );
                }

                $accounts_payable_vouchers[$key]['supplier_name'] = $supplier_name;

                $cost_center_id = $accounts_payable_voucher['cost_center_id'] ?? null;
                if ($cost_center_id && $this->resolve_branch_models((int) $cost_center_id)) {
                    $accounts_payable_vouchers[$key]['attachments'] = $this->accounts_payable_voucher_attachment->get_details(
                        null,
                        $accounts_payable_voucher['id']
                    ) ?: [];
                    $this->_attach_send_out_to_apv($accounts_payable_vouchers[$key]);
                } else {
                    $accounts_payable_vouchers[$key]['attachments'] = [];
                }

                $this->_attach_refund_to_apv($accounts_payable_vouchers[$key]);
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $accounts_payable_vouchers
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get accounts_payable_vouchers
     */
    public function get_all_for_apv()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id !== null && $branch_id !== '' && (!is_scalar($branch_id)
            || !ctype_digit((string) $branch_id) || $this->fund_router()->group((int) $branch_id) === null)) {
            return $this->_create_response(FAIL, ['Invalid Cost Center ID.']);
        }
        $status = $this->payload['status'] ?? null;
        $type = $this->payload['type'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $supplier_type = $this->payload['supplier_type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($supplier_id && !$supplier_type) {
            return $this->_create_response(
                FAIL,
                ['Supplier type is required if supplier_id is provided.']
            );
        }

        $pack = (new Accounts_payable_voucher())->get_all_for_apv_all_branches(
            $this->branch_db_map(),
            $branch_id,
            $type,
            $status,
            $supplier_id,
            $supplier_type,
            $term,
            $page,
            $data_size
        );
        $accounts_payable_vouchers = $pack['data'];
        $size = $pack['size'];

        if(!$accounts_payable_vouchers) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No accounts_payable_vouchers found.'
            ];
        } else {
            foreach ($accounts_payable_vouchers as $key => $accounts_payable_voucher) {
                if (($accounts_payable_voucher['supplier_type'] ?? '') === 'patient') {
                    $accounts_payable_vouchers[$key]['supplier_name'] = $this->_patient_supplier_name(
                        $accounts_payable_voucher['branch_id'] ?? null,
                        $accounts_payable_voucher['supplier_id'] ?? null
                    );
                }
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $accounts_payable_vouchers
            ];
        }

        return $this->_create_response($response_status, $response);
    }
    
    /**
     * Create accounts_payable_voucher
     */
    private function _create_with_entries()
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

        $supplier_id = $this->payload['supplier_id'] ?? null;
        $supplier_type = $this->payload['supplier_type'] ?? null;
        if ($supplier_id && !$supplier_type) {
            return $this->_create_response(
                FAIL,
                ['supplier_type is required if supplier_id is provided. Get it from the suppliers/get API response by passing screen equals to apv.']
            );
        }


        $type = $this->payload['type'] ?? null;
        $type_id = $this->payload['type_id'] ?? null;
        $amount = $this->payload['amount'] ?? null;

        // If type is excess, set type to liquidation
        if ($type == 'excess') {
            $type = 'liquidation';
            $this->payload['type'] = $type;
        }

        if (!$type || !$type_id || !$amount) {
            $response_status = FAIL;
            $response = [
                "type, type_id, and amount are required. ",
            ];
        } elseif (!$supplier_id) {
            $response_status = FAIL;
        
            $typeMessageMap = [
                'petty_cash'     => 'Supplier ID is required (use requested_by_id).',
                'cash_advance'   => 'Supplier ID is required (use requested_by_id).',
                'change_fund'    => 'Supplier ID is required (use requested_by_id).',
                'direct_payment' => 'Supplier ID is required (use requested_by_id).',
                'reimbursement'  => 'Supplier ID is required (use liquidated_by_id).',
                'replenishment'  => 'Supplier ID is required (use liquidated_by_id).',
                'liquidation'    => 'Supplier ID is required (use liquidated_by_id).',
                'refund'         => 'Supplier ID is required (use patient_id).',
                'send_out'       => 'Supplier ID is required (use send_out.supplier_id).',
            ];
        
            $message = $typeMessageMap[$type] ?? 'Supplier ID is required.';
            $response = [$message];        
        } elseif (!$this->$type->select('', ['id' => $type_id, 'is_deleted' => 0], 1) ) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                "$type with ID $type_id is not found. ",
            ];
        } elseif ($type === 'refund' && !$this->_refund_matches_cost_center($type_id, $cost_center_id)) {
            $response_status = FAIL;
            $response = [
                "refund with ID $type_id does not belong to this cost center. ",
            ];
        } elseif ($type !== 'send_out' && !$this->$type->select('', ['id' => $type_id, 'status' => 'approved', 'is_deleted' => 0], 1) ) {
            $response_status = FAIL;
            $response = [
                "$type with ID $type_id must be approved before creating accounts payable voucher. ",
            ];
        //validate existing type and type_id
        } elseif ($accounts_payable_voucher = $this->accounts_payable_voucher->select('', ['type' => $type, 'type_id' => $type_id, 'is_deleted' => 0], 1)) {
            $response_status = FAIL;
            $response = [
                "accounts_payable_voucher with type $type and type_id $type_id already exists. ",
            ];
        } elseif (!$accounts_payable_voucher_id = $this->_attempt_create($amount)) {
            $response_status = FAIL;
            $response = [
                "Failed to add accounts_payable_voucher. ",
            ];
        } elseif (!$this->_save_voucher_accounting($accounts_payable_voucher_id)) {
            $response_status = FAIL;
            $response = ['Failed to save APV accounting entries.'];
        } elseif (!$this->_attempt_create_voucher_attachments($accounts_payable_voucher_id, $cost_center_id, $attachments)) {
            $this->_attempt_delete($accounts_payable_voucher_id);
            $response_status = FAIL;
            $response = [
                'Failed to upload or save accounts_payable_voucher attachments. ' . ($this->error_message ?? ''),
            ];
        } else {
            $this->accounting_saved = true;
            $response_status = SUCCESS;
            $response = [
                'accounts_payable_voucher_id' => $accounts_payable_voucher_id,
                'message'    => 'accounts_payable_voucher added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a accounts_payable_voucher
     */
    private function _attempt_create($amount)
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->accounts_payable_voucher->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        $values['balance'] = $amount;

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$accounts_payable_voucher_id = $this->accounts_payable_voucher->insert($values)) {
            $this->error_message = $this->accounts_payable_voucher->error();
            return false;
        }

        return $accounts_payable_voucher_id;
    }

    /**
     * Validate optional attachments[] before creating APV/payment records.
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
            if (!$file) {
                continue;
            }
            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
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

    protected function _attempt_create_voucher_attachments($voucher_id, $cost_center_id, array $files): bool
    {
        $path = 'assets/accounts_payable_vouchers/' . (int) $cost_center_id . '/' . (int) $voucher_id . '/';
        $success = $this->_upload_apv_attachments(
            $files,
            $this->accounts_payable_voucher_attachment,
            'accounts_payable_voucher_id',
            $voucher_id,
            $path
        );
        if (!$success) {
            $this->accounts_payable_voucher_attachment->update(
                ['accounts_payable_voucher_id' => $voucher_id, 'is_deleted' => 0],
                ['is_deleted' => 1, 'updated_by' => $this->payload['requester'], 'updated_on' => date('Y-m-d H:i:s')]
            );
        }

        return $success;
    }

    private function _attempt_create_payment_attachments($payment_id, array $files): bool
    {
        $path = 'assets/accounts_payable_voucher_payments/' . (int) $payment_id . '/';
        $success = $this->_upload_apv_attachments(
            $files,
            $this->accounts_payable_voucher_payment_attachment,
            'accounts_payable_voucher_payment_id',
            $payment_id,
            $path
        );
        if (!$success) {
            $this->accounts_payable_voucher_payment_attachment->update(
                ['accounts_payable_voucher_payment_id' => $payment_id, 'is_deleted' => 0],
                ['is_deleted' => 1, 'updated_by' => $this->payload['requester'], 'updated_on' => date('Y-m-d H:i:s')]
            );
        }

        return $success;
    }

    private function _upload_apv_attachments(array $files, $model, string $parent_field, $parent_id, string $path): bool
    {
        foreach ($files as $file) {
            $upload = upload_to_vm4($file, $path);
            if (!$upload || empty($upload['success']) || empty($upload['path'])) {
                $this->error_message = 'VM4 upload failed: ' . ($upload['error'] ?? 'unknown error');
                return false;
            }

            $values = [
                $parent_field => $parent_id,
                'file_name' => $file->getName(),
                'file_path' => $upload['path'],
                'file_url' => rtrim(VM4_ENDPOINT, '/') . '/' . ltrim($upload['path'], '/'),
                'mime' => $file->getMimeType(),
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s'),
            ];

            if (!$model->insert($values)) {
                $this->error_message = $model->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Update accounts_payable_voucher
     */
    private function _update_with_entries()
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

        $supplier_id = $this->payload['supplier_id'] ?? null;
        $supplier_type = $this->payload['supplier_type'] ?? null;
        if ($supplier_id && !$supplier_type) {
            return $this->_create_response(
                FAIL,
                ['supplier_type is required if supplier_id is provided. Get it from the suppliers/get API response by passing screen equals to apv.']
            );
        }

        $accounts_payable_voucher_id = $this->payload['accounts_payable_voucher_id'];
        $where = [
                    'id' => $accounts_payable_voucher_id, 
                    'is_deleted' => 0
                ];


        $type = $this->payload['type'] ?? null;
        if ($type === 'excess') {
            $type = 'liquidation';
            if (!$this->legacy_update_without_entries) { $this->payload['type'] = $type; }
        }
        $type_id = $this->payload['type_id'] ?? null;
        $amount = $this->payload['amount'] ?? null;

        if (!$type || !$type_id || !$amount) {
            $response_status = FAIL;
            $response = [
                "type, type_id, and amount are required. ",
            ];
        } elseif (!$supplier_id) {
            $response_status = FAIL;
        
            $typeMessageMap = [
                'petty_cash'     => 'Supplier ID is required (use requested_by_id).',
                'cash_advance'   => 'Supplier ID is required (use requested_by_id).',
                'change_fund'    => 'Supplier ID is required (use requested_by_id).',
                'direct_payment' => 'Supplier ID is required (use requested_by_id).',
                'reimbursement'  => 'Supplier ID is required (use liquidated_by_id).',
                'liquidation'    => 'Supplier ID is required (use liquidated_by_id).',
                'replenishment'  => 'Supplier ID is required (use liquidated_by_id).',
                'refund'         => 'Supplier ID is required (use patient_id).',
                'send_out'       => 'Supplier ID is required (use send_out.supplier_id).',
            ];
        
            $message = $typeMessageMap[$type] ?? 'Supplier ID is required.';
            $response = [$message];
        } elseif (!$this->$type->select('', ['id' => $type_id, 'is_deleted' => 0], 1) ) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                "$type with ID $type_id is not found. ",
            ];
        } elseif ($type === 'refund' && !$this->_refund_matches_cost_center($type_id, $cost_center_id)) {
            $response_status = FAIL;
            $response = [
                "refund with ID $type_id does not belong to this cost center. ",
            ];
        } elseif ($type !== 'send_out' && !$this->$type->select('', ['id' => $type_id, 'status' => 'approved', 'is_deleted' => 0], 1) ) {
            $response_status = FAIL;
            $response = [
                "$type with ID $type_id must be approved before creating accounts payable voucher. ",
            ];
        } elseif (!$accounts_payable_voucher = $this->accounts_payable_voucher->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'accounts_payable_voucher is not found.'
            ];
        //validate existing type and type_id
        } elseif ($duplicate_voucher = $this->accounts_payable_voucher->select('', ['id !=' => $accounts_payable_voucher_id, 'type' => $type, 'type_id' => $type_id, 'is_deleted' => 0], 1)) {
            $response_status = FAIL;
            $response = [
                "accounts_payable_voucher with type $type and type_id $type_id already exists. Use update API to update the amount.",
            ];
        } elseif (!$this->_attempt_update($accounts_payable_voucher)) {
            $response_status = FAIL;
            $response = [
                'Failed to update accounts_payable_voucher.',
            ];
        } elseif (!$this->_save_voucher_accounting($accounts_payable_voucher_id)) {
            $response_status = FAIL;
            $response = ['Failed to save APV accounting entries.'];
        } else {
            $this->accounting_saved = true;
            $response_status = SUCCESS;
            $response = [
                'accounts_payable_voucher_id' => $accounts_payable_voucher_id,
                'message'    => 'accounts_payable_voucher updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    public function create()
    {
        return $this->_accounting_transaction(false);
    }

    public function update()
    {
        return $this->_accounting_transaction(true);
    }

    private function _accounting_transaction(bool $update)
    {
        if (($response = $this->_api_validation()) !== true) { return $response; }
        $branch = $this->payload['cost_center_id'] ?? null;
        if (!$branch || !$this->resolve_branch_models((int) $branch)) {
            return $this->_create_response(FAIL, ['A valid cost_center_id is required.']);
        }
        $original_db = $this->db;
        $this->db = $this->branch_db;
        $prior_deferral = $this->branch_db->query('SELECT @apv_entry_sync AS value')->getRowArray()['value'];
        $this->accounting_saved = false;
        try {
            $this->branch_db->transBegin();
            $id = $update ? (int) ($this->payload['accounts_payable_voucher_id'] ?? 0) : 0;
            $header = $update ? $this->voucher_entries->get_header_for_update($id) : null;
            if ($update && !$header) {
                throw new \InvalidArgumentException('accounts_payable_voucher is not found.');
            }
            if (($this->payload['type'] ?? ($header['type'] ?? ''))==='receipt_note'
                && $this->fund_router()->group((int)$branch)==='master') {
                $source_id=(int)($this->payload['type_id'] ?? ($header['type_id'] ?? 0));
                $source=$this->branch_db->query('SELECT id FROM receipt_note WHERE id=? AND branch_id=? AND is_deleted=0 FOR UPDATE',[$source_id,$branch])->getRowArray();
                if (!$source) { throw new \InvalidArgumentException('Receipt note does not belong to this shared center.'); }
            }
            $legacy = $update && $this->voucher_entries->is_legacy($id);
            $lookup = fn(int $id) => $this->_lookup_entry_account($id);
            $requested_type = $this->payload['type'] ?? ($header['type'] ?? '');
            $is_liquidation = in_array($requested_type, ['liquidation', 'excess'], true);
            $was_liquidation = $update && in_array($header['type'] ?? '', ['liquidation', 'excess'], true);
            $has_entries = array_key_exists('entries', $this->payload);
            if ($update && ($is_liquidation || $was_liquidation) && !$has_entries) {
                throw new \InvalidArgumentException('entries must be supplied on liquidation APV update.');
            }
            $optional_entries = !$update && $is_liquidation;
            $this->prepared_entries = $has_entries && !($optional_entries && $this->payload['entries'] === [])
                ? Apv_accounting::entries($this->payload['entries'], $this->payload['amount'] ?? 0, $lookup)
                : null;
            if (!$update && !$optional_entries && $this->prepared_entries === null) {
                throw new \InvalidArgumentException('entries must be supplied on APV create.');
            }
            $this->legacy_update_without_entries = $legacy && $this->prepared_entries === null;
            if ($this->legacy_update_without_entries) {
                $this->voucher_entries->assert_legacy_update($header,$this->payload);
            }
            if ($update && !$legacy && $this->prepared_entries === null) {
                Apv_accounting::entries($this->voucher_entries->get_for_vouchers([$id])[$id] ?? [],
                    $this->payload['amount'] ?? 0, $lookup);
            }
            $type = $this->payload['type'] ?? '';
            $dp = $type === 'direct_payment' ? $this->direct_payment->select('',
                ['id' => $this->payload['type_id'] ?? 0, 'is_deleted' => 0], 1) : null;
            if ($dp) {
                $this->branch_db->query('SELECT id FROM direct_payment WHERE id = ? FOR UPDATE', [$dp->id]);
                $dp = $this->direct_payment->select('', ['id' => $dp->id, 'is_deleted' => 0], 1);
            }
            $prepaid = $dp && $dp->duration_from && $dp->duration_to;
            $this->prepared_allocations = null;
            if ($prepaid && (!$legacy || $this->prepared_entries !== null)) {
                $lines = $this->payload['amortization_entries'] ??
                    ($update ? ($this->amortization_entries->get_for_vouchers([$id])[$id] ?? []) : []);
                $validated = Apv_accounting::allocations($lines, $dp->amount, $lookup);
                if (array_key_exists('amortization_entries', $this->payload) || !$update) {
                    $this->prepared_allocations = $validated;
                }
            } elseif (!empty($this->payload['amortization_entries'])) {
                throw new \InvalidArgumentException('Amortization entries require a direct payment with duration.');
            } elseif ($update) {
                $this->prepared_allocations = [];
            }
            $this->branch_db->query('SET @apv_entry_sync = 1');
            $response = $update ? $this->_update_with_entries() : $this->_create_with_entries();
            if ($this->branch_db->transStatus() === false) {
                throw new \RuntimeException('Branch transaction failed.');
            }
            if (!$this->accounting_saved) {
                $this->branch_db->transRollback();
                return $response;
            }
            if (!$this->branch_db->transCommit()) {
                throw new \RuntimeException('Unable to commit APV transaction.');
            }
            return $response;
        } catch (\Throwable $error) {
            $this->branch_db->transRollback();
            log_message('error', 'APV accounting save failed: ' . $error->getMessage());
            return $this->_create_response(FAIL, [$error instanceof \InvalidArgumentException
                ? $error->getMessage() : 'Failed to save APV. No accounting changes were committed.']);
        } finally {
            $this->branch_db->query('SET @apv_entry_sync = ?', [$prior_deferral]);
            $this->db = $original_db;
        }
    }

    protected function _lookup_entry_account(int $id): ?array
    {
        $accounts = new \App\Models\Account_type(db_connect('master'));
        $row = $accounts->select('id, code', ['id' => $id, 'is_deleted' => 0, 'status' => 'active'], 1);
        return $row ? (array) $row : null;
    }

    private function _save_voucher_accounting(int $id): bool
    {
        $requester = (int) $this->payload['requester'];
        if ($this->prepared_entries !== null &&
            !$this->voucher_entries->replace_entries($id, $this->prepared_entries, $requester)) { return false; }
        if ($this->prepared_allocations !== null &&
            !$this->amortization_entries->replace_entries($id, $this->prepared_allocations, $requester)) { return false; }
        $this->branch_db->query('SET @apv_entry_sync = 0');
        $query = $this->branch_db->query('CALL gl_post_apv(?)', [$id]);
        if (!$query) { return false; }
        $query->freeResult();
        while ($this->branch_db->connID->more_results() && $this->branch_db->connID->next_result()) {
            $extra = $this->branch_db->connID->store_result();
            if ($extra) { $extra->free(); }
        }
        $this->branch_db->query('SET @apv_entry_sync = 1');
        return true;
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($accounts_payable_voucher)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->accounts_payable_voucher->allowedFields is declared at the top of the model
        foreach($this->accounts_payable_voucher->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $accounts_payable_voucher->id];

        if (!$this->accounts_payable_voucher->update($where, $values)) {
            $this->error_message = $this->accounts_payable_voucher->error();
            return false;
        }
        return true;
    }

    /**
     * Change status accounts_payable_voucher
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
        $accounts_payable_voucher_id = $this->payload['accounts_payable_voucher_id'];
        $where = [
                    'id' => $accounts_payable_voucher_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $user = $this->user->select('id, role_id', ['id' => $this->payload['requester'], 'is_deleted' => 0], 1);

        if (!$accounts_payable_voucher = $this->accounts_payable_voucher->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'accounts_payable_voucher is not found.'
            ];
        } elseif (!in_array($user->role_id, [4,23])) {
            $response_status = FAIL;
            $response = [
                "Finance Manager or Admin users can change accounts_payable_voucher status. ",
            ];
        } elseif ($status && $accounts_payable_voucher->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Receipt_note is already $status. ",
            ];
        } elseif ($status === 'approved' && $this->voucher_entries->is_legacy((int) $accounts_payable_voucher_id)
            && !in_array($accounts_payable_voucher->status,['approved','open','closed'],true)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = ['Supply balanced entries before first approval of a legacy APV.'];
        } elseif (!$this->_attempt_update_status($accounts_payable_voucher, $user->role_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change accounts_payable_voucher status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'accounts_payable_voucher_id' => $accounts_payable_voucher_id,
                'message'    => "accounts_payable_voucher $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($accounts_payable_voucher, $role_id)
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
            $values['disapproved_reason'] = $this->payload['disapproved_reason'] ?? null;
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $accounts_payable_voucher->id];

        if (!$this->accounts_payable_voucher->update($where, $values)) {
            $this->error_message = $this->accounts_payable_voucher->error();
            return false;
        }
        return true;
    }

    /**
     * Delete accounts_payable_vouchers
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
        $accounts_payable_voucher_id = $this->payload['accounts_payable_voucher_id'];

        $where = ['id' => $accounts_payable_voucher_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$accounts_payable_voucher = $this->accounts_payable_voucher->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'accounts_payable_voucher is not found.',
            ];
        } elseif (!$this->_attempt_delete($accounts_payable_voucher_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'accounts_payable_voucher is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'accounts_payable_voucher is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($accounts_payable_voucher_id)
    {
        $where = ['id' => $accounts_payable_voucher_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->accounts_payable_voucher->update($where, $values)) {
            $this->error_message = $this->accounts_payable_voucher->error();
            return false;
        }

        return true;
    }

    /**
     * Get accounts_payable_voucher_payment_details payment
     */
    public function get_payment()
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

        $accounts_payable_voucher_payment_detail_id = $this->payload['accounts_payable_voucher_payment_detail_id'] ?? null;
        $accounts_payable_voucher_id = $this->payload['accounts_payable_voucher_id'] ?? null;
        $accounts_payable_voucher_payment_id = $this->payload['accounts_payable_voucher_payment_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $supplier_type = $this->payload['supplier_type'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $mop = $this->payload['mop'] ?? null;
        $type = $this->payload['type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($supplier_id && !$supplier_type) {
            return $this->_create_response(
                FAIL,
                ['Supplier type is required if supplier_id is provided.']
            );
        }

        $accounts_payable_voucher_payment_details = $this->accounts_payable_voucher_payment_detail->get_details($accounts_payable_voucher_payment_detail_id, $accounts_payable_voucher_id, $accounts_payable_voucher_payment_id, $type, $supplier_id, $supplier_type, $date_from, $date_to, $mop, $term, $page, $data_size)?:[];
        $size = count($this->accounts_payable_voucher_payment_detail->get_details($accounts_payable_voucher_payment_detail_id, $accounts_payable_voucher_id, $accounts_payable_voucher_payment_id, $type, $supplier_id, $supplier_type, $date_from, $date_to, $mop, $term, null, null)?:[]);

        if(!$accounts_payable_voucher_payment_details) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No accounts_payable_voucher_payment_details found.'
            ];
        } else {
            foreach ($accounts_payable_voucher_payment_details as $key => $accounts_payable_voucher_payment_detail) {
                //supplier details
                $supplier_name = null;
                if ($accounts_payable_voucher_payment_detail['supplier_type'] == 'supplier') {
                    $supplier = $this->supplier->select('name', ['id' => $accounts_payable_voucher_payment_detail['supplier_id'], 'is_deleted' => 0], 1);
                    $supplier_name = $supplier ? $supplier->name : null;
                } elseif ($accounts_payable_voucher_payment_detail['supplier_type'] == 'payor') {
                    $payor = $this->user->select('first_name, last_name, middle_name', ['id' => $accounts_payable_voucher_payment_detail['supplier_id'], 'is_deleted' => 0], 1);
                    $supplier_name = $payor ? $payor->first_name . ' ' . $payor->last_name . ' ' . $payor->middle_name : null;
                }

                //payment details
                $accounts_payable_voucher_payment = $this->accounts_payable_voucher_payment->get_details($accounts_payable_voucher_payment_detail['accounts_payable_voucher_payment_id']) ?: null;
                $accounts_payable_voucher_payment = $this->_attach_payment_attachments($accounts_payable_voucher_payment);
                
                //cost center details based on cost_center_id
                $cost_center_name = $this->fund_router()->branches()[$cost_center_id]['name'] ?? null;

                $accounts_payable_voucher_payment_details[$key]['cost_center_id'] = $cost_center_id;
                $accounts_payable_voucher_payment_details[$key]['cost_center_name'] = $cost_center_name;
                $accounts_payable_voucher_payment_details[$key]['supplier_name'] = $supplier_name;
                $accounts_payable_voucher_payment_details[$key]['accounts_payable_voucher_payment'] = $accounts_payable_voucher_payment ? $accounts_payable_voucher_payment[0] : null;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $accounts_payable_voucher_payment_details
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get accounts_payable_voucher_payment_details payment
     */
    public function get_all_payment()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $accounts_payable_voucher_payment_id = $this->payload['accounts_payable_voucher_payment_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $supplier_type = $this->payload['supplier_type'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $mop = $this->payload['mop'] ?? null;
        $type = $this->payload['type'] ?? null;
        $type_id = $this->payload['type_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($supplier_id && !$supplier_type) {
            return $this->_create_response(
                FAIL,
                ['Supplier type is required if supplier_id is provided.']
            );
        }

        $pack = (new Accounts_payable_voucher_payment_detail())->get_all_details_all_branches(
            $this->branch_db_map(), 
            $accounts_payable_voucher_payment_id, 
            $type, 
            $type_id,
            $supplier_id, 
            $supplier_type, 
            $date_from, 
            $date_to, 
            $mop, 
            $term, 
            $page, 
            $data_size
        );

        $accounts_payable_voucher_payment_details = $pack['data'];
        $size = $pack['size'];

        if(!$accounts_payable_voucher_payment_details) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No accounts_payable_voucher_payment_details found.'
            ];
        } else {
            foreach ($accounts_payable_voucher_payment_details as $key => $accounts_payable_voucher_payment_detail) {
                $supplier_name = null;
                if ($accounts_payable_voucher_payment_detail['supplier_type'] == 'supplier') {
                    $supplier = $this->supplier->select('name', ['id' => $accounts_payable_voucher_payment_detail['supplier_id'], 'is_deleted' => 0], 1);
                    $supplier_name = $supplier ? $supplier->name : null;
                } elseif ($accounts_payable_voucher_payment_detail['supplier_type'] == 'payor') {
                    $payor = $this->user->select('first_name, last_name, middle_name', ['id' => $accounts_payable_voucher_payment_detail['supplier_id'], 'is_deleted' => 0], 1);
                    $supplier_name = $payor ? $payor->first_name . ' ' . $payor->last_name . ' ' . $payor->middle_name : null;
                }

                $accounts_payable_voucher_payment = $this->accounts_payable_voucher_payment->get_details($accounts_payable_voucher_payment_detail['accounts_payable_voucher_payment_id']) ?: null;
                $accounts_payable_voucher_payment = $this->_attach_payment_attachments($accounts_payable_voucher_payment);

                $accounts_payable_voucher_payment_details[$key]['supplier_name'] = $supplier_name;
                $accounts_payable_voucher_payment_details[$key]['accounts_payable_voucher_payment'] = $accounts_payable_voucher_payment ? $accounts_payable_voucher_payment[0] : null;
            }

            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $accounts_payable_voucher_payment_details
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     *  Accounts_payable_voucher create payment
     */
    public function create_payment()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $mop = $this->payload['mop'] ?? null;
        $details = $this->payload['details'] ?? null;

        if ($details == null || !is_array($details) || count($details) == 0) {
            return $this->_create_response(FAIL, [
                "Payment details are required. ",
            ]);
        }

        if ($mop == null) {
            return $this->_create_response(FAIL, [
                "Mode of Payment (mop) is required. ",
            ]);
        }

        $attachments = $this->_get_valid_attachment_files();
        if ($attachments === false) {
            return $this->_create_response(FAIL, [
                $this->error_message ?: 'Attachments must be valid PDF, JPG, JPEG, or PNG files no larger than 5 MB each.',
            ]);
        }

        if (!$this->_validate_payment_details($details)) {
            return $this->_create_response(FAIL, [
                "Invalid payment details, make sure accounts_payable_voucher is approved. ",
            ]);
        }

        // Commit the master payment first. The branch GL trigger joins this row
        // with INSERT...SELECT; an open master transaction lock-waits until 504.
        $accounts_payable_voucher_payment_id = $this->_attempt_payment();
        if (!$accounts_payable_voucher_payment_id) {
            return $this->_create_response(FAIL, [
                "Failed to add payment accounts_payable_voucher. ",
            ]);
        }

        if (!$this->_attempt_payment_details($accounts_payable_voucher_payment_id, $mop)) {
            $this->_soft_delete_payment($accounts_payable_voucher_payment_id);
            return $this->_create_response(FAIL, [
                "Failed to add payment details for accounts_payable_voucher. ",
            ]);
        }

        if (!$this->_attempt_create_payment_attachments($accounts_payable_voucher_payment_id, $attachments)) {
            $this->_soft_delete_payment($accounts_payable_voucher_payment_id);
            return $this->_create_response(FAIL, [
                'Failed to upload or save accounts_payable_voucher_payment attachments. ' . ($this->error_message ?? ''),
            ]);
        }

        if ($mop == 'cash' && !$this->_update_apv_status($details)) {
            $this->_soft_delete_payment($accounts_payable_voucher_payment_id);
            return $this->_create_response(FAIL, [
                "Failed to add payment details for accounts_payable_voucher. ",
            ]);
        }

        return $this->_create_response(SUCCESS, [
            'message' => 'accounts_payable_vouchers added payment successfully.'
        ]);
    }

    /**
     * Validate payment details
     */
    protected function _validate_payment_details($details)
    {
        foreach ($details as $detail) {
            $accounts_payable_voucher_id = $detail['accounts_payable_voucher_id'] ?? null;

            if (!$accounts_payable_voucher_id) {
                return false;
            }

            $cost_center_id = $detail['cost_center_id'] ?? null;
            if (!$cost_center_id) {
                return false;
            }

            if (!$this->resolve_branch_models($cost_center_id)) {
                return false;
            }

            $where = ['id' => $accounts_payable_voucher_id, 'status' => 'approved', 'is_deleted' => 0];

            if (!$this->accounts_payable_voucher->select('', $where, 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create payments for accounts_payable_voucher
     */
    private function _attempt_payment()
    {
        $values = [
            'account_type_id'      => $this->payload['account_type_id'] ?? null,
            'mop'                  => $this->payload['mop'],
            'bank_id'              => $this->payload['bank_id'] ?? null,
            'check_date'           => $this->payload['check_date'] ?? null,
            'check_no'             => $this->payload['check_no'] ?? null,
            'reference_no'         => $this->payload['reference_no'] ?? null,
            'remarks'              => $this->payload['remarks'] ?? null,
            'grand_total'          => $this->payload['grand_total'],
            'added_by'             => $this->payload['requester'],
            'added_on'             => date('Y-m-d H:i:s'),
        ];

        if (!$accounts_payable_voucher_payment_id = $this->accounts_payable_voucher_payment->insert($values)) {
            $this->error_message = $this->accounts_payable_voucher_payment->error();
            return false;
        }

        return $accounts_payable_voucher_payment_id;
    }

    /**
     * Attempt to create payment details for accounts_payable_voucher
     */
    private function _attempt_payment_details($accounts_payable_voucher_payment_id, $mop)
    {
        foreach ($this->payload['details'] as $detail) {
            $cost_center_id = $detail['cost_center_id'] ?? null;
            if (!$cost_center_id) {
                return false;
            }

            if (!$this->resolve_branch_models($cost_center_id)) {
                return false;
            }

            $values = [
                'accounts_payable_voucher_payment_id' => $accounts_payable_voucher_payment_id,
                'accounts_payable_voucher_id' => array_key_exists('accounts_payable_voucher_id', $detail) && $detail['accounts_payable_voucher_id'] != '' ? $detail['accounts_payable_voucher_id'] : null,
                'supplier_id' => array_key_exists('supplier_id', $detail) && $detail['supplier_id'] != '' ? $detail['supplier_id'] : null,
                'supplier_type' => array_key_exists('supplier_type', $detail) && $detail['supplier_type'] != '' ? $detail['supplier_type'] : null,
                'date' => array_key_exists('date', $detail) && $detail['date'] != '' ? $detail['date'] : ($mop == 'cash' ? date('Y-m-d') : null),
                'bank' => array_key_exists('bank', $detail) && $detail['bank'] != '' ? $detail['bank'] : null,
                'check_name' => array_key_exists('check_name', $detail) && $detail['check_name'] != '' ? $detail['check_name'] : null,
                'check_no' => array_key_exists('check_no', $detail) && $detail['check_no'] != '' ? $detail['check_no'] : null,
                'transaction_no' => array_key_exists('transaction_no', $detail) && $detail['transaction_no'] != '' ? $detail['transaction_no'] : null,
                'amount' => array_key_exists('amount', $detail) && $detail['amount'] != '' ? $detail['amount'] : 0,
                'balance' => array_key_exists('balance', $detail) && $detail['balance'] != '' ? $detail['balance'] : 0,
                'remarks' => array_key_exists('remarks', $detail) && $detail['remarks'] != '' ? $detail['remarks'] : null,
                // 'is_cleared' => $mop == 'cash' ? 1 : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->accounts_payable_voucher_payment_detail->insert($values)) {
                $this->error_message = $this->accounts_payable_voucher_payment_detail->error();
                return false;
            }

            // if APV type is REPLENISHMENT, restore the petty cash balance associated with replenishment
            $accounts_payable_voucher = $this->accounts_payable_voucher->select('', ['id' => $detail['accounts_payable_voucher_id'], 'is_deleted' => 0], 1);
            if ($accounts_payable_voucher && $accounts_payable_voucher->type == 'replenishment') {
                $replenishment = $this->replenishment->select('', ['id' => $accounts_payable_voucher->type_id, 'is_deleted' => 0], 1);
                if ($replenishment) {
                    $petty_cash = $this->petty_cash->select('', ['id' => $replenishment->petty_cash_id, 'is_deleted' => 0], 1);
                    if ($petty_cash) {
                        $petty_cash_values = [
                            'balance' => $petty_cash->balance + $detail['amount'],
                            'updated_by' => $this->payload['requester'],
                            'updated_on' => date('Y-m-d H:i:s')
                        ];

                        if (!$this->petty_cash->update(['id' => $petty_cash->id], $petty_cash_values)) {
                            $this->error_message = $this->petty_cash->error();
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Soft-delete a master payment header after a failed branch-detail write.
     */
    private function _soft_delete_payment($accounts_payable_voucher_payment_id)
    {
        $this->accounts_payable_voucher_payment->update(
            ['id' => $accounts_payable_voucher_payment_id],
            [
                'is_deleted' => 1,
                'updated_by' => $this->payload['requester'] ?? null,
                'updated_on' => date('Y-m-d H:i:s'),
            ]
        );
    }

    private function _attach_payment_attachments($payments)
    {
        if (!is_array($payments) || empty($payments[0]['id'])) {
            return $payments;
        }

        $payments[0]['attachments'] = $this->accounts_payable_voucher_payment_attachment->get_details(
            null,
            $payments[0]['id']
        ) ?: [];

        return $payments;
    }

    /**
     * Update APV status
     */
    protected function _update_apv_status($details)
    {
        foreach ($details as $detail) {

            $cost_center_id = $detail['cost_center_id'] ?? null;
            if (!$cost_center_id) {
                return false;
            }

            if (!$this->resolve_branch_models($cost_center_id)) {
                return false;
            }

            $accounts_payable_voucher_id = $detail['accounts_payable_voucher_id'];
            $where = ['id' => $accounts_payable_voucher_id];

            $values = [
                'status' => 'closed',
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s')
            ];

            if (!$this->accounts_payable_voucher->update($where, $values)) {
                $this->error_message = $this->accounts_payable_voucher->error();
                return false;
            }
        }

        return true;
    }

    // /**
    //  *  Accounts_payable_voucher update payment detail
    //  */
    // public function update_payment()
    // {
    //     if (($response = $this->_api_validation()) !== true) {
    //         return $response;
    //     }

    //     $cost_center_id = $this->payload['cost_center_id'] ?? null;
    //     if (!$cost_center_id) {
    //         return $this->_create_response(
    //             FAIL,
    //             ['Cost center ID is required. Get it from the get API response.']
    //         );
    //     }

    //     if (!$this->resolve_branch_models($cost_center_id)) {
    //         return $this->_create_response(
    //             FAIL,
    //             ['Invalid Cost Center ID.']
    //         );
    //     }

    //     $response_status = SUCCESS;
    //     $accounts_payable_voucher_payment_detail_id = $this->payload['accounts_payable_voucher_payment_detail_id'];

    //     $where = ['id' => $accounts_payable_voucher_payment_detail_id, 'is_deleted' => 0];

    //     $this->db->transBegin();

    //     if (!$accounts_payable_voucher_payment_detail = $this->accounts_payable_voucher_payment_detail->select('', $where, 1)) {
    //         $this->db->transRollback();
    //         $response_status = FAIL_NOT_FOUND;
    //         $response = [
    //             'accounts_payable_voucher_payment_detail is not found.',
    //         ];
    //     } elseif (!$this->_attempt_update_payment($accounts_payable_voucher_payment_detail)) {
    //         $this->db->transRollback();
    //         $response_status = FAIL;
    //         $response = [
    //             'Failed to update accounts_payable_voucher_payment_detail.'
    //         ];
    //     } else {
    //         $this->db->transCommit();
    //         $response_status = SUCCESS;
    //         $response = [
    //             'message' => 'accounts_payable_voucher_payment_detail updated successfully.'
    //         ];
    //     }

    //     return $this->_create_response($response_status, $response);
    // }

    // /**
    //  * Attempt update payment detail
    //  */
    // protected function _attempt_update_payment($accounts_payable_voucher_payment_detail)
    // {
    //     $values = [
    //         // 'is_cleared'            => 1,
    //         'updated_by'            => $this->payload['requester'],
    //         'updated_on'            => date('Y-m-d H:i:s')
    //     ];

    //     // This iterates over each necessary field
    //     // $this->accounts_payable_voucher_payment_detail->allowedFields is declared at the top of the model
    //     foreach($this->accounts_payable_voucher_payment_detail->allowedFields AS $field) {
    //         if(!array_key_exists($field, $this->payload)) {
    //             continue;
    //         }
    //         $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
    //     }

    //     $where = ['id' => $accounts_payable_voucher_payment_detail->id];

    //     if (!$this->accounts_payable_voucher_payment_detail->update($where, $values)) {
    //         $this->error_message = $this->accounts_payable_voucher_payment_detail->error();
    //         return false;
    //     }

    //     //update apv status
    //     $accounts_payable_voucher_id = $accounts_payable_voucher_payment_detail->accounts_payable_voucher_id;
    //     $where_apv = ['id' => $accounts_payable_voucher_id];
    //     $values_apv = [
    //         'status' => 'closed',
    //         'updated_by' => $this->payload['requester'],
    //         'updated_on' => date('Y-m-d H:i:s')
    //     ];
    //     if (!$this->accounts_payable_voucher->update($where_apv, $values_apv)) {
    //         $this->error_message = $this->accounts_payable_voucher->error();
    //         return false;
    //     }

    //     return true;
    // }

    protected function _refund_matches_cost_center($type_id, $cost_center_id): bool
    {
        $refund = $this->refund->select('', ['id' => $type_id, 'is_deleted' => 0], 1);

        return $refund && (int) $refund->branch_id === (int) $cost_center_id;
    }

    protected function _attach_refund_to_apv(array &$row): void
    {
        if (($row['type'] ?? '') !== 'refund') {
            return;
        }

        $row['refund'] = $this->refund->get_details($row['type_id']) ?: [];
        $row['refund_details'] = $this->refund_detail->get_details($row['type_id']) ?: [];
    }

    protected function _attach_send_out_to_apv(array &$row): void
    {
        if (($row['type'] ?? '') !== 'send_out') {
            return;
        }

        $row['send_out'] = $this->send_out->get_details($row['type_id']) ?: [];
        $row['send_out_entries'] = $this->send_out_entry->get_details(null, $row['type_id']) ?: [];
    }

    protected function _patient_supplier_name($branch_id, $patient_id)
    {
        $db_map = $this->branch_db_map();
        if (! $patient_id || ! isset($db_map[(int) $branch_id])) {
            return null;
        }

        $patient = new \App\Models\Patient_diagnostic(db_connect($db_map[(int) $branch_id]));
        $row = $patient->get_patient_name($patient_id);

        return $row ? ($row->patient_name ?? null) : null;
    }
}
