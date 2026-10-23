<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Billing_entry extends MYT_Model
{
    protected $table = 'billing_entry';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'billing_id',
        'branch_id',
        'company_id',
        'booking_id',
        'transaction_id',
        'endorsement_id',
        'floating_patient_no',
        'master_patient_id',
        'as_of_date',
        'yakap_tranche',
        'yakap_year',
        'yakap_month',
        'yakap_calculation',
        'yakap_patient_ids',
        'yakap_master_patient_ids',
        'yakap_patient_names',
        'amount',
        'balance',
        'status',
        'remarks',
        'is_migrated',
    ];

    public function get_details($billing_entry_id = null, $billing_id = null, $company_id = null, $charge_to = null, $screen = null, $with_invoice = null, $page = null, $data_size = null, $sort_by_patient_name = false, $include_invoice_no = false, $term = null) {
        $binds = [];
        $memo_joins = $this->_memo_balance_joins();
        $patient_name_sort = $sort_by_patient_name
            ? $this->_patient_name_sort_expression() . " AS _patient_name_sort,\n    "
            : '';
        $invoice_no_select = $include_invoice_no ? "billing.invoice_no AS invoice_no,\n    " : '';
        $sql = <<<EOT
SELECT billing_entry.id, 
    billing_entry.billing_id, 
    billing_entry.branch_id,
    billing_entry.company_id, 
    billing.charge_to, 
    billing.due_date,
    {$invoice_no_select}
    billing.invoice_no AS invoice_no,
    billing_entry.booking_id,
    billing_entry.transaction_id,
    billing_entry.endorsement_id,
    billing_entry.floating_patient_no,
    billing_entry.master_patient_id,
    billing_entry.as_of_date,
    billing_entry.yakap_tranche,
    billing_entry.yakap_year,
    billing_entry.yakap_month,
    billing_entry.yakap_calculation,
    billing_entry.yakap_patient_ids,
    billing_entry.yakap_master_patient_ids,
    billing_entry.yakap_patient_names,
    {$patient_name_sort}
    billing_entry.remarks,
    billing_entry.is_migrated,
    billing_entry.amount,
    IFNULL(SUM(billing_payment_entry.amount), 0) AS paid_amount,
    (billing_entry.amount - IFNULL(SUM(billing_payment_entry.amount), 0) - IFNULL(MAX(credit_alloc.memo_amount), 0) + IFNULL(MAX(debit_alloc.memo_amount), 0)) AS balance
FROM billing_entry
LEFT JOIN billing ON billing.id = billing_entry.billing_id
LEFT JOIN billing_payment_entry ON billing_payment_entry.billing_entry_id = billing_entry.id AND billing_payment_entry.is_deleted = 0
{$memo_joins}
WHERE billing_entry.is_deleted = 0
AND billing.is_deleted = 0
EOT;

        if (is_array($billing_entry_id)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $billing_entry_id))));
            if (!$ids) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql .= "\nAND billing_entry.id IN ($placeholders)";
            foreach ($ids as $id) {
                $binds[] = $id;
            }
        } elseif ($billing_entry_id) {
            $sql .= "\nAND billing_entry.id = ?";
            $binds[] = $billing_entry_id;
        }

        if (!$this->_append_billing_id_filter($sql, $binds, $billing_id)) {
            return [];
        }

        if ($company_id) {
            $sql .= <<<EOT

AND billing_entry.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($charge_to) {
            $sql .= <<<EOT

AND billing.charge_to = ?
EOT;
            $binds[] = $charge_to;
        }

        if ($with_invoice && $with_invoice == 1) {
            $sql .= <<<EOT

AND billing.invoice_id IS NOT NULL
EOT;
        }
        
        $this->_append_patient_term($sql, $binds, $term);
        $sql .= " GROUP BY billing_entry.id ";

        if ($screen === 'collection') {
            $sql .= <<<EOT
        
HAVING (MAX(billing_entry.amount) - IFNULL(SUM(billing_payment_entry.amount), 0) - IFNULL(MAX(credit_alloc.memo_amount), 0) + IFNULL(MAX(debit_alloc.memo_amount), 0)) > 0
EOT;
        }

        if ($sort_by_patient_name) {
            $sql .= <<<EOT

ORDER BY (_patient_name_sort IS NULL OR OCTET_LENGTH(TRIM(_patient_name_sort)) = 0) ASC,
    LOWER(_patient_name_sort) ASC,
    billing_entry.id ASC
EOT;
        }

        $sql .= sql_limit_clause($page, $data_size);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /** Aggregate each memo source before joining payments to avoid fan-out. */
    private function _memo_balance_joins(): string
    {
        $joins = '';
        foreach (['credit', 'debit'] as $kind) {
            $joins .= " LEFT JOIN (
                SELECT e.billing_entry_id, e.billing_id, e.branch_id, SUM(e.amount) AS memo_amount
                FROM {$kind}_memo_entries e
                JOIN {$kind}_memo m ON m.id = e.{$kind}_memo_id AND m.is_deleted = 0
                    AND m.status = 'approved' AND m.billing_id = e.billing_id AND m.branch_id = e.branch_id
                WHERE e.is_deleted = 0
                GROUP BY e.billing_entry_id, e.billing_id, e.branch_id
            ) {$kind}_alloc ON {$kind}_alloc.billing_entry_id = billing_entry.id
                AND {$kind}_alloc.billing_id = billing_entry.billing_id AND {$kind}_alloc.branch_id = billing_entry.branch_id ";
        }
        return $joins;
    }

    public function apply_memo_allocation(array $row, string $type, $requester): bool
    {
        if (!in_array($type, ['credit', 'debit'], true)) {
            return false;
        }
        $operator = $type === 'credit' ? '-' : '+';
        $sql = "UPDATE billing_entry SET
            status = CASE WHEN status IN ('approved', 'open_bill', 'closed_bill')
                THEN CASE WHEN balance $operator CAST(? AS DECIMAL(13,2)) <= 0 THEN 'closed_bill' ELSE 'open_bill' END
                ELSE status END,
            balance = balance $operator CAST(? AS DECIMAL(13,2)), updated_by = ?, updated_on = ?
            WHERE id = ? AND billing_id = ? AND branch_id = ? AND is_deleted = 0";
        return $this->db->query($sql, [$row['amount'], $row['amount'], $requester, date('Y-m-d H:i:s'),
            $row['billing_entry_id'], $row['billing_id'], $row['branch_id']]) !== false;
    }

    private function _append_patient_term(string &$sql, array &$binds, $term): void
    {
        $term = trim((string) ($term ?? ''));
        if ($term !== '') {
            $sql .= "\nAND (CONVERT(" . $this->_patient_name_sort_expression()
                . " USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE (CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci)";
            $binds[] = '%' . $term . '%';
        }
    }

    /** Build the same patient-name fallbacks used when billing entries are decorated. */
    private function _patient_name_sort_expression(): string
    {
        $key = $this->db->escape($this->encryption_key);
        $patient_name_sql = static function (string $alias) use ($key): string {
            return "TRIM(CONCAT("
                . "CAST(AES_DECRYPT({$alias}.last_name, {$key}) AS CHAR), ', ', "
                . "CAST(AES_DECRYPT({$alias}.first_name, {$key}) AS CHAR), ' ', "
                . "IFNULL(CAST(AES_DECRYPT({$alias}.middle_name, {$key}) AS CHAR), ''), ' ', "
                . "IFNULL(CAST(AES_DECRYPT({$alias}.suffix, {$key}) AS CHAR), '')"
                . '))';
        };

        $clinic_schemas = [
            1 => MOBILE_DB,
            2 => OAKRIDGE_DB,
            3 => MAC_DB,
            4 => CONSO_DB,
            7 => MANTLEWOOD_DB,
        ];
        $emr_schemas = [
            1 => MOBILE_EMR_DB,
            2 => OAKRIDGE_EMR_DB,
            3 => MAC_EMR_DB,
            4 => CONSO_EMR_DB,
            7 => MANTLEWOOD_EMR_DB,
        ];

        $clinic_cases = [];
        $emr_cases = [];
        foreach ($clinic_schemas as $branch_id => $schema_name) {
            $schema = '`' . str_replace('`', '``', $schema_name) . '`';
            $clinic_cases[] = "WHEN billing_entry.branch_id = {$branch_id} THEN ("
                . 'SELECT ' . $patient_name_sql('sort_patient')
                . " FROM {$schema}.booking AS sort_booking"
                . " LEFT JOIN {$schema}.patient AS sort_patient ON sort_patient.id = sort_booking.patient_id"
                . ' WHERE sort_booking.id = billing_entry.booking_id'
                . ' AND sort_booking.is_deleted = 0 LIMIT 1)';

            $emr_schema = '`' . str_replace('`', '``', $emr_schemas[$branch_id]) . '`';
            $emr_cases[] = "WHEN billing_entry.branch_id = {$branch_id} THEN ("
                . 'SELECT ' . $patient_name_sql('sort_emr_patient')
                . " FROM {$emr_schema}.patient AS sort_emr_patient"
                . ' WHERE CAST(sort_emr_patient.master_patient_id AS UNSIGNED) = CAST(billing_entry.master_patient_id AS UNSIGNED)'
                . ' AND sort_emr_patient.is_deleted = 0 LIMIT 1)';
        }

        $clinic_name = 'CASE ' . implode(' ', $clinic_cases) . ' ELSE NULL END';
        $emr_name = 'CASE ' . implode(' ', $emr_cases) . ' ELSE NULL END';
        $master_schema = '`' . str_replace('`', '``', MASTER_DB) . '`';
        $master_patient_name = '(SELECT ' . $patient_name_sql('sort_master_patient')
            . " FROM {$master_schema}.patient AS sort_master_patient"
            . ' WHERE sort_master_patient.id = CAST(billing_entry.master_patient_id AS UNSIGNED)'
            . ' AND sort_master_patient.is_deleted = 0 LIMIT 1)';
        $fpe_name = "COALESCE({$master_patient_name}, {$emr_name})";

        return <<<EOT
CASE
    WHEN OCTET_LENGTH(TRIM(billing_entry.yakap_patient_names)) > 0
        THEN TRIM(billing_entry.yakap_patient_names)
    WHEN (billing_entry.yakap_tranche IS NOT NULL OR billing.company_id = 3210 OR billing.charge_to = 3210)
        AND billing_entry.master_patient_id IS NOT NULL
        AND LOCATE(0x2C, CAST(billing_entry.master_patient_id AS BINARY)) = 0
        THEN {$fpe_name}
    WHEN (billing_entry.booking_id IS NULL OR billing_entry.booking_id = 0)
        AND billing_entry.floating_patient_no IS NOT NULL
        THEN CONCAT('Floating Patient ', billing_entry.floating_patient_no)
    WHEN (billing_entry.booking_id IS NULL OR billing_entry.booking_id = 0)
        AND billing_entry.remarks IS NOT NULL
        AND OCTET_LENGTH(TRIM(billing_entry.remarks)) > 0
        THEN TRIM(SUBSTRING_INDEX(billing_entry.remarks, ' | ', 1))
    WHEN billing_entry.booking_id IS NOT NULL AND billing_entry.booking_id <> 0
        THEN {$clinic_name}
    ELSE NULL
END
EOT;
    }

    public function count_details($billing_entry_id = null, $billing_id = null, $company_id = null, $charge_to = null, $screen = null, $with_invoice = null, $term = null): int
    {
        $binds = [];
        $memo_joins = $this->_memo_balance_joins();
        $sql = <<<EOT
SELECT billing_entry.id
FROM billing_entry
LEFT JOIN billing ON billing.id = billing_entry.billing_id
LEFT JOIN billing_payment_entry ON billing_payment_entry.billing_entry_id = billing_entry.id AND billing_payment_entry.is_deleted = 0
{$memo_joins}
WHERE billing_entry.is_deleted = 0
AND billing.is_deleted = 0
EOT;
        if ($billing_entry_id) {
            $sql .= "\nAND billing_entry.id = ?";
            $binds[] = $billing_entry_id;
        }
        if (!$this->_append_billing_id_filter($sql, $binds, $billing_id)) {
            return 0;
        }
        if ($company_id) {
            $sql .= "\nAND billing_entry.company_id = ?";
            $binds[] = $company_id;
        }
        if ($charge_to) {
            $sql .= "\nAND billing.charge_to = ?";
            $binds[] = $charge_to;
        }
        if ($with_invoice && $with_invoice == 1) {
            $sql .= "\nAND billing.invoice_id IS NOT NULL";
        }
        $this->_append_patient_term($sql, $binds, $term);
        $sql .= " GROUP BY billing_entry.id ";
        if ($screen === 'collection') {
            $sql .= "\nHAVING (MAX(billing_entry.amount) - IFNULL(SUM(billing_payment_entry.amount), 0) - IFNULL(MAX(credit_alloc.memo_amount), 0) + IFNULL(MAX(debit_alloc.memo_amount), 0)) > 0";
        }
        $query = $this->db->query('SELECT COUNT(*) AS total FROM (' . $sql . ') AS list_count', $binds);
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param int[] $billing_ids
     * @return array{entries: array<int, array>, counts: array<string, int>, totals: array<string, array<string, float>>}
     */
    public function get_details_for_billing_ids(array $billing_ids, $cap_per_group = null, $entry_page = null, $entry_data_size = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $billing_ids))));
        if (!$ids) {
            return ['entries' => [], 'counts' => [], 'totals' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $memo_joins = $this->_memo_balance_joins();
        $count_sql = <<<EOT
SELECT billing.company_id, billing.charge_to, COUNT(*) AS entry_count
FROM billing_entry
INNER JOIN billing ON billing.id = billing_entry.billing_id AND billing.is_deleted = 0
WHERE billing_entry.is_deleted = 0
AND billing_entry.billing_id IN ($placeholders)
GROUP BY billing.company_id, billing.charge_to
EOT;
        $count_query = $this->db->query($count_sql, $ids);
        $counts = [];
        foreach ($count_query ? $count_query->getResultArray() : [] as $row) {
            $counts[$row['company_id'] . ':' . $row['charge_to']] = (int) $row['entry_count'];
        }

        $totals_sql = <<<EOT
SELECT billing_entry.company_id,
    billing.charge_to,
    COALESCE(SUM(billing_entry.amount), 0) AS grand_total,
    COALESCE(SUM(COALESCE(entry_payments.paid_amount, 0)), 0) AS paid_amount,
    COALESCE(SUM(billing_entry.amount - COALESCE(entry_payments.paid_amount, 0) - COALESCE(credit_alloc.memo_amount, 0) + COALESCE(debit_alloc.memo_amount, 0)), 0) AS balance
FROM billing_entry
INNER JOIN billing ON billing.id = billing_entry.billing_id AND billing.is_deleted = 0
LEFT JOIN (
    SELECT billing_entry_id, SUM(amount) AS paid_amount
    FROM billing_payment_entry
    WHERE is_deleted = 0
    GROUP BY billing_entry_id
) entry_payments ON entry_payments.billing_entry_id = billing_entry.id
{$memo_joins}
WHERE billing_entry.is_deleted = 0
AND billing_entry.billing_id IN ($placeholders)
GROUP BY billing_entry.company_id, billing.charge_to
EOT;
        $totals_query = $this->db->query($totals_sql, $ids);
        $totals = [];
        foreach ($totals_query ? $totals_query->getResultArray() : [] as $row) {
            $totals[$row['company_id'] . ':' . $row['charge_to']] = [
                'grand_total' => (float) $row['grand_total'],
                'paid_amount' => (float) $row['paid_amount'],
                'balance' => (float) $row['balance'],
            ];
        }

        $select = <<<EOT
SELECT billing_entry.id,
    billing_entry.billing_id,
    billing_entry.branch_id,
    billing_entry.company_id,
    billing.charge_to,
    billing.due_date,
    billing.invoice_no,
    billing.invoice_date,
    billing_entry.booking_id,
    billing_entry.transaction_id,
    billing_entry.endorsement_id,
    billing_entry.floating_patient_no,
    billing_entry.master_patient_id,
    billing_entry.as_of_date,
    billing_entry.yakap_tranche,
    billing_entry.yakap_year,
    billing_entry.yakap_month,
    billing_entry.yakap_calculation,
    billing_entry.yakap_patient_ids,
    billing_entry.yakap_master_patient_ids,
    billing_entry.yakap_patient_names,
    billing_entry.remarks,
    billing_entry.added_on,
    billing_entry.is_migrated,
    billing_entry.amount,
    IFNULL(SUM(billing_payment_entry.amount), 0) AS paid_amount,
    (billing_entry.amount - IFNULL(SUM(billing_payment_entry.amount), 0) - IFNULL(MAX(credit_alloc.memo_amount), 0) + IFNULL(MAX(debit_alloc.memo_amount), 0)) AS balance
FROM billing_entry
LEFT JOIN billing ON billing.id = billing_entry.billing_id
LEFT JOIN billing_payment_entry ON billing_payment_entry.billing_entry_id = billing_entry.id AND billing_payment_entry.is_deleted = 0
{$memo_joins}
WHERE billing_entry.is_deleted = 0
AND billing.is_deleted = 0
AND billing_entry.billing_id IN ($placeholders)
GROUP BY billing_entry.id
EOT;

        if ($entry_page !== null && $entry_data_size !== null) {
            $page = max(1, (int) $entry_page);
            $page_size = min(100, max(1, (int) $entry_data_size));
            $offset = ($page - 1) * $page_size;
            $end = $offset + $page_size;
            $sql = <<<EOT
SELECT *
FROM (
    SELECT grouped_entries.*,
        ROW_NUMBER() OVER (
            PARTITION BY grouped_entries.company_id, grouped_entries.charge_to
            ORDER BY grouped_entries.id
        ) AS rn
    FROM (
        {$select}
    ) grouped_entries
) paged_entries
WHERE paged_entries.rn > ?
AND paged_entries.rn <= ?
ORDER BY paged_entries.company_id, paged_entries.charge_to, paged_entries.rn
EOT;
            $query = $this->db->query($sql, array_merge($ids, [$offset, $end]));
        } elseif ($cap_per_group) {
            $cap = (int) $cap_per_group;
            $sql = <<<EOT
SELECT * FROM (
    SELECT grouped.*,
        ROW_NUMBER() OVER (
            PARTITION BY grouped.company_id, grouped.charge_to
            ORDER BY grouped.id
        ) AS rn
    FROM (
        {$select}
        ) grouped
) capped
WHERE capped.rn <= {$cap}
EOT;
            $query = $this->db->query($sql, $ids);
        } else {
            $query = $this->db->query($select, $ids);
        }

        return [
            'entries' => $query ? $query->getResultArray() : [],
            'counts' => $counts,
            'totals' => $totals,
        ];
    }

    /**
     * Billed floating-patient numbers for company / charge_to / branch.
     *
     * @param array<int|string>|int|string|null $charge_to
     * @param array<int|string>|int|string|null $endorsement_id
     * @return int[]
     */
    public function get_billed_floating_patient_nos($company_id = null, $charge_to = null, $branch_id = null, $endorsement_id = null): array
    {
        return $this->_billed_floater_ids($company_id, $charge_to, $branch_id, $endorsement_id)['nos'];
    }

    /**
     * Billed floating master patient ids for company / charge_to / branch.
     *
     * @param array<int|string>|int|string|null $charge_to
     * @param array<int|string>|int|string|null $endorsement_id
     * @return int[]
     */
    public function get_billed_floating_master_patient_ids($company_id = null, $charge_to = null, $branch_id = null, $endorsement_id = null): array
    {
        return $this->_billed_floater_ids($company_id, $charge_to, $branch_id, $endorsement_id)['master_patient_ids'];
    }

    /**
     * Billed floater nos and master patient ids keyed by endorsement_id.
     *
     * @param array<int|string>|int|string|null $charge_to
     * @param array<int|string>|int|string|null $endorsement_id
     * @return array<int, array{nos: int[], master_patient_ids: int[]}>
     */
    public function get_billed_floaters_grouped($company_id = null, $charge_to = null, $branch_id = null, $endorsement_id = null): array
    {
        $grouped = [];
        foreach ($this->_billed_floater_rows($company_id, $charge_to, $branch_id, $endorsement_id) as $row) {
            if (!isset($row['endorsement_id']) || $row['endorsement_id'] === '' || $row['endorsement_id'] === null) {
                continue;
            }
            $eid = (int) $row['endorsement_id'];
            if (!isset($grouped[$eid])) {
                $grouped[$eid] = [
                    'nos' => [],
                    'master_patient_ids' => [],
                ];
            }
            if (isset($row['floating_patient_no']) && $row['floating_patient_no'] !== '') {
                $grouped[$eid]['nos'][] = (int) $row['floating_patient_no'];
            }
            if (isset($row['master_patient_id']) && $row['master_patient_id'] !== '') {
                $grouped[$eid]['master_patient_ids'][] = (int) $row['master_patient_id'];
            }
        }

        foreach ($grouped as $eid => $ids) {
            $grouped[$eid]['nos'] = array_values(array_unique($ids['nos']));
            $grouped[$eid]['master_patient_ids'] = array_values(array_unique($ids['master_patient_ids']));
        }

        return $grouped;
    }

    /**
     * @param array<int|string>|int|string|null $charge_to
     * @param array<int|string>|int|string|null $endorsement_id
     * @return array{nos: int[], master_patient_ids: int[]}
     */
    private function _billed_floater_ids($company_id = null, $charge_to = null, $branch_id = null, $endorsement_id = null): array
    {
        $nos = [];
        $master_ids = [];
        foreach ($this->_billed_floater_rows($company_id, $charge_to, $branch_id, $endorsement_id) as $row) {
            if (isset($row['floating_patient_no']) && $row['floating_patient_no'] !== '') {
                $nos[] = (int) $row['floating_patient_no'];
            }
            if (isset($row['master_patient_id']) && $row['master_patient_id'] !== '') {
                $master_ids[] = (int) $row['master_patient_id'];
            }
        }

        return [
            'nos' => array_values(array_unique($nos)),
            'master_patient_ids' => array_values(array_unique($master_ids)),
        ];
    }

    /**
     * @param array<int|string>|int|string|null $charge_to
     * @param array<int|string>|int|string|null $endorsement_id
     * @return list<array<string, mixed>>
     */
    private function _billed_floater_rows($company_id = null, $charge_to = null, $branch_id = null, $endorsement_id = null): array
    {
        $binds = [];
        $sql = <<<EOT
SELECT billing_entry.endorsement_id, billing_entry.floating_patient_no, billing_entry.master_patient_id
FROM billing_entry
INNER JOIN billing ON billing.id = billing_entry.billing_id
WHERE billing_entry.is_deleted = 0
AND billing.is_deleted = 0
AND billing.status != 'cancelled'
AND (
    billing_entry.floating_patient_no IS NOT NULL
    OR billing_entry.master_patient_id IS NOT NULL
)
EOT;

        if ($company_id) {
            $sql .= "\nAND billing.company_id = ?";
            $binds[] = $company_id;
        }

        $charge_ids = [];
        if (is_array($charge_to)) {
            foreach ($charge_to as $id) {
                if ($id === null || $id === '' || $id === '0') {
                    continue;
                }
                $charge_ids[] = (int) $id;
            }
            $charge_ids = array_values(array_unique($charge_ids));
        } elseif ($charge_to !== null && $charge_to !== '' && $charge_to !== '0') {
            $charge_ids[] = (int) $charge_to;
        }

        if ($charge_ids) {
            $placeholders = implode(',', array_fill(0, count($charge_ids), '?'));
            $sql .= "\nAND billing.charge_to IN ($placeholders)";
            foreach ($charge_ids as $id) {
                $binds[] = $id;
            }
        }

        $endorsement_ids = [];
        if (is_array($endorsement_id)) {
            foreach ($endorsement_id as $id) {
                if ($id === null || $id === '' || $id === '0') {
                    continue;
                }
                $endorsement_ids[] = (int) $id;
            }
            $endorsement_ids = array_values(array_unique($endorsement_ids));
        } elseif ($endorsement_id !== null && $endorsement_id !== '' && $endorsement_id !== '0') {
            $endorsement_ids[] = (int) $endorsement_id;
        }

        if ($endorsement_ids) {
            $placeholders = implode(',', array_fill(0, count($endorsement_ids), '?'));
            $sql .= "\nAND billing_entry.endorsement_id IN ($placeholders)";
            foreach ($endorsement_ids as $id) {
                $binds[] = $id;
            }
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : [];
    }

    /**
     * @param array<int|string>|int|string|null $billing_id
     * @return int[]|null null = no billing_id filter
     */
    private function _billing_ids_from_filter($billing_id): ?array
    {
        if ($billing_id === null || $billing_id === '' || $billing_id === false) {
            return null;
        }

        if (is_array($billing_id)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $billing_id))));

            return $ids ?: [];
        }

        if (is_string($billing_id) && str_contains($billing_id, ',')) {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $billing_id)))));

            return $ids;
        }

        $id = (int) $billing_id;

        return $id > 0 ? [$id] : [];
    }

    /**
     * @param array<int|string>|int|string|null $billing_id
     * @return bool false when filter was requested but no valid ids
     */
    private function _append_billing_id_filter(string &$sql, array &$binds, $billing_id): bool
    {
        $ids = $this->_billing_ids_from_filter($billing_id);
        if ($ids === null) {
            return true;
        }
        if (!$ids) {
            return false;
        }
        if (count($ids) === 1) {
            $sql .= "\nAND billing_entry.billing_id = ?";
            $binds[] = $ids[0];

            return true;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql .= "\nAND billing_entry.billing_id IN ($placeholders)";
        foreach ($ids as $id) {
            $binds[] = $id;
        }

        return true;
    }
}

?>
