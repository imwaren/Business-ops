<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Replenishment extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'replenishment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'liquidated_by_id',
        'department_id',
        'cost_center_id',
        'grand_total',
        'due_date',
    ];

    public function get_details($replenishment_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT replenishment.*,
    department.name AS department_name
FROM replenishment
LEFT JOIN {MASTER_DB}.department AS department ON department.id = replenishment.department_id
WHERE replenishment.is_deleted = 0
EOT;

        if ($replenishment_id) {
            $sql .= <<<EOT

AND replenishment.id = ?
EOT;
            $binds[] = $replenishment_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND replenishment.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (replenishment.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY replenishment.due_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>