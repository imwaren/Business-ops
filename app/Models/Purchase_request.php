<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Purchase_request extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'purchase_request';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_id',
        'supplier_id',
        'cost_center_id',
        'capital_expenditure_id',
        'expenditure_type',
        'type',
        'needed_date',
        'request_date',
        'delivery_date',
        'payment_date',
        'company_name',
        'billing_address',
        'delivery_branch_id',
        'vat_registered_tin',
        'contact',
        'contact_ref',
        'shipping_address',
        'payment_terms',
        'buyer_assigned',
        'buyer_contact',
        'shipping_delivery_notes',
        'expected_expiration_date_from',
        'expected_expiration_date_to',
        'country',
        'region',
        'province',
        'city',
        'barangay',
        'tax_type',
        'delivery_fee',
        'subtotal',
        'discount',
        'general_discount',
        'grand_total',
        'paid_amount',
        'remarks'
    ];

    /**
     * Build the base SQL and binds shared by get_details() and get_count().
     * ORDER BY and LIMIT are intentionally excluded so the caller can append them.
     */
    private function _build_pr_sql($purchase_request_id, $status, $term, $for_po, $is_po, array &$binds): string
    {
        $binds = [];

        $sql = <<<EOT
SELECT purchase_request.*,
    capital_expenditure.name AS capital_expenditure_name,
    purchase_order.id AS purchase_order_id,
    purchase_order.purchase_order_no AS purchase_order_no,
    CASE WHEN purchase_order.status = 'pending' AND purchase_order.approved_by_department_director IS NULL THEN 'For Approval (by Department Director)'
    WHEN purchase_order.status = 'pending' AND purchase_order.approved_by_department_director IS NOT NULL THEN 'For Approval (by CEO/President)'
    WHEN purchase_order.status = 'approved' AND purchase_order.received_status = 'pending' AND purchase_order.is_sent = 0 THEN 'For Sending (by Inventory Officer)'
    WHEN purchase_order.status = 'approved' AND purchase_order.received_status = 'pending' AND purchase_order.is_sent = 1 THEN 'For Receipt Note'
    WHEN purchase_order.status = 'approved' AND purchase_order.received_status = 'open' AND purchase_order.is_sent = 1 THEN 'Open PO'
    WHEN purchase_order.status = 'approved' AND purchase_order.received_status = 'closed' AND purchase_order.is_sent = 1 THEN 'Closed PO'
    ELSE purchase_order.status
    END AS po_status,
    aoq.id AS aoq_id,
    aoq.recommended_bidder_id AS recommended_bidder_id,
    recommended_bidder.name AS recommended_bidder_name,
    delivery_branch.name AS delivery_branch_name,
    CASE WHEN purchase_order.id IS NOT NULL THEN 1 ELSE 0 END AS is_po,
    CASE
        WHEN EXISTS (
            SELECT 1 FROM purchase_request_item pri
            INNER JOIN {MASTER_DB}.item i ON pri.item_id = i.id AND i.is_deleted = 0
            WHERE pri.purchase_request_id = purchase_request.id
              AND pri.is_deleted = 0
              AND i.is_canvassing = 1
        )
        THEN CASE WHEN EXISTS (
            SELECT 1 FROM aoq a
            WHERE a.purchase_request_id = purchase_request.id
              AND a.status = 'approved'
              AND a.is_deleted = 0
        ) THEN 1 ELSE 0 END
        ELSE 1
    END AS for_po,
    CONCAT_WS(' ', buyer_assigned_user.first_name, buyer_assigned_user.middle_name, buyer_assigned_user.last_name) AS buyer_assigned_name
FROM purchase_request
LEFT JOIN capital_expenditure ON purchase_request.capital_expenditure_id = capital_expenditure.id
LEFT JOIN purchase_order ON purchase_request.id = purchase_order.purchase_request_id AND purchase_order.is_deleted = 0
LEFT JOIN aoq ON purchase_request.id = aoq.purchase_request_id AND aoq.is_deleted = 0
LEFT JOIN {MASTER_DB}.supplier AS recommended_bidder ON aoq.recommended_bidder_id = recommended_bidder.id
LEFT JOIN {MASTER_DB}.branch AS delivery_branch ON purchase_request.delivery_branch_id = delivery_branch.id
LEFT JOIN {MASTER_DB}.user AS buyer_assigned_user ON purchase_request.buyer_assigned = buyer_assigned_user.id
WHERE purchase_request.is_deleted = 0
EOT;

        if ($purchase_request_id) {
            $sql .= "\nAND purchase_request.id = ?";
            $binds[] = $purchase_request_id;
        }

        if ($status) {
            $sql .= "\nAND purchase_request.status = ?";
            $binds[] = $status;
        }

        if ($term) {
            $sql .= "\nAND purchase_request.id LIKE ?";
            $binds[] = "%$term%";
        }

        $sql .= " GROUP BY purchase_request.id";

        $having = [];
        if ($for_po !== null) {
            $having[] = "for_po = ?";
            $binds[] = (int) $for_po;
        }
        if ($is_po !== null) {
            $having[] = "is_po = ?";
            $binds[] = (int) $is_po;
        }
        if (!empty($having)) {
            $sql .= " HAVING " . implode(" AND ", $having);
        }

        return $sql;
    }

    public function get_details($purchase_request_id = null, $status = null, $term = null, $page = null, $data_size = null, $for_po = null, $is_po = null)
    {
        $binds = [];
        $sql  = $this->_build_pr_sql($purchase_request_id, $status, $term, $for_po, $is_po, $binds);
        $sql .= " ORDER BY purchase_request.needed_date DESC";

        if ($page && $data_size) {
            $offset = ($page - 1) * $data_size;
            $sql   .= " LIMIT $offset, $data_size";
        }

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_count($purchase_request_id = null, $status = null, $term = null, $for_po = null, $is_po = null): int
    {
        $binds    = [];
        $base_sql = $this->_build_pr_sql($purchase_request_id, $status, $term, $for_po, $is_po, $binds);
        $sql      = "SELECT COUNT(*) AS total FROM ($base_sql) AS pr_count";

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }
}

?>