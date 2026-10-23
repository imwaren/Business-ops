<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Voucher extends MYT_Model
{
    protected $table = 'voucher';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'description',
        'voucher_code_from',
        'voucher_code_to',
        'voucher_type',
        'valid_from',
        'valid_to',
        'amount',
        'percentage',
        'price',
        'minimum_purchase',
        'max_purchase',
        'time_from',
        'time_to',
        'days_of_week',
        'is_all',
        'is_any_service',
        'no_min',
        'no_max',
        'no_limit',
        'any_day',
        'not_time_bound',
    ];

    public function get_details($voucher_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT voucher.*
FROM voucher
WHERE voucher.is_deleted = 0
EOT;

        if ($voucher_id) {
            $sql .= <<<EOT

AND voucher.id = ?
EOT;
            $binds[] = $voucher_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND voucher.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (voucher.name LIKE ? OR voucher.description LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function update_validity_date_range_status() 
    {
        $sql = <<<EOT
UPDATE voucher
SET status = 'expired'
WHERE is_deleted = 0
AND status = 'active'
AND CURDATE() > valid_to  
EOT;
        return $this->db->query($sql);
    }
}

?>