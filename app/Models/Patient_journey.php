<?php
namespace App\Core;

namespace App\Models;

use App\Core\MYT_Model;

class Patient_journey extends MYT_Model
{
    protected $DBGroup       = 'master';
    protected $table         = 'patient_tat';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'patient_master_id',
        'queue_id',
        'transaction_id',
        'booking_detail_id',
        'action_performed',
        'source',
        'section',
        'group_id',
        'time_start',
        'time_end',
    ];

    /** Cache of existing patient_tat column names, keyed by branch database name. */
    private $patient_tat_columns = [];

    public function get_patient_journey($branch_id = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null, $patient_ids = null)
    {
        $key   = $this->encryption_key;
        $binds = [];
        $union = $this->_patientJourneyBranchUnionSql($branch_id, $date_from, $date_to, $binds);

        [$filterSql, $filterBinds] = $this->_patientJourneyFilterSql($branch_id, $term, $key);
        $binds = array_merge($binds, $filterBinds);

        $sql = <<<EOT
SELECT
    pt.*,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM (
{$union}
) AS pt
LEFT JOIN {MASTER_DB}.patient AS patient
    ON patient.id = pt.patient_master_id
WHERE 1=1
{$filterSql}
EOT;

        if ($patient_ids !== null) {
            if (! $patient_ids) {
                return [];
            }

            $placeholders = implode(', ', array_fill(0, count($patient_ids), '?'));
            $sql .= "\nAND pt.patient_master_id IN ({$placeholders})";
            foreach ($patient_ids as $patient_id) {
                $binds[] = $patient_id;
            }
        }

        $sql .= " ORDER BY patient.last_name ASC, patient.first_name ASC, pt.time_start ASC";

        if ($patient_ids === null) {
            $sql .= sql_limit_clause($page, $data_size);
        }

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function count_patient_journey_patients($branch_id = null, $date_from = null, $date_to = null, $term = null)
    {
        $key   = $this->encryption_key;
        $binds = [];
        $union = $this->_patientJourneyBranchUnionSql($branch_id, $date_from, $date_to, $binds, true);

        [$filterSql, $filterBinds] = $this->_patientJourneyFilterSql($branch_id, $term, $key);
        $binds = array_merge($binds, $filterBinds);

        $sql = <<<EOT
SELECT COUNT(DISTINCT pt.patient_master_id) AS patient_count
FROM (
{$union}
) AS pt
LEFT JOIN {MASTER_DB}.patient AS patient
    ON patient.id = pt.patient_master_id
WHERE 1=1
{$filterSql}
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        if (! $query) {
            return 0;
        }

        $row = $query->getRowArray();
        return (int) ($row['patient_count'] ?? 0);
    }

    public function get_patient_journey_patient_page($branch_id = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null)
    {
        $key   = $this->encryption_key;
        $binds = [];
        $union = $this->_patientJourneyBranchUnionSql($branch_id, $date_from, $date_to, $binds, true);

        [$filterSql, $filterBinds] = $this->_patientJourneyFilterSql($branch_id, $term, $key);
        $binds = array_merge($binds, $filterBinds);

        $sql = <<<EOT
SELECT
    pt.patient_master_id,
    MAX(TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    ))) AS patient_name
FROM (
{$union}
) AS pt
LEFT JOIN {MASTER_DB}.patient AS patient
    ON patient.id = pt.patient_master_id
WHERE 1=1
{$filterSql}
GROUP BY pt.patient_master_id
ORDER BY MAX(patient.last_name) ASC, MAX(patient.first_name) ASC
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Outer filters that can only be applied to the derived table.
     *
     * RULES: The date range is deliberately not handled here. It is pushed into the branch union
     * instead, so out of range steps are discarded before the exam lookups in the select list run.
     * The branch is pushed down too, but the check is kept here as well: pushdown can only narrow
     * the union to a branch it recognises, so an unknown branch id still has to be excluded here
     * rather than quietly matching everything. The search term has to stay outside because it
     * matches the decrypted patient name, which only exists once the union is joined to patient.
     */
    private function _patientJourneyFilterSql($branch_id, $term, string $key): array
    {
        $sql   = '';
        $binds = [];

        // Applied out here rather than inside each union part, because pt.patient_master_id is the
        // already resolved column: testing it in the part would mean running the resolver a second
        // time for every row it scans.
        $sql .= "\nAND pt.patient_master_id IS NOT NULL";

        if ($branch_id) {
            $sql .= "\nAND pt.branch_id = ?";
            $binds[] = $branch_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND TRIM(CONCAT(
    CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
    IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
    IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
)) LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        return [$sql, $binds];
    }

    private function _unionCharExpr(string $column): string
    {
        return "CAST({$column} AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci";
    }

    /**
     * Checks whether a branch database already has a given patient_tat column. The lookup is cached
     * per database because the branch UNION is rebuilt several times within one request (rows, page
     * of patients, and total count).
     */
    private function _patientTatHasColumn(string $dbPlaceholder, string $column): bool
    {
        $database = $this->_resolve_cross_db_sql($dbPlaceholder);

        if (! isset($this->patient_tat_columns[$database])) {
            $query = $this->db->query(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $this->table]
            );

            $columns = [];
            foreach ($query ? $query->getResultArray() : [] as $row) {
                $columns[strtolower($row['COLUMN_NAME'])] = true;
            }

            $this->patient_tat_columns[$database] = $columns;
        }

        return isset($this->patient_tat_columns[$database][strtolower($column)]);
    }

    /**
     * Scalar lookup of one exam attribute for a patient_tat step.
     *
     * RULES: The `detail` scope reads the single exam line the step points at, which is exact but
     * only possible when booking_detail_id was recorded, so it is guarded and costs nothing on the
     * branches that never fill that column. The `queue` scope widens to every exam of the same
     * visit through transaction.queue_id, which is the only link left when the step carries no
     * booking_detail_id. A visit can hold several exams, so a queue scoped lookup is an aggregate
     * over that set rather than a single exam.
     */
    private function _patientJourneyExamLookupSql(string $dbPlaceholder, string $selectExpr, string $scope): string
    {
        $labTestId = "CASE WHEN fbd.type = 'package' THEN fbpd.test_id ELSE fbd.test_id END";

        $examJoins = <<<EOT
LEFT JOIN {$dbPlaceholder}.booking_package_details AS fbpd
        ON fbpd.booking_detail_id = fbd.id
       AND fbpd.is_deleted = 0
    INNER JOIN {MASTER_DB}.lab_test AS flt
        ON flt.id = {$labTestId}
       AND flt.is_deleted = 0
EOT;

        if ($scope === 'detail') {
            return <<<EOT
CASE WHEN patient_tat.booking_detail_id IS NOT NULL THEN (
    SELECT {$selectExpr}
    FROM {$dbPlaceholder}.booking_details AS fbd
    {$examJoins}
    WHERE fbd.is_deleted = 0
      AND fbd.id = patient_tat.booking_detail_id
) END
EOT;
        }

        return <<<EOT
(
    SELECT {$selectExpr}
    FROM {$dbPlaceholder}.`transaction` AS ft
    INNER JOIN {$dbPlaceholder}.booking AS fb
        ON fb.transaction_id = ft.id
       AND fb.is_deleted = 0
    INNER JOIN {$dbPlaceholder}.booking_details AS fbd
        ON fbd.booking_id = fb.id
       AND fbd.is_deleted = 0
    {$examJoins}
    WHERE ft.is_deleted = 0
      AND ft.queue_id = patient_tat.queue_id
)
EOT;
    }

    /**
     * The per branch UNION the report reads from.
     *
     * RULES: $patientsOnly builds a stripped down part that selects nothing but the resolved
     * patient and the branch. The journey endpoint runs this union three times for one request -
     * to count patients, to page them, and then to fetch their steps - and the first two only ever
     * read patient_master_id. Building the exam lookups, the category joins and the transaction
     * join for those two meant paying for columns that were thrown away immediately.
     */
    private function _patientJourneyBranchUnionSql($branch_id, $date_from, $date_to, array &$binds, bool $patientsOnly = false): string
    {
        $branches = [
            1 => '{MOBILE_DB}',
            2 => '{OAKRIDGE_DB}',
            3 => '{MAC_DB}',
            4 => '{CONSO_DB}',
            7 => '{MANTLEWOOD_DB}',
        ];

        // Reading the other four branch databases only to have the outer query throw their rows
        // away is pure waste, so a requested branch narrows the union to that one part.
        if ($branch_id !== null && $branch_id !== '' && strtolower((string) $branch_id) !== 'all' && isset($branches[(int) $branch_id])) {
            $branches = [(int) $branch_id => $branches[(int) $branch_id]];
        }

        $actionPerformed = $this->_unionCharExpr('patient_tat.action_performed');
        $source          = $this->_unionCharExpr('patient_tat.source');
        $section         = $this->_unionCharExpr('patient_tat.section');
        $transactionNo   = $this->_unionCharExpr('transaction.transaction_no');
        $groupName       = $this->_unionCharExpr('`group`.name');

        // RULES: Written as a half open range instead of DATE(time_start) BETWEEN ? AND ?, which
        // means the same thing but wraps the column in a function and so cannot use an index on
        // time_start - every request had to scan the whole table before it could discard a single
        // out of range step. The upper bound is the day after date_to at midnight, exclusive.
        $fromTs       = $date_from ? strtotime((string) $date_from) : false;
        $toTs         = $date_to ? strtotime((string) $date_to . ' +1 day') : false;
        $hasDateRange = $fromTs !== false && $toTs !== false;
        $dateFilter   = $hasDateRange
            ? "\nAND patient_tat.time_start >= ?\nAND patient_tat.time_start < ?"
            : '';

        $parts = [];
        foreach ($branches as $branchId => $dbPlaceholder) {
            // Applied inside each part so the exam lookups in the select list are never evaluated
            // for a step outside the requested range.
            if ($hasDateRange) {
                $binds[] = date('Y-m-d 00:00:00', $fromTs);
                $binds[] = date('Y-m-d 00:00:00', $toTs);
            }

            // RULES: Resolve a missing patient only through a real queue_id. Mobile kiosk_wait rows
            // use queue_id = 0 as a placeholder before a visit exists, so every such row would
            // otherwise match the same subquery and inherit the latest patient on the branch.
            $resolvedPatientId = <<<EOT
COALESCE(
    patient_tat.patient_master_id,
    CASE
        WHEN patient_tat.queue_id > 0 THEN (
            SELECT pt2.patient_master_id
            FROM {$dbPlaceholder}.patient_tat AS pt2
            WHERE pt2.queue_id = patient_tat.queue_id
              AND pt2.is_deleted = 0
              AND pt2.patient_master_id IS NOT NULL
            ORDER BY pt2.id DESC
            LIMIT 1
        )
    END
)
EOT;

            if ($patientsOnly) {
                $parts[] = <<<EOT
SELECT
    {$resolvedPatientId} AS patient_master_id,
    {$branchId} AS branch_id
FROM {$dbPlaceholder}.patient_tat
WHERE patient_tat.is_deleted = 0{$dateFilter}
EOT;
                continue;
            }

            // RULES: The EMR denormalises exam_name, category_id and category_name onto patient_tat
            // when it records a step, so the stored value is always preferred. It is not always
            // there: a branch database may not have migrated the columns yet, and even where they
            // exist the EMR can leave them empty. Each field therefore falls back to the exam
            // catalogue, first through booking_detail_id when the step names an exam line, then
            // through the visit's queue. A branch missing a column contributes only the fallbacks
            // rather than breaking the whole UNION with an unknown column error.
            $examNameParts = [];
            if ($this->_patientTatHasColumn($dbPlaceholder, 'exam_name')) {
                $examNameParts[] = "NULLIF(patient_tat.exam_name, '')";
            }
            $examNameParts[] = $this->_patientJourneyExamLookupSql($dbPlaceholder, 'MIN(flt.name)', 'detail');
            $examNameParts[] = $this->_patientJourneyExamLookupSql(
                $dbPlaceholder,
                "GROUP_CONCAT(DISTINCT flt.name ORDER BY flt.name SEPARATOR ', ')",
                'queue'
            );
            $examName = $this->_unionCharExpr('COALESCE(' . implode(', ', $examNameParts) . ')');

            $hasCategoryId = $this->_patientTatHasColumn($dbPlaceholder, 'category_id');

            $categoryIdParts = [];
            if ($hasCategoryId) {
                $categoryIdParts[] = 'patient_tat.category_id';
            }
            $categoryIdParts[] = $this->_patientJourneyExamLookupSql($dbPlaceholder, 'MIN(flt.category_id)', 'detail');
            $categoryIdParts[] = $this->_patientJourneyExamLookupSql($dbPlaceholder, 'MIN(flt.category_id)', 'queue');
            $resolvedCategoryId = 'COALESCE(' . implode(', ', $categoryIdParts) . ')';

            // The name is read from the category the id resolved to rather than looked up on its
            // own, so the two can never disagree when a visit spans several categories.
            $categoryNameParts = [];
            if ($this->_patientTatHasColumn($dbPlaceholder, 'category_name')) {
                $categoryNameParts[] = "NULLIF(patient_tat.category_name, '')";
            }
            $categoryNameParts[] = 'category.name';
            $examCategoryName = $this->_unionCharExpr('COALESCE(' . implode(', ', $categoryNameParts) . ')');

            // RULES: category is a self-referencing table - a row's category_id points at its
            // parent in the same table, and a row without one is itself a top level section. So the
            // parent columns below fall back to the category itself, which is what lets a report
            // roll HEMATOLOGY and CLINICAL CHEMISTRY up into LABORATORY while leaving a top level
            // section such as XRAY standing on its own.
            $categoryJoins = <<<EOT
LEFT JOIN {MASTER_DB}.category AS category
    ON category.id = {$resolvedCategoryId}
   AND category.is_deleted = 0
LEFT JOIN {MASTER_DB}.category AS parent_category
    ON parent_category.id = category.category_id
   AND parent_category.is_deleted = 0
EOT;

            // Taken off the join rather than repeating the resolver, which would run the lookup
            // subqueries a second time for every row.
            $examCategoryId         = $hasCategoryId ? 'COALESCE(patient_tat.category_id, category.id)' : 'category.id';
            $parentCategoryId       = 'COALESCE(parent_category.id, category.id)';
            $examCategoryParentName = $this->_unionCharExpr('COALESCE(parent_category.name, category.name)');

            $resolvedTransactionId = <<<EOT
COALESCE(
    patient_tat.transaction_id,
    (
        SELECT pt3.transaction_id
        FROM {$dbPlaceholder}.patient_tat AS pt3
        WHERE pt3.queue_id = patient_tat.queue_id
          AND pt3.is_deleted = 0
          AND pt3.transaction_id IS NOT NULL
        ORDER BY pt3.id DESC
        LIMIT 1
    )
)
EOT;

            $parts[] = <<<EOT
SELECT
    patient_tat.id,
    {$resolvedPatientId} AS patient_master_id,
    patient_tat.queue_id,
    {$resolvedTransactionId} AS transaction_id,
    patient_tat.booking_detail_id,
    {$actionPerformed} AS action_performed,
    {$source} AS source,
    {$section} AS section,
    patient_tat.group_id,
    patient_tat.time_start,
    patient_tat.time_end,
    patient_tat.added_by,
    patient_tat.updated_by,
    patient_tat.added_on,
    patient_tat.updated_on,
    patient_tat.is_deleted,
    {$branchId} AS branch_id,
    {$transactionNo} AS transaction_no,
    transaction.transaction_date,
    patient_queue.queue_no,
    {$examName} AS exam_name,
    {$examCategoryId} AS exam_category_id,
    {$examCategoryName} AS exam_category_name,
    {$parentCategoryId} AS exam_category_parent_id,
    {$examCategoryParentName} AS exam_category_parent_name,
    {$groupName} AS group_name
FROM {$dbPlaceholder}.patient_tat
LEFT JOIN {$dbPlaceholder}.patient_queue AS patient_queue
    ON patient_queue.id = patient_tat.queue_id
LEFT JOIN {$dbPlaceholder}.`transaction` AS transaction
    ON transaction.id = {$resolvedTransactionId}
   AND transaction.is_deleted = 0
LEFT JOIN {MASTER_DB}.`group` AS `group`
    ON `group`.id = patient_tat.group_id
   AND `group`.is_deleted = 0
{$categoryJoins}
WHERE patient_tat.is_deleted = 0{$dateFilter}
EOT;
        }

        return implode("\nUNION ALL\n", $parts);
    }
}
