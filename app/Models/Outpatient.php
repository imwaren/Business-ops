<?php

namespace App\Models;

use App\Core\MYT_Model;

class Outpatient extends MYT_Model
{
    protected $table = 'outpatient';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'patient_id',
        'doctor_id',
        'assessment_id',
        'followup_on',
        'professional_fee',
        'professional_fee_method',
        'plan',
        'hmo_type',
        'corporate_type',
        'hmo_id',
        'reference_no',
        'sales_invoice_no',
        'referred_to',
        'referral_facility',
        'doctor_schedule',
        'doctor_contact',
        'is_clinica_doc',
        'with_medical_certificate',
        'ádmin_fee',
        'next_consultation_free'
    ];

    public function get_details($where = null, $page = null, $assessment_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT outpatient.*,
    IFNULL(doctor.full_name, '') as doctor_name
FROM outpatient
LEFT JOIN patient ON patient.id = outpatient.patient_id
LEFT JOIN user AS doctor ON doctor.id = outpatient.doctor_id
WHERE outpatient.is_deleted = 0
EOT;

        if ($where) {
            foreach ($where as $key => $value) {
                $sql .= <<<EOT

AND outpatient.$key ?
EOT;
                $binds[] = $value;
            }
        }

        if ($assessment_id) {
            $sql .= <<<EOT

AND outpatient.assessment_id = ?
EOT;
            $binds[] = $assessment_id;
        }

        if ($page) {
            $page -= 1;
            $offset = $page * 10;
            $sql .= " LIMIT $offset, 10";
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_pf_charged($date_from = null, $date_to = null, $doctor_id = null, $physician_type_id = null, $page = null, $role_type = null)
    {
        $today = date('Y-m-d');
        $address = "CONCAT_WS(', ', AES_DECRYPT(patient.street, '{$this->encryption_key}'), AES_DECRYPT(patient.barangay, '{$this->encryption_key}'), AES_DECRYPT(patient.province, '{$this->encryption_key}'), AES_DECRYPT(patient.city, '{$this->encryption_key}'), AES_DECRYPT(patient.zip_code, '{$this->encryption_key}')) as address";

        $sql = <<<EOT
SELECT UNION_TABLE.*,
    qa.checked_up_on,
    qa.ended_on AS queue_ended_on,
    qa.processing_time
FROM (
    SELECT DATE(assessment.added_on) AS assessment_date,
        AES_DECRYPT(patient.birthdate, '{$this->encryption_key}') AS birthdate,
        $address,
        CASE
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 0 AND TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') > 1 THEN CONCAT(TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' mos')
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 0 AND TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 1 THEN CONCAT(TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' mo')
            ELSE CONCAT(TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' y/o')
        END AS age,
        CASE
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), ?) >= 14 THEN 'adult'
            ELSE 'pedia'
        END AS patient_type,
        CASE
            WHEN (
                SELECT COUNT(*)
                FROM assessment
                WHERE assessment.patient_id = patient.id
            ) > 1 THEN 'revisit'
            ELSE 'new'
        END AS visit_type,
        CASE
            WHEN doctor.role_name = 'admin' THEN 'ns'
            ELSE 'vs'
        END AS physician_type,
        outpatient.followup_on,
        outpatient.professional_fee AS professional_fee,
        outpatient.professional_fee_method AS professional_fee_method,
        'outpatient' AS type,
        outpatient.doctor_id,
        IFNULL(doctor.full_name, '') as doctor_name,
        IFNULL(doctor.ptr_no, '') AS ptr_no,
        IFNULL(doctor.license_no, '') AS license_no,
        IFNULL(doctor.specializations, '') AS specializations,
        assessment.is_deleted,
        CONCAT_WS(",",
            NULLIF((SELECT GROUP_CONCAT(assessment_diagnosis.diagnosis_name SEPARATOR ",")
                    FROM assessment_diagnosis
                    WHERE is_deleted = 0
                    AND assessment_diagnosis.diagnosis_name IS NOT NULL
                    AND assessment_diagnosis.diagnosis_name != ""
                    AND assessment_diagnosis.assessment_id = assessment.id), 'N/A'),
            NULLIF(NULLIF(TRIM(assessment.checkup_note), ''), 'N/A')
        ) AS diagnosis,
        assessment.`procedure`,
        assessment.complaint as chief_complaint,
        outpatient.hmo_type,
        outpatient.corporate_type,
        outpatient.sales_invoice_no,
        CONCAT_WS(' ', CONCAT(AES_DECRYPT(patient.last_name, '{$this->encryption_key}'), ', ', AES_DECRYPT(patient.first_name, '{$this->encryption_key}')), IF(AES_DECRYPT(patient.middle_name, '{$this->encryption_key}') = 'N/A', '', AES_DECRYPT(patient.middle_name, '{$this->encryption_key}')), AES_DECRYPT(patient.suffix, '{$this->encryption_key}')) as formatted_patient_name,
        doctor.role_name,
        outpatient.patient_id,
        pe.blood_pressure,
        pe.height,
        pe.weight,
        pe.pulse_rate,
        pe.respiratory_rate,
        pe.bmi,
        pe.temperature,
        pe.o2_sat,
        pe.pe_general,
        pe.pe_head_and_neck,
        pe.pe_heent,
        pe.pe_mouth_and_throat,
        pe.pe_chest_and_lungs,
        pe.pe_cardiovascular,
        pe.pe_abdomen,
        pe.pe_extremities,
        pe.pe_skin,
        pe.pe_neurological,
        pe.pe_genitalia,
        pe.pe_back,
        pe.pe_rectal,
        pe.pe_nose_sinuses,
        pe.pe_eyes_ears,
        assessment.id AS assessment_id
    FROM outpatient
    LEFT JOIN
    (
        SELECT user.id, user.full_name, user.ptr_no, user.license_no, user.role_name, user.role_id, specialization.name AS specializations
        FROM user
        LEFT JOIN specialization ON specialization.id = user.specializations
        GROUP BY user.id
    ) AS doctor ON doctor.id = outpatient.doctor_id
    LEFT JOIN assessment ON assessment.id = outpatient.assessment_id
    LEFT JOIN patient ON patient.id = outpatient.patient_id
    LEFT JOIN physical_exam AS pe ON pe.assessment_id = assessment.id AND pe.is_deleted = 0

    UNION ALL

    SELECT DATE(assessment.added_on) AS assessment_date,
        AES_DECRYPT(patient.birthdate, '{$this->encryption_key}') AS birthdate,
        $address,
        CASE
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 0 AND TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') > 1 THEN CONCAT(TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' mos')
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 0 AND TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 1 THEN CONCAT(TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' mo')
            ELSE CONCAT(TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' y/o')
        END AS age,
        CASE
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), ?) >= 14 THEN 'adult'
            ELSE 'pedia'
        END AS patient_type,
        CASE
            WHEN (
                SELECT COUNT(*)
                FROM assessment
                WHERE assessment.patient_id = patient.id
            ) > 1 THEN 'revisit'
            ELSE 'new'
        END AS visit_type,
        CASE
            WHEN doctor.role_name = 'admin' THEN 'ns'
            ELSE 'vs'
        END AS physician_type,
        NULL AS followup_on,
        inpatient.professional_fee AS professional_fee,
        inpatient.professional_fee_method AS professional_fee_method,
        'admit' AS type,
        inpatient.doctor_id,
        IFNULL(doctor.full_name, '') as doctor_name,
        IFNULL(doctor.ptr_no, '') AS ptr_no,
        IFNULL(doctor.license_no, '') AS license_no,
        IFNULL(doctor.specializations, '') AS specializations,
        assessment.is_deleted,
        CONCAT_WS(",",
            NULLIF((SELECT GROUP_CONCAT(assessment_diagnosis.diagnosis_name SEPARATOR ",")
                    FROM assessment_diagnosis
                    WHERE is_deleted = 0
                    AND assessment_diagnosis.diagnosis_name IS NOT NULL
                    AND assessment_diagnosis.diagnosis_name != ""
                    AND assessment_diagnosis.assessment_id = assessment.id), 'N/A'),
            NULLIF(NULLIF(TRIM(assessment.checkup_note), ''), 'N/A')
        ) AS diagnosis,
        assessment.`procedure`,
        assessment.complaint as chief_complaint,
        '' as hmo_type,
        '' as corporate_type,
        '' as sales_invoice_no,
        CONCAT_WS(' ', CONCAT(AES_DECRYPT(patient.last_name, '{$this->encryption_key}'), ', ', AES_DECRYPT(patient.first_name, '{$this->encryption_key}')), IF(AES_DECRYPT(patient.middle_name, '{$this->encryption_key}') = 'N/A', '', AES_DECRYPT(patient.middle_name, '{$this->encryption_key}')), AES_DECRYPT(patient.suffix, '{$this->encryption_key}')) as formatted_patient_name,
        doctor.role_name,
        inpatient.patient_id,
        pe.blood_pressure,
        pe.height,
        pe.weight,
        pe.pulse_rate,
        pe.respiratory_rate,
        pe.bmi,
        pe.temperature,
        pe.o2_sat,
        pe.pe_general,
        pe.pe_head_and_neck,
        pe.pe_heent,
        pe.pe_mouth_and_throat,
        pe.pe_chest_and_lungs,
        pe.pe_cardiovascular,
        pe.pe_abdomen,
        pe.pe_extremities,
        pe.pe_skin,
        pe.pe_neurological,
        pe.pe_genitalia,
        pe.pe_back,
        pe.pe_rectal,
        pe.pe_nose_sinuses,
        pe.pe_eyes_ears,
        assessment.id AS assessment_id
    FROM inpatient
    LEFT JOIN
    (
        SELECT user.id, user.full_name, user.ptr_no, user.license_no, user.role_name, user.role_id, specialization.name AS specializations
        FROM user
        LEFT JOIN specialization ON specialization.id = user.specializations
        GROUP BY user.id
    ) AS doctor ON doctor.id = inpatient.doctor_id
    LEFT JOIN assessment ON assessment.id = inpatient.assessment_id
    LEFT JOIN patient ON patient.id = inpatient.patient_id
    LEFT JOIN physical_exam AS pe ON pe.assessment_id = assessment.id AND pe.is_deleted = 0

    UNION ALL

    SELECT DATE(pe.added_on) AS assessment_date,
        AES_DECRYPT(patient.birthdate, '{$this->encryption_key}') AS birthdate,
        $address,
        CASE
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 0 AND TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') > 1 THEN CONCAT(TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' mos')
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 0 AND TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today') <= 1 THEN CONCAT(TIMESTAMPDIFF(MONTH, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' mo')
            ELSE CONCAT(TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), '$today'), ' y/o')
        END AS age,
        CASE
            WHEN TIMESTAMPDIFF(YEAR, AES_DECRYPT(patient.birthdate, '{$this->encryption_key}'), ?) >= 14 THEN 'adult'
            ELSE 'pedia'
        END AS patient_type,
        NULL AS visit_type,
        CASE
            WHEN doctor.role_name = 'admin' THEN 'ns'
            ELSE 'vs'
        END AS physician_type,
        NULL AS followup_on,
        NULL AS professional_fee,
        NULL AS professional_fee_method,
        IFNULL(pe.type, 'physical_exam') AS type,
        pe.doctor_id,
        IFNULL(doctor.full_name, '') as doctor_name,
        IFNULL(doctor.ptr_no, '') AS ptr_no,
        IFNULL(doctor.license_no, '') AS license_no,
        IFNULL(doctor.specializations, '') AS specializations,
        pe.is_deleted,
        'N/A' AS diagnosis,
        NULL AS `procedure`,
        NULL AS chief_complaint,
        '' as hmo_type,
        '' as corporate_type,
        '' as sales_invoice_no,
        CONCAT_WS(' ', CONCAT(AES_DECRYPT(patient.last_name, '{$this->encryption_key}'), ', ', AES_DECRYPT(patient.first_name, '{$this->encryption_key}')), IF(AES_DECRYPT(patient.middle_name, '{$this->encryption_key}') = 'N/A', '', AES_DECRYPT(patient.middle_name, '{$this->encryption_key}')), AES_DECRYPT(patient.suffix, '{$this->encryption_key}')) as formatted_patient_name,
        doctor.role_name,
        pe.patient_id,
        pe.blood_pressure,
        pe.height,
        pe.weight,
        pe.pulse_rate,
        pe.respiratory_rate,
        pe.bmi,
        pe.temperature,
        pe.o2_sat,
        pe.pe_general,
        pe.pe_head_and_neck,
        pe.pe_heent,
        pe.pe_mouth_and_throat,
        pe.pe_chest_and_lungs,
        pe.pe_cardiovascular,
        pe.pe_abdomen,
        pe.pe_extremities,
        pe.pe_skin,
        pe.pe_neurological,
        pe.pe_genitalia,
        pe.pe_back,
        pe.pe_rectal,
        pe.pe_nose_sinuses,
        pe.pe_eyes_ears,
        NULL AS assessment_id
    FROM physical_exam AS pe
    LEFT JOIN
    (
        SELECT user.id, user.full_name, user.ptr_no, user.license_no, user.role_name, user.role_id, specialization.name AS specializations
        FROM user
        LEFT JOIN specialization ON specialization.id = user.specializations
        GROUP BY user.id
    ) AS doctor ON doctor.id = pe.doctor_id
    LEFT JOIN patient ON patient.id = pe.patient_id
    WHERE pe.assessment_id IS NULL
) AS UNION_TABLE
LEFT JOIN queue ON queue.assessment_id = UNION_TABLE.assessment_id AND queue.is_deleted = 0
LEFT JOIN
(
    SELECT queue_id,
        MIN(added_on) AS checked_up_on,
        MAX(ended_on) AS ended_on,
        SEC_TO_TIME(TIMESTAMPDIFF(SECOND, MIN(added_on), MAX(ended_on))) AS processing_time
    FROM queue_action
    WHERE is_deleted = 0
    GROUP BY queue_id
) AS qa ON qa.queue_id = queue.id
WHERE UNION_TABLE.is_deleted = 0
EOT;
        $binds = [
            $today,
            $today,
            $today
        ];

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND assessment_date BETWEEN ? AND ?
EOT;

            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($doctor_id) {
            $sql .= <<<EOT

AND UNION_TABLE.doctor_id = ?
EOT;
            $binds[] = $doctor_id;
        }
        if ($physician_type_id) {
            $sql .= <<<EOT

AND FIND_IN_SET(?, specializations)
EOT;
            $binds[] = $physician_type_id;
        }

        if ($role_type) {
            $sql .= <<<EOT

AND role_name = ?
EOT;
            $binds[] = $role_type;
        }

        if ($page) {
            $page -= 1;
            $offset = $page * 10;
            $sql .= " LIMIT $offset, 10";
        }

        $sql .= <<<EOT

ORDER BY assessment_date DESC
EOT;

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Outpatient visits with queue processing time — used by Doctor TAT (no inpatient / PE union).
     */
    public function get_doctor_tat_outpatient_rows(
        $date_from = null,
        $date_to = null,
        $doctor_id = null,
        $doctor_type = null,
        $term = null
    ) {
        $sql = <<<EOT
SELECT
    IFNULL(master_doctor.id, doctor.id) AS doctor_id,
    IFNULL(
        NULLIF(TRIM(CONCAT_WS(' ', master_doctor.first_name, master_doctor.middle_name, master_doctor.last_name)), ''),
        IFNULL(doctor.full_name, '')
    ) AS doctor_name,
    IFNULL(emr_role.name, '') AS doctor_type_name,
    qa.processing_time
FROM outpatient
LEFT JOIN
(
    SELECT user.id,
        user.full_name,
        user.master_doctor_id,
        user.role_id
    FROM user
    WHERE user.is_deleted = 0
) AS doctor ON doctor.id = outpatient.doctor_id
LEFT JOIN {MASTER_DB}.doctor AS master_doctor
    ON master_doctor.id = doctor.master_doctor_id
   AND master_doctor.is_deleted = 0
LEFT JOIN {OAKRIDGE_EMR_DB}.role AS emr_role
    ON emr_role.id = IFNULL(master_doctor.type, doctor.role_id)
   AND emr_role.is_deleted = 0
INNER JOIN assessment ON assessment.id = outpatient.assessment_id AND assessment.is_deleted = 0
LEFT JOIN queue ON queue.assessment_id = assessment.id AND queue.is_deleted = 0
LEFT JOIN
(
    SELECT queue_id,
        SEC_TO_TIME(TIMESTAMPDIFF(SECOND, MIN(added_on), MAX(ended_on))) AS processing_time
    FROM queue_action
    WHERE is_deleted = 0
    GROUP BY queue_id
) AS qa ON qa.queue_id = queue.id
WHERE outpatient.is_deleted = 0
EOT;
        $binds = [];

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND DATE(assessment.added_on) BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($doctor_id) {
            $sql .= <<<EOT

AND IFNULL(master_doctor.id, doctor.id) = ?
EOT;
            $binds[] = $doctor_id;
        }

        if ($doctor_type !== null && $doctor_type !== '') {
            $sql .= <<<EOT

AND IFNULL(master_doctor.type, doctor.role_id) = ?
EOT;
            $binds[] = $doctor_type;
        }

        if ($term !== null && trim((string) $term) !== '') {
            $sql .= <<<EOT

AND (
    IFNULL(doctor.full_name, '') LIKE ?
    OR TRIM(CONCAT_WS(' ', master_doctor.first_name, master_doctor.middle_name, master_doctor.last_name)) LIKE ?
)
EOT;
            $like = '%' . trim((string) $term) . '%';
            $binds[] = $like;
            $binds[] = $like;
        }

        $sql .= <<<EOT

ORDER BY assessment.added_on ASC
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }

    /**
     * Get Resident Fees
     */
    public function get_resident_fees($date_from = null, $date_to = null, $doctor_id = null, $page = null)
    {
        $sql = <<<EOT
SELECT *
FROM (
    SELECT
        DATE(assessment.added_on) AS assessment_date,
        IFNULL(doctor.full_name, '') AS doctor_name,
        IFNULL(doctor.ptr_no, '') AS ptr_no,
        IFNULL(doctor.license_no, '') AS license_no,
        outpatient.professional_fee AS professional_fee,
        outpatient.professional_fee_method AS professional_fee_method,
        'outpatient' AS type,
        doctor.id as doctor_id,
        outpatient.hmo_type,
        outpatient.corporate_type,
        CONCAT_WS(' ', CONCAT(patient.last_name, ', ', patient.first_name), patient.middle_name, patient.suffix) as formatted_patient_name
    FROM outpatient
    LEFT JOIN user AS doctor ON doctor.id = outpatient.doctor_id
    LEFT JOIN assessment ON assessment.id = outpatient.assessment_id
    LEFT JOIN patient ON patient.id = outpatient.patient_id
    WHERE outpatient.is_deleted = 0
        AND doctor.role_name = 'resident'
        AND assessment.status = 'ended'

    UNION ALL

    SELECT
        DATE(assessment.added_on) AS assessment_date,
        IFNULL(doctor.full_name, '') AS doctor_name,
        IFNULL(doctor.ptr_no, '') AS ptr_no,
        IFNULL(doctor.license_no, '') AS license_no,
        IFNULL(inpatient.professional_fee, 0) AS professional_fee,
        inpatient.professional_fee_method AS professional_fee_method,
        'inpatient' AS type,
        doctor.id as doctor_id,
        null as hmo_type,
        null as corporate_type,
        CONCAT_WS(' ', CONCAT(patient.last_name, ', ', patient.first_name), patient.middle_name, patient.suffix) as formatted_patient_name
    FROM inpatient
    LEFT JOIN user AS doctor ON doctor.id = inpatient.doctor_id
    LEFT JOIN assessment ON assessment.id = inpatient.assessment_id
    LEFT JOIN patient ON patient.id = inpatient.patient_id
    WHERE inpatient.is_deleted = 0
        AND doctor.role_name = 'resident'
        AND assessment.status = 'ended'
) AS fees
WHERE 1
EOT;
        $binds = [];

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND assessment_date BETWEEN ? AND ?
EOT;

            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($doctor_id) {
            $sql .= <<<EOT

AND doctor_id = ?
EOT;
            $binds[] = $doctor_id;
        }

        if ($page) {
            $page -= 1;
            $offset = $page * 10;
            $sql .= " LIMIT $offset, 10";
        }

        $sql .= <<<EOT

ORDER BY assessment_date DESC
EOT;

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Get number of Check-ups
     */
    public function no_of_checkups($pf_method = null, $date_from = null, $date_to = null, $doctor_id = null)
    {
        $sql = <<<EOT
SELECT COUNT(outpatient.id) AS no_of_checkups
FROM outpatient
LEFT JOIN assessment ON assessment.id = outpatient.assessment_id
WHERE outpatient.is_deleted = 0
AND assessment.status = 'ended'
EOT;
        $binds = [];

        if ($pf_method) {
            $sql .= <<<EOT

AND outpatient.professional_fee_method = ?
EOT;
            $binds[] = $pf_method;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND DATE(outpatient.added_on) BETWEEN ? and ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if (!empty($doctor_id)) {
            $sql .= <<<EOT

AND assessment.doctor_id = ?
EOT;
            $binds[] = $doctor_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray()[0]['no_of_checkups'] : false;
    }

    /**
     * Check if with Referral
     */
    public function get_referral($post_consultation_id)
    {
        $sql = <<<EOT
SELECT outpatient.*
FROM outpatient
LEFT JOIN post_consultation ON post_consultation.assessment_id = outpatient.assessment_id
WHERE outpatient.is_deleted = 0
    AND outpatient.referred_to IS NOT NULL
    AND outpatient.is_clinica_doc = 0
    AND post_consultation.id = ?
EOT;
        $binds = [$post_consultation_id];

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Get Referred Doctors outside Clinica
     */
    public function get_referral_outside()
    {
        $sql = <<<EOT
SELECT
    DISTINCT referred_to AS doc_name,
    0 AS is_clinica_doc
FROM outpatient
WHERE outpatient.is_deleted = 0
    AND is_clinica_doc = 0
EOT;
        $binds = [];

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}
