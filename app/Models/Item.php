<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Item extends MYT_Model
{
    protected $table = 'item';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'brand',
        'form',
        'vat_type',
        'account_type_id',
        'vat_account_type_id',
        'is_canvassing',
        'cost',
        'weighted_cost',
        'remarks',
        'description',
        'subcategory',
        'with_conversion',
        'conversion',
        'type',
        'min',
        'max',
        'kind',
        'selling_price',
        'critical_level',
        'generic',
        'dosage',
        'preparation',
        'category',
        'philhealth_medicine_type',
        'philhealth_medicine_price',
        'vaccine_type',
        'is_antibiotic',
        'is_batch',
    ];

    /**
     * Independent dropdown values from the master item catalog.
     */
    public function get_item_filters()
    {
        $filters = [];
        try {
            foreach (['brand', 'generic', 'dosage', 'form'] as $field) {
                // Identifiers come only from this fixed list; no payload identifiers are used.
                $sql = "SELECT DISTINCT item.$field FROM item
                    WHERE item.is_deleted = 0 AND item.$field IS NOT NULL AND TRIM(item.$field) <> ''
                    ORDER BY item.$field ASC";
                $query = $this->db->query($sql);
                if ($query === false) {
                    return false;
                }
                $filters[$field] = array_values(array_filter(
                    array_column($query->getResultArray(), $field),
                    static fn($value) => trim($value) !== ''
                ));
            }
        } catch (\Throwable $error) {
            log_message('error', 'Item filter query failed: {exception}', ['exception' => get_class($error)]);
            return false;
        }

        return $filters;
    }

    public function get_details($item_id = null, $type = null, $kind = null, $status = null, $category = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT item.*,
    account_type.name AS account_type_name,
    vat_account_type.name AS vat_account_type_name
FROM item
LEFT JOIN {MASTER_DB}.account_type AS account_type ON account_type.id = item.account_type_id
LEFT JOIN {MASTER_DB}.account_type AS vat_account_type ON vat_account_type.id = item.vat_account_type_id
WHERE item.is_deleted = 0
EOT;

        if ($item_id) {
            $sql .= <<<EOT

AND item.id = ?
EOT;
            $binds[] = $item_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND item.type = ?
EOT;
            $binds[] = $type;
        }

        if ($kind) {
            $kind_ids = explode(',', $kind);
        
            $conditions = [];
            foreach ($kind_ids as $id) {
                $conditions[] = 'FIND_IN_SET(?, item.kind)';
                $binds[] = trim($id);
            }
        
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")\n";
        }

        if ($status) {
            $sql .= <<<EOT

AND item.status = ?
EOT;
            $binds[] = $status;
        }

        if ($category) {
            $sql .= <<<EOT

AND item.category = ?
EOT;
            $binds[] = $category;
        }

        if ($term) {
            $sql .= <<<EOT

AND (item.name LIKE ? OR item.description LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_list($item_id = null, $type = null, $kind = null, $branch_id = null, $is_canvassing = null, $status = null, $category = null, $conversion_type = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT branch_item.id AS branch_item_id,
    branch_item.branch_id,
    branch_item.item_id,
    branch_item.beggining_qty,
    branch_item.current_qty,
    branch_item.unit,
    item_conversion.unit AS unit_name,
    item.name AS item_name,
    item.brand AS item_brand,
    item.form,
    item.kind,
    item.cost,
    item.weighted_cost,
    IFNULL(item_cost.current_amount, IFNULL(item.weighted_cost, 0)) AS current_cost,
    item.dosage,
    item.preparation,
    item.generic,
    item.brand,
    item.is_canvassing,
    item.account_type_id,
    item.vat_account_type_id,
    item.vat_type,
    item.philhealth_medicine_type,
    item.philhealth_medicine_price,
    item.is_batch
FROM branch_item
LEFT JOIN item_conversion ON item_conversion.id = branch_item.unit AND item_conversion.is_deleted = 0
LEFT JOIN item ON item.id = branch_item.item_id AND item.is_deleted = 0
LEFT JOIN item_cost ON item_cost.id = (
    SELECT ic.id
    FROM item_cost ic
    WHERE ic.item_id = item.id
      AND ic.branch_id = branch_item.branch_id
      AND ic.is_deleted = 0
    ORDER BY ic.id DESC
    LIMIT 1
)
WHERE branch_item.is_deleted = 0
EOT;

        if ($item_id) {
            $sql .= <<<EOT

AND item.id = ?
EOT;
            $binds[] = $item_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND item.type = ?
EOT;
            $binds[] = $type;
        }

        if ($kind) {
            $kind_ids = explode(',', $kind);
        
            $conditions = [];
            foreach ($kind_ids as $id) {
                $conditions[] = 'FIND_IN_SET(?, item.kind)';
                $binds[] = trim($id);
            }
        
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")\n";
        }

        if  ($is_canvassing != null) {
            $sql .= <<<EOT

AND item.is_canvassing = ?
EOT;
            $binds[] = $is_canvassing;
        }

        if ($branch_id !== null && $branch_id !== '') {
            $sql .= <<<EOT

AND branch_item.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND item.status = ?
EOT;
            $binds[] = $status;
        }

        if ($category) {
            $sql .= <<<EOT

AND item.category = ?
EOT;
            $binds[] = $category;
        }

        if ($conversion_type) {
            $sql .= <<<EOT

AND item_conversion.conversion_type = ?
EOT;
            $binds[] = $conversion_type;
        }

        if ($term) {
            $sql .= <<<EOT

AND item.name LIKE ? 
EOT;
            $binds[] = "%$term%";
        }

        //group by item_id to avoid duplicate items in different branches
        $sql .= " GROUP BY branch_item.id";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_history($item_id = null, $type = null, $kind = null, $branch_id = null, $status = null, $conversion_type = null, $activity = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT item_history.id AS item_history_id,
    item_history.branch_item_id,
    item_history.item_id,
    item_history.branch_id,
    item_history.department_id,
    department.name AS department_name,
    item_history.beggining_qty,
    item_history.current_qty,
    item_history.activity,
    item_history.activity_id AS doc_id,
    item_history.quantity,
    item_history.adjustment_type,
    item_conversion.unit AS unit_name,
    item.name AS item_name,
    item.brand AS item_brand,
    item.cost,
    item.weighted_cost,
    item.vat_type,
    item_history.added_by,
    item_history.added_on
FROM item_history
LEFT JOIN item_conversion ON item_conversion.id = item_history.unit AND item_conversion.is_deleted = 0
LEFT JOIN item ON item.id = item_history.item_id AND item.is_deleted = 0
LEFT JOIN {MASTER_DB}.department AS department ON department.id = item_history.department_id
WHERE item_history.is_deleted = 0
EOT;

        if ($item_id) {
            $sql .= <<<EOT

AND item.id = ?
EOT;
            $binds[] = $item_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND item.type = ?
EOT;
            $binds[] = $type;
        }

        if ($kind) {
            $kind_ids = explode(',', $kind);
        
            $conditions = [];
            foreach ($kind_ids as $id) {
                $conditions[] = 'FIND_IN_SET(?, item.kind)';
                $binds[] = trim($id);
            }
        
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")\n";
        }

        if ($branch_id) {
            $sql .= <<<EOT

AND item_history.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND item.status = ?
EOT;
            $binds[] = $status;
        }

        if ($conversion_type) {
            $sql .= <<<EOT

AND item_conversion.conversion_type = ?
EOT;
            $binds[] = $conversion_type;
        }

        if ($activity) {
            $sql .= <<<EOT

AND item_history.activity = ?
EOT;
            $binds[] = $activity;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND DATE(item_history.added_on) BETWEEN ? AND ?
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($term) {
            $sql .= <<<EOT

AND item.name LIKE ? 
EOT;
            $binds[] = "%$term%";
        }

        //order by added on descending
        $sql .= " ORDER BY item_history.added_on DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_inventory_report($branch_id = null, $type = null, $kind = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT
    branch_item.id AS branch_item_id,
    branch_item.branch_id,
    branch_item.item_id,
    item.name AS item_name,
    item.brand AS item_brand,
    item.type,
    item.kind,
    branch_item.current_qty,
    item_conversion.unit AS unit_name,
    IFNULL(item_cost.current_amount, IFNULL(item.weighted_cost, 0)) AS weighted_cost,
    (branch_item.current_qty * IFNULL(item_cost.current_amount, IFNULL(item.weighted_cost, 0))) AS ending_cost
FROM branch_item
INNER JOIN item ON item.id = branch_item.item_id AND item.is_deleted = 0
INNER JOIN item_conversion ON item_conversion.id = branch_item.unit
    AND item_conversion.is_deleted = 0
    AND item_conversion.conversion_type = 'purchase'
LEFT JOIN item_cost ON item_cost.id = (
    SELECT ic.id
    FROM item_cost ic
    WHERE ic.item_id = item.id
      AND ic.branch_id = branch_item.branch_id
      AND ic.is_deleted = 0
    ORDER BY ic.id DESC
    LIMIT 1
)
WHERE branch_item.is_deleted = 0
EOT;

        if ($branch_id) {
            $sql .= <<<EOT

AND branch_item.branch_id = ?
EOT;
            $binds[] = $branch_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND item.type = ?
EOT;
            $binds[] = $type;
        }

        if ($kind) {
            $kind_ids = explode(',', $kind);
            $conditions = [];
            foreach ($kind_ids as $id) {
                $conditions[] = 'FIND_IN_SET(?, item.kind)';
                $binds[] = trim($id);
            }
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")\n";
        }

        if ($term) {
            $sql .= <<<EOT

AND (item.name LIKE ? OR item.brand LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY item.name ASC, branch_item.branch_id ASC";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>
