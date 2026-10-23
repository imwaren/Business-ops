<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Send_out_entry extends MYT_Model
{
    protected $table = 'send_out_entry';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'send_out_id',
        'sendout_census_id',
        'date',
        'facility_id',
        'transaction_id',
        'patient_id',
        'service_id',
        'total_sales',
        'total_expense',
    ];

    public function get_details($send_out_entry_id = null, $send_out_id = null, $facility_id = null, $page = null, $data_size = null)
    {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT send_out_entry.*,
    facility.name AS facility_name,
    lab_test.name AS lab_test_name,
    TRIM(CONCAT(
        CAST(AES_DECRYPT(patient.last_name, '{$key}') AS CHAR), ', ',
        CAST(AES_DECRYPT(patient.first_name, '{$key}') AS CHAR), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.middle_name, '{$key}') AS CHAR), ''), ' ',
        IFNULL(CAST(AES_DECRYPT(patient.suffix, '{$key}') AS CHAR), '')
    )) AS patient_name
FROM send_out_entry
LEFT JOIN send_out ON send_out.id = send_out_entry.send_out_id AND send_out.is_deleted = 0
LEFT JOIN {MASTER_DB}.facility AS facility ON facility.id = send_out_entry.facility_id AND facility.is_deleted = 0
LEFT JOIN {MASTER_DB}.lab_test AS lab_test ON lab_test.id = send_out_entry.service_id
LEFT JOIN patient ON patient.id = send_out_entry.patient_id
WHERE send_out_entry.is_deleted = 0
EOT;

        if ($send_out_entry_id) {
            $sql .= <<<EOT

AND send_out_entry.id = ?
EOT;
            $binds[] = $send_out_entry_id;
        }

        if ($send_out_id) {
            $sql .= <<<EOT

AND send_out_entry.send_out_id = ?
EOT;
            $binds[] = $send_out_id;
        }

        if ($facility_id) {
            $sql .= <<<EOT

AND send_out_entry.facility_id = ?
EOT;
            $binds[] = $facility_id;
        }

        $sql .= "\nORDER BY send_out_entry.date DESC, send_out_entry.id DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}
