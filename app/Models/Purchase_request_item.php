<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Purchase_request_item extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'purchase_request_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'account_type_id',
        'item_id',
        'qty',
        'unit',
        'discount',
        'amount',
        'remarks'
    ];

    public function get_details($purchase_request_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT purchase_request_item.id, 
    purchase_request_item.purchase_request_id, 
    purchase_request_item.account_type_id,
    purchase_request_item.item_id,
    purchase_request_item.qty,
    purchase_request_item.unit,
    purchase_request_item.discount,
    purchase_request_item.price,
    purchase_request_item.total,
    purchase_request_item.vat,
    purchase_request_item.vat_type,
    purchase_request_item.total_value,
    purchase_request_item.amount,
    purchase_request_item.remarks,
    account_type.name AS account_type_name
FROM purchase_request_item
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = purchase_request_item.account_type_id
WHERE purchase_request_item.is_deleted = 0
EOT;

        if ($purchase_request_id) {
            $sql .= <<<EOT

AND purchase_request_item.purchase_request_id = ?
EOT;
            $binds[] = $purchase_request_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>