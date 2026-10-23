<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Accounts_payable_voucher extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    private const FOR_PAYMENT_PREDICATE = "accounts_payable_voucher.is_deleted = 0 AND accounts_payable_voucher.status = 'approved' AND accounts_payable_voucher.balance > 0";

    protected $table = 'accounts_payable_voucher';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'type',
        'type_id',
        'supplier_id',
        'supplier_type',
        'due_date',
        'subtotal',
        'vat',
        'withholding_tax',
        'wht_code',
        'amount',
        'balance',
        'status',
    ];

    public function get_details($accounts_payable_voucher_id = null, $type = null, $type_id = null, $status = null, $supplier_id = null, $supplier_type = null, $screen = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT accounts_payable_voucher.*,
    CONCAT_WS(' ', adder_user.first_name, adder_user.middle_name, adder_user.last_name) AS added_by_name,
    CONCAT_WS(' ', approver_user.first_name, approver_user.middle_name, approver_user.last_name) AS approved_by_name,
    CONCAT_WS(' ', disapprover_user.first_name, disapprover_user.middle_name, disapprover_user.last_name) AS disapproved_by_name,
    receipt_note.purchase_order_id AS purchase_order_id,
    purchase_order.purchase_request_id AS purchase_request_id,
    aoq.id AS aoq_id
FROM accounts_payable_voucher
LEFT JOIN {MASTER_DB}.user AS adder_user ON adder_user.id = accounts_payable_voucher.added_by
LEFT JOIN {MASTER_DB}.user AS approver_user ON approver_user.id = accounts_payable_voucher.approved_by
LEFT JOIN {MASTER_DB}.user AS disapprover_user ON disapprover_user.id = accounts_payable_voucher.disapproved_by
LEFT JOIN receipt_note ON receipt_note.id = accounts_payable_voucher.type_id
    AND accounts_payable_voucher.type = 'receipt_note'
    AND receipt_note.is_deleted = 0
LEFT JOIN purchase_order ON purchase_order.id = receipt_note.purchase_order_id
    AND purchase_order.is_deleted = 0
LEFT JOIN aoq ON aoq.id = (
    SELECT aoq2.id FROM aoq AS aoq2
    WHERE aoq2.purchase_request_id = purchase_order.purchase_request_id
        AND aoq2.is_deleted = 0
    LIMIT 1
)
WHERE accounts_payable_voucher.is_deleted = 0
EOT;

        if ($this->shared_cost_centers !== null && !$this->db->fieldExists('branch_id','receipt_note')) {
            // Legacy master sources have no receipt-note/PO/AOQ dependencies in master.
            $join = strpos($sql, 'LEFT JOIN receipt_note');
            $where = strpos($sql, 'WHERE accounts_payable_voucher.is_deleted');
            $sql = substr($sql, 0, $join) . substr($sql, $where);
            $sql = str_replace(['receipt_note.purchase_order_id', 'purchase_order.purchase_request_id', 'aoq.id'], 'NULL', $sql);
        }

        if ($accounts_payable_voucher_id) {
            $sql .= <<<EOT

AND accounts_payable_voucher.id = ?
EOT;
            $binds[] = $accounts_payable_voucher_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND accounts_payable_voucher.type = ?
EOT;
            $binds[] = $type;
        }

        if ($type_id) {
            $sql .= <<<EOT

AND accounts_payable_voucher.type_id = ?
EOT;
            $binds[] = $type_id;
        }

        if ($status) {
            if ($status == 'approved') {
                $sql .= <<<EOT

AND accounts_payable_voucher.status = 'approved'
AND accounts_payable_voucher.amount = accounts_payable_voucher.balance
EOT;
            } elseif ($status == 'open') {
                $sql .= <<<EOT

AND accounts_payable_voucher.status = 'approved'
AND accounts_payable_voucher.balance > 0
AND accounts_payable_voucher.amount != accounts_payable_voucher.balance
EOT;
            } elseif ($status == 'closed') {
                $sql .= <<<EOT

AND accounts_payable_voucher.status = 'approved'
AND accounts_payable_voucher.balance <= 0
EOT;
            } else {
                $sql .= <<<EOT
    
AND accounts_payable_voucher.status = ?
EOT;
                $binds[] = $status;
            }
        }

        if ($supplier_id && $supplier_type) {
            $sql .= <<<EOT

AND accounts_payable_voucher.supplier_id = ?
AND accounts_payable_voucher.supplier_type = ?
EOT;
            $binds[] = $supplier_id;
            $binds[] = $supplier_type;
        }

        if ($screen) {
            if (in_array($screen, ['payment', 'for_payment'], true)) {
                $sql .= "\nAND " . self::FOR_PAYMENT_PREDICATE;
            } elseif ($screen === 'asset_depreciation') {
                $sql .= <<<EOT

AND accounts_payable_voucher.type = 'receipt_note'
AND receipt_note.type = 'goods'
EOT;
            }
        }

        if ($term) {
            $sql .= <<<EOT

AND accounts_payable_voucher.amount LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        //order by due date descending
        $sql .= " ORDER BY accounts_payable_voucher.due_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /** Shared source rows carry their actual business center, not the master DB key. */
    protected function get_shared_for_apv($type, $status, $supplier_id, $supplier_type, $term, $page, $data_size)
    {
        $parts = [];
        foreach (['petty_cash','cash_advance','liquidation','reimbursement','replenishment','change_fund','direct_payment'] as $table) {
            $amount = in_array($table, ['reimbursement','replenishment'], true) ? 'grand_total' : 'amount';
            $user = in_array($table, ['liquidation','reimbursement','replenishment'], true) ? 'liquidated_by_id' : 'requested_by_id';
            $date = $table === 'petty_cash' ? 'date' : 'added_on';
            $due = $table === 'cash_advance' ? 'date_needed' : 'due_date';
            $extra = $table === 'petty_cash' ? " AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher v INNER JOIN replenishment r ON r.id=v.type_id WHERE v.type='replenishment' AND v.is_deleted=0 AND v.cost_center_id=s.cost_center_id AND r.cost_center_id=s.cost_center_id AND r.petty_cash_id=s.id)" : '';
            $parts[] = "SELECT s.id AS type_id, '$table' AS type, s.cost_center_id AS branch_id,
                DATE(s.$date) AS date, s.$due AS due_date, s.$user AS supplier_id,
                'payor' AS supplier_type, CONCAT_WS(' ', u.first_name,u.middle_name,u.last_name) AS supplier_name,
                s.$amount AS amount FROM $table s
                LEFT JOIN {MASTER_DB}.user u ON u.id=s.$user
                WHERE s.is_deleted=0 AND s.status='approved'
                AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher v WHERE v.type='$table'
                    AND v.type_id=s.id AND v.cost_center_id=s.cost_center_id AND v.is_deleted=0)$extra";
        }
        if ($this->db->fieldExists('branch_id','receipt_note')) {
            $parts[] = "SELECT r.id AS type_id,'receipt_note' AS type,r.branch_id,
                DATE(r.added_on) AS date,r.due_date,r.supplier_id,'supplier' AS supplier_type,
                supplier.name AS supplier_name,r.grand_total AS amount FROM receipt_note r
                LEFT JOIN {MASTER_DB}.supplier supplier ON supplier.id=r.supplier_id AND supplier.is_deleted=0
                WHERE r.is_deleted=0 AND r.status='approved' AND r.grand_total<>0
                AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher v WHERE v.type='receipt_note'
                    AND v.type_id=r.id AND v.cost_center_id=r.branch_id AND v.is_deleted=0)";
        }
        $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') sources WHERE 1=1';
        $binds = [];
        // for_apv selects approved sources without an active voucher, not a stored source status.
        if ($status && !in_array($status, ['approved', 'for_apv'], true)) { return []; }
        foreach (['type'=>$type,'supplier_id'=>$supplier_id,'supplier_type'=>$supplier_type] as $key=>$value) {
            if ($value !== null && $value !== '') { $sql .= " AND sources.$key=?"; $binds[]=$value; }
        }
        if ($term) { $sql .= ' AND (sources.supplier_name LIKE ? OR sources.type_id LIKE ?)'; $binds[]="%$term%"; $binds[]="%$term%"; }
        $sql .= ' ORDER BY sources.due_date DESC,sources.type_id DESC' . sql_limit_clause($page,$data_size);
        $query = $this->scoped_query($this->_resolve_cross_db_sql($sql),$binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_all_for_apv($branch_id = null, $type = null, $status = null, $supplier_id = null, $supplier_type = null, $term = null, $page = null, $data_size = null) {
        if ($this->shared_cost_centers !== null) {
            return $this->get_shared_for_apv($type,$status,$supplier_id,$supplier_type,$term,$page,$data_size);
        }
        $binds = [];
        $bid   = $branch_id ? (int)$branch_id : 2;

        $sql = <<<EOT
SELECT * FROM (
SELECT receipt_note.id AS type_id,
    'receipt_note' AS type,
    '{$bid}' AS branch_id,
    DATE(receipt_note.added_on) AS date,
    receipt_note.due_date AS due_date,
    receipt_note.supplier_id,
    'supplier' AS supplier_type,
    supplier.name AS supplier_name,
    receipt_note.grand_total AS amount
FROM receipt_note
LEFT JOIN {MASTER_DB}.supplier AS supplier ON supplier.id = receipt_note.supplier_id
WHERE receipt_note.is_deleted = 0
AND receipt_note.status = 'approved'
AND receipt_note.grand_total != 0
AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher AS apv WHERE apv.type = 'receipt_note' AND apv.type_id = receipt_note.id AND apv.is_deleted = 0)

UNION ALL

SELECT petty_cash.id AS type_id,
    'petty_cash' AS type,
    '{$bid}' AS branch_id,
    petty_cash.date AS date,
    petty_cash.due_date AS due_date,
    petty_cash.requested_by_id AS supplier_id,
    'payor' AS supplier_type,
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) AS supplier_name,
    petty_cash.amount AS amount
FROM petty_cash
LEFT JOIN {MASTER_DB}.user AS user ON user.id = petty_cash.requested_by_id
WHERE petty_cash.is_deleted = 0
AND petty_cash.status = 'approved'
AND NOT (
    EXISTS (
        SELECT 1 FROM accounts_payable_voucher AS apv 
        WHERE apv.type = 'petty_cash' AND apv.type_id = petty_cash.id AND apv.is_deleted = 0
    )
    OR
    EXISTS (
        SELECT 1 FROM accounts_payable_voucher AS apv 
        INNER JOIN replenishment ON replenishment.id = apv.type_id
        WHERE apv.type = 'replenishment' AND apv.is_deleted = 0 AND replenishment.petty_cash_id = petty_cash.id
    )
)

UNION ALL

SELECT reimbursement.id AS type_id,
    'reimbursement' AS type,
    '{$bid}' AS branch_id,
    DATE(reimbursement.added_on) AS date,
    reimbursement.due_date AS due_date,
    reimbursement.liquidated_by_id AS supplier_id,
    'payor' AS supplier_type,
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) AS supplier_name,
    reimbursement.grand_total AS amount
FROM reimbursement
LEFT JOIN {MASTER_DB}.user AS user ON user.id = reimbursement.liquidated_by_id
WHERE reimbursement.is_deleted = 0
AND reimbursement.status = 'approved'
AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher AS apv WHERE apv.type = 'reimbursement' AND apv.type_id = reimbursement.id AND apv.is_deleted = 0)

UNION ALL

SELECT replenishment.id AS type_id,
    'replenishment' AS type,
    '{$bid}' AS branch_id,
    DATE(replenishment.added_on) AS date,
    replenishment.due_date AS due_date,
    replenishment.liquidated_by_id AS supplier_id,
    'payor' AS supplier_type,
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) AS supplier_name,
    replenishment.grand_total AS amount
FROM replenishment
LEFT JOIN {MASTER_DB}.user AS user ON user.id = replenishment.liquidated_by_id
WHERE replenishment.is_deleted = 0
AND replenishment.status = 'approved'
AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher AS apv WHERE apv.type = 'replenishment' AND apv.type_id = replenishment.id AND apv.is_deleted = 0)

UNION ALL

SELECT cash_advance.id AS type_id,
    'cash_advance' AS type,
    '{$bid}' AS branch_id,
    DATE(cash_advance.added_on) AS date,
    cash_advance.date_needed AS due_date,
    cash_advance.requested_by_id AS supplier_id,
    'payor' AS supplier_type,
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) AS supplier_name,
    cash_advance.amount AS amount
FROM cash_advance
LEFT JOIN {MASTER_DB}.user AS user ON user.id = cash_advance.requested_by_id
WHERE cash_advance.is_deleted = 0
AND cash_advance.status = 'approved'
AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher AS apv WHERE apv.type = 'cash_advance' AND apv.type_id = cash_advance.id AND apv.is_deleted = 0)

UNION ALL

SELECT liquidation.id AS type_id,
    'liquidation' AS type,
    '{$bid}' AS branch_id,
    DATE(liquidation.added_on) AS date,
    liquidation.due_date AS due_date,
    liquidation.liquidated_by_id AS supplier_id,
    'payor' AS supplier_type,
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) AS supplier_name,
    liquidation.amount AS amount
FROM liquidation
LEFT JOIN {MASTER_DB}.user AS user ON user.id = liquidation.liquidated_by_id
WHERE liquidation.is_deleted = 0
AND liquidation.status = 'approved'
AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher AS apv WHERE apv.type = 'liquidation' AND apv.type_id = liquidation.id AND apv.is_deleted = 0)

UNION ALL

SELECT change_fund.id AS type_id,
    'change_fund' AS type,
    '{$bid}' AS branch_id,
    DATE(change_fund.added_on) AS date,
    change_fund.due_date AS due_date,
    change_fund.requested_by_id AS supplier_id,
    'payor' AS supplier_type,
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) AS supplier_name,
    change_fund.amount AS amount
FROM change_fund
LEFT JOIN {MASTER_DB}.user AS user ON user.id = change_fund.requested_by_id
WHERE change_fund.is_deleted = 0
AND change_fund.status = 'approved'
AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher AS apv WHERE apv.type = 'change_fund' AND apv.type_id = change_fund.id AND apv.is_deleted = 0)

UNION ALL

SELECT direct_payment.id AS type_id,
    'direct_payment' AS type,
    '{$bid}' AS branch_id,
    DATE(direct_payment.added_on) AS date,
    direct_payment.due_date AS due_date,
    direct_payment.requested_by_id AS supplier_id,
    'payor' AS supplier_type,
    CONCAT_WS(' ', user.first_name, user.middle_name, user.last_name) AS supplier_name,
    direct_payment.amount AS amount
FROM direct_payment
LEFT JOIN {MASTER_DB}.user AS user ON user.id = direct_payment.requested_by_id
WHERE direct_payment.is_deleted = 0
AND direct_payment.status = 'approved'
AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher AS apv WHERE apv.type = 'direct_payment' AND apv.type_id = direct_payment.id AND apv.is_deleted = 0)

UNION ALL

SELECT refund.id AS type_id,
    'refund' AS type,
    '{$bid}' AS branch_id,
    DATE(refund.added_on) AS date,
    DATE(refund.added_on) AS due_date,
    refund.patient_id AS supplier_id,
    'patient' AS supplier_type,
    NULL AS supplier_name,
    refund.amount AS amount
FROM {MASTER_DB}.refund AS refund
WHERE refund.is_deleted = 0
AND refund.branch_id = {$bid}
AND refund.status = 'approved'
AND refund.amount != 0
AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher AS apv WHERE apv.type = 'refund' AND apv.type_id = refund.id AND apv.is_deleted = 0)

UNION ALL

SELECT send_out.id AS type_id,
    'send_out' AS type,
    send_out.branch_id,
    DATE(send_out.added_on) AS date,
    send_out.due_date AS due_date,
    send_out.supplier_id,
    'supplier' AS supplier_type,
    supplier.name AS supplier_name,
    send_out.total_expense AS amount
FROM send_out
LEFT JOIN {MASTER_DB}.supplier AS supplier ON supplier.id = send_out.supplier_id
WHERE send_out.is_deleted = 0
AND NOT EXISTS (
    SELECT 1 FROM accounts_payable_voucher AS apv
    WHERE apv.type = 'send_out' AND apv.type_id = send_out.id AND apv.is_deleted = 0
)
) AS apv_data
WHERE 1=1
EOT;

        if ($type) {
            $sql    .= "\nAND apv_data.type = ?";
            $binds[] = $type;
        }

        if ($supplier_id && $supplier_type) {
            $sql    .= "\nAND apv_data.supplier_id = ?";
            $binds[] = $supplier_id;
            $sql    .= "\nAND apv_data.supplier_type = ?";
            $binds[] = $supplier_type;
        }

        if ($term) {
            $sql    .= "\nAND apv_data.type LIKE ?";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql .= " ORDER BY apv_data.due_date DESC";

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Run get_all_for_apv() on each branch DB, merge and sort in PHP, then paginate.
     *
     * @param array<int,string> $branch_db_map  branch_id => database group name
     *                                          e.g. [1=>'mobile', 2=>'oakridge', 3=>'mactan', 4=>'consolacion', 7=>'mantlewood']
     * @param int|null          $branch_id      When set, only that branch key is queried; otherwise all branches are looped.
     * @return array{size:int,data:array}
     */
    public function get_all_for_apv_all_branches(
        array $branch_db_map,
        $branch_id     = null,
        $type          = null,
        $status        = null,
        $supplier_id   = null,
        $supplier_type = null,
        $term          = null,
        $page          = null,
        $data_size     = null
    ) {
        $merged = [];

        if ($branch_id) {
            $branch_db_map = array_intersect_key($branch_db_map, [(int) $branch_id => true]);
        }

        foreach (\App\Libraries\Fund_cost_center::storage_groups($branch_db_map) as $groupName => $center_ids) {
            $branch_id = $center_ids[0];
            $db          = \Config\Database::connect($groupName);
            $branchModel = new self($db);
            if ($groupName === 'master') { $branchModel->with_shared_cost_centers($center_ids); }
            $rows        = $branchModel->get_all_for_apv(
                $branch_id,
                $type,
                $status,
                $supplier_id,
                $supplier_type,
                $term,
                null,
                null
            ) ?: [];

            $merged = array_merge($merged, $rows);
        }

        usort($merged, static function ($a, $b) {
            $cmp = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($b['type_id'] ?? 0)) <=> ((int) ($a['type_id'] ?? 0));
        });

        $size = count($merged);

        if ($page && $data_size) {
            $page   = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $merged = array_slice($merged, $offset, (int) $data_size);
        }

        return [
            'size' => $size,
            'data' => $merged,
        ];
    }

    /**
     * Same filters as {@see get_details()}, run on each branch DB, merged in PHP with cost_center_id on each row.
     * Pagination applies to the merged list (not per branch). Numeric ids may repeat across branches; use cost_center_id + id together.
     *
     * @param array<int,string> $branch_db_map cost_center_id / branch_id => database group name
     * @return array{size:int,data:array}
     */
    public function get_all_details_all_branches(
        array $branch_db_map,
        $accounts_payable_voucher_id = null,
        $type = null,
        $type_id = null,
        $status = null,
        $supplier_id = null,
        $supplier_type = null,
        $screen = null,
        $term = null,
        $page = null,
        $data_size = null
    ) {
        $merged = [];
        $shared_names = in_array('master', $branch_db_map, true)
            ? (new \App\Libraries\Fund_cost_center())->branches() : [];

        foreach (\App\Libraries\Fund_cost_center::storage_groups($branch_db_map) as $groupName => $center_ids) {
            $cost_center_id = $center_ids[0];
            $db = \Config\Database::connect($groupName);
            $branchModel = new self($db);
            if ($groupName === 'master') { $branchModel->with_shared_cost_centers($center_ids); }
            $rows = $branchModel->get_details(
                $accounts_payable_voucher_id,
                $type,
                $type_id,
                $status,
                $supplier_id,
                $supplier_type,
                $screen,
                $term,
                null,
                null
            ) ?: [];

            foreach ($rows as $row) {
                $row['cost_center_id'] = $groupName === 'master' ? (int) $row['cost_center_id'] : (int) $cost_center_id;
                $row['cost_center_name'] = $groupName === 'master'
                    ? ($shared_names[$row['cost_center_id']]['name'] ?? 'Shared')
                    : ucwords(str_replace('_', ' ', $groupName));
                $merged[] = $row;
            }
        }

        usort($merged, static function ($a, $b) {
            $aKey = $a['added_on'] ?? '';
            $bKey = $b['added_on'] ?? '';
            $cmp = strcmp((string) $bKey, (string) $aKey);
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
            return $cmp ?: ((int) $a['cost_center_id'] <=> (int) $b['cost_center_id']);
        });

        $size = count($merged);

        if ($page && $data_size) {
            $page = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $merged = array_slice($merged, $offset, (int) $data_size);
        }

        return [
            'size' => $size,
            'data' => $merged,
        ];
    }

    /**
     * Open APVs (balance > 0) aged by due date for one clinic DB.
     *
     * @return array<int,array<string,mixed>>|false
     */
    public function get_aged_payables($as_of_date = null, $supplier_id = null, $supplier_type = null)
    {
        $as_of_date = $as_of_date ?: date('Y-m-d');
        $binds = [
            $as_of_date,
            $as_of_date,
            $as_of_date,
            $as_of_date,
            $as_of_date,
            $as_of_date,
            $as_of_date,
            $as_of_date,
            $as_of_date,
        ];

        $eligibility = self::FOR_PAYMENT_PREDICATE;
        $sql = <<<EOT
SELECT accounts_payable_voucher.id,
    accounts_payable_voucher.type,
    accounts_payable_voucher.type_id,
    accounts_payable_voucher.supplier_id,
    accounts_payable_voucher.supplier_type,
    DATE(accounts_payable_voucher.added_on) AS apv_date,
    COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on)) AS due_date,
    accounts_payable_voucher.balance,
    accounts_payable_voucher.status,
    CASE
        WHEN LOWER(TRIM(accounts_payable_voucher.supplier_type)) = 'supplier' THEN supplier.name
        WHEN LOWER(TRIM(accounts_payable_voucher.supplier_type)) = 'payor' THEN TRIM(CONCAT_WS(' ', payor.first_name, payor.last_name, payor.middle_name))
        ELSE NULL
    END AS supplier_name,
    supplier.category AS supplier_category,
    CASE
        WHEN accounts_payable_voucher.type = 'receipt_note' AND NULLIF(purchase_order.po_number, '') IS NOT NULL
            THEN CONCAT('Payment for PO # ', purchase_order.po_number)
        ELSE COALESCE(
            NULLIF(receipt_note.remarks, ''),
            NULLIF(petty_cash.remarks, ''),
            NULLIF(cash_advance.remarks, ''),
            NULLIF(liquidation.remarks, ''),
            NULLIF(change_fund.remarks, ''),
            NULLIF(direct_payment.remarks, ''),
            CONCAT('Payment for ', accounts_payable_voucher.type, ' #', accounts_payable_voucher.type_id)
        )
    END AS remarks,
    COALESCE(
        CASE
            WHEN accounts_payable_voucher.type = 'receipt_note' AND NULLIF(receipt_note.sales_invoice_no, '') IS NOT NULL
                THEN receipt_note.sales_invoice_no
            WHEN accounts_payable_voucher.type = 'reimbursement' THEN (
                SELECT GROUP_CONCAT(DISTINCT TRIM(reimbursement_item.invoice_no) ORDER BY reimbursement_item.invoice_no SEPARATOR ', ')
                FROM reimbursement_item
                WHERE reimbursement_item.reimbursement_id = accounts_payable_voucher.type_id
                    AND reimbursement_item.is_deleted = 0
                    AND NULLIF(TRIM(reimbursement_item.invoice_no), '') IS NOT NULL
            )
            WHEN accounts_payable_voucher.type = 'replenishment' THEN (
                SELECT GROUP_CONCAT(DISTINCT TRIM(replenishment_item.invoice_no) ORDER BY replenishment_item.invoice_no SEPARATOR ', ')
                FROM replenishment_item
                WHERE replenishment_item.replenishment_id = accounts_payable_voucher.type_id
                    AND replenishment_item.is_deleted = 0
                    AND NULLIF(TRIM(replenishment_item.invoice_no), '') IS NOT NULL
            )
            WHEN accounts_payable_voucher.type = 'liquidation' THEN (
                SELECT GROUP_CONCAT(DISTINCT TRIM(liquidation_detail.invoice_no) ORDER BY liquidation_detail.invoice_no SEPARATOR ', ')
                FROM liquidation_detail
                WHERE liquidation_detail.liquidation_id = accounts_payable_voucher.type_id
                    AND liquidation_detail.is_deleted = 0
                    AND NULLIF(TRIM(liquidation_detail.invoice_no), '') IS NOT NULL
            )
        END,
        CONCAT('RFP No.# ', accounts_payable_voucher.type_id)
    ) AS invoice_reference,
    CASE
        WHEN COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on)) >= DATE_FORMAT(?, '%Y-%m-01')
            THEN accounts_payable_voucher.balance
        ELSE 0
    END AS current,
    CASE
        WHEN COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on)) < DATE_FORMAT(?, '%Y-%m-01')
            AND DATEDIFF(?, COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on))) BETWEEN 1 AND 60
            THEN accounts_payable_voucher.balance
        ELSE 0
    END AS `1_month`,
    CASE
        WHEN COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on)) < DATE_FORMAT(?, '%Y-%m-01')
            AND DATEDIFF(?, COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on))) BETWEEN 61 AND 90
            THEN accounts_payable_voucher.balance
        ELSE 0
    END AS `2_months`,
    CASE
        WHEN COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on)) < DATE_FORMAT(?, '%Y-%m-01')
            AND DATEDIFF(?, COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on))) BETWEEN 91 AND 120
            THEN accounts_payable_voucher.balance
        ELSE 0
    END AS `3_months`,
    CASE
        WHEN COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on)) < DATE_FORMAT(?, '%Y-%m-01')
            AND DATEDIFF(?, COALESCE(accounts_payable_voucher.due_date, DATE(accounts_payable_voucher.added_on))) >= 121
            THEN accounts_payable_voucher.balance
        ELSE 0
    END AS older
FROM accounts_payable_voucher
LEFT JOIN {MASTER_DB}.supplier AS supplier
    ON supplier.id = accounts_payable_voucher.supplier_id
    AND accounts_payable_voucher.supplier_type = 'supplier'
LEFT JOIN {MASTER_DB}.user AS payor
    ON payor.id = accounts_payable_voucher.supplier_id
    AND LOWER(TRIM(accounts_payable_voucher.supplier_type)) = 'payor'
LEFT JOIN receipt_note
    ON receipt_note.id = accounts_payable_voucher.type_id
    AND accounts_payable_voucher.type = 'receipt_note'
    AND receipt_note.is_deleted = 0
LEFT JOIN purchase_order
    ON purchase_order.id = receipt_note.purchase_order_id
    AND purchase_order.is_deleted = 0
LEFT JOIN petty_cash
    ON petty_cash.id = accounts_payable_voucher.type_id
    AND accounts_payable_voucher.type = 'petty_cash'
    AND petty_cash.is_deleted = 0
LEFT JOIN cash_advance
    ON cash_advance.id = accounts_payable_voucher.type_id
    AND accounts_payable_voucher.type = 'cash_advance'
    AND cash_advance.is_deleted = 0
LEFT JOIN liquidation
    ON liquidation.id = accounts_payable_voucher.type_id
    AND accounts_payable_voucher.type = 'liquidation'
    AND liquidation.is_deleted = 0
LEFT JOIN change_fund
    ON change_fund.id = accounts_payable_voucher.type_id
    AND accounts_payable_voucher.type = 'change_fund'
    AND change_fund.is_deleted = 0
LEFT JOIN direct_payment
    ON direct_payment.id = accounts_payable_voucher.type_id
    AND accounts_payable_voucher.type = 'direct_payment'
    AND direct_payment.is_deleted = 0
WHERE {$eligibility}
EOT;
        if ($supplier_type !== null) {
            $sql .= "\nAND accounts_payable_voucher.supplier_type = ?";
            $binds[] = $supplier_type;
        }
        if ($supplier_id !== null) {
            $sql .= "\nAND accounts_payable_voucher.supplier_id = ?";
            $binds[] = $supplier_id;
        }
        $sql .= "\nORDER BY supplier_name ASC, due_date ASC, accounts_payable_voucher.id ASC";

        if ($this->shared_cost_centers !== null) {
            if (!$this->db->fieldExists('branch_id', 'receipt_note')) {
                $join = strpos($sql, 'LEFT JOIN receipt_note');
                $funds = strpos($sql, 'LEFT JOIN petty_cash');
                $sql = substr($sql, 0, $join) . substr($sql, $funds);
                $sql = preg_replace('/\b(?:receipt_note|purchase_order)\.[a-z_]+/', 'NULL', $sql);
            }

            $sql = str_replace('SELECT accounts_payable_voucher.id,',
                'SELECT accounts_payable_voucher.id, accounts_payable_voucher.cost_center_id,', $sql);
        }
        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Run {@see get_aged_payables()} on each branch DB and merge in PHP.
     *
     * @param array<int,string> $branch_db_map branch_id => database group name
     * @return array<int,array<string,mixed>>
     */
    public function get_aged_payables_all_branches(array $branch_db_map, $as_of_date = null, $supplier_id = null, $supplier_type = null)
    {
        $merged = [];
        $shared_names = in_array('master', $branch_db_map, true)
            ? (new \App\Libraries\Fund_cost_center())->branches() : [];

        foreach (\App\Libraries\Fund_cost_center::storage_groups($branch_db_map) as $groupName => $center_ids) {
            $branch_id = $center_ids[0];
            $db = \Config\Database::connect($groupName);
            $branchModel = new self($db);
            if ($groupName === 'master') { $branchModel->with_shared_cost_centers($center_ids); }
            $rows = $branchModel->get_aged_payables($as_of_date, $supplier_id, $supplier_type) ?: [];

            foreach ($rows as $row) {
                $row['branch_id'] = $groupName === 'master' ? (int) $row['cost_center_id'] : (int) $branch_id;
                $row['branch_name'] = $groupName === 'master'
                    ? ($shared_names[$row['branch_id']]['name'] ?? 'Shared')
                    : ucwords(str_replace('_', ' ', $groupName));
                $merged[] = $row;
            }
        }

        usort($merged, static function ($a, $b) {
            $nameCmp = strcasecmp((string) ($a['supplier_name'] ?? ''), (string) ($b['supplier_name'] ?? ''));
            if ($nameCmp !== 0) {
                return $nameCmp;
            }

            $dueCmp = strcmp((string) ($a['due_date'] ?? ''), (string) ($b['due_date'] ?? ''));
            if ($dueCmp !== 0) {
                return $dueCmp;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $merged;
    }
}
?>
