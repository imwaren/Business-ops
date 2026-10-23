<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Receipt_note extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'receipt_note';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'purchase_order_id',
        'supplier_id',
        'branch_id',
        'type',
        'needed_date',
        'receive_date',
        'delivery_receipt_no',
        'sales_invoice_no',
        'company_name',
        'billing_address',
        'vat_registered_tin',
        'contact',
        'contact_ref',
        'buyer_assigned',
        'buyer_contact',
        'revision',
        'incoterms',
        'payment_terms',
        'tax_type',
        'shipping_address',
        'shipping_delivery_notes',
        'delivery_fee',
        'subtotal',
        'discount',
        'general_discount',
        'grand_total',
        'paid_amount',
        'remarks',
        'delivery_branch_id',
        'expenditure_type',
        'capital_expenditure_id',
        'expected_expiration_date_from',
        'expected_expiration_date_to',
        'request_date',
        'due_date',
    ];

    public function get_details($receipt_note_id = null, $purchase_order_id = null, $status = null, $term = null, $page = null, $data_size = null, $supplier_id = null, $pharmacy_only = false) {
        $binds = [];
        $sql = <<<EOT
SELECT receipt_note.*, purchase_order.po_number
FROM receipt_note
LEFT JOIN purchase_order ON purchase_order.id = receipt_note.purchase_order_id
LEFT JOIN {MASTER_DB}.supplier AS supplier ON supplier.id = receipt_note.supplier_id AND supplier.is_deleted = 0
WHERE receipt_note.is_deleted = 0
EOT;

        if ($receipt_note_id) {
            $sql .= <<<EOT

AND receipt_note.id = ?
EOT;
            $binds[] = $receipt_note_id;
        }

        if ($purchase_order_id) {
            $sql .= <<<EOT

AND purchase_order.id = ?
EOT;
            $binds[] = $purchase_order_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND receipt_note.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($pharmacy_only) {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1
    FROM receipt_note_item pharmacy_receipt_note_item
    INNER JOIN item pharmacy_item
        ON pharmacy_item.id = pharmacy_receipt_note_item.item_id
        AND pharmacy_item.is_deleted = 0
    WHERE pharmacy_receipt_note_item.receipt_note_id = receipt_note.id
      AND pharmacy_receipt_note_item.is_deleted = 0
      AND FIND_IN_SET('pharmacy', REPLACE(LOWER(pharmacy_item.kind), ' ', '')) > 0
)
EOT;
        }

        if ($status) {
            $sql .= <<<EOT

AND receipt_note.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (supplier.name LIKE ? OR receipt_note.remarks LIKE ? OR purchase_order.po_number LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY receipt_note.needed_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
