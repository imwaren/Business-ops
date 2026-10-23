<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Accounts_payable_voucher_payment_detail extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'accounts_payable_voucher_payment_detail';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'accounts_payable_voucher_id',
        'accounts_payable_voucher_payment_id',
        'supplier_id',
        'supplier_type',
        'date',
        'bank',
        'check_name',
        'check_no',
        'transaction_no',
        'amount',
        'balance',
        'remarks',
    ];

    public function get_details($accounts_payable_voucher_payment_detail_id = null, $accounts_payable_voucher_id = null, $accounts_payable_voucher_payment_id = null, $type = null, $supplier_id = null, $supplier_type = null, $date_from = null, $date_to = null, $mop = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT accounts_payable_voucher_payment_detail.*,
    accounts_payable_voucher.type AS accounts_payable_voucher_type,
    accounts_payable_voucher.amount AS accounts_payable_voucher_amount,
    accounts_payable_voucher.status AS status
FROM accounts_payable_voucher_payment_detail
LEFT JOIN accounts_payable_voucher ON accounts_payable_voucher.id = accounts_payable_voucher_payment_detail.accounts_payable_voucher_id AND accounts_payable_voucher.is_deleted = 0
WHERE accounts_payable_voucher_payment_detail.is_deleted = 0
EOT;

        if ($accounts_payable_voucher_payment_detail_id) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment_detail.id = ?
EOT;
            $binds[] = $accounts_payable_voucher_payment_detail_id;
        }

        if ($accounts_payable_voucher_id) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment_detail.accounts_payable_voucher_id = ?
EOT;
            $binds[] = $accounts_payable_voucher_id;
        }

        if ($accounts_payable_voucher_payment_id) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment_detail.accounts_payable_voucher_payment_id = ?
EOT;
            $binds[] = $accounts_payable_voucher_payment_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND accounts_payable_voucher.type = ?
EOT;
            $binds[] = $type;
        }

        if ($supplier_id && $supplier_type) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment_detail.supplier_id = ? 
AND accounts_payable_voucher_payment_detail.supplier_type = ?
EOT;
            $binds[] = $supplier_id;
            $binds[] = $supplier_type;
        }

        if ($date_from) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment_detail.date >= ?
EOT;
            $binds[] = $date_from;
        }

        if ($date_to) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment_detail.date <= ?
EOT;
            $binds[] = $date_to;
        }

        if ($mop) {
            $sql .= <<<EOT

AND accounts_payable_voucher_payment.mop = ?
EOT;
            $binds[] = $mop;
        }

        if ($term) {
            $sql .= <<<EOT

AND accounts_payable_voucher.amount LIKE ?
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        if ($this->shared_cost_centers !== null) {
            $sql = str_replace('SELECT accounts_payable_voucher_payment_detail.*,',
                'SELECT accounts_payable_voucher_payment_detail.*, accounts_payable_voucher.cost_center_id,', $sql);
        }
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Same filters as {@see get_details()}, run on each branch DB, merged in PHP with cost_center_id on each row.
     * Pagination applies to the merged list (not per branch). Numeric ids may repeat across branches; use cost_center_id + id together.
     *
     * @param array<int,string> $branch_db_map cost_center_id / branch_id => database group name
     * @return array{size:int,data:array}
     */
    public function get_all_details_all_branches(
        array $branch_db_map,
        $accounts_payable_voucher_payment_id = null, 
        $type = null, 
        $supplier_id = null, 
        $supplier_type = null, 
        $date_from = null, 
        $date_to = null, 
        $mop = null, 
        $term = null, 
        $page = null, 
        $data_size = null
    ) {
        $merged = [];
        $shared_names = in_array('master', $branch_db_map, true)
            ? (new \App\Libraries\Fund_cost_center())->branches() : [];

        foreach (\App\Libraries\Fund_cost_center::storage_groups($branch_db_map) as $groupName => $center_ids) {
            $cost_center_id = $center_ids[0];
            $db = \Config\Database::connect($groupName);
            $branchModel = new self($db);
            if ($groupName === 'master') { $branchModel->with_shared_cost_centers($center_ids); }
            $rows = $branchModel->get_details(
                null, null, $accounts_payable_voucher_payment_id,
                $type,
                $supplier_id,
                $supplier_type,
                $date_from,
                $date_to,
                $mop,
                $term,
                null,
                null
            ) ?: [];

            foreach ($rows as $row) {
                $row['cost_center_id'] = $groupName === 'master' ? (int) $row['cost_center_id'] : (int) $cost_center_id;
                $row['cost_center_name'] = $groupName === 'master'
                    ? ($shared_names[$row['cost_center_id']]['name'] ?? 'Shared')
                    : ucwords(str_replace('_', ' ', $groupName));
                $merged[] = $row;
            }
        }

        usort($merged, static function ($a, $b) {
            $aKey = $a['added_on'] ?? '';
            $bKey = $b['added_on'] ?? '';
            $cmp = strcmp((string) $bKey, (string) $aKey);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        $size = count($merged);

        if ($page && $data_size) {
            $page = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $merged = array_slice($merged, $offset, (int) $data_size);
        }

        return [
            'size' => $size,
            'data' => $merged,
        ];
    }

// Note: APV voucher balance triggers should be executed directly in each branch database.
// See accounts_payable_voucher_payment_detail_balance_trigger.sql at the project root.
}

?>