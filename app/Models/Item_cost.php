<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Item_cost extends MYT_Model
{
    protected $table = 'item_cost';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_id',
        'item_id',
        'last_value',
        'latest_total',
        'current_amount',
    ];

    public function get_details($item_cost_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT item_cost.*
FROM item_cost
WHERE item_cost.is_deleted = 0
EOT;

        if ($item_cost_id) {
            $sql .= <<<EOT

AND item_cost.id = ?
EOT;
            $binds[] = $item_cost_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Latest trail row for an item at a branch.
     */
    public function get_latest($item_id, $branch_id, $for_update = false)
    {
        $sql = <<<EOT
SELECT item_cost.*
FROM item_cost
WHERE item_cost.is_deleted = 0
AND item_cost.item_id = ?
AND item_cost.branch_id = ?
ORDER BY item_cost.id DESC
LIMIT 1
EOT;

        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }

        $query = $this->db->query($sql, [$item_id, $branch_id]);
        return $query ? $query->getRow() : null;
    }

    /**
     * Next trail values after a qty movement.
     *
     * $at_current_wac = true uses current_amount as the unit cost (adjustments / transfer-out).
     * Otherwise $unit_cost is the inbound purchase/transfer unit cost.
     */
    public function compute_next(array $current, float $qty_delta, float $unit_cost, bool $at_current_wac = false): array
    {
        $last_value = (float)($current['last_value'] ?? 0);
        $latest_total = (float)($current['latest_total'] ?? 0);
        $current_amount = (float)($current['current_amount'] ?? 0);
        $apply_cost = $at_current_wac ? $current_amount : $unit_cost;

        $new_last_value = $last_value + ($qty_delta * $apply_cost);
        $new_latest_total = $latest_total + $qty_delta;

        if ($new_latest_total <= 0) {
            return [
                'last_value' => 0,
                'latest_total' => 0,
                'current_amount' => $current_amount,
            ];
        }

        if ($new_last_value < 0) {
            $new_last_value = 0;
        }

        return [
            'last_value' => $new_last_value,
            'latest_total' => $new_latest_total,
            'current_amount' => $new_last_value / $new_latest_total,
        ];
    }
}

?>
