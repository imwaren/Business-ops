<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Informed_consent extends MYT_Model
{
    protected $table = 'informed_consent';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'master_patient_id',
        'transaction_id',
        'first_name',
        'last_name',
        'middle_name',
        'date_of_birth',
        'is_pregnant',
        'guardian_name',
        'signature',
    ];

    public function get_details($informed_consent_id = null, $transaction_id = null, $first_name = null, $last_name = null, $middle_name = null, $date_of_birth = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT informed_consent.*
FROM informed_consent
WHERE informed_consent.is_deleted = 0
EOT;

        if ($informed_consent_id) {
            $sql .= <<<EOT

AND informed_consent.id = ?
EOT;
            $binds[] = $informed_consent_id;
        }

        if ($transaction_id) {
            $sql .= <<<EOT

AND informed_consent.transaction_id = ?
EOT;
            $binds[] = $transaction_id;
        }

        if ($first_name) {
            $sql .= <<<EOT

AND informed_consent.first_name = ?
EOT;
            $binds[] = $first_name;
        }

        if ($last_name) {
            $sql .= <<<EOT

AND informed_consent.last_name = ?
EOT;
            $binds[] = $last_name;
        }

        if ($middle_name) {
            $sql .= <<<EOT

AND informed_consent.middle_name = ?
EOT;
            $binds[] = $middle_name;
        }

        if ($date_of_birth) {
            $sql .= <<<EOT

AND informed_consent.date_of_birth = ?
EOT;
            $binds[] = $date_of_birth;
        }

        if ($term) {
            $sql .= <<<EOT

AND CONCAT_WS(' ', informed_consent.first_name, informed_consent.middle_name, informed_consent.last_name) LIKE ?
EOT;
            $binds[] = '%' . $term . '%';
        }

        $sql .= " ORDER BY informed_consent.last_name ASC, informed_consent.first_name ASC ";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
