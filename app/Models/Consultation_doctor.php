<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Consultation_doctor extends MYT_Model
{
    protected $table = 'consultation_doctor';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'consultation_id',
        'doctor_id',
        'fee_name',
        'amount',
        'doctor_share',          // was doctor_share_amount
        'cpci_share',            // was doctor_share (percentage)
        'discount_doctor_share', // was discount_amount
        'discount_cpci_share',   // was discount_percentage
    ];

    public function get_by_doctor($doctor_id)
    {
        $binds = [$doctor_id];
        $sql = <<<EOT
SELECT consultation_doctor.*,
    consultation.name AS consultation_name
FROM consultation_doctor
LEFT JOIN consultation ON consultation.id = consultation_doctor.consultation_id AND consultation.is_deleted = 0
WHERE consultation_doctor.is_deleted = 0
AND consultation_doctor.doctor_id = ?
ORDER BY consultation_doctor.id ASC
EOT;

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
