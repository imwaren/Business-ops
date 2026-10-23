<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_mobilization extends MYT_Model
{
    protected $table = 'costing_mobilization';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'description',
        'unit',
        'amount',
        'total',
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_mobilization.*
FROM costing_mobilization
WHERE costing_mobilization.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_mobilization.costing_id', $costing_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>