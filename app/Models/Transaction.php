<?php

namespace App\Core;

namespace App\Models;

use App\Core\MYT_Model;

class Transaction extends MYT_Model
{
    protected $table         = 'transaction';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'queue_id',
        'transaction_no',
        'patient_id',
        'endorsement_id',
        'transaction_date',
        'grand_total',
        'paid_amount',
        'is_billed',
        'is_paid',
        'billing_id',
        'is_addon',
        'hmo_id',
        'copay',
        'membership_id',
        'is_worklist',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    /**
     * Load one CSR transaction and related rows for report tracing.
     * Includes soft-deleted rows. Returns null when the hub row is missing.
     *
     * @return array<string, mixed>|null
     */
    public function get_for_tracing($transaction_no, $patient_id, $transaction_date, $branch_id)
    {
        $transaction = $this->_find_for_tracing($transaction_no, $patient_id, $transaction_date);
        if (! $transaction) {
            return null;
        }

        $txn_id    = (int) ($transaction['id'] ?? 0);
        $branch_id = (int) $branch_id;
        $queue_id  = (int) ($transaction['queue_id'] ?? 0);

        $bookings    = $this->_rows(
            'SELECT booking.* FROM booking WHERE booking.transaction_id = ? ORDER BY booking.id',
            [$txn_id]
        );
        $booking_ids = $this->_int_ids($bookings, 'id');

        $details = $this->_rows_in(
            'SELECT booking_details.* FROM booking_details WHERE booking_details.booking_id {in} ORDER BY booking_details.id',
            $booking_ids
        );
        $detail_ids = $this->_int_ids($details, 'id');

        $package_details = $this->_rows_in(
            'SELECT booking_package_details.* FROM booking_package_details WHERE booking_package_details.booking_detail_id {in} ORDER BY booking_package_details.id',
            $detail_ids
        );

        $payments    = $this->_rows(
            'SELECT payment.* FROM payment WHERE payment.transaction_id = ? ORDER BY payment.id',
            [$txn_id]
        );
        $payment_ids = $this->_int_ids($payments, 'id');

        $payment_methods = $this->_rows_in(
            'SELECT payment_multiple_method.* FROM payment_multiple_method WHERE payment_multiple_method.payment_id {in} ORDER BY payment_multiple_method.id',
            $payment_ids
        );
        $payment_discounts = $this->_rows_in(
            'SELECT payment_discount.* FROM payment_discount WHERE payment_discount.payment_id {in} ORDER BY payment_discount.id',
            $payment_ids
        );

        $send_out_entries = $this->_rows(
            'SELECT send_out_entry.* FROM send_out_entry WHERE send_out_entry.transaction_id = ? ORDER BY send_out_entry.id',
            [$txn_id]
        );
        $booking_medicines = $this->_rows_in(
            'SELECT booking_medicine.* FROM booking_medicine WHERE booking_medicine.booking_id {in} ORDER BY booking_medicine.id',
            $booking_ids
        );
        $booking_doctors_fees = $this->_rows(
            'SELECT booking_doctors_fee.* FROM booking_doctors_fee WHERE booking_doctors_fee.transaction_id = ? ORDER BY booking_doctors_fee.id',
            [$txn_id]
        );

        $patient_queue_sql    = 'SELECT patient_queue.* FROM patient_queue WHERE patient_queue.transaction_id = ?';
        $patient_queue_binds  = [$txn_id];
        if ($queue_id > 0) {
            $patient_queue_sql   .= ' OR patient_queue.id = ?';
            $patient_queue_binds[] = $queue_id;
        }
        $patient_queue_sql .= ' ORDER BY patient_queue.id';
        $patient_queue      = $this->_rows($patient_queue_sql, $patient_queue_binds);

        $patient_tat = $this->_rows(
            'SELECT patient_tat.* FROM patient_tat WHERE patient_tat.transaction_id = ? ORDER BY patient_tat.id',
            [$txn_id]
        );

        $activity_ids  = array_merge($detail_ids, $this->_int_ids($package_details, 'id'));
        $item_history  = $this->_rows_in(
            "SELECT item_history.* FROM item_history
WHERE item_history.activity_id {in}
AND item_history.activity IN ('perform', 'Machine Run', 'lab supplies', 'pharmacy')
ORDER BY item_history.id",
            $activity_ids
        );

        $billing_entries = $this->_rows(
            'SELECT billing_entry.* FROM {MASTER_DB}.billing_entry AS billing_entry
WHERE billing_entry.transaction_id = ?
AND billing_entry.branch_id = ?
ORDER BY billing_entry.id',
            [$txn_id, $branch_id]
        );
        $billing_ids = $this->_int_ids($billing_entries, 'billing_id');
        $billings    = $this->_rows_in(
            'SELECT billing.* FROM {MASTER_DB}.billing AS billing WHERE billing.id {in} ORDER BY billing.id',
            $billing_ids
        );

        $billing_entry_ids       = $this->_int_ids($billing_entries, 'id');
        $billing_payment_entries = $this->_rows_in(
            'SELECT billing_payment_entry.*,
    billing_payment.date AS billing_payment_date,
    billing_payment.mop,
    billing_payment.reference_no,
    billing_payment.grand_total AS billing_payment_grand_total,
    billing_payment.paid_date
FROM {MASTER_DB}.billing_payment_entry AS billing_payment_entry
LEFT JOIN {MASTER_DB}.billing_payment AS billing_payment
    ON billing_payment.id = billing_payment_entry.billing_payment_id
WHERE billing_payment_entry.billing_entry_id {in}
ORDER BY billing_payment_entry.id',
            $billing_entry_ids
        );

        $refunds    = $this->_rows(
            'SELECT refund.* FROM {MASTER_DB}.refund AS refund
WHERE refund.transaction_id = ?
AND refund.branch_id = ?
ORDER BY refund.id',
            [$txn_id, $branch_id]
        );
        $refund_ids = $this->_int_ids($refunds, 'id');
        $refund_details = $this->_rows_in(
            'SELECT refund_detail.* FROM {MASTER_DB}.refund_detail AS refund_detail
WHERE refund_detail.refund_id {in}
ORDER BY refund_detail.id',
            $refund_ids
        );

        $general_ledger   = $this->_general_ledger_for_tracing(
            $txn_id,
            $this->_int_ids($payments, 'id'),
            $this->_int_ids($payment_methods, 'id'),
            $this->_int_ids($item_history, 'id')
        );
        $journal_entries  = $this->_rows(
            "SELECT journal_entry.* FROM journal_entry
WHERE journal_entry.source = 'transaction'
AND journal_entry.source_id = ?
ORDER BY journal_entry.id",
            [$txn_id]
        );
        $journal_entry_ids = $this->_int_ids($journal_entries, 'id');
        $journal_items     = $this->_rows_in(
            'SELECT journal_entry_item.* FROM journal_entry_item
WHERE journal_entry_item.journal_entry_id {in}
ORDER BY journal_entry_item.id',
            $journal_entry_ids
        );

        $nested_bookings = $this->_nest_bookings($bookings, $details, $package_details);
        $nested_payments = $this->_nest_payments($payments, $payment_methods, $payment_discounts);
        $nested_refunds  = $this->_nest_by_child($refunds, $refund_details, 'refund_id', 'refund_details');
        $nested_jes      = $this->_nest_by_child($journal_entries, $journal_items, 'journal_entry_id', 'items');

        return [
            'branch_id'               => $branch_id,
            'transaction'             => $transaction,
            'bookings'                => $nested_bookings,
            'payments'                => $nested_payments,
            'billing_entries'         => $billing_entries,
            'billings'                => $billings,
            'billing_payment_entries' => $billing_payment_entries,
            'refunds'                 => $nested_refunds,
            'send_out_entries'        => $send_out_entries,
            'booking_medicines'       => $booking_medicines,
            'booking_doctors_fees'    => $booking_doctors_fees,
            'patient_queue'           => $patient_queue,
            'patient_tat'             => $patient_tat,
            'item_history'            => $item_history,
            'general_ledger'          => $general_ledger,
            'journal_entries'         => $nested_jes,
            'trace'                   => [
                'sales_report' => $this->_sales_report_trace($transaction, $bookings, $details),
                'billed'       => [
                    'is_billed'           => (int) ($transaction['is_billed'] ?? 0),
                    'billing_entry_count' => count($billing_entries),
                ],
                'gl'           => $this->_gl_trace($general_ledger, $nested_jes),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function _find_for_tracing($transaction_no, $patient_id, $transaction_date)
    {
        $key   = $this->encryption_key;
        $binds = [$transaction_no];
        $sql   = <<<EOT
SELECT transaction.*,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM transaction
LEFT JOIN patient ON patient.id = transaction.patient_id
WHERE transaction.transaction_no = ?
EOT;

        if ($patient_id !== null && $patient_id !== '') {
            $sql    .= "\nAND transaction.patient_id = ?";
            $binds[] = $patient_id;
        }

        if ($transaction_date !== null && $transaction_date !== '') {
            $sql    .= "\nAND DATE(transaction.transaction_date) = ?";
            $binds[] = $transaction_date;
        }

        $sql .= <<<EOT

ORDER BY transaction.is_deleted ASC, transaction.id DESC
LIMIT 1
EOT;

        $rows = $this->_rows($sql, $binds);

        return $rows[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $bookings
     * @param list<array<string, mixed>> $details
     * @param list<array<string, mixed>> $package_details
     * @return list<array<string, mixed>>
     */
    private function _nest_bookings(array $bookings, array $details, array $package_details)
    {
        $packages_by_detail = $this->_group_by($package_details, 'booking_detail_id');
        $details_by_booking = $this->_group_by($details, 'booking_id');
        $nested             = [];

        foreach ($bookings as $booking) {
            $booking_details = $details_by_booking[(int) ($booking['id'] ?? 0)] ?? [];
            foreach ($booking_details as &$detail) {
                $detail['booking_package_details'] = $packages_by_detail[(int) ($detail['id'] ?? 0)] ?? [];
            }
            unset($detail);
            $booking['booking_details'] = $booking_details;
            $nested[]                   = $booking;
        }

        return $nested;
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @param list<array<string, mixed>> $methods
     * @param list<array<string, mixed>> $discounts
     * @return list<array<string, mixed>>
     */
    private function _nest_payments(array $payments, array $methods, array $discounts)
    {
        $methods_by_payment   = $this->_group_by($methods, 'payment_id');
        $discounts_by_payment = $this->_group_by($discounts, 'payment_id');
        $nested               = [];

        foreach ($payments as $payment) {
            $payment_id                         = (int) ($payment['id'] ?? 0);
            $payment['payment_multiple_method'] = $methods_by_payment[$payment_id] ?? [];
            $payment['payment_discount']        = $discounts_by_payment[$payment_id] ?? [];
            $nested[]                           = $payment;
        }

        return $nested;
    }

    /**
     * @param list<array<string, mixed>> $parents
     * @param list<array<string, mixed>> $children
     * @return list<array<string, mixed>>
     */
    private function _nest_by_child(array $parents, array $children, string $fk, string $child_key)
    {
        $by_parent = $this->_group_by($children, $fk);
        $nested    = [];
        foreach ($parents as $parent) {
            $parent[$child_key] = $by_parent[(int) ($parent['id'] ?? 0)] ?? [];
            $nested[]           = $parent;
        }

        return $nested;
    }

    /**
     * @param list<int> $payment_ids
     * @param list<int> $pmm_ids
     * @param list<int> $item_history_ids
     * @return list<array<string, mixed>>
     */
    private function _general_ledger_for_tracing($txn_id, array $payment_ids, array $pmm_ids, array $item_history_ids)
    {
        $parts  = ['(general_ledger.source = ? AND general_ledger.source_id = ?)'];
        $binds  = ['transaction', (int) $txn_id];
        $in_map = [
            'payment'                 => $payment_ids,
            'payment_multiple_method' => $pmm_ids,
            'item_history'            => $item_history_ids,
        ];

        foreach ($in_map as $source => $ids) {
            $in = $this->_in_clause($ids);
            if (! $in) {
                continue;
            }
            $parts[] = "(general_ledger.source = ? AND general_ledger.source_id {$in[0]})";
            $binds[] = $source;
            $binds   = array_merge($binds, $in[1]);
        }

        $where = implode("\n    OR ", $parts);
        $sql   = <<<EOT
SELECT general_ledger.*,
    account_type.code AS account_code,
    account_type.name AS account_name
FROM general_ledger
LEFT JOIN {MASTER_DB}.account_type AS account_type
    ON account_type.id = general_ledger.account_type_id
WHERE {$where}
ORDER BY general_ledger.id
EOT;

        return $this->_rows($sql, $binds);
    }

    /**
     * Mirrors Booking::get_transaction_sales() eligibility filters.
     *
     * @param array<string, mixed> $transaction
     * @param list<array<string, mixed>> $bookings
     * @param list<array<string, mixed>> $details
     * @return array{eligible: bool, skip_reasons: list<string>}
     */
    private function _sales_report_trace(array $transaction, array $bookings, array $details)
    {
        $reasons            = [];
        $details_by_booking = $this->_group_by($details, 'booking_id');

        if ((int) ($transaction['is_deleted'] ?? 0) !== 0) {
            $reasons[] = 'transaction is_deleted';
        }
        if ((int) ($transaction['is_worklist'] ?? 0) !== 0) {
            $reasons[] = 'is_worklist';
        }
        if ((float) ($transaction['grand_total'] ?? 0) <= 0) {
            $reasons[] = 'grand_total <= 0';
        }

        $has_eligible_lines = false;
        $has_consultation   = false;
        foreach ($bookings as $booking) {
            if ((int) ($booking['is_deleted'] ?? 0) !== 0) {
                continue;
            }
            if ((int) ($booking['package_id'] ?? 0) !== 0) {
                continue;
            }

            $has_wallet     = false;
            $has_non_wallet = false;
            foreach ($details_by_booking[(int) ($booking['id'] ?? 0)] ?? [] as $line) {
                if ((int) ($line['is_deleted'] ?? 0) !== 0) {
                    continue;
                }
                if (($line['type'] ?? '') === 'wallet') {
                    $has_wallet = true;
                } else {
                    $has_non_wallet = true;
                }
            }

            if ($has_non_wallet) {
                $has_eligible_lines = true;
            }
            if (($booking['transaction_type'] ?? '') === 'consultation' && ! $has_wallet) {
                $has_consultation = true;
            }
        }

        if (! $has_eligible_lines && ! $has_consultation) {
            $reasons[] = 'no eligible booking lines (wallet-only, package_id set, or no details)';
        }

        return [
            'eligible'     => $reasons === [],
            'skip_reasons' => $reasons,
        ];
    }

    /**
     * @param list<array<string, mixed>> $general_ledger
     * @param list<array<string, mixed>> $journal_entries
     * @return array{ledger_count: int, journal_entry_count: int, total_debit: float, total_credit: float}
     */
    private function _gl_trace(array $general_ledger, array $journal_entries)
    {
        $debit  = 0.0;
        $credit = 0.0;
        foreach ($general_ledger as $row) {
            $debit  += (float) ($row['debit'] ?? 0);
            $credit += (float) ($row['credit'] ?? 0);
        }

        return [
            'ledger_count'        => count($general_ledger),
            'journal_entry_count' => count($journal_entries),
            'total_debit'         => round($debit, 2),
            'total_credit'        => round($credit, 2),
        ];
    }

    /**
     * @param list<mixed> $binds
     * @return list<array<string, mixed>>
     */
    private function _rows(string $sql, array $binds = [])
    {
        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Replace `{in}` with `IN (?,?,?)`. Returns [] when $ids is empty.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function _rows_in(string $sql, array $ids)
    {
        $in = $this->_in_clause($ids);
        if (! $in) {
            return [];
        }

        return $this->_rows(str_replace('{in}', $in[0], $sql), $in[1]);
    }

    /**
     * @param list<int|string> $ids
     * @return array{0: string, 1: list<int>}|null
     */
    private function _in_clause(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));
        if (! $ids) {
            return null;
        }

        return ['IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function _int_ids(array $rows, string $key)
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$key] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private function _group_by(array $rows, string $key)
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) ($row[$key] ?? 0)][] = $row;
        }

        return $grouped;
    }
}
