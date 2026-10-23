<?php

namespace Tests\Support\Controllers;

use App\Controllers\Reports;

class Fda_sales_controller extends Reports
{
    public $authenticated = true;

    public function __construct($report)
    {
        $this->report = $report;
    }

    public function sales(array $payload = [])
    {
        $this->payload = $payload;
        return $this->get_fda_sales();
    }

    protected function _api_validation()
    {
        return $this->authenticated ? true : ['status' => 401, 'data' => ['Unauthorized']];
    }

    protected function _create_response($status, $data_response, $skip_response_logging = false)
    {
        return ['status' => $status, 'data' => $data_response];
    }
}
