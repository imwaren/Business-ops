<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement_consultation extends MYT_Model
{
    protected $table = 'endorsement_consultation';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'endorsement_id',
        'company_id',
        'specialization_id',
        'fee_amount',
        'doctor_share',
        'doctor_share_amount',
        'cpci_share',
        'discount_doctor_share',
        'discount_cpci_share',
        'ahmopi_fee',
        'pahmoc_fee',
        'charge_to',
        'charge_to_ids',
    ];

    public function get_details($endorsement_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement_consultation.id,
    endorsement_consultation.endorsement_id,
    endorsement_consultation.company_id,
    endorsement_consultation.specialization_id,
    endorsement_consultation.fee_amount,
    endorsement_consultation.doctor_share,
    endorsement_consultation.doctor_share_amount,
    endorsement_consultation.cpci_share,
    /* endorsement_consultation.discount_doctor_share, */
    /* endorsement_consultation.discount_cpci_share, */
    endorsement_consultation.ahmopi_fee,
    endorsement_consultation.pahmoc_fee,
    endorsement_consultation.charge_to,
    endorsement_consultation.charge_to_ids,
    GROUP_CONCAT(charge_company.name ORDER BY charge_company.name SEPARATOR ', ') AS charge_to_names,
    company.name AS company_name,
    emr_spec.name AS specialization_name
FROM endorsement_consultation
LEFT JOIN company ON company.id = endorsement_consultation.company_id AND company.is_deleted = 0
LEFT JOIN company AS charge_company ON FIND_IN_SET(charge_company.id, endorsement_consultation.charge_to_ids)
LEFT JOIN {OAKRIDGE_EMR_DB}.specialization AS emr_spec
    ON emr_spec.id = endorsement_consultation.specialization_id AND emr_spec.is_deleted = 0
WHERE endorsement_consultation.is_deleted = 0 
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'endorsement_consultation.endorsement_id', $endorsement_id)) {
            return [];
        }

        $sql .= " GROUP BY endorsement_consultation.id";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>