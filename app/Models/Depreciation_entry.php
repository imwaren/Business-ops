<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Depreciation_entry extends MYT_Model
{
    protected $table = 'depreciation_entry';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'cost_center_id',
        'accounts_payable_voucher_id',
        'date',
        'remarks',
        'total_credit',
        'total_debit',
    ];

    public function get_details($depreciation_entry_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT depreciation_entry.*,
    accounts_payable_voucher.amount AS accounts_payable_voucher_amount,
    accounts_payable_voucher.status AS accounts_payable_voucher_status,
    accounts_payable_voucher.supplier_id AS accounts_payable_voucher_supplier_id,
    CASE
        WHEN LOWER(TRIM(accounts_payable_voucher.supplier_type)) = 'supplier' THEN supplier.name
        WHEN LOWER(TRIM(accounts_payable_voucher.supplier_type)) = 'payor' THEN TRIM(CONCAT_WS(' ', payor.first_name, payor.middle_name, payor.last_name))
        ELSE NULL
    END AS supplier_name,
    accounts_payable_voucher.type AS accounts_payable_voucher_type,
    accounts_payable_voucher.type_id AS accounts_payable_voucher_type_id
FROM depreciation_entry
LEFT JOIN accounts_payable_voucher ON accounts_payable_voucher.id = depreciation_entry.accounts_payable_voucher_id
LEFT JOIN {MASTER_DB}.supplier AS supplier
    ON supplier.id = accounts_payable_voucher.supplier_id
    AND accounts_payable_voucher.supplier_type = 'supplier'
    AND supplier.is_deleted = 0
LEFT JOIN {MASTER_DB}.user AS payor
    ON payor.id = accounts_payable_voucher.supplier_id
    AND LOWER(TRIM(accounts_payable_voucher.supplier_type)) = 'payor'
WHERE depreciation_entry.is_deleted = 0
EOT;

        if ($depreciation_entry_id) {
            $sql .= <<<EOT

AND depreciation_entry.id = ?
EOT;
            $binds[] = $depreciation_entry_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND depreciation_entry.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (depreciation_entry.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
