<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_detail extends MYT_Model
{
    protected $table = 'costing_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_id',
        'lab_test_id',
        'quantity',
        'unit_price',
        'total_amount',
        'discount',
        'pro_rate_price',
        'margin',
        'cost'
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_detail.id, 
    costing_detail.costing_id, 
    costing_detail.lab_test_id, 
    costing_detail.quantity, 
    costing_detail.unit_price, 
    costing_detail.total_amount, 
    costing_detail.discount,
    costing_detail.pro_rate_price,
    costing_detail.margin,
    costing_detail.cost,
    lab_test.name AS lab_test_name
FROM costing_detail
LEFT JOIN lab_test ON lab_test.branch_lab_test_id = costing_detail.lab_test_id AND lab_test.is_deleted = 0
WHERE costing_detail.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_detail.costing_id', $costing_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>