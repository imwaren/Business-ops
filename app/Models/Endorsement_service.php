<?php

namespace App\Core;

namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_service extends MYT_Model
{
    protected $table = 'endorsement_service';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
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

    public function get_details($endorsement_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_service.id,
    endorsement_service.endorsement_id,
    endorsement_service.type,
    endorsement_service.type_id,
    endorsement_service.type_price,
    endorsement_service.margin,
    endorsement_service.cost,
    endorsement_service.pro_rate_price,
    endorsement_service.discount,
    endorsement_service.total_amount,
    endorsement_service.branch_id,
    endorsement_service.charge_to,
    endorsement_service.charge_to_ids,
    endorsement_service.package_ids,
    endorsement_service.is_add_on,
    GROUP_CONCAT(company.id SEPARATOR '|') AS charge_to_ids_joined,
    GROUP_CONCAT(company.name SEPARATOR '|') AS charge_to_names,
    endorsement_service.head_count,
    endorsement_service.discount_amount,
    endorsement_service.margin_amount,
    endorsement_service.sales,
    endorsement_service.admin_fee,
    endorsement_service.direct_cost,
    endorsement_service.manpower_cost,
    endorsement_service.mobilization_cost,
    endorsement_service.total_cost,
    endorsement_service.estimated_margin,
    endorsement_service.direct_cost_amount_total,
    endorsement_service.manpower_cost_amount_total,
    endorsement_service.mobilization_cost_amount_total,
    endorsement_service.total_cost_amount_total,
    endorsement_service.estimated_margin_amount_total,
    endorsement_service.estimated_revenue,
    endorsement_service.pre_negotiated_rate,
    endorsement_service.is_guaranteed,
    endorsement_service.guaranteed_headcount,
    endorsement_service.theoretical_cost,
    CASE 
        WHEN endorsement_service.type = 'lab_test' THEN lab_test.name
        WHEN endorsement_service.type = 'package' THEN package.name
    END AS type_name,
    lab_test.category_id AS category_id,
    category.name AS category_name
FROM endorsement_service
LEFT JOIN lab_test ON lab_test.id = endorsement_service.type_id AND endorsement_service.type = 'lab_test'
LEFT JOIN package ON package.id = endorsement_service.type_id AND endorsement_service.type = 'package'
LEFT JOIN company ON FIND_IN_SET(company.id, endorsement_service.charge_to_ids) 
LEFT JOIN category ON category.id = lab_test.category_id
WHERE endorsement_service.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_service.endorsement_id', $endorsement_id)) {
            return [];
        }

        // GROUP BY is needed to properly aggregate charge_to_names when joining with company
        $sql .= " GROUP BY endorsement_service.id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_services($endorsement_id = null, $company_id = null, $type = null, $type_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_service.id,
    endorsement_service.endorsement_id,
    endorsement_service.type,
    endorsement_service.type_id,
    endorsement_service.type_price,
    endorsement_service.pro_rate_price
FROM endorsement_service
LEFT JOIN endorsement ON endorsement.id = endorsement_service.endorsement_id
WHERE endorsement_service.is_deleted = 0 
AND endorsement.is_deleted = 0
EOT;

        if ($endorsement_id) {
            $sql .= <<<EOT

AND endorsement_service.endorsement_id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        if ($company_id) {
            $sql .= <<<EOT

AND endorsement.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND endorsement_service.type = ?
EOT;
            $binds[] = $type;
        }

        if ($type_id) {
            $sql .= <<<EOT

AND endorsement_service.type_id = ?
EOT;
            $binds[] = $type_id;
        }

        // order by latest endorsement_service added_on
        $sql .= " ORDER BY endorsement_service.added_on DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_packages($endorsement_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_service.endorsement_id,
    GROUP_CONCAT(
        CASE 
            WHEN endorsement_service.type = 'lab_test' THEN lab_test.name
            WHEN endorsement_service.type = 'package' THEN package.name
        END
        ORDER BY 
            CASE 
                WHEN endorsement_service.type = 'lab_test' THEN lab_test.name
                WHEN endorsement_service.type = 'package' THEN package.name
            END
        SEPARATOR ', '
    ) AS service_names
FROM endorsement_service
LEFT JOIN lab_test ON lab_test.id = endorsement_service.type_id AND endorsement_service.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = endorsement_service.type_id AND endorsement_service.type = 'package' AND package.is_deleted = 0
WHERE endorsement_service.is_deleted = 0
EOT;

        if ($endorsement_id) {
            $sql .= <<<EOT

AND endorsement_service.endorsement_id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        $sql .= " GROUP BY endorsement_service.endorsement_id";

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray()[0]['service_names'] : false;
    }

    public function get_charge_to($endorsement_id = null, $charge_to = null, $company_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT DISTINCT IFNULL(endorsement_service.charge_to_ids, charge_to_company.id) AS charge_to_id,
    IFNULL(company.name, charge_to_company.name) AS charge_to_name
FROM endorsement_service
LEFT JOIN endorsement ON endorsement.id = endorsement_service.endorsement_id
LEFT JOIN company ON company.id = endorsement_service.charge_to_ids
LEFT JOIN company AS charge_to_company ON charge_to_company.id = endorsement.company_id
WHERE endorsement_service.is_deleted = 0 
AND endorsement.is_deleted = 0
EOT;

        if ($endorsement_id) {
            $sql .= <<<EOT

AND endorsement_service.endorsement_id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        if ($charge_to) {
            $sql .= <<<EOT

AND endorsement_service.charge_to_ids = ?
EOT;
            $binds[] = $charge_to;
        }

//         if (!$company_id) {
//             $sql .= <<<EOT

// AND endorsement_service.charge_to_ids IS NULL
// EOT;
//         } else {
//             $sql .= <<<EOT

// AND endorsement.company_id = ?
// EOT;
//             $binds[] = $company_id;
//         }

        if ($company_id) {
            $sql .= <<<EOT

AND endorsement.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        // union in company 3210 so it's always an available option
//         $sql .= <<<EOT

// UNION

// SELECT company.id AS charge_to_id, company.name AS charge_to_name
// FROM company
// WHERE company.id = 3210
// AND company.is_deleted = 0

// UNION

// SELECT company.id AS charge_to_id, company.name AS charge_to_name
// FROM company
// WHERE company.is_hmo = 1
// AND company.is_deleted = 0
// EOT;

        // ORDER BY now has to apply to the combined result, so reference the
        // output alias/column, not company.name directly
        $sql .= " ORDER BY charge_to_name ASC";

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Endorsement IDs that still produce floating patients for a company / charge-to / branch.
     *
     * @param array<int|string>|int|string|null $charge_to
     * @return int[]
     */
    public function get_endorsement_ids_for_billing(
        $company_id = null,
        $charge_to = null,
        $branch_id = null,
        $endorsement_id = null
    ): array {
        $charge_ids = [];
        if (is_array($charge_to)) {
            foreach ($charge_to as $id) {
                if ($id === null || $id === '' || $id === '0') {
                    continue;
                }
                $charge_ids[] = (int) $id;
            }
            $charge_ids = array_values(array_unique($charge_ids));
        } elseif ($charge_to !== null && $charge_to !== '' && $charge_to !== '0') {
            $charge_ids[] = (int) $charge_to;
        }

        if (!$company_id && !$charge_ids && !$endorsement_id) {
            return [];
        }

        $binds = [];
        $sql = <<<EOT
SELECT DISTINCT endorsement.id
FROM endorsement
INNER JOIN endorsement_service
    ON endorsement_service.endorsement_id = endorsement.id
    AND endorsement_service.is_deleted = 0
WHERE endorsement.is_deleted = 0
EOT;

        if ($endorsement_id) {
            $sql .= "\nAND endorsement.id = ?";
            $binds[] = $endorsement_id;
        }

        if ($company_id) {
            $sql .= "\nAND endorsement.company_id = ?";
            $binds[] = $company_id;
        }

        if ($charge_ids) {
            $ors = [];
            foreach ($charge_ids as $id) {
                $ors[] = 'endorsement_service.charge_to = ?';
                $binds[] = $id;
                $ors[] = 'FIND_IN_SET(?, endorsement_service.charge_to_ids)';
                $binds[] = $id;
                $ors[] = "(endorsement_service.charge_to = 'company'"
                    . " AND (endorsement_service.charge_to_ids IS NULL OR endorsement_service.charge_to_ids = '')"
                    . " AND endorsement.company_id = ?)";
                $binds[] = $id;
            }
            $sql .= "\nAND (" . implode(' OR ', $ors) . ")";
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND (
    FIND_IN_SET(?, endorsement.branch_id)
    OR endorsement.preferred_branch_id = ?
    OR endorsement_service.branch_id = ?
)
EOT;
            $binds[] = $branch_id;
            $binds[] = $branch_id;
            $binds[] = $branch_id;
        }

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return [];
        }

        $ids = [];
        foreach ($query->getResultArray() as $row) {
            if (isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
