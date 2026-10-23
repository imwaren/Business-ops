<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Refund extends MYT_Model
{
    protected $table = 'refund';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id',
        'branch_id',
        'patient_id',
        'payment_id',
        'new_payment_id',
        'amount',
        'remarks',
        'status',
        'approved_by',
        'approved_on',
        'disapproved_by',
        'disapproved_on',
    ];

    public function get_details(
        $refund_id = null,
        $branch_id = null,
        $transaction_id = null,
        $payment_id = null,
        $date_from = null,
        $date_to = null,
        $term = null,
        $status = null,
        $page = null,
        $data_size = null
    ) {
        $key = $this->encryption_key;

        $binds = [];
        $sql = <<<EOT
SELECT refund.*,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    CONCAT_WS(' ', approver.first_name, approver.middle_name, approver.last_name) AS approved_by_name,
    CONCAT_WS(' ', disapprover.first_name, disapprover.middle_name, disapprover.last_name) AS disapproved_by_name
FROM refund
LEFT JOIN {MASTER_DB}.patient AS patient ON patient.id = refund.patient_id
LEFT JOIN {MASTER_DB}.user AS approver ON approver.id = refund.approved_by
LEFT JOIN {MASTER_DB}.user AS disapprover ON disapprover.id = refund.disapproved_by
WHERE refund.is_deleted = 0
EOT;

        if ($refund_id) {
            $sql .= <<<EOT

AND refund.id = ?
EOT;
            $binds[] = $refund_id;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND refund.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($transaction_id) {
            $sql .= <<<EOT

AND refund.transaction_id = ?
EOT;
            $binds[] = $transaction_id;
        }

        if ($payment_id) {
            $sql .= <<<EOT

AND refund.payment_id = ?
EOT;
            $binds[] = $payment_id;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND DATE(refund.added_on) >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND DATE(refund.added_on) <= ?
EOT;
            $binds[] = $date_to;
        }

        if ($term) {
            $sql .= <<<EOT

AND (
    refund.remarks LIKE ?
    OR refund.id LIKE ?
)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        if ($status) {
            $sql .= <<<EOT

AND refund.status = ?
EOT;
            $binds[] = $status;
        }

        $sql .= ' ORDER BY refund.id DESC';

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }
}
