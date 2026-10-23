<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Non_cash_deposit extends MYT_Model
{
    protected $table = 'non_cash_deposit';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id',
        'transaction_no',
        'transaction_date',
        'payment_id',
        'payment_multiple_method_id',
        'bank_service_fee_id',
        'bank_service_fee_percentage',
        'amount',
        'mop',
        'reference_no',
        'remarks',
        'status',
        'posted_remarks',
        'posted_by',
        'posted_on',
    ];

    public function get_details($non_cash_deposit_id = null, $mop = null, $status = null, $date_from = null, $date_to = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT non_cash_deposit.*, 
    bank.name AS bank_name,
    bank.id AS bank_id,
    bank_service_fee.percentage AS bank_service_fee_rate,
    CONCAT_WS(' ', reviewed_by.first_name, reviewed_by.middle_name, reviewed_by.last_name) AS reviewed_by_name
FROM non_cash_deposit
LEFT JOIN bank_service_fee ON bank_service_fee.id = non_cash_deposit.bank_service_fee_id
    AND bank_service_fee.is_deleted = 0
LEFT JOIN bank ON bank.id = bank_service_fee.bank_id
    AND bank.is_deleted = 0
LEFT JOIN {MASTER_DB}.user AS reviewed_by ON reviewed_by.id = non_cash_deposit.reviewed_by
WHERE non_cash_deposit.is_deleted = 0
EOT;

        if ($non_cash_deposit_id) {
            $sql .= <<<EOT

AND non_cash_deposit.id = ?
EOT;
            $binds[] = $non_cash_deposit_id;
        }

        if ($mop) {
            $sql .= <<<EOT

AND non_cash_deposit.mop = ?
EOT;
            $binds[] = $mop;
        }

        if ($status) {
            if ($status == 'reviewed') {
                $sql .= <<<EOT

AND non_cash_deposit.is_reviewed = 1
EOT;
            } elseif ($status == 'for_review') {
                $sql .= <<<EOT

AND non_cash_deposit.is_reviewed = 0
EOT;
            } else {
                $sql .= <<<EOT

AND non_cash_deposit.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND non_cash_deposit.transaction_date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_report($non_cash_deposit_id = null, $mop = null, $status = null, $date_from = null, $date_to = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT non_cash_deposit.id,
    non_cash_deposit.transaction_date,
    non_cash_deposit.is_reviewed,
    non_cash_deposit.reviewed_by,
    CONCAT_WS(' ', reviewed_by.first_name, reviewed_by.middle_name, reviewed_by.last_name) AS reviewed_by_name,
    non_cash_deposit.reviewed_on,
    non_cash_deposit.reviewed_remarks,
    SUM(non_cash_deposit.amount) AS total_amount,
    non_cash_deposit.mop,
    non_cash_deposit.bank_service_fee_id,
    non_cash_deposit.bank_service_fee_percentage,
    bank.name AS bank_name,
    non_cash_deposit.status,
    non_cash_deposit.reference_no
FROM non_cash_deposit
LEFT JOIN bank_service_fee ON bank_service_fee.id = non_cash_deposit.bank_service_fee_id
    AND bank_service_fee.is_deleted = 0
LEFT JOIN bank ON bank.id = bank_service_fee.bank_id
    AND bank.is_deleted = 0
LEFT JOIN {MASTER_DB}.user AS reviewed_by ON reviewed_by.id = non_cash_deposit.reviewed_by
WHERE non_cash_deposit.is_deleted = 0
EOT;

        if ($non_cash_deposit_id) {
            $sql .= <<<EOT

AND non_cash_deposit.id = ?
EOT;
            $binds[] = $non_cash_deposit_id;
        }

        if ($mop) {
            $sql .= <<<EOT

AND non_cash_deposit.mop = ?
EOT;
            $binds[] = $mop;
        }

        if ($status) {
            if ($status == 'reviewed') {
                $sql .= <<<EOT

AND non_cash_deposit.is_reviewed = 1
EOT;
            } elseif ($status == 'for_review') {
                $sql .= <<<EOT

AND non_cash_deposit.is_reviewed = 0
EOT;
            } else {
                $sql .= <<<EOT

AND non_cash_deposit.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND non_cash_deposit.transaction_date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql .= " GROUP BY non_cash_deposit.transaction_date, non_cash_deposit.mop, non_cash_deposit.bank_service_fee_id, non_cash_deposit.bank_service_fee_percentage, bank.name";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
