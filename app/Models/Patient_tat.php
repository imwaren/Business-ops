<?php
namespace App\Models;

use App\Core\MYT_Model;

class Patient_tat extends MYT_Model
{
    protected $table         = 'patient_tat';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'patient_master_id',
        'queue_id',
        'queue_no',
        'transaction_id',
        'booking_detail_id',
        'category_id',
        'category_name',
        'exam_name',
        'action_performed',
        'source',
        'section',
        'group_id',
        'time_start',
        'time_end',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    /**
     * Build WHERE clause for open segment queries (whitelist columns only).
     */
    protected function build_open_segment_where(array $filters, array &$binds)
    {
        $allowed = [
            'queue_id',
            'transaction_id',
            'booking_detail_id',
            'category_id',
            'action_performed',
            'patient_master_id',
        ];

        $conditions = [
            'patient_tat.is_deleted = 0',
            'patient_tat.time_end IS NULL',
        ];

        foreach ($filters as $column => $value) {
            if (! in_array($column, $allowed, true)) {
                continue;
            }

            if ($value === null) {
                $conditions[] = "patient_tat.{$column} IS NULL";
            } else {
                $conditions[] = "patient_tat.{$column} = ?";
                $binds[]        = $value;
            }
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Get the latest open segment matching filters.
     */
    public function get_latest_open(array $filters = [])
    {
        $binds = [];
        $where = $this->build_open_segment_where($filters, $binds);

        $sql = <<<EOT
SELECT patient_tat.*
FROM patient_tat
WHERE {$where}
ORDER BY patient_tat.id DESC
LIMIT 1
EOT;

        $query = $this->db->query($sql, $binds);

        return $query->getRow();
    }

    /**
     * Get all open segments matching filters.
     */
    public function get_open_segments(array $filters = [])
    {
        $binds = [];
        $where = $this->build_open_segment_where($filters, $binds);

        $sql = <<<EOT
SELECT patient_tat.*
FROM patient_tat
WHERE {$where}
ORDER BY patient_tat.id ASC
EOT;

        $query = $this->db->query($sql, $binds);

        return $query->getResult();
    }

    /**
     * Get open serving segments for a queue (lab/diag/health/pharmacy).
     */
    public function get_open_serving_segments($queue_id, $booking_detail_id = null, $category_id = null)
    {
        $sql = <<<EOT
SELECT patient_tat.*
FROM patient_tat
WHERE patient_tat.is_deleted = 0
AND patient_tat.time_end IS NULL
AND patient_tat.queue_id = ?
AND patient_tat.action_performed IN ('lab_serving', 'diag_serving', 'health_serving', 'pharmacy_serving')
EOT;

        $binds = [$queue_id];

        if ($category_id !== null && $category_id !== '') {
            $sql .= "\nAND patient_tat.category_id = ?";
            $binds[] = $category_id;
        } elseif ($booking_detail_id !== null && $booking_detail_id !== '') {
            $sql .= "\nAND patient_tat.booking_detail_id = ?";
            $binds[] = $booking_detail_id;
        }

        $sql .= "\nORDER BY patient_tat.id ASC";

        $query = $this->db->query($sql, $binds);

        return $query->getResult();
    }
}
