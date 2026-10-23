<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Asset_lab_test_machine extends MYT_Model
{
    protected $table = 'asset_lab_test_machine';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'asset_lab_test_id',
        'asset_id',
        'lab_test_id',
        'item_id',
        'qty',
        'unit',
    ];

    public function get_details($asset_lab_test_id = null, $asset_id = null, $lab_test_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT asset_lab_test_machine.*, 
    lab_test.name AS lab_test_name,
    item.name AS item_name,
    item_conversion.unit AS unit_name,
    item_conversion.conversion_type AS unit_conversion
FROM asset_lab_test_machine
LEFT JOIN lab_test ON asset_lab_test_machine.lab_test_id = lab_test.id
LEFT JOIN item ON asset_lab_test_machine.item_id = item.id
LEFT JOIN item_conversion ON asset_lab_test_machine.unit = item_conversion.id
WHERE asset_lab_test_machine.is_deleted = 0
EOT;

        if ($asset_lab_test_id) {
            $sql .= <<<EOT

AND asset_lab_test_machine.asset_lab_test_id = ?
EOT;
            $binds[] = $asset_lab_test_id;
        }

        if ($asset_id) {
            $sql .= <<<EOT

AND asset_lab_test_machine.asset_id = ?
EOT;
            $binds[] = $asset_id;
        }

        if ($lab_test_id) {
            $sql .= <<<EOT

AND asset_lab_test_machine.lab_test_id = ?
EOT;
            $binds[] = $lab_test_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>