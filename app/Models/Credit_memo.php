<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Credit_memo extends MYT_Model
{
    protected $table = 'credit_memo';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'control_no',
        'billing_id',
        'branch_id',
        'debit_account_type_id',
        'credit_account_type_id',
        'date_issued',
        'reason',
        'amount',
        'remarks',
        'status',
        'approved_by',
        'approved_on',
        'disapproved_by',
        'disapproved_on',
    ];

    public function get_details($credit_memo_id = null, $branch_id = null, $billing_id = null, $status = null, $date_from = null, $date_to = null, $page = null, $data_size = null, $billing_ids = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT credit_memo.*,
    billing.company_id AS company_id,
    memo_company.name AS company_name,
    billing.invoice_id AS billing_reference,
    billing.invoice_no AS invoice_no,
    billing.invoice_date AS invoice_date,
    branch.name AS branch_name,
    debit_account.name AS debit_account_name,
    debit_account.code AS debit_account_code,
    credit_account.name AS credit_account_name,
    credit_account.code AS credit_account_code,
    CONCAT_WS(' ', approved_by.first_name, approved_by.middle_name, approved_by.last_name) AS approved_by_name,
    CONCAT_WS(' ', disapproved_by.first_name, disapproved_by.middle_name, disapproved_by.last_name) AS disapproved_by_name
FROM credit_memo
LEFT JOIN billing ON billing.id = credit_memo.billing_id
LEFT JOIN company AS memo_company ON memo_company.id = billing.company_id AND memo_company.is_deleted = 0
LEFT JOIN branch ON branch.id = credit_memo.branch_id
LEFT JOIN account_type AS debit_account ON debit_account.id = credit_memo.debit_account_type_id
LEFT JOIN account_type AS credit_account ON credit_account.id = credit_memo.credit_account_type_id
LEFT JOIN user AS approved_by ON approved_by.id = credit_memo.approved_by
LEFT JOIN user AS disapproved_by ON disapproved_by.id = credit_memo.disapproved_by
WHERE credit_memo.is_deleted = 0
EOT;

        if ($credit_memo_id) {
            $sql .= <<<EOT

AND credit_memo.id = ?
EOT;
            $binds[] = $credit_memo_id;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND credit_memo.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($billing_id) {
            $sql .= <<<EOT

AND credit_memo.billing_id = ?
EOT;
            $binds[] = $billing_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND credit_memo.status = ?
EOT;
            $binds[] = $status;
        }

        if ($billing_ids !== null && !$this->_append_id_filter($sql, $binds, 'credit_memo.billing_id', $billing_ids)) {
            return [];
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND credit_memo.date_issued BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql .= " ORDER BY credit_memo.id DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_details_for_billing_ids(array $billing_ids)
    {
        $billing_ids = array_values(array_unique(array_filter(array_map('intval', $billing_ids), static function ($id) {
            return $id > 0;
        })));
        if (!$billing_ids) {
            return [];
        }

        return $this->get_details(null, null, null, 'approved', null, null, null, null, $billing_ids);
    }

    public function get_for_update($credit_memo_id)
    {
        $sql = 'SELECT * FROM credit_memo WHERE id = ? AND is_deleted = 0 FOR UPDATE';
        try {
            $query = $this->db->query($sql, [$credit_memo_id]);
            return $query ? $query->getRow() : false;
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $error) {
            return false;
        }
    }

    public function update_pending_status($credit_memo_id, array $values): bool
    {
        if (!$this->update(['id' => $credit_memo_id, 'status' => 'pending', 'is_deleted' => 0], $values)) {
            return false;
        }

        return $this->db->affectedRows() === 1;
    }

    public function get_last_control_no($branch_code = null, $year = null)
    {
        if (!$branch_code || !$year) {
            return false;
        }

        $sql = <<<EOT
SELECT control_no
FROM credit_memo
WHERE is_deleted = 0
AND control_no LIKE ?
ORDER BY control_no DESC
LIMIT 1
EOT;

        $query = $this->db->query($sql, ['CM' . $branch_code . $year . '%']);
        return $query ? $query->getRowArray() : false;
    }
}

?>
