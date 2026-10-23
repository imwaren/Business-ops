<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Medicine_stock_in extends MYT_Model
{
    protected $table = 'medicine_stock_in';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'medicine_id',
        'qty',
        'unit',
        'remarks',
    ];

    public function get_details($medicine_stock_in_id = null, $medicine_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT medicine_stock_in.id,
    medicine_stock_in.medicine_id,
    medicine_stock_in.qty,
    medicine_stock_in.unit,
    medicine_stock_in.remarks,
    medicine.name AS medicine_name,
    medicine.brand AS medicine_brand,
    medicine.generic AS medicine_generic,
    medicine.dosage AS medicine_dosage,
    medicine.form AS medicine_form
FROM medicine_stock_in
LEFT JOIN medicine ON medicine.id = medicine_stock_in.medicine_id AND medicine.is_deleted = 0
WHERE medicine_stock_in.is_deleted = 0
EOT;

        if ($medicine_stock_in_id) {
            $sql .= <<<EOT

AND medicine_stock_in.id = ?
EOT;
            $binds[] = $medicine_stock_in_id;
        }

        if ($medicine_id) {
            $sql .= <<<EOT

AND medicine_stock_in.medicine_id = ?
EOT;
            $binds[] = $medicine_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND medicine.name LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>