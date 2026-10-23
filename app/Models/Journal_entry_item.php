<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Journal_entry_item extends MYT_Model
{
    use \App\Core\Journal_center_scope;

    protected $table = 'journal_entry_item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'journal_entry_id',
        'capital_expenditure_id',
        'account_type_id',
        'cost_center_id',
        'debit',
        'credit',
        'remarks',
        'type',
        'direct_payment_id',
    ];

    public function get_details($journal_entry_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT journal_entry_item.*,
    capital_expenditure.name AS capital_expenditure_name,
    account_type.name AS account_type_name
FROM journal_entry_item
LEFT JOIN capital_expenditure ON capital_expenditure.id = journal_entry_item.capital_expenditure_id AND capital_expenditure.is_deleted = 0
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = journal_entry_item.account_type_id AND account_type.is_deleted = 0
WHERE journal_entry_item.is_deleted = 0
EOT;

        if ($journal_entry_id) {
            $sql .= <<<EOT

AND journal_entry_item.journal_entry_id = ?
EOT;
            $binds[] = $journal_entry_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * True when a cron-generated journal already exists for this direct payment date.
     */
    public function has_posted_period($direct_payment_id, $date)
    {
        $sql = <<<EOT
SELECT journal_entry_item.id
FROM journal_entry_item
INNER JOIN journal_entry ON journal_entry.id = journal_entry_item.journal_entry_id
    AND journal_entry.is_deleted = 0
WHERE journal_entry_item.is_deleted = 0
AND journal_entry_item.direct_payment_id = ?
AND journal_entry.date = ?
LIMIT 1
EOT;
        $query = $this->db->query($sql, [$direct_payment_id, $date]);
        return $query && $query->getRowArray() ? true : false;
    }
}

?>