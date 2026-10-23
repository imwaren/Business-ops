<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_detail extends MYT_Model
{
    protected $table = 'endorsement_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'patient_id',
        'beneficiary_role',
        'assigned_to',
        'scheduled_date',
        'remark'
    ];

    public function get_details($endorsement_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_detail.id, 
    endorsement_detail.endorsement_id, 
    endorsement_detail.patient_id,
    endorsement_detail.beneficiary_role,
    endorsement_detail.assigned_to,
    endorsement_detail.scheduled_date,
    endorsement_detail.remark
FROM endorsement_detail
WHERE endorsement_detail.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_detail.endorsement_id', $endorsement_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>