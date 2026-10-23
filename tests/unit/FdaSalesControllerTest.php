<?php

use App\Models\Report;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Fda_sales_controller;

final class FdaSalesControllerTest extends CIUnitTestCase
{
    /** @dataProvider invalid_payloads */
    public function testInvalidPayloadsFailBeforeQueries($payload, $message)
    {
        $model = $this->getMockBuilder(Report::class)->disableOriginalConstructor()->onlyMethods(['get_fda_sales'])->getMock();
        $model->expects($this->never())->method('get_fda_sales');
        $response = (new Fda_sales_controller($model))->sales($payload);
        $this->assertSame(400, $response['status']);
        $this->assertSame([$message], $response['data']);
    }

    public function invalid_payloads()
    {
        $cases = [];
        foreach (['branch_id', 'patient_id', 'item_id', 'page', 'data_size', 'page_size'] as $field) {
            foreach ([0, -1, '1.5', 'abc', true, [], '999999999999999999999999'] as $index => $value) {
                $cases[$field.' '.$index] = [[$field => $value], $field.' must be a positive integer.'];
            }
        }
        foreach (['date_from', 'date_to', 'term', 'brand', 'generic', 'dosage', 'form', 'kind'] as $field) {
            $cases[$field.' array'] = [[$field => []], $field.' must be a string or null.'];
        }
        $cases['unsupported branch'] = [['branch_id' => 6], 'Invalid branch_id. Allowed values: 1, 2, 3, 4 and 7.'];
        $cases['partial range'] = [['date_from' => '2026-10-15'], 'Both date_from and date_to are required when filtering by date.'];
        $cases['invalid date'] = [['date_from' => '2026-02-30', 'date_to' => '2026-10-15'], 'Dates must be valid and use YYYY-MM-DD format.'];
        $cases['invalid year'] = [['date_from' => '0000-01-01', 'date_to' => '2026-10-15'], 'Dates must be valid and use YYYY-MM-DD format.'];
        $cases['reverse range'] = [['date_from' => '2026-10-16', 'date_to' => '2026-10-15'], 'date_from must not be later than date_to.'];
        return $cases;
    }

    public function testControllerForwardsFiltersAndPreservesStringZero()
    {
        $model = $this->getMockBuilder(Report::class)->disableOriginalConstructor()->onlyMethods(['get_fda_sales'])->getMock();
        $expected = ['data' => [], 'size' => 0, 'page' => 2, 'data_size' => 5, 'total_pages' => 0];
        $model->expects($this->once())->method('get_fda_sales')
            ->with(2, 2, 5, '2026-10-15', '2026-10-16', 7, 10, '0', 'Acme', 'Catalog A', '10 mg', 'Tablet', 'pharmacy,laboratory')
            ->willReturn($expected);
        $response = (new Fda_sales_controller($model))->sales(['branch_id' => '2', 'page' => '2', 'data_size' => '5',
            'date_from' => '2026-10-15', 'date_to' => '2026-10-16', 'patient_id' => '7', 'item_id' => '10',
            'term' => ' 0 ', 'brand' => 'Acme', 'generic' => 'Catalog A', 'dosage' => '10 mg', 'form' => 'Tablet', 'kind' => 'pharmacy,laboratory']);
        $this->assertSame(['status' => 200, 'data' => $expected], $response);
    }

    public function testDefaultsAndEmptyFiltersMatchFdaPagination()
    {
        foreach ([[], ['branch_id' => '', 'date_from' => null, 'date_to' => '', 'term' => '  ']] as $payload) {
            $model = $this->getMockBuilder(Report::class)->disableOriginalConstructor()->onlyMethods(['get_fda_sales'])->getMock();
            $model->expects($this->once())->method('get_fda_sales')->with(null, 1, 20,
                null, null, null, null, null, null, null, null, null, null)->willReturn(['data' => [], 'size' => 0]);
            $this->assertSame(200, (new Fda_sales_controller($model))->sales($payload)['status']);
        }
    }

    public function testSalesRequiresAuthentication()
    {
        $model = $this->getMockBuilder(Report::class)->disableOriginalConstructor()->onlyMethods(['get_fda_sales'])->getMock();
        $model->expects($this->never())->method('get_fda_sales');
        $controller = new Fda_sales_controller($model);
        $controller->authenticated = false;
        $this->assertSame(401, $controller->sales()['status']);
    }

    public function testCorePageSizeAliasStillUsesValidatedPagination()
    {
        $model = $this->getMockBuilder(Report::class)->disableOriginalConstructor()->onlyMethods(['get_fda_sales'])->getMock();
        $model->expects($this->once())->method('get_fda_sales')->with(null, 1, 4,
            null, null, null, null, null, null, null, null, null, null)->willReturn(['data' => [], 'size' => 0]);
        $this->assertSame(200, (new Fda_sales_controller($model))->sales(['page_size' => '4'])['status']);
    }
}
