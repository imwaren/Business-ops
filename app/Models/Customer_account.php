<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Customer_account extends MYT_Model
{
    protected $table = 'customer_account';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'company_id',
        'name',
    ];

    public function get_customer_account($company_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT customer_account.id, 
    customer_account.company_id,
    customer_account.name
FROM customer_account
WHERE customer_account.is_deleted = 0
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND customer_account.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>