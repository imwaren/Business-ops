<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Other_supplier extends MYT_Model
{
    protected $table = 'other_supplier';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'tin_no',
        'address',
    ];

    public function get_details($supplier_id = null, $tin_no = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT other_supplier.*
FROM other_supplier
WHERE other_supplier.is_deleted = 0
EOT;

        if ($supplier_id) {
            $sql .= <<<EOT

AND other_supplier.id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($tin_no) {
            $sql .= <<<EOT

AND other_supplier.tin_no = ?
EOT;
            $binds[] = $tin_no;
        }

        if ($term) {
            $sql .= <<<EOT

AND (other_supplier.name LIKE ?)
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>