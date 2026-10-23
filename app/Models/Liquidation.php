<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Liquidation extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'liquidation';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'cash_advance_id',
        'liquidated_by_id',
        'department_id',
        'cost_center_id',
        'amount',
        'total',
        'balance',
        'remarks',
        'due_date'
    ];

    public function get_details($liquidation_id = null, $department_id = null, $cash_advance_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT liquidation.*,
    CONCAT_WS(' ', requester.first_name, requester.middle_name, requester.last_name) AS requested_by_name,
    cash_advance.balance AS cash_advance_balance,
    CONCAT_WS(' ', liquidated_by.first_name, liquidated_by.middle_name, liquidated_by.last_name) AS liquidated_by_name,
    department.name AS department_name,
    branch.name AS cost_center_name
FROM liquidation
LEFT JOIN {MASTER_DB}.branch ON branch.id = liquidation.cost_center_id AND branch.is_deleted = 0
LEFT JOIN cash_advance ON cash_advance.id = liquidation.cash_advance_id AND cash_advance.is_deleted = 0
LEFT JOIN {MASTER_DB}.user AS requester ON requester.id = cash_advance.requested_by_id AND requester.is_deleted = 0
LEFT JOIN {MASTER_DB}.department AS department ON department.id = liquidation.department_id
LEFT JOIN {MASTER_DB}.user AS liquidated_by ON liquidated_by.id = liquidation.liquidated_by_id
WHERE liquidation.is_deleted = 0
EOT;

        if ($liquidation_id) {
            $sql .= <<<EOT

AND liquidation.id = ?
EOT;
            $binds[] = $liquidation_id;
        }

        if ($department_id) {
            $sql .= <<<EOT

AND liquidation.department_id = ?
EOT;
            $binds[] = $department_id;
        }

        if ($cash_advance_id) {
            $sql .= <<<EOT

AND liquidation.cash_advance_id = ?
EOT;
            $binds[] = $cash_advance_id;
        }

        if ($status) {
            if ($status == 'journal_voucher') { //returned approved liquidations that has a balance
                $sql .= <<<EOT

AND liquidation.status = 'approved'
AND liquidation.balance >= 0
EOT;
            } elseif ($status == 'approved') {
                $sql .= <<<EOT

AND liquidation.status = 'approved'
AND liquidation.balance < 0
EOT; 
            } else {
                $sql .= <<<EOT

AND liquidation.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($term) {
            $sql .= <<<EOT

AND (CONCAT_WS(' ', liquidated_by.first_name, liquidated_by.middle_name, liquidated_by.last_name) LIKE ? OR liquidation.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY liquidation.due_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>