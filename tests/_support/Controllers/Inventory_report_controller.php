<?php

namespace Tests\Support\Controllers;

use App\Controllers\Items;
use RuntimeException;
use ZipArchive;

class Inventory_report_controller extends Items
{
    public $authenticated = true;
    public $resolved_branches = [];
    public $local_paths = [];
    public $uploads = [];
    public $upload_result = ['success' => true, 'path' => 'assets/mock/inventory.xlsx'];
    public $generation_failure = false;

    public function __construct($daily, $monthly, $branches)
    {
        $this->item_inventory_daily = $daily;
        $this->item_inventory_monthly = $monthly;
        $this->branch_master = $branches;
    }

    public function report($payload)
    {
        $this->payload = $payload;
        return $this->item_inventory_report();
    }

    public function export($payload)
    {
        $this->payload = $payload;
        return $this->item_inventory_report_excel();
    }

    protected function _api_validation()
    {
        return $this->authenticated ? true : ['status' => 401, 'data' => []];
    }

    protected function resolve_branch_models(int $branch_id)
    {
        $this->resolved_branches[] = $branch_id;
        return in_array($branch_id, [0, 1, 2, 3, 4, 5, 6, 7], true);
    }

    protected function _create_response($status, $data_response, $skip_response_logging = false)
    {
        return ['status' => $status, 'data' => $data_response];
    }

    protected function _attempt_write_item_inventory_report_excel(string $local_path, array $report, ?callable $fetch_batch)
    {
        $this->local_paths[] = $local_path;
        if ($this->generation_failure) {
            file_put_contents($local_path, 'incomplete fixture workbook');
            throw new RuntimeException('Fixture workbook failure');
        }
        return parent::_attempt_write_item_inventory_report_excel($local_path, $report, $fetch_batch);
    }

    protected function _upload_item_inventory_report_excel(string $local_path, string $file_name, string $vm4_path)
    {
        $zip = new ZipArchive();
        if ($zip->open($local_path) !== true) {
            throw new RuntimeException('Fixture expected a readable XLSX archive.');
        }
        $parts = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $parts[$name] = $zip->getFromIndex($index);
        }
        $zip->close();
        $this->uploads[] = compact('local_path', 'file_name', 'vm4_path', 'parts');
        if ($this->upload_result instanceof \Throwable) {
            throw $this->upload_result;
        }
        return $this->upload_result;
    }
}
