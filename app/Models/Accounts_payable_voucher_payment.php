<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Accounts_payable_voucher_payment extends MYT_Model
{
    protected $table = 'accounts_payable_voucher_payment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'account_type_id',
        'mop',
        'bank_id',
        'check_date',
        'check_no',
        'remarks',
        'grand_total'
    ];

    public function get_details($accounts_payable_voucher_payment_id = null, $mop = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT accounts_payable_voucher_payment.*,
    account_type.name AS account_type_name,
    bank.name AS bank_name
FROM accounts_payable_voucher_payment
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = accounts_payable_voucher_payment.account_type_id
LEFT JOIN bank ON bank.id = accounts_payable_voucher_payment.bank_id
WHERE accounts_payable_voucher_payment.is_deleted = 0
EOT;

        if ($accounts_payable_voucher_payment_id) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment.id = ?
EOT;
            $binds[] = $accounts_payable_voucher_payment_id;
        }

        if ($mop) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment.mop = ?
EOT;
            $binds[] = $type;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>