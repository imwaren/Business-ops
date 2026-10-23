<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Asset_lab_test extends MYT_Model
{
    protected $table = 'asset_lab_test';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'asset_id',
        'lab_test_id',
    ];

    public function get_details($asset_id = null, $lab_test_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT asset_lab_test.*,
    lab_test.name AS lab_test_name
FROM asset_lab_test
LEFT JOIN lab_test ON asset_lab_test.lab_test_id = lab_test.id
WHERE asset_lab_test.is_deleted = 0
EOT;

        if ($asset_id) {
            $sql .= <<<EOT

AND asset_lab_test.asset_id = ?
EOT;
            $binds[] = $asset_id;
        }

        if ($lab_test_id) {
            $sql .= <<<EOT

AND asset_lab_test.lab_test_id = ?
EOT;
            $binds[] = $lab_test_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>