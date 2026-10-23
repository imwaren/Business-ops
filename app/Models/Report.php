<?php

namespace App\Core;

namespace App\Models;

use App\Core\MYT_Model;

class Report extends MYT_Model
{
    protected $DBGroup       = 'report';
    protected $table         = 'daily_branch_sales';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'branch_id',
    ];

    /** Column names per "database.table", cached for the branch exam tables. */
    private array $service_tat_columns = [];

    public function get_daily_sales($branch_id = null, $date_from = null, $date_to = null, $sales_date = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql   = <<<EOT
SELECT daily_branch_sales.*
FROM daily_branch_sales
WHERE daily_branch_sales.is_deleted = 0
EOT;

        if ($branch_id) {
            $sql .= <<<EOT

AND daily_branch_sales.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND daily_branch_sales.sales_date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($sales_date) {
            $sql .= <<<EOT

AND daily_branch_sales.sales_date = ?
EOT;
            $binds[] = $sales_date;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_patient_tat($branch_id = null, $date_from = null, $date_to = null, $tat_date = null, $page = null, $data_size = null)
    {
        $branchIds = array_keys($this->_serviceTatBranchDbTokens($branch_id));
        if (! $branchIds) {
            return false;
        }

        if (count($branchIds) === 1) {
            return $this->_get_patient_tat_for_branch($branchIds[0], $date_from, $date_to, $tat_date, $page, $data_size);
        }

        $merged = [];

        foreach ($branchIds as $id) {
            $rows = $this->_get_patient_tat_for_branch($id, $date_from, $date_to, $tat_date, null, null);
            if (! $rows) {
                continue;
            }

            foreach ($rows as $row) {
                $row['branch_id'] = $id;
                $row['branch']    = $this->_serviceTatBranchName($id);
                $key = $id . '|' . ($row['tat_date'] ?? '') . '|' . ($row['time_from'] ?? '');
                $merged[$key] = $row;
            }
        }

        ksort($merged);
        $patient_tats = array_values($merged);

        if ($page && $data_size) {
            $page   = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $patient_tats = array_slice($patient_tats, $offset, (int) $data_size);
        }

        return $patient_tats;
    }

    private function _get_patient_tat_for_branch($branch_id, $date_from = null, $date_to = null, $tat_date = null, $page = null, $data_size = null)
    {
        $emrDb = $this->_patientTatEmrDbToken($branch_id);
        if ($emrDb === null) {
            return false;
        }

        [$startDate, $endDate] = $this->_patientTatDateRange($date_from, $date_to, $tat_date);
        $rangeStart = $startDate . ' 00:00:00';
        $rangeEnd   = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';
        $branchId   = (int) $branch_id;

        $diagDb = $this->_serviceTatBranchDbToken($branch_id);
        if ($diagDb === null) {
            return false;
        }

        $datesSql = $this->_patientTatDatesSql($startDate, $endDate);
        $hoursSql = $this->_patientTatHoursSql();
        $apeSql   = $this->_patientTatPhysicalExamAggSql($emrDb);
        $yakapSql = $this->_patientTatQueueAggSql($emrDb, $this->_yakapQueueTypeFilter($diagDb));
        $regSql   = $this->_patientTatQueueAggSql($emrDb, $this->_regularQueueTypeFilter($diagDb));

        $sql = <<<EOT
SELECT
    {$branchId} AS branch_id,
    days.tat_date,
    hrs.time_from,
    hrs.time_to,
    0 AS total_tests,
    0.00 AS avg_tat_minutes,
    0 AS min_tat_minutes,
    0 AS max_tat_minutes,
    IFNULL(ape.ape_peme_old, 0) AS ape_peme_old,
    IFNULL(ape.ape_peme_new, 0) AS ape_peme_new,
    IFNULL(yakap.cnt_old, 0) AS yakap_old,
    IFNULL(yakap.cnt_new, 0) AS yakap_new,
    IFNULL(reg.cnt_old, 0) AS regular_old,
    IFNULL(reg.cnt_new, 0) AS regular_new
FROM ({$datesSql}) AS days
CROSS JOIN ({$hoursSql}) AS hrs
LEFT JOIN ({$apeSql}) AS ape
    ON ape.tat_date = days.tat_date
   AND ape.time_from = hrs.time_from
LEFT JOIN ({$yakapSql}) AS yakap
    ON yakap.tat_date = days.tat_date
   AND yakap.time_from = hrs.time_from
LEFT JOIN ({$regSql}) AS reg
    ON reg.tat_date = days.tat_date
   AND reg.time_from = hrs.time_from
ORDER BY days.tat_date ASC, hrs.time_from ASC
EOT;

        $rangeBinds = [$rangeStart, $rangeEnd, $rangeStart, $rangeEnd];
        $binds      = array_merge($rangeBinds, $rangeBinds, $rangeBinds);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    private function _patientTatEmrDbToken($branch_id): ?string
    {
        $map = [
            1 => '{MOBILE_EMR_DB}',
            2 => '{OAKRIDGE_EMR_DB}',
            3 => '{MAC_EMR_DB}',
            4 => '{CONSO_EMR_DB}',
            7 => '{MANTLEWOOD_EMR_DB}',
        ];

        return $map[(int) $branch_id] ?? null;
    }

    private function _patientTatDateRange($date_from, $date_to, $tat_date): array
    {
        if ($tat_date) {
            $day = date('Y-m-d', strtotime((string) $tat_date));
            return [$day, $day];
        }

        if ($date_from && $date_to) {
            return [
                date('Y-m-d', strtotime((string) $date_from)),
                date('Y-m-d', strtotime((string) $date_to)),
            ];
        }

        $today = date('Y-m-d');
        return [$today, $today];
    }

    private function _patientTatDatesSql(string $startDate, string $endDate): string
    {
        $parts  = [];
        $cursor = strtotime($startDate);
        $endTs  = strtotime($endDate);
        while ($cursor !== false && $endTs !== false && $cursor <= $endTs) {
            $day     = date('Y-m-d', $cursor);
            $parts[] = "SELECT '{$day}' AS tat_date";
            $cursor  = strtotime('+1 day', $cursor);
        }

        return $parts ? implode("\nUNION ALL\n", $parts) : "SELECT CURDATE() AS tat_date";
    }

    private function _patientTatHoursSql(): string
    {
        return <<<EOT
SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
SELECT TIME('17:00:00'), TIME('18:00:00')
EOT;
    }

    private function _patientTatPhysicalExamAggSql(string $emrDb): string
    {
        return <<<EOT
SELECT
    DATE(pe.added_on) AS tat_date,
    MAKETIME(HOUR(pe.added_on), 0, 0) AS time_from,
    COUNT(DISTINCT CASE WHEN DATE(first_pe.first_on) < DATE(pe.added_on) THEN pe.patient_id END) AS ape_peme_old,
    COUNT(DISTINCT CASE WHEN DATE(first_pe.first_on) = DATE(pe.added_on) THEN pe.patient_id END) AS ape_peme_new
FROM {$emrDb}.physical_exam pe
INNER JOIN (
    SELECT pe_today.patient_id, MIN(pe_all.added_on) AS first_on
    FROM {$emrDb}.physical_exam pe_today
    INNER JOIN {$emrDb}.physical_exam pe_all
        ON pe_all.patient_id = pe_today.patient_id
       AND pe_all.is_deleted = 0
    WHERE pe_today.is_deleted = 0
      AND pe_today.added_on >= ?
      AND pe_today.added_on < ?
    GROUP BY pe_today.patient_id
) first_pe ON first_pe.patient_id = pe.patient_id
WHERE pe.is_deleted = 0
  AND pe.added_on >= ?
  AND pe.added_on < ?
  AND TIME(pe.added_on) >= '06:00:00'
  AND TIME(pe.added_on) < '18:00:00'
GROUP BY DATE(pe.added_on), HOUR(pe.added_on)
EOT;
    }

    private function _patientTatQueueAggSql(string $emrDb, string $typeFilter): string
    {
        return <<<EOT
SELECT
    DATE(q.added_on) AS tat_date,
    MAKETIME(HOUR(q.added_on), 0, 0) AS time_from,
    COUNT(DISTINCT CASE WHEN DATE(first_q.first_on) < DATE(q.added_on) THEN q.patient_id END) AS cnt_old,
    COUNT(DISTINCT CASE WHEN DATE(first_q.first_on) = DATE(q.added_on) THEN q.patient_id END) AS cnt_new
FROM {$emrDb}.queue q
INNER JOIN (
    SELECT q_today.patient_id, MIN(q_all.added_on) AS first_on
    FROM {$emrDb}.queue q_today
    INNER JOIN {$emrDb}.queue q_all
        ON q_all.patient_id = q_today.patient_id
       AND q_all.is_deleted = 0
       AND q_all.doctor_id IS NOT NULL
       AND {$this->_patientTatQueueFilterAlias($typeFilter, 'q_all')}
    WHERE q_today.is_deleted = 0
      AND q_today.doctor_id IS NOT NULL
      AND {$this->_patientTatQueueFilterAlias($typeFilter, 'q_today')}
      AND q_today.added_on >= ?
      AND q_today.added_on < ?
    GROUP BY q_today.patient_id
) first_q ON first_q.patient_id = q.patient_id
WHERE q.is_deleted = 0
  AND q.doctor_id IS NOT NULL
  AND {$typeFilter}
  AND q.added_on >= ?
  AND q.added_on < ?
  AND TIME(q.added_on) >= '06:00:00'
  AND TIME(q.added_on) < '18:00:00'
GROUP BY DATE(q.added_on), HOUR(q.added_on)
EOT;
    }

    private function _patientTatQueueFilterAlias(string $typeFilter, string $alias): string
    {
        return str_replace('q.', $alias . '.', $typeFilter);
    }

    /**
     * Yakap: ekonsulta assessment, or PHILHEALTH - YAKAP (transaction.hmo_id = 3210).
     * Uses q. so _patientTatQueueFilterAlias() can rewrite the alias.
     */
    private function _yakapQueueTypeFilter(string $diagDb): string
    {
        return "(q.assessment_type = 'ekonsulta' OR EXISTS ("
            . "SELECT 1 FROM {$diagDb}.`transaction` t "
            . "WHERE t.id = q.transaction_id AND t.hmo_id = 3210 AND t.is_deleted = 0"
            . "))";
    }

    /**
     * Regular: not ekonsulta and not PHILHEALTH - YAKAP (transaction.hmo_id = 3210).
     * Uses q. so _patientTatQueueFilterAlias() can rewrite the alias.
     */
    private function _regularQueueTypeFilter(string $diagDb): string
    {
        return "(q.assessment_type <> 'ekonsulta' AND NOT EXISTS ("
            . "SELECT 1 FROM {$diagDb}.`transaction` t "
            . "WHERE t.id = q.transaction_id AND t.hmo_id = 3210 AND t.is_deleted = 0"
            . "))";
    }

    public function get_pcp_clinic_tat($branch_id = null, $date_from = null, $date_to = null, $tat_date = null, $page = null, $data_size = null)
    {
        $emrDb = $this->_patientTatEmrDbToken($branch_id);
        if ($emrDb === null) {
            return false;
        }

        [$startDate, $endDate] = $this->_patientTatDateRange($date_from, $date_to, $tat_date);
        $rangeStart = $startDate . ' 00:00:00';
        $rangeEnd   = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';
        $branchId   = (int) $branch_id;

        $diagDb = $this->_serviceTatBranchDbToken($branch_id);
        if ($diagDb === null) {
            return false;
        }

        $datesSql   = $this->_patientTatDatesSql($startDate, $endDate);
        $hoursSql   = $this->_patientTatHoursSql();
        $doctorsSql = $this->_pcpClinicDoctorsSql($emrDb);
        $apeSql     = $this->_pcpClinicPhysicalExamAggSql($emrDb);
        $yakapSql   = $this->_pcpClinicQueueAggSql($emrDb, $this->_yakapQueueTypeFilter($diagDb));
        $regSql     = $this->_pcpClinicQueueAggSql($emrDb, $this->_regularQueueTypeFilter($diagDb));

        $sql = <<<EOT
SELECT
    {$branchId} AS branch_id,
    days.tat_date,
    docs.doctor_id,
    docs.doctor_name,
    hrs.time_from,
    hrs.time_to,
    IFNULL(ape.ape_peme_old, 0) AS ape_peme_old,
    IFNULL(ape.ape_peme_new, 0) AS ape_peme_new,
    IFNULL(yakap.cnt_old, 0) AS yakap_old,
    IFNULL(yakap.cnt_new, 0) AS yakap_new,
    IFNULL(reg.cnt_old, 0) AS regular_old,
    IFNULL(reg.cnt_new, 0) AS regular_new
FROM ({$doctorsSql}) AS docs
CROSS JOIN ({$datesSql}) AS days
CROSS JOIN ({$hoursSql}) AS hrs
LEFT JOIN ({$apeSql}) AS ape
    ON ape.tat_date = days.tat_date
   AND ape.time_from = hrs.time_from
   AND ape.doctor_id = docs.doctor_id
LEFT JOIN ({$yakapSql}) AS yakap
    ON yakap.tat_date = days.tat_date
   AND yakap.time_from = hrs.time_from
   AND yakap.doctor_id = docs.doctor_id
LEFT JOIN ({$regSql}) AS reg
    ON reg.tat_date = days.tat_date
   AND reg.time_from = hrs.time_from
   AND reg.doctor_id = docs.doctor_id
ORDER BY days.tat_date ASC, docs.doctor_name ASC, hrs.time_from ASC
EOT;

        $rangeBinds = [$rangeStart, $rangeEnd, $rangeStart, $rangeEnd];
        $binds      = array_merge($rangeBinds, $rangeBinds, $rangeBinds, $rangeBinds);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_specialist_md_tat($branch_id = null, $date_from = null, $date_to = null, $tat_date = null, $page = null, $data_size = null)
    {
        $emrDb = $this->_patientTatEmrDbToken($branch_id);
        if ($emrDb === null) {
            return false;
        }

        [$startDate, $endDate] = $this->_patientTatDateRange($date_from, $date_to, $tat_date);
        $rangeStart = $startDate . ' 00:00:00';
        $rangeEnd   = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';
        $branchId   = (int) $branch_id;

        $datesSql    = $this->_patientTatDatesSql($startDate, $endDate);
        $hoursSql    = $this->_patientTatHoursSql();
        $doctorsSql  = $this->_specialistDoctorsSql($emrDb);
        $walkInSql   = $this->_specialistQueueAggSql($emrDb, "q.transaction_type = 'walk-in'");
        $referredSql = $this->_specialistQueueAggSql($emrDb, "q.transaction_type = 'referred'");

        $sql = <<<EOT
SELECT
    {$branchId} AS branch_id,
    days.tat_date,
    docs.doctor_id,
    docs.doctor_name,
    hrs.time_from,
    hrs.time_to,
    IFNULL(walk_in.cnt_old, 0) AS walk_in_old,
    IFNULL(walk_in.cnt_new, 0) AS walk_in_new,
    IFNULL(referred.cnt_old, 0) AS referred_old,
    IFNULL(referred.cnt_new, 0) AS referred_new
FROM ({$doctorsSql}) AS docs
CROSS JOIN ({$datesSql}) AS days
CROSS JOIN ({$hoursSql}) AS hrs
LEFT JOIN ({$walkInSql}) AS walk_in
    ON walk_in.tat_date = days.tat_date
   AND walk_in.time_from = hrs.time_from
   AND walk_in.doctor_id = docs.doctor_id
LEFT JOIN ({$referredSql}) AS referred
    ON referred.tat_date = days.tat_date
   AND referred.time_from = hrs.time_from
   AND referred.doctor_id = docs.doctor_id
ORDER BY days.tat_date ASC, docs.doctor_name ASC, hrs.time_from ASC
EOT;

        $rangeBinds = [$rangeStart, $rangeEnd, $rangeStart, $rangeEnd];
        $binds      = array_merge(
            [$rangeStart, $rangeEnd],
            $rangeBinds,
            $rangeBinds
        );

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    private function _doctorRoleJoinSql(int $roleId): string
    {
        return <<<EOT
INNER JOIN {MASTER_DB}.doctor AS doctor
    ON doctor.id = src.doctor_id
   AND doctor.is_deleted = 0
INNER JOIN {OAKRIDGE_EMR_DB}.role AS emr_role
    ON emr_role.id = doctor.type
   AND emr_role.is_deleted = 0
   AND emr_role.id = {$roleId}
EOT;
    }

    private function _pcpClinicDoctorsSql(string $emrDb): string
    {
        $roleJoin = $this->_doctorRoleJoinSql(5);

        return <<<EOT
SELECT src.doctor_id,
    CONCAT_WS(' ', doctor.first_name, doctor.middle_name, doctor.last_name) AS doctor_name
FROM (
    SELECT pe.doctor_id
    FROM {$emrDb}.physical_exam pe
    WHERE pe.is_deleted = 0
      AND pe.doctor_id IS NOT NULL
      AND pe.added_on >= ?
      AND pe.added_on < ?
    UNION
    SELECT q.doctor_id
    FROM {$emrDb}.queue q
    WHERE q.is_deleted = 0
      AND q.doctor_id IS NOT NULL
      AND q.added_on >= ?
      AND q.added_on < ?
) src
{$roleJoin}
GROUP BY src.doctor_id, doctor.first_name, doctor.middle_name, doctor.last_name
EOT;
    }

    private function _pcpClinicPhysicalExamAggSql(string $emrDb): string
    {
        return <<<EOT
SELECT
    DATE(pe.added_on) AS tat_date,
    pe.doctor_id,
    MAKETIME(HOUR(pe.added_on), 0, 0) AS time_from,
    COUNT(DISTINCT CASE WHEN DATE(first_pe.first_on) < DATE(pe.added_on) THEN pe.patient_id END) AS ape_peme_old,
    COUNT(DISTINCT CASE WHEN DATE(first_pe.first_on) = DATE(pe.added_on) THEN pe.patient_id END) AS ape_peme_new
FROM {$emrDb}.physical_exam pe
INNER JOIN (
    SELECT pe_today.patient_id, MIN(pe_all.added_on) AS first_on
    FROM {$emrDb}.physical_exam pe_today
    INNER JOIN {$emrDb}.physical_exam pe_all
        ON pe_all.patient_id = pe_today.patient_id
       AND pe_all.is_deleted = 0
    WHERE pe_today.is_deleted = 0
      AND pe_today.doctor_id IS NOT NULL
      AND pe_today.added_on >= ?
      AND pe_today.added_on < ?
    GROUP BY pe_today.patient_id
) first_pe ON first_pe.patient_id = pe.patient_id
WHERE pe.is_deleted = 0
  AND pe.doctor_id IS NOT NULL
  AND pe.added_on >= ?
  AND pe.added_on < ?
  AND TIME(pe.added_on) >= '06:00:00'
  AND TIME(pe.added_on) < '18:00:00'
GROUP BY DATE(pe.added_on), pe.doctor_id, HOUR(pe.added_on)
EOT;
    }

    private function _pcpClinicQueueAggSql(string $emrDb, string $typeFilter): string
    {
        return <<<EOT
SELECT
    DATE(q.added_on) AS tat_date,
    q.doctor_id,
    MAKETIME(HOUR(q.added_on), 0, 0) AS time_from,
    COUNT(DISTINCT CASE WHEN DATE(first_q.first_on) < DATE(q.added_on) THEN q.patient_id END) AS cnt_old,
    COUNT(DISTINCT CASE WHEN DATE(first_q.first_on) = DATE(q.added_on) THEN q.patient_id END) AS cnt_new
FROM {$emrDb}.queue q
INNER JOIN (
    SELECT q_today.patient_id, MIN(q_all.added_on) AS first_on
    FROM {$emrDb}.queue q_today
    INNER JOIN {$emrDb}.queue q_all
        ON q_all.patient_id = q_today.patient_id
       AND q_all.is_deleted = 0
       AND q_all.doctor_id IS NOT NULL
       AND {$this->_patientTatQueueFilterAlias($typeFilter, 'q_all')}
    WHERE q_today.is_deleted = 0
      AND q_today.doctor_id IS NOT NULL
      AND {$this->_patientTatQueueFilterAlias($typeFilter, 'q_today')}
      AND q_today.added_on >= ?
      AND q_today.added_on < ?
    GROUP BY q_today.patient_id
) first_q ON first_q.patient_id = q.patient_id
WHERE q.is_deleted = 0
  AND q.doctor_id IS NOT NULL
  AND {$typeFilter}
  AND q.added_on >= ?
  AND q.added_on < ?
  AND TIME(q.added_on) >= '06:00:00'
  AND TIME(q.added_on) < '18:00:00'
GROUP BY DATE(q.added_on), q.doctor_id, HOUR(q.added_on)
EOT;
    }

    private function _specialistDoctorsSql(string $emrDb): string
    {
        $roleJoin = $this->_doctorRoleJoinSql(3);

        return <<<EOT
SELECT src.doctor_id,
    CONCAT_WS(' ', doctor.first_name, doctor.middle_name, doctor.last_name) AS doctor_name
FROM (
    SELECT q.doctor_id
    FROM {$emrDb}.queue q
    WHERE q.is_deleted = 0
      AND q.doctor_id IS NOT NULL
      AND q.transaction_type IN ('walk-in', 'referred')
      AND q.added_on >= ?
      AND q.added_on < ?
) src
{$roleJoin}
GROUP BY src.doctor_id, doctor.first_name, doctor.middle_name, doctor.last_name
EOT;
    }

    private function _specialistQueueAggSql(string $emrDb, string $typeFilter): string
    {
        return $this->_pcpClinicQueueAggSql($emrDb, $typeFilter);
    }

    /**
     * Latest EMR queue doctor per diagnostic transaction, keyed as "{branch_id}:{transaction_id}".
     *
     * @param array<int, array{branch_id: int|string, transaction_id: int|string}> $transaction_branch_pairs
     */
    public function get_emr_doctor_ids_by_transaction(array $transaction_branch_pairs): array
    {
        $grouped = [];
        foreach ($transaction_branch_pairs as $pair) {
            $branchId = (int) ($pair['branch_id'] ?? 0);
            $txnId    = (int) ($pair['transaction_id'] ?? 0);
            if ($branchId <= 0 || $txnId <= 0) {
                continue;
            }
            $grouped[$branchId][$txnId] = $txnId;
        }

        $map = [];
        foreach ($grouped as $branchId => $txnIds) {
            $emrDb = $this->_patientTatEmrDbToken($branchId);
            if ($emrDb === null || ! $txnIds) {
                continue;
            }

            $ids          = array_values($txnIds);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $sql          = <<<EOT
SELECT q.transaction_id, q.doctor_id
FROM {$emrDb}.queue q
INNER JOIN (
    SELECT transaction_id, MAX(id) AS max_id
    FROM {$emrDb}.queue
    WHERE is_deleted = 0
      AND doctor_id IS NOT NULL
      AND transaction_id IN ({$placeholders})
    GROUP BY transaction_id
) latest ON latest.max_id = q.id
EOT;
            $sql   = $this->_resolve_cross_db_sql($sql);
            $query = $this->db->query($sql, $ids);
            foreach ($query ? $query->getResultArray() : [] as $row) {
                $key       = $branchId . ':' . (int) $row['transaction_id'];
                $map[$key] = (int) $row['doctor_id'];
            }
        }

        return $map;
    }

    public function get_medrecord($branch_id = null, $company_id = null, $endorsement_id = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null)
    {
        $key   = $this->encryption_key;
        $binds = [];
        $sql   = <<<EOT
SELECT medrecord.*,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    lab_test_significant.name AS significant_findings_names,
    lab_test_specimen.name AS pending_specimen_names,
    lab_test_result.name AS pending_result_names,
    lab_test_recollection.name AS recollection_lab_test_name
FROM medrecord
LEFT JOIN {MASTER_DB}.patient patient ON patient.id = medrecord.patient_id
LEFT JOIN {MASTER_DB}.lab_test lab_test_significant
    ON lab_test_significant.id = medrecord.significant_findings
    AND lab_test_significant.is_deleted = 0
LEFT JOIN {MASTER_DB}.lab_test lab_test_specimen
    ON lab_test_specimen.id = medrecord.pending_specimen
    AND lab_test_specimen.is_deleted = 0
LEFT JOIN {MASTER_DB}.lab_test lab_test_result
    ON lab_test_result.id = medrecord.pending_result
    AND lab_test_result.is_deleted = 0
LEFT JOIN {MASTER_DB}.lab_test lab_test_recollection
    ON lab_test_recollection.id = medrecord.recollection
    AND lab_test_recollection.is_deleted = 0
WHERE medrecord.is_deleted = 0
AND (
    medrecord.branch_id != 1
    OR EXISTS (
        SELECT 1
        FROM {MOBILE_DB}.`transaction` mobile_txn
        WHERE mobile_txn.id = medrecord.transaction_id
          AND mobile_txn.is_deleted = 0
          AND IFNULL(mobile_txn.is_worklist, 0) = 0
    )
)
EOT;

        if ($branch_id) {
            $sql .= <<<EOT

AND medrecord.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($company_id) {
            $sql .= <<<EOT

AND medrecord.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($endorsement_id) {
            $sql .= <<<EOT

AND medrecord.endorsement_id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND medrecord.transaction_date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($term) {
            $sql .= <<<EOT

AND (
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) LIKE ?
)
EOT;
            $binds[] = "%$term%";
        }

        //order by patient.last_name, patient.first_name, patient.middle_name, patient.suffix
        $sql .= <<<EOT

ORDER BY TRIM(CONCAT(
    CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
    IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), '')
)) ASC
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_sales_census($branch_id = null, $tat_date = null, $service_id = null, $parent_category_id = null, $category_id = null, $group_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $where = 'WHERE sc.is_deleted = 0';

        if ($branch_id) {
            $where .= ' AND sc.branch_id = ?';
            $binds[] = $branch_id;
        }

        if ($tat_date) {
            $where .= ' AND sc.tat_date = ?';
            $binds[] = $tat_date;
        }

        if ($service_id) {
            $where .= ' AND sc.service_id = ?';
            $binds[] = $service_id;
        }

        if ($parent_category_id) {
            $where .= ' AND ct.category_id = ?';
            $binds[] = $parent_category_id;
        }

        if ($category_id) {
            $where .= ' AND lt.category_id = ?';
            $binds[] = $category_id;
        }

        if ($group_id) {
            $where .= ' AND lt.group_id = ?';
            $binds[] = $group_id;
        }

        $limit = '';
        if ($page && $data_size) {
            $offset = ((int) $page - 1) * (int) $data_size;
            $limit  = "LIMIT $offset, $data_size";
        }

        // GROUP_CONCAT builds the services array per time slot (JSON_ARRAYAGG not available in MariaDB 10.4).
        // COUNT(*) OVER() computes the total slot count in the same pass — no separate count query needed.
        $sql = <<<EOT
SELECT
    time_from,
    time_to,
    `date`,
    services,
    total
FROM (
    SELECT
        sc.time_from,
        sc.time_to,
        sc.tat_date AS `date`,
        CONCAT('[', GROUP_CONCAT(
            JSON_OBJECT(
                'service_id',   CAST(sc.service_id  AS CHAR),
                'service_name', lt.name,
                'total_sales',  CAST(sc.total_sales AS CHAR),
                'total_count',  CAST(sc.count       AS CHAR)
            ) ORDER BY sc.service_id ASC SEPARATOR ','
        ), ']') AS services,
        COUNT(*) OVER() AS total
    FROM sales_census sc
    LEFT JOIN {MASTER_DB}.lab_test lt ON lt.id = sc.service_id
    LEFT JOIN {MASTER_DB}.category ct ON ct.id = lt.category_id
    $where
    GROUP BY sc.tat_date, sc.time_from, sc.time_to
    ORDER BY sc.tat_date ASC, sc.time_from ASC
) AS grouped
$limit
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        if (! $query) {
            return false;
        }

        $rows = $query->getResultArray();
        if (empty($rows)) {
            return false;
        }

        $total = (int) $rows[0]['total'];
        foreach ($rows as &$row) {
            $row['services'] = json_decode($row['services'], true) ?: [];
            unset($row['total']);
        }

        return ['total' => $total, 'rows' => $rows];
    }

    public function get_sendout_census($branch_id = null, $date = null, $service_id = null, $facility_id = null, $term = null, $supplier_id = null, $page = null, $data_size = null, $unassigned_only = false, $date_from = null, $date_to = null)
    {
        $key      = $this->encryption_key;
        $branches = $this->_serviceTatBranchDbTokens($branch_id);
        if (!$branches) {
            return [];
        }

        $patientNameSql = <<<EOT
TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '{$key}') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '{$key}') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '{$key}') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '{$key}') AS CHAR), '')
    ))
EOT;

        $unions = [];
        foreach ($branches as $id => $dbToken) {
            $unions[] = <<<EOT
SELECT sendout_census.id,
    sendout_census.date,
    sendout_census.branch_id,
    sendout_census.facility_id,
    sendout_census.transaction_id,
    transc.transaction_no,
    sendout_census.channel,
    sendout_census.channel_id,
    sendout_census.service_id,
    sendout_census.total_sales,
    sendout_census.total_expense,
    sendout_census.added_by,
    sendout_census.added_on,
    sendout_census.updated_by,
    sendout_census.updated_on,
    booking.patient_id,
    {$patientNameSql} AS patient_name
FROM sendout_census
LEFT JOIN {$dbToken}.booking AS booking
    ON booking.id = (
        SELECT MIN(booking_one.id)
        FROM {$dbToken}.booking AS booking_one
        WHERE booking_one.transaction_id = sendout_census.transaction_id
          AND booking_one.is_deleted = 0
    )
LEFT JOIN {$dbToken}.patient AS patient
    ON patient.id = booking.patient_id
LEFT JOIN {$dbToken}.transaction AS transc
    ON transc.id = sendout_census.transaction_id
WHERE sendout_census.is_deleted = 0
AND sendout_census.branch_id = {$id}
EOT;
        }

        $sql = 'SELECT census.id,
    census.date,
    census.branch_id,
    branch.name AS branch_name,
    census.facility_id,
    facility.name AS facility_name,
    facility.supplier_id,
    census.transaction_id,
    census.transaction_no,
    census.channel,
    census.channel_id,
    census.service_id AS lab_test_id,
    lab_test.name AS lab_test_name,
    census.patient_id,
    census.patient_name,
    census.added_by,
    census.added_on,
    census.updated_by,
    census.updated_on,
    census.total_sales,
    census.total_expense
FROM (
' . implode("\nUNION ALL\n", $unions) . '
) AS census
LEFT JOIN {MASTER_DB}.branch AS branch ON branch.id = census.branch_id
LEFT JOIN {MASTER_DB}.facility AS facility ON facility.id = census.facility_id AND facility.is_deleted = 0
LEFT JOIN {MASTER_DB}.lab_test AS lab_test ON lab_test.id = census.service_id
WHERE 1 = 1';

        $binds = [];

        if ($date) {
            $sql .= <<<EOT

AND census.date = ?
EOT;
            $binds[] = $date;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND census.date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($service_id) {
            $sql .= <<<EOT

AND census.service_id = ?
EOT;
            $binds[] = $service_id;
        }

        if ($facility_id) {
            $sql .= <<<EOT

AND census.facility_id = ?
EOT;
            $binds[] = $facility_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND facility.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($unassigned_only) {
            $sql .= $this->_sendoutCensusUnassignedSql();
        }

        if ($term !== null && $term !== '') {
            $sql .= <<<EOT

AND (
    census.patient_name LIKE ?
    OR census.patient_id LIKE ?
)
EOT;
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        $sql .= "\nORDER BY census.date DESC, census.id DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_inter_branch_census($branch_id_from = null, $branch_id_to = null, $tat_date = null, $service_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql   = <<<EOT
SELECT inter_branch_census.tat_date,
    inter_branch_census.branch_id_from,
    branch_from.name AS branch_name_from,
    inter_branch_census.branch_id_to,
    branch_to.name AS branch_name_to,
    inter_branch_census.service_id AS lab_test_id,
    lab_test.name AS lab_test_name,
    SUM(inter_branch_census.total_sales) AS total_sales,
    SUM(inter_branch_census.total_expense) AS total_expense,
    SUM(inter_branch_census.count) AS total_count
FROM inter_branch_census
LEFT JOIN {MASTER_DB}.branch AS branch_from ON branch_from.id = inter_branch_census.branch_id_from
LEFT JOIN {MASTER_DB}.branch AS branch_to ON branch_to.id = inter_branch_census.branch_id_to
LEFT JOIN {MASTER_DB}.lab_test AS lab_test ON lab_test.id = inter_branch_census.service_id
WHERE inter_branch_census.is_deleted = 0
EOT;

        if ($branch_id_from) {
            $sql .= <<<EOT

AND inter_branch_census.branch_id_from = ?
EOT;
            $binds[] = $branch_id_from;
        }

        if ($branch_id_to) {
            $sql .= <<<EOT

AND inter_branch_census.branch_id_to = ?
EOT;
            $binds[] = $branch_id_to;
        }

        if ($tat_date) {
            $sql .= <<<EOT

AND inter_branch_census.tat_date = ?
EOT;
            $binds[] = $tat_date;
        }

        if ($service_id) {
            $sql .= <<<EOT

AND inter_branch_census.service_id = ?
EOT;
            $binds[] = $service_id;
        }

        $sql .= " GROUP BY inter_branch_census.tat_date, inter_branch_census.branch_id_from, inter_branch_census.branch_id_to, inter_branch_census.service_id";

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_service_census($branch_id = null, $tat_date = null, $service_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql   = <<<EOT
SELECT
    service_census.tat_date,
    service_census.time_from,
    service_census.time_to,
    service_census.service_id,
    service_census.category_id,
    service_census.subcategory_id,
    service_census.old,
    service_census.new
FROM service_census
WHERE service_census.is_deleted = 0
EOT;

        if ($branch_id) {
            $sql .= "\nAND service_census.branch_id = ?";
            $binds[] = $branch_id;
        }

        if ($tat_date) {
            $sql .= "\nAND service_census.tat_date = ?";
            $binds[] = $tat_date;
        }

        if ($service_id) {
            $sql .= "\nAND service_census.service_id = ?";
            $binds[] = $service_id;
        }

        $sql .= "\nORDER BY service_census.tat_date, service_census.time_from";

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        if (! $query) {
            return false;
        }

        $rows = $query->getResultArray();
        if (empty($rows)) {
            return [];
        }

        // Collect unique service IDs
        $service_ids  = array_unique(array_column($rows, 'service_id'));
        $placeholders = implode(',', array_fill(0, count($service_ids), '?'));

        // Flattened JOIN — no more subquery
        $master_db = db_connect('master');
        $lab_query = $master_db->query(
            "SELECT
            lt.id,
            lt.name,
            sub.name       AS subcategory_name,
            parent.name    AS category_name
        FROM lab_test lt
        LEFT JOIN category sub    ON sub.id = lt.category_id    AND sub.is_deleted = 0
        LEFT JOIN category parent ON parent.id = sub.category_id
        WHERE lt.id IN ($placeholders)",
            $service_ids
        );

        $service_map = [];
        if ($lab_query) {
            foreach ($lab_query->getResultArray() as $lab) {
                $service_map[$lab['id']] = [
                    'service_name'     => $lab['name'],
                    'subcategory_name' => $lab['subcategory_name'],
                    'category_name'    => $lab['category_name'],
                ];
            }
        }

        // Group by date + time range
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['tat_date'] . '_' . $row['time_from'] . '_' . $row['time_to'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'time_from' => $row['time_from'],
                    'time_to'   => $row['time_to'],
                    'date'      => $row['tat_date'],
                    'services'  => [],
                ];
            }

            $map = $service_map[$row['service_id']] ?? [];

            $grouped[$key]['services'][] = [
                'service_id'       => $row['service_id'],
                'service_name'     => $map['service_name'] ?? null,
                'category_id'      => $row['category_id'],
                'subcategory_id'   => $row['subcategory_id'],
                'subcategory_name' => $map['subcategory_name'] ?? null,
                'category_name'    => $map['category_name'] ?? null,
                'old'              => $row['old'],
                'new'              => $row['new'],
            ];
        }

        return array_values($grouped);
    }

    public function sales_census_report()
    {
        $sql = <<<EOT
INSERT IGNORE INTO {REPORT_DB}.sales_census (
    branch_id,
    tat_date,
    time_from,
    time_to,
    service_id,
    added_on,
    is_deleted
)
SELECT
    b.branch_id     AS branch_id,
    CURDATE()       AS tat_date,
    hrs.time_from   AS time_from,
    hrs.time_to     AS time_to,
    lt.id           AS service_id,
    NOW()           AS added_on,
    0               AS is_deleted
FROM (
    SELECT 1 AS branch_id UNION ALL
    SELECT 2               UNION ALL
    SELECT 3               UNION ALL
    SELECT 4               UNION ALL
    SELECT 7
) b
CROSS JOIN {MASTER_DB}.lab_test lt
CROSS JOIN (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs
WHERE lt.is_deleted = 0
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function inter_branch_census_report()
    {
        $sql = <<<EOT
INSERT IGNORE INTO {REPORT_DB}.inter_branch_census (
    branch_id_from,
    branch_id_to,
    tat_date,
    time_from,
    time_to,
    service_id,
    added_on,
    is_deleted
)
SELECT
    b_from.branch_id AS branch_id_from,
    b_to.branch_id   AS branch_id_to,
    CURDATE()        AS tat_date,
    hrs.time_from    AS time_from,
    hrs.time_to      AS time_to,
    lt.id            AS service_id,
    NOW()            AS added_on,
    0                AS is_deleted
FROM (
    SELECT 1 AS branch_id UNION ALL
    SELECT 2               UNION ALL
    SELECT 3               UNION ALL
    SELECT 4               UNION ALL
    SELECT 7
) b_from
CROSS JOIN (
    SELECT 1 AS branch_id UNION ALL
    SELECT 2               UNION ALL
    SELECT 3               UNION ALL
    SELECT 4               UNION ALL
    SELECT 7
) b_to
CROSS JOIN {MASTER_DB}.lab_test lt
CROSS JOIN (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs
WHERE b_from.branch_id != b_to.branch_id
AND lt.is_deleted = 0
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function service_census_report()
    {
        $sql = <<<EOT
INSERT IGNORE INTO {REPORT_DB}.service_census (
    branch_id,
    tat_date,
    time_from,
    time_to,
    service_id,
    category_id,
    subcategory_id,
    added_on,
    is_deleted
)
SELECT
    b.branch_id     AS branch_id,
    CURDATE()       AS tat_date,
    hrs.time_from   AS time_from,
    hrs.time_to     AS time_to,
    lt.id           AS service_id,
    CASE
        WHEN cat.category_id IS NULL THEN cat.id
        ELSE cat.category_id
    END             AS category_id,
    cat.id          AS subcategory_id,
    NOW()           AS added_on,
    0               AS is_deleted
FROM (
    SELECT 1 AS branch_id UNION ALL
    SELECT 2               UNION ALL
    SELECT 3               UNION ALL
    SELECT 4               UNION ALL
    SELECT 7
) b
CROSS JOIN {MASTER_DB}.lab_test lt
LEFT JOIN {MASTER_DB}.category cat ON cat.id = lt.category_id
CROSS JOIN (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs
WHERE lt.is_deleted = 0
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    private function _medrecord_lre_exists_sql(int $branchId, string $sourceIdExpr, string $sourceTable): string
    {
        return <<<EOT
(
                    SELECT 1 FROM {OAKRIDGE_EMR_DB}.lab_result_evaluation lre WHERE lre.source_id = {$sourceIdExpr} AND lre.source_table = '{$sourceTable}' AND lre.branch_id = {$branchId} AND lre.is_deleted = 0
                    UNION ALL
                    SELECT 1 FROM {MAC_EMR_DB}.lab_result_evaluation lre WHERE lre.source_id = {$sourceIdExpr} AND lre.source_table = '{$sourceTable}' AND lre.branch_id = {$branchId} AND lre.is_deleted = 0
                    UNION ALL
                    SELECT 1 FROM {CONSO_EMR_DB}.lab_result_evaluation lre WHERE lre.source_id = {$sourceIdExpr} AND lre.source_table = '{$sourceTable}' AND lre.branch_id = {$branchId} AND lre.is_deleted = 0
                    UNION ALL
                    SELECT 1 FROM {MANTLEWOOD_EMR_DB}.lab_result_evaluation lre WHERE lre.source_id = {$sourceIdExpr} AND lre.source_table = '{$sourceTable}' AND lre.branch_id = {$branchId} AND lre.is_deleted = 0
                )
EOT;
    }

    private function _build_medrecord_insert_sql(int $branchId, string $branchDbToken, string $txnDate): string
    {
        $lreBd  = $this->_medrecord_lre_exists_sql($branchId, 'bd.id', 'booking_details');
        $lreBpd = $this->_medrecord_lre_exists_sql($branchId, 'bpd.id', 'booking_package_details');
        $lreBd2 = $this->_medrecord_lre_exists_sql($branchId, 'bd2.id', 'booking_details');
        $lreBpd2 = $this->_medrecord_lre_exists_sql($branchId, 'bpd2.id', 'booking_package_details');
        $lreBdX = $this->_medrecord_lre_exists_sql($branchId, 'bd_x.id', 'booking_details');
        $lreBpdX = $this->_medrecord_lre_exists_sql($branchId, 'bpd_x.id', 'booking_package_details');

        $labTestId = "CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END";

        // Inter-branch: skip transferred-out lines (new_transaction_id set).
        // Recollection = same-branch parent only (exclude parent_branch_id from another branch).
        $missingEvalBd = <<<EOT
NOT EXISTS (
                SELECT 1 FROM {$branchDbToken}.booking_details bd2
                WHERE bd2.booking_id = b.id
                  AND bd2.type != 'package'
                  AND bd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND NOT EXISTS {$lreBd2}
            )
            AND NOT EXISTS (
                SELECT 1 FROM {$branchDbToken}.booking_package_details bpd2
                JOIN {$branchDbToken}.booking_details bd2
                    ON bd2.id = bpd2.booking_detail_id
                WHERE bd2.booking_id = b.id
                  AND bpd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND NOT EXISTS {$lreBpd2}
            )
EOT;

        $notFinalizedBd = <<<EOT
NOT EXISTS (
                SELECT 1 FROM {$branchDbToken}.booking_details bd2
                WHERE bd2.booking_id = b.id
                  AND bd2.type != 'package'
                  AND bd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND (
                    bd2.status IN ('pending', 'to follow')
                    OR NOT (bd2.status = 'waived' OR bd2.result_status = 'finalized')
                  )
            )
            AND NOT EXISTS (
                SELECT 1 FROM {$branchDbToken}.booking_package_details bpd2
                JOIN {$branchDbToken}.booking_details bd2
                    ON bd2.id = bpd2.booking_detail_id
                WHERE bd2.booking_id = b.id
                  AND bpd2.is_deleted = 0
                  AND bd2.new_transaction_id IS NULL
                  AND (
                    bpd2.status IN ('pending', 'to follow')
                    OR NOT (bpd2.status = 'waived' OR bpd2.result_status = 'finalized')
                  )
            )
EOT;

        $significantCond = <<<EOT
(
                (bd.type != 'package'
                    AND bd.new_transaction_id IS NULL
                    AND EXISTS {$lreBd})
                OR (bd.type = 'package'
                    AND bd.new_transaction_id IS NULL
                    AND EXISTS {$lreBpd})
            )
EOT;

        $pendingResultCond = <<<EOT
(
                (bd.type != 'package'
                    AND bd.new_transaction_id IS NULL
                    AND bd.status NOT IN ('waived', 'pending')
                    AND (bd.result_status IS NULL OR bd.result_status != 'finalized')
                    AND bd.is_deleted = 0)
                OR (bd.type = 'package'
                    AND bd.new_transaction_id IS NULL
                    AND bpd.status NOT IN ('waived', 'pending')
                    AND (bpd.result_status IS NULL OR bpd.result_status != 'finalized')
                    AND bpd.is_deleted = 0)
            )
EOT;

        $pendingSpecimenCond = <<<EOT
(
                (bd.type != 'package'
                    AND bd.new_transaction_id IS NULL
                    AND bd.status IN ('pending', 'to follow')
                    AND bd.is_deleted = 0)
                OR (bd.type = 'package'
                    AND bd.new_transaction_id IS NULL
                    AND bpd.status IN ('pending', 'to follow')
                    AND bpd.is_deleted = 0)
            )
EOT;

        $recollectionCond = <<<EOT
(
                (bd.type != 'package'
                    AND bd.parent_id IS NOT NULL
                    AND (bd.parent_branch_id IS NULL OR bd.parent_branch_id = {$branchId})
                    AND bd.new_transaction_id IS NULL
                    AND bd.is_deleted = 0)
                OR (bd.type = 'package'
                    AND bpd.parent_id IS NOT NULL
                    AND (bpd.parent_branch_id IS NULL OR bpd.parent_branch_id = {$branchId})
                    AND bd.new_transaction_id IS NULL
                    AND bpd.is_deleted = 0)
            )
EOT;

        $anyBucketExists = <<<EOT
EXISTS (
                SELECT 1
                FROM {$branchDbToken}.booking_details bd_x
                LEFT JOIN {$branchDbToken}.booking_package_details bpd_x
                    ON bpd_x.booking_detail_id = bd_x.id
                   AND bpd_x.is_deleted = 0
                WHERE bd_x.booking_id = b.id
                  AND bd_x.is_deleted = 0
                  AND bd_x.new_transaction_id IS NULL
                  AND (
                    (bd_x.type != 'package' AND EXISTS {$lreBdX})
                    OR (bd_x.type = 'package' AND EXISTS {$lreBpdX})
                    OR (bd_x.type != 'package'
                        AND bd_x.status NOT IN ('waived', 'pending')
                        AND (bd_x.result_status IS NULL OR bd_x.result_status != 'finalized'))
                    OR (bd_x.type = 'package'
                        AND bpd_x.status NOT IN ('waived', 'pending')
                        AND (bpd_x.result_status IS NULL OR bpd_x.result_status != 'finalized')
                        AND bpd_x.is_deleted = 0)
                    OR (bd_x.type != 'package' AND bd_x.status IN ('pending', 'to follow'))
                    OR (bd_x.type = 'package' AND bpd_x.status IN ('pending', 'to follow') AND bpd_x.is_deleted = 0)
                    OR (bd_x.type != 'package'
                        AND bd_x.parent_id IS NOT NULL
                        AND (bd_x.parent_branch_id IS NULL OR bd_x.parent_branch_id = {$branchId}))
                    OR (bd_x.type = 'package'
                        AND bpd_x.parent_id IS NOT NULL
                        AND (bpd_x.parent_branch_id IS NULL OR bpd_x.parent_branch_id = {$branchId})
                        AND bpd_x.is_deleted = 0)
                  )
            )
EOT;

        $performedOnSub = <<<EOT
(
        SELECT NULLIF(
            GREATEST(
                IFNULL(MAX(bd_po.performed_on), '1970-01-01'),
                IFNULL(MAX(bpd_po.performed_on), '1970-01-01')
            ),
            '1970-01-01'
        )
        FROM {$branchDbToken}.booking_details bd_po
        LEFT JOIN {$branchDbToken}.booking_package_details bpd_po
            ON bpd_po.booking_detail_id = bd_po.id
           AND bpd_po.is_deleted = 0
        WHERE bd_po.booking_id = b.id
          AND bd_po.is_deleted = 0
    )
EOT;

        // Inter-branch dest: inherit source endorsement when local txn endorsement is null/0.
        // Use scalar correlated subqueries only — MySQL does not correlate into derived tables.
        $localBdEndorsement = <<<EOT
(
        SELECT cbd.endorsement_id
        FROM {$branchDbToken}.booking_details cbd
        WHERE cbd.booking_id = b.id
          AND cbd.is_deleted = 0
          AND cbd.endorsement_id IS NOT NULL
          AND cbd.endorsement_id != 0
        LIMIT 1
    )
EOT;

        $parentEndorsementMobile = <<<EOT
(
        SELECT pt.endorsement_id
        FROM {$branchDbToken}.booking_details cbd
        INNER JOIN {MOBILE_DB}.booking_details pbd
            ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
        INNER JOIN {MOBILE_DB}.booking pb
            ON pb.id = pbd.booking_id AND pb.is_deleted = 0
        INNER JOIN {MOBILE_DB}.`transaction` pt
            ON pt.id = pb.transaction_id AND pt.is_deleted = 0
        WHERE cbd.booking_id = b.id
          AND cbd.is_deleted = 0
          AND cbd.parent_id IS NOT NULL
          AND cbd.parent_branch_id = 1
          AND pt.endorsement_id IS NOT NULL
          AND pt.endorsement_id != 0
        LIMIT 1
    )
EOT;

        $parentEndorsementOakridge = <<<EOT
(
        SELECT pt.endorsement_id
        FROM {$branchDbToken}.booking_details cbd
        INNER JOIN {OAKRIDGE_DB}.booking_details pbd
            ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
        INNER JOIN {OAKRIDGE_DB}.booking pb
            ON pb.id = pbd.booking_id AND pb.is_deleted = 0
        INNER JOIN {OAKRIDGE_DB}.`transaction` pt
            ON pt.id = pb.transaction_id AND pt.is_deleted = 0
        WHERE cbd.booking_id = b.id
          AND cbd.is_deleted = 0
          AND cbd.parent_id IS NOT NULL
          AND cbd.parent_branch_id = 2
          AND pt.endorsement_id IS NOT NULL
          AND pt.endorsement_id != 0
        LIMIT 1
    )
EOT;

        $parentEndorsementMac = <<<EOT
(
        SELECT pt.endorsement_id
        FROM {$branchDbToken}.booking_details cbd
        INNER JOIN {MAC_DB}.booking_details pbd
            ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
        INNER JOIN {MAC_DB}.booking pb
            ON pb.id = pbd.booking_id AND pb.is_deleted = 0
        INNER JOIN {MAC_DB}.`transaction` pt
            ON pt.id = pb.transaction_id AND pt.is_deleted = 0
        WHERE cbd.booking_id = b.id
          AND cbd.is_deleted = 0
          AND cbd.parent_id IS NOT NULL
          AND cbd.parent_branch_id = 3
          AND pt.endorsement_id IS NOT NULL
          AND pt.endorsement_id != 0
        LIMIT 1
    )
EOT;

        $parentEndorsementConso = <<<EOT
(
        SELECT pt.endorsement_id
        FROM {$branchDbToken}.booking_details cbd
        INNER JOIN {CONSO_DB}.booking_details pbd
            ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
        INNER JOIN {CONSO_DB}.booking pb
            ON pb.id = pbd.booking_id AND pb.is_deleted = 0
        INNER JOIN {CONSO_DB}.`transaction` pt
            ON pt.id = pb.transaction_id AND pt.is_deleted = 0
        WHERE cbd.booking_id = b.id
          AND cbd.is_deleted = 0
          AND cbd.parent_id IS NOT NULL
          AND cbd.parent_branch_id = 4
          AND pt.endorsement_id IS NOT NULL
          AND pt.endorsement_id != 0
        LIMIT 1
    )
EOT;

        $parentEndorsementMantlewood = <<<EOT
(
        SELECT pt.endorsement_id
        FROM {$branchDbToken}.booking_details cbd
        INNER JOIN {MANTLEWOOD_DB}.booking_details pbd
            ON pbd.id = cbd.parent_id AND pbd.is_deleted = 0
        INNER JOIN {MANTLEWOOD_DB}.booking pb
            ON pb.id = pbd.booking_id AND pb.is_deleted = 0
        INNER JOIN {MANTLEWOOD_DB}.`transaction` pt
            ON pt.id = pb.transaction_id AND pt.is_deleted = 0
        WHERE cbd.booking_id = b.id
          AND cbd.is_deleted = 0
          AND cbd.parent_id IS NOT NULL
          AND cbd.parent_branch_id = 7
          AND pt.endorsement_id IS NOT NULL
          AND pt.endorsement_id != 0
        LIMIT 1
    )
EOT;

        $effectiveEndorsement = <<<EOT
COALESCE(
        NULLIF(t.endorsement_id, 0),
        {$localBdEndorsement},
        {$parentEndorsementMobile},
        {$parentEndorsementOakridge},
        {$parentEndorsementMac},
        {$parentEndorsementConso},
        {$parentEndorsementMantlewood},
        t.endorsement_id
    )
EOT;

        $forReleaseLog = <<<EOT
CASE
        WHEN {$effectiveEndorsement} IS NOT NULL AND {$effectiveEndorsement} != 0
            AND {$missingEvalBd} THEN (
            SELECT GROUP_CONCAT(DISTINCT logs.log_id ORDER BY logs.log_id SEPARATOR ',')
            FROM (
                SELECT el.id AS log_id, el.booking_id
                FROM {$branchDbToken}.email_log el
                UNION ALL
                SELECT pl.id AS log_id, pl.booking_id
                FROM {$branchDbToken}.print_log pl
            ) logs
            WHERE logs.booking_id = b.id
        )
        ELSE NULL
    END
EOT;

        $worklistFilter = $branchId === 1
            ? "\n  AND IFNULL(t.is_worklist, 0) = 0"
            : '';

        $fromJoins = <<<EOT
FROM {$branchDbToken}.transaction t
INNER JOIN {$branchDbToken}.booking b
    ON b.transaction_id = t.id
   AND b.is_deleted = 0
INNER JOIN {$branchDbToken}.patient p
    ON p.id = b.patient_id
   AND p.master_id IS NOT NULL
INNER JOIN {$branchDbToken}.booking_details bd
    ON bd.booking_id = b.id
   AND bd.is_deleted = 0
LEFT JOIN {$branchDbToken}.booking_package_details bpd
    ON bpd.booking_detail_id = bd.id
   AND bpd.is_deleted = 0
WHERE t.is_deleted = 0
  AND DATE(t.transaction_date) = {$this->db->escape($txnDate)}{$worklistFilter}
EOT;

        $companyIdExpr = 'COALESCE(bd.charge_to, b.company_id, 0)';

        $bookingSelectPrefix = <<<EOT
    {$branchId}                                 AS branch_id,
    {$companyIdExpr}                            AS company_id,
    t.id                                        AS transaction_id,
    b.id                                        AS booking_id,
    MAX(bd.id)                                  AS booking_details_id,
    MAX(CASE WHEN bd.type = 'package' THEN bpd.id END)
                                                AS booking_package_details_id,
    {$effectiveEndorsement}                     AS endorsement_id,
    t.transaction_date                          AS transaction_date,
    t.added_on                                  AS transaction_date_time,
    {$performedOnSub}                           AS performed_on,
    p.master_id                                 AS patient_id
EOT;

        $evalReleaseSelect = <<<EOT
    CASE
        WHEN {$effectiveEndorsement} IS NULL OR {$effectiveEndorsement} = 0 THEN 3
        WHEN {$missingEvalBd} THEN 2
        WHEN {$notFinalizedBd} THEN 1
        ELSE 0
    END                                         AS for_evaluation,
    CASE
        WHEN ({$effectiveEndorsement} IS NULL OR {$effectiveEndorsement} = 0)
            AND {$notFinalizedBd} THEN 1
        WHEN {$effectiveEndorsement} IS NOT NULL AND {$effectiveEndorsement} != 0
            AND {$missingEvalBd} THEN 2
        ELSE 0
    END                                         AS for_release,
    {$forReleaseLog}                            AS for_release_log,
    NOW()                                       AS added_on,
    0                                           AS is_deleted,
    0                                           AS is_force_evaluate,
    NULL                                        AS force_evaluate_by,
    NULL                                        AS force_evaluate_on
EOT;

        return <<<EOT
INSERT INTO {REPORT_DB}.medrecord (
    branch_id,
    company_id,
    transaction_id,
    booking_id,
    booking_details_id,
    booking_package_details_id,
    endorsement_id,
    transaction_date,
    transaction_date_time,
    performed_on,
    patient_id,
    lab_test_id,
    significant_findings,
    pending_result,
    pending_specimen,
    recollection,
    for_evaluation,
    for_release,
    for_release_log,
    added_on,
    is_deleted,
    is_force_evaluate,
    force_evaluate_by,
    force_evaluate_on
)
SELECT
    {$bookingSelectPrefix},
    {$labTestId}                                AS lab_test_id,
    MAX(CASE WHEN {$significantCond} THEN {$labTestId} END)
                                                AS significant_findings,
    MAX(CASE WHEN {$pendingResultCond} THEN {$labTestId} END)
                                                AS pending_result,
    MAX(CASE WHEN {$pendingSpecimenCond} THEN {$labTestId} END)
                                                AS pending_specimen,
    MAX(CASE WHEN {$recollectionCond} THEN {$labTestId} END)
                                                AS recollection,
    {$evalReleaseSelect}
{$fromJoins}
  AND {$labTestId} IS NOT NULL
GROUP BY
    b.id,
    t.id,
    p.master_id,
    {$effectiveEndorsement},
    {$companyIdExpr},
    t.transaction_date,
    t.added_on,
    {$labTestId}
HAVING
    significant_findings IS NOT NULL
    OR pending_result IS NOT NULL
    OR pending_specimen IS NOT NULL
    OR recollection IS NOT NULL

UNION ALL

SELECT
    {$bookingSelectPrefix},
    NULL                                        AS lab_test_id,
    NULL                                        AS significant_findings,
    NULL                                        AS pending_result,
    NULL                                        AS pending_specimen,
    NULL                                        AS recollection,
    {$evalReleaseSelect}
{$fromJoins}
  AND NOT {$anyBucketExists}
GROUP BY
    b.id,
    t.id,
    p.master_id,
    {$effectiveEndorsement},
    {$companyIdExpr},
    t.transaction_date,
    t.added_on

ON DUPLICATE KEY UPDATE
    company_id             = VALUES(company_id),
    transaction_id         = VALUES(transaction_id),
    booking_id             = VALUES(booking_id),
    booking_details_id     = VALUES(booking_details_id),
    booking_package_details_id = VALUES(booking_package_details_id),
    endorsement_id         = VALUES(endorsement_id),
    transaction_date_time  = VALUES(transaction_date_time),
    performed_on           = VALUES(performed_on),
    significant_findings   = VALUES(significant_findings),
    pending_result         = VALUES(pending_result),
    pending_specimen       = VALUES(pending_specimen),
    recollection           = VALUES(recollection),
    for_evaluation         = IF(medrecord.is_force_evaluate IN (1, 2), medrecord.for_evaluation, VALUES(for_evaluation)),
    for_release            = IF(medrecord.is_force_evaluate IN (1, 2), medrecord.for_release, VALUES(for_release)),
    for_release_log        = IF(medrecord.is_force_evaluate IN (1, 2), medrecord.for_release_log, VALUES(for_release_log)),
    added_on               = VALUES(added_on),
    is_deleted             = VALUES(is_deleted),
    is_force_evaluate      = medrecord.is_force_evaluate,
    force_evaluate_by      = medrecord.force_evaluate_by,
    force_evaluate_on      = medrecord.force_evaluate_on
EOT;
    }

    private const MEDRECORD_REFRESH_DEBOUNCE = 30;

    private const MEDRECORD_REFRESH_BRANCHES = [
        1 => 'medrecord_mobile',
        2 => 'medrecord_oakridge',
        3 => 'medrecord_mactan',
        4 => 'medrecord_consolacion',
        7 => 'medrecord_mantlewood',
    ];

    /**
     * Normalize medrecord ETL transaction date (Y-m-d). Defaults to today.
     */
    private function _medrecord_txn_date(?string $txnDate): string
    {
        return $this->_medrecord_parse_date($txnDate) ?? date('Y-m-d');
    }

    /**
     * Queue a scoped background rebuild (today/yesterday only). Does not run the ETL.
     */
    public function queue_medrecord_refresh(?int $branchId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $branches = $this->_medrecord_refresh_branches($branchId);
        $dates    = $this->_medrecord_refresh_dates($dateFrom, $dateTo, true);
        $queued   = [];
        $skipped  = [];

        foreach ($branches as $id) {
            foreach ($dates as $date) {
                if ($this->_medrecord_refresh_is_fresh($id, $date)) {
                    $skipped[] = ['branch_id' => $id, 'date' => $date, 'reason' => 'debounce'];
                    continue;
                }

                $this->_spawn_medrecord_refresh($id, $date);
                $queued[] = ['branch_id' => $id, 'date' => $date];
            }
        }

        return [
            'queued'   => $queued !== [],
            'branches' => array_values(array_unique(array_column($queued, 'branch_id'))),
            'dates'    => array_values(array_unique(array_column($queued, 'date'))),
            'skipped'  => $skipped,
        ];
    }

    /**
     * Run the medrecord ETL for the resolved branch/date targets (synchronous).
     * List/on-demand callers should use queue_medrecord_refresh() instead.
     */
    public function refresh_medrecord(?int $branchId = null, ?string $dateFrom = null, ?string $dateTo = null, bool $clampRecent = true, bool $force = false): array
    {
        $ran     = [];
        $skipped = [];

        foreach ($this->_medrecord_refresh_branches($branchId) as $id) {
            foreach ($this->_medrecord_refresh_dates($dateFrom, $dateTo, $clampRecent) as $date) {
                $status = $this->refresh_medrecord_target($id, $date, $force);
                $entry  = ['branch_id' => $id, 'date' => $date, 'status' => $status];
                if ($status === 'ok') {
                    $ran[] = $entry;
                } else {
                    $skipped[] = $entry;
                }
            }
        }

        return [
            'queued'   => $ran !== [],
            'branches' => array_values(array_unique(array_column($ran, 'branch_id'))),
            'dates'    => array_values(array_unique(array_column($ran, 'date'))),
            'ran'      => $ran,
            'skipped'  => $skipped,
        ];
    }

    /**
     * Rebuild one branch + date with flock so overlapping jobs do not double-DELETE.
     */
    public function refresh_medrecord_target(int $branchId, string $txnDate, bool $force = false): string
    {
        $txnDate = $this->_medrecord_txn_date($txnDate);
        $method  = self::MEDRECORD_REFRESH_BRANCHES[$branchId] ?? null;
        if (! $method) {
            return 'invalid';
        }

        $lockFile  = $this->_medrecord_refresh_lock_file($branchId, $txnDate);
        $stampFile = $this->_medrecord_refresh_stamp_file($branchId, $txnDate);
        $handle    = @fopen($lockFile, 'c+');
        if (! $handle) {
            log_message('error', "Medrecord refresh lock open failed for branch {$branchId} date {$txnDate}");
            return 'failed';
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return 'locked';
        }

        if (! $force && $this->_medrecord_refresh_is_fresh($branchId, $txnDate)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return 'debounce';
        }

        $ok = (bool) $this->{$method}($txnDate);
        if ($ok) {
            touch($stampFile);
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        return $ok ? 'ok' : 'failed';
    }

    private function _medrecord_parse_date(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = substr(trim($value), 0, 10);
        $dt    = \DateTime::createFromFormat('Y-m-d', $value);

        return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
    }

    private function _medrecord_refresh_branches(?int $branchId): array
    {
        $all = array_keys(self::MEDRECORD_REFRESH_BRANCHES);
        if ($branchId === null || $branchId === 0) {
            return $all;
        }

        return isset(self::MEDRECORD_REFRESH_BRANCHES[$branchId]) ? [$branchId] : [];
    }

    private function _medrecord_refresh_dates(?string $dateFrom, ?string $dateTo, bool $clampRecent): array
    {
        $from = $this->_medrecord_parse_date($dateFrom);
        $to   = $this->_medrecord_parse_date($dateTo);
        if (! $from && ! $to) {
            $from = $to = date('Y-m-d');
        } elseif (! $from) {
            $from = $to;
        } elseif (! $to) {
            $to = $from;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $dates  = [];
        $cursor = new \DateTime($from);
        $end    = new \DateTime($to);
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }

        if ($clampRecent) {
            $min   = date('Y-m-d', strtotime('-1 day'));
            $max   = date('Y-m-d');
            $dates = array_values(array_filter($dates, static function (string $date) use ($min, $max): bool {
                return $date >= $min && $date <= $max;
            }));
        }

        return $dates;
    }

    private function _medrecord_refresh_dir(): string
    {
        $dir = WRITEPATH . 'cache/medrecord_refresh/';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function _medrecord_refresh_lock_file(int $branchId, string $txnDate): string
    {
        return $this->_medrecord_refresh_dir() . $branchId . '_' . $txnDate . '.lock';
    }

    private function _medrecord_refresh_stamp_file(int $branchId, string $txnDate): string
    {
        return $this->_medrecord_refresh_dir() . $branchId . '_' . $txnDate . '.stamp';
    }

    private function _medrecord_refresh_is_fresh(int $branchId, string $txnDate): bool
    {
        $stampFile = $this->_medrecord_refresh_stamp_file($branchId, $txnDate);
        if (! is_file($stampFile)) {
            return false;
        }

        return (time() - (int) filemtime($stampFile)) < self::MEDRECORD_REFRESH_DEBOUNCE;
    }

    private function _spawn_medrecord_refresh(int $branchId, string $txnDate): void
    {
        $cmd = sprintf(
            'nohup %s %s medrecord:refresh %s %s >> %s 2>&1 &',
            escapeshellarg($this->_medrecord_php_cli()),
            escapeshellarg(ROOTPATH . 'spark'),
            escapeshellarg((string) $branchId),
            escapeshellarg($txnDate),
            escapeshellarg(WRITEPATH . 'logs/medrecord_refresh.log')
        );
        log_message('info', 'Medrecord refresh queued for branch ' . $branchId . ' date ' . $txnDate . ': ' . $cmd);
        exec($cmd);
    }

    /**
     * PHP_BINARY is php-fpm under the web SAPI and cannot run Spark.
     */
    private function _medrecord_php_cli(): string
    {
        $binary = PHP_BINARY ?: '';
        if ($binary !== '' && ! $this->_medrecord_is_fpm_or_cgi($binary) && $this->_medrecord_is_cli_php($binary)) {
            return $binary;
        }

        $candidates = [];
        $version    = ($binary !== '' && preg_match('/(\d+\.\d+)/', basename($binary), $m))
            ? $m[1]
            : null;
        if ($version) {
            $candidates[] = '/usr/bin/php' . $version;
        }
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/Applications/XAMPP/xamppfiles/bin/php';
        $candidates[] = '/bin/php';
        $candidates[] = 'php';

        foreach ($candidates as $path) {
            if ($path === 'php' || $this->_medrecord_is_cli_php($path)) {
                return $path;
            }
        }

        return 'php';
    }

    private function _medrecord_is_fpm_or_cgi(string $path): bool
    {
        $name = strtolower(basename($path));

        return strpos($name, 'fpm') !== false || strpos($name, 'cgi') !== false;
    }

    private function _medrecord_is_cli_php(string $path): bool
    {
        if ($path === '' || $this->_medrecord_is_fpm_or_cgi($path) || ! $this->_medrecord_is_statable_php($path)) {
            return false;
        }

        return is_file($path) && is_executable($path);
    }

    private function _medrecord_is_statable_php(string $path): bool
    {
        $allowed    = ['/usr/bin/', '/usr/local/bin/', '/bin/', '/opt/', '/Applications/XAMPP/'];
        $normalized = str_replace('\\', '/', $path) . '/';
        foreach ($allowed as $prefix) {
            if (strpos($normalized, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private function _medrecord_branch(int $branchId, string $branchDbToken, ?string $txnDate = null): bool
    {
        $txnDate = $this->_medrecord_txn_date($txnDate);

        $deleted = $this->db->query(
            'DELETE FROM medrecord WHERE branch_id = ? AND DATE(transaction_date) = ? AND is_force_evaluate = 0',
            [$branchId, $txnDate]
        );
        if ($deleted === false) {
            return false;
        }

        $sql   = $this->_build_medrecord_insert_sql($branchId, $branchDbToken, $txnDate);
        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);

        return (bool) $query;
    }

    public function medrecord_mobile(?string $txnDate = null): bool
    {
        return $this->_medrecord_branch(1, '{MOBILE_DB}', $txnDate);
    }

    public function medrecord_oakridge(?string $txnDate = null): bool
    {
        return $this->_medrecord_branch(2, '{OAKRIDGE_DB}', $txnDate);
    }

    public function medrecord_mactan(?string $txnDate = null): bool
    {
        return $this->_medrecord_branch(3, '{MAC_DB}', $txnDate);
    }

    public function medrecord_consolacion(?string $txnDate = null): bool
    {
        return $this->_medrecord_branch(4, '{CONSO_DB}', $txnDate);
    }

    public function medrecord_mantlewood(?string $txnDate = null): bool
    {
        return $this->_medrecord_branch(7, '{MANTLEWOOD_DB}', $txnDate);
    }

    private function _medrecord_branch_db_group(int $branch_id): ?string
    {
        $dbMap = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
        ];

        return $dbMap[$branch_id] ?? null;
    }

    public function get_medrecord_by_id(int $medrecord_id): ?array
    {
        $query = $this->db->query(
            'SELECT * FROM medrecord WHERE id = ? AND is_deleted = 0',
            [$medrecord_id]
        );

        if (! $query) {
            return null;
        }

        $row = $query->getRowArray();

        return $row ?: null;
    }

    public function get_for_release_log(int $branch_id, int $booking_id): ?string
    {
        $dbGroup = $this->_medrecord_branch_db_group($branch_id);
        if (! $dbGroup) {
            return null;
        }

        $branchDb = db_connect($dbGroup);
        $query    = $branchDb->query(
            <<<EOT
SELECT GROUP_CONCAT(DISTINCT logs.log_id ORDER BY logs.log_id SEPARATOR ',') AS for_release_log
FROM (
    SELECT el.id AS log_id
    FROM email_log el
    WHERE el.booking_id = ?
    UNION ALL
    SELECT pl.id AS log_id
    FROM print_log pl
    WHERE pl.booking_id = ?
) logs
EOT,
            [$booking_id, $booking_id]
        );

        if (! $query) {
            return null;
        }

        $row = $query->getRowArray();
        $log = $row['for_release_log'] ?? null;

        return $log !== null && $log !== '' ? $log : null;
    }

    public function force_evaluate(int $medrecord_id, $force_evaluate_by = null)
    {
        $medrecord = $this->get_medrecord_by_id($medrecord_id);
        if (! $medrecord) {
            return false;
        }

        $values = [
            'for_evaluation'    => 1,
            'is_force_evaluate' => 1,
            'force_evaluate_by' => $force_evaluate_by,
            'force_evaluate_on' => date('Y-m-d H:i:s'),
        ];

        $hasEndorsement = ! empty($medrecord['endorsement_id'])
            && (int) $medrecord['endorsement_id'] !== 0;

        if ($hasEndorsement) {
            $values['for_release']     = 0;
            $values['for_release_log'] = $this->get_for_release_log(
                (int) $medrecord['branch_id'],
                (int) $medrecord['booking_id']
            );
        }

        $updated = $this->db->table('medrecord')
            ->where('id', $medrecord_id)
            ->where('is_deleted', 0)
            ->update($values);

        if (! $updated) {
            return false;
        }

        $this->_force_evaluate_booking_lines($medrecord, $force_evaluate_by);

        return $this->get_medrecord_by_id($medrecord_id);
    }

    /**
     * Stamp the medrecord row's own source branch line as done/finalized.
     * booking_package_details when the row has a booking_package_details_id,
     * otherwise booking_details.
     */
    private function _force_evaluate_booking_lines(array $medrecord, $forceEvaluateBy): void
    {
        $dbGroup = $this->_medrecord_branch_db_group((int) $medrecord['branch_id']);
        if (! $dbGroup) {
            return;
        }

        $bpdId = (int) ($medrecord['booking_package_details_id'] ?? 0);
        $bdId  = (int) ($medrecord['booking_details_id'] ?? 0);

        if (! $bpdId && ! $bdId) {
            return;
        }

        $lineValues = [
            'status'            => 'done',
            'result_status'     => 'finalized',
            'force_evaluate_by' => $forceEvaluateBy,
            'force_evaluate_on' => date('Y-m-d H:i:s'),
        ];

        $branchDb = db_connect($dbGroup);

        if ($bpdId) {
            $branchDb->table('booking_package_details')
                ->where('id', $bpdId)
                ->update($lineValues);
        } elseif ($bdId) {
            $branchDb->table('booking_details')
                ->where('id', $bdId)
                ->update($lineValues);
        }
    }

    public function daily_branch_sales_report_mobile()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.daily_branch_sales (
    branch_id,
    sales_date,
    total_transactions,
    gross_sales,
    total_discount,
    net_sales,
    total_collected,
    total_change,
    vat_sales,
    vat_amount,
    vat_exempt,
    vat_exemption,
    cash_amount,
    card_amount,
    gcash_amount,
    check_amount,
    maya_amount,
    qrph_amount,
    gc_amount,
    ar_amount,
    company_wallet_amount,
    others_amount,
    added_on,
    is_deleted
)
SELECT
    1                                               AS branch_id,
    t.transaction_date                              AS sales_date,
    COUNT(DISTINCT p.transaction_id)                AS total_transactions,
    SUM(t.grand_total)                              AS gross_sales,
    SUM(p.discount)                                 AS total_discount,
    SUM(p.subtotal)                                 AS net_sales,
    SUM(p.grand_total)                              AS total_collected,
    SUM(p.change)                                   AS total_change,
    0.00                                            AS vat_sales,
    0.00                                            AS vat_amount,
    0.00                                            AS vat_exempt,
    0.00                                            AS vat_exemption,
    SUM(CASE WHEN pmm.type = 'cash'
        THEN pmm.amount ELSE 0 END)                 AS cash_amount,
    SUM(CASE WHEN pmm.type = 'card'
        THEN pmm.amount ELSE 0 END)                 AS card_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'gcash'
        THEN pmm.amount ELSE 0 END)                 AS gcash_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'check'
        THEN pmm.amount ELSE 0 END)                 AS check_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'maya'
        THEN pmm.amount ELSE 0 END)                 AS maya_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'qrph'
        THEN pmm.amount ELSE 0 END)                 AS qrph_amount,
    SUM(CASE WHEN pmm.type = 'gc'
        THEN pmm.amount ELSE 0 END)                 AS gc_amount,
    SUM(CASE WHEN pmm.type = 'ar'
        THEN pmm.amount ELSE 0 END)                 AS ar_amount,
    SUM(CASE WHEN pmm.type = 'company_wallet'
        THEN pmm.amount ELSE 0 END)                 AS company_wallet_amount,
    SUM(CASE WHEN pmm.type = 'others' AND (
            pmm.other_payment_details IS NULL OR
            pmm.other_payment_details NOT IN ('gcash', 'maya', 'qrph', 'check')
        ) THEN pmm.amount ELSE 0 END)               AS others_amount,
    NOW()                                           AS added_on,
    0                                               AS is_deleted

FROM {MOBILE_DB}.payment p
JOIN {MOBILE_DB}.`transaction` t
    ON t.id = p.transaction_id
   AND t.is_deleted = 0
LEFT JOIN {MOBILE_DB}.payment_multiple_method pmm
    ON pmm.payment_id = p.id
   AND pmm.is_deleted = 0
WHERE p.is_deleted = 0
  AND p.grand_total > 0
  AND t.transaction_date = CURDATE()
GROUP BY t.transaction_date

ON DUPLICATE KEY UPDATE
    total_transactions    = VALUES(total_transactions),
    gross_sales           = VALUES(gross_sales),
    total_discount        = VALUES(total_discount),
    net_sales             = VALUES(net_sales),
    total_collected       = VALUES(total_collected),
    total_change          = VALUES(total_change),
    vat_sales             = VALUES(vat_sales),
    vat_amount            = VALUES(vat_amount),
    vat_exempt            = VALUES(vat_exempt),
    vat_exemption         = VALUES(vat_exemption),
    cash_amount           = VALUES(cash_amount),
    card_amount           = VALUES(card_amount),
    gcash_amount          = VALUES(gcash_amount),
    check_amount          = VALUES(check_amount),
    maya_amount           = VALUES(maya_amount),
    qrph_amount           = VALUES(qrph_amount),
    gc_amount             = VALUES(gc_amount),
    ar_amount             = VALUES(ar_amount),
    company_wallet_amount = VALUES(company_wallet_amount),
    others_amount         = VALUES(others_amount),
    added_on              = VALUES(added_on)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_branch_sales_report_oakridge()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.daily_branch_sales (
    branch_id,
    sales_date,
    total_transactions,
    gross_sales,
    total_discount,
    net_sales,
    total_collected,
    total_change,
    vat_sales,
    vat_amount,
    vat_exempt,
    vat_exemption,
    cash_amount,
    card_amount,
    gcash_amount,
    check_amount,
    maya_amount,
    qrph_amount,
    gc_amount,
    ar_amount,
    company_wallet_amount,
    others_amount,
    added_on,
    is_deleted
)
SELECT
    2                                               AS branch_id,
    t.transaction_date                              AS sales_date,
    COUNT(DISTINCT p.transaction_id)                AS total_transactions,
    SUM(t.grand_total)                              AS gross_sales,
    SUM(p.discount)                                 AS total_discount,
    SUM(p.subtotal)                                 AS net_sales,
    SUM(p.grand_total)                              AS total_collected,
    SUM(p.change)                                   AS total_change,
    0.00                                            AS vat_sales,
    0.00                                            AS vat_amount,
    0.00                                            AS vat_exempt,
    0.00                                            AS vat_exemption,
    SUM(CASE WHEN pmm.type = 'cash'
        THEN pmm.amount ELSE 0 END)                 AS cash_amount,
    SUM(CASE WHEN pmm.type = 'card'
        THEN pmm.amount ELSE 0 END)                 AS card_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'gcash'
        THEN pmm.amount ELSE 0 END)                 AS gcash_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'check'
        THEN pmm.amount ELSE 0 END)                 AS check_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'maya'
        THEN pmm.amount ELSE 0 END)                 AS maya_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'qrph'
        THEN pmm.amount ELSE 0 END)                 AS qrph_amount,
    SUM(CASE WHEN pmm.type = 'gc'
        THEN pmm.amount ELSE 0 END)                 AS gc_amount,
    SUM(CASE WHEN pmm.type = 'ar'
        THEN pmm.amount ELSE 0 END)                 AS ar_amount,
    SUM(CASE WHEN pmm.type = 'company_wallet'
        THEN pmm.amount ELSE 0 END)                 AS company_wallet_amount,
    SUM(CASE WHEN pmm.type = 'others' AND (
            pmm.other_payment_details IS NULL OR
            pmm.other_payment_details NOT IN ('gcash', 'maya', 'qrph', 'check')
        ) THEN pmm.amount ELSE 0 END)               AS others_amount,
    NOW()                                           AS added_on,
    0                                               AS is_deleted

FROM {OAKRIDGE_DB}.payment p
JOIN {OAKRIDGE_DB}.`transaction` t
    ON t.id = p.transaction_id
   AND t.is_deleted = 0
LEFT JOIN {OAKRIDGE_DB}.payment_multiple_method pmm
    ON pmm.payment_id = p.id
   AND pmm.is_deleted = 0
WHERE p.is_deleted = 0
  AND p.grand_total > 0
  AND t.transaction_date = CURDATE()
GROUP BY t.transaction_date

ON DUPLICATE KEY UPDATE
    total_transactions    = VALUES(total_transactions),
    gross_sales           = VALUES(gross_sales),
    total_discount        = VALUES(total_discount),
    net_sales             = VALUES(net_sales),
    total_collected       = VALUES(total_collected),
    total_change          = VALUES(total_change),
    vat_sales             = VALUES(vat_sales),
    vat_amount            = VALUES(vat_amount),
    vat_exempt            = VALUES(vat_exempt),
    vat_exemption         = VALUES(vat_exemption),
    cash_amount           = VALUES(cash_amount),
    card_amount           = VALUES(card_amount),
    gcash_amount          = VALUES(gcash_amount),
    check_amount          = VALUES(check_amount),
    maya_amount           = VALUES(maya_amount),
    qrph_amount           = VALUES(qrph_amount),
    gc_amount             = VALUES(gc_amount),
    ar_amount             = VALUES(ar_amount),
    company_wallet_amount = VALUES(company_wallet_amount),
    others_amount         = VALUES(others_amount),
    added_on              = VALUES(added_on)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_branch_sales_report_mactan()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.daily_branch_sales (
    branch_id,
    sales_date,
    total_transactions,
    gross_sales,
    total_discount,
    net_sales,
    total_collected,
    total_change,
    vat_sales,
    vat_amount,
    vat_exempt,
    vat_exemption,
    cash_amount,
    card_amount,
    gcash_amount,
    check_amount,
    maya_amount,
    qrph_amount,
    gc_amount,
    ar_amount,
    company_wallet_amount,
    others_amount,
    added_on,
    is_deleted
)
SELECT
    3                                               AS branch_id,
    t.transaction_date                              AS sales_date,
    COUNT(DISTINCT p.transaction_id)                AS total_transactions,
    SUM(t.grand_total)                              AS gross_sales,
    SUM(p.discount)                                 AS total_discount,
    SUM(p.subtotal)                                 AS net_sales,
    SUM(p.grand_total)                              AS total_collected,
    SUM(p.change)                                   AS total_change,
    0.00                                            AS vat_sales,
    0.00                                            AS vat_amount,
    0.00                                            AS vat_exempt,
    0.00                                            AS vat_exemption,
    SUM(CASE WHEN pmm.type = 'cash'
        THEN pmm.amount ELSE 0 END)                 AS cash_amount,
    SUM(CASE WHEN pmm.type = 'card'
        THEN pmm.amount ELSE 0 END)                 AS card_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'gcash'
        THEN pmm.amount ELSE 0 END)                 AS gcash_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'check'
        THEN pmm.amount ELSE 0 END)                 AS check_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'maya'
        THEN pmm.amount ELSE 0 END)                 AS maya_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'qrph'
        THEN pmm.amount ELSE 0 END)                 AS qrph_amount,
    SUM(CASE WHEN pmm.type = 'gc'
        THEN pmm.amount ELSE 0 END)                 AS gc_amount,
    SUM(CASE WHEN pmm.type = 'ar'
        THEN pmm.amount ELSE 0 END)                 AS ar_amount,
    SUM(CASE WHEN pmm.type = 'company_wallet'
        THEN pmm.amount ELSE 0 END)                 AS company_wallet_amount,
    SUM(CASE WHEN pmm.type = 'others' AND (
            pmm.other_payment_details IS NULL OR
            pmm.other_payment_details NOT IN ('gcash', 'maya', 'qrph', 'check')
        ) THEN pmm.amount ELSE 0 END)               AS others_amount,
    NOW()                                           AS added_on,
    0                                               AS is_deleted

FROM {MAC_DB}.payment p
JOIN {MAC_DB}.`transaction` t
    ON t.id = p.transaction_id
   AND t.is_deleted = 0
LEFT JOIN {MAC_DB}.payment_multiple_method pmm
    ON pmm.payment_id = p.id
   AND pmm.is_deleted = 0
WHERE p.is_deleted = 0
  AND p.grand_total > 0
  AND t.transaction_date = CURDATE()
GROUP BY t.transaction_date

ON DUPLICATE KEY UPDATE
    total_transactions    = VALUES(total_transactions),
    gross_sales           = VALUES(gross_sales),
    total_discount        = VALUES(total_discount),
    net_sales             = VALUES(net_sales),
    total_collected       = VALUES(total_collected),
    total_change          = VALUES(total_change),
    vat_sales             = VALUES(vat_sales),
    vat_amount            = VALUES(vat_amount),
    vat_exempt            = VALUES(vat_exempt),
    vat_exemption         = VALUES(vat_exemption),
    cash_amount           = VALUES(cash_amount),
    card_amount           = VALUES(card_amount),
    gcash_amount          = VALUES(gcash_amount),
    check_amount          = VALUES(check_amount),
    maya_amount           = VALUES(maya_amount),
    qrph_amount           = VALUES(qrph_amount),
    gc_amount             = VALUES(gc_amount),
    ar_amount             = VALUES(ar_amount),
    company_wallet_amount = VALUES(company_wallet_amount),
    others_amount         = VALUES(others_amount),
    added_on              = VALUES(added_on)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_branch_sales_report_consolacion()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.daily_branch_sales (
    branch_id,
    sales_date,
    total_transactions,
    gross_sales,
    total_discount,
    net_sales,
    total_collected,
    total_change,
    vat_sales,
    vat_amount,
    vat_exempt,
    vat_exemption,
    cash_amount,
    card_amount,
    gcash_amount,
    check_amount,
    maya_amount,
    qrph_amount,
    gc_amount,
    ar_amount,
    company_wallet_amount,
    others_amount,
    added_on,
    is_deleted
)
SELECT
    4                                               AS branch_id,
    t.transaction_date                              AS sales_date,
    COUNT(DISTINCT p.transaction_id)                AS total_transactions,
    SUM(t.grand_total)                              AS gross_sales,
    SUM(p.discount)                                 AS total_discount,
    SUM(p.subtotal)                                 AS net_sales,
    SUM(p.grand_total)                              AS total_collected,
    SUM(p.change)                                   AS total_change,
    0.00                                            AS vat_sales,
    0.00                                            AS vat_amount,
    0.00                                            AS vat_exempt,
    0.00                                            AS vat_exemption,
    SUM(CASE WHEN pmm.type = 'cash'
        THEN pmm.amount ELSE 0 END)                 AS cash_amount,
    SUM(CASE WHEN pmm.type = 'card'
        THEN pmm.amount ELSE 0 END)                 AS card_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'gcash'
        THEN pmm.amount ELSE 0 END)                 AS gcash_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'check'
        THEN pmm.amount ELSE 0 END)                 AS check_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'maya'
        THEN pmm.amount ELSE 0 END)                 AS maya_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'qrph'
        THEN pmm.amount ELSE 0 END)                 AS qrph_amount,
    SUM(CASE WHEN pmm.type = 'gc'
        THEN pmm.amount ELSE 0 END)                 AS gc_amount,
    SUM(CASE WHEN pmm.type = 'ar'
        THEN pmm.amount ELSE 0 END)                 AS ar_amount,
    SUM(CASE WHEN pmm.type = 'company_wallet'
        THEN pmm.amount ELSE 0 END)                 AS company_wallet_amount,
    SUM(CASE WHEN pmm.type = 'others' AND (
            pmm.other_payment_details IS NULL OR
            pmm.other_payment_details NOT IN ('gcash', 'maya', 'qrph', 'check')
        ) THEN pmm.amount ELSE 0 END)               AS others_amount,
    NOW()                                           AS added_on,
    0                                               AS is_deleted

FROM {CONSO_DB}.payment p
JOIN {CONSO_DB}.`transaction` t
    ON t.id = p.transaction_id
   AND t.is_deleted = 0
LEFT JOIN {CONSO_DB}.payment_multiple_method pmm
    ON pmm.payment_id = p.id
   AND pmm.is_deleted = 0
WHERE p.is_deleted = 0
  AND p.grand_total > 0
  AND t.transaction_date = CURDATE()
GROUP BY t.transaction_date

ON DUPLICATE KEY UPDATE
    total_transactions    = VALUES(total_transactions),
    gross_sales           = VALUES(gross_sales),
    total_discount        = VALUES(total_discount),
    net_sales             = VALUES(net_sales),
    total_collected       = VALUES(total_collected),
    total_change          = VALUES(total_change),
    vat_sales             = VALUES(vat_sales),
    vat_amount            = VALUES(vat_amount),
    vat_exempt            = VALUES(vat_exempt),
    vat_exemption         = VALUES(vat_exemption),
    cash_amount           = VALUES(cash_amount),
    card_amount           = VALUES(card_amount),
    gcash_amount          = VALUES(gcash_amount),
    check_amount          = VALUES(check_amount),
    maya_amount           = VALUES(maya_amount),
    qrph_amount           = VALUES(qrph_amount),
    gc_amount             = VALUES(gc_amount),
    ar_amount             = VALUES(ar_amount),
    company_wallet_amount = VALUES(company_wallet_amount),
    others_amount         = VALUES(others_amount),
    added_on              = VALUES(added_on)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function patient_tat_report_mobile()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.patient_branch_tat (
    branch_id, tat_date, time_from, time_to,
    total_tests, avg_tat_minutes, min_tat_minutes, max_tat_minutes,
    ape_peme_old, ape_peme_new, yakap_old, yakap_new,
    regular_old, regular_new, added_on, is_deleted
)
SELECT
    1, CURDATE(), hrs.time_from, hrs.time_to,
    0, 0.00, 0, 0,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {MOBILE_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id IN (
           SELECT patient_id FROM {MOBILE_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_old,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {MOBILE_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id NOT IN (
           SELECT patient_id FROM {MOBILE_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_new,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {MOBILE_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.assessment_type = 'ekonsulta'
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id IN (
           SELECT patient_id FROM {MOBILE_EMR_DB}.queue
           WHERE assessment_type = 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS yakap_old,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {MOBILE_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.assessment_type = 'ekonsulta'
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id NOT IN (
           SELECT patient_id FROM {MOBILE_EMR_DB}.queue
           WHERE assessment_type = 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS yakap_new,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MOBILE_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.assessment_type <> 'ekonsulta'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id IN (
           SELECT patient_id FROM {MOBILE_EMR_DB}.queue
           WHERE assessment_type <> 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS regular_old,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MOBILE_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.assessment_type <> 'ekonsulta'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id NOT IN (
           SELECT patient_id FROM {MOBILE_EMR_DB}.queue
           WHERE assessment_type <> 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS regular_new,

    NOW(), 0

FROM (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs

ON DUPLICATE KEY UPDATE
    ape_peme_old = VALUES(ape_peme_old), ape_peme_new = VALUES(ape_peme_new),
    yakap_old    = VALUES(yakap_old),    yakap_new    = VALUES(yakap_new),
    regular_old  = VALUES(regular_old),  regular_new  = VALUES(regular_new),
    added_on     = VALUES(added_on)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function patient_tat_report_oakridge()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.patient_branch_tat (
    branch_id, tat_date, time_from, time_to,
    total_tests, avg_tat_minutes, min_tat_minutes, max_tat_minutes,
    ape_peme_old, ape_peme_new, yakap_old, yakap_new,
    regular_old, regular_new, added_on, is_deleted
)
SELECT
    2, CURDATE(), hrs.time_from, hrs.time_to,
    0, 0.00, 0, 0,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {OAKRIDGE_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id IN (
           SELECT patient_id FROM {OAKRIDGE_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_old,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {OAKRIDGE_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id NOT IN (
           SELECT patient_id FROM {OAKRIDGE_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_new,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {OAKRIDGE_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.assessment_type = 'ekonsulta'
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id IN (
           SELECT patient_id FROM {OAKRIDGE_EMR_DB}.queue
           WHERE assessment_type = 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS yakap_old,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {OAKRIDGE_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.assessment_type = 'ekonsulta'
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id NOT IN (
           SELECT patient_id FROM {OAKRIDGE_EMR_DB}.queue
           WHERE assessment_type = 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS yakap_new,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {OAKRIDGE_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.assessment_type <> 'ekonsulta'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id IN (
           SELECT patient_id FROM {OAKRIDGE_EMR_DB}.queue
           WHERE assessment_type <> 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS regular_old,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {OAKRIDGE_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.assessment_type <> 'ekonsulta'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id NOT IN (
           SELECT patient_id FROM {OAKRIDGE_EMR_DB}.queue
           WHERE assessment_type <> 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS regular_new,

    NOW(), 0

FROM (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs

ON DUPLICATE KEY UPDATE
    ape_peme_old = VALUES(ape_peme_old), ape_peme_new = VALUES(ape_peme_new),
    yakap_old    = VALUES(yakap_old),    yakap_new    = VALUES(yakap_new),
    regular_old  = VALUES(regular_old),  regular_new  = VALUES(regular_new),
    added_on     = VALUES(added_on)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function patient_tat_report_mactan()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.patient_branch_tat (
    branch_id, tat_date, time_from, time_to,
    total_tests, avg_tat_minutes, min_tat_minutes, max_tat_minutes,
    ape_peme_old, ape_peme_new, yakap_old, yakap_new,
    regular_old, regular_new, added_on, is_deleted
)
SELECT
    3, CURDATE(), hrs.time_from, hrs.time_to,
    0, 0.00, 0, 0,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {MAC_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id IN (
           SELECT patient_id FROM {MAC_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_old,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {MAC_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id NOT IN (
           SELECT patient_id FROM {MAC_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_new,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {MAC_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.assessment_type = 'ekonsulta'
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id IN (
           SELECT patient_id FROM {MAC_EMR_DB}.queue
           WHERE assessment_type = 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS yakap_old,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {MAC_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.assessment_type = 'ekonsulta'
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id NOT IN (
           SELECT patient_id FROM {MAC_EMR_DB}.queue
           WHERE assessment_type = 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS yakap_new,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MAC_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.assessment_type <> 'ekonsulta'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id IN (
           SELECT patient_id FROM {MAC_EMR_DB}.queue
           WHERE assessment_type <> 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS regular_old,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MAC_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.assessment_type <> 'ekonsulta'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id NOT IN (
           SELECT patient_id FROM {MAC_EMR_DB}.queue
           WHERE assessment_type <> 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS regular_new,

    NOW(), 0

FROM (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs

ON DUPLICATE KEY UPDATE
    ape_peme_old = VALUES(ape_peme_old), ape_peme_new = VALUES(ape_peme_new),
    yakap_old    = VALUES(yakap_old),    yakap_new    = VALUES(yakap_new),
    regular_old  = VALUES(regular_old),  regular_new  = VALUES(regular_new),
    added_on     = VALUES(added_on)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function patient_tat_report_consolacion()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.patient_branch_tat (
    branch_id, tat_date, time_from, time_to,
    total_tests, avg_tat_minutes, min_tat_minutes, max_tat_minutes,
    ape_peme_old, ape_peme_new, yakap_old, yakap_new,
    regular_old, regular_new, added_on, is_deleted
)
SELECT
    4, CURDATE(), hrs.time_from, hrs.time_to,
    0, 0.00, 0, 0,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {CONSO_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id IN (
           SELECT patient_id FROM {CONSO_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_old,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {CONSO_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id NOT IN (
           SELECT patient_id FROM {CONSO_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_new,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {CONSO_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.assessment_type = 'ekonsulta'
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id IN (
           SELECT patient_id FROM {CONSO_EMR_DB}.queue
           WHERE assessment_type = 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS yakap_old,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {CONSO_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.assessment_type = 'ekonsulta'
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id NOT IN (
           SELECT patient_id FROM {CONSO_EMR_DB}.queue
           WHERE assessment_type = 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS yakap_new,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {CONSO_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.assessment_type <> 'ekonsulta'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id IN (
           SELECT patient_id FROM {CONSO_EMR_DB}.queue
           WHERE assessment_type <> 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS regular_old,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {CONSO_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.assessment_type <> 'ekonsulta'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id NOT IN (
           SELECT patient_id FROM {CONSO_EMR_DB}.queue
           WHERE assessment_type <> 'ekonsulta'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS regular_new,

    NOW(), 0

FROM (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_branch_sales_report_mantlewood()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.daily_branch_sales (
    branch_id,
    sales_date,
    total_transactions,
    gross_sales,
    total_discount,
    net_sales,
    total_collected,
    total_change,
    vat_sales,
    vat_amount,
    vat_exempt,
    vat_exemption,
    cash_amount,
    card_amount,
    gcash_amount,
    check_amount,
    maya_amount,
    qrph_amount,
    gc_amount,
    ar_amount,
    company_wallet_amount,
    others_amount,
    added_on,
    is_deleted
)
SELECT
    7                                               AS branch_id,
    t.transaction_date                              AS sales_date,
    COUNT(DISTINCT p.transaction_id)                AS total_transactions,
    SUM(t.grand_total)                              AS gross_sales,
    SUM(p.discount)                                 AS total_discount,
    SUM(p.subtotal)                                 AS net_sales,
    SUM(p.grand_total)                              AS total_collected,
    SUM(p.change)                                   AS total_change,
    0.00                                            AS vat_sales,
    0.00                                            AS vat_amount,
    0.00                                            AS vat_exempt,
    0.00                                            AS vat_exemption,
    SUM(CASE WHEN pmm.type = 'cash'
        THEN pmm.amount ELSE 0 END)                 AS cash_amount,
    SUM(CASE WHEN pmm.type = 'card'
        THEN pmm.amount ELSE 0 END)                 AS card_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'gcash'
        THEN pmm.amount ELSE 0 END)                 AS gcash_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'check'
        THEN pmm.amount ELSE 0 END)                 AS check_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'maya'
        THEN pmm.amount ELSE 0 END)                 AS maya_amount,
    SUM(CASE WHEN pmm.type = 'others'
        AND pmm.other_payment_details = 'qrph'
        THEN pmm.amount ELSE 0 END)                 AS qrph_amount,
    SUM(CASE WHEN pmm.type = 'gc'
        THEN pmm.amount ELSE 0 END)                 AS gc_amount,
    SUM(CASE WHEN pmm.type = 'ar'
        THEN pmm.amount ELSE 0 END)                 AS ar_amount,
    SUM(CASE WHEN pmm.type = 'company_wallet'
        THEN pmm.amount ELSE 0 END)                 AS company_wallet_amount,
    SUM(CASE WHEN pmm.type = 'others' AND (
            pmm.other_payment_details IS NULL OR
            pmm.other_payment_details NOT IN ('gcash', 'maya', 'qrph', 'check')
        ) THEN pmm.amount ELSE 0 END)               AS others_amount,
    NOW()                                           AS added_on,
    0                                               AS is_deleted

FROM {MANTLEWOOD_DB}.payment p
JOIN {MANTLEWOOD_DB}.`transaction` t
    ON t.id = p.transaction_id
   AND t.is_deleted = 0
LEFT JOIN {MANTLEWOOD_DB}.payment_multiple_method pmm
    ON pmm.payment_id = p.id
   AND pmm.is_deleted = 0
WHERE p.is_deleted = 0
  AND p.grand_total > 0
  AND t.transaction_date = CURDATE()
GROUP BY t.transaction_date

ON DUPLICATE KEY UPDATE
    total_transactions    = VALUES(total_transactions),
    gross_sales           = VALUES(gross_sales),
    total_discount        = VALUES(total_discount),
    net_sales             = VALUES(net_sales),
    total_collected       = VALUES(total_collected),
    total_change          = VALUES(total_change),
    vat_sales             = VALUES(vat_sales),
    vat_amount            = VALUES(vat_amount),
    vat_exempt            = VALUES(vat_exempt),
    vat_exemption         = VALUES(vat_exemption),
    cash_amount           = VALUES(cash_amount),
    card_amount           = VALUES(card_amount),
    gcash_amount          = VALUES(gcash_amount),
    check_amount          = VALUES(check_amount),
    maya_amount           = VALUES(maya_amount),
    qrph_amount           = VALUES(qrph_amount),
    gc_amount             = VALUES(gc_amount),
    ar_amount             = VALUES(ar_amount),
    company_wallet_amount = VALUES(company_wallet_amount),
    others_amount         = VALUES(others_amount),
    added_on              = VALUES(added_on)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function patient_tat_report_mantlewood()
    {
        $yakapFilter   = $this->_yakapQueueTypeFilter('{MANTLEWOOD_DB}');
        $regularFilter = $this->_regularQueueTypeFilter('{MANTLEWOOD_DB}');
        $yakapQe       = $this->_patientTatQueueFilterAlias($yakapFilter, 'qe');
        $regularQr     = $this->_patientTatQueueFilterAlias($regularFilter, 'qr');

        $sql = <<<EOT
INSERT INTO {REPORT_DB}.patient_branch_tat (
    branch_id, tat_date, time_from, time_to,
    total_tests, avg_tat_minutes, min_tat_minutes, max_tat_minutes,
    ape_peme_old, ape_peme_new, yakap_old, yakap_new,
    regular_old, regular_new, added_on, is_deleted
)
SELECT
    7, CURDATE(), hrs.time_from, hrs.time_to,
    0, 0.00, 0, 0,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_old,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.is_deleted = 0
       AND pe.patient_id NOT IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_new,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND {$yakapQe}
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue q
           WHERE {$yakapFilter}
             AND DATE(q.added_on) < CURDATE() AND q.is_deleted = 0
       )
    ) AS yakap_old,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND {$yakapQe}
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id NOT IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue q
           WHERE {$yakapFilter}
             AND DATE(q.added_on) < CURDATE() AND q.is_deleted = 0
       )
    ) AS yakap_new,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND {$regularQr}
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue q
           WHERE {$regularFilter}
             AND DATE(q.added_on) < CURDATE() AND q.is_deleted = 0
       )
    ) AS regular_old,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND {$regularQr}
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id NOT IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue q
           WHERE {$regularFilter}
             AND DATE(q.added_on) < CURDATE() AND q.is_deleted = 0
       )
    ) AS regular_new,

    NOW(), 0

FROM (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function pcp_clinic_tat_report_mantlewood()
    {
        $yakapFilter   = $this->_yakapQueueTypeFilter('{MANTLEWOOD_DB}');
        $regularFilter = $this->_regularQueueTypeFilter('{MANTLEWOOD_DB}');
        $yakapQe       = $this->_patientTatQueueFilterAlias($yakapFilter, 'qe');
        $regularQr     = $this->_patientTatQueueFilterAlias($regularFilter, 'qr');

        $sql = <<<EOT
INSERT IGNORE INTO {REPORT_DB}.pcp_clinic_branch_tat (
    branch_id, tat_date, doctor_id, time_from, time_to,
    ape_peme_old, ape_peme_new, yakap_old, yakap_new,
    regular_old, regular_new, added_on, is_deleted
)
SELECT
    7, CURDATE(), d.doctor_id, hrs.time_from, hrs.time_to,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.doctor_id = d.doctor_id
       AND pe.is_deleted = 0
       AND pe.patient_id IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_old,

    (SELECT COUNT(DISTINCT pe.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.physical_exam pe
     WHERE DATE(pe.added_on) = CURDATE()
       AND TIME(pe.added_on) >= hrs.time_from
       AND TIME(pe.added_on) < hrs.time_to
       AND pe.doctor_id = d.doctor_id
       AND pe.is_deleted = 0
       AND pe.patient_id NOT IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.physical_exam
           WHERE DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS ape_peme_new,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.doctor_id = d.doctor_id
       AND {$yakapQe}
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue q
           WHERE {$yakapFilter}
             AND DATE(q.added_on) < CURDATE() AND q.is_deleted = 0
       )
    ) AS yakap_old,

    (SELECT COUNT(DISTINCT qe.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qe
     WHERE DATE(qe.added_on) = CURDATE()
       AND TIME(qe.added_on) >= hrs.time_from
       AND TIME(qe.added_on) < hrs.time_to
       AND qe.doctor_id = d.doctor_id
       AND {$yakapQe}
       AND qe.is_deleted = 0
       AND qe.doctor_id IS NOT NULL
       AND qe.patient_id NOT IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue q
           WHERE {$yakapFilter}
             AND DATE(q.added_on) < CURDATE() AND q.is_deleted = 0
       )
    ) AS yakap_new,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.doctor_id = d.doctor_id
       AND {$regularQr}
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue q
           WHERE {$regularFilter}
             AND DATE(q.added_on) < CURDATE() AND q.is_deleted = 0
       )
    ) AS regular_old,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.doctor_id = d.doctor_id
       AND {$regularQr}
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id NOT IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue q
           WHERE {$regularFilter}
             AND DATE(q.added_on) < CURDATE() AND q.is_deleted = 0
       )
    ) AS regular_new,

    NOW(), 0

FROM (
    SELECT doctor_id FROM {MANTLEWOOD_EMR_DB}.physical_exam
    WHERE DATE(added_on) = CURDATE() AND is_deleted = 0 AND doctor_id IS NOT NULL
    UNION
    SELECT doctor_id FROM {MANTLEWOOD_EMR_DB}.queue
    WHERE DATE(added_on) = CURDATE() AND is_deleted = 0 AND doctor_id IS NOT NULL
) d
CROSS JOIN (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function specialist_md_tat_report_mantlewood()
    {
        $sql = <<<EOT
INSERT INTO {REPORT_DB}.specialist_md_branch_tat (
    branch_id, tat_date, doctor_id, time_from, time_to,
    walk_in_old, walk_in_new, referred_old, referred_new,
    added_on, is_deleted
)
SELECT
    7, CURDATE(), d.doctor_id, hrs.time_from, hrs.time_to,

    (SELECT COUNT(DISTINCT qw.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qw
     WHERE DATE(qw.added_on) = CURDATE()
       AND TIME(qw.added_on) >= hrs.time_from
       AND TIME(qw.added_on) < hrs.time_to
       AND qw.doctor_id = d.doctor_id
       AND qw.transaction_type = 'walk-in'
       AND qw.is_deleted = 0
       AND qw.doctor_id IS NOT NULL
       AND qw.patient_id IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue
           WHERE transaction_type = 'walk-in'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS walk_in_old,

    (SELECT COUNT(DISTINCT qw.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qw
     WHERE DATE(qw.added_on) = CURDATE()
       AND TIME(qw.added_on) >= hrs.time_from
       AND TIME(qw.added_on) < hrs.time_to
       AND qw.doctor_id = d.doctor_id
       AND qw.transaction_type = 'walk-in'
       AND qw.is_deleted = 0
       AND qw.doctor_id IS NOT NULL
       AND qw.patient_id NOT IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue
           WHERE transaction_type = 'walk-in'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS walk_in_new,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.doctor_id = d.doctor_id
       AND qr.transaction_type = 'referred'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue
           WHERE transaction_type = 'referred'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS referred_old,

    (SELECT COUNT(DISTINCT qr.patient_id)
     FROM {MANTLEWOOD_EMR_DB}.queue qr
     WHERE DATE(qr.added_on) = CURDATE()
       AND TIME(qr.added_on) >= hrs.time_from
       AND TIME(qr.added_on) < hrs.time_to
       AND qr.doctor_id = d.doctor_id
       AND qr.transaction_type = 'referred'
       AND qr.is_deleted = 0
       AND qr.doctor_id IS NOT NULL
       AND qr.patient_id NOT IN (
           SELECT patient_id FROM {MANTLEWOOD_EMR_DB}.queue
           WHERE transaction_type = 'referred'
             AND DATE(added_on) < CURDATE() AND is_deleted = 0
       )
    ) AS referred_new,

    NOW(), 0

FROM (
    SELECT doctor_id FROM {MANTLEWOOD_EMR_DB}.queue
    WHERE transaction_type IN ('walk-in', 'referred')
      AND DATE(added_on) = CURDATE()
      AND is_deleted = 0
      AND doctor_id IS NOT NULL
) d
CROSS JOIN (
    SELECT TIME('06:00:00') AS time_from, TIME('07:00:00') AS time_to UNION ALL
    SELECT TIME('07:00:00'), TIME('08:00:00') UNION ALL
    SELECT TIME('08:00:00'), TIME('09:00:00') UNION ALL
    SELECT TIME('09:00:00'), TIME('10:00:00') UNION ALL
    SELECT TIME('10:00:00'), TIME('11:00:00') UNION ALL
    SELECT TIME('11:00:00'), TIME('12:00:00') UNION ALL
    SELECT TIME('12:00:00'), TIME('13:00:00') UNION ALL
    SELECT TIME('13:00:00'), TIME('14:00:00') UNION ALL
    SELECT TIME('14:00:00'), TIME('15:00:00') UNION ALL
    SELECT TIME('15:00:00'), TIME('16:00:00') UNION ALL
    SELECT TIME('16:00:00'), TIME('17:00:00') UNION ALL
    SELECT TIME('17:00:00'), TIME('18:00:00')
) hrs
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_census_mobile()
    {
        $sql   = $this->_daily_census_sql(1, MOBILE_EMR_DB, MOBILE_DB);
        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_census_oakridge()
    {
        $sql   = $this->_daily_census_sql(2, OAKRIDGE_EMR_DB, OAKRIDGE_DB);
        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_census_mactan()
    {
        $sql   = $this->_daily_census_sql(3, MAC_EMR_DB, MAC_DB);
        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_census_consolacion()
    {
        $sql   = $this->_daily_census_sql(4, CONSO_EMR_DB, CONSO_DB);
        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    public function daily_census_mantlewood()
    {
        $sql   = $this->_daily_census_sql(7, MANTLEWOOD_EMR_DB, MANTLEWOOD_DB);
        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql);
        return (bool) $query;
    }

    protected function _daily_census_sql(int $branch_id, string $emr_db, string $diag_db): string
    {
        // $date = '2026-09-24'; // trigger date
        $date = date('Y-m-d');

        return <<<EOT
INSERT INTO {REPORT_DB}.daily_census (
    branch_id, date, 
    
    specialist_consult, total_yakap, pcp_ape_peme,
    pcp_yakap_new, pcp_yakap_old, pcp_consult_hmo_cash, xray, ecg, two_d_echo,
    tst, vascular, ultrasound, womancare, clin_chem, hema, immuno, sero,
    clin_mic, histo, drug_test, send_out, ophtha, opto, dental, ent, audiometry,
    abc, clinic_vax, cardio_source, 

    specialist_consult_count, total_yakap_count, pcp_ape_peme_count,
    pcp_yakap_new_count, pcp_yakap_old_count, pcp_consult_hmo_cash_count, xray_count, ecg_count, two_d_echo_count,
    tst_count, vascular_count, ultrasound_count, womancare_count, clin_chem_count, hema_count, immuno_count, sero_count,
    clin_mic_count, histo_count, drug_test_count, send_out_count, ophtha_count, opto_count, dental_count, ent_count, audiometry_count,
    abc_count, clinic_vax_count, cardio_source_count, 
    
    added_on, is_deleted
)
SELECT
    {$branch_id}                                    AS branch_id,
    '{$date}'                                         AS date,

    (SELECT SUM(t.grand_total)
     FROM {$emr_db}.queue q
     JOIN {$diag_db}.transaction t ON t.id = q.transaction_id
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND DATE(q.added_on) = '{$date}'
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.prefix = 'SP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS specialist_consult,

    (SELECT SUM(t.grand_total)
     FROM {$emr_db}.queue q
     JOIN {$diag_db}.transaction t ON t.id = q.transaction_id
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND q.assessment_type = 'ekonsulta'
       AND DATE(q.added_on) = '{$date}'
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS total_yakap,

    (SELECT SUM(t.grand_total)
     FROM {$emr_db}.physical_exam pe
     JOIN {$diag_db}.transaction t ON t.id = pe.transaction_id
     JOIN {MASTER_DB}.doctor d ON d.id = pe.doctor_id AND d.is_deleted = 0
     WHERE pe.is_deleted = 0
       AND DATE(pe.added_on) = '{$date}'
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS pcp_ape_peme,

    (SELECT SUM(t.grand_total)
     FROM {$emr_db}.queue q
     JOIN {$diag_db}.transaction t ON t.id = q.transaction_id
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND q.assessment_type = 'ekonsulta'
       AND DATE(q.added_on) = '{$date}'
       AND q.doctor_id NOT IN (
           SELECT doctor_id FROM {$emr_db}.queue
           WHERE is_deleted = 0 AND doctor_id IS NOT NULL
             AND DATE(added_on) < CURDATE()
       )
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS pcp_yakap_new,

    (SELECT SUM(t.grand_total)
     FROM {$emr_db}.queue q
     JOIN {$diag_db}.transaction t ON t.id = q.transaction_id
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND q.assessment_type = 'ekonsulta'
       AND DATE(q.added_on) = '{$date}'
       AND q.doctor_id IN (
           SELECT doctor_id FROM {$emr_db}.queue
           WHERE is_deleted = 0 AND doctor_id IS NOT NULL
             AND DATE(added_on) < CURDATE()
       )
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS pcp_yakap_old,

    (SELECT SUM(t.grand_total)
     FROM {$emr_db}.queue q
     JOIN {$diag_db}.transaction t ON t.id = q.transaction_id
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND q.assessment_type <> 'ekonsulta'
       AND DATE(q.added_on) = '{$date}'
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS pcp_consult_hmo_cash,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 43
    )                                               AS xray,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 24
    )                                               AS ecg,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 25
    )                                               AS two_d_echo,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 41
    )                                               AS tst,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 39
    )                                               AS vascular,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 27
    )                                               AS ultrasound,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 42
    )                                               AS womancare,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 18
    )                                               AS clin_chem,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 18
    )                                               AS hema,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 31
    )                                               AS immuno,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 31
    )                                               AS sero,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 19
    )                                               AS clin_mic,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 29
    )                                               AS histo,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id IN (2, 23)
    )                                               AS drug_test,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND bd.status = 'send out'
    )                                               AS send_out,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 37
    )                                               AS ophtha,

    0.00                                            AS opto,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id IN (22, 77)
    )                                               AS dental,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 26
    )                                               AS ent,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 16
    )                                               AS audiometry,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 14
    )                                               AS abc,

    (SELECT SUM(bd.amount)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 17
    )                                               AS clinic_vax,

    0.00                                            AS cardio_source,

    (SELECT COUNT(*)
     FROM {$emr_db}.queue q
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND DATE(q.added_on) = '{$date}'
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.prefix = 'SP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS specialist_consult_count,

    (SELECT COUNT(*)
     FROM {$emr_db}.queue q
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND q.assessment_type = 'ekonsulta'
       AND DATE(q.added_on) = '{$date}'
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS total_yakap_count,

    (SELECT COUNT(*)
     FROM {$emr_db}.physical_exam pe
     JOIN {MASTER_DB}.doctor d ON d.id = pe.doctor_id AND d.is_deleted = 0
     WHERE pe.is_deleted = 0
       AND DATE(pe.added_on) = '{$date}'
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS pcp_ape_peme_count,

    (SELECT COUNT(*)
     FROM {$emr_db}.queue q
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND q.assessment_type = 'ekonsulta'
       AND DATE(q.added_on) = '{$date}'
       AND q.doctor_id NOT IN (
           SELECT doctor_id FROM {$emr_db}.queue
           WHERE is_deleted = 0 AND doctor_id IS NOT NULL
             AND DATE(added_on) < CURDATE()
       )
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS pcp_yakap_new_count,

    (SELECT COUNT(*)
     FROM {$emr_db}.queue q
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND q.assessment_type = 'ekonsulta'
       AND DATE(q.added_on) = '{$date}'
       AND q.doctor_id IN (
           SELECT doctor_id FROM {$emr_db}.queue
           WHERE is_deleted = 0 AND doctor_id IS NOT NULL
             AND DATE(added_on) < CURDATE()
       )
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS pcp_yakap_old_count,

    (SELECT COUNT(*)
     FROM {$emr_db}.queue q
     JOIN {MASTER_DB}.doctor d ON d.id = q.doctor_id AND d.is_deleted = 0
     WHERE q.status = 'finished'
       AND q.is_deleted = 0
       AND q.assessment_type <> 'ekonsulta'
       AND DATE(q.added_on) = '{$date}'
       AND EXISTS (
           SELECT 1 FROM {MASTER_DB}.specialization s
           WHERE s.is_deleted = 0 AND s.name = 'PCP'
             AND FIND_IN_SET(s.id, d.specializations)
       )
    )                                               AS pcp_consult_hmo_cash_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 43
    )                                               AS xray_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 24
    )                                               AS ecg_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 25
    )                                               AS two_d_echo_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 41
    )                                               AS tst_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 39
    )                                               AS vascular_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 27
    )                                               AS ultrasound_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 42
    )                                               AS womancare_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 18
    )                                               AS clin_chem_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 18
    )                                               AS hema_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 31
    )                                               AS immuno_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 31
    )                                               AS sero_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 19
    )                                               AS clin_mic_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 29
    )                                               AS histo_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id IN (2, 23)
    )                                               AS drug_test_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND bd.status = 'send out'
    )                                               AS send_out_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 37
    )                                               AS ophtha_count,

    0.00                                            AS opto_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id IN (22, 77)
    )                                               AS dental_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 26
    )                                               AS ent_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 16
    )                                               AS audiometry_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 14
    )                                               AS abc_count,

    (SELECT COUNT(*)
     FROM {$diag_db}.booking_details bd
     LEFT JOIN {$diag_db}.booking_package_details bpd
       ON bpd.booking_detail_id = bd.id AND bpd.is_deleted = 0
     JOIN {$diag_db}.lab_test lt
       ON lt.id = CASE WHEN bd.type = 'package' THEN bpd.test_id ELSE bd.test_id END
      AND lt.is_deleted = 0
     WHERE bd.is_deleted = 0
       AND DATE(bd.added_on) = '{$date}'
       AND (bd.type != 'package' OR bpd.id IS NOT NULL)
       AND lt.category_id = 17
    )                                               AS clinic_vax_count,

    0.00                                            AS cardio_source_count,

    NOW()                                           AS added_on,
    0                                               AS is_deleted

ON DUPLICATE KEY UPDATE
    specialist_consult    = VALUES(specialist_consult),
    total_yakap           = VALUES(total_yakap),
    pcp_ape_peme          = VALUES(pcp_ape_peme),
    pcp_yakap_new         = VALUES(pcp_yakap_new),
    pcp_yakap_old         = VALUES(pcp_yakap_old),
    pcp_consult_hmo_cash  = VALUES(pcp_consult_hmo_cash),
    xray                  = VALUES(xray),
    ecg                   = VALUES(ecg),
    two_d_echo            = VALUES(two_d_echo),
    tst                   = VALUES(tst),
    vascular              = VALUES(vascular),
    ultrasound            = VALUES(ultrasound),
    womancare             = VALUES(womancare),
    clin_chem             = VALUES(clin_chem),
    hema                  = VALUES(hema),
    immuno                = VALUES(immuno),
    sero                  = VALUES(sero),
    clin_mic              = VALUES(clin_mic),
    histo                 = VALUES(histo),
    drug_test             = VALUES(drug_test),
    send_out              = VALUES(send_out),
    ophtha                = VALUES(ophtha),
    opto                  = VALUES(opto),
    dental                = VALUES(dental),
    ent                   = VALUES(ent),
    audiometry            = VALUES(audiometry),
    abc                   = VALUES(abc),
    clinic_vax            = VALUES(clinic_vax),
    cardio_source         = VALUES(cardio_source),
    specialist_consult_count    = VALUES(specialist_consult_count),
    total_yakap_count           = VALUES(total_yakap_count),
    pcp_ape_peme_count          = VALUES(pcp_ape_peme_count),
    pcp_yakap_new_count         = VALUES(pcp_yakap_new_count),
    pcp_yakap_old_count         = VALUES(pcp_yakap_old_count),
    pcp_consult_hmo_cash_count  = VALUES(pcp_consult_hmo_cash_count),
    xray_count                  = VALUES(xray_count),
    ecg_count                   = VALUES(ecg_count),
    two_d_echo_count            = VALUES(two_d_echo_count),
    tst_count                   = VALUES(tst_count),
    vascular_count              = VALUES(vascular_count),
    ultrasound_count            = VALUES(ultrasound_count),
    womancare_count             = VALUES(womancare_count),
    clin_chem_count             = VALUES(clin_chem_count),
    hema_count                  = VALUES(hema_count),
    immuno_count                = VALUES(immuno_count),
    sero_count                  = VALUES(sero_count),
    clin_mic_count              = VALUES(clin_mic_count),
    histo_count                 = VALUES(histo_count),
    drug_test_count             = VALUES(drug_test_count),
    send_out_count              = VALUES(send_out_count),
    ophtha_count                = VALUES(ophtha_count),
    opto_count                  = VALUES(opto_count),
    dental_count                = VALUES(dental_count),
    ent_count                   = VALUES(ent_count),
    audiometry_count            = VALUES(audiometry_count),
    abc_count                   = VALUES(abc_count),
    clinic_vax_count            = VALUES(clinic_vax_count),
    cardio_source_count         = VALUES(cardio_source_count),
    added_on                    = VALUES(added_on)
EOT;
    }

    public function get_daily_census($branch_id = null, $date = null, $date_from = null, $date_to = null, $parent_category_id = null, $category_id = null, $group_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql   = <<<EOT
SELECT
    daily_census.branch_id,
    branch.name AS branch_name,
    SUM(daily_census.specialist_consult)         AS specialist_consult,
    SUM(daily_census.total_yakap)                AS total_yakap,
    SUM(daily_census.pcp_ape_peme)                AS pcp_ape_peme,
    SUM(daily_census.pcp_yakap_new)               AS pcp_yakap_new,
    SUM(daily_census.pcp_yakap_old)               AS pcp_yakap_old,
    SUM(daily_census.pcp_consult_hmo_cash)        AS pcp_consult_hmo_cash,
    SUM(daily_census.xray)                        AS xray,
    SUM(daily_census.ecg)                         AS ecg,
    SUM(daily_census.two_d_echo)                  AS two_d_echo,
    SUM(daily_census.tst)                         AS tst,
    SUM(daily_census.vascular)                    AS vascular,
    SUM(daily_census.ultrasound)                  AS ultrasound,
    SUM(daily_census.womancare)                   AS womancare,
    SUM(daily_census.clin_chem)                   AS clin_chem,
    SUM(daily_census.hema)                        AS hema,
    SUM(daily_census.immuno)                      AS immuno,
    SUM(daily_census.sero)                        AS sero,
    SUM(daily_census.clin_mic)                    AS clin_mic,
    SUM(daily_census.histo)                       AS histo,
    SUM(daily_census.drug_test)                   AS drug_test,
    SUM(daily_census.send_out)                    AS send_out,
    SUM(daily_census.ophtha)                      AS ophtha,
    SUM(daily_census.opto)                        AS opto,
    SUM(daily_census.dental)                      AS dental,
    SUM(daily_census.ent)                         AS ent,
    SUM(daily_census.audiometry)                  AS audiometry,
    SUM(daily_census.abc)                         AS abc,
    SUM(daily_census.clinic_vax)                  AS clinic_vax,
    SUM(daily_census.cardio_source)               AS cardio_source,
    SUM(daily_census.specialist_consult_count)    AS specialist_consult_count,
    SUM(daily_census.total_yakap_count)           AS total_yakap_count,
    SUM(daily_census.pcp_ape_peme_count)          AS pcp_ape_peme_count,
    SUM(daily_census.pcp_yakap_new_count)         AS pcp_yakap_new_count,
    SUM(daily_census.pcp_yakap_old_count)         AS pcp_yakap_old_count,
    SUM(daily_census.pcp_consult_hmo_cash_count)  AS pcp_consult_hmo_cash_count,
    SUM(daily_census.xray_count)                  AS xray_count,
    SUM(daily_census.ecg_count)                   AS ecg_count,
    SUM(daily_census.two_d_echo_count)            AS two_d_echo_count,
    SUM(daily_census.tst_count)                   AS tst_count,
    SUM(daily_census.vascular_count)              AS vascular_count,
    SUM(daily_census.ultrasound_count)            AS ultrasound_count,
    SUM(daily_census.womancare_count)             AS womancare_count,
    SUM(daily_census.clin_chem_count)             AS clin_chem_count,
    SUM(daily_census.hema_count)                  AS hema_count,
    SUM(daily_census.immuno_count)                AS immuno_count,
    SUM(daily_census.sero_count)                  AS sero_count,
    SUM(daily_census.clin_mic_count)              AS clin_mic_count,
    SUM(daily_census.histo_count)                 AS histo_count,
    SUM(daily_census.drug_test_count)             AS drug_test_count,
    SUM(daily_census.send_out_count)              AS send_out_count,
    SUM(daily_census.ophtha_count)                AS ophtha_count,
    SUM(daily_census.opto_count)                  AS opto_count,
    SUM(daily_census.dental_count)                AS dental_count,
    SUM(daily_census.ent_count)                   AS ent_count,
    SUM(daily_census.audiometry_count)            AS audiometry_count,
    SUM(daily_census.abc_count)                   AS abc_count,
    SUM(daily_census.clinic_vax_count)            AS clinic_vax_count,
    SUM(daily_census.cardio_source_count)         AS cardio_source_count
FROM daily_census
LEFT JOIN {MASTER_DB}.branch ON branch.id = daily_census.branch_id
WHERE daily_census.is_deleted = 0
EOT;

        if ($branch_id) {
            $sql .= <<<EOT

AND daily_census.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($date) {
            $sql .= <<<EOT

AND daily_census.date = ?
EOT;
            $binds[] = $date;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND daily_census.date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($parent_category_id) {
            $sql .= <<<EOT
AND daily_census.parent_category_id = ?
EOT;
            $binds[] = $parent_category_id;
        }

        if ($category_id) {
            $sql .= <<<EOT
AND daily_census.category_id = ?
EOT;
            $binds[] = $category_id;
        }

        if ($group_id) {
            $sql .= <<<EOT
AND daily_census.group_id = ?
EOT;
            $binds[] = $group_id;
        }

        $sql .= <<<EOT

GROUP BY daily_census.branch_id
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /***
     * get md day census and sales update
     */
    public function get_md_day_census_and_sales_update($date_filter = null)
    {
        $branchConfig = config('Branch');
        $date         = $date_filter ?? date('Y-m-d');
        $month_start  = date('Y-m-01', strtotime($date));

        $result = [
            'date'  => $date,
            'daily' => $this->_get_census_data($branchConfig, $date, 'daily'),
            'mtd'   => $this->_get_census_data($branchConfig, $date, 'mtd', $month_start),
        ];

        return $result;
    }

    // -------------------------------------------------------
    // Core census builder — reused for daily and MTD
    // -------------------------------------------------------
    private function _get_census_data($branchConfig, $date, $mode = 'daily', $date_from = null)
    {
        $dept_keys     = ['Consultation', 'Labs/Diagnostics', 'Health Services', 'Pharmacy', 'Others'];
        $branches      = [];
        $clinic_totals = [
            'new_patient' => 0,
            'old_patient' => 0,
            'census'      => 0,
            'total_sales' => 0,
            'headcount'   => 0,
            'departments' => array_fill_keys($dept_keys, ['census' => 0, 'total_sales' => 0]),
        ];

        $range_from  = $mode === 'mtd' ? $date_from : $date;
        $booking_all = new Booking_all();

        foreach ($branchConfig->branches as $branch_id => $info) {
            $db          = \Config\Database::connect($info['db']);
            $branch_name = $info['name'];
            $booking     = new Booking($db);

            $transactions = $booking->get_transaction_sales(
                null,
                null,
                null,
                null,
                $range_from,
                $date
            ) ?: [];
            $transaction_ids = array_values(array_filter(array_map(
                'intval',
                array_column($transactions, 'id')
            )));
            $details_by_transaction = $booking->get_transaction_sales_details($transaction_ids);
            $discounts_by_transaction = $booking_all->get_transaction_discounts_on_connection(
                $db,
                $transaction_ids
            );

            $branch_sales   = 0.0;
            $dept_txn_ids   = [];
            $dept_sales     = [];
            $yakap_txn_ids  = [];
            $yakap_sales    = 0.0;
            foreach ($dept_keys as $key) {
                $dept_txn_ids[$key] = [];
                $dept_sales[$key]   = 0.0;
            }

            foreach ($transactions as $row) {
                $transaction_id = (int) ($row['id'] ?? 0);
                $lines          = $details_by_transaction[$transaction_id] ?? [];
                $totals         = $this->_md_day_sales_line_totals(
                    $lines,
                    $discounts_by_transaction[$transaction_id] ?? []
                );
                $net          = $totals['grand_total'];
                $branch_sales += $net;

                $line_sum = 0.0;
                $shares   = [];
                foreach ($lines as $line) {
                    $amount = (float) ($line['amount'] ?? 0);
                    $line_sum += $amount;

                    $charge_to = $line['charge_to'] ?? null;
                    if (
                        $charge_to !== null
                        && $charge_to !== ''
                        && (int) $charge_to === Yakap_billing::COMPANY_ID
                    ) {
                        $yakap_txn_ids[$transaction_id] = true;
                        $yakap_sales += $amount;
                    }

                    $dept = $this->_md_day_sales_dept($line);
                    if ($dept === 'Yakap' || ! isset($dept_sales[$dept])) {
                        $dept = 'Others';
                    }
                    $dept_txn_ids[$dept][$transaction_id] = true;
                    $shares[] = [
                        'dept'   => $dept,
                        'amount' => $amount,
                    ];
                }

                if ($shares === []) {
                    if ($net > 0) {
                        $dept_sales['Others'] += $net;
                        $dept_txn_ids['Others'][$transaction_id] = true;
                    }
                    continue;
                }

                if ($line_sum <= 0) {
                    if ($net > 0) {
                        $dept_sales[$shares[0]['dept']] += $net;
                    }
                    continue;
                }

                $allocated = 0.0;
                $last_dept = $shares[0]['dept'];
                foreach ($shares as $share) {
                    $part = round($net * ($share['amount'] / $line_sum), 2);
                    $dept_sales[$share['dept']] += $part;
                    $allocated += $part;
                    $last_dept = $share['dept'];
                }
                $remainder = round($net - $allocated, 2);
                if ($remainder !== 0.0) {
                    $dept_sales[$last_dept] += $remainder;
                }
            }

            $branch_sales = round($branch_sales, 2);
            $dept_sum     = 0.0;
            $last_dept    = null;
            foreach ($dept_keys as $key) {
                $dept_sales[$key] = round($dept_sales[$key], 2);
                $dept_sum += $dept_sales[$key];
                if ($dept_sales[$key] != 0.0) {
                    $last_dept = $key;
                }
            }
            $dept_gap = round($branch_sales - $dept_sum, 2);
            if ($dept_gap !== 0.0) {
                $adjust_key = $last_dept ?? 'Others';
                $dept_sales[$adjust_key] = round($dept_sales[$adjust_key] + $dept_gap, 2);
            }

            $departments = [];
            foreach ($dept_keys as $key) {
                $departments[$key] = $this->_dept([
                    'census'      => count($dept_txn_ids[$key]),
                    'total_sales' => $dept_sales[$key],
                ]);
            }

            $yakap              = $this->_dept([
                'census'      => count($yakap_txn_ids),
                'total_sales' => $yakap_sales,
            ]);
            $branch_census      = count($transactions);
            $branch_new_patient = $this->_md_day_new_patient_count($db, $transaction_ids);
            $branch_old_patient = $branch_census - $branch_new_patient;

            $branches[] = [
                'branch_id'   => $branch_id,
                'branch_name' => $branch_name,
                'new_patient' => $branch_new_patient,
                'old_patient' => $branch_old_patient,
                'census'      => $branch_census,
                'total_sales' => $branch_sales,
                'ave_trans'   => $branch_census > 0
                    ? round($branch_sales / $branch_census, 2) : 0,
                'headcount'   => 0, // TBD
                'psr'         => null,
                'yakap'       => [
                    'census'      => $yakap['census'],
                    'total_sales' => $yakap['total_sales'],
                ],
                'departments' => $departments,
            ];

            $clinic_totals['new_patient'] += $branch_new_patient;
            $clinic_totals['old_patient'] += $branch_old_patient;
            $clinic_totals['census'] += $branch_census;
            $clinic_totals['total_sales'] += $branch_sales;

            foreach ($dept_keys as $key) {
                $clinic_totals['departments'][$key]['census'] += $departments[$key]['census'];
                $clinic_totals['departments'][$key]['total_sales'] += $departments[$key]['total_sales'];
            }
        }

        foreach ($dept_keys as $key) {
            $clinic_totals['departments'][$key]['ave_trans'] = $this->_ave(
                $clinic_totals['departments'][$key]
            );
        }

        $clinic_totals['total_sales'] = round((float) $clinic_totals['total_sales'], 2);
        $clinic_totals['ave_trans']   = $clinic_totals['census'] > 0
            ? round($clinic_totals['total_sales'] / $clinic_totals['census'], 2) : 0;

        return [
            'branches'      => $branches,
            'clinic_totals' => $clinic_totals,
            'grand_total'   => [
                'new_patient' => $clinic_totals['new_patient'],
                'old_patient' => $clinic_totals['old_patient'],
                'census'      => $clinic_totals['census'],
                'total_sales' => $clinic_totals['total_sales'],
                'ave_trans'   => $clinic_totals['ave_trans'],
                'headcount'   => 0, // TBD
                'psr'         => null,
            ],
        ];
    }

    /**
     * amount = sum(transaction_details.amount); grand_total = amount - discount_total.
     *
     * @param array<int, array> $details
     * @param array<int, array> $discount
     * @return array{amount: float, discount_total: float, grand_total: float}
     */
    private function _md_day_sales_line_totals(array $details, array $discount)
    {
        $amount = 0.0;
        foreach ($details as $line) {
            $amount += (float) ($line['amount'] ?? 0);
        }
        $discount_total = 0.0;
        foreach ($discount as $row) {
            if (! is_array($row)) {
                continue;
            }
            $discount_total += (float) ($row['total_discount'] ?? 0);
        }
        $amount         = round($amount, 2);
        $discount_total = round($discount_total, 2);

        return [
            'amount'         => $amount,
            'discount_total' => $discount_total,
            'grand_total'    => round(max(0, $amount - $discount_total), 2),
        ];
    }

    /**
     * Classify a sales-report line into an MD Day department bucket.
     */
    private function _md_day_sales_dept(array $line)
    {
        $group_id   = (int) ($line['group_id'] ?? 0);
        $group_name = strtolower(trim((string) ($line['group_name'] ?? '')));
        if (
            in_array($group_id, [1, 2], true)
            || in_array($group_name, ['laboratory', 'diagnostics'], true)
        ) {
            return 'Labs/Diagnostics';
        }

        $type = strtolower((string) ($line['type'] ?? ''));
        if ($type === 'consultation') {
            return 'Consultation';
        }

        if ($group_id === 3) {
            return 'Health Services';
        }

        $charge_to = $line['charge_to'] ?? null;
        if ($charge_to !== null && $charge_to !== '' && (int) $charge_to === Yakap_billing::COMPANY_ID) {
            return 'Yakap';
        }

        return 'Others';
    }

    /**
     * First-ever visits among sales-report transactions.
     *
     * @param array<int> $transaction_ids
     */
    private function _md_day_new_patient_count($db, array $transaction_ids)
    {
        $transaction_ids = array_values(array_filter(array_map('intval', $transaction_ids)));
        if ($transaction_ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
        $sql          = <<<EOT
SELECT COALESCE(SUM(CASE WHEN NOT EXISTS (
    SELECT 1
    FROM transaction pt
    WHERE pt.is_deleted = 0
      AND pt.patient_id = t.patient_id
      AND (
          pt.transaction_date < t.transaction_date
          OR (pt.transaction_date = t.transaction_date AND pt.id < t.id)
      )
) THEN 1 ELSE 0 END), 0) AS new_patient
FROM transaction t
WHERE t.id IN ($placeholders)
EOT;
        $query = $db->query($sql, $transaction_ids);
        $row   = $query ? $query->getRowArray() : [];

        return (int) ($row['new_patient'] ?? 0);
    }

    /**
     * get yakap census month end report
     */
    public function get_yakap_census_month_end_report($fpe_to = null, $filter_branch_id = null)
    {
        $fpe_to       = $fpe_to ?? date('Y-m-d');
        $fpe_from     = date('Y-01-01', strtotime($fpe_to));
        $year         = date('Y', strtotime($fpe_to));
        $branchConfig = config('Branch');

        // Hard-coded per PhilHealth Annex I
        $kpi_targets = [
            'Consultation'    => ['target' => 1.00, 'weight' => 0.30],
            'Laboratory'      => ['target' => 0.50, 'weight' => 0.30],
            'Antibiotic Meds' => ['target' => 0.15, 'weight' => 0.10],
            'NCD Meds'        => ['target' => 0.20, 'weight' => 0.30],
        ];
        $second_tranch_rate = 1020.00;

        $branches     = [];
        $grand_totals = [
            'no_fpe'           => 0,
            'fpe'              => 0,
            'acquired'         => 0,
            'engaged'          => 0,
            'retained'         => 0,
            'returnee'         => 0,
            'total_registered' => 0,
            'consultation'     => 0,
            'prescribed'       => 0,
            'transacted'       => 0,
            'finalized'        => 0,
            'ekas'             => 0,
            'antibiotic'       => 0,
            'ncd'              => 0,
        ];
        $kpi_counts = [
            'Consultation'    => 0,
            'Laboratory'      => 0,
            'Antibiotic Meds' => 0,
            'NCD Meds'        => 0,
        ];
        $total_fpe_all_branches = 0;

        foreach ($branchConfig->branches as $branch_id => $info) {
            if ($filter_branch_id !== null && (int) $branch_id !== (int) $filter_branch_id) {
                continue;
            }

            // Skip branches without EMR DB (e.g. Mobile)
            $emr_db_name = $branchConfig->getEmrDB($branch_id);
            if (! $emr_db_name) {
                continue;
            }

            // Validate connection exists in Database config
            $db_config = config('Database');
            if (! isset($db_config->$emr_db_name)) {
                continue;
            }

            // -------------------------------------------------------
            // Wrap entire branch in try-catch
            // -------------------------------------------------------
            try {
                $emr_db  = \Config\Database::connect($emr_db_name);
                $db_name = $emr_db->getDatabase();

                // -------------------------------------------------------
                // 1. TOTAL REGISTERED — yakap_registration_list this year
                // -------------------------------------------------------
                $registered = $emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT id) AS total_registered
            FROM yakap_registration_list
            WHERE is_deleted = 0
              AND eff_year   = ?
        EOT, [$year])->getRowArray();

                // -------------------------------------------------------
                // 2. FPE COUNT — patient_fpe within date range
                // -------------------------------------------------------
                $fpe_result = $emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT patient_id) AS fpe_count
            FROM patient_fpe
            WHERE is_deleted = 0
              AND DATE(added_on) BETWEEN ? AND ?
        EOT, [$fpe_from, $fpe_to])->getRowArray();
                $fpe_count = (int) ($fpe_result['fpe_count'] ?? 0);

                // -------------------------------------------------------
                // 3. NO FPE — registered but no FPE record
                //    map: yakap_registration_list.phic_pin
                //       → master.patient (AES_DECRYPT phic_pin)
                //       → emr.patient (master_patient_id)
                //       LEFT JOIN patient_fpe (null = no FPE)
                // -------------------------------------------------------
                $no_fpe = $emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT yrl.id) AS no_fpe_count
            FROM yakap_registration_list yrl
            JOIN mytemr_master.patient mp
                ON AES_DECRYPT(mp.phic_pin, ?) = yrl.phic_pin
               AND mp.is_deleted = 0
            JOIN patient ep
                ON ep.master_patient_id = mp.id
               AND ep.is_deleted = 0
            LEFT JOIN patient_fpe pf
                ON pf.patient_id  = ep.id
               AND pf.is_deleted  = 0
               AND DATE(pf.added_on) BETWEEN ? AND ?
            WHERE yrl.is_deleted = 0
              AND yrl.eff_year   = ?
              AND pf.id IS NULL
        EOT, [ENCRYPTION_KEY, $fpe_from, $fpe_to, $year])->getRowArray();

                // -------------------------------------------------------
                // 4. PATIENT CATEGORIES among FPE patients
                // -------------------------------------------------------
                $categories = $this->_get_yakap_patient_categories($emr_db, $fpe_from, $fpe_to);

                // -------------------------------------------------------
                // 5–8. LABS FUNNEL — consultation → prescribed → transacted → finalized/ekas
                // -------------------------------------------------------
                $labs = $this->_yakap_labs_funnel_counts($emr_db, (int) $branch_id, $fpe_from, $fpe_to);

                // -------------------------------------------------------
                // 9. ANTIBIOTIC MEDS — yakap-specific, FPE patients
                // -------------------------------------------------------
                $antibiotic = $emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT th.patient_id) AS antibiotic_count
            FROM therapeutic th
            JOIN assessment a
                ON a.id              = th.assessment_id
               AND a.is_deleted      = 0
               AND a.assessment_type = 'ekonsulta'
            JOIN mytemr_master.item i
                ON i.id              = th.medicine_id
               AND i.is_deleted      = 0
               AND i.is_antibiotic   = 1
            JOIN patient_fpe pf
                ON pf.patient_id  = th.patient_id
               AND pf.is_deleted  = 0
               AND DATE(pf.added_on) BETWEEN ? AND ?
            WHERE th.is_deleted = 0
              AND DATE(th.added_on) BETWEEN ? AND ?
        EOT, [$fpe_from, $fpe_to, $fpe_from, $fpe_to])->getRowArray();

                // -------------------------------------------------------
                // 10. NCD MEDS — yakap-specific, FPE patients
                // -------------------------------------------------------
                $ncd = $emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT th.patient_id) AS ncd_count
            FROM therapeutic th
            JOIN assessment a
                ON a.id              = th.assessment_id
               AND a.is_deleted      = 0
               AND a.assessment_type = 'ekonsulta'
            JOIN mytemr_master.item i
                ON i.id                        = th.medicine_id
               AND i.is_deleted                = 0
               AND i.is_antibiotic             = 0
               AND i.philhealth_medicine_type IS NOT NULL
            JOIN patient_fpe pf
                ON pf.patient_id  = th.patient_id
               AND pf.is_deleted  = 0
               AND DATE(pf.added_on) BETWEEN ? AND ?
            WHERE th.is_deleted = 0
              AND DATE(th.added_on) BETWEEN ? AND ?
        EOT, [$fpe_from, $fpe_to, $fpe_from, $fpe_to])->getRowArray();

                // -------------------------------------------------------
                // Build branch row
                // -------------------------------------------------------
                $branch_data = [
                    'branch_id'        => $branch_id,
                    'branch_name'      => $info['name'],
                    'no_fpe'           => (int) ($no_fpe['no_fpe_count'] ?? 0),
                    'acquired'         => (int) ($categories['acquired'] ?? 0),
                    'engaged'          => (int) ($categories['engaged'] ?? 0),
                    'retained'         => (int) ($categories['retained'] ?? 0),
                    'returnee'         => (int) ($categories['returnee'] ?? 0),
                    'total_registered' => (int) ($registered['total_registered'] ?? 0),
                    'fpe'              => $fpe_count,
                    'consultation'     => $labs['consultation'],
                    'prescribed'       => $labs['prescribed'],
                    'transacted'       => $labs['transacted'],
                    'finalized'        => $labs['finalized'],
                    'ekas'             => $labs['ekas'],
                    'antibiotic'       => (int) ($antibiotic['antibiotic_count'] ?? 0),
                    'ncd'              => (int) ($ncd['ncd_count'] ?? 0),
                ];

                $branches[] = $branch_data;

                // Roll up
                foreach (
                    [
                        'no_fpe',
                        'fpe',
                        'acquired',
                        'engaged',
                        'retained',
                        'returnee',
                        'total_registered',
                        'consultation',
                        'prescribed',
                        'transacted',
                        'finalized',
                        'ekas',
                        'antibiotic',
                        'ncd'
                    ] as $key
                ) {
                    $grand_totals[$key] += $branch_data[$key];
                }

                $kpi_counts['Consultation'] += $branch_data['consultation'];
                $kpi_counts['Laboratory'] += $branch_data['ekas'];
                $kpi_counts['Antibiotic Meds'] += $branch_data['antibiotic'];
                $kpi_counts['NCD Meds'] += $branch_data['ncd'];
                $total_fpe_all_branches += $fpe_count;
            } catch (\Throwable $e) {
                // Log the error but continue to next branch
                log_message('error', "[yakap_census] Branch {$info['name']} DB error: " . $e->getMessage());

                // Include branch in response with null data so frontend knows it failed
                $branches[] = [
                    'branch_id'        => $branch_id,
                    'branch_name'      => $info['name'],
                    'error'            => 'Database unavailable',
                    'no_fpe'           => null,
                    'fpe'              => null,
                    'acquired'         => null,
                    'engaged'          => null,
                    'retained'         => null,
                    'returnee'         => null,
                    'total_registered' => null,
                    'consultation'     => null,
                    'prescribed'       => null,
                    'transacted'       => null,
                    'finalized'        => null,
                    'ekas'             => null,
                    'antibiotic'       => null,
                    'ncd'              => null,
                ];
            }
        }

        // -------------------------------------------------------
        // Second Tranch Monitoring
        // -------------------------------------------------------
        $kpi_rows           = [];
        $performance_factor = 0;

        foreach ($kpi_targets as $kpi_name => $kpi) {
            $count      = $kpi_counts[$kpi_name];
            $perf_score = $total_fpe_all_branches > 0
                ? $count / $total_fpe_all_branches
                : 0;
            $resultant_score = $kpi['target'] > 0
                ? ($perf_score / $kpi['target']) * $kpi['weight']
                : 0;

            $kpi_rows[] = [
                'kpi'               => $kpi_name,
                'count'             => $count,
                'performance_score' => round($perf_score * 100, 1) . '%',
                'resultant_score'   => round($resultant_score * 100, 1) . '%',
                'target'            => ($kpi['target'] * 100) . '%',
                'weight'            => ($kpi['weight'] * 100) . '%',
            ];
            $performance_factor += $resultant_score;
        }

        $second_tranch_amount = round(
            $total_fpe_all_branches * round($performance_factor, 3) * $second_tranch_rate,
            2
        );

        // var_dump($total_fpe_all_branches, round($performance_factor, 3), $second_tranch_rate); die();

        return [
            'fpe_from'      => $fpe_from,
            'fpe_to'        => $fpe_to,
            'as_of_date'    => $fpe_to,
            'branches'      => $branches,
            'grand_total'   => $grand_totals,
            'total_fpe'     => $total_fpe_all_branches,
            'second_tranch' => [
                'kpi_rows'             => $kpi_rows,
                'performance_factor'   => round($performance_factor * 100, 1) . '%',
                'second_tranch_amount' => $second_tranch_amount,
            ],
        ];
    }

    /**
     * Yakap Labs report — unique-FPE funnel per branch (YTD through as_of).
     */
    public function get_yakap_labs_report($as_of = null, $filter_branch_id = null): array
    {
        $as_of        = $as_of ?? date('Y-m-d');
        $date_from    = date('Y-01-01', strtotime($as_of));
        $branchConfig = config('Branch');
        $db_config    = config('Database');

        $branches = [];
        $totals   = [
            'fpe'          => 0,
            'consultation' => 0,
            'prescribed'   => 0,
            'transacted'   => 0,
            'finalized'    => 0,
            'ekas'         => 0,
        ];

        foreach ($branchConfig->branches as $branch_id => $info) {
            if ($filter_branch_id !== null && (int) $branch_id !== (int) $filter_branch_id) {
                continue;
            }

            $emr_db_name = $branchConfig->getEmrDB($branch_id);
            if (! $emr_db_name || ! isset($db_config->$emr_db_name)) {
                continue;
            }

            try {
                $emr_db = \Config\Database::connect($emr_db_name);

                $fpe_result = $emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT patient_id) AS fpe_count
            FROM patient_fpe
            WHERE is_deleted = 0
              AND DATE(added_on) BETWEEN ? AND ?
        EOT, [$date_from, $as_of])->getRowArray();
                $fpe_count = (int) ($fpe_result['fpe_count'] ?? 0);

                $labs = $this->_yakap_labs_funnel_counts($emr_db, (int) $branch_id, $date_from, $as_of);

                $row = [
                    'branch_id'    => $branch_id,
                    'branch_name'  => $info['name'],
                    'fpe'          => $fpe_count,
                    'consultation' => $labs['consultation'],
                    'prescribed'   => $labs['prescribed'],
                    'transacted'   => $labs['transacted'],
                    'finalized'    => $labs['finalized'],
                    'ekas'         => $labs['ekas'],
                ];

                $branches[] = $row;
                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $row[$key];
                }
            } catch (\Throwable $e) {
                log_message('error', "[yakap_labs] Branch {$info['name']} DB error: " . $e->getMessage());
                $branches[] = [
                    'branch_id'    => $branch_id,
                    'branch_name'  => $info['name'],
                    'error'        => 'Database unavailable',
                    'fpe'          => null,
                    'consultation' => null,
                    'prescribed'   => null,
                    'transacted'   => null,
                    'finalized'    => null,
                    'ekas'         => null,
                ];
            }
        }

        return [
            'as_of'     => $as_of,
            'date_from' => $date_from,
            'date_to'   => $as_of,
            'branches'  => $branches,
            'totals'    => $totals,
        ];
    }

    /**
     * FDA dashboard medication dispenses, optionally combined across diagnostic branches.
     *
     * @return array{data: array<int,array<string,mixed>>, size: int, page: int, data_size: int, total_pages: int}
     */
    public function get_fda_dashboard(
        ?int $branch_id,
        int $page,
        int $data_size,
        ?string $date_from = null,
        ?string $date_to = null,
        ?int $patient_id = null,
        ?int $item_id = null,
        ?string $term = null,
        ?int $pharmacy_doctor_id = null
    ): array {
        if ($pharmacy_doctor_id !== null && ($pharmacy_doctor_id <= 0 || $branch_id === null)) {
            throw new \InvalidArgumentException('A positive pharmacy_doctor_id requires a diagnostic branch.');
        }
        $branch_config = config('Branch');
        $sources = [
            1 => ['connection' => 'mobile', 'schema' => '{MOBILE_DB}', 'database' => MOBILE_DB],
            2 => ['connection' => 'oakridge', 'schema' => '{OAKRIDGE_DB}', 'database' => OAKRIDGE_DB],
            3 => ['connection' => 'mactan', 'schema' => '{MAC_DB}', 'database' => MAC_DB],
            4 => ['connection' => 'consolacion', 'schema' => '{CONSO_DB}', 'database' => CONSO_DB],
            7 => ['connection' => 'mantlewood', 'schema' => '{MANTLEWOOD_DB}', 'database' => MANTLEWOOD_DB],
        ];
        $branch_ids = $branch_id === null ? array_keys($sources) : [$branch_id];

        foreach ($branch_ids as $source_branch_id) {
            if (! isset($sources[$source_branch_id], $branch_config->branches[$source_branch_id])) {
                throw new \InvalidArgumentException('Invalid diagnostic branch.');
            }
        }

        $db = \Config\Database::connect($branch_id === null ? 'master' : $sources[$branch_id]['connection']);
        $schema_names = array_map(static fn(array $source): string => $source['database'], $sources);
        $schema_placeholders = implode(',', array_fill(0, count($schema_names), '?'));
        $column_sql = <<<EOT
SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA IN ({$schema_placeholders})
  AND TABLE_NAME IN (?, ?)
EOT;
        $column_rows = $db->query(
            $column_sql,
            array_merge($schema_names, ['dispense_medicine_details', 'item'])
        )->getResultArray();

        $columns_by_schema = [];
        foreach ($column_rows as $column_row) {
            $schema_name = (string) $column_row['TABLE_SCHEMA'];
            $table_name = (string) $column_row['TABLE_NAME'];
            $column_name = strtolower((string) $column_row['COLUMN_NAME']);
            $columns_by_schema[$schema_name][$table_name][$column_name] = true;
        }

        $branch_ids = array_values(array_filter(
            $branch_ids,
            static fn(int $id): bool => isset(
                $columns_by_schema[$sources[$id]['database']]['dispense_medicine_details']['id'],
                $columns_by_schema[$sources[$id]['database']]['dispense_medicine_details']['added_on']
            )
        ));

        if ($branch_ids === []) {
            return [
                'data'        => [],
                'size'        => 0,
                'page'        => $page,
                'data_size'   => $data_size,
                'total_pages' => 0,
            ];
        }

        $union_parts = [];
        $binds = [];

        foreach ($branch_ids as $source_branch_id) {
            $source = $sources[$source_branch_id] ?? null;
            $branch = $branch_config->branches[$source_branch_id] ?? null;
            $schema = $branch_id === null ? $source['schema'] : '';
            $details_table = $schema === ''
                ? 'dispense_medicine_details'
                : $schema . '.dispense_medicine_details';
            $item_table = $schema === '' ? 'item' : $schema . '.item';
            $schema_columns = $columns_by_schema[$source['database']];
            $detail_columns = $schema_columns['dispense_medicine_details'];
            $item_columns = $schema_columns['item'] ?? [];

            $detail_expression = static function (string $column) use ($detail_columns): string {
                return isset($detail_columns[strtolower($column)]) ? "dmd.`{$column}`" : 'NULL';
            };
            $address_columns = ['street', 'barangay', 'province', 'city', 'country', 'zip_code'];
            $address_parts = array_map(
                static fn(string $column): string => 'NULLIF(TRIM(' . $detail_expression($column) . "), '')",
                $address_columns
            );
            $medication_columns = ['generic', 'dosage', 'preparation'];
            $medication_parts = array_map(
                static fn(string $column): string => 'NULLIF(TRIM(' . $detail_expression($column) . "), '')",
                $medication_columns
            );
            $doctor_expression = $detail_expression('doctor_name');
            $patient_expression = $detail_expression('patient_name');
            $age_expression = $detail_expression('age');
            $quantity_expression = $detail_expression('quantity');
            $address_sql = implode(', ', $address_parts);
            $medication_sql = implode(', ', $medication_parts);
            $fs_condition = isset($detail_columns['fs']) ? 'dmd.fs = 1' : '0 = 1';
            $ps_condition = isset($detail_columns['ps']) ? 'dmd.ps = 1' : '0 = 1';
            $has_item_type = isset($detail_columns['item_id'], $item_columns['id'], $item_columns['philhealth_medicine_type']);
            $rx_type_expression = $has_item_type
                ? "COALESCE(i.philhealth_medicine_type, 'regular')"
                : "'regular'";
            $item_join = $has_item_type
                ? "LEFT JOIN {$item_table} i ON i.id = dmd.item_id"
                : '';

            $where_conditions = [];
            $branch_binds = [(int) $source_branch_id, $branch['name']];

            if ($date_from !== null && $date_to !== null) {
                $where_conditions[] = 'DATE(dmd.added_on) BETWEEN ? AND ?';
                $branch_binds[] = $date_from;
                $branch_binds[] = $date_to;
            }

            if ($patient_id !== null) {
                if (isset($detail_columns['patient_id'])) {
                    $where_conditions[] = 'dmd.patient_id = ?';
                    $branch_binds[] = $patient_id;
                } else {
                    $where_conditions[] = '1 = 0';
                }
            }

            if ($item_id !== null) {
                if (isset($detail_columns['item_id'])) {
                    $where_conditions[] = 'dmd.item_id = ?';
                    $branch_binds[] = $item_id;
                } else {
                    $where_conditions[] = '1 = 0';
                }
            }

            if ($pharmacy_doctor_id !== null) {
                if (isset($detail_columns['pharmacy_doctor_id'])) {
                    $where_conditions[] = 'dmd.pharmacy_doctor_id = ?';
                    $branch_binds[] = $pharmacy_doctor_id;
                } else {
                    $where_conditions[] = '1 = 0';
                }
            }

            if ($term !== null && $term !== '') {
                $term_columns = array_values(array_filter(
                    ['patient_name', 'doctor_name', 'generic'],
                    static fn(string $column): bool => isset($detail_columns[$column])
                ));
                if ($term_columns === []) {
                    $where_conditions[] = '1 = 0';
                } else {
                    $where_conditions[] = '(' . implode(' OR ', array_map(
                        static fn(string $column): string => "LOWER(dmd.`{$column}`) LIKE LOWER(?)",
                        $term_columns
                    )) . ')';
                    foreach ($term_columns as $_) {
                        $branch_binds[] = '%' . $term . '%';
                    }
                }
            }

            $sql = <<<EOT
SELECT
    dmd.id AS rx_no,
    DATE(dmd.added_on) AS date,
    {$doctor_expression} AS doctor_name,
    {$patient_expression} AS patient_name,
    CONCAT_WS(' ', {$address_sql}) AS address,
    {$age_expression} AS age,
    CONCAT_WS(' ', {$medication_sql}) AS medications,
    {$quantity_expression} AS quantity_dispensed,
    CASE
        WHEN {$fs_condition} THEN 'FS'
        WHEN {$ps_condition} THEN 'PS'
        ELSE NULL
    END AS remarks,
    {$rx_type_expression} AS rx_type,
    ? AS branch_id,
    ? AS branch_name
FROM {$details_table} dmd
{$item_join}
EOT;
            if ($where_conditions !== []) {
                $sql .= "\nWHERE " . implode("\n  AND ", $where_conditions);
            }
            $union_parts[] = $sql;
            array_push($binds, ...$branch_binds);
        }

        $union_sql = $branch_id === null
            ? $this->_resolve_cross_db_sql(implode("\nUNION ALL\n", $union_parts))
            : $union_parts[0];
        $count_sql = <<<EOT
SELECT COUNT(*) AS total
FROM ({$union_sql}) AS fda_rows
EOT;
        $count = $db->query($count_sql, $binds)->getRowArray();
        $size = (int) ($count['total'] ?? 0);

        $offset = ($page - 1) * $data_size;
        $sql = <<<EOT
SELECT fda_rows.*
FROM ({$union_sql}) AS fda_rows
ORDER BY fda_rows.rx_no ASC, fda_rows.branch_id ASC
LIMIT ? OFFSET ?
EOT;
        $rows = $db->query($sql, array_merge($binds, [$data_size, $offset]))->getResultArray();

        return [
            'data'        => $rows,
            'size'        => $size,
            'page'        => $page,
            'data_size'   => $data_size,
            'total_pages' => $data_size > 0 ? (int) ceil($size / $data_size) : 0,
        ];
    }

    /**
     * Daily dispensing totals. Branch-local IDs never merge across sources.
     */
    public function get_fda_sales(
        ?int $branch_id,
        int $page,
        int $data_size,
        ?string $date_from = null,
        ?string $date_to = null,
        ?int $patient_id = null,
        ?int $item_id = null,
        ?string $term = null,
        ?string $brand = null,
        ?string $generic = null,
        ?string $dosage = null,
        ?string $form = null,
        ?string $kind = null
    ): array {
        $branch_config = config('Branch');
        if (($branch_id !== null && !isset($branch_config->branches[$branch_id])) || $page < 1 || $data_size < 1) {
            throw new \InvalidArgumentException('Invalid FDA sales branch or pagination.');
        }
        $sources = [];
        foreach ($branch_config->branches as $source_branch_id => $branch) {
            if ($branch_id !== null && $branch_id !== $source_branch_id) {
                continue;
            }
            $database = \Config\Database::connect($branch['db'])->getDatabase();
            if ($database === '') {
                throw new \RuntimeException('FDA sales branch database is not configured.');
            }
            $sources[$source_branch_id] = ['database' => $database, 'name' => $branch['name']];
        }
        $db = \Config\Database::connect('master');
        $schema_names = array_column($sources, 'database');
        $placeholders = implode(',', array_fill(0, count($schema_names), '?'));
        $metadata = $db->query("SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA IN ($placeholders)
            AND TABLE_NAME IN (?, ?, ?) ORDER BY TABLE_SCHEMA, TABLE_NAME, ORDINAL_POSITION",
            array_merge($schema_names, ['dispense_medicine_details', 'item', 'user']));
        if ($metadata === false) {
            throw new \RuntimeException('Unable to inspect FDA sales source columns.');
        }
        $columns = [];
        foreach ($metadata->getResultArray() as $column) {
            $columns[$column['TABLE_SCHEMA']][$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column['DATA_TYPE'];
        }
        $filters = compact('date_from', 'date_to', 'patient_id', 'item_id', 'term', 'brand', 'generic', 'dosage', 'form', 'kind');
        $reserved_columns = ['added_by', 'item_id', 'added_on', 'quantity', 'price', 'amount',
            'branch_id', 'branch_name', 'item_name', 'dispense_by_name'];
        $csv_columns = [];
        $queries = [];
        $union_parts = [];
        $binds = [];
        foreach ($sources as $source_branch_id => $source) {
            $source_columns = $columns[$source['database']] ?? [];
            $detail_columns = $source_columns['dispense_medicine_details'] ?? [];
            if (!$detail_columns) {
                continue;
            }
            foreach (['id', 'added_by', 'item_id', 'added_on', 'quantity', 'price', 'amount'] as $column) {
                if (!isset($detail_columns[$column])) {
                    throw new \RuntimeException('FDA sales source is missing required column: '.$column.'.');
                }
            }
            foreach (['quantity', 'price', 'amount'] as $column) {
                if (!in_array($detail_columns[$column], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'], true)) {
                    throw new \RuntimeException('FDA sales source has a nonnumeric '.$column.' column.');
                }
            }
            if (!in_array($detail_columns['added_on'], ['date', 'datetime', 'timestamp'], true)) {
                throw new \RuntimeException('FDA sales source has an incompatible added_on column.');
            }
            foreach (array_diff(array_keys($detail_columns), $reserved_columns) as $column) {
                $csv_columns[$column] = true;
            }
            $query = $this->_get_fda_sales_source_query($source['database'], $source_columns, $filters);
            $queries[$source_branch_id] = $query;
            $from_sql = $query['from'];
            $where_sql = $query['where'];
            $item_name = $query['item_name'];
            $dispense_name = $query['dispense_by_name'];
            $union_parts[] = <<<EOT
SELECT ? AS branch_id, ? AS branch_name, dmd.added_by, dmd.item_id, DATE(dmd.added_on) AS added_on,
    SUM(COALESCE(dmd.quantity, 0)) AS quantity,
    SUM(COALESCE(dmd.price, 0)) AS price,
    SUM(COALESCE(dmd.amount, 0)) AS amount,
    MAX({$item_name}) AS item_name, MAX({$dispense_name}) AS dispense_by_name
{$from_sql}
{$where_sql}
GROUP BY dmd.added_by, dmd.item_id, DATE(dmd.added_on)
EOT;
            array_push($binds, $source_branch_id, $source['name'], ...$query['binds']);
        }
        $response = ['data' => [], 'size' => 0, 'page' => $page, 'data_size' => $data_size, 'total_pages' => 0];
        if (!$union_parts) {
            return $response;
        }
        $union_sql = implode("\nUNION ALL\n", $union_parts);
        $count_query = $db->query('SELECT COUNT(*) AS total FROM ('.$union_sql.') AS fda_sales', $binds);
        if ($count_query === false) {
            throw new \RuntimeException('Unable to count FDA sales groups.');
        }
        $response['size'] = (int) $count_query->getRowArray()['total'];
        $response['total_pages'] = (int) ceil($response['size'] / $data_size);
        $row_query = $db->query('SELECT fda_sales.* FROM ('.$union_sql.') AS fda_sales
            ORDER BY added_on ASC, branch_id ASC, added_by ASC, item_id ASC LIMIT ? OFFSET ?',
            array_merge($binds, [$data_size, ($page - 1) * $data_size]));
        if ($row_query === false) {
            throw new \RuntimeException('Unable to retrieve FDA sales groups.');
        }
        $rows = $row_query->getResultArray();
        $page_groups = [];
        foreach ($rows as $index => &$row) {
            foreach (['quantity', 'price', 'amount'] as $column) {
                // DECIMAL sums remain strings; never round financial totals through PHP floats.
                $row[$column] = (string) $row[$column];
            }
            foreach ($csv_columns as $column => $_) {
                $row[$column] = null;
            }
            $key = $this->_get_fda_sales_group_key($row['added_by'], $row['item_id'], $row['added_on']);
            $page_groups[(int) $row['branch_id']][$key] = $index;
        }
        unset($row);
        $csv_values = [];
        foreach ($page_groups as $source_branch_id => $groups) {
            $query = $queries[$source_branch_id];
            $group_conditions = [];
            $group_binds = [];
            foreach ($groups as $index) {
                $group_conditions[] = '(dmd.added_by <=> ? AND dmd.item_id <=> ? AND DATE(dmd.added_on) <=> ?)';
                array_push($group_binds, $rows[$index]['added_by'], $rows[$index]['item_id'], $rows[$index]['added_on']);
            }
            $detail_query = $db->query('SELECT dmd.*, DATE(dmd.added_on) AS _fda_sales_day '
                .$query['from'].' '.$query['where'].' AND ('.implode(' OR ', $group_conditions).') ORDER BY dmd.id ASC',
                array_merge($query['binds'], $group_binds));
            if ($detail_query === false) {
                throw new \RuntimeException('Unable to retrieve FDA sales detail values.');
            }
            foreach ($detail_query->getResultArray() as $detail) {
                $key = $this->_get_fda_sales_group_key($detail['added_by'], $detail['item_id'], $detail['_fda_sales_day']);
                $index = $groups[$key];
                foreach ($csv_columns as $column => $_) {
                    if (!isset($detail[$column]) || trim((string) $detail[$column]) === '') {
                        continue;
                    }
                    $value = (string) $detail[$column];
                    // Prefix keys so numeric-looking values keep their exact string identity.
                    $csv_values[$index][$column]['value:'.$value] = $value;
                }
            }
        }
        foreach ($csv_values as $index => $values_by_column) {
            foreach ($values_by_column as $column => $values) {
                $rows[$index][$column] = $this->_get_fda_sales_csv(array_values($values));
            }
        }
        $response['data'] = $rows;
        return $response;
    }

    /**
     * Reuse the same joins and bound, pre-aggregation filters for totals and CSV detail reads.
     */
    private function _get_fda_sales_source_query(string $database, array $columns, array $filters): array
    {
        $schema = '`'.str_replace('`', '``', $database).'`';
        $detail_columns = $columns['dispense_medicine_details'];
        $item_columns = $columns['item'] ?? [];
        $user_columns = $columns['user'] ?? [];
        $from = 'FROM '.$schema.'.dispense_medicine_details dmd';
        $item_name = 'NULL';
        if (isset($item_columns['id'])) {
            $from .= ' LEFT JOIN '.$schema.'.item i ON i.id = dmd.item_id';
            if (isset($item_columns['name'])) {
                $item_name = 'i.name';
            }
        }
        $dispense_name = 'NULL';
        if (isset($user_columns['id'])) {
            $from .= ' LEFT JOIN '.$schema.'.`user` u ON u.id = dmd.added_by';
            $name_parts = [];
            foreach (['first_name', 'middle_name', 'last_name'] as $column) {
                if (isset($user_columns[$column])) {
                    $name_parts[] = "NULLIF(TRIM(u.`{$column}`), '')";
                }
            }
            if ($name_parts) {
                $dispense_name = "NULLIF(TRIM(CONCAT_WS(' ', ".implode(', ', $name_parts).")), '')";
            }
        }
        $conditions = ['1 = 1'];
        $binds = [];
        if (isset($detail_columns['is_deleted'])) {
            $conditions[] = 'dmd.is_deleted = 0';
        }
        if ($filters['date_from'] !== null && $filters['date_to'] !== null) {
            $conditions[] = 'DATE(dmd.added_on) BETWEEN ? AND ?';
            array_push($binds, $filters['date_from'], $filters['date_to']);
        }
        foreach (['patient_id', 'item_id'] as $column) {
            if ($filters[$column] !== null) {
                if (isset($detail_columns[$column])) {
                    $conditions[] = 'dmd.'.$column.' = ?';
                    $binds[] = $filters[$column];
                } else {
                    $conditions[] = '1 = 0';
                }
            }
        }
        foreach (['brand', 'generic', 'dosage', 'form'] as $column) {
            $value = $filters[$column];
            if ($value !== null && trim($value) !== '') {
                if (isset($item_columns['id'], $item_columns[$column])) {
                    $conditions[] = 'i.'.$column.' = ?';
                    $binds[] = trim($value);
                } else {
                    $conditions[] = '1 = 0';
                }
            }
        }
        $tokens = array_filter(array_unique(array_map('trim', explode(',', $filters['kind'] ?? ''))),
            static fn($token) => $token !== '');
        if ($tokens) {
            if (isset($item_columns['id'], $item_columns['kind'])) {
                $kind_conditions = [];
                foreach ($tokens as $token) {
                    $kind_conditions[] = 'FIND_IN_SET(?, i.kind) > 0';
                    $binds[] = $token;
                }
                $conditions[] = '('.implode(' OR ', $kind_conditions).')';
            } else {
                $conditions[] = '1 = 0';
            }
        }
        if ($filters['term'] !== null && trim($filters['term']) !== '') {
            $expressions = [$item_name, $dispense_name];
            foreach (['doctor_name', 'patient_name'] as $column) {
                if (isset($detail_columns[$column])) {
                    $expressions[] = 'dmd.'.$column;
                }
            }
            $search = [];
            foreach ($expressions as $expression) {
                $search[] = 'LOWER('.$expression.') LIKE LOWER(?)';
                $binds[] = '%'.trim($filters['term']).'%';
            }
            $conditions[] = '('.implode(' OR ', $search).')';
        }
        return ['from' => $from, 'where' => 'WHERE '.implode(' AND ', $conditions), 'binds' => $binds,
            'item_name' => $item_name, 'dispense_by_name' => $dispense_name];
    }

    private function _get_fda_sales_group_key($added_by, $item_id, $added_on): string
    {
        return json_encode(array_map(static fn($value) => $value === null ? null : (string) $value,
            [$added_by, $item_id, $added_on]), JSON_THROW_ON_ERROR);
    }

    private function _get_fda_sales_csv(array $values): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to format FDA sales CSV values.');
        }
        try {
            if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false || !rewind($stream)) {
                throw new \RuntimeException('Unable to format FDA sales CSV values.');
            }
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new \RuntimeException('Unable to read FDA sales CSV values.');
            }
            return substr($csv, 0, -2);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Active pharmacy doctors, queried through master across available clinic schemas.
     *
     * @return array{data: array<int,array<string,mixed>>, size: int}
     */
    public function get_pharmacy_doctor(?int $branch_id = null): array
    {
        $branch_config = config('Branch');
        $sources = [
            1 => MOBILE_DB,
            2 => OAKRIDGE_DB,
            3 => MAC_DB,
            4 => CONSO_DB,
            7 => MANTLEWOOD_DB,
        ];
        if ($branch_id !== null && ! isset($sources[$branch_id], $branch_config->branches[$branch_id])) {
            throw new \InvalidArgumentException('Invalid diagnostic branch.');
        }

        $db = \Config\Database::connect('master');
        $schema_names = array_values($sources);
        $schema_placeholders = implode(',', array_fill(0, count($schema_names), '?'));
        $table_sql = <<<EOT
SELECT TABLE_SCHEMA
FROM information_schema.TABLES
WHERE TABLE_SCHEMA IN ({$schema_placeholders}) AND TABLE_NAME = ?
EOT;
        $table_query = $db->query($table_sql, array_merge($schema_names, ['pharmacy_doctor']));
        if ($table_query === false) {
            throw new \RuntimeException('Unable to inspect diagnostic pharmacy-doctor tables.');
        }
        $available_schemas = array_fill_keys(array_column($table_query->getResultArray(), 'TABLE_SCHEMA'), true);

        $union_parts = [];
        $binds = [];
        foreach ($sources as $source_branch_id => $database) {
            if (($branch_id !== null && $source_branch_id !== $branch_id) || ! isset($available_schemas[$database])) {
                continue;
            }
            $branch = $branch_config->branches[$source_branch_id] ?? null;
            if ($branch === null) {
                throw new \InvalidArgumentException('Invalid diagnostic branch configuration.');
            }
            $schema = '`' . str_replace('`', '``', $database) . '`';
            $union_parts[] = <<<EOT
SELECT pd.id, pd.doctor_id, pd.name, pd.lic_no, pd.is_cpci,
       pd.added_by, pd.added_on, pd.updated_by, pd.updated_on, pd.is_deleted,
       ? AS branch_id, ? AS branch_name
FROM {$schema}.pharmacy_doctor pd
WHERE pd.is_deleted = 0
EOT;
            array_push($binds, $source_branch_id, $branch['name']);
        }
        if ($union_parts === []) {
            return ['data' => [], 'size' => 0];
        }

        $union_sql = implode("\nUNION ALL\n", $union_parts);
        $sql = <<<EOT
SELECT pharmacy_doctors.*
FROM ({$union_sql}) AS pharmacy_doctors
ORDER BY pharmacy_doctors.name ASC, pharmacy_doctors.branch_id ASC, pharmacy_doctors.id ASC
EOT;
        $query = $db->query($sql, $binds);
        if ($query === false) {
            throw new \RuntimeException('Unable to retrieve diagnostic pharmacy doctors.');
        }
        $rows = $query->getResultArray();

        return ['data' => $rows, 'size' => count($rows)];
    }

    /**
     * Unique-FPE labs funnel: consultation → prescribed → transacted → finalized/ekas.
     */
    private function _yakap_labs_funnel_counts($emr_db, int $branch_id, string $date_from, string $date_to): array
    {
        $consultation = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT pf.patient_id) AS consult_count
            FROM patient_fpe pf
            JOIN assessment a
                ON a.patient_id       = pf.patient_id
               AND a.is_deleted       = 0
               AND a.assessment_type  = 'ekonsulta'
               AND DATE(a.added_on)  BETWEEN ? AND ?
            WHERE pf.is_deleted = 0
              AND DATE(pf.added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to, $date_from, $date_to])->getRowArray()['consult_count'] ?? 0);

        $prescribed = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT pf.patient_id) AS prescribed_count
            FROM patient_fpe pf
            JOIN diagnostic d
                ON d.patient_id = pf.patient_id
               AND d.is_deleted = 0
               AND DATE(d.added_on) BETWEEN ? AND ?
            JOIN diagnostic_item di
                ON di.diagnostic_id = d.id
               AND di.is_deleted    = 0
            JOIN assessment a
                ON a.id               = d.assessment_id
               AND a.is_deleted       = 0
               AND a.assessment_type  = 'ekonsulta'
            WHERE pf.is_deleted = 0
              AND DATE(pf.added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to, $date_from, $date_to])->getRowArray()['prescribed_count'] ?? 0);

        $transacted = 0;
        $branchConfig = config('Branch');
        $db_config    = config('Database');
        $clinic_group = $branchConfig->getClinicDB($branch_id);
        if ($clinic_group && isset($db_config->$clinic_group)) {
            try {
                $csr_db         = \Config\Database::connect($clinic_group);
                $clinic_db_name = $csr_db->getDatabase();
                $company_id     = Yakap_billing::COMPANY_ID;
                $transacted     = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT pf.patient_id) AS transacted_count
            FROM patient_fpe pf
            JOIN patient ep
                ON ep.id         = pf.patient_id
               AND ep.is_deleted = 0
            WHERE pf.is_deleted = 0
              AND DATE(pf.added_on) BETWEEN ? AND ?
              AND EXISTS (
                  SELECT 1
                  FROM `{$clinic_db_name}`.`transaction` transc
                  LEFT JOIN `{$clinic_db_name}`.`booking` booking
                      ON booking.transaction_id = transc.id
                     AND booking.is_deleted     = 0
                  LEFT JOIN `{$clinic_db_name}`.`patient` cp
                      ON cp.id = COALESCE(transc.patient_id, booking.patient_id)
                  WHERE transc.is_deleted = 0
                    AND cp.master_id = ep.master_patient_id
                    AND (
                        transc.hmo_id = ?
                        OR booking.charge_to = ?
                        OR EXISTS (
                            SELECT 1
                            FROM `{$clinic_db_name}`.`booking_details` bd
                            WHERE bd.booking_id = booking.id
                              AND bd.is_deleted = 0
                              AND bd.charge_to  = ?
                        )
                    )
                    AND transc.transaction_date BETWEEN ? AND ?
              )
        EOT, [$date_from, $date_to, $company_id, $company_id, $company_id, $date_from, $date_to])->getRowArray()['transacted_count'] ?? 0);
            } catch (\Throwable $e) {
                log_message('error', "[yakap_labs] Branch {$branch_id} CSR transacted query failed: " . $e->getMessage());
            }
        }

        $finalized = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT pf.patient_id) AS finalized_count
            FROM patient_fpe pf
            JOIN lab_result lr
                ON lr.patient_id  = pf.patient_id
               AND lr.is_deleted  = 0
               AND lr.is_sent     = 1
               AND DATE(lr.added_on) BETWEEN ? AND ?
            WHERE pf.is_deleted = 0
              AND DATE(pf.added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to, $date_from, $date_to])->getRowArray()['finalized_count'] ?? 0);

        return [
            'consultation' => $consultation,
            'prescribed'   => $prescribed,
            'transacted'   => $transacted,
            'finalized'    => $finalized,
            'ekas'         => $finalized,
        ];
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------
    /**
     * dept helper
     */
    private function _dept(array $row): array
    {
        $census = (int) ($row['census'] ?? 0);
        $sales  = (float) ($row['total_sales'] ?? 0);
        return [
            'census'      => $census,
            'total_sales' => $sales,
            'ave_trans'   => $census > 0 ? round($sales / $census, 2) : 0,
        ];
    }

    /**
     * ave helper
     */
    private function _ave(array $row): float
    {
        $census = (int) ($row['census'] ?? 0);
        $sales  = (float) ($row['total_sales'] ?? 0);
        return $census > 0 ? round($sales / $census, 2) : 0;
    }

    /**
     * get yakap patient categories helper
     */
    private function _get_yakap_patient_categories(
        $emr_db,
        string $fpe_from,
        string $fpe_to
    ): array {
        $result = $emr_db->query(<<<EOT
        WITH fpe_patients AS (
            SELECT DISTINCT patient_id
            FROM patient_fpe
            WHERE is_deleted = 0
              AND DATE(added_on) BETWEEN ? AND ?
        ),
        psh AS (
            SELECT
                patient_id,
                MAX(CASE WHEN DATEDIFF(CURDATE(), added_on) > 720
                    THEN 1 ELSE 0 END) AS was_lost
            FROM outpatient
            WHERE is_deleted = 0
            GROUP BY patient_id
        ),
        pl AS (
            SELECT
                t.patient_id,
                COUNT(DISTINCT t.id)                AS transaction_count,
                DATEDIFF(CURDATE(), MAX(t.added_on)) AS days_since_last_tx
            FROM outpatient t
            WHERE t.is_deleted = 0
            GROUP BY t.patient_id
        ),
        categorized AS (
            SELECT
                pl.patient_id,
                CASE
                    WHEN pl.days_since_last_tx > 720                       THEN 'Lost'
                    WHEN psh.was_lost = 1 AND pl.days_since_last_tx <= 720 THEN 'Returnee'
                    WHEN pl.transaction_count = 1                          THEN 'Acquired'
                    WHEN pl.transaction_count = 2                          THEN 'Engaged'
                    WHEN pl.transaction_count >= 3                         THEN 'Retained'
                    ELSE 'Unknown'
                END AS patient_category
            FROM pl
            LEFT JOIN psh          ON psh.patient_id = pl.patient_id
            INNER JOIN fpe_patients fp ON fp.patient_id = pl.patient_id
        )
        SELECT
            COUNT(DISTINCT CASE WHEN patient_category = 'Acquired' THEN patient_id END) AS acquired,
            COUNT(DISTINCT CASE WHEN patient_category = 'Engaged'  THEN patient_id END) AS engaged,
            COUNT(DISTINCT CASE WHEN patient_category = 'Retained' THEN patient_id END) AS retained,
            COUNT(DISTINCT CASE WHEN patient_category = 'Returnee' THEN patient_id END) AS returnee
        FROM categorized
    EOT, [$fpe_from, $fpe_to])->getRowArray();

        return [
            'acquired' => (int) ($result['acquired'] ?? 0),
            'engaged'  => (int) ($result['engaged'] ?? 0),
            'retained' => (int) ($result['retained'] ?? 0),
            'returnee' => (int) ($result['returnee'] ?? 0),
        ];
    }

    /**
     * Allowed FPE report tabs.
     */
    public function get_allowed_fpe_tabs(): array
    {
        return ['dashboard', 'tracker'];
    }

    /**
     * FPE report entry point (dashboard or tracker tab).
     */
    public function get_fpe(string $date_from, string $date_to, string $tab): array
    {
        switch ($tab) {
            case 'dashboard':
                return $this->get_fpe_dashboard($date_from, $date_to);
            case 'tracker':
                return $this->get_fpe_dashboard_tracker($date_from, $date_to);
            default:
                return [];
        }
    }

    /**
     * FPE dashboard — branch summary (02 FPE Dashboard worksheet).
     */
    public function get_fpe_dashboard(string $date_from, string $date_to): array
    {
        $year           = date('Y', strtotime($date_to));
        $branch_config  = config('Branch');
        $branches       = [];
        $registered     = 0;
        $numeric_totals = $this->_fpe_empty_dashboard_totals();

        foreach ($this->_fpe_active_branch_ids() as $branch_id) {
            $info = $branch_config->branches[$branch_id] ?? null;
            if (! $info) {
                continue;
            }

            try {
                $emr_db = $this->_connect_branch_emr($branch_id);
                if (! $emr_db) {
                    throw new \RuntimeException('EMR connection unavailable');
                }

                $metrics = $this->_fpe_branch_dashboard_metrics(
                    $emr_db,
                    $info['db'],
                    $date_from,
                    $date_to,
                    $year
                );

                $registered += $metrics['registered'];

                $row = array_merge([
                    'branch_id'     => $branch_id,
                    'branch'        => $info['name'],
                    'type'          => $branch_id === 1 ? 'Mobile' : 'Clinic',
                    'is_carry_over' => false,
                ], $metrics);

                unset($row['registered']);
                $branches[] = $row;
                $this->_fpe_add_dashboard_totals($numeric_totals, $row);
            } catch (\Throwable $e) {
                log_message('error', "[fpe_dashboard] Branch {$info['name']} error: " . $e->getMessage());
                $branches[] = array_merge([
                    'branch_id'     => $branch_id,
                    'branch'        => $info['name'],
                    'type'          => $branch_id === 1 ? 'Mobile' : 'Clinic',
                    'is_carry_over' => false,
                    'error'         => 'Database unavailable',
                ], $this->_fpe_empty_dashboard_row());
            }
        }

        $carry_over = $this->_fpe_carry_over_row();

        return [
            'registered' => $registered,
            'date_from'  => $date_from,
            'date_to'    => $date_to,
            'carry_over' => $carry_over,
            'branches'   => $branches,
            'totals'     => $numeric_totals,
        ];
    }

    /**
     * FPE tracker — one row per branch for the selected date range.
     */
    public function get_fpe_dashboard_tracker(string $date_from, string $date_to): array
    {
        $year          = date('Y', strtotime($date_to));
        $branch_config = config('Branch');
        $rows          = [];
        $registered    = 0;
        $months        = date('F Y', strtotime($date_from)) . ' – ' . date('F Y', strtotime($date_to));
        $totals        = $this->_fpe_empty_tracker_totals();

        foreach ($this->_fpe_active_branch_ids() as $branch_id) {
            $info = $branch_config->branches[$branch_id] ?? null;
            if (! $info) {
                continue;
            }

            if ($this->_fpe_is_excluded_location($info['name'])) {
                continue;
            }

            try {
                $emr_db = $this->_connect_branch_emr($branch_id);
                if (! $emr_db) {
                    throw new \RuntimeException('EMR connection unavailable');
                }

                $metrics = $this->_fpe_branch_dashboard_metrics(
                    $emr_db,
                    $info['db'],
                    $date_from,
                    $date_to,
                    $year
                );

                $registered += $metrics['registered'];
                $tracker_key = $this->_fpe_branch_tracker_key($branch_id);
                $fpe_done    = (int) ($metrics['fpe_done'] ?? 0);
                $onsite      = [
                    'oak'   => $tracker_key === 'oak' ? $fpe_done : 0,
                    'mac'   => $tracker_key === 'mac' ? $fpe_done : 0,
                    'con'   => $tracker_key === 'con' ? $fpe_done : 0,
                    'total' => $fpe_done,
                ];

                $row = [
                    'branch_id'                    => $branch_id,
                    'remarks'                      => null,
                    'months'                       => $months,
                    'report_date'                  => $date_to,
                    'transaction_date'             => null,
                    'location'                     => $info['name'],
                    'reference_location'           => $info['name'],
                    'type'                         => $branch_id === 1 ? 'Mobile' : 'Clinic',
                    'registered_count'             => $metrics['registered'],
                    'reported_fpe_done_onsite'     => $onsite,
                    'received_forms'               => $this->_fpe_count_total(0),
                    'fpe_in_validated'             => $this->_fpe_count_total($metrics['fpe_encoded']),
                    'emr_encoding'                 => $this->_fpe_count_total($metrics['fpe_encoded']),
                    'ihcp_portal'                  => $this->_fpe_count_total($metrics['fpe_uploaded']),
                    'cis_uploading'                => $this->_fpe_count_total(0),
                    'fpe_amount'                   => $this->_fpe_count_total($metrics['fpe_amount_calculated']),
                    'lab_diagnostics_availed'      => $this->_fpe_count_total($metrics['lab_diagnostics_availed']),
                    'medical_consults_count'       => $this->_fpe_count_total($metrics['medical_consults_count']),
                    'prescribed_medications_amount'=> $this->_fpe_count_total($metrics['prescribed_medications_amount']),
                    'medications_availed'          => $this->_fpe_count_total($metrics['medications_availed']),
                ];

                $rows[] = $row;
                $this->_fpe_add_tracker_totals($totals, $row);
            } catch (\Throwable $e) {
                log_message('error', "[fpe_tracker] Branch {$info['name']} error: " . $e->getMessage());
            }
        }

        return [
            'registered' => $registered,
            'date_from'  => $date_from,
            'date_to'    => $date_to,
            'rows'       => $rows,
            'size'       => count($rows),
            'totals'     => $totals,
        ];
    }

    /**
     * Per-branch dashboard metrics from EMR DB.
     */
    private function _fpe_branch_dashboard_metrics(
        $emr_db,
        string $branch_key,
        string $date_from,
        string $date_to,
        string $year
    ): array {
        $fpe_done = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT patient_id) AS cnt
            FROM patient_fpe
            WHERE is_deleted = 0
              AND DATE(added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to])->getRowArray()['cnt'] ?? 0);

        $fpe_failed = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT pr.patient_id) AS cnt
            FROM philhealth_report pr
            JOIN patient_fpe pf
                ON pf.patient_id  = pr.patient_id
               AND pf.is_deleted  = 0
               AND DATE(pf.added_on) BETWEEN ? AND ?
            WHERE pr.is_deleted      = 0
              AND pr.tranche_number  = 1
              AND pr.validate_status = 'failed'
              AND DATE(pr.added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to, $date_from, $date_to])->getRowArray()['cnt'] ?? 0);

        $fpe_encoded = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT pr.patient_id) AS cnt
            FROM philhealth_report pr
            JOIN patient_fpe pf
                ON pf.patient_id  = pr.patient_id
               AND pf.is_deleted  = 0
               AND DATE(pf.added_on) BETWEEN ? AND ?
            WHERE pr.is_deleted      = 0
              AND pr.tranche_number  = 1
              AND pr.validate_status = 'success'
              AND DATE(pr.added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to, $date_from, $date_to])->getRowArray()['cnt'] ?? 0);

        $fpe_uploaded = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT pr.patient_id) AS cnt
            FROM philhealth_report pr
            JOIN patient_fpe pf
                ON pf.patient_id  = pr.patient_id
               AND pf.is_deleted  = 0
               AND DATE(pf.added_on) BETWEEN ? AND ?
            WHERE pr.is_deleted     = 0
              AND pr.tranche_number = 1
              AND pr.submit_status IN ('success', 'resend_success', 'download_success')
              AND DATE(pr.added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to, $date_from, $date_to])->getRowArray()['cnt'] ?? 0);

        $medical_consults_count = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT patient_id) AS cnt
            FROM assessment
            WHERE is_deleted = 0
              AND assessment_type = 'ekonsulta'
              AND DATE(added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to])->getRowArray()['cnt'] ?? 0);

        $diag_db = $this->_fpe_diagnostic_db_name($branch_key);
        $lab_diagnostics_availed = 0.0;
        if ($diag_db) {
            $lab_diagnostics_availed = (float) ($emr_db->query(<<<EOT
                SELECT COALESCE(SUM(lt.price), 0) AS total
                FROM diagnostic d
                JOIN diagnostic_item di
                    ON di.diagnostic_id = d.id
                   AND di.is_deleted    = 0
                JOIN {$diag_db}.lab_test lt
                    ON lt.id = di.lab_id
                WHERE d.is_deleted = 0
                  AND DATE(d.added_on) BETWEEN ? AND ?
            EOT, [$date_from, $date_to])->getRowArray()['total'] ?? 0);
        }

        $master_db = MASTER_DB;
        $prescribed_medications_amount = (float) ($emr_db->query(<<<EOT
            SELECT COALESCE(SUM(i.selling_price * th.quantity), 0) AS total
            FROM therapeutic th
            JOIN assessment a
                ON a.id              = th.assessment_id
               AND a.is_deleted      = 0
               AND a.assessment_type = 'ekonsulta'
            JOIN {$master_db}.item i
                ON i.id         = th.medicine_id
               AND i.is_deleted = 0
            WHERE th.is_deleted = 0
              AND DATE(th.added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to])->getRowArray()['total'] ?? 0);

        $medications_availed = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT th.patient_id) AS cnt
            FROM therapeutic th
            JOIN assessment a
                ON a.id              = th.assessment_id
               AND a.is_deleted      = 0
               AND a.assessment_type = 'ekonsulta'
            WHERE th.is_deleted = 0
              AND DATE(th.added_on) BETWEEN ? AND ?
        EOT, [$date_from, $date_to])->getRowArray()['cnt'] ?? 0);

        $registered = (int) ($emr_db->query(<<<EOT
            SELECT COUNT(DISTINCT id) AS total_registered
            FROM yakap_registration_list
            WHERE is_deleted = 0
              AND eff_year   = ?
        EOT, [$year])->getRowArray()['total_registered'] ?? 0);

        $fpe_rate = 680;

        return [
            'fpe_done'                      => $fpe_done,
            'fpe_failed'                    => $fpe_failed,
            'fpe_encoded'                   => $fpe_encoded,
            'fpe_uploaded'                  => $fpe_uploaded,
            'fpe_amount_calculated'         => round($fpe_uploaded * $fpe_rate, 2),
            'fpe_amount_collected'          => 0.0,
            'medical_consults_count'        => $medical_consults_count,
            'lab_diagnostics_availed'       => round($lab_diagnostics_availed, 2),
            'prescribed_medications_amount' => round($prescribed_medications_amount, 2),
            'medications_availed'           => $medications_availed,
            'registered'                    => $registered,
        ];
    }

    /**
     * Static carry-over row from spreadsheet template.
     */
    private function _fpe_carry_over_row(): array
    {
        $fpe_uploaded = 6582;
        $fpe_rate     = 680;

        return [
            'branch_id'                     => null,
            'branch'                        => 'Carry Over',
            'type'                          => null,
            'is_carry_over'                 => true,
            'fpe_done'                      => null,
            'fpe_failed'                    => null,
            'fpe_encoded'                   => null,
            'fpe_uploaded'                  => $fpe_uploaded,
            'fpe_amount_calculated'         => round($fpe_uploaded * $fpe_rate, 2),
            'fpe_amount_collected'          => 0.0,
            'medical_consults_count'        => null,
            'lab_diagnostics_availed'       => null,
            'prescribed_medications_amount' => null,
            'medications_availed'           => null,
        ];
    }

    private function _fpe_active_branch_ids(): array
    {
        return [4, 2, 3, 1, 7];
    }

    private function _fpe_excluded_locations(): array
    {
        return [
            'Pacific Mall',
            'SM Consolacion',
            'Park Mall',
            'Island Central Mall',
            'Gaisano Liloan',
            'Gaisano Mandaue North',
            'Gaisano Cordova',
            'Gaisano Basak LLC',
            'Gaisano Centro Mandaue',
        ];
    }

    private function _fpe_is_excluded_location(string $location): bool
    {
        return in_array($location, $this->_fpe_excluded_locations(), true);
    }

    private function _fpe_branch_tracker_key(int $branch_id): ?string
    {
        $map = [
            2 => 'oak',
            3 => 'mac',
            4 => 'con',
        ];

        return $map[$branch_id] ?? null;
    }

    private function _fpe_diagnostic_db_name(string $branch_key): ?string
    {
        $map = [
            'mobile'      => MOBILE_DB,
            'oakridge'    => OAKRIDGE_DB,
            'mactan'      => MAC_DB,
            'consolacion' => CONSO_DB,
            'mantlewood'  => MANTLEWOOD_DB,
        ];

        return $map[$branch_key] ?? null;
    }

    private function _connect_branch_emr(int $branch_id)
    {
        $branch_config = config('Branch');
        $emr_db_name   = $branch_config->getEmrDB($branch_id);
        if (! $emr_db_name) {
            return null;
        }

        $db_config = config('Database');
        if (! isset($db_config->$emr_db_name)) {
            return null;
        }

        return \Config\Database::connect($emr_db_name);
    }

    private function _fpe_empty_dashboard_row(): array
    {
        return [
            'fpe_done'                      => null,
            'fpe_failed'                    => null,
            'fpe_encoded'                   => null,
            'fpe_uploaded'                  => null,
            'fpe_amount_calculated'         => null,
            'fpe_amount_collected'          => null,
            'medical_consults_count'        => null,
            'lab_diagnostics_availed'       => null,
            'prescribed_medications_amount' => null,
            'medications_availed'           => null,
        ];
    }

    private function _fpe_empty_dashboard_totals(): array
    {
        return [
            'fpe_done'                      => 0,
            'fpe_failed'                    => 0,
            'fpe_encoded'                   => 0,
            'fpe_uploaded'                  => 0,
            'fpe_amount_calculated'         => 0.0,
            'fpe_amount_collected'          => 0.0,
            'medical_consults_count'        => 0,
            'lab_diagnostics_availed'       => 0.0,
            'prescribed_medications_amount' => 0.0,
            'medications_availed'           => 0,
        ];
    }

    private function _fpe_add_dashboard_totals(array &$totals, array $row): void
    {
        foreach (array_keys($totals) as $key) {
            if ($row[$key] === null) {
                continue;
            }
            if (is_float($totals[$key])) {
                $totals[$key] += (float) $row[$key];
            } else {
                $totals[$key] += (int) $row[$key];
            }
        }
    }

    private function _fpe_count_total($value): array
    {
        return [
            'count' => $value,
            'total' => $value,
        ];
    }

    private function _fpe_empty_tracker_totals(): array
    {
        $zero_int   = ['count' => 0, 'total' => 0];
        $zero_float = ['count' => 0.0, 'total' => 0.0];

        return [
            'registered_count'              => 0,
            'reported_fpe_done_onsite'      => [
                'oak'   => 0,
                'mac'   => 0,
                'con'   => 0,
                'total' => 0,
            ],
            'received_forms'                => $zero_int,
            'fpe_in_validated'              => $zero_int,
            'emr_encoding'                  => $zero_int,
            'ihcp_portal'                   => $zero_int,
            'cis_uploading'                 => $zero_int,
            'fpe_amount'                    => $zero_float,
            'lab_diagnostics_availed'       => $zero_float,
            'medical_consults_count'        => $zero_int,
            'prescribed_medications_amount' => $zero_float,
            'medications_availed'           => $zero_int,
        ];
    }

    private function _fpe_add_tracker_totals(array &$totals, array $row): void
    {
        $totals['registered_count'] += (int) ($row['registered_count'] ?? 0);

        foreach (['oak', 'mac', 'con', 'total'] as $key) {
            $totals['reported_fpe_done_onsite'][$key] += (int) ($row['reported_fpe_done_onsite'][$key] ?? 0);
        }

        $nested_keys = [
            'received_forms',
            'fpe_in_validated',
            'emr_encoding',
            'ihcp_portal',
            'cis_uploading',
            'fpe_amount',
            'lab_diagnostics_availed',
            'medical_consults_count',
            'prescribed_medications_amount',
            'medications_availed',
        ];

        foreach ($nested_keys as $key) {
            $is_float = is_float($totals[$key]['total']);
            $count    = $row[$key]['count'] ?? 0;
            $total    = $row[$key]['total'] ?? 0;
            $totals[$key]['count'] += $is_float ? (float) $count : (int) $count;
            $totals[$key]['total'] += $is_float ? (float) $total : (int) $total;
        }
    }

    /**
     * Exam-level Service TAT from booking_details + booking_package_details.
     *
     * @return array|false
     */
    public function get_service_tat($branch_id, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null)
    {
        $tokens = $this->_serviceTatBranchDbTokens($branch_id);
        if (! $tokens) {
            return false;
        }

        $key   = $this->encryption_key;
        $binds = [];
        $sql   = $this->_serviceTatSelectSql($branch_id, $key, $date_from, $date_to, $binds);

        [$filterSql, $filterBinds] = $this->_serviceTatFilterSql($term);
        $sql .= $filterSql;
        $binds = array_merge($binds, $filterBinds);

        $sql .= " ORDER BY st.transaction_date ASC, st.transaction_no ASC, st.registered_added_on ASC";

        if ($page && $data_size) {
            $page -= 1;
            $offset = $page * $data_size;
            $sql .= " LIMIT {$offset}, {$data_size}";
        }

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_service_tat_count($branch_id, $date_from = null, $date_to = null, $term = null): int
    {
        $tokens = $this->_serviceTatBranchDbTokens($branch_id);
        if (! $tokens) {
            return 0;
        }

        $key   = $this->encryption_key;
        $binds = [];
        $sql   = "SELECT COUNT(*) AS total_count FROM (\n"
            . $this->_serviceTatAllBranchesUnionSql($branch_id, $key, $date_from, $date_to, $binds)
            . "\n) AS st WHERE 1=1";

        [$filterSql, $filterBinds] = $this->_serviceTatFilterSql($term);
        $sql .= $filterSql;
        $binds = array_merge($binds, $filterBinds);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        if (! $query) {
            return 0;
        }

        $row = $query->getRowArray();
        return (int) ($row['total_count'] ?? 0);
    }

    /**
     * Fastest release and slowest finalize per section across the whole requested range.
     *
     * RULES: Min Released Time and Max Finalized Time are range wide bounds rather than per exam
     * values, so they cannot come out of the paged row query - page two would report different
     * bounds than page one for the same section. Both are measured from in_process_on, the same
     * clock Min In Process states the standard for, so the three columns sit on one scale.
     *
     * This deliberately does not reuse get_service_tat. That query decrypts four patient columns,
     * none of which survives into a min or a max. Min Released Time is measured from emailed_on
     * on the exam row. Each half groups on its own, so a section can come back twice and is merged
     * in PHP.
     */
    public function get_service_tat_section_bounds($branch_id, $date_from = null, $date_to = null)
    {
        $tokens = $this->_serviceTatBranchDbTokens($branch_id);
        if (! $tokens || ! $date_from || ! $date_to) {
            return false;
        }

        $parts = [];
        $binds = [];
        foreach ($tokens as $branchDbToken) {
            foreach (['booking_details', 'booking_package_details'] as $source) {
                $parts[] = $this->_serviceTatSectionBoundsSql($branchDbToken, $source);
                $binds[] = $date_from;
                $binds[] = $date_to;
            }
        }

        $sql   = $this->_resolve_cross_db_sql(implode("\nUNION ALL\n", $parts));
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    private function _serviceTatSectionBoundsSql(string $branchDbToken, string $source): string
    {
        $isPackage = $source === 'booking_package_details';
        $examAlias = $isPackage ? 'bpd' : 'bd';

        // Same outward-from-`transaction` shape as the row query, for the same reason: the date is
        // the only selective filter, so it has to be applied before the exam tables are touched.
        $from = <<<EOT
FROM {$branchDbToken}.`transaction` AS t
STRAIGHT_JOIN {$branchDbToken}.booking AS b
    ON b.transaction_id = t.id
   AND b.is_deleted = 0
STRAIGHT_JOIN {$branchDbToken}.booking_details AS bd
    ON bd.booking_id = b.id
   AND bd.is_deleted = 0
EOT;

        if ($isPackage) {
            $from .= <<<EOT

STRAIGHT_JOIN {$branchDbToken}.booking_package_details AS bpd
    ON bpd.booking_detail_id = bd.id
   AND bpd.is_deleted = 0
EOT;
            $whereExtra = '';
        } else {
            $whereExtra = "\n  AND (bd.type IS NULL OR bd.type != 'package')";
        }

        $inProcess = "{$examAlias}.in_process_on";
        $emailedOn = "{$examAlias}.emailed_on";

        // A stamp that predates in_process_on is a data fault, not a zero length leg, so it is
        // dropped here the same way the per row formatter discards a negative span.
        $emailedSpan   = "CASE WHEN {$emailedOn} >= {$inProcess} THEN TIMESTAMPDIFF(SECOND, {$inProcess}, {$emailedOn}) END";
        $finalizedSpan = "CASE WHEN {$examAlias}.finalized_on >= {$inProcess} THEN TIMESTAMPDIFF(SECOND, {$inProcess}, {$examAlias}.finalized_on) END";

        return <<<EOT
SELECT
    COALESCE(NULLIF(lt.section_header, ''), lt.category_name) AS section_name,
    MIN({$emailedSpan}) AS min_emailed_seconds,
    MAX({$finalizedSpan}) AS max_finalized_seconds
{$from}
LEFT JOIN {MASTER_DB}.lab_test AS lt
    ON lt.id = {$examAlias}.test_id
   AND lt.is_deleted = 0
WHERE t.is_deleted = 0
  AND t.transaction_date BETWEEN ? AND ?
  AND {$inProcess} IS NOT NULL{$whereExtra}
GROUP BY section_name
EOT;
    }

    /**
     * Exam level in_process/finalized stamps for the Daily TAT report.
     *
     * RULES: This deliberately does not reuse get_service_tat. That query decrypts patient names and
     * runs a correlated print_log/email_log lookup for every row, all of which the Daily TAT summary
     * throws away - over a wide date range it is what makes the request time out. Only the category
     * and the two stamps the report measures are selected, and a test that never reached both stamps
     * is dropped in SQL instead of being fetched and then skipped in PHP.
     *
     * The start of the window is not in_process_on alone: imaging exams reach completed_on and
     * finalized_on without it, so it falls back to completed_on and then approved_on.
     */
    public function get_daily_tat($branch_id, $date_from, $date_to)
    {
        $tokens = $this->_serviceTatBranchDbTokens($branch_id);
        if (! $tokens) {
            return false;
        }

        $parts = [];
        $binds = [];
        foreach ($tokens as $id => $branchDbToken) {
            foreach (['booking_details', 'booking_package_details'] as $source) {
                $parts[] = $this->_dailyTatExamSelectSql($id, $branchDbToken, $source);
                $binds[] = $date_from;
                $binds[] = $date_to;
            }
        }

        $sql   = $this->_resolve_cross_db_sql(implode("\nUNION ALL\n", $parts));
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    private function _dailyTatExamSelectSql($branch_id, string $branchDbToken, string $source): string
    {
        // RULES: The transaction date is the only selective filter in this report, so the join is
        // written outward from `transaction` and pinned with STRAIGHT_JOIN. Left to reorder freely
        // the optimizer drives from the exam table and scans it in full, testing the date three
        // joins away, which is what made a single day expensive enough to lose the PHP worker.
        //
        // Only the tables the measurement needs are joined. `patient` in particular is not, even
        // though the service TAT query this was shaped from carries it: nothing here reads a patient
        // column, so joining it only silently drops an exam whose patient row was archived, pulling
        // that test out of its section average for a reason unrelated to turnaround.
        $examFrom = <<<EOT
FROM {$branchDbToken}.`transaction` AS t
STRAIGHT_JOIN {$branchDbToken}.booking AS b
    ON b.transaction_id = t.id
   AND b.is_deleted = 0
STRAIGHT_JOIN {$branchDbToken}.booking_details AS bd
    ON bd.booking_id = b.id
   AND bd.is_deleted = 0
EOT;

        if ($source === 'booking_package_details') {
            $examAlias = 'bpd';
            $examFrom .= <<<EOT

STRAIGHT_JOIN {$branchDbToken}.booking_package_details AS bpd
    ON bpd.booking_detail_id = bd.id
   AND bpd.is_deleted = 0
EOT;
            $whereExtra = '';
        } else {
            $examAlias  = 'bd';
            $whereExtra = "  AND (bd.type IS NULL OR bd.type != 'package')";
        }

        // RULES: Imaging exams reach `completed_on` and `finalized_on` without ever getting an
        // `in_process_on` stamp, so requiring that column dropped whole sections such as XRAY from
        // the report. The start of the measured window falls back to the next stamp the exam did
        // reach, which keeps the section present instead of silently missing.
        $startStamp = "COALESCE({$examAlias}.in_process_on, {$examAlias}.completed_on, {$examAlias}.approved_on)";
        $branchId   = (int) $branch_id;
        $branchName = str_replace("'", "''", $this->_serviceTatBranchName($branch_id));

        return <<<EOT
SELECT
    {$branchId} AS branch_id,
    '{$branchName}' AS branch,
    lt.category_id AS category_id,
    lt.category_name AS department,
    COALESCE(NULLIF(lt.section_header, ''), lt.category_name) AS section_name,
    {$startStamp} AS tat_start_on,
    {$examAlias}.finalized_on AS tat_end_on
{$examFrom}
STRAIGHT_JOIN {MASTER_DB}.lab_test AS lt
    ON lt.id = {$examAlias}.test_id
   AND lt.is_deleted = 0
WHERE t.is_deleted = 0
  AND t.transaction_date BETWEEN ? AND ?
  AND {$startStamp} IS NOT NULL
  AND {$examAlias}.finalized_on IS NOT NULL
  AND {$examAlias}.finalized_on >= {$startStamp}
  AND (NULLIF(lt.category_name, '') IS NOT NULL OR NULLIF(lt.section_header, '') IS NOT NULL)
{$whereExtra}
EOT;
    }

    /**
     * Pending results and finalization performance per section.
     *
     * RULES: Every figure is aggregated in SQL. The report only ever shows a handful of section
     * rows, so returning one row per exam and folding them in PHP would move thousands of rows
     * for a summary that never displays them.
     *
     * The report uses the same agreed child-category standards as Daily TAT. Its measured window
     * keeps `added_on` as a final fallback because pending-result totals include unfinished exams
     * whose progress stamps may not exist yet. Exams with no matching lab_test are dropped rather
     * than collected under a blank section.
     */
    public function get_pending_result($branch_id, $date_from, $date_to)
    {
        $tokens = $this->_serviceTatBranchDbTokens($branch_id);
        if (! $tokens) {
            return false;
        }

        $parts = [];
        $binds = [];
        foreach ($tokens as $id => $branchDbToken) {
            foreach (['booking_details', 'booking_package_details'] as $source) {
                $parts[] = $this->_pendingResultExamSelectSql($id, $branchDbToken, $source);
                $binds[] = $date_from;
                $binds[] = $date_to;
            }
        }

        $examSql = implode("\nUNION ALL\n", $parts);

        // Each finalized exam lands in exactly one of early/on_time/delay, so section totals always
        // satisfy total_exams = pending_results + early + on_time + delay.
        $sql = <<<EOT
SELECT
    ex.branch_id AS branch_id,
    ex.branch AS branch,
    ex.section AS section,
    ex.department AS department,
    MAX(ex.standard_minutes) AS standard_minutes,
    COUNT(*) AS total_exams,
    SUM(CASE WHEN ex.tat_end_on IS NULL THEN 1 ELSE 0 END) AS pending_results,
    SUM(CASE WHEN ex.tat_end_on IS NOT NULL AND ex.actual_minutes < ex.standard_minutes THEN 1 ELSE 0 END) AS early,
    SUM(CASE WHEN ex.tat_end_on IS NOT NULL AND ex.actual_minutes = ex.standard_minutes THEN 1 ELSE 0 END) AS on_time,
    SUM(CASE WHEN ex.tat_end_on IS NOT NULL AND ex.actual_minutes > ex.standard_minutes THEN 1 ELSE 0 END) AS delay,
    MIN(CASE WHEN ex.tat_end_on IS NOT NULL THEN ex.actual_minutes END) AS min_minutes,
    MAX(CASE WHEN ex.tat_end_on IS NOT NULL THEN ex.actual_minutes END) AS max_minutes,
    SUM(CASE WHEN ex.is_emailed = 1 OR ex.is_printed = 1 THEN 1 ELSE 0 END) AS released,
    SUM(ex.is_emailed) AS emailed,
    SUM(ex.is_printed) AS printed,
    SUM(CASE WHEN ex.is_emailed = 1 OR ex.is_printed = 1 THEN 1 ELSE 0 END) AS delivered
FROM (
{$examSql}
) AS ex
GROUP BY ex.branch_id, ex.branch, ex.section, ex.department
ORDER BY ex.branch ASC, ex.section ASC
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    private function _pendingResultExamSelectSql($branch_id, string $branchDbToken, string $source): string
    {
        $isPackage  = $source === 'booking_package_details';
        $examAlias  = $isPackage ? 'bpd' : 'bd';
        $printTable = $isPackage ? "'booking_package_detail'" : "'booking_detail'";

        // RULES: Driven from `transaction` and pinned with STRAIGHT_JOIN so the date range filters
        // before the release lookups below run, rather than three joins away from the driving table.
        $joins = <<<EOT
FROM {$branchDbToken}.`transaction` AS t
STRAIGHT_JOIN {$branchDbToken}.booking AS b
    ON b.transaction_id = t.id
   AND b.is_deleted = 0
STRAIGHT_JOIN {$branchDbToken}.booking_details AS bd
    ON bd.booking_id = b.id
   AND bd.is_deleted = 0
EOT;

        if ($isPackage) {
            $joins .= <<<EOT

STRAIGHT_JOIN {$branchDbToken}.booking_package_details AS bpd
    ON bpd.booking_detail_id = bd.id
   AND bpd.is_deleted = 0
EOT;
            $whereExtra = '';
        } else {
            $whereExtra = "\n  AND (bd.type IS NULL OR bd.type != 'package')";
        }

        // RULES: added_on closes the fallback chain so a finalized exam always has a start to measure
        // from. Without it an exam missing all three progress stamps would be finalized yet fall into
        // none of the early/on_time/delay buckets, and the section counts would stop adding up.
        $startStamp = "COALESCE({$examAlias}.in_process_on, {$examAlias}.completed_on, {$examAlias}.approved_on, {$examAlias}.added_on)";

        $isPrinted = <<<EOT
CASE
    WHEN EXISTS (
            SELECT 1
            FROM {$branchDbToken}.print_log AS pl
            WHERE pl.booking_id = b.id
              AND pl.source_id = {$examAlias}.id
              AND pl.table_from = {$printTable}
         )
    THEN 1
    ELSE 0
END
EOT;

        $isEmailed = <<<EOT
CASE
    WHEN {$examAlias}.emailed_on IS NOT NULL
      OR EXISTS (
            SELECT 1
            FROM {$branchDbToken}.email_log AS el
            WHERE el.booking_id = b.id
              AND el.source_id = {$examAlias}.id
              AND el.table_from = {$printTable}
         )
    THEN 1
    ELSE 0
END
EOT;

        // Release/delivery has no independent event table in the branch schema. The Service TAT
        // report already treats the first print or email trail as the delivery stamp, so Pending
        // Result uses that same definition while also exposing each channel separately.
        // Daily TAT groups and applies standards by the child category name (`lt.category_name`).
        // Pending Result must use that same key; section_header is only a fallback for legacy tests.
        $sectionExpr = "COALESCE(NULLIF(lt.category_name, ''), NULLIF(lt.section_header, ''))";
        $standardTat = $this->_pendingResultStandardMinutesSql($sectionExpr);
        $branchId    = (int) $branch_id;
        $branchName  = str_replace("'", "''", $this->_serviceTatBranchName($branch_id));

        return <<<EOT
SELECT
    {$branchId} AS branch_id,
    '{$branchName}' AS branch,
    {$sectionExpr} AS section,
    COALESCE(NULLIF(parent.name, ''), NULLIF(sub.name, ''), lt.category_name) AS department,
    {$standardTat} AS standard_minutes,
    {$examAlias}.finalized_on AS tat_end_on,
    GREATEST(0, TIMESTAMPDIFF(MINUTE, {$startStamp}, {$examAlias}.finalized_on)) AS actual_minutes,
    {$isEmailed} AS is_emailed,
    {$isPrinted} AS is_printed
{$joins}
INNER JOIN {MASTER_DB}.lab_test AS lt
    ON lt.id = {$examAlias}.test_id
   AND lt.is_deleted = 0
LEFT JOIN {MASTER_DB}.category AS sub
    ON sub.id = lt.category_id
   AND sub.is_deleted = 0
LEFT JOIN {MASTER_DB}.category AS parent
    ON parent.id = sub.category_id
   AND parent.is_deleted = 0
WHERE t.is_deleted = 0
  AND t.transaction_date BETWEEN ? AND ?
  AND {$sectionExpr} IS NOT NULL{$whereExtra}
EOT;
    }

    /**
     * SQL CASE generated from the same section standards used by Daily TAT.
     */
    private function _pendingResultStandardMinutesSql(string $sectionExpr): string
    {
        $cases = [];
        foreach (TAT_STANDARD_MINUTES as $section => $minutes) {
            $escaped = str_replace("'", "''", strtoupper(trim((string) $section)));
            $cases[] = "WHEN '{$escaped}' THEN " . (float) $minutes;
        }

        return "CASE UPPER(TRIM({$sectionExpr}))\n        "
            . implode("\n        ", $cases)
            . "\n        ELSE NULL\n    END";
    }

    /**
     * Whether a branch exam table has a column, cached per database and table.
     *
     * RULES: The branch databases share a structure on paper but drift in practice, so a column that
     * is only needed for a fallback is verified before it reaches the SELECT. Without the check one
     * branch missing the column would turn the whole report into an "Unknown column" error.
     */
    private function _serviceTatHasColumn(string $dbPlaceholder, string $table, string $column): bool
    {
        $database = $this->_resolve_cross_db_sql($dbPlaceholder);
        $cacheKey = $database . '.' . $table;

        if (! isset($this->service_tat_columns[$cacheKey])) {
            $query = $this->db->query(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $table]
            );

            $columns = [];
            foreach ($query ? $query->getResultArray() : [] as $row) {
                $columns[strtolower($row['COLUMN_NAME'])] = true;
            }

            $this->service_tat_columns[$cacheKey] = $columns;
        }

        return isset($this->service_tat_columns[$cacheKey][strtolower($column)]);
    }

    private function _serviceTatBranchDbToken($branch_id): ?string
    {
        $tokens = $this->_serviceTatBranchDbTokens($branch_id);
        if (count($tokens) !== 1) {
            return null;
        }

        return reset($tokens) ?: null;
    }

    /**
     * Exclude census rows already saved on any diagnostic send_out_entry.
     */
    private function _sendoutCensusUnassignedSql(): string
    {
        $clauses = [];
        foreach (['{MOBILE_DB}', '{OAKRIDGE_DB}', '{MAC_DB}', '{CONSO_DB}', '{MANTLEWOOD_DB}'] as $dbToken) {
            $clauses[] = <<<EOT
AND NOT EXISTS (
    SELECT 1
    FROM {$dbToken}.send_out_entry AS send_out_entry
    WHERE send_out_entry.sendout_census_id = census.id
      AND send_out_entry.is_deleted = 0
)
EOT;
        }

        return "\n" . implode("\n", $clauses);
    }

    private function _serviceTatBranchDbTokens($branch_id): array
    {
        $map = [
            1 => '{MOBILE_DB}',
            2 => '{OAKRIDGE_DB}',
            3 => '{MAC_DB}',
            4 => '{CONSO_DB}',
            7 => '{MANTLEWOOD_DB}',
        ];

        if ($branch_id === null || $branch_id === '' || strtolower((string) $branch_id) === 'all') {
            return $map;
        }

        $id = (int) $branch_id;
        return isset($map[$id]) ? [$id => $map[$id]] : [];
    }

    private function _serviceTatBranchName($branch_id): string
    {
        $map = [
            1 => 'Mobile',
            2 => 'Oakridge',
            3 => 'Mactan',
            4 => 'Consolacion',
            7 => 'Mantlewood',
        ];

        return $map[(int) $branch_id] ?? '';
    }

    /**
     * Outer filters that can only be applied to the derived table.
     *
     * RULES: The date range is deliberately not handled here. It is pushed into each half of the
     * union instead, so it filters on the indexed `transaction.transaction_date` before the
     * expensive per row work runs. The search term has to stay outside because it matches derived
     * columns such as the decrypted patient name.
     */
    private function _serviceTatFilterSql($term): array
    {
        $sql   = '';
        $binds = [];

        if ($term) {
            $sql .= <<<EOT

AND (
    st.transaction_no LIKE ?
    OR st.patient_name LIKE ?
    OR st.exam_name LIKE ?
    OR st.exam_id LIKE ?
)
EOT;
            $like = "%{$term}%";
            $binds[] = $like;
            $binds[] = $like;
            $binds[] = $like;
            $binds[] = $like;
        }

        return [$sql, $binds];
    }

    private function _serviceTatSelectSql($branch_id, string $key, $date_from, $date_to, array &$binds): string
    {
        return "SELECT st.* FROM (\n"
            . $this->_serviceTatAllBranchesUnionSql($branch_id, $key, $date_from, $date_to, $binds)
            . "\n) AS st WHERE 1=1";
    }

    private function _serviceTatAllBranchesUnionSql($branch_id, string $key, $date_from, $date_to, array &$binds): string
    {
        $unions = [];
        foreach ($this->_serviceTatBranchDbTokens($branch_id) as $id => $token) {
            $unions[] = $this->_serviceTatUnionSql($id, $token, $key, $date_from, $date_to, $binds);
        }

        return implode("\nUNION ALL\n", $unions);
    }

    private function _serviceTatUnionSql($branch_id, string $branchDbToken, string $key, $date_from, $date_to, array &$binds): string
    {
        // The two halves are built in the same order their placeholders appear in the final SQL,
        // so each appends its own date binds as it goes.
        $bdPart  = $this->_serviceTatExamSelectSql($branch_id, $branchDbToken, $key, 'booking_details', $date_from, $date_to, $binds);
        $bpdPart = $this->_serviceTatExamSelectSql($branch_id, $branchDbToken, $key, 'booking_package_details', $date_from, $date_to, $binds);

        return $bdPart . "\nUNION ALL\n" . $bpdPart;
    }

    private function _serviceTatExamSelectSql($branch_id, string $branchDbToken, string $key, string $source, $date_from, $date_to, array &$binds): string
    {
        $branchName = $this->_serviceTatBranchName($branch_id);
        $branchId   = (int) $branch_id;
        $isPackage  = $source === 'booking_package_details';

        // RULES: The join is written outward from `transaction` and pinned with STRAIGHT_JOIN so the
        // date range below filters first. Every row of this select costs four AES_DECRYPT calls, so
        // letting the optimizer drive from the exam table meant paying that for the whole history
        // before the date was ever applied.
        $leadJoins = <<<EOT
FROM {$branchDbToken}.`transaction` AS t
STRAIGHT_JOIN {$branchDbToken}.booking AS b
    ON b.transaction_id = t.id
   AND b.is_deleted = 0
STRAIGHT_JOIN {$branchDbToken}.patient AS p
    ON p.id = b.patient_id
   AND p.is_deleted = 0
STRAIGHT_JOIN {$branchDbToken}.booking_details AS bd
    ON bd.booking_id = b.id
   AND bd.is_deleted = 0
EOT;

        if ($isPackage) {
            $examAlias   = 'bpd';
            $fromJoins   = $leadJoins . <<<EOT

STRAIGHT_JOIN {$branchDbToken}.booking_package_details AS bpd
    ON bpd.booking_detail_id = bd.id
   AND bpd.is_deleted = 0
LEFT JOIN {MASTER_DB}.lab_test AS lt
    ON lt.id = bpd.test_id
   AND lt.is_deleted = 0
EOT;
            $chargeToExpr = 'COALESCE(bd.charge_to, b.company_id, b.hmo_id, t.hmo_id)';
            $whereExtra   = '';
            $statusCol    = 'bpd.status';
            $resultStatus = 'bpd.result_status';
        } else {
            $examAlias = 'bd';
            $fromJoins = $leadJoins . <<<EOT

LEFT JOIN {MASTER_DB}.lab_test AS lt
    ON lt.id = bd.test_id
   AND lt.is_deleted = 0
EOT;
            $chargeToExpr = 'COALESCE(bd.charge_to, b.company_id, b.hmo_id, t.hmo_id)';
            $whereExtra   = "AND (bd.type IS NULL OR bd.type != 'package')";
            $statusCol    = 'bd.status';
            $resultStatus = 'bd.result_status';
        }

        $dateFilter = '';
        if ($date_from && $date_to) {
            $dateFilter = "\nAND t.transaction_date BETWEEN ? AND ?";
            $binds[]    = $date_from;
            $binds[]    = $date_to;
        }

        $patientName = <<<EOT
TRIM(CONCAT(
    CAST(AES_DECRYPT(p.last_name, '{$key}') AS CHAR), ', ',
    CAST(AES_DECRYPT(p.first_name, '{$key}') AS CHAR), ' ',
    IFNULL(CAST(AES_DECRYPT(p.middle_name, '{$key}') AS CHAR), ''), ' ',
    IFNULL(CAST(AES_DECRYPT(p.suffix, '{$key}') AS CHAR), '')
))
EOT;

        // RULES: Emailed On is the exam row's emailed_on. Released On, Printed On, and Delivered On
        // are not sourced here - they used to pull print_log / derived LEAST stamps and stayed empty.
        $emailedOn = "{$examAlias}.emailed_on";

        $servedOn = <<<EOT
COALESCE(
    pq.lab_served_on,
    pq.diag_served_on,
    pq.health_served_on,
    pq.csr_served_on
)
EOT;

        // RULES: `approved_on` is the result approval, so it lands well after the exam is already in
        // process. Measuring the CSR legs against it made Approve to In Process run backwards, and the
        // duration helper drops negative spans, which is why that column was always empty. The CSR
        // transaction is the queue's own csr_served_on. There is deliberately no fallback to
        // t.added_on: with no queue row the leg is unmeasurable, and defaulting to the transaction
        // stamp made Reg to Approve report a self-referential zero instead of an honest blank.
        $approvedCsrOn = 'pq.csr_served_on';

        // RULES: The EMR tracks these lifecycle states as `status` strings ('waived', 'to follow',
        // 'send out'), not as timestamps, so the matching *_on columns stay empty and the report used
        // to show them all as null. Each stamp keeps its stored column whenever that holds a value
        // and otherwise falls back to when the row last changed, which is the only time available for
        // a status transition. A branch without `updated_on` falls back to NULL rather than erroring.
        $stateStamp = $this->_serviceTatHasColumn($branchDbToken, $source, 'updated_on')
            ? "{$examAlias}.updated_on"
            : 'NULL';

        $statusStamp = function (string $status) use ($examAlias, $stateStamp): string {
            return "CASE WHEN LOWER(TRIM({$examAlias}.status)) = '{$status}' THEN {$stateStamp} ELSE NULL END";
        };

        // RULES: The result lifecycle is tracked on `result_status`, not on `status`, so a state that
        // belongs to the result side has to be matched there. Stored values are written both spaced
        // and underscored ('for finalization' / 'for_finalization'), so the underscore is normalised
        // away before comparing.
        $resultStatusStamp = function (string $status) use ($resultStatus, $stateStamp): string {
            return "CASE WHEN LOWER(TRIM(REPLACE({$resultStatus}, '_', ' '))) = '{$status}' THEN {$stateStamp} ELSE NULL END";
        };

        // RULES: Laboratory Result series stamps live on booking_details (and the package twin).
        // The series tabs map to these columns:
        //   Accepted On     -> approved_on (specimen accepted for processing — not performed/processed)
        //   Extracted On    -> extracted_on, then performed_on (draw/performed stamp)
        //   Processed On    -> processed_on (machine run — selected separately below)
        //   In Process      -> in_process_on
        //   Send Out        -> result_out_on / status 'send out'
        //   Completed       -> completed_on
        //   For Finalization-> approved_on (same stamp, kept for the result-status column)
        //   Finalized       -> finalized_on
        $examApprovedOn = $this->_serviceTatHasColumn($branchDbToken, $source, 'approved_on')
            ? "{$examAlias}.approved_on"
            : 'NULL';

        $bookingPerformedOn = $this->_serviceTatHasColumn($branchDbToken, 'booking', 'performed_on')
            ? 'b.performed_on'
            : 'NULL';

        $examPerformedOn = $this->_serviceTatHasColumn($branchDbToken, $source, 'performed_on')
            ? "{$examAlias}.performed_on"
            : 'NULL';

        $extractedOn = 'COALESCE(' . implode(', ', [
            "{$examAlias}.extracted_on",
            $examPerformedOn,
            $bookingPerformedOn,
            $statusStamp('extracted'),
            $resultStatusStamp('extracted'),
        ]) . ')';

        $acceptedOn = $examApprovedOn;

        $waivedOn    = "COALESCE({$examAlias}.waived_on, " . $statusStamp('waived') . ')';
        $toFollowOn  = "COALESCE({$examAlias}.to_follow_on, " . $statusStamp('to follow') . ')';

        // Send Out series: prefer result_out_on (schema), then send_out_on if present, then status.
        $resultOutOn = $this->_serviceTatHasColumn($branchDbToken, $source, 'result_out_on')
            ? "{$examAlias}.result_out_on"
            : 'NULL';
        $sendOutCol  = $this->_serviceTatHasColumn($branchDbToken, $source, 'send_out_on')
            ? "{$examAlias}.send_out_on"
            : 'NULL';
        $sendOutOn   = 'COALESCE(' . implode(', ', [
            $resultOutOn,
            $sendOutCol,
            $statusStamp('send out'),
        ]) . ')';

        // RULES: For Finalization has no *_on column of its own. The stamp is `approved_on`, the
        // point the result was approved and so became ready to finalize. Unlike the status-derived
        // stamps this survives the exam moving on to finalized, so the column reads for historical
        // rows too. The result_status derivation is kept behind it for a row sitting in the state
        // that has not been approved yet. completed_on is not reused - that is the Completed series.
        $forFinalizationOn = "COALESCE({$examAlias}.approved_on, " . $resultStatusStamp('for finalization') . ')';

        // RULES: An inter-branch transfer is marked by `booking_details.new_transaction_id`, which is
        // how the rest of this file detects transferred-out lines. The previous version gated on
        // `inter_branch_id`/`new_branch_id` but returned `send_out_on`, so it could only ever mirror
        // the send-out stamp. `new_transaction_id` lives on booking_details, which the package query
        // joins as `bd`, so both halves of the union read it from there.
        $interBranchStamp = $this->_serviceTatHasColumn($branchDbToken, 'booking_details', 'updated_on')
            ? 'bd.updated_on'
            : 'NULL';

        $interBranchOn = <<<EOT
CASE
    WHEN bd.new_transaction_id IS NOT NULL THEN {$interBranchStamp}
    ELSE NULL
END
EOT;

        return <<<EOT
SELECT
    {$branchId} AS branch_id,
    '{$branchName}' AS branch,
    CASE
        WHEN LOWER(IFNULL(NULLIF(b.origin, ''), 'csr')) IN ('csr', 'kiosk') THEN 'WALK-IN'
        ELSE UPPER(IFNULL(NULLIF(b.type, ''), IFNULL(NULLIF(b.origin, ''), 'WALK-IN')))
    END AS transaction_type,
    CASE
        WHEN b.pricelist = 'hmo' OR b.hmo_id IS NOT NULL OR t.hmo_id IS NOT NULL THEN 'HMO'
        WHEN b.company_id IS NOT NULL OR bd.charge_to IS NOT NULL THEN 'CORPORATE'
        ELSE 'CASH'
    END AS availment_type,
    t.transaction_date AS transaction_date,
    company.account_number AS account_id,
    company.name AS company,
    COALESCE(p.patient_no, p.master_id, p.id) AS patient_id,
    {$patientName} AS patient_name,
    t.transaction_no AS transaction_no,
    {$statusCol} AS transaction_status,
    {$resultStatus} AS exam_status,
    lt.category_name AS department,
    lt.category_id AS category_id,
    COALESCE(NULLIF(lt.section_header, ''), lt.category_name) AS section_name,
    lt.exam_id AS exam_id,
    lt.name AS exam_name,
    {$examAlias}.added_on AS registered_added_on,
    {$approvedCsrOn} AS approved_csr_transacted_on,
    {$servedOn} AS served_on,
    {$extractedOn} AS extracted_on,
    {$waivedOn} AS waived_on,
    {$toFollowOn} AS to_follow_on,
    {$sendOutOn} AS send_out_on,
    {$interBranchOn} AS inter_branch_on,
    {$acceptedOn} AS accepted_on,
    {$examAlias}.processed_on AS processed_on,
    {$examAlias}.in_process_on AS in_process_result_status,
    {$examAlias}.completed_on AS completed_result_status,
    {$forFinalizationOn} AS for_finalization_result_status,
    {$examAlias}.finalized_on AS finalized_result_status,
    NULL AS released_on,
    NULL AS printed_on,
    {$emailedOn} AS emailed_on,
    NULL AS delivered_on,
    lt.tat AS min_in_process
{$fromJoins}
LEFT JOIN {$branchDbToken}.patient_queue AS pq
    ON pq.is_deleted = 0
   AND pq.id = COALESCE(
        NULLIF(t.queue_id, 0),
        (
            SELECT pq2.id
            FROM {$branchDbToken}.patient_queue AS pq2
            WHERE pq2.transaction_id = t.id
              AND pq2.is_deleted = 0
            ORDER BY pq2.id DESC
            LIMIT 1
        )
    )
LEFT JOIN {MASTER_DB}.company AS company
    ON company.id = {$chargeToExpr}
   AND company.is_deleted = 0
WHERE t.is_deleted = 0{$dateFilter}
{$whereExtra}
EOT;
    }

    /**
     * Allowed tabs for the list of medical practitioners report.
     */
    public function get_allowed_medical_practitioner_tabs(): array
    {
        return ['prof_fee', 'certification'];
    }

    /**
     * Union EMR users across clinic EMR DBs, drop nurse/admin, enrich from master doctor,
     * and shape rows for the PROF FEE / CERTIFICATION Excel tabs.
     *
     * @return array{size:int,data:array}
     */
    public function get_list_of_medical_practitioners(
        string $tab,
        $branch_id = null,
        $status = 'active',
        $term = null,
        $page = null,
        $data_size = null
    ): array {
        $sources = $this->_medical_practitioner_emr_sources($branch_id !== null && $branch_id !== '' ? (int) $branch_id : null);
        if (! $sources) {
            return ['size' => 0, 'data' => []];
        }

        $unionParts = [];
        foreach ($sources as $id => $info) {
            $unionParts[] = <<<EOT
SELECT
    u.master_doctor_id,
    u.full_name,
    u.username,
    u.role_id,
    u.role_name,
    u.specializations,
    u.status,
    {$id} AS branch_id,
    '{$info['name']}' AS branch_name
FROM {$info['token']}.`user` u
WHERE u.is_deleted = 0
  AND LOWER(IFNULL(u.role_name, '')) NOT IN ('nurse', 'admin')
  AND IFNULL(u.role_id, 0) <> 2
EOT;
        }

        $unionSql = implode("\nUNION ALL\n", $unionParts);

        $binds = [];
        $sql   = <<<EOT
SELECT
    emr.master_doctor_id,
    emr.full_name AS emr_full_name,
    emr.username,
    emr.role_name,
    emr.specializations AS user_specializations,
    emr.branch_id,
    emr.branch_name,
    emr.status,
    doctor.full_name AS doctor_full_name,
    doctor.tin_no,
    doctor.consult_pf_fee,
    doctor.wht_percentage,
    doctor.specializations AS doctor_specializations,
    spec.name AS field_of_specialization
FROM (
{$unionSql}
) emr
LEFT JOIN {MASTER_DB}.doctor AS doctor
    ON doctor.id = emr.master_doctor_id
   AND doctor.is_deleted = 0
LEFT JOIN {OAKRIDGE_EMR_DB}.specialization AS spec
    ON spec.id = COALESCE(NULLIF(doctor.specializations, ''), NULLIF(emr.specializations, ''))
   AND spec.is_deleted = 0
WHERE emr.master_doctor_id IS NOT NULL
EOT;

        if ($status && strtolower((string) $status) !== 'all') {
            $sql .= <<<EOT

  AND emr.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

  AND (
        emr.full_name LIKE ?
     OR emr.username LIKE ?
     OR doctor.full_name LIKE ?
     OR doctor.tin_no LIKE ?
  )
EOT;
            $like     = '%' . $term . '%';
            $binds[]  = $like;
            $binds[]  = $like;
            $binds[]  = $like;
            $binds[]  = $like;
        }

        $sql   = $this->_resolve_cross_db_sql($sql);
        $db    = \Config\Database::connect();
        $query = $db->query($sql, $binds);
        $rows  = $query ? $query->getResultArray() : [];

        $deduped = $this->_dedupe_medical_practitioners($rows);
        $shaped  = [];
        foreach ($deduped as $row) {
            $shaped[] = $this->_shape_medical_practitioner_row($row, $tab);
        }

        usort($shaped, static function ($a, $b) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $size = count($shaped);

        if ($page && $data_size) {
            $page   = (int) $page;
            $limit  = (int) $data_size;
            $offset = max(0, ($page - 1) * $limit);
            $shaped = array_slice($shaped, $offset, $limit);
        }

        return ['size' => $size, 'data' => $shaped];
    }

    /**
     * EMR schemas for the medical practitioners union, keyed by branch id.
     */
    private function _medical_practitioner_emr_sources(?int $branch_id): array
    {
        $map = [
            1 => ['token' => '{MOBILE_EMR_DB}', 'name' => 'Mobile'],
            2 => ['token' => '{OAKRIDGE_EMR_DB}', 'name' => 'Oakridge'],
            3 => ['token' => '{MAC_EMR_DB}', 'name' => 'Mactan'],
            4 => ['token' => '{CONSO_EMR_DB}', 'name' => 'Consolacion'],
            7 => ['token' => '{MANTLEWOOD_EMR_DB}', 'name' => 'Mantlewood'],
        ];

        if ($branch_id !== null) {
            return isset($map[$branch_id]) ? [$branch_id => $map[$branch_id]] : [];
        }

        return $map;
    }

    /**
     * One row per person. Prefer Oakridge when the same practitioner exists in multiple EMRs.
     */
    private function _dedupe_medical_practitioners(array $rows): array
    {
        $deduped = [];

        foreach ($rows as $row) {
            $masterId = $row['master_doctor_id'] ?? null;
            if ($masterId !== null && $masterId !== '') {
                $key = 'doctor:' . $masterId;
            } else {
                $username = strtolower(trim((string) ($row['username'] ?? '')));
                if ($username === '') {
                    continue;
                }
                $key = 'user:' . $username;
            }

            $branchName = $row['branch_name'] ?? null;
            $branchId   = (int) ($row['branch_id'] ?? 0);

            if (! isset($deduped[$key])) {
                $row['branches'] = $branchName ? [$branchName] : [];
                $deduped[$key]   = $row;
                continue;
            }

            if ($branchName && ! in_array($branchName, $deduped[$key]['branches'], true)) {
                $deduped[$key]['branches'][] = $branchName;
            }

            $existingBranchId = (int) ($deduped[$key]['branch_id'] ?? 0);
            if ($existingBranchId !== 2 && $branchId === 2) {
                $branches          = $deduped[$key]['branches'];
                $row['branches']   = $branches;
                $deduped[$key]     = $row;
            }
        }

        return array_values($deduped);
    }

    /**
     * Map a deduped EMR+doctor row to the Excel tab payload.
     */
    private function _shape_medical_practitioner_row(array $row, string $tab): array
    {
        $name = trim((string) (($row['doctor_full_name'] ?: $row['emr_full_name']) ?? ''));
        $tin  = trim(preg_replace('/\s+/', ' ', (string) ($row['tin_no'] ?? '')));
        $gross = (float) ($row['consult_pf_fee'] ?? 0);
        $whtPct = ($row['wht_percentage'] !== null && $row['wht_percentage'] !== '')
            ? (float) $row['wht_percentage']
            : 0.0;
        $rate     = $whtPct / 100;
        $withheld = round($gross * $rate, 2);

        $shaped = [
            'name'              => $name,
            'tin'               => $tin,
            'address'           => '',
            'master_doctor_id'  => $row['master_doctor_id'] !== null && $row['master_doctor_id'] !== ''
                ? (int) $row['master_doctor_id']
                : null,
            'username'          => $row['username'] ?? null,
            'role_name'         => $row['role_name'] ?? null,
            'branches'          => $row['branches'] ?? [],
        ];

        if ($tab === 'prof_fee') {
            $shaped['gross_amount']      = round($gross, 2);
            $shaped['withholding_rate']  = $rate;
            $shaped['amount_withheld']   = $withheld;
        } else {
            $shaped['field_of_specialization'] = $row['field_of_specialization'] ?? null;
        }

        return $shaped;
    }
}
