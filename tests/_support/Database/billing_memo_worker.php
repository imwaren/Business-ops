<?php

chdir(dirname(__DIR__, 3));
require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

use Tests\Support\Controllers\Credit_memo_controller;
use Tests\Support\Controllers\Debit_memo_controller;
use Tests\Support\Database\Billing_memo_fixture;

Billing_memo_fixture::connect_existing($argv[1]);
$db = Billing_memo_fixture::connection();
$controller = $argv[2] === 'credit' ? new Credit_memo_controller($db) : new Debit_memo_controller($db);
echo json_encode($controller->change($argv[3]), JSON_THROW_ON_ERROR);
