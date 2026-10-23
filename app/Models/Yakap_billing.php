<?php

namespace App\Models;

use App\Core\MYT_Model;
use CodeIgniter\Database\BaseConnection;
use Config\Branch;

/**
 * PHILHEALTH - YAKAP (company 3210) aggregated tranche billing.
 *
 * Master columns (run on mytemr_master before using this API):
 *
 * ALTER TABLE billing_entry
 *   ADD COLUMN master_patient_id int(11) DEFAULT NULL AFTER transaction_id,
 *   ADD COLUMN as_of_date DATE DEFAULT NULL AFTER master_patient_id,
 *   ADD COLUMN yakap_tranche TINYINT DEFAULT NULL AFTER as_of_date,
 *   ADD COLUMN yakap_patient_ids TEXT DEFAULT NULL AFTER yakap_tranche,
 *   ADD COLUMN yakap_master_patient_ids TEXT DEFAULT NULL AFTER yakap_patient_ids,
 *   ADD COLUMN yakap_patient_names TEXT DEFAULT NULL AFTER yakap_master_patient_ids;
 */
class Yakap_billing extends MYT_Model
{
    public const COMPANY_ID = 3210;
    public const FPE_AMOUNT = 680.0;
    public const CSR_AMOUNT = 1020.0;

    /**
     * @var array<string,array{target:float,weight:float}>
     */
    protected const KPI_TARGETS = [
        'consultation' => ['target' => 1.00, 'weight' => 0.30],
        'laboratory'   => ['target' => 0.50, 'weight' => 0.30],
        'antibiotic'   => ['target' => 0.15, 'weight' => 0.10],
        'ncd'          => ['target' => 0.20, 'weight' => 0.30],
    ];

    public static function is_yakap($company_id = null, $charge_to = null): bool
    {
        return (int) $company_id === self::COMPANY_ID || (int) $charge_to === self::COMPANY_ID;
    }

    public static function tranche_from_entry(array $entry): ?int
    {
        if (isset($entry['yakap_tranche']) && $entry['yakap_tranche'] !== '' && $entry['yakap_tranche'] !== null) {
            $tranche = (int) $entry['yakap_tranche'];
            return ($tranche === 1 || $tranche === 2) ? $tranche : null;
        }

        $channel = (string) ($entry['billing_channel'] ?? '');
        if ($channel === 'tranche_1') {
            return 1;
        }
        if ($channel === 'tranche_2') {
            return 2;
        }

        if (!self::is_yakap($entry['company_id'] ?? null, $entry['charge_to'] ?? null)) {
            return null;
        }

        $transaction_no = (string) ($entry['transaction_no'] ?? '');
        if ($transaction_no === '01') {
            return 1;
        }
        if ($transaction_no === '02') {
            return 2;
        }

        return null;
    }

    public static function fpe_booking_details(): array
    {
        return self::tranche_1_booking_details();
    }

    public static function tranche_1_booking_details(): array
    {
        return [
            [
                'id' => null,
                'result_status' => null,
                'result_approval' => null,
                'type' => null,
                'test_id' => null,
                'type_name' => 'YAKAP TRANCHE 1',
            ],
        ];
    }

    public static function tranche_2_booking_details(): array
    {
        return [
            [
                'id' => null,
                'result_status' => null,
                'result_approval' => null,
                'type' => null,
                'test_id' => null,
                'type_name' => 'YAKAP TRANCHE 2',
            ],
        ];
    }

    public static function booking_details_for_tranche(?int $tranche): array
    {
        if ($tranche === 2) {
            return self::tranche_2_booking_details();
        }
        if ($tranche === 1) {
            return self::tranche_1_booking_details();
        }

        return [];
    }

    public function is_fpe_entry(array $entry, $charge_to = null): bool
    {
        if (!self::is_yakap($entry['company_id'] ?? null, $charge_to ?? ($entry['charge_to'] ?? null))) {
            return false;
        }

        $tranche = self::tranche_from_entry($entry);
        if ($tranche === 1) {
            return true;
        }

        return $tranche === null && empty($entry['booking_id']) && empty($entry['transaction_id']);
    }

    public function is_tranche_entry(array $entry, $charge_to = null): bool
    {
        if (self::tranche_from_entry($entry) !== null) {
            return true;
        }

        return $this->is_fpe_entry($entry, $charge_to);
    }

    /**
     * Unbilled YAKAP rows for the given branches (0–2 aggregated rows each).
     *
     * @param array<int,string> $branchDbMap
     * @return array{data: array, size: int}
     */
    public function get_transactions(
        array $branchDbMap,
        $date_from = null,
        $date_to = null,
        $page = null,
        $data_size = null
    ): array {
        $rows = [];
        foreach ($branchDbMap as $branch_id => $group_name) {
            $rows = array_merge(
                $rows,
                $this->get_transactions_for_branch((int) $branch_id, (string) $group_name, $date_from, $date_to)
            );
        }

        usort($rows, static function ($a, $b) {
            $date = strcmp((string) ($b['transaction_date'] ?? ''), (string) ($a['transaction_date'] ?? ''));
            if ($date !== 0) {
                return $date;
            }

            return ((int) ($a['branch_id'] ?? 0)) <=> ((int) ($b['branch_id'] ?? 0));
        });

        $size = count($rows);
        if ($page && $data_size) {
            $page = max(1, (int) $page);
            $rows = array_slice($rows, ($page - 1) * (int) $data_size, (int) $data_size);
        }

        return [
            'data' => $rows,
            'size' => $size,
        ];
    }

    /**
     * Unbilled YAKAP tranche 1 + 2 for one branch. FPE/KPI from EMR; CSR lists from $csrDb.
     *
     * @return array<int,array>
     */
    public function get_transactions_for_branch(
        int $branch_id,
        string $branch_name,
        $date_from = null,
        $date_to = null,
        $csrDb = null
    ): array {
        $as_of = $this->as_of_date($date_to);
        $fpe_from = date('Y-01-01', strtotime($as_of));

        $emr_db = $this->connect_emr($branch_id);
        if (!$emr_db) {
            return [];
        }

        $fpe_patients = $this->get_fpe_patients($emr_db, $fpe_from, $as_of);
        if (!$fpe_patients) {
            return [];
        }

        $billed = $this->get_billed_tranches($branch_id, $as_of);
        $kpi_ids = $this->get_kpi_patient_ids($emr_db, $fpe_from, $as_of);
        $tranche2_ids = $this->unique_kpi_patient_ids($kpi_ids);
        $tranche2_patients = $this->patients_in_set($fpe_patients, $tranche2_ids);
        $tranche1_patients = $this->patients_not_in_set($fpe_patients, $tranche2_ids);

        $csr_db = $csrDb instanceof BaseConnection ? $csrDb : $this->connect_csr($branch_name);
        $t1_csr = $this->get_csr_lists($csr_db, $this->master_ids_from_patients($tranche1_patients), $fpe_from, $as_of);
        $t2_csr = $this->get_csr_lists($csr_db, $this->master_ids_from_patients($tranche2_patients), $fpe_from, $as_of);

        $rows = [];
        if (!isset($billed[1]) && $tranche1_patients) {
            $rows[] = $this->build_tranche_row(
                1,
                $as_of,
                $branch_id,
                $branch_name,
                $tranche1_patients,
                count($tranche1_patients) * self::FPE_AMOUNT,
                null,
                null,
                $t1_csr['endorsement_ids']
            );
        }

        if (!isset($billed[2])) {
            $rows[] = $this->build_tranche_row(
                2,
                $as_of,
                $branch_id,
                $branch_name,
                $tranche2_patients,
                $this->second_tranch_amount(count($fpe_patients), $kpi_ids),
                $t2_csr['transaction_ids'],
                $t2_csr['booking_ids'],
                $t2_csr['endorsement_ids']
            );
        }

        return $rows;
    }

    public function get_fpe_patient_name(int $branch_id, int $master_patient_id): ?string
    {
        $master = new Patient_master();
        $row = $master->get_patient_name($master_patient_id);
        if ($row && !empty($row->patient_name)) {
            return $row->patient_name;
        }

        $emr_db = $this->connect_emr($branch_id);
        if (!$emr_db) {
            return null;
        }

        $key = $this->encryption_key;
        $sql = <<<EOT
SELECT TRIM(CONCAT(
    CAST(AES_DECRYPT(patient.last_name, ?) AS CHAR), ', ',
    CAST(AES_DECRYPT(patient.first_name, ?) AS CHAR), ' ',
    IFNULL(CAST(AES_DECRYPT(patient.middle_name, ?) AS CHAR), ''), ' ',
    IFNULL(CAST(AES_DECRYPT(patient.suffix, ?) AS CHAR), '')
)) AS patient_name
FROM patient
WHERE patient.is_deleted = 0
AND patient.master_patient_id = ?
LIMIT 1
EOT;
        $query = $emr_db->query($sql, [$key, $key, $key, $key, $master_patient_id]);
        $emr_row = $query ? $query->getRowArray() : null;

        return $emr_row['patient_name'] ?? null;
    }

    public function stored_patient_name(array $entry): ?string
    {
        if (!empty($entry['yakap_patient_names'])) {
            return (string) $entry['yakap_patient_names'];
        }

        return null;
    }

    public function stored_patient_ids(array $entry): ?string
    {
        if (!empty($entry['yakap_patient_ids'])) {
            return (string) $entry['yakap_patient_ids'];
        }

        return null;
    }

    /**
     * @return array<int,array{patient_id:mixed,master_patient_id:mixed,patient_name:?string}>
     */
    protected function get_fpe_patients(BaseConnection $emr_db, string $fpe_from, string $as_of): array
    {
        $key = $this->encryption_key;
        $sql = <<<EOT
SELECT DISTINCT pf.patient_id,
    p.master_patient_id,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(p.last_name, ?) AS CHAR), ', ',
        CAST(AES_DECRYPT(p.first_name, ?) AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(p.middle_name, ?) AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(p.suffix, ?) AS CHAR), '')
    )) AS patient_name
FROM patient_fpe pf
INNER JOIN patient p ON p.id = pf.patient_id AND p.is_deleted = 0
WHERE pf.is_deleted = 0
AND DATE(pf.added_on) BETWEEN ? AND ?
ORDER BY pf.patient_id ASC
EOT;

        try {
            $query = $emr_db->query($sql, [$key, $key, $key, $key, $fpe_from, $as_of]);
        } catch (\Throwable $e) {
            log_message('error', '[Yakap_billing] FPE patient query failed: ' . $e->getMessage());
            return [];
        }

        $best = [];
        foreach (($query ? $query->getResultArray() : []) as $row) {
            $patient_id = $row['patient_id'] ?? null;
            if ($patient_id === null || $patient_id === '') {
                continue;
            }
            $best[(int) $patient_id] = [
                'patient_id' => $patient_id,
                'master_patient_id' => $row['master_patient_id'] ?? null,
                'patient_name' => $row['patient_name'] ?? null,
            ];
        }

        return array_values($best);
    }

    /**
     * @return array{consultation: int[], laboratory: int[], antibiotic: int[], ncd: int[]}
     */
    protected function get_kpi_patient_ids(BaseConnection $emr_db, string $fpe_from, string $as_of): array
    {
        $master_db = MASTER_DB;

        $queries = [
            'consultation' => [
                <<<EOT
SELECT DISTINCT pf.patient_id
FROM patient_fpe pf
JOIN assessment a
    ON a.patient_id = pf.patient_id
   AND a.is_deleted = 0
   AND a.assessment_type = 'ekonsulta'
   AND DATE(a.added_on) BETWEEN ? AND ?
WHERE pf.is_deleted = 0
AND DATE(pf.added_on) BETWEEN ? AND ?
EOT,
                [$fpe_from, $as_of, $fpe_from, $as_of],
            ],
            'laboratory' => [
                <<<EOT
SELECT DISTINCT pf.patient_id
FROM patient_fpe pf
JOIN lab_result lr
    ON lr.patient_id = pf.patient_id
   AND lr.is_deleted = 0
   AND lr.is_sent = 1
   AND DATE(lr.added_on) BETWEEN ? AND ?
WHERE pf.is_deleted = 0
AND DATE(pf.added_on) BETWEEN ? AND ?
EOT,
                [$fpe_from, $as_of, $fpe_from, $as_of],
            ],
            'antibiotic' => [
                <<<EOT
SELECT DISTINCT th.patient_id
FROM therapeutic th
JOIN assessment a
    ON a.id = th.assessment_id
   AND a.is_deleted = 0
   AND a.assessment_type = 'ekonsulta'
JOIN {$master_db}.item i
    ON i.id = th.medicine_id
   AND i.is_deleted = 0
   AND i.is_antibiotic = 1
JOIN patient_fpe pf
    ON pf.patient_id = th.patient_id
   AND pf.is_deleted = 0
   AND DATE(pf.added_on) BETWEEN ? AND ?
WHERE th.is_deleted = 0
AND DATE(th.added_on) BETWEEN ? AND ?
EOT,
                [$fpe_from, $as_of, $fpe_from, $as_of],
            ],
            'ncd' => [
                <<<EOT
SELECT DISTINCT th.patient_id
FROM therapeutic th
JOIN assessment a
    ON a.id = th.assessment_id
   AND a.is_deleted = 0
   AND a.assessment_type = 'ekonsulta'
JOIN {$master_db}.item i
    ON i.id = th.medicine_id
   AND i.is_deleted = 0
   AND i.is_antibiotic = 0
   AND i.philhealth_medicine_type IS NOT NULL
JOIN patient_fpe pf
    ON pf.patient_id = th.patient_id
   AND pf.is_deleted = 0
   AND DATE(pf.added_on) BETWEEN ? AND ?
WHERE th.is_deleted = 0
AND DATE(th.added_on) BETWEEN ? AND ?
EOT,
                [$fpe_from, $as_of, $fpe_from, $as_of],
            ],
        ];

        $ids = [
            'consultation' => [],
            'laboratory' => [],
            'antibiotic' => [],
            'ncd' => [],
        ];

        foreach ($queries as $kpi => [$sql, $binds]) {
            try {
                $query = $emr_db->query($sql, $binds);
            } catch (\Throwable $e) {
                log_message('error', '[Yakap_billing] KPI query failed (' . $kpi . '): ' . $e->getMessage());
                continue;
            }

            foreach (($query ? $query->getResultArray() : []) as $row) {
                if (!empty($row['patient_id'])) {
                    $ids[$kpi][] = (int) $row['patient_id'];
                }
            }
            $ids[$kpi] = array_values(array_unique($ids[$kpi]));
        }

        return $ids;
    }

    /**
     * @param array{consultation: int[], laboratory: int[], antibiotic: int[], ncd: int[]} $kpi_ids
     */
    protected function second_tranch_amount(int $fpe_count, array $kpi_ids): float
    {
        if ($fpe_count <= 0) {
            return 0.0;
        }

        $performance_factor = 0.0;
        foreach (self::KPI_TARGETS as $kpi => $meta) {
            $count = count($kpi_ids[$kpi] ?? []);
            $perf_score = $count / $fpe_count;
            $resultant = $meta['target'] > 0
                ? ($perf_score / $meta['target']) * $meta['weight']
                : 0;
            $performance_factor += $resultant;
        }

        return round($fpe_count * round($performance_factor, 3) * self::CSR_AMOUNT, 2);
    }

    /**
     * @param array<int,array{patient_id:mixed,master_patient_id:mixed,patient_name:?string}> $patients
     * @param int[] $patient_ids
     * @return array<int,array{patient_id:mixed,master_patient_id:mixed,patient_name:?string}>
     */
    protected function patients_in_set(array $patients, array $patient_ids): array
    {
        $lookup = array_fill_keys($patient_ids, true);
        $matched = [];
        foreach ($patients as $patient) {
            $id = (int) ($patient['patient_id'] ?? 0);
            if (isset($lookup[$id])) {
                $matched[] = $patient;
            }
        }

        return $matched;
    }

    /**
     * @param array<int,array{patient_id:mixed,master_patient_id:mixed,patient_name:?string}> $patients
     * @param int[] $patient_ids
     * @return array<int,array{patient_id:mixed,master_patient_id:mixed,patient_name:?string}>
     */
    protected function patients_not_in_set(array $patients, array $patient_ids): array
    {
        $lookup = array_fill_keys($patient_ids, true);
        $matched = [];
        foreach ($patients as $patient) {
            $id = (int) ($patient['patient_id'] ?? 0);
            if (!isset($lookup[$id])) {
                $matched[] = $patient;
            }
        }

        return $matched;
    }

    /**
     * @param array{consultation: int[], laboratory: int[], antibiotic: int[], ncd: int[]} $kpi_ids
     * @return int[]
     */
    protected function unique_kpi_patient_ids(array $kpi_ids): array
    {
        $all = [];
        foreach ($kpi_ids as $ids) {
            foreach ($ids as $id) {
                $all[] = (int) $id;
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * @param array<int,array{master_patient_id?:mixed}> $patients
     * @return int[]
     */
    protected function master_ids_from_patients(array $patients): array
    {
        $ids = [];
        foreach ($patients as $patient) {
            $master_id = $patient['master_patient_id'] ?? null;
            if ($master_id !== null && $master_id !== '' && (int) $master_id > 0) {
                $ids[] = (int) $master_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param int[] $master_ids
     * @return array{transaction_ids: array<int,int|string>, booking_ids: array<int,int|string>, endorsement_ids: array<int,int|string>}
     */
    protected function get_csr_lists($csr_db, array $master_ids, string $fpe_from, string $as_of): array
    {
        $empty = [
            'transaction_ids' => [],
            'booking_ids' => [],
            'endorsement_ids' => [],
        ];
        if (!$csr_db instanceof BaseConnection || !$master_ids) {
            return $empty;
        }

        $placeholders = implode(',', array_fill(0, count($master_ids), '?'));
        $company_id = self::COMPANY_ID;
        $binds = array_merge($master_ids, [$company_id, $company_id, $company_id, $fpe_from, $as_of]);
        $sql = <<<EOT
SELECT transc.id AS transaction_id,
    booking.id AS booking_id,
    transc.endorsement_id
FROM transaction AS transc
LEFT JOIN booking ON booking.transaction_id = transc.id AND booking.is_deleted = 0
LEFT JOIN patient ON patient.id = COALESCE(transc.patient_id, booking.patient_id)
WHERE transc.is_deleted = 0
AND patient.master_id IN ($placeholders)
AND (
    transc.hmo_id = ?
    OR booking.charge_to = ?
    OR EXISTS (
        SELECT 1
        FROM booking_details
        WHERE booking_details.booking_id = booking.id
        AND booking_details.is_deleted = 0
        AND booking_details.charge_to = ?
    )
)
AND transc.transaction_date >= ?
AND transc.transaction_date <= ?
ORDER BY transc.id ASC
EOT;

        try {
            $query = $csr_db->query($sql, $binds);
        } catch (\Throwable $e) {
            log_message('error', '[Yakap_billing] CSR list query failed: ' . $e->getMessage());
            return $empty;
        }

        $seen_txn = [];
        foreach (($query ? $query->getResultArray() : []) as $row) {
            $txn_id = $row['transaction_id'] ?? null;
            if ($txn_id && isset($seen_txn[$txn_id])) {
                continue;
            }
            if ($txn_id) {
                $seen_txn[$txn_id] = true;
                $empty['transaction_ids'][] = $txn_id;
            }
            if (!empty($row['booking_id'])) {
                $empty['booking_ids'][] = $row['booking_id'];
            }
            if (!empty($row['endorsement_id'])) {
                $empty['endorsement_ids'][] = $row['endorsement_id'];
            }
        }

        $empty['booking_ids'] = array_values(array_unique($empty['booking_ids']));
        $empty['endorsement_ids'] = array_values(array_unique($empty['endorsement_ids']));

        return $empty;
    }

    /**
     * @param array<int,array{patient_id:mixed,master_patient_id:mixed,patient_name:?string}> $patients
     * @param array<int,int|string>|null $transaction_ids
     * @param array<int,int|string>|null $booking_ids
     * @param array<int,int|string>|null $endorsement_ids
     */
    protected function build_tranche_row(
        int $tranche,
        string $as_of,
        int $branch_id,
        string $branch_name,
        array $patients,
        float $total_amount,
        $transaction_ids,
        $booking_ids,
        $endorsement_ids
    ): array {
        $patient_ids = [];
        $master_ids = [];
        $names = [];
        foreach ($patients as $patient) {
            if (isset($patient['patient_id']) && $patient['patient_id'] !== '' && $patient['patient_id'] !== null) {
                $patient_ids[] = $patient['patient_id'];
            }
            if (isset($patient['master_patient_id']) && $patient['master_patient_id'] !== '' && $patient['master_patient_id'] !== null) {
                $master_ids[] = $patient['master_patient_id'];
            }
            if (!empty($patient['patient_name'])) {
                $names[] = $patient['patient_name'];
            }
        }

        return [
            'transaction_id' => $this->join_list($transaction_ids ?? []),
            'booking_id' => $this->join_list($booking_ids ?? []),
            'transaction_date' => $as_of,
            'company_id' => self::COMPANY_ID,
            'charge_to' => self::COMPANY_ID,
            'billing_channel' => $tranche === 2 ? 'tranche_2' : 'tranche_1',
            'status' => null,
            'transaction_type' => null,
            'transaction_no' => $tranche === 2 ? '02' : '01',
            'patient_id' => $this->join_list($patient_ids),
            'master_patient_id' => $this->join_list($master_ids),
            'patient_name' => $this->join_list($names),
            'endorsement_id' => $this->join_list($endorsement_ids ?? []),
            'total_amount' => $total_amount,
            'branch_id' => $branch_id,
            'branch_name' => $branch_name,
            'booking_details' => self::booking_details_for_tranche($tranche),
            'as_of_date' => $as_of,
            'yakap_tranche' => $tranche,
            'yakap_patient_ids' => $this->join_list($patient_ids),
            'yakap_master_patient_ids' => $this->join_list($master_ids),
            'yakap_patient_names' => $this->join_list($names),
        ];
    }

    /**
     * @return array<int,true>
     */
    protected function get_billed_tranches(int $branch_id, string $as_of): array
    {
        $sql = <<<EOT
SELECT billing_entry.yakap_tranche
FROM billing_entry
LEFT JOIN billing ON billing.id = billing_entry.billing_id
WHERE billing_entry.is_deleted = 0
AND billing.is_deleted = 0
AND IFNULL(billing.status, '') != 'cancelled'
AND (billing.charge_to = ? OR billing_entry.company_id = ?)
AND billing_entry.branch_id = ?
AND billing_entry.as_of_date = ?
AND billing_entry.yakap_tranche IN (1, 2)
EOT;

        try {
            $query = $this->db->query($sql, [self::COMPANY_ID, self::COMPANY_ID, $branch_id, $as_of]);
        } catch (\Throwable $e) {
            log_message('error', '[Yakap_billing] billed-as-of query failed: ' . $e->getMessage());
            return [];
        }

        $billed = [];
        foreach (($query ? $query->getResultArray() : []) as $row) {
            $tranche = (int) ($row['yakap_tranche'] ?? 0);
            if ($tranche === 1 || $tranche === 2) {
                $billed[$tranche] = true;
            }
        }

        return $billed;
    }

    /**
     * @param array<int,mixed> $values
     */
    protected function join_list(array $values): ?string
    {
        $clean = [];
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[] = (string) $value;
        }
        $clean = array_values(array_unique($clean));

        return $clean ? implode(',', $clean) : null;
    }

    protected function as_of_date($date_to): string
    {
        if ($date_to) {
            $ts = strtotime((string) $date_to);
            if ($ts) {
                return date('Y-m-d', $ts);
            }
        }

        return date('Y-m-d');
    }

    protected function connect_csr(string $group_name)
    {
        if ($group_name === '') {
            return null;
        }

        try {
            return \Config\Database::connect($group_name);
        } catch (\Throwable $e) {
            log_message('error', '[Yakap_billing] CSR connect failed on ' . $group_name . ': ' . $e->getMessage());
            return null;
        }
    }

    protected function connect_emr(int $branch_id)
    {
        $branch_config = config(Branch::class);
        $emr_db_name = $branch_config->getEmrDB($branch_id);
        if (!$emr_db_name) {
            return null;
        }

        $db_config = config('Database');
        if (!isset($db_config->$emr_db_name)) {
            return null;
        }

        try {
            return \Config\Database::connect($emr_db_name);
        } catch (\Throwable $e) {
            log_message('error', '[Yakap_billing] EMR connect failed for ' . $emr_db_name . ': ' . $e->getMessage());
            return null;
        }
    }
}

?>
