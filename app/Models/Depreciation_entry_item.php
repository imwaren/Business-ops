<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Depreciation_entry_item extends MYT_Model
{
    protected $table = 'depreciation_entry_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'depreciation_entry_id',
        'asset_id',
        'cost_center_id',
        'debit',
        'credit',
        'remarks',
        'useful_life_months',
        'start_date',
        'end_date',
        'debit_account_type_id',
        'credit_account_type_id',
        'amount',
        'serial_number',
        'asset_tag_number',
    ];

    public function get_details($depreciation_entry_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT depreciation_entry_item.*,
    asset.name AS asset_name,
    debit_account_type.name AS debit_account_type_name,
    credit_account_type.name AS credit_account_type_name
FROM depreciation_entry_item
LEFT JOIN asset ON asset.id = depreciation_entry_item.asset_id AND asset.is_deleted = 0
LEFT JOIN {MASTER_DB}.account_type AS debit_account_type ON debit_account_type.id = depreciation_entry_item.debit_account_type_id
LEFT JOIN {MASTER_DB}.account_type AS credit_account_type ON credit_account_type.id = depreciation_entry_item.credit_account_type_id
WHERE depreciation_entry_item.is_deleted = 0
EOT;

        if ($depreciation_entry_id) {
            $sql .= <<<EOT

AND depreciation_entry_item.depreciation_entry_id = ?
EOT;
            $binds[] = $depreciation_entry_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_asset_depreciation_report($as_of_date, $depreciation_entry_id = null, $asset_id = null, $status = null, $term = null, $page = null, $data_size = null)
    {
        $binds = [$as_of_date, $as_of_date];
        $sql = <<<EOT
SELECT
    report.*,
    CASE
        WHEN report.monthly_depreciation IS NULL THEN NULL
        ELSE LEAST(
            IFNULL(report.acquisition_cost, 0),
            FLOOR(report.monthly_depreciation * report.months_elapsed)
        )
    END AS accumulated_depreciation,
    CASE
        WHEN report.monthly_depreciation IS NULL THEN report.acquisition_cost
        ELSE report.acquisition_cost - LEAST(
            IFNULL(report.acquisition_cost, 0),
            FLOOR(report.monthly_depreciation * report.months_elapsed)
        )
    END AS net_book_value
FROM (
    SELECT
        depreciation_entry_item.id AS depreciation_entry_item_id,
        depreciation_entry_item.depreciation_entry_id,
        depreciation_entry_item.asset_id,
        COALESCE(asset.name, depreciation_entry_item.remarks) AS asset_name,
        depreciation_entry_item.serial_number,
        depreciation_entry_item.asset_tag_number,
        depreciation_entry_item.start_date,
        depreciation_entry_item.end_date,
        depreciation_entry_item.amount AS acquisition_cost,
        depreciation_entry_item.useful_life_months,
        ? AS as_of_date,
        CASE
            WHEN depreciation_entry_item.start_date IS NULL
                OR depreciation_entry_item.useful_life_months IS NULL
                OR depreciation_entry_item.useful_life_months = 0
            THEN 0
            ELSE GREATEST(
                0,
                LEAST(
                    depreciation_entry_item.useful_life_months,
                    TIMESTAMPDIFF(MONTH, depreciation_entry_item.start_date, ?)
                )
            )
        END AS months_elapsed,
        CASE
            WHEN depreciation_entry_item.amount IS NULL
                OR depreciation_entry_item.useful_life_months IS NULL
                OR depreciation_entry_item.useful_life_months = 0
            THEN NULL
            ELSE depreciation_entry_item.amount / depreciation_entry_item.useful_life_months
        END AS monthly_depreciation,
        depreciation_entry_item.cost_center_id,
        depreciation_entry.accounts_payable_voucher_id,
        supplier.name AS supplier_name,
        depreciation_entry.status,
        depreciation_entry_item.debit_account_type_id,
        debit_account_type.name AS debit_account_type_name,
        depreciation_entry_item.credit_account_type_id,
        credit_account_type.name AS credit_account_type_name
    FROM depreciation_entry_item
    INNER JOIN depreciation_entry ON depreciation_entry.id = depreciation_entry_item.depreciation_entry_id
        AND depreciation_entry.is_deleted = 0
    LEFT JOIN accounts_payable_voucher
        ON accounts_payable_voucher.id = depreciation_entry.accounts_payable_voucher_id
        AND accounts_payable_voucher.is_deleted = 0
    LEFT JOIN receipt_note
        ON receipt_note.id = accounts_payable_voucher.type_id
        AND accounts_payable_voucher.type = 'receipt_note'
        AND receipt_note.is_deleted = 0
    LEFT JOIN {MASTER_DB}.supplier AS supplier
        ON supplier.id = receipt_note.supplier_id
        AND supplier.is_deleted = 0
    LEFT JOIN asset ON asset.id = depreciation_entry_item.asset_id AND asset.is_deleted = 0
    LEFT JOIN {MASTER_DB}.account_type AS debit_account_type ON debit_account_type.id = depreciation_entry_item.debit_account_type_id
    LEFT JOIN {MASTER_DB}.account_type AS credit_account_type ON credit_account_type.id = depreciation_entry_item.credit_account_type_id
    WHERE depreciation_entry_item.is_deleted = 0
EOT;

        if ($depreciation_entry_id) {
            $sql .= <<<EOT

    AND depreciation_entry_item.depreciation_entry_id = ?
EOT;
            $binds[] = $depreciation_entry_id;
        }

        if ($asset_id) {
            $sql .= <<<EOT

    AND depreciation_entry_item.asset_id = ?
EOT;
            $binds[] = $asset_id;
        }

        if ($status) {
            $sql .= <<<EOT

    AND depreciation_entry.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

    AND (
        asset.name LIKE ?
        OR depreciation_entry_item.remarks LIKE ?
        OR depreciation_entry_item.serial_number LIKE ?
        OR depreciation_entry_item.asset_tag_number LIKE ?
    )
EOT;
            $like = "%$term%";
            $binds[] = $like;
            $binds[] = $like;
            $binds[] = $like;
            $binds[] = $like;
        }

        $sql .= <<<EOT

) AS report
ORDER BY report.asset_name ASC, report.depreciation_entry_item_id DESC
EOT;

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
