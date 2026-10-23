<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Billing_payment extends MYT_Model
{
    protected $table = 'billing_payment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'charge_to',
        'is_unidentified',
        'mop',
        'date',
        'reference_no',
        'check_no',
        'check_date',
        'bank_name',
        'others',
        'paid_date',
        'remarks',
        'account_type_id',
        'bank_check',
        'wht_amount',
        'wht_percent',
        'wht_account',
        'grand_total',
        'is_migrated',
    ];

    public function get_details($billing_payment_id = null, $mop = null, $date = null, $charge_to = null, $page = null, $data_size = null, $is_unidentified = null, $branch_id = null, $date_from = null, $date_to = null) {
        [$sql, $binds] = $this->_details_sql($billing_payment_id, $mop, $date, $charge_to, $is_unidentified, $branch_id, $date_from, $date_to);
        $sql .= sql_limit_clause($page, $data_size);
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function count_details($billing_payment_id = null, $mop = null, $date = null, $charge_to = null, $is_unidentified = null, $branch_id = null, $date_from = null, $date_to = null): int
    {
        [$sql, $binds] = $this->_details_sql($billing_payment_id, $mop, $date, $charge_to, $is_unidentified, $branch_id, $date_from, $date_to);
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query('SELECT COUNT(*) AS total FROM (' . $sql . ') AS list_count', $binds);
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    private function _details_sql($billing_payment_id = null, $mop = null, $date = null, $charge_to = null, $is_unidentified = null, $branch_id = null, $date_from = null, $date_to = null): array
    {
        $binds = [];
        $sql = <<<EOT
SELECT billing_payment.*, account_type.name AS account_type_name, wht_account_type.name AS wht_account_name
FROM billing_payment
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = billing_payment.account_type_id
LEFT JOIN {MASTER_DB}.account_type AS wht_account_type ON wht_account_type.id = billing_payment.wht_account
WHERE billing_payment.is_deleted = 0
EOT;

        if ($billing_payment_id) {
            $sql .= "\nAND billing_payment.id = ?";
            $binds[] = $billing_payment_id;
        }
        if ($mop) {
            $sql .= "\nAND billing_payment.mop = ?";
            $binds[] = $mop;
        }
        if ($date) {
            $sql .= "\nAND billing_payment.date = ?";
            $binds[] = $date;
        }
        if ($date_from) {
            $sql .= "\nAND billing_payment.paid_date >= ?";
            $binds[] = $date_from;
        }
        if ($date_to) {
            $sql .= "\nAND billing_payment.paid_date < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $date_to;
        }
        if ($charge_to) {
            $sql .= "\nAND billing_payment.charge_to = ?";
            $binds[] = $charge_to;
        }
        if ($is_unidentified !== null && $is_unidentified !== '') {
            $sql .= "\nAND billing_payment.is_unidentified = ?";
            $binds[] = (int) $is_unidentified;
        }
        if ($branch_id) {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1
    FROM billing_payment_entry bpe
    INNER JOIN billing_entry be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
    WHERE bpe.billing_payment_id = billing_payment.id
    AND bpe.is_deleted = 0
    AND be.branch_id = ?
)
EOT;
            $binds[] = $branch_id;
        }

        $sql .= "\nORDER BY billing_payment.paid_date DESC, billing_payment.id DESC";

        return [$sql, $binds];
    }

    /**
     * Payments applied to the given billings (via billing_payment_entry).
     * One row per billing_id + billing_payment.id.
     *
     * @param int[] $billing_ids
     * @return list<array<string, mixed>>
     */
    public function get_details_for_billing_ids(array $billing_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $billing_ids))));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT billing_payment.*,
    billing_entry.billing_id,
    account_type.name AS account_type_name
FROM billing_payment
INNER JOIN billing_payment_entry
    ON billing_payment_entry.billing_payment_id = billing_payment.id
    AND billing_payment_entry.is_deleted = 0
INNER JOIN billing_entry
    ON billing_entry.id = billing_payment_entry.billing_entry_id
    AND billing_entry.is_deleted = 0
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = billing_payment.account_type_id
WHERE billing_payment.is_deleted = 0
AND billing_entry.billing_id IN ($placeholders)
EOT;
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $ids);
        $rows = $query ? $query->getResultArray() : [];
        $unique = [];
        foreach ($rows as $row) {
            $key = (int) ($row['billing_id'] ?? 0) . ':' . (int) ($row['id'] ?? 0);
            $unique[$key] = $row;
        }

        return array_values($unique);
    }
}

?>
