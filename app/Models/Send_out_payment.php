<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Send_out_payment extends MYT_Model
{
    protected $table = 'send_out_payment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'facility_id',
        'mop',
        'date',
        'reference_no',
        'check_no',
        'check_date',
        'bank_name',
        'others',
        'paid_date',
        'remarks',
        'account_type_id',
        'bank_check',
        'wht_amount',
        'wht_percent',
        'wht_account',
        'grand_total',
    ];

    public function get_details($send_out_payment_id = null, $mop = null, $date = null, $facility_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT send_out_payment.*, account_type.name AS account_type_name
FROM send_out_payment
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = send_out_payment.account_type_id
WHERE send_out_payment.is_deleted = 0
EOT;

        if ($send_out_payment_id) {
            $sql .= <<<EOT

AND send_out_payment.id = ?
EOT;
            $binds[] = $send_out_payment_id;
        }

        if ($mop) {
            $sql .= <<<EOT

AND send_out_payment.mop = ?
EOT;
            $binds[] = $mop;
        }

        if ($date) {
            $sql .= <<<EOT

AND send_out_payment.date = ?
EOT;
            $binds[] = $date;
        }

        if ($facility_id) {
            $sql .= <<<EOT

AND send_out_payment.facility_id = ?
EOT;
            $binds[] = $facility_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>