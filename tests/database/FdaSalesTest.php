<?php

use App\Models\Report;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Tests\Support\Controllers\Fda_sales_controller;
use Tests\Support\Database\Fda_sales_fixture;

final class FdaSalesTest extends CIUnitTestCase
{
    private static $fixture_ready = false;
    private $model;
    private $controller;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (getenv('FDA_SALES_TEST_SOCKET')) {
            Fda_sales_fixture::create();
            self::$fixture_ready = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$fixture_ready) {
            $this->markTestSkipped('An explicit isolated FDA sales fixture socket is required.');
        }
        Fda_sales_fixture::reset();
        foreach (['master', 'mobile', 'oakridge', 'mactan', 'consolacion', 'mantlewood'] as $group) {
            if (!str_starts_with(db_connect($group)->getDatabase(), 'fda_sales_test_')) {
                throw new RuntimeException('Refusing a non-fixture FDA sales connection.');
            }
        }
        $this->model = new Report(db_connect('master'));
        $this->controller = new Fda_sales_controller($this->model);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture_ready) {
            Fda_sales_fixture::destroy();
        }
        parent::tearDownAfterClass();
    }

    private function sales(array $payload = [])
    {
        $response = $this->controller->sales($payload);
        $this->assertSame(200, $response['status']);
        return $response['data'];
    }

    private function csv($value)
    {
        return $value === null ? [] : str_getcsv($value, ',', '"', '');
    }

    public function testDailyTotalsRemainDecimalAndBranchLocal()
    {
        $sales = $this->sales();
        $this->assertSame(6, $sales['size']);
        $this->assertSame(1, $sales['total_pages']);
        $this->assertSame(['quantity' => '3.7500', 'price' => '5.30', 'amount' => '10.63'],
            array_intersect_key($sales['data'][0], array_flip(['quantity', 'price', 'amount'])));
        $row = $sales['data'][0];
        $this->assertSame('2026-10-15', $row['added_on']);
        $this->assertSame('Alice Q Dispenser', $row['dispense_by_name']);
        $this->assertSame('Drug A', $row['item_name']);
        $this->assertSame(['1', '2', '8'], $this->csv($row['id']));
        $this->assertSame(['1', '2'], $this->csv($row['patient_id']));
        $this->assertSame(['Doctor Alpha', 'Doctor Beta'], $this->csv($row['doctor_name']));
        $this->assertSame(['A, "quoted" note'], $this->csv($row['note']));
        $this->assertSame(['0012', '12', '0'], $this->csv($row['code']));
        $this->assertSame(['0'], $this->csv($row['fs']));
        $this->assertNull($row['updated_by']);
        $this->assertSame('Former User', $sales['data'][2]['dispense_by_name']);
        $this->assertNull($sales['data'][3]['dispense_by_name']);
        $this->assertNull($sales['data'][3]['item_name']);
        $this->assertSame('Bob Local', $sales['data'][4]['dispense_by_name']);
        $this->assertSame('Oakridge', $sales['data'][4]['branch_name']);
        $this->assertSame('4.0000', $sales['data'][4]['quantity']);
        $this->assertSame('2026-10-16', $sales['data'][5]['added_on']);
    }

    public function testFiltersApplyBeforeSummingAndCsvAssembly()
    {
        $filters = ['branch_id' => 1, 'item_id' => 10, 'date_from' => '2026-10-15', 'date_to' => '2026-10-15',
            'patient_id' => 2, 'brand' => 'Acme', 'generic' => 'Catalog A', 'dosage' => '10 mg',
            'form' => 'Tablet', 'kind' => 'other, laboratory, laboratory'];
        $sales = $this->sales($filters);
        $this->assertSame(1, $sales['size']);
        $row = $sales['data'][0];
        $this->assertSame('2.5000', $row['quantity']);
        $this->assertSame('3.20', $row['price']);
        $this->assertSame('8.00', $row['amount']);
        $this->assertSame('2', $row['id']);
        $this->assertSame(['Snapshot A'], $this->csv($row['generic']));
        $this->assertSame('Old brand', $this->csv($row['brand'])[0]);
        foreach (['brand' => 'Old brand', 'generic' => 'Snapshot A', 'dosage' => '5 mg', 'form' => 'Saved tablet', 'kind' => 'pharm'] as $field => $value) {
            $this->assertSame(0, $this->sales(array_replace($filters, [$field => $value]))['size']);
        }
        $this->assertSame(1, $this->sales(array_replace($filters, ['kind' => ' , ']))['size']);
    }

    public function testSearchCoversOnlyRequestedNamesAndRetainsBoundQuotes()
    {
        foreach (['Drug A' => 4, 'Alice Q Dispenser' => 3, 'Bob Local' => 1,
            'Doctor Beta' => 1, 'Patient B' => 1, 'doctor beta' => 1, 'Master' => 0, 'Acme' => 0,
            'Snapshot A' => 0, "' OR 1=1 --" => 0] as $term => $size) {
            $this->assertSame($size, $this->sales(['term' => $term])['size']);
        }
        $this->assertSame('2.5000', $this->sales(['term' => 'Doctor Beta'])['data'][0]['quantity']);
        $schema = Fda_sales_fixture::$schemas[1];
        Fda_sales_fixture::$pdo->exec("UPDATE `$schema`.dispense_medicine_details SET doctor_name='O''Brien 0' WHERE id=2");
        $this->assertSame('2.5000', $this->sales(['term' => "O'Brien"])['data'][0]['quantity']);
        $this->assertSame(1, $this->sales(['term' => '0'])['size']);
    }

    public function testGlobalPaginationCountsGroupsRatherThanDetails()
    {
        $all = $this->sales();
        $combined = [];
        foreach ([1, 2, 3] as $page) {
            $result = $this->sales(['page' => $page, 'data_size' => 2]);
            $this->assertSame(6, $result['size']);
            $this->assertSame(3, $result['total_pages']);
            $this->assertCount(2, $result['data']);
            $combined = array_merge($combined, $result['data']);
        }
        $this->assertSame($all['data'], $combined);
        $this->assertSame([], $this->sales(['page' => 4, 'data_size' => 2])['data']);
        $this->assertSame(1, $this->sales(['branch_id' => 2])['size']);
        $this->assertSame(0, $this->sales(['branch_id' => 7])['size']);
    }

    public function testAllConfiguredSourcesStayDistinctAndMasterRowsAreNotSales()
    {
        foreach ([0, 3, 4, 7] as $branch_id) {
            Fda_sales_fixture::insert($branch_id, ['id' => 1]);
        }
        $sales = $this->sales(['date_from' => '2026-10-15', 'date_to' => '2026-10-15', 'item_id' => 10]);
        $this->assertSame(6, $sales['size']);
        $this->assertSame([1, 1, 2, 3, 4, 7], array_map('intval', array_column($sales['data'], 'branch_id')));
        $this->assertNotContains('Master Q Dispenser', array_column($sales['data'], 'dispense_by_name'));
        $this->assertSame('1.0000', $this->sales(['branch_id' => 7])['data'][0]['quantity']);
        $this->assertSame(1, $this->sales(['date_from' => '2026-10-16', 'date_to' => '2026-10-16'])['size']);
    }

    public function testSchemaVariantsAndStrictGroupingKeepSourceColumnsAndNullGroups()
    {
        $schema = Fda_sales_fixture::$schemas[3];
        Fda_sales_fixture::$pdo->exec("ALTER TABLE `$schema`.dispense_medicine_details
            DROP COLUMN is_deleted, DROP COLUMN doctor_name, ADD COLUMN legacy_note VARCHAR(255) NULL");
        $schema = Fda_sales_fixture::$schemas[1];
        Fda_sales_fixture::$pdo->exec("ALTER TABLE `$schema`.dispense_medicine_details ADD COLUMN optional_code VARCHAR(255) NULL");
        Fda_sales_fixture::insert(1, ['id' => 9, 'optional_code' => 'AB123']);
        Fda_sales_fixture::insert(1, ['id' => 10, 'optional_code' => 'ab123']);
        Fda_sales_fixture::insert(1, ['id' => 11, 'optional_code' => '0012', 'added_by' => null, 'item_id' => null, 'added_on' => null]);
        $db = db_connect('master');
        $original_mode = $db->query('SELECT @@SESSION.sql_mode AS mode')->getRowArray()['mode'];
        $db->query('SET SESSION sql_mode=?', [implode(',', array_unique(array_merge(explode(',', $original_mode), ['ONLY_FULL_GROUP_BY'])))]);
        try {
            $sales = $this->sales();
            $this->assertSame(7, $sales['size']);
            $this->assertNull($sales['data'][0]['added_on']);
            $this->assertNull($sales['data'][0]['added_by']);
            $this->assertNull($sales['data'][0]['item_id']);
            $this->assertSame('0012', $sales['data'][0]['optional_code']);
            $this->assertSame(['AB123', 'ab123'], $this->csv($sales['data'][1]['optional_code']));
            $this->assertNull($sales['data'][5]['optional_code']);
            $this->assertArrayHasKey('legacy_note', $sales['data'][0]);
        } finally {
            $db->query('SET SESSION sql_mode=?', [$original_mode]);
        }
    }

    public function testIncompatibleNumericAndDateColumnsFailClearly()
    {
        $schema = Fda_sales_fixture::$schemas[1];
        foreach (['price', 'added_on'] as $column) {
            Fda_sales_fixture::reset();
            Fda_sales_fixture::$pdo->exec("ALTER TABLE `$schema`.dispense_medicine_details MODIFY `$column` VARCHAR(100) NULL");
            try {
                $this->sales(['branch_id' => 1]);
                $this->fail('Incompatible aggregation columns must be reported.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString($column, $error->getMessage());
            }
        }
    }

    public function testMissingOptionalMetadataPreservesSalesButDoesNotIgnoreFilters()
    {
        $schema = Fda_sales_fixture::$schemas[1];
        Fda_sales_fixture::$pdo->exec("DROP TABLE `$schema`.item, `$schema`.user");
        $sales = $this->sales(['branch_id' => 1]);
        $this->assertSame(5, $sales['size']);
        foreach ($sales['data'] as $row) {
            $this->assertNull($row['item_name']);
            $this->assertNull($row['dispense_by_name']);
        }
        $this->assertSame(0, $this->sales(['branch_id' => 1, 'brand' => 'Acme'])['size']);
        $this->assertSame(0, $this->sales(['branch_id' => 1, 'kind' => 'pharmacy'])['size']);
        $this->assertSame(1, $this->sales(['branch_id' => 1, 'term' => 'Patient B'])['size']);
        Fda_sales_fixture::$pdo->exec("ALTER TABLE `$schema`.dispense_medicine_details DROP COLUMN patient_id");
        $this->assertSame(0, $this->sales(['branch_id' => 1, 'patient_id' => 2])['size']);
    }

    public function testMissingDispensingTablesReturnEmptyWhileIncompatibleColumnsFail()
    {
        $schema = Fda_sales_fixture::$schemas[1];
        Fda_sales_fixture::$pdo->exec("ALTER TABLE `$schema`.dispense_medicine_details DROP COLUMN price");
        try {
            $this->sales(['branch_id' => 1]);
            $this->fail('Missing sums must not silently become zero.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('required column: price', $error->getMessage());
        }
        foreach (Fda_sales_fixture::$schemas as $branch_id => $database) {
            if ($branch_id !== 0) {
                Fda_sales_fixture::$pdo->exec("DROP TABLE `$database`.dispense_medicine_details");
            }
        }
        $this->assertSame(['data' => [], 'size' => 0, 'page' => 1, 'data_size' => 20, 'total_pages' => 0], $this->sales());
    }

    public function testLongCsvValuesNullsAndLargeDecimalsAreNotTruncatedOrRounded()
    {
        $schema = Fda_sales_fixture::$schemas[1];
        $long_note = str_repeat('Unicode ñ, "quoted"; ', 10000)."\nlast line";
        Fda_sales_fixture::insert(1, ['id' => 9, 'quantity' => null, 'price' => '9007199254740991.99', 'amount' => '9007199254740991.99', 'note' => $long_note]);
        $row = $this->sales(['branch_id' => 1, 'item_id' => 10, 'term' => 'Alice', 'date_from' => '2026-10-15', 'date_to' => '2026-10-15'])['data'][0];
        $this->assertSame('3.7500', $row['quantity']);
        $this->assertSame('9007199254740997.29', $row['price']);
        $this->assertSame('9007199254741002.62', $row['amount']);
        $this->assertSame(['A, "quoted" note', $long_note], $this->csv($row['note']));
        Fda_sales_fixture::$pdo->exec("UPDATE `$schema`.dispense_medicine_details SET quantity=NULL,price=NULL,amount=NULL WHERE added_by=1 AND item_id=10");
        $row = $this->sales(['branch_id' => 1, 'item_id' => 10, 'term' => 'Alice'])['data'][0];
        $this->assertSame('0.0000', $row['quantity']);
        $this->assertSame('0.00', $row['price']);
        $this->assertSame('0.00', $row['amount']);
    }

    public function testQueryFailuresRemainErrorsAndMetadataIsBatchedOnce()
    {
        $instances = new ReflectionProperty(Database::class, 'instances');
        $saved = $instances->getValue();
        $actual = db_connect('master');
        foreach (['information_schema.COLUMNS', 'SELECT COUNT(*)', 'SELECT fda_sales.*', 'SELECT dmd.*'] as $failure) {
            $calls = [];
            $connection = $this->getMockBuilder(\CodeIgniter\Database\MySQLi\Connection::class)
                ->disableOriginalConstructor()->onlyMethods(['query'])->getMock();
            $connection->method('query')->willReturnCallback(static function ($sql, $binds = []) use ($actual, $failure, &$calls) {
                $calls[] = $sql;
                return str_contains($sql, $failure) ? false : $actual->query($sql, $binds);
            });
            $instances->setValue(null, array_replace($saved, ['master' => $connection]));
            try {
                $this->model->get_fda_sales(null, 1, 20);
                $this->fail('Failed SQL must not become an empty report.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('FDA sales', $error->getMessage());
                $this->assertSame(1, count(array_filter($calls, static fn($sql) => str_contains($sql, 'information_schema.COLUMNS'))));
            } finally {
                $instances->setValue(null, $saved);
            }
        }
    }

    public function testCsvReadsAreBatchedOnlyForGroupsOnTheSelectedPage()
    {
        $instances = new ReflectionProperty(Database::class, 'instances');
        $saved = $instances->getValue();
        $actual = db_connect('master');
        $calls = [];
        $connection = $this->getMockBuilder(\CodeIgniter\Database\MySQLi\Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['query'])->getMock();
        $connection->method('query')->willReturnCallback(static function ($sql, $binds = []) use ($actual, &$calls) {
            $calls[] = [$sql, $binds];
            return $actual->query($sql, $binds);
        });
        $instances->setValue(null, array_replace($saved, ['master' => $connection]));
        try {
            $result = $this->sales(['data_size' => 1]);
            $this->assertCount(1, $result['data']);
            $this->assertCount(4, $calls);
            $this->assertStringContainsString('information_schema.COLUMNS', $calls[0][0]);
            $this->assertStringContainsString('SELECT COUNT(*)', $calls[1][0]);
            $this->assertStringContainsString('LIMIT ? OFFSET ?', $calls[2][0]);
            $this->assertStringContainsString('SELECT dmd.*', $calls[3][0]);
            $this->assertEquals(['1', '10', '2026-10-15'], $calls[3][1]);
            foreach ($calls as [$sql]) {
                $this->assertStringStartsWith('SELECT', $sql);
            }
        } finally {
            $instances->setValue(null, $saved);
        }
    }
}
