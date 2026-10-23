<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Deposit_detail extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'deposit_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'cash_advance_id',
        'amount',
    ];

    public function get_details($deposit_id = null, $cash_advance_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT deposit_detail.id,
    deposit_detail.deposit_id,
    deposit_detail.cash_advance_id,
    deposit_detail.amount,
    cash_advance.amount AS cash_advance_amount,
    cash_advance.balance AS cash_advance_balance
FROM deposit_detail
LEFT JOIN cash_advance ON cash_advance.id = deposit_detail.cash_advance_id AND cash_advance.is_deleted = 0
WHERE deposit_detail.is_deleted = 0
EOT;

        if ($deposit_id) {
            $sql .= <<<EOT

AND deposit_detail.deposit_id = ?
EOT;
            $binds[] = $deposit_id;
        }

        if ($cash_advance_id) {
            $sql .= <<<EOT

AND deposit_detail.cash_advance_id = ?
EOT;
            $binds[] = $cash_advance_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
