<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Endorsement extends MYT_Model
{
    protected $table = 'endorsement';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'request_id',
        'costing_id',
        'quotation_id',
        'branch_id',
        'mobile_branch_id',
        'mobile_van_ids',
        'type',
        'endorsement_date',
        'transaction_type',
        'company_id',
        'address',
        'preferred_branch_id',
        'mode_of_releasing',
        'results_tat',
        'significant_findings',
        'email',
        'time',
        'head_count',
        'compliance_report',
        'completion_period',
        'no_show_valid_until',
        'payment_terms',
        'date_from',
        'date_to',
        'contract_date_from',
        'contract_date_to',
        'ape_results_tat',
        'requirement',
        'note',
        'remarks',
        'all_services_discount_percentage',
        'mandatory_tests',
        'optional_tests',
        'registration_cut_off',
        'submission_cut_off',
        'package_name',
        'with_masterlist',
        'with_walk_in',
        'mobile_site',
        'effectivity_date_from',
        'effectivity_date_to',
        'service_duration_date_from',
        'service_duration_date_to',
        'is_pending_revision',
        'kind_of_break',
        'mobile_activity_date_from',
        'mobile_activity_date_to',
        'clinic_activity_date_from',
        'clinic_activity_date_to',
        'completion_date',
        'completion_date_from',
        'completion_date_to',
        'demographics_report_date',
        'guaranteed_headcount',
        'cost_total',
        'estimated_margin_total',
        'estimated_margin_amount_total',
        'age_for_ecg',
        'age_for_papsmear',
        'email_result',
        'evaluation_data_source',
        'evaluation',
        'estimated_patient_per_shift',
        'contract_head_count',
    ];

    /**
     * Build shared FROM/WHERE for get_details() and get_count().
     * LIMIT is excluded so callers can append it.
     *
     * @param bool $for_count When true, select only endorsement.id (skip display columns).
     */
    private function _build_endorsement_sql($endorsement_id, $status, $company_id, $branch_id, $is_pending_revision, $term, array &$binds, bool $for_count = false, $is_force_evaluate = null, $request_added_by = null, ?string $transaction_type = null): string
    {
        $binds = [];

        if ($for_count) {
            $sql = <<<EOT
SELECT endorsement.id
FROM endorsement
LEFT JOIN company ON company.id = endorsement.company_id AND company.is_deleted = 0
LEFT JOIN branch ON branch.id = endorsement.preferred_branch_id AND branch.is_deleted = 0
LEFT JOIN branch AS mobile_branch ON mobile_branch.id = endorsement.mobile_branch_id AND mobile_branch.is_deleted = 0
WHERE endorsement.is_deleted = 0
EOT;
        } else {
            $sql = <<<EOT
SELECT endorsement.*,
    company.name AS company_name,
    branch.name AS preferred_branch_name,
    mobile_branch.name AS mobile_branch_name,
    (
        SELECT GROUP_CONCAT(mobile_van.name SEPARATOR ', ') 
        FROM mobile_van 
        WHERE FIND_IN_SET(mobile_van.id, endorsement.mobile_van_ids)
    ) AS mobile_van_names
FROM endorsement
LEFT JOIN company ON company.id = endorsement.company_id AND company.is_deleted = 0
LEFT JOIN branch ON branch.id = endorsement.preferred_branch_id AND branch.is_deleted = 0
LEFT JOIN branch AS mobile_branch ON mobile_branch.id = endorsement.mobile_branch_id AND mobile_branch.is_deleted = 0
WHERE endorsement.is_deleted = 0
EOT;
        }

        if ($endorsement_id) {
            $sql .= "\nAND endorsement.id = ?";
            $binds[] = $endorsement_id;
        }

        if ($status) {
            // with_masterlist = 1 == closed
            // with_masterlist = 0 == open
            if ($status == 'patient_upload') {
                $sql .= <<<EOT

AND endorsement.with_masterlist = 1
AND endorsement.status = 'approved'
AND (
    endorsement.contract_date_from <= CURDATE()
    OR endorsement.contract_date_from > CURDATE()
)
EOT;
            } else {
                $sql .= "\nAND endorsement.status = ?";
                $binds[] = $status;
            }
        }

        if ($company_id) {
            $sql .= "\nAND endorsement.company_id = ?";
            $binds[] = $company_id;
        }

        if ($branch_id) {
            $branchIds = explode(',', $branch_id);

            $conditions = [];
            foreach ($branchIds as $id) {
                $conditions[] = 'FIND_IN_SET(?, endorsement.branch_id)';
                $binds[] = trim($id);
            }

            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")\n";
        }

        if ($is_pending_revision !== null) {
            $sql .= "\nAND endorsement.is_pending_revision = ?";
            $binds[] = $is_pending_revision;
        }

        if ($is_force_evaluate !== null && $is_force_evaluate !== '') {
            $sql .= "\nAND endorsement.is_force_evaluate = ?";
            $binds[] = (int) $is_force_evaluate;
        }

        if ($term) {
            $sql .= "\nAND (endorsement.remarks LIKE ? OR company.name LIKE ?)";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        if ($request_added_by !== null) {
            $sql .= <<<EOT

AND EXISTS (
    SELECT 1 FROM request AS owned_request
    WHERE owned_request.id = endorsement.request_id
    AND owned_request.added_by = ?
)
EOT;
            $binds[] = $request_added_by;
        }

        $transaction_types = $transaction_type === null ? [] : array_values(array_unique(array_filter(
            array_map('trim', explode(',', $transaction_type)),
            static fn(string $value): bool => $value !== ''
        )));
        if ($transaction_types) {
            $conditions = [];
            foreach ($transaction_types as $value) {
                $conditions[] = 'FIND_IN_SET(?, endorsement.transaction_type) > 0';
                $binds[] = $value;
            }
            $sql .= "\nAND (" . implode(' OR ', $conditions) . ")";
        }

        $sql .= "\nORDER BY endorsement.added_on DESC";

        return $sql;
    }

    public function get_details($endorsement_id = null, $status = null, $company_id = null, $branch_id = null, $is_pending_revision = null, $term = null, $page = null, $data_size = null, $is_force_evaluate = null, $request_added_by = null, ?string $transaction_type = null) {
        $binds = [];
        $sql = $this->_build_endorsement_sql($endorsement_id, $status, $company_id, $branch_id, $is_pending_revision, $term, $binds, false, $is_force_evaluate, $request_added_by, $transaction_type);

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_count($endorsement_id = null, $status = null, $company_id = null, $branch_id = null, $is_pending_revision = null, $term = null, $is_force_evaluate = null, $request_added_by = null, ?string $transaction_type = null): int
    {
        $binds = [];
        $base_sql = $this->_build_endorsement_sql($endorsement_id, $status, $company_id, $branch_id, $is_pending_revision, $term, $binds, true, $is_force_evaluate, $request_added_by, $transaction_type);
        $sql = "SELECT COUNT(*) AS total FROM ($base_sql) AS endorsement_count";

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    public function get_for_sales($request_id = null, $status = null) {
        $binds = [];
        $sql = <<<EOT
SELECT
    GROUP_CONCAT(endorsement.id ORDER BY endorsement.id SEPARATOR ', ') AS id,
    GROUP_CONCAT(endorsement.request_id ORDER BY endorsement.id SEPARATOR ', ') AS request_id,
    GROUP_CONCAT(
        CONCAT(
            endorsement.mobile_activity_date_from, '_', 
            endorsement.mobile_activity_date_to
        )
        ORDER BY endorsement.id SEPARATOR ', '
    ) AS mobile_activity_date,
    GROUP_CONCAT(
        CONCAT(
            endorsement.clinic_activity_date_from, '_', 
            endorsement.clinic_activity_date_to
        )
        ORDER BY endorsement.id SEPARATOR ', '
    ) AS clinic_activity_date,
    SUM(endorsement.head_count) AS cost_total,
    SUM(endorsement.estimated_margin_total) AS estimated_margin_total,
    SUM(endorsement.estimated_margin_amount_total) AS estimated_margin_amount_total
FROM endorsement
WHERE endorsement.is_deleted = 0
EOT;

        if ($request_id) {

            // Convert string to array
            $ids = explode(',', $request_id);

            // Create ?,?,? placeholders dynamically
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $sql .= " AND endorsement.request_id IN ($placeholders)";

            $binds = $ids;
        }

        if ($status) {
            $sql .= <<<EOT

AND endorsement.status = ?
EOT;
            $binds[] = $status;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_sales_tracker($endorsement_id = null, $sae_in_charge_id = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT endorsement.id AS endorsement_id,
    endorsement.company_id,
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
    endorsement.head_count AS cost_total,
    endorsement.estimated_margin_total,
    endorsement.estimated_margin_amount_total,
    endorsement_service.estimated_margin,
    endorsement_service.estimated_margin_amount_total,
    endorsement_service.estimated_revenue,
    endorsement_service.head_count,
    endorsement_service.charge_to,
    endorsement_service.charge_to_ids,
    (SELECT GROUP_CONCAT(company.name SEPARATOR ', ')
     FROM company
     WHERE FIND_IN_SET(company.id, endorsement_service.charge_to_ids)
       AND company.is_deleted = 0) AS charge_to_names,
    request.added_on AS inquiry_date,
    endorsement.added_on AS booking_date
FROM endorsement_service
LEFT JOIN endorsement ON endorsement.id = endorsement_service.endorsement_id AND endorsement.is_deleted = 0
LEFT JOIN company ON company.id = endorsement.company_id AND company.is_deleted = 0
LEFT JOIN request ON request.id = endorsement.request_id AND request.is_deleted = 0
WHERE request.is_deleted = 0
EOT;

        if ($endorsement_id) {
            $sql .= <<<EOT

AND endorsement.id = ?
EOT;
            $binds[] = $endorsement_id;
        }

        if ($sae_in_charge_id) {
            $sql .= <<<EOT

AND request.sae_in_charge_id = ?
EOT;
            $binds[] = $sae_in_charge_id;
        }

        $sql .= " GROUP BY endorsement_service.id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Endorsements encoded without a real request / costing / quotation chain.
     */
    private function _missing_request_costing_quotation_sql($endorsement_id, array &$binds, bool $for_count = false): string
    {
        $binds = [];
        $sql = $for_count
            ? "SELECT endorsement.id\nFROM endorsement\nWHERE endorsement.is_deleted = 0"
            : "SELECT endorsement.*\nFROM endorsement\nWHERE endorsement.is_deleted = 0";

        $sql .= <<<EOT

AND (
    IFNULL(endorsement.request_id, 0) = 0
    OR IFNULL(endorsement.costing_id, 0) = 0
    OR IFNULL(endorsement.quotation_id, 0) = 0
)
EOT;

        if ($endorsement_id) {
            $sql .= "\nAND endorsement.id = ?";
            $binds[] = $endorsement_id;
        }

        return $sql;
    }

    public function get_missing_request_costing_quotation($limit = 10, $endorsement_id = null)
    {
        $binds = [];
        $sql = $this->_missing_request_costing_quotation_sql($endorsement_id, $binds, false);
        $sql .= "\nORDER BY endorsement.id ASC";

        $limit = (int) $limit;
        if ($limit > 0) {
            $sql .= "\nLIMIT " . $limit;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function count_missing_request_costing_quotation($endorsement_id = null): int
    {
        $binds = [];
        $base_sql = $this->_missing_request_costing_quotation_sql($endorsement_id, $binds, true);
        $sql = "SELECT COUNT(*) AS total FROM ($base_sql) AS missing_endorsement_count";

        $query = $this->db->query($sql, $binds);
        if (!$query) {
            return 0;
        }
        $row = $query->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    public function get_endorsement_for_billing($endorsement_ids = null) {
        $key = $this->encryption_key;
        $binds = [];
        $sql = <<<EOT
SELECT endorsement.*
FROM endorsement
WHERE endorsement.is_deleted = 0 
EOT;

        if ($endorsement_ids) {
            $ids = array_values(array_filter((array) $endorsement_ids, static function ($id) {
                return $id !== null && $id !== '';
            }));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql .= "\nAND endorsement.id IN ($placeholders)";
                foreach ($ids as $id) {
                    $binds[] = $id;
                }
            }
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResult() : false;
    }
}

?>
