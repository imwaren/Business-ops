<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Change_fund extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'change_fund';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'requested_by_id',
        'department_id',
        'account_type_id',
        'branch_id',
        'cost_center_id',
        'invoice_no',
        'pos_no',
        'date',
        'amount',
        'remarks',
        'due_date'
    ];

    public function get_details($change_fund_id = null, $department_id = null, $status = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT change_fund.*, 
    account_type.name AS account_type_name,
    department.name AS department_name
FROM change_fund
LEFT JOIN {MASTER_DB}.user AS requested_by ON requested_by.id=change_fund.requested_by_id
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = change_fund.account_type_id
LEFT JOIN {MASTER_DB}.department AS department ON department.id = change_fund.department_id
WHERE change_fund.is_deleted = 0
EOT;

        if ($change_fund_id) {
            $sql .= <<<EOT

AND change_fund.id = ?
EOT;
            $binds[] = $change_fund_id;
        }

        if ($department_id) {
            $sql .= <<<EOT

AND change_fund.department_id = ?
EOT;
            $binds[] = $department_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND change_fund.status = ?
EOT;
            $binds[] = $status;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND change_fund.date BETWEEN ? AND ? 
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($term) {
            $sql .= <<<EOT

AND (CONCAT_WS(' ', requested_by.first_name, requested_by.middle_name, requested_by.last_name) LIKE ? OR change_fund.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY change_fund.due_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>