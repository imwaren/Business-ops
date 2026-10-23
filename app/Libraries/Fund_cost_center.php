<?php

namespace App\Libraries;

/** Storage routing for fund workflows. Shared IDs are business IDs, never DB IDs. */
class Fund_cost_center
{
    public const CLINICS = [1 => 'mobile', 2 => 'oakridge', 3 => 'mactan', 4 => 'consolacion', 7 => 'mantlewood'];
    public const ROOTS = ['petty_cash', 'cash_advance', 'liquidation', 'reimbursement',
        'replenishment', 'change_fund', 'direct_payment', 'accounts_payable_voucher'];
    public const PROCUREMENT_OWNERS = ['purchase_request'=>'branch_id', 'aoq'=>'branch_id',
        'purchase_order'=>'branch_id', 'receipt_note'=>'branch_id', 'purchase_return'=>'branch_id'];

    public static function owned_roots(): array
    {
        return array_merge(self::ROOTS, array_keys(self::PROCUREMENT_OWNERS));
    }

    public static function owner_column(string $table): string
    {
        return self::PROCUREMENT_OWNERS[$table] ?? 'cost_center_id';
    }

    public const CHILDREN = [
        'purchase_request_item' => ['purchase_request', 'purchase_request_id'],
        'purchase_request_attachment' => ['purchase_request', 'purchase_request_id'],
        'aoq_detail' => ['aoq', 'aoq_id'],
        'purchase_order_item' => ['purchase_order', 'purchase_order_id'],
        'purchase_order_attachment' => ['purchase_order', 'purchase_order_id'],
        'receipt_note_item' => ['receipt_note', 'receipt_note_id'],
        'receipt_note_additional_item' => ['receipt_note', 'receipt_note_id'],
        'receipt_note_item_expiry_date' => ['receipt_note', 'receipt_note_id'],
        'receipt_note_attachment' => ['receipt_note', 'receipt_note_id'],
        'purchase_return_item' => ['purchase_return', 'purchase_return_id'],
        'liquidation_detail' => ['liquidation', 'liquidation_id'],
        'liquidation_attachment' => ['liquidation', 'liquidation_id'],
        'deposit_detail' => ['cash_advance', 'cash_advance_id'],
        'reimbursement_item' => ['reimbursement', 'reimbursement_id'],
        'reimbursement_attachment' => ['reimbursement', 'reimbursement_id'],
        'replenishment_item' => ['replenishment', 'replenishment_id'],
        'replenishment_attachment' => ['replenishment', 'replenishment_id'],
        'direct_payment_attachment' => ['direct_payment', 'direct_payment_id'],
        'accounts_payable_voucher_attachment' => ['accounts_payable_voucher', 'accounts_payable_voucher_id'],
        'accounts_payable_voucher_entry' => ['accounts_payable_voucher', 'accounts_payable_voucher_id'],
        'accounts_payable_voucher_amortization_entry' => ['accounts_payable_voucher', 'accounts_payable_voucher_id'],
        'accounts_payable_voucher_payment_detail' => ['accounts_payable_voucher', 'accounts_payable_voucher_id'],
    ];

    private $db;
    private ?array $branches = null;

    public function __construct($db = null)
    {
        $this->db = $db ?? db_connect('master');
    }

    public function branches(): array
    {
        if ($this->branches === null) {
            $query = $this->db->query('SELECT id, name, type FROM branch WHERE is_deleted = ?', [0]);
            if (!$query) { throw new \RuntimeException('Unable to resolve cost centers.'); }
            $this->branches = array_column($query->getResultArray(), null, 'id');
        }
        return $this->branches;
    }

    public function group(int $id): ?string
    {
        $branch = $this->branches()[$id] ?? null;
        if (!$branch) { return null; }
        return $branch['type'] === 'shared' ? 'master' : (self::CLINICS[$id] ?? null);
    }

    public function procurement_group(int $id): ?string
    {
        if (!isset($this->branches()[$id])) { return null; }
        return $this->group($id) ?? ([5=>'warehouse', 6=>'pharmacy'][$id] ?? null);
    }

    public function groups(): array
    {
        $groups = [];
        foreach ($this->branches() as $id => $branch) {
            $group = $this->group((int) $id);
            if ($group !== null) { $groups[(int) $id] = $group; }
        }
        return $groups;
    }

    /** Query each physical database once, retaining all of its business IDs. */
    public static function storage_groups(array $map): array
    {
        $groups = [];
        foreach ($map as $id => $group) { $groups[$group][] = (int) $id; }
        return $groups;
    }
}
