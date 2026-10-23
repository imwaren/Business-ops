<?php
namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Patient_emr extends MYT_Model
{
    protected $table            = 'patient';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $allowedFields    = ['id', 'master_patient_id', 'is_vip', 'first_name', 'last_name', 'middle_name', 'suffix', 'birthdate', 'contact_no', 'email', 'sex', 'emergency_contact', 'emergency_contact_no', 'relation_w_contact', 'senior_id', 'pwd_id', 'country', 'province', 'city', 'barangay', 'street', 'zip_code', 'civil_status', 'occupation', 'nationality', 'religion', 'phic_membership_type', 'phic_pin', 'phic_dependent_pin', 'phic_last_name', 'phic_first_name', 'phic_middle_name', 'phic_birthdate', 'phic_contact_no', 'phic_suffix', 'phic_enlistment_date', 'phic_enlistment_status', 'phic_package_type', 'phic_atc', 'file_url', 'remarks', 'blood_type', 'fpe_done', 'added_by', 'added_on', 'updated_by', 'updated_on', 'is_deleted'];

    /**
     * Get by patient no or combination of last name / birthdate
     */
    public function search_record($patient_id = null, $term = null)
    {
        $key = $this->encryption_key;

        $sql = <<<EOT
SELECT patient.id,
    patient_master.branch_id, 
    patient.sex, 
    CAST(AES_DECRYPT(patient.birthdate, '$key') AS CHAR) AS birthdate,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR), '')
    )) AS patient_name
FROM patient
LEFT JOIN {MASTER_DB}.patient AS patient_master ON patient_master.id = patient.master_patient_id
WHERE patient.is_deleted = 0
EOT;

        $binds = [];

        if (! empty($patient_id)) {
            $sql .= " AND patient.id = ?";
            $binds[] = $patient_id;
        }

        if (! empty($term)) {
            $sql .= " AND TRIM(
                CONCAT_WS(
                    ' ',
                    CONCAT(CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ','),
                    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR),
                    CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR),
                    CAST(AES_DECRYPT(patient.suffix, '$key') AS CHAR)
                )
            ) LIKE ? OR patient.id LIKE ?";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
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

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getRow() : false;
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

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getRow() : false;
    }

    /**
     * Existing master_patient_id values in this branch DB.
     *
     * @param array $master_ids
     * @return array [master_patient_id => true]
     */
    public function get_existing_master_patient_ids(array $master_ids): array
    {
        $ids = array_values(array_unique(array_filter($master_ids, static function ($v) {
            return $v !== null && $v !== '';
        })));
        if (!$ids) {
            return [];
        }

        $map = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT master_patient_id FROM patient WHERE is_deleted = 0 AND master_patient_id IN ($placeholders)";
            $query = $this->db->query($sql, $chunk);
            if (!$query) {
                continue;
            }
            foreach ($query->getResultArray() as $row) {
                if (isset($row['master_patient_id']) && $row['master_patient_id'] !== '' && $row['master_patient_id'] !== null) {
                    $map[$row['master_patient_id']] = true;
                }
            }
        }

        return $map;
    }
}