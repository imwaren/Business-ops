<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_service extends MYT_Model
{
    protected $table = 'supplier_service';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'services_offered',
    ];

    public function get_details($supplier_service_id = null, $supplier_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier_service.*
FROM supplier_service
WHERE supplier_service.is_deleted = 0
EOT;

        if ($supplier_service_id) {
            $sql .= <<<EOT

AND supplier_service.id = ?
EOT;
            $binds[] = $supplier_service_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier_service.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>