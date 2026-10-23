<?php

namespace App\Libraries;

use App\Models\Billing_entry;
use App\Models\Billing_payment_entry;
use App\Models\Branch_master;
use App\Models\Memo_entries;
use App\Models\Yakap_billing;

/** Page-scoped memo children, sharing the billing patient/booking enrichment rules. */
class Memo_entries_response
{
    private Billing_entry $billing_entry;
    private Billing_collection_entries $enrichment;

    public function __construct($db, ?Billing_collection_entries $enrichment = null, ?Billing_entry $billing_entry = null)
    {
        $this->billing_entry = $billing_entry ?? new Billing_entry($db);
        $this->enrichment = $enrichment ?? new Billing_collection_entries(
            new Billing_payment_entry($db), $this->billing_entry,
            new Branch_master($db), new Yakap_billing($db)
        );
    }

    public function enrich(array $memos, Memo_entries $model, string $type): array
    {
        $rows = $model->for_memos(array_column($memos, 'id'));
        $ids = array_values(array_unique(array_column($rows, 'billing_entry_id')));
        $context = [];
        foreach ($ids ? ($this->billing_entry->get_details($ids) ?: []) : [] as $entry) {
            $context[(int) $entry['id']] = $this->enrichment->decorate_billing_entry($entry, $entry['charge_to'] ?? null);
        }
        $children = [];
        foreach ($rows as $row) {
            $entry = $context[(int) $row['billing_entry_id']] ?? [];
            $row['patient_id'] = $entry['patient_id'] ?? null;
            $row['patient_name'] = $entry['patient_name'] ?? null;
            $row['branch_name'] = $entry['branch_name'] ?? null;
            $row['invoice_no'] = $entry['invoice_no'] ?? null;
            $row['transaction_no'] = $entry['transaction_no'] ?? null;
            $row['booking_details'] = $entry['booking_details'] ?? [];
            $children[(int) $row[$type . '_id']][] = $row;
        }
        foreach ($memos as &$memo) {
            $memo['memo_entries'] = $children[(int) $memo['id']] ?? [];
        }
        unset($memo);
        return $memos;
    }
}
