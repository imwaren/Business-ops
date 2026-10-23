<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Mobile_van extends MYT_Model
{
    protected $table = 'mobile_van';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'branch_id',
        'worklist_path',
    ];

    public function get_details($mobile_van_id = null, $branch_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT mobile_van.*
FROM mobile_van
WHERE mobile_van.is_deleted = 0
EOT;

        if ($mobile_van_id) {
            $sql .= <<<EOT

AND mobile_van.id = ?
EOT;
            $binds[] = $mobile_van_id;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND mobile_van.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND mobile_van.name LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>