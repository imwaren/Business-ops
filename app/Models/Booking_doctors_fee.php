<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Booking_doctors_fee extends MYT_Model
{
    protected $table = 'booking_doctors_fee';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'doc_type',
    ];

    public function get_doctors_fee($transaction_from = null, $transaction_to = null, $doctor_id = null, $type = null, $branch_id = null, $term = null, $page = null, $data_size = null) {
        // ROUND(booking_doctors_fee.lab_test_amount * (booking_doctors_fee.pf_share_percent/100), 2) AS pf_share_amount,
        $binds = [];
        $key = ENCRYPTION_KEY;
        $sql = <<<EOT
SELECT booking_doctors_fee.*,
    COALESCE(booking_doctors_fee.lab_test_amount - booking_doctors_fee.discount_amount, 0) AS net_amount,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    COALESCE(
        bd.finalized_on,
        bpd.finalized_on,
        booking_doctors_fee.added_on
    ) AS finalized_date,
    CASE 
        WHEN booking.type = 'walk in' THEN 'walk-in'
        WHEN booking.hmo_id IS NOT NULL THEN 'hmo'
        ELSE 'clinic'
    END AS transaction_type,
    CASE 
        WHEN booking_doctors_fee.doc_type = 'consultation' THEN 'Consultation'
        ELSE booking_doctors_fee.lab_test_name
    END AS lab_test_name,
    CONCAT_WS(' ', doctor.first_name, doctor.middle_name, doctor.last_name) AS doctor_name,
    branch.name AS branch_name
FROM booking_doctors_fee
LEFT JOIN {MASTER_DB}.patient patient ON patient.id = booking_doctors_fee.master_id
LEFT JOIN {MASTER_DB}.branch branch ON branch.id = booking_doctors_fee.branch_id
LEFT JOIN booking_details bd
    ON bd.id = booking_doctors_fee.source_id
    AND booking_doctors_fee.source_table = 'booking_details'
LEFT JOIN booking_package_details bpd
    ON bpd.id = booking_doctors_fee.source_id
    AND booking_doctors_fee.source_table = 'booking_package_details'
LEFT JOIN transaction ON transaction.id = booking_doctors_fee.transaction_id
LEFT JOIN booking ON booking.transaction_id = transaction.id
LEFT JOIN doctor ON doctor.id = booking_doctors_fee.doctor_id
WHERE booking_doctors_fee.is_deleted = 0
AND IFNULL(booking_doctors_fee.pf_share_amount, 0) != 0
EOT;

        if ($transaction_from && $transaction_to) {
            $sql .= <<<EOT

AND DATE(booking_doctors_fee.added_on) BETWEEN ? AND ?
EOT;
            $binds[] = $transaction_from;
            $binds[] = $transaction_to;
        }

        if ($doctor_id) {
            $sql .= <<<EOT

AND booking_doctors_fee.doctor_id = ?
EOT;
            $binds[] = $doctor_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND booking_doctors_fee.doc_type = ?
EOT;
            $binds[] = $type;
        }

//         if ($branch_id) {
//             $sql .= <<<EOT

// AND booking_doctors_fee.branch_id = ?
// EOT;
//             $binds[] = $branch_id;
//         }

        if ($term) {
            $sql .= <<<EOT

AND (
        TRIM(CONCAT(
            CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
            CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
            IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
            IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
        )) LIKE ?
        OR 
        CONCAT_WS(' ', doctor.first_name, doctor.middle_name, doctor.last_name) LIKE ?
    )
EOT;
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        //GROUP BY booking_doctors_fee.id
        $sql .= " GROUP BY booking_doctors_fee.id";

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_doctors($transaction_from = null, $transaction_to = null, $doctor_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT booking_doctors_fee.doctor_id
FROM booking_doctors_fee
WHERE booking_doctors_fee.is_deleted = 0
EOT;

        if ($transaction_from && $transaction_to) {
            $sql .= <<<EOT

AND DATE(booking_doctors_fee.added_on) BETWEEN ? AND ?
EOT;
            $binds[] = $transaction_from;
            $binds[] = $transaction_to;
        }

        if ($doctor_id) {
            $sql .= <<<EOT

AND booking_doctors_fee.doctor_id = ?
EOT;
            $binds[] = $doctor_id;
        }

        // GROUP BY doctor_id 
        $sql .= " GROUP BY booking_doctors_fee.doctor_id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_doctors_fee_transaction($transaction_from = null, $transaction_to = null, $doctor_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT booking_doctors_fee.doctor_id, 
    booking_doctors_fee.doc_type, 
    COUNT(booking_doctors_fee.id) AS total,
    SUM(booking_doctors_fee.pf_share_amount) as total_amount
FROM booking_doctors_fee
WHERE booking_doctors_fee.is_deleted = 0
EOT;

        if ($transaction_from && $transaction_to) {
            $sql .= <<<EOT

AND DATE(booking_doctors_fee.added_on) BETWEEN ? AND ?
EOT;
            $binds[] = $transaction_from;
            $binds[] = $transaction_to;
        }

        if ($doctor_id) {
            $sql .= <<<EOT

AND booking_doctors_fee.doctor_id = ?
EOT;
            $binds[] = $doctor_id;
        }

        // GROUP BY doctor_id and doc_type to get total transactions per doctor
        $sql .= " GROUP BY booking_doctors_fee.doctor_id, booking_doctors_fee.doc_type";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>