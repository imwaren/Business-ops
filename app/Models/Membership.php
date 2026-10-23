<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Membership extends MYT_Model
{
    protected $table = 'membership';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'price',
        'consultation',
        'vaccine',
        'meds',
        'diagnostics',
        // 'other_health_services',
        'days_duration',
        'vaccine_discount',
    ];

    public function get_details($membership_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT membership.*
FROM membership
WHERE membership.is_deleted = 0
EOT;

        if ($membership_id) {
            $sql .= <<<EOT

AND membership.id = ?
EOT;
            $binds[] = $membership_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND membership.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (membership.name LIKE ?)
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>