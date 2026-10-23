<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Supplier extends MYT_Model
{
    protected $table = 'supplier';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'email',
        'address',
        'contact_no',
        'contact_person',
        'tin',
        'website',
        'type',
        'category',
        'registration_date',
        'payment_term',
        'credit_limit',
        'payee_name',
        'bank',
        'account_name',
        'account_no',
        'others_category',
        'business_address',
        'business_operation_start_date',
        'total_employees',
        'bank_branch',
        'dti_no',
        'sec_no',
        'bir_cert_no',
        'business_permit_no',
        'valid_until',
        'product_lines_carried',
        'brand',
        'brand_principal',
        'products_exclusively_carried',
        'services_offered',
        'remarks',
        'is_other_supplier',
    ];

    public function get_details($supplier_id = null, $status = null, $is_other_supplier = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier.*,
    CONCAT_WS(' ', adder.first_name, adder.middle_name, adder.last_name) AS added_by_name,
    CONCAT_WS(' ', updater.first_name, updater.middle_name, updater.last_name) AS updated_by_name,
    CONCAT_WS(' ', approver.first_name, approver.middle_name, approver.last_name) AS approved_by_name,
    CONCAT_WS(' ', disapprover.first_name, disapprover.middle_name, disapprover.last_name) AS disapproved_by_name,
    CONCAT_WS(' ', pending_blacklister.first_name, pending_blacklister.middle_name, pending_blacklister.last_name) AS pending_blacklist_by_name,
    CONCAT_WS(' ', pending_reconciler.first_name, pending_reconciler.middle_name, pending_reconciler.last_name) AS pending_reconcile_by_name,
    CONCAT_WS(' ', balacklister.first_name, balacklister.middle_name, balacklister.last_name) AS blacklisted_by_name
FROM supplier
LEFT JOIN user AS adder ON supplier.added_by = adder.id
LEFT JOIN user AS updater ON supplier.updated_by = updater.id
LEFT JOIN user AS approver ON supplier.approved_by = approver.id
LEFT JOIN user AS disapprover ON supplier.disapproved_by = disapprover.id
LEFT JOIN user AS pending_blacklister ON supplier.pending_blacklist_by = pending_blacklister.id
LEFT JOIN user AS pending_reconciler ON supplier.pending_reconcile_by = pending_reconciler.id
LEFT JOIN user AS balacklister ON supplier.blacklisted_by = balacklister.id
WHERE supplier.is_deleted = 0
EOT;

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier.id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND supplier.status = ?
EOT;
            $binds[] = $status;
        }

        if ($is_other_supplier !== null) {
            $sql .= <<<EOT

AND supplier.is_other_supplier = ?
EOT;
            $binds[] = $is_other_supplier;
        }

        if ($term) {
            $sql .= <<<EOT

AND (supplier.name LIKE ? OR supplier.email LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_suppliers($supplier_id = null, $status = null, $is_other_supplier = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT supplier.id,
    supplier.name,
    'supplier' AS type
FROM supplier
WHERE supplier.is_deleted = 0
EOT;

        if ($supplier_id) {
            $sql .= <<<EOT

AND supplier.id = ?
EOT;
            $binds[] = $supplier_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND supplier.status = ?
EOT;
            $binds[] = $status;
        }

        if ($is_other_supplier !== null) {
            $sql .= <<<EOT

AND supplier.is_other_supplier = ?
EOT;
            $binds[] = $is_other_supplier;
        }

        if ($term) {
            $sql .= <<<EOT

AND (supplier.name LIKE ?)
EOT;
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>