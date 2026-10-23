<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Aoq_detail extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'aoq_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'aoq_id',
        'supplier_id',
        'name',
        'cost'
    ];

    public function get_details($aoq_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT aoq_detail.id, 
    aoq_detail.aoq_id, 
    aoq_detail.supplier_id,
    aoq_detail.name, 
    aoq_detail.cost
FROM aoq_detail
WHERE aoq_detail.is_deleted = 0
EOT;

        if ($aoq_id) {
            $sql .= <<<EOT

AND aoq_detail.aoq_id = ?
EOT;
            $binds[] = $aoq_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>