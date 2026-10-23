<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Promo_branch extends MYT_Model
{
    protected $table = 'promo_branch';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'promo_id',
        'branch_id'
    ];

    public function get_branches($promo_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT promo_branch.id, 
    promo_branch.promo_id,
    promo_branch.branch_id
FROM promo_branch
WHERE promo_branch.is_deleted = 0
EOT;

        if ($promo_id) {
            $sql .= <<<EOT

AND promo_branch.promo_id = ?
EOT;
            $binds[] = $promo_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>