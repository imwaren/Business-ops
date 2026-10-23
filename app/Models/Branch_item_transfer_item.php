<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Branch_item_transfer_item extends MYT_Model
{
    protected $table = 'branch_item_transfer_item';
    protected $primaryKey = 'id';
    protected $transfer_error_message;
    protected $allowedFields = [
        'batch_no',
        'receipt_note_item_expiry_date_id',
        'branch_item_transfer_id',
        'branch_item_id',
        'item_id',
        'transfer_qty',
        'unit',
    ];

    public function get_details($branch_item_transfer_id = null, $item_id = null, $branch_id = null, $branch_item_id = null, $status = null, $page = null, $data_size = null, $for_update = false) {
        $binds = [];
        $sql = <<<EOT
SELECT branch_item_transfer_item.*,
    item.name AS item_name,
    item.is_batch,
    branch_item_transfer_item.unit,
    item_conversion.conversion_type,
    item_conversion.unit AS item_unit_name
FROM branch_item_transfer_item
LEFT JOIN branch_item_transfer ON branch_item_transfer.id = branch_item_transfer_item.branch_item_transfer_id
LEFT JOIN item ON item.id = branch_item_transfer_item.item_id
LEFT JOIN item_conversion ON item_conversion.id = branch_item_transfer_item.unit
WHERE branch_item_transfer_item.is_deleted = 0 
EOT;

        if ($branch_item_transfer_id) {
            $sql .= <<<EOT

AND branch_item_transfer_item.branch_item_transfer_id = ?
EOT;
            $binds[] = $branch_item_transfer_id;
        }

        if ($item_id) {
            $sql .= <<<EOT

AND branch_item_transfer_item.item_id = ?
EOT;
            $binds[] = $item_id;
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND branch_item_transfer_item.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($branch_item_id) {
            $sql .= <<<EOT

AND branch_item_transfer_item.branch_item_id = ?
EOT;
            $binds[] = $branch_item_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND branch_item_transfer_item.status = ?
EOT;
            $binds[] = $status;
        }

        // $sql .= " ORDER BY branch_item_transfer_item.id DESC";

        $sql .= sql_limit_clause($page, $data_size);
        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }

        return $this->_get_transfer_rows($sql, $binds);
    }
    /**
     * Lock item rows in a consistent order before reserving or receiving quantities.
     */
    public function lock_items($items)
    {
        $item_ids = array_values(array_unique(array_column($items, 'item_id')));
        sort($item_ids, SORT_NUMERIC);
        if (!$item_ids) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $sql = "SELECT id FROM item WHERE id IN ($placeholders) AND is_deleted = 0 ORDER BY id FOR UPDATE";
        $rows = $this->_get_transfer_rows($sql, $item_ids);
        return $rows !== false && count($rows) === count($item_ids);
    }

    /**
     * Validate all lines together in the common item-conversion quantity.
     */
    public function validate_quantities($items, $branch_id_from, $exclude_transfer_id = null)
    {
        $this->transfer_error_message = null;
        if (!$this->lock_items($items)) {
            $this->transfer_error_message = 'Failed to lock the selected inventory items.';
            return false;
        }

        $expiry_ids = array_values(array_unique(array_filter(array_column($items, 'receipt_note_item_expiry_date_id'))));
        $expiry_rows = $this->get_receipt_expiry_details($branch_id_from, $expiry_ids);
        if ($expiry_rows === false) {
            $this->transfer_error_message = 'Failed to read receipt expiry selections.';
            return false;
        }
        $expiry_rows = array_column($expiry_rows, null, 'id');
        foreach ($items as $index => $item) {
            $expiry_id = $item['receipt_note_item_expiry_date_id'] ?? null;
            if ($expiry_id === null) {
                continue;
            }
            $row = $expiry_rows[$expiry_id] ?? null;
            if (!$row || (int) $row['linked_item_id'] !== (int) $item['item_id']
                || (string) $row['batch_no'] !== (string) $item['batch_no']) {
                $this->transfer_error_message = 'items['.$index.'].receipt_note_item_expiry_date_id must match an active approved source receipt allocation for the item and batch.';
                return false;
            }
        }

        $totals = [];
        $batches = [];
        $allocations = [];
        $allocation_indexes = [];
        $stock = [];
        $movements = [];
        foreach ($items as $index => $item) {
            $item_id = (int) $item['item_id'];
            if (!isset($stock[$item_id])) {
                $stock[$item_id] = $this->get_source_stock($item_id, $branch_id_from);
                $movements[$item_id] = $this->get_quantity_movements($item_id, $branch_id_from);
                if (!$stock[$item_id] || $movements[$item_id] === false) {
                    $this->transfer_error_message = $this->transfer_error_message
                        ?: 'Failed to read source inventory or transfer quantities.';
                    return false;
                }
            }
            $source = null;
            $seen_units = [];
            foreach ($stock[$item_id] as $row) {
                if (isset($seen_units[$row['unit']])) {
                    $this->transfer_error_message = 'Source inventory is ambiguous for the selected item and unit.';
                    return false;
                }
                $seen_units[$row['unit']] = true;
                if (!$row['id'] || (float) $row['conversion_qty'] <= 0) {
                    $this->transfer_error_message = 'Invalid source item conversion.';
                    return false;
                }
                if ((int) $row['id'] === (int) $item['branch_item_id'] && (int) $row['unit'] === (int) $item['unit']) {
                    $source = $row;
                }
            }
            if (!$source) {
                $this->transfer_error_message = 'branch_item_id must match the source branch, item, and unit.';
                return false;
            }
            $quantity = (float) $item['transfer_qty'] / (float) $source['conversion_qty'];
            $totals[$item_id] = ($totals[$item_id] ?? 0) + $quantity;
            if ($item['batch_no'] !== null) {
                $batch_key = 'batch:'.(string) $item['batch_no'];
                $batches[$item_id][$batch_key] = ($batches[$item_id][$batch_key] ?? 0) + $quantity;
                $expiry_id = $item['receipt_note_item_expiry_date_id'] ?? null;
                if ($expiry_id !== null) {
                    $allocations[$item_id][$batch_key][$expiry_id] = ($allocations[$item_id][$batch_key][$expiry_id] ?? 0) + $quantity;
                    $allocation_indexes[$item_id][$batch_key][$expiry_id] = $index;
                }
            }
        }

        foreach ($totals as $item_id => $quantity) {
            $reserved_qty = 0;
            $batch_reserved = [];
            $allocation_reserved = [];
            foreach ($movements[$item_id] as $movement) {
                $conversion_qty = (float) $movement['conversion_qty'];
                if ($conversion_qty <= 0) {
                    $this->transfer_error_message = 'Transfer history has an unavailable item conversion.';
                    return false;
                }
                $remaining_qty = max(0, (float) $movement['transfer_qty'] - (float) $movement['received_qty']) / $conversion_qty;
                $is_source = (int) $movement['branch_id_from'] === (int) $branch_id_from;
                $is_reserved = $is_source && (int) $movement['branch_item_transfer_id'] !== (int) $exclude_transfer_id
                    && in_array($movement['status'], ['pending', 'approved', 'opened'], true);
                if ($is_reserved) {
                    $reserved_qty += $remaining_qty;
                }
                if ($movement['batch_no'] === null) {
                    continue;
                }
                $batch_key = 'batch:'.(string) $movement['batch_no'];
                if ($is_reserved) {
                    $batch_reserved[$batch_key] = ($batch_reserved[$batch_key] ?? 0) + $remaining_qty;
                    $expiry_id = $movement['receipt_note_item_expiry_date_id'] ?? null;
                    if ($expiry_id !== null) {
                        $allocation_reserved[$batch_key][$expiry_id] = ($allocation_reserved[$batch_key][$expiry_id] ?? 0) + $remaining_qty;
                    }
                }
            }
            $factor = max(array_column($stock[$item_id], 'conversion_qty'));
            foreach ($stock[$item_id] as $row) {
                $available_qty = (float) $row['current_qty'] / (float) $row['conversion_qty'] - $reserved_qty;
                if ($this->_quantity_exceeds($quantity, $available_qty, $factor)) {
                    $this->transfer_error_message = 'Transfer quantity exceeds available source stock for item '.$item_id.'.';
                    return false;
                }
            }
            foreach ($batches[$item_id] ?? [] as $batch_key => $batch_qty) {
                $batch_no = substr($batch_key, 6);
                $receipts = $this->get_batch_receipts($item_id, $branch_id_from, $batch_no);
                if ($receipts === false) {
                    $this->transfer_error_message = 'Failed to read receipt quantities for batch '.$batch_no.'.';
                    return false;
                }
                $balance_qty = 0;
                foreach ($receipts as $receipt) {
                    if ((float) $receipt['conversion_qty'] <= 0) {
                        $this->transfer_error_message = 'Receipt history has an unavailable item conversion.';
                        return false;
                    }
                    if ((float) $receipt['qty_balance'] < 0) {
                        $this->transfer_error_message = 'Invalid source batch balance for item '.$item_id.', batch '.$batch_no.'.';
                        return false;
                    }
                    $balance_qty += (float) $receipt['qty_balance'] / (float) $receipt['conversion_qty'];
                }
                $receipt_rows = array_column($receipts, null, 'id');
                foreach ($allocations[$item_id][$batch_key] ?? [] as $expiry_id => $allocation_qty) {
                    $index = $allocation_indexes[$item_id][$batch_key][$expiry_id];
                    if (!isset($receipt_rows[$expiry_id])) {
                        $this->transfer_error_message = 'items['.$index.'].receipt_note_item_expiry_date_id must match an active approved source receipt allocation for the item and batch.';
                        return false;
                    }
                    $receipt = $receipt_rows[$expiry_id];
                    $available_qty = (float) $receipt['qty_balance'] / (float) $receipt['conversion_qty']
                        - ($allocation_reserved[$batch_key][$expiry_id] ?? 0);
                    if ($this->_quantity_exceeds($allocation_qty, $available_qty, $factor)) {
                        $this->transfer_error_message = 'Transfer quantity exceeds available source batch stock for item '.$item_id.', batch '.$batch_no.', allocation '.$expiry_id.' (items['.$index.']).';
                        return false;
                    }
                }
                $available_qty = $balance_qty - ($batch_reserved[$batch_key] ?? 0);
                if ($this->_quantity_exceeds($batch_qty, $available_qty, $factor)) {
                    $this->transfer_error_message = 'Transfer quantity exceeds available source batch stock for item '.$item_id.', batch '.$batch_no.'.';
                    return false;
                }
            }
        }
        return true;
    }

    public function get_source_stock($item_id, $branch_id)
    {
        $schema = $this->_get_receipt_schema($branch_id);
        if (!$schema) {
            return false;
        }
        $sql = <<<EOT
SELECT branch_item.id, item_conversion.id AS unit, branch_item.current_qty, item_conversion.qty AS conversion_qty
FROM {$schema}.item_conversion AS item_conversion
LEFT JOIN {$schema}.branch_item AS branch_item ON branch_item.unit = item_conversion.id AND branch_item.item_id = item_conversion.item_id
    AND branch_item.branch_id = ? AND branch_item.is_deleted = 0
WHERE item_conversion.item_id = ? AND item_conversion.is_deleted = 0
ORDER BY item_conversion.id, branch_item.id
FOR UPDATE
EOT;
        return $this->_get_transfer_rows($sql, [$branch_id, $item_id]);
    }

    /**
     * Create inventory only in the receiving branch, retaining the global MAX + 1 convention.
     */
    public function insert_branch_stock($branch_id, $values)
    {
        $schema = $this->_get_receipt_schema($branch_id);
        if (!$schema) {
            $this->transfer_error_message = 'Failed to resolve destination inventory database.';
            return false;
        }
        $max_id = 0;
        foreach ([0, 1, 2, 3, 4, 7, 5, 6] as $inventory_branch_id) {
            $inventory_schema = $inventory_branch_id === 0
                ? '`'.str_replace('`', '``', $this->db->getDatabase()).'`'
                : $this->_get_receipt_schema($inventory_branch_id);
            if (!$inventory_schema) {
                $this->transfer_error_message = 'Failed to resolve inventory ID allocation database.';
                return false;
            }
            // Use a current read after waiting for another transfer's item lock.
            $rows = $this->_get_transfer_rows("SELECT MAX(id) AS max_id FROM {$inventory_schema}.branch_item FOR UPDATE", []);
            if ($rows === false) {
                $this->transfer_error_message = 'Failed to allocate destination inventory ID.';
                return false;
            }
            $max_id = max($max_id, (int) $rows[0]['max_id']);
        }
        $values['id'] = $max_id + 1;
        $values['branch_id'] = $branch_id;
        try {
            if ($this->db->table($schema.'.branch_item')->insert($values)) {
                return $values['id'];
            }
        } catch (\Throwable $error) {
            log_message('error', 'Transfer inventory insert failed: {exception}', ['exception' => get_class($error)]);
        }
        $this->transfer_error_message = 'Failed to create destination inventory.';
        return false;
    }

    /**
     * Update a locked branch-local inventory row through the transfer transaction.
     */
    public function update_branch_stock($branch_id, $item_id, $branch_item_id, $values)
    {
        $schema = $this->_get_receipt_schema($branch_id);
        if (!$schema) {
            $this->transfer_error_message = 'Failed to resolve transfer inventory database.';
            return false;
        }
        $where = ['id' => $branch_item_id, 'item_id' => $item_id, 'branch_id' => $branch_id, 'is_deleted' => 0];
        try {
            if ($this->db->table($schema.'.branch_item')->where($where)->update($values)) {
                return true;
            }
        } catch (\Throwable $error) {
            log_message('error', 'Transfer inventory update failed: {exception}', ['exception' => get_class($error)]);
        }
        $this->transfer_error_message = 'Failed to update branch-local transfer inventory.';
        return false;
    }

    public function get_quantity_movements($item_id, $branch_id)
    {
        $sql = <<<EOT
SELECT branch_item_transfer_item.*, branch_item_transfer.branch_id_from,
    branch_item_transfer.branch_id_to, branch_item_transfer.status,
    item_conversion.qty AS conversion_qty
FROM branch_item_transfer_item
INNER JOIN branch_item_transfer ON branch_item_transfer.id = branch_item_transfer_item.branch_item_transfer_id
LEFT JOIN item_conversion ON item_conversion.id = branch_item_transfer_item.unit
    AND item_conversion.item_id = branch_item_transfer_item.item_id AND item_conversion.is_deleted = 0
WHERE branch_item_transfer_item.item_id = ? AND branch_item_transfer_item.is_deleted = 0
    AND branch_item_transfer.is_deleted = 0
    AND (branch_item_transfer.branch_id_from = ? OR branch_item_transfer.branch_id_to = ?)
ORDER BY branch_item_transfer_item.id
FOR UPDATE
EOT;
        return $this->_get_transfer_rows($sql, [$item_id, $branch_id, $branch_id]);
    }

    public function get_batch_receipts($item_id, $branch_id, $batch_no)
    {
        $schema = $this->_get_receipt_schema($branch_id);
        if (!$schema) {
            return false;
        }
        $sql = <<<EOT
SELECT expiry.id, expiry.date, expiry.qty, expiry.qty_balance, item_conversion.qty AS conversion_qty
FROM {$schema}.receipt_note_item_expiry_date AS expiry
INNER JOIN {$schema}.receipt_note_item AS receipt_item ON receipt_item.id = expiry.receipt_note_item_id
    AND receipt_item.receipt_note_id = expiry.receipt_note_id
INNER JOIN {$schema}.receipt_note AS receipt ON receipt.id = receipt_item.receipt_note_id
LEFT JOIN {$schema}.item_conversion AS item_conversion ON item_conversion.id = receipt_item.unit
    AND item_conversion.item_id = receipt_item.item_id AND item_conversion.is_deleted = 0
WHERE receipt.branch_id = ? AND receipt.status = 'approved' AND receipt.is_deleted = 0
    AND receipt_item.is_deleted = 0 AND expiry.is_deleted = 0
    AND receipt_item.item_id = ? AND BINARY expiry.batch_no = BINARY ?
ORDER BY expiry.date, expiry.id
FOR UPDATE
EOT;
        return $this->_get_transfer_rows($sql, [$branch_id, $item_id, (string) $batch_no]);
    }

    /**
     * Consume the selected allocation, or earliest available allocations for legacy unlinked lines.
     * The caller owns the master transaction, including these qualified source writes.
     */
    public function deduct_batch_balance($item_id, $branch_id, $batch_no, $unit, $quantity, $requester,
        $expiry_id = null, $transfer_id = null, $projected_receipts = [])
    {
        $this->transfer_error_message = null;
        $schema = $this->_get_receipt_schema($branch_id);
        if (!$schema) {
            $this->transfer_error_message = 'Failed to resolve source batch database.';
            return false;
        }
        $conversions = $this->_get_transfer_rows(
            "SELECT qty FROM {$schema}.item_conversion WHERE id = ? AND item_id = ? AND is_deleted = 0 FOR UPDATE",
            [$unit, $item_id]
        );
        if (!$conversions || (float) $conversions[0]['qty'] <= 0) {
            $this->transfer_error_message = 'Source batch has an unavailable item conversion.';
            return false;
        }
        $factor = (float) $conversions[0]['qty'];
        $remaining_qty = (float) $quantity / $factor;
        $receipts = $this->get_batch_receipts($item_id, $branch_id, $batch_no);
        if ($receipts === false) {
            $this->transfer_error_message = 'Failed to read source batch balances.';
            return false;
        }
        $reserved = $this->_get_allocation_reservations($item_id, $branch_id, $batch_no, $transfer_id, $projected_receipts, $expiry_id !== null);
        if ($reserved === false) {
            return false;
        }
        if ($expiry_id !== null) {
            $receipts = array_values(array_filter($receipts, static function ($receipt) use ($expiry_id) {
                return (int) $receipt['id'] === (int) $expiry_id;
            }));
        }
        foreach ($receipts as $receipt) {
            $conversion_qty = (float) $receipt['conversion_qty'];
            if ((float) $receipt['qty_balance'] < 0 || $conversion_qty <= 0) {
                $this->transfer_error_message = 'Invalid source batch balance or conversion for item '.$item_id.', batch '.$batch_no.'.';
                return false;
            }
            $factor = max($factor, $conversion_qty);
        }
        $deductions = [];
        foreach ($receipts as $receipt) {
            $conversion_qty = (float) $receipt['conversion_qty'];
            $balance = max(0, (float) $receipt['qty_balance'] - ($reserved[$receipt['id']] ?? 0) * $conversion_qty);
            $deduction = round(min($balance, $remaining_qty * $conversion_qty), 4, PHP_ROUND_HALF_UP);
            if ($deduction <= 0) {
                continue;
            }
            $deductions[] = ['id' => $receipt['id'], 'quantity' => $deduction];
            $remaining_qty -= $deduction / $conversion_qty;
            if (!$this->_quantity_exceeds($remaining_qty, 0, $factor)) {
                break;
            }
        }
        if ($this->_quantity_exceeds($remaining_qty, 0, $factor)) {
            $this->transfer_error_message = 'Transfer quantity exceeds available source batch stock for item '.$item_id.', batch '.$batch_no.'.';
            return false;
        }
        $sql = <<<EOT
UPDATE {$schema}.receipt_note_item_expiry_date
SET qty_balance = qty_balance - ?, updated_by = ?, updated_on = ?
WHERE id = ? AND is_deleted = 0 AND qty_balance >= ?
EOT;
        try {
            foreach ($deductions as $deduction) {
                $quantity = number_format($deduction['quantity'], 4, '.', '');
                $binds = [$quantity, $requester, date('Y-m-d H:i:s'), $deduction['id'], $quantity];
                if (!$this->db->query($sql, $binds) || $this->db->affectedRows() !== 1) {
                    $this->transfer_error_message = 'Failed to update source batch balance.';
                    return false;
                }
            }
        } catch (\Throwable $error) {
            log_message('error', 'Transfer batch balance update failed: {exception}', ['exception' => get_class($error)]);
            $this->transfer_error_message = 'Failed to update source batch balance.';
            return false;
        }
        return true;
    }

    /**
     * Keep pooled legacy deductions clear of outstanding explicitly linked allocations.
     */
    private function _get_allocation_reservations($item_id, $branch_id, $batch_no, $transfer_id, $projected_receipts, $linked)
    {
        $movements = $this->get_quantity_movements($item_id, $branch_id);
        if ($movements === false) {
            $this->transfer_error_message = 'Failed to read allocation reservations.';
            return false;
        }
        $reserved = [];
        foreach ($movements as $movement) {
            $expiry_id = $movement['receipt_note_item_expiry_date_id'] ?? null;
            if ($expiry_id === null || (int) $movement['branch_id_from'] !== (int) $branch_id
                || (string) $movement['batch_no'] !== (string) $batch_no
                || !in_array($movement['status'], ['pending', 'approved', 'opened'], true)) {
                continue;
            }
            $is_current = (int) $movement['branch_item_transfer_id'] === (int) $transfer_id;
            if ($linked && $is_current) {
                continue;
            }
            $conversion_qty = (float) $movement['conversion_qty'];
            if ($conversion_qty <= 0) {
                $this->transfer_error_message = 'Transfer history has an unavailable item conversion.';
                return false;
            }
            $received_qty = $is_current ? ($projected_receipts[$movement['id']] ?? $movement['received_qty']) : $movement['received_qty'];
            $reserved[$expiry_id] = ($reserved[$expiry_id] ?? 0)
                + max(0, (float) $movement['transfer_qty'] - (float) $received_qty) / $conversion_qty;
        }
        return $reserved;
    }

    /**
     * Load current receipt allocations once per source branch for transfer GET responses.
     */
    public function get_transfer_expiry_details($transfers)
    {
        $branch_ids = [];
        foreach ($transfers as $transfer) {
            foreach ($transfer['transfer_items'] as $item) {
                if (!empty($item['receipt_note_item_expiry_date_id'])) {
                    $branch_ids[$transfer['branch_id_from']][] = $item['receipt_note_item_expiry_date_id'];
                }
            }
        }
        $details = [];
        foreach ($branch_ids as $branch_id => $ids) {
            $rows = $this->get_receipt_expiry_details($branch_id, array_values(array_unique($ids)));
            if ($rows === false) {
                return false;
            }
            $details[$branch_id] = array_column($rows, null, 'id');
        }
        foreach ($transfers as $index => $transfer) {
            foreach ($transfer['transfer_items'] as $item_index => $item) {
                $row = $details[$transfer['branch_id_from']][$item['receipt_note_item_expiry_date_id'] ?? ''] ?? null;
                if ($row && ((int) $row['linked_item_id'] !== (int) $item['item_id']
                    || (string) $row['batch_no'] !== (string) $item['batch_no'])) {
                    $row = null;
                }
                if ($row !== null) {
                    unset($row['linked_item_id']);
                }
                $transfers[$index]['transfer_items'][$item_index]['receipt_note_item_expiry_date'] = $row;
            }
        }
        return $transfers;
    }

    public function get_receipt_expiry_details($branch_id, $ids)
    {
        if (!$ids) {
            return [];
        }
        $schema = $this->_get_receipt_schema($branch_id);
        if (!$schema) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT expiry.*, receipt_item.item_id AS linked_item_id
FROM {$schema}.receipt_note_item_expiry_date AS expiry
INNER JOIN {$schema}.receipt_note_item AS receipt_item ON receipt_item.id = expiry.receipt_note_item_id
    AND receipt_item.receipt_note_id = expiry.receipt_note_id
INNER JOIN {$schema}.receipt_note AS receipt ON receipt.id = receipt_item.receipt_note_id
WHERE expiry.id IN ({$placeholders}) AND expiry.is_deleted = 0 AND receipt_item.is_deleted = 0
    AND receipt.is_deleted = 0 AND receipt.status = 'approved' AND receipt.branch_id = ?
ORDER BY expiry.id
EOT;
        return $this->_get_transfer_rows($sql, array_merge($ids, [$branch_id]));
    }

    public function get_quantity_error()
    {
        return $this->transfer_error_message;
    }

    /**
     * Receipt batch allocations and actual received transfers, without mirrored duplicates.
     */
    public function get_transfer_report($item_id = null, $batch_no = null, $page = null, $data_size = null,
        $brand = null, $generic = null, $dosage = null, $form = null, $kind = null, $term = null)
    {
        [$sql, $binds] = $this->_get_transfer_report_sql($item_id, $batch_no, $brand, $generic, $dosage, $form, $kind, $term);
        $report_sql = $this->_get_grouped_transfer_report_sql($sql);
        $report_sql .= " ORDER BY BINARY batch_no ASC, movement_date, CASE WHEN activity = 'Receipt' THEN 0 ELSE 1 END,
            item_id, branch_id, receipt_note_id, receipt_note_item_id, expiry_id,
            branch_id_from, branch_id_to, BINARY conversion_type, unit, transfer_id, transfer_item_id";
        $report_sql .= sql_limit_clause($page, $data_size);
        $rows = $this->_get_transfer_rows($report_sql, array_merge($binds, $binds));
        return $rows === false ? false : $this->_get_transfer_report_csv_details($rows, $sql, $binds);
    }

    public function get_transfer_report_count($item_id = null, $batch_no = null,
        $brand = null, $generic = null, $dosage = null, $form = null, $kind = null, $term = null)
    {
        [$sql, $binds] = $this->_get_transfer_report_sql($item_id, $batch_no, $brand, $generic, $dosage, $form, $kind, $term);
        $sql = $this->_get_grouped_transfer_report_sql($sql);
        $rows = $this->_get_transfer_rows('SELECT COUNT(*) AS total FROM ('.$sql.') AS movements', array_merge($binds, $binds));
        return $rows === false ? false : (int) $rows[0]['total'];
    }

    /**
     * Keep receipt allocations separate and aggregate transfer quantities before pagination.
     */
    private function _get_grouped_transfer_report_sql($source_sql)
    {
        return <<<EOT
SELECT item_id, item_name, brand, generic, dosage, form, kind, branch_item_id,
    branch_id, branch_name, branch_id_from, branch_name_from, branch_id_to, branch_name_to,
    unit, unit_name, conversion_type, batch_no, activity, quantity, beginning_qty, current_qty,
    transfer_qty, transfer_id, transfer_item_id, receipt_note_id, receipt_note_item_id,
    expiry_id, expiry_date, movement_date
FROM ({$source_sql}) AS receipt_movements WHERE activity = 'Receipt'
UNION ALL
SELECT item_id, MAX(item_name), MAX(brand), MAX(generic), MAX(dosage), MAX(form), MAX(kind),
    CASE WHEN COUNT(DISTINCT branch_item_id) = 1 AND COUNT(branch_item_id) = COUNT(*)
        THEN MAX(branch_item_id) ELSE NULL END,
    NULL, NULL, branch_id_from, MAX(branch_name_from), branch_id_to, MAX(branch_name_to),
    unit, MAX(unit_name), MAX(conversion_type), MAX(batch_no), 'Transfer',
    SUM(quantity), SUM(beginning_qty), SUM(current_qty), SUM(transfer_qty),
    MIN(transfer_id), MIN(transfer_item_id), NULL, NULL, NULL, NULL, MIN(movement_date)
FROM ({$source_sql}) AS transfer_movements WHERE activity = 'Transfer'
GROUP BY branch_id_from, branch_id_to, item_id, BINARY batch_no, BINARY conversion_type, unit
EOT;
    }

    /**
     * Build untruncated reference lists only for transfer groups on the selected page.
     */
    private function _get_transfer_report_csv_details($rows, $source_sql, $binds)
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            if ($row['activity'] === 'Transfer') {
                $groups[$this->_get_transfer_report_group_key($row)] = $index;
            }
        }
        if (!$groups) {
            return $rows;
        }
        $details = [];
        $expiry_ids = [];
        foreach (array_chunk($groups, 100, true) as $page_groups) {
            $conditions = [];
            $group_binds = [];
            foreach ($page_groups as $index) {
                $conditions[] = '(movements.branch_id_from <=> ? AND movements.branch_id_to <=> ?
                    AND movements.item_id <=> ? AND BINARY movements.batch_no <=> BINARY ?
                    AND BINARY movements.conversion_type <=> BINARY ? AND movements.unit <=> ?)';
                foreach (['branch_id_from', 'branch_id_to', 'item_id', 'batch_no', 'conversion_type', 'unit'] as $field) {
                    $group_binds[] = $rows[$index][$field];
                }
            }
            $sql = 'SELECT movements.* FROM ('.$source_sql.") AS movements WHERE activity = 'Transfer' AND ("
                .implode(' OR ', $conditions).') ORDER BY transfer_item_id';
            $group_details = $this->_get_transfer_rows($sql, array_merge($binds, $group_binds));
            if ($group_details === false) {
                return false;
            }
            foreach ($group_details as $detail) {
                $details[] = $detail;
                if ($detail['_receipt_expiry_id'] !== null) {
                    $expiry_ids[$detail['branch_id_from']][$detail['_receipt_expiry_id']] = $detail['_receipt_expiry_id'];
                }
            }
        }
        $expiries = [];
        foreach ($expiry_ids as $branch_id => $ids) {
            foreach (array_chunk(array_values($ids), 500) as $chunk) {
                $allocations = $this->get_receipt_expiry_details($branch_id, $chunk);
                if ($allocations === false) {
                    return false;
                }
                foreach ($allocations as $allocation) {
                    $expiries[$branch_id][$allocation['id']] = $allocation;
                }
            }
        }
        $csv_fields = ['transfer_id', 'transfer_item_id', 'receipt_note_id', 'receipt_note_item_id', 'movement_date'];
        $values = [];
        foreach ($details as $detail) {
            $index = $groups[$this->_get_transfer_report_group_key($detail)];
            $allocation = $expiries[$detail['branch_id_from']][$detail['_receipt_expiry_id'] ?? ''] ?? null;
            if ($allocation && (string) $allocation['linked_item_id'] === (string) $detail['item_id']
                && (string) $allocation['batch_no'] === (string) $detail['batch_no']) {
                $detail['receipt_note_id'] = $allocation['receipt_note_id'];
                $detail['receipt_note_item_id'] = $allocation['receipt_note_item_id'];
            }
            foreach ($csv_fields as $field) {
                if ($detail[$field] !== null && trim((string) $detail[$field]) !== '') {
                    $value = (string) $detail[$field];
                    $values[$index][$field]['value:'.$value] = $value;
                }
            }
        }
        foreach ($groups as $index) {
            foreach ($csv_fields as $field) {
                $field_values = array_values($values[$index][$field] ?? []);
                sort($field_values, $field === 'movement_date' ? SORT_STRING : SORT_NATURAL);
                $rows[$index][$field] = $field_values ? implode(',', $field_values) : null;
            }
        }
        return $rows;
    }

    private function _get_transfer_report_group_key($row)
    {
        $values = [];
        foreach (['branch_id_from', 'branch_id_to', 'item_id', 'batch_no', 'conversion_type', 'unit'] as $field) {
            $values[] = $row[$field] === null ? null : (string) $row[$field];
        }
        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    private function _get_transfer_report_sql($item_id, $batch_no, $brand, $generic, $dosage, $form, $kind, $term)
    {
        $binds = [];
        $sql = <<<EOT
SELECT item.id AS item_id, item.name AS item_name,
    item.brand, item.generic, item.dosage, item.form, item.kind,
    branch_item_transfer_item.branch_item_id,
    NULL AS branch_id, NULL AS branch_name,
    branch_item_transfer.branch_id_from, branch_from.name AS branch_name_from,
    branch_item_transfer.branch_id_to, branch_to.name AS branch_name_to,
    branch_item_transfer_item.unit, item_conversion.unit AS unit_name, item_conversion.conversion_type,
    branch_item_transfer_item.batch_no, 'Transfer' AS activity,
    branch_item_transfer_item.received_qty AS quantity, NULL AS beginning_qty, NULL AS current_qty,
    branch_item_transfer_item.transfer_qty, branch_item_transfer.id AS transfer_id,
    branch_item_transfer_item.id AS transfer_item_id,
    NULL AS receipt_note_id, NULL AS receipt_note_item_id, NULL AS expiry_id, NULL AS expiry_date,
    DATE(COALESCE(branch_item_transfer_item.updated_on, branch_item_transfer.received_on,
        branch_item_transfer.opened_on, branch_item_transfer.added_on)) AS movement_date,
    branch_item_transfer_item.receipt_note_item_expiry_date_id AS _receipt_expiry_id
FROM branch_item_transfer_item
INNER JOIN branch_item_transfer ON branch_item_transfer.id = branch_item_transfer_item.branch_item_transfer_id
INNER JOIN item ON item.id = branch_item_transfer_item.item_id AND item.is_deleted = 0
LEFT JOIN branch AS branch_from ON branch_from.id = branch_item_transfer.branch_id_from
LEFT JOIN branch AS branch_to ON branch_to.id = branch_item_transfer.branch_id_to
LEFT JOIN item_conversion ON item_conversion.id = branch_item_transfer_item.unit
    AND item_conversion.item_id = item.id
WHERE branch_item_transfer_item.is_deleted = 0 AND branch_item_transfer.is_deleted = 0
    AND branch_item_transfer_item.received_qty > 0 AND branch_item_transfer_item.batch_no IS NOT NULL
EOT;

        foreach ([1, 2, 3, 4, 5, 6, 7] as $branch_id) {
            $schema = $this->_get_receipt_schema($branch_id);
            $sql .= <<<EOT

UNION ALL
SELECT item.id AS item_id, item.name AS item_name,
    item.brand, item.generic, item.dosage, item.form, item.kind,
    NULL AS branch_item_id,
    receipt.branch_id, branch.name AS branch_name,
    NULL AS branch_id_from, NULL AS branch_name_from, NULL AS branch_id_to, NULL AS branch_name_to,
    receipt_item.unit, item_conversion.unit AS unit_name, item_conversion.conversion_type,
    expiry.batch_no, 'Receipt' AS activity,
    expiry.qty_balance AS quantity, expiry.qty AS beginning_qty, NULL AS current_qty,
    NULL AS transfer_qty, NULL AS transfer_id, NULL AS transfer_item_id,
    receipt.id AS receipt_note_id, receipt_item.id AS receipt_note_item_id,
    expiry.id AS expiry_id, expiry.date AS expiry_date,
    DATE(COALESCE(receipt.receive_date, receipt.added_on)) AS movement_date, NULL AS _receipt_expiry_id
FROM {$schema}.receipt_note_item_expiry_date AS expiry
INNER JOIN {$schema}.receipt_note_item AS receipt_item ON receipt_item.id = expiry.receipt_note_item_id
    AND receipt_item.receipt_note_id = expiry.receipt_note_id
INNER JOIN {$schema}.receipt_note AS receipt ON receipt.id = receipt_item.receipt_note_id
INNER JOIN item ON item.id = receipt_item.item_id AND item.is_deleted = 0
LEFT JOIN branch ON branch.id = receipt.branch_id
LEFT JOIN {$schema}.item_conversion AS item_conversion ON item_conversion.id = receipt_item.unit
    AND item_conversion.item_id = receipt_item.item_id AND item_conversion.is_deleted = 0
WHERE receipt.branch_id = ? AND receipt.status = 'approved' AND receipt.is_deleted = 0
    AND receipt_item.is_deleted = 0 AND expiry.is_deleted = 0
    AND expiry.batch_no IS NOT NULL AND TRIM(expiry.batch_no) <> ''
EOT;
            $binds[] = $branch_id;
        }

        $sql = 'SELECT movements.* FROM ('.$sql.') AS movements WHERE 1 = 1';
        if ($item_id !== null && $item_id !== '') {
            $sql .= "\nAND movements.item_id = ?";
            $binds[] = $item_id;
        }
        if ($batch_no !== null) {
            $sql .= "\nAND movements.batch_no = ?";
            $binds[] = (string) $batch_no;
        }
        foreach (['brand' => $brand, 'generic' => $generic, 'dosage' => $dosage, 'form' => $form] as $field => $value) {
            if ($value !== null && trim($value) !== '') {
                $sql .= "\nAND movements.".$field." = ?";
                $binds[] = $value;
            }
        }
        if ($kind !== null && $kind !== '') {
            $conditions = [];
            $tokens = array_unique(array_map('trim', explode(',', $kind)));
            foreach ($tokens as $token) {
                if ($token !== '') {
                    $conditions[] = 'FIND_IN_SET(?, movements.kind)';
                    $binds[] = $token;
                }
            }
            if ($conditions) {
                $sql .= "\nAND (".implode(' OR ', $conditions).')';
            }
        }

        if ($term !== null && trim($term) !== '') {
            $sql .= "\nAND movements.item_name LIKE ?";
            $binds[] = '%'.trim($term).'%';
        }

        return [$sql, $binds];
    }

    private function _quantity_exceeds($quantity, $available_qty, $factor)
    {
        return round($quantity * $factor, 4, PHP_ROUND_HALF_UP)
            > round($available_qty * $factor, 4, PHP_ROUND_HALF_UP) + 0.0000001;
    }

    private function _get_receipt_schema($branch_id)
    {
        $connection = $this->_get_source_connection($branch_id);
        if (!$connection) {
            return false;
        }
        $schema = $connection->getDatabase();
        return '`'.str_replace('`', '``', $schema).'`';
    }

    private function _get_source_connection($branch_id)
    {
        $groups = [1 => 'mobile', 2 => 'oakridge', 3 => 'mactan', 4 => 'consolacion',
            5 => 'warehouse', 6 => 'pharmacy', 7 => 'mantlewood'];
        if (!isset($groups[$branch_id])) {
            return false;
        }
        return db_connect($groups[$branch_id]);
    }

    private function _get_transfer_rows($sql, $binds)
    {
        try {
            $query = $this->db->query($sql, $binds);
            return $query ? $query->getResultArray() : false;
        } catch (\Throwable $error) {
            log_message('error', 'Transfer query failed: {exception}', ['exception' => get_class($error)]);
            return false;
        }
    }

}

?>
