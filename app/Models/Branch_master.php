<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Branch_master extends MYT_Model
{
    protected $table = 'branch';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'code',
        'phic_accred_no',
    ];

    public function get_details($branch_id = null, $type = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT branch.*
FROM branch
WHERE branch.is_deleted = 0
EOT;

        if ($branch_id) {
            $sql .= <<<EOT

AND branch.id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($type) {
            // Convert comma separated string into array
            $types = array_map('trim', explode(',', $type));
        
            // Create placeholders (?, ?, ?)
            $placeholders = implode(',', array_fill(0, count($types), '?'));
        
            $sql .= <<<EOT
        
AND branch.type IN ($placeholders)
EOT;
            // Add each type to bind array
            foreach ($types as $t) {
                $binds[] = $t;
            }
        }

        if ($term) {
            $sql .= <<<EOT

AND (branch.name LIKE ? OR branch.code LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Batch-load branch names by id. Returns [id => name].
     */
    public function get_names_by_ids($branch_ids = null): array
    {
        if (is_string($branch_ids)) {
            $branch_ids = explode(',', $branch_ids);
        }
        if (!is_array($branch_ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter($branch_ids, static function ($v) {
            return $v !== null && $v !== '' && $v !== 'all';
        })));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT branch.id, branch.name
FROM branch
WHERE branch.is_deleted = 0
AND branch.id IN ($placeholders)
EOT;
        $query = $this->db->query($sql, $ids);
        if (!$query) {
            return [];
        }
        $map = [];
        foreach ($query->getResultArray() as $row) {
            $map[$row['id']] = $row['name'];
        }
        return $map;
    }
}

?>