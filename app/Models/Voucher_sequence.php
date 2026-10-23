<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Voucher_sequence extends MYT_Model
{
    protected $table = 'voucher_sequence';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'voucher_id',
        'voucher_code'
    ];

    public function get_sequences($voucher_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT voucher_sequence.id, 
    voucher_sequence.voucher_id, 
    voucher_sequence.voucher_code
FROM voucher_sequence
WHERE voucher_sequence.is_deleted = 0
EOT;

        if ($voucher_id) {
            $sql .= <<<EOT

AND voucher_sequence.voucher_id = ?
EOT;
            $binds[] = $voucher_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_range($voucher_id = null, $voucher_code_start = null, $voucher_code_end = null) {
        $binds = [];
        $sql = <<<EOT
SELECT voucher_sequence.id, 
    voucher_sequence.voucher_id,
    voucher_sequence.redeemed_by
FROM voucher_sequence
WHERE voucher_sequence.is_deleted = 0
AND voucher_sequence.redeemed_by IS NULL
EOT;

        if ($voucher_id) {
            $sql .= <<<EOT

AND voucher_sequence.voucher_id = ?
EOT;
            $binds[] = $voucher_id;
        }

        if ($voucher_code_start) {
            $sql .= <<<EOT

AND voucher_sequence.voucher_code > ?
EOT;
            $binds[] = $voucher_code_start;
        }

        if ($voucher_code_end) {
            $sql .= <<<EOT

AND voucher_sequence.voucher_code <= ?
EOT;
            $binds[] = $voucher_code_end;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>