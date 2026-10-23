<?php

namespace App\Core;
namespace App\Models;

use CodeIgniter\Database\BaseConnection;

/**
 * Cross-branch booking transaction reads. Keeps {@see Booking} unchanged for single-branch use.
 */
class Booking_all extends Booking
{
    /**
     * Append charge_to IN (?) filter when $charge_to_ids is non-empty.
     *
     * @param array<int|string>|string|null $charge_to_ids
     */
    protected function append_charge_to_sql_filter(
        string &$sql,
        array &$binds,
        string $column,
        $charge_to_ids = null
    ): void {
        if ($charge_to_ids === null || $charge_to_ids === '') {
            return;
        }

        $ids = is_array($charge_to_ids)
            ? array_filter($charge_to_ids)
            : array_filter(array_map('trim', explode(',', (string) $charge_to_ids)));

        if (!$ids) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql .= "\nAND {$column} IN ($placeholders)";
        foreach ($ids as $id) {
            $binds[] = $id;
        }
    }

    /**
     * Unbilled DSMR + consultation + copay + wallet + YAKAP rows on an explicit connection (branch DB).
     * Parity with {@see Billings::get_transaction()} merge of
     * {@see Booking::get_all_transaction()}, {@see Booking::get_consultation_transaction()},
     * and {@see Booking::get_copay_transaction()}.
     */
    public function get_all_transaction_on_connection(
        BaseConnection $db,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to_ids = null,
        $page = null,
        $data_size = null,
        $branch_id = null,
        $branch_name = null
    ) {
        $merged = array_merge(
            $this->get_dsmr_transaction_on_connection($db, $company_id, $date_from, $date_to, null, null, $charge_to_ids),
            $this->get_hmo_transaction_on_connection($db, $company_id, $date_from, $date_to, null, null, $charge_to_ids),
            $this->get_consultation_transaction_on_connection($db, $company_id, $date_from, $date_to, null, null, $charge_to_ids),
            $this->get_copay_transaction_on_connection($db, $company_id, $date_from, $date_to, null, null, $charge_to_ids),
            $this->get_company_wallet_transaction_on_connection($db, $company_id, $date_from, $date_to, null, $charge_to_ids),
            $this->get_yakap_transaction_on_connection(
                $db,
                $branch_id,
                $branch_name,
                $company_id,
                $date_from,
                $date_to,
                null,
                $charge_to_ids
            )
        );

        if ($page && $data_size) {
            $page = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $merged = array_slice($merged, $offset, (int) $data_size);
        }

        return $merged;
    }

    /**
     * Include YAKAP when company / charge-to filters are empty or contain 3210.
     *
     * @param array<int|string>|string|null $charge_to_ids
     */
    protected function should_include_yakap($company_id = null, $charge_to = null, $charge_to_ids = null): bool
    {
        $yakap_id = Yakap_billing::COMPANY_ID;

        $ids = [];
        if ($charge_to_ids !== null && $charge_to_ids !== '') {
            $ids = is_array($charge_to_ids)
                ? array_filter($charge_to_ids, static function ($id) {
                    return $id !== null && $id !== '';
                })
                : array_filter(array_map('trim', explode(',', (string) $charge_to_ids)));
            $ids = array_map('intval', $ids);
        }

        $has_company = $company_id !== null && $company_id !== '' && (int) $company_id !== 0;
        $has_charge_to = $charge_to !== null && $charge_to !== '' && (int) $charge_to !== 0;
        $has_ids = $ids !== [];

        if (!$has_company && !$has_charge_to && !$has_ids) {
            return true;
        }
        if ($has_company && (int) $company_id === $yakap_id) {
            return true;
        }
        if ($has_charge_to && (int) $charge_to === $yakap_id) {
            return true;
        }

        return $has_ids && in_array($yakap_id, $ids, true);
    }

    /**
     * YAKAP FPE + CSR candidates for one branch. FPE hits EMR; CSR uses $db.
     *
     * @param array<int|string>|string|null $charge_to_ids
     */
    protected function get_yakap_transaction_on_connection(
        BaseConnection $db,
        $branch_id = null,
        $branch_name = null,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $charge_to_ids = null
    ): array {
        // Tranches are billed only through the period-based YAKAP endpoints.
        return [];
    }

    /**
     * DSMR / get_transaction rows on an explicit connection (no LIMIT).
     * Parity with {@see Booking::get_transaction()} — no hmo_id IS NULL filter.
     */
    protected function get_transaction_on_connection(
        BaseConnection $db,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $endorsement_id = null
    ) {
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
    SUM(COALESCE(endorsement_service.pro_rate_price, booking_details.amount)) AS total_amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
LEFT JOIN patient ON patient.id = booking.patient_id
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
AND booking_details.charge_to IS NOT NULL
AND booking_details.charge_to != ?
AND NOT EXISTS (
    SELECT 1 
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing_entry.booking_id = booking.id
    AND billing.charge_to = booking_details.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;
        $binds[] = Yakap_billing::COMPANY_ID;

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

        if ($endorsement_id) {
            $sql .= <<<EOT

AND (
    booking_details.endorsement_id = ?
    OR transc.endorsement_id = ?
)
EOT;
            $binds[] = $endorsement_id;
            $binds[] = $endorsement_id;
        }

        $sql .= <<<EOT

GROUP BY booking.id, booking_details.charge_to
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * DSMR / get_all_transaction rows on an explicit connection (no LIMIT).
     */
    protected function get_dsmr_transaction_on_connection(
        BaseConnection $db,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $endorsement_id = null,
        $charge_to_ids = null
    ) {
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
    SUM(COALESCE(endorsement_service.pro_rate_price, booking_details.amount)) AS total_amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
LEFT JOIN patient ON patient.id = booking.patient_id
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
LEFT JOIN {MASTER_DB}.company AS charge_company ON charge_company.id = booking_details.charge_to
    AND charge_company.is_deleted = 0
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
AND IFNULL(charge_company.is_hmo, 0) = 0
AND booking_details.charge_to IS NOT NULL
AND booking_details.charge_to != ?
AND NOT EXISTS (
    SELECT 1 
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing_entry.booking_id = booking.id
    AND billing.charge_to = booking_details.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;
        $binds[] = Yakap_billing::COMPANY_ID;

        if ($company_id) {
            $sql .= <<<EOT

AND booking.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($charge_to_ids !== null && $charge_to_ids !== '') {
            $this->append_charge_to_sql_filter($sql, $binds, 'booking_details.charge_to', $charge_to_ids);
        } elseif ($charge_to) {
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

        if ($endorsement_id) {
            $sql .= <<<EOT

AND (
    booking_details.endorsement_id = ?
    OR transc.endorsement_id = ?
)
EOT;
            $binds[] = $endorsement_id;
            $binds[] = $endorsement_id;
        }

        $sql .= <<<EOT

GROUP BY booking.id, booking_details.charge_to
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * DSMR / get_all_transaction rows on an explicit connection (no LIMIT).
     */
    protected function get_hmo_transaction_on_connection(
        BaseConnection $db,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $endorsement_id = null,
        $charge_to_ids = null
    ) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT transc.id AS transaction_id, 
    booking.id AS booking_id, 
    transc.transaction_date, 
    booking.company_id, 
    booking_details.charge_to,
    'hmo' AS billing_channel,
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
LEFT JOIN {MASTER_DB}.company AS charge_company ON charge_company.id = booking_details.charge_to
    AND charge_company.is_deleted = 0
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND transc.id IS NOT NULL
AND (booking.company_id IS NOT NULL OR booking.company_id != 0)
AND booking.payment_status = 'unpaid'
AND booking_details.is_deleted = 0
AND booking_details.is_billed_externally = 1
AND charge_company.is_hmo = 1
AND booking_details.charge_to IS NOT NULL
AND booking_details.charge_to != ?
AND NOT EXISTS (
    SELECT 1 
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing_entry.booking_id = booking.id
    AND billing.charge_to = booking_details.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;
        $binds[] = Yakap_billing::COMPANY_ID;

        if ($company_id) {
            $sql .= <<<EOT

AND booking.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($charge_to_ids !== null && $charge_to_ids !== '') {
            $this->append_charge_to_sql_filter($sql, $binds, 'booking_details.charge_to', $charge_to_ids);
        } elseif ($charge_to) {
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

        if ($endorsement_id) {
            $sql .= <<<EOT

AND (
    booking_details.endorsement_id = ?
    OR transc.endorsement_id = ?
)
EOT;
            $binds[] = $endorsement_id;
            $binds[] = $endorsement_id;
        }

        $sql .= <<<EOT

GROUP BY booking.id, booking_details.charge_to
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Consultation transaction rows on an explicit connection (no LIMIT).
     */
    protected function get_consultation_transaction_on_connection(
        BaseConnection $db,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $endorsement_id = null,
        $charge_to_ids = null
    ) {
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
AND booking.charge_to != ?
AND NOT EXISTS (
    SELECT 1 
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing_entry.booking_id = booking.id
    AND billing.charge_to = booking.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;
        $binds[] = Yakap_billing::COMPANY_ID;

        if ($company_id) {
            $sql .= <<<EOT

AND booking.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($charge_to_ids !== null && $charge_to_ids !== '') {
            $this->append_charge_to_sql_filter($sql, $binds, 'booking.charge_to', $charge_to_ids);
        } elseif ($charge_to) {
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

        if ($endorsement_id) {
            $sql .= <<<EOT

AND (
    transc.endorsement_id = ?
    OR EXISTS (
        SELECT 1 FROM booking_details
        WHERE booking_details.booking_id = booking.id
        AND booking_details.is_deleted = 0
        AND booking_details.endorsement_id = ?
    )
)
EOT;
            $binds[] = $endorsement_id;
            $binds[] = $endorsement_id;
        }

        $sql .= <<<EOT

GROUP BY booking.id
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Copay transaction rows on an explicit connection (no LIMIT).
     */
    protected function get_copay_transaction_on_connection(
        BaseConnection $db,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $endorsement_id = null,
        $charge_to_ids = null
    ) {
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
    WHERE billing_entry.booking_id = booking.id
    AND billing.charge_to = booking_details.charge_to
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

        if ($charge_to_ids !== null && $charge_to_ids !== '') {
            $this->append_charge_to_sql_filter($sql, $binds, 'transc.hmo_id', $charge_to_ids);
        } elseif ($charge_to) {
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

        if ($endorsement_id) {
            $sql .= <<<EOT

AND (
    booking_details.endorsement_id = ?
    OR transc.endorsement_id = ?
)
EOT;
            $binds[] = $endorsement_id;
            $binds[] = $endorsement_id;
        }

        $sql .= <<<EOT

GROUP BY booking.id
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * CSR company_wallet payments billed to the company (not cash).
     * Paid at CSR; amount is the wallet slice only.
     */
    protected function get_company_wallet_transaction_on_connection(
        BaseConnection $db,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $charge_to_ids = null
    ) {
        $key = $this->encryption_key;
        $binds = [];
        $charge_expr = 'COALESCE(payment.charge_to, booking.charge_to, booking.company_id)';
        $sql = <<<EOT
SELECT transc.id AS transaction_id,
    booking.id AS booking_id,
    transc.transaction_date,
    COALESCE(booking.company_id, booking.charge_to, payment.charge_to) AS company_id,
    {$charge_expr} AS charge_to,
    'company_wallet' AS billing_channel,
    booking.status,
    booking.transaction_type,
    transc.transaction_no,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    COALESCE((
        SELECT MAX(bd.endorsement_id)
        FROM booking_details AS bd
        WHERE bd.booking_id = booking.id
        AND bd.is_deleted = 0
    ), transc.endorsement_id) AS endorsement_id,
    SUM(pmm.amount) AS total_amount
FROM booking
INNER JOIN transaction AS transc ON booking.transaction_id = transc.id
INNER JOIN payment ON payment.transaction_id = transc.id AND payment.is_deleted = 0
INNER JOIN payment_multiple_method AS pmm ON pmm.payment_id = payment.id
    AND pmm.is_deleted = 0
    AND pmm.type = 'company_wallet'
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND {$charge_expr} IS NOT NULL
AND {$charge_expr} != 0
AND NOT EXISTS (
    SELECT 1
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing_entry.transaction_id = transc.id
    AND billing.charge_to = {$charge_expr}
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND (booking.company_id = ? OR {$charge_expr} = ?)
EOT;
            $binds[] = $company_id;
            $binds[] = $company_id;
        }

        if ($charge_to_ids !== null && $charge_to_ids !== '') {
            $this->append_charge_to_sql_filter($sql, $binds, $charge_expr, $charge_to_ids);
        } elseif ($charge_to) {
            $sql .= <<<EOT

AND {$charge_expr} = ?
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

GROUP BY booking.id, {$charge_expr}
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Run the same query on each branch database and merge in PHP.
     *
     * @param array<int,string> $branchDbMap branch_id => Database config group name (e.g. 1 => 'mobile')
     * @return array{size:int,data:array}
     */
    public function get_all_transaction_all_branches(
        array $branchDbMap,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to_ids = null,
        $page = null,
        $data_size = null,
        $term = null
    ) {
        $merged = [];

        foreach ($branchDbMap as $branch_id => $groupName) {
            $db = \Config\Database::connect($groupName);
            $rows = $this->get_all_transaction_on_connection(
                $db,
                $company_id,
                $date_from,
                $date_to,
                $charge_to_ids,
                null,
                null,
                $branch_id,
                $groupName
            ) ?: [];

            foreach ($rows as $row) {
                $row['branch_id'] = (int) $branch_id;
                $row['branch_name'] = $groupName;
                $merged[] = $row;
            }
        }

        usort($merged, static function ($a, $b) {
            $da = $a['transaction_date'] ?? '';
            $dbt = $b['transaction_date'] ?? '';

            return strcmp((string) $dbt, (string) $da);
        });

        if ($term !== null && $term !== '') {
            $needle = (string) $term;
            $merged = array_values(array_filter($merged, static function ($row) use ($needle) {
                return stripos((string) ($row['patient_name'] ?? ''), $needle) !== false;
            }));
        }

        $size = count($merged);

        if ($page && $data_size) {
            $page = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $merged = array_slice($merged, $offset, (int) $data_size);
        }

        return [
            'size' => $size,
            'data' => $merged,
        ];
    }

    /**
     * Cross-branch parity with {@see Billings::get_transaction()} merge of
     * {@see Booking::get_transaction()}, {@see Booking::get_consultation_transaction()},
     * and {@see Booking::get_copay_transaction()}.
     *
     * @param array<int,string> $branchDbMap branch_id => Database config group name
     * @return array
     */
    public function get_transaction_all_branches(
        array $branchDbMap,
        $company_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $endorsement_id = null
    ) {
        $merged = [];

        foreach ($branchDbMap as $branch_id => $groupName) {
            $db = \Config\Database::connect($groupName);
            $parts = [
                $this->get_dsmr_transaction_on_connection($db, $company_id, $date_from, $date_to, $charge_to, $endorsement_id),
                $this->get_hmo_transaction_on_connection($db, $company_id, $date_from, $date_to, $charge_to, $endorsement_id),
                $this->get_consultation_transaction_on_connection($db, $company_id, $date_from, $date_to, $charge_to, $endorsement_id),
                $this->get_copay_transaction_on_connection($db, $company_id, $date_from, $date_to, $charge_to, $endorsement_id),
            ];
            if ($endorsement_id === null || $endorsement_id === '' || $endorsement_id === '0' || (int) $endorsement_id === 0) {
                $parts[] = $this->get_company_wallet_transaction_on_connection($db, $company_id, $date_from, $date_to, $charge_to);
            }
            $parts[] = $this->get_yakap_transaction_on_connection(
                $db,
                $branch_id,
                $groupName,
                $company_id,
                $date_from,
                $date_to,
                $charge_to
            );
            $rows = array_merge(...$parts);

            foreach ($rows as $row) {
                $row['branch_id'] = (int) $branch_id;
                $row['branch_name'] = $groupName;
                $merged[] = $row;
            }
        }

        usort($merged, static function ($a, $b) {
            return strcasecmp(
                (string) ($a['patient_name'] ?? ''),
                (string) ($b['patient_name'] ?? '')
            );
        });

        return $merged;
    }

    /**
     * Unbilled DSMR sales aggregated per charge company (booking_details.charge_to).
     */
    public function get_unbilled_sales_by_charge_on_connection(
        BaseConnection $db,
        $date_from = null,
        $date_to = null,
        $company_id = null, 
        $sales_executive_id = null
    ) {
        $binds = [];
        $sql   = <<<EOT
SELECT booking_details.charge_to AS charge_company_id,
    COALESCE(SUM(booking_details.amount), 0) AS amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND IFNULL(transc.is_worklist, 0) = 0
AND transc.id IS NOT NULL
AND (booking.company_id IS NOT NULL OR booking.company_id != 0)
AND booking.payment_status = 'unpaid'
AND booking_details.is_deleted = 0
AND booking_details.is_billed_externally = 1
AND booking_details.hmo_id IS NULL
AND booking_details.charge_to IS NOT NULL
AND NOT EXISTS (
    SELECT 1
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing_entry.booking_id = booking.id
    AND billing.charge_to = booking_details.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;

        if ($company_id) {
            $sql .= "\nAND transc.hmo_id = ?";
            $binds[] = $company_id;
        }

        if ($sales_executive_id) {
            $sql .= "\nAND transc.added_by = ?";
            $binds[] = $sales_executive_id;
        }

        if ($date_from) {
            $sql .= "\nAND transc.transaction_date >= ?";
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= "\nAND transc.transaction_date <= ?";
            $binds[] = $date_to;
        }

        $sql .= "\nGROUP BY booking_details.charge_to";

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Copay unbilled sales aggregated per HMO company (transaction.hmo_id).
     */
    public function get_copay_sales_by_hmo_on_connection(
        BaseConnection $db,
        $date_from = null,
        $date_to = null,
        $company_id = null, 
        $sales_executive_id = null
    ) {
        $binds = [];
        $sql   = <<<EOT
SELECT transc.hmo_id AS charge_company_id,
    COALESCE(SUM(transc.copay), 0) AS amount
FROM booking
LEFT JOIN transaction AS transc ON booking.transaction_id = transc.id
LEFT JOIN booking_details ON booking_details.booking_id = booking.id
WHERE booking.is_deleted = 0
AND transc.is_deleted = 0
AND IFNULL(transc.is_worklist, 0) = 0
AND transc.id IS NOT NULL
AND (booking.company_id IS NOT NULL OR booking.company_id != 0)
AND booking.payment_status = 'unpaid'
AND booking_details.is_deleted = 0
AND booking_details.is_billed_externally = 1
AND transc.hmo_id IS NOT NULL
AND NOT EXISTS (
    SELECT 1
    FROM {MASTER_DB}.billing_entry
    LEFT JOIN {MASTER_DB}.billing ON billing_entry.billing_id = billing.id
    WHERE billing_entry.booking_id = booking.id
    AND billing.charge_to = booking_details.charge_to
    AND billing_entry.is_deleted = 0
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
)
EOT;

        if ($company_id) {
            $sql .= "\nAND transc.hmo_id = ?";
            $binds[] = $company_id;
        }

        if ($sales_executive_id) {
            $sql .= "\nAND transc.added_by = ?";
            $binds[] = $sales_executive_id;
        }

        if ($date_from) {
            $sql .= "\nAND transc.transaction_date >= ?";
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= "\nAND transc.transaction_date <= ?";
            $binds[] = $date_to;
        }

        $sql .= "\nGROUP BY transc.hmo_id";

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Payment term totals from payment_multiple_method for mode-of-payment tab.
     */
    public function get_payment_terms_on_connection(
        BaseConnection $db,
        $date_from = null,
        $date_to = null, 
        $company_id = null, 
        $sales_executive_id = null
    ) {
        $binds = [];
        $sql   = <<<EOT
SELECT
    CASE
        WHEN pmm.type = 'cash' THEN 'cash'
        WHEN pmm.type = 'card' AND LOWER(IFNULL(pmm.other_payment_details, '')) LIKE '%debit%' THEN 'debit'
        WHEN pmm.type = 'card' THEN 'credit'
        ELSE 'other'
    END AS payment_term,
    COALESCE(SUM(pmm.amount), 0) AS amount
FROM payment p
INNER JOIN `transaction` t ON t.id = p.transaction_id AND t.is_deleted = 0
LEFT JOIN payment_multiple_method pmm ON pmm.payment_id = p.id AND pmm.is_deleted = 0
WHERE p.is_deleted = 0
AND p.grand_total > 0
AND pmm.id IS NOT NULL
EOT;

        if ($company_id) {
            $sql .= "\nAND t.hmo_id = ?";
            $binds[] = $company_id;
        }

        if ($sales_executive_id) {
            $sql .= "\nAND t.added_by = ?";
            $binds[] = $sales_executive_id;
        }

        if ($date_from) {
            $sql .= "\nAND t.transaction_date >= ?";
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= "\nAND t.transaction_date <= ?";
            $binds[] = $date_to;
        }

        $sql .= "\nGROUP BY payment_term";

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Raw booking_details × payment method lines for mode-of-payment tab (diagnostic DB).
     * One row per booking_details (no payment), or per booking_details × pmm when paid.
     */
    public function get_mode_of_payment_lines_on_connection(
        BaseConnection $db,
        $date_from = null,
        $date_to = null,
        $company_id = null,
        $sales_executive_id = null
    ) {
        $key   = $this->encryption_key;
        $binds = [];
        $sql   = <<<EOT
SELECT
    booking_details.id AS booking_details_id,
    booking.id AS booking_id,
    booking.transaction_id,
    booking.patient_id,
    booking.company_id,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    booking.transaction_type,
    booking.assessment_type,
    booking.package_id,
    transc.transaction_no,
    booking_details.type,
    booking_details.test_id,
    CASE
        WHEN booking_details.type = 'lab' THEN lab_test.name
        WHEN booking_details.type = 'package' THEN package.name
        WHEN booking_details.type = 'wallet' THEN 'Medical Services'
        ELSE NULL
    END AS test_name,
    lab_test.group_id AS group_id,
    `group`.name AS group_name,
    booking_details.amount,
    booking_details.status,
    booking_details.is_billed_externally,
    booking_details.charge_to,
    company.name AS company_name,
    booking_company.name AS booking_company_name,
    company.is_hmo,
    p.id AS payment_id,
    p.charge_to AS payment_charge_to,
    payment_company.name AS payment_charge_to_name,
    pmm.id AS pmm_id,
    pmm.type AS pmm_type,
    pmm.other_payment_details,
    pmm.amount AS pmm_amount,
    (
        SELECT COALESCE(SUM(pmm2.amount), 0)
        FROM payment_multiple_method pmm2
        WHERE pmm2.payment_id = p.id
        AND pmm2.is_deleted = 0
    ) AS payment_pmm_total,
    billing_entry.id AS billing_entry_id,
    billing_entry.billing_id,
    billing_entry.branch_id AS billing_entry_branch_id,
    billing_entry.company_id AS billing_entry_company_id,
    billing_entry.booking_id AS billing_entry_booking_id,
    billing_entry.transaction_id AS billing_entry_transaction_id,
    billing_entry.amount AS billing_entry_amount,
    billing_entry.balance AS billing_entry_balance,
    billing_entry.status AS billing_entry_status,
    billing.charge_to AS billing_charge_to,
    billing.due_date,
    billing.status AS billing_status,
    billing.grand_total,
    transc.grand_total AS transaction_grand_total,
    billing.invoice_id,
    billing.invoice_status,
    billing.date_from,
    billing.date_to
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id AND booking.is_deleted = 0
INNER JOIN `transaction` AS transc ON transc.id = booking.transaction_id AND transc.is_deleted = 0 AND IFNULL(transc.is_worklist, 0) = 0 AND transc.grand_total > 0
LEFT JOIN patient ON patient.id = booking.patient_id
LEFT JOIN lab_test
    ON booking_details.type = 'lab'
    AND booking_details.test_id = lab_test.id
LEFT JOIN {MASTER_DB}.`group` AS `group`
    ON booking_details.type = 'lab'
    AND `group`.id = lab_test.group_id
LEFT JOIN package
    ON booking_details.type = 'package'
    AND booking_details.test_id = package.id
LEFT JOIN payment p
    ON p.transaction_id = transc.id
    AND p.is_deleted = 0
    AND p.grand_total > 0
LEFT JOIN payment_multiple_method pmm
    ON pmm.payment_id = p.id
    AND pmm.is_deleted = 0
LEFT JOIN {MASTER_DB}.company AS company
    ON company.id = booking_details.charge_to
    AND company.is_deleted = 0
LEFT JOIN {MASTER_DB}.company AS booking_company
    ON booking_company.id = COALESCE(
        NULLIF(booking.company_id, 0),
        (
            SELECT b2.company_id
            FROM booking b2
            WHERE b2.transaction_id = booking.transaction_id
            AND b2.is_deleted = 0
            AND b2.company_id IS NOT NULL
            AND b2.company_id != 0
            LIMIT 1
        )
    )
    AND booking_company.is_deleted = 0
LEFT JOIN {MASTER_DB}.company AS payment_company
    ON payment_company.id = p.charge_to
    AND payment_company.is_deleted = 0
LEFT JOIN {MASTER_DB}.billing_entry AS billing_entry
    ON billing_entry.booking_id = booking.id
    AND billing_entry.transaction_id = transc.id
    AND billing_entry.is_deleted = 0
LEFT JOIN {MASTER_DB}.billing AS billing
    ON billing.id = billing_entry.billing_id
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
    AND billing.charge_to = booking_details.charge_to
WHERE booking_details.is_deleted = 0
AND IFNULL(booking.package_id, 0) = 0
AND booking_details.type != 'wallet'
AND NOT EXISTS (
    SELECT 1
    FROM booking_details bd_wallet
    WHERE bd_wallet.booking_id = booking.id
    AND bd_wallet.is_deleted = 0
    AND bd_wallet.type = 'wallet'
)
EOT;

        if ($company_id) {
            $sql .= "\nAND booking_details.charge_to = ?";
            $binds[] = $company_id;
        }

        if ($sales_executive_id) {
            $sql .= "\nAND transc.added_by = ?";
            $binds[] = $sales_executive_id;
        }

        if ($date_from) {
            $sql .= "\nAND transc.transaction_date >= ?";
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= "\nAND transc.transaction_date <= ?";
            $binds[] = $date_to;
        }

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Paid transactions with pmm but no qualifying mop booking_details
     * (consultations, or all lines transferred out via new_transaction_id).
     *
     * @return array<int, array>
     */
    public function get_mode_of_payment_payment_only_on_connection(
        BaseConnection $db,
        $date_from = null,
        $date_to = null,
        $company_id = null,
        $sales_executive_id = null
    ) {
        $key   = $this->encryption_key;
        $binds = [];
        $sql   = <<<EOT
SELECT
    NULL AS booking_details_id,
    booking.id AS booking_id,
    booking.transaction_id,
    booking.patient_id,
    booking.company_id,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    booking.transaction_type,
    booking.assessment_type,
    booking.package_id,
    transc.transaction_no,
    CASE
        WHEN booking.transaction_type = 'consultation' THEN 'consultation'
        ELSE booking.transaction_type
    END AS type,
    NULL AS test_id,
    CASE
        WHEN booking.transaction_type = 'consultation' THEN 'Consultation'
        ELSE NULL
    END AS test_name,
    NULL AS group_id,
    NULL AS group_name,
    COALESCE(booking.grand_total, transc.grand_total, 0) AS amount,
    booking.status,
    0 AS is_billed_externally,
    booking.charge_to,
    company.name AS company_name,
    booking_company.name AS booking_company_name,
    company.is_hmo,
    p.id AS payment_id,
    p.charge_to AS payment_charge_to,
    payment_company.name AS payment_charge_to_name,
    pmm.id AS pmm_id,
    pmm.type AS pmm_type,
    pmm.other_payment_details,
    pmm.amount AS pmm_amount,
    (
        SELECT COALESCE(SUM(pmm2.amount), 0)
        FROM payment_multiple_method pmm2
        WHERE pmm2.payment_id = p.id
        AND pmm2.is_deleted = 0
    ) AS payment_pmm_total,
    billing_entry.id AS billing_entry_id,
    billing_entry.billing_id,
    billing_entry.branch_id AS billing_entry_branch_id,
    billing_entry.company_id AS billing_entry_company_id,
    billing_entry.booking_id AS billing_entry_booking_id,
    billing_entry.transaction_id AS billing_entry_transaction_id,
    billing_entry.amount AS billing_entry_amount,
    billing_entry.balance AS billing_entry_balance,
    billing_entry.status AS billing_entry_status,
    billing.charge_to AS billing_charge_to,
    billing.due_date,
    billing.status AS billing_status,
    billing.grand_total,
    transc.grand_total AS transaction_grand_total,
    billing.invoice_id,
    billing.invoice_status,
    billing.date_from,
    billing.date_to
FROM `transaction` AS transc
INNER JOIN booking
    ON booking.transaction_id = transc.id
    AND booking.is_deleted = 0
INNER JOIN payment p
    ON p.transaction_id = transc.id
    AND p.is_deleted = 0
    AND p.grand_total > 0
INNER JOIN payment_multiple_method pmm
    ON pmm.payment_id = p.id
    AND pmm.is_deleted = 0
LEFT JOIN patient ON patient.id = booking.patient_id
LEFT JOIN {MASTER_DB}.company AS company
    ON company.id = booking.charge_to
    AND company.is_deleted = 0
LEFT JOIN {MASTER_DB}.company AS booking_company
    ON booking_company.id = COALESCE(
        NULLIF(booking.company_id, 0),
        (
            SELECT b2.company_id
            FROM booking b2
            WHERE b2.transaction_id = booking.transaction_id
            AND b2.is_deleted = 0
            AND b2.company_id IS NOT NULL
            AND b2.company_id != 0
            LIMIT 1
        )
    )
    AND booking_company.is_deleted = 0
LEFT JOIN {MASTER_DB}.company AS payment_company
    ON payment_company.id = p.charge_to
    AND payment_company.is_deleted = 0
LEFT JOIN {MASTER_DB}.billing_entry AS billing_entry
    ON billing_entry.booking_id = booking.id
    AND billing_entry.transaction_id = transc.id
    AND billing_entry.is_deleted = 0
LEFT JOIN {MASTER_DB}.billing AS billing
    ON billing.id = billing_entry.billing_id
    AND billing.is_deleted = 0
    AND billing.status != 'cancelled'
    AND billing.charge_to = booking.charge_to
WHERE transc.is_deleted = 0
AND IFNULL(transc.is_worklist, 0) = 0
AND transc.grand_total > 0
AND NOT EXISTS (
    SELECT 1
    FROM booking_details bd
    WHERE bd.booking_id = booking.id
    AND bd.is_deleted = 0
    AND bd.type != 'wallet'
    AND bd.new_transaction_id IS NULL
    AND bd.previous_booking_details_id IS NULL
)
EOT;

        if ($company_id) {
            $sql .= "\nAND booking.charge_to = ?";
            $binds[] = $company_id;
        }

        if ($sales_executive_id) {
            $sql .= "\nAND transc.added_by = ?";
            $binds[] = $sales_executive_id;
        }

        if ($date_from) {
            $sql .= "\nAND transc.transaction_date >= ?";
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= "\nAND transc.transaction_date <= ?";
            $binds[] = $date_to;
        }

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Active ewallet names on a branch diagnostic connection.
     *
     * @return array<int, array{id: mixed, name: mixed}>
     */
    public function get_ewallets_on_connection(BaseConnection $db)
    {
        $sql = <<<EOT
SELECT id, name
FROM ewallet
WHERE is_deleted = 0
ORDER BY name ASC
EOT;

        $query = $db->query($sql);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Nested transaction discounts keyed by transaction_id for mop details.
     *
     * @param array<int|string> $transaction_ids
     * @return array<int, array<int, array>>
     */
    public function get_transaction_discounts_on_connection(BaseConnection $db, array $transaction_ids)
    {
        $transaction_ids = array_values(array_unique(array_filter(array_map('intval', $transaction_ids))));
        if (empty($transaction_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
        $sql          = <<<EOT
SELECT
    pd.id,
    p.transaction_id,
    p.subtotal,
    pd.amount AS total_discount,
    t.grand_total,
    pd.id AS detail_id,
    pd.id AS transaction_discount_id,
    pd.payment_id,
    pd.discount_id,
    pd.discount_type AS discount_name,
    pd.discount_type,
    pd.amount AS detail_amount
FROM payment_discount pd
INNER JOIN payment p
    ON p.id = pd.payment_id
    AND p.is_deleted = 0
INNER JOIN `transaction` t
    ON t.id = p.transaction_id
    AND t.is_deleted = 0
WHERE pd.is_deleted = 0
AND p.transaction_id IN ($placeholders)
ORDER BY p.transaction_id ASC, pd.id ASC
EOT;

        $query = $db->query($sql, $transaction_ids);
        $rows  = $query ? $query->getResultArray() : [];

        $by_transaction = [];
        foreach ($rows as $row) {
            $transaction_id = (int) ($row['transaction_id'] ?? 0);
            $discount_id    = (int) ($row['id'] ?? 0);
            if ($transaction_id <= 0 || $discount_id <= 0) {
                continue;
            }

            if (! isset($by_transaction[$transaction_id][$discount_id])) {
                $by_transaction[$transaction_id][$discount_id] = [
                    'id'             => $discount_id,
                    'transaction_id' => $transaction_id,
                    'subtotal'       => isset($row['subtotal']) ? round((float) $row['subtotal'], 2) : null,
                    'total_discount' => isset($row['total_discount']) ? round((float) $row['total_discount'], 2) : null,
                    'grand_total'    => isset($row['grand_total']) ? round((float) $row['grand_total'] - $row['total_discount'], 2) : null,
                    'details'        => [],
                ];
            }

            $detail_id = (int) ($row['detail_id'] ?? 0);
            if ($detail_id > 0) {
                $by_transaction[$transaction_id][$discount_id]['details'][] = [
                    'id'                      => $detail_id,
                    'transaction_discount_id' => isset($row['transaction_discount_id'])
                        ? (int) $row['transaction_discount_id']
                        : $discount_id,
                    'payment_id'              => isset($row['payment_id']) ? (int) $row['payment_id'] : null,
                    'discount_id'             => isset($row['discount_id']) ? (int) $row['discount_id'] : null,
                    'discount_name'           => $row['discount_name'] ?? null,
                    'discount_type'           => $row['discount_type'] ?? null,
                    'amount'                  => isset($row['detail_amount'])
                        ? round((float) $row['detail_amount'], 2)
                        : null,
                ];
            }
        }

        $result = [];
        foreach ($by_transaction as $transaction_id => $discounts) {
            $result[$transaction_id] = array_values($discounts);
        }

        return $result;
    }

    /**
     * Booking line items keyed by transaction_id for mop details.
     * Non-package from booking_details; package rows expand via booking_package_details.
     *
     * @param array<int|string> $transaction_ids
     * @return array<int, array<int, array{transaction_type: mixed, type: mixed, test_id: mixed, test_name: mixed}>>
     */
    public function get_transaction_details_on_connection(BaseConnection $db, array $transaction_ids)
    {
        $transaction_ids = array_values(array_unique(array_filter(array_map('intval', $transaction_ids))));
        if (empty($transaction_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
        $sql          = <<<EOT
SELECT booking.transaction_id,
    booking.transaction_type,
    booking_details.type,
    booking_details.test_id,
    lab_test.name AS test_name
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id
LEFT JOIN lab_test
    ON booking_details.type = 'lab'
    AND booking_details.test_id = lab_test.id
WHERE booking_details.is_deleted = 0
AND booking.is_deleted = 0
AND booking_details.type != 'package'
AND booking.transaction_id IN ($placeholders)

UNION ALL

SELECT booking.transaction_id,
    booking.transaction_type,
    booking_details.type,
    booking_package_details.test_id,
    lab_test.name AS test_name
FROM booking_package_details
INNER JOIN booking_details
    ON booking_package_details.booking_detail_id = booking_details.id
    AND booking_details.is_deleted = 0
INNER JOIN booking ON booking.id = booking_details.booking_id
LEFT JOIN lab_test ON booking_package_details.test_id = lab_test.id
WHERE booking_package_details.is_deleted = 0
AND booking.is_deleted = 0
AND booking_details.type = 'package'
AND booking.transaction_id IN ($placeholders)
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $binds = array_merge($transaction_ids, $transaction_ids);
        $query = $db->query($sql, $binds);
        $rows  = $query ? $query->getResultArray() : [];

        $by_transaction = [];
        foreach ($rows as $row) {
            $transaction_id = (int) ($row['transaction_id'] ?? 0);
            if ($transaction_id <= 0) {
                continue;
            }

            $by_transaction[$transaction_id][] = [
                'transaction_type' => $row['transaction_type'] ?? null,
                'type'             => $row['type'] ?? null,
                'test_id'          => $row['test_id'] ?? null,
                'test_name'        => $row['test_name'] ?? null,
            ];
        }

        return $by_transaction;
    }

    /**
     * booking_medicine rows keyed by booking_id for mop details.
     *
     * @param array<int|string> $booking_ids
     * @return array<int, array<int, array>>
     */
    public function get_booking_medicines_on_connection(BaseConnection $db, array $booking_ids)
    {
        $booking_ids = array_values(array_unique(array_filter(array_map('intval', $booking_ids))));
        if (empty($booking_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
        $sql          = <<<EOT
SELECT
    booking_medicine.id,
    booking_medicine.booking_id,
    booking_medicine.medicine_id,
    booking_medicine.qty,
    booking_medicine.price,
    booking_medicine.amount,
    booking_medicine.is_free,
    booking_medicine.is_yakap,
    booking_medicine.is_gamot,
    booking_medicine.charge_to,
    medicine.name AS medicine_name,
    medicine.brand AS medicine_brand,
    medicine.generic AS medicine_generic,
    medicine.dosage AS medicine_dosage,
    medicine.vat_type AS medicine_vat_type
FROM booking_medicine
LEFT JOIN item AS medicine
    ON medicine.id = booking_medicine.medicine_id
    AND medicine.is_deleted = 0
WHERE booking_medicine.is_deleted = 0
AND booking_medicine.booking_id IN ($placeholders)
ORDER BY booking_medicine.booking_id ASC, booking_medicine.id ASC
EOT;

        $query = $db->query($sql, $booking_ids);
        $rows  = $query ? $query->getResultArray() : [];

        $by_booking = [];
        foreach ($rows as $row) {
            $booking_id = (int) ($row['booking_id'] ?? 0);
            if ($booking_id <= 0) {
                continue;
            }

            $by_booking[$booking_id][] = [
                'id'                => isset($row['id']) ? (int) $row['id'] : null,
                'booking_id'        => $booking_id,
                'medicine_id'       => isset($row['medicine_id']) ? (int) $row['medicine_id'] : null,
                'qty'               => isset($row['qty']) ? (float) $row['qty'] : null,
                'price'             => isset($row['price']) ? round((float) $row['price'], 2) : null,
                'amount'            => isset($row['amount']) ? round((float) $row['amount'], 2) : null,
                'is_free'           => isset($row['is_free']) ? (int) $row['is_free'] : null,
                'is_yakap'          => isset($row['is_yakap']) ? (int) $row['is_yakap'] : null,
                'is_gamot'          => isset($row['is_gamot']) ? (int) $row['is_gamot'] : null,
                'charge_to'         => $row['charge_to'] ?? null,
                'medicine_name'     => $row['medicine_name'] ?? null,
                'medicine_brand'    => $row['medicine_brand'] ?? null,
                'medicine_generic'  => $row['medicine_generic'] ?? null,
                'medicine_dosage'   => $row['medicine_dosage'] ?? null,
                'medicine_vat_type' => $row['medicine_vat_type'] ?? null,
            ];
        }

        return $by_booking;
    }

    /**
     * Yakap Konsulta lines from EMR philhealth_report (count × 680).
     */
    public function get_yakap_konsulta_lines_on_connection(
        BaseConnection $db,
        $date_from = null,
        $date_to = null
    ) {
        $key   = $this->encryption_key;
        $binds = [];
        $sql   = <<<EOT
SELECT
    pr.transaction_no,
    pr.patient_id,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    680 AS amount
FROM philhealth_report pr
JOIN patient ON patient.id = pr.patient_id
WHERE pr.is_deleted = 0
AND pr.tranche_number = 1
AND pr.validate_status = 'success'
EOT;

        if ($date_from) {
            $sql .= "\nAND DATE(pr.added_on) >= ?";
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= "\nAND DATE(pr.added_on) <= ?";
            $binds[] = $date_to;
        }

        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * Same rows as {@see Booking::get_all_transaction()}, on an explicit connection (branch DB).
     */
    public function get_all_send_out_transaction_on_connection(
        BaseConnection $db,
        $facility_id = null,
        $date_from = null,
        $date_to = null,
        $charge_to = null,
        $page = null,
        $data_size = null
    ) {
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
        $query = $db->query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }
}
