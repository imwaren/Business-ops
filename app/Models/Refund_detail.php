<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Refund_detail extends MYT_Model
{
    protected $table = 'refund_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'refund_id',
        'source_id',
        'table_from',
        'item_name',
        'amount',
    ];

    public function get_details($refund_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT refund_detail.*
FROM refund_detail
WHERE refund_detail.is_deleted = 0
EOT;

        if ($refund_id) {
            $sql .= <<<EOT

AND refund_detail.refund_id = ?
EOT;
            $binds[] = $refund_id;
        }

        $sql .= ' ORDER BY refund_detail.id ASC';

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }
}
