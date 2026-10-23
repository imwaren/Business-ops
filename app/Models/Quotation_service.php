<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Quotation_service extends MYT_Model
{
    protected $table = 'quotation_service';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'quotation_id',
        'type',
        'type_id',
        'type_price',
        'margin',
        'cost',
        'pro_rate_price',
        'discount',
        'total_amount',
        'branch_id',
        'status',
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

    public function get_details($quotation_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT quotation_service.id,
    quotation_service.quotation_id,
    quotation_service.type,
    quotation_service.type_id,
    quotation_service.type_price,
    quotation_service.margin,
    quotation_service.cost,
    quotation_service.pro_rate_price,
    quotation_service.discount,
    quotation_service.total_amount,
    quotation_service.branch_id,
    quotation_service.status,
    quotation_service.disapproved_reason,
    quotation_service.charge_to,
    quotation_service.charge_to_ids,
    quotation_service.package_ids,
    quotation_service.is_add_on,
    GROUP_CONCAT(company.id SEPARATOR '|') AS charge_to_ids_joined,
    GROUP_CONCAT(company.name SEPARATOR '|') AS charge_to_names,
    quotation_service.head_count,
    quotation_service.discount_amount,
    quotation_service.margin_amount,
    quotation_service.sales,
    quotation_service.admin_fee,
    quotation_service.direct_cost,
    quotation_service.manpower_cost,
    quotation_service.mobilization_cost,
    quotation_service.total_cost,
    quotation_service.estimated_margin,
    quotation_service.direct_cost_amount_total,
    quotation_service.manpower_cost_amount_total,
    quotation_service.mobilization_cost_amount_total,
    quotation_service.total_cost_amount_total,
    quotation_service.estimated_margin_amount_total,
    quotation_service.estimated_revenue,
    quotation_service.pre_negotiated_rate,
    quotation_service.is_guaranteed,
    quotation_service.guaranteed_headcount,
    quotation_service.theoretical_cost,
    CASE 
        WHEN quotation_service.type = 'lab_test' THEN lab_test.name
        WHEN quotation_service.type = 'package' THEN package.name
    END AS type_name
FROM quotation_service
LEFT JOIN lab_test ON lab_test.id = quotation_service.type_id AND quotation_service.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = quotation_service.type_id AND quotation_service.type = 'package' AND package.is_deleted = 0
LEFT JOIN company ON FIND_IN_SET(company.id, quotation_service.charge_to_ids) 
WHERE quotation_service.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'quotation_service.quotation_id', $quotation_id)) {
            return [];
        }

        // GROUP BY is needed to properly aggregate charge_to_names when joining with company
        $sql .= " GROUP BY quotation_service.id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_packages($quotation_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT quotation_service.quotation_id,
    GROUP_CONCAT(
        CASE 
            WHEN quotation_service.type = 'lab_test' THEN lab_test.name
            WHEN quotation_service.type = 'package' THEN package.name
        END
        ORDER BY 
            CASE 
                WHEN quotation_service.type = 'lab_test' THEN lab_test.name
                WHEN quotation_service.type = 'package' THEN package.name
            END
        SEPARATOR ', '
    ) AS service_names
FROM quotation_service
LEFT JOIN lab_test ON lab_test.id = quotation_service.type_id AND quotation_service.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = quotation_service.type_id AND quotation_service.type = 'package' AND package.is_deleted = 0
WHERE quotation_service.is_deleted = 0
EOT;

        if ($quotation_id) {
            $sql .= <<<EOT

AND quotation_service.quotation_id = ?
EOT;
            $binds[] = $quotation_id;
        }

        $sql .= " GROUP BY quotation_service.quotation_id";

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray()[0]['service_names'] : false;
    }
}

?>