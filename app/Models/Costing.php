<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Costing extends MYT_Model
{
    protected $table = 'costing';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'version_no',
        'customer_account_id',
        'request_id',
        'grand_total',
        'date',
        'sae_in_charge_id',
        'company_id',
        'preferred_branch_id',
        'service_package',
        'other_service_package',
        'mode_of_payment',
        'hmo_account_name',
        'estimated_headcount',
        'number_of_days',
        'shift',
        'payment_terms',
        'with_masterlist',
        'with_walk_in',
        'service_duration_date_from',
        'service_duration_date_to',
        'hours_per_day',
        'transaction_type',
        'male_35_plus',
        'female_35_plus',
        'classification',
        'ape_date',
        'signatory',
        'signatory_designation',
        'pre_negotiated_rate',
        'package_name',
        'qty_totals',
        'daily_rate_totals',
        'total_daily_rate_totals',
        'meals_totals',
        'ingress_count_totals',
        'rate_totals',
        'mobilization_amount_totals',
        'mobilization_total_totals',
        'mobilization_unit_totals',
        'remarks',
        'all_services_discount_percentage',
        'total_service',
        'total_manpower',
        'total_mobilization',
        'total_meal',
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
        'guaranteed_headcount',
        'email_result',
        'evaluation_data_source',
        'estimated_patient_per_shift',
        'pax_per_hour_totals',
        'minutes_per_pax_totals',
        'active_hours_totals',
        'patient_per_shift_totals',
        'suggested_qty_totals',
        'mode_of_releasing',
        'contract_head_count',
    ];

    /**
     * Build shared FROM/WHERE for get_details() and get_count().
     * LIMIT is excluded so callers can append it.
     *
     * @param bool $for_count When true, select only costing.id (skip display columns).
     */
    private function _build_costing_sql($costing_id, $status, $company_id, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $term, array &$binds, bool $for_count = false, $request_added_by = null): string
    {
        $binds = [];

        if ($for_count) {
            $sql = <<<EOT
SELECT costing.id
FROM costing
LEFT JOIN customer_account ON customer_account.id = costing.customer_account_id AND customer_account.is_deleted = 0
LEFT JOIN company ON company.id = costing.company_id AND company.is_deleted = 0
LEFT JOIN request ON request.id = costing.request_id
WHERE costing.is_deleted = 0
EOT;
        } else {
            $sql = <<<EOT
SELECT costing.*, 
    DATE(request.added_on) AS request_date,
    customer_account.name AS customer_account_name,
    company.name AS company_name
FROM costing
LEFT JOIN customer_account ON customer_account.id = costing.customer_account_id AND customer_account.is_deleted = 0
LEFT JOIN company ON company.id = costing.company_id AND company.is_deleted = 0
LEFT JOIN request ON request.id = costing.request_id
WHERE costing.is_deleted = 0
EOT;
        }

        if (!$this->_append_id_filter($sql, $binds, 'costing.id', $costing_id)) {
            return '';
        }

        if ($status) {
            if ($status == 'pending' && $screen == 'manager' && !empty($role_name)) {
                if ($role_name == 'sales_manager') {
                    $sql .= <<<EOT

AND costing.status = 'pending'
AND costing.approved_by_sales_manager IS NULL
AND NOT EXISTS (
    SELECT 1 FROM quotation
    WHERE quotation.is_deleted = 0
    AND quotation.costing_id = costing.id
)
EOT;
                } elseif ($role_name == 'sales_director') {
                    $sql .= <<<EOT

AND costing.status = 'pending'
AND costing.approved_by_sales_manager IS NOT NULL
AND costing.approved_by_sales_director IS NULL
AND NOT EXISTS (
    SELECT 1 FROM quotation
    WHERE quotation.is_deleted = 0
    AND quotation.costing_id = costing.id
)
EOT;
                } elseif ($role_name == 'finance_director') {
                    $sql .= <<<EOT

AND costing.status = 'pending'
AND costing.approved_by_sales_director IS NOT NULL
AND costing.approved_by_finance_director IS NULL
AND NOT EXISTS (
    SELECT 1 FROM quotation
    WHERE quotation.is_deleted = 0
    AND quotation.costing_id = costing.id
)
EOT;
                }
            } else {
                $sql .= "\nAND costing.status = ?";
                $binds[] = $status;
            }
        }

        if ($company_id) {
            $sql .= "\nAND costing.company_id = ?";
            $binds[] = $company_id;
        }

        if ($date_from && $date_to) {
            $sql .= "\nAND costing.added_on >= ? AND costing.added_on < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($request_date_from && $request_date_to) {
            $sql .= "\nAND request.added_on >= ? AND request.added_on < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $request_date_from;
            $binds[] = $request_date_to;
        }

        if ($term) {
            $sql .= "\nAND (costing.remarks LIKE ? OR customer_account.name LIKE ?)";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        if ($request_added_by !== null) {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1 FROM request AS owned_request
    WHERE owned_request.id = costing.request_id
    AND owned_request.added_by = ?
)
EOT;
            $binds[] = $request_added_by;
        }

        $sql .= "\nORDER BY request.added_on DESC";

        return $sql;
    }

    public function get_details($costing_id = null, $status = null, $company_id = null, $screen = null, $role_name = null, $date_from = null, $date_to = null, $request_date_from = null, $request_date_to = null, $term = null, $page = null, $data_size = null, $request_added_by = null) {
        if (is_array($costing_id) && !$costing_id) {
            return [];
        }
        $binds = [];
        $sql = $this->_build_costing_sql($costing_id, $status, $company_id, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $term, $binds, false, $request_added_by);
        if ($sql === '') {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_count($costing_id = null, $status = null, $company_id = null, $screen = null, $role_name = null, $date_from = null, $date_to = null, $request_date_from = null, $request_date_to = null, $term = null, $request_added_by = null): int
    {
        if (is_array($costing_id) && !$costing_id) {
            return 0;
        }
        $binds = [];
        $base_sql = $this->_build_costing_sql($costing_id, $status, $company_id, $screen, $role_name, $date_from, $date_to, $request_date_from, $request_date_to, $term, $binds, true, $request_added_by);
        if ($base_sql === '') {
            return 0;
        }
        $sql = "SELECT COUNT(*) AS total FROM ($base_sql) AS costing_count";

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    public function get_last_details($request_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing.*,
    DATE(request.added_on) AS request_date,
    customer_account.name AS customer_account_name,
    company.name AS company_name
FROM costing
INNER JOIN (
    SELECT request_id, MAX(version_no) AS max_version
    FROM costing
    WHERE is_deleted = 0
EOT;

        if (!$this->_append_id_filter($sql, $binds, 'request_id', $request_id)) {
            return [];
        }

        $sql .= <<<EOT

    GROUP BY request_id
) latest ON latest.request_id = costing.request_id AND latest.max_version = costing.version_no
LEFT JOIN customer_account ON customer_account.id = costing.customer_account_id AND customer_account.is_deleted = 0
LEFT JOIN company ON company.id = costing.company_id AND company.is_deleted = 0
LEFT JOIN request ON request.id = costing.request_id
WHERE costing.is_deleted = 0
EOT;

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function validate_package_name($package_name = null, $costing_id = null)
    {
        $package_name = strtolower(trim($package_name));

        $binds = [];

        $sql = <<<EOT
(
    SELECT costing.package_name
    FROM costing
    WHERE LOWER(costing.package_name) = ?
    AND costing.is_deleted = 0
    AND costing.status IN ('pending', 'approved', 'archived')
EOT;

        $binds[] = $package_name;

        // exclude current costing on update
        if ($costing_id) {
            $sql .= " AND costing.id != ? ";
            $binds[] = $costing_id;
        }

        $sql .= <<<EOT
)
UNION
(
    SELECT package.name
    FROM package
    WHERE LOWER(package.name) = ?
    AND package.is_deleted = 0
)
UNION
(
    SELECT lab_test.name
    FROM lab_test
    WHERE LOWER(lab_test.name) = ?
    AND lab_test.is_deleted = 0
)
LIMIT 1
EOT;

        $binds[] = $package_name; // package
        $binds[] = $package_name; // lab_test

        $query = $this->db->query($sql, $binds);
        return $query && $query->getNumRows() > 0;
    }

    public function get_latest_prev_costing($request_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT costing.id, costing.version_no
FROM costing
WHERE costing.is_deleted = 0
EOT;

        if ($request_id) {
            $sql .= <<<EOT

AND costing.request_id = ?
EOT;
            $binds[] = $request_id;
        }

        $sql .= " ORDER BY version_no DESC
                    LIMIT 1";

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }
}

?>