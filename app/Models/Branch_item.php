<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Branch_item extends MYT_Model
{
    protected $table = 'branch_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_id',
        'beggining_qty',
        'current_qty',
        'unit',
    ];

    public function get_details($item_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT branch_item.*,
    item_conversion.unit AS unit_name
FROM branch_item
LEFT JOIN item_conversion ON item_conversion.id = branch_item.unit AND item_conversion.is_deleted = 0
WHERE branch_item.is_deleted = 0
EOT;

        if ($item_id) {
            $sql .= <<<EOT

AND branch_item.item_id = ?
EOT;
            $binds[] = $item_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
    /** Read current inventory inside a transfer transaction. */
    public function get_for_update($item_id, $branch_id, $unit)
    {
        $sql = 'SELECT * FROM branch_item WHERE item_id = ? AND branch_id = ? AND unit = ? AND is_deleted = 0 FOR UPDATE';
        try {
            $query = $this->db->query($sql, [$item_id, $branch_id, $unit]);
            return $query ? $query->getRow() : false;
        } catch (\Throwable $error) {
            log_message('error', 'Failed to read current transfer inventory.');
            return false;
        }
    }

}

?>