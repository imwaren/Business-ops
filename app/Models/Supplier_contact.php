<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_contact extends MYT_Model
{
    protected $table = 'supplier_contact';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'contact_person',
        'contact_no',
        'email',
    ];

    public function get_details($supplier_contact_id = null, $supplier_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier_contact.*
FROM supplier_contact
WHERE supplier_contact.is_deleted = 0
EOT;

        if ($supplier_contact_id) {
            $sql .= <<<EOT

AND supplier_contact.id = ?
EOT;
            $binds[] = $supplier_contact_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier_contact.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>