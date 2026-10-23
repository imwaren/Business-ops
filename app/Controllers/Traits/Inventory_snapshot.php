<?php

namespace App\Controllers\Traits;

use App\Models\Item;
use App\Models\Item_inventory_daily;
use App\Models\Item_inventory_monthly;

/**
 * Daily / monthly inventory snapshot writes used by cron and backfill.
 */
trait Inventory_snapshot
{
    protected function _inventory_snapshot_branches(): array
    {
        return [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            6 => 'pharmacy',
            7 => 'mantlewood',
        ];
    }

    /**
     * Snapshot every inventory branch for one date, then refresh that month on the branch DB and master.
     *
     * @return array<int,int> item counts per branch_id
     */
    protected function _snapshot_item_inventory_for_date(string $date, bool $use_live): array
    {
        $results = [];
        $masterDb = db_connect('master');
        $month = date('Y-m-01', strtotime($date));

        foreach ($this->_inventory_snapshot_branches() as $branch_id => $group) {
            $db = db_connect($group);
            $count = $use_live
                ? $this->_snapshot_live_inventory($db, $branch_id, $date)
                : $this->_snapshot_as_of_inventory($db, $branch_id, $date);

            $this->_rebuild_inventory_month($db, $masterDb, $branch_id, $month);
            $results[$branch_id] = $count;
        }

        return $results;
    }

    protected function _snapshot_live_inventory($db, int $branch_id, string $date): int
    {
        $item = new Item($db);
        $rows = $item->get_inventory_report($branch_id) ?: [];
        $daily = new Item_inventory_daily($db);

        return $daily->upsert_rows($date, $branch_id, $rows);
    }

    protected function _snapshot_as_of_inventory($db, int $branch_id, string $date): int
    {
        $item = new Item($db);
        $daily = new Item_inventory_daily($db);
        $rows = $item->get_inventory_report($branch_id) ?: [];
        $qtyMap = $daily->qty_as_of($branch_id, $date);

        foreach ($rows as &$row) {
            $branchItemId = (int)($row['branch_item_id'] ?? 0);
            $qty = $branchItemId && array_key_exists($branchItemId, $qtyMap)
                ? $qtyMap[$branchItemId]
                : 0.0;
            $wac = (float)($row['weighted_cost'] ?? 0);
            $row['current_qty'] = $qty;
            $row['ending_cost'] = $qty * $wac;
        }
        unset($row);

        return $daily->upsert_rows($date, $branch_id, $rows);
    }

    protected function _rebuild_inventory_month($branchDb, $masterDb, int $branch_id, string $snapshot_month): bool
    {
        $branchMonthly = new Item_inventory_monthly($branchDb);
        $totals = $branchMonthly->totals_from_daily($branch_id, $snapshot_month);
        if ($totals === null) {
            $totals = ['current_qty' => 0, 'ending_cost' => 0];
        }

        if (!$branchMonthly->upsert_month($branch_id, $snapshot_month, $totals['current_qty'], $totals['ending_cost'])) {
            return false;
        }

        $masterMonthly = new Item_inventory_monthly($masterDb);
        return $masterMonthly->upsert_month($branch_id, $snapshot_month, $totals['current_qty'], $totals['ending_cost']);
    }
}
