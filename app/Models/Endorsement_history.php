<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_history extends MYT_Model
{
    protected $table = 'endorsement_history';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'version_no',
        'source_id',
        'col_name',
        'old_value',
        'new_value'
    ];

    public function get_details($endorsement_id = null, $version_no = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_history.id,
    endorsement_history.version_no,
    endorsement_history.source_id,
    endorsement_history.col_name,
    endorsement_history.old_value,
    endorsement_history.new_value,
    endorsement_history.added_by,
    endorsement_history.added_on
FROM endorsement_history
WHERE endorsement_history.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_history.source_id', $endorsement_id)) {
            return [];
        }

        if ($version_no) {
            $sql .= <<<EOT

AND endorsement_history.version_no = ?
EOT;
            $binds[] = $version_no;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>