<?php

namespace App\Models;

use App\Core\MYT_Model;

class Facility extends MYT_Model
{
    protected $table = 'facility';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'supplier_id',
        'name',
        'address',
    ];

    public function get_details($facility_id = null, $page = null, $data_size = null, $term = null, $supplier_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT facility.*,
    supplier.name AS supplier_name
FROM facility
LEFT JOIN {MASTER_DB}.supplier AS supplier ON supplier.id = facility.supplier_id AND supplier.is_deleted = 0
WHERE facility.is_deleted = 0
EOT;

        if ($facility_id) {
            $sql .= <<<EOT

AND facility.id = ?
EOT;
            $binds[] = $facility_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND facility.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($term !== null && $term !== '') {
            $sql .= <<<EOT

AND facility.name LIKE ?
EOT;
            $binds[] = '%' . $term . '%';
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
