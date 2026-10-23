<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_service extends MYT_Model
{
    protected $table = 'costing_service';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_id',
        'type',
        'type_id',
        'type_price',
        'margin',
        'cost',
        'pro_rate_price',
        'discount',
        'total_amount',
        'branch_id',
        'charge_to',
        'charge_to_ids',
        'package_ids',
        'is_add_on',
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
        'is_guaranteed',
        'guaranteed_headcount',
        'theoretical_cost'
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_service.id,
    costing_service.costing_id,
    costing_service.type,
    costing_service.type_id,
    costing_service.type_price,
    costing_service.margin,
    costing_service.cost,
    costing_service.pro_rate_price,
    costing_service.discount,
    costing_service.total_amount,
    costing_service.branch_id,
    costing_service.charge_to,
    costing_service.charge_to_ids,
    costing_service.package_ids,
    costing_service.is_add_on,
    GROUP_CONCAT(company.name ORDER BY company.name SEPARATOR ', ') AS charge_to_names,
    costing_service.head_count,
    costing_service.discount_amount,
    costing_service.margin_amount,
    costing_service.sales,
    costing_service.admin_fee,
    costing_service.direct_cost,
    costing_service.manpower_cost,
    costing_service.mobilization_cost,
    costing_service.total_cost,
    costing_service.estimated_margin,
    costing_service.direct_cost_amount_total,
    costing_service.manpower_cost_amount_total,
    costing_service.mobilization_cost_amount_total,
    costing_service.total_cost_amount_total,
    costing_service.estimated_margin_amount_total,
    costing_service.estimated_revenue,
    costing_service.pre_negotiated_rate,
    costing_service.is_guaranteed,
    costing_service.guaranteed_headcount,
    costing_service.theoretical_cost,
    CASE 
        WHEN costing_service.type = 'lab_test' THEN lab_test.name
        WHEN costing_service.type = 'package' THEN package.name
    END AS type_name
FROM costing_service
LEFT JOIN lab_test ON lab_test.id = costing_service.type_id AND costing_service.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = costing_service.type_id AND costing_service.type = 'package' AND package.is_deleted = 0 
LEFT JOIN company ON FIND_IN_SET(company.id, costing_service.charge_to_ids) 
WHERE costing_service.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_service.costing_id', $costing_id)) {
            return [];
        }

        // GROUP BY is needed to properly aggregate charge_to_names when joining with company
        $sql .= " GROUP BY costing_service.id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_packages($costing_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_service.costing_id,
    GROUP_CONCAT(
        CASE 
            WHEN costing_service.type = 'lab_test' THEN lab_test.name
            WHEN costing_service.type = 'package' THEN package.name
        END
        ORDER BY 
            CASE 
                WHEN costing_service.type = 'lab_test' THEN lab_test.name
                WHEN costing_service.type = 'package' THEN package.name
            END
        SEPARATOR ', '
    ) AS service_names
FROM costing_service
LEFT JOIN lab_test ON lab_test.id = costing_service.type_id AND costing_service.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = costing_service.type_id AND costing_service.type = 'package' AND package.is_deleted = 0
WHERE costing_service.is_deleted = 0
EOT;

        if ($costing_id) {
            $sql .= <<<EOT

AND costing_service.costing_id = ?
EOT;
            $binds[] = $costing_id;
        }

        $sql .= " GROUP BY costing_service.costing_id";

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray()[0]['service_names'] : false;
    }
}

?>