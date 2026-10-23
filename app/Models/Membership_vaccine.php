<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Membership_vaccine extends MYT_Model
{
    protected $table = 'membership_vaccine';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'membership_id',
        'vaccine_id',
        'lab_test_id',
        'frequency',
        'percentage',
        'is_free',
    ];

    public function get_vaccines($membership_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT membership_vaccine.id, 
    membership_vaccine.membership_id, 
    membership_vaccine.vaccine_id, 
    membership_vaccine.lab_test_id,
    membership_vaccine.frequency,
    membership_vaccine.percentage,
    membership_vaccine.is_free,
    lab_test.name AS lab_test_name,
    vaccine.vax AS vaccine_name
FROM membership_vaccine
LEFT JOIN vaccine ON vaccine.id = membership_vaccine.vaccine_id AND vaccine.is_deleted = 0
LEFT JOIN lab_test ON lab_test.id = membership_vaccine.lab_test_id AND lab_test.is_deleted = 0
WHERE membership_vaccine.is_deleted = 0
EOT;

        if ($membership_id) {
            $sql .= <<<EOT

AND membership_vaccine.membership_id = ?
EOT;
            $binds[] = $membership_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>