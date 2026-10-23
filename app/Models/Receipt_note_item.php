<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Receipt_note_item extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'receipt_note_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'batch_no',
        'account_type_id',
        'cost_center_id',
        'expenditure_type',
        'item_id',
        'ordered_qty',
        'qty',
        'unit',
        'cost',
        'discount',
        'amount'
    ];

    public function get_details($receipt_note_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT receipt_note_item.id, 
    receipt_note_item.receipt_note_id, 
    receipt_note_item.account_type_id, 
    receipt_note_item.cost_center_id, 
    receipt_note_item.expenditure_type, 
    receipt_note_item.item_id,
    receipt_note_item.batch_no,
    receipt_note_item.ordered_qty,
    receipt_note_item.qty,
    receipt_note_item.unit,
    receipt_note_item.cost,
    receipt_note_item.discount,
    receipt_note_item.price,
    receipt_note_item.total,
    receipt_note_item.vat,
    receipt_note_item.vat_type,
    receipt_note_item.total_value,
    receipt_note_item.amount,
    receipt_note_item.remarks,
    account_type.name AS account_type_name,
    item.name AS item_name,
    item.kind AS item_kind
FROM receipt_note_item
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = receipt_note_item.account_type_id
LEFT JOIN item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
WHERE receipt_note_item.is_deleted = 0
EOT;

        if ($receipt_note_id) {
            $sql .= <<<EOT

AND receipt_note_item.receipt_note_id = ?
EOT;
            $binds[] = $receipt_note_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>