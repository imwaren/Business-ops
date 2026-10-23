<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_employer extends MYT_Model
{
    protected $table = 'endorsement_employer';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'name'
    ];

    public function get_details($endorsement_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_employer.id, 
    endorsement_employer.endorsement_id, 
    endorsement_employer.name
FROM endorsement_employer
WHERE endorsement_employer.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_employer.endorsement_id', $endorsement_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>