<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Purchaser extends MYT_Model
{
    protected $table = 'purchaser';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'contact_no',
    ];

    public function get_details($purchaser_id = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT purchaser.*
FROM purchaser
WHERE purchaser.is_deleted = 0
EOT;

        if ($purchaser_id) {
            $sql .= <<<EOT
AND purchaser.id = ?
EOT;
            $binds[] = $purchaser_id;
        }

        if ($term) {
            $sql .= <<<EOT

AND (purchaser.name LIKE ?)
EOT;
            $binds[] = '%' . $term . '%';
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>