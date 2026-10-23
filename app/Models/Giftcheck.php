<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Giftcheck extends MYT_Model
{
    protected $table = 'giftcheck';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'description',
        'giftcheck_code',
        'valid_from',
        'valid_to',
        'amount',
        'is_all',
        'is_any_service'
    ];

    public function get_details($giftcheck_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT giftcheck.*
FROM giftcheck
WHERE giftcheck.is_deleted = 0
EOT;

        if ($giftcheck_id) {
            $sql .= <<<EOT

AND giftcheck.id = ?
EOT;
            $binds[] = $giftcheck_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND giftcheck.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (giftcheck.description LIKE ? OR giftcheck.giftcheck_code LIKE ?)
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
UPDATE giftcheck
SET status = 'expired'
WHERE is_deleted = 0
AND status = 'active'
AND CURDATE() > valid_to  
EOT;
        return $this->db->query($sql);
    }
}

?>