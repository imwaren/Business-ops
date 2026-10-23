<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Quotation_service_package_detail extends MYT_Model
{
    protected $table = 'quotation_service_package_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'quotation_service_id',
        'quotation_id',
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

    public function get_details($quotation_service_id = null, $quotation_id = null, $package_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT quotation_service_package_detail.id,
    quotation_service_package_detail.quotation_service_id,
    quotation_service_package_detail.quotation_id,
    quotation_service_package_detail.package_id,
    quotation_service_package_detail.lab_test_id,
    quotation_service_package_detail.is_add_on,
    quotation_service_package_detail.price,
    quotation_service_package_detail.margin,
    quotation_service_package_detail.cost,
    quotation_service_package_detail.pro_rate_price,
    quotation_service_package_detail.discount,
    quotation_service_package_detail.total_amount,
    quotation_service_package_detail.branch_id,
    quotation_service_package_detail.charge_to,
    quotation_service_package_detail.head_count,
    quotation_service_package_detail.discount_amount,
    quotation_service_package_detail.margin_amount,
    quotation_service_package_detail.sales,
    quotation_service_package_detail.admin_fee,
    quotation_service_package_detail.direct_cost,
    quotation_service_package_detail.manpower_cost,
    quotation_service_package_detail.mobilization_cost,
    quotation_service_package_detail.total_cost,
    quotation_service_package_detail.estimated_margin,
    quotation_service_package_detail.direct_cost_amount_total,
    quotation_service_package_detail.manpower_cost_amount_total,
    quotation_service_package_detail.mobilization_cost_amount_total,
    quotation_service_package_detail.total_cost_amount_total,
    quotation_service_package_detail.estimated_margin_amount_total,
    quotation_service_package_detail.estimated_revenue,
    quotation_service_package_detail.pre_negotiated_rate,
    quotation_service_package_detail.theoretical_cost,
    lab_test.name AS lab_test_name,
    package.name AS package_name
FROM quotation_service_package_detail
LEFT JOIN lab_test ON lab_test.id = quotation_service_package_detail.lab_test_id
LEFT JOIN package ON package.id = quotation_service_package_detail.package_id
WHERE quotation_service_package_detail.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'quotation_service_package_detail.quotation_service_id', $quotation_service_id)) {
            return [];
        }

        if (!$this->_append_id_filter($sql, $binds, 'quotation_service_package_detail.quotation_id', $quotation_id)) {
            return [];
        }

        if (!$this->_append_id_filter($sql, $binds, 'quotation_service_package_detail.package_id', $package_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>