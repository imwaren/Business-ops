<?php

namespace Tests\Support\Controllers;

use App\Controllers\Billings;
use App\Models\Billing;
use App\Models\Credit_memo;
use App\Models\Debit_memo;
use ReflectionMethod;

class Billing_memo_controller extends Billings
{
    public $authenticated = true;

    public function __construct($db, $billing = null, $credit_memo = null, $debit_memo = null)
    {
        $this->db = $db;
        $this->billing = $billing ?? new Billing($db);
        $this->credit_memo = $credit_memo ?? new Credit_memo($db);
        $this->debit_memo = $debit_memo ?? new Debit_memo($db);
        $this->billing_payment = new class {
            public $rows = [];
            public function get_details_for_billing_ids(array $ids): array
            {
                return array_values(array_filter($this->rows, static function ($row) use ($ids) {
                    return in_array($row['billing_id'], $ids);
                }));
            }
        };
        $this->billing_entry = new class {
            public function get_details_for_billing_ids(array $ids): array
            {
                return ['entries' => []];
            }
        };
    }

    public function billings(array $payload = [], array $payments = [])
    {
        $this->payload = $payload;
        $this->billing_payment->rows = $payments;
        return $this->get();
    }

    public function create_billing(array $payload)
    {
        $this->payload = $payload + ['requester' => 7];
        return (new ReflectionMethod(Billings::class, '_attempt_create'))->invoke($this);
    }

    protected function _companies_by_ids(array $ids): array
    {
        return [];
    }

    protected function _branch_names(): array
    {
        return [2 => 'Fixture Oakridge'];
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
