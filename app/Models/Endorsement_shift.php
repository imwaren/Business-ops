<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_shift extends MYT_Model
{
    protected $table = 'endorsement_shift';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'name',
        'start_shift',
        'end_shift',
        'registration_cut_off',
        'specimen_submission_cut_off',
        'total_hours',
        'break',
        'ingress',
    ];

    public function get_details($endorsement_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_shift.id,
    endorsement_shift.endorsement_id,
    endorsement_shift.name,
    endorsement_shift.start_shift,
    endorsement_shift.end_shift,
    endorsement_shift.registration_cut_off,
    endorsement_shift.specimen_submission_cut_off,
    endorsement_shift.total_hours,
    endorsement_shift.break,
    endorsement_shift.ingress
FROM endorsement_shift
WHERE endorsement_shift.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_shift.endorsement_id', $endorsement_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>