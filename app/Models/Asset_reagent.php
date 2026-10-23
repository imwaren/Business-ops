<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Asset_reagent extends MYT_Model
{
    protected $table = 'asset_reagent';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'asset_id',
        'item_id',
    ];

    public function get_details($asset_id = null, $item_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT asset_reagent.*,
    item.name AS item_name
FROM asset_reagent
LEFT JOIN item ON asset_reagent.item_id = item.id
WHERE asset_reagent.is_deleted = 0
EOT;

        if ($asset_id) {
            $sql .= <<<EOT

AND asset_reagent.asset_id = ?
EOT;
            $binds[] = $asset_id;
        }

        if ($item_id) {
            $sql .= <<<EOT

AND asset_reagent.item_id = ?
EOT;
            $binds[] = $item_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
