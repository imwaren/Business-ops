<?php
namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Period-based, master-persisted YAKAP bills. EMR figures are queried, never supplied by clients. */
class Yakap_period_billing extends Yakap_billing
{
    protected $DBGroup = 'master';

    public static function validatePeriod(array $input, bool $creation = false): array
    {
        $integer = static function ($value, string $name): int {
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/', (string) $value)) {
                throw new InvalidArgumentException($name . ' must be a positive integer.');
            }
            return (int) $value;
        };
        $branch = $integer($input['branch_id'] ?? '', 'branch_id');
        $year = $integer($input['year'] ?? '', 'year');
        if (!in_array($branch, [1, 2, 3, 4, 7], true) || $year < 1900 || $year > 9998) {
            throw new InvalidArgumentException('Unsupported branch_id or year.');
        }
        $tranche = $creation ? $integer($input['tranche_number'] ?? '', 'tranche_number') : null;
        if ($creation && !in_array($tranche, [1, 2], true)) {
            throw new InvalidArgumentException('tranche_number must be 1 or 2.');
        }
        $month = $tranche === 1 ? $integer($input['month'] ?? '', 'month') : 0;
        if ($month > 12) {
            throw new InvalidArgumentException('month must be between 1 and 12.');
        }
        if ($tranche === 2 && isset($input['month']) && $input['month'] !== '' && (string) $input['month'] !== '0') {
            throw new InvalidArgumentException('month is not applicable to tranche 2.');
        }
        return [$branch, $year, $tranche, $month];
    }

    public static function completed(int $year, int $month, ?DateTimeImmutable $now = null): bool
    {
        $zone = new DateTimeZone('Asia/Manila');
        $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
        $end = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month ?: 1), $zone);
        $end = $end->modify($month ? '+1 month' : '+1 year');
        return $now >= $end;
    }

    public static function scores(int $fpe, array $counts): array
    {
        $breakdown = [];
        $factor = 0.0;
        foreach (self::KPI_TARGETS as $key => $meta) {
            $count = (int) ($counts[$key] ?? 0);
            $actual = $fpe > 0 ? round($count / $fpe, 2, PHP_ROUND_HALF_UP) : 0.0;
            $computation = round(min($actual / $meta['target'], 1.0), 2, PHP_ROUND_HALF_UP);
            $resultant = round($computation * $meta['weight'], 2, PHP_ROUND_HALF_UP);
            $breakdown[$key] = ['count' => $count, 'target' => $meta['target'], 'weight' => $meta['weight'],
                'actual_score' => $actual, 'computation' => $computation, 'resultant_score' => $resultant];
            $factor += $resultant;
        }
        $factor = round($factor, 2, PHP_ROUND_HALF_UP);
        return ['breakdown' => $breakdown, 'performance_factor' => $factor,
            'calculated_amount' => round($fpe * $factor * self::CSR_AMOUNT, 2, PHP_ROUND_HALF_UP)];
    }

    /** One SQL statement: first FPE month and unique-cohort annual service indicators. */
    protected function metrics(int $branch, int $year): array
    {
        $emr = $this->connect_emr($branch);
        if (!$emr) {
            throw new RuntimeException('EMR connection unavailable for branch ' . $branch . '.');
        }
        $master = '`' . str_replace('`', '``', MASTER_DB) . '`';
        $from = sprintf('%04d-01-01', $year);
        $until = sprintf('%04d-01-01', $year + 1);
        $sql = <<<EOT
SELECT MONTH(cohort.first_fpe) AS month, COUNT(*) AS fpe_count,
 SUM(EXISTS(SELECT 1 FROM assessment a WHERE a.patient_id=cohort.patient_id
   AND a.is_deleted=0 AND a.assessment_type='ekonsulta' AND a.added_on>=? AND a.added_on<?)) AS consultation,
 SUM(EXISTS(SELECT 1 FROM lab_result l WHERE l.patient_id=cohort.patient_id
   AND l.is_deleted=0 AND l.added_on>=? AND l.added_on<?)) AS laboratory,
 SUM(EXISTS(SELECT 1 FROM therapeutic t JOIN assessment a ON a.id=t.assessment_id
   AND a.patient_id=t.patient_id AND a.is_deleted=0 AND a.assessment_type='ekonsulta'
   JOIN {$master}.item i ON i.id=t.medicine_id AND i.is_deleted=0 AND i.is_antibiotic=1
   WHERE t.patient_id=cohort.patient_id AND t.is_deleted=0 AND t.added_on>=? AND t.added_on<?)) AS antibiotic,
 SUM(EXISTS(SELECT 1 FROM therapeutic t JOIN assessment a ON a.id=t.assessment_id
   AND a.patient_id=t.patient_id AND a.is_deleted=0 AND a.assessment_type='ekonsulta'
   JOIN {$master}.item i ON i.id=t.medicine_id AND i.is_deleted=0 AND i.is_antibiotic=0
   AND i.philhealth_medicine_type IS NOT NULL
   WHERE t.patient_id=cohort.patient_id AND t.is_deleted=0 AND t.added_on>=? AND t.added_on<?)) AS ncd
FROM (SELECT pf.patient_id, MIN(pf.added_on) AS first_fpe FROM patient_fpe pf
 JOIN patient p ON p.id=pf.patient_id AND p.is_deleted=0
 WHERE pf.is_deleted=0 AND pf.added_on>=? AND pf.added_on<? GROUP BY pf.patient_id) cohort
GROUP BY MONTH(cohort.first_fpe) ORDER BY month
EOT;
        try {
            $result = $emr->query($sql, array_merge(...array_fill(0, 5, [$from, $until])));
            if (!$result) {
                throw new RuntimeException('Source query failed.');
            }
            return $result->getResultArray();
        } catch (\Throwable $error) {
            log_message('error', 'YAKAP source calculation failed for branch ' . $branch . ': ' . $error->getMessage());
            throw new RuntimeException('Unable to calculate YAKAP figures for branch ' . $branch . '. Required EMR sources must be available.');
        }
    }

    protected function reservations(int $branch, int $year): array
    {
        $sql = <<<EOT
SELECT e.id AS billing_entry_id,e.billing_id,e.yakap_tranche,e.yakap_year,e.yakap_month,
 e.amount AS billed_amount,e.yakap_calculation,b.status
FROM billing_entry e JOIN billing b ON b.id=e.billing_id
WHERE e.branch_id=? AND e.is_deleted=0 AND b.is_deleted=0 AND IFNULL(b.status,'')<>'cancelled'
 AND (e.company_id=? OR b.charge_to=?) AND e.yakap_tranche IN (1,2)
 AND (e.yakap_year=? OR (e.yakap_year IS NULL AND YEAR(e.as_of_date)=?))
EOT;
        $rows = $this->db->query($sql, [$branch, self::COMPANY_ID, self::COMPANY_ID, $year, $year])->getResultArray();
        $result = [];
        foreach ($rows as $row) {
            // Pre-period aggregate bills cannot safely be assigned to a single month/year.
            if ($row['yakap_year'] === null) {
                throw new RuntimeException('Legacy YAKAP billing #' . $row['billing_id'] . ' requires period review before billing this year.');
            }
            $key = $row['yakap_tranche'] . ':' . (int) $row['yakap_month'];
            if (isset($result[$key])) {
                throw new RuntimeException('Multiple active bills exist for one YAKAP period.');
            }
            $row['calculation_snapshot'] = $row['yakap_calculation'] ? json_decode($row['yakap_calculation'], true, 512, JSON_THROW_ON_ERROR) : null;
            unset($row['yakap_calculation']);
            $result[$key] = $row;
        }
        return $result;
    }

    public function assertMetadataUpdate(int $billingId, array $input): void
    {
        $hasTranche = $this->db->query('SELECT id FROM billing_entry WHERE billing_id=? AND is_deleted=0 AND yakap_tranche IN (1,2) LIMIT 1', [$billingId])->getRowArray();
        if (!$hasTranche) {
            return;
        }
        foreach (['grand_total', 'amount', 'balance', 'company_id', 'charge_to', 'date_from', 'date_to',
            'year', 'month', 'tranche_number', 'entries'] as $field) {
            if (array_key_exists($field, $input)) {
                throw new InvalidArgumentException('YAKAP amounts and periods cannot be edited. Cancel and recreate the billing; only metadata may be updated.');
            }
        }
    }

    public function preview(int $branch, int $year, int $tranche): array
    {
        $rows = $this->metrics($branch, $year);
        $reserved = $this->reservations($branch, $year);
        $base = ['company_id' => self::COMPANY_ID, 'branch_id' => $branch,
            'branch_name' => config(\Config\Branch::class)->getBranchName($branch), 'year' => $year, 'tranche_number' => $tranche];
        if ($tranche === 1) {
            $byMonth = array_column($rows, null, 'month');
            $months = [];
            $totals = array_fill_keys(['total', 'billed', 'unbilled', 'completed_unbilled'], ['fpe_count' => 0, 'calculated_amount' => 0.0]);
            $billedAmount = 0.0;
            for ($month = 1; $month <= 12; $month++) {
                $count = (int) ($byMonth[$month]['fpe_count'] ?? 0);
                $bill = $reserved['1:' . $month] ?? null;
                $complete = self::completed($year, $month);
                $amount = round($count * self::FPE_AMOUNT, 2);
                $months[] = ['month' => $month, 'fpe_count' => $count, 'rate' => self::FPE_AMOUNT,
                    'calculated_amount' => $amount, 'is_completed' => $complete, 'is_billed' => $bill !== null,
                    'can_bill' => $complete && !$bill && $amount > 0, 'billing' => $bill];
                foreach (['total', $bill ? 'billed' : 'unbilled'] as $bucket) {
                    $totals[$bucket]['fpe_count'] += $count;
                    $totals[$bucket]['calculated_amount'] += $amount;
                }
                if (!$bill && $complete) {
                    $totals['completed_unbilled']['fpe_count'] += $count;
                    $totals['completed_unbilled']['calculated_amount'] += $amount;
                }
                $billedAmount += (float) ($bill['billed_amount'] ?? 0);
            }
            return $base + ['months' => $months, 'totals' => $totals, 'billed_amount' => $billedAmount];
        }
        $fpe = array_sum(array_column($rows, 'fpe_count'));
        $counts = [];
        foreach (array_keys(self::KPI_TARGETS) as $key) {
            $counts[$key] = array_sum(array_column($rows, $key));
        }
        $scores = self::scores((int) $fpe, $counts);
        $bill = $reserved['2:0'] ?? null;
        return $base + ['fpe_count' => (int) $fpe, 'rate' => self::CSR_AMOUNT] + $scores
            + ['is_completed' => self::completed($year, 0), 'is_billed' => $bill !== null,
                'can_bill' => self::completed($year, 0) && !$bill && $scores['calculated_amount'] > 0,
                'billing' => $bill, 'billed_amount' => (float) ($bill['billed_amount'] ?? 0),
                'unbilled_amount' => $bill ? 0.0 : $scores['calculated_amount']];
    }

    public function createPeriod(array $input): array
    {
        [$branch, $year, $tranche, $month] = self::validatePeriod($input, true);
        if (!self::completed($year, $month)) {
            throw new InvalidArgumentException('The selected billing period is not complete.');
        }
        foreach (['due_date', 'invoice_date'] as $field) {
            if (!isset($input[$field]) || $input[$field] === '') {
                continue;
            }
            $date = is_string($input[$field]) ? DateTimeImmutable::createFromFormat('!Y-m-d', $input[$field]) : false;
            if (!$date || $date->format('Y-m-d') !== $input[$field]) {
                throw new InvalidArgumentException($field . ' must be a valid YYYY-MM-DD date.');
            }
        }
        if (isset($input['invoice_no']) && (!is_scalar($input['invoice_no']) || strlen((string) $input['invoice_no']) > 64)) {
            throw new InvalidArgumentException('invoice_no must be at most 64 characters.');
        }
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Unable to begin YAKAP billing transaction.');
        }
        try {
            $sql = <<<EOT
INSERT INTO yakap_billing_period (branch_id,tranche_number,year,month)
VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE branch_id=VALUES(branch_id)
EOT;
            if (!$this->db->query($sql, [$branch, $tranche, $year, $month])) {
                throw new RuntimeException('Unable to reserve YAKAP period.');
            }
            $this->db->query('SELECT id FROM yakap_billing_period WHERE branch_id=? AND tranche_number=? AND year=? AND month=? FOR UPDATE',
                [$branch, $tranche, $year, $month])->getRowArray();
            $preview = $this->preview($branch, $year, $tranche);
            $calculation = $tranche === 1 ? $preview['months'][$month - 1] : $preview;
            if (!$calculation['can_bill']) {
                throw new InvalidArgumentException('Period is already billed or has no billable amount.');
            }
            $amount = $calculation['calculated_amount'];
            if ($amount > 999999999.9999) {
                throw new InvalidArgumentException('Calculated amount exceeds the billing storage limit.');
            }
            foreach (['company_id', 'charge_to', 'charge_to_id'] as $field) {
                if (array_key_exists($field, $input) && (!is_scalar($input[$field]) || (string) $input[$field] !== (string) self::COMPANY_ID)) {
                    throw new InvalidArgumentException($field . ' must be ' . self::COMPANY_ID . '.');
                }
            }
            foreach (['grand_total', 'amount', 'balance', 'subtotal', 'total_amount'] as $field) {
                if (array_key_exists($field, $input) && (!is_scalar($input[$field]) || !is_numeric($input[$field])
                    || abs((float) $input[$field] - $amount) > 0.00001)) {
                    throw new InvalidArgumentException($field . ' conflicts with the calculated amount.');
                }
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
            $calculation['calculated_on'] = $now->format('Y-m-d H:i:s');
            $calculation['calculation_version'] = 1;
            $start = sprintf('%04d-%02d-01', $year, $month ?: 1);
            $end = (new DateTimeImmutable($start))->modify($month ? 'last day of this month' : 'last day of December')->format('Y-m-d');
            $header = ['company_id' => self::COMPANY_ID, 'charge_to' => self::COMPANY_ID,
                'date_from' => $start, 'date_to' => $end, 'due_date' => empty($input['due_date']) ? $now->format('Y-m-d') : $input['due_date'],
                'grand_total' => $amount, 'balance' => $amount, 'status' => $amount >= 50000 ? 'pending' : 'approved',
                'approved_by' => $amount >= 50000 ? null : $input['requester'],
                'approved_on' => $amount >= 50000 ? null : $now->format('Y-m-d H:i:s'),
                'added_by' => $input['requester'], 'added_on' => $now->format('Y-m-d H:i:s')];
            foreach (['invoice_no', 'invoice_date'] as $field) {
                if (array_key_exists($field, $input)) {
                    $header[$field] = $input[$field] === '' ? null : $input[$field];
                }
            }
            if (!$this->db->table('billing')->insert($header)) {
                throw new RuntimeException('Unable to create YAKAP billing.');
            }
            $billingId = (int) $this->db->insertID();
            $entry = ['billing_id' => $billingId, 'branch_id' => $branch, 'company_id' => self::COMPANY_ID,
                'booking_id' => 0, 'transaction_id' => 0,
                'yakap_tranche' => $tranche, 'yakap_year' => $year, 'yakap_month' => $month,
                'as_of_date' => $end, 'amount' => $amount, 'balance' => $amount,
                'remarks' => 'YAKAP TRANCHE ' . $tranche . ' ' . ($month ? substr($start, 0, 7) : $year),
                'yakap_calculation' => json_encode($calculation, JSON_THROW_ON_ERROR),
                'added_by' => $input['requester'], 'added_on' => $now->format('Y-m-d H:i:s')];
            if (!$this->db->table('billing_entry')->insert($entry)) {
                throw new RuntimeException('Unable to create YAKAP billing entry.');
            }
            $entryId = (int) $this->db->insertID();
            if (!$this->db->table('yakap_billing_period')->where(['branch_id' => $branch, 'tranche_number' => $tranche, 'year' => $year, 'month' => $month])
                ->update(['billing_entry_id' => $entryId, 'updated_on' => $now->format('Y-m-d H:i:s')])
                || !$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Unable to commit YAKAP billing.');
            }
            return ['billing_id' => $billingId, 'billing_entry_id' => $entryId, 'grand_total' => $amount, 'calculation' => $calculation];
        } catch (\Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }
}
