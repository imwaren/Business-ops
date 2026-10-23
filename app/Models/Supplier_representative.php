<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_representative extends MYT_Model
{
    protected $table = 'supplier_representative';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'supplier_id',
        'transaction_name',
        'employee',
        'position',
    ];

    public function get_details($supplier_representative_id = null, $supplier_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier_representative.*
FROM supplier_representative
WHERE supplier_representative.is_deleted = 0
EOT;

        if ($supplier_representative_id) {
            $sql .= <<<EOT

AND supplier_representative.id = ?
EOT;
            $binds[] = $supplier_representative_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier_representative.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>