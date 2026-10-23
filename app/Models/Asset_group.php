<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Asset_group extends MYT_Model
{
    protected $table = 'asset_group';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
    ];

    public function get_details($asset_group_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT asset_group.*
FROM asset_group
WHERE asset_group.is_deleted = 0
EOT;

        if ($asset_group_id) {
            $sql .= <<<EOT

AND asset_group.id = ?
EOT;
            $binds[] = $asset_group_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND asset_group.name LIKE ? 
EOT;
            $like_term = '%' . $term . '%';
            $binds[] = $like_term;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>