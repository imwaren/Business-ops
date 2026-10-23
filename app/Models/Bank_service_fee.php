<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Bank_service_fee extends MYT_Model
{
    protected $table = 'bank_service_fee';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'bank_id',
        'percentage',
        'is_deleted',
    ];

    public function get_details($bank_service_fee_id = null, $bank_id = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT bank_service_fee.*,
    bank.name AS bank_name,
    bank.account_type_ids
FROM bank_service_fee
LEFT JOIN bank ON bank.id = bank_service_fee.bank_id
    AND bank.is_deleted = 0
WHERE bank_service_fee.is_deleted = 0
EOT;

        if ($bank_service_fee_id) {
            $sql .= <<<EOT

AND bank_service_fee.id = ?
EOT;
            $binds[] = $bank_service_fee_id;
        }

        if ($bank_id) {
            $sql .= <<<EOT

AND bank_service_fee.bank_id = ?
EOT;
            $binds[] = $bank_id;
        }

        $sql .= " ORDER BY bank_service_fee.id DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function bank_exists($bank_id)
    {
        $query = $this->db->query(
            'SELECT id FROM bank WHERE id = ? AND is_deleted = 0 LIMIT 1',
            [$bank_id]
        );
        $row = $query ? $query->getRow() : null;

        return (bool) $row;
    }
}

?>
