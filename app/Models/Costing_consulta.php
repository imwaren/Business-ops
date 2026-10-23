<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_consulta extends MYT_Model
{
    protected $table = 'costing_consulta';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_id',
        'type',
        'type_id',
        'type_price',
        'mark_up',
        'mark_up_amount',
        'total_amount',
        'head_count',
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_consulta.id,
    costing_consulta.costing_id,
    costing_consulta.type,
    costing_consulta.type_id,
    costing_consulta.type_price,
    costing_consulta.mark_up,
    costing_consulta.mark_up_amount,
    costing_consulta.total_amount,
    costing_consulta.head_count,
    CASE 
        WHEN costing_consulta.type = 'lab_test' THEN lab_test.name
        WHEN costing_consulta.type = 'package' THEN package.name
    END AS type_name
FROM costing_consulta
LEFT JOIN lab_test ON lab_test.id = costing_consulta.type_id AND costing_consulta.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = costing_consulta.type_id AND costing_consulta.type = 'package' AND package.is_deleted = 0 
WHERE costing_consulta.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_consulta.costing_id', $costing_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>