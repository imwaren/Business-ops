<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Reimbursement_item extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'reimbursement_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'account_type_id',
        'supplier_id',
        'invoice_no',
        'tin',
        'address',
        'description',
        'expense_date',
        'amount',
        'vat',
        'total_value',
        'total',
        'remarks',
        'is_vat'
    ];

    public function get_details($reimbursement_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT reimbursement_item.id, 
    reimbursement_item.reimbursement_id, 
    reimbursement_item.account_type_id, 
    reimbursement_item.supplier_id, 
    reimbursement_item.invoice_no,
    reimbursement_item.tin,
    reimbursement_item.address,
    reimbursement_item.description,
    reimbursement_item.expense_date,
    reimbursement_item.amount,
    reimbursement_item.vat,
    reimbursement_item.total_value,
    reimbursement_item.total,
    reimbursement_item.remarks,
    reimbursement_item.is_vat,
    account_type.name AS account_type_name
FROM reimbursement_item
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = reimbursement_item.account_type_id AND account_type.is_deleted = 0
WHERE reimbursement_item.is_deleted = 0
EOT;

        if ($reimbursement_id) {
            $sql .= <<<EOT

AND reimbursement_item.reimbursement_id = ?
EOT;
            $binds[] = $reimbursement_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>