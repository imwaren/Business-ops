<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Quotation_detail extends MYT_Model
{
    protected $table = 'quotation_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'quotation_id',
        'lab_test_id',
        'branch_id',
        'quantity',
        'unit_price',
        'total_amount',
        'discount',
        'pro_rate_price',
        'margin',
        'cost'
    ];

    public function get_details($quotation_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT quotation_detail.id, 
    quotation_detail.quotation_id, 
    quotation_detail.lab_test_id, 
    quotation_detail.branch_id, 
    quotation_detail.quantity, 
    quotation_detail.unit_price, 
    quotation_detail.total_amount, 
    quotation_detail.discount,
    quotation_detail.pro_rate_price,
    quotation_detail.margin,
    quotation_detail.cost,
    lab_test.name AS lab_test_name
FROM quotation_detail
LEFT JOIN lab_test ON lab_test.branch_lab_test_id = quotation_detail.lab_test_id AND lab_test.is_deleted = 0
WHERE quotation_detail.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'quotation_detail.quotation_id', $quotation_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>