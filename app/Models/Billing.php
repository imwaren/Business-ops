<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Billing extends MYT_Model
{
    protected $table = 'billing';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'company_id',
        'charge_to',
        'due_date',
        'date_from',
        'date_to',
        'grand_total',
        'credit_memo_total',
        'debit_memo_total',
        'status',
        'approved_by',
        'approved_on',
        'invoice_id',
        'invoice_no',
        'invoice_date',
        'invoice_status',
        'is_migrated',
    ];

    public function get_details($billing_id = null, $date_from = null, $date_to = null, $company_id = null, $charge_to = null, $status = null, $page = null, $data_size = null, $term = null, $branch_id = null, $screen = null) {
        [$sql, $binds] = $this->_details_sql($billing_id, $date_from, $date_to, $company_id, $charge_to, $status, $term, $branch_id, $screen);
        $sql .= sql_limit_clause($page, $data_size);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function count_details($billing_id = null, $date_from = null, $date_to = null, $company_id = null, $charge_to = null, $status = null, $term = null, $branch_id = null, $screen = null): int
    {
        [$sql, $binds] = $this->_details_sql($billing_id, $date_from, $date_to, $company_id, $charge_to, $status, $term, $branch_id, $screen);
        $query = $this->db->query('SELECT COUNT(*) AS total FROM (' . $sql . ') AS list_count', $binds);
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    private function _details_sql($billing_id = null, $date_from = null, $date_to = null, $company_id = null, $charge_to = null, $status = null, $term = null, $branch_id = null, $screen = null): array
    {
        $binds = [];
        $sql = <<<EOT
SELECT billing.*,
    billing.grand_total - billing.credit_memo_total + billing.debit_memo_total - billing.balance AS paid_amount
FROM billing
LEFT JOIN company ON company.id = billing.company_id AND company.is_deleted = 0
WHERE billing.is_deleted = 0
EOT;

        if ($billing_id) {
            $sql .= "\nAND billing.id = ?";
            $binds[] = $billing_id;
        }

        if ($date_from) {
            $sql .= "\nAND billing.date_from >= ?";
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= "\nAND billing.date_to <= ?";
            $binds[] = $date_to;
        }

        if ($company_id) {
            $sql .= "\nAND billing.company_id = ?";
            $binds[] = $company_id;
        }

        if ($charge_to) {
            $sql .= "\nAND billing.charge_to = ?";
            $binds[] = $charge_to;
        }

        if ($status) {
            if ($status == 'for_cancellation') {
                $sql .= "\nAND billing.is_for_cancellation = 1";
            } else {
                $sql .= "\nAND billing.status = ?";
                $binds[] = $status;
            }
        }

        if ($term) {
            $sql .= "\nAND billing.invoice_no LIKE ?";
            $binds[] = '%' . $term . '%';
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1 FROM billing_entry
    WHERE billing_entry.billing_id = billing.id
    AND billing_entry.branch_id = ?
    AND billing_entry.is_deleted = 0
)
EOT;
            $binds[] = $branch_id;
        }

        if ($screen === 'match_unidentified') {
            $sql .= "\nAND billing.balance != 0";
        }

        if ($status === 'pending' || $status === 'open_bill') {
            $sql .= "\nORDER BY billing.due_date ASC, billing.id ASC";
        } elseif ($status === 'closed_bill') {
            $sql .= "\nORDER BY company.name ASC, billing.id ASC";
        }

        return [$sql, $binds];
    }

    public function get_for_update($billing_id)
    {
        $sql = 'SELECT * FROM billing WHERE id = ? AND is_deleted = 0 FOR UPDATE';
        $query = $this->db->query($sql, [$billing_id]);
        return $query ? $query->getRow() : false;
    }

    public function apply_memo($billing_id, $amount, $memo_type, $requester): bool
    {
        if (!is_numeric($amount) || $amount < 0 || !in_array($memo_type, ['credit', 'debit'], true)) {
            return false;
        }

        $column = $memo_type === 'credit' ? 'credit_memo_total' : 'debit_memo_total';
        $operator = $memo_type === 'credit' ? '-' : '+';
        $sql = <<<EOT
UPDATE billing
SET status = CASE
        WHEN status IN ('approved', 'open_bill', 'closed_bill') THEN
            CASE WHEN balance $operator CAST(? AS DECIMAL(13,2)) <= 0 THEN 'closed_bill' ELSE 'open_bill' END
        ELSE status
    END,
    $column = $column + CAST(? AS DECIMAL(13,2)),
    balance = balance $operator CAST(? AS DECIMAL(13,2)),
    updated_by = ?,
    updated_on = ?
WHERE id = ? AND is_deleted = 0
EOT;

        return $this->db->query($sql, [$amount, $amount, $amount, $requester, date('Y-m-d H:i:s'), $billing_id]) !== false;
    }

    /**
     * @param array<int|string>|int|string|null $billing_id
     * @return int[]|null null = no filter; [] = invalid/empty list
     */
    public function normalize_billing_ids($billing_id): ?array
    {
        if ($billing_id === null || $billing_id === '' || $billing_id === false) {
            return null;
        }

        if (is_array($billing_id)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $billing_id))));

            return $ids ?: [];
        }

        if (is_string($billing_id) && str_contains($billing_id, ',')) {
            return array_values(array_unique(array_filter(array_map('intval', explode(',', $billing_id)))));
        }

        $id = (int) $billing_id;

        return $id > 0 ? [$id] : [];
    }

    /**
     * Billings for collect validation: every id must exist and match charge_to.
     *
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    public function get_by_ids_and_charge_to(array $ids, $charge_to): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || $charge_to === null || $charge_to === '' || $charge_to === false) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $binds = [(int) $charge_to];
        foreach ($ids as $id) {
            $binds[] = $id;
        }

        $sql = <<<EOT
SELECT billing.id, billing.charge_to, billing.balance, billing.grand_total, billing.status,
       billing.approved_by, billing.approved_on
FROM billing
WHERE billing.is_deleted = 0
AND billing.charge_to = ?
AND billing.id IN ($placeholders)
EOT;
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * open/closed are legacy collection lifecycle states and therefore
     * represent billings that already passed approval.
     */
    public function is_approved_for_collection(array $billing): bool
    {
        if (in_array($billing['status'] ?? null, ['approved', 'open_bill', 'closed_bill'], true)) {
            return true;
        }

        return !empty($billing['approved_by']) && !empty($billing['approved_on']);
    }

    public function get_soa($billing_id = null, $date_from = null, $date_to = null, $company_id = null, $charge_to = null, $status = null, $page = null, $data_size = null) {
        [$sql, $binds] = $this->_soa_sql($billing_id, $date_from, $date_to, $company_id, $charge_to, $status);
        $this->db->query('SET SESSION group_concat_max_len = 1000000');
        $sql .= sql_limit_clause($page, $data_size);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function count_soa($billing_id = null, $date_from = null, $date_to = null, $company_id = null, $charge_to = null, $status = null): int
    {
        [$sql, $binds] = $this->_soa_sql($billing_id, $date_from, $date_to, $company_id, $charge_to, $status);
        $query = $this->db->query('SELECT COUNT(*) AS total FROM (' . $sql . ') AS list_count', $binds);
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    private function _soa_sql($billing_id = null, $date_from = null, $date_to = null, $company_id = null, $charge_to = null, $status = null): array
    {
        $binds = [];
        $sql = <<<EOT
SELECT billing.company_id, billing.charge_to,
    GROUP_CONCAT(DISTINCT billing.id) AS billing_ids,
    SUM(billing.grand_total) AS grand_total,
    SUM(billing.balance) AS balance,
    (SUM(billing.grand_total) - SUM(billing.balance)) AS paid_amount
FROM billing
WHERE billing.is_deleted = 0
EOT;

        if ($billing_id) {
            $sql .= "\nAND billing.id = ?";
            $binds[] = $billing_id;
        }
        if ($date_from) {
            $sql .= "\nAND billing.date_from >= ?";
            $binds[] = $date_from;
        }
        if ($date_to) {
            $sql .= "\nAND billing.date_to <= ?";
            $binds[] = $date_to;
        }
        if ($company_id) {
            $sql .= "\nAND billing.company_id = ?";
            $binds[] = $company_id;
        }
        if ($charge_to) {
            $sql .= "\nAND billing.charge_to = ?";
            $binds[] = $charge_to;
        }
        if ($status) {
            if ($status == 'for_cancellation') {
                $sql .= "\nAND billing.is_for_cancellation = 1";
            } else {
                $sql .= "\nAND billing.status = ?";
                $binds[] = $status;
            }
        }

        $sql .= " GROUP BY billing.company_id, billing.charge_to ";

        return [$sql, $binds];
    }

    public function get_customer($charge_to = null) {
        $binds = [];
        $sql = <<<EOT
SELECT billing.charge_to
FROM billing
WHERE billing.is_deleted = 0
EOT;

        if ($charge_to) {
            $sql .= <<<EOT

AND billing.charge_to = ?
EOT;
            $binds[] = $charge_to;
        }

        //group by charge_to to get unique customers
        $sql .= " GROUP BY billing.charge_to";

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_billing($charge_to = null, $branch_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT billing.id, 
    billing.charge_to, 
    billing.date_from, 
    billing.date_to, 
    billing.grand_total, 
    billing.invoice_id, 
    billing.invoice_status
FROM billing
WHERE billing.is_deleted = 0
EOT;

        if ($charge_to) {
            $sql .= <<<EOT

AND billing.charge_to = ?
EOT;
            $binds[] = $charge_to;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1 FROM billing_entry
    WHERE billing_entry.billing_id = billing.id
    AND billing_entry.branch_id = ?
    AND billing_entry.is_deleted = 0
)
EOT;
            $binds[] = $branch_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_billing_sales_entry($billing_id = null, $date = null, $branch_id = null) {
        $binds = [];

        // Add the current date to the binds array multiple times as it's used in multiple places in the query
        $binds[] = $date;
        $binds[] = $date;
        $binds[] = $date;
        $binds[] = $date;
        $binds[] = $date;
        $binds[] = $date;
        $binds[] = $date;
        $binds[] = $date;
        
        $sql = <<<EOT
SELECT billing_entry.booking_id,
    billing_entry.transaction_id,
    billing_entry.branch_id,
    CASE
        WHEN billing.due_date >= ? THEN billing_entry.balance
        ELSE 0
    END AS current,
    CASE
        WHEN billing.due_date < ? AND billing.due_date >= DATE_SUB(?, INTERVAL 30 DAY) THEN billing_entry.balance
        ELSE 0
    END AS `1-30_days`,
    CASE
        WHEN billing.due_date < DATE_SUB(?, INTERVAL 31 DAY) AND billing.due_date >= DATE_SUB(?, INTERVAL 60 DAY) THEN billing_entry.balance
        ELSE 0
    END AS `31-60_days`,
    CASE
        WHEN billing.due_date < DATE_SUB(?, INTERVAL 61 DAY) AND billing.due_date >= DATE_SUB(?, INTERVAL 90 DAY) THEN billing_entry.balance
        ELSE 0
    END AS `61-90_days`,
    CASE
        WHEN billing.due_date < DATE_SUB(?, INTERVAL 90 DAY) THEN billing_entry.balance
        ELSE 0
    END AS `above_90_days`
FROM billing_entry
LEFT JOIN billing ON billing.id = billing_entry.billing_id
WHERE billing_entry.is_deleted = 0
EOT;

        if ($billing_id) {
            $sql .= <<<EOT

AND billing_entry.billing_id = ?
EOT;
            $binds[] = $billing_id;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND billing_entry.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_billing_and_collection_summary($billing_id = null, $date_from = null, $date_to = null, $charge_to = null, $page = null, $data_size = null) {
        [$sql, $binds] = $this->_summary_sql($billing_id, $date_from, $date_to, $charge_to);
        $sql .= ' ORDER BY date DESC';
        $sql .= sql_limit_clause($page, $data_size);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function count_billing_and_collection_summary($billing_id = null, $date_from = null, $date_to = null, $charge_to = null): int
    {
        [$sql, $binds] = $this->_summary_sql($billing_id, $date_from, $date_to, $charge_to);
        $query = $this->db->query('SELECT COUNT(*) AS total FROM (' . $sql . ') AS list_count', $binds);
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    private function _summary_sql($billing_id = null, $date_from = null, $date_to = null, $charge_to = null): array
    {
        $billed_where = 'billing.is_deleted = 0';
        $collected_where = 'bpe.is_deleted = 0 AND billing.is_deleted = 0 AND be.is_deleted = 0';
        $billed_binds = [];
        $collected_binds = [];

        if ($billing_id) {
            $billed_where .= ' AND billing.id = ?';
            $collected_where .= ' AND billing.id = ?';
            $billed_binds[] = $billing_id;
            $collected_binds[] = $billing_id;
        }
        if ($date_from && $date_to) {
            $billed_where .= ' AND DATE(billing.added_on) BETWEEN ? AND ?';
            $collected_where .= ' AND DATE(billing.added_on) BETWEEN ? AND ?';
            $billed_binds[] = $date_from;
            $billed_binds[] = $date_to;
            $collected_binds[] = $date_from;
            $collected_binds[] = $date_to;
        }
        if ($charge_to) {
            $billed_where .= ' AND billing.charge_to = ?';
            $collected_where .= ' AND billing.charge_to = ?';
            $billed_binds[] = $charge_to;
            $collected_binds[] = $charge_to;
        }

        $sql = <<<EOT
SELECT billed.date,
    billed.total_billing,
    IFNULL(collected.total_collected, 0) AS total_collected,
    billed.total_billing - IFNULL(collected.total_collected, 0) AS total_uncollected
FROM (
    SELECT DATE(billing.added_on) AS date,
        SUM(billing.grand_total) AS total_billing
    FROM billing
    WHERE {$billed_where}
    GROUP BY DATE(billing.added_on)
) billed
LEFT JOIN (
    SELECT DATE(billing.added_on) AS date,
        SUM(bpe.amount) AS total_collected
    FROM billing_payment_entry bpe
    INNER JOIN billing_entry be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
    INNER JOIN billing ON billing.id = be.billing_id AND billing.is_deleted = 0
    WHERE {$collected_where}
    GROUP BY DATE(billing.added_on)
) collected ON collected.date = billed.date
EOT;

        return [$sql, array_merge($billed_binds, $collected_binds)];
    }

    /**
     * Billed invoice totals per charge company for a date range.
     *
     * @return array<int, array{charge_company_id:int|string, branch_id:int|string|null, amount:float}>
     */
    public function get_billed_totals_by_charge($date_from = null, $date_to = null, $with_branch = false, $company_id = null, $sales_executive_id = null, $status = null) {
        $binds = [];

        if ($with_branch) {
            $sql = <<<EOT
SELECT billing.charge_to AS charge_company_id,
    billing_entry.branch_id,
    COALESCE(SUM(billing_entry.amount), 0) AS amount
FROM billing_entry
LEFT JOIN billing ON billing.id = billing_entry.billing_id
WHERE billing_entry.is_deleted = 0
AND billing.is_deleted = 0
AND billing.charge_to IS NOT NULL
EOT;
        } else {
            $sql = <<<EOT
SELECT billing.charge_to AS charge_company_id,
    COALESCE(SUM(billing.grand_total), 0) AS amount
FROM billing
WHERE billing.is_deleted = 0
AND billing.charge_to IS NOT NULL
EOT;
        }

        if ($status) {
            if ($status == "billed") {
                $sql .= "\nAND billing.status = ?";
                $binds[] = 'cancelled';
            } else {
                $sql .= "\nAND billing.status = ?";
                $binds[] = $status;
            }
        }

        if ($company_id) {
            $sql .= "\nAND billing.company_id = ?";
            $binds[] = $company_id;
        }

        if ($sales_executive_id) {
            $sql .= "\nAND billing.added_by = ?";
            $binds[] = $sales_executive_id;
        }

        if ($date_from && $date_to) {
            $sql .= "\nAND DATE(billing.added_on) BETWEEN ? AND ?";
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($with_branch) {
            $sql .= "\nGROUP BY billing.charge_to, billing_entry.branch_id";
        } else {
            $sql .= "\nGROUP BY billing.charge_to";
        }

        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Open billed AR per charge company as of a date, with above-90 aging.
     *
     * @return array<int, array{company_id:int|string, type:?string, classification:?string, total:float|string, above_90:float|string}>
     */
    public function get_ar_outstanding_by_company($as_of_date, $branch_id = null)
    {
        $binds = [$as_of_date];
        $sql   = <<<EOT
SELECT company.id AS company_id,
    company.type,
    company.classification,
    COALESCE(SUM(billing_entry.balance), 0) AS total,
    COALESCE(SUM(CASE
        WHEN billing.due_date < DATE_SUB(?, INTERVAL 90 DAY) THEN billing_entry.balance
        ELSE 0
    END), 0) AS above_90
FROM billing_entry
INNER JOIN billing ON billing.id = billing_entry.billing_id AND billing.is_deleted = 0
INNER JOIN company ON company.id = billing.charge_to AND company.is_deleted = 0
WHERE billing_entry.is_deleted = 0
EOT;

        if ($branch_id) {
            $sql .= <<<EOT

AND billing_entry.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        $sql .= <<<EOT

GROUP BY company.id, company.type, company.classification
EOT;

        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Collected amounts per charge company for a payment-date range.
     *
     * @return array<int, array{company_id:int|string, type:?string, classification:?string, amount:float|string}>
     */
    public function get_collections_by_company($date_from, $date_to, $branch_id = null)
    {
        $binds = [$date_from, $date_to];
        $sql   = <<<EOT
SELECT company.id AS company_id,
    company.type,
    company.classification,
    COALESCE(SUM(bpe.amount), 0) AS amount
FROM billing_payment_entry bpe
INNER JOIN billing_payment bp ON bp.id = bpe.billing_payment_id AND bp.is_deleted = 0
INNER JOIN billing_entry be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
INNER JOIN billing b ON b.id = be.billing_id AND b.is_deleted = 0
INNER JOIN company ON company.id = b.charge_to AND company.is_deleted = 0
WHERE bpe.is_deleted = 0
AND DATE(COALESCE(bp.paid_date, bp.date)) BETWEEN ? AND ?
EOT;

        if ($branch_id) {
            $sql .= <<<EOT

AND be.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        $sql .= <<<EOT

GROUP BY company.id, company.type, company.classification
EOT;

        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }
}
