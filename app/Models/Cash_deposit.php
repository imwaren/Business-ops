<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Cash_deposit extends MYT_Model
{
    protected $table = 'cash_deposit';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'sales_date_from',
        'sales_date_to',
        'deposit_date',
        'amount',
        'bank_id',
        'reference_no',
        'remarks',
        'status',
        'posted_remarks',
        'posted_by',
        'posted_on',
    ];

    public function get_details($cash_deposit_id = null, $bank_id = null, $status = null, $date_from = null, $date_to = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT cash_deposit.*, 
    bank.name AS bank_name,
    CONCAT_WS(' ', reviewed_by.first_name, reviewed_by.middle_name, reviewed_by.last_name) AS reviewed_by_name
FROM cash_deposit
LEFT JOIN bank ON bank.id = cash_deposit.bank_id
LEFT JOIN {MASTER_DB}.user AS reviewed_by ON reviewed_by.id = cash_deposit.reviewed_by
WHERE cash_deposit.is_deleted = 0
EOT;

        if ($cash_deposit_id) {
            $sql .= <<<EOT

AND cash_deposit.id = ?
EOT;
            $binds[] = $cash_deposit_id;
        }

        if ($bank_id) {
            $sql .= <<<EOT

AND cash_deposit.bank_id = ?
EOT;
            $binds[] = $bank_id;
        }

        if ($status) {
            if ($status == 'reviewed') {
                $sql .= <<<EOT

AND cash_deposit.is_reviewed = 1
EOT;
            } elseif ($status == 'for_review') {
                $sql .= <<<EOT

AND cash_deposit.is_reviewed = 0
EOT;
            } else {
                $sql .= <<<EOT

AND cash_deposit.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND cash_deposit.deposit_date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_report($cash_deposit_id = null, $bank_id = null, $status = null, $date_from = null, $date_to = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT cash_deposit.id,
    cash_deposit.sales_date_from,
    cash_deposit.sales_date_to,
    cash_deposit.deposit_date,
    cash_deposit.is_reviewed,
    cash_deposit.reviewed_by,
    CONCAT_WS(' ', reviewed_by.first_name, reviewed_by.middle_name, reviewed_by.last_name) AS reviewed_by_name,
    cash_deposit.reviewed_on,
    cash_deposit.reviewed_remarks,
    SUM(cash_deposit.amount) AS total_amount, 
    (
        SELECT SUM(payment_multiple_method.amount) 
        FROM payment_multiple_method 
        LEFT JOIN payment ON payment.id = payment_multiple_method.payment_id
        LEFT JOIN transaction ON transaction.id = payment.transaction_id
        WHERE payment_multiple_method.is_deleted = 0 
        AND payment_multiple_method.type = 'cash'
        AND transaction.is_deleted = 0
        AND DATE(payment_multiple_method.added_on) BETWEEN cash_deposit.sales_date_from AND cash_deposit.sales_date_to
    ) AS total_cash_sales,
    (
        (SELECT SUM(payment_multiple_method.amount) 
        FROM payment_multiple_method 
        LEFT JOIN payment ON payment.id = payment_multiple_method.payment_id
        LEFT JOIN transaction ON transaction.id = payment.transaction_id
        WHERE payment_multiple_method.is_deleted = 0 
        AND payment_multiple_method.type = 'cash'
        AND transaction.is_deleted = 0
        AND DATE(payment_multiple_method.added_on) BETWEEN cash_deposit.sales_date_from AND cash_deposit.sales_date_to) - SUM(cash_deposit.amount)
    ) AS total_variance,
    bank.name AS bank_name,
    cash_deposit.status,
    cash_deposit.reference_no
FROM cash_deposit
LEFT JOIN bank ON bank.id = cash_deposit.bank_id
LEFT JOIN {MASTER_DB}.user AS reviewed_by ON reviewed_by.id = cash_deposit.reviewed_by
WHERE cash_deposit.is_deleted = 0
EOT;

        if ($cash_deposit_id) {
            $sql .= <<<EOT

AND cash_deposit.id = ?
EOT;
            $binds[] = $cash_deposit_id;
        }

        if ($bank_id) {
            $sql .= <<<EOT

AND cash_deposit.bank_id = ?
EOT;
            $binds[] = $bank_id;
        }

        if ($status) {
            if ($status == 'reviewed') {
                $sql .= <<<EOT

AND cash_deposit.is_reviewed = 1
EOT;
            } elseif ($status == 'for_review') {
                $sql .= <<<EOT

AND cash_deposit.is_reviewed = 0
EOT;
            } else {
                $sql .= <<<EOT

AND cash_deposit.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND cash_deposit.deposit_date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql .= " GROUP BY cash_deposit.deposit_date, cash_deposit.sales_date_from, cash_deposit.sales_date_to, cash_deposit.bank_id";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_report_for_deposits($date_from = null, $date_to = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT NULL AS id,
    cash_dates.sales_date AS sales_date_from,
    cash_dates.sales_date AS sales_date_to,
    NULL AS deposit_date,
    NULL AS is_reviewed,
    NULL AS reviewed_by,
    NULL AS reviewed_by_name,
    NULL AS reviewed_on,
    NULL AS reviewed_remarks,
    0 AS total_amount,
    cash_dates.total_cash_sales,
    cash_dates.total_cash_sales AS total_variance,
    NULL AS bank_id,
    NULL AS bank_name,
    'for_deposits' AS status,
    NULL AS reference_no
FROM (
    SELECT DATE(payment_multiple_method.added_on) AS sales_date,
        SUM(payment_multiple_method.amount) AS total_cash_sales
    FROM payment_multiple_method
    LEFT JOIN payment ON payment.id = payment_multiple_method.payment_id
    LEFT JOIN transaction ON transaction.id = payment.transaction_id
    WHERE payment_multiple_method.is_deleted = 0
    AND payment_multiple_method.type = 'cash'
    AND transaction.is_deleted = 0
EOT;

        if ($date_from && $date_to) {
            $sql .= <<<EOT

    AND DATE(payment_multiple_method.added_on) BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql .= <<<EOT

    GROUP BY DATE(payment_multiple_method.added_on)
) AS cash_dates
EOT;

        $sql .= <<<EOT

WHERE NOT EXISTS (
    SELECT 1
    FROM cash_deposit
    WHERE cash_deposit.is_deleted = 0
    AND cash_dates.sales_date BETWEEN cash_deposit.sales_date_from AND cash_deposit.sales_date_to
)
ORDER BY cash_dates.sales_date
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>