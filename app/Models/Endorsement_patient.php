<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_patient extends MYT_Model
{
    protected $table = 'endorsement_patient';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'endorsement_service_id',
        'patient_id',
        'type',
        'type_id',
        'with_consultation',
        'type_price',
        'mark_up',
        'total_amount',
        'charge_to',
        'charge_to_ids',
        'package_ids',
        'is_add_on',
        'branch_id',
        'mobile_van_id'
    ];

    public function get_details($endorsement_id = null, $page = null, $data_size = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_patient.*,
    CASE 
        WHEN endorsement_patient.type = 'lab_test' THEN lab_test.name
        WHEN endorsement_patient.type = 'package' THEN package.name
    END AS type_name,
    CASE 
        WHEN endorsement_patient.type = 'lab_test' THEN lab_test.price
        WHEN endorsement_patient.type = 'package' THEN package.price
    END AS type_price,
    patient.sex,
    CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) AS birthdate,
    CAST(AES_DECRYPT(patient.civil_status, '$key') AS CHAR) AS civil_status,
    CAST(AES_DECRYPT(patient.email, '$key') AS CHAR) AS email,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR) AS last_name,
    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR) AS first_name,
    CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR) AS middle_name,
    CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR) AS suffix,
    (
        SELECT GROUP_CONCAT(mobile_van.name SEPARATOR ', ') 
        FROM mobile_van 
        WHERE FIND_IN_SET(mobile_van.id, endorsement_patient.mobile_van_id)
    ) AS mobile_van_names
FROM endorsement_patient
LEFT JOIN patient ON patient.id = endorsement_patient.patient_id
LEFT JOIN lab_test ON lab_test.id = endorsement_patient.type_id AND endorsement_patient.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = endorsement_patient.type_id AND endorsement_patient.type = 'package' AND package.is_deleted = 0
WHERE endorsement_patient.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_patient.endorsement_id', $endorsement_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /** Resolve only explicitly uploaded selections after batch insertion. */
    public function get_selected_upload_rows($endorsement_id, array $selected_rows)
    {
        if (!$selected_rows) {
            return [];
        }

        $fields = ['patient_id', 'endorsement_service_id', 'type', 'type_id', 'branch_id', 'charge_to', 'charge_to_ids'];
        $rows = [];
        foreach (array_chunk($selected_rows, 100) as $selected_batch) {
            $binds = [$endorsement_id];
            $sql = <<<EOT
SELECT id, patient_id, endorsement_service_id, type, type_id, branch_id, charge_to, charge_to_ids
FROM endorsement_patient
WHERE endorsement_id = ? AND is_deleted = 0 AND COALESCE(is_add_on, 0) = 0
EOT;
            $conditions = [];
            foreach ($selected_batch as $selection) {
                $field_conditions = [];
                foreach ($fields as $field) {
                    $field_conditions[] = "COALESCE($field, '') = ?";
                    $binds[] = (string) ($selection[$field] ?? '');
                }
                $conditions[] = '(' . implode(' AND ', $field_conditions) . ')';
            }
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ') ORDER BY id ASC';
            $query = $this->db->query($sql, $binds);
            if (!$query) {
                return false;
            }
            $rows = array_merge($rows, $query->getResultArray());
        }
        return $rows;
    }

    public function get_details_for_worklist($endorsement_id = null, $patient_ids = null, $page = null, $data_size = null, $endorsement_patient_ids = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT 
    endorsement_patient.id,
    endorsement_patient.endorsement_id,
    endorsement_patient.endorsement_service_id,
    endorsement_patient.patient_id,
    endorsement_patient.charge_to,
    endorsement_patient.charge_to_ids,
    endorsement_patient.branch_id,
    endorsement_patient.is_availed,

    -- Original type metadata (preserved as-is)
    endorsement_patient.type AS original_type,
    endorsement_patient.type_id AS original_type_id,
    CASE 
        WHEN endorsement_patient.type = 'lab_test' THEN lab_test.name
        WHEN endorsement_patient.type = 'package' THEN package.name
    END AS original_type_name,

    -- Normalized to lab_test always
    'lab_test' AS type,
    COALESCE(pkg_lab_test.id, lab_test.id) AS type_id,
    COALESCE(pkg_lab_test.name, lab_test.name) AS type_name,
    COALESCE(pkg_lab_test.price, lab_test.price) AS type_price,

    patient.sex,
    CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) AS birthdate,
    CAST(AES_DECRYPT(patient.civil_status, '$key') AS CHAR) AS civil_status,
    CAST(AES_DECRYPT(patient.email, '$key') AS CHAR) AS email,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR) AS last_name,
    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR) AS first_name,
    CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR) AS middle_name,
    CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR) AS suffix,

    endorsement_patient.mobile_van_id,
    endorsement_patient.added_on,
    endorsement_patient.added_by,
    endorsement_patient.updated_on,
    endorsement_patient.updated_by,
    endorsement_patient.is_deleted

FROM endorsement_patient
LEFT JOIN patient ON patient.id = endorsement_patient.patient_id

-- Direct lab_test
LEFT JOIN lab_test ON lab_test.id = endorsement_patient.type_id 
    AND endorsement_patient.type = 'lab_test' 
    AND lab_test.is_deleted = 0

-- Package → explode via package_detail → lab_test
LEFT JOIN package ON package.id = endorsement_patient.type_id 
    AND endorsement_patient.type = 'package' 
    AND package.is_deleted = 0
LEFT JOIN package_detail ON package_detail.package_id = package.id 
    AND package_detail.is_deleted = 0
LEFT JOIN lab_test AS pkg_lab_test ON pkg_lab_test.id = package_detail.lab_test_id 
    AND pkg_lab_test.is_deleted = 0

WHERE endorsement_patient.is_deleted = 0
AND (pkg_lab_test.category_id = '43' OR lab_test.category_id = '43')
EOT;

        if ($endorsement_id) {
            $sql .= <<<EOT

AND endorsement_patient.endorsement_id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        if ($patient_ids) {
            $patient_ids = str_replace('"', '', $patient_ids);
            
            // Explode into array and trim whitespace from each ID
            $ids_array = array_map('trim', explode(',', $patient_ids));
            
            // Build placeholders: ?,?,?
            $placeholders = implode(',', array_fill(0, count($ids_array), '?'));
            
            $sql .= "\nAND endorsement_patient.patient_id IN ($placeholders)";
            
            // Merge individual IDs into $binds (not as a nested array)
            foreach ($ids_array as $id) {
                $binds[] = $id;
            }
        }

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_patient.id', $endorsement_patient_ids)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_patients($endorsement_id = null, $type = null, $term = null, $page = null, $data_size = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_patient.id, endorsement_patient.endorsement_id, endorsement_patient.patient_id, 
    patient.sex AS patient_sex,
    CAST(AES_DECRYPT(patient.email, '$key') AS CHAR) AS patient_email,
    CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) AS patient_birthdate,
    CAST(AES_DECRYPT(patient.civil_status, '$key') AS CHAR) AS patient_civil_status,
    CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR) AS patient_last_name,
    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR) AS patient_first_name,
    CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR) AS patient_middle_name,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM endorsement_patient
LEFT JOIN patient ON patient.id = endorsement_patient.patient_id
WHERE endorsement_patient.is_deleted = 0 
EOT;

        if ($endorsement_id) {
            $sql .= <<<EOT

AND endorsement_patient.endorsement_id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND endorsement_patient.type = ?
EOT;
            $binds[] = $type;
        }

        if (!empty($term)) {
            $sql .= " AND TRIM(
                CONCAT_WS(
                    ' ',
                    CONCAT(CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ','),
                    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR),
                    CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR),
                    CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR)
                )
            ) LIKE ? ";
            $binds[] = "%$term%";
        }

        // GROUP BY endorsement_id and patient_id to avoid duplicates
        $sql .= " GROUP BY endorsement_patient.endorsement_id, endorsement_patient.patient_id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_patient_services($endorsement_id = null, $patient_id = null, $page = null, $data_size = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_patient.*,
    CASE 
        WHEN endorsement_patient.type = 'lab_test' THEN lab_test.name
        WHEN endorsement_patient.type = 'package' THEN package.name
    END AS type_name,
    CASE 
        WHEN endorsement_patient.type = 'lab_test' THEN lab_test.price
        WHEN endorsement_patient.type = 'package' THEN package.price
    END AS type_price,
    company.name AS charge_to_name
FROM endorsement_patient
LEFT JOIN lab_test ON lab_test.id = endorsement_patient.type_id AND endorsement_patient.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = endorsement_patient.type_id AND endorsement_patient.type = 'package' AND package.is_deleted = 0
LEFT JOIN company ON company.id = endorsement_patient.charge_to_ids AND endorsement_patient.charge_to_ids IS NOT NULL
WHERE endorsement_patient.is_deleted = 0 
EOT;

        if ($endorsement_id) {
            $sql .= <<<EOT

AND endorsement_patient.endorsement_id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        if ($patient_id) {
            $sql .= <<<EOT

AND endorsement_patient.patient_id = ?
EOT;
            $binds[] = $patient_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_patient_for_billing($endorsement_ids = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_patient.*,
    CASE
        WHEN endorsement_patient.type = 'lab_test' THEN lab_test.name
        WHEN endorsement_patient.type = 'package' THEN package.name
    END AS type_name,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM endorsement_patient
LEFT JOIN patient ON patient.id = endorsement_patient.patient_id
LEFT JOIN lab_test ON lab_test.id = endorsement_patient.type_id AND endorsement_patient.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = endorsement_patient.type_id AND endorsement_patient.type = 'package' AND package.is_deleted = 0
WHERE endorsement_patient.is_deleted = 0 
EOT;

        if ($endorsement_ids) {
            $ids = array_values(array_filter((array) $endorsement_ids, static function ($id) {
                return $id !== null && $id !== '';
            }));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql .= "\nAND endorsement_patient.endorsement_id IN ($placeholders)";
                foreach ($ids as $id) {
                    $binds[] = $id;
                }
            }
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResult() : false;
    }

    public function get_patient_by_endorsement($endorsement_ids = null, $first_name = null, $last_name = null, $middle_name = null, $date_of_birth = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_patient.*,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM endorsement_patient
INNER JOIN patient ON patient.id = endorsement_patient.patient_id AND patient.is_deleted = 0
WHERE endorsement_patient.is_deleted = 0 
EOT;

        if ($endorsement_ids) {
            $ids = array_values(array_filter((array) $endorsement_ids, static function ($id) {
                return $id !== null && $id !== '';
            }));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql .= "\nAND endorsement_patient.endorsement_id IN ($placeholders)";
                foreach ($ids as $id) {
                    $binds[] = $id;
                }
            }
        }

        if (!empty($first_name)) {
            $sql .= " AND LOWER(CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR)) = ?";
            $binds[] = strtolower($first_name);
        }

        if (!empty($last_name)) {
            $sql .= " AND LOWER(CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR)) = ?";
            $binds[] = strtolower($last_name);
        }

        if (!empty($middle_name)) {
            $sql .= " AND LOWER(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR)) = ?";
            $binds[] = strtolower($middle_name);
        }

        if (!empty($date_of_birth)) {
            $sql .= " AND CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) = ?";
            $binds[] = $date_of_birth;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResult() : false;
    }

}
