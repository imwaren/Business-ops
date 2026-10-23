<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Request_history extends MYT_Model
{
    protected $table = 'request_history';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'version_no',
        'source_id',
        'col_name',
        'old_value',
        'new_value'
    ];

    public function get_details($request_id = null, $version_no = null) {
        $binds = [];
        $sql = <<<EOT
SELECT request_history.id,
    request_history.version_no,
    request_history.source_id,
    request_history.col_name,
    request_history.old_value,
    request_history.new_value,
    request_history.added_by,
    request_history.added_on
FROM request_history
WHERE request_history.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'request_history.source_id', $request_id)) {
            return [];
        }

        if ($version_no) {
            $sql .= <<<EOT

AND request_history.version_no = ?
EOT;
            $binds[] = $version_no;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>