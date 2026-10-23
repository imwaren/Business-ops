<?php

namespace App\Controllers\Traits;

/**
 * Weighted-average cost trail (item_cost) helpers.
 * Mirrors item_cost / item catalog writes without rebinding controller models.
 */
trait Weighted_cost
{
    protected function _wac_inventory_ids(): array
    {
        return [0, 1, 2, 3, 4, 5, 6, 7];
    }

    protected function _wac_db_map(): array
    {
        return [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            6 => 'pharmacy',
            7 => 'mantlewood',
        ];
    }

    protected function _wac_global_max_id(string $table): int
    {
        $maxId = 0;
        foreach ($this->_wac_inventory_ids() as $branch_id) {
            $dbName = $this->_wac_db_map()[$branch_id];
            $db = db_connect($dbName);
            $row = $db->query("SELECT MAX(id) AS max_id FROM {$table}")->getRow();
            if ($row && isset($row->max_id) && (int)$row->max_id > $maxId) {
                $maxId = (int)$row->max_id;
            }
        }

        return $maxId;
    }

    protected function _wac_mirror_insert(string $table, array $values): int|false
    {
        $values['id'] = $this->_wac_global_max_id($table) + 1;

        foreach ($this->_wac_inventory_ids() as $branch_id) {
            $db = db_connect($this->_wac_db_map()[$branch_id]);
            if ($db->table($table)->insert($values) === false) {
                $error = $db->error();
                $this->error_message = ($error['message'] ?? '') ?: ('Failed insert on ' . $table);
                return false;
            }
        }

        return (int)$values['id'];
    }

    protected function _wac_mirror_update(string $table, array $where, array $values): bool
    {
        foreach ($this->_wac_inventory_ids() as $branch_id) {
            $db = db_connect($this->_wac_db_map()[$branch_id]);
            if ($db->table($table)->set($values)->where($where)->update() === false) {
                $error = $db->error();
                $this->error_message = ($error['message'] ?? '') ?: ('Failed update on ' . $table);
                return false;
            }
        }

        return true;
    }

    /**
     * Convert a qty in $unit_id (item_conversion.id) into the purchase UOM.
     */
    protected function _qty_to_purchase_uom(int $item_id, $unit_id, float $qty): float
    {
        $conversions = $this->item_conversion->select('', [
            'item_id' => $item_id,
            'is_deleted' => 0,
        ]) ?: [];

        if (!$conversions) {
            return $qty;
        }

        $purchase = null;
        $source = null;
        foreach ($conversions as $conv) {
            if (($conv->conversion_type ?? '') === 'purchase') {
                $purchase = $conv;
            }
            if ((int)$conv->id === (int)$unit_id) {
                $source = $conv;
            }
        }

        if (!$source || !$purchase || (int)$purchase->id === (int)$source->id) {
            return $qty;
        }

        $sourceQty = (float)$source->qty;
        if ($sourceQty == 0.0) {
            return $qty;
        }

        return $qty * ((float)$purchase->qty / $sourceQty);
    }

    /**
     * Purchase-unit beginning/current qty for a branch from the create payload.
     */
    protected function _purchase_qty_from_payload_branches(int $branch_id, $conversions): float
    {
        if (!array_key_exists('branches', $this->payload) || !is_array($this->payload['branches'])) {
            return 0;
        }

        $purchaseUnit = null;
        if (is_array($conversions)) {
            foreach ($conversions as $conv) {
                $type = is_array($conv) ? ($conv['conversion_type'] ?? '') : ($conv->conversion_type ?? '');
                $unit = is_array($conv) ? ($conv['unit'] ?? null) : ($conv->unit ?? null);
                if ($type === 'purchase') {
                    $purchaseUnit = $unit;
                    break;
                }
            }
        }

        foreach ($this->payload['branches'] as $branch) {
            if ((int)($branch['branch_id'] ?? 0) !== $branch_id) {
                continue;
            }
            $convType = $branch['conversion_type'] ?? '';
            if ($convType === 'purchase' || ($purchaseUnit !== null && ($branch['unit'] ?? null) == $purchaseUnit)) {
                return (float)($branch['current_qty'] ?? 0);
            }
        }

        return 0;
    }

    protected function _latest_item_cost_row(int $item_id, int $branch_id, bool $for_update = false): array
    {
        $row = $for_update ? $this->item_cost->get_latest($item_id, $branch_id, true)
            : $this->item_cost->get_latest($item_id, $branch_id);
        if ($row) {
            return [
                'last_value' => (float)$row->last_value,
                'latest_total' => (float)$row->latest_total,
                'current_amount' => (float)$row->current_amount,
            ];
        }

        $item = $this->item->select('cost', ['id' => $item_id, 'is_deleted' => 0], 1);
        $cost = $item ? (float)$item->cost : 0;

        return [
            'last_value' => 0,
            'latest_total' => 0,
            'current_amount' => $cost,
        ];
    }

    protected function _seed_item_cost_values(int $item_id, int $branch_id, float $cost, float $qty): array
    {
        return [
            'item_id' => $item_id,
            'branch_id' => $branch_id,
            'last_value' => $qty * $cost,
            'latest_total' => $qty,
            'current_amount' => $cost,
        ];
    }

    /**
     * Insert a new item_cost trail row. Optionally update item.cost / weighted_cost.
     */
    protected function _insert_item_cost_trail(int $item_id, int $branch_id, array $next, bool $update_item_catalog, ?float $batch_cost = null): bool
    {
        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? null;

        $values = [
            'item_id' => $item_id,
            'branch_id' => $branch_id,
            'last_value' => $next['last_value'],
            'latest_total' => $next['latest_total'],
            'current_amount' => $next['current_amount'],
            'added_by' => $requester,
            'added_on' => $now,
        ];

        if (!$this->_wac_mirror_insert('item_cost', $values)) {
            return false;
        }

        if (!$update_item_catalog) {
            return true;
        }

        $itemValues = [
            'weighted_cost' => $next['current_amount'],
            'updated_by' => $requester,
            'updated_on' => $now,
        ];
        if ($batch_cost !== null) {
            $itemValues['cost'] = $batch_cost;
        }

        return $this->_wac_mirror_update('item', ['id' => $item_id], $itemValues);
    }

    /**
     * Receipt at a new unit cost (purchase). Updates item.cost and weighted_cost.
     */
    protected function _apply_receipt_weighted_cost(int $item_id, int $branch_id, float $qty, $unit_id, float $unit_cost): bool
    {
        $purchaseQty = $this->_qty_to_purchase_uom($item_id, $unit_id, $qty);
        $current = $this->_latest_item_cost_row($item_id, $branch_id);
        $next = $this->item_cost->compute_next($current, $purchaseQty, $unit_cost, false);

        return $this->_insert_item_cost_trail($item_id, $branch_id, $next, true, $unit_cost);
    }

    /**
     * Qty-only movement. $inbound_unit_cost null = apply at current WAC.
     * Does not change item.cost.
     */
    protected function _apply_qty_delta_weighted_cost(int $item_id, int $branch_id, float $qty_delta_purchase, ?float $inbound_unit_cost = null, bool $for_update = false): bool
    {
        $current = $this->_latest_item_cost_row($item_id, $branch_id, $for_update);
        $atCurrent = $inbound_unit_cost === null;
        $unitCost = $atCurrent ? (float)$current['current_amount'] : (float)$inbound_unit_cost;
        $next = $this->item_cost->compute_next($current, $qty_delta_purchase, $unitCost, $atCurrent);

        return $this->_insert_item_cost_trail($item_id, $branch_id, $next, false);
    }
}
