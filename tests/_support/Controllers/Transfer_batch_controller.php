<?php

namespace Tests\Support\Controllers;

use App\Controllers\Items;

class Transfer_batch_controller extends Items
{
    public $authenticated = true;

    public function __construct()
    {
    }

    public function submit($payload)
    {
        $this->payload = $payload + ['requester' => 67];
        return $this->transfer();
    }

    public function details($payload = [])
    {
        $this->payload = $payload + ['requester' => 67];
        $this->branch_master = new \App\Models\Branch_master(db_connect('master'));
        $this->user = new \App\Models\User(db_connect('master'));
        return $this->get_transfer();
    }

    /**
     * Seed pre-linkage selections for the existing pooled-transfer regression scenarios.
     * New-submission tests must use submit(), which enforces the public endpoint contract.
     */
    public function submit_legacy($payload)
    {
        $this->payload = $payload + ['requester' => 67];
        $this->_load_transfer_models();
        $this->db->transBegin();
        $normalize = new \ReflectionMethod(Items::class, '_normalize_transfer_batch_no');
        foreach ($this->payload['items'] as $index => $item) {
            $batch_no = null;
            $arguments = [$item['batch_no'] ?? null, &$batch_no];
            if ($normalize->invokeArgs($this, $arguments)) {
                $this->payload['items'][$index]['batch_no'] = $batch_no;
            }
        }
        $validate = new \ReflectionMethod(Items::class, '_attempt_validate_transfer_items');
        if (!$validate->invoke($this, $this->payload['items'], $payload['branch_id_from'])) {
            $this->db->transRollback();
            return ['status' => 400, 'data' => [$this->error_message]];
        }
        if ($this->branch_item_transfer->select('', ['branch_id_from' => $payload['branch_id_from'],
            'branch_id_to' => $payload['branch_id_to'], 'status' => 'pending', 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            return ['status' => 400, 'data' => ['There is still a pending transfer.']];
        }
        $insert_header = new \ReflectionMethod(Items::class, '_attempt_transfer');
        $insert_items = new \ReflectionMethod(Items::class, '_attempt_transfer_items');
        $id = $insert_header->invoke($this);
        if (!$id || !$insert_items->invoke($this, $id) || !$this->db->transStatus() || !$this->db->transCommit()) {
            $this->db->transRollback();
            return ['status' => 400, 'data' => ['Failed to seed legacy transfer.']];
        }
        return ['status' => 200, 'data' => ['branch_item_transfer_id' => $id]];
    }

    public function change($payload)
    {
        $this->payload = $payload + ['requester' => 67];
        return $this->transfer_change_status();
    }

    public function report($payload)
    {
        $this->payload = $payload + ['requester' => 67];
        return $this->transfer_report();
    }

    public function filters($payload = [])
    {
        $this->payload = $payload + ['requester' => 67];
        return $this->get_item_filters();
    }

    public function batches($payload)
    {
        $this->payload = $payload + ['requester' => 67];
        return $this->get_item_batches();
    }

    protected function _load_transfer_models()
    {
        parent::_load_transfer_models();
        $this->branch_item_transfer = new class($this->db) extends \App\Models\Branch_item_transfer {
            protected function _resolve_cross_db_sql(string $sql): string
            {
                return str_replace('{MASTER_DB}', $this->db->getDatabase(), $sql);
            }
        };
    }

    protected function _api_validation()
    {
        return $this->authenticated ? true : ['status' => 401, 'data' => []];
    }

    protected function _create_response($status, $data_response, $skip_response_logging = false)
    {
        return ['status' => $status, 'data' => $data_response];
    }
}
