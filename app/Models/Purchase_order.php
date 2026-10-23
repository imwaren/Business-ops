<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Purchase_order extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'purchase_order';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'purchase_request_id',
        'branch_id',
        'supplier_id',
        'type',
        'request_date',
        'delivery_branch_id',
        'expenditure_type',
        'capital_expenditure_id',
        'expected_expiration_date_from',
        'expected_expiration_date_to',
        'needed_date',
        'purchase_order_no',
        'purchase_date',
        'delivery_date',
        'payment_date',
        'company_name',
        'billing_address',
        'vat_registered_tin',
        'contact',
        'revision',
        'contact_ref',
        'shipping_address',
        'incoterms',
        'payment_terms',
        'terms',
        'buyer_assigned',
        'buyer_contact',
        'shipping_delivery_notes',
        'tax_type',
        'delivery_fee',
        'vat_total',
        'subtotal',
        'discount',
        'general_discount',
        'grand_total',
        'paid_amount',
        'remarks',
    ];

    public function get_details($purchase_order_id = null, $supplier_id = null, $status = null, $term = null, $screen = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT purchase_order.*, 
    purchase_request.request_date,
    CASE WHEN purchase_order.status = 'pending' AND purchase_order.approved_by_department_director IS NULL THEN 'For Approval (by Department Director)'
    WHEN purchase_order.status = 'pending' AND purchase_order.approved_by_department_director IS NOT NULL THEN 'For Approval (by CEO/President)'
    WHEN purchase_order.status = 'approved' AND purchase_order.received_status = 'pending' AND purchase_order.is_sent = 0 THEN 'For Sending (by Inventory Officer)'
    WHEN purchase_order.status = 'approved' AND purchase_order.received_status = 'pending' AND purchase_order.is_sent = 1 THEN 'For Receipt Note'
    WHEN purchase_order.status = 'approved' AND purchase_order.received_status = 'open' AND purchase_order.is_sent = 1 THEN 'Open PO'
    WHEN purchase_order.status = 'approved' AND purchase_order.received_status = 'closed' AND purchase_order.is_sent = 1 THEN 'Closed PO'
    ELSE purchase_order.status
    END AS po_status
FROM purchase_order
LEFT JOIN purchase_request ON purchase_request.id = purchase_order.purchase_request_id AND purchase_request.is_deleted = 0
WHERE purchase_order.is_deleted = 0
EOT;

        if ($purchase_order_id) {
            $sql .= <<<EOT

AND purchase_order.id = ?
EOT;
            $binds[] = $purchase_order_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND purchase_order.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($status) {
            if ($status === 'for_approval_dd' && $screen == 'manager') {
                $sql .= <<<EOT

AND purchase_order.status = 'pending'
AND purchase_order.approved_by_department_director IS NULL
EOT;
            } elseif ($status === 'for_approval_pr' && $screen == 'manager') {
                $sql .= <<<EOT

AND purchase_order.status = 'pending'
AND purchase_order.approved_by_department_director IS NOT NULL
AND purchase_order.approved_by_president IS NULL
EOT;
            } elseif ($status === 'for_sending' && $screen == 'manager') {
                $sql .= <<<EOT

AND purchase_order.status = 'approved'
AND purchase_order.received_status = 'pending'
AND purchase_order.is_sent = 0
EOT;
            } elseif ($status === 'approved' && $screen == 'manager') {
                $sql .= <<<EOT

AND purchase_order.status = 'approved'
AND purchase_order.received_status = 'pending'
AND purchase_order.is_sent = 1
EOT;
            } elseif ($status === 'open' && $screen == 'manager') {
                $sql .= <<<EOT

AND purchase_order.status = 'approved'
AND purchase_order.received_status = 'open'
AND purchase_order.is_sent = 1
AND purchase_order.is_closed_request = 0
EOT;
            } elseif ($status === 'closed_request' && $screen == 'manager') {
                $sql .= <<<EOT

AND purchase_order.status = 'approved'
AND purchase_order.received_status = 'open'
AND purchase_order.is_sent = 1
AND purchase_order.is_closed_request = 1
EOT;
            } elseif ($status === 'closed' && $screen == 'manager') {
                $sql .= <<<EOT

AND purchase_order.status = 'approved'
AND purchase_order.received_status = 'closed'
AND purchase_order.is_sent = 1
EOT;
            } else {
                $sql .= <<<EOT

AND purchase_order.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($term) {
            $sql .= <<<EOT

AND (supplier.name LIKE ? OR purchase_order.id LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY purchase_order.needed_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_last_po($branch_code = null, $year = null)
    {
        if (!$branch_code || !$year) {
            return false;
        }

        $binds = [];

    $sql = <<<EOT
SELECT po_number
FROM purchase_order
WHERE is_deleted = 0
AND po_number LIKE ?
ORDER BY po_number DESC
LIMIT 1
EOT;

        // Match: branch_code + year + anything after
        $binds[] = $branch_code . $year . '%';

        $query = $this->scoped_query($sql, $binds);

        return $query ? $query->getRowArray() : false;
    }
}

?>