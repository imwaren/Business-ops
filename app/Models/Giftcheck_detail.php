<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Giftcheck_detail extends MYT_Model
{
    protected $table = 'giftcheck_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'giftcheck_id',
        'lab_test_id',
    ];

    public function get_details($giftcheck_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT giftcheck_detail.id, 
    giftcheck_detail.giftcheck_id, 
    giftcheck_detail.lab_test_id, 
    lab_test.name AS lab_test_name
FROM giftcheck_detail
LEFT JOIN lab_test ON lab_test.id = giftcheck_detail.lab_test_id AND lab_test.is_deleted = 0
WHERE giftcheck_detail.is_deleted = 0
EOT;

        if ($giftcheck_id) {
            $sql .= <<<EOT

AND giftcheck_detail.giftcheck_id = ?
EOT;
            $binds[] = $giftcheck_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>