<?php

namespace App\Models;

use App\Core\MYT_Model;

class Relief extends MYT_Model
{
    protected $table      = 'billing';
    protected $primaryKey = 'id';

    /**
     * APV payments in the period, one row per vendor TIN.
     *
     * @return array{size:int,data:array}
     */
    public function get_purchases(string $date_from, string $date_to, $branch_id = null, $term = null): array
    {
        $grouped = [];

        foreach ($this->_load_purchase_payments($date_from, $date_to, $branch_id) as $row) {
            $this->_accumulate_purchase($grouped, $row);
        }

        $data = array_values($grouped);
        $data = $this->_finalize_purchase_rows($data);
        $data = $this->_filter_term($data, $term, ['Vendor_TIN', 'companyName']);

        usort($data, static function ($a, $b) {
            return strcasecmp((string) ($a['companyName'] ?? ''), (string) ($b['companyName'] ?? ''));
        });

        return ['size' => count($data), 'data' => $data];
    }

    /**
     * CSR payments plus billing collections, one row per client TIN.
     *
     * @return array{size:int,data:array}
     */
    public function get_sales(string $date_from, string $date_to, $branch_id = null, $term = null): array
    {
        $grouped = [];

        foreach ($this->_load_sales_collections($date_from, $date_to, $branch_id) as $row) {
            $this->_accumulate_sale($grouped, $row);
        }

        $data = array_values($grouped);
        $data = $this->_finalize_sales_rows($data);
        $data = $this->_filter_term($data, $term, ['client_TIN', 'companyName']);

        usort($data, static function ($a, $b) {
            return strcasecmp((string) ($a['companyName'] ?? ''), (string) ($b['companyName'] ?? ''));
        });

        return ['size' => count($data), 'data' => $data];
    }

    /**
     * SAWT (sawt_1702Q): WHT on HMO/CORP/YAKAP collections. Same dataset as relief sales.
     *
     * @return array{size:int,data:array}
     */
    public function get_sawt(string $date_from, string $date_to, $branch_id = null, $term = null): array
    {
        $grouped = [];

        foreach ($this->_load_sales_collections($date_from, $date_to, $branch_id) as $row) {
            $wht = (float) ($row['withholding_tax'] ?? 0);
            if ($wht <= 0) {
                continue;
            }
            if (! $this->_is_receivable_company($row['company_type'] ?? null, $row['is_hmo'] ?? 0)) {
                continue;
            }

            $tin = $this->_normalize_tin($row['tin_no'] ?? '');
            if ($tin === '') {
                continue;
            }

            $isIndividual = $this->_is_individual($row['business_type'] ?? ($row['company_type'] ?? null));
            $rate         = $this->_ewt_rate_from_amounts($row['withholding_percent'] ?? 0, $wht, $this->_sales_gross($row));
            $atc          = $this->_atc_from_rate($rate, $isIndividual);
            $income       = $this->_income_payment($wht, $rate, $this->_sales_gross($row));

            $this->_accumulate_alphalist($grouped, $tin, $atc, [
                'name'          => $row['company_name'] ?? '',
                'type'          => $row['business_type'] ?? ($row['company_type'] ?? null),
                'payment_date'  => $row['payment_date'] ?? $date_from,
                'income'        => $income,
                'rate'          => $rate,
                'tax'           => $wht,
            ]);
        }

        $data = $this->_finalize_alphalist_rows($grouped, $date_from);
        $data = $this->_filter_term($data, $term, ['Vendor_TIN', 'companyName', 'surName']);

        return ['size' => count($data), 'data' => $data];
    }

    /**
     * 1601-EQ Schedule 1 / 1604-E Schedule 3: WHT on APV supplier payments.
     * One row per supplier TIN + ATC + invoice.
     *
     * @return array{size:int,data:array}
     */
    public function get_1601eq(string $date_from, string $date_to, $branch_id = null, $term = null): array
    {
        $grouped = [];

        foreach ($this->_load_purchase_payments($date_from, $date_to, $branch_id) as $row) {
            $wht = (float) ($row['scaled_wht'] ?? 0);
            if ($wht <= 0) {
                continue;
            }

            $tin = $this->_normalize_tin($row['tin'] ?? '');
            if ($tin === '') {
                continue;
            }

            $income       = (float) ($row['scaled_subtotal'] ?? 0);
            $isIndividual = $this->_is_individual($row['supplier_type'] ?? null);
            $rate         = $this->_ewt_rate_from_amounts(0, $wht, $income);
            $rate         = $this->_nearest_mapped_ewt_rate($rate);
            $atc          = $this->_atc_from_rate($rate, $isIndividual);

            $this->_accumulate_supplier_alphalist($grouped, $tin, $atc, (string) ($row['invoice_reference'] ?? ''), [
                'registered_name' => $row['registered_name'] ?? ($row['supplier_name'] ?? ''),
                'payment_date'    => $row['payment_date'] ?? $date_from,
                'income'          => $income,
                'rate'            => $rate,
                'tax'             => $wht,
            ]);
        }

        $data = $this->_finalize_supplier_alphalist_rows($grouped, $date_from);
        $data = $this->_filter_term($data, $term, ['Vendor_TIN', 'Registered Name', 'Invoice #']);

        return ['size' => count($data), 'data' => $data];
    }

    /**
     * @return array<int,array{group:string,name:string}>
     */
    private function _branch_targets($branch_id): array
    {
        $map = [
            1 => ['group' => 'mobile', 'name' => 'Mobile'],
            2 => ['group' => 'oakridge', 'name' => 'Oakridge'],
            3 => ['group' => 'mactan', 'name' => 'Mactan'],
            4 => ['group' => 'consolacion', 'name' => 'Consolacion'],
            7 => ['group' => 'mantlewood', 'name' => 'Mantlewood'],
        ];

        if ($branch_id !== null && $branch_id !== '') {
            $branch_id = (int) $branch_id;

            return isset($map[$branch_id]) ? [$branch_id => $map[$branch_id]] : [];
        }

        return $map;
    }

    /**
     * CSR payment (unbilled) plus master billing_payment collections.
     *
     * @return list<array>
     */
    private function _load_sales_collections(string $date_from, string $date_to, $branch_id = null): array
    {
        $rows = $this->_query_billing_payments($date_from, $date_to, $branch_id);

        foreach ($this->_branch_targets($branch_id) as $id => $info) {
            $db = \Config\Database::connect($info['group']);
            foreach ($this->_query_csr_payments($db, $date_from, $date_to) as $row) {
                $row['source']      = 'csr';
                $row['branch_id']   = $id;
                $row['branch_name'] = $info['name'];
                $rows[]             = $row;
            }
        }

        return $rows;
    }

    private function _query_csr_payments($db, string $date_from, string $date_to): array
    {
        $sql = <<<EOT
SELECT
    payment.id,
    payment.payment_date,
    payment.charge_to,
    payment.transaction_id,
    payment.subtotal,
    payment.grand_total,
    payment.withholding_tax,
    payment.withholding_percent,
    company.id AS company_id,
    company.name AS company_name,
    company.address,
    company.street,
    company.barangay,
    company.city_municipality,
    company.province,
    company.tin_no,
    company.business_type,
    company.type AS company_type,
    company.is_hmo
FROM payment
INNER JOIN `transaction` AS transc
    ON transc.id = payment.transaction_id
   AND transc.is_deleted = 0
LEFT JOIN {MASTER_DB}.company AS company
    ON company.id = payment.charge_to
   AND company.is_deleted = 0
WHERE payment.is_deleted = 0
  AND DATE(payment.payment_date) BETWEEN ? AND ?
  AND IFNULL(transc.is_billed, 0) <> 1
  AND (transc.billing_id IS NULL OR transc.billing_id = 0)
  AND company.tin_no IS NOT NULL
  AND TRIM(company.tin_no) <> ''
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, [$date_from, $date_to]);

        return $query ? $query->getResultArray() : [];
    }

    private function _query_billing_payments(string $date_from, string $date_to, $branch_id = null): array
    {
        $master = \Config\Database::connect();
        $binds  = [$date_from, $date_to];
        $sql    = <<<EOT
SELECT
    billing_payment.id,
    COALESCE(billing_payment.paid_date, billing_payment.date) AS payment_date,
    billing_payment.charge_to,
    billing_payment.grand_total,
    billing_payment.wht_amount AS withholding_tax,
    billing_payment.wht_percent AS withholding_percent,
    company.id AS company_id,
    company.name AS company_name,
    company.address,
    company.street,
    company.barangay,
    company.city_municipality,
    company.province,
    company.tin_no,
    company.business_type,
    company.type AS company_type,
    company.is_hmo,
    (
        SELECT billing_entry.branch_id
        FROM billing_payment_entry
        INNER JOIN billing_entry
            ON billing_entry.id = billing_payment_entry.billing_entry_id
           AND billing_entry.is_deleted = 0
        WHERE billing_payment_entry.billing_payment_id = billing_payment.id
          AND billing_payment_entry.is_deleted = 0
        LIMIT 1
    ) AS branch_id
FROM billing_payment
LEFT JOIN company
    ON company.id = billing_payment.charge_to
   AND company.is_deleted = 0
WHERE billing_payment.is_deleted = 0
  AND DATE(COALESCE(billing_payment.paid_date, billing_payment.date)) BETWEEN ? AND ?
  AND company.tin_no IS NOT NULL
  AND TRIM(company.tin_no) <> ''
EOT;

        if ($branch_id !== null && $branch_id !== '') {
            $sql .= <<<EOT

  AND EXISTS (
        SELECT 1
        FROM billing_payment_entry
        INNER JOIN billing_entry
            ON billing_entry.id = billing_payment_entry.billing_entry_id
           AND billing_entry.is_deleted = 0
        WHERE billing_payment_entry.billing_payment_id = billing_payment.id
          AND billing_payment_entry.is_deleted = 0
          AND billing_entry.branch_id = ?
    )
EOT;
            $binds[] = (int) $branch_id;
        }

        $query       = $master->query($sql, $binds);
        $rows        = $query ? $query->getResultArray() : [];
        $branchNames = $this->_branch_name_map();

        foreach ($rows as &$row) {
            $row['source']      = 'billing';
            $row['branch_name'] = $branchNames[(int) ($row['branch_id'] ?? 0)] ?? null;
        }
        unset($row);

        return $rows;
    }

    private function _accumulate_sale(array &$grouped, array $row): void
    {
        $tin = $this->_normalize_tin($row['tin_no'] ?? '');
        if ($tin === '') {
            return;
        }

        $gross = $this->_sales_gross($row);
        if ($gross <= 0) {
            return;
        }

        $net = $gross / 1.12;
        $vat = $gross - $net;

        $this->_ensure_sales_row($grouped, $tin, $row);
        $grouped[$tin]['taxableNetofVat'] += $net;
        $grouped[$tin]['outputVat']       += $vat;

        $branchName = $row['branch_name'] ?? null;
        if ($branchName && ! in_array($branchName, $grouped[$tin]['branches'], true)) {
            $grouped[$tin]['branches'][] = $branchName;
        }
    }

    private function _sales_gross(array $row): float
    {
        return (float) ($row['grand_total'] ?? 0) + (float) ($row['withholding_tax'] ?? 0);
    }

    /**
     * APV payment details with scaled header amounts and optional receipt-note lines.
     *
     * @return list<array>
     */
    private function _load_purchase_payments(string $date_from, string $date_to, $branch_id = null): array
    {
        $rows = [];

        foreach ($this->_branch_targets($branch_id) as $id => $info) {
            $db      = \Config\Database::connect($info['group']);
            $payments = $this->_query_apv_payments($db, $date_from, $date_to);
            $this->_attach_receipt_note_items($db, $payments);

            foreach ($payments as $row) {
                $apvAmount = (float) ($row['apv_amount'] ?? 0);
                $paid      = (float) ($row['paid_amount'] ?? 0);
                $scale     = $this->_scale_factor($paid, $apvAmount);

                $row['branch_id']       = $id;
                $row['branch_name']     = $info['name'];
                $row['scale']           = $scale;
                $row['scaled_subtotal'] = (float) ($row['subtotal'] ?? 0) * $scale;
                $row['scaled_vat']      = (float) ($row['vat'] ?? 0) * $scale;
                $row['scaled_wht']      = (float) ($row['withholding_tax'] ?? 0) * $scale;
                $rows[]                 = $row;
            }
        }

        return $rows;
    }

    private function _query_apv_payments($db, string $date_from, string $date_to): array
    {
        $sql = <<<EOT
SELECT
    accounts_payable_voucher_payment_detail.id AS payment_detail_id,
    accounts_payable_voucher_payment_detail.date AS payment_date,
    COALESCE(accounts_payable_voucher_payment_detail.amount, accounts_payable_voucher.amount, 0) AS paid_amount,
    accounts_payable_voucher.id AS apv_id,
    accounts_payable_voucher.type AS apv_type,
    accounts_payable_voucher.type_id,
    accounts_payable_voucher.supplier_id,
    accounts_payable_voucher.subtotal,
    accounts_payable_voucher.vat,
    accounts_payable_voucher.withholding_tax,
    accounts_payable_voucher.amount AS apv_amount,
    supplier.tin,
    supplier.name AS supplier_name,
    COALESCE(NULLIF(supplier.payee_name, ''), supplier.name) AS registered_name,
    supplier.address,
    supplier.business_address,
    supplier.type AS supplier_type,
    COALESCE(
        CASE
            WHEN accounts_payable_voucher.type = 'receipt_note' AND NULLIF(receipt_note.sales_invoice_no, '') IS NOT NULL
                THEN receipt_note.sales_invoice_no
            WHEN accounts_payable_voucher.type = 'reimbursement' THEN (
                SELECT GROUP_CONCAT(DISTINCT TRIM(reimbursement_item.invoice_no) ORDER BY reimbursement_item.invoice_no SEPARATOR ', ')
                FROM reimbursement_item
                WHERE reimbursement_item.reimbursement_id = accounts_payable_voucher.type_id
                    AND reimbursement_item.is_deleted = 0
                    AND NULLIF(TRIM(reimbursement_item.invoice_no), '') IS NOT NULL
            )
            WHEN accounts_payable_voucher.type = 'replenishment' THEN (
                SELECT GROUP_CONCAT(DISTINCT TRIM(replenishment_item.invoice_no) ORDER BY replenishment_item.invoice_no SEPARATOR ', ')
                FROM replenishment_item
                WHERE replenishment_item.replenishment_id = accounts_payable_voucher.type_id
                    AND replenishment_item.is_deleted = 0
                    AND NULLIF(TRIM(replenishment_item.invoice_no), '') IS NOT NULL
            )
            WHEN accounts_payable_voucher.type = 'liquidation' THEN (
                SELECT GROUP_CONCAT(DISTINCT TRIM(liquidation_detail.invoice_no) ORDER BY liquidation_detail.invoice_no SEPARATOR ', ')
                FROM liquidation_detail
                WHERE liquidation_detail.liquidation_id = accounts_payable_voucher.type_id
                    AND liquidation_detail.is_deleted = 0
                    AND NULLIF(TRIM(liquidation_detail.invoice_no), '') IS NOT NULL
            )
        END,
        CONCAT('RFP No.# ', accounts_payable_voucher.type_id)
    ) AS invoice_reference
FROM accounts_payable_voucher_payment_detail
INNER JOIN accounts_payable_voucher
    ON accounts_payable_voucher.id = accounts_payable_voucher_payment_detail.accounts_payable_voucher_id
   AND accounts_payable_voucher.is_deleted = 0
LEFT JOIN receipt_note
    ON receipt_note.id = accounts_payable_voucher.type_id
   AND accounts_payable_voucher.type = 'receipt_note'
   AND receipt_note.is_deleted = 0
LEFT JOIN {MASTER_DB}.supplier AS supplier
    ON supplier.id = accounts_payable_voucher.supplier_id
   AND supplier.is_deleted = 0
WHERE accounts_payable_voucher_payment_detail.is_deleted = 0
  AND DATE(accounts_payable_voucher_payment_detail.date) BETWEEN ? AND ?
  AND IFNULL(accounts_payable_voucher.supplier_type, 'supplier') = 'supplier'
  AND supplier.tin IS NOT NULL
  AND TRIM(supplier.tin) <> ''
EOT;

        $sql   = $this->_resolve_cross_db_sql($sql);
        $query = $db->query($sql, [$date_from, $date_to]);

        return $query ? $query->getResultArray() : [];
    }

    /**
     * @param list<array> $payments
     */
    private function _attach_receipt_note_items($db, array &$payments): void
    {
        $ids = [];
        foreach ($payments as $row) {
            if (strtolower(trim((string) ($row['apv_type'] ?? ''))) === 'receipt_note') {
                $typeId = (int) ($row['type_id'] ?? 0);
                if ($typeId) {
                    $ids[$typeId] = $typeId;
                }
            }
        }

        $byReceipt = $this->_query_receipt_note_items($db, array_values($ids));

        foreach ($payments as &$row) {
            $row['receipt_note_items'] = [];
            if (strtolower(trim((string) ($row['apv_type'] ?? ''))) !== 'receipt_note') {
                continue;
            }
            $typeId = (int) ($row['type_id'] ?? 0);
            $row['receipt_note_items'] = $byReceipt[$typeId] ?? [];
        }
        unset($row);
    }

    /**
     * @param list<int> $receiptNoteIds
     * @return array<int,list<array>>
     */
    private function _query_receipt_note_items($db, array $receiptNoteIds): array
    {
        if (! $receiptNoteIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($receiptNoteIds), '?'));
        $sql          = <<<EOT
SELECT
    receipt_note.id AS receipt_note_id,
    receipt_note.supplier_id,
    receipt_note.capital_expenditure_id,
    receipt_note.expenditure_type AS header_expenditure_type,
    receipt_note.tax_type,
    receipt_note_item.amount,
    receipt_note_item.vat,
    receipt_note_item.vat_type,
    receipt_note_item.expenditure_type AS item_expenditure_type,
    item.type AS item_type,
    item.kind AS item_kind,
    item.vat_type AS item_vat_type
FROM receipt_note
INNER JOIN receipt_note_item
    ON receipt_note_item.receipt_note_id = receipt_note.id
   AND receipt_note_item.is_deleted = 0
LEFT JOIN item
    ON item.id = receipt_note_item.item_id
   AND item.is_deleted = 0
WHERE receipt_note.is_deleted = 0
  AND receipt_note.id IN ({$placeholders})
EOT;

        $query = $db->query($sql, $receiptNoteIds);
        $rows  = $query ? $query->getResultArray() : [];
        $byId  = [];
        foreach ($rows as $row) {
            $byId[(int) $row['receipt_note_id']][] = $row;
        }

        return $byId;
    }

    private function _accumulate_purchase(array &$grouped, array $row): void
    {
        $tin = $this->_normalize_tin($row['tin'] ?? '');
        if ($tin === '') {
            return;
        }

        if (! isset($grouped[$tin])) {
            $nameParts = $this->_party_name($row['supplier_name'] ?? '', $row['supplier_type'] ?? null);
            $address   = $this->_split_address($row['address'] ?? '', $row['business_address'] ?? '');
            $grouped[$tin] = array_merge($nameParts, $address, [
                'Vendor_TIN'            => $tin,
                'exempt'                => 0.0,
                'zeroRated'             => 0.0,
                'services'              => 0.0,
                'capitalGoods'          => 0.0,
                'otherThancapitalGoods' => 0.0,
                'inputVat'              => 0.0,
                'supplier_id'           => $row['supplier_id'] !== null && $row['supplier_id'] !== ''
                    ? (int) $row['supplier_id']
                    : null,
                'branches'              => [],
            ]);
        }

        $scale = (float) ($row['scale'] ?? 1);
        $items = $row['receipt_note_items'] ?? [];

        if ($items) {
            foreach ($items as $item) {
                $vatType = strtolower(trim((string) (($item['vat_type'] ?: $item['item_vat_type'] ?: $item['tax_type']) ?? '')));
                [$net, $vat] = $this->_line_net_and_vat((float) ($item['amount'] ?? 0), $item['vat'] ?? null, $vatType);
                $net    *= $scale;
                $vat    *= $scale;
                $bucket  = $this->_purchase_bucket($vatType, $item);
                $grouped[$tin][$bucket] += $net;
                if (in_array($bucket, ['services', 'capitalGoods', 'otherThancapitalGoods'], true)) {
                    $grouped[$tin]['inputVat'] += $vat;
                }
            }
        } else {
            $grouped[$tin]['otherThancapitalGoods'] += (float) ($row['scaled_subtotal'] ?? 0);
            $grouped[$tin]['inputVat']              += (float) ($row['scaled_vat'] ?? 0);
        }

        $branchName = $row['branch_name'] ?? null;
        if ($branchName && ! in_array($branchName, $grouped[$tin]['branches'], true)) {
            $grouped[$tin]['branches'][] = $branchName;
        }
    }

    private function _finalize_purchase_rows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['exempt']                = $this->_money($row['exempt']);
            $row['zeroRated']             = $this->_money($row['zeroRated']);
            $row['services']              = $this->_money($row['services']);
            $row['capitalGoods']          = $this->_money($row['capitalGoods']);
            $row['otherThancapitalGoods'] = $this->_money($row['otherThancapitalGoods']);
            $row['taxableNetofVat']       = $this->_money(
                $row['services'] + $row['capitalGoods'] + $row['otherThancapitalGoods']
            );
            $row['vatRate'] = 12.00;
            if ((float) $row['inputVat'] <= 0 && (float) $row['taxableNetofVat'] > 0) {
                $row['inputVat'] = $row['taxableNetofVat'] * 12 / 100;
            }
            $row['inputVat']       = $this->_money($row['inputVat']);
            $row['totalPurchases'] = $this->_money(
                $row['exempt'] + $row['zeroRated'] + $row['taxableNetofVat'] + $row['inputVat']
            );
        }
        unset($row);

        return $rows;
    }

    private function _ensure_sales_row(array &$grouped, string $tin, array $source): void
    {
        if (isset($grouped[$tin])) {
            return;
        }

        $nameParts = $this->_party_name(
            $source['company_name'] ?? '',
            $source['business_type'] ?? ($source['company_type'] ?? null)
        );
        $address2  = trim(implode(', ', array_filter([
            $source['barangay'] ?? null,
            $source['city_municipality'] ?? null,
            $source['province'] ?? null,
        ])));
        $address1  = trim((string) (($source['address'] ?: $source['street']) ?? ''));
        $address   = $this->_split_address($address1, $address2);
        $companyId = $source['charge_to'] ?? $source['company_id'] ?? null;

        $grouped[$tin] = array_merge($nameParts, $address, [
            'client_TIN'      => $tin,
            'exempt'          => 0.0,
            'zeroRated'       => 0.0,
            'taxableNetofVat' => 0.0,
            'outputVat'       => 0.0,
            'company_id'      => $companyId !== null && $companyId !== '' ? (int) $companyId : null,
            'branches'        => [],
        ]);
    }

    private function _finalize_sales_rows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['exempt']          = $this->_money($row['exempt']);
            $row['zeroRated']       = $this->_money($row['zeroRated']);
            $row['taxableNetofVat'] = $this->_money($row['taxableNetofVat']);
            $row['vatRate']         = 12.00;
            if ((float) $row['outputVat'] <= 0 && (float) $row['taxableNetofVat'] > 0) {
                $row['outputVat'] = $row['taxableNetofVat'] * 12 / 100;
            }
            $row['outputVat']    = $this->_money($row['outputVat']);
            $row['totalSales']   = $this->_money(
                $row['exempt'] + $row['zeroRated'] + $row['taxableNetofVat'] + $row['outputVat']
            );
            $row['grossTaxable'] = $this->_money($row['taxableNetofVat'] + $row['outputVat']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array{0:float,1:float} net, vat
     */
    private function _line_net_and_vat(float $amount, $vat, string $vatType): array
    {
        $storedVat = ($vat !== null && $vat !== '') ? (float) $vat : null;

        if ($vatType === 'vat_in') {
            if ($storedVat !== null) {
                return [$amount - $storedVat, $storedVat];
            }

            $net = $amount / 1.12;

            return [$net, $amount - $net];
        }

        $net         = $amount;
        $computedVat = $storedVat !== null ? $storedVat : ($this->_is_vatable($vatType) ? $net * 0.12 : 0.0);

        return [$net, $computedVat];
    }

    private function _purchase_bucket(string $vatType, array $row): string
    {
        if (in_array($vatType, ['nvat', 'exempt', 'vat_exempt'], true)) {
            return 'exempt';
        }
        if (in_array($vatType, ['zero_rated', 'zero-rated', '0rated'], true)) {
            return 'zeroRated';
        }

        $expenditure = strtolower(trim((string) (($row['item_expenditure_type'] ?: $row['header_expenditure_type']) ?? '')));
        $hasCapex    = ! empty($row['capital_expenditure_id']);
        if ($hasCapex || str_contains($expenditure, 'capital')) {
            return 'capitalGoods';
        }

        $itemType = strtolower(trim((string) ($row['item_type'] ?? '')));
        $kind     = strtolower(trim((string) ($row['item_kind'] ?? '')));
        if (in_array($itemType, ['service', 'services'], true) || str_contains($kind, 'service')) {
            return 'services';
        }

        return 'otherThancapitalGoods';
    }

    private function _is_vatable(string $vatType): bool
    {
        return ! in_array($vatType, ['nvat', 'exempt', 'vat_exempt', 'zero_rated', 'zero-rated', '0rated'], true);
    }

    private function _accumulate_supplier_alphalist(array &$grouped, string $tin, string $atc, string $invoice, array $payload): void
    {
        $invoice = trim($invoice);
        $key     = $tin . '|' . $atc . '|' . $invoice;
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'Reporting_Month'  => $payload['payment_date'] ?? null,
                'Vendor_TIN'       => $tin,
                'branchCode'       => '00000',
                'Registered Name'  => trim((string) ($payload['registered_name'] ?? '')),
                'Invoice #'        => $invoice,
                'ATC'              => $atc,
                'income_payment'   => 0.0,
                'ewt_rate'         => 0.0,
                'tax_amount'       => 0.0,
                '_rate_weight'     => 0.0,
            ];
        }

        $grouped[$key]['income_payment'] += (float) ($payload['income'] ?? 0);
        $grouped[$key]['tax_amount']     += (float) ($payload['tax'] ?? 0);
        $grouped[$key]['_rate_weight']   += (float) ($payload['rate'] ?? 0) * (float) ($payload['income'] ?? 0);
        if (empty($grouped[$key]['Reporting_Month']) && ! empty($payload['payment_date'])) {
            $grouped[$key]['Reporting_Month'] = $payload['payment_date'];
        }
    }

    private function _finalize_supplier_alphalist_rows(array $grouped, string $date_from): array
    {
        $rows = [];
        foreach ($grouped as $row) {
            $income = (float) $row['income_payment'];
            if ($income > 0 && (float) $row['_rate_weight'] > 0) {
                $row['ewt_rate'] = $this->_money($row['_rate_weight'] / $income);
            } elseif ((float) $row['tax_amount'] > 0 && $income > 0) {
                $row['ewt_rate'] = $this->_money(((float) $row['tax_amount'] / $income) * 100);
            } else {
                $row['ewt_rate'] = $this->_money($row['ewt_rate']);
            }
            unset($row['_rate_weight']);

            $row['income_payment']  = $this->_money($row['income_payment']);
            $row['tax_amount']      = $this->_money($row['tax_amount']);
            $month                  = $row['Reporting_Month'] ?: $date_from;
            $row['Reporting_Month'] = date('Y-m-d', strtotime((string) $month) ?: strtotime($date_from));
            $rows[] = $row;
        }

        usort($rows, static function ($a, $b) {
            $nameCmp = strcasecmp((string) ($a['Registered Name'] ?? ''), (string) ($b['Registered Name'] ?? ''));
            if ($nameCmp !== 0) {
                return $nameCmp;
            }
            $atcCmp = strcmp((string) $a['ATC'], (string) $b['ATC']);
            if ($atcCmp !== 0) {
                return $atcCmp;
            }

            return strcmp((string) ($a['Invoice #'] ?? ''), (string) ($b['Invoice #'] ?? ''));
        });

        return $rows;
    }

    private function _accumulate_alphalist(array &$grouped, string $tin, string $atc, array $payload): void
    {
        $key = $tin . '|' . $atc;
        if (! isset($grouped[$key])) {
            $grouped[$key] = array_merge($this->_alphalist_name($payload['name'] ?? '', $payload['type'] ?? null), [
                'Reporting_Month' => $payload['payment_date'] ?? null,
                'Vendor_TIN'      => $tin,
                'branchCode'      => '00000',
                'ATC'             => $atc,
                'income_payment'  => 0.0,
                'ewt_rate'        => 0.0,
                'tax_amount'      => 0.0,
                '_rate_weight'    => 0.0,
            ]);
        }

        $grouped[$key]['income_payment'] += (float) ($payload['income'] ?? 0);
        $grouped[$key]['tax_amount']     += (float) ($payload['tax'] ?? 0);
        $grouped[$key]['_rate_weight']   += (float) ($payload['rate'] ?? 0) * (float) ($payload['income'] ?? 0);
        if (empty($grouped[$key]['Reporting_Month']) && ! empty($payload['payment_date'])) {
            $grouped[$key]['Reporting_Month'] = $payload['payment_date'];
        }
    }

    private function _finalize_alphalist_rows(array $grouped, string $date_from): array
    {
        $rows = [];
        foreach ($grouped as $row) {
            $income = (float) $row['income_payment'];
            if ($income > 0 && (float) $row['_rate_weight'] > 0) {
                $row['ewt_rate'] = $this->_money($row['_rate_weight'] / $income);
            } elseif ((float) $row['tax_amount'] > 0 && $income > 0) {
                $row['ewt_rate'] = $this->_money(((float) $row['tax_amount'] / $income) * 100);
            } else {
                $row['ewt_rate'] = $this->_money($row['ewt_rate']);
            }
            unset($row['_rate_weight']);

            $row['income_payment'] = $this->_money($row['income_payment']);
            $row['tax_amount']     = $this->_money($row['tax_amount']);
            $month                 = $row['Reporting_Month'] ?: $date_from;
            $row['Reporting_Month'] = date('Y-m-d', strtotime((string) $month) ?: strtotime($date_from));
            $rows[] = $row;
        }

        usort($rows, static function ($a, $b) {
            $nameCmp = strcasecmp((string) ($a['companyName'] ?: $a['surName']), (string) ($b['companyName'] ?: $b['surName']));
            if ($nameCmp !== 0) {
                return $nameCmp;
            }

            return strcmp((string) $a['ATC'], (string) $b['ATC']);
        });

        return $rows;
    }

    private function _alphalist_name(string $name, $type): array
    {
        $parts = $this->_party_name($name, $type);

        return [
            'companyName' => $parts['companyName'],
            'surName'     => $parts['lastName'],
            'firstName'   => $parts['firstName'],
            'middleName'  => $parts['middleName'],
        ];
    }

    private function _is_receivable_company($type, $is_hmo): bool
    {
        if ((int) $is_hmo === 1) {
            return true;
        }

        $value = strtolower(trim((string) $type));
        if ($value === '') {
            return false;
        }

        return in_array($value, ['hmo', 'corporate', 'corp', 'yakap', 'yakap konsulta', 'konsulta'], true)
            || str_contains($value, 'hmo')
            || str_contains($value, 'corporate')
            || str_contains($value, 'yakap');
    }

    private function _normalize_ewt_rate($percent): float
    {
        $value = (float) $percent;
        if ($value > 0 && $value < 1) {
            $value *= 100;
        }

        return round($value, 2);
    }

    private function _ewt_rate_from_amounts($storedPercent, float $tax, float $base): float
    {
        $rate = $this->_normalize_ewt_rate($storedPercent);
        if ($rate > 0) {
            return $rate;
        }
        if ($tax > 0 && $base > 0) {
            return $this->_normalize_ewt_rate(($tax / $base) * 100);
        }

        return 0.0;
    }

    private function _nearest_mapped_ewt_rate(float $rate): float
    {
        if ($rate <= 0) {
            return 0.0;
        }

        $candidates = [1.0, 2.0, 5.0, 10.0];
        $best       = $candidates[0];
        $bestDiff   = PHP_FLOAT_MAX;
        foreach ($candidates as $candidate) {
            $diff = abs($rate - $candidate);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best     = $candidate;
            }
        }

        return $best;
    }

    private function _atc_from_rate($percent, bool $isIndividual): string
    {
        $rate = (int) round($this->_normalize_ewt_rate($percent));
        $map  = [
            1  => '010',
            2  => '020',
            5  => '050',
            10 => '010',
        ];
        $suffix = $map[$rate] ?? str_pad((string) max(0, $rate), 3, '0', STR_PAD_LEFT);

        return ($isIndividual ? 'WI' : 'WC') . $suffix;
    }

    private function _income_payment(float $tax, float $rate, float $gross): float
    {
        if ($rate > 0 && $tax > 0) {
            return $tax / ($rate / 100);
        }

        return $gross > 0 ? $gross / 1.12 : 0.0;
    }

    private function _scale_factor(float $paid, float $apvAmount): float
    {
        if ($apvAmount <= 0) {
            return 1.0;
        }
        if ($paid <= 0) {
            return 1.0;
        }

        return $paid / $apvAmount;
    }

    private function _party_name(string $name, $type): array
    {
        $name = trim($name);
        if ($this->_is_individual($type)) {
            $parts = preg_split('/\s+/', $name) ?: [];

            return [
                'companyName' => '',
                'lastName'    => $parts[count($parts) - 1] ?? '',
                'firstName'   => $parts[0] ?? '',
                'middleName'  => count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : '',
            ];
        }

        return [
            'companyName' => $name,
            'lastName'    => '',
            'firstName'   => '',
            'middleName'  => '',
        ];
    }

    private function _is_individual($type): bool
    {
        $value = strtolower(trim((string) $type));

        return $value !== '' && (
            str_contains($value, 'individual')
            || str_contains($value, 'person')
            || $value === 'sole'
            || str_contains($value, 'sole prop')
        );
    }

    private function _split_address($line1, $line2): array
    {
        return [
            'address1' => trim((string) $line1),
            'address2' => trim((string) $line2),
        ];
    }

    private function _normalize_tin($tin): string
    {
        return preg_replace('/\D+/', '', (string) $tin) ?? '';
    }

    private function _money($value): float
    {
        return round((float) $value, 2);
    }

    private function _filter_term(array $rows, $term, array $fields): array
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $rows;
        }

        $needle = strtolower($term);
        $digits = $this->_normalize_tin($term);

        return array_values(array_filter($rows, static function ($row) use ($needle, $digits, $fields) {
            foreach ($fields as $field) {
                $value = strtolower((string) ($row[$field] ?? ''));
                if ($value !== '' && str_contains($value, $needle)) {
                    return true;
                }
                if ($digits !== '' && preg_match('/TIN$/i', $field) && str_contains($value, $digits)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return array<int,string>
     */
    private function _branch_name_map(): array
    {
        $names = [];
        foreach ($this->_branch_targets(null) as $id => $info) {
            $names[$id] = $info['name'];
        }

        return $names;
    }
}
