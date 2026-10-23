<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Company extends MYT_Model
{
    protected $table = 'company';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'name',
        'description',
        'address',
        'street',
        'telephone',
        'email',
        'tin_no',
        'mailing_address',
        'website',
        'point_of_contact_name',
        'designation',
        'contact_email',
        'contact_phone',
        'bank_id',
        'bank_name',
        'account_number',
        'branch',
        'account_type',
        'country',
        'swift_bic_code',
        'additional_info',
        'for_releasing_name',
        'for_releasing_designation',
        'for_releasing_email',
        'for_releasing_phone',
        'autorized_billing_name',
        'autorized_billing_designation',
        'autorized_billing_email',
        'autorized_billing_phone',
        'province',
        'city_municipality',
        'barangay',
        'business_type',
        'industry',
        'estimated_employees',
        'account_credit_limit',
        'company_no',
        'registered_date',
        'account_status',
        'accreditation_status',
        'account_terms',
        'remarks',
        'type',
        'is_hmo',
        'industry_category',
        'region',
        'bank_country',
        'consumable_amount_per_employee',
        'discount_amount',
        'discount_percentage',
        'wallet_validity_date_from',
        'wallet_validity_date_to',
    ];

    /** Deactivate inactive-year accounts and repair inactive-account accreditation. */
    public function annualStatus($year = null, bool $dryRun = false): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        $year = $year ?? ((int) $now->format('Y') - 1);
        if (!is_scalar($year) || !preg_match('/^[0-9]{4}$/', (string) $year)
            || (int) $year < 1900 || (int) $year >= (int) $now->format('Y')) {
            throw new \InvalidArgumentException('year must be a completed calendar year (YYYY).');
        }
        $year = (int) $year;
        $from = sprintf('%04d-01-01', $year);
        $until = sprintf('%04d-01-01', $year + 1);
        $where = <<<EOT
company.is_deleted = 0
AND (company.account_status <> 'inactive' OR company.accreditation_status <> 'inactive')
AND (company.account_status = 'inactive' OR NOT EXISTS (
    SELECT 1 FROM request r WHERE r.company_id = company.id
    AND r.is_deleted = 0 AND r.added_on >= ? AND r.added_on < ?
))
EOT;
        $summary = ['year' => $year, 'date_from' => $from, 'date_to_exclusive' => $until,
            'dry_run' => $dryRun, 'affected_rows' => 0];
        if ($dryRun) {
            $result = $this->db->query('SELECT COUNT(*) AS matched FROM company WHERE ' . $where, [$from, $until]);
            if (!$result) {
                throw new \RuntimeException('Unable to preview annual company statuses.');
            }
            return $summary + ['matched_rows' => (int) $result->getRow()->matched];
        }
        if (!$this->db->transBegin()) {
            throw new \RuntimeException('Unable to begin company status transaction.');
        }
        try {
            $sql = <<<EOT
UPDATE company SET account_status = 'inactive', accreditation_status = 'inactive',
    updated_by = NULL, updated_on = ?
WHERE {$where}
EOT;
            if (!$this->db->query($sql, [$now->format('Y-m-d H:i:s'), $from, $until])) {
                throw new \RuntimeException('Unable to update annual company statuses.');
            }
            $summary['affected_rows'] = $this->db->affectedRows();
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new \RuntimeException('Unable to commit annual company statuses.');
            }
            return $summary;
        } catch (\Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    public function get_details($company_id = null, $type = null, $business_type = null, $accreditation_status = null, $account_status = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT company.*
FROM company
WHERE company.is_deleted = 0
EOT;

        if ($company_id) {
            $sql .= <<<EOT

AND company.id = ?
EOT;
            $binds[] = $company_id;
        }

        if ($type) {
            $sql .= <<<EOT

AND company.type = ?
EOT;
            $binds[] = $type;
        }

        if ($business_type) {
            $sql .= <<<EOT

AND company.business_type = ?
EOT;
            $binds[] = $business_type;
        }

        if ($accreditation_status) {
            $sql .= <<<EOT

AND company.accreditation_status = ?
EOT;
            $binds[] = $accreditation_status;
        }

        if ($account_status) {
            $sql .= <<<EOT

AND company.account_status = ?
EOT;
            $binds[] = $account_status;
        }

        if ($term) {
            $sql .= <<<EOT

AND (company.name LIKE ? OR company.description LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_existing_by_year($year = null) {
        $binds = [];
        $sql = <<<EOT
SELECT company.id
FROM company
WHERE company.is_deleted = 0
EOT;

        if ($year) {
            $sql .= <<<EOT

AND YEAR(company.added_on) = ?
EOT;
            $binds[] = $year;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_bank($bank_id = null) {
        $binds = [];
        $sql = <<<EOT
SELECT bank.*
FROM bank
WHERE bank.is_deleted = 0
EOT;

        if ($bank_id) {
            $sql .= <<<EOT

AND bank.id = ?
EOT;
            $binds[] = $bank_id;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_customer($customer_id = null, $page = null, $data_size = null, $type = null) {
        $binds = [];
        $sql = <<<EOT
SELECT company.id AS charge_to, company.name AS customer_name
FROM company
WHERE company.is_deleted = 0
EOT;

        if ($customer_id) {
            $sql .= <<<EOT

AND company.id = ?
EOT;
            $binds[] = $customer_id;
        }

        $yakap_id = (int) Yakap_billing::COMPANY_ID;
        $not_philhealth = "(company.classification IS NULL OR company.classification <> 'philhealth')";
        $is_yakap = "(company.id = {$yakap_id} OR (company.type = 'hmo' AND company.classification = 'philhealth'))";

        if ($type === 'hmo') {
            $sql .= <<<EOT

AND company.type = 'hmo'
AND {$not_philhealth}
AND company.id <> {$yakap_id}
EOT;
        } elseif ($type === 'corporate') {
            $sql .= <<<EOT

AND (company.type IS NULL OR company.type <> 'hmo')
AND company.id <> {$yakap_id}
EOT;
        } elseif ($type === 'yakap_ar') {
            $sql .= <<<EOT

AND {$is_yakap}
EOT;
        } elseif ($type === 'ar_at_risk') {
            $sql .= <<<EOT

AND NOT {$is_yakap}
EOT;
        }

        //group by charge_to to get unique customers
        $sql .= " GROUP BY company.id";

        $sql .= sql_limit_clause($page, $data_size);

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_customer_accounts($customer_account_ids = null) {
        $binds = [];
        $sql = <<<EOT
SELECT company.id AS company_id, company.name AS company_name
FROM company
WHERE company.is_deleted = 0
EOT;

        if ($customer_account_ids) {

            // Convert string to array
            $ids = explode(',', $customer_account_ids);

            // Create ?,?,? placeholders dynamically
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $sql .= " AND company.id IN ($placeholders)";

            $binds = $ids;
        }

        $query = $this->db->query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    public function get_by_ids(array $company_ids) {
        $company_ids = array_values(array_filter(array_unique(array_map('intval', $company_ids))));
        if (empty($company_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($company_ids), '?'));
        $sql          = <<<EOT
SELECT company.id, company.name, company.is_hmo, company.type, company.account_type, company.classification
FROM company
WHERE company.is_deleted = 0
AND company.id IN ({$placeholders})
EOT;

        $query = $this->db->query($sql, $company_ids);

        return $query ? $query->getResultArray() : [];
    }
}
