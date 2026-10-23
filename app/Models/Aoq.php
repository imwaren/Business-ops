<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Aoq extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'aoq';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'purchase_request_id',
        'supplier_id',
        'branch_id',
        'cost_center_id',
        'recommended_bidder_id',
        'recommended_by_id',
        'justification',
        'type',
        'needed_date',
        'project_name',
        'subtotal',
        'discount',
        'grand_total',
        'remarks',
    ];

    public function get_details($aoq_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT aoq.*
FROM aoq
WHERE aoq.is_deleted = 0
EOT;

        if ($aoq_id) {
            $sql .= <<<EOT

AND aoq.id = ?
EOT;
            $binds[] = $aoq_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND aoq.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (aoq.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY aoq.needed_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>