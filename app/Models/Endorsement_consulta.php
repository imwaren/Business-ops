<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_consulta extends MYT_Model
{
    protected $table = 'endorsement_consulta';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'type',
        'type_id',
        'type_price',
        'mark_up',
        'mark_up_amount',
        'total_amount',
        'head_count',
    ];

    public function get_details($endorsement_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_consulta.id,
    endorsement_consulta.endorsement_id,
    endorsement_consulta.type,
    endorsement_consulta.type_id,
    endorsement_consulta.type_price,
    endorsement_consulta.mark_up,
    endorsement_consulta.mark_up_amount,
    endorsement_consulta.total_amount,
    endorsement_consulta.head_count,
    CASE 
        WHEN endorsement_consulta.type = 'lab_test' THEN lab_test.name
        WHEN endorsement_consulta.type = 'package' THEN package.name
    END AS type_name
FROM endorsement_consulta
LEFT JOIN lab_test ON lab_test.id = endorsement_consulta.type_id AND endorsement_consulta.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = endorsement_consulta.type_id AND endorsement_consulta.type = 'package' AND package.is_deleted = 0 
WHERE endorsement_consulta.is_deleted = 0 
EOT;

        if ($endorsement_id) {
            $sql .= <<<EOT

AND endorsement_consulta.endorsement_id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>