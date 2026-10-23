<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_discount extends MYT_Model
{
    protected $table = 'costing_discount';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_id',
        'type',
        'type_id',
        'type_price',
        'discount',
        'discount_amount',
        'total_amount'
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_discount.id,
    costing_discount.costing_id,
    costing_discount.type,
    costing_discount.type_id,
    costing_discount.type_price,
    costing_discount.discount,
    costing_discount.discount_amount,
    costing_discount.total_amount,
    CASE 
        WHEN costing_discount.type = 'lab_test' THEN lab_test.name
        WHEN costing_discount.type = 'package' THEN package.name
    END AS type_name
FROM costing_discount
LEFT JOIN lab_test ON lab_test.id = costing_discount.type_id AND costing_discount.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = costing_discount.type_id AND costing_discount.type = 'package' AND package.is_deleted = 0 
WHERE costing_discount.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_discount.costing_id', $costing_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>