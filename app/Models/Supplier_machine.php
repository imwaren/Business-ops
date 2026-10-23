<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_machine extends MYT_Model
{
    protected $table = 'supplier_machine';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'supplier_id',
        'type',
        'machinery',
        'description',
    ];

    public function get_details($supplier_machine_id = null, $supplier_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier_machine.*
FROM supplier_machine
WHERE supplier_machine.is_deleted = 0
EOT;

        if ($supplier_machine_id) {
            $sql .= <<<EOT

AND supplier_machine.id = ?
EOT;
            $binds[] = $supplier_machine_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier_machine.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>