<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\Accounts_payable_voucher;
use App\Models\Facility;
use App\Models\Report;

class Send_outs extends MYT_Controller
{
    protected $request;
    protected $facility;
    protected $report;
    protected $helpers = ['text'];

    protected $branch_db;
    protected $send_out;
    protected $send_out_entry;
    protected $send_out_attachment;

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
        $this->report = new Report();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }

    /**
     * Maps branch_id (master) to CodeIgniter database group names (per-branch clinic DB).
     */
    protected function branch_db_map(): array
    {
        return [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];
    }

    /**
     * Resolve send_out models for a diagnostic branch DB.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $dbMap = $this->branch_db_map();

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->branch_db = $db;
        $this->send_out = new \App\Models\Send_out($db);
        $this->send_out_entry = new \App\Models\Send_out_entry($db);
        $this->send_out_attachment = new \App\Models\Send_out_attachment($db);

        return true;
    }

    /**
     * Get send_outs
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $send_out_id = $this->payload['send_out_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;

        if ($branch_id && !$this->resolve_branch_models((int) $branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $pack = (new \App\Models\Send_out())->get_details_all_branches(
            $this->branch_db_map(),
            $branch_id,
            $send_out_id,
            $supplier_id,
            $status,
            $page,
            $data_size
        );
        $send_outs = $pack['data'];
        $size = $pack['size'];

        if (!$send_outs) {
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['No send_outs found.']
            );
        }

        foreach ($send_outs as $key => $send_out) {
            if (!$this->resolve_branch_models((int) $send_out['branch_id'])) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Branch ID.']
                );
            }

            $send_outs[$key]['entries'] = $this->send_out_entry->get_details(null, $send_out['id']) ?: [];
            $send_outs[$key]['attachments'] = $this->send_out_attachment->get_details(null, $send_out['id']) ?: [];
            $send_outs[$key]['facility_name'] = $this->_facility_names($send_out['facility_id'] ?? '');
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $send_outs,
        ]);
    }

    /**
     * Unused sendout_census rows (not yet saved as send_out_entry).
     */
    public function get_all_transaction()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        $date = $this->payload['date'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $facility_id = $this->payload['facility_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($branch_id && !isset($this->branch_db_map()[(int) $branch_id])) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $transactions = $this->report->get_sendout_census(
            $branch_id,
            $date,
            null,
            $facility_id,
            $term,
            $supplier_id,
            $page,
            $data_size,
            true,
            $date_from,
            $date_to
        ) ?: [];
        $size = count($this->report->get_sendout_census(
            $branch_id,
            $date,
            null,
            $facility_id,
            $term,
            $supplier_id,
            null,
            null,
            true,
            $date_from,
            $date_to
        ) ?: []);

        if (!$transactions) {
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['No transactions found.']
            );
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $transactions,
        ]);
    }

    /**
     * Unused sendout_census rows filtered by branch and supplier.
     */
    public function get_transaction()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        $supplier_id = $this->payload['supplier_id'] ?? null;
        $facility_id = $this->payload['facility_id'] ?? null;
        $date = $this->payload['date'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if (!$supplier_id) {
            return $this->_create_response(
                FAIL,
                ['Supplier ID is required.']
            );
        }

        if ($branch_id && !isset($this->branch_db_map()[(int) $branch_id])) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $transactions = $this->report->get_sendout_census(
            $branch_id,
            $date,
            null,
            $facility_id,
            $term,
            $supplier_id,
            $page,
            $data_size,
            true,
            $date_from,
            $date_to
        ) ?: [];
        $size = count($this->report->get_sendout_census(
            $branch_id,
            $date,
            null,
            $facility_id,
            $term,
            $supplier_id,
            null,
            null,
            true,
            $date_from,
            $date_to
        ) ?: []);

        if (!$transactions) {
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['No transactions found.']
            );
        }

        return $this->_create_response(SUCCESS, [
            'size' => $size,
            'data' => $transactions,
        ]);
    }

    /**
     * Create send_out header + send_out_entry rows, one header per entry branch_id group.
     */
    public function generate()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $required = ['supplier_id', 'due_date', 'invoice_no', 'invoice_date'];
        foreach ($required as $field) {
            if (!isset($this->payload[$field]) || $this->payload[$field] === '') {
                return $this->_create_response(
                    FAIL,
                    [ucfirst(str_replace('_', ' ', $field)) . ' is required.']
                );
            }
        }

        if (!array_key_exists('entries', $this->payload) || !is_array($this->payload['entries']) || count($this->payload['entries']) == 0) {
            return $this->_create_response(
                FAIL,
                ['entries is required.']
            );
        }

        $entry_required = [
            'branch_id',
            'sendout_census_id',
            'date',
            'facility_id',
            'transaction_id',
            'patient_id',
            'service_id',
        ];
        $seen_census_ids = [];
        $groups = [];
        foreach ($this->payload['entries'] as $index => $entry) {
            foreach ($entry_required as $field) {
                if (!isset($entry[$field]) || $entry[$field] === '') {
                    return $this->_create_response(
                        FAIL,
                        ["entries[{$index}][{$field}] is required."]
                    );
                }
            }

            $entry_branch_id = (int) $entry['branch_id'];
            if (!isset($this->branch_db_map()[$entry_branch_id])) {
                return $this->_create_response(
                    FAIL,
                    ["entries[{$index}][branch_id] is invalid."]
                );
            }

            $census_id = (int) $entry['sendout_census_id'];
            if (isset($seen_census_ids[$census_id])) {
                return $this->_create_response(
                    FAIL,
                    ["Duplicate sendout_census_id {$census_id} in entries."]
                );
            }
            $seen_census_ids[$census_id] = true;

            if ($this->_census_already_saved($census_id)) {
                return $this->_create_response(
                    FAIL,
                    ["sendout_census_id {$census_id} is already saved in a send out entry."]
                );
            }

            $groups[$entry_branch_id][] = $entry;
        }

        $created = [];
        foreach ($groups as $group_branch_id => $group_entries) {
            if (!$this->resolve_branch_models((int) $group_branch_id)) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Branch ID.']
                );
            }

            $this->branch_db->transBegin();

            $header = $this->_header_for_group((int) $group_branch_id, $group_entries);
            if (!$send_out_id = $this->_attempt_generate($header)) {
                $this->branch_db->transRollback();
                return $this->_create_response(
                    FAIL,
                    ['Failed to add send_out.']
                );
            }

            if (!$this->_attempt_generate_entries($send_out_id, $group_entries)) {
                $this->branch_db->transRollback();
                return $this->_create_response(
                    FAIL,
                    ['Failed to add send_out entries.']
                );
            }

            if (!$this->_attempt_create_attachments($send_out_id)) {
                $this->branch_db->transRollback();
                return $this->_create_response(
                    FAIL,
                    ['Failed to upload attachments. Make sure you have the correct file type (pdf, png, jpeg, jpg).']
                );
            }

            $this->branch_db->transCommit();
            $created[] = [
                'branch_id' => (int) $group_branch_id,
                'send_out_id' => $send_out_id,
            ];
        }

        return $this->_create_response(SUCCESS, [
            'send_out_ids' => $created,
            'message' => 'send_out generated successfully.',
        ]);
    }

    /**
     * Upload attachments[] to an existing send_out via VM4.
     */
    public function upload_attachment()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        $send_out_id = $this->payload['send_out_id'] ?? null;

        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$send_out_id) {
            return $this->_create_response(
                FAIL,
                ['Send out ID is required.']
            );
        }

        if (!$this->resolve_branch_models((int) $branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $attachments = $this->request->getFileMultiple('attachments') ?: [];
        $has_valid_file = false;
        foreach ($attachments as $file) {
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $has_valid_file = true;
                break;
            }
        }

        if (!$has_valid_file) {
            return $this->_create_response(
                FAIL,
                ['attachments[] is required.']
            );
        }

        $where = ['id' => $send_out_id, 'is_deleted' => 0];

        $this->branch_db->transBegin();

        if (!$this->send_out->select('', $where, 1)) {
            $this->branch_db->transRollback();
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['send_out is not found.']
            );
        }

        if (!$this->_attempt_create_attachments($send_out_id)) {
            $this->branch_db->transRollback();
            return $this->_create_response(
                FAIL,
                ['Failed to upload attachments. Make sure you have the correct file type (pdf, png, jpeg, jpg).']
            );
        }

        $this->branch_db->transCommit();

        return $this->_create_response(SUCCESS, [
            'send_out_id' => $send_out_id,
            'attachments' => $this->send_out_attachment->get_details(null, $send_out_id) ?: [],
            'message' => 'attachments uploaded successfully.',
        ]);
    }

    /**
     * Soft-delete send_out header and entries on the branch diagnostic DB.
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        $send_out_id = $this->payload['send_out_id'] ?? null;

        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$send_out_id) {
            return $this->_create_response(
                FAIL,
                ['Send out ID is required.']
            );
        }

        if (!$this->resolve_branch_models((int) $branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $where = ['id' => $send_out_id, 'is_deleted' => 0];

        $this->branch_db->transBegin();

        if (!$this->send_out->select('', $where, 1)) {
            $this->branch_db->transRollback();
            return $this->_create_response(
                FAIL_NOT_FOUND,
                ['send_out is not found.']
            );
        }

        $apv = new Accounts_payable_voucher($this->branch_db);
        if ($apv->select('id', [
            'type' => 'send_out',
            'type_id' => $send_out_id,
            'is_deleted' => 0,
        ], 1)) {
            $this->branch_db->transRollback();
            return $this->_create_response(
                FAIL,
                ['send_out has an accounts payable voucher and cannot be deleted.']
            );
        }

        if (!$this->_attempt_delete($send_out_id)) {
            $this->branch_db->transRollback();
            return $this->_create_response(
                FAIL,
                ['send_out is not deleted.']
            );
        }

        $this->branch_db->transCommit();

        return $this->_create_response(SUCCESS, [
            'message' => 'send_out is deleted.',
        ]);
    }

    /**
     * Build send_out header values for one branch group.
     */
    private function _header_for_group(int $branch_id, array $entries): array
    {
        $facility_ids = [];
        $total_sales = 0;
        $total_expense = 0;
        foreach ($entries as $entry) {
            $facility_id = trim((string) ($entry['facility_id'] ?? ''));
            if ($facility_id !== '' && !in_array($facility_id, $facility_ids, true)) {
                $facility_ids[] = $facility_id;
            }
            $total_sales += (float) ($entry['total_sales'] ?? 0);
            $total_expense += (float) ($entry['total_expense'] ?? 0);
        }

        $payload_facility_id = isset($this->payload['facility_id']) ? trim((string) $this->payload['facility_id']) : '';

        return [
            'branch_id' => $branch_id,
            'facility_id' => $payload_facility_id !== '' ? $payload_facility_id : implode(',', $facility_ids),
            'supplier_id' => $this->payload['supplier_id'],
            'due_date' => $this->payload['due_date'],
            'invoice_no' => $this->payload['invoice_no'] ?? null,
            'invoice_date' => $this->payload['invoice_date'] ?? null,
            'total_sales' => $total_sales,
            'total_expense' => $total_expense,
        ];
    }

    /**
     * Insert send_out header.
     */
    private function _attempt_generate(array $header)
    {
        $values = [
            'added_by' => $this->payload['requester'],
            'added_on' => date('Y-m-d H:i:s'),
        ];

        foreach ($this->send_out->allowedFields as $field) {
            if (!array_key_exists($field, $header)) {
                continue;
            }
            $values[$field] = $header[$field] != '' ? $header[$field] : null;
        }

        if (!$send_out_id = $this->send_out->insert($values)) {
            $this->error_message = $this->send_out->error();
            return false;
        }

        return $send_out_id;
    }

    /**
     * Insert send_out_entry rows.
     */
    private function _attempt_generate_entries($send_out_id, array $entries)
    {
        foreach ($entries as $entry) {
            $values = [
                'send_out_id' => $send_out_id,
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s'),
            ];

            foreach ($this->send_out_entry->allowedFields as $field) {
                if ($field === 'send_out_id' || !array_key_exists($field, $entry)) {
                    continue;
                }
                $values[$field] = $entry[$field] != '' ? $entry[$field] : ($field === 'total_sales' || $field === 'total_expense' ? 0 : null);
            }

            if (!$this->send_out_entry->insert($values)) {
                $this->error_message = $this->send_out_entry->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Upload attachments[] to VM4 and insert send_out_attachment rows.
     */
    private function _attempt_create_attachments($send_out_id)
    {
        $attachments = $this->request->getFileMultiple('attachments');
        if (empty($attachments)) {
            return true;
        }

        $file_path = 'assets/send_outs/' . $send_out_id . '/';

        foreach ($attachments as $file) {
            if (!$file || !$file->isValid() || $file->hasMoved()) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), ['pdf', 'png', 'jpeg', 'jpg'])) {
                return false;
            }

            $upload_result = upload_to_vm4($file, $file_path);
            if (!$upload_result || empty($upload_result['success'])) {
                $this->error_message = 'upload failed: ' . ($upload_result['error'] ?? 'unknown');
                return false;
            }

            $values = [
                'send_out_id' => $send_out_id,
                'file_name' => $file->getName(),
                'file_path' => $upload_result['path'],
                'file_url' => VM4_ENDPOINT . $upload_result['path'],
                'mime' => $file->getMimeType(),
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s'),
            ];

            if (!$this->send_out_attachment->insert($values)) {
                $this->error_message = $this->send_out_attachment->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Soft-delete header and entries.
     */
    private function _attempt_delete($send_out_id)
    {
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        if (!$this->send_out->update(['id' => $send_out_id], $values)) {
            $this->error_message = $this->send_out->error();
            return false;
        }

        $entries = $this->send_out_entry->select('id', [
            'send_out_id' => $send_out_id,
            'is_deleted' => 0,
        ]) ?: [];

        foreach ($entries as $entry) {
            $entry_id = is_object($entry) ? $entry->id : ($entry['id'] ?? null);
            if (!$entry_id) {
                continue;
            }
            if (!$this->send_out_entry->update(['id' => $entry_id], $values)) {
                $this->error_message = $this->send_out_entry->error();
                return false;
            }
        }

        $attachments = $this->send_out_attachment->select('id', [
            'send_out_id' => $send_out_id,
            'is_deleted' => 0,
        ]) ?: [];

        foreach ($attachments as $attachment) {
            $attachment_id = is_object($attachment) ? $attachment->id : ($attachment['id'] ?? null);
            if (!$attachment_id) {
                continue;
            }
            if (!$this->send_out_attachment->update(['id' => $attachment_id], $values)) {
                $this->error_message = $this->send_out_attachment->error();
                return false;
            }
        }

        return true;
    }

    /**
     * True when sendout_census_id is already on a live send_out_entry in any diagnostic DB.
     */
    private function _census_already_saved($sendout_census_id): bool
    {
        foreach ($this->branch_db_map() as $branch_id => $group) {
            $this->resolve_branch_models((int) $branch_id);
            $row = $this->send_out_entry->select('id', [
                'sendout_census_id' => $sendout_census_id,
                'is_deleted' => 0,
            ], 1);
            if ($row) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve comma-separated facility IDs to names.
     */
    private function _facility_names($facility_id_csv): ?string
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $facility_id_csv)), static function ($id) {
            return $id !== '';
        }));

        if (!$ids) {
            return null;
        }

        $names = [];
        foreach ($ids as $id) {
            $facility = $this->facility->select('name', ['id' => $id, 'is_deleted' => 0], 1);
            $names[] = $facility ? $facility->name : $id;
        }

        return implode(', ', $names);
    }
}
