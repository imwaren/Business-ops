<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Purchase_return_item extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'purchase_return_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'purchase_return_id',
        'receipt_note_id',
        'receipt_note_item_id',
        'purchase_order_id',
        'purchase_order_item_id',
        'account_type_id',
        'cost_center_id',
        'item_id',
        'qty',
        'unit',
        'price',
        'amount',
        'total',
        'vat',
        'vat_type',
        'total_value',
        'remarks',
    ];

    public function get_details($purchase_return_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT purchase_return_item.*,
    account_type.name AS account_type_name,
    account_type.code AS account_type_code
FROM purchase_return_item
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = purchase_return_item.account_type_id
WHERE purchase_return_item.is_deleted = 0
EOT;

        if ($purchase_return_id) {
            $sql .= <<<EOT

AND purchase_return_item.purchase_return_id = ?
EOT;
            $binds[] = $purchase_return_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
