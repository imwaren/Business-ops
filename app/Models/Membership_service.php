<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Membership_service extends MYT_Model
{
    protected $table = 'membership_service';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'membership_id',
        'lab_test_id',
        'frequency',
        'is_free',
    ];

    public function get_services($membership_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT membership_service.id, 
    membership_service.membership_id, 
    membership_service.lab_test_id, 
    membership_service.frequency,
    membership_service.is_free,
    lab_test.name AS lab_test_name
FROM membership_service
LEFT JOIN lab_test ON lab_test.id = membership_service.lab_test_id AND lab_test.is_deleted = 0
WHERE membership_service.is_deleted = 0
EOT;

        if ($membership_id) {
            $sql .= <<<EOT

AND membership_service.membership_id = ?
EOT;
            $binds[] = $membership_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>