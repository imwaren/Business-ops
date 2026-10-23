<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Branch_item_transfer extends MYT_Model
{
    protected $table = 'branch_item_transfer';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_id_from',
        'branch_id_to',
        'department_id_from',
        'department_id_to',
        'transfer_date',
        'delivery_date',
        'dispatcher',
        'remarks',
    ];

    public function get_details($transfer_id = null, $transfer_date_from = null, $transfer_date_to = null, $delivery_date_from = null, $delivery_date_to = null, $branch_id_from = null, $branch_id_to = null, $status = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT branch_item_transfer.*,
    CONCAT(approver.first_name, ' ', approver.last_name) AS approver_name,
    department_from.name AS department_name_from,
    department_to.name AS department_name_to
FROM branch_item_transfer
LEFT JOIN user AS approver ON approver.id = branch_item_transfer.approved_by
LEFT JOIN {MASTER_DB}.department AS department_from ON department_from.id = branch_item_transfer.department_id_from
LEFT JOIN {MASTER_DB}.department AS department_to ON department_to.id = branch_item_transfer.department_id_to
WHERE branch_item_transfer.is_deleted = 0  
EOT;

        if ($transfer_id) {
            $sql .= <<<EOT

AND branch_item_transfer.id = ?
EOT;
            $binds[] = $transfer_id;
        }

        if ($transfer_date_from) {
            $sql .= <<<EOT

AND branch_item_transfer.transfer_date >= ?
EOT;
            $binds[] = $transfer_date_from;
        }

        if ($transfer_date_to) {
            $sql .= <<<EOT

AND branch_item_transfer.transfer_date <= ?
EOT;
            $binds[] = $transfer_date_to;
        }

        if ($delivery_date_from) {
            $sql .= <<<EOT

AND branch_item_transfer.delivery_date >= ?
EOT;
            $binds[] = $delivery_date_from;
        }

        if ($delivery_date_to) {
            $sql .= <<<EOT

AND branch_item_transfer.delivery_date <= ?
EOT;
            $binds[] = $delivery_date_to;
        }

        if ($branch_id_from) {
            $sql .= <<<EOT

AND branch_item_transfer.branch_id_from = ?
EOT;
            $binds[] = $branch_id_from;
        }

        if ($branch_id_to) {
            $sql .= <<<EOT

AND branch_item_transfer.branch_id_to = ?
EOT;
            $binds[] = $branch_id_to;
        }
        
        if ($status) {
            $sql .= <<<EOT

AND branch_item_transfer.status = ?
EOT;
            $binds[] = $status;
        }

        // $sql .= " ORDER BY branch_item_transfer.id DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
    /** Read the current transfer after inventory item locks have been acquired. */
    public function get_for_update($transfer_id)
    {
        $sql = 'SELECT * FROM branch_item_transfer WHERE id = ? AND is_deleted = 0 FOR UPDATE';
        try {
            $query = $this->db->query($sql, [$transfer_id]);
            return $query ? $query->getRow() : false;
        } catch (\Throwable $error) {
            log_message('error', 'Failed to lock transfer {id}.', ['id' => $transfer_id]);
            return false;
        }
    }

}

?>