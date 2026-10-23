<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Package_detail extends MYT_Model
{
    protected $table = 'package_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'pathologist_fee',
        'consultation_fee',
        'procedure_fee',
        'reading_fee',
        'theoretical_cost',
        'clinic_based_cost',
        'retail_price',
        'pro_rate_price',
        'discount',
        'estimated_revenue',
        'direct_cost',
        'estimated_margin',
        'labtest_count',
    ];

    public function get_details($package_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT package_detail.id, 
    package_detail.package_id,
    package_detail.lab_test_id,
    package_detail.frequency,
    package_detail.pathologist_fee,
    package_detail.consultation_fee,
    package_detail.procedure_fee,
    package_detail.reading_fee,
    package_detail.theoretical_cost,
    package_detail.clinic_based_cost,
    package_detail.retail_price,
    package_detail.pro_rate_price,
    package_detail.discount,
    package_detail.estimated_revenue,
    package_detail.direct_cost,
    package_detail.estimated_margin,
    package_detail.labtest_count,
    lab_test.name AS lab_test_name,
    lab_test.price AS lab_test_price
FROM package_detail
LEFT JOIN lab_test ON lab_test.id = package_detail.lab_test_id AND lab_test.is_deleted = 0
WHERE package_detail.is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'package_detail.package_id', $package_id)) {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>