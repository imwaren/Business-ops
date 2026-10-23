<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Package extends MYT_Model
{
    protected $table = 'package';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_id',
        'name',
        'price',
        'type',
        'package_status',
        'package_type',
        'remarks',
        'total_theoretical_cost',
        'clinic_based_cost_total',
        'retail_price_total',
        'pro_rate_price_total',
        'discount_total',
        'margin_total',
        'sales_total',
        'estimated_revenue_total',
        'direct_cost_total',
        'estimated_margin_total',
        'reading_fee_total',
        'procedure_fee_total',
        'consultation_fee_total',
        'pathologist_fee_total',
    ];

    /**
     * Build shared FROM/WHERE for get_details() and get_count().
     * GROUP BY / LIMIT are excluded so callers can append them.
     *
     * @param bool $for_count When true, select only package.id
     */
    private function _build_package_sql($package_id, $branch_id, $package_status, $type, $term, array &$binds, bool $for_count = false): string
    {
        $binds = [];

        if ($for_count) {
            $sql = <<<EOT
SELECT package.id
FROM package
WHERE package.is_deleted = 0
EOT;
        } else {
            $sql = <<<EOT
SELECT package.*,
    GROUP_CONCAT(DISTINCT branch.name SEPARATOR ', ') AS branch_names
FROM package
LEFT JOIN branch ON FIND_IN_SET(branch.id, package.branch_id) AND branch.is_deleted = 0
WHERE package.is_deleted = 0
EOT;
        }

        if (!$this->_append_id_filter($sql, $binds, 'package.id', $package_id)) {
            return '';
        }

        if ($branch_id) {
            $branchIds = explode(',', $branch_id);
            $conditions = [];
            foreach ($branchIds as $id) {
                $conditions[] = 'FIND_IN_SET(?, package.branch_id)';
                $binds[] = trim($id);
            }
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")\n";
        }

        if ($package_status) {
            $sql .= "\nAND package.package_status = ?";
            $binds[] = $package_status;
        }

        // $type is accepted for API compatibility but historically unused in SQL

        if ($term) {
            $sql .= "\nAND package.name LIKE ?";
            $binds[] = "%$term%";
        }

        return $sql;
    }

    public function get_details($package_id = null, $branch_id = null, $package_status = null, $type = null, $term = null, $page = null, $data_size = null) {
        if (is_array($package_id) && !$package_id) {
            return [];
        }
        $binds = [];
        $sql = $this->_build_package_sql($package_id, $branch_id, $package_status, $type, $term, $binds, false);
        if ($sql === '') {
            return [];
        }

        $sql .= " GROUP BY package.id ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_count($package_id = null, $branch_id = null, $package_status = null, $type = null, $term = null): int
    {
        if (is_array($package_id) && !$package_id) {
            return 0;
        }
        $binds = [];
        $base_sql = $this->_build_package_sql($package_id, $branch_id, $package_status, $type, $term, $binds, true);
        if ($base_sql === '') {
            return 0;
        }
        $sql = "SELECT COUNT(DISTINCT package_count.id) AS total FROM ($base_sql) AS package_count";

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    public function search_details($package_id = null, $package_type = null, $package_status = null, $type = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT package.id,
    package.name,
    package.retail_price_total,
    package.clinic_based_cost_total,
    package.branch_id,
    package.package_type,
    package.price,
    package.pro_rate_price_total,
    package.total_theoretical_cost,
    (
        SELECT IFNULL(
            CONCAT('[', GROUP_CONCAT(JSON_OBJECT(
                'id', package_detail.id,
                'package_id', package_detail.package_id,
                'lab_test_id', package_detail.lab_test_id,
                'lab_test_name', lab_test.name,
                'lab_test_price', lab_test.price,
                'clinic_based_cost', package_detail.clinic_based_cost,
                'theoretical_cost', package_detail.theoretical_cost
            ) SEPARATOR ','), ']'),
            '[]'
        )
        FROM package_detail
        LEFT JOIN lab_test ON lab_test.id = package_detail.lab_test_id
        WHERE package_detail.package_id = package.id
          AND package_detail.is_deleted = 0
          AND lab_test.is_deleted = 0
    ) AS details
FROM package
WHERE package.is_deleted = 0
EOT;

        if ($package_id) {
            $sql .= <<<EOT

AND package.id = ? 
EOT;
            $binds[] = $package_id;
        }

        if ($package_type) {
            $sql .= <<<EOT

AND package.package_type = ?
EOT;
            $binds[] = $package_type;
        }

        if ($package_status) {
            $sql .= <<<EOT

AND package.package_status = ?
EOT;
            $binds[] = $package_status;
        }

        if ($type) {
            $sql .= <<<EOT

AND package.type = ?
EOT;
            $binds[] = $type;
        }

        if ($term) {
            $sql .= <<<EOT

AND package.name LIKE ? 
EOT;
            $binds[] = "%$term%";
        }

        $sql .= " GROUP BY package.id ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        if (!$query) return false;

        $rows = $query->getResultArray();
        foreach ($rows as &$row) {
            $row['details']     = json_decode($row['details'] ?? '[]', true) ?? [];
        }
        return $rows;
    }

    public function search_details_count($package_id = null, $package_type = null, $package_status = null, $type = null, $term = null) {
        $binds = [];
        $sql = <<<EOT
SELECT COUNT(package.id) AS total
FROM package
WHERE package.is_deleted = 0
EOT;

        if ($package_id) {
            $sql .= <<<EOT

AND package.id = ? 
EOT;
            $binds[] = $package_id;
        }

        if ($package_type) {
            $sql .= <<<EOT

AND package.package_type = ?
EOT;
            $binds[] = $package_type;
        }

        if ($package_status) {
            $sql .= <<<EOT

AND package.package_status = ?
EOT;
            $binds[] = $package_status;
        }

        if ($type) {
            $sql .= <<<EOT

AND package.type = ?
EOT;
            $binds[] = $type;
        }

        if ($term) {
            $sql .= <<<EOT

AND package.name LIKE ? 
EOT;
            $binds[] = "%$term%";
        }

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }
}

?>
