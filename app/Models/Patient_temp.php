<?php
namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Patient_temp extends MYT_Model
{
    protected $DBGroup       = 'master';
    protected $table         = 'patient_temp';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['id', 'patient_no', 'patient_name', 'gender', 'birthdate', 'contact_no', 'is_processed'];

    /**
     * Get a batch of unprocessed patient_temp rows
     */
    public function get_unprocessed($limit)
    {
        $limit = (int) $limit;

        $sql = <<<EOT
SELECT *
FROM patient_temp
WHERE is_processed = 0
ORDER BY id
LIMIT $limit
EOT;

        $query = $this->db->query($sql);
        return $query ? $query->getResult() : false;
    }

    /**
     * Count remaining unprocessed patient_temp rows
     */
    public function count_unprocessed()
    {
        $sql = <<<EOT
SELECT COUNT(*) AS total_count
FROM patient_temp
WHERE is_processed = 0
EOT;

        $query = $this->db->query($sql);
        return $query ? (int) $query->getRow()->total_count : 0;
    }
}
