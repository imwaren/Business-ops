<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Voucher_holiday extends MYT_Model
{
    protected $table = 'voucher_holiday';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'voucher_id',
        'date',
    ];

    public function get_holidays($voucher_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT voucher_holiday.id, 
    voucher_holiday.voucher_id, 
    voucher_holiday.date
FROM voucher_holiday
WHERE voucher_holiday.is_deleted = 0
EOT;

        if ($voucher_id) {
            $sql .= <<<EOT

AND voucher_holiday.voucher_id = ?
EOT;
            $binds[] = $voucher_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>