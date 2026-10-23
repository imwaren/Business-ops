<?php

namespace App\Models;

use App\Core\MYT_Model;

class Accounts_payable_voucher_amortization_entry extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'accounts_payable_voucher_amortization_entry';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'accounts_payable_voucher_id',
        'expense_account_type_id',
        'prepaid_account_type_id',
        'amount',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function get_for_vouchers(array $ids): array
    {
        if (!$ids) { return []; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT e.*
FROM accounts_payable_voucher_amortization_entry e

WHERE e.is_deleted = 0 AND e.accounts_payable_voucher_id IN ($placeholders)
ORDER BY e.id
EOT;
        $query = $this->scoped_query($this->_resolve_cross_db_sql($sql), array_values($ids));
        if (!$query) { throw new \RuntimeException('Unable to retrieve APV entries.'); }
        $result = [];
        foreach ($query->getResultArray() as $row) {
            $result[(int) $row['accounts_payable_voucher_id']][] = $row;
        }
        return $result;
    }

    public function replace_entries(int $id, array $entries, int $requester): bool
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->update(['accounts_payable_voucher_id' => $id, 'is_deleted' => 0],
            ['is_deleted' => 1, 'updated_by' => $requester, 'updated_on' => $now])) { return false; }
        foreach ($entries as $entry) {
            $entry['accounts_payable_voucher_id'] = $id;
            $entry['added_by'] = $requester;
            $entry['added_on'] = $now;
            if (!$this->insert($entry)) { return false; }
        }
        return true;
    }
}
