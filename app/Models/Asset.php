<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Asset extends MYT_Model
{
    protected $table = 'asset';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'parent_id',
        'asset_group_id',
        'group_id',
        'cost_center_id',
        'item_id',
        'name',
        'useful_life_years',
        'end_user_location',
        'cost',
        'as_of',
        'supplier_id',
        'each_period',
        'accumulated',
        'net_value',
        'consumption_unit',
    ];

    public function get_details($asset_id = null, $asset_group_id = null, $cost_center_id = null, $supplier_id = null, $term = null, $item_id = null, $page = null, $data_size = null, $parent_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT asset.*,
    asset_group.name AS asset_group_name
FROM asset
LEFT JOIN asset_group ON asset.asset_group_id = asset_group.id
WHERE asset.is_deleted = 0
EOT;

        if ($asset_id) {
            $sql .= <<<EOT

AND asset.id = ?
EOT;
            $binds[] = $asset_id;
        }

        if ($parent_id !== null) {
            if ((string) $parent_id === '0') {
                $sql .= "\nAND asset.parent_id IS NULL";
            } else {
                $sql .= "\nAND asset.parent_id = ?";
                $binds[] = $parent_id;
            }
        }

        if ($asset_group_id) {
            $sql .= <<<EOT

AND asset.asset_group_id = ?
EOT;
            $binds[] = $asset_group_id;
        }

        if ($cost_center_id) {
            $sql .= <<<EOT

AND asset.cost_center_id = ?
EOT;
            $binds[] = $cost_center_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND asset.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND asset.name LIKE ? 
EOT;
            $like_term = '%' . $term . '%';
            $binds[] = $like_term;
        }

        if ($item_id) {
            $sql .= <<<EOT

AND (
  asset.item_id = ?
  OR EXISTS (
    SELECT 1 FROM asset_reagent
    WHERE asset_reagent.asset_id = asset.id
      AND asset_reagent.item_id = ?
      AND asset_reagent.is_deleted = 0
  )
)
EOT;
            $binds[] = $item_id;
            $binds[] = $item_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
