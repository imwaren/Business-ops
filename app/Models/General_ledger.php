<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class General_ledger extends MYT_Model
{
    protected $table = 'general_ledger';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_id',
        'source',
        'source_id',
        'account_type_id',
        'date',
        'debit',
        'credit',
        'remark',
        'is_migrated',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    /** @var int|null Cached gl_settings.branch_id; 0 means none. */
    private $gl_settings_branch_id = null;

    /**
     * Get PINV opening general ledger
     */
    public function get_opening_regular_general_ledger($account_type_id = null, $date_from = null, $cost_center_id = null, $supplier_id = null, $customer_id = null, $project_id = null)
    {
        $sql = <<<EOT
SELECT 'Opening' AS name,
    SUM(general_ledger.debit) AS debit,
    SUM(general_ledger.credit) AS credit
FROM general_ledger
WHERE general_ledger.is_deleted = 0
AND general_ledger.is_payment = 0
EOT;

        $binds = [];
        
        if ($account_type_id) {
            $sql .= <<<EOT

AND general_ledger.account_type_id = ?
EOT;
            $binds[] = $account_type_id;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND general_ledger.date < ?
EOT;
            $binds[] = $date_from;
        }

        if ($cost_center_id) {
            $sql .= <<<EOT

AND general_ledger.cost_center_id = ?
EOT;
            $binds[] = $cost_center_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND general_ledger.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($customer_id) {
            $sql .= <<<EOT

AND general_ledger.customer_id = ?
EOT;
            $binds[] = $customer_id;
        }

        if ($project_id) {
            $sql .= <<<EOT

AND general_ledger.project_id = ?
EOT;
            $binds[] = $project_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Get PINV closing general ledger
     */
    public function get_closing_regular_general_ledger($account_type_id = null, $date_to = null, $cost_center_id = null, $supplier_id = null, $customer_id = null, $project_id = null)
    {
        $database = \Config\Database::connect();
        $sql = <<<EOT
SELECT 'Closing' AS name,
    SUM(general_ledger.debit) AS debit,
    SUM(general_ledger.credit) AS credit
FROM general_ledger
WHERE general_ledger.is_deleted = 0
AND general_ledger.is_payment = 0
EOT;

        $binds = [];
        
        if ($account_type_id) {
            $sql .= <<<EOT

AND general_ledger.account_type_id = ?
EOT;
            $binds[] = $account_type_id;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND general_ledger.date <= ?
EOT;
            $binds[] = $date_to;
        }

        if ($cost_center_id) {
            $sql .= <<<EOT

AND general_ledger.cost_center_id = ?
EOT;
            $binds[] = $cost_center_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND general_ledger.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($customer_id) {
            $sql .= <<<EOT

AND general_ledger.customer_id = ?
EOT;
            $binds[] = $customer_id;
        }

        if ($project_id) {
            $sql .= <<<EOT

AND general_ledger.project_id = ?
EOT;
            $binds[] = $project_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Get regular general ledger
     */
    public function get_regular_general_ledger($account_type_id = null, $date_from = null, $date_to = null, $cost_center_id = null, $supplier_id = null, $customer_id = null, $project_id = null)
    {
        $database = \Config\Database::connect();
        $sql = <<<EOT
SELECT CONCAT(general_ledger.ledger_type, '-', general_ledger.ledger_type_id) AS id,
    general_ledger.remark AS remarks,
    account_type.name AS name,
    general_ledger.debit,
    general_ledger.credit,
    general_ledger.date
FROM general_ledger
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = general_ledger.account_type_id
WHERE general_ledger.is_deleted = 0
EOT;

        $binds = [];
        
        if ($account_type_id) {
            $sql .= <<<EOT

AND general_ledger.account_type_id = ?
EOT;
            $binds[] = $account_type_id;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND general_ledger.date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($cost_center_id) {
            $sql .= <<<EOT

AND general_ledger.cost_center_id = ?
EOT;
            $binds[] = $cost_center_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND general_ledger.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($customer_id) {
            $sql .= <<<EOT

AND general_ledger.customer_id = ?
EOT;
            $binds[] = $customer_id;
        }

        if ($project_id) {
            $sql .= <<<EOT

AND general_ledger.project_id = ?
EOT;
            $binds[] = $project_id;
        }
        
        $sql .= " GROUP BY general_ledger.id";

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Posted journals for reports (one branch connection).
     * Clinic general_ledger plus this branch's billing journals from mytemr_master.
     *
     * @return array<int, array>
     */
    public function get_posted_entries($date_from, $date_to): array
    {
        $union = $this->_posted_union_sql();
        $sql = <<<EOT
SELECT posted.id,
    posted.branch_id,
    posted.source,
    posted.source_id,
    posted.source AS type,
    posted.source_id AS type_id,
    posted.account_type_id,
    posted.date,
    posted.date AS txn_date,
    posted.debit,
    posted.credit,
    posted.remark,
    posted.remark AS remarks,
    account_type.code AS account_code,
    account_type.name AS account_name,
    account_type.financial_statement
FROM (
    {$union}
) AS posted
LEFT JOIN {MASTER_DB}.account_type AS account_type
    ON account_type.id = posted.account_type_id
    AND account_type.is_deleted = 0
ORDER BY posted.date, posted.id
EOT;
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $this->_posted_union_binds($date_from, $date_to));
        $rows = $query ? $query->getResultArray() : [];
        foreach ($rows as &$row) {
            $id = (int) ($row['account_type_id'] ?? 0);
            $row['account_type'] = [
                'id'                  => $id,
                'code'                => $row['account_code'] ?? null,
                'name'                => $row['account_name'] ?? null,
                'financial_statement' => $row['financial_statement'] ?? null,
            ];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<int> $account_ids
     */
    private function _account_in_sql(string $alias, array $account_ids): string
    {
        if (! $account_ids) {
            return '';
        }

        return " AND {$alias}.account_type_id IN (" . implode(',', array_fill(0, count($account_ids), '?')) . ')';
    }

    /**
     * Clinic-local posted rows (no master UNION).
     *
     * @param list<int> $account_ids
     */
    private function _posted_local_sql(array $account_ids = []): string
    {
        $account_sql = $this->_account_in_sql('general_ledger', $account_ids);

        return <<<EOT
SELECT general_ledger.id,
    COALESCE(general_ledger.branch_id, 0) AS branch_id,
    general_ledger.source,
    general_ledger.source_id,
    general_ledger.account_type_id,
    general_ledger.date,
    general_ledger.debit,
    general_ledger.credit,
    general_ledger.remark
FROM general_ledger
WHERE general_ledger.is_deleted = 0
    AND general_ledger.account_type_id IS NOT NULL
    AND general_ledger.source NOT IN (
        'billing_entry', 'billing_payment', 'billing_payment_deposit'
    )
    AND general_ledger.date BETWEEN ? AND ?
    {$account_sql}
EOT;
    }

    /**
     * Shared master rows (NULL/0 branch_id). No gl_settings.
     *
     * @param list<int> $account_ids
     */
    private function _posted_master_shared_sql(array $account_ids = []): string
    {
        $account_sql = $this->_account_in_sql('general_ledger', $account_ids);

        return <<<EOT
SELECT general_ledger.id,
    COALESCE(general_ledger.branch_id, 0) AS branch_id,
    general_ledger.source,
    general_ledger.source_id,
    general_ledger.account_type_id,
    general_ledger.date,
    general_ledger.debit,
    general_ledger.credit,
    general_ledger.remark
FROM general_ledger
WHERE general_ledger.is_deleted = 0
    AND general_ledger.account_type_id IS NOT NULL
    AND (general_ledger.branch_id IS NULL OR general_ledger.branch_id = 0)
    AND general_ledger.date BETWEEN ? AND ?
    {$account_sql}
EOT;
    }

    /**
     * Clinic + this branch's billing/memo from master. gl_settings bound once.
     *
     * @param list<int> $account_ids
     */
    private function _posted_union_sql(array $account_ids = []): string
    {
        if ($this->_is_master_connection()) {
            return $this->_posted_master_shared_sql($account_ids);
        }

        $local = $this->_posted_local_sql($account_ids);
        $settings_id = $this->_clinic_settings_branch_id();
        if ($settings_id === null) {
            return $local;
        }

        $account_sql = $this->_account_in_sql('master_gl', $account_ids);

        return $local . <<<EOT

UNION ALL
SELECT master_gl.id,
    master_gl.branch_id,
    master_gl.source,
    master_gl.source_id,
    master_gl.account_type_id,
    master_gl.date,
    master_gl.debit,
    master_gl.credit,
    master_gl.remark
FROM {MASTER_DB}.general_ledger AS master_gl
WHERE master_gl.is_deleted = 0
    AND master_gl.account_type_id IS NOT NULL
    AND master_gl.source IN (
        'billing_entry', 'billing_payment', 'billing_payment_deposit',
        'credit_memo', 'debit_memo'
    )
    AND master_gl.branch_id = ?
    AND master_gl.date BETWEEN ? AND ?
    {$account_sql}
EOT;
    }

    private function _is_master_connection(): bool
    {
        return (string) ($this->db->database ?? '') === 'mytemr_master';
    }

    private function _clinic_settings_branch_id(): ?int
    {
        if ($this->_is_master_connection()) {
            return null;
        }
        if ($this->gl_settings_branch_id !== null) {
            return $this->gl_settings_branch_id ?: null;
        }

        $row = $this->db->query('SELECT branch_id FROM gl_settings LIMIT 1')->getRowArray();
        $id = isset($row['branch_id']) ? (int) $row['branch_id'] : 0;
        $this->gl_settings_branch_id = $id;

        return $id > 0 ? $id : null;
    }

    /**
     * @param list<int> $account_ids
     * @return list<mixed>
     */
    private function _posted_union_binds($date_from, $date_to, array $account_ids = []): array
    {
        $binds = array_merge([$date_from, $date_to], $account_ids);
        if ($this->_is_master_connection()) {
            return $binds;
        }
        $settings_id = $this->_clinic_settings_branch_id();
        if ($settings_id === null) {
            return $binds;
        }

        return array_merge($binds, [$settings_id, $date_from, $date_to], $account_ids);
    }

    /**
     * @param list<int> $account_ids
     * @return list<mixed>
     */
    private function _posted_local_binds($date_from, $date_to, array $account_ids = []): array
    {
        return array_merge([$date_from, $date_to], $account_ids);
    }

    /**
     * Monthly debit/credit totals by account_type_id. One row per account per month.
     *
     * @return list<array{account_type_id:mixed,yr:mixed,mo:mixed,debit:mixed,credit:mixed}>
     */
    public function get_posted_monthly_totals($date_from, $date_to, bool $local_only = false): array
    {
        if ($local_only && ! $this->_is_master_connection()) {
            $inner = $this->_posted_local_sql();
            $binds = $this->_posted_local_binds($date_from, $date_to);
        } else {
            $inner = $this->_posted_union_sql();
            $binds = $this->_posted_union_binds($date_from, $date_to);
        }
        $sql = <<<EOT
SELECT posted.account_type_id,
    YEAR(posted.date) AS yr,
    MONTH(posted.date) AS mo,
    SUM(IFNULL(posted.debit, 0)) AS debit,
    SUM(IFNULL(posted.credit, 0)) AS credit
FROM (
    {$inner}
) AS posted
GROUP BY posted.account_type_id, YEAR(posted.date), MONTH(posted.date)
EOT;
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * One master scan: shared (NULL/0) rows plus clinic-tagged billing/memo.
     * Call on the master connection only.
     *
     * @param list<int> $clinic_branch_ids
     * @return list<array{account_type_id:mixed,yr:mixed,mo:mixed,debit:mixed,credit:mixed}>
     */
    public function get_posted_monthly_totals_master_merged($date_from, $date_to, array $clinic_branch_ids): array
    {
        [$inner, $binds] = $this->_posted_master_merged_query($date_from, $date_to, $clinic_branch_ids);
        $sql = <<<EOT
SELECT posted.account_type_id,
    YEAR(posted.date) AS yr,
    MONTH(posted.date) AS mo,
    SUM(IFNULL(posted.debit, 0)) AS debit,
    SUM(IFNULL(posted.credit, 0)) AS credit
FROM (
    {$inner}
) AS posted
GROUP BY posted.account_type_id, YEAR(posted.date), MONTH(posted.date)
EOT;
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Posted debit/credit totals by account_type_id for a date range.
     *
     * @return list<array{account_type_id:mixed,debit:mixed,credit:mixed}>
     */
    public function get_posted_account_totals($date_from, $date_to, bool $local_only = false): array
    {
        if ($local_only && ! $this->_is_master_connection()) {
            $inner = $this->_posted_local_sql();
            $binds = $this->_posted_local_binds($date_from, $date_to);
        } else {
            $inner = $this->_posted_union_sql();
            $binds = $this->_posted_union_binds($date_from, $date_to);
        }
        $sql = <<<EOT
SELECT posted.account_type_id,
    SUM(IFNULL(posted.debit, 0)) AS debit,
    SUM(IFNULL(posted.credit, 0)) AS credit
FROM (
    {$inner}
) AS posted
GROUP BY posted.account_type_id
EOT;
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * One master scan: shared (NULL/0) rows plus clinic-tagged billing/memo.
     * Call on the master connection only.
     *
     * @param list<int> $clinic_branch_ids
     * @return list<array{account_type_id:mixed,debit:mixed,credit:mixed}>
     */
    public function get_posted_account_totals_master_merged($date_from, $date_to, array $clinic_branch_ids): array
    {
        [$inner, $binds] = $this->_posted_master_merged_query($date_from, $date_to, $clinic_branch_ids);
        $sql = <<<EOT
SELECT posted.account_type_id,
    SUM(IFNULL(posted.debit, 0)) AS debit,
    SUM(IFNULL(posted.credit, 0)) AS credit
FROM (
    {$inner}
) AS posted
GROUP BY posted.account_type_id
EOT;
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Shared master rows plus clinic-tagged billing/memo. Master connection only.
     *
     * @param list<int> $clinic_branch_ids
     * @param list<int> $account_ids
     * @return array{0: string, 1: list<mixed>}
     */
    private function _posted_master_merged_query($date_from, $date_to, array $clinic_branch_ids, array $account_ids = []): array
    {
        $clinic_ids = $this->_positive_ids($clinic_branch_ids);
        $sql = $this->_posted_master_shared_sql($account_ids);
        $binds = $this->_posted_local_binds($date_from, $date_to, $account_ids);
        if (! $clinic_ids) {
            return [$sql, $binds];
        }

        $ph = implode(',', array_fill(0, count($clinic_ids), '?'));
        $account_sql = $this->_account_in_sql('general_ledger', $account_ids);
        $sql .= <<<EOT

UNION ALL
SELECT general_ledger.id,
    general_ledger.branch_id,
    general_ledger.source,
    general_ledger.source_id,
    general_ledger.account_type_id,
    general_ledger.date,
    general_ledger.debit,
    general_ledger.credit,
    general_ledger.remark
FROM general_ledger
WHERE general_ledger.is_deleted = 0
    AND general_ledger.account_type_id IS NOT NULL
    AND general_ledger.source IN (
        'billing_entry', 'billing_payment', 'billing_payment_deposit',
        'credit_memo', 'debit_memo'
    )
    AND general_ledger.branch_id IN ({$ph})
    AND general_ledger.date BETWEEN ? AND ?
    {$account_sql}
EOT;

        return [$sql, array_merge($binds, $clinic_ids, [$date_from, $date_to], $account_ids)];
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private function _positive_ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Posted debit/credit totals by account_type_id for all dates before $date.
     *
     * @return list<array{account_type_id:mixed,debit:mixed,credit:mixed}>
     */
    public function get_posted_account_totals_before($date): array
    {
        $before = date('Y-m-d', strtotime((string) $date . ' -1 day'));
        if (! $before || $before >= (string) $date) {
            return [];
        }

        $union = $this->_posted_union_sql();
        $sql = <<<EOT
SELECT posted.account_type_id,
    SUM(IFNULL(posted.debit, 0)) AS debit,
    SUM(IFNULL(posted.credit, 0)) AS credit
FROM (
    {$union}
) AS posted
GROUP BY posted.account_type_id
EOT;
        $sql = $this->_resolve_cross_db_sql($sql);
        $from = '1970-01-01';
        $query = $this->db->query($sql, $this->_posted_union_binds($from, $before));

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Paged posted rows for one account family.
     * Account/date filters are in the inner query. No account_type join.
     *
     * @param list<int> $account_type_ids
     * @return array{rows: list<array>, size: int}
     */
    public function get_posted_account_entries(
        $date_from,
        $date_to,
        array $account_type_ids,
        $page = null,
        $data_size = null,
        bool $local_only = false,
        $limit = null
    ): array {
        $ids = $this->_positive_ids($account_type_ids);
        if (! $ids) {
            return ['rows' => [], 'size' => 0];
        }

        if ($local_only && ! $this->_is_master_connection()) {
            $inner = $this->_posted_local_sql($ids);
            $binds = $this->_posted_local_binds($date_from, $date_to, $ids);
        } else {
            $inner = $this->_posted_union_sql($ids);
            $binds = $this->_posted_union_binds($date_from, $date_to, $ids);
        }

        return $this->_posted_account_page($inner, $binds, $page, $data_size, $limit);
    }

    /**
     * Shared master rows plus clinic billing/memo for one account family.
     * Call on the master connection only.
     *
     * @param list<int> $account_type_ids
     * @param list<int> $clinic_branch_ids
     * @return array{rows: list<array>, size: int}
     */
    public function get_posted_account_entries_master_merged(
        $date_from,
        $date_to,
        array $account_type_ids,
        array $clinic_branch_ids,
        $limit = null
    ): array {
        $ids = $this->_positive_ids($account_type_ids);
        if (! $ids) {
            return ['rows' => [], 'size' => 0];
        }

        [$inner, $binds] = $this->_posted_master_merged_query(
            $date_from,
            $date_to,
            $clinic_branch_ids,
            $ids
        );

        return $this->_posted_account_page($inner, $binds, null, null, $limit);
    }

    /**
     * Period debit/credit totals for one account family.
     *
     * @param list<int> $account_type_ids
     * @return array{debit: float, credit: float}
     */
    public function get_posted_account_period_totals(
        $date_from,
        $date_to,
        array $account_type_ids,
        bool $local_only = false
    ): array {
        $ids = $this->_positive_ids($account_type_ids);
        if (! $ids) {
            return ['debit' => 0.0, 'credit' => 0.0];
        }

        if ($local_only && ! $this->_is_master_connection()) {
            $inner = $this->_posted_local_sql($ids);
            $binds = $this->_posted_local_binds($date_from, $date_to, $ids);
        } else {
            $inner = $this->_posted_union_sql($ids);
            $binds = $this->_posted_union_binds($date_from, $date_to, $ids);
        }

        return $this->_posted_account_period_totals($inner, $binds);
    }

    /**
     * Period debit/credit totals for shared master rows plus clinic billing/memo.
     * Call on the master connection only.
     *
     * @param list<int> $account_type_ids
     * @param list<int> $clinic_branch_ids
     * @return array{debit: float, credit: float}
     */
    public function get_posted_account_period_totals_master_merged(
        $date_from,
        $date_to,
        array $account_type_ids,
        array $clinic_branch_ids
    ): array {
        $ids = $this->_positive_ids($account_type_ids);
        if (! $ids) {
            return ['debit' => 0.0, 'credit' => 0.0];
        }

        [$inner, $binds] = $this->_posted_master_merged_query(
            $date_from,
            $date_to,
            $clinic_branch_ids,
            $ids
        );

        return $this->_posted_account_period_totals($inner, $binds);
    }

    /**
     * @param list<mixed> $binds
     * @return array{debit: float, credit: float}
     */
    private function _posted_account_period_totals(string $inner, array $binds): array
    {
        $sql = $this->_resolve_cross_db_sql(
            <<<EOT
SELECT SUM(IFNULL(posted.debit, 0)) AS debit,
    SUM(IFNULL(posted.credit, 0)) AS credit
FROM (
    {$inner}
) AS posted
EOT
        );
        $query = $this->db->query($sql, $binds);
        $row = $query ? $query->getRowArray() : null;

        return [
            'debit'  => round((float) ($row['debit'] ?? 0), 4),
            'credit' => round((float) ($row['credit'] ?? 0), 4),
        ];
    }

    /**
     * Sum debit - credit of the first $offset posted rows (date, then id).
     *
     * @param list<int> $account_type_ids
     */
    public function get_posted_account_running_before(
        $date_from,
        $date_to,
        array $account_type_ids,
        int $offset,
        bool $local_only = false
    ): float {
        $offset = (int) $offset;
        if ($offset <= 0) {
            return 0.0;
        }

        $ids = $this->_positive_ids($account_type_ids);
        if (! $ids) {
            return 0.0;
        }

        if ($local_only && ! $this->_is_master_connection()) {
            $inner = $this->_posted_local_sql($ids);
            $binds = $this->_posted_local_binds($date_from, $date_to, $ids);
        } else {
            $inner = $this->_posted_union_sql($ids);
            $binds = $this->_posted_union_binds($date_from, $date_to, $ids);
        }

        $sql = $this->_resolve_cross_db_sql(
            <<<EOT
SELECT SUM(IFNULL(prior.debit, 0) - IFNULL(prior.credit, 0)) AS balance
FROM (
    SELECT posted.debit, posted.credit
    FROM (
        {$inner}
    ) AS posted
    ORDER BY posted.date, posted.id
    LIMIT {$offset}
) AS prior
EOT
        );
        $query = $this->db->query($sql, $binds);
        $row = $query ? $query->getRowArray() : null;

        return round((float) ($row['balance'] ?? 0), 4);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function with_running_balance(array $entries, float $start = 0.0): array
    {
        $running = $start;
        foreach ($entries as &$entry) {
            $running = round(
                $running + (float) ($entry['debit'] ?? 0) - (float) ($entry['credit'] ?? 0),
                4
            );
            $entry['running_balance'] = $running;
        }
        unset($entry);

        return $entries;
    }

    /**
     * @param list<mixed> $binds
     * @return array{rows: list<array>, size: int}
     */
    private function _posted_account_page(
        string $inner,
        array $binds,
        $page = null,
        $data_size = null,
        $limit = null
    ): array {
        $count_sql = $this->_resolve_cross_db_sql(
            "SELECT COUNT(*) AS total FROM ({$inner}) AS posted"
        );
        $count_query = $this->db->query($count_sql, $binds);
        $size = (int) (($count_query ? $count_query->getRowArray()['total'] : 0) ?? 0);

        $list_sql = $this->_resolve_cross_db_sql(
            <<<EOT
SELECT posted.id,
    posted.branch_id,
    posted.source,
    posted.source_id,
    posted.account_type_id,
    posted.date,
    posted.debit,
    posted.credit,
    posted.remark
FROM (
    {$inner}
) AS posted
ORDER BY posted.date, posted.id
EOT
        );

        if ($limit !== null) {
            $list_sql .= ' LIMIT ' . max(1, (int) $limit);
        } elseif ($page && $data_size) {
            $page = max(1, (int) $page);
            $data_size = (int) $data_size;
            $offset = ($page - 1) * $data_size;
            $list_sql .= " LIMIT {$offset}, {$data_size}";
        }

        $query = $this->db->query($list_sql, $binds);
        $rows = $query ? $query->getResultArray() : [];

        return ['rows' => $rows, 'size' => $size];
    }

    public function ensure_posted_schema(int $branch_id): void
    {
        $this->db->query(<<<'EOT'
CREATE TABLE IF NOT EXISTS gl_settings (
    branch_id int(11) NOT NULL,
    PRIMARY KEY (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
EOT);
        $this->db->query('INSERT IGNORE INTO gl_settings (branch_id) VALUES (?)', [$branch_id]);
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_gl_source_id ON general_ledger (source, source_id, is_deleted)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_gl_date_account ON general_ledger (date, is_deleted, account_type_id)');
    }

    /**
     * Post current source documents into general_ledger using the same
     * procedures as the branch triggers (CALL gl_backfill_all).
     */
    public function backfill_from_live($branch_id, $date_from = null, $date_to = null): int
    {
        $this->ensure_posted_schema((int) $branch_id);
        $before = $this->_posted_row_count();
        $this->db->query('CALL gl_backfill_all()');
        $this->_drain_call_results();

        return max(0, $this->_posted_row_count() - $before);
    }

    private function _posted_row_count(): int
    {
        $query = $this->db->query(
            'SELECT COUNT(*) AS total FROM general_ledger WHERE is_deleted = 0'
        );
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    private function _drain_call_results(): void
    {
        $conn = $this->db->connID ?? null;
        if (! is_object($conn) || ! method_exists($conn, 'more_results')) {
            return;
        }
        while ($conn->more_results() && $conn->next_result()) {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        }
    }

    private function _posted_source_exists(string $source, int $source_id): bool
    {
        $query = $this->db->query(
            'SELECT id FROM general_ledger WHERE source = ? AND source_id = ? AND is_deleted = 0 LIMIT 1',
            [$source, $source_id]
        );
        $row = $query ? $query->getRowArray() : null;

        return ! empty($row);
    }

    private function _posted_source_family(string $type): string
    {
        if (substr($type, -8) === '_revenue') {
            return substr($type, 0, -8);
        }

        return $type;
    }

    /**
     * Latest-document-only source totals for the balance sheet.
     * Purchases: receipt note > PO > PR.
     * Expenses: source line accounts plus APV payable / APV payment cash movement.
     *
     * @return array<int, array{account_type_id: mixed, type: string, type_id: mixed, debit: mixed, credit: mixed, txn_date: mixed, remarks?: mixed}>|false
     */
    public function get_balance_sheet_source_totals($date_from, $date_to, $branch_id = null)
    {
        return $this->get_posted_entries($date_from, $date_to);
    }

    /**
     * Live procurement / AP / expense journals plus approved journal items
     * (not the general_ledger table). Journals are queried separately so a
     * missing journal_entry table does not drop RN/PO/expense rows.
     *
     * @param string $date_bound between|before|through
     * @return array<int, array>
     */
    public function get_live_source_document_entries(
        $date_from,
        $date_to,
        string $date_bound = 'between',
        $account_type_id = null,
        bool $aggregate = false,
        $branch_id = null
    ) {
        $binds = [];
        $rn_date = 'COALESCE(receipt_note.receive_date, receipt_note.needed_date, DATE(receipt_note.added_on))';
        $po_date = 'COALESCE(purchase_order.purchase_date, purchase_order.needed_date, DATE(purchase_order.added_on))';
        $pr_date = 'COALESCE(purchase_request.request_date, purchase_request.needed_date, DATE(purchase_request.added_on))';
        $apv_date = 'COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on))';
        $apvp_date = 'COALESCE(apvpd.date, DATE(apvpd.added_on))';
        $cf_date = 'COALESCE(change_fund.date, DATE(change_fund.added_on))';
        $liq_date = 'COALESCE(liquidation_detail.expense_date, liquidation.due_date, DATE(liquidation.added_on))';
        $ca_date = 'COALESCE(cash_advance.date, DATE(cash_advance.added_on))';
        $reimb_date = 'COALESCE(reimbursement_item.expense_date, reimbursement.due_date, DATE(reimbursement.added_on))';
        $rep_date = 'COALESCE(replenishment_item.expense_date, replenishment.due_date, DATE(replenishment.added_on))';
        $pc_date = 'COALESCE(petty_cash.date, DATE(petty_cash.added_on))';
        $rn_later = $this->_later_doc_cutoff_sql('COALESCE(rn.receive_date, rn.needed_date, DATE(rn.added_on))', $date_bound);
        $po_later = $this->_later_doc_cutoff_sql('COALESCE(po.purchase_date, po.needed_date, DATE(po.added_on))', $date_bound);
        $liq_later = $this->_later_doc_cutoff_sql('COALESCE(liq.due_date, DATE(liq.added_on))', $date_bound);
        $rep_later = $this->_later_doc_cutoff_sql('COALESCE(rep.due_date, DATE(rep.added_on))', $date_bound);
        $ap_id_sql = $this->_account_type_id_by_code_sql('201-111');

        $sql = <<<EOT
SELECT src.account_type_id,
    src.type,
    src.type_id,
    src.debit,
    src.credit,
    src.txn_date,
    src.remarks
FROM (

SELECT COALESCE(receipt_note_item.account_type_id, item.account_type_id) AS account_type_id,
    'receipt_note_item' AS type,
    receipt_note_item.id AS type_id,
    COALESCE(receipt_note_item.amount, receipt_note_item.total, 0) AS debit,
    0 AS credit,
    {$rn_date} AS txn_date,
    CONCAT('receipt_note_item #', receipt_note_item.id) AS remarks
FROM receipt_note_item
INNER JOIN receipt_note ON receipt_note.id = receipt_note_item.receipt_note_id
    AND receipt_note.is_deleted = 0
    AND receipt_note.status = 'approved'
LEFT JOIN {MASTER_DB}.item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
WHERE receipt_note_item.is_deleted = 0
AND {$this->_date_bound_sql($rn_date, $date_bound)}

UNION ALL

SELECT COALESCE(purchase_order_item.account_type_id, item.account_type_id) AS account_type_id,
    'purchase_order_item' AS type,
    purchase_order_item.id AS type_id,
    COALESCE(purchase_order_item.amount, purchase_order_item.total, 0) AS debit,
    0 AS credit,
    {$po_date} AS txn_date,
    CONCAT('purchase_order_item #', purchase_order_item.id) AS remarks
FROM purchase_order_item
INNER JOIN purchase_order ON purchase_order.id = purchase_order_item.purchase_order_id
    AND purchase_order.is_deleted = 0
    AND purchase_order.status = 'approved'
LEFT JOIN {MASTER_DB}.item AS item ON item.id = purchase_order_item.item_id AND item.is_deleted = 0
WHERE purchase_order_item.is_deleted = 0
AND {$this->_date_bound_sql($po_date, $date_bound)}
AND NOT EXISTS (
    SELECT 1 FROM receipt_note AS rn
    WHERE rn.purchase_order_id = purchase_order.id
        AND rn.is_deleted = 0
        AND rn.status = 'approved'
        AND {$rn_later}
)

UNION ALL

SELECT COALESCE(purchase_request_item.account_type_id, item.account_type_id) AS account_type_id,
    'purchase_request_item' AS type,
    purchase_request_item.id AS type_id,
    COALESCE(purchase_request_item.amount, purchase_request_item.total, 0) AS debit,
    0 AS credit,
    {$pr_date} AS txn_date,
    CONCAT('purchase_request_item #', purchase_request_item.id) AS remarks
FROM purchase_request_item
INNER JOIN purchase_request ON purchase_request.id = purchase_request_item.purchase_request_id
    AND purchase_request.is_deleted = 0
    AND purchase_request.status = 'approved'
LEFT JOIN {MASTER_DB}.item AS item ON item.id = purchase_request_item.item_id AND item.is_deleted = 0
WHERE purchase_request_item.is_deleted = 0
AND {$this->_date_bound_sql($pr_date, $date_bound)}
AND NOT EXISTS (
    SELECT 1 FROM purchase_order AS po
    WHERE po.purchase_request_id = purchase_request.id
        AND po.is_deleted = 0
        AND po.status = 'approved'
        AND {$po_later}
)

UNION ALL

SELECT voucher_entry.account_type_id AS account_type_id,
    'accounts_payable_voucher' AS type,
    accounts_payable_voucher.id AS type_id,
    voucher_entry.debit AS debit,
    voucher_entry.credit AS credit,
    {$apv_date} AS txn_date,
    COALESCE(NULLIF(voucher_entry.remarks, ''), CONCAT('accounts_payable_voucher #', accounts_payable_voucher.id)) AS remarks
FROM accounts_payable_voucher
INNER JOIN accounts_payable_voucher_entry voucher_entry
    ON voucher_entry.accounts_payable_voucher_id = accounts_payable_voucher.id
    AND voucher_entry.is_deleted = 0
WHERE accounts_payable_voucher.is_deleted = 0
AND accounts_payable_voucher.status IN ('approved', 'open', 'closed')
AND {$this->_date_bound_sql($apv_date, $date_bound)}

UNION ALL

SELECT legacy_gl.account_type_id, legacy_gl.source AS type, legacy_gl.source_id AS type_id,
    legacy_gl.debit, legacy_gl.credit, legacy_gl.date AS txn_date, legacy_gl.remark AS remarks
FROM general_ledger legacy_gl
WHERE legacy_gl.is_deleted=0 AND (
    (legacy_gl.source='accounts_payable_voucher' AND gl_apv_is_legacy(legacy_gl.source_id)=1)
    OR (legacy_gl.source='direct_payment' AND EXISTS (SELECT 1 FROM apv_accounting_cutover
        WHERE id=1 AND legacy_gl.source_id<=legacy_max_direct_payment_id))
)
AND {$this->_date_bound_sql('legacy_gl.date', $date_bound)}

UNION ALL

SELECT apvp.account_type_id AS account_type_id,
    'accounts_payable_voucher_payment_detail' AS type,
    apvpd.id AS type_id,
    0 AS debit,
    COALESCE(apvpd.amount, 0) AS credit,
    {$apvp_date} AS txn_date,
    CONCAT('accounts_payable_voucher_payment_detail #', apvpd.id) AS remarks
FROM accounts_payable_voucher_payment_detail AS apvpd
INNER JOIN accounts_payable_voucher AS apv ON apv.id = apvpd.accounts_payable_voucher_id
    AND apv.is_deleted = 0
    AND apv.status IN ('approved', 'open', 'closed')
LEFT JOIN {MASTER_DB}.accounts_payable_voucher_payment AS apvp
    ON apvp.id = apvpd.accounts_payable_voucher_payment_id
    AND apvp.is_deleted = 0
WHERE apvpd.is_deleted = 0
AND {$this->_date_bound_sql($apvp_date, $date_bound)}

UNION ALL

SELECT {$ap_id_sql} AS account_type_id,
    'accounts_payable_voucher_payment_detail' AS type,
    apvpd.id AS type_id,
    COALESCE(apvpd.amount, 0) AS debit,
    0 AS credit,
    {$apvp_date} AS txn_date,
    CONCAT('accounts_payable_voucher_payment_detail #', apvpd.id) AS remarks
FROM accounts_payable_voucher_payment_detail AS apvpd
INNER JOIN accounts_payable_voucher AS apv ON apv.id = apvpd.accounts_payable_voucher_id
    AND apv.is_deleted = 0
    AND apv.status IN ('approved', 'open', 'closed')
WHERE apvpd.is_deleted = 0
AND {$this->_date_bound_sql($apvp_date, $date_bound)}

UNION ALL

SELECT change_fund.account_type_id AS account_type_id,
    'change_fund' AS type,
    change_fund.id AS type_id,
    COALESCE(change_fund.amount, 0) AS debit,
    0 AS credit,
    {$cf_date} AS txn_date,
    CONCAT('change_fund #', change_fund.id) AS remarks
FROM change_fund
WHERE change_fund.is_deleted = 0
AND change_fund.status = 'approved'
AND {$this->_date_bound_sql($cf_date, $date_bound)}

UNION ALL

SELECT liquidation_detail.account_type_id AS account_type_id,
    'liquidation_detail' AS type,
    liquidation_detail.id AS type_id,
    COALESCE(liquidation_detail.amount, liquidation_detail.total, 0) AS debit,
    0 AS credit,
    {$liq_date} AS txn_date,
    CONCAT('liquidation_detail #', liquidation_detail.id) AS remarks
FROM liquidation_detail
INNER JOIN liquidation ON liquidation.id = liquidation_detail.liquidation_id
    AND liquidation.is_deleted = 0
    AND liquidation.status = 'approved'
WHERE liquidation_detail.is_deleted = 0
AND {$this->_date_bound_sql($liq_date, $date_bound)}

UNION ALL

SELECT cash_advance.account_type_id AS account_type_id,
    'cash_advance' AS type,
    cash_advance.id AS type_id,
    COALESCE(cash_advance.amount, 0) AS debit,
    0 AS credit,
    {$ca_date} AS txn_date,
    CONCAT('cash_advance #', cash_advance.id) AS remarks
FROM cash_advance
WHERE cash_advance.is_deleted = 0
AND cash_advance.status = 'approved'
AND {$this->_date_bound_sql($ca_date, $date_bound)}
AND NOT EXISTS (
    SELECT 1 FROM liquidation AS liq
    WHERE liq.cash_advance_id = cash_advance.id
        AND liq.is_deleted = 0
        AND liq.status = 'approved'
        AND {$liq_later}
)

UNION ALL

SELECT reimbursement_item.account_type_id AS account_type_id,
    'reimbursement_item' AS type,
    reimbursement_item.id AS type_id,
    COALESCE(reimbursement_item.amount, reimbursement_item.total, 0) AS debit,
    0 AS credit,
    {$reimb_date} AS txn_date,
    CONCAT('reimbursement_item #', reimbursement_item.id) AS remarks
FROM reimbursement_item
INNER JOIN reimbursement ON reimbursement.id = reimbursement_item.reimbursement_id
    AND reimbursement.is_deleted = 0
    AND reimbursement.status = 'approved'
WHERE reimbursement_item.is_deleted = 0
AND {$this->_date_bound_sql($reimb_date, $date_bound)}

UNION ALL

SELECT replenishment_item.account_type_id AS account_type_id,
    'replenishment_item' AS type,
    replenishment_item.id AS type_id,
    COALESCE(replenishment_item.amount, replenishment_item.total, 0) AS debit,
    0 AS credit,
    {$rep_date} AS txn_date,
    CONCAT('replenishment_item #', replenishment_item.id) AS remarks
FROM replenishment_item
INNER JOIN replenishment ON replenishment.id = replenishment_item.replenishment_id
    AND replenishment.is_deleted = 0
    AND replenishment.status = 'approved'
WHERE replenishment_item.is_deleted = 0
AND {$this->_date_bound_sql($rep_date, $date_bound)}

UNION ALL

SELECT petty_cash.account_type_id AS account_type_id,
    'petty_cash' AS type,
    petty_cash.id AS type_id,
    COALESCE(petty_cash.amount, 0) AS debit,
    0 AS credit,
    {$pc_date} AS txn_date,
    CONCAT('petty_cash #', petty_cash.id) AS remarks
FROM petty_cash
WHERE petty_cash.is_deleted = 0
AND petty_cash.status = 'approved'
AND {$this->_date_bound_sql($pc_date, $date_bound)}
AND NOT EXISTS (
    SELECT 1 FROM replenishment AS rep
    WHERE rep.petty_cash_id = petty_cash.id
        AND rep.is_deleted = 0
        AND rep.status = 'approved'
        AND {$rep_later}
)

) AS src
WHERE src.account_type_id IS NOT NULL
EOT;

        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_cutoff($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_cutoff($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_cutoff($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_cutoff($binds, $date_bound, $date_from, $date_to);

        $docs = $this->_finalize_live_journal_sql($sql, $binds, $account_type_id, $aggregate);
        $journals = $this->_journal_entry_item_entries(
            $date_from,
            $date_to,
            $date_bound,
            $account_type_id,
            $aggregate,
            $branch_id
        );

        return array_merge($docs ?: [], $journals ?: []);
    }

    /**
     * Approved journal items for one cost center (branch). Isolated so a
     * missing journal_entry table does not drop RN/PO/expense rows.
     *
     * @return array<int, array>|false
     */
    private function _journal_entry_item_entries(
        $date_from,
        $date_to,
        string $date_bound,
        $account_type_id = null,
        bool $aggregate = false,
        $branch_id = null
    ) {
        $je_date = 'COALESCE(journal_entry.date, DATE(journal_entry.added_on))';
        $sql = <<<EOT
SELECT journal_entry_item.account_type_id AS account_type_id,
    'journal_entry' AS type,
    journal_entry.id AS type_id,
    COALESCE(journal_entry_item.debit, 0) AS debit,
    COALESCE(journal_entry_item.credit, 0) AS credit,
    {$je_date} AS txn_date,
    COALESCE(
        NULLIF(journal_entry_item.remarks, ''),
        NULLIF(journal_entry.remarks, ''),
        CONCAT('journal_entry #', journal_entry.id)
    ) AS remarks
FROM journal_entry_item
INNER JOIN journal_entry ON journal_entry.id = journal_entry_item.journal_entry_id
    AND journal_entry.is_deleted = 0
    AND journal_entry.status = 'approved'
WHERE journal_entry_item.is_deleted = 0
AND {$this->_date_bound_sql($je_date, $date_bound)}
EOT;
        $binds = [];
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        if ($branch_id) {
            $sql .= <<<EOT

AND journal_entry.cost_center_id = ?
EOT;
            $binds[] = $branch_id;
        }

        return $this->_finalize_live_journal_sql($sql, $binds, $account_type_id, $aggregate);
    }

    /**
     * CSR sales, HMO/corp unbilled AR, billing reclass, collections, and deposits.
     *
     * @param string $date_bound between|before|through
     * @return array<int, array>
     */
    public function get_clinic_operation_source_totals(
        $date_from,
        $date_to,
        string $date_bound = 'between',
        $account_type_id = null,
        bool $aggregate = false
    ): array {
        $asset_rows = $this->_clinic_sale_asset_entries($date_from, $date_to, $date_bound, $account_type_id, $aggregate) ?: [];
        $billing_rows = $this->_clinic_billing_entries($date_from, $date_to, $date_bound, $account_type_id, $aggregate) ?: [];
        $rows = array_merge($asset_rows, $billing_rows);
        if (! $aggregate) {
            return $rows;
        }

        $merged = [];
        foreach ($rows as $row) {
            $id = (int) ($row['account_type_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (! isset($merged[$id])) {
                $merged[$id] = [
                    'account_type_id' => $id,
                    'debit'           => 0.0,
                    'credit'          => 0.0,
                ];
            }
            $merged[$id]['debit'] += (float) ($row['debit'] ?? 0);
            $merged[$id]['credit'] += (float) ($row['credit'] ?? 0);
        }

        return array_values($merged);
    }

    /**
     * Opening / period / closing lines from live source documents plus clinic journals.
     *
     * @return array<int, array{account_type_id: mixed, type: string, type_id: mixed, debit: mixed, credit: mixed, txn_date: mixed, remarks: mixed, name: mixed, account_type: array{id: int, code: mixed, name: mixed}}>
     */
    public function get_live_general_ledger_entries(
        $account_type_id = null,
        $date_from = null,
        $date_to = null,
        string $date_bound = 'between',
        $branch_id = null
    ): array {
        $docs = $this->get_live_source_document_entries($date_from, $date_to, $date_bound, $account_type_id, false, $branch_id) ?: [];
        $clinic = $this->get_clinic_operation_source_totals($date_from, $date_to, $date_bound, $account_type_id);
        $rows = array_merge($docs, $clinic);

        $accounts = $this->_account_types_by_id($rows);
        $filtered = [];
        foreach ($rows as $row) {
            $id = (int) ($row['account_type_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($account_type_id && (int) $account_type_id !== $id) {
                continue;
            }
            $row['account_type'] = [
                'id'                  => $id,
                'code'                => $accounts[$id]['code'] ?? null,
                'name'                => $accounts[$id]['name'] ?? null,
                'financial_statement' => $accounts[$id]['financial_statement'] ?? null,
            ];
            $row['name'] = $accounts[$id]['name'] ?? null;
            $filtered[] = $row;
        }

        usort($filtered, static function ($a, $b) {
            $date_cmp = strcmp((string) ($a['txn_date'] ?? ''), (string) ($b['txn_date'] ?? ''));
            if ($date_cmp !== 0) {
                return $date_cmp;
            }

            return strcmp(
                (string) ($a['type'] ?? '') . '-' . (string) ($a['type_id'] ?? ''),
                (string) ($b['type'] ?? '') . '-' . (string) ($b['type_id'] ?? '')
            );
        });

        return $filtered;
    }

    /**
     * Debit/credit totals by account from live journals (no line items).
     *
     * @return array<int, array{account_type_id: int, debit: float, credit: float}>
     */
    public function get_live_general_ledger_totals(
        $account_type_id = null,
        $date_from = null,
        $date_to = null,
        string $date_bound = 'before',
        $branch_id = null
    ): array {
        $docs = $this->get_live_source_document_entries($date_from, $date_to, $date_bound, $account_type_id, true, $branch_id) ?: [];
        $clinic = $this->get_clinic_operation_source_totals($date_from, $date_to, $date_bound, $account_type_id, true);
        $totals = [];
        foreach (array_merge($docs, $clinic) as $row) {
            $id = (int) ($row['account_type_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (! isset($totals[$id])) {
                $totals[$id] = [
                    'account_type_id' => $id,
                    'debit'           => 0.0,
                    'credit'          => 0.0,
                ];
            }
            $totals[$id]['debit'] += (float) ($row['debit'] ?? 0);
            $totals[$id]['credit'] += (float) ($row['credit'] ?? 0);
        }

        return $totals;
    }

    /**
     * Cash / card / e-wallet collections and unbilled HMO/corp remainder,
     * each paired with an equal revenue credit so the journal balances.
     *
     * @return array<int, array>|false
     */
    private function _clinic_sale_asset_entries(
        $date_from,
        $date_to,
        string $date_bound,
        $account_type_id = null,
        bool $aggregate = false
    )
    {
        $mop_code_sql = $this->_clinic_mop_code_sql();
        $collected_sql = $this->_clinic_collected_mop_sql();
        $txn_date_sql = $this->_date_bound_sql('transaction.transaction_date', $date_bound);
        $unbilled_id_sql = $this->_account_type_id_by_code_sql('102-113B');
        $discount_id_sql = $this->_account_type_id_by_code_sql('440-000');
        $txn_rev_join = $this->_clinic_transaction_revenue_join_sql();
        $cancelled = "LOWER(COALESCE(booking.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')";
        $unbilled_amount_sql = <<<EOT
GREATEST(
    0,
    GREATEST(
        COALESCE(transaction.grand_total, 0),
        COALESCE(svc.service_amount, 0)
    ) - COALESCE(collected.collected_amount, 0)
)
EOT;
        $unbilled_from_sql = <<<EOT
FROM transaction
{$txn_rev_join}
LEFT JOIN (
    SELECT booking.transaction_id,
        COALESCE(SUM(COALESCE(NULLIF(booking.grand_total, 0), booking.total_amount, 0)), 0) AS service_amount
    FROM booking
    WHERE booking.is_deleted = 0
    AND {$cancelled}
    GROUP BY booking.transaction_id
) AS svc ON svc.transaction_id = transaction.id
LEFT JOIN (
    SELECT payment.transaction_id,
        COALESCE(SUM(payment_multiple_method.amount), 0) AS collected_amount
    FROM payment
    INNER JOIN payment_multiple_method ON payment_multiple_method.payment_id = payment.id
        AND payment_multiple_method.is_deleted = 0
    WHERE payment.is_deleted = 0
    AND {$collected_sql}
    GROUP BY payment.transaction_id
) AS collected ON collected.transaction_id = transaction.id
WHERE transaction.is_deleted = 0
AND {$txn_date_sql}
AND COALESCE(transaction.is_billed, 0) = 0
AND transaction.billing_id IS NULL
AND (
    COALESCE(transaction.hmo_id, 0) > 0
    OR COALESCE(transaction.endorsement_id, 0) > 0
    OR EXISTS (
        SELECT 1 FROM booking
        WHERE booking.transaction_id = transaction.id
            AND booking.is_deleted = 0
            AND (
                COALESCE(booking.company_id, 0) > 0
                OR COALESCE(booking.hmo_id, 0) > 0
                OR COALESCE(booking.charge_to, 0) > 0
            )
    )
    OR EXISTS (
        SELECT 1 FROM payment
        WHERE payment.transaction_id = transaction.id
            AND payment.is_deleted = 0
            AND COALESCE(payment.charge_to, 0) > 0
    )
)
AND NOT EXISTS (
    SELECT 1
    FROM {MASTER_DB}.billing_entry
    INNER JOIN {MASTER_DB}.billing ON billing.id = billing_entry.billing_id
        AND billing.is_deleted = 0
        AND billing.status IN ('open_bill', 'closed_bill')
    WHERE billing_entry.transaction_id = transaction.id
        AND billing_entry.is_deleted = 0
        AND billing_entry.status IN ('open_bill', 'closed_bill')
)
AND {$unbilled_amount_sql} > 0
EOT;

        $sql = <<<EOT
SELECT account_type.id AS account_type_id,
    'payment_multiple_method' AS type,
    payment_multiple_method.id AS type_id,
    COALESCE(payment_multiple_method.amount, 0) AS debit,
    0 AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR collection #', COALESCE(transaction.transaction_no, payment.id)) AS remarks
FROM payment_multiple_method
INNER JOIN payment ON payment.id = payment_multiple_method.payment_id
    AND payment.is_deleted = 0
INNER JOIN transaction ON transaction.id = payment.transaction_id
    AND transaction.is_deleted = 0
    AND {$txn_date_sql}
{$txn_rev_join}
INNER JOIN {MASTER_DB}.account_type AS account_type
    ON account_type.code = {$mop_code_sql}
    AND account_type.parent_id IS NULL
    AND account_type.is_deleted = 0
WHERE payment_multiple_method.is_deleted = 0
AND {$mop_code_sql} IS NOT NULL
AND COALESCE(payment_multiple_method.amount, 0) > 0

UNION ALL

SELECT revenue_account.id AS account_type_id,
    'payment_multiple_method_revenue' AS type,
    payment_multiple_method.id AS type_id,
    0 AS debit,
    COALESCE(payment_multiple_method.amount, 0) AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR sale #', COALESCE(transaction.transaction_no, payment.id)) AS remarks
FROM payment_multiple_method
INNER JOIN payment ON payment.id = payment_multiple_method.payment_id
    AND payment.is_deleted = 0
INNER JOIN transaction ON transaction.id = payment.transaction_id
    AND transaction.is_deleted = 0
    AND {$txn_date_sql}
{$txn_rev_join}
WHERE payment_multiple_method.is_deleted = 0
AND {$mop_code_sql} IS NOT NULL
AND COALESCE(payment_multiple_method.amount, 0) > 0

UNION ALL

SELECT {$discount_id_sql} AS account_type_id,
    'payment' AS type,
    payment.id AS type_id,
    COALESCE(payment.discount, 0) AS debit,
    0 AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR discount #', COALESCE(transaction.transaction_no, payment.id)) AS remarks
FROM payment
INNER JOIN transaction ON transaction.id = payment.transaction_id
    AND transaction.is_deleted = 0
    AND {$txn_date_sql}
{$txn_rev_join}
WHERE payment.is_deleted = 0
AND COALESCE(payment.discount, 0) > 0

UNION ALL

SELECT revenue_account.id AS account_type_id,
    'payment_revenue' AS type,
    payment.id AS type_id,
    0 AS debit,
    COALESCE(payment.discount, 0) AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR sale discount #', COALESCE(transaction.transaction_no, payment.id)) AS remarks
FROM payment
INNER JOIN transaction ON transaction.id = payment.transaction_id
    AND transaction.is_deleted = 0
    AND {$txn_date_sql}
{$txn_rev_join}
WHERE payment.is_deleted = 0
AND COALESCE(payment.discount, 0) > 0

UNION ALL

SELECT {$unbilled_id_sql} AS account_type_id,
    'transaction' AS type,
    transaction.id AS type_id,
    {$unbilled_amount_sql} AS debit,
    0 AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('HMO/Corp unbilled #', COALESCE(transaction.transaction_no, transaction.id)) AS remarks
{$unbilled_from_sql}

UNION ALL

SELECT revenue_account.id AS account_type_id,
    'transaction_revenue' AS type,
    transaction.id AS type_id,
    0 AS debit,
    {$unbilled_amount_sql} AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR unbilled sale #', COALESCE(transaction.transaction_no, transaction.id)) AS remarks
{$unbilled_from_sql}
EOT;

        $binds = [];
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);

        return $this->_finalize_live_journal_sql($sql, $binds, $account_type_id, $aggregate);
    }

    /**
     * Resolve one P&L revenue account per transaction (lab / diagnostic / consult).
     */
    private function _clinic_transaction_revenue_join_sql(): string
    {
        return <<<EOT
LEFT JOIN (
    SELECT booking.transaction_id,
        CASE
            WHEN MAX(CASE WHEN booking.transaction_type = 'consultation' THEN 1 ELSE 0 END) = 1 THEN '400-030'
            WHEN MAX(CASE WHEN booking_details.type = 'lab' THEN 1 ELSE 0 END) = 1 THEN '400-010a'
            WHEN MAX(CASE WHEN booking_details.type IN ('test', 'diagnosis')
                OR booking.transaction_type = 'diagnosis' THEN 1 ELSE 0 END) = 1 THEN '400-010b'
            WHEN MAX(CASE WHEN booking.transaction_type = 'pharmacy' THEN 1 ELSE 0 END) = 1 THEN '420-000'
            WHEN MAX(CASE WHEN booking.transaction_type = 'vaccination' THEN 1 ELSE 0 END) = 1 THEN '480-030'
            ELSE '400-100'
        END AS revenue_code
    FROM booking
    LEFT JOIN booking_details ON booking_details.booking_id = booking.id
        AND booking_details.is_deleted = 0
    WHERE booking.is_deleted = 0
    GROUP BY booking.transaction_id
) AS txn_rev ON txn_rev.transaction_id = transaction.id
INNER JOIN {MASTER_DB}.account_type AS revenue_account
    ON revenue_account.code = COALESCE(txn_rev.revenue_code, '400-100')
    AND revenue_account.parent_id IS NULL
    AND revenue_account.is_deleted = 0
EOT;
    }

    /**
     * Billing reclass (unbilled -> billed), collections, and deposits.
     *
     * @return array<int, array>|false
     */
    private function _clinic_billing_entries(
        $date_from,
        $date_to,
        string $date_bound,
        $account_type_id = null,
        bool $aggregate = false
    )
    {
        $billed_id_sql = $this->_account_type_id_by_code_sql('102-113');
        $unbilled_id_sql = $this->_account_type_id_by_code_sql('102-113B');
        $cwt_id_sql = $this->_account_type_id_by_code_sql('105-000');
        $cash_id_sql = $this->_account_type_id_by_code_sql('100-004');
        $gcash_id_sql = $this->_account_type_id_by_code_sql('102-193');
        $bill_date = 'DATE(COALESCE(NULLIF(billing.added_on, "0000-00-00 00:00:00"), billing_entry.added_on))';
        $pay_date = 'COALESCE(billing_payment.paid_date, billing_payment.date, DATE(billing_payment.added_on))';
        $dep_date = 'COALESCE(DATE(billing_payment_deposit.added_on), billing_payment.paid_date, billing_payment.date)';
        $branch_payment_exists = <<<EOT
EXISTS (
    SELECT 1
    FROM {MASTER_DB}.billing_payment_entry AS bpe
    INNER JOIN {MASTER_DB}.billing_entry AS be ON be.id = bpe.billing_entry_id
        AND be.is_deleted = 0
    INNER JOIN transaction ON transaction.id = be.transaction_id
        AND transaction.is_deleted = 0
    WHERE bpe.billing_payment_id = billing_payment.id
        AND bpe.is_deleted = 0
)
EOT;

        $sql = <<<EOT
SELECT {$billed_id_sql} AS account_type_id,
    'billing_entry' AS type,
    billing_entry.id AS type_id,
    COALESCE(billing_entry.amount, 0) AS debit,
    0 AS credit,
    {$bill_date} AS txn_date,
    CONCAT('Billed invoice #', billing_entry.billing_id) AS remarks
FROM {MASTER_DB}.billing_entry
INNER JOIN {MASTER_DB}.billing ON billing.id = billing_entry.billing_id
    AND billing.is_deleted = 0
    AND billing.status IN ('open_bill', 'closed_bill')
INNER JOIN transaction ON transaction.id = billing_entry.transaction_id
    AND transaction.is_deleted = 0
WHERE billing_entry.is_deleted = 0
AND billing_entry.status IN ('open_bill', 'closed_bill')
AND {$this->_date_bound_sql($bill_date, $date_bound)}

UNION ALL

SELECT {$unbilled_id_sql} AS account_type_id,
    'billing_entry' AS type,
    billing_entry.id AS type_id,
    0 AS debit,
    COALESCE(billing_entry.amount, 0) AS credit,
    {$bill_date} AS txn_date,
    CONCAT('Reclass unbilled #', billing_entry.billing_id) AS remarks
FROM {MASTER_DB}.billing_entry
INNER JOIN {MASTER_DB}.billing ON billing.id = billing_entry.billing_id
    AND billing.is_deleted = 0
    AND billing.status IN ('open_bill', 'closed_bill')
INNER JOIN transaction ON transaction.id = billing_entry.transaction_id
    AND transaction.is_deleted = 0
WHERE billing_entry.is_deleted = 0
AND billing_entry.status IN ('open_bill', 'closed_bill')
AND {$this->_date_bound_sql($bill_date, $date_bound)}

UNION ALL

SELECT COALESCE(
        billing_payment.account_type_id,
        CASE
            WHEN billing_payment.mop = 'online_payment' THEN {$gcash_id_sql}
            ELSE {$cash_id_sql}
        END
    ) AS account_type_id,
    'billing_payment' AS type,
    billing_payment.id AS type_id,
    GREATEST(0, COALESCE(billing_payment.grand_total, 0) - COALESCE(billing_payment.wht_amount, 0)) AS debit,
    0 AS credit,
    {$pay_date} AS txn_date,
    CONCAT('Collection #', billing_payment.id) AS remarks
FROM {MASTER_DB}.billing_payment
WHERE billing_payment.is_deleted = 0
AND {$branch_payment_exists}
AND {$this->_date_bound_sql($pay_date, $date_bound)}
AND GREATEST(0, COALESCE(billing_payment.grand_total, 0) - COALESCE(billing_payment.wht_amount, 0)) > 0

UNION ALL

SELECT {$cwt_id_sql} AS account_type_id,
    'billing_payment' AS type,
    billing_payment.id AS type_id,
    COALESCE(billing_payment.wht_amount, 0) AS debit,
    0 AS credit,
    {$pay_date} AS txn_date,
    CONCAT('Collection CWT #', billing_payment.id) AS remarks
FROM {MASTER_DB}.billing_payment
WHERE billing_payment.is_deleted = 0
AND {$branch_payment_exists}
AND {$this->_date_bound_sql($pay_date, $date_bound)}
AND COALESCE(billing_payment.wht_amount, 0) > 0

UNION ALL

SELECT {$billed_id_sql} AS account_type_id,
    'billing_payment' AS type,
    billing_payment.id AS type_id,
    0 AS debit,
    COALESCE(billing_payment.grand_total, 0) AS credit,
    {$pay_date} AS txn_date,
    CONCAT('Collection apply #', billing_payment.id) AS remarks
FROM {MASTER_DB}.billing_payment
WHERE billing_payment.is_deleted = 0
AND {$branch_payment_exists}
AND {$this->_date_bound_sql($pay_date, $date_bound)}
AND COALESCE(billing_payment.grand_total, 0) > 0

UNION ALL

SELECT billing_payment_deposit.account_type_id AS account_type_id,
    'billing_payment_deposit' AS type,
    billing_payment_deposit.id AS type_id,
    COALESCE(billing_payment_deposit.amount, 0) AS debit,
    0 AS credit,
    {$dep_date} AS txn_date,
    CONCAT('Deposit #', billing_payment_deposit.id) AS remarks
FROM {MASTER_DB}.billing_payment_deposit
INNER JOIN {MASTER_DB}.billing_payment ON billing_payment.id = billing_payment_deposit.billing_payment_id
    AND billing_payment.is_deleted = 0
WHERE billing_payment_deposit.is_deleted = 0
AND {$branch_payment_exists}
AND {$this->_date_bound_sql($dep_date, $date_bound)}

UNION ALL

SELECT {$cash_id_sql} AS account_type_id,
    'billing_payment_deposit' AS type,
    billing_payment_deposit.id AS type_id,
    0 AS debit,
    COALESCE(billing_payment_deposit.amount, 0) AS credit,
    {$dep_date} AS txn_date,
    CONCAT('Deposit from cash/check on hand #', billing_payment_deposit.id) AS remarks
FROM {MASTER_DB}.billing_payment_deposit
INNER JOIN {MASTER_DB}.billing_payment ON billing_payment.id = billing_payment_deposit.billing_payment_id
    AND billing_payment.is_deleted = 0
WHERE billing_payment_deposit.is_deleted = 0
AND {$branch_payment_exists}
AND {$this->_date_bound_sql($dep_date, $date_bound)}
EOT;

        $binds = [];
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);
        $this->_bind_date_bound($binds, $date_bound, $date_from, $date_to);

        return $this->_finalize_live_journal_sql($sql, $binds, $account_type_id, $aggregate);
    }

    /**
     * @return array<int, array>|false
     */
    private function _finalize_live_journal_sql(string $sql, array $binds, $account_type_id, bool $aggregate)
    {
        $sql = $this->_wrap_live_journal_sql($sql, $account_type_id, $aggregate);
        if ($account_type_id) {
            $binds[] = $account_type_id;
        }
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }

    private function _wrap_live_journal_sql(string $sql, $account_type_id, bool $aggregate): string
    {
        if (! $aggregate && ! $account_type_id) {
            return $sql;
        }

        $filter = 'wrapped.account_type_id IS NOT NULL';
        if ($account_type_id) {
            $filter .= ' AND wrapped.account_type_id = ?';
        }
        if ($aggregate) {
            return <<<EOT
SELECT wrapped.account_type_id,
    SUM(wrapped.debit) AS debit,
    SUM(wrapped.credit) AS credit
FROM ({$sql}) AS wrapped
WHERE {$filter}
GROUP BY wrapped.account_type_id
EOT;
        }

        return <<<EOT
SELECT wrapped.account_type_id,
    wrapped.type,
    wrapped.type_id,
    wrapped.debit,
    wrapped.credit,
    wrapped.txn_date,
    wrapped.remarks
FROM ({$sql}) AS wrapped
WHERE {$filter}
EOT;
    }

    private function _clinic_mop_code_sql(): string
    {
        return <<<EOT
CASE
    WHEN LOWER(payment_multiple_method.type) = 'cash' THEN '100-004'
    WHEN LOWER(payment_multiple_method.type) = 'others'
        AND LOWER(REPLACE(COALESCE(payment_multiple_method.other_payment_details, ''), ' ', '')) = 'check' THEN '100-004'
    WHEN LOWER(payment_multiple_method.type) = 'card' THEN '102-192'
    WHEN LOWER(payment_multiple_method.type) = 'gcash' THEN '102-193'
    WHEN LOWER(payment_multiple_method.type) = 'company_wallet' THEN '102-113B'
    WHEN LOWER(payment_multiple_method.type) = 'others'
        AND LOWER(REPLACE(COALESCE(payment_multiple_method.other_payment_details, ''), ' ', '')) IN ('gcash', 'maya', 'qrph', 'qr') THEN '102-193'
    ELSE NULL
END
EOT;
    }

    private function _clinic_collected_mop_sql(): string
    {
        return <<<EOT
(
    LOWER(payment_multiple_method.type) = 'cash'
    OR LOWER(payment_multiple_method.type) = 'card'
    OR LOWER(payment_multiple_method.type) = 'gcash'
    OR (
        LOWER(payment_multiple_method.type) = 'others'
        AND LOWER(REPLACE(COALESCE(payment_multiple_method.other_payment_details, ''), ' ', '')) IN ('check', 'gcash', 'maya', 'qrph', 'qr')
    )
)
EOT;
    }

    private function _account_type_id_by_code_sql(string $code): string
    {
        $code = str_replace("'", "''", $code);

        return "(
        SELECT account_type.id
        FROM {MASTER_DB}.account_type AS account_type
        WHERE account_type.code = '{$code}'
            AND account_type.parent_id IS NULL
            AND account_type.is_deleted = 0
        LIMIT 1
    )";
    }

    private function _lookup_account_type_id_by_code(string $code): ?int
    {
        static $cache = [];
        $key = strtoupper(trim($code));
        if ($key === '') {
            return null;
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $sql = $this->_resolve_cross_db_sql(
            "SELECT account_type.id
            FROM {MASTER_DB}.account_type AS account_type
            WHERE UPPER(account_type.code) = ?
                AND account_type.parent_id IS NULL
                AND account_type.is_deleted = 0
            LIMIT 1"
        );
        $query = $this->db->query($sql, [$key]);
        $row = $query ? $query->getRowArray() : null;
        $cache[$key] = $row ? (int) $row['id'] : null;

        return $cache[$key];
    }

    /**
     * @param array<int, array> $rows
     * @return array<int, array{id: int, code: mixed, name: mixed}>
     */
    private function _account_types_by_id(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['account_type_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (! $ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = $this->_resolve_cross_db_sql(
            "SELECT account_type.id, account_type.code, account_type.name, account_type.financial_statement
            FROM {MASTER_DB}.account_type AS account_type
            WHERE account_type.id IN ({$placeholders})"
        );
        $query = $this->db->query($sql, array_values($ids));
        $accounts = [];
        foreach (($query ? $query->getResultArray() : []) as $row) {
            $account_id = (int) $row['id'];
            $accounts[$account_id] = [
                'id'                  => $account_id,
                'code'                => $row['code'] ?? null,
                'name'                => $row['name'] ?? null,
                'financial_statement' => $row['financial_statement'] ?? null,
            ];
        }

        return $accounts;
    }

    private function _date_bound_sql(string $expr, string $date_bound): string
    {
        if ($date_bound === 'before') {
            return "{$expr} < ?";
        }
        if ($date_bound === 'through') {
            return "{$expr} <= ?";
        }
        if ($date_bound === 'none') {
            return '1 = 1';
        }

        return "{$expr} BETWEEN ? AND ?";
    }

    private function _later_doc_cutoff_sql(string $expr, string $date_bound): string
    {
        if ($date_bound === 'none') {
            return '1 = 1';
        }
        if ($date_bound === 'before') {
            return "{$expr} < ?";
        }

        return "{$expr} <= ?";
    }

    private function _bind_date_bound(array &$binds, string $date_bound, $date_from, $date_to): void
    {
        if ($date_bound === 'none') {
            return;
        }
        if ($date_bound === 'before') {
            $binds[] = $date_from;
            return;
        }
        if ($date_bound === 'through') {
            $binds[] = $date_to;
            return;
        }

        $binds[] = $date_from;
        $binds[] = $date_to;
    }

    private function _bind_date_cutoff(array &$binds, string $date_bound, $date_from, $date_to): void
    {
        if ($date_bound === 'none') {
            return;
        }
        $binds[] = $date_bound === 'before' ? $date_from : $date_to;
    }
}

?>
