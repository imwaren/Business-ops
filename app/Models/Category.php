<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Category extends MYT_Model
{
    protected $table = 'category';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'category_id',
        'group_id',
        'name',
        'remarks',
    ];

    public function get_details($category_id = null, $parent_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT category.id, category.category_id, category.group_id, category.name, category.remarks, `group`.name AS group_name
FROM category
LEFT JOIN {MASTER_DB}.`group` AS `group` ON `group`.id = category.group_id
WHERE category.is_deleted = 0
EOT;

        if ($category_id) {
            $sql .= <<<EOT

AND category.id = ?
EOT;
            $binds[] = $category_id;
        }

        if ($parent_id) {
            $sql .= <<<EOT

AND category.category_id = ?
EOT;
            $binds[] = $parent_id;
        } else {
            $sql .= <<<EOT

AND category.category_id IS NULL
EOT;
        }

        if ($term) {
            $sql .= <<<EOT

AND category.name LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>