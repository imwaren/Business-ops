<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Lab_test extends MYT_Model
{
    protected $table = 'lab_test';
    protected $primaryKey = 'id';
    protected $allowedFields = ['branch_id', 'name', 'category_id', 'group_id', 'vaccine_type', 'price', 'hmo_price', 'is_consulta', 'is_hmo', 'is_post_service', 'pathologist_fee', 'consultation_fee', 'procedure_fee', 'reading_fee', 'clinic_based_cost', 'theoretical_cost', 'critical_value', 'rerun_value', 'remarks', 'is_sendout', 'gender'];

    /**
     * Build shared FROM/WHERE for get_details() and get_count().
     * GROUP BY / LIMIT are excluded so callers can append them.
     *
     * @param bool $for_count When true, select only lab_test.id
     */
    private function _build_lab_test_sql($lab_test_id, $is_consulta, $branch_id, $category_id, $parent_category_id, $group_id, $gender, $term, array &$binds, bool $for_count = false): string
    {
        $binds = [];

        if ($for_count) {
            $sql = <<<EOT
SELECT lab_test.id
FROM lab_test
LEFT JOIN category ON category.id = lab_test.category_id AND category.is_deleted = 0
LEFT JOIN category AS parent_category ON parent_category.id = category.category_id AND parent_category.is_deleted = 0
WHERE lab_test.is_deleted = 0
EOT;
        } else {
            $sql = <<<EOT
SELECT lab_test.id,
    lab_test.branch_id,
    lab_test.exam_id,
    lab_test.name, 
    lab_test.price, 
    lab_test.hmo_price, 
    lab_test.category_id, 
    parent_category.id AS parent_category_id,
    lab_test.group_id,
    lab_test.vaccine_type,
    category.name AS category_name, 
    parent_category.name AS parent_category_name, 
    lab_test.remarks, 
    lab_test.gender,
    lab_test.is_consulta,
    lab_test.is_hmo,
    lab_test.is_post_service,
    lab_test.pathologist_fee,
    lab_test.consultation_fee,
    lab_test.procedure_fee,
    lab_test.reading_fee,
    lab_test.theoretical_cost,
    lab_test.clinic_based_cost,
    lab_test.critical_value,
    lab_test.rerun_value,
    lab_test.is_sendout,
    GROUP_CONCAT(DISTINCT branch.name SEPARATOR ', ') AS branch_names
FROM lab_test
LEFT JOIN category ON category.id = lab_test.category_id AND category.is_deleted = 0
LEFT JOIN category AS parent_category ON parent_category.id = category.category_id AND parent_category.is_deleted = 0
LEFT JOIN branch ON FIND_IN_SET(branch.id, lab_test.branch_id) AND branch.is_deleted = 0
WHERE lab_test.is_deleted = 0
EOT;
        }

        if (!$this->_append_id_filter($sql, $binds, 'lab_test.id', $lab_test_id)) {
            return '';
        }

        if ($is_consulta !== null) {
            $sql .= "\nAND lab_test.is_consulta = ?";
            $binds[] = $is_consulta;
        }

        if ($branch_id) {
            $branchIds = explode(',', $branch_id);
            $conditions = [];
            foreach ($branchIds as $id) {
                $conditions[] = 'FIND_IN_SET(?, lab_test.branch_id)';
                $binds[] = trim($id);
            }
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")\n";
        }

        if ($category_id) {
            $sql .= "\nAND lab_test.category_id = ?";
            $binds[] = $category_id;
        }

        if ($parent_category_id) {
            $sql .= "\nAND parent_category.id = ?";
            $binds[] = $parent_category_id;
        }

        if ($group_id) {
            $sql .= "\nAND lab_test.group_id = ?";
            $binds[] = $group_id;
        }

        if ($gender) {
            $sql .= "\nAND lab_test.gender = ?";
            $binds[] = $gender;
        }

        if ($term) {
            $sql .= "\nAND lab_test.name LIKE ?";
            $binds[] = "%$term%";
        }

        return $sql;
    }

    public function get_details($lab_test_id = null, $is_consulta = null, $branch_id = null, $category_id = null, $parent_category_id = null, $group_id = null, $gender = null, $term = null, $page = null, $data_size = null) {
        if (is_array($lab_test_id) && !$lab_test_id) {
            return [];
        }
        $binds = [];
        $sql = $this->_build_lab_test_sql($lab_test_id, $is_consulta, $branch_id, $category_id, $parent_category_id, $group_id, $gender, $term, $binds, false);
        if ($sql === '') {
            return [];
        }

        $sql .= " GROUP BY lab_test.id ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_count($lab_test_id = null, $is_consulta = null, $branch_id = null, $category_id = null, $parent_category_id = null, $group_id = null, $gender = null, $term = null): int
    {
        if (is_array($lab_test_id) && !$lab_test_id) {
            return 0;
        }
        $binds = [];
        $base_sql = $this->_build_lab_test_sql($lab_test_id, $is_consulta, $branch_id, $category_id, $parent_category_id, $group_id, $gender, $term, $binds, true);
        if ($base_sql === '') {
            return 0;
        }
        // Distinct ids (branch FIND_IN_SET can duplicate rows without GROUP BY on count path)
        $sql = "SELECT COUNT(DISTINCT lab_test_count.id) AS total FROM ($base_sql) AS lab_test_count";

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    public function search_details($lab_test_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT lab_test.id,
    lab_test.name, 
    lab_test.price, 
    lab_test.theoretical_cost,
    lab_test.clinic_based_cost,
    lab_test.branch_id,
    lab_test.hmo_price, 
    lab_test.is_consulta,
    lab_test.is_hmo
FROM lab_test
WHERE lab_test.is_deleted = 0
EOT;

        if ($lab_test_id) {
            $sql .= <<<EOT

AND lab_test.id = ?
EOT;
            $binds[] = $lab_test_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND lab_test.name LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        $sql .= " GROUP BY lab_test.id ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function search_details_count($lab_test_id = null, $term = null) {
        $binds = [];
        $sql = <<<EOT
SELECT COUNT(lab_test.id) AS total
FROM lab_test
WHERE lab_test.is_deleted = 0
EOT;

        if ($lab_test_id) {
            $sql .= <<<EOT

AND lab_test.id = ?
EOT;
            $binds[] = $lab_test_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND lab_test.name LIKE ?
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
