<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_shift extends MYT_Model
{
    protected $table = 'costing_shift';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_id',
        'name',
        'start_shift',
        'end_shift',
        'registration_cut_off',
        'specimen_submission_cut_off',
        'total_hours',
        'break',
        'ingress',
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_shift.id,
    costing_shift.costing_id,
    costing_shift.name,
    costing_shift.start_shift,
    costing_shift.end_shift,
    costing_shift.registration_cut_off,
    costing_shift.specimen_submission_cut_off,
    costing_shift.total_hours,
    costing_shift.break,
    costing_shift.ingress
FROM costing_shift
WHERE costing_shift.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_shift.costing_id', $costing_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>