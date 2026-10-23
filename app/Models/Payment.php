<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Payment extends MYT_Model
{
    protected $table = 'payment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id',
        'charge_to',
        'facility_id',
        'voucher_id',
        'payment_date',
        'remarks',
        'withholding_tax',
        'withholding_percent',
        'discount',
        'subtotal',
        'grand_total',
        'change'
    ];

    public function get_details($transaction_id = null, $charge_to = null, $facility_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT payment.*
FROM payment
WHERE payment.is_deleted = 0
EOT;

        if ($transaction_id) {
            $sql .= <<<EOT

AND payment.transaction_id = ?
EOT;
            $binds[] = $transaction_id;
        }

        if ($charge_to) {
            $sql .= <<<EOT

AND payment.charge_to = ?
EOT;
            $binds[] = $charge_to;
        }

        if ($facility_id) {
            $sql .= <<<EOT

AND payment.facility_id = ?
EOT;
            $binds[] = $facility_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_payment($payment_id = null, $transaction_id = null, $date_from = null, $date_to = null, $invoice_status = null, $mobile_van_id = null, $mode_of_payment = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT payment.*,
    mobile_van.name AS mobile_van_name
FROM payment
LEFT JOIN transaction ON transaction.id = payment.transaction_id
LEFT JOIN patient ON patient.id = transaction.patient_id
LEFT JOIN {MASTER_DB}.mobile_van ON mobile_van.id = payment.mobile_van_id
    AND mobile_van.is_deleted = 0
WHERE payment.is_deleted = 0
AND transaction.is_deleted = 0
EOT;

        if ($transaction_id) {
            $sql .= <<<EOT

AND payment.transaction_id = ?
EOT;
            $binds[] = $transaction_id;
        }

        if ($payment_id) {
            $sql .= <<<EOT

AND payment.id = ?
EOT;
            $binds[] = $payment_id;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND payment.payment_date BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($invoice_status && $invoice_status == 'printed') {
            $sql .= <<<EOT

AND payment.invoice_status = 'printed'
EOT;
        } elseif ($invoice_status && $invoice_status == 'unprinted') {
            $sql .= <<<EOT

AND (payment.invoice_status IS NULL OR payment.invoice_status != 'printed')
EOT;
        }

        if ($mobile_van_id) {
            $sql .= <<<EOT

AND payment.mobile_van_id = ?
EOT;
            $binds[] = $mobile_van_id;
        }

        if ($mode_of_payment) {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1
    FROM payment_multiple_method pmm
    WHERE pmm.payment_id = payment.id
      AND pmm.is_deleted = 0
      AND pmm.type = ?
)
EOT;
            $binds[] = $mode_of_payment;
        }

        if ($term) {
            $sql .= <<<EOT

AND (
    CAST(AES_DECRYPT(patient.last_name, '$this->encryption_key') AS CHAR) LIKE ?
    OR CAST(AES_DECRYPT(patient.first_name, '$this->encryption_key') AS CHAR) LIKE ?
    OR CAST(AES_DECRYPT(patient.middle_name, '$this->encryption_key') AS CHAR) LIKE ?
    OR transaction.transaction_no LIKE ?
    OR TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$this->encryption_key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$this->encryption_key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$this->encryption_key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$this->encryption_key') AS CHAR), '')
    )) LIKE ?
)
EOT;
            $like_term = '%' . $term . '%';
            $binds[] = $like_term;
            $binds[] = $like_term;
            $binds[] = $like_term;
            $binds[] = $like_term;
            $binds[] = $like_term;
        }

        $sql .= " GROUP BY payment.id";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
    
    public function get_transaction($transaction_id = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT transaction.*,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM transaction
LEFT JOIN patient ON patient.id = transaction.patient_id
WHERE transaction.is_deleted = 0
EOT;

        if ($transaction_id) {
            $sql .= <<<EOT

AND transaction.id = ?
EOT;
            $binds[] = $transaction_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_booking_details($transaction_id = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT booking_details.id,
    booking_details.result_status,
    booking_details.result_approval,
    booking_details.amount,
    booking_details.type,
    booking_details.test_id,
    CASE 
        WHEN booking_details.type = 'lab' THEN lab_test.name
        WHEN booking_details.type = 'package' THEN package.name
    END AS type_name,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    booking_details.endorsement_id,
    booking_details.patient_id,
    booking.id AS booking_id,
    booking.company_id AS company_id
FROM booking_details
LEFT JOIN lab_test 
    ON booking_details.type = 'lab' 
    AND booking_details.test_id = lab_test.id
LEFT JOIN package 
    ON booking_details.type = 'package' 
    AND booking_details.test_id = package.id
LEFT JOIN booking ON booking.id = booking_details.booking_id
LEFT JOIN patient ON patient.id = booking_details.patient_id
WHERE booking_details.is_deleted = 0 
AND booking.is_deleted = 0
AND booking_details.is_billed_externally = 0
AND booking_details.charge_to IS NULL
EOT;

        if ($transaction_id) {
            $sql .= <<<EOT

AND booking.transaction_id = ?
EOT;
            $binds[] = $transaction_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_payment_multiple_method($payment_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT payment_multiple_method.*
FROM payment_multiple_method
WHERE payment_multiple_method.is_deleted = 0
EOT;

        if ($payment_id) {
            $sql .= <<<EOT

AND payment_multiple_method.payment_id = ?
EOT;
            $binds[] = $payment_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>