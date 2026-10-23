<?php
namespace App\Core;

namespace App\Models;

use App\Core\MYT_Model;

class Patient_queue extends MYT_Model
{
    protected $table         = 'patient_queue';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['id', 'patient_id', 'transaction_id', 'prev_queue_id', 'kiosk_series', 'lab_series', 'status', 'priority_type', 'section', 'location', 'lab_served_on', 'lab_skipped_on', 'lab_done_on', 'lab_served_by', 'lab_accomodated_by', 'lab_skipped_by', 'lab_added_on', 'lab_added_by','lab_accomodated_on', 'health_served_on', 'health_skipped_on', 'health_done_on', 'health_served_by', 'health_accomodated_by', 'health_skipped_by', 'diag_served_on', 'diag_skipped_on', 'diag_done_on', 'diag_served_by', 'diag_accomodated_by', 'diag_skipped_by', 'csr_served_on', 'is_called', 'csr_skipped_on', 'csr_done_on', 'csr_served_by', 'csr_accomodated_by', 'csr_skipped_by', 'medrecord_served_on', 'medrecord_served_by', 'medrecord_skipped_on', 'medrecord_skipped_by', 'added_by', 'added_on', 'updated_by', 'updated_on', 'is_deleted'];

    /**
     * Get id by Booking
     */
    public function get_id_by_booking($booking_id)
    {

        $db = db_connect();

        $sql = <<<EOT
SELECT patient_queue.id AS id
FROM patient_queue
WHERE patient_queue.is_deleted = 0
AND patient_queue.booking_id = ?
EOT;

        $binds = [$booking_id];
        $query = $db->query($sql, $binds);
        $res   = $query->getResult();

        if ($res) {
            return $res[0]->id;
        } else {
            return null;
        }

    }

    /**
     * Get All
     */
    public function get_all()
    {

        $db = db_connect();

        $sql = <<<EOT
SELECT patient_queue.patient_id, patient_queue.id AS queue_no, patient.first_name, patient.middle_name, patient.last_name, patient_queue.status
FROM patient_queue
LEFT JOIN patient ON patient.id = patient_queue.patient_id AND patient.is_deleted = 0
WHERE patient_queue.is_deleted = 0
AND booking_id IS NULL
ORDER BY patient_queue.id
EOT;

        $query = $db->query($sql);
        $res   = $query->getResultArray();

        if (! empty($res)) {
            return $res;
        } else {
            return null;
        }

    }

    /**
     * Get Now Serving
     */
    public function get_now_serving()
    {

        $date_today = date('Y-m-d');
        $db         = db_connect();

        $sql = <<<EOT
SELECT patient_queue.id AS id, patient.first_name AS first_name, patient.middle_name AS middle_name, patient.last_name AS last_name, user.name AS served_by, patient.birthdate, TIMESTAMPDIFF(YEAR, patient.birthdate, ?) AS age, patient_queue.serving_type, patient_queue.booking_id
FROM patient_queue
LEFT JOIN patient ON patient.id = patient_queue.patient_id
LEFT JOIN user ON user.id = patient_queue.attended_by
WHERE patient_queue.is_deleted = 0
AND DATE(patient_queue.added_on) = ?
AND patient_queue.status = 'attending'
EOT;

        $binds = [$date_today, $date_today];
        $query = $db->query($sql, $binds);
        $res   = $query->getResult();

        if (! empty($res)) {
            return $res;
        } else {
            return null;
        }

    }

    /**
     * Get By Params
     */
    public function get_by_params()
    {
        $date_today = date('Y-m-d');
        $db         = db_connect();

        $sql = <<<EOT
SELECT
    id,
    CONCAT(
        CASE priority_type
            WHEN 'regular' THEN 'RE'
            WHEN 'hmo' THEN 'RE'
            WHEN 'corporate' THEN 'RE'
            WHEN 'priority' THEN 'PR'
            WHEN 'compliance' THEN 'CO'
            WHEN 'appointment' THEN 'AP'
            WHEN 'medrecord' THEN 'X'
            WHEN 'emr' THEN 'X'
            WHEN 'consultation' THEN 'X'
            ELSE 'NA'
        END,
        kiosk_series
    ) AS queue_no,
    priority_type
FROM patient_queue
WHERE patient_queue.is_deleted = 0
    AND patient_id IS NULL
    AND date(added_on) = ?
    AND `status` = 'attending'
    AND `section` = 'csr'
    AND is_called = 0
EOT;

        $binds = [$date_today];

        $query = $db->query($sql, $binds);
        $res   = $query->getResult();

        if (! empty($res)) {
            return $res[0];
        } else {
            return null;
        }

    }


    /**
     * Get All medrecords
     */
    public function get_all_priority_with_transaction()
    {

        $db = db_connect();

        $sql = <<<EOT
SELECT patient_queue.transaction_id, patient_queue.priority_type, patient_queue.patient_id, patient_queue.id AS queue_id, AES_DECRYPT(patient.first_name,?) AS first_name, AES_DECRYPT(patient.last_name,?) AS last_name, AES_DECRYPT(patient.middle_name,?) AS middle_name,
    CONCAT(
    CASE patient_queue.priority_type
        WHEN 'emr' THEN 'X'
        WHEN 'medrecord' THEN 'X'
        ELSE 'NA'
    END,
    kiosk_series
) AS queue_no
FROM patient_queue
LEFT JOIN patient ON patient.id = patient_queue.patient_id AND patient.is_deleted = 0
LEFT JOIN transaction ON transaction.id = patient_queue.transaction_id AND transaction.is_deleted = 0
WHERE patient_queue.is_deleted = 0
AND patient_queue.priority_type IN ('medrecord','emr','consultation')
AND patient.id IS NOT NULL
AND (transaction.is_paid = 0 OR transaction.id IS NULL)
ORDER BY patient_queue.id
EOT;
        $binds = [ENCRYPTION_KEY, ENCRYPTION_KEY, ENCRYPTION_KEY];
        $query = $db->query($sql, $binds);
        $res   = $query->getResultArray();

        if (! empty($res)) {
            return $res;
        } else {
            return null;
        }

    }
}
