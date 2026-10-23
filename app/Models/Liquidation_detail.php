<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Liquidation_detail extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'liquidation_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'account_type_id',
        'invoice_no',
        'expense_date',
        'amount',
        'description',
        'supplier_id',
        'tin',
        'address',
        'vat',
        'total',
        'is_vat'
    ];

    public function get_details($liquidation_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT liquidation_detail.id, 
    liquidation_detail.liquidation_id, 
    liquidation_detail.account_type_id,
    liquidation_detail.invoice_no,
    liquidation_detail.expense_date,
    liquidation_detail.amount,
    liquidation_detail.description,
    liquidation_detail.supplier_id,
    liquidation_detail.tin,
    liquidation_detail.address,
    liquidation_detail.vat,
    liquidation_detail.total,
    liquidation_detail.is_vat,
    account_type.name AS account_type_name
FROM liquidation_detail
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = liquidation_detail.account_type_id AND account_type.is_deleted = 0
WHERE liquidation_detail.is_deleted = 0
EOT;

        if ($liquidation_id) {
            $sql .= <<<EOT

AND liquidation_detail.liquidation_id = ?
EOT;
            $binds[] = $liquidation_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>