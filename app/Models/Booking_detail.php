<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Booking_detail extends MYT_Model
{
    protected $table = 'booking_details';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'transaction_id',
        'status',
    ];

    public function get_details($booking_id = null, $charge_to = null) {
        $binds = [];
        $sql = <<<EOT
SELECT booking_details.id,
    booking_details.result_status,
    booking_details.result_approval,
    booking_details.type,
    booking_details.test_id,
    booking_details.amount,
    endorsement_service.pro_rate_price,
    CASE 
        WHEN booking_details.type = 'lab' THEN lab_test.name
        WHEN booking_details.type = 'package' THEN package.name
        WHEN booking_details.type = 'wallet' THEN 'Medical Services'
    END AS type_name
FROM booking_details
LEFT JOIN patient ON patient.id = booking_details.patient_id
LEFT JOIN lab_test 
    ON booking_details.type = 'lab' 
    AND booking_details.test_id = lab_test.id
LEFT JOIN package 
    ON booking_details.type = 'package' 
    AND booking_details.test_id = package.id
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
EOT;

        if ($booking_id) {
            $sql .= <<<EOT

AND booking_details.booking_id = ?
EOT;
            $binds[] = $booking_id;
        }

        if ($charge_to) {
            $sql .= <<<EOT

AND booking_details.charge_to = ?
EOT;
            $binds[] = $charge_to;
        }

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_details_merged($booking_id = null, $facility_id = null) {
        $binds = [];

        $part1 = <<<EOT
SELECT booking_details.id,
    booking_details.result_status,
    booking_details.result_approval,
    booking_details.type,
    booking_details.test_id,
    CASE 
        WHEN booking_details.type = 'lab' THEN lab_test.name
        WHEN booking_details.type = 'package' THEN package.name
        WHEN booking_details.type = 'wallet' THEN 'Medical Services'
    END AS type_name
FROM booking_details
LEFT JOIN lab_test 
    ON booking_details.type = 'lab' 
    AND booking_details.test_id = lab_test.id
LEFT JOIN package 
    ON booking_details.type = 'package' 
    AND booking_details.test_id = package.id
WHERE booking_details.is_deleted = 0
EOT;

        if ($booking_id) {
            $part1 .= "\nAND booking_details.booking_id = ?";
            $binds[] = $booking_id;
        }

        if ($facility_id) {
            //use performed_at because it is the facility id
            $part1 .= "\nAND booking_details.performed_at = ?";
            $binds[] = $facility_id;
        }

        $part2 = <<<EOT
SELECT booking_package_details.id,
    booking_package_details.result_status,
    booking_package_details.result_approval,
    'lab' AS type,
    booking_package_details.test_id,
    lab_test.name AS type_name
FROM booking_package_details
LEFT JOIN booking_details 
    ON booking_package_details.booking_detail_id = booking_details.id
    AND booking_details.is_deleted = 0
LEFT JOIN lab_test 
    ON booking_package_details.test_id = lab_test.id
WHERE booking_package_details.is_deleted = 0
EOT;

        if ($booking_id) {
            $part2 .= "\nAND booking_details.booking_id = ?";
            $binds[] = $booking_id;
        }

        if ($facility_id) {
            $part2 .= "\nAND booking_package_details.performed_at = ?";
            $binds[] = $facility_id;
        }

        $sql = $part1 . "\nUNION\n" . $part2;

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Return distinct endorsement_ids that have at least one non-deleted booking_details row.
     *
     * @param array $endorsement_ids
     * @return array list of endorsement_id values found
     */
    public function get_endorsement_ids_with_bookings(array $endorsement_ids): array
    {
        $ids = array_values(array_unique(array_filter($endorsement_ids, static function ($v) {
            return $v !== null && $v !== '';
        })));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT DISTINCT booking_details.endorsement_id
FROM booking_details
WHERE booking_details.is_deleted = 0
AND booking_details.endorsement_id IN ($placeholders)
EOT;
        $query = $this->db->query($sql, $ids);
        if (!$query) {
            return [];
        }
        $found = [];
        foreach ($query->getResultArray() as $row) {
            if (isset($row['endorsement_id'])) {
                $found[] = $row['endorsement_id'];
            }
        }
        return $found;
    }

    /**
     * All booking_details (billed and unbilled) on the given endorsements, for slot counts.
     *
     * @param array<int|string> $endorsement_ids
     * @param array<int|string>|int|string|null $charge_to
     * @return array<int, array<string, mixed>>
     */
    public function get_booked_for_endorsements(array $endorsement_ids, $charge_to = null): array
    {
        $ids = array_values(array_unique(array_filter($endorsement_ids, static function ($v) {
            return $v !== null && $v !== '';
        })));
        if (!$ids) {
            return [];
        }

        $key = $this->encryption_key;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $binds = $ids;
        $sql = <<<EOT
SELECT booking_details.endorsement_id,
    booking_details.type,
    booking_details.test_id,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM booking_details
INNER JOIN booking ON booking.id = booking_details.booking_id AND booking.is_deleted = 0
LEFT JOIN patient ON patient.id = booking.patient_id
WHERE booking_details.is_deleted = 0
AND booking_details.endorsement_id IN ($placeholders)
EOT;

        $charge_ids = [];
        if (is_array($charge_to)) {
            foreach ($charge_to as $id) {
                if ($id === null || $id === '' || $id === '0') {
                    continue;
                }
                $charge_ids[] = (int) $id;
            }
            $charge_ids = array_values(array_unique($charge_ids));
        } elseif ($charge_to !== null && $charge_to !== '' && $charge_to !== '0') {
            $charge_ids[] = (int) $charge_to;
        }

        if ($charge_ids) {
            $charge_placeholders = implode(',', array_fill(0, count($charge_ids), '?'));
            $sql .= "\nAND booking_details.charge_to IN ($charge_placeholders)";
            foreach ($charge_ids as $id) {
                $binds[] = $id;
            }
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : [];
    }
}

?>