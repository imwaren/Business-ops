<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Send_out_payment_entry extends MYT_Model
{
    protected $table = 'send_out_payment_entry';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'send_out_payment_id',
        'send_out_entry_id',
        'amount'
    ];

    public function get_details($send_out_payment_entry_id = null, $send_out_payment_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT send_out_payment_entry.id, 
    send_out_payment_entry.send_out_payment_id, 
    send_out_payment_entry.send_out_entry_id, 
    send_out_payment_entry.amount
FROM send_out_payment_entry
LEFT JOIN send_out_payment ON send_out_payment.id = send_out_payment_entry.send_out_payment_id
WHERE send_out_payment_entry.is_deleted = 0
AND send_out_payment.is_deleted = 0
EOT;

        if ($send_out_payment_entry_id) {
            $sql .= <<<EOT

AND send_out_payment_entry.id = ?
EOT;
            $binds[] = $send_out_payment_entry_id;
        }

        if ($send_out_payment_id) {
            $sql .= <<<EOT

AND send_out_payment_entry.send_out_payment_id = ?
EOT;
            $binds[] = $send_out_payment_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>