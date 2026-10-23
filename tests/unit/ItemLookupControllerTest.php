<?php

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Controllers\Transfer_batch_controller;

final class ItemLookupControllerTest extends CIUnitTestCase
{
    /** @dataProvider invalid_batch_payloads */
    public function testBatchLookupValidatesBeforeConnecting($payload)
    {
        $response = (new Transfer_batch_controller())->batches($payload);
        $this->assertSame(400, $response['status']);
    }

    public function invalid_batch_payloads()
    {
        $valid = ['item_id' => 1, 'branch_id_from' => 2, 'date' => '2028-01-01'];
        $cases = [
            'missing item' => ['item_id' => null],
            'zero item' => ['item_id' => 0],
            'array item' => ['item_id' => []],
            'boolean item' => ['item_id' => true],
            'missing branch' => ['branch_id_from' => null],
            'master branch' => ['branch_id_from' => 0],
            'unknown branch' => ['branch_id_from' => 8],
            'array branch' => ['branch_id_from' => []],
            'missing date' => ['date' => null],
            'blank date' => ['date' => ''],
            'array date' => ['date' => []],
            'invalid leap day' => ['date' => '2027-02-29'],
            'invalid day' => ['date' => '2028-04-31'],
            'wrong date format' => ['date' => '2028-1-1'],
            'null byte date' => ['date' => "2028-01-01\0"],
            'zero year' => ['date' => '0000-01-01'],
        ];
        foreach ($cases as $name => $changes) {
            $cases[$name] = [array_replace($valid, $changes)];
        }
        return $cases;
    }

    public function testReportRejectsNonStringFiltersBeforeConnecting()
    {
        foreach (['brand', 'generic', 'dosage', 'form', 'kind', 'term'] as $field) {
            foreach ([[], true, 10] as $value) {
                $response = (new Transfer_batch_controller())->report([$field => $value]);
                $this->assertSame(400, $response['status']);
                $this->assertSame([$field.' must be a string or null.'], $response['data']);
            }
        }
    }

    public function testReportRejectsInvalidTermsBeforeConnecting()
    {
        foreach ([[], false, true, 0, 10, 1.5, (object) []] as $term) {
            $response = (new Transfer_batch_controller())->report(['term' => $term]);
            $this->assertSame(400, $response['status']);
            $this->assertSame(['term must be a string or null.'], $response['data']);
        }
    }

    public function testFilterQueryFailureIsNotAnEmptySuccess()
    {
        $controller = new class extends Transfer_batch_controller {
            protected function resolve_branch_models(int $branch_id)
            {
                $this->item = new class {
                    public function get_item_filters() { return false; }
                };
                return true;
            }
        };
        $this->assertSame(['status' => 400, 'data' => ['Failed to load item filters.']], $controller->filters());
    }

    public function testNewReadEndpointsStillRequireAuthentication()
    {
        $controller = new Transfer_batch_controller();
        $controller->authenticated = false;
        $this->assertSame(401, $controller->filters()['status']);
        $this->assertSame(401, $controller->batches([])['status']);
        $this->assertSame(401, $controller->report([])['status']);
    }
}
