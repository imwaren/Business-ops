<?php

namespace App\Commands;

use App\Models\Asset;
use App\Models\Asset_lab_test;
use App\Models\Asset_lab_test_machine;
use App\Models\Asset_reagent;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ImportAssets extends BaseCommand
{
    protected $group       = 'Assets';
    protected $name        = 'assets:import';
    protected $description = 'Import assets (and optional reagents, lab tests, machines) from the migration workbook.';
    protected $usage       = 'assets:import <file> --requester <user_id> [--dry-run] [--stop-on-error]';
    protected $arguments   = [
        'file' => 'Path to the filled asset_migration_template.xlsx',
    ];
    protected $options     = [
        '--requester'     => 'User id written to added_by (required).',
        '--dry-run'       => 'Validate and resolve only; do not insert.',
        '--stop-on-error' => 'Stop after the first failed asset.',
    ];

    private const DB_MAP = [
        0 => 'master',
        1 => 'mobile',
        2 => 'oakridge',
        3 => 'mactan',
        4 => 'consolacion',
        7 => 'mantlewood',
        5 => 'warehouse',
        6 => 'pharmacy',
    ];

    private const BRANCH_ALIASES = [
        'mobile'      => 1,
        'oakridge'    => 2,
        'mactan'      => 3,
        'consolacion' => 4,
        'mantlewood'  => 7,
        'warehouse'   => 5,
        'pharmacy'    => 6,
    ];

    private $masterDb;
    private array $contexts = [];
    private array $reportRows = [];

    public function run(array $params)
    {
        if (!class_exists(Spreadsheet::class)) {
            CLI::error('PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet:^1.29');
            return EXIT_ERROR;
        }

        $file = $params[0] ?? null;
        if (!$file) {
            CLI::error('File path is required.');
            CLI::write('Usage: php spark assets:import path/to/filled.xlsx --requester <user_id>');
            return EXIT_ERROR;
        }
        if (!is_file($file)) {
            CLI::error('File not found: ' . $file);
            return EXIT_ERROR;
        }

        $requester = CLI::getOption('requester');
        if ($requester === null || $requester === '' || (int) $requester <= 0) {
            CLI::error('--requester <user_id> is required.');
            return EXIT_ERROR;
        }
        $requester = (int) $requester;
        $dryRun = CLI::getOption('dry-run') !== null;
        $stopOnError = CLI::getOption('stop-on-error') !== null;

        try {
            $spreadsheet = IOFactory::load($file);
        } catch (\Throwable $e) {
            CLI::error('Failed to read Excel file: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        if ($spreadsheet->getSheetByName('Assets') === null) {
            CLI::error('Workbook is missing the Assets sheet.');
            return EXIT_ERROR;
        }

        $this->masterDb = db_connect('default');

        $assets = $this->parseSheet($spreadsheet, 'Assets');
        $reagents = $this->parseSheet($spreadsheet, 'Reagents');
        $labTests = $this->parseSheet($spreadsheet, 'Lab_tests');
        $machines = $this->parseSheet($spreadsheet, 'Machines');

        $byKey = [];
        $duplicateKeys = [];
        foreach ($assets as $row) {
            $key = $row['asset_key'] ?? '';
            if ($key === '' || $this->isSampleKey($key)) {
                if ($key === '') {
                    $this->addReport('Assets', $row['_row'], '', 'failed', 'asset_key is required.');
                }
                continue;
            }
            if (isset($byKey[$key]) || isset($duplicateKeys[$key])) {
                $duplicateKeys[$key] = true;
                if (isset($byKey[$key])) {
                    $first = $byKey[$key];
                    $this->addReport('Assets', $first['_row'], $key, 'failed', 'Duplicate asset_key in workbook.');
                    unset($byKey[$key]);
                }
                $this->addReport('Assets', $row['_row'], $key, 'failed', 'Duplicate asset_key in workbook.');
                continue;
            }
            $byKey[$key] = $row;
        }

        foreach ($byKey as $key => $row) {
            $byKey[$key]['reagents'] = [];
            $byKey[$key]['lab_tests'] = [];
            $byKey[$key]['machines'] = [];
        }

        $this->attachChildren($byKey, $reagents, 'Reagents');
        $this->attachChildren($byKey, $labTests, 'Lab_tests');
        $this->attachChildren($byKey, $machines, 'Machines');

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $wouldCreate = 0;
        foreach ($this->reportRows as $reportRow) {
            if ($reportRow['status'] === 'failed') {
                $failed++;
            }
        }

        foreach ($byKey as $key => $row) {
            $result = $this->processAsset($row, $requester, $dryRun);
            $status = $result['status'];
            $this->addReport(
                'Assets',
                $row['_row'],
                $key,
                $status,
                $result['message'],
                $result['asset_id']
            );

            if ($status === 'created' || $status === 'would_create') {
                if ($status === 'created') {
                    $created++;
                } else {
                    $wouldCreate++;
                }
                CLI::write("  [{$status}] {$key}: {$result['message']}", 'green');
            } elseif ($status === 'skipped') {
                $skipped++;
                CLI::write("  [{$status}] {$key}: {$result['message']}", 'yellow');
            } else {
                $failed++;
                CLI::write("  [{$status}] {$key}: {$result['message']}", 'red');
                if ($stopOnError) {
                    CLI::error('Stopped on first error (--stop-on-error).');
                    break;
                }
            }
        }

        $reportPath = $this->writeReport($file);

        CLI::newLine();
        if ($dryRun) {
            CLI::write("Dry run complete. would_create={$wouldCreate} skipped={$skipped} failed={$failed}", 'yellow');
        } else {
            CLI::write("Import complete. created={$created} skipped={$skipped} failed={$failed}", 'yellow');
        }
        CLI::write('Report: ' . $reportPath);

        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    private function attachChildren(array &$byKey, array $rows, string $sheet): void
    {
        foreach ($rows as $row) {
            $key = $row['asset_key'] ?? '';
            if ($key === '' || $this->isSampleKey($key)) {
                continue;
            }
            if (!isset($byKey[$key])) {
                $this->addReport($sheet, $row['_row'], $key, 'failed', "asset_key '{$key}' was not found on the Assets sheet.");
                continue;
            }
            if ($sheet === 'Reagents') {
                $byKey[$key]['reagents'][] = $row;
            } elseif ($sheet === 'Lab_tests') {
                $byKey[$key]['lab_tests'][] = $row;
            } else {
                $byKey[$key]['machines'][] = $row;
            }
        }
    }

    private function processAsset(array $row, int $requester, bool $dryRun): array
    {
        $name = $row['name'] ?? '';
        if ($name === '') {
            return $this->failResult('name is required.');
        }

        [$costCenterId, $costCenterError] = $this->resolveCostCenter($row);
        if ($costCenterError) {
            return $this->failResult($costCenterError);
        }

        $ctx = $this->branchContext($costCenterId);
        $existing = $ctx['asset']->select('id', ['name' => $name, 'is_deleted' => 0], 1);
        if ($existing) {
            return [
                'status'   => 'skipped',
                'message'  => "Asset '{$name}' already exists on this branch.",
                'asset_id' => isset($existing->id) ? (int) $existing->id : null,
            ];
        }

        [$assetGroupId, $error] = $this->resolveOptionalLookup(
            $this->masterDb,
            'asset_group',
            $row['asset_group_id'] ?? '',
            $row['asset_group_name'] ?? '',
            'Asset group'
        );
        if ($error) {
            return $this->failResult($error);
        }

        [$supplierId, $error] = $this->resolveOptionalLookup(
            $this->masterDb,
            'supplier',
            $row['supplier_id'] ?? '',
            $row['supplier_name'] ?? '',
            'Supplier'
        );
        if ($error) {
            return $this->failResult($error);
        }

        [$itemId, $error] = $this->resolveItem($ctx['db'], $row, false);
        if ($error) {
            return $this->failResult($error);
        }

        $asOf = $row['as_of'] ?? '';
        if ($asOf !== '' && !$this->isDate($asOf)) {
            return $this->failResult("as_of must be YYYY-MM-DD. Got: {$asOf}");
        }

        $labTests = $this->prepareLabTests($ctx['db'], $row);
        if (isset($labTests['error'])) {
            return $this->failResult($labTests['error']);
        }

        $reagents = $this->prepareReagents($ctx['db'], $row['reagents']);
        if (isset($reagents['error'])) {
            return $this->failResult($reagents['error']);
        }

        $values = [
            'added_by'           => $requester,
            'added_on'           => date('Y-m-d H:i:s'),
            'asset_group_id'     => $assetGroupId,
            'cost_center_id'     => $costCenterId,
            'item_id'            => $itemId,
            'name'               => $name,
            'useful_life_years'  => $this->nullIfEmpty($row['useful_life_years'] ?? ''),
            'end_user_location'  => $this->nullIfEmpty($row['end_user_location'] ?? ''),
            'cost'               => $this->nullIfEmpty($row['cost'] ?? ''),
            'as_of'              => $this->nullIfEmpty($asOf),
            'supplier_id'        => $supplierId,
            'each_period'        => $this->nullIfEmpty($row['each_period'] ?? ''),
            'accumulated'        => $this->nullIfEmpty($row['accumulated'] ?? ''),
            'net_value'          => $this->nullIfEmpty($row['net_value'] ?? ''),
            'consumption_unit'   => $this->nullIfEmpty($row['consumption_unit'] ?? ''),
        ];

        if ($dryRun) {
            $childCount = count($reagents) + count($labTests);
            return [
                'status'   => 'would_create',
                'message'  => "Would create '{$name}' on cost center {$costCenterId} with {$childCount} child row(s).",
                'asset_id' => null,
            ];
        }

        $db = $ctx['db'];
        $db->transBegin();

        $assetId = $ctx['asset']->insert($values);
        if (!$assetId) {
            $db->transRollback();
            return $this->failResult('Failed to insert asset. ' . $this->dbError($db));
        }

        foreach ($reagents as $reagent) {
            $ok = $ctx['reagent']->insert([
                'asset_id' => $assetId,
                'item_id'  => $reagent['item_id'],
                'added_by' => $requester,
                'added_on' => date('Y-m-d H:i:s'),
            ]);
            if (!$ok) {
                $db->transRollback();
                return $this->failResult('Failed to insert reagent. ' . $this->dbError($db));
            }
        }

        foreach ($labTests as $labTest) {
            $assetLabTestId = $ctx['lab_test']->insert([
                'asset_id'    => $assetId,
                'lab_test_id' => $labTest['lab_test_id'],
                'added_by'    => $requester,
                'added_on'    => date('Y-m-d H:i:s'),
            ]);
            if (!$assetLabTestId) {
                $db->transRollback();
                return $this->failResult('Failed to insert lab test. ' . $this->dbError($db));
            }

            foreach ($labTest['machines'] as $machine) {
                $ok = $ctx['machine']->insert([
                    'asset_lab_test_id' => $assetLabTestId,
                    'asset_id'          => $assetId,
                    'lab_test_id'       => $labTest['lab_test_id'],
                    'item_id'           => $machine['item_id'],
                    'qty'               => $machine['qty'],
                    'unit'              => $machine['unit'],
                    'added_by'          => $requester,
                    'added_on'          => date('Y-m-d H:i:s'),
                ]);
                if (!$ok) {
                    $db->transRollback();
                    return $this->failResult('Failed to insert machine. ' . $this->dbError($db));
                }
            }
        }

        $db->transCommit();

        return [
            'status'   => 'created',
            'message'  => "Created '{$name}' as asset_id {$assetId}.",
            'asset_id' => (int) $assetId,
        ];
    }

    private function prepareReagents($db, array $rows): array
    {
        $prepared = [];
        foreach ($rows as $row) {
            [$itemId, $error] = $this->resolveItem($db, $row, true);
            if ($error) {
                $this->addReport('Reagents', $row['_row'], $row['asset_key'] ?? '', 'failed', $error);
                return ['error' => 'Reagents row ' . $row['_row'] . ': ' . $error];
            }
            $prepared[] = ['item_id' => $itemId];
        }
        return $prepared;
    }

    private function prepareLabTests($db, array $assetRow): array
    {
        $prepared = [];
        $byKey = [];
        $byName = [];

        foreach ($assetRow['lab_tests'] as $row) {
            $labTestKey = $row['lab_test_key'] ?? '';
            [$labTestId, $error] = $this->resolveOptionalLookup(
                $db,
                'lab_test',
                $row['lab_test_id'] ?? '',
                $row['lab_test_name'] ?? '',
                'Lab test'
            );
            if ($error) {
                $this->addReport('Lab_tests', $row['_row'], $assetRow['asset_key'] ?? '', 'failed', $error);
                return ['error' => 'Lab_tests row ' . $row['_row'] . ': ' . $error];
            }
            if ($labTestId === null) {
                $error = 'lab_test_name or lab_test_id is required.';
                $this->addReport('Lab_tests', $row['_row'], $assetRow['asset_key'] ?? '', 'failed', $error);
                return ['error' => 'Lab_tests row ' . $row['_row'] . ': ' . $error];
            }

            $entry = [
                'lab_test_id' => $labTestId,
                'lab_test_key' => $labTestKey,
                'lab_test_name' => $row['lab_test_name'] ?? '',
                'machines' => [],
            ];
            $prepared[] = $entry;
            $index = count($prepared) - 1;
            if ($labTestKey !== '') {
                $byKey[mb_strtolower($labTestKey)] = $index;
            }
            if (($row['lab_test_name'] ?? '') !== '') {
                $byName[mb_strtolower($row['lab_test_name'])] = $index;
            }
        }

        foreach ($assetRow['machines'] as $row) {
            $labTestKey = $row['lab_test_key'] ?? '';
            $labTestName = $row['lab_test_name'] ?? '';
            $index = null;
            if ($labTestKey !== '' && isset($byKey[mb_strtolower($labTestKey)])) {
                $index = $byKey[mb_strtolower($labTestKey)];
            } elseif ($labTestName !== '' && isset($byName[mb_strtolower($labTestName)])) {
                $index = $byName[mb_strtolower($labTestName)];
            }

            if ($index === null) {
                $error = 'Machine must match a Lab_tests row via lab_test_key or lab_test_name.';
                $this->addReport('Machines', $row['_row'], $assetRow['asset_key'] ?? '', 'failed', $error);
                return ['error' => 'Machines row ' . $row['_row'] . ': ' . $error];
            }

            [$itemId, $error] = $this->resolveItem($db, $row, false);
            if ($error) {
                $this->addReport('Machines', $row['_row'], $assetRow['asset_key'] ?? '', 'failed', $error);
                return ['error' => 'Machines row ' . $row['_row'] . ': ' . $error];
            }

            [$unitId, $error] = $this->resolveUnit($db, $row, $itemId);
            if ($error) {
                $this->addReport('Machines', $row['_row'], $assetRow['asset_key'] ?? '', 'failed', $error);
                return ['error' => 'Machines row ' . $row['_row'] . ': ' . $error];
            }

            $prepared[$index]['machines'][] = [
                'item_id' => $itemId,
                'qty'     => $this->nullIfEmpty($row['qty'] ?? ''),
                'unit'    => $unitId,
            ];
        }

        return $prepared;
    }

    private function resolveCostCenter(array $row): array
    {
        $id = trim((string) ($row['cost_center_id'] ?? ''));
        $name = trim((string) ($row['cost_center_name'] ?? ''));

        if ($id === '' && $name === '') {
            return [null, 'cost_center_name or cost_center_id is required.'];
        }

        if ($id !== '') {
            $costCenterId = (int) $id;
            if (!isset(self::DB_MAP[$costCenterId])) {
                return [null, "Invalid cost_center_id: {$id}"];
            }
            if ($name !== '') {
                $fromName = $this->costCenterIdFromName($name);
                if ($fromName !== null && $fromName !== $costCenterId) {
                    return [null, "cost_center_name '{$name}' does not match cost_center_id {$id}."];
                }
            }
            return [$costCenterId, null];
        }

        $fromName = $this->costCenterIdFromName($name);
        if ($fromName === null) {
            return [null, "Cost center not found: {$name}"];
        }
        return [$fromName, null];
    }

    private function costCenterIdFromName(string $name): ?int
    {
        $alias = preg_replace('/\s+/', '', mb_strtolower($name));
        if (isset(self::BRANCH_ALIASES[$alias])) {
            return self::BRANCH_ALIASES[$alias];
        }
        $aliasLoose = mb_strtolower(trim($name));
        if (isset(self::BRANCH_ALIASES[$aliasLoose])) {
            return self::BRANCH_ALIASES[$aliasLoose];
        }

        $rows = $this->masterDb->query(
            'SELECT id FROM branch WHERE is_deleted = 0 AND (LOWER(name) = ? OR LOWER(code) = ?)',
            [$aliasLoose, $aliasLoose]
        )->getResultArray();
        if (count($rows) === 1) {
            $id = (int) $rows[0]['id'];
            return isset(self::DB_MAP[$id]) ? $id : null;
        }
        return null;
    }

    private function resolveOptionalLookup($db, string $table, $id, $name, string $label): array
    {
        $id = trim((string) $id);
        $name = trim((string) $name);
        if ($id === '' && $name === '') {
            return [null, null];
        }

        if ($id !== '') {
            $found = $this->findById($db, $table, $id);
            if ($found === null) {
                return [null, "{$label} id not found: {$id}"];
            }
            return [$found, null];
        }

        return $this->findByName($db, $table, $name, $label);
    }

    private function resolveItem($db, array $row, bool $required): array
    {
        $id = trim((string) ($row['item_id'] ?? ''));
        $code = trim((string) ($row['item_code'] ?? ''));
        $name = trim((string) ($row['item_name'] ?? ''));

        if ($id === '' && $code === '' && $name === '') {
            return $required
                ? [null, 'item_name, item_code, or item_id is required.']
                : [null, null];
        }

        if ($id !== '') {
            $found = $this->findById($db, 'item', $id);
            if ($found === null) {
                return [null, "Item id not found: {$id}"];
            }
            return [$found, null];
        }

        if ($code !== '') {
            if (ctype_digit($code)) {
                $found = $this->findById($db, 'item', $code);
                if ($found === null) {
                    return [null, "Item code/id not found: {$code}"];
                }
                return [$found, null];
            }
            return $this->findByName($db, 'item', $code, 'Item');
        }

        return $this->findByName($db, 'item', $name, 'Item');
    }

    private function resolveUnit($db, array $row, ?int $itemId): array
    {
        $id = trim((string) ($row['unit'] ?? ''));
        $name = trim((string) ($row['unit_name'] ?? ''));
        if ($id === '' && $name === '') {
            return [null, null];
        }

        if ($id !== '') {
            $found = $this->findById($db, 'item_conversion', $id);
            if ($found === null) {
                return [null, "Unit id not found: {$id}"];
            }
            return [$found, null];
        }

        $binds = [mb_strtolower($name)];
        $sql = 'SELECT id FROM item_conversion WHERE is_deleted = 0 AND LOWER(unit) = ?';
        if ($itemId) {
            $sql .= ' AND item_id = ?';
            $binds[] = $itemId;
        }
        $rows = $db->query($sql, $binds)->getResultArray();
        if (count($rows) === 0) {
            return [null, "Unit not found: {$name}"];
        }
        if (count($rows) > 1) {
            return [null, "Ambiguous unit name: {$name}"];
        }
        return [(int) $rows[0]['id'], null];
    }

    private function findById($db, string $table, $id): ?int
    {
        $row = $db->query(
            "SELECT id FROM {$table} WHERE id = ? AND is_deleted = 0",
            [$id]
        )->getRowArray();
        return $row ? (int) $row['id'] : null;
    }

    private function findByName($db, string $table, string $name, string $label): array
    {
        $rows = $db->query(
            "SELECT id FROM {$table} WHERE is_deleted = 0 AND LOWER(name) = ?",
            [mb_strtolower($name)]
        )->getResultArray();
        if (count($rows) === 0) {
            return [null, "{$label} not found: {$name}"];
        }
        if (count($rows) > 1) {
            return [null, "Ambiguous {$label} name: {$name}"];
        }
        return [(int) $rows[0]['id'], null];
    }

    private function branchContext(int $costCenterId): array
    {
        if (isset($this->contexts[$costCenterId])) {
            return $this->contexts[$costCenterId];
        }

        $db = db_connect(self::DB_MAP[$costCenterId]);
        $this->contexts[$costCenterId] = [
            'db'      => $db,
            'asset'   => new Asset($db),
            'reagent' => new Asset_reagent($db),
            'lab_test'=> new Asset_lab_test($db),
            'machine' => new Asset_lab_test_machine($db),
        ];
        return $this->contexts[$costCenterId];
    }

    private function parseSheet($spreadsheet, string $title): array
    {
        $sheet = $spreadsheet->getSheetByName($title);
        if (!$sheet) {
            return [];
        }

        $highestRow = (int) $sheet->getHighestDataRow();
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($highestRow < 1) {
            return [];
        }

        $headers = [];
        for ($col = 1; $col <= $highestCol; $col++) {
            $headers[$col] = $this->normalizeHeader(
                (string) $sheet->getCell(Coordinate::stringFromColumnIndex($col) . '1')->getValue()
            );
        }

        $rows = [];
        for ($rowNum = 2; $rowNum <= $highestRow; $rowNum++) {
            $row = ['_row' => $rowNum, '_sheet' => $title];
            $empty = true;
            for ($col = 1; $col <= $highestCol; $col++) {
                $key = $headers[$col] ?? '';
                if ($key === '') {
                    continue;
                }
                $value = $this->cellValue($sheet, $col, $rowNum);
                $row[$key] = $value;
                if ($value !== '') {
                    $empty = false;
                }
            }
            if (!$empty) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function cellValue($sheet, int $col, int $row): string
    {
        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
        if (Date::isDateTime($cell)) {
            $value = $cell->getValue();
            if ($value === null || $value === '') {
                return '';
            }
            return Date::excelToDateTimeObject($value)->format('Y-m-d');
        }

        $value = $cell->getCalculatedValue();
        if ($value === null || $value === '') {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (floor($value) == $value) {
                return (string) (int) $value;
            }
            return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }
        return trim((string) $value);
    }

    private function normalizeHeader($value): string
    {
        return strtolower(trim((string) $value));
    }

    private function isSampleKey(string $key): bool
    {
        return strncasecmp($key, 'SAMPLE', 6) === 0;
    }

    private function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed && $parsed->format('Y-m-d') === $value;
    }

    private function nullIfEmpty($value)
    {
        if ($value === null) {
            return null;
        }
        $value = is_string($value) ? trim($value) : $value;
        return $value === '' ? null : $value;
    }

    private function failResult(string $message): array
    {
        return [
            'status'   => 'failed',
            'message'  => $message,
            'asset_id' => null,
        ];
    }

    private function dbError($db): string
    {
        $error = $db->error();
        $message = $error['message'] ?? '';
        return $message !== '' ? $message : 'Unknown database error.';
    }

    private function addReport(string $sheet, $row, string $assetKey, string $status, string $message, $assetId = null): void
    {
        $this->reportRows[] = [
            'sheet'     => $sheet,
            'row'       => $row,
            'asset_key' => $assetKey,
            'status'    => $status,
            'message'   => $message,
            'asset_id'  => $assetId,
        ];
    }

    private function writeReport(string $sourceFile): string
    {
        $stamp = date('Ymd-His');
        $sourceDir = dirname((string) (realpath($sourceFile) ?: $sourceFile));
        $filename = "asset_import_report-{$stamp}.csv";
        $path = $sourceDir . DIRECTORY_SEPARATOR . $filename;

        $handle = @fopen($path, 'w');
        if (!$handle) {
            $fallbackDir = rtrim(ROOTPATH, '/\\') . '/docs/templates';
            if (!is_dir($fallbackDir)) {
                mkdir($fallbackDir, 0775, true);
            }
            $path = $fallbackDir . DIRECTORY_SEPARATOR . $filename;
            $handle = fopen($path, 'w');
        }

        fputcsv($handle, ['sheet', 'row', 'asset_key', 'status', 'message', 'asset_id']);
        foreach ($this->reportRows as $row) {
            fputcsv($handle, [
                $row['sheet'],
                $row['row'],
                $row['asset_key'],
                $row['status'],
                $row['message'],
                $row['asset_id'],
            ]);
        }
        fclose($handle);

        return $path;
    }
}
