<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Medicine_inventory extends MYT_Model
{
    protected $table = 'medicine_inventory';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'medicine_id',
        'qty',
        'unit',
        'beginning_inventory',
        'min_level',
        'max_level',
        'critical_level'
    ];

    public function get_details($medicine_inventory_id = null, $medicine_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT medicine_inventory.id,
    medicine_inventory.medicine_id,
    medicine_inventory.qty,
    medicine_inventory.unit,
    medicine_inventory.beginning_inventory,
    medicine_inventory.min_level,
    medicine_inventory.max_level,
    medicine_inventory.critical_level,
    medicine.name AS medicine_name,
    medicine.brand AS medicine_brand,
    medicine.generic AS medicine_generic,
    medicine.dosage AS medicine_dosage,
    medicine.form AS medicine_form
FROM medicine_inventory
LEFT JOIN medicine ON medicine.id = medicine_inventory.medicine_id AND medicine.is_deleted = 0
WHERE medicine_inventory.is_deleted = 0
EOT;

        if ($medicine_inventory_id) {
            $sql .= <<<EOT

AND medicine_inventory.id = ?
EOT;
            $binds[] = $medicine_inventory_id;
        }

        if ($medicine_id) {
            $sql .= <<<EOT

AND medicine_inventory.medicine_id = ?
EOT;
            $binds[] = $medicine_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND medicine.name LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>