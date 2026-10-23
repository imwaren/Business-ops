<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Promo extends MYT_Model
{
    protected $table = 'promo';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'description',
        'code',
        'type',
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
        'redemption_value',
        'no_min',
        'no_max',
        'no_limit',
        'any_day',
        'not_time_bound',
    ];

    public function get_details($promo_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT promo.*
FROM promo
WHERE promo.is_deleted = 0
EOT;

        if ($promo_id) {
            $sql .= <<<EOT

AND promo.id = ?
EOT;
            $binds[] = $promo_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND promo.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (promo.description LIKE ? OR promo.code LIKE ?)
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
UPDATE promo
SET status = 'expired'
WHERE is_deleted = 0
AND status = 'active'
AND CURDATE() > valid_to  
EOT;
        return $this->db->query($sql);
    }
}

?>