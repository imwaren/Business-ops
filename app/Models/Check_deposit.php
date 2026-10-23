<?php

namespace App\Models;

use App\Core\MYT_Model;

class Check_deposit extends MYT_Model
{
    protected $table = 'check_deposit';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'billing_payment_id',
        'account_type_id',
        'amount',
        'check_no',
        'check_date',
        'bank_name',
        'reference_no',
        'paid_date',
        'status',
        'posted_remarks',
        'posted_by',
        'posted_on',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function get_details($check_deposit_id = null, $status = null, $date_from = null, $date_to = null, $page = null, $data_size = null)
    {
        [$sql, $binds] = $this->_details_sql($check_deposit_id, $status, $date_from, $date_to);
        $sql .= "\nORDER BY check_deposit.paid_date DESC, check_deposit.id DESC";
        $sql .= sql_limit_clause($page, $data_size);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }

    public function count_details($check_deposit_id = null, $status = null, $date_from = null, $date_to = null): int
    {
        [$sql, $binds] = $this->_details_sql($check_deposit_id, $status, $date_from, $date_to);
        $query = $this->db->query('SELECT COUNT(*) AS total FROM (' . $sql . ') AS check_deposit_list', $binds);
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    public function get_report($check_deposit_id = null, $status = null, $date_from = null, $date_to = null, $page = null, $data_size = null)
    {
        [$where_sql, $binds] = $this->_where_sql($check_deposit_id, $status, $date_from, $date_to);
        $sql = <<<EOT
SELECT check_deposit.paid_date,
    check_deposit.bank_name,
    check_deposit.status,
    COUNT(*) AS check_count,
    SUM(check_deposit.amount) AS total_amount
FROM check_deposit
INNER JOIN billing_payment ON billing_payment.id = check_deposit.billing_payment_id
WHERE {$where_sql}
GROUP BY check_deposit.paid_date, check_deposit.bank_name, check_deposit.status
ORDER BY check_deposit.paid_date DESC, check_deposit.bank_name ASC, check_deposit.status ASC
EOT;
        $sql .= sql_limit_clause($page, $data_size);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }

    public function count_report($check_deposit_id = null, $status = null, $date_from = null, $date_to = null): int
    {
        [$where_sql, $binds] = $this->_where_sql($check_deposit_id, $status, $date_from, $date_to);
        $sql = <<<EOT
SELECT COUNT(*) AS total
FROM (
    SELECT check_deposit.paid_date, check_deposit.bank_name, check_deposit.status
    FROM check_deposit
    INNER JOIN billing_payment ON billing_payment.id = check_deposit.billing_payment_id
    WHERE {$where_sql}
    GROUP BY check_deposit.paid_date, check_deposit.bank_name, check_deposit.status
) AS check_deposit_report
EOT;
        $query = $this->db->query($sql, $binds);
        $row = $query ? $query->getRowArray() : null;

        return (int) ($row['total'] ?? 0);
    }

    private function _details_sql($check_deposit_id, $status, $date_from, $date_to): array
    {
        [$conditions_sql, $binds] = $this->_where_sql($check_deposit_id, $status, $date_from, $date_to);
        $sql = <<<EOT
SELECT check_deposit.*, billing_payment.charge_to, billing_payment.is_unidentified
FROM check_deposit
INNER JOIN billing_payment ON billing_payment.id = check_deposit.billing_payment_id
WHERE {$conditions_sql}
EOT;

        return [$sql, $binds];
    }

    private function _where_sql($check_deposit_id, $status, $date_from, $date_to): array
    {
        $binds = [];
        $sql = 'check_deposit.is_deleted = 0 AND billing_payment.is_deleted = 0';

        if ($check_deposit_id) {
            $sql .= "\nAND check_deposit.id = ?";
            $binds[] = $check_deposit_id;
        }
        if ($status) {
            $sql .= "\nAND check_deposit.status = ?";
            $binds[] = $status;
        }
        if ($date_from) {
            $sql .= "\nAND check_deposit.paid_date >= ?";
            $binds[] = $date_from;
        }
        if ($date_to) {
            $sql .= "\nAND check_deposit.paid_date <= ?";
            $binds[] = $date_to;
        }

        return [$sql, $binds];
    }
}
