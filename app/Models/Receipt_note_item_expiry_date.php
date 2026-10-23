<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Receipt_note_item_expiry_date extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'receipt_note_item_expiry_date';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'batch_no',
        'receipt_note_id',
        'receipt_note_item_id',
        'date',
        'qty',
        'qty_balance',
    ];

    /**
     * Distinct batches whose expiry date is on or before the requested cutoff.
     */
    public function get_item_batches($item_id, $branch_id, $date)
    {
        $binds = [$item_id, $branch_id, $date];
        $sql = <<<EOT
SELECT DISTINCT expiry.batch_no, expiry.date AS expiry_date, expiry.id AS receipt_note_item_expiry_date_id, expiry.qty AS expiry_qty, expiry.qty_balance AS expiry_qty_balance
FROM receipt_note_item_expiry_date AS expiry
INNER JOIN receipt_note_item AS receipt_item ON receipt_item.id = expiry.receipt_note_item_id
    AND receipt_item.receipt_note_id = expiry.receipt_note_id
INNER JOIN receipt_note AS receipt ON receipt.id = receipt_item.receipt_note_id
INNER JOIN item ON item.id = receipt_item.item_id AND item.is_deleted = 0
WHERE receipt_item.item_id = ? AND receipt.branch_id = ? AND expiry.date >= ?
    AND receipt.status = 'approved' AND receipt.is_deleted = 0
    AND receipt_item.is_deleted = 0 AND expiry.is_deleted = 0
    AND expiry.batch_no IS NOT NULL
ORDER BY expiry.batch_no ASC
EOT;

        try {
            $query = $this->scoped_query($sql, $binds);
            if ($query === false) {
                return false;
            }
            $rows = $query->getResultArray();
            foreach ($rows as &$row) {
                $row['batch_no'] = (string) $row['batch_no'];
            }
            unset($row);
            return $rows;
        } catch (\Throwable $error) {
            log_message('error', 'Item batch query failed: {exception}', ['exception' => get_class($error)]);
            return false;
        }
    }

    public function get_details($receipt_note_item_id = null, $receipt_note_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT receipt_note_item_expiry_date.id,
    receipt_note_item_expiry_date.receipt_note_id,
    receipt_note_item_expiry_date.receipt_note_item_id,
    receipt_note_item_expiry_date.batch_no,
    receipt_note_item_expiry_date.date,
    receipt_note_item_expiry_date.qty
FROM receipt_note_item_expiry_date
WHERE receipt_note_item_expiry_date.is_deleted = 0
EOT;

        if ($receipt_note_id) {
            $sql .= <<<EOT

AND receipt_note_item_expiry_date.receipt_note_id = ?
EOT;
            $binds[] = $receipt_note_id;
        }

        if ($receipt_note_item_id) {
            $sql .= <<<EOT

AND receipt_note_item_expiry_date.receipt_note_item_id = ?
EOT;
            $binds[] = $receipt_note_item_id;
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
