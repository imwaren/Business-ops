<?php

namespace App\Core;

namespace App\Models;

use App\Core\MYT_Model;
use App\Models\Company;

class Report_clinic extends MYT_Model
{
    protected $table         = 'api_log';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['controller', 'method', 'ip_address', 'latitude', 'longitude', 'data_received', 'signature', 'requested_by', 'requested_on'];

    protected $company;

    /** @var array<int, string>|null */
    private $_branchSchemasCache = null;

    public function __construct()
    {
        parent::__construct();
        $this->company = new Company();
    }

    /**
     * Resolve branch id => schema name from Config\Branch + Config\Database.
     *
     * @return array<int, string>
     */
    private function _getBranchSchemas(): array
    {
        if ($this->_branchSchemasCache !== null) {
            return $this->_branchSchemasCache;
        }

        $branchConfig = config('Branch')->branches;
        $dbConfig     = config('Database');

        if (empty($branchConfig)) {
            throw new \RuntimeException('No branches configured in Config\Branch.');
        }

        $schemas = [];
        foreach ($branchConfig as $branchId => $info) {
            $group = $info['db'] ?? null;

            if (! $group || ! isset($dbConfig->{$group})) {
                throw new \RuntimeException("Branch id {$branchId} ('{$info['name']}') has no matching Config\\Database group '{$group}'.");
            }

            $schema = $dbConfig->{$group}['database'] ?? null;

            if (! $schema || ! preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
                throw new \RuntimeException("Invalid or missing schema name for branch '{$group}'.");
            }

            $schemas[(int) $branchId] = $schema;
        }

        return $this->_branchSchemasCache = $schemas;
    }

    /**
     * Optional date-range predicate for pushdown into branch subqueries.
     * $column must include the SQL table alias when used inside an aliased FROM clause (e.g. tx.added_on).
     */
    private function _scopedDateSql(string $column, $date_from, $date_to): string
    {
        return "((? IS NULL OR {$column} >= ?) AND (? IS NULL OR {$column} < DATE_ADD(?, INTERVAL 1 DAY)))";
    }

    /**
     * Binds for _scopedDateSql (four placeholders).
     */
    private function _scopedDateBinds($date_from, $date_to): array
    {
        return [$date_from, $date_from, $date_to, $date_to];
    }

    /**
     * SQL expression for a globally unique transaction id across branches.
     */
    private function _compositeTxId(string $branchCol = 'branch_id', string $txCol = 'transaction_id'): string
    {
        return "CONCAT({$branchCol}, '-', {$txCol})";
    }

    /**
     * Escaped encryption key literal for AES_DECRYPT (avoids positional bind drift in UNION queries).
     */
    private function _encryptionKeySql(): string
    {
        return $this->db->escape(ENCRYPTION_KEY);
    }

    /**
     * Escaped today's date literal for age calculations (avoids per-branch bind repetition).
     */
    private function _dateTodaySql(): string
    {
        return $this->db->escape(date('Y-m-d'));
    }

    /**
     * Outer PPL filter placeholders shared by detail and aggregate queries.
     */
    private function _pplFilterBinds($age_group, $charge_to, $membership_id): array
    {
        return [
            $age_group,
            $age_group,
            $charge_to,
            $charge_to,
            $membership_id,
            $membership_id,
        ];
    }

    /**
     * Build UNION ALL for branch patient rows.
     * When a date range is given, only patients with transactions in that window are included.
     *
     * @return array{sql: string, decrypt_binds: array}
     */
    private function _buildBranchPatientUnion($date_from = null, $date_to = null, string $dateColumn = 'added_on', bool $decryptBirthdate = true): array
    {
        $schemas           = $this->_getBranchSchemas();
        $patientUnionParts = [];
        $decryptBinds      = [];
        $scopedDate        = $this->_scopedDateSql('tx.' . $dateColumn, $date_from, $date_to);
        $keySql            = $this->_encryptionKeySql();

        foreach ($schemas as $branchId => $schema) {
            $birthdateSelect = $decryptBirthdate
                ? "CAST(AES_DECRYPT(p.birthdate, {$keySql}) AS DATE) AS birthdate_decrypted"
                : 'NULL AS birthdate_decrypted';

            $patientUnionParts[] = "SELECT p.master_id, p.id AS branch_patient_id, p.sex, "
                . "{$birthdateSelect}, {$branchId} AS branch_id "
                . "FROM {$schema}.patient p "
                . "INNER JOIN ("
                . "SELECT DISTINCT tx.patient_id FROM {$schema}.transaction tx "
                . "WHERE tx.is_deleted = 0 AND {$scopedDate}"
                . ") active_patients ON active_patients.patient_id = p.id "
                . "WHERE p.is_deleted = 0 AND p.master_id IS NOT NULL";

            $decryptBinds = array_merge($decryptBinds, $this->_scopedDateBinds($date_from, $date_to));
        }

        return [
            'sql'           => implode("\n    UNION ALL\n    ", $patientUnionParts),
            'decrypt_binds' => $decryptBinds,
        ];
    }

    /**
     * Build UNION ALL for branch transaction rows (optional date pushdown).
     */
    private function _buildBranchTransactionUnion($date_from = null, $date_to = null, string $dateColumn = 'added_on'): array
    {
        $schemas    = $this->_getBranchSchemas();
        $parts      = [];
        $extraBinds = [];
        $scopedDate = $this->_scopedDateSql($dateColumn, $date_from, $date_to);
        $hasFilter  = $date_from !== null || $date_to !== null;

        foreach ($schemas as $branchId => $schema) {
            $where = "is_deleted = 0";
            if ($hasFilter) {
                $where .= " AND {$scopedDate}";
                $extraBinds = array_merge($extraBinds, $this->_scopedDateBinds($date_from, $date_to));
            }

            $parts[] = "SELECT patient_id AS branch_patient_id, id AS transaction_id, "
                . "grand_total, transaction_date, added_on, endorsement_id, membership_id, "
                . "{$branchId} AS branch_id "
                . "FROM {$schema}.transaction WHERE {$where}";
        }

        return [
            'sql'   => implode("\n    UNION ALL\n    ", $parts),
            'binds' => $extraBinds,
        ];
    }

    /**
     * Transactions for patients active in the date window (history per active patient).
     * Optional $asOfDate pushes transaction_date <= as_of into each branch subquery.
     */
    private function _buildBranchTransactionHistoryUnion($date_from, $date_to, string $dateColumn = 'added_on', $asOfDate = null): array
    {
        $schemas    = $this->_getBranchSchemas();
        $parts      = [];
        $extraBinds = [];
        $scopedDate = $this->_scopedDateSql('tx_scope.' . $dateColumn, $date_from, $date_to);

        foreach ($schemas as $branchId => $schema) {
            $parts[] = "SELECT t.patient_id AS branch_patient_id, t.id AS transaction_id, "
                . "t.grand_total, t.transaction_date, t.added_on, t.endorsement_id, t.membership_id, "
                . "{$branchId} AS branch_id "
                . "FROM {$schema}.transaction t "
                . "INNER JOIN ("
                . "SELECT DISTINCT patient_id FROM {$schema}.transaction tx_scope "
                . "WHERE tx_scope.is_deleted = 0 AND {$scopedDate}"
                . ") active ON active.patient_id = t.patient_id "
                . "WHERE t.is_deleted = 0"
                . " AND (? IS NULL OR t.transaction_date <= ?)";

            $extraBinds = array_merge(
                $extraBinds,
                $this->_scopedDateBinds($date_from, $date_to),
                [$asOfDate, $asOfDate]
            );
        }

        return [
            'sql'   => implode("\n    UNION ALL\n    ", $parts),
            'binds' => $extraBinds,
        ];
    }

    /**
     * Build UNION ALL for transaction + booking + booking_details rows.
     */
    private function _buildBranchTxBookingUnion($date_from = null, $date_to = null, string $dateColumn = 'transaction_date'): array
    {
        $schemas    = $this->_getBranchSchemas();
        $parts      = [];
        $extraBinds = [];
        $scopedDate = $this->_scopedDateSql('t.' . $dateColumn, $date_from, $date_to);
        $hasFilter  = $date_from !== null || $date_to !== null;

        foreach ($schemas as $branchId => $schema) {
            $txWhere = 't.is_deleted = 0';
            if ($hasFilter) {
                $txWhere .= " AND {$scopedDate}";
                $extraBinds = array_merge($extraBinds, $this->_scopedDateBinds($date_from, $date_to));
            }

            $parts[] = <<<EOT
SELECT
    t.patient_id AS branch_patient_id,
    t.id AS transaction_id,
    t.grand_total,
    t.transaction_date,
    t.added_on,
    t.endorsement_id,
    t.membership_id,
    {$branchId} AS branch_id,
    b.company_id AS account_id,
    bd.charge_to,
    bd.amount,
    b.type AS booking_type
FROM {$schema}.transaction t
INNER JOIN {$schema}.booking b ON b.transaction_id = t.id
LEFT JOIN {$schema}.booking_details bd
    ON bd.booking_id = b.id
    AND bd.is_deleted = 0
WHERE {$txWhere}
EOT;
        }

        return [
            'sql'   => implode("\n    UNION ALL\n    ", $parts),
            'binds' => $extraBinds,
        ];
    }

    /**
     * Transaction + booking rows with charge_to only (for HMO account reports).
     */
    private function _buildBranchTxBookingUnionForAccounts($date_from = null, $date_to = null, string $dateColumn = 'transaction_date'): array
    {
        $schemas    = $this->_getBranchSchemas();
        $parts      = [];
        $extraBinds = [];
        $scopedDate = $this->_scopedDateSql('t.' . $dateColumn, $date_from, $date_to);
        $hasFilter  = $date_from !== null || $date_to !== null;

        foreach ($schemas as $branchId => $schema) {
            $txWhere = 't.is_deleted = 0';
            if ($hasFilter) {
                $txWhere .= " AND {$scopedDate}";
                $extraBinds = array_merge($extraBinds, $this->_scopedDateBinds($date_from, $date_to));
            }

            $parts[] = <<<EOT
SELECT
    t.patient_id AS branch_patient_id,
    t.id AS transaction_id,
    t.grand_total,
    t.transaction_date,
    t.added_on,
    t.endorsement_id,
    t.membership_id,
    {$branchId} AS branch_id,
    b.company_id AS account_id,
    bd.charge_to,
    bd.amount,
    b.type AS booking_type
FROM {$schema}.transaction t
INNER JOIN {$schema}.booking b ON b.transaction_id = t.id
INNER JOIN {$schema}.booking_details bd
    ON bd.booking_id = b.id
    AND bd.is_deleted = 0
    AND bd.charge_to IS NOT NULL
WHERE {$txWhere}
EOT;
        }

        return [
            'sql'   => implode("\n    UNION ALL\n    ", $parts),
            'binds' => $extraBinds,
        ];
    }

    /**
     * Single-branch PPL row select (shared by UNION subquery and per-branch detail queries).
     *
     * @return array{sql: string, binds: array}
     */
    private function _branchPplSingleBranchSelect(
        string $schema,
        int $branchId,
        $date_from,
        $date_to,
        bool $includePatientName,
        bool $computeAgeGroup,
        $charge_to = null,
        $membership_id = null
    ): array {
        $keySql       = $this->_encryptionKeySql();
        $dateTodaySql = $this->_dateTodaySql();
        $scopedDate   = $this->_scopedDateSql('t.transaction_date', $date_from, $date_to);
        $binds        = array_merge(
            $this->_scopedDateBinds($date_from, $date_to),
            [$charge_to, $charge_to, $membership_id, $membership_id]
        );

        if ($computeAgeGroup) {
            $birthdateExpr  = "CAST(AES_DECRYPT(p.birthdate, {$keySql}) AS DATE)";
            $ageGroupSelect = <<<EOT
CASE
        WHEN TIMESTAMPDIFF(YEAR, {$birthdateExpr}, {$dateTodaySql}) BETWEEN 0 AND 19  THEN '0-19'
        WHEN TIMESTAMPDIFF(YEAR, {$birthdateExpr}, {$dateTodaySql}) BETWEEN 20 AND 39 THEN '20-39'
        WHEN TIMESTAMPDIFF(YEAR, {$birthdateExpr}, {$dateTodaySql}) BETWEEN 40 AND 59 THEN '40-59'
        ELSE '60 & UP'
    END AS age_group
EOT;
        } else {
            $ageGroupSelect = 'NULL AS age_group';
        }

        $nameSelect = $includePatientName
            ? "TRIM(CONCAT(CAST(AES_DECRYPT(p.last_name, {$keySql}) AS CHAR), ', ', CAST(AES_DECRYPT(p.first_name, {$keySql}) AS CHAR), ' ', IFNULL(CAST(AES_DECRYPT(p.middle_name, {$keySql}) AS CHAR), ''), ' ', IFNULL(CAST(AES_DECRYPT(p.suffix, {$keySql}) AS CHAR), ''))) AS patient_name"
            : 'NULL AS patient_name';

        $sql = <<<EOT
SELECT
    p.master_id,
    p.id AS branch_patient_id,
    {$branchId} AS branch_id,
    {$nameSelect},
    p.sex AS gender,
    {$ageGroupSelect},
    t.id AS transaction_id,
    t.transaction_date,
    t.membership_id,
    t.endorsement_id,
    pkg.name AS package_name,
    pkg.id AS package_id,
    b.company_id AS account_id,
    bd.charge_to AS charged_to_id,
    bd.amount
FROM {$schema}.transaction t
INNER JOIN {$schema}.patient p
    ON p.id = t.patient_id AND p.is_deleted = 0 AND p.master_id IS NOT NULL
INNER JOIN {$schema}.booking b ON b.transaction_id = t.id
INNER JOIN {$schema}.booking_details bd
    ON bd.booking_id = b.id AND bd.is_deleted = 0 AND bd.type = 'package'
INNER JOIN {$schema}.package pkg ON pkg.id = bd.test_id
WHERE t.is_deleted = 0 AND {$scopedDate}
AND (? IS NULL OR bd.charge_to = ?)
AND (? IS NULL OR t.membership_id = ?)
EOT;

        return ['sql' => $sql, 'binds' => $binds];
    }

    /**
     * Build UNION ALL subquery for filtered PPL rows (optional patient name decrypt).
     * Patient decrypt is scoped to transactions in the date window only.
     *
     * @return array{sql: string, binds: array}
     */
    private function _branchPplFilteredSubquery($date_from, $date_to, bool $includePatientName, bool $computeAgeGroup = true): array
    {
        $schemas = $this->_getBranchSchemas();
        $parts   = [];
        $binds   = [];

        foreach ($schemas as $branchId => $schema) {
            $single  = $this->_branchPplSingleBranchSelect(
                $schema,
                (int) $branchId,
                $date_from,
                $date_to,
                $includePatientName,
                $computeAgeGroup,
                null,
                null
            );
            $parts[] = $single['sql'];
            $binds   = array_merge($binds, $single['binds']);
        }

        return [
            'sql'   => '(' . implode("\nUNION ALL\n", $parts) . ')',
            'binds' => $binds,
        ];
    }

    /**
     * UNION ALL for cross-branch lifetime package transactions (no decryption).
     */
    private function _pplLifetimeUnionSql(): string
    {
        $schemas = $this->_getBranchSchemas();
        $parts   = [];

        foreach ($schemas as $branchId => $schema) {
            $parts[] = <<<EOT
SELECT p.master_id, {$branchId} AS branch_id, t.id AS transaction_id
FROM {$schema}.transaction t
INNER JOIN {$schema}.patient p ON p.id = t.patient_id AND p.is_deleted = 0 AND p.master_id IS NOT NULL
INNER JOIN {$schema}.booking b ON b.transaction_id = t.id
INNER JOIN {$schema}.booking_details bd
    ON bd.booking_id = b.id AND bd.is_deleted = 0 AND bd.type = 'package'
WHERE t.is_deleted = 0
EOT;
        }

        return implode("\nUNION ALL\n", $parts);
    }

    /**
     * Normalize page/data_size for PPL detail pagination.
     *
     * @return array{0: int, 1: int, 2: int} page, data_size, offset
     */
    private function _normalizePplDetailPagination($page, $data_size): array
    {
        $page      = max(1, (int) ($page ?? 1));
        $data_size = max(1, min(1000, (int) ($data_size ?? 100)));
        $offset    = ($page - 1) * $data_size;

        return [$page, $data_size, $offset];
    }

    /**
     * Shared PPL detail filter + group-by (master_id, no patient_name in GROUP BY).
     */
    private function _pplDetailFilterSql(): string
    {
        return 'WHERE (? IS NULL OR age_group = ?) '
            . 'AND (? IS NULL OR charged_to_id = ?) '
            . 'AND (? IS NULL OR membership_id = ?) ';
    }

    private function _pplDetailGroupBySql(): string
    {
        return 'GROUP BY branch_id, transaction_id, transaction_date, master_id, branch_patient_id, gender, '
            . 'package_name, package_id, account_id, charged_to_id';
    }

    private function _pplDetailSingleBranchGroupBySql(): string
    {
        return 'GROUP BY transaction_id, transaction_date, master_id, branch_patient_id, gender, '
            . 'package_name, package_id, account_id, charged_to_id';
    }

    private function _pplDetailAgeFilterSql(): string
    {
        return 'WHERE (? IS NULL OR age_group = ?) ';
    }

    /**
     * Per-branch binds/subquery for PPL detail count and page fragments.
     *
     * @return array{sub: array, ageFilter: string, binds: array}
     */
    private function _pplDetailBranchQueryContext(
        string $schema,
        int $branchId,
        $date_from,
        $date_to,
        $age_group,
        $charge_to,
        $membership_id
    ): array {
        $computeAge = $age_group !== null;
        $sub        = $this->_branchPplSingleBranchSelect(
            $schema,
            $branchId,
            $date_from,
            $date_to,
            false,
            $computeAge,
            $charge_to,
            $membership_id
        );
        $ageFilter = $computeAge ? $this->_pplDetailAgeFilterSql() : '';
        $binds     = $sub['binds'];
        if ($computeAge) {
            $binds = array_merge($binds, [$age_group, $age_group]);
        }

        return ['sub' => $sub, 'ageFilter' => $ageFilter, 'binds' => $binds];
    }

    /**
     * SQL fragment: COUNT grouped rows for one branch (for UNION ALL count).
     */
    private function _pplDetailBranchCountFragment(
        string $schema,
        int $branchId,
        $date_from,
        $date_to,
        $age_group,
        $charge_to,
        $membership_id
    ): array {
        $ctx = $this->_pplDetailBranchQueryContext(
            $schema,
            $branchId,
            $date_from,
            $date_to,
            $age_group,
            $charge_to,
            $membership_id
        );

        $sql = 'SELECT COUNT(*) AS branch_cnt FROM ('
            . 'SELECT 1 FROM (' . $ctx['sub']['sql'] . ') AS branch_ppl '
            . $ctx['ageFilter']
            . $this->_pplDetailSingleBranchGroupBySql()
            . ') AS grouped';

        return ['sql' => $sql, 'binds' => $ctx['binds']];
    }

    /**
     * SQL fragment: top N grouped rows for one branch (for UNION ALL page).
     */
    private function _pplDetailBranchPageFragment(
        string $schema,
        int $branchId,
        $date_from,
        $date_to,
        $age_group,
        $charge_to,
        $membership_id,
        int $limit
    ): array {
        $ctx = $this->_pplDetailBranchQueryContext(
            $schema,
            $branchId,
            $date_from,
            $date_to,
            $age_group,
            $charge_to,
            $membership_id
        );

        $sql = '(' . <<<SQL
SELECT
    {$branchId} AS branch_id,
    branch_patient_id,
    transaction_id,
    transaction_date,
    gender,
    package_name,
    account_id,
    charged_to_id,
    SUM(amount) AS total_sales
FROM ({$ctx['sub']['sql']}) AS branch_ppl
{$ctx['ageFilter']}
{$this->_pplDetailSingleBranchGroupBySql()}
ORDER BY transaction_date DESC, transaction_id DESC
LIMIT {$limit}
SQL . ')';

        return ['sql' => $sql, 'binds' => $ctx['binds']];
    }

    /**
     * Decrypt patient names for paginated PPL detail rows only.
     *
     * @return array<string, string> "{branch_id}-{patient_id}" => name
     */
    private function _fetchPatientNamesByBranch(array $rows): array
    {
        $byBranch = [];
        foreach ($rows as $row) {
            $branchId  = $row['branch_id'] ?? null;
            $patientId = $row['branch_patient_id'] ?? null;
            if ($branchId && $patientId) {
                $byBranch[(int) $branchId][(int) $patientId] = true;
            }
        }

        if (empty($byBranch)) {
            return [];
        }

        $schemas = $this->_getBranchSchemas();
        $keySql  = $this->_encryptionKeySql();
        $map     = [];

        foreach ($byBranch as $branchId => $patientIds) {
            $schema = $schemas[$branchId] ?? null;
            if (! $schema) {
                continue;
            }

            $ids = implode(',', array_map('intval', array_keys($patientIds)));
            $sql = "SELECT id, TRIM(CONCAT("
                . "CAST(AES_DECRYPT(last_name, {$keySql}) AS CHAR), ', ', "
                . "CAST(AES_DECRYPT(first_name, {$keySql}) AS CHAR), ' ', "
                . "IFNULL(CAST(AES_DECRYPT(middle_name, {$keySql}) AS CHAR), ''), ' ', "
                . "IFNULL(CAST(AES_DECRYPT(suffix, {$keySql}) AS CHAR), '')"
                . ")) AS patient_name "
                . "FROM {$schema}.patient WHERE is_deleted = 0 AND id IN ({$ids})";

            foreach ($this->db->query($sql)->getResultArray() as $p) {
                $map[$branchId . '-' . $p['id']] = $p['patient_name'];
            }
        }

        return $map;
    }

    /**
     * Row count for PPL detail — single UNION query (one round-trip).
     */
    private function _fetchBranchPplDetailCount($date_from, $date_to, $age_group, $charge_to, $membership_id): int
    {
        $schemas  = $this->_getBranchSchemas();
        $parts    = [];
        $binds    = [];

        foreach ($schemas as $branchId => $schema) {
            $fragment = $this->_pplDetailBranchCountFragment(
                $schema,
                (int) $branchId,
                $date_from,
                $date_to,
                $age_group,
                $charge_to,
                $membership_id
            );
            $parts[] = $fragment['sql'];
            $binds   = array_merge($binds, $fragment['binds']);
        }

        if (empty($parts)) {
            return 0;
        }

        $sql = 'SELECT SUM(branch_cnt) AS size FROM (' . implode("\nUNION ALL\n", $parts) . ') AS branch_counts';
        $row = $this->db->query($sql, $binds)->getRowArray();

        return (int) ($row['size'] ?? 0);
    }

    /**
     * SQL aggregation for PPL detail table — single UNION + outer LIMIT (one round-trip).
     */
    private function _fetchBranchPplDetailAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id, $page = 1, $data_size = 100): array
    {
        [$page, $data_size, $offset] = $this->_normalizePplDetailPagination($page, $data_size);
        $need                        = $offset + $data_size;
        $schemas                     = $this->_getBranchSchemas();
        $parts                       = [];
        $binds                       = [];

        foreach ($schemas as $branchId => $schema) {
            $fragment = $this->_pplDetailBranchPageFragment(
                $schema,
                (int) $branchId,
                $date_from,
                $date_to,
                $age_group,
                $charge_to,
                $membership_id,
                $need
            );
            $parts[] = $fragment['sql'];
            $binds   = array_merge($binds, $fragment['binds']);
        }

        if (empty($parts)) {
            return [];
        }

        $unionSql = implode("\nUNION ALL\n", $parts);

        $sql = $this->_resolve_cross_db_sql(<<<SQL
SELECT
    merged.transaction_date,
    merged.branch_id,
    merged.branch_patient_id,
    merged.transaction_id,
    merged.gender,
    merged.package_name,
    COALESCE(c_acct.name, 'WALK-IN') AS account_name,
    COALESCE(c_chrg.name, 'WALK-IN') AS charged_to_account_name,
    merged.total_sales
FROM (
    {$unionSql}
) AS merged
LEFT JOIN {MASTER_DB}.company c_acct
    ON c_acct.id = merged.account_id AND c_acct.is_deleted = 0
LEFT JOIN {MASTER_DB}.company c_chrg
    ON c_chrg.id = merged.charged_to_id AND c_chrg.is_deleted = 0
ORDER BY merged.transaction_date DESC, merged.branch_id, merged.transaction_id
LIMIT {$offset}, {$data_size}
SQL);

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * SQL aggregation for PPL age group counts.
     */
    private function _fetchBranchPplAgeGroupAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id): array
    {
        $sub = $this->_branchPplFilteredSubquery($date_from, $date_to, false, true);

        $sql = <<<SQL
SELECT
    age_group,
    COUNT(DISTINCT master_id) AS patient_count,
    COUNT(DISTINCT CASE WHEN gender = 'Female' THEN master_id END) AS female,
    COUNT(DISTINCT CASE WHEN gender = 'Male' THEN master_id END) AS male
FROM (
    SELECT master_id, gender, age_group
    FROM {$sub['sql']} AS branch_ppl
    WHERE (? IS NULL OR age_group = ?)
      AND (? IS NULL OR charged_to_id = ?)
      AND (? IS NULL OR membership_id = ?)
    GROUP BY master_id, gender, age_group
) AS patients
GROUP BY age_group
SQL;

        $binds = array_merge($sub['binds'], $this->_pplFilterBinds($age_group, $charge_to, $membership_id));

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * SQL aggregation for PPL patient category counts (uses lifetime tx counts).
     */
    private function _fetchBranchPplPatientCategoryAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id): array
    {
        $sub         = $this->_branchPplFilteredSubquery($date_from, $date_to, false, $age_group !== null);
        $lifetimeSql = $this->_pplLifetimeUnionSql();
        $filterBinds = $this->_pplFilterBinds($age_group, $charge_to, $membership_id);

        $sql = <<<SQL
WITH branch_ppl AS (
    {$sub['sql']}
),
filtered AS (
    SELECT master_id, gender, age_group, charged_to_id, membership_id
    FROM branch_ppl
    WHERE (? IS NULL OR age_group = ?)
      AND (? IS NULL OR charged_to_id = ?)
      AND (? IS NULL OR membership_id = ?)
),
filtered_master_ids AS (
    SELECT DISTINCT master_id FROM filtered
),
lifetime AS (
    SELECT lu.master_id, COUNT(DISTINCT CONCAT(lu.branch_id, '-', lu.transaction_id)) AS lifetime_tx_count
    FROM ({$lifetimeSql}) AS lu
    INNER JOIN filtered_master_ids fm ON fm.master_id = lu.master_id
    GROUP BY lu.master_id
),
filtered_patients AS (
    SELECT master_id, gender FROM filtered GROUP BY master_id, gender
),
categorized AS (
    SELECT
        fp.master_id,
        fp.gender,
        CASE
            WHEN COALESCE(l.lifetime_tx_count, 0) = 1 THEN 'Acquired'
            WHEN COALESCE(l.lifetime_tx_count, 0) = 2 THEN 'Engaged'
            WHEN COALESCE(l.lifetime_tx_count, 0) >= 3 THEN 'Retained'
            ELSE 'Lost'
        END AS patient_category
    FROM filtered_patients fp
    LEFT JOIN lifetime l ON l.master_id = fp.master_id
)
SELECT
    patient_category,
    COUNT(DISTINCT master_id) AS patient_count,
    COUNT(DISTINCT CASE WHEN gender = 'Female' THEN master_id END) AS female,
    COUNT(DISTINCT CASE WHEN gender = 'Male' THEN master_id END) AS male
FROM categorized
GROUP BY patient_category
SQL;

        $binds = array_merge($sub['binds'], $filterBinds);

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * Distinct PPL membership rows for pivot (no patient name decrypt).
     */
    private function _fetchBranchPplMembershipTypeRows($date_from, $date_to, $age_group, $charge_to, $membership_id): array
    {
        $sub = $this->_branchPplFilteredSubquery($date_from, $date_to, false, $age_group !== null);

        $sql = 'SELECT DISTINCT branch_id, transaction_id, membership_id, endorsement_id, charged_to_id '
            . 'FROM ' . $sub['sql'] . ' AS branch_ppl '
            . 'WHERE (? IS NULL OR age_group = ?) '
            . 'AND (? IS NULL OR charged_to_id = ?) '
            . 'AND (? IS NULL OR membership_id = ?)';

        $binds = array_merge($sub['binds'], $this->_pplFilterBinds($age_group, $charge_to, $membership_id));

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * Pivoted membership counts by name x charge type (corporate/hmo/walk_in).
     */
    private function _fetchBranchPplMembershipPivot($date_from, $date_to, $age_group, $charge_to, $membership_id): array
    {
        $membership_rows = $this->_fetchBranchPplMembershipTypeRows($date_from, $date_to, $age_group, $charge_to, $membership_id);
        $memberships     = $this->fetchMembershipNamesByBranch($membership_rows);

        $company_ids = array_unique(array_filter(array_column($membership_rows, 'charged_to_id')));
        $companies   = [];

        if (! empty($company_ids)) {
            $ids_in  = implode(',', array_map('intval', $company_ids));
            $wherein = "is_deleted = 0 AND id IN ({$ids_in})";
            foreach ($this->company->select('id, type', $wherein) as $c) {
                $companies[$c->id] = $c;
            }
        }

        $pivot = [];
        foreach ($membership_rows as $row) {
            $membership_key  = ($row['branch_id'] ?? '') . '-' . ($row['membership_id'] ?? '');
            $membership_name = $memberships[$membership_key] ?? 'UNKNOWN';

            if (empty($row['endorsement_id'])) {
                $charge_type = 'walk_in';
            } else {
                $company_type = $companies[$row['charged_to_id']]->type ?? null;
                $charge_type  = match ($company_type) {
                    'corporate' => 'corporate',
                    'hmo'       => 'hmo',
                    default     => 'walk_in',
                };
            }

            if (! isset($pivot[$membership_name])) {
                $pivot[$membership_name] = [
                    'membership_name' => $membership_name,
                    'corporate'       => 0,
                    'hmo'             => 0,
                    'walk_in'         => 0,
                    'total'           => 0,
                ];
            }

            $tx_id = ($row['branch_id'] ?? '') . '-' . ($row['transaction_id'] ?? '');

            if (! isset($pivot[$membership_name]['_counted'][$tx_id][$charge_type])) {
                $pivot[$membership_name][$charge_type]++;
                $pivot[$membership_name]['total']++;
                $pivot[$membership_name]['_counted'][$tx_id][$charge_type] = true;
            }
        }

        unset($membership_rows);

        $result = [];
        foreach ($pivot as $row) {
            unset($row['_counted']);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * PPL summary tables only (no detail — use get_ppl_detail() separately).
     */
    public function get_ppl_summary_bundle($date_from = null, $date_to = null, $age_group = null, $charge_to = null, $membership_id = null): array
    {
        return [
            'age_group'        => $this->_fetchBranchPplAgeGroupAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id),
            'patient_category' => $this->_fetchBranchPplPatientCategoryAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id),
            'membership_type'  => $this->_fetchBranchPplMembershipPivot($date_from, $date_to, $age_group, $charge_to, $membership_id),
        ];
    }

    /**
     * Run all four PPL queries from targeted SQL (detail aggregated in SQL).
     */
    public function get_ppl_bundle($date_from = null, $date_to = null, $age_group = null, $charge_to = null, $membership_id = null): array
    {
        return array_merge(
            ['detail' => $this->_fetchBranchPplDetailAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id, 1, 100)],
            $this->get_ppl_summary_bundle($date_from, $date_to, $age_group, $charge_to, $membership_id)
        );
    }

    /**
     * Fetch membership names keyed by "{branch_id}-{membership_id}".
     */
    public function fetchMembershipNamesByBranch(array $rows): array
    {
        $byBranch = [];
        foreach ($rows as $row) {
            $branchId     = $row['branch_id'] ?? null;
            $membershipId = $row['membership_id'] ?? null;
            if ($branchId && $membershipId) {
                $byBranch[(int) $branchId][(int) $membershipId] = true;
            }
        }

        if (empty($byBranch)) {
            return [];
        }

        $schemas = $this->_getBranchSchemas();
        $map     = [];

        foreach ($byBranch as $branchId => $membershipIds) {
            $schema = $schemas[$branchId] ?? null;
            if (! $schema) {
                continue;
            }

            $ids = implode(',', array_map('intval', array_keys($membershipIds)));
            $sql = "SELECT id, name FROM {$schema}.membership WHERE is_deleted = 0 AND id IN ({$ids})";
            foreach ($this->db->query($sql)->getResultArray() as $m) {
                $map[$branchId . '-' . $m['id']] = $m['name'];
            }
        }

        return $map;
    }

    /**
     * Master IDs active in 2+ branch schemas (small result vs all masters).
     *
     * @return list<int|string>
     */
    private function _uipFetchMultiBranchMasterIds($date_from, $date_to, string $dateColumn = 'added_on'): array
    {
        $parts = [];
        $binds = [];

        foreach ($this->_getBranchSchemas() as $schema) {
            $scopedDate = $this->_scopedDateSql('tx_scope.' . $dateColumn, $date_from, $date_to);
            $parts[]    = "SELECT p.master_id AS patient_id "
                . "FROM {$schema}.patient p "
                . "INNER JOIN ("
                . "SELECT DISTINCT tx_scope.patient_id FROM {$schema}.transaction tx_scope "
                . "WHERE tx_scope.is_deleted = 0 AND {$scopedDate}"
                . ") active ON active.patient_id = p.id "
                . "WHERE p.is_deleted = 0 AND p.master_id IS NOT NULL";
            $binds = array_merge($binds, $this->_scopedDateBinds($date_from, $date_to));
        }

        if ($parts === []) {
            return [];
        }

        $union = implode("\nUNION ALL\n", $parts);
        $sql   = "SELECT patient_id FROM ({$union}) u GROUP BY patient_id HAVING COUNT(*) > 1";

        return array_column($this->db->query($sql, $binds)->getResultArray(), 'patient_id');
    }

    /**
     * Load multi-branch master ids into a session temp table for anti-join exclusion.
     *
     * @param list<int|string> $multiIds
     */
    private function _uipLoadMultiMasterTempTable(array $multiIds): void
    {
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS uip_multi_masters');
        $this->db->query('CREATE TEMPORARY TABLE uip_multi_masters (master_id BIGINT NOT NULL PRIMARY KEY)');

        foreach (array_chunk($multiIds, 1000) as $chunk) {
            $values = [];
            foreach ($chunk as $id) {
                $values[] = '(' . (int) $id . ')';
            }
            if ($values !== []) {
                $this->db->query('INSERT INTO uip_multi_masters (master_id) VALUES ' . implode(',', $values));
            }
        }
    }

    /**
     * Per-branch category bucket aggregates.
     * Metrics via one GROUP BY (no full hist CTE); a single LAG pass over
     * gap_candidates yields both last_return_date and post_lost_tx_count.
     *
     * @return list<array<string, mixed>>
     */
    private function _fetchBranchUipCategoryBuckets(
        string $schema,
        $date_from,
        $date_to,
        string $asOfDate,
        bool $excludeMulti,
        string $dateColumn = 'added_on'
    ): array {
        $scopedDate   = $this->_scopedDateSql('tx_scope.' . $dateColumn, $date_from, $date_to);
        $keySql       = $this->_encryptionKeySql();
        $multiExclude = $excludeMulti
            ? 'AND NOT EXISTS (SELECT 1 FROM uip_multi_masters mm WHERE mm.master_id = p.master_id)'
            : '';

        $sql = <<<EOT
WITH active_patients AS (
    SELECT DISTINCT tx_scope.patient_id
    FROM {$schema}.transaction tx_scope
    WHERE tx_scope.is_deleted = 0
      AND {$scopedDate}
),
patient_metrics AS (
    SELECT
        p.master_id,
        COUNT(*) AS transaction_count,
        SUM(IFNULL(t.grand_total, 0)) AS total_sales,
        MAX(t.transaction_date) AS last_transaction_date,
        MIN(t.transaction_date) AS first_transaction_date,
        DATEDIFF(?, MAX(t.transaction_date)) AS days_since_last_tx
    FROM {$schema}.patient p
    INNER JOIN active_patients ap ON ap.patient_id = p.id
    INNER JOIN {$schema}.transaction t
        ON t.patient_id = p.id
        AND t.is_deleted = 0
        AND t.transaction_date <= ?
    WHERE p.is_deleted = 0
      AND p.master_id IS NOT NULL
      {$multiExclude}
    GROUP BY p.master_id
),
patient_demo AS (
    SELECT
        p.master_id,
        MAX(p.sex) AS gender,
        MAX(CAST(AES_DECRYPT(p.birthdate, {$keySql}) AS DATE)) AS birthdate
    FROM {$schema}.patient p
    INNER JOIN patient_metrics pm ON pm.master_id = p.master_id
    WHERE p.is_deleted = 0
    GROUP BY p.master_id
),
gap_candidates AS (
    SELECT master_id
    FROM patient_metrics
    WHERE days_since_last_tx <= 720
      AND DATEDIFF(last_transaction_date, first_transaction_date) > 720
),
gap_win AS (
    SELECT
        p.master_id,
        t.transaction_date,
        LAG(t.transaction_date) OVER (
            PARTITION BY p.master_id
            ORDER BY t.transaction_date, t.id
        ) AS prev_date
    FROM {$schema}.patient p
    INNER JOIN gap_candidates g ON g.master_id = p.master_id
    INNER JOIN {$schema}.transaction t
        ON t.patient_id = p.id
        AND t.is_deleted = 0
        AND t.transaction_date <= ?
    WHERE p.is_deleted = 0
),
gap_flagged AS (
    SELECT
        master_id,
        transaction_date,
        MAX(
            CASE
                WHEN prev_date IS NOT NULL AND DATEDIFF(transaction_date, prev_date) > 720
                THEN transaction_date
            END
        ) OVER (PARTITION BY master_id) AS last_return_date
    FROM gap_win
),
gap_info AS (
    SELECT
        master_id,
        MAX(last_return_date) AS last_return_date,
        SUM(
            CASE
                WHEN last_return_date IS NOT NULL AND transaction_date >= last_return_date
                THEN 1 ELSE 0
            END
        ) AS post_lost_tx_count
    FROM gap_flagged
    GROUP BY master_id
),
categorized AS (
    SELECT
        pm.master_id,
        pd.gender,
        FLOOR(TIMESTAMPDIFF(YEAR, pd.birthdate, ?)) AS age,
        pm.transaction_count,
        pm.total_sales,
        CASE
            WHEN pm.days_since_last_tx > 720 THEN 'Lost'
            WHEN gi.last_return_date IS NOT NULL THEN
                CASE
                    WHEN COALESCE(gi.post_lost_tx_count, 0) = 1 THEN 'Returnee'
                    WHEN COALESCE(gi.post_lost_tx_count, 0) = 2 THEN 'Engaged'
                    WHEN COALESCE(gi.post_lost_tx_count, 0) >= 3 THEN 'Retained'
                    ELSE 'Returnee'
                END
            WHEN pm.transaction_count = 1 THEN 'Acquired'
            WHEN pm.transaction_count = 2 THEN 'Engaged'
            WHEN pm.transaction_count >= 3 THEN 'Retained'
            ELSE 'Unknown'
        END AS category
    FROM patient_metrics pm
    LEFT JOIN patient_demo pd ON pd.master_id = pm.master_id
    LEFT JOIN gap_info gi ON gi.master_id = pm.master_id
    WHERE pm.transaction_count > 0
)
SELECT
    category,
    COALESCE(SUM(total_sales), 0) AS sales,
    COUNT(*) AS patients,
    COALESCE(SUM(transaction_count), 0) AS tx,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END), 0) AS male,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END), 0) AS female,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN age ELSE 0 END), 0) AS male_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Male' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS male_age_n,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN age ELSE 0 END), 0) AS female_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Female' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS female_age_n
FROM categorized
GROUP BY category
EOT;

        $binds = array_merge(
            $this->_scopedDateBinds($date_from, $date_to),
            [$asOfDate, $asOfDate, $asOfDate, $asOfDate]
        );

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * Per-branch age-group bucket aggregates (4 rows). Decrypt once per master.
     *
     * @return list<array<string, mixed>>
     */
    private function _fetchBranchUipAgeBuckets(
        string $schema,
        $date_from,
        $date_to,
        string $asOfDate,
        bool $excludeMulti,
        string $dateColumn = 'added_on'
    ): array {
        $scopedDate   = $this->_scopedDateSql('tx_scope.' . $dateColumn, $date_from, $date_to);
        $keySql       = $this->_encryptionKeySql();
        $multiExclude = $excludeMulti
            ? 'AND NOT EXISTS (SELECT 1 FROM uip_multi_masters mm WHERE mm.master_id = p.master_id)'
            : '';

        $sql = <<<EOT
WITH active_patients AS (
    SELECT DISTINCT tx_scope.patient_id
    FROM {$schema}.transaction tx_scope
    WHERE tx_scope.is_deleted = 0
      AND {$scopedDate}
),
tx_metrics AS (
    SELECT
        p.master_id,
        COUNT(*) AS transaction_count,
        SUM(IFNULL(t.grand_total, 0)) AS total_sales
    FROM {$schema}.patient p
    INNER JOIN active_patients ap ON ap.patient_id = p.id
    INNER JOIN {$schema}.transaction t
        ON t.patient_id = p.id
        AND t.is_deleted = 0
        AND t.transaction_date <= ?
    WHERE p.is_deleted = 0
      AND p.master_id IS NOT NULL
      {$multiExclude}
    GROUP BY p.master_id
),
demo AS (
    SELECT
        p.master_id,
        MAX(p.sex) AS gender,
        MAX(CAST(AES_DECRYPT(p.birthdate, {$keySql}) AS DATE)) AS birthdate
    FROM {$schema}.patient p
    INNER JOIN tx_metrics tm ON tm.master_id = p.master_id
    WHERE p.is_deleted = 0
    GROUP BY p.master_id
),
aged AS (
    SELECT
        tm.master_id,
        d.gender,
        FLOOR(TIMESTAMPDIFF(YEAR, d.birthdate, ?)) AS age,
        tm.transaction_count,
        tm.total_sales,
        CASE
            WHEN d.birthdate IS NULL THEN '60 & UP'
            WHEN FLOOR(TIMESTAMPDIFF(YEAR, d.birthdate, ?)) <= 19 THEN '0-19'
            WHEN FLOOR(TIMESTAMPDIFF(YEAR, d.birthdate, ?)) <= 39 THEN '20-39'
            WHEN FLOOR(TIMESTAMPDIFF(YEAR, d.birthdate, ?)) <= 59 THEN '40-59'
            ELSE '60 & UP'
        END AS age_group
    FROM tx_metrics tm
    INNER JOIN demo d ON d.master_id = tm.master_id
    WHERE tm.transaction_count > 0
)
SELECT
    age_group,
    COALESCE(SUM(total_sales), 0) AS sales,
    COUNT(*) AS patients,
    COALESCE(SUM(transaction_count), 0) AS tx,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END), 0) AS male,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END), 0) AS female,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN age ELSE 0 END), 0) AS male_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Male' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS male_age_n,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN age ELSE 0 END), 0) AS female_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Female' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS female_age_n
FROM aged
GROUP BY age_group
EOT;

        $binds = array_merge(
            $this->_scopedDateBinds($date_from, $date_to),
            [$asOfDate, $asOfDate, $asOfDate, $asOfDate, $asOfDate]
        );

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * Compact per-master age metrics for multi-branch correction (short ranges).
     *
     * @param list<int|string> $masterIds
     * @return list<array<string, mixed>>
     */
    private function _fetchBranchUipAgeMetricsForMasters(
        string $schema,
        array $masterIds,
        string $asOfDate
    ): array {
        if ($masterIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $masterIds));
        if ($ids === '') {
            return [];
        }

        $keySql = $this->_encryptionKeySql();

        $sql = <<<EOT
SELECT
    p.master_id AS patient_id,
    MAX(p.sex) AS gender,
    MAX(CAST(AES_DECRYPT(p.birthdate, {$keySql}) AS DATE)) AS birthdate,
    COUNT(DISTINCT t.id) AS transaction_count,
    SUM(IFNULL(t.grand_total, 0)) AS total_sales
FROM {$schema}.patient p
INNER JOIN {$schema}.transaction t
    ON t.patient_id = p.id
    AND t.is_deleted = 0
    AND t.transaction_date <= ?
WHERE p.is_deleted = 0
  AND p.master_id IN ({$ids})
GROUP BY p.master_id
EOT;

        return $this->db->query($sql, [$asOfDate])->getResultArray();
    }

    /**
     * Raw as-of txs for selected master_ids (cross-branch Returnee merge).
     *
     * @param list<int|string> $masterIds
     * @return list<array<string, mixed>>
     */
    private function _fetchBranchUipTxStreamForMasters(int $branchId, string $schema, array $masterIds, string $asOfDate): array
    {
        if ($masterIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $masterIds));
        if ($ids === '') {
            return [];
        }

        $keySql = $this->_encryptionKeySql();

        $sql = <<<EOT
SELECT
    p.master_id AS patient_id,
    p.sex AS gender,
    CAST(AES_DECRYPT(p.birthdate, {$keySql}) AS DATE) AS birthdate,
    t.id AS transaction_id,
    t.transaction_date,
    IFNULL(t.grand_total, 0) AS grand_total
FROM {$schema}.patient p
INNER JOIN {$schema}.transaction t
    ON t.patient_id = p.id
    AND t.is_deleted = 0
    AND t.transaction_date <= ?
WHERE p.is_deleted = 0
  AND p.master_id IN ({$ids})
ORDER BY p.master_id, t.transaction_date, t.id
EOT;

        return $this->db->query($sql, [$asOfDate])->getResultArray();
    }

    /**
     * Floor age in whole years as of $asOfDate.
     */
    private function _uipAgeYears(?string $birthdate, string $asOfDate): ?int
    {
        if ($birthdate === null || $birthdate === '' || $birthdate === '0000-00-00') {
            return null;
        }

        try {
            $birth = new \DateTimeImmutable(substr($birthdate, 0, 10));
            $asOf  = new \DateTimeImmutable($asOfDate);
        } catch (\Exception $e) {
            return null;
        }

        if ($birth > $asOf) {
            return 0;
        }

        return (int) $birth->diff($asOf)->y;
    }

    /**
     * Days between two Y-m-d dates (toDate - fromDate).
     */
    private function _uipDaysBetween(string $fromDate, string $toDate): int
    {
        $from = new \DateTimeImmutable(substr($fromDate, 0, 10));
        $to   = new \DateTimeImmutable(substr($toDate, 0, 10));

        return (int) $from->diff($to)->format('%r%a');
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    private function _uipEmptyCategoryBuckets(): array
    {
        $buckets = [];
        foreach (['Acquired', 'Engaged', 'Lost', 'Returnee', 'Retained'] as $cat) {
            $buckets[$cat] = [
                'patients'       => 0,
                'sales'          => 0.0,
                'tx'             => 0,
                'male'           => 0,
                'female'         => 0,
                'male_age_sum'   => 0,
                'male_age_n'     => 0,
                'female_age_sum' => 0,
                'female_age_n'   => 0,
            ];
        }

        return $buckets;
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    private function _uipEmptyAgeBuckets(): array
    {
        $buckets = [];
        foreach (['0-19', '20-39', '40-59', '60 & UP'] as $group) {
            $buckets[$group] = [
                'patients'       => 0,
                'sales'          => 0.0,
                'tx'             => 0,
                'male'           => 0,
                'female'         => 0,
                'male_age_sum'   => 0,
                'male_age_n'     => 0,
                'female_age_sum' => 0,
                'female_age_n'   => 0,
            ];
        }

        return $buckets;
    }

    /**
     * @param array<string, array<string, int|float>> $buckets
     * @param array{category: string, transaction_count: int, total_sales: float, age: ?int, gender: ?string} $patient
     */
    private function _uipAddPatientToCategoryBucket(array &$buckets, array $patient): void
    {
        $cat = $patient['category'];
        if (! isset($buckets[$cat]) || ($patient['transaction_count'] ?? 0) <= 0) {
            return;
        }

        $buckets[$cat]['patients']++;
        $buckets[$cat]['sales'] += $patient['total_sales'];
        $buckets[$cat]['tx']    += $patient['transaction_count'];

        $gender = $patient['gender'] ?? '';
        $age    = $patient['age'];
        if ($gender === 'Male') {
            $buckets[$cat]['male']++;
            if ($age !== null) {
                $buckets[$cat]['male_age_sum'] += $age;
                $buckets[$cat]['male_age_n']++;
            }
        } elseif ($gender === 'Female') {
            $buckets[$cat]['female']++;
            if ($age !== null) {
                $buckets[$cat]['female_age_sum'] += $age;
                $buckets[$cat]['female_age_n']++;
            }
        }
    }

    /**
     * @param array<string, array<string, int|float>> $buckets
     * @return list<array<string, mixed>>
     */
    private function _uipFinalizeCategoryRows(array $buckets): array
    {
        $order    = ['Acquired', 'Engaged', 'Lost', 'Returnee', 'Retained'];
        $totalUip = 0;
        foreach ($order as $cat) {
            $totalUip += (int) $buckets[$cat]['patients'];
        }

        $rows = [];
        foreach ($order as $cat) {
            $b         = $buckets[$cat];
            $uipCount  = (int) $b['patients'];
            $txSum     = (float) $b['tx'];
            $sales     = (float) $b['sales'];
            $aveVisits = $uipCount > 0 ? $txSum / $uipCount : null;
            $acPerTx   = $txSum > 0 ? $sales / $txSum : null;
            $aveSpend  = ($aveVisits !== null && $acPerTx !== null) ? $aveVisits * $acPerTx : null;

            $rows[] = [
                'PatientCategory'    => $cat,
                'Total_Sales'        => (int) round($sales, 0),
                'UIP_Count'          => $uipCount,
                'UIP_Count_Pct'      => $totalUip > 0 ? round($uipCount / $totalUip * 100, 1) : null,
                'Transaction_Count'  => round($txSum, 3),
                'Ave_Visits'         => $aveVisits !== null ? round($aveVisits, 4) : null,
                'AC_per_Transaction' => $acPerTx !== null ? round($acPerTx, 6) : null,
                'Ave_Spend'          => $aveSpend !== null ? round($aveSpend, 2) : null,
                'Male'               => (int) $b['male'],
                'Female'             => (int) $b['female'],
                'Male_AveAge'        => $b['male_age_n'] > 0 ? round($b['male_age_sum'] / $b['male_age_n'], 2) : null,
                'Female_AveAge'      => $b['female_age_n'] > 0 ? round($b['female_age_sum'] / $b['female_age_n'], 2) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, int|float>> $buckets
     * @return list<array<string, mixed>>
     */
    private function _uipFinalizeAgeRows(array $buckets): array
    {
        $order    = ['0-19', '20-39', '40-59', '60 & UP'];
        $totalUip = 0;
        foreach ($order as $group) {
            $totalUip += (int) $buckets[$group]['patients'];
        }

        $rows = [];
        foreach ($order as $group) {
            $b         = $buckets[$group];
            $uipCount  = (int) $b['patients'];
            $txSum     = (float) $b['tx'];
            $sales     = (float) $b['sales'];
            $aveVisits = $uipCount > 0 ? $txSum / $uipCount : null;
            $acPerTx   = $txSum > 0 ? $sales / $txSum : null;
            $aveSpend  = ($aveVisits !== null && $acPerTx !== null) ? $aveVisits * $acPerTx : null;

            $rows[] = [
                'AgeGroup'           => $group,
                'Total_Sales'        => (int) round($sales, 0),
                'UIP_Count'          => $uipCount,
                'UIP_Count_Pct'      => $totalUip > 0 ? round($uipCount / $totalUip * 100, 1) : null,
                'Transaction_Count'  => round($txSum, 3),
                'Average_Visits'     => $aveVisits !== null ? round($aveVisits, 4) : null,
                'AC_per_Transaction' => $acPerTx !== null ? round($acPerTx, 6) : null,
                'Average_Spend'      => $aveSpend !== null ? round($aveSpend, 2) : null,
                'Male'               => (int) $b['male'],
                'Female'             => (int) $b['female'],
                'Male_AveAge'        => $b['male_age_n'] > 0 ? round($b['male_age_sum'] / $b['male_age_n'], 2) : null,
                'Female_AveAge'      => $b['female_age_n'] > 0 ? round($b['female_age_sum'] / $b['female_age_n'], 2) : null,
            ];
        }

        return $rows;
    }

    /**
     * Categorize one patient from ordered unique txs (composite key branch-txid).
     *
     * @param list<array{transaction_date: string, composite_tx: string, grand_total: float|int}> $txs
     * @return array{category: string, transaction_count: int, total_sales: float, age: ?int, gender: ?string}
     */
    private function _uipCategorizeFromTxs(array $txs, ?string $gender, ?string $birthdate, string $asOfDate): array
    {
        usort($txs, static function ($a, $b) {
            $cmp = strcmp($a['transaction_date'], $b['transaction_date']);

            return $cmp !== 0 ? $cmp : strcmp($a['composite_tx'], $b['composite_tx']);
        });

        $seen           = [];
        $unique         = [];
        $totalSales     = 0.0;
        $lastReturnDate = null;
        $prevDate       = null;

        foreach ($txs as $tx) {
            $key = $tx['composite_tx'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[]   = $tx;
            $totalSales += (float) $tx['grand_total'];

            if ($prevDate !== null && $this->_uipDaysBetween($prevDate, $tx['transaction_date']) > 720) {
                $lastReturnDate = substr($tx['transaction_date'], 0, 10);
            }
            $prevDate = substr($tx['transaction_date'], 0, 10);
        }

        $txCount = count($unique);
        if ($txCount === 0) {
            return [
                'category'          => 'Unknown',
                'transaction_count' => 0,
                'total_sales'       => 0.0,
                'age'               => $this->_uipAgeYears($birthdate, $asOfDate),
                'gender'            => $gender,
            ];
        }

        $lastTx        = substr($unique[$txCount - 1]['transaction_date'], 0, 10);
        $daysSinceLast = $this->_uipDaysBetween($lastTx, $asOfDate);
        $postLostCount = 0;

        if ($lastReturnDate !== null) {
            foreach ($unique as $tx) {
                if (substr($tx['transaction_date'], 0, 10) >= $lastReturnDate) {
                    $postLostCount++;
                }
            }
        }

        if ($daysSinceLast > 720) {
            $category = 'Lost';
        } elseif ($lastReturnDate !== null) {
            $category = match (true) {
                $postLostCount === 1 => 'Returnee',
                $postLostCount === 2 => 'Engaged',
                $postLostCount >= 3  => 'Retained',
                default              => 'Returnee',
            };
        } else {
            $category = match (true) {
                $txCount === 1 => 'Acquired',
                $txCount === 2 => 'Engaged',
                $txCount >= 3  => 'Retained',
                default        => 'Unknown',
            };
        }

        return [
            'category'          => $category,
            'transaction_count' => $txCount,
            'total_sales'       => $totalSales,
            'age'               => $this->_uipAgeYears($birthdate, $asOfDate),
            'gender'            => $gender,
        ];
    }

    /**
     * Merge a SQL category-bucket row into running PHP buckets.
     *
     * @param array<string, array<string, int|float>> $buckets
     */
    private function _uipMergeCategoryBucketSqlRow(array &$buckets, array $row): void
    {
        $cat = $row['category'] ?? null;
        if ($cat === null || ! isset($buckets[$cat])) {
            return;
        }

        $buckets[$cat]['patients']       += (int) ($row['patients'] ?? 0);
        $buckets[$cat]['sales']          += (float) ($row['sales'] ?? 0);
        $buckets[$cat]['tx']             += (float) ($row['tx'] ?? 0);
        $buckets[$cat]['male']           += (int) ($row['male'] ?? 0);
        $buckets[$cat]['female']         += (int) ($row['female'] ?? 0);
        $buckets[$cat]['male_age_sum']   += (float) ($row['male_age_sum'] ?? 0);
        $buckets[$cat]['male_age_n']     += (int) ($row['male_age_n'] ?? 0);
        $buckets[$cat]['female_age_sum'] += (float) ($row['female_age_sum'] ?? 0);
        $buckets[$cat]['female_age_n']   += (int) ($row['female_age_n'] ?? 0);
    }

    /**
     * Merge a SQL age-group bucket row into running PHP buckets.
     *
     * @param array<string, array<string, int|float>> $buckets
     */
    private function _uipMergeAgeBucketSqlRow(array &$buckets, array $row): void
    {
        $group = $row['age_group'] ?? null;
        if ($group === null || ! isset($buckets[$group])) {
            return;
        }

        $buckets[$group]['patients']       += (int) ($row['patients'] ?? 0);
        $buckets[$group]['sales']          += (float) ($row['sales'] ?? 0);
        $buckets[$group]['tx']             += (float) ($row['tx'] ?? 0);
        $buckets[$group]['male']           += (int) ($row['male'] ?? 0);
        $buckets[$group]['female']         += (int) ($row['female'] ?? 0);
        $buckets[$group]['male_age_sum']   += (float) ($row['male_age_sum'] ?? 0);
        $buckets[$group]['male_age_n']     += (int) ($row['male_age_n'] ?? 0);
        $buckets[$group]['female_age_sum'] += (float) ($row['female_age_sum'] ?? 0);
        $buckets[$group]['female_age_n']   += (int) ($row['female_age_n'] ?? 0);
    }

    /**
     * Add one categorized/merged patient into age buckets.
     *
     * @param array<string, array<string, int|float>> $buckets
     * @param array{transaction_count: int, total_sales: float, age: ?int, gender: ?string} $patient
     */
    private function _uipAddPatientToAgeBucket(array &$buckets, array $patient): void
    {
        if (($patient['transaction_count'] ?? 0) <= 0) {
            return;
        }

        $age = $patient['age'];
        if ($age === null) {
            $group = '60 & UP';
        } elseif ($age <= 19) {
            $group = '0-19';
        } elseif ($age <= 39) {
            $group = '20-39';
        } elseif ($age <= 59) {
            $group = '40-59';
        } else {
            $group = '60 & UP';
        }

        $buckets[$group]['patients']++;
        $buckets[$group]['sales'] += $patient['total_sales'];
        $buckets[$group]['tx']    += $patient['transaction_count'];

        $gender = $patient['gender'] ?? '';
        if ($gender === 'Male') {
            $buckets[$group]['male']++;
            if ($age !== null) {
                $buckets[$group]['male_age_sum'] += $age;
                $buckets[$group]['male_age_n']++;
            }
        } elseif ($gender === 'Female') {
            $buckets[$group]['female']++;
            if ($age !== null) {
                $buckets[$group]['female_age_sum'] += $age;
                $buckets[$group]['female_age_n']++;
            }
        }
    }

    /**
     * Copy one branch window into the UIP read model.
     *
     * $dateToExclusive must be the first instant after the requested window.
     */
    private function _refreshUipBranch(int $branchId, string $schema, string $dateFrom, string $dateToExclusive): void
    {
        $reportDb = REPORT_DB;
        $keySql   = $this->_encryptionKeySql();

        $transactionSql = <<<SQL
INSERT INTO {$reportDb}.uip_transaction (
    branch_id, transaction_id, master_id, transaction_date, added_on,
    grand_total, is_deleted, synced_on
)
SELECT
    {$branchId},
    t.id,
    p.master_id,
    t.transaction_date,
    t.added_on,
    IFNULL(t.grand_total, 0),
    IF(t.is_deleted = 1 OR p.is_deleted = 1, 1, 0),
    NOW()
FROM {$schema}.transaction t
INNER JOIN {$schema}.patient p ON p.id = t.patient_id
WHERE p.master_id IS NOT NULL
  AND t.added_on >= ?
  AND t.added_on < ?
ON DUPLICATE KEY UPDATE
    master_id        = VALUES(master_id),
    transaction_date = VALUES(transaction_date),
    added_on         = VALUES(added_on),
    grand_total      = VALUES(grand_total),
    is_deleted       = VALUES(is_deleted),
    synced_on        = VALUES(synced_on)
SQL;
        $this->db->query($transactionSql, [$dateFrom, $dateToExclusive]);

        $demoSql = <<<SQL
INSERT INTO {$reportDb}.uip_patient_demo (master_id, gender, birthdate, synced_on)
SELECT
    p.master_id,
    MAX(NULLIF(p.sex, '')),
    MAX(CAST(AES_DECRYPT(p.birthdate, {$keySql}) AS DATE)),
    NOW()
FROM {$schema}.patient p
INNER JOIN {$schema}.transaction t ON t.patient_id = p.id
WHERE p.master_id IS NOT NULL
  AND t.added_on >= ?
  AND t.added_on < ?
GROUP BY p.master_id
ON DUPLICATE KEY UPDATE
    gender    = COALESCE(VALUES(gender), uip_patient_demo.gender),
    birthdate = COALESCE(VALUES(birthdate), uip_patient_demo.birthdate),
    synced_on = VALUES(synced_on)
SQL;
        $this->db->query($demoSql, [$dateFrom, $dateToExclusive]);
    }

    /**
     * Rebuild compact daily activity rows for one imported window.
     */
    private function _refreshUipActivity(string $dateFrom, string $dateToExclusive): void
    {
        $reportDb = REPORT_DB;
        $this->db->query(
            "DELETE FROM {$reportDb}.uip_patient_activity WHERE activity_date >= ? AND activity_date < ?",
            [$dateFrom, $dateToExclusive]
        );

        $sql = <<<SQL
INSERT INTO {$reportDb}.uip_patient_activity (activity_date, master_id, synced_on)
SELECT DATE(added_on), master_id, NOW()
FROM {$reportDb}.uip_transaction
WHERE is_deleted = 0
  AND added_on >= ?
  AND added_on < ?
GROUP BY DATE(added_on), master_id
SQL;
        $this->db->query($sql, [$dateFrom, $dateToExclusive]);
    }

    /**
     * Refresh snapshots only for patients touched by the imported window.
     */
    private function _refreshUipSnapshots(string $dateFrom, string $dateToExclusive): void
    {
        $reportDb = REPORT_DB;
        $sql = <<<SQL
INSERT INTO {$reportDb}.uip_patient_snapshot (
    master_id, transaction_count, total_sales, first_transaction_date,
    last_transaction_date, last_return_date, post_lost_tx_count,
    gender, birthdate, synced_on
)
WITH touched AS (
    SELECT DISTINCT master_id
    FROM {$reportDb}.uip_transaction
    WHERE added_on >= ? AND added_on < ?
),
ordered AS (
    SELECT
        t.master_id,
        t.transaction_date,
        t.branch_id,
        t.transaction_id,
        t.grand_total,
        LAG(t.transaction_date) OVER (
            PARTITION BY t.master_id
            ORDER BY t.transaction_date, t.branch_id, t.transaction_id
        ) AS previous_date
    FROM {$reportDb}.uip_transaction t
    INNER JOIN touched x ON x.master_id = t.master_id
    WHERE t.is_deleted = 0
      AND t.transaction_date <= CURDATE()
),
metrics AS (
    SELECT
        master_id,
        COUNT(*) AS transaction_count,
        SUM(grand_total) AS total_sales,
        MIN(transaction_date) AS first_transaction_date,
        MAX(transaction_date) AS last_transaction_date,
        MAX(
            CASE
                WHEN previous_date IS NOT NULL
                 AND DATEDIFF(transaction_date, previous_date) > 720
                THEN transaction_date
            END
        ) AS last_return_date
    FROM ordered
    GROUP BY master_id
),
post_lost AS (
    SELECT
        m.master_id,
        COUNT(o.transaction_id) AS post_lost_tx_count
    FROM metrics m
    LEFT JOIN ordered o
        ON o.master_id = m.master_id
       AND m.last_return_date IS NOT NULL
       AND o.transaction_date >= m.last_return_date
    GROUP BY m.master_id
)
SELECT
    m.master_id,
    m.transaction_count,
    m.total_sales,
    m.first_transaction_date,
    m.last_transaction_date,
    m.last_return_date,
    pl.post_lost_tx_count,
    d.gender,
    d.birthdate,
    NOW()
FROM metrics m
INNER JOIN post_lost pl ON pl.master_id = m.master_id
LEFT JOIN {$reportDb}.uip_patient_demo d ON d.master_id = m.master_id
ON DUPLICATE KEY UPDATE
    transaction_count      = VALUES(transaction_count),
    total_sales            = VALUES(total_sales),
    first_transaction_date = VALUES(first_transaction_date),
    last_transaction_date  = VALUES(last_transaction_date),
    last_return_date       = VALUES(last_return_date),
    post_lost_tx_count     = VALUES(post_lost_tx_count),
    gender                 = COALESCE(VALUES(gender), uip_patient_snapshot.gender),
    birthdate              = COALESCE(VALUES(birthdate), uip_patient_snapshot.birthdate),
    synced_on              = VALUES(synced_on)
SQL;
        $this->db->query($sql, [$dateFrom, $dateToExclusive]);
    }

    /**
     * Refresh a UIP range in month-sized batches so cron work is bounded.
     *
     * @return array{date_from: string, date_to: string, batches: int}
     */
    public function refresh_uip_read_model(string $dateFrom, string $dateTo): array
    {
        $from = new \DateTimeImmutable($dateFrom);
        $to   = (new \DateTimeImmutable($dateTo))->modify('+1 day');
        if ($from >= $to) {
            throw new \InvalidArgumentException('date_from must not be after date_to.');
        }

        $lockName = 'uip_read_model_refresh';
        $lockRow  = $this->db->query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->getRowArray();
        if ((int) ($lockRow['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Another UIP refresh is already running.');
        }

        try {
            $schemas = $this->_getBranchSchemas();
            $cursor  = $from;
            $batches = 0;

            while ($cursor < $to) {
                $next = $cursor->modify('first day of next month')->setTime(0, 0);
                if ($next > $to) {
                    $next = $to;
                }

                $batchFrom = $cursor->format('Y-m-d H:i:s');
                $batchTo   = $next->format('Y-m-d H:i:s');
                foreach ($schemas as $branchId => $schema) {
                    $this->_refreshUipBranch((int) $branchId, $schema, $batchFrom, $batchTo);
                }
                $this->_refreshUipActivity($batchFrom, $batchTo);
                $this->_refreshUipSnapshots($batchFrom, $batchTo);

                $cursor = $next;
                $batches++;
            }

            $reportDb = REPORT_DB;
            $this->db->query(
                "INSERT INTO {$reportDb}.uip_refresh_state "
                . "(id, coverage_from, coverage_to, data_as_of) VALUES (1, ?, ?, NOW()) "
                . "ON DUPLICATE KEY UPDATE "
                . "coverage_from = LEAST(uip_refresh_state.coverage_from, VALUES(coverage_from)), "
                . "coverage_to = GREATEST(uip_refresh_state.coverage_to, VALUES(coverage_to)), "
                . "data_as_of = VALUES(data_as_of)",
                [$from->format('Y-m-d'), $to->modify('-1 day')->format('Y-m-d')]
            );

            return [
                'date_from' => $from->format('Y-m-d'),
                'date_to'   => $to->modify('-1 day')->format('Y-m-d'),
                'batches'   => $batches,
            ];
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * Last successful read-model refresh timestamp.
     */
    public function get_uip_data_as_of(): ?string
    {
        $reportDb = REPORT_DB;
        $row = $this->db->query(
            "SELECT data_as_of FROM {$reportDb}.uip_refresh_state WHERE id = 1"
        )->getRowArray();

        return $row['data_as_of'] ?? null;
    }

    /**
     * UIP patient report from the precomputed current snapshot.
     */
    public function get_uip_patient($date_from = null, $date_to = null, $transaction_type = null, $account_type = null, $charge_to_account_type = null)
    {
        $asOfDate = $date_to ?: date('Y-m-d');
        if ($asOfDate !== date('Y-m-d')) {
            return $this->_getUipPatientFromFact($date_from, $date_to, $asOfDate);
        }

        $reportDb  = REPORT_DB;
        $scopedDate = $this->_scopedDateSql('a.activity_date', $date_from, $date_to);
        $sql = <<<SQL
WITH active AS (
    SELECT DISTINCT a.master_id
    FROM {$reportDb}.uip_patient_activity a
    WHERE {$scopedDate}
),
categorized AS (
    SELECT
        s.master_id,
        s.gender,
        FLOOR(TIMESTAMPDIFF(YEAR, s.birthdate, ?)) AS age,
        s.transaction_count,
        s.total_sales,
        CASE
            WHEN DATEDIFF(?, s.last_transaction_date) > 720 THEN 'Lost'
            WHEN s.last_return_date IS NOT NULL THEN
                CASE
                    WHEN s.post_lost_tx_count = 1 THEN 'Returnee'
                    WHEN s.post_lost_tx_count = 2 THEN 'Engaged'
                    WHEN s.post_lost_tx_count >= 3 THEN 'Retained'
                    ELSE 'Returnee'
                END
            WHEN s.transaction_count = 1 THEN 'Acquired'
            WHEN s.transaction_count = 2 THEN 'Engaged'
            WHEN s.transaction_count >= 3 THEN 'Retained'
            ELSE 'Unknown'
        END AS category
    FROM active a
    INNER JOIN {$reportDb}.uip_patient_snapshot s ON s.master_id = a.master_id
)
SELECT
    category,
    COALESCE(SUM(total_sales), 0) AS sales,
    COUNT(*) AS patients,
    COALESCE(SUM(transaction_count), 0) AS tx,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END), 0) AS male,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END), 0) AS female,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN age ELSE 0 END), 0) AS male_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Male' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS male_age_n,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN age ELSE 0 END), 0) AS female_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Female' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS female_age_n
FROM categorized
GROUP BY category
SQL;

        $rows    = $this->db->query($sql, array_merge($this->_scopedDateBinds($date_from, $date_to), [$asOfDate, $asOfDate]))->getResultArray();
        $buckets = $this->_uipEmptyCategoryBuckets();
        foreach ($rows as $row) {
            $this->_uipMergeCategoryBucketSqlRow($buckets, $row);
        }

        return $this->_uipFinalizeCategoryRows($buckets);
    }

    /**
     * Historical as-of calculation over the consolidated fact table.
     */
    private function _getUipPatientFromFact($date_from, $date_to, string $asOfDate): array
    {
        $reportDb   = REPORT_DB;
        $scopedDate = $this->_scopedDateSql('a.activity_date', $date_from, $date_to);
        $sql = <<<SQL
WITH active AS (
    SELECT DISTINCT a.master_id
    FROM {$reportDb}.uip_patient_activity a
    WHERE {$scopedDate}
),
ordered AS (
    SELECT
        t.master_id,
        t.transaction_date,
        t.branch_id,
        t.transaction_id,
        t.grand_total,
        LAG(t.transaction_date) OVER (
            PARTITION BY t.master_id
            ORDER BY t.transaction_date, t.branch_id, t.transaction_id
        ) AS previous_date
    FROM {$reportDb}.uip_transaction t
    INNER JOIN active a ON a.master_id = t.master_id
    WHERE t.is_deleted = 0 AND t.transaction_date <= ?
),
metrics AS (
    SELECT
        master_id,
        COUNT(*) AS transaction_count,
        SUM(grand_total) AS total_sales,
        MIN(transaction_date) AS first_transaction_date,
        MAX(transaction_date) AS last_transaction_date,
        MAX(CASE WHEN previous_date IS NOT NULL AND DATEDIFF(transaction_date, previous_date) > 720
            THEN transaction_date END) AS last_return_date
    FROM ordered
    GROUP BY master_id
),
categorized AS (
    SELECT
        m.master_id,
        d.gender,
        FLOOR(TIMESTAMPDIFF(YEAR, d.birthdate, ?)) AS age,
        m.transaction_count,
        m.total_sales,
        CASE
            WHEN DATEDIFF(?, m.last_transaction_date) > 720 THEN 'Lost'
            WHEN m.last_return_date IS NOT NULL THEN
                CASE
                    WHEN SUM(o.transaction_date >= m.last_return_date) = 1 THEN 'Returnee'
                    WHEN SUM(o.transaction_date >= m.last_return_date) = 2 THEN 'Engaged'
                    WHEN SUM(o.transaction_date >= m.last_return_date) >= 3 THEN 'Retained'
                    ELSE 'Returnee'
                END
            WHEN m.transaction_count = 1 THEN 'Acquired'
            WHEN m.transaction_count = 2 THEN 'Engaged'
            WHEN m.transaction_count >= 3 THEN 'Retained'
            ELSE 'Unknown'
        END AS category
    FROM metrics m
    INNER JOIN ordered o ON o.master_id = m.master_id
    LEFT JOIN {$reportDb}.uip_patient_demo d ON d.master_id = m.master_id
    GROUP BY m.master_id, d.gender, d.birthdate, m.transaction_count, m.total_sales,
        m.last_transaction_date, m.last_return_date
)
SELECT
    category,
    COALESCE(SUM(total_sales), 0) AS sales,
    COUNT(*) AS patients,
    COALESCE(SUM(transaction_count), 0) AS tx,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END), 0) AS male,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END), 0) AS female,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN age ELSE 0 END), 0) AS male_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Male' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS male_age_n,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN age ELSE 0 END), 0) AS female_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Female' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS female_age_n
FROM categorized
GROUP BY category
SQL;

        $rows    = $this->db->query($sql, array_merge($this->_scopedDateBinds($date_from, $date_to), [$asOfDate, $asOfDate, $asOfDate]))->getResultArray();
        $buckets = $this->_uipEmptyCategoryBuckets();
        foreach ($rows as $row) {
            $this->_uipMergeCategoryBucketSqlRow($buckets, $row);
        }

        return $this->_uipFinalizeCategoryRows($buckets);
    }

    /**
     * Legacy live multi-branch implementation, retained for production parity checks.
     */
    public function get_uip_patient_live($date_from = null, $date_to = null, $transaction_type = null, $account_type = null, $charge_to_account_type = null)
    {
        $asOfDate  = $date_to ?: date('Y-m-d');
        $schemas   = $this->_getBranchSchemas();
        $buckets   = $this->_uipEmptyCategoryBuckets();

        $multiIds = $this->_uipFetchMultiBranchMasterIds($date_from, $date_to, 'added_on');
        $excludeMulti = $multiIds !== [];
        if ($excludeMulti) {
            $this->_uipLoadMultiMasterTempTable($multiIds);
        }

        foreach ($schemas as $schema) {
            $rows = $this->_fetchBranchUipCategoryBuckets(
                $schema,
                $date_from,
                $date_to,
                $asOfDate,
                $excludeMulti,
                'added_on'
            );
            foreach ($rows as $row) {
                $this->_uipMergeCategoryBucketSqlRow($buckets, $row);
            }
            unset($rows);
        }

        if ($multiIds !== []) {
            foreach (array_chunk($multiIds, 500) as $idChunk) {
                $txByMaster = [];
                $demo       = [];

                foreach ($schemas as $branchId => $schema) {
                    $stream = $this->_fetchBranchUipTxStreamForMasters((int) $branchId, $schema, $idChunk, $asOfDate);
                    foreach ($stream as $tx) {
                        $pid = $tx['patient_id'];
                        if (! isset($demo[$pid])) {
                            $demo[$pid] = [
                                'gender'    => $tx['gender'] ?? null,
                                'birthdate' => $tx['birthdate'] ?? null,
                            ];
                        }
                        $txByMaster[$pid][] = [
                            'transaction_date' => $tx['transaction_date'],
                            'composite_tx'     => $branchId . '-' . $tx['transaction_id'],
                            'grand_total'      => $tx['grand_total'],
                        ];
                    }
                    unset($stream);
                }

                foreach ($idChunk as $pid) {
                    $this->_uipAddPatientToCategoryBucket(
                        $buckets,
                        $this->_uipCategorizeFromTxs(
                            $txByMaster[$pid] ?? [],
                            $demo[$pid]['gender'] ?? null,
                            $demo[$pid]['birthdate'] ?? null,
                            $asOfDate
                        )
                    );
                }
                unset($txByMaster, $demo, $idChunk);
            }
        }

        if ($excludeMulti) {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS uip_multi_masters');
        }

        return $this->_uipFinalizeCategoryRows($buckets);
    }

    /**
     * UIP age report from the precomputed snapshot.
     */
    public function get_uip_age($date_from = null, $date_to = null)
    {
        $asOfDate = $date_to ?: date('Y-m-d');
        if ($asOfDate !== date('Y-m-d')) {
            return $this->_getUipAgeFromFact($date_from, $date_to, $asOfDate);
        }

        $reportDb   = REPORT_DB;
        $scopedDate = $this->_scopedDateSql('a.activity_date', $date_from, $date_to);
        $sql = <<<SQL
WITH active AS (
    SELECT DISTINCT a.master_id
    FROM {$reportDb}.uip_patient_activity a
    WHERE {$scopedDate}
),
aged AS (
    SELECT
        s.master_id,
        s.gender,
        FLOOR(TIMESTAMPDIFF(YEAR, s.birthdate, ?)) AS age,
        s.transaction_count,
        s.total_sales
    FROM active a
    INNER JOIN {$reportDb}.uip_patient_snapshot s ON s.master_id = a.master_id
)
SELECT
    CASE
        WHEN age IS NULL OR age >= 60 THEN '60 & UP'
        WHEN age <= 19 THEN '0-19'
        WHEN age <= 39 THEN '20-39'
        ELSE '40-59'
    END AS age_group,
    COALESCE(SUM(total_sales), 0) AS sales,
    COUNT(*) AS patients,
    COALESCE(SUM(transaction_count), 0) AS tx,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END), 0) AS male,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END), 0) AS female,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN age ELSE 0 END), 0) AS male_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Male' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS male_age_n,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN age ELSE 0 END), 0) AS female_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Female' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS female_age_n
FROM aged
GROUP BY age_group
SQL;

        $rows    = $this->db->query($sql, array_merge($this->_scopedDateBinds($date_from, $date_to), [$asOfDate]))->getResultArray();
        $buckets = $this->_uipEmptyAgeBuckets();
        foreach ($rows as $row) {
            $this->_uipMergeAgeBucketSqlRow($buckets, $row);
        }

        return $this->_uipFinalizeAgeRows($buckets);
    }

    /**
     * Historical age calculation over the consolidated fact table.
     */
    private function _getUipAgeFromFact($date_from, $date_to, string $asOfDate): array
    {
        $reportDb   = REPORT_DB;
        $scopedDate = $this->_scopedDateSql('a.activity_date', $date_from, $date_to);
        $sql = <<<SQL
WITH active AS (
    SELECT DISTINCT a.master_id
    FROM {$reportDb}.uip_patient_activity a
    WHERE {$scopedDate}
),
metrics AS (
    SELECT
        t.master_id,
        COUNT(*) AS transaction_count,
        SUM(t.grand_total) AS total_sales
    FROM {$reportDb}.uip_transaction t
    INNER JOIN active a ON a.master_id = t.master_id
    WHERE t.is_deleted = 0 AND t.transaction_date <= ?
    GROUP BY t.master_id
),
aged AS (
    SELECT
        m.master_id,
        d.gender,
        FLOOR(TIMESTAMPDIFF(YEAR, d.birthdate, ?)) AS age,
        m.transaction_count,
        m.total_sales
    FROM metrics m
    LEFT JOIN {$reportDb}.uip_patient_demo d ON d.master_id = m.master_id
)
SELECT
    CASE
        WHEN age IS NULL OR age >= 60 THEN '60 & UP'
        WHEN age <= 19 THEN '0-19'
        WHEN age <= 39 THEN '20-39'
        ELSE '40-59'
    END AS age_group,
    COALESCE(SUM(total_sales), 0) AS sales,
    COUNT(*) AS patients,
    COALESCE(SUM(transaction_count), 0) AS tx,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END), 0) AS male,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END), 0) AS female,
    COALESCE(SUM(CASE WHEN gender = 'Male' THEN age ELSE 0 END), 0) AS male_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Male' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS male_age_n,
    COALESCE(SUM(CASE WHEN gender = 'Female' THEN age ELSE 0 END), 0) AS female_age_sum,
    COALESCE(SUM(CASE WHEN gender = 'Female' AND age IS NOT NULL THEN 1 ELSE 0 END), 0) AS female_age_n
FROM aged
GROUP BY age_group
SQL;

        $rows    = $this->db->query($sql, array_merge($this->_scopedDateBinds($date_from, $date_to), [$asOfDate, $asOfDate]))->getResultArray();
        $buckets = $this->_uipEmptyAgeBuckets();
        foreach ($rows as $row) {
            $this->_uipMergeAgeBucketSqlRow($buckets, $row);
        }

        return $this->_uipFinalizeAgeRows($buckets);
    }

    /**
     * Legacy live multi-branch age implementation for parity checks.
     */
    public function get_uip_age_live($date_from = null, $date_to = null)
    {
        $asOfDate  = $date_to ?: date('Y-m-d');
        $schemas   = $this->_getBranchSchemas();
        $buckets   = $this->_uipEmptyAgeBuckets();

        $multiIds     = $this->_uipFetchMultiBranchMasterIds($date_from, $date_to, 'added_on');
        $excludeMulti = $multiIds !== [];
        if ($excludeMulti) {
            $this->_uipLoadMultiMasterTempTable($multiIds);
        }

        foreach ($schemas as $schema) {
            $rows = $this->_fetchBranchUipAgeBuckets(
                $schema,
                $date_from,
                $date_to,
                $asOfDate,
                $excludeMulti,
                'added_on'
            );
            foreach ($rows as $row) {
                $this->_uipMergeAgeBucketSqlRow($buckets, $row);
            }
            unset($rows);
        }

        if ($multiIds !== []) {
            foreach (array_chunk($multiIds, 500) as $idChunk) {
                $merged = [];
                foreach ($schemas as $schema) {
                    $rows = $this->_fetchBranchUipAgeMetricsForMasters($schema, $idChunk, $asOfDate);
                    foreach ($rows as $row) {
                        $pid = $row['patient_id'];
                        if (! isset($merged[$pid])) {
                            $merged[$pid] = [
                                'transaction_count' => 0,
                                'total_sales'       => 0.0,
                                'age'               => $this->_uipAgeYears($row['birthdate'] ?? null, $asOfDate),
                                'gender'            => $row['gender'] ?? null,
                            ];
                        }
                        $merged[$pid]['transaction_count'] += (int) $row['transaction_count'];
                        $merged[$pid]['total_sales']       += (float) $row['total_sales'];
                        if (($merged[$pid]['gender'] === null || $merged[$pid]['gender'] === '')
                            && ! empty($row['gender'])
                        ) {
                            $merged[$pid]['gender'] = $row['gender'];
                        }
                        if ($merged[$pid]['age'] === null) {
                            $merged[$pid]['age'] = $this->_uipAgeYears($row['birthdate'] ?? null, $asOfDate);
                        }
                    }
                    unset($rows);
                }

                foreach ($merged as $patient) {
                    $this->_uipAddPatientToAgeBucket($buckets, $patient);
                }
                unset($merged, $idChunk);
            }
        }

        if ($excludeMulti) {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS uip_multi_masters');
        }

        return $this->_uipFinalizeAgeRows($buckets);
    }

    /**
     * Get UIP Lost (consolidated)
     */
    public function get_uip_lost($date_from = null, $date_to = null, $transaction_type = null, $account_type = null)
    {
        $date_today       = date('Y-m-d');
        $patientUnion     = $this->_buildBranchPatientUnion(null, null, 'added_on', false);
        $txBookingUnion   = $this->_buildBranchTxBookingUnion(null, null, 'transaction_date');
        $scopedBooking    = $this->_buildBranchTxBookingUnion($date_from, $date_to, 'added_on');
        $branchPatientSql = $patientUnion['sql'];
        $branchTxBookingSql = $txBookingUnion['sql'];
        $scopedTxBookingSql = $scopedBooking['sql'];
        $compositeTx      = $this->_compositeTxId('btb.branch_id', 'btb.transaction_id');

        $sql = <<<EOT
WITH branch_patient AS (
    {$branchPatientSql}
),
branch_tx_booking AS (
    {$branchTxBookingSql}
),
branch_tx_booking_scoped AS (
    {$scopedTxBookingSql}
),
patient_last_activity AS (
    SELECT
        bp.master_id,
        MAX(btb.transaction_date) AS last_transaction_date
    FROM branch_patient bp
    JOIN branch_tx_booking btb
        ON btb.branch_patient_id = bp.branch_patient_id
        AND btb.branch_id = bp.branch_id
    GROUP BY bp.master_id
),
lost_patients AS (
    SELECT master_id
    FROM patient_last_activity
    WHERE DATEDIFF(?, last_transaction_date) > 720
)
SELECT
    btb.booking_type AS transaction_type,
    btb.charge_to AS account_id,
    COUNT(DISTINCT {$compositeTx}) AS patient_count,
    COALESCE(SUM(btb.amount), 0) AS sum_of_total_amount
FROM branch_tx_booking_scoped btb
INNER JOIN branch_patient bp
    ON bp.branch_patient_id = btb.branch_patient_id
    AND bp.branch_id = btb.branch_id
INNER JOIN lost_patients lp ON lp.master_id = bp.master_id
WHERE (? IS NULL OR btb.booking_type = ?)
GROUP BY btb.booking_type, btb.charge_to
ORDER BY btb.booking_type
EOT;

        $binds = array_merge(
            // patientUnion (date pushdown) → txBookingUnion → scopedBooking → tail
            $patientUnion['decrypt_binds'],
            $txBookingUnion['binds'],
            $scopedBooking['binds'],
            [$date_today, $transaction_type, $transaction_type]
        );

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * get uip patient category (consolidated)
     */
    public function get_uip_patient_category($date_from = null, $date_to = null, $age_group = null)
    {
        $date_today       = date('Y-m-d');
        $patientUnion     = $this->_buildBranchPatientUnion($date_from, $date_to, 'transaction_date', true);
        $transactionUnion = $this->_buildBranchTransactionUnion($date_from, $date_to, 'transaction_date');
        $historyUnion     = $this->_buildBranchTransactionHistoryUnion($date_from, $date_to, 'transaction_date');
        $branchPatientSql = $patientUnion['sql'];
        $branchTransactionSql = $transactionUnion['sql'];
        $branchHistorySql     = $historyUnion['sql'];
        $compositeTx          = $this->_compositeTxId('bt.branch_id', 'bt.transaction_id');

        $sql = <<<EOT
WITH branch_patient AS (
    {$branchPatientSql}
),
branch_transaction AS (
    {$branchTransactionSql}
),
branch_transaction_history AS (
    {$branchHistorySql}
),
patient_status_history AS (
    SELECT
        bp.master_id,
        MAX(CASE WHEN DATEDIFF(?, bt.transaction_date) > 720 THEN 1 ELSE 0 END) AS was_lost
    FROM branch_patient bp
    JOIN branch_transaction_history bt
        ON bt.branch_patient_id = bp.branch_patient_id
        AND bt.branch_id = bp.branch_id
    GROUP BY bp.master_id
),
patient_lifetime AS (
    SELECT
        bp.master_id AS patient_id,
        TIMESTAMPDIFF(YEAR, MAX(bp.birthdate_decrypted), ?) AS age,
        COUNT(DISTINCT CASE WHEN bt.transaction_id IS NOT NULL THEN {$compositeTx} END) AS transaction_count,
        DATEDIFF(?, MAX(bt.transaction_date)) AS days_since_last_tx
    FROM branch_patient bp
    LEFT JOIN branch_transaction bt
        ON bt.branch_patient_id = bp.branch_patient_id
        AND bt.branch_id = bp.branch_id
    GROUP BY bp.master_id
),
categorized_patient AS (
    SELECT
        pl.patient_id,
        pl.age,
        CASE
            WHEN pl.days_since_last_tx > 720 THEN 'Lost'
            WHEN psh.was_lost = 1 AND pl.days_since_last_tx <= 720 THEN 'Returnee'
            WHEN pl.transaction_count = 1 THEN 'Acquired'
            WHEN pl.transaction_count = 2 THEN 'Engaged'
            WHEN pl.transaction_count >= 3 THEN 'Retained'
            ELSE 'Lost'
        END AS patient_category,
        CASE
            WHEN pl.age BETWEEN 0 AND 19 THEN '0-19'
            WHEN pl.age BETWEEN 20 AND 39 THEN '20-39'
            WHEN pl.age BETWEEN 40 AND 59 THEN '40-59'
            ELSE '60 & UP'
        END AS age_group
    FROM patient_lifetime pl
    LEFT JOIN patient_status_history psh ON pl.patient_id = psh.master_id
    WHERE pl.transaction_count > 0
),
scoped_transactions AS (
    SELECT
        cp.patient_id,
        cp.patient_category,
        bt.grand_total,
        YEAR(bt.transaction_date) AS transaction_year
    FROM categorized_patient cp
    INNER JOIN branch_patient bp ON bp.master_id = cp.patient_id
    INNER JOIN branch_transaction bt
        ON bt.branch_patient_id = bp.branch_patient_id
        AND bt.branch_id = bp.branch_id
    WHERE (? IS NULL OR cp.age_group = ?)
)
SELECT
    patient_category,
    transaction_year,
    COUNT(DISTINCT patient_id) AS uip_count,
    COALESCE(SUM(grand_total), 0) AS sales
FROM scoped_transactions
GROUP BY patient_category, transaction_year
ORDER BY patient_category, transaction_year
EOT;

        $binds = array_merge(
            // patientUnion (date pushdown) → transactionUnion → historyUnion → tail (date_today x3, age_group x2)
            $patientUnion['decrypt_binds'],
            $transactionUnion['binds'],
            $historyUnion['binds'],
            [$date_today, $date_today, $date_today, $age_group, $age_group]
        );

        return $this->db->query($sql, $binds)->getResultArray();
    }

    /**
     * Shared CTE chain for HMO account reports (age-filtered booking rows).
     *
     * @return array{sql: string, binds: array}
     */
    private function _buildUipAccountFilteredCte($date_from, $date_to, $age_group): array
    {
        $dateTodaySql     = $this->_dateTodaySql();
        $patientUnion     = $this->_buildBranchPatientUnion($date_from, $date_to, 'transaction_date', true);
        $txBookingUnion   = $this->_buildBranchTxBookingUnionForAccounts($date_from, $date_to, 'transaction_date');
        $branchPatientSql = $patientUnion['sql'];
        $branchTxBookingSql = $txBookingUnion['sql'];

        $sql = <<<EOT
WITH branch_patient AS (
    {$branchPatientSql}
),
branch_tx_booking AS (
    {$branchTxBookingSql}
),
patient_rows AS (
    SELECT
        bp.master_id AS patient_id,
        TIMESTAMPDIFF(YEAR, bp.birthdate_decrypted, {$dateTodaySql}) AS age,
        btb.transaction_id,
        btb.branch_id,
        btb.grand_total,
        btb.amount,
        YEAR(btb.transaction_date) AS transaction_year,
        btb.charge_to AS account_id
    FROM branch_patient bp
    INNER JOIN branch_tx_booking btb
        ON btb.branch_patient_id = bp.branch_patient_id
        AND btb.branch_id = bp.branch_id
),
age_filtered AS (
    SELECT patient_id, transaction_id, branch_id, grand_total, amount, transaction_year, account_id
    FROM (
        SELECT *,
            CASE
                WHEN age BETWEEN 0 AND 19 THEN '0-19'
                WHEN age BETWEEN 20 AND 39 THEN '20-39'
                WHEN age BETWEEN 40 AND 59 THEN '40-59'
                ELSE '60 & UP'
            END AS age_group
        FROM patient_rows
    ) AS scored
    WHERE (? IS NULL OR age_group = ?)
)
EOT;

        $binds = array_merge(
            $patientUnion['decrypt_binds'],
            $txBookingUnion['binds'],
            [$age_group, $age_group]
        );

        return ['sql' => $sql, 'binds' => $binds];
    }

    /**
     * SQL aggregation for account reports (by-year and by-account in one round-trip).
     *
     * @return array{by_year: array, by_account: array}
     */
    private function _fetchUipAccountAggregates($date_from, $date_to, $age_group): array
    {
        $cte = $this->_buildUipAccountFilteredCte($date_from, $date_to, $age_group);

        $sql = $this->_resolve_cross_db_sql($cte['sql'] . <<<SQL

SELECT
    'by_year' AS agg_type,
    UPPER(COALESCE(c.type, 'UNKNOWN')) AS account_type,
    NULL AS account_name,
    af.transaction_year,
    COUNT(DISTINCT af.patient_id) AS uip_count,
    NULL AS census,
    SUM(af.grand_total) AS sales,
    NULL AS total_sales
FROM age_filtered af
LEFT JOIN {MASTER_DB}.company c ON c.id = af.account_id AND c.is_deleted = 0
GROUP BY UPPER(COALESCE(c.type, 'UNKNOWN')), af.transaction_year

UNION ALL

SELECT
    'by_account' AS agg_type,
    NULL AS account_type,
    COALESCE(c.name, 'N/A') AS account_name,
    NULL AS transaction_year,
    COUNT(DISTINCT af.patient_id) AS uip_count,
    COUNT(DISTINCT CONCAT(af.branch_id, '-', af.transaction_id)) AS census,
    NULL AS sales,
    SUM(af.amount) AS total_sales
FROM age_filtered af
LEFT JOIN {MASTER_DB}.company c ON c.id = af.account_id AND c.is_deleted = 0
GROUP BY COALESCE(c.name, 'N/A')
SQL);

        $rows      = $this->db->query($sql, $cte['binds'])->getResultArray();
        $byYear    = [];
        $byAccount = [];

        foreach ($rows as $row) {
            if ($row['agg_type'] === 'by_year') {
                $byYear[] = [
                    'account_type'     => $row['account_type'],
                    'transaction_year' => $row['transaction_year'],
                    'uip_count'        => $row['uip_count'],
                    'sales'            => $row['sales'],
                ];
            } else {
                $byAccount[] = [
                    'account_name' => $row['account_name'],
                    'uip_count'    => $row['uip_count'],
                    'census'       => $row['census'],
                    'total_sales'  => $row['total_sales'],
                ];
            }
        }

        return ['by_year' => $byYear, 'by_account' => $byAccount];
    }

    /**
     * SQL aggregation for account type report (by account + year).
     */
    private function _fetchUipAccountAggregatesByYear($date_from, $date_to, $age_group): array
    {
        return $this->_fetchUipAccountAggregates($date_from, $date_to, $age_group)['by_year'];
    }

    /**
     * SQL aggregation for account name report (by account).
     */
    private function _fetchUipAccountAggregatesByAccount($date_from, $date_to, $age_group): array
    {
        return $this->_fetchUipAccountAggregates($date_from, $date_to, $age_group)['by_account'];
    }

    /**
     * Build account type rows from SQL aggregates + company type lookup.
     */
    private function _buildUipAccountTypeRows(array $yearRows): array
    {
        $result = [];
        foreach ($yearRows as $row) {
            $result[] = [
                'account_type'     => $row['account_type'],
                'transaction_year' => $row['transaction_year'],
                'uip_count'        => (int) $row['uip_count'],
                'sales'            => (float) $row['sales'],
            ];
        }

        return $result;
    }

    /**
     * Build account name rows from SQL aggregates (names resolved in SQL).
     */
    private function _buildUipAccountNameRows(array $accountRows): array
    {
        $result = [];
        foreach ($accountRows as $row) {
            $account_name = $row['account_name'] ?? 'N/A';
            $result[]     = [
                'charged_to_account_name' => $account_name,
                'account_name'            => $account_name,
                'uip_count'               => (int) $row['uip_count'],
                'census'                  => (int) $row['census'],
                'total_sales'             => (float) $row['total_sales'],
            ];
        }

        return $result;
    }

    /**
     * All UIP HMO corporate sections in one orchestration (no duplicate row fetches).
     */
    public function get_uip_hmo_bundle($date_from = null, $date_to = null, $age_group = null): array
    {
        $aggregates = $this->_fetchUipAccountAggregates($date_from, $date_to, $age_group);

        return [
            'patient_category' => $this->get_uip_patient_category($date_from, $date_to, $age_group),
            'account_type'     => $this->_buildUipAccountTypeRows($aggregates['by_year']),
            'account_name'     => $this->_buildUipAccountNameRows($aggregates['by_account']),
        ];
    }

    /**
     * get uip account type (consolidated)
     */
    public function get_uip_account_type($date_from = null, $date_to = null, $age_group = null)
    {
        return $this->_buildUipAccountTypeRows(
            $this->_fetchUipAccountAggregatesByYear($date_from, $date_to, $age_group)
        );
    }

    /**
     * get uip account name (consolidated)
     */
    public function get_uip_account_name($date_from = null, $date_to = null, $age_group = null)
    {
        return $this->_buildUipAccountNameRows(
            $this->_fetchUipAccountAggregatesByAccount($date_from, $date_to, $age_group)
        );
    }

    /**
     * Table 1 - PPL Detail (consolidated, paginated)
     */
    public function get_ppl_detail($date_from = null, $date_to = null, $age_group = null, $charge_to = null, $membership_id = null, $page = 1, $data_size = 100): array
    {
        [$page, $data_size] = $this->_normalizePplDetailPagination($page, $data_size);

        $rows  = $this->_fetchBranchPplDetailAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id, $page, $data_size);
        $names = $this->_fetchPatientNamesByBranch($rows);

        foreach ($rows as &$row) {
            $nameKey             = ($row['branch_id'] ?? '') . '-' . ($row['branch_patient_id'] ?? '');
            $row['patient_name'] = $names[$nameKey] ?? '';
            unset($row['branch_id'], $row['branch_patient_id'], $row['transaction_id']);
        }
        unset($row);

        $size = $this->_fetchBranchPplDetailCount($date_from, $date_to, $age_group, $charge_to, $membership_id);

        return [
            'size'      => $size,
            'page'      => $page,
            'data_size' => $data_size,
            'rows'      => $rows,
        ];
    }

    /**
     * Table 2 - Age Group (consolidated)
     */
    public function get_ppl_age_group($date_from = null, $date_to = null, $age_group = null, $charge_to = null, $membership_id = null)
    {
        return $this->_fetchBranchPplAgeGroupAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id);
    }

    /**
     * Table 3 - Patient Category (consolidated)
     */
    public function get_ppl_patient_category($date_from = null, $date_to = null, $age_group = null, $charge_to = null, $membership_id = null)
    {
        return $this->_fetchBranchPplPatientCategoryAggregates($date_from, $date_to, $age_group, $charge_to, $membership_id);
    }

    /**
     * Table 4 - membership pivot (consolidated)
     */
    public function get_ppl_membership_type($date_from = null, $date_to = null, $age_group = null, $charge_to = null, $membership_id = null)
    {
        return $this->_fetchBranchPplMembershipPivot($date_from, $date_to, $age_group, $charge_to, $membership_id);
    }
}
