<?php
/**
 * Generate docs/templates/request_bulk_template.xlsx
 *
 * Prefers PhpSpreadsheet when installed; otherwise runs the Python generator:
 *   python3 scripts/generate_bulk_template.py
 *
 * Usage:
 *   php scripts/generate_bulk_template.php
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
$output = $root . '/docs/templates/request_bulk_template.xlsx';

if (is_file($autoload)) {
    require $autoload;
}

if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    $instructions = $spreadsheet->getActiveSheet();
    $instructions->setTitle('Instructions');
    $instructions->fromArray([
        ['Request Bulk Upload Template'],
        [''],
        ['1. Fill the Request sheet (1 data row). Required-ish: company_id, customer_account_id, date, sae_in_charge_id.'],
        ['2. Fill Costing / Quotation / Endorsement sheets for entity-specific fields (1 data row each). Shared fields blank on Costing are copied from Request.'],
        ['3. Add rows on Services, Details, Shifts as needed. Leave unused rows blank.'],
        ['4. Consultations: use GET /requests/download_bulk_template for EMR-prefilled specialization rows when possible.'],
        ['   Fill company_id, fee_amount, doctor_share only for rows you need. Rows with empty fee_amount are skipped on upload.'],
        ['5. Upload via POST /requests/upload_bulk with multipart field "file".'],
        ['6. Upload creates one approved Request → Costing → Quotation → Endorsement chain.'],
    ], null, 'A1');
    $instructions->getColumnDimension('A')->setWidth(120);

    $writeSheet = function ($spreadsheet, $title, $headers, $singleDataRow) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray(array_values($headers), null, 'A1');
        if ($singleDataRow) {
            $sheet->fromArray(array_fill(0, count($headers), ''), null, 'A2');
        }
        $sheet->freezePane('A2');
        for ($i = 1; $i <= count($headers); $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    };

    $requestHeaders = [
        'customer_account_id', 'reference_no', 'request_type', 'priority', 'due_date',
        'contact_person', 'contact_number', 'email', 'delivery_address', 'payment_terms',
        'grand_total', 'date', 'sae_in_charge_id', 'company_id', 'mode_of_payment',
        'preferred_branch_id', 'hmo_account_name', 'estimated_headcount', 'number_of_days',
        'shift', 'with_masterlist', 'with_walk_in', 'transaction_type', 'male_35_plus',
        'female_35_plus', 'classification', 'service_duration_date_from', 'service_duration_date_to',
        'rdt_requester', 'signatory', 'signatory_designation', 'remarks', 'guaranteed_headcount',
        'mobile_site', 'email_result', 'evaluation_data_source', 'estimated_patient_per_shift',
        'type_cost', 'mode_of_releasing',
    ];
    $writeSheet($spreadsheet, 'Request', $requestHeaders, true);
    $writeSheet($spreadsheet, 'Costing', [
        'package_name', 'service_package', 'other_service_package', 'ape_date', 'hours_per_day',
        'pre_negotiated_rate', 'admin_fee', 'grand_total', 'remarks',
        'service_duration_date_from', 'service_duration_date_to',
    ], true);
    $writeSheet($spreadsheet, 'Quotation', [
        'version_no', 'effectivity_date_from', 'effectivity_date_to', 'quotation_no',
        'remarks', 'note', 'signatory', 'signatory_designation', 'grand_total',
    ], true);
    $writeSheet($spreadsheet, 'Endorsement', [
        'branch_id', 'mobile_branch_id', 'type', 'endorsement_date', 'transaction_type',
        'address', 'preferred_branch_id', 'mode_of_releasing', 'results_tat', 'email', 'time',
        'head_count', 'payment_terms', 'date_from', 'date_to', 'contract_date_from', 'contract_date_to',
        'package_name', 'effectivity_date_from', 'effectivity_date_to',
        'service_duration_date_from', 'service_duration_date_to', 'remarks', 'note',
    ], true);
    $writeSheet($spreadsheet, 'Services', [
        'type', 'type_id', 'type_price', 'type_cost', 'branch_id', 'charge_to', 'charge_to_ids',
        'head_count', 'discount_amount', 'margin_amount', 'is_guaranteed', 'pre_negotiated_rate',
        'margin', 'cost', 'pro_rate_price', 'discount', 'sales', 'admin_fee', 'direct_cost',
        'manpower_cost', 'mobilization_cost', 'total_cost', 'estimated_margin', 'total_amount',
        'theoretical_cost',
    ], false);
    $writeSheet($spreadsheet, 'Details', [
        'lab_test_id', 'branch_id', 'quantity', 'unit_price', 'total_amount',
        'discount', 'pro_rate_price', 'margin', 'cost',
    ], false);
    $writeSheet($spreadsheet, 'Shifts', [
        'name', 'start_shift', 'end_shift', 'registration_cut_off',
        'specimen_submission_cut_off', 'total_hours', 'break', 'ingress',
    ], false);

    $consult = $spreadsheet->createSheet();
    $consult->setTitle('Consultations');
    $consult->fromArray(['specialization_id', 'specialization_name', 'company_id', 'fee_amount', 'doctor_share'], null, 'A1');
    $consult->freezePane('A2');

    // Optional EMR prefills if bootstrap is possible (best-effort; ignore failures)
    try {
        // Bootstrapping full CI for EMR is heavy; leave headers for static file.
    } catch (Throwable $e) {
        // ignore
    }

    if (!is_dir(dirname($output))) {
        mkdir(dirname($output), 0775, true);
    }
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save($output);
    fwrite(STDOUT, "Wrote {$output}\n");
    exit(0);
}

$py = $root . '/scripts/generate_bulk_template.py';
passthru('python3 ' . escapeshellarg($py), $code);
exit($code);
