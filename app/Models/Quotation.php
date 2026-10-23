<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Quotation extends MYT_Model
{
    protected $table = 'quotation';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'version_no',
        'customer_account_id',
        'company_id',
        'request_id',
        'costing_id',
        'quotation_no',
        'grand_total',
        'effectivity_date_from',
        'effectivity_date_to',
        'remarks',
        'all_services_discount_percentage',
        'request_type',
        'priority',
        'due_date',
        'payment_terms',
        'contact_person',
        'contact_no',
        'contact_email',
        'delivery_address',
        'with_masterlist',
        'with_walk_in',
        'mobile_site',
        'head_count_total',
        'type_price_total',
        'cost_total',
        'pro_rate_price_total',
        'discount_total',
        'discount_amount_total',
        'margin_total',
        'margin_amount_total',
        'sales_total',
        'admin_fee_total',
        'direct_cost_total',
        'manpower_cost_total',
        'mobilization_cost_total',
        'total_cost_total',
        'estimated_margin_total',
        'direct_cost_amount_total',
        'manpower_cost_amount_total',
        'mobilization_cost_amount_total',
        'total_cost_amount_total',
        'estimated_margin_amount_total',
        'estimated_revenue_total',
        'admin_fee',
        'pre_negotiated_rate_total',
        'package_name',
        'guaranteed_headcount',
        'email_result',
        'evaluation_data_source',
        'estimated_patient_per_shift',
        'mode_of_releasing',
        'signatory_designation',
        'signatory',
        'note',
        'contract_head_count',
    ];

    /**
     * Build shared FROM/WHERE for get_details() and get_count().
     * LIMIT is excluded so callers can append it.
     *
     * @param bool $for_count When true, select only quotation.id (skip display columns).
     */
    private function _build_quotation_sql($quotation_id, $company_id, $status, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $costing_date_from, $costing_date_to, $term, array &$binds, bool $for_count = false, $request_added_by = null): string
    {
        $binds = [];

        if ($for_count) {
            $sql = <<<EOT
SELECT quotation.id
FROM quotation
LEFT JOIN company ON company.id = quotation.company_id AND company.is_deleted = 0
LEFT JOIN customer_account ON customer_account.id = quotation.customer_account_id AND customer_account.is_deleted = 0
LEFT JOIN request ON request.id = quotation.request_id
LEFT JOIN costing ON costing.id = quotation.costing_id
WHERE quotation.is_deleted = 0
EOT;
        } else {
            $sql = <<<EOT
SELECT quotation.*, 
    DATE(request.added_on) AS request_date,
    DATE(costing.added_on) AS costing_date,
    DATE(request.added_on) AS request_added_on,
    company.name AS company_name,
    company.email AS company_email
FROM quotation
LEFT JOIN company ON company.id = quotation.company_id AND company.is_deleted = 0
LEFT JOIN customer_account ON customer_account.id = quotation.customer_account_id AND customer_account.is_deleted = 0
LEFT JOIN request ON request.id = quotation.request_id
LEFT JOIN costing ON costing.id = quotation.costing_id
WHERE quotation.is_deleted = 0
EOT;
        }

        if (!$this->_append_id_filter($sql, $binds, 'quotation.id', $quotation_id)) {
            return '';
        }

        if ($company_id) {
            $sql .= "\nAND quotation.company_id = ?";
            $binds[] = $company_id;
        }

        if ($status) {
            if ($status == 'pending' && $screen == 'manager' && !empty($role_name)) {
                if ($role_name == 'sales' || $role_name == 'sales_account_executive') {
                    $sql .= <<<EOT

AND quotation.status IN ('pending', 'disapproved')
AND quotation.approved_by_sales IS NULL
AND NOT EXISTS (
    SELECT 1 FROM endorsement
    WHERE endorsement.is_deleted = 0
    AND endorsement.quotation_id = quotation.id
)
EOT;
                } elseif ($role_name == 'sales_manager') {
                    $sql .= <<<EOT

AND quotation.status IN ('pending', 'disapproved')
AND quotation.approved_by_sales IS NOT NULL
AND quotation.approved_by_sales_manager IS NULL
AND NOT EXISTS (
    SELECT 1 FROM endorsement
    WHERE endorsement.is_deleted = 0
    AND endorsement.quotation_id = quotation.id
)
EOT;
                } elseif ($role_name == 'sales_director') {
                    $sql .= <<<EOT

AND quotation.status IN ('pending', 'disapproved')
AND quotation.approved_by_sales_manager IS NOT NULL
AND quotation.approved_by_sales_director IS NULL
AND NOT EXISTS (
    SELECT 1 FROM endorsement
    WHERE endorsement.is_deleted = 0
    AND endorsement.quotation_id = quotation.id
)
EOT;
                // } elseif ($role_name == 'finance_manager') {
                //     $sql .= <<<EOT
                //
                // AND quotation.status IN ('pending', 'disapproved')
                // AND quotation.approved_by_sales_director IS NOT NULL
                // AND quotation.approved_by_sales_director IS NOT NULL
                // AND quotation.approved_by_finance_manager IS NULL
                // AND NOT EXISTS (
                //     SELECT 1 FROM endorsement
                //     WHERE endorsement.is_deleted = 0
                //     AND endorsement.quotation_id = quotation.id
                // )
                // EOT;
                } elseif ($role_name == 'finance_director') {
                    $sql .= <<<EOT

AND quotation.status IN ('pending', 'disapproved')
AND quotation.approved_by_sales_director IS NOT NULL
AND quotation.approved_by_finance_director IS NULL
AND NOT EXISTS (
    SELECT 1 FROM endorsement
    WHERE endorsement.is_deleted = 0
    AND endorsement.quotation_id = quotation.id
)
EOT;
                }
            } else {
                $sql .= "\nAND quotation.status = ?";
                $binds[] = $status;
            }
        }

        if ($screen && $screen == 'endorsement') {
            $sql .= <<<EOT

AND NOT EXISTS (
    SELECT 1 FROM endorsement 
    WHERE endorsement.is_deleted = 0 
    AND endorsement.quotation_id = quotation.id 
    AND endorsement.status NOT IN ('disapproved') 
)
EOT;
        }

        if ($date_from && $date_to) {
            $sql .= "\nAND quotation.added_on >= ? AND quotation.added_on < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($request_date_from && $request_date_to) {
            $sql .= "\nAND request.added_on >= ? AND request.added_on < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $request_date_from;
            $binds[] = $request_date_to;
        }

        if ($costing_date_from && $costing_date_to) {
            $sql .= "\nAND costing.added_on >= ? AND costing.added_on < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $costing_date_from;
            $binds[] = $costing_date_to;
        }

        if ($term) {
            $sql .= "\nAND (quotation.remarks LIKE ? OR customer_account.name LIKE ? OR quotation.id LIKE ? OR company.name LIKE ?)";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        if ($request_added_by !== null) {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1 FROM request AS owned_request
    WHERE owned_request.id = quotation.request_id
    AND owned_request.added_by = ?
)
EOT;
            $binds[] = $request_added_by;
        }

        $sql .= "\nORDER BY request.added_on DESC";

        return $sql;
    }

    public function get_details($quotation_id = null, $company_id = null, $status = null, $screen = null, $role_name = null, $date_from = null, $date_to = null, $request_date_from = null, $request_date_to = null, $costing_date_from = null, $costing_date_to = null, $term = null, $page = null, $data_size = null, $request_added_by = null) {
        if (is_array($quotation_id) && !$quotation_id) {
            return [];
        }
        $binds = [];
        $sql = $this->_build_quotation_sql($quotation_id, $company_id, $status, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $costing_date_from, $costing_date_to, $term, $binds, false, $request_added_by);
        if ($sql === '') {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_count($quotation_id = null, $company_id = null, $status = null, $screen = null, $role_name = null, $date_from = null, $date_to = null, $request_date_from = null, $request_date_to = null, $costing_date_from = null, $costing_date_to = null, $term = null, $request_added_by = null): int
    {
        if (is_array($quotation_id) && !$quotation_id) {
            return 0;
        }
        $binds = [];
        $base_sql = $this->_build_quotation_sql($quotation_id, $company_id, $status, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $costing_date_from, $costing_date_to, $term, $binds, true, $request_added_by);
        if ($base_sql === '') {
            return 0;
        }
        $sql = "SELECT COUNT(*) AS total FROM ($base_sql) AS quotation_count";

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }
}

?>