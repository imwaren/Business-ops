<?php

namespace App\Models;

use App\Core\MYT_Model;

class Facility_lab_test extends MYT_Model
{
    protected $table = 'facility_lab_test';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'facility_id',
        'lab_test_id',
        'rate',
    ];

    public function get_details($facility_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT facility_lab_test.*,
    lab_test.name AS lab_test_name
FROM facility_lab_test
LEFT JOIN lab_test ON lab_test.id = facility_lab_test.lab_test_id AND lab_test.is_deleted = 0
WHERE facility_lab_test.is_deleted = 0
EOT;

        if ($facility_id) {
            $sql .= <<<EOT

AND facility_lab_test.facility_id = ?
EOT;
            $binds[] = $facility_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
