<?php
namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Patient_diagnostic extends MYT_Model
{
    protected $table            = 'patient';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $allowedFields    = ['id', 'master_id', 'is_vip', 'first_name', 'last_name', 'middle_name', 'suffix', 'birthdate', 'contact_no', 'email', 'sex', 'emergency_contact', 'emergency_contact_no', 'relation_w_contact', 'senior_id', 'pwd_id', 'country', 'province', 'city', 'barangay', 'street', 'zip_code', 'civil_status', 'occupation', 'nationality', 'religion', 'phic_membership_type', 'phic_pin', 'phic_dependent_pin', 'phic_last_name', 'phic_first_name', 'phic_middle_name', 'phic_birthdate', 'phic_contact_no', 'phic_suffix', 'phic_enlistment_date', 'phic_enlistment_status', 'phic_package_type', 'phic_atc', 'file_url', 'remarks', 'blood_type', 'fpe_done', 'added_by', 'added_on', 'updated_by', 'updated_on', 'is_deleted'];

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
LEFT JOIN {MASTER_DB}.patient AS patient_master ON patient_master.id = patient.master_id
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
     * Consent report: paginate patients first, then attach consent flags
     * so AES_DECRYPT / data_consent matching only run on the page rows.
     *
     * Recommended indexes (branch diagnostic DB):
     *   ALTER TABLE patient ADD INDEX idx_patient_deleted_added_id (is_deleted, added_on, id);
     *   ALTER TABLE informed_consent ADD INDEX idx_ic_master_deleted (master_patient_id, is_deleted);
     *   ALTER TABLE data_consent ADD INDEX idx_dc_name_dob (last_name, first_name, date_of_birth, is_deleted);
     */
    public function get_consent_report($date_from = null, $date_to = null, $information_consent = null, $registration_consent = null, $company_id = null, $endorsement_id = null, $term = null, $page = null, $data_size = null)
    {
        $key = $this->encryption_key;
        $binds = [];

        $inner_sql = "SELECT patient.id, patient.master_id, patient.sex, patient.birthdate,
            patient.first_name, patient.last_name, patient.middle_name,
            patient.suffix, patient.added_on, patient.email, patient.contact_no, patient.is_email_consent
        FROM patient
        WHERE patient.is_deleted = 0";

        $filter = $this->_consent_report_filters($key, $date_from, $date_to, $information_consent, $registration_consent, $company_id, $endorsement_id, $term);
        $inner_sql .= $filter['sql'];
        $binds = array_merge($binds, $filter['binds']);

        $inner_sql .= " ORDER BY patient.id";

        if ($page && $data_size) {
            $page -= 1;
            $offset = (int) ($page * $data_size);
            $limit = (int) $data_size;
            $inner_sql .= " LIMIT {$offset}, {$limit}";
        }

        $dc_match = $this->_data_consent_match_sql('p', $key);
        $dc_match_any = $dc_match . " AND dc.is_deleted = 0";
        $informed_consent_exists = $this->_informed_consent_exists_sql('p');
        $data_consent_exists = $this->_data_consent_exists_sql('p', $key);

        $sql = <<<EOT
SELECT p.id,
    pm.branch_id,
    p.sex,
    CAST(AES_DECRYPT(p.birthdate, '$key') AS CHAR) AS birthdate,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(p.last_name, '$key') AS CHAR), ', ',
        CAST(AES_DECRYPT(p.first_name, '$key') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(p.middle_name, '$key') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(p.suffix, '$key') AS CHAR), '')
    )) AS patient_name,
    CAST(AES_DECRYPT(p.email, '$key') AS CHAR) AS email,
    CAST(AES_DECRYPT(p.contact_no, '$key') AS CHAR) AS contact_no,
    p.is_email_consent,
    p.added_on AS date_added,
    CASE WHEN {$informed_consent_exists} THEN 1 ELSE 0 END AS is_informed_consent,
    CASE WHEN {$data_consent_exists} THEN 1 ELSE 0 END AS is_data_consent,
    IFNULL((
        SELECT dc.is_data_privay_consent FROM data_consent dc
        WHERE {$dc_match_any}
        LIMIT 1
    ), 0) AS data_privacy_consent
FROM ({$inner_sql}) p
LEFT JOIN {MASTER_DB}.patient AS pm ON pm.id = p.master_id
EOT;

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Count for consent report. Skips AES / consent joins when filters allow.
     */
    public function get_consent_report_count($date_from = null, $date_to = null, $information_consent = null, $registration_consent = null, $company_id = null, $endorsement_id = null, $term = null)
    {
        $key = $this->encryption_key;

        $sql = "SELECT COUNT(*) AS total_count
FROM patient
WHERE patient.is_deleted = 0";

        $filter = $this->_consent_report_filters($key, $date_from, $date_to, $information_consent, $registration_consent, $company_id, $endorsement_id, $term);
        $sql .= $filter['sql'];

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $filter['binds']);
        return $query ? $query->getRow()->total_count : 0;
    }

    /**
     * Shared WHERE fragments for consent report list/count (sargable dates, EXISTS filters).
     *
     * @return array{sql: string, binds: array}
     */
    private function _consent_report_filters($key, $date_from, $date_to, $information_consent, $registration_consent, $company_id, $endorsement_id, $term)
    {
        $sql = '';
        $binds = [];

        if (! empty($date_from)) {
            $sql .= " AND patient.added_on >= ?";
            $binds[] = $date_from;
        }

        if (! empty($date_to)) {
            $sql .= " AND patient.added_on < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $date_to;
        }

        if ($information_consent !== null && $information_consent !== '') {
            $exists = $this->_informed_consent_exists_sql('patient');
            $sql .= ((int) $information_consent === 1) ? " AND {$exists}" : " AND NOT {$exists}";
        }

        if ($registration_consent !== null && $registration_consent !== '') {
            $exists = $this->_data_consent_exists_sql('patient', $key);
            $sql .= ((int) $registration_consent === 1) ? " AND {$exists}" : " AND NOT {$exists}";
        }

        if (! empty($endorsement_id)) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM {MASTER_DB}.endorsement_patient ep
                WHERE ep.patient_id = patient.master_id
                  AND ep.endorsement_id = ?
                  AND ep.is_deleted = 0
            )";
            $binds[] = $endorsement_id;
        }

        if (! empty($company_id)) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM {MASTER_DB}.endorsement_patient ep
                INNER JOIN {MASTER_DB}.endorsement e
                    ON e.id = ep.endorsement_id AND e.is_deleted = 0
                WHERE ep.patient_id = patient.master_id
                  AND e.company_id = ?
                  AND ep.is_deleted = 0
            )";
            $binds[] = $company_id;
        }

        if (! empty($term)) {
            $sql .= " AND TRIM(
                CONCAT_WS(
                    ' ',
                    CONCAT(CAST(AES_DECRYPT(patient.last_name, '$key') AS CHAR), ','),
                    CAST(AES_DECRYPT(patient.first_name, '$key') AS CHAR),
                    CAST(AES_DECRYPT(patient.middle_name, '$key') AS CHAR)
                )
            ) LIKE ?";
            $binds[] = "%{$term}%";
        }

        return ['sql' => $sql, 'binds' => $binds];
    }

    /**
     * Whether the patient has a non-deleted informed consent on master_patient_id.
     */
    private function _informed_consent_exists_sql($patient_alias)
    {
        return "EXISTS (
            SELECT 1 FROM informed_consent ic
            WHERE ic.master_patient_id = {$patient_alias}.master_id AND ic.is_deleted = 0
        )";
    }

    /**
     * Whether the patient has a matching non-deleted data_consent (name/DOB) with privacy consent.
     */
    private function _data_consent_exists_sql($patient_alias, $key)
    {
        $match = $this->_data_consent_match_sql($patient_alias, $key);

        return "EXISTS (
            SELECT 1 FROM data_consent dc
            WHERE {$match}
              AND dc.is_data_privay_consent = 1
              AND dc.is_deleted = 0
        )";
    }

    /**
     * Equality match of decrypted patient name/DOB to data_consent columns.
     */
    private function _data_consent_match_sql($patient_alias, $key)
    {
        return "TRIM(CAST(AES_DECRYPT({$patient_alias}.first_name, '{$key}') AS CHAR) COLLATE utf8mb4_unicode_ci) = dc.first_name
            AND TRIM(CAST(AES_DECRYPT({$patient_alias}.last_name, '{$key}') AS CHAR) COLLATE utf8mb4_unicode_ci) = dc.last_name
            AND CAST(AES_DECRYPT({$patient_alias}.birthdate, '{$key}') AS DATE) = dc.date_of_birth";
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
     * Existing master_id values in this branch DB.
     *
     * @param array $master_ids
     * @return array [master_id => true]
     */
    public function get_existing_master_ids(array $master_ids): array
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
            $sql = "SELECT master_id FROM patient WHERE is_deleted = 0 AND master_id IN ($placeholders)";
            $query = $this->db->query($sql, $chunk);
            if (!$query) {
                continue;
            }
            foreach ($query->getResultArray() as $row) {
                if (isset($row['master_id']) && $row['master_id'] !== '' && $row['master_id'] !== null) {
                    $map[$row['master_id']] = true;
                }
            }
        }

        return $map;
    }
}