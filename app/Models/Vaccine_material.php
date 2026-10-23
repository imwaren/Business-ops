<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Vaccine_material extends MYT_Model
{
    protected $table = 'vaccine_material';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'vaccine_id',
        'item_id',
        'qty',
        'unit'
    ];

    public function get_materials($vaccine_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT vaccine_material.id, 
    vaccine_material.vaccine_id, 
    vaccine_material.qty,
    vaccine_material.unit,
    vaccine_material.item_id
FROM vaccine_material
LEFT JOIN vaccine ON vaccine.id = vaccine_material.vaccine_id AND vaccine.is_deleted = 0
WHERE vaccine_material.is_deleted = 0
EOT;

        if ($vaccine_id) {
            $sql .= <<<EOT

AND vaccine_material.vaccine_id = ?
EOT;
            $binds[] = $vaccine_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>