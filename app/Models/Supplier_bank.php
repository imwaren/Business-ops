<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_bank extends MYT_Model
{
    protected $table = 'supplier_bank';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'bank_branch',
        'account_name',
        'account_no'
    ];

    public function get_details($supplier_bank_id = null, $supplier_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier_bank.*
FROM supplier_bank
WHERE supplier_bank.is_deleted = 0
EOT;

        if ($supplier_bank_id) {
            $sql .= <<<EOT

AND supplier_bank.id = ?
EOT;
            $binds[] = $supplier_bank_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier_bank.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>