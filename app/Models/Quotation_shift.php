<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Quotation_shift extends MYT_Model
{
    protected $table = 'quotation_shift';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'quotation_id',
        'name',
        'start_shift',
        'end_shift',
        'registration_cut_off',
        'specimen_submission_cut_off',
        'total_hours',
        'break',
        'ingress',
    ];

    public function get_details($quotation_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT quotation_shift.id,
    quotation_shift.quotation_id,
    quotation_shift.name,
    quotation_shift.start_shift,
    quotation_shift.end_shift,
    quotation_shift.registration_cut_off,
    quotation_shift.specimen_submission_cut_off,
    quotation_shift.total_hours,
    quotation_shift.break,
    quotation_shift.ingress
FROM quotation_shift
WHERE quotation_shift.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'quotation_shift.quotation_id', $quotation_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>