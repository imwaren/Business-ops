<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Branch_item_adjustment extends MYT_Model
{
    protected $table = 'branch_item_adjustment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_item_id',
        'branch_id',
        'item_id',
        'counted_by_id',
        'adjustment_type',
        'physical_count',
        'difference',
        'prev_qty',
        'date_delivered',
        'date_counted',
        'remarks',
    ];

    public function get_details($item_id = null, $branch_id = null, $branch_item_id = null, $status = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT branch_item_adjustment.*,
    item.name AS item_name,
    branch_item_adjustment.added_on,
    CONCAT(approved_by.first_name, ' ', approved_by.last_name) AS approved_by_name,
    CONCAT(disapproved_by.first_name, ' ', disapproved_by.last_name) AS disapproved_by_name,
    department.name AS department_name
FROM branch_item_adjustment
LEFT JOIN item ON item.id = branch_item_adjustment.item_id
LEFT JOIN {MASTER_DB}.user AS approved_by ON approved_by.id = branch_item_adjustment.approved_by AND approved_by.is_deleted = 0
LEFT JOIN {MASTER_DB}.user AS disapproved_by ON disapproved_by.id = branch_item_adjustment.disapproved_by AND disapproved_by.is_deleted = 0
LEFT JOIN {MASTER_DB}.department AS department ON department.id = branch_item_adjustment.department_id
WHERE branch_item_adjustment.is_deleted = 0
EOT;

        if ($item_id) {
            $sql .= <<<EOT

AND branch_item_adjustment.item_id = ?
EOT;
            $binds[] = $item_id;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND branch_item_adjustment.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($branch_item_id) {
            $sql .= <<<EOT

AND branch_item_adjustment.branch_item_id = ?
EOT;
            $binds[] = $branch_item_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND branch_item_adjustment.status = ?
EOT;
            $binds[] = $status;
        }

        // $sql .= " ORDER BY branch_item_adjustment.id DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>