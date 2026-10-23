<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Vaccine extends MYT_Model
{
    protected $table = 'vaccine';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'vax',
        'shot',
        'type',
        'generic',
        'brand',
        'cost_price',
        'description',
        'remarks'
    ];

    public function get_details($vaccine_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT vaccine.id, 
    vaccine.vax, 
    vaccine.shot, 
    vaccine.type,
    vaccine.generic,
    vaccine.brand,
    vaccine.cost_price,
    vaccine.description,
    vaccine.remarks
FROM vaccine
WHERE vaccine.is_deleted = 0
EOT;

        if ($vaccine_id) {
            $sql .= <<<EOT

AND vaccine.id = ?
EOT;
            $binds[] = $vaccine_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND vaccine.vax LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>