<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Booking_medicine extends MYT_Model
{
    protected $table = 'booking_medicine';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'booking_id',
        'medicine_id',
        'qty',
        'unit',
        'price',
        'amount',
    ];

    public function get_details($booking_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT 
    booking_medicine.id,
    booking_medicine.booking_id,
    booking_medicine.medicine_id,
    booking_medicine.qty,
    booking_medicine.price,
    booking_medicine.amount,
    medicine.name AS medicine_name,
    medicine.brand AS medicine_brand,
    medicine.generic AS medicine_generic,
    medicine.dosage AS medicine_dosage,
    medicine.vat_type AS medicine_vat_type,
    CASE WHEN medicine.vat_type = 'vat_in' THEN booking_medicine.amount / 1.12 ELSE 0 END AS vat_ex,
    (booking_medicine.amount - CASE WHEN medicine.vat_type = 'vat_in' THEN booking_medicine.amount / 1.12 ELSE 0 END) AS vat_amount
FROM booking_medicine
LEFT JOIN item AS medicine ON medicine.id = booking_medicine.medicine_id AND medicine.is_deleted = 0
WHERE booking_medicine.is_deleted = 0
EOT;

        if ($booking_id) {
            $sql .= <<<EOT

AND booking_medicine.booking_id = ?
EOT;
            $binds[] = $booking_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>