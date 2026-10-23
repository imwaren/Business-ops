<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Item_conversion extends MYT_Model
{
    protected $table = 'item_conversion';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'item_id',
        'conversion_type',
        'price',
        'qty',
        'unit',
        'min',
        'max',
    ];

    public function get_details($item_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT item_conversion.id,
    item_conversion.item_id,
    item_conversion.conversion_type,
    item_conversion.price,
    item_conversion.qty,
    item_conversion.unit,
    item_conversion.min,
    item_conversion.max
FROM item_conversion
WHERE item_conversion.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'item_conversion.item_id', $item_id)) {
            return [];
        }

        // Group results per item_id, with conversion order po → clinic → usage
        $sql .= <<<EOT

ORDER BY item_conversion.item_id, 
        FIELD(item_conversion.conversion_type, 'purchase', 'clinic', 'usage')
EOT;

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>