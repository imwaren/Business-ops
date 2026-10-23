<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Purchase_return extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'purchase_return';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'receipt_note_id',
        'purchase_order_id',
        'branch_id',
        'supplier_id',
        'return_date',
        'reason',
        'remarks',
        'grand_total',
        'status',
        'approved_by',
        'approved_on',
        'disapproved_by',
        'disapproved_on',
        'disapproved_reason',
    ];

    public function get_details($purchase_return_id = null, $receipt_note_id = null, $purchase_order_id = null, $status = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT purchase_return.*,
    purchase_order.po_number,
    receipt_note.delivery_receipt_no,
    receipt_note.sales_invoice_no
FROM purchase_return
LEFT JOIN purchase_order ON purchase_order.id = purchase_return.purchase_order_id
LEFT JOIN receipt_note ON receipt_note.id = purchase_return.receipt_note_id
WHERE purchase_return.is_deleted = 0
EOT;

        if ($purchase_return_id) {
            $sql .= <<<EOT

AND purchase_return.id = ?
EOT;
            $binds[] = $purchase_return_id;
        }

        if ($receipt_note_id) {
            $sql .= <<<EOT

AND purchase_return.receipt_note_id = ?
EOT;
            $binds[] = $receipt_note_id;
        }

        if ($purchase_order_id) {
            $sql .= <<<EOT

AND purchase_return.purchase_order_id = ?
EOT;
            $binds[] = $purchase_order_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND purchase_return.status = ?
EOT;
            $binds[] = $status;
        }

        $sql .= " ORDER BY purchase_return.id DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
