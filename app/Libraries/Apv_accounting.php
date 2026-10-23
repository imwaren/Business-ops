<?php

namespace App\Libraries;

use InvalidArgumentException;

/** Validate monetary values as integer ten-thousandths, never floating totals. */
class Apv_accounting
{
    public static function units($value): int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D', (string) $value)) {
            throw new InvalidArgumentException('Amounts must be non-negative decimals with at most four decimal places.');
        }
        $parts = explode('.', (string) $value, 2);
        return (int) $parts[0] * 10000 + (int) str_pad($parts[1] ?? '', 4, '0');
    }

    public static function decimal(int $units): string
    {
        return intdiv($units, 10000) . '.' . str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }

    public static function entries($entries, $amount, callable $account): array
    {
        if (!is_array($entries) || !$entries) {
            throw new InvalidArgumentException('entries must be a non-empty array.');
        }
        $debit = $credit = $payable = 0;
        $result = [];
        foreach ($entries as $line) {
            if (!is_array($line) || !ctype_digit((string) ($line['account_type_id'] ?? ''))) {
                throw new InvalidArgumentException('Each entry requires an active account_type_id.');
            }
            $id = (int) $line['account_type_id'];
            $row = $account($id);
            if (!$row) {
                throw new InvalidArgumentException('Each entry requires an active account_type_id.');
            }
            $dr = self::units($line['debit'] ?? 0);
            $cr = self::units($line['credit'] ?? 0);
            if (($dr > 0) === ($cr > 0)) {
                throw new InvalidArgumentException('Each entry must have exactly one positive debit or credit.');
            }
            $remarks = $line['remarks'] ?? null;
            if ($remarks !== null && (!is_string($remarks) || mb_strlen($remarks) > 250)) {
                throw new InvalidArgumentException('Entry remarks must be text of at most 250 characters.');
            }
            $debit += $dr;
            $credit += $cr;
            if (($row['code'] ?? null) === '201-111') {
                $payable += $cr - $dr;
            }
            $result[] = ['account_type_id' => $id, 'debit' => self::decimal($dr),
                'credit' => self::decimal($cr), 'remarks' => $remarks];
        }
        $net = self::units($amount);
        if ($net <= 0 || $debit !== $credit || $payable !== $net) {
            throw new InvalidArgumentException('Entries must balance and net credit to Accounts Payable (201-111) must equal amount.');
        }
        return $result;
    }

    public static function allocations($lines, $amount, callable $account): array
    {
        if (!is_array($lines) || !$lines) {
            throw new InvalidArgumentException('Prepaid direct-payment APVs require amortization_entries.');
        }
        $sum = 0;
        $result = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('Invalid amortization allocation.');
            }
            foreach (['expense_account_type_id', 'prepaid_account_type_id'] as $field) {
                if (!ctype_digit((string) ($line[$field] ?? '')) || !$account((int) $line[$field])) {
                    throw new InvalidArgumentException('Amortization accounts must be active account IDs.');
                }
            }
            if ((int) $line['expense_account_type_id'] === (int) $line['prepaid_account_type_id']) {
                throw new InvalidArgumentException('Expense and prepaid accounts must differ.');
            }
            $units = self::units($line['amount'] ?? 0);
            if ($units <= 0) {
                throw new InvalidArgumentException('Amortization allocations must be positive.');
            }
            $sum += $units;
            $result[] = ['expense_account_type_id' => (int) $line['expense_account_type_id'],
                'prepaid_account_type_id' => (int) $line['prepaid_account_type_id'], 'amount' => self::decimal($units)];
        }
        if ($sum !== self::units($amount)) {
            throw new InvalidArgumentException('Amortization allocations must total the direct-payment amount.');
        }
        return $result;
    }
}
