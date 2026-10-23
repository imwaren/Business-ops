<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Request_detail extends MYT_Model
{
    protected $table = 'request_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'request_id',
        'lab_test_id',
        'branch_id',
    ];

    public function get_details($request_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT request_detail.id, 
    request_detail.request_id, 
    request_detail.lab_test_id, 
    request_detail.branch_id,
    lab_test.name AS lab_test_name
FROM request_detail
LEFT JOIN lab_test ON lab_test.branch_lab_test_id = request_detail.lab_test_id AND lab_test.is_deleted = 0
WHERE request_detail.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'request_detail.request_id', $request_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>