<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Deposit extends MYT_Model
{
    protected $table = 'deposit';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'cost_center_id',
        'account_type_id',
        'bank_id',
        'total_amount',
    ];

    public function get_details($deposit_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT deposit.*,
    bank.name AS bank_name,
    account_type.name AS account_type_name
FROM deposit
LEFT JOIN bank ON bank.id = deposit.bank_id AND bank.is_deleted = 0
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = deposit.account_type_id AND account_type.is_deleted = 0
WHERE deposit.is_deleted = 0
EOT;

        if ($deposit_id) {
            $sql .= <<<EOT

AND deposit.id = ?
EOT;
            $binds[] = $deposit_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
