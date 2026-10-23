<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Request_shift extends MYT_Model
{
    protected $table = 'request_shift';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'request_id',
        'name',
        'start_shift',
        'end_shift',
        'registration_cut_off',
        'specimen_submission_cut_off',
        'total_hours',
        'break',
        'ingress',
    ];

    public function get_details($request_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT request_shift.id,
    request_shift.request_id,
    request_shift.name,
    request_shift.start_shift,
    request_shift.end_shift,
    request_shift.registration_cut_off,
    request_shift.specimen_submission_cut_off,
    request_shift.total_hours,
    request_shift.break,
    request_shift.ingress
FROM request_shift
WHERE request_shift.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'request_shift.request_id', $request_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>