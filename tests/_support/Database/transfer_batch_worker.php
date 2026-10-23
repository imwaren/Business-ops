<?php

chdir(dirname(__DIR__, 3));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

use Tests\Support\Controllers\Transfer_batch_controller;
use Tests\Support\Database\Receipt_batch_fixture;

Receipt_batch_fixture::configure();
$controller = new Transfer_batch_controller();
if (($argv[1] ?? '') === 'receive') {
    $result = $controller->change(['transfer_id' => $argv[2], 'status' => 'received',
        'transfer_item_ids' => [$argv[3]], 'received_qtys' => [$argv[4]]]);
} elseif (($argv[1] ?? '') === 'submit_linked') {
    $result = $controller->submit(['branch_id_from' => 2, 'branch_id_to' => (int) $argv[2],
        'items' => [['item_id' => 1, 'branch_item_id' => 1, 'unit' => 11, 'transfer_qty' => 2,
            'batch_no' => '12', 'receipt_note_item_expiry_date_id' => 1]]]);
} else {
    $result = $controller->submit_legacy(['branch_id_from' => 2, 'branch_id_to' => (int) $argv[2],
        'items' => [['item_id' => 1, 'branch_item_id' => 1, 'unit' => 11, 'transfer_qty' => 6, 'batch_no' => 12]]]);
}
echo json_encode($result, JSON_THROW_ON_ERROR);
