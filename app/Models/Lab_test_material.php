<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Lab_test_material extends MYT_Model
{
    protected $table = 'lab_test_material';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'lab_test_id',
        'branch_id',
        'item_id',
        'qty',
        'unit',
        'redo_for_payment'
    ];

    public function get_materials($lab_test_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT lab_test_material.id, 
    lab_test_material.lab_test_id,
    lab_test_material.branch_id, 
    lab_test_material.qty,
    lab_test_material.unit,
    lab_test_material.redo_for_payment,
    lab_test_material.item_id, 
    item.name AS item_name
FROM lab_test_material
LEFT JOIN lab_test ON lab_test.id = lab_test_material.lab_test_id AND lab_test.is_deleted = 0
LEFT JOIN item ON item.id = lab_test_material.item_id AND item.is_deleted = 0
WHERE lab_test_material.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'lab_test_material.lab_test_id', $lab_test_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>