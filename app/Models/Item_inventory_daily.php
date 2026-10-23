<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Item_inventory_daily extends MYT_Model
{
    protected $table = 'item_inventory_daily';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'snapshot_date',
        'branch_id',
        'item_id',
        'current_qty',
        'weighted_cost',
        'ending_cost',
        'added_on',
        'updated_on',
    ];

    /**
     * Upsert one daily row per item for a branch and date.
     */
    public function upsert_rows(string $snapshot_date, int $branch_id, array $rows): int
    {
        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($rows as $row) {
            $item_id = (int)($row['item_id'] ?? 0);
            if (!$item_id) {
                continue;
            }

            $qty = (float)($row['current_qty'] ?? 0);
            $wac = (float)($row['weighted_cost'] ?? 0);
            $ending = array_key_exists('ending_cost', $row)
                ? (float)$row['ending_cost']
                : ($qty * $wac);

            $values = [
                'snapshot_date' => $snapshot_date,
                'branch_id'     => $branch_id,
                'item_id'       => $item_id,
                'current_qty'   => $qty,
                'weighted_cost' => $wac,
                'ending_cost'   => $ending,
                'updated_on'    => $now,
            ];

            $existing = $this->select('id', [
                'snapshot_date' => $snapshot_date,
                'branch_id'     => $branch_id,
                'item_id'       => $item_id,
            ], 1);

            if ($existing) {
                $this->update(['id' => $existing->id], $values);
            } else {
                $values['added_on'] = $now;
                $this->insert($values);
            }

            $count++;
        }

        return $count;
    }

    public function latest_date_in_range(int $branch_id, string $date_from, string $date_to)
    {
        $sql = <<<EOT
SELECT MAX(snapshot_date) AS snapshot_date
FROM item_inventory_daily
WHERE branch_id = ?
AND snapshot_date BETWEEN ? AND ?
EOT;

        $query = $this->db->query($sql, [$branch_id, $date_from, $date_to]);
        $row = $query ? $query->getRow() : null;

        return $row && $row->snapshot_date ? $row->snapshot_date : null;
    }

    public function get_breakdown(
        int $branch_id,
        string $snapshot_date,
        $type = null,
        $kind = null,
        $term = null,
        $page = null,
        $data_size = null,
        $conversion_type = 'purchase',
        $philhealth_medicine_type = null,
        bool $live = false,
        bool $for_count = false
    ) {
        $binds = [$conversion_type, $branch_id, $snapshot_date];
        $source = 'item_inventory_daily d';
        $cost = 'd.weighted_cost';
        $ending = 'd.ending_cost';
        $cost_join = '';
        $date_filter = 'AND d.snapshot_date = ?';
        if ($live) {
            // Quantities stay in purchase units, as in the daily snapshot job.
            $source = "(SELECT ? AS snapshot_date, b.branch_id, b.item_id, SUM(b.current_qty) AS current_qty
                FROM branch_item b
                INNER JOIN item_conversion purchase_unit ON purchase_unit.id = b.unit
                    AND purchase_unit.is_deleted = 0 AND purchase_unit.conversion_type = 'purchase'
                WHERE b.is_deleted = 0 AND b.branch_id = ?
                GROUP BY b.branch_id, b.item_id) d";
            $cost = 'COALESCE(live_cost.current_amount, item.weighted_cost, 0)';
            $ending = "(d.current_qty * $cost)";
            $cost_join = 'LEFT JOIN item_cost live_cost ON live_cost.id = (
                SELECT ic.id FROM item_cost ic
                WHERE ic.item_id = d.item_id AND ic.branch_id = d.branch_id AND ic.is_deleted = 0
                ORDER BY ic.id DESC LIMIT 1
            )';
            $date_filter = '';
            $binds = [$snapshot_date, $branch_id, $conversion_type, $branch_id];
        }
        $sql = <<<EOT
SELECT
    d.snapshot_date,
    d.branch_id,
    d.item_id,
    item.name AS item_name,
    item.brand AS item_brand,
    item.type,
    item.kind,
    item.selling_price,
    CASE
        WHEN FIND_IN_SET('pharmacy', REPLACE(LOWER(item.kind), ' ', '')) > 0
            THEN COALESCE(item.philhealth_medicine_type, 'regular')
        ELSE NULL
    END AS philhealth_medicine_type,
    item_conversion.unit AS unit_name,
    d.current_qty,
    $cost AS weighted_cost,
    $ending AS ending_cost
FROM $source
INNER JOIN item ON item.id = d.item_id AND item.is_deleted = 0
$cost_join
LEFT JOIN item_conversion ON item_conversion.item_id = d.item_id
    AND item_conversion.conversion_type = ?
    AND item_conversion.is_deleted = 0
WHERE d.branch_id = ?
$date_filter
EOT;

        if ($for_count) {
            $sql = 'SELECT COUNT(*) AS total ' . substr($sql, strpos($sql, 'FROM '));
        }

        if ($type) {
            $sql .= "\nAND item.type = ?";
            $binds[] = $type;
        }

        if ($kind) {
            $kind_ids = explode(',', $kind);
            $conditions = [];
            foreach ($kind_ids as $id) {
                $conditions[] = 'FIND_IN_SET(?, item.kind)';
                $binds[] = trim($id);
            }
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")\n";
        }

        if ($philhealth_medicine_type !== null && $philhealth_medicine_type !== '') {
            $medicine_types = array_values(array_filter(array_map('trim', explode(',', (string) $philhealth_medicine_type))));
            if (!$medicine_types) {
                return $for_count ? 0 : [];
            }

            $medicine_type_placeholders = implode(',', array_fill(0, count($medicine_types), '?'));
            $sql .= "\nAND FIND_IN_SET('pharmacy', REPLACE(LOWER(item.kind), ' ', '')) > 0";
            $sql .= "\nAND COALESCE(item.philhealth_medicine_type, 'regular') IN ($medicine_type_placeholders)";
            array_push($binds, ...$medicine_types);
        }

        if ($term) {
            $sql .= "\nAND (item.name LIKE ? OR item.brand LIKE ?)";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        if (!$for_count) {
            $sql .= " ORDER BY item.name ASC, d.item_id ASC";
            $sql .= sql_limit_clause($page, $data_size);
        }

        $query = $this->db->query($sql, $binds);
        if ($query === false) { return false; }
        return $for_count ? (int) ($query->getRowArray()['total'] ?? 0) : $query->getResultArray();
    }

    /**
     * Purchase-unit qty as of a date from item_history (inclusive end of day).
     *
     * @return array<int,float> keyed by branch_item.id
     */
    public function qty_as_of(int $branch_id, string $as_of): array
    {
        $asOfStamp = $as_of . ' 23:59:59';
        $sql = <<<EOT
SELECT h.branch_item_id, h.current_qty
FROM item_history h
INNER JOIN (
    SELECT branch_item_id, MAX(id) AS max_id
    FROM item_history
    WHERE is_deleted = 0
      AND branch_id = ?
      AND added_on <= ?
    GROUP BY branch_item_id
) latest ON latest.max_id = h.id
EOT;

        $query = $this->db->query($sql, [$branch_id, $asOfStamp]);
        $rows = $query ? $query->getResultArray() : [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['branch_item_id']] = (float)$row['current_qty'];
        }

        return $map;
    }
}

?>
