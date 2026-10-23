<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_discount extends MYT_Model
{
    protected $table = 'endorsement_discount';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'type',
        'type_id',
        'type_price',
        'discount',
        'discount_amount',
        'total_amount'
    ];

    public function get_details($endorsement_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_discount.id,
    endorsement_discount.endorsement_id,
    endorsement_discount.type,
    endorsement_discount.type_id,
    endorsement_discount.type_price,
    endorsement_discount.discount,
    endorsement_discount.discount_amount,
    endorsement_discount.total_amount,
    CASE 
        WHEN endorsement_discount.type = 'lab_test' THEN lab_test.name
        WHEN endorsement_discount.type = 'package' THEN package.name
    END AS type_name
FROM endorsement_discount
LEFT JOIN lab_test ON lab_test.id = endorsement_discount.type_id AND endorsement_discount.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = endorsement_discount.type_id AND endorsement_discount.type = 'package' AND package.is_deleted = 0 
WHERE endorsement_discount.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_discount.endorsement_id', $endorsement_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>