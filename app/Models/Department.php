<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Department extends MYT_Model
{
    protected $table = 'department';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
    ];

    public function get_details($department_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT department.*
FROM department
WHERE department.is_deleted = 0
EOT;

        if ($department_id) {
            $sql .= <<<EOT

AND department.id = ?
EOT;
            $binds[] = $department_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>