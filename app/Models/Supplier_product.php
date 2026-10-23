<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier_product extends MYT_Model
{
    protected $table = 'supplier_product';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'product_lines_carried',
        'brand',
        'products_exclusively_carried',
        'brand_principal',
    ];

    public function get_details($supplier_product_id = null, $supplier_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier_product.*
FROM supplier_product
WHERE supplier_product.is_deleted = 0
EOT;

        if ($supplier_product_id) {
            $sql .= <<<EOT

AND supplier_product.id = ?
EOT;
            $binds[] = $supplier_product_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier_product.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>