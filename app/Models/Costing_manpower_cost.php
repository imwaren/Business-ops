<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_manpower_cost extends MYT_Model
{
    protected $table = 'costing_manpower_cost';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_id',
        'description',
        'shift_no',
        'basic_pay',
        'premium_pay',
        'premium_pay_amount',
        'premium_hrs',
        'ingress_hrs',
        'total_premium_pay',
        'total'
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_manpower_cost.*
FROM costing_manpower_cost
WHERE costing_manpower_cost.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_manpower_cost.costing_id', $costing_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>