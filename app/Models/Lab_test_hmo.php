<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Lab_test_hmo extends MYT_Model
{
    protected $table = 'lab_test_hmo';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'lab_test_id',
        'branch_id',
        'company_id',
        'price',
    ];

    public function get_hmos($lab_test_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT lab_test_hmo.id, 
    lab_test_hmo.lab_test_id,
    lab_test_hmo.branch_id, 
    lab_test_hmo.company_id,
    company.name AS company_name,
    lab_test_hmo.price
FROM lab_test_hmo
LEFT JOIN lab_test ON lab_test.id = lab_test_hmo.lab_test_id AND lab_test.is_deleted = 0
LEFT JOIN company ON company.id = lab_test_hmo.company_id
WHERE lab_test_hmo.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'lab_test_hmo.lab_test_id', $lab_test_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>