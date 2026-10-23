<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_manpower extends MYT_Model
{
    protected $table = 'costing_manpower';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'description',
        'manpower_type',
        'qty',
        'daily_rate',
        'total_daily_rate',
        'meals',
        'ingress_count',
        'rate',
        'pax_per_hour',
        'minutes_per_pax',
        'active_hours',
        'suggested_qty',
        'patient_per_shift',
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_manpower.*
FROM costing_manpower
WHERE costing_manpower.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_manpower.costing_id', $costing_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>