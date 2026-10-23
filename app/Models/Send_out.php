<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Send_out extends MYT_Model
{
    protected $table = 'send_out';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'branch_id',
        'facility_id',
        'supplier_id',
        'due_date',
        'invoice_no',
        'invoice_date',
        'total_sales',
        'total_expense',
    ];

    public function get_details($send_out_id = null, $supplier_id = null, $status = null, $page = null, $data_size = null)
    {
        $binds = [];
        $sql = <<<EOT
SELECT send_out.*,
    supplier.name AS supplier_name,
    branch.name AS branch_name,
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM accounts_payable_voucher AS apv
            WHERE apv.type = 'send_out'
              AND apv.type_id = send_out.id
              AND apv.is_deleted = 0
        ) THEN 'apv'
        ELSE 'for_apv'
    END AS status,
    (
        SELECT apv.id
        FROM accounts_payable_voucher AS apv
        WHERE apv.type = 'send_out'
          AND apv.type_id = send_out.id
          AND apv.is_deleted = 0
        LIMIT 1
    ) AS accounts_payable_voucher_id
FROM send_out
LEFT JOIN {MASTER_DB}.supplier AS supplier ON supplier.id = send_out.supplier_id AND supplier.is_deleted = 0
LEFT JOIN {MASTER_DB}.branch AS branch ON branch.id = send_out.branch_id
WHERE send_out.is_deleted = 0
EOT;

        if ($send_out_id) {
            $sql .= <<<EOT

AND send_out.id = ?
EOT;
            $binds[] = $send_out_id;
        }

        if ($supplier_id) {
            $sql .= <<<EOT

AND send_out.supplier_id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($status === 'for_apv') {
            $sql .= <<<EOT

AND NOT EXISTS (
    SELECT 1
    FROM accounts_payable_voucher AS apv
    WHERE apv.type = 'send_out'
      AND apv.type_id = send_out.id
      AND apv.is_deleted = 0
)
EOT;
        } elseif ($status === 'apv') {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1
    FROM accounts_payable_voucher AS apv
    WHERE apv.type = 'send_out'
      AND apv.type_id = send_out.id
      AND apv.is_deleted = 0
)
EOT;
        }

        $sql .= "\nORDER BY send_out.due_date DESC, send_out.id DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Run get_details() on each branch DB, merge in PHP, then paginate.
     *
     * @param array<int,string> $branch_db_map branch_id => database group name
     * @return array{size:int,data:array}
     */
    public function get_details_all_branches(
        array $branch_db_map,
        $branch_id = null,
        $send_out_id = null,
        $supplier_id = null,
        $status = null,
        $page = null,
        $data_size = null
    ) {
        $merged = [];

        if ($branch_id) {
            $branch_db_map = array_intersect_key($branch_db_map, [(int) $branch_id => true]);
        }

        foreach ($branch_db_map as $map_branch_id => $groupName) {
            $db = \Config\Database::connect($groupName);
            $branchModel = new self($db);
            $rows = $branchModel->get_details($send_out_id, $supplier_id, $status, null, null) ?: [];
            $merged = array_merge($merged, $rows);
        }

        usort($merged, static function ($a, $b) {
            $cmp = strcmp((string) ($b['due_date'] ?? ''), (string) ($a['due_date'] ?? ''));
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
}
