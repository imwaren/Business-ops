<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_reference extends MYT_Model
{
    protected $table = 'supplier_reference';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'supplier_id',
        'name',
        'address',
        'contact',
    ];

    public function get_details($supplier_reference_id = null, $supplier_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier_reference.*
FROM supplier_reference
WHERE supplier_reference.is_deleted = 0
EOT;

        if ($supplier_reference_id) {
            $sql .= <<<EOT

AND supplier_reference.id = ?
EOT;
            $binds[] = $supplier_reference_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier_reference.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>