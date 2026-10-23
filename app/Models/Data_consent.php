<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Data_consent extends MYT_Model
{
    protected $table = 'data_consent';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'first_name',
        'last_name',
        'middle_name',
        'date_of_birth',
        'is_data_privay_consent',
        'is_email_consent',
        'signature',
    ];

    public function get_details($data_consent_id = null, $first_name = null, $last_name = null, $middle_name = null, $date_of_birth = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT data_consent.*
FROM data_consent
WHERE data_consent.is_deleted = 0
EOT;

        if ($data_consent_id) {
            $sql .= <<<EOT

AND data_consent.id = ?
EOT;
            $binds[] = $data_consent_id;
        }

        if ($first_name) {
            $sql .= <<<EOT

AND data_consent.first_name = ?
EOT;
            $binds[] = $first_name;
        }

        if ($last_name) {
            $sql .= <<<EOT

AND data_consent.last_name = ?
EOT;
            $binds[] = $last_name;
        }

        if ($middle_name) {
            $sql .= <<<EOT

AND data_consent.middle_name = ?
EOT;
            $binds[] = $middle_name;
        }

        if ($date_of_birth) {
            $sql .= <<<EOT

AND data_consent.date_of_birth = ?
EOT;
            $binds[] = $date_of_birth;
        }

        if ($term) {
            $sql .= <<<EOT

AND CONCAT_WS(' ', data_consent.first_name, data_consent.middle_name, data_consent.last_name) LIKE ?
EOT;
            $binds[] = '%' . $term . '%';
        }

        $sql .= " ORDER BY data_consent.last_name ASC, data_consent.first_name ASC ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
