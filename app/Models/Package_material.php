<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Package_material extends MYT_Model
{
    protected $table = 'package_material';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'package_id',
        'branch_id',
        'item_id',
        'qty',
        'unit'
    ];

    public function get_materials($package_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT package_material.id, 
    package_material.package_id,
    package_material.branch_id, 
    package_material.qty,
    package_material.unit,
    package_material.item_id, 
    item.name AS item_name
FROM package_material
LEFT JOIN package ON package.id = package_material.package_id AND package.is_deleted = 0
LEFT JOIN item ON item.id = package_material.item_id AND item.is_deleted = 0
WHERE package_material.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'package_material.package_id', $package_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>