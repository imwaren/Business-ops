<?php

namespace App\Core;
namespace App\Models;

use App\Core\MYT_Model;

class Direct_payment extends MYT_Model
{
    use \App\Core\Shared_fund_scope;

    protected $table = 'direct_payment';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'requested_by_id',
        'department_id',
        'invoice_no',
        'supplier_id',
        'vat_type',
        'amount',
        'branch_id',
        'cost_center_id',
        'pos_no',
        'date',
        'remarks',
        'due_date',
        'duration_from',
        'duration_to',
    ];

    public function get_details($direct_payment_id = null, $department_id = null, $status = null, $date_from = null, $date_to = null, $term = null, $page = null, $data_size = null) {
        $binds = [];
        $sql = <<<EOT
SELECT direct_payment.*, 
    department.name AS department_name,
    supplier.name AS supplier_name
FROM direct_payment
LEFT JOIN {MASTER_DB}.department AS department ON department.id = direct_payment.department_id
LEFT JOIN {MASTER_DB}.supplier AS supplier ON supplier.id = direct_payment.supplier_id AND supplier.is_deleted = 0
LEFT JOIN {MASTER_DB}.user AS requested_by ON requested_by.id = direct_payment.requested_by_id
WHERE direct_payment.is_deleted = 0
EOT;

        if ($direct_payment_id) {
            $sql .= <<<EOT

AND direct_payment.id = ?
EOT;
            $binds[] = $direct_payment_id;
        }

        if ($department_id) {
            $sql .= <<<EOT

AND direct_payment.department_id = ?
EOT;
            $binds[] = $department_id;
        }

        if ($status) {
            $sql .= <<<EOT

AND direct_payment.status = ?
EOT;
            $binds[] = $status;
        }

        if ($date_from && $date_to) {
            $sql .= <<<EOT

AND direct_payment.date BETWEEN ? AND ? 
EOT;
            $binds[] = $date_from;
            $binds[] = $date_to;
        }

        if ($term) {
            $sql .= <<<EOT

AND (CONCAT_WS(' ', requested_by.first_name, requested_by.middle_name, requested_by.last_name) LIKE ?
    OR supplier.name LIKE ? OR direct_payment.invoice_no LIKE ? OR direct_payment.remarks LIKE ?)
EOT;
            $binds[] = "%$term%";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
            $binds[] = "%$term%";
        }

        $sql .= " ORDER BY direct_payment.due_date DESC";

        $sql .= sql_limit_clause($page, $data_size);

        $sql = $this->_resolve_cross_db_sql($sql);
        $query = $this->scoped_query($sql, $binds);
        return $query ? $query->getResultArray() : false;
    }

    /**
     * Direct payments with prepaid duration and a paid (closed) APV.
     */
    public function get_prepaid_for_amortization()
    {
        $sql = <<<EOT
SELECT direct_payment.*, apv.id AS accounts_payable_voucher_id
FROM direct_payment
INNER JOIN accounts_payable_voucher apv
    ON apv.type = 'direct_payment' AND apv.type_id = direct_payment.id
    AND apv.is_deleted = 0 AND apv.status = 'closed'
WHERE direct_payment.is_deleted = 0
AND direct_payment.duration_from IS NOT NULL
AND direct_payment.duration_to IS NOT NULL
AND EXISTS (
    SELECT 1 FROM accounts_payable_voucher_amortization_entry allocation
    WHERE allocation.accounts_payable_voucher_id = apv.id AND allocation.is_deleted = 0
)
EOT;
        if ($this->shared_cost_centers !== null) {
            $sql = str_replace("AND apv.is_deleted = 0", "AND apv.cost_center_id=direct_payment.cost_center_id AND apv.is_deleted = 0", $sql);
        }
        $query = $this->scoped_query($sql);
        if (!$query) {
            return [];
        }

        $payments = $query->getResultArray();
        $allocation_model = new Accounts_payable_voucher_amortization_entry($this->db);
        if ($this->shared_cost_centers !== null) { $allocation_model->with_shared_cost_centers($this->shared_cost_centers); }
        $allocations = $allocation_model->get_for_vouchers(array_column($payments, 'accounts_payable_voucher_id'));
        foreach ($payments as &$payment) {
            $payment['amortization_entries'] = $allocations[(int) $payment['accounts_payable_voucher_id']] ?? [];
        }
        unset($payment);
        return $payments;
    }

    /**
     * Add months to Y-m-d, clamping to the last day of the target month.
     */
    public function add_month_clamped($date, $months = 1)
    {
        $dt = \DateTime::createFromFormat('!Y-m-d', (string) $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            return null;
        }

        $day = (int) $dt->format('d');
        $year = (int) $dt->format('Y');
        $month = (int) $dt->format('m') + (int) $months;
        while ($month > 12) {
            $month -= 12;
            $year++;
        }
        while ($month < 1) {
            $month += 12;
            $year--;
        }

        $lastDay = (int) (new \DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $dt->setDate($year, $month, min($day, $lastDay));

        return $dt->format('Y-m-d');
    }

    /**
     * Inclusive monthly dates from duration_from through duration_to.
     */
    public function monthly_dates($from, $to)
    {
        if (!$from || !$to) {
            return [];
        }

        $dates = [];
        $i = 0;
        while ($i <= 1200) {
            $current = $i === 0 ? $from : $this->add_month_clamped($from, $i);
            if ($current === null || $current > $to) {
                break;
            }
            $dates[] = $current;
            $i++;
        }

        return $dates;
    }

    /**
     * Split amount across n periods; remainder ten-thousandths go to the last period.
     */
    public function split_amount($amount, $n)
    {
        $n = (int) $n;
        if ($n < 1) {
            return [];
        }

        $cents = \App\Libraries\Apv_accounting::units($amount);
        $base = intdiv($cents, $n);
        $amounts = [];
        for ($i = 0; $i < $n; $i++) {
            $periodCents = ($i === $n - 1) ? ($cents - ($base * ($n - 1))) : $base;
            $amounts[] = \App\Libraries\Apv_accounting::decimal($periodCents);
        }

        return $amounts;
    }
}

?>
