<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Item_inventory_monthly extends MYT_Model
{
    protected $table = 'item_inventory_monthly';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'snapshot_month',
        'branch_id',
        'current_qty',
        'ending_cost',
        'added_on',
        'updated_on',
    ];

    /**
     * Roll up the latest daily snapshot in the month for one branch.
     *
     * @return array{current_qty:float,ending_cost:float}|null
     */
    public function totals_from_daily(int $branch_id, string $snapshot_month)
    {
        $month_start = date('Y-m-01', strtotime($snapshot_month));
        $month_end = date('Y-m-t', strtotime($month_start));

        $sql = <<<EOT
SELECT
    IFNULL(SUM(d.current_qty), 0) AS current_qty,
    IFNULL(SUM(d.ending_cost), 0) AS ending_cost
FROM item_inventory_daily d
INNER JOIN (
    SELECT MAX(snapshot_date) AS snapshot_date
    FROM item_inventory_daily
    WHERE branch_id = ?
      AND snapshot_date BETWEEN ? AND ?
) latest ON latest.snapshot_date = d.snapshot_date
WHERE d.branch_id = ?
EOT;

        $query = $this->db->query($sql, [$branch_id, $month_start, $month_end, $branch_id]);
        $row = $query ? $query->getRow() : null;
        if (!$row) {
            return null;
        }

        return [
            'current_qty' => (float)$row->current_qty,
            'ending_cost' => (float)$row->ending_cost,
        ];
    }

    public function upsert_month(int $branch_id, string $snapshot_month, float $current_qty, float $ending_cost): bool
    {
        $month_start = date('Y-m-01', strtotime($snapshot_month));
        $now = date('Y-m-d H:i:s');
        $values = [
            'snapshot_month' => $month_start,
            'branch_id'      => $branch_id,
            'current_qty'    => $current_qty,
            'ending_cost'    => $ending_cost,
            'updated_on'     => $now,
        ];

        $existing = $this->select('id', [
            'snapshot_month' => $month_start,
            'branch_id'      => $branch_id,
        ], 1);

        if ($existing) {
            return $this->update(['id' => $existing->id], $values);
        }

        $values['added_on'] = $now;
        return (bool)$this->insert($values);
    }

    public function get_months_in_range(string $date_from, string $date_to): array
    {
        $sql = <<<EOT
SELECT snapshot_month, branch_id, current_qty, ending_cost
FROM item_inventory_monthly
WHERE snapshot_month BETWEEN ? AND ?
ORDER BY snapshot_month ASC, branch_id ASC
EOT;

        $month_from = date('Y-m-01', strtotime($date_from));
        $month_to = date('Y-m-01', strtotime($date_to));
        $query = $this->db->query($sql, [$month_from, $month_to]);

        return $query ? $query->getResultArray() : [];
    }
}

?>
