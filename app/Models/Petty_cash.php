<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Petty_cash extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'petty_cash';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'account_type_id',
        'requested_by_id',
        'department_id',
        'cost_center_id',
        'date',
        'due_date',
        'amount',
        'balance',
        'remarks'
    ];

    public function get_details($petty_cash_id = null, $department_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT petty_cash.*, 
    account_type.name AS account_type_name,
    department.name AS department_name
FROM petty_cash
LEFT JOIN {MASTER_DB}.user AS requested_by ON requested_by.id=petty_cash.requested_by_id
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = petty_cash.account_type_id
LEFT JOIN {MASTER_DB}.department AS department ON department.id = petty_cash.department_id
WHERE petty_cash.is_deleted = 0
EOT;

        if ($petty_cash_id) {
            $sql .= <<<EOT

AND petty_cash.id = ?
EOT;
            $binds[] = $petty_cash_id;
        }

        if ($department_id) {
            $sql .= <<<EOT

AND petty_cash.department_id = ?
EOT;
            $binds[] = $department_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND petty_cash.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (CONCAT_WS(' ', requested_by.first_name, requested_by.middle_name, requested_by.last_name) LIKE ? OR petty_cash.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY petty_cash.due_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>