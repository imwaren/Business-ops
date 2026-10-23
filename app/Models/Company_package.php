<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Company_package extends MYT_Model
{
    protected $table = 'company_package';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'company_id',
        'package_id',
        'price',
    ];

    public function get_packages($company_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT company_package.id, 
    company_package.company_id, 
    company_package.package_id, 
    company_package.price,
    package.name AS package_name,
    package.price AS package_price
FROM company_package
LEFT JOIN package ON package.id = company_package.package_id AND package.is_deleted = 0
WHERE company_package.is_deleted = 0
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND company_package.company_id = ?
EOT;
            $binds[] = $company_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>