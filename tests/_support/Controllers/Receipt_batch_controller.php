<?php

namespace Tests\Support\Controllers;

use App\Controllers\Receipt_notes;
use ReflectionMethod;

class Receipt_batch_controller extends Receipt_notes
{
    public $authenticated = true;

    public function __construct($item_model = null, $expiry_model = null)
    {
        $this->receipt_note_item = $item_model;
        $this->receipt_note_item_expiry_date = $expiry_model;
    }

    public function validate_batches($items, $receipt_note_id = null)
    {
        $this->payload = ['items' => $items, 'requester' => 67];
        $method = new ReflectionMethod(Receipt_notes::class, '_attempt_validate_item_batches');
        $valid = $method->invoke($this, $receipt_note_id);
        return ['valid' => $valid, 'items' => $this->payload['items'], 'error' => $this->error_message];
    }

    public function save_items($receipt_note_id, $update = false)
    {
        $method = new ReflectionMethod(Receipt_notes::class, $update ? '_attempt_update_items' : '_attempt_create_items');
        return $method->invoke($this, $receipt_note_id, (object) ['id' => 1]);
    }

    protected function _api_validation()
    {
        return $this->authenticated ? true : ['status' => 401];
    }
}
