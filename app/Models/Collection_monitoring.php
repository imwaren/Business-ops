<?php

namespace App\Models;

use App\Core\MYT_Model;
use App\Models\Company;
use App\Models\Endorsement;

/**
 * Collection Monitoring Report — aging of collected amounts by branch.
 */
class Collection_monitoring extends MYT_Model
{
    private $company;
    private $endorsement;

    public function __construct()
    {
        parent::__construct();
        $this->company     = new Company();
        $this->endorsement = new Endorsement();
    }

    private function _branch_db_map(): array
    {
        return [
            1 => ['key' => 'mobile', 'name' => 'mobile'],
            2 => ['key' => 'oakridge', 'name' => 'oakridge'],
            3 => ['key' => 'mactan', 'name' => 'mactan'],
            4 => ['key' => 'consolacion', 'name' => 'consolacion'],
            7 => ['key' => 'mantlewood', 'name' => 'mantlewood'],
        ];
    }

    private function _bucket_labels(): array
    {
        return [
            'within_terms' => 'Collected Within Terms',
            '1_7'          => 'Collected 1–7 Days Overdue',
            '8_14'         => 'Collected 8–14 Days Overdue',
            '15_21'        => 'Collected 15–21 Days Overdue',
            '22_30'        => 'Collected 22–30 Days Overdue',
            'beyond_30'    => 'Collected Beyond 30 Days',
        ];
    }

    /**
     * @return array<int, array>
     */
    public function get($date_from, $date_to, $company_id = null, $charge_to = null, $sae_in_charge_id = null): array
    {
        $payment_rows = $this->_load_payment_rows($date_from, $date_to, $company_id, $charge_to);
        if (!$payment_rows) {
            return $this->_empty_matrix();
        }

        $by_branch = [];
        foreach ($payment_rows as $row) {
            $branch_id = (int) ($row['branch_id'] ?? 0);
            if (!isset($this->_branch_db_map()[$branch_id])) {
                continue;
            }
            $by_branch[$branch_id][] = $row;
        }

        $transactions_by_branch = [];
        foreach ($by_branch as $branch_id => $rows) {
            $txn_ids = array_values(array_unique(array_filter(array_map(static function ($r) {
                return (int) ($r['transaction_id'] ?? 0);
            }, $rows))));
            $transactions_by_branch[$branch_id] = $this->_load_transactions($branch_id, $txn_ids);
        }

        $endorsement_ids = [];
        foreach ($transactions_by_branch as $txns) {
            foreach ($txns as $txn) {
                $eid = (int) ($txn['endorsement_id'] ?? 0);
                if ($eid > 0) {
                    $endorsement_ids[$eid] = $eid;
                }
            }
        }
        $endorsements = $this->_load_endorsements(array_values($endorsement_ids), $sae_in_charge_id);

        $company_ids = [];
        foreach ($payment_rows as $row) {
            $cid = (int) ($row['billing_charge_to'] ?? 0);
            if ($cid <= 0) {
                $cid = (int) ($row['company_id'] ?? 0);
            }
            if ($cid > 0) {
                $company_ids[$cid] = $cid;
            }
        }
        $company_map = $this->_load_company_map(array_values($company_ids));

        return $this->_build_matrix(
            $payment_rows,
            $transactions_by_branch,
            $endorsements,
            $company_map,
            $sae_in_charge_id
        );
    }

    private function _load_payment_rows($date_from, $date_to, $company_id = null, $charge_to = null): array
    {
        $binds = [];
        $sql   = <<<EOT
SELECT bpe.id AS billing_payment_entry_id,
    bpe.amount,
    bpe.billing_entry_id,
    bpe.billing_payment_id,
    bp.paid_date,
    bp.charge_to AS payment_charge_to,
    be.branch_id,
    be.booking_id,
    be.transaction_id,
    be.company_id,
    be.billing_id,
    be.amount AS entry_amount,
    be.balance AS entry_balance,
    be.status AS entry_status,
    b.due_date AS billing_due_date,
    b.charge_to AS billing_charge_to,
    b.company_id AS billing_company_id,
    b.status AS billing_status
FROM billing_payment_entry bpe
INNER JOIN billing_payment bp ON bp.id = bpe.billing_payment_id AND bp.is_deleted = 0
INNER JOIN billing_entry be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
INNER JOIN billing b ON b.id = be.billing_id AND b.is_deleted = 0
WHERE bpe.is_deleted = 0
EOT;

        if ($date_from) {
            $sql .= "\nAND bp.paid_date >= ?";
            $binds[] = $date_from;
        }
        if ($date_to) {
            $sql .= "\nAND bp.paid_date <= ?";
            $binds[] = $date_to;
        }
        if ($company_id) {
            $sql .= "\nAND be.company_id = ?";
            $binds[] = $company_id;
        }
        if ($charge_to) {
            $sql .= "\nAND b.charge_to = ?";
            $binds[] = $charge_to;
        }

        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * @param int[] $transaction_ids
     * @return array<int, array> keyed by transaction id
     */
    private function _load_transactions(int $branch_id, array $transaction_ids): array
    {
        $map = $this->_branch_db_map();
        if (!$transaction_ids || !isset($map[$branch_id])) {
            return [];
        }

        $db           = db_connect($map[$branch_id]['name']);
        $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
        $sql          = <<<EOT
SELECT id, transaction_no, transaction_date, endorsement_id, patient_id, hmo_id
FROM transaction
WHERE is_deleted = 0
AND id IN ($placeholders)
EOT;
        $query = $db->query($sql, $transaction_ids);
        $rows  = $query ? $query->getResultArray() : [];

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row['id']] = $row;
        }

        return $keyed;
    }

    /**
     * @param int[] $endorsement_ids
     * @return array<int, array> keyed by endorsement id
     */
    private function _load_endorsements(array $endorsement_ids, $sae_in_charge_id = null): array
    {
        if (!$endorsement_ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($endorsement_ids), '?'));
        $binds        = $endorsement_ids;
        $sql          = <<<EOT
SELECT endorsement.id,
    endorsement.company_id,
    endorsement.payment_terms,
    endorsement.status,
    endorsement.guaranteed_headcount,
    endorsement.costing_id,
    costing.sae_in_charge_id,
    CONCAT_WS(' ', sae_in_charge.first_name, sae_in_charge.last_name) AS sae_in_charge_name
FROM endorsement
LEFT JOIN costing ON costing.id = endorsement.costing_id AND costing.is_deleted = 0
LEFT JOIN user AS sae_in_charge ON sae_in_charge.id = costing.sae_in_charge_id
WHERE endorsement.is_deleted = 0
AND endorsement.id IN ($placeholders)
EOT;

        if ($sae_in_charge_id) {
            $sql .= "\nAND costing.sae_in_charge_id = ?";
            $binds[] = $sae_in_charge_id;
        }

        $query = $this->db->query($sql, $binds);
        $rows  = $query ? $query->getResultArray() : [];

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row['id']] = $row;
        }

        return $keyed;
    }

    /**
     * @param int[] $company_ids
     * @return array<int, string>
     */
    private function _load_company_map(array $company_ids): array
    {
        if (!$company_ids) {
            return [];
        }

        $map = [];
        foreach ($company_ids as $cid) {
            $company = $this->company->select('id, name', ['id' => $cid, 'is_deleted' => 0], 1);
            if ($company) {
                $map[(int) $cid] = $company->name;
            }
        }

        return $map;
    }

    private function _parse_payment_terms_days($payment_terms): int
    {
        if ($payment_terms === null || $payment_terms === '') {
            return 0;
        }
        if (preg_match('/(\d+)/', (string) $payment_terms, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function _bucket_for_days_overdue(int $days_overdue): string
    {
        if ($days_overdue <= 0) {
            return 'within_terms';
        }
        if ($days_overdue <= 7) {
            return '1_7';
        }
        if ($days_overdue <= 14) {
            return '8_14';
        }
        if ($days_overdue <= 21) {
            return '15_21';
        }
        if ($days_overdue <= 30) {
            return '22_30';
        }

        return 'beyond_30';
    }

    private function _empty_matrix(): array
    {
        $branch_keys = array_map(static fn($meta) => $meta['key'], $this->_branch_db_map());
        $rows        = [];

        foreach ($this->_bucket_labels() as $label) {
            $row = ['particulars' => $label];
            foreach ($branch_keys as $key) {
                $row[$key]              = 0.0;
                $row[$key . '_details'] = [];
            }
            $row['total'] = 0.0;
            $rows[]       = $row;
        }

        $eff = ['particulars' => 'Collection Efficiency %'];
        foreach ($branch_keys as $key) {
            $eff[$key] = 0.0;
        }
        $eff['total'] = 0.0;
        $rows[]       = $eff;

        return $rows;
    }

    /**
     * @param array<int, array> $payment_rows
     * @param array<int, array<int, array>> $transactions_by_branch
     * @param array<int, array> $endorsements
     * @param array<int, string> $company_map
     */
    private function _build_matrix(
        array $payment_rows,
        array $transactions_by_branch,
        array $endorsements,
        array $company_map,
        $sae_in_charge_id = null
    ): array {
        $branch_map  = $this->_branch_db_map();
        $branch_keys = array_map(static fn($meta) => $meta['key'], $branch_map);
        $labels      = $this->_bucket_labels();

        $matrix = [];
        foreach ($labels as $bucket => $label) {
            $matrix[$bucket] = [
                'particulars' => $label,
            ];
            foreach ($branch_keys as $key) {
                $matrix[$bucket][$key]              = 0.0;
                $matrix[$bucket][$key . '_details'] = [];
            }
            $matrix[$bucket]['total'] = 0.0;
        }

        $within_by_branch = array_fill_keys($branch_keys, 0.0);
        $total_by_branch  = array_fill_keys($branch_keys, 0.0);
        $within_total     = 0.0;
        $collected_total  = 0.0;

        foreach ($payment_rows as $row) {
            $branch_id = (int) ($row['branch_id'] ?? 0);
            if (!isset($branch_map[$branch_id])) {
                continue;
            }
            $branch_key = $branch_map[$branch_id]['key'];
            $txn_id     = (int) ($row['transaction_id'] ?? 0);
            $txn        = $transactions_by_branch[$branch_id][$txn_id] ?? null;
            if (!$txn || empty($txn['transaction_date'])) {
                continue;
            }

            $endorsement_id = (int) ($txn['endorsement_id'] ?? 0);
            $endorsement    = $endorsements[$endorsement_id] ?? null;
            if ($sae_in_charge_id && (!$endorsement_id || !$endorsement)) {
                continue;
            }
            $terms_days     = $this->_parse_payment_terms_days($endorsement['payment_terms'] ?? null);

            $txn_date  = substr((string) $txn['transaction_date'], 0, 10);
            $paid_date = substr((string) ($row['paid_date'] ?? ''), 0, 10);
            if ($paid_date === '') {
                continue;
            }

            try {
                $due_dt  = (new \DateTimeImmutable($txn_date))->modify('+' . $terms_days . ' days');
                $paid_dt = new \DateTimeImmutable($paid_date);
            } catch (\Exception $e) {
                continue;
            }

            $due_date      = $due_dt->format('Y-m-d');
            $days_overdue  = (int) $due_dt->diff($paid_dt)->format('%r%a');
            $bucket        = $this->_bucket_for_days_overdue($days_overdue);
            $amount        = (float) ($row['amount'] ?? 0);

            $charge_company_id = (int) ($row['billing_charge_to'] ?? 0);
            if ($charge_company_id <= 0) {
                $charge_company_id = (int) ($row['company_id'] ?? 0);
            }
            $company_name = $company_map[$charge_company_id] ?? null;

            $detail = [
                'amount'        => round($amount, 2),
                'transaction_date' => $txn_date,
                'paid_date'     => $paid_date,
                'due_date'      => $due_date,
                'days_overdue'  => $days_overdue,
                'company_name'  => $company_name,
                'billing_entry' => [
                    'id'             => (int) ($row['billing_entry_id'] ?? 0),
                    'billing_id'     => (int) ($row['billing_id'] ?? 0),
                    'branch_id'      => $branch_id,
                    'company_id'     => (int) ($row['company_id'] ?? 0),
                    'booking_id'     => (int) ($row['booking_id'] ?? 0),
                    'transaction_id' => $txn_id,
                    'amount'         => isset($row['entry_amount']) ? (float) $row['entry_amount'] : null,
                    'balance'        => isset($row['entry_balance']) ? (float) $row['entry_balance'] : null,
                    'status'         => $row['entry_status'] ?? null,
                ],
                'transaction' => [
                    'id'               => $txn_id,
                    'transaction_no'   => $txn['transaction_no'] ?? null,
                    'transaction_date' => $txn_date,
                    'endorsement_id'   => $endorsement_id ?: null,
                ],
                'endorsement' => $endorsement ? [
                    'id'                   => (int) $endorsement['id'],
                    'company_id'           => isset($endorsement['company_id']) ? (int) $endorsement['company_id'] : null,
                    'payment_terms'        => $endorsement['payment_terms'] ?? null,
                    'payment_terms_days'   => $terms_days,
                    'status'               => $endorsement['status'] ?? null,
                    'sae_in_charge_id'     => isset($endorsement['sae_in_charge_id']) ? (int) $endorsement['sae_in_charge_id'] : null,
                    'sae_in_charge_name'   => $endorsement['sae_in_charge_name'] ?? null,
                ] : null,
            ];

            $matrix[$bucket][$branch_key] += $amount;
            $matrix[$bucket]['total'] += $amount;
            $matrix[$bucket][$branch_key . '_details'][] = $detail;

            $total_by_branch[$branch_key] += $amount;
            $collected_total += $amount;
            if ($bucket === 'within_terms') {
                $within_by_branch[$branch_key] += $amount;
                $within_total += $amount;
            }
        }

        $rows = [];
        foreach ($labels as $bucket => $label) {
            $row = ['particulars' => $label];
            foreach ($branch_keys as $key) {
                $row[$key]              = round($matrix[$bucket][$key], 2);
                $row[$key . '_details'] = $matrix[$bucket][$key . '_details'];
            }
            $row['total'] = round($matrix[$bucket]['total'], 2);
            $rows[]       = $row;
        }

        $eff = ['particulars' => 'Collection Efficiency %'];
        foreach ($branch_keys as $key) {
            $den = $total_by_branch[$key];
            $eff[$key] = $den > 0
                ? round(($within_by_branch[$key] / $den) * 100, 2)
                : 0.0;
        }
        $eff['total'] = $collected_total > 0
            ? round(($within_total / $collected_total) * 100, 2)
            : 0.0;
        $rows[] = $eff;

        return $rows;
    }
}
