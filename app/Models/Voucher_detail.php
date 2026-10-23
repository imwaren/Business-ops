<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Voucher_detail extends MYT_Model
{
    protected $table = 'voucher_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'voucher_id',
        'lab_test_id',
    ];

    public function get_details($voucher_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT voucher_detail.id, 
    voucher_detail.voucher_id, 
    voucher_detail.lab_test_id, 
    lab_test.name AS lab_test_name
FROM voucher_detail
LEFT JOIN lab_test ON lab_test.id = voucher_detail.lab_test_id AND lab_test.is_deleted = 0
WHERE voucher_detail.is_deleted = 0
EOT;

        if ($voucher_id) {
            $sql .= <<<EOT

AND voucher_detail.voucher_id = ?
EOT;
            $binds[] = $voucher_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>