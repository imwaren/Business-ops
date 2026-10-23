<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Booking extends MYT_Model
{
    protected $table = 'booking';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id',
        'status',
        'billing_id',
    ];

    public function get_details($booking_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT booking.*
FROM booking
WHERE booking.is_deleted = 0
EOT;

        if ($booking_id) {
            $sql .= <<<EOT

AND booking.id = ?
EOT;
            $binds[] = $booking_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_transaction($company_id = null, $date_from = null, $date_to = null, $charge_to = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT transc.id AS transaction_id, 
    booking.id AS booking_id, 
    transc.transaction_date, 
    booking.company_id, 
    booking_details.charge_to,
    'dsmr' AS billing_channel,
    booking.status, 
    transc.transaction_no,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    booking_details.endorsement_id,
    SUM(booking_details.amount) AS total_amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND transc.id IS NOT NULL
AND (booking.company_id IS NOT NULL OR booking.company_id != 0)
AND booking.payment_status = 'unpaid'
AND booking_details.is_deleted = 0
AND booking_details.type != 'wallet'
AND NOT EXISTS (
    SELECT 1
    FROM booking_details bd_wallet
    WHERE bd_wallet.booking_id = booking.id
    AND bd_wallet.is_deleted = 0
    AND bd_wallet.type = 'wallet'
)
AND booking_details.is_billed_externally = 1
AND NOT EXISTS (
    SELECT 1 
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing.charge_to = booking_details.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND booking.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($charge_to) {
            $sql .= <<<EOT

AND booking_details.charge_to = ?
EOT;
            $binds[] = $charge_to;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND transc.transaction_date >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND transc.transaction_date <= ?
EOT;
            $binds[] = $date_to;
        }

        $sql .= <<<EOT

GROUP BY booking.id
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_consultation_transaction($company_id = null, $date_from = null, $date_to = null, $charge_to = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT transc.id AS transaction_id, 
    booking.id AS booking_id, 
    transc.transaction_date, 
    booking.company_id, 
    booking.charge_to,
    'consultation' AS billing_channel,
    booking.status, 
    transc.transaction_no,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    transc.endorsement_id,
    booking.grand_total AS total_amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND transc.id IS NOT NULL
AND (booking.company_id IS NOT NULL OR booking.company_id != 0)
AND booking.payment_status = 'unpaid'
AND booking.transaction_type = 'consultation'
AND (booking.charge_to IS NOT NULL OR booking.charge_to != 0)
AND NOT EXISTS (
    SELECT 1 
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing.charge_to = booking.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND booking.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($charge_to) {
            $sql .= <<<EOT

AND booking.charge_to = ?
EOT;
            $binds[] = $charge_to;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND transc.transaction_date >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND transc.transaction_date <= ?
EOT;
            $binds[] = $date_to;
        }

        $sql .= <<<EOT

GROUP BY booking.id
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_copay_transaction($company_id = null, $date_from = null, $date_to = null, $charge_to = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT transc.id AS transaction_id, 
    booking.id AS booking_id, 
    transc.transaction_date, 
    booking.company_id, 
    transc.hmo_id AS charge_to,
    'copay' AS billing_channel,
    booking.status, 
    transc.transaction_no,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    booking_details.endorsement_id,
    SUM(transc.copay) AS total_amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND transc.id IS NOT NULL
AND (booking.company_id IS NOT NULL OR booking.company_id != 0)
AND booking.payment_status = 'unpaid'
AND booking_details.is_deleted = 0
AND booking_details.is_billed_externally = 1
AND transc.copay IS NOT NULL
AND transc.copay != 0
AND transc.copay > 0
AND transc.hmo_id IS NOT NULL
AND NOT EXISTS (
    SELECT 1 
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing.charge_to = booking_details.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND booking.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($charge_to) {
            $sql .= <<<EOT

AND transc.hmo_id = ?
EOT;
            $binds[] = $charge_to;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND transc.transaction_date >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND transc.transaction_date <= ?
EOT;
            $binds[] = $date_to;
        }

        $sql .= <<<EOT

GROUP BY booking.id
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_all_transaction($company_id = null, $date_from = null, $date_to = null, $charge_to = null, $page = null, $data_size = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT transc.id AS transaction_id, 
    booking.id AS booking_id, 
    transc.transaction_date, 
    booking.company_id, 
    booking.status, 
    transc.transaction_no,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    booking_details.endorsement_id,
    SUM(booking_details.amount) AS total_amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND transc.id IS NOT NULL
AND (booking.company_id IS NOT NULL OR booking.company_id != 0)
AND booking.payment_status = 'unpaid'
AND booking_details.is_deleted = 0
AND booking_details.is_billed_externally = 1
AND booking_details.hmo_id IS NULL
AND NOT EXISTS (
    SELECT 1 
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing.charge_to = booking_details.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND booking.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($charge_to) {
            $sql .= <<<EOT

AND booking_details.charge_to = ?
EOT;
            $binds[] = $charge_to;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND transc.transaction_date >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND transc.transaction_date <= ?
EOT;
            $binds[] = $date_to;
        }

        $sql .= <<<EOT

GROUP BY booking.id
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_for_sales($endorsement_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT SUM(booking_details.amount) AS booking_total_sales
FROM booking_details
WHERE booking_details.is_deleted = 0
EOT;

        if ($endorsement_id) {
            // Convert comma separated string into array
            $endorsement_ids = array_map('trim', explode(',', $endorsement_id));
        
            // Create placeholders (?, ?, ?)
            $placeholders = implode(',', array_fill(0, count($endorsement_ids), '?'));
        
            $sql .= <<<EOT
        
AND booking_details.endorsement_id IN ($placeholders)
EOT;
            // Add each type to bind array
            foreach ($endorsement_ids as $t) {
                $binds[] = $t;
            }
        }

        $sql .= <<<EOT

GROUP BY booking_details.endorsement_id
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_transaction_sales($patient_id = null, $billing_id = null, $is_billed = null, $is_paid = null, $date_from = null, $date_to = null, $page = null, $data_size = null) {
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
-- AND transaction.is_paid = 1
AND transaction.is_worklist = 0
AND transaction.grand_total > 0
AND (
    EXISTS (
        SELECT 1
        FROM booking b
        INNER JOIN booking_details bd ON bd.booking_id = b.id
        WHERE b.transaction_id = transaction.id
        AND b.is_deleted = 0
        AND bd.is_deleted = 0
        AND bd.type != 'wallet'
        AND IFNULL(b.package_id, 0) = 0
    )
    OR EXISTS (
        SELECT 1
        FROM booking b
        WHERE b.transaction_id = transaction.id
        AND b.is_deleted = 0
        AND IFNULL(b.package_id, 0) = 0
        AND b.transaction_type = 'consultation'
        AND NOT EXISTS (
            SELECT 1
            FROM booking_details bd_wallet
            WHERE bd_wallet.booking_id = b.id
            AND bd_wallet.is_deleted = 0
            AND bd_wallet.type = 'wallet'
        )
    )
)
EOT;

        if ($patient_id) {
            $sql .= <<<EOT

AND transaction.patient_id = ?
EOT;
            $binds[] = $patient_id;
        }

        if ($billing_id) {
            $sql .= <<<EOT

AND transaction.billing_id = ?
EOT;
            $binds[] = $billing_id;
        }

        if ($is_billed !== null) {
            $sql .= <<<EOT

AND transaction.is_billed = ?
EOT;
            $binds[] = $is_billed;
        }

        if ($is_paid !== null) {
            $sql .= <<<EOT

AND transaction.is_paid = ?
EOT;
            $binds[] = $is_paid;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND transaction.transaction_date >= ?
AND transaction.transaction_date <= ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Booking line items for sales report, keyed by transaction_id.
     * Non-package rows come from booking_details; package rows expand via booking_package_details.
     *
     * @param array<int|string> $transaction_ids
     * @return array<int, array<int, array{type: mixed, test_id: mixed, test_name: mixed, group_id: mixed, group_name: mixed}>>
     */
    public function get_transaction_sales_details(array $transaction_ids)
    {
        $transaction_ids = array_values(array_unique(array_filter(array_map('intval', $transaction_ids))));
        if (empty($transaction_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
        $sql          = <<<EOT
SELECT booking.transaction_id,
    '1' AS union_level,
    booking_details.booking_id,
    booking_details.type,
    booking_details.test_id,
    lab_test.name AS test_name,
    lab_test.group_id AS group_id,
    `group`.name AS group_name,
    CASE
        WHEN booking_details.is_billed_externally = 1
         AND booking_details.charge_to IS NOT NULL
         AND booking_details.charge_to != 3210
        THEN COALESCE(endorsement_service.pro_rate_price, booking_details.amount)
        ELSE booking_details.amount
    END AS amount,
    booking_details.is_billed_externally,
    booking_details.charge_to
FROM booking_details
LEFT JOIN patient ON patient.id = booking_details.patient_id
INNER JOIN booking ON booking.id = booking_details.booking_id
LEFT JOIN lab_test
    ON booking_details.type = 'lab'
    AND booking_details.test_id = lab_test.id
LEFT JOIN {MASTER_DB}.`group` AS `group`
    ON booking_details.type = 'lab'
    AND `group`.id = lab_test.group_id
LEFT JOIN {MASTER_DB}.endorsement_service
    ON endorsement_service.endorsement_id = booking_details.endorsement_id
    AND endorsement_service.is_deleted = 0
    AND endorsement_service.type_id = booking_details.test_id
    AND endorsement_service.type = CASE
        WHEN booking_details.type = 'lab' THEN 'lab_test'
        ELSE booking_details.type
    END
    AND EXISTS (
        SELECT 1
        FROM {MASTER_DB}.endorsement_patient ep
        WHERE ep.endorsement_service_id = endorsement_service.id
          AND ep.endorsement_id = endorsement_service.endorsement_id
          AND ep.patient_id = patient.master_id
    )
WHERE booking_details.is_deleted = 0
AND booking.is_deleted = 0
AND IFNULL(booking.package_id, 0) = 0
AND booking_details.type != 'package'
AND booking_details.type != 'wallet'
AND NOT EXISTS (
    SELECT 1
    FROM booking_details bd_wallet
    WHERE bd_wallet.booking_id = booking.id
    AND bd_wallet.is_deleted = 0
    AND bd_wallet.type = 'wallet'
)
AND booking.transaction_id IN ($placeholders)

UNION ALL

SELECT booking.transaction_id,
    '2' AS union_level,
    booking_details.booking_id,
    booking_details.type,
    booking_details.test_id,
    package.name AS test_name,
    null AS group_id,
    null AS group_name,
    booking_details.amount AS amount,
    booking_details.is_billed_externally,
    booking_details.charge_to
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id
LEFT JOIN package
    ON booking_details.type = 'package'
    AND booking_details.test_id = package.id
WHERE booking_details.is_deleted = 0
AND booking.is_deleted = 0
AND IFNULL(booking.package_id, 0) = 0
AND booking_details.type = 'package'
AND IFNULL(booking_details.is_billed_externally, 0) = 0
AND booking_details.charge_to IS NULL
AND NOT EXISTS (
    SELECT 1
    FROM booking_details bd_wallet
    WHERE bd_wallet.booking_id = booking.id
    AND bd_wallet.is_deleted = 0
    AND bd_wallet.type = 'wallet'
)
AND booking.transaction_id IN ($placeholders)

UNION ALL

SELECT booking.transaction_id,
    '3' AS union_level,
    booking_details.booking_id,
    booking_details.type,
    booking_package_details.test_id,
    lab_test.name AS test_name,
    lab_test.group_id AS group_id,
    `group`.name AS group_name,
    CASE
        WHEN booking_details.is_billed_externally = 1
         AND booking_details.charge_to IS NOT NULL
         AND booking_details.charge_to != 3210
        THEN COALESCE(endorsement_service_package_detail.pro_rate_price, lab_test.price)
        ELSE lab_test.price
    END AS amount,
    booking_details.is_billed_externally,
    booking_details.charge_to
FROM booking_package_details
INNER JOIN booking_details
    ON booking_package_details.booking_detail_id = booking_details.id
    AND booking_details.is_deleted = 0
INNER JOIN booking ON booking.id = booking_details.booking_id
LEFT JOIN patient ON patient.id = booking_details.patient_id
LEFT JOIN lab_test ON booking_package_details.test_id = lab_test.id
LEFT JOIN {MASTER_DB}.`group` AS `group`
    ON `group`.id = lab_test.group_id
LEFT JOIN {MASTER_DB}.endorsement_service
    ON endorsement_service.endorsement_id = booking_details.endorsement_id
    AND endorsement_service.is_deleted = 0
    AND endorsement_service.type_id = booking_details.test_id
    AND endorsement_service.type = CASE
        WHEN booking_details.type = 'lab' THEN 'lab_test'
        ELSE booking_details.type
    END
    AND EXISTS (
        SELECT 1
        FROM {MASTER_DB}.endorsement_patient ep
        WHERE ep.endorsement_service_id = endorsement_service.id
          AND ep.endorsement_id = endorsement_service.endorsement_id
          AND ep.patient_id = patient.master_id
    )
LEFT JOIN {MASTER_DB}.endorsement_service_package_detail
    ON endorsement_service_package_detail.endorsement_service_id = endorsement_service.id
    AND endorsement_service_package_detail.lab_test_id = booking_package_details.test_id
    AND endorsement_service_package_detail.is_deleted = 0
WHERE booking_package_details.is_deleted = 0
AND booking.is_deleted = 0
AND IFNULL(booking.package_id, 0) = 0
AND booking_details.type = 'package'
AND (booking_details.is_billed_externally = 1 OR booking_details.charge_to IS NOT NULL)
AND NOT EXISTS (
    SELECT 1
    FROM booking_details bd_wallet
    WHERE bd_wallet.booking_id = booking.id
    AND bd_wallet.is_deleted = 0
    AND bd_wallet.type = 'wallet'
)
AND booking.transaction_id IN ($placeholders)

UNION ALL

SELECT booking.transaction_id,
    '4' AS union_level,
    booking.id AS booking_id,
    'consultation' AS type,
    null AS test_id,
    'Consultation' AS test_name,
    null AS group_id,
    null AS group_name,
    booking.grand_total AS amount,
    null AS is_billed_externally,
    null AS charge_to
FROM booking
WHERE booking.is_deleted = 0
AND IFNULL(booking.package_id, 0) = 0
AND booking.transaction_type = 'consultation'
AND NOT EXISTS (
    SELECT 1
    FROM booking_details bd_wallet
    WHERE bd_wallet.booking_id = booking.id
    AND bd_wallet.is_deleted = 0
    AND bd_wallet.type = 'wallet'
)
AND booking.transaction_id IN ($placeholders)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $binds = array_merge($transaction_ids, $transaction_ids, $transaction_ids, $transaction_ids);
        $query = $this->db->query($sql, $binds);
        $rows  = $query ? $query->getResultArray() : [];

        $by_transaction = [];
        foreach ($rows as $row) {
            $transaction_id = (int) ($row['transaction_id'] ?? 0);
            if ($transaction_id <= 0) {
                continue;
            }

            $by_transaction[$transaction_id][] = [
                'union_level'          => $row['union_level'] ?? null,
                'transaction_id'       => $row['transaction_id'] ?? null,
                'booking_id'           => $row['booking_id'] ?? null,
                'type'                 => $row['type'] ?? null,
                'test_id'              => $row['test_id'] ?? null,
                'test_name'            => $row['test_name'] ?? null,
                'group_id'             => $row['group_id'] ?? null,
                'group_name'           => $row['group_name'] ?? null,
                'amount'               => isset($row['amount']) ? round((float) $row['amount'], 2) : null,
                'is_billed_externally' => isset($row['is_billed_externally']) && $row['is_billed_externally'] !== null
                    ? (int) $row['is_billed_externally']
                    : null,
                'charge_to'            => isset($row['charge_to']) && $row['charge_to'] !== null && $row['charge_to'] !== ''
                    ? (int) $row['charge_to']
                    : null,
            ];
        }

        return $by_transaction;
    }

    /**
     * Mobile-only van transactions. Requires booking_details.mobile_van_id /
     * booking_package_details.mobile_van_id (present only on the mobile diagnostic DB).
     */
    public function get_mobile_van_transaction($mobile_van_id = null, $supplier_id = null, $patient_id = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null)
    {
        $key   = $this->encryption_key;
        $binds = [];
        $sql   = <<<EOT
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
AND IFNULL(transaction.is_worklist, 0) = 0
AND (
    EXISTS (
        SELECT 1
        FROM booking_details bd
        INNER JOIN booking b ON b.id = bd.booking_id AND b.is_deleted = 0
        LEFT JOIN {MASTER_DB}.mobile_van mv ON mv.id = bd.mobile_van_id AND mv.is_deleted = 0
        WHERE b.transaction_id = transaction.id
        AND bd.is_deleted = 0
        AND bd.type != 'package'
        AND bd.mobile_van_id IS NOT NULL
EOT;

        if ($mobile_van_id) {
            $sql .= <<<EOT

        AND bd.mobile_van_id = ?
EOT;
            $binds[] = $mobile_van_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

        AND mv.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= <<<EOT

    )
    OR EXISTS (
        SELECT 1
        FROM booking_package_details bpd
        INNER JOIN booking_details bd ON bd.id = bpd.booking_detail_id AND bd.is_deleted = 0
        INNER JOIN booking b ON b.id = bd.booking_id AND b.is_deleted = 0
        LEFT JOIN {MASTER_DB}.mobile_van mv ON mv.id = bpd.mobile_van_id AND mv.is_deleted = 0
        WHERE b.transaction_id = transaction.id
        AND bpd.is_deleted = 0
        AND bd.type = 'package'
        AND bpd.mobile_van_id IS NOT NULL
EOT;

        if ($mobile_van_id) {
            $sql .= <<<EOT

        AND bpd.mobile_van_id = ?
EOT;
            $binds[] = $mobile_van_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

        AND mv.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= <<<EOT

    )
)
EOT;

        if ($patient_id) {
            $sql .= <<<EOT

AND transaction.patient_id = ?
EOT;
            $binds[] = $patient_id;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND DATE(transaction.transaction_date) BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($term) {
            $sql .= <<<EOT

AND (
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) LIKE ?
    OR EXISTS (
        SELECT 1
        FROM booking_details bd
        INNER JOIN booking b ON b.id = bd.booking_id AND b.is_deleted = 0
        LEFT JOIN {MASTER_DB}.mobile_van mv ON mv.id = bd.mobile_van_id AND mv.is_deleted = 0
        LEFT JOIN {MASTER_DB}.supplier s ON s.id = mv.supplier_id AND s.is_deleted = 0
        WHERE b.transaction_id = transaction.id
        AND bd.is_deleted = 0
        AND bd.type != 'package'
        AND bd.mobile_van_id IS NOT NULL
        AND (mv.name LIKE ? OR s.name LIKE ?)
    )
    OR EXISTS (
        SELECT 1
        FROM booking_package_details bpd
        INNER JOIN booking_details bd ON bd.id = bpd.booking_detail_id AND bd.is_deleted = 0
        INNER JOIN booking b ON b.id = bd.booking_id AND b.is_deleted = 0
        LEFT JOIN {MASTER_DB}.mobile_van mv ON mv.id = bpd.mobile_van_id AND mv.is_deleted = 0
        LEFT JOIN {MASTER_DB}.supplier s ON s.id = mv.supplier_id AND s.is_deleted = 0
        WHERE b.transaction_id = transaction.id
        AND bpd.is_deleted = 0
        AND bd.type = 'package'
        AND bpd.mobile_van_id IS NOT NULL
        AND (mv.name LIKE ? OR s.name LIKE ?)
    )
)
EOT;
            $like    = "%$term%";
            $binds[] = $like;
            $binds[] = $like;
            $binds[] = $like;
            $binds[] = $like;
            $binds[] = $like;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Van-scoped line items for mobile transactions, keyed by transaction_id.
     * Non-package rows come from booking_details; package rows expand via booking_package_details.
     *
     * @param array<int|string> $transaction_ids
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function get_mobile_van_transaction_details(array $transaction_ids, $mobile_van_id = null, $supplier_id = null)
    {
        $transaction_ids = array_values(array_unique(array_filter(array_map('intval', $transaction_ids))));
        if (empty($transaction_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
        $sql          = <<<EOT
SELECT booking.transaction_id,
    booking.id AS booking_id,
    booking_details.id AS booking_details_id,
    NULL AS booking_package_details_id,
    booking_details.type,
    booking_details.test_id,
    CASE
        WHEN booking_details.type = 'lab' THEN lab_test.name
        WHEN booking_details.type = 'wallet' THEN 'Medical Services'
        ELSE booking_details.type
    END AS test_name,
    booking_details.amount,
    booking_details.status,
    booking_details.mobile_van_id,
    mobile_van.name AS mobile_van_name,
    mobile_van.supplier_id,
    supplier.name AS supplier_name
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id
LEFT JOIN lab_test
    ON booking_details.type = 'lab'
    AND booking_details.test_id = lab_test.id
LEFT JOIN {MASTER_DB}.mobile_van
    ON mobile_van.id = booking_details.mobile_van_id
    AND mobile_van.is_deleted = 0
LEFT JOIN {MASTER_DB}.supplier
    ON supplier.id = mobile_van.supplier_id
    AND supplier.is_deleted = 0
WHERE booking_details.is_deleted = 0
AND booking.is_deleted = 0
AND booking_details.type != 'package'
AND booking.transaction_id IN ($placeholders)
EOT;

        $binds = $transaction_ids;

        if ($mobile_van_id) {
            $sql .= <<<EOT

AND booking_details.mobile_van_id = ?
EOT;
            $binds[] = $mobile_van_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND mobile_van.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= <<<EOT

UNION ALL

SELECT booking.transaction_id,
    booking.id AS booking_id,
    booking_details.id AS booking_details_id,
    booking_package_details.id AS booking_package_details_id,
    booking_details.type,
    booking_package_details.test_id,
    lab_test.name AS test_name,
    lab_test.price AS amount,
    booking_package_details.status,
    booking_package_details.mobile_van_id,
    mobile_van.name AS mobile_van_name,
    mobile_van.supplier_id,
    supplier.name AS supplier_name
FROM booking_package_details
INNER JOIN booking_details
    ON booking_package_details.booking_detail_id = booking_details.id
    AND booking_details.is_deleted = 0
INNER JOIN booking ON booking.id = booking_details.booking_id
LEFT JOIN lab_test ON booking_package_details.test_id = lab_test.id
LEFT JOIN {MASTER_DB}.mobile_van
    ON mobile_van.id = booking_package_details.mobile_van_id
    AND mobile_van.is_deleted = 0
LEFT JOIN {MASTER_DB}.supplier
    ON supplier.id = mobile_van.supplier_id
    AND supplier.is_deleted = 0
WHERE booking_package_details.is_deleted = 0
AND booking.is_deleted = 0
AND booking_details.type = 'package'
AND booking.transaction_id IN ($placeholders)
EOT;

        $binds = array_merge($binds, $transaction_ids);

        if ($mobile_van_id) {
            $sql .= <<<EOT

AND booking_package_details.mobile_van_id = ?
EOT;
            $binds[] = $mobile_van_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND mobile_van.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        $rows  = $query ? $query->getResultArray() : [];

        $by_transaction = [];
        foreach ($rows as $row) {
            $transaction_id = (int) ($row['transaction_id'] ?? 0);
            if ($transaction_id <= 0) {
                continue;
            }

            $by_transaction[$transaction_id][] = [
                'booking_id'                 => $row['booking_id'] ?? null,
                'booking_details_id'         => $row['booking_details_id'] ?? null,
                'booking_package_details_id' => $row['booking_package_details_id'] ?? null,
                'type'                       => $row['type'] ?? null,
                'test_id'                    => $row['test_id'] ?? null,
                'test_name'                  => $row['test_name'] ?? null,
                'amount'                     => isset($row['amount']) ? round((float) $row['amount'], 2) : null,
                'status'                     => $row['status'] ?? null,
                'mobile_van_id'              => $row['mobile_van_id'] ?? null,
                'mobile_van_name'            => $row['mobile_van_name'] ?? null,
                'supplier_id'                => $row['supplier_id'] ?? null,
                'supplier_name'              => $row['supplier_name'] ?? null,
            ];
        }

        return $by_transaction;
    }

    public function get_send_out_transaction($facility_id = null, $date_from = null, $date_to = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT transc.id AS transaction_id, 
    booking.id AS booking_id, 
    transc.transaction_date, 
    booking.status, 
    transc.transaction_no,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    booking_details.endorsement_id,
    SUM(booking_details.amount) AS total_amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
LEFT JOIN booking_package_details ON booking_package_details.booking_detail_id = booking_details.id AND booking_package_details.is_deleted = 0
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND transc.id IS NOT NULL
AND (
        (booking_details.performed_at IS NOT NULL OR booking_details.performed_at != 0) 
        OR 
        (booking_package_details.performed_at IS NOT NULL OR booking_package_details.performed_at != 0)
    )
AND booking_details.is_deleted = 0
AND NOT EXISTS (
    SELECT 1 
    FROM send_out_entry
    LEFT JOIN send_out ON send_out_entry.send_out_id = send_out.id
    WHERE send_out_entry.is_deleted = 0
    AND (send_out.facility_id = booking_details.performed_at OR send_out.facility_id = booking_package_details.performed_at)
    AND send_out.is_deleted = 0
    AND send_out.status != 'cancelled'
)
EOT;

        if ($facility_id) {
            $sql .= <<<EOT

AND (booking_details.performed_at = ? OR booking_package_details.performed_at = ?)
EOT;
            $binds[] = $facility_id;
            $binds[] = $facility_id;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND transc.transaction_date >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND transc.transaction_date <= ?
EOT;
            $binds[] = $date_to;
        }

        $sql .= <<<EOT

GROUP BY booking.id
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_all_send_out_transaction($company_id = null, $date_from = null, $date_to = null, $charge_to = null, $page = null, $data_size = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT transc.id AS transaction_id, 
    booking.id AS booking_id, 
    transc.transaction_date, 
    booking.status, 
    transc.transaction_no,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    booking_details.endorsement_id,
    SUM(booking_details.amount) AS total_amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
LEFT JOIN booking_package_details ON booking_package_details.booking_detail_id = booking_details.id AND booking_package_details.is_deleted = 0
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND transc.id IS NOT NULL
AND (
        (booking_details.performed_at IS NOT NULL OR booking_details.performed_at != 0) 
        OR 
        (booking_package_details.performed_at IS NOT NULL OR booking_package_details.performed_at != 0)
    )
AND booking_details.is_deleted = 0
AND NOT EXISTS (
    SELECT 1 
    FROM send_out_entry
    LEFT JOIN send_out ON send_out_entry.send_out_id = send_out.id
    WHERE send_out_entry.is_deleted = 0
    AND (send_out.facility_id = booking_details.performed_at OR send_out.facility_id = booking_package_details.performed_at)
    AND send_out.is_deleted = 0
    AND send_out.status != 'cancelled'
)
EOT;

        if ($facility_id) {
            $sql .= <<<EOT

AND (booking_details.performed_at = ? OR booking_package_details.performed_at = ?)
EOT;
            $binds[] = $facility_id;
            $binds[] = $facility_id;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND transc.transaction_date >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND transc.transaction_date <= ?
EOT;
            $binds[] = $date_to;
        }

        $sql .= <<<EOT

GROUP BY booking.id
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Clinic sales for the P&L: booking lines, packages, consultations, and discounts.
     *
     * @param string $date_bound between|before|through
     * @return array<int, array{account_type_code: string, type: string, type_id: mixed, debit: mixed, credit: mixed, txn_date: mixed, remarks: mixed}>|false
     */
    public function get_profit_and_loss_revenue($date_from, $date_to, string $date_bound = 'between')
    {
        $revenue_code_sql = $this->_profit_and_loss_revenue_code_sql();
        $date_sql = $this->_transaction_date_bound_sql($date_bound);
        $binds = [];

        $sql = <<<EOT
SELECT {$revenue_code_sql} AS account_type_code,
    'booking_details' AS type,
    booking_details.id AS type_id,
    0 AS debit,
    COALESCE(booking_details.amount, 0) AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR sale #', COALESCE(transaction.transaction_no, booking_details.id)) AS remarks
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id
    AND booking.is_deleted = 0
    AND LOWER(COALESCE(booking.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
    AND booking.transaction_type != 'consultation'
INNER JOIN transaction ON transaction.id = booking.transaction_id
    AND transaction.is_deleted = 0
    AND {$date_sql}
LEFT JOIN lab_test ON lab_test.id = booking_details.test_id
    AND lab_test.is_deleted = 0
LEFT JOIN category AS child_cat ON child_cat.id = lab_test.category_id
    AND child_cat.is_deleted = 0
LEFT JOIN category AS parent_cat ON parent_cat.id = child_cat.category_id
    AND parent_cat.is_deleted = 0
WHERE booking_details.is_deleted = 0
AND booking_details.type != 'package'

UNION ALL

SELECT '440-000' AS account_type_code,
    'booking_details' AS type,
    booking_details.id AS type_id,
    COALESCE(booking_details.discount, 0) AS debit,
    0 AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR discount #', COALESCE(transaction.transaction_no, booking_details.id)) AS remarks
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id
    AND booking.is_deleted = 0
    AND LOWER(COALESCE(booking.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
    AND booking.transaction_type != 'consultation'
INNER JOIN transaction ON transaction.id = booking.transaction_id
    AND transaction.is_deleted = 0
    AND {$date_sql}
WHERE booking_details.is_deleted = 0
AND booking_details.type != 'package'
AND COALESCE(booking_details.discount, 0) > 0

UNION ALL

SELECT {$revenue_code_sql} AS account_type_code,
    'booking_details' AS type,
    booking_details.id AS type_id,
    0 AS debit,
    COALESCE(booking_details.amount, 0) AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR package #', COALESCE(transaction.transaction_no, booking_details.id)) AS remarks
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id
    AND booking.is_deleted = 0
    AND LOWER(COALESCE(booking.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
    AND booking.transaction_type != 'consultation'
INNER JOIN transaction ON transaction.id = booking.transaction_id
    AND transaction.is_deleted = 0
    AND {$date_sql}
LEFT JOIN (
    SELECT booking_package_details.booking_detail_id,
        MIN(booking_package_details.test_id) AS test_id
    FROM booking_package_details
    WHERE booking_package_details.is_deleted = 0
    GROUP BY booking_package_details.booking_detail_id
) AS pkg ON pkg.booking_detail_id = booking_details.id
LEFT JOIN lab_test ON lab_test.id = pkg.test_id
    AND lab_test.is_deleted = 0
LEFT JOIN category AS child_cat ON child_cat.id = lab_test.category_id
    AND child_cat.is_deleted = 0
LEFT JOIN category AS parent_cat ON parent_cat.id = child_cat.category_id
    AND parent_cat.is_deleted = 0
WHERE booking_details.is_deleted = 0
AND booking_details.type = 'package'

UNION ALL

SELECT '440-000' AS account_type_code,
    'booking_details' AS type,
    booking_details.id AS type_id,
    COALESCE(booking_details.discount, 0) AS debit,
    0 AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR package discount #', COALESCE(transaction.transaction_no, booking_details.id)) AS remarks
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id
    AND booking.is_deleted = 0
    AND LOWER(COALESCE(booking.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
    AND booking.transaction_type != 'consultation'
INNER JOIN transaction ON transaction.id = booking.transaction_id
    AND transaction.is_deleted = 0
    AND {$date_sql}
WHERE booking_details.is_deleted = 0
AND booking_details.type = 'package'
AND COALESCE(booking_details.discount, 0) > 0

UNION ALL

SELECT '400-030' AS account_type_code,
    'booking' AS type,
    booking.id AS type_id,
    0 AS debit,
    COALESCE(booking.grand_total, 0) AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR consultation #', COALESCE(transaction.transaction_no, booking.id)) AS remarks
FROM booking
INNER JOIN transaction ON transaction.id = booking.transaction_id
    AND transaction.is_deleted = 0
    AND {$date_sql}
WHERE booking.is_deleted = 0
AND LOWER(COALESCE(booking.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
AND booking.transaction_type = 'consultation'

UNION ALL

SELECT '440-000' AS account_type_code,
    'booking' AS type,
    booking.id AS type_id,
    COALESCE(booking.discount, 0) AS debit,
    0 AS credit,
    transaction.transaction_date AS txn_date,
    CONCAT('CSR booking discount #', COALESCE(transaction.transaction_no, booking.id)) AS remarks
FROM booking
INNER JOIN transaction ON transaction.id = booking.transaction_id
    AND transaction.is_deleted = 0
    AND {$date_sql}
WHERE booking.is_deleted = 0
AND LOWER(COALESCE(booking.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
AND COALESCE(booking.discount, 0) > 0
EOT;

        for ($i = 0; $i < 6; $i++) {
            $this->_bind_transaction_date_bound($binds, $date_bound, $date_from, $date_to);
        }

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Temporary lab vs diagnostic credit mapping for CSR tests.
     */
    private function _profit_and_loss_revenue_code_sql(): string
    {
        return <<<EOT
CASE
    WHEN lab_test.is_sendout = 1 OR booking_details.send_out_on IS NOT NULL THEN '400-050'
    WHEN lab_test.is_yakap = 1 OR lab_test.is_consulta = 1
        OR UPPER(COALESCE(parent_cat.name, child_cat.name, '')) = 'YAKAP' THEN '400-060'
    WHEN UPPER(COALESCE(parent_cat.name, child_cat.name, '')) = 'HEALTH SERVICES' THEN '400-020'
    WHEN UPPER(COALESCE(child_cat.name, '')) = 'CLINICA VAXX'
        OR booking.transaction_type = 'vaccination' THEN '480-030'
    WHEN UPPER(COALESCE(parent_cat.name, child_cat.name, '')) = 'EYE CARE' THEN '400-900'
    WHEN booking_details.type = 'lab'
        OR UPPER(COALESCE(parent_cat.name, child_cat.name, '')) = 'LABORATORY' THEN '400-010a'
    WHEN booking_details.type IN ('test', 'diagnosis')
        OR booking.transaction_type = 'diagnosis'
        OR UPPER(COALESCE(parent_cat.name, child_cat.name, '')) IN (
            'RADIOLOGY', 'ULTRASOUND', 'CARDIOLOGY', 'DRUG TEST', 'PULMONOLOGY'
        ) THEN '400-010b'
    WHEN booking.transaction_type = 'pharmacy' THEN '420-000'
    ELSE '400-100'
END
EOT;
    }

    private function _transaction_date_bound_sql(string $date_bound): string
    {
        if ($date_bound === 'none') {
            return '1 = 1';
        }
        if ($date_bound === 'before') {
            return 'transaction.transaction_date < ?';
        }
        if ($date_bound === 'through') {
            return 'transaction.transaction_date <= ?';
        }

        return 'transaction.transaction_date BETWEEN ? AND ?';
    }

    private function _bind_transaction_date_bound(array &$binds, string $date_bound, $date_from, $date_to): void
    {
        if ($date_bound === 'none') {
            return;
        }
        if ($date_bound === 'before') {
            $binds[] = $date_from;
            return;
        }
        if ($date_bound === 'through') {
            $binds[] = $date_to;
            return;
        }

        $binds[] = $date_from;
        $binds[] = $date_to;
    }

    /**
     * Distinct transactions in range, plus those tagged Yakap/Konsulta.
     *
     * @return array{total_census: int, konsulta_census: int}
     */
    public function get_profit_and_loss_census($date_from, $date_to): array
    {
        $cancelled = "LOWER(COALESCE(booking.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')";

        $sql = <<<EOT
SELECT COUNT(DISTINCT transaction.id) AS total_census,
    COUNT(DISTINCT CASE
        WHEN lab_test.is_yakap = 1
            OR lab_test.is_consulta = 1
            OR UPPER(COALESCE(parent_cat.name, child_cat.name, '')) = 'YAKAP'
            OR pkg_lab_test.is_yakap = 1
            OR pkg_lab_test.is_consulta = 1
            OR UPPER(COALESCE(pkg_parent_cat.name, pkg_child_cat.name, '')) = 'YAKAP'
        THEN transaction.id
        ELSE NULL
    END) AS konsulta_census
FROM transaction
INNER JOIN booking ON booking.transaction_id = transaction.id
    AND booking.is_deleted = 0
    AND {$cancelled}
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
    AND booking_details.is_deleted = 0
LEFT JOIN lab_test ON booking_details.type = 'lab'
    AND lab_test.id = booking_details.test_id
    AND lab_test.is_deleted = 0
LEFT JOIN category AS child_cat ON child_cat.id = lab_test.category_id
    AND child_cat.is_deleted = 0
LEFT JOIN category AS parent_cat ON parent_cat.id = child_cat.category_id
    AND parent_cat.is_deleted = 0
LEFT JOIN booking_package_details ON booking_package_details.booking_detail_id = booking_details.id
    AND booking_package_details.is_deleted = 0
    AND booking_details.type = 'package'
LEFT JOIN lab_test AS pkg_lab_test ON pkg_lab_test.id = booking_package_details.test_id
    AND pkg_lab_test.is_deleted = 0
LEFT JOIN category AS pkg_child_cat ON pkg_child_cat.id = pkg_lab_test.category_id
    AND pkg_child_cat.is_deleted = 0
LEFT JOIN category AS pkg_parent_cat ON pkg_parent_cat.id = pkg_child_cat.category_id
    AND pkg_parent_cat.is_deleted = 0
WHERE transaction.is_deleted = 0
AND transaction.transaction_date BETWEEN ? AND ?
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, [$date_from, $date_to]);
        $row = $query ? $query->getRowArray() : null;

        return [
            'total_census'     => (int) ($row['total_census'] ?? 0),
            'konsulta_census'  => (int) ($row['konsulta_census'] ?? 0),
        ];
    }
}

?>