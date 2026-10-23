<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Account_type extends MYT_Model
{
    protected $table = 'account_type';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'category',
        'nature',
        'financial_statement',
        'status',
        'group_code',
        'code',
        'tax_code',
        'description',
        'is_dashboard',
        'is_expense_claims',
        'is_enable_payments',
        'no',
    ];

    public function get_details($account_type_id = null, $parent_id = null, $is_input_tax = null, $status = null, $is_subtype_cash_in_banks = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT account_type.id, 
    account_type.parent_id, 
    account_type.name, 
    account_type.category, 
    account_type.nature, 
    account_type.financial_statement,
    account_type.status,
    account_type.group_code,
    account_type.code,
    account_type.no,
    account_type.tax_code,
    account_type.description,
    account_type.is_dashboard,
    account_type.is_expense_claims,
    account_type.is_enable_payments
FROM account_type
WHERE account_type.is_deleted = 0
AND account_type.parent_id IS NULL
EOT;

        if ($account_type_id) {
            $sql .= <<<EOT

AND account_type.id = ?
EOT;
            $binds[] = $account_type_id;
        }

        if ($parent_id) {
            $sql .= <<<EOT

AND account_type.parent_id = ?
EOT;
            $binds[] = $parent_id;
        }

        if ($is_input_tax !== null) {
            $sql .= <<<EOT

AND account_type.name LIKE 'Input Tax%' 
EOT;
        }

        if ($status) {
            $sql .= <<<EOT

AND account_type.status = ?
EOT;
            $binds[] = $status;
        }

        if ($is_subtype_cash_in_banks !== null) {
            $sql .= <<<EOT

AND account_type.name LIKE 'Cash in Banks%'
EOT;
        }

        if ($term) {
            $sql .= <<<EOT

AND (account_type.name LIKE ? OR account_type.category LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_child_details($account_type_id = null, $parent_id = null, $is_input_tax = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT account_type.id, 
    account_type.parent_id, 
    account_type.name, 
    account_type.category, 
    account_type.nature, 
    account_type.financial_statement,
    account_type.status,
    account_type.group_code,
    account_type.code,
    account_type.no,
    account_type.tax_code,
    account_type.description,
    account_type.is_dashboard,
    account_type.is_expense_claims,
    account_type.is_enable_payments
FROM account_type
WHERE account_type.is_deleted = 0
AND account_type.parent_id IS NOT NULL
EOT;

        if ($account_type_id) {
            $sql .= <<<EOT

AND account_type.id = ?
EOT;
            $binds[] = $account_type_id;
        }

        if ($parent_id) {
            $sql .= <<<EOT

AND account_type.parent_id = ?
EOT;
            $binds[] = $parent_id;
        }

        if ($is_input_tax !== null) {
            $sql .= <<<EOT

AND account_type.name LIKE 'Input Tax%' 
EOT;
        }

        if ($term) {
            $sql .= <<<EOT

AND (account_type.name LIKE ? OR account_type.category LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Leaf GL accounts plus CIFI children, each with the parent leaf's rollup
     * (no=1 section, no=2 line, no=3 detail) so posted subtype ids resolve to a BS line.
     */
    public function get_balance_sheet_accounts()
    {
        $sql = <<<EOT
SELECT leaf.id,
    leaf.parent_id,
    leaf.code,
    leaf.group_code,
    leaf.name,
    leaf.nature,
    leaf.category,
    leaf.financial_statement,
    detail.name AS detail_name,
    line.name AS line_name,
    section.name AS section_name
FROM account_type AS leaf
LEFT JOIN account_type AS detail
    ON detail.parent_id = leaf.id
    AND detail.no = '3'
    AND detail.is_deleted = 0
LEFT JOIN account_type AS line
    ON line.parent_id = leaf.id
    AND line.no = '2'
    AND line.is_deleted = 0
LEFT JOIN account_type AS section
    ON section.parent_id = leaf.id
    AND section.no = '1'
    AND section.is_deleted = 0
WHERE leaf.is_deleted = 0
AND leaf.parent_id IS NULL

UNION ALL

SELECT child.id,
    child.parent_id,
    child.group_code,
    child.code,
    child.name,
    leaf.nature,
    leaf.category,
    leaf.financial_statement,
    detail.name AS detail_name,
    line.name AS line_name,
    section.name AS section_name
FROM account_type AS child
INNER JOIN account_type AS leaf
    ON leaf.id = child.parent_id
    AND leaf.parent_id IS NULL
    AND leaf.is_deleted = 0
LEFT JOIN account_type AS detail
    ON detail.parent_id = leaf.id
    AND detail.no = '3'
    AND detail.is_deleted = 0
LEFT JOIN account_type AS line
    ON line.parent_id = leaf.id
    AND line.no = '2'
    AND line.is_deleted = 0
LEFT JOIN account_type AS section
    ON section.parent_id = leaf.id
    AND section.no = '1'
    AND section.is_deleted = 0
WHERE child.is_deleted = 0
AND child.parent_id IS NOT NULL
EOT;

        $query = $this->db->query($sql);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * P&L leaf GL accounts plus CIFI children, with parent leaf rollup
     * (no=1 section, no=2 line/report, no=3 detail).
     */
    public function get_profit_and_loss_accounts()
    {
        $sql = <<<EOT
SELECT leaf.id,
    leaf.parent_id,
    leaf.group_code,
    leaf.code,
    leaf.name,
    leaf.nature,
    leaf.category,
    leaf.financial_statement,
    detail.name AS detail_name,
    line.name AS line_name,
    section.name AS section_name,
    report.name AS report_name
FROM account_type AS leaf
LEFT JOIN account_type AS detail
    ON detail.parent_id = leaf.id
    AND detail.no = '3'
    AND detail.is_deleted = 0
LEFT JOIN account_type AS line
    ON line.parent_id = leaf.id
    AND line.no = '2'
    AND line.is_deleted = 0
LEFT JOIN account_type AS section
    ON section.parent_id = leaf.id
    AND section.no = '1'
    AND section.is_deleted = 0
LEFT JOIN account_type AS report
    ON report.parent_id = leaf.id
    AND report.no = '2'
    AND report.is_deleted = 0
WHERE leaf.is_deleted = 0
AND leaf.parent_id IS NULL
AND leaf.financial_statement = 'profit_and_loss'

UNION ALL

SELECT child.id,
    child.parent_id,
    child.group_code,
    child.code,
    child.name,
    leaf.nature,
    leaf.category,
    leaf.financial_statement,
    detail.name AS detail_name,
    line.name AS line_name,
    section.name AS section_name,
    report.name AS report_name
FROM account_type AS child
INNER JOIN account_type AS leaf
    ON leaf.id = child.parent_id
    AND leaf.parent_id IS NULL
    AND leaf.is_deleted = 0
    AND leaf.financial_statement = 'profit_and_loss'
LEFT JOIN account_type AS detail
    ON detail.parent_id = leaf.id
    AND detail.no = '3'
    AND detail.is_deleted = 0
LEFT JOIN account_type AS line
    ON line.parent_id = leaf.id
    AND line.no = '2'
    AND line.is_deleted = 0
LEFT JOIN account_type AS section
    ON section.parent_id = leaf.id
    AND section.no = '1'
    AND section.is_deleted = 0
LEFT JOIN account_type AS report
    ON report.parent_id = leaf.id
    AND report.no = '2'
    AND report.is_deleted = 0
WHERE child.is_deleted = 0
AND child.parent_id IS NOT NULL
EOT;

        $query = $this->db->query($sql);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * All GL leaf accounts plus CIFI children so posted subtype ids roll up
     * to the parent leaf on the trial balance.
     *
     * @return array<int, array>|false
     */
    public function get_trial_balance_accounts()
    {
        $sql = <<<EOT
SELECT leaf.id,
    leaf.parent_id,
    leaf.group_code,
    leaf.code,
    leaf.name,
    leaf.nature,
    leaf.category,
    leaf.financial_statement
FROM account_type AS leaf
WHERE leaf.is_deleted = 0
AND leaf.parent_id IS NULL

UNION ALL

SELECT child.id,
    child.parent_id,
    leaf.group_code,
    leaf.code,
    leaf.name,
    leaf.nature,
    leaf.category,
    leaf.financial_statement
FROM account_type AS child
INNER JOIN account_type AS leaf
    ON leaf.id = child.parent_id
    AND leaf.parent_id IS NULL
    AND leaf.is_deleted = 0
WHERE child.is_deleted = 0
AND child.parent_id IS NOT NULL
EOT;

        $query = $this->db->query($sql);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Leaf GL accounts with Group Code and ST2 name for the per_account_type sheet.
     *
     * @return array<int, array>|false
     */
    public function get_general_ledger_sheet_accounts()
    {
        $sql = <<<EOT
SELECT leaf.id,
    leaf.group_code,
    leaf.code,
    leaf.name,
    leaf.nature,
    leaf.category,
    leaf.financial_statement,
    leaf.group_code AS group_code,
    line.name AS group_name
FROM account_type AS leaf
LEFT JOIN account_type AS line
    ON line.parent_id = leaf.id
    AND line.no = '2'
    AND line.is_deleted = 0
WHERE leaf.is_deleted = 0
AND leaf.parent_id IS NULL
ORDER BY leaf.group_code IS NULL, leaf.group_code, leaf.code
EOT;
        $query = $this->db->query($sql);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Leaf id plus CIFI children so posted subtype rows roll up to the leaf.
     *
     * @return list<int>
     */
    public function get_account_type_family_ids($account_type_id): array
    {
        $account_type_id = (int) $account_type_id;
        if ($account_type_id <= 0) {
            return [];
        }

        $row = $this->select('', ['id' => $account_type_id, 'is_deleted' => 0], 1);
        if (! $row) {
            return [];
        }

        $leaf_id = ($row->parent_id !== null && $row->parent_id !== '')
            ? (int) $row->parent_id
            : (int) $row->id;
        $ids = [$leaf_id];

        $query = $this->db->query(
            'SELECT id FROM account_type WHERE parent_id = ? AND is_deleted = 0',
            [$leaf_id]
        );
        foreach ($query ? $query->getResultArray() : [] as $child) {
            $id = (int) ($child['id'] ?? 0);
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}

?>