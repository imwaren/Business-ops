<?php
namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Patient_master extends MYT_Model
{
    protected $DBGroup          = 'master';
    protected $table            = 'patient';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $allowedFields    = ['id', 'branch_id', 'patient_no', 'is_vip', 'first_name', 'last_name', 'middle_name', 'suffix', 'birthdate', 'contact_no', 'email', 'sex', 'emergency_contact', 'emergency_contact_no', 'relation_w_contact', 'senior_id', 'pwd_id', 'country', 'province', 'city', 'barangay', 'street', 'zip_code', 'civil_status', 'occupation', 'nationality', 'religion', 'phic_membership_type', 'phic_pin', 'phic_dependent_pin', 'phic_last_name', 'phic_first_name', 'phic_middle_name', 'phic_birthdate', 'phic_contact_no', 'phic_suffix', 'phic_enlistment_date', 'is_email_consent', 'phic_enlistment_status', 'phic_package_type', 'phic_atc', 'file_url', 'remarks', 'blood_type', 'fpe_done', 'added_by', 'added_on', 'updated_by', 'updated_on', 'is_deleted'];

    /**
     * Get by patient no or combination of last name / birthdate
     */
    public function search_record($patient_id = null, $term = null, $page = null, $data_size = null, $branch_ids = null)
    {
        $key = $this->encryption_key;

        $sql = <<<EOT
SELECT patient.id,
    patient.branch_id, 
    patient.sex, 
    patient.is_email_consent,
    CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) AS birthdate,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    CAST(AES_DECRYPT(patient.email, '$key') AS CHAR) AS email,
    CAST(AES_DECRYPT(patient.contact_no, '$key') AS CHAR) AS contact_no
FROM patient
WHERE patient.is_deleted = 0
EOT;

        [$filter_sql, $binds] = $this->_search_record_filters($patient_id, $term, $branch_ids);
        if ($filter_sql === false) {
            return [];
        }
        $sql .= $filter_sql;

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Count matching patients in SQL without materializing the full result set.
     */
    public function count_search_record($patient_id = null, $term = null, $branch_ids = null): int
    {
        [$filter_sql, $binds] = $this->_search_record_filters($patient_id, $term, $branch_ids);
        if ($filter_sql === false) {
            return 0;
        }

        $sql = <<<EOT
SELECT COUNT(*) AS total
FROM patient
WHERE patient.is_deleted = 0
{$filter_sql}
EOT;
        $query = $this->db->query($sql, $binds);
        return $query ? (int) ($query->getRowArray()['total'] ?? 0) : 0;
    }

    /** Build identical bound filters for patient list and count queries. */
    private function _search_record_filters($patient_id, $term, $branch_ids): array
    {
        $key = $this->encryption_key;
        $sql = '';
        $binds = [];

        if (is_array($branch_ids)) {
            if ($branch_ids === []) {
                return [false, []];
            }
            $placeholders = implode(',', array_fill(0, count($branch_ids), '?'));
            $sql .= "\nAND patient.branch_id IN ({$placeholders})";
            $binds = array_merge($binds, array_values($branch_ids));
        }

        if (! empty($patient_id)) {
            $sql .= "\nAND patient.id = ?";
            $binds[] = $patient_id;
        }

        if (! empty($term)) {
            $sql .= <<<EOT

AND (
    TRIM(CONCAT_WS(
        ' ',
        CONCAT(CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ','),
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR),
        CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR),
        CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR)
    )) LIKE ?
    OR patient.id LIKE ?
)
EOT;
            $binds[] = '%' . $term . '%';
            $binds[] = '%' . $term . '%';
        }

        return [$sql, $binds];
    }

    /**
     * Get by patient no or combination of last name / birthdate
     */
    public function get_patient_name($patient_id = null)
    {
        $key = $this->encryption_key;

        $sql = <<<EOT
SELECT TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM patient
WHERE patient.is_deleted = 0
EOT;

        $binds = [];

        if (! empty($patient_id)) {
            $sql .= " AND patient.id = ?";
            $binds[] = $patient_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getRow() : false;
    }

    /**
     * Batch-load patient names by id. Returns [id => patient_name].
     */
    public function get_patient_names_by_ids($patient_ids = null): array
    {
        if (is_string($patient_ids)) {
            $patient_ids = explode(',', $patient_ids);
        }
        if (!is_array($patient_ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter($patient_ids, static function ($v) {
            return $v !== null && $v !== '';
        })));
        if (!$ids) {
            return [];
        }

        $key = $this->encryption_key;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT patient.id,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM patient
WHERE patient.is_deleted = 0
AND patient.id IN ($placeholders)
EOT;
        $query = $this->db->query($sql, $ids);
        if (!$query) {
            return [];
        }
        $map = [];
        foreach ($query->getResultArray() as $row) {
            $map[$row['id']] = $row['patient_name'];
        }
        return $map;
    }

    /**
     * Get by patient no or combination of last name / birthdate
     */
    public function get_patient_by_name($patient_id = null, $first_name = null, $last_name = null, $middle_name = null, $date_of_birth = null, $sex = null)
    {
        $key = $this->encryption_key;

        $sql = <<<EOT
SELECT TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    patient.id,
    patient.branch_id,
    CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR) AS last_name,
    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR) AS first_name,
    CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR) AS middle_name,
    CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR) AS suffix,
    CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) AS birthdate,
    LOWER(patient.sex) AS sex
FROM patient
WHERE patient.is_deleted = 0
EOT;

        $binds = [];

        if (!empty($patient_id)) {
            $sql .= " AND patient.id = ? ";
            $binds[] = $patient_id;
        }

        if (!empty($first_name)) {
            $sql .= " AND LOWER(CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR)) LIKE ? ";
            $binds[] = $first_name;
        }

        if (!empty($last_name)) {
            $sql .= " AND LOWER(CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR)) LIKE ? ";
            $binds[] = $last_name;
        }

        if (!empty($middle_name)) {
            $sql .= " AND LOWER(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR)) LIKE ? ";
            $binds[] = $middle_name;
        }

        if (!empty($date_of_birth)) {
            $sql .= " AND CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) = ? ";
            $binds[] = $date_of_birth;
        }

        if (!empty($sex)) {
            $sql .= " AND LOWER(patient.sex) = ? ";
            $binds[] = $sex;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getRow() : false;
    }

    /**
     * Batch-load patients by first/last/middle + birthdate using the same
     * AES_DECRYPT + LIKE match as get_patient_by_name().
     *
     * @param array $people List of [first_name, last_name, middle_name, birthdate]
     * @return array
     */
    public function get_patients_by_identities(array $people): array
    {
        if (empty($people)) {
            return [];
        }

        $seen = [];
        $unique = [];
        foreach ($people as $person) {
            $first = $person['first_name'] ?? '';
            $last = $person['last_name'] ?? '';
            $middle = $person['middle_name'] ?? '';
            $dob = $person['birthdate'] ?? '';
            $key = strtolower(trim((string) $first) . '|' . trim((string) $last) . '|' . trim((string) $middle) . '|' . trim((string) $dob));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = [
                'first_name' => $first,
                'last_name' => $last,
                'middle_name' => $middle,
                'birthdate' => $dob,
            ];
        }

        if (!$unique) {
            return [];
        }

        $key = $this->encryption_key;
        $select = <<<EOT
SELECT TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    patient.id,
    patient.branch_id,
    CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR) AS last_name,
    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR) AS first_name,
    CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR) AS middle_name,
    CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR) AS suffix,
    CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) AS birthdate,
    LOWER(patient.sex) AS sex
FROM patient
WHERE patient.is_deleted = 0
AND (
EOT;

        $rows = [];
        foreach (array_chunk($unique, 50) as $chunk) {
            $binds = [];
            $orGroups = [];
            foreach ($chunk as $person) {
                $conds = [];
                if ($person['first_name'] !== '' && $person['first_name'] !== null) {
                    $conds[] = "LOWER(CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR)) LIKE ?";
                    $binds[] = $person['first_name'];
                }
                if ($person['last_name'] !== '' && $person['last_name'] !== null) {
                    $conds[] = "LOWER(CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR)) LIKE ?";
                    $binds[] = $person['last_name'];
                }
                if ($person['middle_name'] !== '' && $person['middle_name'] !== null) {
                    $conds[] = "LOWER(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR)) LIKE ?";
                    $binds[] = $person['middle_name'];
                }
                if ($person['birthdate'] !== '' && $person['birthdate'] !== null) {
                    $conds[] = "CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) = ?";
                    $binds[] = $person['birthdate'];
                }
                if ($conds) {
                    $orGroups[] = '(' . implode(' AND ', $conds) . ')';
                }
            }
            if (!$orGroups) {
                continue;
            }

            $sql = $select . implode(' OR ', $orGroups) . ')';
            $query = $this->db->query($sql, $binds);
            if ($query) {
                foreach ($query->getResult() as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }
}
