<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing_consultation extends MYT_Model
{
    protected $table = 'costing_consultation';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'costing_id',
        'company_id',
        'specialization_id',
        'fee_amount',
        'ahmopi_fee',
        'pahmoc_fee',
        'doctor_share',
        'doctor_share_amount',
        'cpci_share',
        'discount_doctor_share',
        'discount_cpci_share',
        'charge_to',
        'charge_to_ids',
    ];

    public function get_details($costing_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing_consultation.id,
    costing_consultation.costing_id,
    costing_consultation.company_id,
    costing_consultation.specialization_id,
    costing_consultation.fee_amount,
    costing_consultation.ahmopi_fee,
    costing_consultation.pahmoc_fee,
    costing_consultation.doctor_share,
    costing_consultation.doctor_share_amount,
    costing_consultation.cpci_share,
    /* costing_consultation.discount_doctor_share, */
    /* costing_consultation.discount_cpci_share, */
    costing_consultation.charge_to,
    costing_consultation.charge_to_ids,
    GROUP_CONCAT(charge_company.name ORDER BY charge_company.name SEPARATOR ', ') AS charge_to_names,
    company.name AS company_name,
    emr_spec.name AS specialization_name
FROM costing_consultation
LEFT JOIN company ON company.id = costing_consultation.company_id AND company.is_deleted = 0
LEFT JOIN company AS charge_company ON FIND_IN_SET(charge_company.id, costing_consultation.charge_to_ids)
LEFT JOIN {OAKRIDGE_EMR_DB}.specialization AS emr_spec
    ON emr_spec.id = costing_consultation.specialization_id AND emr_spec.is_deleted = 0
WHERE costing_consultation.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'costing_consultation.costing_id', $costing_id)) {
            return [];
        }

        $sql .= " GROUP BY costing_consultation.id";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>