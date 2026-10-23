<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_manpower_cost_premium_pay extends MYT_Model
{
    protected $table = 'costing_manpower_cost_premium_pay';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_id',
        'costing_manpower_cost_id',
        'premium_pay',
        'premium_pay_amount',
        'premium_hrs',
        'ingress_hrs',
        'total_premium_pay',
        'total'
    ];

    public function get_details($costing_id = null, $costing_manpower_cost_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_manpower_cost_premium_pay.*
FROM costing_manpower_cost_premium_pay
WHERE costing_manpower_cost_premium_pay.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_manpower_cost_premium_pay.costing_id', $costing_id)) {
            return [];
        }

        if (!$this->_append_id_filter($sql, $binds, 'costing_manpower_cost_premium_pay.costing_manpower_cost_id', $costing_manpower_cost_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>