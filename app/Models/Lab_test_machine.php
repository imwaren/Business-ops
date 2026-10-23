<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Lab_test_machine extends MYT_Model
{
    protected $table = 'lab_test_machine';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'lab_test_id',
        'branch_id',
        'item_id',
        'asset_id',
        'qty',
        'unit'
    ];

    public function get_machines($lab_test_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT lab_test_machine.id, 
    lab_test_machine.lab_test_id,
    lab_test_machine.branch_id, 
    lab_test_machine.qty,
    lab_test_machine.unit,
    lab_test_machine.item_id,
    lab_test_machine.asset_id,
    item.name AS item_name,
    asset.name AS asset_name
FROM lab_test_machine
LEFT JOIN lab_test ON lab_test.id = lab_test_machine.lab_test_id AND lab_test.is_deleted = 0
LEFT JOIN item ON item.id = lab_test_machine.item_id AND item.is_deleted = 0
LEFT JOIN asset ON asset.id = lab_test_machine.asset_id AND asset.is_deleted = 0
WHERE lab_test_machine.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'lab_test_machine.lab_test_id', $lab_test_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>