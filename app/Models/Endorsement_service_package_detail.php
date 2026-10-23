<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_service_package_detail extends MYT_Model
{
    protected $table = 'endorsement_service_package_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_service_id',
        'endorsement_id',
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

    public function get_details($endorsement_service_id = null, $endorsement_id = null, $package_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_service_package_detail.id,
    endorsement_service_package_detail.endorsement_service_id,
    endorsement_service_package_detail.endorsement_id,
    endorsement_service_package_detail.package_id,
    endorsement_service_package_detail.lab_test_id,
    endorsement_service_package_detail.is_add_on,
    endorsement_service_package_detail.price,
    endorsement_service_package_detail.margin,
    endorsement_service_package_detail.cost,
    endorsement_service_package_detail.pro_rate_price,
    endorsement_service_package_detail.discount,
    endorsement_service_package_detail.total_amount,
    endorsement_service_package_detail.branch_id,
    endorsement_service_package_detail.charge_to,
    endorsement_service_package_detail.head_count,
    endorsement_service_package_detail.discount_amount,
    endorsement_service_package_detail.margin_amount,
    endorsement_service_package_detail.sales,
    endorsement_service_package_detail.admin_fee,
    endorsement_service_package_detail.direct_cost,
    endorsement_service_package_detail.manpower_cost,
    endorsement_service_package_detail.mobilization_cost,
    endorsement_service_package_detail.total_cost,
    endorsement_service_package_detail.estimated_margin,
    endorsement_service_package_detail.direct_cost_amount_total,
    endorsement_service_package_detail.manpower_cost_amount_total,
    endorsement_service_package_detail.mobilization_cost_amount_total,
    endorsement_service_package_detail.total_cost_amount_total,
    endorsement_service_package_detail.estimated_margin_amount_total,
    endorsement_service_package_detail.estimated_revenue,
    endorsement_service_package_detail.pre_negotiated_rate,
    endorsement_service_package_detail.theoretical_cost,
    lab_test.name AS lab_test_name,
    package.name AS package_name,
    lab_test.category_id AS category_id,
    category.name AS category_name
FROM endorsement_service_package_detail
LEFT JOIN lab_test ON lab_test.id = endorsement_service_package_detail.lab_test_id
LEFT JOIN package ON package.id = endorsement_service_package_detail.package_id
LEFT JOIN category ON category.id = lab_test.category_id AND category.is_deleted = 0
WHERE endorsement_service_package_detail.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_service_package_detail.endorsement_service_id', $endorsement_service_id)) {
            return [];
        }

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_service_package_detail.endorsement_id', $endorsement_id)) {
            return [];
        }

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_service_package_detail.package_id', $package_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>