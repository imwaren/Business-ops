<?php

namespace Tests\Support\Controllers;

use App\Controllers\Debit_memos;
use App\Models\Debit_memo;
use App\Models\Billing;

class Debit_memo_controller extends Debit_memos
{
    public $authenticated = true;

    public function __construct($db, $memo = null, $billing = null, $transaction_db = null)
    {
        $this->db = $transaction_db ?? $db;
        $this->debit_memo = $memo ?? new Debit_memo($db);
        $this->billing = $billing ?? new Billing($db);
    }

    public function change($memo_id, $status = 'approved')
    {
        $this->payload = ['debit_memo_id' => $memo_id, 'status' => $status, 'requester' => 7];
        return $this->change_status();
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
