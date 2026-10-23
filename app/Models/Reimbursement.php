<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Reimbursement extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'reimbursement';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'liquidated_by_id',
        'department_id',
        'cost_center_id',
        'grand_total',
        'due_date',
    ];

    public function get_details($reimbursement_id = null, $department_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT reimbursement.*,
    department.name AS department_name
FROM reimbursement
LEFT JOIN {MASTER_DB}.department AS department ON department.id = reimbursement.department_id
WHERE reimbursement.is_deleted = 0
EOT;

        if ($reimbursement_id) {
            $sql .= <<<EOT

AND reimbursement.id = ?
EOT;
            $binds[] = $reimbursement_id;
        }

        if ($department_id) {
            $sql .= <<<EOT

AND reimbursement.department_id = ?
EOT;
            $binds[] = $department_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND reimbursement.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (reimbursement.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY reimbursement.due_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Get journal entry datas report
     */
    public function get_all_details($reimbursement_id = null, $department_id = null, $status = null, $term = null, $page = null, $data_size = null) {
        // Legacy schemas may omit remarks; expose the same shape in both UNION arms.
        $reimbursement_remarks = $this->db->fieldExists('remarks', 'reimbursement') ? 'reimbursement.remarks' : 'NULL';
        $replenishment_remarks = $this->db->fieldExists('remarks', 'replenishment') ? 'replenishment.remarks' : 'NULL';
        $sql = <<<EOT
SELECT reimbursement.id,
        reimbursement.liquidated_by_id,
        reimbursement.department_id,
        reimbursement.cost_center_id,
        reimbursement.due_date,
        reimbursement.grand_total,
        reimbursement.status,
        reimbursement.disapproved_reason,
        reimbursement.approved_by,
        reimbursement.approved_on,
        reimbursement.disapproved_by,
        reimbursement.disapproved_on,
        reimbursement.added_by,
        reimbursement.added_on,
        reimbursement.updated_by,
        reimbursement.updated_on,
        reimbursement.is_deleted,
        NULL AS petty_cash_id,
        {$reimbursement_remarks} AS remarks,
        'reimbursement' AS form_type,
        department.name AS department_name,
        CASE WHEN liquidator.id IS NULL THEN NULL
            ELSE TRIM(CONCAT_WS(' ', liquidator.first_name, liquidator.last_name)) END AS liquidated_by_name
    FROM reimbursement
    LEFT JOIN {MASTER_DB}.department AS department ON department.id = reimbursement.department_id
    LEFT JOIN {MASTER_DB}.user AS liquidator ON liquidator.id = reimbursement.liquidated_by_id AND liquidator.is_deleted = 0
    WHERE reimbursement.is_deleted = 0
UNION ALL
SELECT replenishment.id,
        replenishment.liquidated_by_id,
        replenishment.department_id,
        replenishment.cost_center_id,
        replenishment.due_date,
        replenishment.grand_total,
        replenishment.status,
        replenishment.disapproved_reason,
        replenishment.approved_by,
        replenishment.approved_on,
        replenishment.disapproved_by,
        replenishment.disapproved_on,
        replenishment.added_by,
        replenishment.added_on,
        replenishment.updated_by,
        replenishment.updated_on,
        replenishment.is_deleted,
        replenishment.petty_cash_id AS petty_cash_id,
        {$replenishment_remarks} AS remarks,
        'replenishment' AS form_type,
        department.name AS department_name,
        CASE WHEN liquidator.id IS NULL THEN NULL
            ELSE TRIM(CONCAT_WS(' ', liquidator.first_name, liquidator.last_name)) END AS liquidated_by_name
    FROM replenishment
    LEFT JOIN {MASTER_DB}.department AS department ON department.id = replenishment.department_id
    LEFT JOIN {MASTER_DB}.user AS liquidator ON liquidator.id = replenishment.liquidated_by_id AND liquidator.is_deleted = 0
    WHERE replenishment.is_deleted = 0
EOT;

        $sql = "SELECT * FROM ({$sql}) AS combined WHERE 1=1";
        
        $binds = [];

        if ($reimbursement_id) {
            $sql .= <<<EOT

AND combined.id = ?
EOT;
            $binds[] = $reimbursement_id;
        }

        if ($department_id) {
            $sql .= <<<EOT

AND combined.department_id = ?
EOT;
            $binds[] = $department_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND combined.status = ?
EOT;
            $binds[] = $status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (combined.id LIKE ? OR combined.liquidated_by_name LIKE ? OR combined.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY combined.due_date DESC, combined.id DESC, combined.form_type ASC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>