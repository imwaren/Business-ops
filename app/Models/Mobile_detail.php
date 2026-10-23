<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Mobile_detail extends MYT_Model
{
    protected $table = 'mobile_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'type',
        'description',
        'amount',
        'estimated_patient_per_hour',
        'minutes_per_pax',
        'active_hours_deduction',
    ];

    public function get_details($mobile_detail_id = null, $type = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT mobile_detail.*
FROM mobile_detail
WHERE mobile_detail.is_deleted = 0
EOT;

        if ($mobile_detail_id) {
            $sql .= <<<EOT
AND mobile_detail.id = ?
EOT;
            $binds[] = $mobile_detail_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND mobile_detail.type = ?
EOT;
            $binds[] = $type;
        }

        if ($term) {
            $sql .= <<<EOT

AND (mobile_detail.description LIKE ?)
EOT;
            $binds[] = '%' . $term . '%';
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>