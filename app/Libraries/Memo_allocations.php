<?php

namespace App\Libraries;

use App\Models\Memo_entries;

class Memo_allocations
{
    public static function cents($value): int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/^([0-9]{1,11})(?:\.([0-9]{1,2}))?$/D', (string) $value, $match)) {
            throw new \InvalidArgumentException('Memo amounts must be nonnegative decimals with at most 11 integer digits and two decimal places.');
        }
        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    public static function total(array $rows): string
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += self::cents($row['amount']);
        }
        return intdiv($total, 100) . '.' . str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function approval_rows(Memo_entries $model, $memo): array
    {
        $rows = $model->active_rows($memo->id, true);
        if (!$rows) {
            return [];
        }
        usort($rows, static fn(array $a, array $b): int => (int) $a['billing_entry_id'] <=> (int) $b['billing_entry_id']);
        return self::validate($model, $rows, $memo->amount, $memo->billing_id, $memo->branch_id);
    }

    public static function validate(Memo_entries $model, $rows, $amount, $billing_id, $branch_id): array
    {
        if (!is_array($rows) || !$rows) {
            throw new \InvalidArgumentException('memo_entries must be a nonempty array.');
        }
        $expected = self::cents($amount);
        $total = 0;
        $seen = [];
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['billing_entry_id']) || (!is_int($row['billing_entry_id']) && !is_string($row['billing_entry_id'])) || !preg_match('/^[1-9][0-9]*$/D', (string) $row['billing_entry_id']) || !array_key_exists('amount', $row)) {
                throw new \InvalidArgumentException('Each memo entry requires a valid billing_entry_id and amount.');
            }
            $id = (int) $row['billing_entry_id'];
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException('Duplicate billing_entry_id in memo_entries.');
            }
            $seen[$id] = true;
            foreach (['billing_id' => $billing_id, 'branch_id' => $branch_id] as $field => $owner) {
                if (array_key_exists($field, $row) && ((!is_int($row[$field]) && !is_string($row[$field])) || (string) $row[$field] !== (string) $owner)) {
                    throw new \InvalidArgumentException('Memo entry ' . $field . ' does not match its memo.');
                }
            }
            if (!$model->validate_reference($id, $billing_id, $branch_id)) {
                throw new \InvalidArgumentException('Billing entry does not belong to the memo billing and branch, or is deleted.');
            }
            $cents = self::cents($row['amount']);
            if ($cents > $expected - $total) {
                throw new \InvalidArgumentException('memo_entries total must equal memo amount.');
            }
            $total += $cents;
            $normalized[] = ['billing_entry_id' => $id, 'billing_id' => $billing_id, 'branch_id' => $branch_id, 'amount' => intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT)];
        }
        if ($total !== $expected) {
            throw new \InvalidArgumentException('memo_entries total must equal memo amount.');
        }
        return $normalized;
    }
}
