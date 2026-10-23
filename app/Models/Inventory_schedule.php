<?php

namespace App\Models;

use App\Core\MYT_Model;

class Inventory_schedule extends MYT_Model
{
    protected $table      = 'branch_item';
    protected $primaryKey = 'id';

    private const LAB_CATEGORIES = [
        'Consumable - Direct Materials',
        'Consumable - Reagent',
    ];

    private const CLINIC_CATEGORIES = [
        'Supplies',
        'Equipment',
        'Accessories',
        'Pharmacy',
    ];

    /**
     * @return array{laboratory_supplies:array,clinic_supplies:array}
     */
    public function get_schedule(string $date_from, string $date_to, $branch_id = null, $term = null): array
    {
        $lab    = $this->_empty_section('laboratory');
        $clinic = $this->_empty_section('clinic');

        foreach ($this->_location_targets($branch_id) as $location) {
            $db   = \Config\Database::connect($location['group']);
            $rows = $this->_query_location_items($db, $location['branch_item_id']);
            if (! $rows) {
                continue;
            }

            $begQty    = $this->_qty_as_of($db, $location['branch_item_id'], $date_from, false);
            $endQty    = $this->_qty_as_of($db, $location['branch_item_id'], $date_to, true);
            $available = $this->_available_qty($db, $location['branch_item_id'], $date_from, $date_to);

            foreach ($rows as $row) {
                $id     = (int) $row['branch_item_id'];
                [$section, $category] = $this->_classify($row['type'] ?? '', $row['kind'] ?? '');
                $excelBranch = $location['excel_branch'];

                if ($section === 'laboratory' && ! $location['include_lab']) {
                    continue;
                }
                if ($section === 'clinic' && ! $location['include_clinic']) {
                    continue;
                }

                $cost = $this->_unit_cost($row);
                $beg  = $this->_amount($begQty[$id] ?? (float) ($row['beggining_qty'] ?? 0), $cost);
                $end  = $this->_amount($endQty[$id] ?? (float) ($row['current_qty'] ?? 0), $cost);
                $avail = $this->_amount($available[$id] ?? 0.0, $cost);

                $target = $section === 'laboratory' ? $lab : $clinic;
                $target[$excelBranch][$category]['beginning_inventory'] += $beg;
                $target[$excelBranch][$category]['available_inventory'] += $avail;
                $target[$excelBranch][$category]['total_amount_ending'] += $end;

                if ($section === 'laboratory' && $category === 'Consumable - Reagent') {
                    $target[$excelBranch][$category]['beg_remaining_reagents'] += $beg;
                    $target[$excelBranch][$category]['end_remaining_reagents'] += $end;
                }

                if ($section === 'laboratory') {
                    $lab = $target;
                } else {
                    $clinic = $target;
                }
            }
        }

        $laboratory = $this->_finalize_section($lab, 'laboratory', $this->_lab_branch_order($branch_id));
        $clinicOut  = $this->_finalize_section($clinic, 'clinic', $this->_clinic_branch_order($branch_id));

        $laboratory = $this->_filter_term_section($laboratory, $term);
        $clinicOut  = $this->_filter_term_section($clinicOut, $term);

        return [
            'laboratory_supplies' => $laboratory,
            'clinic_supplies'     => $clinicOut,
        ];
    }

    public function is_empty(array $schedule): bool
    {
        return $this->_section_is_empty($schedule['laboratory_supplies'] ?? [])
            && $this->_section_is_empty($schedule['clinic_supplies'] ?? []);
    }

    /**
     * @return list<array{group:string,branch_item_id:int,excel_branch:string,include_lab:bool,include_clinic:bool}>
     */
    private function _location_targets($branch_id): array
    {
        $all = [
            [
                'group'          => 'consolacion',
                'branch_item_id' => 4,
                'excel_branch'   => 'Consolacion',
                'include_lab'    => true,
                'include_clinic' => true,
            ],
            [
                'group'          => 'oakridge',
                'branch_item_id' => 2,
                'excel_branch'   => 'Oakridge',
                'include_lab'    => true,
                'include_clinic' => true,
            ],
            [
                'group'          => 'pharmacy',
                'branch_item_id' => 6,
                'excel_branch'   => 'Oakridge',
                'include_lab'    => false,
                'include_clinic' => true,
            ],
            [
                'group'          => 'mactan',
                'branch_item_id' => 3,
                'excel_branch'   => 'Mactan',
                'include_lab'    => true,
                'include_clinic' => true,
            ],
            [
                'group'          => 'mantlewood',
                'branch_item_id' => 7,
                'excel_branch'   => 'Mantlewood',
                'include_lab'    => true,
                'include_clinic' => true,
            ],
            [
                'group'          => 'mobile',
                'branch_item_id' => 1,
                'excel_branch'   => 'Mobile',
                'include_lab'    => false,
                'include_clinic' => true,
            ],
            [
                'group'          => 'warehouse',
                'branch_item_id' => 5,
                'excel_branch'   => 'Central Warehouse',
                'include_lab'    => false,
                'include_clinic' => true,
            ],
        ];

        if ($branch_id === null || $branch_id === '') {
            return $all;
        }

        $branch_id = (int) $branch_id;
        $filtered  = [];
        foreach ($all as $location) {
            if ($location['branch_item_id'] === $branch_id) {
                $filtered[] = $location;
            }
            if ($branch_id === 2 && $location['branch_item_id'] === 6) {
                $filtered[] = $location;
            }
        }

        return $filtered;
    }

    private function _query_location_items($db, int $branchItemId): array
    {
        $sql = <<<EOT
SELECT
    branch_item.id AS branch_item_id,
    branch_item.branch_id,
    branch_item.item_id,
    branch_item.beggining_qty,
    branch_item.current_qty,
    item.type,
    item.kind,
    item.cost,
    item.weighted_cost,
    item.name AS item_name,
    IFNULL(item_cost.current_amount, IFNULL(item.weighted_cost, 0)) AS current_cost
FROM branch_item
INNER JOIN item
    ON item.id = branch_item.item_id
   AND item.is_deleted = 0
LEFT JOIN item_cost
    ON item_cost.id = (
        SELECT ic.id
        FROM item_cost ic
        WHERE ic.item_id = item.id
          AND ic.branch_id = branch_item.branch_id
          AND ic.is_deleted = 0
        ORDER BY ic.id DESC
        LIMIT 1
    )
WHERE branch_item.is_deleted = 0
  AND branch_item.branch_id = ?
EOT;

        $query = $db->query($sql, [$branchItemId]);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * @return array<int,float>
     */
    private function _qty_as_of($db, int $branchId, string $asOf, bool $inclusive): array
    {
        $operator  = $inclusive ? '<=' : '<';
        $asOfStamp = $inclusive ? $asOf . ' 23:59:59' : $asOf . ' 00:00:00';

        $sql = <<<EOT
SELECT h.branch_item_id, h.current_qty
FROM item_history h
INNER JOIN (
    SELECT branch_item_id, MAX(id) AS max_id
    FROM item_history
    WHERE is_deleted = 0
      AND branch_id = ?
      AND added_on {$operator} ?
    GROUP BY branch_item_id
) latest ON latest.max_id = h.id
EOT;

        $query = $db->query($sql, [$branchId, $asOfStamp]);
        $rows  = $query ? $query->getResultArray() : [];
        $map   = [];
        foreach ($rows as $row) {
            $map[(int) $row['branch_item_id']] = (float) $row['current_qty'];
        }

        return $map;
    }

    /**
     * @return array<int,float>
     */
    private function _available_qty($db, int $branchId, string $date_from, string $date_to): array
    {
        $sql = <<<EOT
SELECT
    branch_item_id,
    SUM(
        CASE
            WHEN activity IN ('Receipt Note', 'Transfer In') THEN quantity
            WHEN activity = 'Transfer Out' THEN -quantity
            ELSE 0
        END
    ) AS available_qty
FROM item_history
WHERE is_deleted = 0
  AND branch_id = ?
  AND DATE(added_on) BETWEEN ? AND ?
GROUP BY branch_item_id
EOT;

        $query = $db->query($sql, [$branchId, $date_from, $date_to]);
        $rows  = $query ? $query->getResultArray() : [];
        $map   = [];
        foreach ($rows as $row) {
            $map[(int) $row['branch_item_id']] = (float) $row['available_qty'];
        }

        return $map;
    }

    /**
     * @return array{0:string,1:string} section, category
     */
    private function _classify($type, $kind): array
    {
        $typeHay = strtolower(trim((string) $type));
        $kindHay = strtolower(str_replace(',', ' ', (string) $kind));
        $hay     = trim($typeHay . ' ' . $kindHay);

        if ($this->_contains_any($hay, ['equipment'])) {
            return ['clinic', 'Equipment'];
        }
        if ($this->_contains_any($hay, ['accessories', 'accessory'])) {
            return ['clinic', 'Accessories'];
        }
        if ($this->_contains_any($hay, ['pharmacy', 'medicine'])) {
            return ['clinic', 'Pharmacy'];
        }
        if ($this->_contains_any($hay, ['laboratory', 'lab', 'reagent', 'consumable'])) {
            $category = $this->_contains_any($kindHay, ['reagent'])
                ? 'Consumable - Reagent'
                : 'Consumable - Direct Materials';

            return ['laboratory', $category];
        }

        return ['clinic', 'Supplies'];
    }

    private function _contains_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function _unit_cost(array $row): float
    {
        $current = (float) ($row['current_cost'] ?? 0);
        if ($current > 0) {
            return $current;
        }

        return (float) ($row['cost'] ?? 0);
    }

    private function _amount(float $qty, float $cost): float
    {
        return $qty * $cost;
    }

    private function _empty_section(string $section): array
    {
        $categories = $section === 'laboratory' ? self::LAB_CATEGORIES : self::CLINIC_CATEGORIES;
        $branches   = $section === 'laboratory'
            ? ['Consolacion', 'Oakridge', 'Mactan', 'Mantlewood']
            : ['Consolacion', 'Oakridge', 'Mactan', 'Mobile', 'Central Warehouse', 'Mantlewood'];

        $out = [];
        foreach ($branches as $branch) {
            foreach ($categories as $category) {
                $out[$branch][$category] = $this->_zero_money($section);
            }
        }

        return $out;
    }

    private function _zero_money(string $section): array
    {
        $row = [
            'beginning_inventory' => 0.0,
            'available_inventory' => 0.0,
            'total_amount_ending' => 0.0,
        ];
        if ($section === 'laboratory') {
            $row['beg_remaining_reagents'] = 0.0;
            $row['end_remaining_reagents'] = 0.0;
        }

        return $row;
    }

    /**
     * @param list<string> $branchOrder
     * @return array{rows:list<array>,total:array}
     */
    private function _finalize_section(array $grouped, string $section, array $branchOrder): array
    {
        $categories = $section === 'laboratory' ? self::LAB_CATEGORIES : self::CLINIC_CATEGORIES;
        $rows       = [];
        $total      = $this->_money_row($section, 'TOTAL', null, true, $this->_zero_money($section));

        foreach ($branchOrder as $branch) {
            if (! isset($grouped[$branch])) {
                continue;
            }

            $branchSum = $this->_zero_money($section);
            foreach ($categories as $category) {
                $money = $grouped[$branch][$category] ?? $this->_zero_money($section);
                $branchSum = $this->_add_money($branchSum, $money);
            }

            $rows[] = $this->_money_row($section, $branch, null, false, $branchSum);
            foreach ($categories as $category) {
                $money  = $grouped[$branch][$category] ?? $this->_zero_money($section);
                $rows[] = $this->_money_row($section, $branch, $category, false, $money);
            }

            $total = $this->_add_money($total, $branchSum);
        }

        $totalRow = $this->_money_row($section, 'TOTAL', null, true, $total);

        return [
            'rows'  => $rows,
            'total' => $totalRow,
        ];
    }

    private function _money_row(string $section, string $branch, $category, bool $isTotal, array $money): array
    {
        $consumption = $this->_round(
            ($money['beginning_inventory'] ?? 0)
            + ($money['available_inventory'] ?? 0)
            - ($money['total_amount_ending'] ?? 0)
        );

        $row = [
            'branch'               => $branch,
            'category'             => $category,
            'is_total'             => $isTotal,
            'beginning_inventory'  => $this->_round($money['beginning_inventory'] ?? 0),
            'available_inventory'  => $this->_round($money['available_inventory'] ?? 0),
            'total_amount_ending'  => $this->_round($money['total_amount_ending'] ?? 0),
            'total_consumption'    => $consumption,
        ];

        if ($section === 'laboratory') {
            $begReag = $this->_round($money['beg_remaining_reagents'] ?? 0);
            $endReag = $this->_round($money['end_remaining_reagents'] ?? 0);
            $row['beg_remaining_reagents']     = $begReag;
            $row['end_remaining_reagents']     = $endReag;
            $row['total_consumption_reagents'] = $this->_round($consumption + $begReag - $endReag);
        }

        return $row;
    }

    private function _add_money(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $left[$key] = ($left[$key] ?? 0) + (float) $value;
        }

        return $left;
    }

    private function _round($value): float
    {
        return round((float) $value, 2);
    }

    /**
     * @return list<string>
     */
    private function _lab_branch_order($branch_id): array
    {
        $order = ['Consolacion', 'Oakridge', 'Mactan', 'Mantlewood'];
        if ($branch_id === null || $branch_id === '') {
            return $order;
        }

        $map = [
            2 => 'Oakridge',
            3 => 'Mactan',
            4 => 'Consolacion',
            7 => 'Mantlewood',
        ];
        $name = $map[(int) $branch_id] ?? null;

        return $name && in_array($name, $order, true) ? [$name] : [];
    }

    /**
     * @return list<string>
     */
    private function _clinic_branch_order($branch_id): array
    {
        $order = ['Consolacion', 'Oakridge', 'Mactan', 'Mobile', 'Central Warehouse', 'Mantlewood'];
        if ($branch_id === null || $branch_id === '') {
            return $order;
        }

        $map = [
            1 => 'Mobile',
            2 => 'Oakridge',
            3 => 'Mactan',
            4 => 'Consolacion',
            5 => 'Central Warehouse',
            7 => 'Mantlewood',
        ];
        $name = $map[(int) $branch_id] ?? null;

        return $name && in_array($name, $order, true) ? [$name] : [];
    }

    private function _filter_term_section(array $section, $term): array
    {
        $term = trim((string) $term);
        if ($term === '' || empty($section['rows'])) {
            return $section;
        }

        $needle = strtolower($term);
        $rows   = array_values(array_filter($section['rows'], static function ($row) use ($needle) {
            return str_contains(strtolower((string) ($row['branch'] ?? '')), $needle)
                || str_contains(strtolower((string) ($row['category'] ?? '')), $needle);
        }));

        $total = $this->_zero_money(isset($section['total']['beg_remaining_reagents']) ? 'laboratory' : 'clinic');
        $kind  = isset($section['total']['beg_remaining_reagents']) ? 'laboratory' : 'clinic';
        foreach ($rows as $row) {
            if ($row['category'] !== null) {
                continue;
            }
            $total = $this->_add_money($total, $row);
        }

        $section['rows']  = $rows;
        $section['total'] = $this->_money_row($kind, 'TOTAL', null, true, $total);

        return $section;
    }

    private function _section_is_empty(array $section): bool
    {
        $total = $section['total'] ?? [];
        $keys  = ['beginning_inventory', 'available_inventory', 'total_amount_ending', 'total_consumption'];
        foreach ($keys as $key) {
            if (abs((float) ($total[$key] ?? 0)) > 0.001) {
                return false;
            }
        }

        return true;
    }
}
