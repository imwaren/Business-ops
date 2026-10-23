<?php

namespace App\Models;

use App\Core\MYT_Model;

/**
 * Collection deposits (cash/check billing_payment transferred to an account type).
 */
class Billing_payment_deposit extends MYT_Model
{
    protected $table = 'billing_payment_deposit';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'billing_payment_id',
        'account_type_id',
        'amount',
    ];

    public function get_details($id = null, $billing_payment_id = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT billing_payment_deposit.*,
    account_type.name AS account_type_name
FROM billing_payment_deposit
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = billing_payment_deposit.account_type_id
WHERE billing_payment_deposit.is_deleted = 0
EOT;

        if ($id) {
            $sql .= <<<EOT

AND billing_payment_deposit.id = ?
EOT;
            $binds[] = $id;
        }

        if (is_array($billing_payment_id)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $billing_payment_id))));
            if (!$ids) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql .= "\nAND billing_payment_deposit.billing_payment_id IN ($placeholders)";
            foreach ($ids as $id) {
                $binds[] = $id;
            }
        } elseif ($billing_payment_id) {
            $sql .= "\nAND billing_payment_deposit.billing_payment_id = ?";
            $binds[] = $billing_payment_id;
        }

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);

        return $query ? $query->getResultArray() : false;
    }

    public function get_deposited_total($billing_payment_id)
    {
        if (is_array($billing_payment_id)) {
            $totals = $this->get_deposited_totals($billing_payment_id);
            return array_sum($totals);
        }
        if (!$billing_payment_id) {
            return 0.0;
        }

        $sql = <<<EOT
SELECT COALESCE(SUM(amount), 0) AS deposited_amount
FROM billing_payment_deposit
WHERE is_deleted = 0
AND billing_payment_id = ?
EOT;
        $query = $this->db->query($sql, [$billing_payment_id]);
        $row = $query ? $query->getRowArray() : null;

        return $row ? (float) $row['deposited_amount'] : 0.0;
    }

    /**
     * @param int[] $billing_payment_ids
     * @return array<int, float>
     */
    public function get_deposited_totals(array $billing_payment_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $billing_payment_ids))));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT billing_payment_id, COALESCE(SUM(amount), 0) AS deposited_amount
FROM billing_payment_deposit
WHERE is_deleted = 0
AND billing_payment_id IN ($placeholders)
GROUP BY billing_payment_id
EOT;
        $query = $this->db->query($sql, $ids);
        $totals = [];
        foreach ($query ? $query->getResultArray() : [] as $row) {
            $totals[(int) $row['billing_payment_id']] = (float) $row['deposited_amount'];
        }

        return $totals;
    }
}

?>
