<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Promo_holiday extends MYT_Model
{
    protected $table = 'promo_holiday';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'promo_id',
        'date',
    ];

    public function get_holidays($promo_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT promo_holiday.id, 
    promo_holiday.promo_id, 
    promo_holiday.date
FROM promo_holiday
WHERE promo_holiday.is_deleted = 0
EOT;

        if ($promo_id) {
            $sql .= <<<EOT

AND promo_holiday.promo_id = ?
EOT;
            $binds[] = $promo_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>