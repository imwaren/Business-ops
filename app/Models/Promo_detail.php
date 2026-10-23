<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Promo_detail extends MYT_Model
{
    protected $table = 'promo_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'promo_id',
        'lab_test_id',
    ];

    public function get_details($promo_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT promo_detail.id, 
    promo_detail.promo_id, 
    promo_detail.lab_test_id, 
    lab_test.name AS lab_test_name
FROM promo_detail
LEFT JOIN lab_test ON lab_test.id = promo_detail.lab_test_id AND lab_test.is_deleted = 0
WHERE promo_detail.is_deleted = 0
EOT;

        if ($promo_id) {
            $sql .= <<<EOT

AND promo_detail.promo_id = ?
EOT;
            $binds[] = $promo_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>