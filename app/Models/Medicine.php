<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Medicine extends MYT_Model
{
    protected $table = 'medicine';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'generic',
        'brand',
        'dosage',
        'preparation',
        'is_vaccine',
        'qty',
        'unit',
        // 'beginning_inventory',
        'min_level',
        'max_level',
        'critical_level'
    ];

    public function get_details($medicine_id = null, $brand = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT medicine.*
FROM medicine
WHERE medicine.is_deleted = 0
EOT;

        if ($medicine_id) {
            $sql .= <<<EOT

AND medicine.id = ?
EOT;
            $binds[] = $medicine_id;
        }

        if ($brand) {
            $sql .= <<<EOT

AND medicine.brand = ?
EOT;
            $binds[] = $brand;
        }

        if ($term) {
            $sql .= <<<EOT

AND (medicine.name LIKE ? OR medicine.brand LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>