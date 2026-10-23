<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_service_package_detail extends MYT_Model
{
    protected $table = 'costing_service_package_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_service_id',
        'costing_id',
        'package_id',
        'lab_test_id',
        'is_add_on',
        'price',
        'margin',
        'cost',
        'pro_rate_price',
        'discount',
        'total_amount',
        'branch_id',
        'charge_to',
        'head_count',
        'discount_amount',
        'margin_amount',
        'sales',
        'admin_fee',
        'direct_cost',
        'manpower_cost',
        'mobilization_cost',
        'total_cost',
        'estimated_margin',
        'direct_cost_amount_total',
        'manpower_cost_amount_total',
        'mobilization_cost_amount_total',
        'total_cost_amount_total',
        'estimated_margin_amount_total',
        'estimated_revenue',
        'pre_negotiated_rate',
        'theoretical_cost',
    ];

    public function get_details($costing_service_id = null, $costing_id = null, $package_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_service_package_detail.id,
    costing_service_package_detail.costing_service_id,
    costing_service_package_detail.costing_id,
    costing_service_package_detail.package_id,
    costing_service_package_detail.lab_test_id,
    costing_service_package_detail.is_add_on,
    costing_service_package_detail.price,
    costing_service_package_detail.margin,
    costing_service_package_detail.cost,
    costing_service_package_detail.pro_rate_price,
    costing_service_package_detail.discount,
    costing_service_package_detail.total_amount,
    costing_service_package_detail.branch_id,
    costing_service_package_detail.charge_to,
    costing_service_package_detail.head_count,
    costing_service_package_detail.discount_amount,
    costing_service_package_detail.margin_amount,
    costing_service_package_detail.sales,
    costing_service_package_detail.admin_fee,
    costing_service_package_detail.direct_cost,
    costing_service_package_detail.manpower_cost,
    costing_service_package_detail.mobilization_cost,
    costing_service_package_detail.total_cost,
    costing_service_package_detail.estimated_margin,
    costing_service_package_detail.direct_cost_amount_total,
    costing_service_package_detail.manpower_cost_amount_total,
    costing_service_package_detail.mobilization_cost_amount_total,
    costing_service_package_detail.total_cost_amount_total,
    costing_service_package_detail.estimated_margin_amount_total,
    costing_service_package_detail.estimated_revenue,
    costing_service_package_detail.pre_negotiated_rate,
    costing_service_package_detail.theoretical_cost,
    lab_test.name AS lab_test_name,
    package.name AS package_name
FROM costing_service_package_detail
LEFT JOIN lab_test ON lab_test.id = costing_service_package_detail.lab_test_id
LEFT JOIN package ON package.id = costing_service_package_detail.package_id
WHERE costing_service_package_detail.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_service_package_detail.costing_service_id', $costing_service_id)) {
            return [];
        }

        if (!$this->_append_id_filter($sql, $binds, 'costing_service_package_detail.costing_id', $costing_id)) {
            return [];
        }

        if (!$this->_append_id_filter($sql, $binds, 'costing_service_package_detail.package_id', $package_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>