<?php
/**
 * Generate docs/templates/asset_migration_template.xlsx
 *
 * Prefers PhpSpreadsheet when installed; otherwise runs the Python generator:
 *   python3 scripts/generate_asset_template.py
 *
 * Usage:
 *   php scripts/generate_asset_template.php
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
$output = $root . '/docs/templates/asset_migration_template.xlsx';

if (is_file($autoload)) {
    require $autoload;
}

if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    $py = $root . '/scripts/generate_asset_template.py';
    passthru('python3 ' . escapeshellarg($py), $code);
    exit($code);
}

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$headerFill = [
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'D9E2F3'],
    ],
];
$requiredFill = [
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFF2CC'],
    ],
];
$sampleFill = [
    'font' => ['italic' => true, 'color' => ['rgb' => '666666']],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F2F2F2'],
    ],
];

$instructions = $spreadsheet->getActiveSheet();
$instructions->setTitle('Instructions');
$instructions->fromArray([
    ['Asset Data Migration Template'],
    [''],
    ['Send this file to the client. They fill the Assets sheet (and optional child sheets). We import with:'],
    ['  php spark assets:import path/to/filled.xlsx --requester <user_id>'],
    [''],
    ['Sheets'],
    ['  Assets     — one row per asset. Required: asset_key, name, and cost_center_name or cost_center_id.'],
    ['  Reagents   — optional. Link with asset_key. Provide item_name, item_code, or item_id.'],
    ['  Lab_tests  — optional. Link with asset_key. lab_test_key is a workbook-only key for Machines.'],
    ['  Machines   — optional. Link with asset_key plus lab_test_key (preferred) or lab_test_name.'],
    ['  Ref_*      — lookup lists. Ref_branches is prefilled. Other catalogs stay header-only.'],
    [''],
    ['Rules'],
    ['  1. Do not rename sheets or header cells. Add data from row 2 downward. Leave unused rows blank.'],
    ['  2. asset_key must be unique in this file. It is not stored; it only links child rows.'],
    ['  3. Rows whose asset_key starts with SAMPLE are examples and are skipped on import.'],
    ['  4. as_of must be YYYY-MM-DD (example: 2026-06-01).'],
    ['  5. name must be unique per branch among existing non-deleted assets. Duplicates are skipped, not updated.'],
    ['  6. Each asset is imported into that cost center clinic database only.'],
    ['  7. Prefer a name from the live catalog. IDs are optional if you already know them.'],
    ['  8. item_code is accepted as the catalog item id (numeric) or the exact item name. There is no separate item_code column in the database.'],
    ['  9. Unused Reagents / Lab_tests / Machines sheets can stay blank.'],
    [''],
    ['Cost centers (also listed on Ref_branches)'],
    ['  1 Mobile, 2 Oakridge, 3 Mactan, 4 Consolacion, 7 Mantlewood, 5 Warehouse, 6 Pharmacy'],
    [''],
    ['Yellow headers are required. Blue headers are optional.'],
], null, 'A1');
$instructions->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$instructions->getColumnDimension('A')->setWidth(140);
$instructions->getRowDimension(1)->setRowHeight(24);

$writeSheet = static function (
    Spreadsheet $spreadsheet,
    string $title,
    array $headers,
    array $required,
    array $sampleRows
) use ($headerFill, $requiredFill, $sampleFill): Worksheet {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($title);
    $sheet->fromArray($headers, null, 'A1');
    $colCount = count($headers);
    $lastCol = Coordinate::stringFromColumnIndex($colCount);
    $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($headerFill);
    foreach ($headers as $i => $header) {
        if (in_array($header, $required, true)) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getStyle($col . '1')->applyFromArray($requiredFill);
        }
    }
    $rowNum = 2;
    foreach ($sampleRows as $row) {
        $values = [];
        foreach ($headers as $header) {
            $values[] = $row[$header] ?? '';
        }
        $sheet->fromArray($values, null, 'A' . $rowNum);
        $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray($sampleFill);
        $rowNum++;
    }
    $sheet->freezePane('A2');
    $sheet->setAutoFilter("A1:{$lastCol}1");
    for ($i = 1; $i <= $colCount; $i++) {
        $col = Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    return $sheet;
};

$assetHeaders = [
    'asset_key', 'name', 'cost_center_name', 'cost_center_id',
    'asset_group_name', 'asset_group_id',
    'item_name', 'item_code', 'item_id',
    'supplier_name', 'supplier_id',
    'useful_life_years', 'end_user_location', 'cost', 'as_of',
    'each_period', 'accumulated', 'net_value', 'consumption_unit',
];
$assetsSheet = $writeSheet($spreadsheet, 'Assets', $assetHeaders, [
    'asset_key', 'name', 'cost_center_name', 'cost_center_id',
], [
    [
        'asset_key' => 'SAMPLE-001',
        'name' => 'Sysmex HISCL 800',
        'cost_center_name' => 'Mobile',
        'cost_center_id' => 1,
        'useful_life_years' => 5,
        'end_user_location' => 'Laboratory',
        'cost' => 10000,
        'as_of' => '2026-06-01',
        'consumption_unit' => 'unit',
    ],
    [
        'asset_key' => 'SAMPLE-002',
        'name' => 'Office Desktop PC',
        'cost_center_name' => 'Oakridge',
        'cost_center_id' => 2,
        'useful_life_years' => 3,
        'end_user_location' => 'Admin',
        'cost' => 45000,
        'as_of' => '2026-01-15',
    ],
]);

$branchNameValidation = $assetsSheet->getCell('C2')->getDataValidation();
$branchNameValidation->setType(DataValidation::TYPE_LIST);
$branchNameValidation->setFormula1('Ref_branches!$B$2:$B$8');
$branchNameValidation->setAllowBlank(true);
$branchNameValidation->setShowDropDown(true);
$branchNameValidation->setShowErrorMessage(true);
$assetsSheet->setDataValidation('C2:C1000', $branchNameValidation);

$branchIdValidation = $assetsSheet->getCell('D2')->getDataValidation();
$branchIdValidation->setType(DataValidation::TYPE_LIST);
$branchIdValidation->setFormula1('Ref_branches!$A$2:$A$8');
$branchIdValidation->setAllowBlank(true);
$branchIdValidation->setShowDropDown(true);
$branchIdValidation->setShowErrorMessage(true);
$assetsSheet->setDataValidation('D2:D1000', $branchIdValidation);

$writeSheet($spreadsheet, 'Reagents', [
    'asset_key', 'item_name', 'item_code', 'item_id',
], ['asset_key'], [
    [
        'asset_key' => 'SAMPLE-001',
        'item_name' => 'Sample Reagent',
    ],
]);

$writeSheet($spreadsheet, 'Lab_tests', [
    'asset_key', 'lab_test_key', 'lab_test_name', 'lab_test_id',
], ['asset_key', 'lab_test_key'], [
    [
        'asset_key' => 'SAMPLE-001',
        'lab_test_key' => 'LT-001',
        'lab_test_name' => 'Complete Blood Count',
    ],
]);

$writeSheet($spreadsheet, 'Machines', [
    'asset_key', 'lab_test_key', 'lab_test_name', 'item_name', 'item_id', 'qty', 'unit_name', 'unit',
], ['asset_key'], [
    [
        'asset_key' => 'SAMPLE-001',
        'lab_test_key' => 'LT-001',
        'lab_test_name' => 'Complete Blood Count',
        'item_name' => 'Sample Analyzer',
        'qty' => 1,
        'unit_name' => 'unit',
    ],
]);

$refBranches = $spreadsheet->createSheet();
$refBranches->setTitle('Ref_branches');
$refBranches->fromArray(['id', 'name', 'db_group'], null, 'A1');
$refBranches->fromArray([
    [1, 'Mobile', 'mobile'],
    [2, 'Oakridge', 'oakridge'],
    [3, 'Mactan', 'mactan'],
    [4, 'Consolacion', 'consolacion'],
    [7, 'Mantlewood', 'mantlewood'],
    [5, 'Warehouse', 'warehouse'],
    [6, 'Pharmacy', 'pharmacy'],
], null, 'A2');
$refBranches->getStyle('A1:C1')->applyFromArray($headerFill);
$refBranches->freezePane('A2');
foreach (range(1, 3) as $i) {
    $refBranches->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}

$writeRef = static function (Spreadsheet $spreadsheet, string $title, array $headers) use ($headerFill): void {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($title);
    $sheet->fromArray($headers, null, 'A1');
    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($headerFill);
    $sheet->freezePane('A2');
    for ($i = 1; $i <= count($headers); $i++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
};

$writeRef($spreadsheet, 'Ref_asset_groups', ['id', 'name']);
$writeRef($spreadsheet, 'Ref_suppliers', ['id', 'name']);
$writeRef($spreadsheet, 'Ref_items', ['id', 'name', 'item_code']);
$writeRef($spreadsheet, 'Ref_lab_tests', ['id', 'name']);
$writeRef($spreadsheet, 'Ref_units', ['id', 'unit', 'item_id']);

if (!is_dir(dirname($output))) {
    mkdir(dirname($output), 0775, true);
}
$writer = new Xlsx($spreadsheet);
$writer->save($output);
fwrite(STDOUT, "Wrote {$output}\n");
exit(0);
