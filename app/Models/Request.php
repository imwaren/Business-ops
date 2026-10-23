<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Request extends MYT_Model
{
    protected $table = 'request';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'customer_account_id',
        'reference_no',
        'request_type',
        'priority',
        'due_date',
        'contact_person',
        'contact_number',
        'email',
        'delivery_address',
        'payment_terms',
        'grand_total',
        'date',
        'sae_in_charge_id',
        'company_id',
        'mode_of_payment',
        'preferred_branch_id',
        'hmo_account_name',
        'estimated_headcount',
        'number_of_days',
        'shift',
        'with_masterlist',
        'with_walk_in',
        'transaction_type',
        'male_35_plus',
        'female_35_plus',
        'classification',
        'service_duration_date_from',
        'service_duration_date_to',
        'rdt_requester',
        'signatory',
        'signatory_designation',
        'remarks',
        'all_services_discount_percentage',
        'guaranteed_headcount',
        'mobile_site',
        'email_result',
        'evaluation_data_source',
        'estimated_patient_per_shift',
        'type_cost',
        'mode_of_releasing',
    ];

    /**
     * Build shared FROM/WHERE for get_details() and get_count().
     * LIMIT is excluded so callers can append it.
     *
     * @param bool $for_count When true, select only request.id (skip display columns / correlated subquery).
     */
    private function _build_request_sql($request_id, $status, $company_id, $screen, $date_from, $date_to, $term, array &$binds, bool $for_count = false, $request_added_by = null): string
    {
        $binds = [];

        if ($for_count) {
            $sql = <<<EOT
SELECT request.id
FROM request
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
WHERE request.is_deleted = 0
EOT;
        } else {
            $sql = <<<EOT
SELECT request.*,
    company.name AS company_name,
    (
        SELECT costing.disapproved_reason 
        FROM costing WHERE costing.request_id = request.id 
        AND costing.is_deleted = 0 
        AND costing.status = 'disapproved'
        ORDER BY costing.id DESC
        LIMIT 1
    ) AS costing_disapproved_reason
FROM request
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
WHERE request.is_deleted = 0
EOT;
        }

        if (!$this->_append_id_filter($sql, $binds, 'request.id', $request_id)) {
            return ''; // signal empty; callers must treat empty string as no rows
        }

        if ($status) {
            if ($status == 'approved' && $screen == 'manager') {
                $sql .= <<<EOT

AND request.status = ?
AND NOT EXISTS (
    SELECT 1 FROM costing
    WHERE costing.is_deleted = 0
    AND costing.request_id = request.id
    AND costing.status IN ('approved', 'pending', 'archived')
)
EOT;
                $binds[] = $status;
            } else {
                $sql .= "\nAND request.status = ?";
                $binds[] = $status;
            }
        }

        if ($company_id) {
            $sql .= "\nAND request.company_id = ?";
            $binds[] = $company_id;
        }

        if ($date_from && $date_to) {
            $sql .= "\nAND request.added_on >= ? AND request.added_on < DATE_ADD(?, INTERVAL 1 DAY)";
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($term) {
            $sql .= "\nAND (request.reference_no LIKE ? OR request.remarks LIKE ? OR company.name LIKE ?)";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        if ($request_added_by !== null) {
            $sql .= "\nAND request.added_by = ?";
            $binds[] = $request_added_by;
        }

        $sql .= "\nORDER BY request.added_on DESC";

        return $sql;
    }

    public function get_details($request_id = null, $status = null, $company_id = null, $screen = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null, $request_added_by = null) {
        if (is_array($request_id) && !$request_id) {
            return [];
        }
        $binds = [];
        $sql = $this->_build_request_sql($request_id, $status, $company_id, $screen, $date_from, $date_to, $term, $binds, false, $request_added_by);
        if ($sql === '') {
            return [];
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_count($request_id = null, $status = null, $company_id = null, $screen = null, $date_from = null, $date_to = null, $term = null, $request_added_by = null): int
    {
        if (is_array($request_id) && !$request_id) {
            return 0;
        }
        $binds = [];
        $base_sql = $this->_build_request_sql($request_id, $status, $company_id, $screen, $date_from, $date_to, $term, $binds, true, $request_added_by);
        if ($base_sql === '') {
            return 0;
        }
        $sql = "SELECT COUNT(*) AS total FROM ($base_sql) AS request_count";

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    public function get_sales_tracker_by_company($request_id = null, $sae_in_charge_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT request.id AS request_id,
    request.company_id,
    company.name AS company_name,
    request.customer_account_id,
    request.date,
    request.sae_in_charge_id,
    request.estimated_headcount,
    request.transaction_type,
    request.classification,
    CONCAT(
            endorsement.mobile_activity_date_from, '_', 
            endorsement.mobile_activity_date_to
        ) AS mobile_activity_date,
    CONCAT(
            endorsement.clinic_activity_date_from, '_', 
            endorsement.clinic_activity_date_to
        ) AS clinic_activity_date,
    costing.total_cost_amount_total,
    costing.estimated_margin_amount_total,
    costing.estimated_revenue_total,
    costing.cost_total,
    request_service.head_count,
    request_service.charge_to,
    request_service.charge_to_ids,
    (SELECT GROUP_CONCAT(company.name SEPARATOR ', ')
     FROM company
     WHERE FIND_IN_SET(company.id, request_service.charge_to_ids)
       AND company.is_deleted = 0) AS charge_to_names,
    request.added_on AS inquiry_date,
    endorsement.added_on AS booking_date
FROM request
LEFT JOIN request_service ON request_service.request_id = request.id AND request_service.is_deleted = 0
LEFT JOIN costing ON costing.request_id = request.id AND costing.is_deleted = 0 AND costing.version_no = (SELECT MAX(version_no) FROM costing WHERE request_id = request.id AND is_deleted = 0)
LEFT JOIN endorsement ON endorsement.request_id = request.id AND endorsement.is_deleted = 0 AND endorsement.version_no = (SELECT MAX(version_no) FROM endorsement WHERE request_id = request.id AND is_deleted = 0)
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
WHERE request.is_deleted = 0
EOT;

        if ($request_id) {
            $sql .= <<<EOT

AND request.id = ?
EOT;
            $binds[] = $request_id;
        }

        if ($sae_in_charge_id) {
            $sql .= <<<EOT

AND request.sae_in_charge_id = ?
EOT;
            $binds[] = $sae_in_charge_id;
        }

        $sql .= " GROUP BY request.company_id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_sales_tracker($sales_person_id = null, $company_id = null, $date_from = null, $date_to = null, $page = null, $data_size = null, $debug = false, $filters = []) {
        $metrics = [];
        $debug_output = [];

        $stage_queries = [
            'inquiry'     => $this->_get_sales_tracker_inquiry_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters),
            'endorsement' => $this->_get_sales_tracker_endorsement_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters),
            'cancelled'   => $this->_get_sales_tracker_cancelled_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters),
            'lapse'       => $this->_get_sales_tracker_lapse_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters),
        ];

        if ($debug) {
            foreach ($stage_queries as $label => $stage) {
                $debug_output[] = [
                    'stage' => $label,
                    'db'    => 'mytemr_master',
                    'sql'   => $this->_interpolate_sql_binds($stage['sql'], $stage['binds']),
                ];
            }

            $branch_debug = $this->_fetch_branch_delivered_transactions($date_from, $date_to, true);
            $debug_output = array_merge($debug_output, $branch_debug);

            $delivered_debug = $this->_get_sales_tracker_delivered_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters, true);
            $debug_output = array_merge($debug_output, $delivered_debug);

            $this->_debug_die_sales_tracker_sql($debug_output);
        }

        foreach ($stage_queries as $stage) {
            $query = $this->db->query($stage['sql'], $stage['binds']);
            if (! $query) {
                return false;
            }

            $this->_merge_sales_tracker_rows($metrics, $query->getResultArray());
        }

        $delivered_rows = $this->_get_sales_tracker_delivered_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters);
        if ($delivered_rows === false) {
            return false;
        }
        $this->_merge_sales_tracker_rows($metrics, $delivered_rows);

        $result = $this->_build_sales_tracker_companies(array_values($metrics));

        if ($page && $data_size) {
            $page = max(1, (int) $page);
            $offset = ($page - 1) * (int) $data_size;
            $result = array_slice($result, $offset, (int) $data_size);
        }

        return $result;
    }

    private function _merge_sales_tracker_rows(&$metrics, $rows) {
        foreach ($rows as $row) {
            $key = $row['sales_person_id'] . ':' . $row['company_id'];
            if (! isset($metrics[$key])) {
                $metrics[$key] = $this->_empty_sales_tracker_company_row(
                    $row['sales_person_id'],
                    $row['sales_person_name'] ?? null,
                    $row['company_id'],
                    $row['company_name'] ?? null
                );
            }

            if (empty($metrics[$key]['sales_person_name']) && ! empty($row['sales_person_name'])) {
                $metrics[$key]['sales_person_name'] = $row['sales_person_name'];
            }
            if (empty($metrics[$key]['company_name']) && ! empty($row['company_name'])) {
                $metrics[$key]['company_name'] = $row['company_name'];
            }

            foreach ($this->_sales_tracker_metric_fields() as $field) {
                if (array_key_exists($field, $row)) {
                    $metrics[$key][$field] = (float) ($row[$field] ?? 0);
                }
            }

            foreach (['lapse_days_sum', 'lapse_request_count'] as $field) {
                if (array_key_exists($field, $row)) {
                    $metrics[$key][$field] += (float) ($row[$field] ?? 0);
                }
            }

            foreach (['inquiry_request_ids', 'endorsement_request_ids', 'delivered_request_ids', 'cancelled_request_ids'] as $field) {
                if (! empty($row[$field])) {
                    $metrics[$key][$field] = $row[$field];
                }
            }
        }
    }

    private function _interpolate_sql_binds($sql, $binds) {
        foreach ($binds as $bind) {
            if (is_null($bind)) {
                $replacement = 'NULL';
            } elseif (is_numeric($bind)) {
                $replacement = (string) $bind;
            } else {
                $replacement = "'" . str_replace("'", "''", (string) $bind) . "'";
            }

            $sql = preg_replace('/\?/', $replacement, $sql, 1);
        }

        return $sql;
    }

    private function _debug_die_sales_tracker_sql($debug_output) {
        $output = '';
        foreach ($debug_output as $block) {
            $output .= "\n-- STAGE: {$block['stage']}\n";
            $output .= '-- DB: ' . ($block['db'] ?? 'mytemr_master') . "\n";
            $output .= $block['sql'] . ";\n";
        }

        die('<pre>' . htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</pre>');
    }

    private function _sales_tracker_metric_fields() {
        return [
            'inquiry_count',
            'inquiry_headcount',
            'inquiry_revenue',
            'endorsement_count',
            'endorsement_headcount',
            'endorsement_revenue',
            'delivered_count',
            'delivered_headcount',
            'delivered_revenue',
            'cancelled_count',
            'cancelled_headcount',
            'cancelled_revenue',
        ];
    }

    private function _empty_sales_tracker_company_row($sales_person_id, $sales_person_name, $company_id, $company_name) {
        $row = [
            'sales_person_id'   => $sales_person_id,
            'sales_person_name' => $sales_person_name,
            'company_id'        => $company_id,
            'company_name'      => $company_name,
        ];

        foreach ($this->_sales_tracker_metric_fields() as $field) {
            $row[$field] = 0;
        }

        $row['lapse_days_sum']     = 0;
        $row['lapse_request_count'] = 0;
        $row['inquiry_request_ids']     = '';
        $row['endorsement_request_ids'] = '';
        $row['delivered_request_ids']   = '';
        $row['cancelled_request_ids']   = '';

        return $row;
    }

    private function _latest_costing_join_sql() {
        return <<<EOT
LEFT JOIN (
    SELECT c.request_id,
    c.estimated_revenue_total,
    c.status,
    c.disapproved_on
    FROM costing c
    INNER JOIN (
        SELECT request_id, MAX(version_no) AS max_version
        FROM costing
        WHERE is_deleted = 0
        GROUP BY request_id
    ) latest ON latest.request_id = c.request_id AND latest.max_version = c.version_no
    WHERE c.is_deleted = 0
) AS latest_costing ON latest_costing.request_id = request.id
EOT;
    }

    private function _apply_sales_tracker_comma_filter($column, $payload, &$sql, &$binds) {
        if ($payload === null || $payload === '') {
            return;
        }

        $values = array_filter(array_map('trim', explode(',', (string) $payload)));
        if (empty($values)) {
            return;
        }

        $conditions = [];
        foreach ($values as $value) {
            $conditions[] = "FIND_IN_SET(?, {$column})";
            $binds[] = $value;
        }

        $sql .= "\nAND (" . implode(' OR ', $conditions) . ")";
    }

    private function _sales_tracker_request_filters($sales_person_id, $company_id, &$sql, &$binds, $filters = [], $alias = 'request') {
        if ($sales_person_id) {
            $sql .= "\nAND {$alias}.added_by = ?";
            $binds[] = $sales_person_id;
        }

        if ($company_id) {
            $sql .= "\nAND {$alias}.company_id = ?";
            $binds[] = $company_id;
        }

        $this->_apply_sales_tracker_comma_filter('company.industry', $filters['industry'] ?? null, $sql, $binds);
        $this->_apply_sales_tracker_comma_filter('company.industry_category', $filters['industry_category'] ?? null, $sql, $binds);
        $this->_apply_sales_tracker_comma_filter('company.business_type', $filters['business_type'] ?? null, $sql, $binds);
        $this->_apply_sales_tracker_comma_filter('request.transaction_type', $filters['transaction_type'] ?? null, $sql, $binds);
        $this->_apply_sales_tracker_comma_filter('request.classification', $filters['classification'] ?? null, $sql, $binds);
        $this->_apply_sales_tracker_comma_filter('request.customer_account_id', $filters['hmo_id'] ?? null, $sql, $binds);
        $this->_apply_sales_tracker_comma_filter('request.mode_of_payment', $filters['charge_to'] ?? null, $sql, $binds);
        $this->_apply_sales_tracker_comma_filter('request.sae_in_charge_id', $filters['sae_in_charge_id'] ?? null, $sql, $binds);
        $this->_apply_sales_tracker_comma_filter('request.preferred_branch_id', $filters['preferred_branch_id'] ?? null, $sql, $binds);
    }

    private function _apply_moving_date_clause($column, $date_from, $date_to, &$sql, &$binds) {
        if ($date_from && $date_to) {
            $sql .= "\nAND DATE({$column}) BETWEEN ? AND ?";
            $binds[] = $date_from;
            $binds[] = $date_to;
        }
    }

    private function _get_sales_tracker_inquiry_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters = []) {
        $binds = [];
        $sql = <<<EOT
SELECT request.added_by AS sales_person_id,
    CONCAT_WS('', sales_person.first_name, sales_person.last_name, sales_person.middle_name) AS sales_person_name,
    request.company_id,
    company.name AS company_name,
    COUNT(DISTINCT request.id) AS inquiry_count,
    COALESCE(SUM(request.estimated_headcount), 0) AS inquiry_headcount,
    COALESCE(SUM(latest_costing.estimated_revenue_total), 0) AS inquiry_revenue,
    GROUP_CONCAT(DISTINCT request.id ORDER BY request.id SEPARATOR ', ') AS inquiry_request_ids
FROM request
LEFT JOIN user AS sales_person ON sales_person.id = request.added_by AND sales_person.is_deleted = 0
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
{$this->_latest_costing_join_sql()}
WHERE request.is_deleted = 0
EOT;

        $this->_sales_tracker_request_filters($sales_person_id, $company_id, $sql, $binds, $filters);
        $this->_apply_moving_date_clause('request.added_on', $date_from, $date_to, $sql, $binds);

        $sql .= "\nGROUP BY request.added_by, request.company_id, sales_person_name, company.name";

        return ['sql' => $sql, 'binds' => $binds];
    }

    private function _get_sales_tracker_endorsement_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters = []) {
        $binds = [];
        $endorsement_date_sql = '';
        if ($date_from && $date_to) {
            $endorsement_date_sql = "\nAND DATE(endorsement.added_on) BETWEEN ? AND ?";
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql = <<<EOT
SELECT request.added_by AS sales_person_id,
    CONCAT_WS('', sales_person.first_name, sales_person.last_name, sales_person.middle_name) AS sales_person_name,
    request.company_id,
    company.name AS company_name,
    COUNT(DISTINCT endorsement_by_request.request_id) AS endorsement_count,
    COALESCE(SUM(endorsement_by_request.guaranteed_headcount), 0) AS endorsement_headcount,
    COALESCE(SUM(latest_costing.estimated_revenue_total), 0) AS endorsement_revenue,
    GROUP_CONCAT(DISTINCT endorsement_by_request.request_id ORDER BY endorsement_by_request.request_id SEPARATOR ', ') AS endorsement_request_ids
FROM (
    SELECT endorsement.request_id,
        COALESCE(SUM(endorsement.guaranteed_headcount), 0) AS guaranteed_headcount
    FROM endorsement
    WHERE endorsement.is_deleted = 0
    {$endorsement_date_sql}
    GROUP BY endorsement.request_id
) AS endorsement_by_request
INNER JOIN request ON request.id = endorsement_by_request.request_id AND request.is_deleted = 0
LEFT JOIN user AS sales_person ON sales_person.id = request.added_by AND sales_person.is_deleted = 0
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
{$this->_latest_costing_join_sql()}
WHERE 1 = 1
EOT;

        $this->_sales_tracker_request_filters($sales_person_id, $company_id, $sql, $binds, $filters);

        $sql .= "\nGROUP BY request.added_by, request.company_id, sales_person_name, company.name";

        return ['sql' => $sql, 'binds' => $binds];
    }

    private function _get_sales_tracker_lapse_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters = []) {
        $binds = [];
        $endorsement_date_sql = '';
        if ($date_from && $date_to) {
            $endorsement_date_sql = "\nAND DATE(endorsement.added_on) BETWEEN ? AND ?";
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql = <<<EOT
SELECT request.added_by AS sales_person_id,
    CONCAT_WS('', sales_person.first_name, sales_person.last_name, sales_person.middle_name) AS sales_person_name,
    request.company_id,
    company.name AS company_name,
    COALESCE(SUM(TIMESTAMPDIFF(HOUR, request.added_on, first_endorsement.added_on) / 24), 0) AS lapse_days_sum,
    COUNT(DISTINCT request.id) AS lapse_request_count
FROM request
INNER JOIN (
    SELECT endorsement.request_id, MIN(endorsement.added_on) AS added_on
    FROM endorsement
    WHERE endorsement.is_deleted = 0
    {$endorsement_date_sql}
    GROUP BY endorsement.request_id
) AS first_endorsement ON first_endorsement.request_id = request.id
LEFT JOIN user AS sales_person ON sales_person.id = request.added_by AND sales_person.is_deleted = 0
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
WHERE request.is_deleted = 0
EOT;

        $this->_sales_tracker_request_filters($sales_person_id, $company_id, $sql, $binds, $filters);

        $sql .= "\nGROUP BY request.added_by, request.company_id, sales_person_name, company.name";

        return ['sql' => $sql, 'binds' => $binds];
    }

    private function _get_sales_tracker_cancelled_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters = []) {
        $binds = [];
        $sql = <<<EOT
SELECT request.added_by AS sales_person_id,
    CONCAT_WS('', sales_person.first_name, sales_person.last_name, sales_person.middle_name) AS sales_person_name,
    request.company_id,
    company.name AS company_name,
    COUNT(DISTINCT request.id) AS cancelled_count,
    COALESCE(SUM(request.estimated_headcount), 0) AS cancelled_headcount,
    COALESCE(SUM(latest_costing.estimated_revenue_total), 0) AS cancelled_revenue,
    GROUP_CONCAT(DISTINCT request.id ORDER BY request.id SEPARATOR ', ') AS cancelled_request_ids
FROM request
LEFT JOIN user AS sales_person ON sales_person.id = request.added_by AND sales_person.is_deleted = 0
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
{$this->_latest_costing_join_sql()}
WHERE request.is_deleted = 0
AND (
    (latest_costing.request_id IS NOT NULL AND latest_costing.status IN ('disapproved', 'returned'))
    OR (latest_costing.request_id IS NULL AND request.status IN ('disapproved', 'returned'))
)
EOT;

        $this->_sales_tracker_request_filters($sales_person_id, $company_id, $sql, $binds, $filters);

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND (
    (latest_costing.request_id IS NOT NULL
        AND latest_costing.status IN ('disapproved', 'returned')
        AND DATE(CASE WHEN latest_costing.status = 'returned' THEN latest_costing.returned_on ELSE latest_costing.disapproved_on END) BETWEEN ? AND ?)
    OR (latest_costing.request_id IS NULL
        AND request.status IN ('disapproved', 'returned')
        AND DATE(CASE WHEN request.status = 'returned' THEN request.returned_on ELSE request.disapproved_on END) BETWEEN ? AND ?)
)
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        $sql .= "\nGROUP BY request.added_by, request.company_id, sales_person_name, company.name";

        return ['sql' => $sql, 'binds' => $binds];
    }

    private function _sales_tracker_branch_connections() {
        return [
            'mobile'      => db_connect('mobile'),
            'oakridge'    => db_connect('oakridge'),
            'mactan'      => db_connect('mactan'),
            'consolacion' => db_connect('consolacion'),
            'mantlewood'  => db_connect('mantlewood'),
        ];
    }

    private function _get_branch_delivered_transaction_sql($date_from, $date_to, &$binds) {
        $sql = <<<EOT
SELECT t.endorsement_id, t.id AS transaction_id, t.transaction_date
FROM `transaction` t
WHERE t.is_deleted = 0 AND t.endorsement_id IS NOT NULL
EOT;

        $this->_apply_moving_date_clause('t.transaction_date', $date_from, $date_to, $sql, $binds);

        return $sql;
    }

    private function _get_branch_delivered_headcount_sql($date_from, $date_to, &$binds) {
        $sql = <<<EOT
SELECT t.endorsement_id, COUNT(DISTINCT b.patient_id) AS patient_count
FROM `transaction` t
INNER JOIN booking b ON b.transaction_id = t.id AND b.is_deleted = 0
WHERE t.is_deleted = 0 AND t.endorsement_id IS NOT NULL
EOT;

        $this->_apply_moving_date_clause('t.transaction_date', $date_from, $date_to, $sql, $binds);

        $sql .= "\nGROUP BY t.endorsement_id";

        return $sql;
    }

    private function _fetch_branch_delivered_transactions($date_from, $date_to, $collect_debug = false) {
        $endorsement_ids = [];
        $headcount_by_endorsement = [];
        $debug_output = [];

        foreach ($this->_sales_tracker_branch_connections() as $branch_name => $db) {
            $tx_binds = [];
            $tx_sql = $this->_get_branch_delivered_transaction_sql($date_from, $date_to, $tx_binds);
            $tx_query = $db->query($tx_sql, $tx_binds);

            if ($collect_debug) {
                $debug_output[] = [
                    'stage' => "delivered_transactions_{$branch_name}",
                    'db'    => $branch_name,
                    'sql'   => $this->_interpolate_sql_binds($tx_sql, $tx_binds),
                ];
            }

            if ($tx_query) {
                foreach ($tx_query->getResultArray() as $row) {
                    $endorsement_ids[] = (int) $row['endorsement_id'];
                }
            }

            $hc_binds = [];
            $hc_sql = $this->_get_branch_delivered_headcount_sql($date_from, $date_to, $hc_binds);
            $hc_query = $db->query($hc_sql, $hc_binds);

            if ($collect_debug) {
                $debug_output[] = [
                    'stage' => "delivered_headcount_{$branch_name}",
                    'db'    => $branch_name,
                    'sql'   => $this->_interpolate_sql_binds($hc_sql, $hc_binds),
                ];
            }

            if ($hc_query) {
                foreach ($hc_query->getResultArray() as $row) {
                    $endorsement_id = (int) $row['endorsement_id'];
                    $patient_count  = (int) ($row['patient_count'] ?? 0);
                    if (! isset($headcount_by_endorsement[$endorsement_id])) {
                        $headcount_by_endorsement[$endorsement_id] = 0;
                    }
                    $headcount_by_endorsement[$endorsement_id] += $patient_count;
                }
            }
        }

        if ($collect_debug) {
            return $debug_output;
        }

        return [
            'endorsement_ids'          => array_values(array_unique($endorsement_ids)),
            'headcount_by_endorsement' => $headcount_by_endorsement,
        ];
    }

    private function _get_sales_tracker_delivered_metrics($sales_person_id, $company_id, $date_from, $date_to, $filters = [], $collect_debug = false) {
        $branch_data = $this->_fetch_branch_delivered_transactions($date_from, $date_to);

        if (empty($branch_data['endorsement_ids'])) {
            return $collect_debug ? [] : [];
        }

        $endorsement_ids = $branch_data['endorsement_ids'];
        $headcount_by_endorsement = $branch_data['headcount_by_endorsement'];
        $chunk_size = 500;
        $aggregated = [];

        foreach (array_chunk($endorsement_ids, $chunk_size) as $chunk) {
            $binds = $chunk;
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $sql = <<<EOT
SELECT request.added_by AS sales_person_id,
    CONCAT_WS('', sales_person.first_name, sales_person.last_name, sales_person.middle_name) AS sales_person_name,
    request.company_id,
    company.name AS company_name,
    endorsement.id AS endorsement_id,
    request.id AS request_id,
    latest_costing.estimated_revenue_total
FROM endorsement
INNER JOIN request ON request.id = endorsement.request_id AND request.is_deleted = 0
LEFT JOIN user AS sales_person ON sales_person.id = request.added_by AND sales_person.is_deleted = 0
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
{$this->_latest_costing_join_sql()}
WHERE endorsement.is_deleted = 0
AND endorsement.id IN ({$placeholders})
EOT;

            $this->_sales_tracker_request_filters($sales_person_id, $company_id, $sql, $binds, $filters);

            if ($collect_debug) {
                return [[
                    'stage' => 'delivered_master',
                    'db'    => 'mytemr_master',
                    'sql'   => $this->_interpolate_sql_binds($sql, $binds),
                ]];
            }

            $query = $this->db->query($sql, $binds);
            if (! $query) {
                return false;
            }

            foreach ($query->getResultArray() as $row) {
                $key = $row['sales_person_id'] . ':' . $row['company_id'];
                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'sales_person_id'   => $row['sales_person_id'],
                        'sales_person_name' => $row['sales_person_name'] ?? null,
                        'company_id'        => $row['company_id'],
                        'company_name'      => $row['company_name'] ?? null,
                        'endorsement_ids'   => [],
                        'request_revenue'   => [],
                        'request_ids'       => [],
                        'delivered_headcount' => 0,
                    ];
                }

                $endorsement_id = (int) $row['endorsement_id'];
                if (! isset($aggregated[$key]['endorsement_ids'][$endorsement_id])) {
                    $aggregated[$key]['endorsement_ids'][$endorsement_id] = true;
                    $aggregated[$key]['delivered_headcount'] += (int) ($headcount_by_endorsement[$endorsement_id] ?? 0);
                }

                $request_id = (int) $row['request_id'];
                if (! isset($aggregated[$key]['request_revenue'][$request_id])) {
                    $aggregated[$key]['request_revenue'][$request_id] = (float) ($row['estimated_revenue_total'] ?? 0);
                    $aggregated[$key]['request_ids'][$request_id] = true;
                }
            }
        }

        $rows = [];
        foreach ($aggregated as $item) {
            $rows[] = [
                'sales_person_id'     => $item['sales_person_id'],
                'sales_person_name'   => $item['sales_person_name'],
                'company_id'          => $item['company_id'],
                'company_name'        => $item['company_name'],
                'delivered_count'     => count($item['endorsement_ids']),
                'delivered_headcount' => $item['delivered_headcount'],
                'delivered_revenue'   => array_sum($item['request_revenue']),
                'delivered_request_ids' => implode(', ', array_keys($item['request_ids'])),
            ];
        }

        return $rows;
    }

    private function _build_sales_tracker_companies($flat_rows) {
        $sales_persons = [];

        foreach ($flat_rows as $row) {
            $sales_person_id = $row['sales_person_id'];
            if (! isset($sales_persons[$sales_person_id])) {
                $sales_persons[$sales_person_id] = [
                    'sales_person_id'   => $sales_person_id,
                    'sales_person_name' => $row['sales_person_name'] ?? null,
                    'companies'         => [],
                    'lapse_days_sum'   => 0,
                    'lapse_request_count' => 0,
                ];

                foreach ($this->_sales_tracker_metric_fields() as $field) {
                    $sales_persons[$sales_person_id][$field] = 0;
                }
            }

            if (empty($sales_persons[$sales_person_id]['sales_person_name']) && ! empty($row['sales_person_name'])) {
                $sales_persons[$sales_person_id]['sales_person_name'] = $row['sales_person_name'];
            }

            $lapse_days_sum     = (float) ($row['lapse_days_sum'] ?? 0);
            $lapse_request_count = (float) ($row['lapse_request_count'] ?? 0);

            $company_metrics = [
                'company_name' => $row['company_name'] ?? null,
                'company_id'   => $row['company_id'] ?? null,
                'average_lapse_time' => $lapse_request_count > 0
                    ? round($lapse_days_sum / $lapse_request_count, 2)
                    : 0,
                'inquiry_request_ids'     => $row['inquiry_request_ids'] ?? '',
                'endorsement_request_ids' => $row['endorsement_request_ids'] ?? '',
                'delivered_request_ids'   => $row['delivered_request_ids'] ?? '',
                'cancelled_request_ids'   => $row['cancelled_request_ids'] ?? '',
            ];

            foreach ($this->_sales_tracker_metric_fields() as $field) {
                $value = (float) ($row[$field] ?? 0);
                $company_metrics[$field] = $this->_cast_sales_tracker_metric($field, $value);
                $sales_persons[$sales_person_id][$field] += $value;
            }

            $sales_persons[$sales_person_id]['lapse_days_sum'] += $lapse_days_sum;
            $sales_persons[$sales_person_id]['lapse_request_count'] += $lapse_request_count;
            $sales_persons[$sales_person_id]['companies'][] = $company_metrics;
        }

        $result = [];
        foreach ($sales_persons as $sales_person) {
            foreach ($this->_sales_tracker_metric_fields() as $field) {
                $sales_person[$field] = $this->_cast_sales_tracker_metric($field, $sales_person[$field]);
            }

            $lapse_days_sum     = (float) ($sales_person['lapse_days_sum'] ?? 0);
            $lapse_request_count = (float) ($sales_person['lapse_request_count'] ?? 0);
            $sales_person['average_lapse_time'] = $lapse_request_count > 0
                ? round($lapse_days_sum / $lapse_request_count, 2)
                : 0;

            unset($sales_person['lapse_days_sum'], $sales_person['lapse_request_count']);

            $result[] = $sales_person;
        }

        return $result;
    }

    private function _cast_sales_tracker_metric($field, $value) {
        if (str_ends_with($field, '_count')) {
            return (int) $value;
        }

        return (float) $value;
    }

    public function get_sales_tracker_tracker($sales_person_id = null, $company_id = null, $date_from = null, $date_to = null, $page = null, $data_size = null, $debug = false, $filters = []) {
        return $this->_get_sales_tracker_tab('tracker', $sales_person_id, $company_id, $date_from, $date_to, $page, $data_size, $debug, $filters, false);
    }

    public function count_sales_tracker_tracker($sales_person_id = null, $company_id = null, $date_from = null, $date_to = null, $debug = false, $filters = []) {
        return $this->_get_sales_tracker_tab('tracker', $sales_person_id, $company_id, $date_from, $date_to, null, null, $debug, $filters, true);
    }

    public function get_sales_tracker_running($sales_person_id = null, $company_id = null, $date_from = null, $date_to = null, $page = null, $data_size = null, $debug = false, $filters = []) {
        return $this->_get_sales_tracker_tab('running', $sales_person_id, $company_id, $date_from, $date_to, $page, $data_size, $debug, $filters, false);
    }

    public function count_sales_tracker_running($sales_person_id = null, $company_id = null, $date_from = null, $date_to = null, $debug = false, $filters = []) {
        return $this->_get_sales_tracker_tab('running', $sales_person_id, $company_id, $date_from, $date_to, null, null, $debug, $filters, true);
    }

    private function _get_sales_tracker_tab($mode, $sales_person_id, $company_id, $date_from, $date_to, $page, $data_size, $debug, $filters, $count_only) {
        $built = $this->_build_sales_tracker_tab_sql($mode, $sales_person_id, $company_id, $date_from, $date_to, $filters);
        $sql   = $built['sql'];
        $binds = $built['binds'];

        if ($debug) {
            $this->_debug_die_sales_tracker_sql([[
                'stage' => $mode === 'tracker' ? 'tracker_tab' : 'running_tab',
                'db'    => 'mytemr_master',
                'sql'   => $this->_interpolate_sql_binds($sql, $binds),
            ]]);
        }

        if ($count_only) {
            $count_sql = "SELECT COUNT(*) AS row_count FROM ({$sql}) AS sales_tracker_tab";
            $query     = $this->db->query($count_sql, $binds);

            return $query ? (int) ($query->getRowArray()['row_count'] ?? 0) : 0;
        }

        if ($page && $data_size) {
            $page -= 1;
            $offset = $page * $data_size;
            $sql .= " LIMIT {$offset}, {$data_size}";
        }

        $query = $this->db->query($sql, $binds);
        if (! $query) {
            return false;
        }

        $rows = $query->getResultArray();
        $endorsement_ids = array_values(array_unique(array_filter(array_map(
            static fn ($row) => ! empty($row['endorsement_id']) ? (int) $row['endorsement_id'] : null,
            $rows
        ))));

        $branch_facts = $this->_fetch_sales_tracker_branch_facts($endorsement_ids, $date_from, $date_to);

        return $this->_format_sales_tracker_tab_rows($rows, $branch_facts, $mode, $date_from, $date_to);
    }

    private function _latest_costing_detail_join_sql() {
        return <<<EOT
LEFT JOIN (
    SELECT c.id AS costing_id, c.request_id
    FROM costing c
    INNER JOIN (
        SELECT request_id, MAX(version_no) AS max_version
        FROM costing
        WHERE is_deleted = 0
        GROUP BY request_id
    ) latest_costing ON latest_costing.request_id = c.request_id AND latest_costing.max_version = c.version_no
    WHERE c.is_deleted = 0
) AS latest_costing_detail ON latest_costing_detail.request_id = request.id
EOT;
    }

    private function _build_sales_tracker_tab_sql($mode, $sales_person_id, $company_id, $date_from, $date_to, $filters) {
        if ($mode === 'running') {
            $binds = [];
            $sql   = $this->_sales_tracker_endorsed_line_select_sql('endorsement_service', 'endorsement_service');
            $sql .= <<<EOT

FROM endorsement_service AS service_line
INNER JOIN endorsement ON endorsement.id = service_line.endorsement_id AND endorsement.is_deleted = 0
INNER JOIN request ON request.id = endorsement.request_id AND request.is_deleted = 0
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
LEFT JOIN user AS sae ON sae.id = request.sae_in_charge_id AND sae.is_deleted = 0
LEFT JOIN branch ON branch.id = request.preferred_branch_id AND branch.is_deleted = 0
LEFT JOIN lab_test ON lab_test.id = service_line.type_id AND service_line.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = service_line.type_id AND service_line.type = 'package' AND package.is_deleted = 0
LEFT JOIN category ON category.id = lab_test.category_id AND category.is_deleted = 0
LEFT JOIN category AS parent_category ON parent_category.id = category.category_id AND parent_category.is_deleted = 0
LEFT JOIN company AS charge_company ON service_line.charge_to = 'COMPANY'
    AND FIND_IN_SET(charge_company.id, service_line.charge_to_ids) AND charge_company.is_deleted = 0
LEFT JOIN company AS hmo_company ON hmo_company.id = request.customer_account_id AND hmo_company.is_deleted = 0
WHERE service_line.is_deleted = 0
EOT;
            $this->_sales_tracker_request_filters($sales_person_id, $company_id, $sql, $binds, $filters);
            $this->_apply_moving_date_clause('endorsement.added_on', $date_from, $date_to, $sql, $binds);
            $sql .= "\nGROUP BY service_line.id";
            $sql .= "\nORDER BY endorsement.added_on DESC, service_line.id DESC";

            return ['sql' => $sql, 'binds' => $binds];
        }

        $endorsed_binds = [];
        $endorsed_sql   = $this->_sales_tracker_endorsed_line_select_sql('endorsement_service', 'endorsement_service');
        $endorsed_sql .= <<<EOT

FROM endorsement_service AS service_line
INNER JOIN endorsement ON endorsement.id = service_line.endorsement_id AND endorsement.is_deleted = 0
INNER JOIN request ON request.id = endorsement.request_id AND request.is_deleted = 0
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
LEFT JOIN user AS sae ON sae.id = request.sae_in_charge_id AND sae.is_deleted = 0
LEFT JOIN branch ON branch.id = request.preferred_branch_id AND branch.is_deleted = 0
LEFT JOIN lab_test ON lab_test.id = service_line.type_id AND service_line.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = service_line.type_id AND service_line.type = 'package' AND package.is_deleted = 0
LEFT JOIN category ON category.id = lab_test.category_id AND category.is_deleted = 0
LEFT JOIN category AS parent_category ON parent_category.id = category.category_id AND parent_category.is_deleted = 0
LEFT JOIN company AS charge_company ON service_line.charge_to = 'COMPANY'
    AND FIND_IN_SET(charge_company.id, service_line.charge_to_ids) AND charge_company.is_deleted = 0
LEFT JOIN company AS hmo_company ON hmo_company.id = request.customer_account_id AND hmo_company.is_deleted = 0
WHERE service_line.is_deleted = 0
EOT;
        $this->_sales_tracker_request_filters($sales_person_id, $company_id, $endorsed_sql, $endorsed_binds, $filters);
        $this->_apply_moving_date_clause('request.added_on', $date_from, $date_to, $endorsed_sql, $endorsed_binds);
        $endorsed_sql .= "\nGROUP BY service_line.id";

        $inquiry_binds = [];
        $inquiry_sql   = $this->_sales_tracker_request_service_line_select_sql(false);
        $inquiry_sql  .= $this->_sales_tracker_request_service_from_sql();
        $inquiry_sql  .= <<<EOT

WHERE service_line.is_deleted = 0
AND request.status NOT IN ('disapproved', 'returned')
AND NOT EXISTS (
    SELECT 1 FROM endorsement
    WHERE endorsement.request_id = request.id
    AND endorsement.is_deleted = 0
)
EOT;
        $this->_sales_tracker_request_filters($sales_person_id, $company_id, $inquiry_sql, $inquiry_binds, $filters);
        $this->_apply_moving_date_clause('request.added_on', $date_from, $date_to, $inquiry_sql, $inquiry_binds);
        $inquiry_sql .= "\nGROUP BY service_line.id";

        $cancelled_binds = [];
        $cancelled_sql   = $this->_sales_tracker_request_service_line_select_sql(true);
        $cancelled_sql  .= $this->_sales_tracker_request_service_from_sql();
        $cancelled_sql  .= <<<EOT

WHERE service_line.is_deleted = 0
AND request.status IN ('disapproved', 'returned')
AND NOT EXISTS (
    SELECT 1 FROM endorsement
    WHERE endorsement.request_id = request.id
    AND endorsement.is_deleted = 0
)
EOT;
        $this->_sales_tracker_request_filters($sales_person_id, $company_id, $cancelled_sql, $cancelled_binds, $filters);
        $this->_apply_moving_date_clause('request.added_on', $date_from, $date_to, $cancelled_sql, $cancelled_binds);
        $cancelled_sql .= "\nGROUP BY service_line.id";

        $sql = "SELECT * FROM (({$endorsed_sql}) UNION ALL ({$inquiry_sql}) UNION ALL ({$cancelled_sql})) AS tracker_rows";
        $sql .= "\nORDER BY inquiry_date DESC, line_id DESC";

        return ['sql' => $sql, 'binds' => array_merge($endorsed_binds, $inquiry_binds, $cancelled_binds)];
    }

    private function _sales_tracker_endorsed_line_select_sql($line_source, $table_alias) {
        return <<<EOT
SELECT service_line.id AS line_id,
    '{$line_source}' AS line_source,
    request.id AS request_id,
    endorsement.id AS endorsement_id,
    YEAR(request.added_on) AS inq_year,
    MONTH(request.added_on) AS inq_month,
    request.added_on AS inquiry_date,
    CASE
        WHEN request.status = 'disapproved' THEN request.disapproved_on
        WHEN request.status = 'returned' THEN request.returned_on
        WHEN endorsement.status = 'disapproved' THEN endorsement.disapproved_on
        WHEN endorsement.status = 'returned' THEN endorsement.returned_on
        ELSE NULL
    END AS cancelled,
    endorsement.added_on AS endorsed_date,
    CASE
        WHEN endorsement.added_on IS NOT NULL THEN TIMESTAMPDIFF(HOUR, request.added_on, endorsement.added_on) / 24
        ELSE NULL
    END AS lapse_time,
    endorsement.clinic_activity_date_from AS clinic_transaction_delivered,
    endorsement.mobile_activity_date_from AS start_of_mobile_date,
    endorsement.mobile_activity_date_to AS end_of_mobile_date,
    endorsement.ape_results_tat AS mobile_agreed_tat,
    endorsement.completion_date AS mobile_actual_tat,
    endorsement.results_tat AS agreed_tat,
    endorsement.demographics_report_date AS actual_tat,
    sae.first_name AS sae,
    CASE
        WHEN service_line.charge_to = 'COMPANY' THEN charge_company.name
        ELSE NULL
    END AS clinic_management_provider,
    CASE
        WHEN request.mode_of_payment = 'HMO' OR service_line.charge_to = 'HMO'
            THEN COALESCE(hmo_company.name, request.hmo_account_name)
        ELSE NULL
    END AS hmo_provider,
    company.city_municipality AS area,
    request.classification,
    company.industry,
    company.name AS company_name,
    COALESCE(service_line.charge_to, request.mode_of_payment) AS charge_to,
    branch.name AS preferred_branch,
    request.transaction_type,
    COALESCE(parent_category.name, package.type, category.name) AS description_of_service,
    COALESCE(endorsement.package_name, package.name, lab_test.name) AS package_name,
    service_line.head_count AS est_headcount,
    service_line.cost AS package_cost,
    service_line.type_price AS package_price,
    service_line.total_cost_amount_total AS est_cost,
    service_line.estimated_revenue AS est_gross_sales,
    service_line.estimated_margin_amount_total AS gross_margin,
    service_line.type_price AS average_check,
    service_line.pre_negotiated_rate AS pre_negotiated_rate,
    COALESCE(request.remarks, endorsement.remarks) AS remarks,
    request.status AS request_status,
    endorsement.status AS endorsement_status
EOT;
    }

    /**
     * Shared SELECT for tracker request_service arms (inquiry + cancelled).
     *
     * @param bool $include_cancelled When true, cancelled = request.disapproved_on / returned_on; otherwise NULL.
     */
    private function _sales_tracker_request_service_line_select_sql($include_cancelled = false) {
        $cancelled_expr = $include_cancelled
            ? "CASE WHEN request.status = 'returned' THEN request.returned_on ELSE request.disapproved_on END"
            : 'NULL';

        return <<<EOT
SELECT service_line.id AS line_id,
    'request_service' AS line_source,
    request.id AS request_id,
    NULL AS endorsement_id,
    YEAR(request.added_on) AS inq_year,
    MONTH(request.added_on) AS inq_month,
    request.added_on AS inquiry_date,
    {$cancelled_expr} AS cancelled,
    NULL AS endorsed_date,
    NULL AS lapse_time,
    NULL AS clinic_transaction_delivered,
    NULL AS start_of_mobile_date,
    NULL AS end_of_mobile_date,
    NULL AS mobile_agreed_tat,
    NULL AS mobile_actual_tat,
    NULL AS agreed_tat,
    NULL AS actual_tat,
    sae.first_name AS sae,
    CASE
        WHEN service_line.charge_to = 'COMPANY' THEN charge_company.name
        ELSE NULL
    END AS clinic_management_provider,
    CASE
        WHEN request.mode_of_payment = 'HMO' OR service_line.charge_to = 'HMO'
            THEN COALESCE(hmo_company.name, request.hmo_account_name)
        ELSE NULL
    END AS hmo_provider,
    company.city_municipality AS area,
    request.classification,
    company.industry,
    company.name AS company_name,
    COALESCE(service_line.charge_to, request.mode_of_payment) AS charge_to,
    branch.name AS preferred_branch,
    request.transaction_type,
    COALESCE(parent_category.name, package.type, category.name) AS description_of_service,
    COALESCE(package.name, lab_test.name) AS package_name,
    COALESCE(costing_service.head_count, service_line.head_count) AS est_headcount,
    COALESCE(costing_service.cost, service_line.type_cost) AS package_cost,
    COALESCE(costing_service.type_price, service_line.type_price) AS package_price,
    COALESCE(costing_service.total_cost_amount_total, 0) AS est_cost,
    COALESCE(costing_service.estimated_revenue, 0) AS est_gross_sales,
    COALESCE(costing_service.estimated_margin_amount_total, 0) AS gross_margin,
    COALESCE(costing_service.type_price, service_line.type_price) AS average_check,
    COALESCE(costing_service.pre_negotiated_rate, service_line.pre_negotiated_rate) AS pre_negotiated_rate,
    request.remarks AS remarks,
    request.status AS request_status,
    NULL AS endorsement_status
EOT;
    }

    private function _sales_tracker_request_service_from_sql() {
        return <<<EOT

FROM request_service AS service_line
INNER JOIN request ON request.id = service_line.request_id AND request.is_deleted = 0
LEFT JOIN company ON company.id = request.company_id AND company.is_deleted = 0
LEFT JOIN user AS sae ON sae.id = request.sae_in_charge_id AND sae.is_deleted = 0
LEFT JOIN branch ON branch.id = request.preferred_branch_id AND branch.is_deleted = 0
{$this->_latest_costing_detail_join_sql()}
LEFT JOIN costing_service ON costing_service.costing_id = latest_costing_detail.costing_id
    AND costing_service.type = service_line.type
    AND costing_service.type_id = service_line.type_id
    AND costing_service.is_deleted = 0
LEFT JOIN lab_test ON lab_test.id = service_line.type_id AND service_line.type = 'lab_test' AND lab_test.is_deleted = 0
LEFT JOIN package ON package.id = service_line.type_id AND service_line.type = 'package' AND package.is_deleted = 0
LEFT JOIN category ON category.id = lab_test.category_id AND category.is_deleted = 0
LEFT JOIN category AS parent_category ON parent_category.id = category.category_id AND parent_category.is_deleted = 0
LEFT JOIN company AS charge_company ON service_line.charge_to = 'COMPANY'
    AND FIND_IN_SET(charge_company.id, service_line.charge_to_ids) AND charge_company.is_deleted = 0
LEFT JOIN company AS hmo_company ON hmo_company.id = request.customer_account_id AND hmo_company.is_deleted = 0
EOT;
    }

    private function _fetch_sales_tracker_branch_facts($endorsement_ids, $date_from = null, $date_to = null) {
        $facts = [];

        if (empty($endorsement_ids)) {
            return $facts;
        }

        foreach ($this->_sales_tracker_branch_connections() as $branch_name => $db) {
            foreach (array_chunk($endorsement_ids, 500) as $chunk) {
                $binds        = $chunk;
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                $tx_binds = $binds;
                $tx_sql   = <<<EOT
SELECT t.endorsement_id,
    MIN(t.transaction_date) AS clinic_transaction_delivered,
    MAX(t.transaction_date) AS last_transaction_date
FROM `transaction` t
WHERE t.is_deleted = 0
AND t.endorsement_id IS NOT NULL
AND t.endorsement_id IN ({$placeholders})
EOT;
                $this->_apply_moving_date_clause('t.transaction_date', $date_from, $date_to, $tx_sql, $tx_binds);
                $tx_sql .= "\nGROUP BY t.endorsement_id";

                $tx_query = $db->query($tx_sql, $tx_binds);
                if ($tx_query) {
                    foreach ($tx_query->getResultArray() as $row) {
                        $id = (int) $row['endorsement_id'];
                        if (! isset($facts[$id])) {
                            $facts[$id] = $this->_empty_sales_tracker_branch_fact();
                        }
                        $facts[$id]['has_transaction'] = true;
                        if (empty($facts[$id]['clinic_transaction_delivered']) || $row['clinic_transaction_delivered'] < $facts[$id]['clinic_transaction_delivered']) {
                            $facts[$id]['clinic_transaction_delivered'] = $row['clinic_transaction_delivered'];
                        }
                        if (empty($facts[$id]['last_transaction_date']) || $row['last_transaction_date'] > $facts[$id]['last_transaction_date']) {
                            $facts[$id]['last_transaction_date'] = $row['last_transaction_date'];
                        }
                    }
                }

                $hc_binds = $binds;
                $hc_sql   = <<<EOT
SELECT t.endorsement_id, COUNT(DISTINCT b.patient_id) AS act_headcount
FROM `transaction` t
INNER JOIN booking b ON b.transaction_id = t.id AND b.is_deleted = 0
WHERE t.is_deleted = 0
AND t.endorsement_id IS NOT NULL
AND t.endorsement_id IN ({$placeholders})
EOT;
                $this->_apply_moving_date_clause('t.transaction_date', $date_from, $date_to, $hc_sql, $hc_binds);
                $hc_sql .= "\nGROUP BY t.endorsement_id";

                $hc_query = $db->query($hc_sql, $hc_binds);
                if ($hc_query) {
                    foreach ($hc_query->getResultArray() as $row) {
                        $id = (int) $row['endorsement_id'];
                        if (! isset($facts[$id])) {
                            $facts[$id] = $this->_empty_sales_tracker_branch_fact();
                        }
                        $facts[$id]['act_headcount'] += (int) ($row['act_headcount'] ?? 0);
                    }
                }

                $sales_binds = $binds;
                $sales_sql   = <<<EOT
SELECT bd.endorsement_id, COALESCE(SUM(bd.price), 0) AS act_gross_sales
FROM `transaction` t
INNER JOIN booking b ON b.transaction_id = t.id AND b.is_deleted = 0
INNER JOIN booking_details bd ON bd.booking_id = b.id AND bd.is_deleted = 0
WHERE t.is_deleted = 0
AND t.endorsement_id IS NOT NULL
AND bd.endorsement_id IN ({$placeholders})
AND bd.is_billed_externally = 1
EOT;
                $this->_apply_moving_date_clause('t.transaction_date', $date_from, $date_to, $sales_sql, $sales_binds);
                $sales_sql .= "\nGROUP BY bd.endorsement_id";

                $sales_query = $db->query($sales_sql, $sales_binds);
                if ($sales_query) {
                    foreach ($sales_query->getResultArray() as $row) {
                        $id = (int) $row['endorsement_id'];
                        if (! isset($facts[$id])) {
                            $facts[$id] = $this->_empty_sales_tracker_branch_fact();
                        }
                        $facts[$id]['act_gross_sales'] += (float) ($row['act_gross_sales'] ?? 0);
                    }
                }

                $monthly_binds = $binds;
                $monthly_sql   = <<<EOT
SELECT bd.endorsement_id,
    YEAR(t.transaction_date) AS yr,
    MONTH(t.transaction_date) AS mo,
    COUNT(DISTINCT b.patient_id) AS census,
    COALESCE(SUM(bd.price), 0) AS amount
FROM `transaction` t
INNER JOIN booking b ON b.transaction_id = t.id AND b.is_deleted = 0
INNER JOIN booking_details bd ON bd.booking_id = b.id AND bd.is_deleted = 0
WHERE t.is_deleted = 0
AND t.endorsement_id IS NOT NULL
AND bd.endorsement_id IN ({$placeholders})
AND bd.is_billed_externally = 1
EOT;
                $this->_apply_moving_date_clause('t.transaction_date', $date_from, $date_to, $monthly_sql, $monthly_binds);
                $monthly_sql .= "\nGROUP BY bd.endorsement_id, YEAR(t.transaction_date), MONTH(t.transaction_date)";

                $monthly_query = $db->query($monthly_sql, $monthly_binds);
                if ($monthly_query) {
                    foreach ($monthly_query->getResultArray() as $row) {
                        $id  = (int) $row['endorsement_id'];
                        $key = ((int) $row['yr']) . '-' . str_pad((string) (int) $row['mo'], 2, '0', STR_PAD_LEFT);
                        if (! isset($facts[$id])) {
                            $facts[$id] = $this->_empty_sales_tracker_branch_fact();
                        }
                        if (! isset($facts[$id]['monthly'][$key])) {
                            $facts[$id]['monthly'][$key] = ['census' => 0, 'amount' => 0.0];
                        }
                        $facts[$id]['monthly'][$key]['census'] += (int) ($row['census'] ?? 0);
                        $facts[$id]['monthly'][$key]['amount'] += (float) ($row['amount'] ?? 0);
                    }
                }
            }
        }

        return $facts;
    }

    private function _empty_sales_tracker_branch_fact() {
        return [
            'has_transaction'             => false,
            'clinic_transaction_delivered' => null,
            'last_transaction_date'       => null,
            'act_headcount'               => 0,
            'act_gross_sales'             => 0.0,
            'monthly'                     => [],
        ];
    }

    private function _format_sales_tracker_tab_rows($rows, $branch_facts, $mode, $date_from, $date_to) {
        $month_keys = $this->_sales_tracker_month_keys($date_from, $date_to);
        $formatted  = [];

        foreach ($rows as $row) {
            $endorsement_id = ! empty($row['endorsement_id']) ? (int) $row['endorsement_id'] : null;
            $fact           = $endorsement_id && isset($branch_facts[$endorsement_id])
                ? $branch_facts[$endorsement_id]
                : $this->_empty_sales_tracker_branch_fact();

            $est_gross_sales = (float) ($row['est_gross_sales'] ?? 0);
            $gross_margin    = (float) ($row['gross_margin'] ?? 0);
            $est_cost        = (float) ($row['est_cost'] ?? 0);
            $act_gross_sales = (float) ($fact['act_gross_sales'] ?? 0);
            $act_headcount   = (int) ($fact['act_headcount'] ?? 0);
            $act_cost        = $act_headcount > 0 && (float) ($row['package_cost'] ?? 0) > 0
                ? $act_headcount * (float) $row['package_cost']
                : 0.0;
            $act_gross_margin = $act_gross_sales - $act_cost;

            $clinic_delivered = $row['clinic_transaction_delivered'] ?? null;
            if (empty($clinic_delivered) && ! empty($fact['clinic_transaction_delivered'])) {
                $clinic_delivered = $fact['clinic_transaction_delivered'];
            }

            $status    = $this->_resolve_sales_tracker_status($row, $fact);
            $tat_status = $this->_resolve_sales_tracker_tat_status($row, $fact, $status);

            if ($mode === 'running') {
                $monthly_census = [];
                $monthly_amount = [];
                foreach ($month_keys as $label) {
                    $monthly_census[$label] = 0;
                    $monthly_amount[$label] = 0.0;
                }
                foreach ($fact['monthly'] as $month_key => $month_data) {
                    $month_num = (int) substr($month_key, -2);
                    $label     = $month_keys[$month_num] ?? null;
                    if ($label === null) {
                        continue;
                    }
                    $monthly_census[$label] += (int) ($month_data['census'] ?? 0);
                    $monthly_amount[$label] += (float) ($month_data['amount'] ?? 0);
                }

                $formatted[] = [
                    'line_id'                      => (int) $row['line_id'],
                    'line_source'                  => $row['line_source'],
                    'request_id'                   => (int) $row['request_id'],
                    'endorsement_id'               => $endorsement_id,
                    'endorsed_date'                => $row['endorsed_date'] ?? null,
                    'clinic_transaction_delivered' => $clinic_delivered,
                    'start_of_mobile_date'         => $row['start_of_mobile_date'] ?? null,
                    'end_of_mobile_date'           => $row['end_of_mobile_date'] ?? null,
                    'internal_tat'                 => $row['actual_tat'] ?? null,
                    'client_agreed_tat'            => $row['agreed_tat'] ?? null,
                    'mobile_result_delivered_date' => $row['mobile_actual_tat'] ?? null,
                    'status_of_delivery'           => $tat_status,
                    'company_name'                 => $row['company_name'] ?? null,
                    'package_name'                 => $row['package_name'] ?? null,
                    'transaction_type'             => $row['transaction_type'] ?? null,
                    'description_of_service'       => $row['description_of_service'] ?? null,
                    'status'                       => $status,
                    'est_headcount'                => (float) ($row['est_headcount'] ?? 0),
                    'package_cost'                 => (float) ($row['package_cost'] ?? 0),
                    'package_price'                => (float) ($row['package_price'] ?? 0),
                    'updated_package_price'        => (float) ($row['pre_negotiated_rate'] ?? $row['package_price'] ?? 0),
                    'est_cost'                     => $est_cost,
                    'est_gross_sales'              => $est_gross_sales,
                    'gross_margin'                 => $gross_margin,
                    'census'                       => $act_headcount,
                    'amount'                       => $act_gross_sales,
                    'compliance_in_sales'          => $est_gross_sales > 0 ? round($act_gross_sales / $est_gross_sales, 4) : 0,
                    'monthly_census'               => $monthly_census,
                    'monthly_amount'               => $monthly_amount,
                ];
                continue;
            }

            $formatted[] = [
                'line_id'                      => (int) $row['line_id'],
                'line_source'                  => $row['line_source'],
                'request_id'                   => (int) $row['request_id'],
                'endorsement_id'               => $endorsement_id,
                'inq_year'                     => (int) ($row['inq_year'] ?? 0),
                'inq_month'                    => (int) ($row['inq_month'] ?? 0),
                'inquiry_date'                 => $row['inquiry_date'] ?? null,
                'cancelled'                    => $row['cancelled'] ?? null,
                'endorsed_date'                => $row['endorsed_date'] ?? null,
                'lapse_time'                   => isset($row['lapse_time']) ? round((float) $row['lapse_time'], 2) : null,
                'clinic_transaction_delivered' => $clinic_delivered,
                'start_of_mobile_date'         => $row['start_of_mobile_date'] ?? null,
                'end_of_mobile_date'           => $row['end_of_mobile_date'] ?? null,
                'mobile_agreed_tat'            => $row['mobile_agreed_tat'] ?? null,
                'mobile_actual_tat'            => $row['mobile_actual_tat'] ?? null,
                'agreed_tat'                   => $row['agreed_tat'] ?? null,
                'actual_tat'                   => $row['actual_tat'] ?? null,
                'tat_status'                   => $tat_status,
                'status'                       => $status,
                'sae'                          => $row['sae'] ?? null,
                'clinic_management_provider'   => $row['clinic_management_provider'] ?? null,
                'hmo_provider'                 => $row['hmo_provider'] ?? null,
                'area'                         => $row['area'] ?? null,
                'classification'               => $row['classification'] ?? null,
                'industry'                     => $row['industry'] ?? null,
                'company_name'                 => $row['company_name'] ?? null,
                'charge_to'                    => $row['charge_to'] ?? null,
                'preferred_branch'             => $row['preferred_branch'] ?? null,
                'transaction_type'             => $row['transaction_type'] ?? null,
                'description_of_service'       => $row['description_of_service'] ?? null,
                'package_name'                 => $row['package_name'] ?? null,
                'est_headcount'                => (float) ($row['est_headcount'] ?? 0),
                'package_cost'                 => (float) ($row['package_cost'] ?? 0),
                'package_price'                => (float) ($row['package_price'] ?? 0),
                'est_cost'                     => $est_cost,
                'est_gross_sales'              => $est_gross_sales,
                'gross_margin'                 => $gross_margin,
                'gross_margin_pct'             => $est_gross_sales > 0 ? round($gross_margin / $est_gross_sales, 4) : 0,
                'average_check'                => (float) ($row['average_check'] ?? 0),
                'act_headcount'                => $act_headcount,
                'cost'                         => $act_cost,
                'gross_sales'                  => $act_gross_sales,
                'gross_margin_actual'          => $act_gross_margin,
                'gross_margin_pct_actual'      => $act_gross_sales > 0 ? round($act_gross_margin / $act_gross_sales, 4) : 0,
                'average_check_actual'         => $act_headcount > 0 ? round($act_gross_sales / $act_headcount, 2) : 0,
                'compliance_php'               => $act_gross_sales,
                'compliance_pct'               => $est_gross_sales > 0 ? round($act_gross_sales / $est_gross_sales, 4) : 0,
                'remarks'                      => $row['remarks'] ?? null,
            ];
        }

        return $formatted;
    }

    private function _sales_tracker_month_keys($date_from, $date_to) {
        return [
            1  => 'jan',
            2  => 'feb',
            3  => 'mar',
            4  => 'apr',
            5  => 'may',
            6  => 'jun',
            7  => 'jul',
            8  => 'aug',
            9  => 'sep',
            10 => 'oct',
            11 => 'nov',
            12 => 'dec',
        ];
    }

    private function _resolve_sales_tracker_status($row, $fact) {
        if (in_array(($row['request_status'] ?? null), ['disapproved', 'returned'], true)
            || in_array(($row['endorsement_status'] ?? null), ['disapproved', 'returned'], true)) {
            return 'Cancelled';
        }

        if (! empty($fact['has_transaction'])) {
            return 'Delivered';
        }

        if (! empty($row['endorsed_date'])) {
            return 'Endorsed';
        }

        return 'Inquiry';
    }

    private function _resolve_sales_tracker_tat_status($row, $fact, $status) {
        if ($status === 'Cancelled') {
            return null;
        }

        $agreed = $this->_parse_sales_tracker_date($row['agreed_tat'] ?? null);
        $actual = $this->_parse_sales_tracker_date($row['actual_tat'] ?? null);

        if ($agreed && $actual) {
            return $actual <= $agreed ? 'MET' : 'UNMET';
        }

        if ($status === 'Delivered' && ! empty($fact['last_transaction_date']) && $agreed) {
            $delivered = $this->_parse_sales_tracker_date($fact['last_transaction_date']);

            return $delivered && $delivered <= $agreed ? 'MET' : 'UNMET';
        }

        return null;
    }

    private function _parse_sales_tracker_date($value) {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp ? $timestamp : null;
    }
}

?>