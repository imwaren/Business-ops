<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Capital_expenditure extends MYT_Model
{
    protected $table = 'capital_expenditure';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'contract_date',
        'start_date',
        'end_date',
        'address',
        'company',
        'contact_person',
        'contact_number',
        'cost_estimate_amount',
        'internal_budget',
        'percentage',
        'cost_center_id',
        'investment_type',
    ];

    public function get_details($capital_expenditure_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT capital_expenditure.*
FROM capital_expenditure
WHERE capital_expenditure.is_deleted = 0
EOT;

        if ($capital_expenditure_id) {
            $sql .= <<<EOT

AND capital_expenditure.id = ?
EOT;
            $binds[] = $capital_expenditure_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND capital_expenditure.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND capital_expenditure.name LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>