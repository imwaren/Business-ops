<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_history extends MYT_Model
{
    protected $table = 'costing_history';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'version_no',
        'source_id',
        'col_name',
        'old_value',
        'new_value'
    ];

    public function get_details($costing_id = null, $version_no = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_history.id,
    costing_history.version_no,
    costing_history.source_id,
    costing_history.col_name,
    costing_history.old_value,
    costing_history.new_value,
    costing_history.added_by,
    costing_history.added_on
FROM costing_history
WHERE costing_history.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_history.source_id', $costing_id)) {
            return [];
        }

        if ($version_no) {
            $sql .= <<<EOT

AND costing_history.version_no = ?
EOT;
            $binds[] = $version_no;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>