<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_revision extends MYT_Model
{
    protected $table = 'endorsement_revision';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'version_no',
        'status',
        'data_received'
    ];

    public function get_details($endorsement_id = null, $version_no = null, $status = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_revision.*
FROM endorsement_revision
WHERE endorsement_revision.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_revision.endorsement_id', $endorsement_id)) {
            return [];
        }

        if ($version_no) {
            $sql .= <<<EOT

AND endorsement_revision.version_no = ?
EOT;
            $binds[] = $version_no;
        }

        if ($status) {
            $sql .= <<<EOT

AND endorsement_revision.status = ?
EOT;
            $binds[] = $status;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>