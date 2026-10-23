<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Request_service extends MYT_Model
{
    protected $table = 'request_service';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'request_id',
        'type',
        'type_id',
        'type_price',
        'type_cost',
        'branch_id',
        'pre_negotiated_rate',
        'charge_to',
        'charge_to_ids',
        'package_ids',
        'is_add_on',
        'head_count',
        'discount_amount',
        'margin_amount',
        'is_guaranteed',
        'guaranteed_headcount',
    ];

    public function get_details($request_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT request_service.id,
    request_service.request_id,
    request_service.type,
    request_service.type_id,
    request_service.type_price,
    request_service.type_cost,
    request_service.branch_id,
    request_service.pre_negotiated_rate,
    request_service.charge_to,
    request_service.charge_to_ids,
    request_service.package_ids,
    request_service.is_add_on,
    GROUP_CONCAT(company.name ORDER BY company.name SEPARATOR ', ') AS charge_to_names,
    request_service.head_count,
    request_service.discount_amount,
    request_service.margin_amount,
    request_service.is_guaranteed,
    request_service.guaranteed_headcount,
    CASE 
        WHEN request_service.type = 'lab_test' THEN lab_test.name
        WHEN request_service.type = 'package' THEN package.name
    END AS type_name,
    CASE 
        WHEN request_service.type = 'lab_test' THEN lab_test.theoretical_cost
        WHEN request_service.type = 'package' THEN package.total_theoretical_cost
    END AS theoretical_cost
FROM request_service
LEFT JOIN lab_test ON lab_test.id = request_service.type_id AND request_service.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = request_service.type_id AND request_service.type = 'package' AND package.is_deleted = 0
LEFT JOIN company ON FIND_IN_SET(company.id, request_service.charge_to_ids)
WHERE request_service.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'request_service.request_id', $request_id)) {
            return [];
        }

        // GROUP BY is needed to properly aggregate charge_to_names when joining with company
        $sql .= " GROUP BY request_service.id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>