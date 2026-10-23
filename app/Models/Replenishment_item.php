<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Replenishment_item extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'replenishment_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'account_type_id',
        'supplier_id',
        'invoice_no',
        'tin',
        'address',
        'description',
        'expense_date',
        'amount',
        'vat',
        'total_value',
        'total',
        'remarks',
        'is_vat'
    ];

    public function get_details($replenishment_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT replenishment_item.id, 
    replenishment_item.replenishment_id, 
    replenishment_item.account_type_id, 
    replenishment_item.supplier_id, 
    replenishment_item.invoice_no,
    replenishment_item.tin,
    replenishment_item.address,
    replenishment_item.description,
    replenishment_item.expense_date,
    replenishment_item.amount,
    replenishment_item.vat,
    replenishment_item.total_value,
    replenishment_item.total,
    replenishment_item.remarks,
    replenishment_item.is_vat,
    account_type.name AS account_type_name
FROM replenishment_item
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = replenishment_item.account_type_id AND account_type.is_deleted = 0
WHERE replenishment_item.is_deleted = 0
EOT;

        if ($replenishment_id) {
            $sql .= <<<EOT

AND replenishment_item.replenishment_id = ?
EOT;
            $binds[] = $replenishment_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>