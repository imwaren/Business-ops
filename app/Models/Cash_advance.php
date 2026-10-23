<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Cash_advance extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'cash_advance';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'account_type_id',
        'requested_by_id',
        'department_id',
        'cost_center_id',
        'date',
        'amount',
        'remarks',
        'date_needed',
        'balance',
        'due_date'
    ];

    public function get_details($cash_advance_id = null, $department_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT cash_advance.*, 
    account_type.name AS account_type_name,
    department.name AS department_name,
    IFNULL(liq.total_liquidated, 0) AS liquidated_amount,
    IFNULL(dep.total_deposited, 0) AS deposited_amount,
    cash_advance.amount - (IFNULL(liq.total_liquidated, 0) + IFNULL(dep.total_deposited, 0)) AS balance
FROM cash_advance
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = cash_advance.account_type_id
LEFT JOIN {MASTER_DB}.user AS requested_by ON requested_by.id = cash_advance.requested_by_id
LEFT JOIN {MASTER_DB}.department AS department ON department.id = cash_advance.department_id 
LEFT JOIN (
    SELECT id, cash_advance_id, SUM(total) AS total_liquidated
    FROM liquidation
    WHERE is_deleted = 0 AND status = 'approved'
    GROUP BY cash_advance_id
) AS liq ON liq.cash_advance_id = cash_advance.id
LEFT JOIN (
    SELECT id, cash_advance_id, SUM(amount) AS total_deposited
    FROM deposit_detail
    WHERE is_deleted = 0
    GROUP BY cash_advance_id
) AS dep ON dep.cash_advance_id = cash_advance.id
WHERE cash_advance.is_deleted = 0
EOT;

        if ($cash_advance_id) {
            $sql .= <<<EOT

AND cash_advance.id = ?
EOT;
            $binds[] = $cash_advance_id;
        }

        if ($department_id) {
            $sql .= <<<EOT

AND cash_advance.department_id = ?
EOT;
            $binds[] = $department_id;
        }

        if ($status) {
            if ($status == 'approved') {
                $sql .= <<<EOT

AND cash_advance.status = 'approved'
AND liq.id IS NULL
EOT;
            } elseif ($status == 'liquidated') {
                $sql .= <<<EOT

AND cash_advance.status = 'approved'
AND liq.id IS NOT NULL
EOT;

            } elseif ($status == 'for_liquidation') {
                $sql .= <<<EOT

AND cash_advance.status = 'approved'
AND IFNULL(liq.total_liquidated, 0) < cash_advance.amount
EOT;

            } else {
                $sql .= <<<EOT

AND cash_advance.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($term) {
            $sql .= <<<EOT

AND (CONCAT_WS(' ', requested_by.first_name, requested_by.middle_name, requested_by.last_name) LIKE ? OR cash_advance.id LIKE ? OR cash_advance.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " GROUP BY cash_advance.id
                    ORDER BY cash_advance.date_needed DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>