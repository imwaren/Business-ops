<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Billing_payment_entry extends MYT_Model
{
    protected $table = 'billing_payment_entry';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'billing_payment_id',
        'billing_entry_id',
        'amount',
        'is_migrated',
    ];

    public function get_details($billing_payment_entry_id = null, $billing_payment_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT billing_payment_entry.id, 
    billing_payment_entry.billing_payment_id, 
    billing_payment_entry.billing_entry_id, 
    billing_payment_entry.amount
FROM billing_payment_entry
LEFT JOIN billing_payment ON billing_payment.id = billing_payment_entry.billing_payment_id
WHERE billing_payment_entry.is_deleted = 0
AND billing_payment.is_deleted = 0
EOT;

        if ($billing_payment_entry_id) {
            $sql .= <<<EOT

AND billing_payment_entry.id = ?
EOT;
            $binds[] = $billing_payment_entry_id;
        }

        if (is_array($billing_payment_id)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $billing_payment_id))));
            if (!$ids) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql .= "\nAND billing_payment_entry.billing_payment_id IN ($placeholders)";
            foreach ($ids as $id) {
                $binds[] = $id;
            }
        } elseif ($billing_payment_id) {
            $sql .= "\nAND billing_payment_entry.billing_payment_id = ?";
            $binds[] = $billing_payment_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>