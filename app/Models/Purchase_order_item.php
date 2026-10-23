<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Purchase_order_item extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'purchase_order_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'account_type_id',
        'cost_center_id',
        'expenditure_type',
        'item_id',
        'qty',
        'unit',
        'cost',
        'discount',
        'amount'
    ];

    public function get_details($purchase_order_id = null, $item_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT purchase_order_item.id, 
    purchase_order_item.purchase_order_id, 
    purchase_order_item.account_type_id, 
    purchase_order_item.cost_center_id, 
    purchase_order_item.expenditure_type, 
    purchase_order_item.item_id,
    purchase_order_item.qty AS ordered_qty,
    purchase_order_item.unit,
    purchase_order_item.cost,
    purchase_order_item.discount,
    purchase_order_item.price,
    purchase_order_item.total,
    purchase_order_item.vat,
    purchase_order_item.vat_type,
    purchase_order_item.total_value,
    purchase_order_item.amount,
    purchase_order_item.remarks,
    account_type.name AS account_type_name
FROM purchase_order_item
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = purchase_order_item.account_type_id
WHERE purchase_order_item.is_deleted = 0
EOT;

        if ($purchase_order_id) {
            $sql .= <<<EOT

AND purchase_order_item.purchase_order_id = ?
EOT;
            $binds[] = $purchase_order_id;
        }

        if ($item_id) {
            $sql .= <<<EOT

AND purchase_order_item.item_id = ?
EOT;
            $binds[] = $item_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_prev_po_details($purchase_order_id = null, $item_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT purchase_order_item.id, 
    purchase_order_item.amount
FROM purchase_order_item
LEFT JOIN purchase_order ON purchase_order.id = purchase_order_item.purchase_order_id
WHERE purchase_order_item.is_deleted = 0
AND purchase_order.status = 'approved'
EOT;

        if ($purchase_order_id) {
            $sql .= <<<EOT

AND purchase_order_item.purchase_order_id = ?
EOT;
            $binds[] = $purchase_order_id;
        }

        if ($item_id) {
            $sql .= <<<EOT

AND purchase_order_item.item_id = ?
EOT;
            $binds[] = $item_id;
        }

        $sql .= <<<EOT

ORDER BY purchase_order.purchase_date DESC
LIMIT 1
EOT;

        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>