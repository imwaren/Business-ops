<?php

namespace App\Models;

use App\Core\MYT_Model;

class Accounts_payable_voucher_entry extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'accounts_payable_voucher_entry';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'accounts_payable_voucher_id',
        'account_type_id',
        'debit',
        'credit',
        'remarks',
        'added_by',
        'added_on',
        'updated_by',
        'updated_on',
        'is_deleted',
    ];

    public function is_legacy(int $id): bool
    {
        $sql = <<<EOT
SELECT EXISTS (SELECT 1 FROM apv_accounting_cutover WHERE id=1 AND ?<=legacy_max_apv_id)
    AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher_entry WHERE accounts_payable_voucher_id=?) AS legacy
EOT;
        return (bool) $this->scoped_query($sql, [$id,$id])->getRowArray()['legacy'];
    }

    public function get_header_for_update(int $id): ?array
    {
        $sql = <<<EOT
SELECT * FROM accounts_payable_voucher WHERE id=? AND is_deleted=0 FOR UPDATE
EOT;
        if ($this->shared_cost_centers !== null) {
            $slots = implode(',', array_fill(0, count($this->shared_cost_centers), '?'));
            return $this->db->query("SELECT * FROM accounts_payable_voucher WHERE id=? AND is_deleted=0 AND cost_center_id IN ($slots) FOR UPDATE",
                array_merge([$id], $this->shared_cost_centers))->getRowArray() ?: null;
        }
        return $this->scoped_query($sql, [$id])->getRowArray() ?: null;
    }

    public function assert_legacy_update(array $header, array $payload): void
    {
        foreach (['amount','withholding_tax','type','type_id','due_date'] as $field) {
            if (!array_key_exists($field,$payload)) { continue; }
            $value = $payload[$field] === '' ? null : $payload[$field];
            $old = $header[$field] ?? null;
            $same = in_array($field,['amount','withholding_tax'],true)
                ? (($value===null && $old===null) || ($value!==null && $old!==null
                    && \App\Libraries\Apv_accounting::units($value)===\App\Libraries\Apv_accounting::units($old)))
                : (string) $value === (string) $old;
            if (!$same) { throw new \InvalidArgumentException('Supply entries before changing legacy APV financial fields.'); }
        }
        if (in_array($payload['status'] ?? '',['approved','open','closed'],true)
            && !in_array($header['status'],['approved','open','closed'],true)) {
            throw new \InvalidArgumentException('Supply entries before first approval of a legacy APV.');
        }
    }

    public function get_for_vouchers(array $ids): array
    {
        if (!$ids) { return []; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = <<<EOT
SELECT e.*, a.name AS account_type_name, a.code AS account_type_code
FROM accounts_payable_voucher_entry e
LEFT JOIN {MASTER_DB}.account_type a ON a.id = e.account_type_id
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
