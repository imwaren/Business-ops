<?php

namespace App\Controllers;

use App\Core\MYT_Controller;

use App\Models\Branch_item_transfer;
use App\Models\Branch_item_transfer_item;

use App\Models\User;
use App\Models\Branch_master;
use App\Models\Account_type;
use App\Controllers\Traits\Weighted_cost;
use App\Controllers\Traits\Inventory_snapshot;
use App\Models\Branch_item_blindsheet;
use App\Libraries\Local_upload_file;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Items extends MYT_Controller
{
    use Weighted_cost;
    use Inventory_snapshot;
    protected $request;
    protected $item;
    protected $item_conversion;
    protected $branch_item;
    protected $item_cost;
    protected $item_inventory_daily;
    protected $item_inventory_monthly;
    protected $branch_item_adjustment;
    protected $branch_item_blindsheet;
    protected $csr_items_authenticated = false;
    protected $blindsheet_db;
    protected array $blindsheet_schemas = [];
    protected $blindsheet_id;
    protected $blindsheet_master;
    protected $blindsheet_lock_name;
    protected $blindsheet_error_status = FAIL;
    protected $branch_item_transfer;
    protected $branch_item_transfer_item;
    protected $transfer_items = [];
    protected $transfer_receipts = [];
    protected $user;
    protected $branch_master;
    protected $account_type;
    protected $purchase_order;
    protected $purchase_order_item;
    protected $helpers = ['text'];

    protected const INVENTORY_CONVERSION_DESCRIPTIONS = [
        'purchase' => 'Unit of measure used when procuring items from suppliers.',
        'clinic'   => 'Unit of measure used by the clinic for inventory counting and sales transactions.',
        'usage'    => 'Unit of measure applied when the item is consumed internally during operations or service delivery.',
    ];

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        $this->item = new \App\Models\Item();
        $this->item_conversion = new \App\Models\Item_conversion();
        $this->branch_item = new \App\Models\Branch_item();
        $this->item_cost = new \App\Models\Item_cost();
        $this->item_inventory_daily = new \App\Models\Item_inventory_daily();
        $this->item_inventory_monthly = new \App\Models\Item_inventory_monthly();
        $this->branch_item_adjustment = new \App\Models\Branch_item_adjustment();
        $this->branch_item_blindsheet = new \App\Models\Branch_item_blindsheet();

        $this->branch_item_transfer = new Branch_item_transfer();
        $this->branch_item_transfer_item = new Branch_item_transfer_item();
        $this->user = new User();
        $this->branch_master  = new Branch_master();
        $this->account_type = new Account_type();

        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }
    
    /**
     * Branch ids helper to resolve models based on branch id. This is to be used in the constructor of each method to set the correct models based on the branch id passed in the payload. The branch id should be passed in the payload of each request and should be used to determine which models to use for the request. If the branch id is not passed or is invalid, it should return an error response.
     */
    protected function resolve_branch_models(int $branch_id)
    {
        $dbMap = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        if (!isset($dbMap[$branch_id])) {
            return false;
        }

        $db = db_connect($dbMap[$branch_id]);

        $this->item = new \App\Models\Item($db);
        $this->item_conversion = new \App\Models\Item_conversion($db);
        $this->branch_item = new \App\Models\Branch_item($db);
        $this->item_cost = new \App\Models\Item_cost($db);
        $this->item_inventory_daily = new \App\Models\Item_inventory_daily($db);
        $this->item_inventory_monthly = new \App\Models\Item_inventory_monthly($db);
        $this->branch_item_adjustment = new \App\Models\Branch_item_adjustment($db);
        $this->branch_item_blindsheet = new \App\Models\Branch_item_blindsheet($db);

        $this->purchase_order = new \App\Models\Purchase_order($db);
        $this->purchase_order_item = new \App\Models\Purchase_order_item($db);

        return true;
    }

    /**
     * Physical database branch ids used for inventory mirroring.
     */
    protected function inventory_db_ids(): array
    {
        return [0, 1, 2, 3, 4, 7, 5, 6];
    }

    /**
     * Allocate a globally free id (MAX across all inventory DBs + 1), then insert
     * the same row with that explicit id into every physical DB.
     */
    protected function mirror_insert(string $modelProperty, array $values): int|false
    {
        $values['id'] = $this->_get_global_max_id($modelProperty) + 1;

        foreach ($this->inventory_db_ids() as $branch_id) {
            $this->resolve_branch_models($branch_id);
            if (!$this->{$modelProperty}->insert($values)) {
                $this->error_message = $this->{$modelProperty}->error();
                return false;
            }
        }

        return $values['id'];
    }

    /**
     * MAX(id) across all physical inventory databases for a model.
     */
    protected function _get_global_max_id(string $modelProperty): int
    {
        $maxId = 0;
        foreach ($this->inventory_db_ids() as $branch_id) {
            $this->resolve_branch_models($branch_id);
            $row = $this->{$modelProperty}->select('MAX(id) AS max_id', null, 1);
            if ($row && isset($row->max_id) && (int)$row->max_id > $maxId) {
                $maxId = (int)$row->max_id;
            }
        }

        return $maxId;
    }

    /**
     * Insert the same batch of rows (with explicit ids) into every physical inventory DB.
     */
    protected function _mirror_insert_batch(string $modelProperty, array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        foreach ($this->inventory_db_ids() as $branch_id) {
            $this->resolve_branch_models($branch_id);
            if ($this->{$modelProperty}->insert_batch($rows) === false) {
                $this->error_message = $this->{$modelProperty}->error()
                    ?: ('Failed batch insert for ' . $modelProperty . ' on DB ' . $branch_id);
                return false;
            }
        }

        return true;
    }

    /**
     * Run the same update on every physical inventory database.
     */
    protected function mirror_update(string $modelProperty, array $where, array $values): bool
    {
        foreach ($this->inventory_db_ids() as $branch_id) {
            $this->resolve_branch_models($branch_id);
            if (!$this->{$modelProperty}->update($where, $values)) {
                $this->error_message = $this->{$modelProperty}->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Convert display labels (e.g. "Consumable - Direct Materials") to snake_case keys.
     */
    private function _label_to_snake(string $label): string
    {
        $label = trim(rtrim(trim($label), '.'));
        $label = preg_replace('/\s*-\s*/', '_', $label);
        $label = preg_replace('/\s+/', '_', $label);

        return strtolower($label);
    }

    /**
     * Get items
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->item->select('id', ['is_deleted' => 0]) ?? []
        );

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        foreach ($branches as $branch_id => $branch_name) {

            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->item->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        $item_id = $this->payload['item_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        $kind = $this->payload['kind'] ?? null;
        $status = $this->payload['status'] ?? null;
        $category = $this->payload['category'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $screen = $this->payload['screen'] ?? null;

        $items = $this->item->get_details($item_id, $type, $kind, $status, $category, $term, $page, $data_size)?:[];

        if(!$items) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No items found.'
            ];
        } else {
            // Fetch conversions for each item
            // Fetch conversions and branches for each item
            foreach ($items as &$item) {

                // Always use master for conversions
                $this->resolve_branch_models(0);
                $item['conversions'] = $this->item_conversion->get_details($item['id']) ?: [];

                if (empty($item['conversions'])) {
                    $this->resolve_branch_models(1); //use oakridge if empty
                    $item['conversions'] = $this->item_conversion->get_details($item['id']) ?: [];
                }

                $item_branches = [];

                $branches = [0, 1, 2, 3, 4, 7, 5, 6]; // master + all branches

                foreach ($branches as $branch_id) {

                    $this->resolve_branch_models($branch_id);

                    $branch_records = $this->branch_item->get_details($item['id']) ?: [];

                    if (!empty($branch_records)) {
                        $item_branches = array_merge($item_branches, $branch_records);
                    }
                }

                // Attach branch name + type
                foreach ($item_branches as &$branch_item) {

                    $branch_data = $this->branch_master->select('id, name, type', ['id' => $branch_item['branch_id']], 1) ?: null;

                    $branch_item['branch_name'] = $branch_data ? $branch_data->name : null;
                    $branch_item['branch_type'] = $branch_data ? $branch_data->type : null;
                }

                $item['branches'] = $item_branches;

                if ($screen && $screen == 'purchase_request') {
                    $branch_id = $this->payload['branch_id'] ?? null;
                    if (!$branch_id) {
                        return $this->_create_response(
                            FAIL,
                            ['Branch ID is required.']
                        );
                    }
            
                    if (!$this->resolve_branch_models($branch_id)) {
                        return $this->_create_response(
                            FAIL,
                            ['Invalid Branch ID.']
                        );
                    }
                    
                    $item['amount'] = $item['cost'];

                    $purchase_order_item = $this->purchase_order_item->get_prev_po_details(null, $item['id'])?:[];
                    if ($purchase_order_item) {
                        $item['amount'] = $purchase_order_item[0]['amount'];
                    }
                }
            }
            $response_status = SUCCESS;
            $response = [
                'size' => count($this->item->get_details($item_id, $type, $kind, $status, $category, $term, null, null)?:[]),
                'data' => $items
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get item list
     */
    public function get_items_list()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $item_id = $this->payload['item_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        $kind = $this->payload['kind'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $is_canvassing = $this->payload['is_canvassing'] ?? null;
        $status = $this->payload['status'] ?? null;
        $category = $this->payload['category'] ?? null;
        $conversion_type = $this->payload['conversion_type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $screen = $this->payload['screen'] ?? null;
        $is_purchase_request = $screen === 'purchase_request';
        $has_branch_id = $branch_id !== null && $branch_id !== '';

        if (!$has_branch_id && !$is_purchase_request) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if ($has_branch_id && !$this->resolve_branch_models((int) $branch_id)) {
            return $this->_create_response(FAIL, ['Invalid Branch ID.']);
        }

        $items = [];
        if (!$has_branch_id && $is_purchase_request) {
            foreach ($this->inventory_db_ids() as $inventory_branch_id) {
                $this->resolve_branch_models($inventory_branch_id);
                $branch_items = $this->item->get_list(
                    $item_id,
                    $type,
                    $kind,
                    $inventory_branch_id,
                    $is_canvassing,
                    $status,
                    $category,
                    $conversion_type,
                    $term,
                    null,
                    null
                ) ?: [];
                $items = array_merge($items, $branch_items);
            }

            usort($items, static function ($left, $right) {
                $name_order = strcasecmp((string) ($left['item_name'] ?? ''), (string) ($right['item_name'] ?? ''));
                return $name_order !== 0
                    ? $name_order
                    : ((int) ($left['branch_id'] ?? 0) <=> (int) ($right['branch_id'] ?? 0));
            });
            $size = count($items);

            if ($page !== null || $data_size !== null) {
                $page_number = max(1, (int) ($page ?? 1));
                $page_size = (int) $data_size;
                $page_size = $page_size > 0 ? min($page_size, 100) : 20;
                $items = array_slice($items, ($page_number - 1) * $page_size, $page_size);
            }
        } else {
            $items = $this->item->get_list(
                $item_id,
                $type,
                $kind,
                $branch_id,
                $is_canvassing,
                $status,
                $category,
                $conversion_type,
                $term,
                $page,
                $data_size
            ) ?: [];
            $size = count($this->item->get_list(
                $item_id,
                $type,
                $kind,
                $branch_id,
                $is_canvassing,
                $status,
                $category,
                $conversion_type,
                $term,
                null,
                null
            ) ?: []);
        }

        if(!$items) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No items found.'
            ];
        } else {
            foreach ($items as &$item) {
                $item_branch_id = (int) ($item['branch_id'] ?? 0);
                if (!$has_branch_id && $is_purchase_request) {
                    $this->resolve_branch_models($item_branch_id);
                }

                $branch = $this->branch_master->select('name', ['id' => $item_branch_id, 'is_deleted' => 0], 1) ?: null;
                $item['branch_name'] = $branch ? $branch->name : null;

                if ($is_purchase_request) {
                    $item['amount'] = $item['current_cost'];

                    $purchase_order_item = $this->purchase_order_item->get_prev_po_details(null, $item['item_id'])?:[];
                    if ($purchase_order_item) {
                        $item['amount'] = $purchase_order_item[0]['amount'];
                    }
                }

                $item['conversions'] = $this->item_conversion->get_details($item['item_id']) ?: [];
            }
            unset($item);
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $items
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Inventory valuation report.
     * tab=breakdown requires date_from, date_to, branch_id (reads daily snapshots).
     * tab=per_branch requires date_from, date_to (reads monthly rollups on master).
     */
    public function item_inventory_report()
    {
        return $this->_item_inventory_report_response();
    }

    /**
     * Export the selected inventory report tab and return its VM4 download link.
     * GET items/item_inventory_report_excel
     */
    public function item_inventory_report_excel()
    {
        return $this->_item_inventory_report_response(true);
    }

    protected function _item_inventory_report_response(bool $export_excel = false)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $tab = $this->payload['tab'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;

        if (!in_array($tab, ['breakdown', 'per_branch'], true)) {
            return $this->_create_response(FAIL, ['tab is required. Allowed values: breakdown, per_branch.']);
        }

        if (!$date_from || !$date_to) {
            return $this->_create_response(FAIL, ['date_from and date_to are required.']);
        }

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_from)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_to)
        ) {
            return $this->_create_response(FAIL, ['Invalid date. Use YYYY-MM-DD.']);
        }

        if ($date_from > $date_to) {
            return $this->_create_response(FAIL, ['date_from must not be after date_to.']);
        }

        if ($tab === 'breakdown') {
            return $this->_item_inventory_report_breakdown($date_from, $date_to, $export_excel);
        }

        return $this->_item_inventory_report_per_branch($date_from, $date_to, $export_excel);
    }

    protected function _item_inventory_report_breakdown(string $date_from, string $date_to, bool $export_excel = false)
    {
        $branch_id = $this->payload['branch_id'] ?? null;
        if ($branch_id === null || $branch_id === '' || (int)$branch_id === 0) {
            return $this->_create_response(FAIL, ['Branch ID is required.']);
        }

        $branch_id = (int)$branch_id;
        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(FAIL, ['Invalid Branch ID.']);
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $live = $date_from === $today && $date_to === $today;
        $snapshot_date = $live ? $today
            : $this->item_inventory_daily->latest_date_in_range($branch_id, $date_from, $date_to);
        if (!$snapshot_date) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No inventory snapshot found for the selected dates.']);
        }

        $type = $this->payload['type'] ?? null;
        $kind = $this->payload['kind'] ?? null;
        $philhealth_medicine_type = $this->payload['philhealth_medicine_type'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;
        $conversion_type = $this->payload['conversion_type'] ?? 'purchase';

        if (!in_array($conversion_type, ['purchase', 'clinic', 'usage'], true)) {
            return $this->_create_response(FAIL, ['Invalid conversion_type. Allowed values: purchase, clinic, usage.']);
        }

        $rows = $this->item_inventory_daily->get_breakdown(
            $branch_id,
            $snapshot_date,
            $type,
            $kind,
            $term,
            $export_excel ? 1 : $page,
            $export_excel ? 100 : $data_size,
            $conversion_type,
            $philhealth_medicine_type,
            $live
        );

        if ($export_excel && $rows === false) {
            return $this->_create_response(FAIL, ['Unable to retrieve inventory report rows.']);
        }
        $rows = $rows ?: [];

        $size = $live ? $this->item_inventory_daily->get_breakdown(
            $branch_id, $snapshot_date, $type, $kind, $term, null, null,
            $conversion_type, $philhealth_medicine_type, true, true
        ) : ($export_excel ? count($rows) : count($this->item_inventory_daily->get_breakdown(
            $branch_id,
            $snapshot_date,
            $type,
            $kind,
            $term,
            null,
            null,
            $conversion_type,
            $philhealth_medicine_type
        ) ?: []));
        if ($live && $size === false) {
            return $this->_create_response(FAIL, ['Unable to retrieve inventory report rows.']);
        }

        if (!$rows) {
            return $this->_create_response(FAIL_NOT_FOUND, ['No inventory found.']);
        }

        $report = [
            'tab'           => 'breakdown',
            'snapshot_date' => $snapshot_date,
            'date_from'     => $date_from,
            'date_to'       => $date_to,
            'size'          => $size,
            'data'          => $rows,
        ];
        if ($export_excel) {
            $branch = $this->branch_master->select('name', ['id' => $branch_id, 'is_deleted' => 0], 1);
            $report['branch_id'] = $branch_id;
            $report['branch_name'] = $branch->name ?? ('Branch '.$branch_id);
            $report['conversion_type'] = $conversion_type;
            return $this->_export_item_inventory_report_excel($report, function (int $page) use (
                $branch_id, $snapshot_date, $type, $kind, $term, $conversion_type, $philhealth_medicine_type, $live
            ) {
                return $this->item_inventory_daily->get_breakdown(
                    $branch_id, $snapshot_date, $type, $kind, $term, $page, 100,
                    $conversion_type, $philhealth_medicine_type, $live
                );
            });
        }
        return $this->_create_response(SUCCESS, $report);
    }

    protected function _item_inventory_report_per_branch(string $date_from, string $date_to, bool $export_excel = false)
    {
        $this->resolve_branch_models(0);
        $stored = $this->item_inventory_monthly->get_months_in_range($date_from, $date_to);

        $monthDefs = $this->_inventory_months_in_range($date_from, $date_to);
        $monthKeys = array_column($monthDefs, 'key');

        $byBranchMonth = [];
        foreach ($stored as $row) {
            $monthKey = date('F', strtotime($row['snapshot_month']));
            $byBranchMonth[(int)$row['branch_id']][$monthKey] = (float)$row['ending_cost'];
        }

        $names = [];
        $branches = $this->branch_master->select('id, name', ['is_deleted' => 0]) ?: [];
        foreach ($branches as $branch) {
            $names[(int)$branch->id] = $branch->name;
        }

        $defaultNames = [
            1 => 'Mobile',
            2 => 'Oakridge',
            3 => 'Mactan',
            4 => 'Consolacion',
            5 => 'Warehouse',
            6 => 'Pharmacy',
            7 => 'Mantlewood',
        ];

        $branchRows = [];
        $total = [];
        foreach ($monthKeys as $monthKey) {
            $total[$monthKey] = 0.0;
        }

        foreach ([4, 2, 3, 7, 1, 5, 6] as $id) {
            $row = [
                'branch_id'   => $id,
                'branch_name' => $names[$id] ?? $defaultNames[$id] ?? ('Branch ' . $id),
            ];
            foreach ($monthKeys as $monthKey) {
                $value = $byBranchMonth[$id][$monthKey] ?? 0.0;
                $row[$monthKey] = $value;
                $total[$monthKey] += $value;
            }
            $branchRows[] = $row;
        }

        $report = [
            'tab'       => 'per_branch',
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'months'    => $monthKeys,
            'branches'  => $branchRows,
            'total'     => $total,
        ];
        if ($export_excel) {
            return $this->_export_item_inventory_report_excel($report);
        }
        return $this->_create_response(SUCCESS, $report);
    }

    /**
     * Build a private workbook and upload it using the existing report-file convention.
     */
    protected function _export_item_inventory_report_excel(array $report, ?callable $fetch_batch = null)
    {
        if (!class_exists(\ZipArchive::class)) {
            return $this->_create_response(FAIL, ['PHP ZIP extension is required to generate Excel files.']);
        }

        $local_path = null;
        try {
            $dir = WRITEPATH.'item_inventory_reports/';
            if ((!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) || !is_writable($dir)) {
                return $this->_create_response(FAIL, ['Unable to create temporary Excel directory.']);
            }
            $local_path = tempnam($dir, 'inventory_report_');
            if ($local_path === false) {
                return $this->_create_response(FAIL, ['Unable to create temporary Excel file.']);
            }
            $file_name = 'Item_inventory_'.$report['tab'].'_'.$report['date_from'].'_'.$report['date_to']
                .'_'.bin2hex(random_bytes(8)).'.xlsx';
            if (!$this->_attempt_write_item_inventory_report_excel($local_path, $report, $fetch_batch)) {
                return $this->_create_response(FAIL, [$this->error_message]);
            }

            $vm4_path = 'assets/reports/item_inventory/'.$report['tab'].'/'.$report['date_from'].'_'.$report['date_to'].'/';
            $upload = $this->_upload_item_inventory_report_excel($local_path, $file_name, $vm4_path);
            if (!$upload || empty($upload['success']) || empty($upload['path'])) {
                return $this->_create_response(FAIL, [$upload['error'] ?? 'Failed to upload Excel file to VM4.']);
            }
            return $this->_create_response(SUCCESS, [
                'file_name' => $file_name,
                'file_url' => rtrim(VM4_ENDPOINT, '/').'/'.ltrim($upload['path'], '/'),
            ]);
        } catch (\Throwable $error) {
            log_message('error', 'Inventory Excel export failed: {exception}', ['exception' => get_class($error)]);
            return $this->_create_response(FAIL, ['Failed to generate or upload inventory report Excel file.']);
        } finally {
            if ($local_path && is_file($local_path)) {
                @unlink($local_path);
            }
        }
    }

    protected function _upload_item_inventory_report_excel(string $local_path, string $file_name, string $vm4_path)
    {
        return upload_to_vm4(
            new Local_upload_file($local_path, $file_name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            $vm4_path
        );
    }

    /**
     * Write XLSX using the same ZIP/inline-string approach as the billing exports.
     */
    protected function _attempt_write_item_inventory_report_excel(string $local_path, array $report, ?callable $fetch_batch)
    {
        $sheet_path = tempnam(dirname($local_path), 'inventory_sheet_');
        $sheet_file = false;
        $zip = null;
        $zip_open = false;
        try {
            if ($sheet_path === false || !($sheet_file = fopen($sheet_path, 'wb'))) {
                $this->error_message = 'Unable to create temporary Excel worksheet.';
                return false;
            }
            $write = static function (string $xml) use ($sheet_file) {
                if (fwrite($sheet_file, $xml) !== strlen($xml)) {
                    throw new \RuntimeException('Unable to write inventory worksheet.');
                }
            };
            $breakdown = $report['tab'] === 'breakdown';
            $fields = [
                'snapshot_date', 'branch_id', 'item_id', 'item_name', 'item_brand', 'type', 'kind',
                'selling_price', 'philhealth_medicine_type', 'unit_name', 'current_qty', 'weighted_cost', 'ending_cost',
            ];
            $headers = $breakdown ? [
                'Snapshot Date', 'Branch ID', 'Item ID', 'Item Name', 'Brand', 'Type', 'Kind',
                'Selling Price', 'Medicine Type', 'Unit', 'Current Quantity', 'Weighted Cost', 'Ending Cost',
            ] : array_merge(['Branch ID', 'Branch Name'], $report['months']);
            if (count($headers) > 16384) {
                $this->error_message = 'Inventory report exceeds the Excel column limit.';
                return false;
            }
            $last_column = $this->_inventory_excel_column_name(count($headers));
            $title = $breakdown ? 'Inventory Breakdown' : 'Inventory per Branch';
            $metadata = $breakdown
                ? 'Branch: '.$report['branch_name'].' ('.$report['branch_id'].') | Snapshot: '.$report['snapshot_date']
                    .' | Conversion: '.$report['conversion_type']
                : 'Inventory ending cost by branch';
            $numeric_columns = $breakdown ? [1 => 0, 2 => 0, 7 => 4, 10 => 3, 11 => 3, 12 => 4] : [0 => 0];
            if (!$breakdown) {
                foreach ($report['months'] as $index => $month) {
                    $numeric_columns[$index + 2] = 4;
                }
            }

            $write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" state="frozen"/></sheetView></sheetViews><cols>');
            foreach ($headers as $index => $header) {
                $width = in_array($header, ['Item Name', 'Branch Name'], true) ? 40 : 20;
                $column = $index + 1;
                $write('<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>');
            }
            $write('</cols><sheetData>');
            $write($this->_inventory_excel_row_xml([$title], 1, [], 1));
            $write($this->_inventory_excel_row_xml(['Date range: '.$report['date_from'].' to '.$report['date_to']], 2));
            $write($this->_inventory_excel_row_xml([$metadata], 3));
            $write($this->_inventory_excel_row_xml($headers, 5, [], 2));
            $row_number = 6;
            if ($breakdown) {
                $rows = $report['data'];
                $page = 1;
                do {
                    foreach ($rows as $row) {
                        if ($row_number > 1048576) {
                            $this->error_message = 'Inventory report exceeds the Excel row limit.';
                            return false;
                        }
                        $values = [];
                        foreach ($fields as $field) {
                            $values[] = $row[$field] ?? null;
                        }
                        $write($this->_inventory_excel_row_xml($values, $row_number++, $numeric_columns));
                    }
                    if (count($rows) < 100) {
                        break;
                    }
                    $rows = $fetch_batch(++$page);
                    if (!is_array($rows)) {
                        $this->error_message = 'Unable to retrieve inventory report rows.';
                        return false;
                    }
                } while ($rows);
            } else {
                foreach ($report['branches'] as $branch) {
                    $values = [$branch['branch_id'], $branch['branch_name']];
                    foreach ($report['months'] as $month) {
                        $values[] = $branch[$month];
                    }
                    $write($this->_inventory_excel_row_xml($values, $row_number++, $numeric_columns));
                }
            }
            $filter_end = $row_number - 1;
            if (!$breakdown) {
                $values = [null, 'Total'];
                $total_columns = [];
                foreach ($report['months'] as $index => $month) {
                    $values[] = $report['total'][$month];
                    $total_columns[$index + 2] = 5;
                }
                $write($this->_inventory_excel_row_xml($values, $row_number, $total_columns, 6));
            }
            $write('</sheetData><autoFilter ref="A5:'.$last_column.$filter_end.'"/>'
                .'<mergeCells count="3"><mergeCell ref="A1:'.$last_column.'1"/><mergeCell ref="A2:'.$last_column.'2"/>'
                .'<mergeCell ref="A3:'.$last_column.'3"/></mergeCells></worksheet>');
            fclose($sheet_file);
            $sheet_file = false;

            $zip = new \ZipArchive();
            if ($zip->open($local_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $this->error_message = 'Failed to create Excel workbook.';
                return false;
            }
            $zip_open = true;
            $parts = [
                '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                    .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                    .'<Default Extension="xml" ContentType="application/xml"/>'
                    .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                    .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                    .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
                '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                    .'<sheets><sheet name="'.$title.'" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                    .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
                'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                    .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.0000"/><numFmt numFmtId="165" formatCode="#,##0.00"/></numFmts>'
                    .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font>'
                    .'<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                    .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                    .'<fill><patternFill patternType="solid"><fgColor rgb="FFE7E6E6"/><bgColor indexed="64"/></patternFill></fill></fills>'
                    .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                    .'<cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                    .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                    .'<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment wrapText="1"/></xf>'
                    .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                    .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                    .'<xf numFmtId="165" fontId="2" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
                    .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
                    .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            ];
            foreach ($parts as $name => $xml) {
                if (!$zip->addFromString($name, $xml)) {
                    $this->error_message = 'Failed to write Excel workbook.';
                    return false;
                }
            }
            if (!$zip->addFile($sheet_path, 'xl/worksheets/sheet1.xml')) {
                $this->error_message = 'Failed to write Excel worksheet.';
                return false;
            }
            $zip_open = false;
            if (!$zip->close()) {
                $this->error_message = 'Failed to finish Excel workbook.';
                return false;
            }
            return true;
        } finally {
            if ($zip_open) {
                try {
                    $zip->close();
                } catch (\Throwable $error) {
                    log_message('error', 'Inventory workbook close failed: {exception}', ['exception' => get_class($error)]);
                }
            }
            if (is_resource($sheet_file)) {
                fclose($sheet_file);
            }
            if ($sheet_path && is_file($sheet_path)) {
                @unlink($sheet_path);
            }
        }
    }

    private function _inventory_excel_row_xml(array $values, int $row_number, array $numeric_columns = [], int $style = 0): string
    {
        $xml = '<row r="'.$row_number.'">';
        foreach (array_values($values) as $index => $value) {
            $cell = $this->_inventory_excel_column_name($index + 1).$row_number;
            $cell_style = $numeric_columns[$index] ?? $style;
            $attributes = ' r="'.$cell.'" s="'.$cell_style.'"';
            if ($value === null || $value === '') {
                $xml .= '<c'.$attributes.'/>';
            } elseif (isset($numeric_columns[$index]) && is_numeric($value) && is_finite((float) $value)) {
                $xml .= '<c'.$attributes.'><v>'.trim((string) $value).'</v></c>';
            } else {
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
                $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $xml .= '<c'.$attributes.' t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
            }
        }
        return $xml.'</row>';
    }

    private function _inventory_excel_column_name(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + $column % 26).$name;
            $column = intdiv($column, 26);
        }
        return $name;
    }

    /**
     * @return array<int,array{key:string,date:string}>
     */
    protected function _inventory_months_in_range(string $date_from, string $date_to): array
    {
        $start = new \DateTime(date('Y-m-01', strtotime($date_from)));
        $end = new \DateTime(date('Y-m-01', strtotime($date_to)));
        $months = [];

        while ($start <= $end) {
            $months[] = [
                'key'  => $start->format('F'),
                'date' => $start->format('Y-m-01'),
            ];
            $start->modify('+1 month');
        }

        return $months;
    }

    /**
     * Backfill daily snapshots (and monthly rollups) from existing item data.
     * date_from / date_to optional (default today). Past dates use item_history qty as-of.
     */
    public function backfill_item_inventory_report()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $today = date('Y-m-d');
        $date_from = $this->payload['date_from'] ?? $today;
        $date_to = $this->payload['date_to'] ?? $today;

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_from)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_to)
        ) {
            return $this->_create_response(FAIL, ['Invalid date. Use YYYY-MM-DD.']);
        }

        if ($date_from > $date_to) {
            return $this->_create_response(FAIL, ['date_from must not be after date_to.']);
        }

        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $cursor = new \DateTime($date_from);
        $end = new \DateTime($date_to);
        $summary = [];

        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $use_live = ($date >= $today);
            $summary[$date] = $this->_snapshot_item_inventory_for_date($date, $use_live);
            $cursor->modify('+1 day');
        }

        return $this->_create_response(SUCCESS, [
            'message'   => 'Item inventory snapshots backfilled.',
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'data'      => $summary,
        ]);
    }
    
    /**
     * Get item list
     */
    public function get_items_history()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $item_id = $this->payload['item_id'] ?? null;
        $type = $this->payload['type'] ?? null;
        $kind = $this->payload['kind'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $conversion_type = $this->payload['conversion_type'] ?? null;
        $activity = $this->payload['activity'] ?? null;
        $date_from = $this->payload['date_from'] ?? null;
        $date_to = $this->payload['date_to'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $item_histories = $this->item->get_history($item_id, $type, $kind, $branch_id, $status, $conversion_type, $activity, $date_from, $date_to, $term, $page, $data_size)?:[];
        $size = count($this->item->get_history($item_id, $type, $kind, $branch_id, $status, $conversion_type, $activity, $date_from, $date_to, $term, null, null)?:[]);

        if(!$item_histories) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No item history found.'
            ];
        } else {
            foreach ($item_histories as $key => $item_history) {
                $user_data = $this->user->select('id, first_name, last_name', ['id' => $item_history['added_by']], 1) ?: null;
                $item_histories[$key]['added_by_name'] = $user_data ? $user_data->first_name . ' ' . $user_data->last_name : null;
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $item_histories
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Create item
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $result = $this->_execute_item_create();
        if (!$result['success']) {
            $this->db->transRollback();
            return $this->_create_response(FAIL, [$result['error']]);
        }

        $this->db->transCommit();

        return $this->_create_response(SUCCESS, [
            'item_id' => $result['item_id'],
            'message' => 'item added successfully.',
        ]);
    }

    /**
     * Create item and child tables (shared by create + upload_inventory).
     */
    private function _execute_item_create(): array
    {
        $this->resolve_branch_models(0);
        $master_count = count(
            $this->item->select('id', ['is_deleted' => 0]) ?? []
        );

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        foreach ($branches as $branch_id => $branch_name) {
            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->item->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                return [
                    'success' => false,
                    'error'   => "Data inconsistency detected between master and {$branch_name}.",
                ];
            }
        }

        if (!$item_id = $this->_attempt_create()) {
            return ['success' => false, 'error' => 'Failed to add item. ' . ($this->error_message ?? '')];
        }
        if (!$conversions = $this->_attempt_create_conversions($item_id)) {
            return ['success' => false, 'error' => 'Failed to add item conversions. ' . ($this->error_message ?? '')];
        }
        if (!$this->_attempt_create_branches($item_id, $conversions)) {
            return ['success' => false, 'error' => 'Failed to add item branches. ' . ($this->error_message ?? '')];
        }
        if (!$this->_attempt_create_cost($item_id, $conversions)) {
            return ['success' => false, 'error' => 'Failed to add item cost. ' . ($this->error_message ?? '')];
        }

        return ['success' => true, 'item_id' => $item_id];
    }

    /**
     * Attempt to create a item
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        foreach($this->item->allowedFields AS $field) {
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        if (array_key_exists('cost', $values) && $values['cost'] !== null && $values['cost'] !== '') {
            $values['weighted_cost'] = $values['cost'];
        }

        // Manually add/modify the fields here
        // $values['field_name'] = 'value';

        if (!$item_id = $this->mirror_insert('item', $values)) {
            return false;
        }

        return $item_id;
    }

    /**
     * Attempt to create item conversions
     */
    private function _attempt_create_conversions($item_id)
    {
        if (!array_key_exists('conversions', $this->payload) || !is_array($this->payload['conversions']) || count($this->payload['conversions']) == 0) {
            return true;
        }

        $conversions = [];

        foreach ($this->payload['conversions'] as $conversion) {
            $values = [
                'item_id'    => $item_id,
                'conversion_type'   => array_key_exists('conversion_type', $conversion) && $conversion['conversion_type'] != '' ? $conversion['conversion_type'] : null,
                'price'   => array_key_exists('price', $conversion) && $conversion['price'] != '' ? $conversion['price'] : null,
                'qty'   => array_key_exists('qty', $conversion) && $conversion['qty'] != '' ? $conversion['qty'] : null,
                'unit'   => array_key_exists('unit', $conversion) && $conversion['unit'] != '' ? $conversion['unit'] : null,
                'min'   => array_key_exists('min', $conversion) && $conversion['min'] != '' ? $conversion['min'] : 0,
                'max'   => array_key_exists('max', $conversion) && $conversion['max'] != '' ? $conversion['max'] : 0,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            // 1️⃣ Insert into MASTER first, mirror to all branch DBs
            $item_conversion_id = $this->mirror_insert('item_conversion', $values);
            if (!$item_conversion_id) {
                return false;
            }

            $conversions[] = [
                'id' => $item_conversion_id,
                'unit' => $values['unit'],
                'conversion_type' => $values['conversion_type']
            ];
        }

        return $conversions;
    }

    /**
     * Attempt to create item branches
     */
    private function _attempt_create_branches($item_id, $conversions)
    {
        if (!array_key_exists('branches', $this->payload) || !is_array($this->payload['branches']) || count($this->payload['branches']) == 0) {
            return true;
        }

        foreach ($this->payload['branches'] as $branch) {
            $unit = null;
            foreach ($conversions as $conv) {
                if ($conv['unit'] == $branch['unit'] && $conv['conversion_type'] == $branch['conversion_type']) {
                    $unit = (object)['id' => $conv['id']];
                    break;
                }
            }

            if (!$unit) {
                $this->error_message = 'No matching conversion for unit ' . ($branch['unit'] ?? '') . ' and type ' . ($branch['conversion_type'] ?? '') . '.';
                return false;
            }

            $values = [
                'item_id'    => $item_id,
                'branch_id'   => array_key_exists('branch_id', $branch) && $branch['branch_id'] != '' ? $branch['branch_id'] : null,
                'beggining_qty'   => array_key_exists('current_qty', $branch) && $branch['current_qty'] != '' ? $branch['current_qty'] : null,
                'current_qty'   => array_key_exists('current_qty', $branch) && $branch['current_qty'] != '' ? $branch['current_qty'] : null,
                'unit'   => $unit->id,
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->mirror_insert('branch_item', $values)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to create item cost
     */
    private function _attempt_create_cost($item_id, $conversions)
    {
        if (!array_key_exists('branches', $this->payload) || !is_array($this->payload['branches']) || count($this->payload['branches']) == 0) {
            return true;
        }

        $cost = (float)($this->payload['cost'] ?? 0);
        $seen = [];

        foreach ($this->payload['branches'] as $branch) {
            $branch_id = (int)($branch['branch_id'] ?? 0);
            if (!$branch_id || isset($seen[$branch_id])) {
                continue;
            }
            $seen[$branch_id] = true;

            $qty = $this->_purchase_qty_from_payload_branches($branch_id, $conversions);
            $values = $this->_seed_item_cost_values($item_id, $branch_id, $cost, $qty);
            $values['added_by'] = $this->payload['requester'];
            $values['added_on'] = date('Y-m-d H:i:s');

            if (!$this->mirror_insert('item_cost', $values)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update item
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $item_id = $this->payload['item_id'];
        $where = [
                    'id' => $item_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        $this->resolve_branch_models(0);
        $master_count = count(
            $this->item->select('id', ['is_deleted' => 0]) ?? []
        );

        $branches = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        foreach ($branches as $branch_id => $branch_name) {

            $this->resolve_branch_models($branch_id);
            $branch_count = count(
                $this->item->select('id', ['is_deleted' => 0]) ?? []
            );

            if ($branch_count !== $master_count) {
                $this->db->transRollback();

                return $this->_create_response(FAIL, [
                    "Data inconsistency detected between master and {$branch_name}."
                ]);
            }
        }

        if (!$item = $this->item->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'item is not found.'
            ];
        } elseif (!$this->_attempt_update($item)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update item.',
            ];
        } elseif (!$conversions = $this->_attempt_update_conversions($item_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update item conversions.',
            ];
        } elseif (!$this->_attempt_update_branches($item_id, $conversions)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update item branches.',
            ];
        } elseif (!$this->_attempt_update_cost($item_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update item cost.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'item_id' => $item_id,
                'message'    => 'item updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($item)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        // This iterates over each necessary field
        // $this->item->allowedFields is declared at the top of the model
        foreach($this->item->allowedFields AS $field) {
            if ($field === 'weighted_cost') {
                continue;
            }
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $item->id];

        if (!$this->mirror_update('item', $where, $values)) {
            return false;
        }

        return true;
    }

    /**
     * Attempt to update item conversions
     */
    private function _attempt_update_conversions($item_id)
    {
        if (!array_key_exists('conversions', $this->payload) || !is_array($this->payload['conversions']) || count($this->payload['conversions']) == 0) {
            return true;
        }

        // 1. Get all current conversion IDs for this item
        $this->resolve_branch_models(0);
        $existing_conversions = $this->item_conversion->select('id', ['item_id' => $item_id, 'is_deleted' => 0]);

        $existing_ids = array_column($existing_conversions, 'id');

        // 2. Collect IDs from payload
        $payload_ids = [];
        if (array_key_exists('conversions', $this->payload) && is_array($this->payload['conversions'])) {
            foreach ($this->payload['conversions'] as $conversion) {
                if (!empty($conversion['id'])) {
                    $payload_ids[] = (int)$conversion['id'];
                }
            }
        }

        // 3. Mark missing ones as deleted
        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s')
                ];

                if (!$this->mirror_update('item_conversion', ['id' => $delete_id], $delete_values)) {
                    return false;
                }
            }
        }

        // 4. Process payload conversions (update existing or insert new)
        if (!array_key_exists('conversions', $this->payload) || !is_array($this->payload['conversions'])) {
            return true;
        }

        $conversions = [];

        foreach ($this->payload['conversions'] as $conversion) {
            $values = [
                'item_id'  => $item_id,
                'conversion_type'   => array_key_exists('conversion_type', $conversion) && $conversion['conversion_type'] != '' ? $conversion['conversion_type'] : null,
                'price'   => array_key_exists('price', $conversion) && $conversion['price'] != '' ? $conversion['price'] : null,
                'qty'   => array_key_exists('qty', $conversion) && $conversion['qty'] != '' ? $conversion['qty'] : null,
                'unit'   => array_key_exists('unit', $conversion) && $conversion['unit'] != '' ? $conversion['unit'] : null,
                'min'   => array_key_exists('min', $conversion) && $conversion['min'] != '' ? $conversion['min'] : 0,
                'max'   => array_key_exists('max', $conversion) && $conversion['max'] != '' ? $conversion['max'] : 0,
            ];

            if (!empty($conversion['id'])) {
                // update existing
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->mirror_update('item_conversion', ['id' => $conversion['id']], $values)) {
                    return false;
                }

                $conversions[] = [
                    'id' => $conversion['id'],
                    'unit' => $values['unit'],
                    'conversion_type' => $values['conversion_type']
                ];
            } else {
                // insert new
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                $item_conversion_id = $this->mirror_insert('item_conversion', $values);
                if (!$item_conversion_id) {
                    return false;
                }

                $conversions[] = [
                    'id' => $item_conversion_id,
                    'unit' => $values['unit'],
                    'conversion_type' => $values['conversion_type']
                ];
            }
        }

        return $conversions;
    }

    /**
     * Attempt to update item branches
     */
    private function _attempt_update_branches($item_id, $conversions)
    {
        if (!array_key_exists('branches', $this->payload) || !is_array($this->payload['branches'])) {
            return true;
        }

        $this->resolve_branch_models(0);
        $existing = $this->branch_item->select('id', [
            'item_id' => $item_id,
            'is_deleted' => 0,
        ]) ?? [];
        $existing_ids = array_column($existing, 'id');

        $payload_ids = [];
        foreach ($this->payload['branches'] as $branch) {
            if (!empty($branch['id'])) {
                $payload_ids[] = (int)$branch['id'];
            }
        }

        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        foreach ($ids_to_delete as $delete_id) {
            $delete_values = [
                'is_deleted' => 1,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s'),
            ];

            if (!$this->mirror_update('branch_item', ['id' => $delete_id], $delete_values)) {
                return false;
            }
        }

        foreach ($this->payload['branches'] as $branch) {
            $branch_id = (int)$branch['branch_id'];

            $unit_id = null;
            foreach ($conversions as $conv) {
                if ($conv['id'] == $branch['unit']) {
                    $unit_id = $conv['id'];
                    break;
                }
            }

            if (!$unit_id) {
                continue;
            }

            $values = [
                'item_id'        => $item_id,
                'branch_id'      => $branch_id,
                'beggining_qty'  => $branch['current_qty'] ?? null,
                'current_qty'    => $branch['current_qty'] ?? null,
                'unit'           => $unit_id,
            ];

            if (!empty($branch['id'])) {
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');

                if (!$this->mirror_update('branch_item', ['id' => $branch['id']], $values)) {
                    return false;
                }
            } else {
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');

                if (!$this->mirror_insert('branch_item', $values)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Attempt to update item cost
     */
    private function _attempt_update_cost($item_id)
    {
        if (!array_key_exists('branches', $this->payload) || !is_array($this->payload['branches']) || count($this->payload['branches']) == 0) {
            return true;
        }

        $cost = (float)($this->payload['cost'] ?? 0);
        $seen = [];

        foreach ($this->payload['branches'] as $branch) {
            if (!array_key_exists('branch_id', $branch) || $branch['branch_id'] == '') {
                continue;
            }

            $branch_id = (int)$branch['branch_id'];
            if (isset($seen[$branch_id])) {
                continue;
            }
            $seen[$branch_id] = true;

            $this->resolve_branch_models(0);
            $item_cost = $this->item_cost->get_latest($item_id, $branch_id);

            if ($item_cost) {
                continue;
            }

            $qty = $this->_purchase_qty_from_payload_branches($branch_id, $this->payload['conversions'] ?? []);
            $values = $this->_seed_item_cost_values($item_id, $branch_id, $cost, $qty);
            $values['added_by'] = $this->payload['requester'];
            $values['added_on'] = date('Y-m-d H:i:s');

            if (!$this->mirror_insert('item_cost', $values)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete items
     */
    public function delete($id = null)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $response_status = SUCCESS;
        $item_id = $this->payload['item_id'];

        $where = ['id' => $item_id, 'is_deleted' => 0];

        $this->db->transBegin();

        if (!$item = $this->item->select('', $where, 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'item is not found.',
            ];
        } elseif (!$this->_attempt_delete($item_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'item is not deleted.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'item is deleted.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt delete
     */
    protected function _attempt_delete($item_id)
    {
        $where = ['id' => $item_id];
        $values = [
            'is_deleted' => 1,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->mirror_update('item', $where, $values)) {
            return false;
        }

        return true;
    }

    /**
     * Soft-delete duplicate items (same name + description + is_deleted=0), keep MIN(id).
     * Cascades soft-delete to child tables across master + all diagnostic DBs.
     * Chunks by limit (default 25 delete ids) per call.
     * POST /items/cleanup_duplicate_items
     */
    public function cleanup_duplicate_items()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $limit = (int)($this->payload['limit'] ?? 25);
        if ($limit < 1) {
            $limit = 25;
        }

        $masterDb = db_connect('master');

        $sql = <<<EOT
SELECT
    name,
    description,
    is_deleted,
    MIN(id) AS keep_item_id,
    GROUP_CONCAT(id ORDER BY id) AS all_ids,
    COUNT(*) AS duplicate_count
FROM item
WHERE is_deleted = 0
GROUP BY name, description, is_deleted
HAVING COUNT(*) > 1
ORDER BY duplicate_count DESC, name
EOT;

        $query = $masterDb->query($sql);
        if ($query === false) {
            return $this->_create_response(FAIL, [
                'Failed to query duplicate items.',
            ]);
        }

        $groups = $query->getResultArray() ?: [];

        $queue = [];
        foreach ($groups as $group) {
            $keepId = (int)$group['keep_item_id'];
            $allIds = array_map('intval', explode(',', $group['all_ids']));
            foreach ($allIds as $id) {
                if ($id === $keepId) {
                    continue;
                }
                $queue[] = [
                    'keep_item_id' => $keepId,
                    'delete_id'    => $id,
                    'name'         => $group['name'],
                    'description'  => $group['description'],
                    'is_deleted'   => (int)$group['is_deleted'],
                ];
            }
        }

        if (empty($queue)) {
            return $this->_create_response(SUCCESS, [
                'groups_cleaned'         => 0,
                'deleted_count'          => 0,
                'deleted'                => [],
                'remaining_delete_count' => 0,
                'done'                   => true,
                'message'                => 'all databases rows is consistent',
            ]);
        }

        $chunk = array_slice($queue, 0, $limit);
        $remaining = count($queue) - count($chunk);

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'];
        $softDeleteValues = [
            'is_deleted' => 1,
            'updated_by' => $requester,
            'updated_on' => $now,
        ];

        $deletedByKeep = [];
        $deletedCount = 0;

        foreach ($chunk as $entry) {
            $deleteId = (int)$entry['delete_id'];
            if (!$this->_soft_delete_item_cascade($deleteId, $softDeleteValues)) {
                return $this->_create_response(FAIL, [
                    'Failed to soft-delete duplicate item_id ' . $deleteId . '. ' . ($this->error_message ?? ''),
                    'partial' => [
                        'deleted_count'          => $deletedCount,
                        'deleted'                => array_values($deletedByKeep),
                        'remaining_delete_count' => $remaining + (count($chunk) - $deletedCount),
                        'done'                   => false,
                    ],
                ]);
            }

            $keepKey = (int)$entry['keep_item_id'];
            if (!isset($deletedByKeep[$keepKey])) {
                $deletedByKeep[$keepKey] = [
                    'name'             => $entry['name'],
                    'description'      => $entry['description'],
                    'is_deleted'       => $entry['is_deleted'],
                    'keep_item_id'     => $keepKey,
                    'deleted_item_ids' => [],
                ];
            }
            $deletedByKeep[$keepKey]['deleted_item_ids'][] = $deleteId;
            $deletedCount++;
        }

        $done = ($remaining === 0);

        return $this->_create_response(SUCCESS, [
            'groups_cleaned'         => count($deletedByKeep),
            'deleted_count'          => $deletedCount,
            'deleted'                => array_values($deletedByKeep),
            'remaining_delete_count' => $remaining,
            'done'                   => $done,
            'message'                => $done
                ? 'all databases rows is consistent'
                : 'Processed ' . $deletedCount . ' duplicate item(s). Call again until done.',
        ]);
    }

    /**
     * Soft-delete one item and its mirrored child rows on every inventory DB.
     */
    private function _soft_delete_item_cascade(int $itemId, array $values): bool
    {
        if (!$this->mirror_update('item', ['id' => $itemId], $values)) {
            return false;
        }
        if (!$this->mirror_update('item_conversion', ['item_id' => $itemId], $values)) {
            return false;
        }
        if (!$this->mirror_update('branch_item', ['item_id' => $itemId], $values)) {
            return false;
        }
        if (!$this->mirror_update('item_cost', ['item_id' => $itemId], $values)) {
            return false;
        }
        if (!$this->mirror_update('branch_item_adjustment', ['item_id' => $itemId], $values)) {
            return false;
        }
        if (!$this->_soft_delete_item_blindsheets($itemId, $values)) {
            return false;
        }
        if (!$this->_mirror_soft_delete_item_history($itemId, [
            'is_deleted' => 1,
        ])) {
            return false;
        }

        return true;
    }

    /**
     * Soft-delete one item and its child rows on a single diagnostic DB.
     */
    private function _soft_delete_item_on_branch(int $itemId, int $branchId, array $values): bool
    {
        if (!$this->_update_item_on_branch($branchId, 'item', ['id' => $itemId], $values)) {
            return false;
        }
        if (!$this->_update_item_on_branch($branchId, 'item_conversion', ['item_id' => $itemId], $values)) {
            return false;
        }
        if (!$this->_update_item_on_branch($branchId, 'branch_item', ['item_id' => $itemId], $values)) {
            return false;
        }
        if (!$this->_update_item_on_branch($branchId, 'item_cost', ['item_id' => $itemId], $values)) {
            return false;
        }
        if (!$this->_update_item_on_branch($branchId, 'branch_item_adjustment', ['item_id' => $itemId], $values)) {
            return false;
        }
        if (!$this->_soft_delete_item_blindsheets($itemId, $values, $branchId)) {
            return false;
        }
        if (!$this->_update_item_on_branch($branchId, 'item_history', ['item_id' => $itemId], [
            'is_deleted' => 1,
        ])) {
            return false;
        }

        return true;
    }

    /**
     * Update rows on a single inventory DB (master or diagnostic).
     */
    private function _update_item_on_branch(int $branchId, string $table, array $where, array $values): bool
    {
        $dbMap = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            7 => 'mantlewood',
            6 => 'pharmacy',
        ];

        if (!isset($dbMap[$branchId])) {
            $this->error_message = 'Invalid branch id for item consistency update.';
            return false;
        }

        $db = db_connect($dbMap[$branchId]);
        $builder = $db->table($table);
        foreach ($where as $col => $val) {
            $builder->where($col, $val);
        }
        if ($builder->update($values) === false) {
            $err = $db->error();
            $this->error_message = $err['message'] ?? ('Failed update on ' . $dbMap[$branchId] . '.' . $table);
            return false;
        }

        return true;
    }

    /**
     * Soft-delete item_history rows for an item_id across all inventory DBs.
     */
    private function _mirror_soft_delete_item_history(int $itemId, array $values): bool
    {
        $dbMap = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            7 => 'mantlewood',
            6 => 'pharmacy',
        ];

        foreach ($this->inventory_db_ids() as $branch_id) {
            $db = db_connect($dbMap[$branch_id]);
            $ok = $db->table('item_history')
                ->where('item_id', $itemId)
                ->update($values);

            if ($ok === false) {
                $err = $db->error();
                $this->error_message = ($err['message'] ?? '') ?: 'Failed to soft-delete item_history.';
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function _item_diagnostic_branches(): array
    {
        return [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];
    }

    /**
     * @param mixed $branchIdFilter
     * @return array<int, string>|false
     */
    private function _resolve_item_target_branches($branchIdFilter)
    {
        $allBranches = $this->_item_diagnostic_branches();

        if ($branchIdFilter === null || $branchIdFilter === '') {
            return $allBranches;
        }

        $branchId = (int)$branchIdFilter;
        if ($branchId < 1 || !isset($allBranches[$branchId])) {
            $allowed = implode(', ', array_keys($allBranches));
            $this->error_message = 'Invalid branch_id. Allowed diagnostic branch ids: ' . $allowed . '.';
            return false;
        }

        return [$branchId => $allBranches[$branchId]];
    }

    /**
     * @return list<string>
     */
    private function _item_consistency_compare_fields(): array
    {
        return [
            'name',
            'description',
            'is_deleted',
            'type',
            'kind',
            'cost',
            'brand',
            'vat_type',
            'account_type_id',
            'vat_account_type_id',
        ];
    }

    /**
     * Compare item rows between master and each diagnostic DB.
     * GET/POST /items/check_consistency
     * Optional: is_deleted=0 (default) or is_deleted=all
     */
    public function check_consistency()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $isDeletedFilter = $this->payload['is_deleted'] ?? '0';
        $includeAll = (is_string($isDeletedFilter) && strtolower($isDeletedFilter) === 'all');

        $compareFields = [
            'name',
            'description',
            'is_deleted',
            'type',
            'kind',
            'cost',
            'brand',
            'vat_type',
            'account_type_id',
            'vat_account_type_id',
        ];

        $masterMap = $this->_load_item_consistency_map(0, $includeAll, $isDeletedFilter);
        if ($masterMap === false) {
            return $this->_create_response(FAIL, [
                'Failed to load items from master. ' . ($this->error_message ?? ''),
            ]);
        }

        $branchNames = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            7 => 'mantlewood',
            5 => 'warehouse',
            6 => 'pharmacy',
        ];

        $databases = [];
        $allConsistent = true;

        foreach ($branchNames as $branchId => $dbName) {
            $branchMap = $this->_load_item_consistency_map($branchId, $includeAll, $isDeletedFilter);
            if ($branchMap === false) {
                return $this->_create_response(FAIL, [
                    "Failed to load items from {$dbName}. " . ($this->error_message ?? ''),
                ]);
            }

            $comparison = $this->_compare_item_consistency_maps($masterMap, $branchMap, $compareFields);
            $dbConsistent = empty($comparison['missing_on_branch'])
                && empty($comparison['extra_on_branch'])
                && empty($comparison['field_diffs']);

            if (!$dbConsistent) {
                $allConsistent = false;
            }

            $databases[] = [
                'branch_id'          => $branchId,
                'db'                 => $dbName,
                'branch_count'       => count($branchMap),
                'consistent'         => $dbConsistent,
                'missing_on_branch'  => $comparison['missing_on_branch'],
                'extra_on_branch'    => $comparison['extra_on_branch'],
                'field_diffs'        => $comparison['field_diffs'],
            ];
        }

        $response = [
            'consistent'   => $allConsistent,
            'filter'       => [
                'is_deleted' => $includeAll ? 'all' : (int)$isDeletedFilter,
            ],
            'master_count' => count($masterMap),
            'databases'    => $databases,
            'message'      => $allConsistent
                ? 'All diagnostic databases are consistent with master.'
                : 'Data inconsistency detected between master and one or more diagnostic databases.',
        ];

        return $this->_create_response(
            $allConsistent ? SUCCESS : FAIL,
            $response
        );
    }

    /**
     * Load item rows keyed by id for consistency checks.
     */
    private function _load_item_consistency_map(int $branchId, bool $includeAll, $isDeletedFilter)
    {
        $dbMap = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            7 => 'mantlewood',
            6 => 'pharmacy',
        ];

        if (!isset($dbMap[$branchId])) {
            $this->error_message = 'Invalid branch id for consistency check.';
            return false;
        }

        $db = db_connect($dbMap[$branchId]);
        $builder = $db->table('item')->select(
            'id, name, description, is_deleted, type, kind, cost, brand, vat_type, account_type_id, vat_account_type_id'
        );

        if (!$includeAll) {
            $builder->where('is_deleted', (int)$isDeletedFilter);
        }

        $query = $builder->get();
        if ($query === false) {
            $err = $db->error();
            $this->error_message = $err['message'] ?? 'Query failed.';
            return false;
        }

        $map = [];
        foreach ($query->getResultArray() as $row) {
            $map[(int)$row['id']] = $row;
        }

        return $map;
    }

    /**
     * Diff two item maps: missing, extra, and field differences.
     */
    private function _compare_item_consistency_maps(array $masterMap, array $branchMap, array $compareFields): array
    {
        $missingOnBranch = [];
        $extraOnBranch = [];
        $fieldDiffs = [];

        foreach ($masterMap as $id => $masterRow) {
            if (!isset($branchMap[$id])) {
                $missingOnBranch[] = [
                    'id'          => $id,
                    'name'        => $masterRow['name'] ?? null,
                    'description' => $masterRow['description'] ?? null,
                ];
                continue;
            }

            $branchRow = $branchMap[$id];
            $differences = [];
            foreach ($compareFields as $field) {
                $masterVal = $this->_normalize_consistency_value($masterRow[$field] ?? null);
                $branchVal = $this->_normalize_consistency_value($branchRow[$field] ?? null);
                if ($masterVal !== $branchVal) {
                    $differences[$field] = [
                        'master' => $masterRow[$field] ?? null,
                        'branch' => $branchRow[$field] ?? null,
                    ];
                }
            }

            if (!empty($differences)) {
                $fieldDiffs[] = [
                    'id'          => $id,
                    'name'        => $masterRow['name'] ?? null,
                    'differences' => $differences,
                ];
            }
        }

        foreach ($branchMap as $id => $branchRow) {
            if (!isset($masterMap[$id])) {
                $extraOnBranch[] = [
                    'id'          => $id,
                    'name'        => $branchRow['name'] ?? null,
                    'description' => $branchRow['description'] ?? null,
                ];
            }
        }

        return [
            'missing_on_branch' => $missingOnBranch,
            'extra_on_branch'   => $extraOnBranch,
            'field_diffs'       => $fieldDiffs,
        ];
    }

    /**
     * Normalize values for fair string comparison (NULL/empty, numeric cost).
     */
    private function _normalize_consistency_value($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_numeric($value)) {
            return (string)(0 + $value);
        }

        return trim((string)$value);
    }

    /**
     * Repair item-row inconsistencies between master and diagnostic DBs.
     * - Soft-delete branch-only active rows when master is deleted
     * - Sync field_diffs from master → branch (master wins)
     * - Soft-delete on all DBs when master is active but any diagnostic has is_deleted=1
     * - Insert diagnostic-only rows onto master and sync missing DBs
     * - Push master rows onto diagnostic DBs that are missing them
     * Optional: branch_id to process one diagnostic DB; chunks by limit (default 25).
     * POST /items/fix_consistency
     */
    public function fix_consistency()
    {
        // if (($response = $this->_api_validation()) !== true) {
        //     return $response;
        // }

        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        $limit = (int)($this->payload['limit'] ?? 25);
        if ($limit < 1) {
            $limit = 25;
        }

        $targetBranches = $this->_resolve_item_target_branches(
            $this->payload['branch_id'] ?? null
        );
        if ($targetBranches === false) {
            return $this->_create_response(FAIL, [
                $this->error_message ?? 'Invalid branch_id for fix_consistency.',
            ]);
        }

        $filterBranchId = array_key_exists('branch_id', $this->payload ?? [])
            && $this->payload['branch_id'] !== null
            && $this->payload['branch_id'] !== ''
            ? (int)$this->payload['branch_id']
            : null;
        $filterMeta = [
            'limit'     => $limit,
            'branch_id' => $filterBranchId,
        ];

        $masterMap = $this->_load_item_consistency_map(0, true, 'all');
        if ($masterMap === false) {
            return $this->_create_response(FAIL, [
                'Failed to load items from master. ' . ($this->error_message ?? ''),
            ]);
        }

        $branchMaps = [];
        foreach ($targetBranches as $branchId => $dbName) {
            $map = $this->_load_item_consistency_map($branchId, true, 'all');
            if ($map === false) {
                return $this->_create_response(FAIL, [
                    "Failed to load items from {$dbName}. " . ($this->error_message ?? ''),
                ]);
            }
            $branchMaps[$branchId] = [
                'db'  => $dbName,
                'map' => $map,
            ];
        }

        $work = [];
        $compareFields = $this->_item_consistency_compare_fields();
        $softDeleteBranchKeys = [];

        // Soft-delete extras: master deleted, branch still active
        foreach ($branchMaps as $branchId => $info) {
            foreach ($info['map'] as $id => $branchRow) {
                if (!isset($masterMap[$id])) {
                    continue;
                }
                $masterRow = $masterMap[$id];
                if ((int)($masterRow['is_deleted'] ?? 0) !== 1) {
                    continue;
                }
                if ((int)($branchRow['is_deleted'] ?? 0) !== 0) {
                    continue;
                }
                $softDeleteBranchKeys[$branchId . ':' . $id] = true;
                $work[] = [
                    'action'           => 'soft_delete_branch',
                    'id'               => (int)$id,
                    'name'             => $masterRow['name'] ?? null,
                    'target_branch_id' => (int)$branchId,
                    'db'               => $info['db'],
                ];
            }
        }

        // Sync field diffs: copy master compare-field values onto branch
        foreach ($branchMaps as $branchId => $info) {
            foreach ($info['map'] as $id => $branchRow) {
                if (!isset($masterMap[$id])) {
                    continue;
                }
                if (isset($softDeleteBranchKeys[$branchId . ':' . $id])) {
                    continue;
                }

                $masterRow = $masterMap[$id];
                $values = [];
                $fields = [];
                foreach ($compareFields as $field) {
                    $masterVal = $this->_normalize_consistency_value($masterRow[$field] ?? null);
                    $branchVal = $this->_normalize_consistency_value($branchRow[$field] ?? null);
                    if ($masterVal !== $branchVal) {
                        $values[$field] = $masterRow[$field] ?? null;
                        $fields[] = $field;
                    }
                }

                if (empty($values)) {
                    continue;
                }

                $work[] = [
                    'action'           => 'sync_fields',
                    'id'               => (int)$id,
                    'name'             => $masterRow['name'] ?? null,
                    'target_branch_id' => (int)$branchId,
                    'db'               => $info['db'],
                    'values'           => $values,
                    'fields'           => $fields,
                ];
            }
        }

        // Soft-delete: master active, any diagnostic deleted
        foreach ($masterMap as $id => $masterRow) {
            if ((int)($masterRow['is_deleted'] ?? 0) !== 0) {
                continue;
            }
            foreach ($branchMaps as $info) {
                if (!isset($info['map'][$id])) {
                    continue;
                }
                if ((int)($info['map'][$id]['is_deleted'] ?? 0) === 1) {
                    $work[] = [
                        'action' => 'soft_delete',
                        'id'     => (int)$id,
                        'name'   => $masterRow['name'] ?? null,
                    ];
                    break;
                }
            }
        }

        // Extra on any diagnostic, missing on master
        $seenExtra = [];
        foreach ($branchMaps as $branchId => $info) {
            foreach ($info['map'] as $id => $branchRow) {
                if (isset($masterMap[$id]) || isset($seenExtra[$id])) {
                    continue;
                }
                $seenExtra[$id] = true;
                $work[] = [
                    'action'    => 'insert',
                    'id'        => (int)$id,
                    'name'      => $branchRow['name'] ?? null,
                    'source_db' => $info['db'],
                    'source_id' => (int)$branchId,
                ];
            }
        }

        // Master has row missing on any diagnostic — push from master
        $seenPush = [];
        foreach ($masterMap as $id => $masterRow) {
            if (isset($seenPush[$id])) {
                continue;
            }
            foreach ($branchMaps as $info) {
                if (!isset($info['map'][$id])) {
                    $seenPush[$id] = true;
                    $work[] = [
                        'action'    => 'push',
                        'id'        => (int)$id,
                        'name'      => $masterRow['name'] ?? null,
                        'source_db' => 'master',
                        'source_id' => 0,
                    ];
                    break;
                }
            }
        }

        if (empty($work)) {
            return $this->_create_response(
                SUCCESS,
                $this->_fix_consistency_done_payload(
                    [],
                    [],
                    [],
                    [],
                    0,
                    $targetBranches,
                    $filterMeta
                )
            );
        }

        $chunk = array_slice($work, 0, $limit);
        $remaining = count($work) - count($chunk);

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'] ?? null;
        $softDeleteValues = [
            'is_deleted' => 1,
            'updated_by' => $requester,
            'updated_on' => $now,
        ];

        $softDeleted = [];
        $fieldSynced = [];
        $inserted = [];
        $pushed = [];

        $partialPayload = function () use (&$softDeleted, &$fieldSynced, &$inserted, &$pushed, $remaining, $filterMeta) {
            return [
                'filter'          => $filterMeta,
                'fixed_count'     => count($softDeleted) + count($fieldSynced) + count($inserted) + count($pushed),
                'soft_deleted'    => $softDeleted,
                'field_synced'    => $fieldSynced,
                'inserted'        => $inserted,
                'pushed'          => $pushed,
                'remaining_count' => $remaining,
                'done'            => false,
            ];
        };

        foreach ($chunk as $job) {
            if ($job['action'] === 'soft_delete_branch') {
                if (!$this->_soft_delete_item_on_branch(
                    (int)$job['id'],
                    (int)$job['target_branch_id'],
                    $softDeleteValues
                )) {
                    return $this->_create_response(FAIL, [
                        'Failed to soft-delete item_id ' . $job['id']
                            . ' on ' . ($job['db'] ?? '') . '. ' . ($this->error_message ?? ''),
                        'partial' => $partialPayload(),
                    ]);
                }
                $softDeleted[] = [
                    'id'        => (int)$job['id'],
                    'name'      => $job['name'],
                    'branch_id' => (int)$job['target_branch_id'],
                    'db'        => $job['db'],
                ];
                continue;
            }

            if ($job['action'] === 'sync_fields') {
                $values = $job['values'];
                $values['updated_by'] = $requester;
                $values['updated_on'] = $now;
                if (!$this->_update_item_on_branch(
                    (int)$job['target_branch_id'],
                    'item',
                    ['id' => (int)$job['id']],
                    $values
                )) {
                    return $this->_create_response(FAIL, [
                        'Failed to sync fields for item_id ' . $job['id']
                            . ' on ' . ($job['db'] ?? '') . '. ' . ($this->error_message ?? ''),
                        'partial' => $partialPayload(),
                    ]);
                }
                $fieldSynced[] = [
                    'id'        => (int)$job['id'],
                    'name'      => $job['name'],
                    'branch_id' => (int)$job['target_branch_id'],
                    'db'        => $job['db'],
                    'fields'    => $job['fields'],
                ];
                continue;
            }

            if ($job['action'] === 'soft_delete') {
                if (!$this->_soft_delete_item_cascade((int)$job['id'], $softDeleteValues)) {
                    return $this->_create_response(FAIL, [
                        'Failed to soft-delete item_id ' . $job['id'] . '. ' . ($this->error_message ?? ''),
                        'partial' => $partialPayload(),
                    ]);
                }
                $softDeleted[] = [
                    'id'   => (int)$job['id'],
                    'name' => $job['name'],
                ];
                continue;
            }

            if ($job['action'] === 'insert' || $job['action'] === 'push') {
                if (!$this->_mirror_insert_item_row((int)$job['id'], (int)$job['source_id'])) {
                    return $this->_create_response(FAIL, [
                        'Failed to sync item_id ' . $job['id'] . ' from ' . ($job['source_db'] ?? '') . '. ' . ($this->error_message ?? ''),
                        'partial' => $partialPayload(),
                    ]);
                }
                $entry = [
                    'id'        => (int)$job['id'],
                    'name'      => $job['name'],
                    'source_db' => $job['source_db'],
                ];
                if ($job['action'] === 'insert') {
                    $inserted[] = $entry;
                } else {
                    $pushed[] = $entry;
                }
            }
        }

        $fixedCount = count($softDeleted) + count($fieldSynced) + count($inserted) + count($pushed);

        if ($remaining > 0) {
            return $this->_create_response(SUCCESS, [
                'filter'          => $filterMeta,
                'fixed_count'     => $fixedCount,
                'soft_deleted'    => $softDeleted,
                'field_synced'    => $fieldSynced,
                'inserted'        => $inserted,
                'pushed'          => $pushed,
                'remaining_count' => $remaining,
                'done'            => false,
                'message'         => 'Processed ' . $fixedCount . ' consistency fix(es). Call again until done.',
            ]);
        }

        return $this->_create_response(
            SUCCESS,
            $this->_fix_consistency_done_payload(
                $softDeleted,
                $inserted,
                $pushed,
                $fieldSynced,
                $fixedCount,
                $targetBranches,
                $filterMeta
            )
        );
    }

    /**
     * Build final fix_consistency payload when no more supported work remains.
     * Only claims full consistency when active-row check_consistency would pass.
     */
    private function _fix_consistency_done_payload(
        array $softDeleted,
        array $inserted,
        array $pushed,
        array $fieldSynced = [],
        int $fixedCount = 0,
        ?array $targetBranches = null,
        ?array $filterMeta = null
    ): array {
        $status = $this->_evaluate_active_item_consistency($targetBranches);

        $payload = [
            'filter'          => $filterMeta ?? [
                'limit'     => null,
                'branch_id' => null,
            ],
            'fixed_count'     => $fixedCount,
            'soft_deleted'    => $softDeleted,
            'field_synced'    => $fieldSynced,
            'inserted'        => $inserted,
            'pushed'          => $pushed,
            'remaining_count' => 0,
            'done'            => true,
        ];

        if ($status['consistent']) {
            $payload['message'] = $filterMeta['branch_id'] ?? null
                ? 'Selected branch is consistent with master.'
                : 'all databases rows is consistent';
            return $payload;
        }

        $payload['message'] = 'Consistency fixes applied, but differences remain. Run check_consistency for details.';
        $payload['remaining_field_diff_count'] = $status['field_diff_count'];
        $payload['remaining_missing_count'] = $status['missing_count'];
        $payload['remaining_extra_count'] = $status['extra_count'];

        return $payload;
    }

    /**
     * Active-only consistency summary (same scope as default check_consistency).
     *
     * @param array<int, string>|null $targetBranches
     */
    private function _evaluate_active_item_consistency(?array $targetBranches = null): array
    {
        $compareFields = $this->_item_consistency_compare_fields();

        $masterMap = $this->_load_item_consistency_map(0, false, 0);
        if ($masterMap === false) {
            return [
                'consistent'       => false,
                'field_diff_count' => 0,
                'missing_count'    => 0,
                'extra_count'      => 0,
            ];
        }

        $branches = $targetBranches ?? $this->_item_diagnostic_branches();
        $fieldDiffCount = 0;
        $missingCount = 0;
        $extraCount = 0;
        $consistent = true;

        foreach ($branches as $branchId => $dbName) {
            $branchMap = $this->_load_item_consistency_map($branchId, false, 0);
            if ($branchMap === false) {
                return [
                    'consistent'       => false,
                    'field_diff_count' => 0,
                    'missing_count'    => 0,
                    'extra_count'      => 0,
                ];
            }

            $comparison = $this->_compare_item_consistency_maps($masterMap, $branchMap, $compareFields);
            $missingCount += count($comparison['missing_on_branch']);
            $extraCount += count($comparison['extra_on_branch']);
            $fieldDiffCount += count($comparison['field_diffs']);

            if (
                !empty($comparison['missing_on_branch'])
                || !empty($comparison['extra_on_branch'])
                || !empty($comparison['field_diffs'])
            ) {
                $consistent = false;
            }
        }

        return [
            'consistent'       => $consistent,
            'field_diff_count' => $fieldDiffCount,
            'missing_count'    => $missingCount,
            'extra_count'      => $extraCount,
        ];
    }

    /**
     * Copy a full item row from sourceBranchId onto every inventory DB missing that id.
     */
    private function _mirror_insert_item_row(int $itemId, int $sourceBranchId): bool
    {
        $dbMap = [
            0 => 'master',
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            7 => 'mantlewood',
            6 => 'pharmacy',
        ];

        if (!isset($dbMap[$sourceBranchId])) {
            $this->error_message = 'Invalid source branch for insert.';
            return false;
        }

        $sourceDb = db_connect($dbMap[$sourceBranchId]);
        $sourceQuery = $sourceDb->table('item')->where('id', $itemId)->get();
        if ($sourceQuery === false) {
            $err = $sourceDb->error();
            $this->error_message = $err['message'] ?? 'Failed to read source item.';
            return false;
        }
        $row = $sourceQuery->getRowArray();
        if (!$row) {
            $this->error_message = 'Source item row not found.';
            return false;
        }

        foreach ($this->inventory_db_ids() as $branchId) {
            $db = db_connect($dbMap[$branchId]);
            $existsQuery = $db->table('item')->select('id')->where('id', $itemId)->get(1);
            if ($existsQuery === false) {
                $err = $db->error();
                $this->error_message = $err['message'] ?? ('Failed exists check on DB ' . $dbMap[$branchId]);
                return false;
            }
            if ($existsQuery->getRow()) {
                continue;
            }

            if ($db->table('item')->insert($row) === false) {
                $err = $db->error();
                $this->error_message = $err['message'] ?? ('Failed insert on DB ' . $dbMap[$branchId]);
                return false;
            }
        }

        return true;
    }

    /**
     * Get item adjustments per branch
     */
    public function get_adjustment()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $item_id = $this->payload['item_id'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $branch_item_id = $this->payload['branch_item_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        if (!$this->resolve_branch_models($branch_id)) {
            return $this->_create_response(
                FAIL,
                ['Invalid Branch ID.']
            );
        }

        $adjustments = $this->branch_item_adjustment->get_details($item_id, $branch_id, $branch_item_id, $status, $page, $data_size)?:[];
        $size = count($this->branch_item_adjustment->get_details($item_id, $branch_id, $branch_item_id, $status, null, null)?:[]);

        if(!$adjustments) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No adjustments found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $adjustments
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Adjust item
     */
    public function adjust()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        $branch_item_id = $this->payload['branch_item_id'] ?? null;
        $branch_id = $this->payload['branch_id'];
        $item_id = $this->payload['item_id'];

        if ($branch_item_id == null || $branch_id == null || $item_id == null) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "branch_item_id, branch_id and item_id are required. ",
            ];
        } elseif (!isset($this->payload['branch_item_id'])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "branch_item_id is required. ",
            ];
        } elseif (!isset($this->payload['prev_qty'])) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "prev_qty is required. this is the current quantity before adjustment. ",
            ];
        } else {

            // Determine correct model based on branch
            if (!$this->resolve_branch_models($branch_id)) {
                return $this->_create_response(
                    FAIL,
                    ['Invalid Branch ID.']
                );
            }
        
            $existing_pending = $this->branch_item_adjustment->select(
                '',
                [
                    'branch_item_id' => $branch_item_id,
                    'status' => 'pending',
                    'is_deleted' => 0
                ],
                1
            );
        
            if (!empty($existing_pending)) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    "There is already a pending adjustment for this branch item."
                ];
            } elseif (!$this->_attempt_adjust()) {
                $this->db->transRollback();
                $response_status = FAIL;
                $response = [
                    "Failed to adjust item."
                ];
            } else {
                $this->db->transCommit();
                $response_status = SUCCESS;
                $response = [
                    'item_id' => $item_id,
                    'message' => 'branch item adjustment added for approval successfully.'
                ];
            }
        
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to adjust item
     */
    private function _attempt_adjust()
    {
        $values = [
            'branch_item_id'        => $this->payload['branch_item_id'],
            'branch_id'             => $this->payload['branch_id'],
            'department_id'         => $this->payload['department_id'] ?? null,
            'item_id'               => $this->payload['item_id'],
            'counted_by_id'         => $this->payload['counted_by_id'] ?? null,
            'adjustment_type'       => $this->payload['adjustment_type'],
            'physical_count'        => $this->payload['physical_count'],
            'difference'            => $this->payload['difference'],
            'prev_qty'              => $this->payload['prev_qty'],
            'date_delivered'        => $this->payload['date_delivered'] ?? null,
            'date_counted'          => $this->payload['date_counted'],
            'remarks'               => $this->payload['remarks'] ?? null,
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        if (!$this->resolve_branch_models($values['branch_id'])) {
            $this->error_message = 'Invalid Branch ID.';
            return false;
        }

        if (!$this->mirror_insert('branch_item_adjustment', $values)) {
            return false;
        }

        return true;
    }

    /**
     * Adjust item change status
     */
    public function adjustment_change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'];
        if (!$branch_id) {
            return $this->_create_response(
                FAIL,
                ['Branch ID is required.']
            );
        }

        $adjustment_id = $this->payload['adjustment_id'];
        $where = [
            'id' => $adjustment_id,
            'is_deleted' => 0
        ];

        // 🔥 Read adjustment from master (mirrored across branch DBs)
        $this->resolve_branch_models(0);

        $this->db->transBegin();

        if (!$adjustment = $this->branch_item_adjustment->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Branch item adjustment is not found.'
            ];
        } elseif ($adjustment->status == $this->payload['status']) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Branch item adjustment status is already ' . $this->payload['status'] . '.'
            ];
        } elseif (!$branch_item = $this->branch_item->select('', ['id' => $adjustment->branch_item_id], 1)) {
            $this->db->transRollback();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Branch item is not found.',
            ];
        } elseif (!$this->_attempt_adjust_branch_item($adjustment, $branch_item)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to adjust branch item.',
            ];
        } elseif (!$this->_attempt_adjustment_change_status($adjustment)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change item adjustment status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'adjustment_id' => $adjustment_id,
                'message'    => 'Item adjustment status changed successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt adjust branch item
     */
    protected function _attempt_adjust_branch_item($adjustment, $branch_item)
    {
        // Only adjust if the adjustment is approved
        if ($this->payload['status'] != 'approved') {
            return true;
        }

        // Calculate new quantity
        $new_qty = $adjustment->physical_count;

        $values = [
            'current_qty'   => $new_qty,
            'latest_activity' => 'Adjustment',
            'latest_activity_id' => $adjustment->id,
            'adjustment_type' => $adjustment->adjustment_type,
            'latest_department_id' => $adjustment->department_id,
            'updated_by'    => $this->payload['requester'],
            'updated_on'    => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $branch_item->id];

        if (!$this->mirror_update('branch_item', $where, $values)) {
            return false;
        }

        $qty_delta = (float)$adjustment->physical_count - (float)$adjustment->prev_qty;
        if ($qty_delta != 0.0) {
            $purchase_delta = $this->_qty_to_purchase_uom(
                (int)$adjustment->item_id,
                $branch_item->unit,
                $qty_delta
            );
            if (!$this->_apply_qty_delta_weighted_cost(
                (int)$adjustment->item_id,
                (int)$adjustment->branch_id,
                $purchase_delta
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt adjustment change status
     */
    protected function _attempt_adjustment_change_status($adjustment)
    {
        $status = $this->payload['status'];

        $values = [
            'status'            => $status,
            'approved_by'      => $status == 'approved' ? $this->payload['requester'] : null,
            'approved_on'      => $status == 'approved' ? date('Y-m-d H:i:s') : null,
            'disapproved_by'   => $status == 'disapproved' ? $this->payload['requester'] : null,
            'disapproved_on'   => $status == 'disapproved' ? date('Y-m-d H:i:s') : null,
            'updated_by'        => $this->payload['requester'],
            'updated_on'        => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $adjustment->id];

        if (!$this->mirror_update('branch_item_adjustment', $where, $values)) {
            return false;
        }

        return true;
    }

    /**
     * Blindsheet counting and approval workflows, following the adjustment controller layout.
     */
    protected function _api_validation()
    {
        $this->csr_items_authenticated = false;
        if (($this->payload['origin'] ?? null) !== 'csr') {
            return parent::_api_validation();
        }

        $method = $this->router->methodName();
        $allowed_methods = ['get', 'get_blindsheet', 'blindsheet', 'blindsheet_change_status'];
        $provided_key = $this->request->getHeaderLine('API-KEY');
        if (!in_array($method, $allowed_methods, true) || !$this->_items_api_key_is_valid($provided_key)) {
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Invalid CSR access.']);
        }

        try {
            $branch_id = $this->_blindsheet_positive_id($this->payload['branch_id'] ?? null, 'branch_id');
            if (!isset(Branch_item_blindsheet::database_groups()[$branch_id]) || $branch_id === 0) {
                throw new \InvalidArgumentException('Invalid Branch ID.');
            }
            $requester_id = $this->_blindsheet_positive_id($this->payload['requester'] ?? null, 'requester');
            $token = $this->payload['token'] ?? null;
            if (!is_string($token) || $token === '') {
                throw new \InvalidArgumentException('Invalid Auth token.');
            }
            $user = $this->_csr_items_user($branch_id, $requester_id);
            if (!$user || !is_string($user->token ?? null) || !hash_equals($user->token, $token)) {
                throw new \InvalidArgumentException('Invalid Requester or Auth token.');
            }
            $expiry = $user->token_expiry ?? null;
            $expiry_date = is_string($expiry) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiry) : false;
            if (!$expiry_date || $expiry_date->format('Y-m-d H:i:s') !== $expiry
                || $expiry_date < new \DateTimeImmutable()) {
                throw new \InvalidArgumentException('Invalid Auth token.');
            }
            $this->requester_user = $user;
            $this->csr_items_authenticated = true;
            return true;
        } catch (\InvalidArgumentException $error) {
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => $error->getMessage()]);
        } catch (\Throwable $error) {
            log_message('error', 'Items CSR authentication failed: {exception}', ['exception' => get_class($error)]);
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Unable to validate CSR user.']);
        }
    }

    private function _items_api_key_is_valid(string $provided_key): bool
    {
        if (function_exists('myt_env')) {
            return api_key_is_valid($provided_key);
        }
        return $this->_items_configured_api_key_is_valid($provided_key);
    }

    /** Older deployed constants lack myt_env(); retain the normal key settings. */
    private function _items_configured_api_key_is_valid(string $provided_key): bool
    {
        if ($provided_key === '') { return false; }
        $api_keys = (string) API_KEY;
        foreach ([$_ENV['API_KEYS'] ?? null, $_SERVER['API_KEYS'] ?? null, getenv('API_KEYS')] as $configured_keys) {
            if (is_string($configured_keys) && $configured_keys !== '') {
                $api_keys = $configured_keys;
                break;
            }
        }
        foreach (array_filter(array_map('trim', explode(',', $api_keys))) as $api_key) {
            if (hash_equals($api_key, $provided_key)) { return true; }
        }
        return false;
    }

    protected function _csr_items_user(int $branch_id, int $requester_id)
    {
        $db = db_connect(Branch_item_blindsheet::database_groups()[$branch_id]);
        return (new User($db))->select('', ['id' => $requester_id, 'is_deleted' => 0], 1);
    }

    protected function _permission_allowed(string $method)
    {
        if (($this->payload['origin'] ?? null) === 'csr') {
            if ($this->csr_items_authenticated
                && in_array($method, ['get', 'get_blindsheet', 'blindsheet', 'blindsheet_change_status'], true)) {
                return true;
            }
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Invalid CSR access.']);
        }
        return parent::_permission_allowed($method);
    }

    /**
     * Get branch item blindsheets
     */
    public function get_blindsheet()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $blindsheet_id = $this->payload['blindsheet_id'] ?? null;
        $item_id = $this->payload['item_id'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $branch_item_id = $this->payload['branch_item_id'] ?? null;
        $status = $this->payload['status'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($branch_id === null || $branch_id === '') {
            return $this->_create_response(FAIL, ['Branch ID is required.']);
        }

        if (!$this->_attempt_resolve_blindsheet_branch($branch_id)) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        if (!$this->_attempt_validate_blindsheet_filters($blindsheet_id, $item_id, $branch_item_id, $status)) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        // Catch database failures without changing the standard list response flow.
        try {
            $blindsheets = $this->branch_item_blindsheet->get_details($blindsheet_id, $item_id, $branch_id, $branch_item_id, $status, $page, $data_size);
            $size = $this->branch_item_blindsheet->get_count($blindsheet_id, $item_id, $branch_id, $branch_item_id, $status);
            if ($blindsheets === false || $size === false) {
                throw new \RuntimeException('Blindsheet lookup failed.');
            }
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        if (!$blindsheets) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No blindsheets found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $blindsheets
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Submit a branch item blindsheet
     */
    public function blindsheet()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_item_id = $this->payload['branch_item_id'] ?? null;
        $branch_id = $this->payload['branch_id'] ?? null;
        $item_id = $this->payload['item_id'] ?? null;

        if ($branch_item_id === null || $branch_item_id === '' || $branch_id === null || $branch_id === ''
            || $item_id === null || $item_id === '') {
            $response_status = FAIL;
            $response = [
                'branch_item_id, branch_id and item_id are required.'
            ];
        } elseif (!isset($this->payload['prev_qty'])) {
            $response_status = FAIL;
            $response = [
                'prev_qty is required. this is the current quantity before blindsheet.'
            ];
        } elseif (!$this->_attempt_resolve_blindsheet_branch($branch_id)) {
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (!$this->_attempt_validate_blindsheet()) {
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (!$this->_attempt_prepare_blindsheet()) {
            $response_status = FAIL;
            $response = [$this->error_message];
        } else {
            $transaction_started = false;
            try {
                if ($this->db->transBegin() === false) {
                    throw new \RuntimeException('Unable to start blindsheet transaction.');
                }
                $existing_pending = $this->blindsheet_master->get_pending($branch_item_id, $branch_id);
                $transaction_started = true;
            } catch (\Throwable $error) {
                $this->_blindsheet_record_error($error);
            }

            if (!$transaction_started) {
                $this->_attempt_rollback_blindsheet();
                $response_status = FAIL;
                $response = [$this->error_message];
            } elseif (!empty($existing_pending)) {
                $this->_attempt_rollback_blindsheet();
                $response_status = FAIL;
                $response = [
                    'There is already a pending blindsheet for this branch item.'
                ];
            } elseif (!$this->_attempt_blindsheet()) {
                $this->_attempt_rollback_blindsheet();
                $response_status = FAIL;
                $response = [$this->error_message];
            } else {
                $committed = false;
                try {
                    $committed = $this->db->transStatus() !== false && $this->db->transCommit() !== false;
                    if (!$committed) {
                        throw new \RuntimeException('Unable to commit blindsheet transaction.');
                    }
                } catch (\Throwable $error) {
                    $this->_blindsheet_record_error($error);
                }

                if (!$committed) {
                    $this->_attempt_rollback_blindsheet();
                    $response_status = FAIL;
                    $response = [$this->error_message];
                } else {
                    $response_status = SUCCESS;
                    $response = [
                        'blindsheet_id' => $this->blindsheet_id,
                        'item_id' => (int) $item_id,
                        'message' => 'Branch item blindsheet added for approval successfully.'
                    ];
                }
            }

            $this->blindsheet_master->release_operation_lock($this->blindsheet_lock_name);
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to add a pending blindsheet
     */
    protected function _attempt_blindsheet(): bool
    {
        try {
            $branch_id = (int) $this->payload['branch_id'];
            $branch_item_id = (int) $this->payload['branch_item_id'];
            $item_id = (int) $this->payload['item_id'];
            $physical_count = $this->_blindsheet_quantity_cents($this->payload['physical_count'], 'physical_count');
            $prev_qty = $this->_blindsheet_quantity_cents($this->payload['prev_qty'], 'prev_qty');
            $difference = $physical_count - $prev_qty;
            $department_id = $this->payload['department_id'] ?? null;
            $department_id = ($department_id === null || $department_id === '') ? null : (int) $department_id;
            $counted_by_id = $this->payload['counted_by_id'] ?? null;
            $remarks = $this->payload['remarks'] ?? null;
            $date_counted = $this->payload['date_counted'];
            $values = [
                'branch_item_id' => $branch_item_id,
                'branch_id' => $branch_id,
                'item_id' => $item_id,
                'department_id' => $department_id,
                'counted_by_id' => $counted_by_id,
                'physical_count' => $this->_blindsheet_quantity_value($physical_count),
                'prev_qty' => $this->_blindsheet_quantity_value($prev_qty),
                'difference' => $this->_blindsheet_quantity_value($difference),
                'date_counted' => $date_counted,
                'remarks' => $remarks,
                'status' => 'pending',
                'is_deleted' => 0,
                'added_by' => $this->payload['requester'],
                'added_by_branch_id' => $this->csr_items_authenticated ? $branch_id : 0,
                'added_on' => date('Y-m-d H:i:s'),
            ];
            $inventory = $this->_blindsheet_read_inventory($values['branch_item_id'], $values['branch_id'], $values['item_id']);
            if ($this->_blindsheet_quantity_cents($inventory['current_qty'], 'current_qty')
                !== $this->_blindsheet_quantity_cents($values['prev_qty'], 'prev_qty')) {
                throw new \InvalidArgumentException('Inventory changed. Please submit a fresh physical count.');
            }
            $blindsheet_id = $this->mirror_insert('branch_item_blindsheet', $values);
            if (!$blindsheet_id) {
                return false;
            }
            $this->blindsheet_id = $blindsheet_id;
            return true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return false;
        }
    }

    /**
     * Change branch item blindsheet status
     */
    public function blindsheet_change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $branch_id = $this->payload['branch_id'] ?? null;
        $blindsheet_id = $this->payload['blindsheet_id'] ?? null;
        $status = $this->payload['status'] ?? null;

        if ($branch_id === null || $branch_id === '') {
            return $this->_create_response(FAIL, ['Branch ID is required.']);
        }

        if (!$this->_attempt_resolve_blindsheet_branch($branch_id)) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        if (!$this->_attempt_validate_blindsheet_status($blindsheet_id, $status)) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        if (!$this->_attempt_prepare_blindsheet()) {
            return $this->_create_response(FAIL, [$this->error_message]);
        }

        // Read canonical records from master, as adjustment_change_status() does.
        $where = [
            'id' => $blindsheet_id,
            'branch_id' => $branch_id,
            'is_deleted' => 0
        ];
        $transaction_started = false;
        $blindsheet = null;
        $branch_item = null;

        try {
            $this->resolve_branch_models(0);
            if ($this->db->transBegin() === false) {
                throw new \RuntimeException('Unable to start blindsheet transaction.');
            }
            $blindsheet = $this->branch_item_blindsheet->select('', $where, 1);
            if (!$blindsheet && (int) ($this->branch_item_blindsheet->db_error()['code'] ?? 0) !== 0) {
                throw new \RuntimeException('Blindsheet record lookup failed.');
            }
            if ($blindsheet && $blindsheet->status === 'pending') {
                $branch_item = $this->branch_item->select('', [
                    'id' => $blindsheet->branch_item_id,
                    'branch_id' => $branch_id,
                    'item_id' => $blindsheet->item_id,
                    'is_deleted' => 0
                ], 1);
                if (!$branch_item && (int) ($this->branch_item->db_error()['code'] ?? 0) !== 0) {
                    throw new \RuntimeException('Blindsheet record lookup failed.');
                }
            }
            $transaction_started = true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
        }

        if (!$transaction_started) {
            $this->_attempt_rollback_blindsheet();
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (!$blindsheet) {
            $this->_attempt_rollback_blindsheet();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Branch item blindsheet is not found in every inventory mirror.'
            ];
        } elseif ($blindsheet->status !== 'pending') {
            $this->_attempt_rollback_blindsheet();
            $response_status = FAIL;
            $response = [
                'Only pending blindsheets can change status.'
            ];
        } elseif (!$branch_item) {
            $this->_attempt_rollback_blindsheet();
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'Active branch item and item must exist in every inventory mirror.'
            ];
        } elseif (!$this->_attempt_blindsheet_branch_item($blindsheet, $branch_item)) {
            $this->_attempt_rollback_blindsheet();
            $response_status = $this->blindsheet_error_status;
            $response = [$this->error_message];
        } elseif (!$this->_attempt_blindsheet_change_status($blindsheet)) {
            $this->_attempt_rollback_blindsheet();
            $response_status = FAIL;
            $response = [$this->error_message];
        } else {
            $committed = false;
            try {
                $committed = $this->db->transStatus() !== false && $this->db->transCommit() !== false;
                if (!$committed) {
                    throw new \RuntimeException('Unable to commit blindsheet transaction.');
                }
            } catch (\Throwable $error) {
                $this->_blindsheet_record_error($error);
            }

            if (!$committed) {
                $this->_attempt_rollback_blindsheet();
                $response_status = FAIL;
                $response = [$this->error_message];
            } else {
                $response_status = SUCCESS;
                $response = [
                    'blindsheet_id' => (int) $blindsheet_id,
                    'message' => 'Item blindsheet status changed successfully.'
                ];
            }
        }

        $this->blindsheet_master->release_operation_lock($this->blindsheet_lock_name);
        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to update inventory for an approved blindsheet
     */
    protected function _attempt_blindsheet_branch_item($blindsheet, $branch_item): bool
    {
        $this->blindsheet_error_status = FAIL;
        try {
            $this->_blindsheet_read_selection((int) $blindsheet->id, (int) $blindsheet->branch_id);
            $inventory = $this->_blindsheet_read_inventory((int) $blindsheet->branch_item_id, (int) $blindsheet->branch_id, (int) $blindsheet->item_id);
            if ($this->payload['status'] !== 'approved') {
                return true;
            }
            $prev_qty = $this->_blindsheet_quantity_cents($blindsheet->prev_qty, 'prev_qty');
            $physical_count = $this->_blindsheet_quantity_cents($blindsheet->physical_count, 'physical_count');
            if ($this->_blindsheet_quantity_cents($inventory['current_qty'], 'current_qty') !== $prev_qty) {
                throw new \InvalidArgumentException('Inventory changed. Disapprove this blindsheet and submit a fresh physical count.');
            }
            $values = [
                'current_qty' => $this->_blindsheet_quantity_value($physical_count),
                'latest_activity' => 'Blindsheet',
                'latest_activity_id' => $blindsheet->id,
                'adjustment_type' => 'inventory_adjustment',
                'latest_department_id' => $blindsheet->department_id,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s'),
            ];
            $where = [
                'id' => $branch_item->id,
                'branch_id' => $blindsheet->branch_id,
                'item_id' => $blindsheet->item_id,
                'is_deleted' => 0
            ];
            if (!$this->mirror_update('branch_item', $where, $values)) {
                return false;
            }
            $qty_delta = ($physical_count - $prev_qty) / 100;
            if ($qty_delta != 0.0) {
                $purchase_delta = $this->_qty_to_purchase_uom((int) $blindsheet->item_id, $branch_item->unit, $qty_delta);
                if (!$this->_apply_qty_delta_weighted_cost((int) $blindsheet->item_id, (int) $blindsheet->branch_id, $purchase_delta)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return false;
        }
    }

    /**
     * Attempt to change blindsheet status
     */
    protected function _attempt_blindsheet_change_status($blindsheet): bool
    {
        try {
            $status = $this->payload['status'];
            $audit_source = $this->csr_items_authenticated ? (int) $this->payload['branch_id'] : 0;
            $now = date('Y-m-d H:i:s');
            $values = [
                'status' => $status,
                'approved_by' => $status === 'approved' ? $this->payload['requester'] : null,
                'approved_by_branch_id' => $status === 'approved' ? $audit_source : null,
                'approved_on' => $status === 'approved' ? $now : null,
                'disapproved_by' => $status === 'disapproved' ? $this->payload['requester'] : null,
                'disapproved_by_branch_id' => $status === 'disapproved' ? $audit_source : null,
                'disapproved_on' => $status === 'disapproved' ? $now : null,
                'updated_by' => $this->payload['requester'],
                'updated_by_branch_id' => $audit_source,
                'updated_on' => $now,
            ];
            $where = [
                'id' => $blindsheet->id,
                'branch_id' => $blindsheet->branch_id,
                'status' => 'pending',
                'is_deleted' => 0
            ];
            if (!$this->mirror_update('branch_item_blindsheet', $where, $values)) {
                return false;
            }
            return true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return false;
        }
    }

    /**
     * Validate and resolve the selected branch
     */
    protected function _attempt_resolve_blindsheet_branch($branch_id): bool
    {
        try {
            $branch_id = $this->_blindsheet_positive_id($branch_id, 'branch_id');
            if (!$this->resolve_branch_models($branch_id)) {
                throw new \InvalidArgumentException('Invalid Branch ID.');
            }
            return true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return false;
        }
    }

    /**
     * Validate optional blindsheet filters
     */
    protected function _attempt_validate_blindsheet_filters($blindsheet_id, $item_id, $branch_item_id, $status): bool
    {
        try {
            foreach (['blindsheet_id' => $blindsheet_id, 'item_id' => $item_id, 'branch_item_id' => $branch_item_id] as $field => $value) {
                if ($value !== null && $value !== '') {
                    $this->_blindsheet_positive_id($value, $field);
                }
            }
            if ($status !== null && $status !== '' && !in_array($status, ['pending', 'approved', 'disapproved'], true)) {
                throw new \InvalidArgumentException('Invalid blindsheet status.');
            }
            return true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return false;
        }
    }

    /**
     * Validate count fields before starting writes
     */
    protected function _attempt_validate_blindsheet(): bool
    {
        try {
            $branch_id = $this->_blindsheet_positive_id($this->payload['branch_id'] ?? null, 'branch_id');
            $branch_item_id = $this->_blindsheet_positive_id($this->payload['branch_item_id'] ?? null, 'branch_item_id');
            $item_id = $this->_blindsheet_positive_id($this->payload['item_id'] ?? null, 'item_id');
            $physical_count = $this->_blindsheet_quantity_cents($this->payload['physical_count'] ?? null, 'physical_count');
            $prev_qty = $this->_blindsheet_quantity_cents($this->payload['prev_qty'] ?? null, 'prev_qty');
            $difference = $physical_count - $prev_qty;
            if (array_key_exists('difference', $this->payload)
                && $this->_blindsheet_quantity_cents($this->payload['difference'], 'difference', true) !== $difference) {
                throw new \InvalidArgumentException('difference must equal physical_count minus prev_qty.');
            }
            $date_counted = $this->payload['date_counted'] ?? null;
            $date = is_string($date_counted) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date_counted) : false;
            if (!$date || $date->format('Y-m-d') !== $date_counted) {
                throw new \InvalidArgumentException('date_counted must be a real YYYY-MM-DD date.');
            }
            $department_id = $this->payload['department_id'] ?? null;
            $department_id = ($department_id === null || $department_id === '') ? null : $this->_blindsheet_positive_id($department_id, 'department_id');
            $counted_by_id = $this->payload['counted_by_id'] ?? null;
            $remarks = $this->payload['remarks'] ?? null;
            if (($counted_by_id !== null && (!is_string($counted_by_id) && !is_int($counted_by_id)))
                || ($counted_by_id !== null && strlen((string) $counted_by_id) > 255)
                || ($remarks !== null && !is_string($remarks))) {
                throw new \InvalidArgumentException('Invalid counted_by_id or remarks.');
            }
            return true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return false;
        }
    }

    /**
     * Validate the requested status transition
     */
    protected function _attempt_validate_blindsheet_status($blindsheet_id, $status): bool
    {
        try {
            $this->_blindsheet_positive_id($blindsheet_id, 'blindsheet_id');
            if (!in_array($status, ['approved', 'disapproved'], true)) {
                throw new \InvalidArgumentException('status must be approved or disapproved.');
            }
            return true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return false;
        }
    }

    /**
     * Check dependencies and lock the operation
     */
    protected function _attempt_prepare_blindsheet(): bool
    {
        $this->blindsheet_lock_name = null;
        try {
            $this->blindsheet_db = $this->blindsheet_db ?? db_connect('master');
            $this->blindsheet_schemas = $this->blindsheet_schemas ?: Branch_item_blindsheet::configured_schemas();
            foreach (Branch_item_blindsheet::database_groups() as $branch_id => $group) {
                if (!isset($this->blindsheet_schemas[$branch_id])
                    || !preg_match('/^[a-zA-Z0-9_]+$/D', $this->blindsheet_schemas[$branch_id])) {
                    throw new \RuntimeException('Invalid inventory schema configuration.');
                }
            }
            $this->blindsheet_master = new Branch_item_blindsheet($this->blindsheet_db);
            $this->blindsheet_lock_name = $this->blindsheet_master->acquire_operation_lock($this->blindsheet_schemas);
            return true;
        } catch (\Throwable $error) {
            $this->_blindsheet_record_error($error);
            return false;
        }
    }

    /**
     * Roll back the owning transaction without masking the original failure
     */
    protected function _attempt_rollback_blindsheet(): bool
    {
        try {
            if ($this->db->transRollback() === false) {
                throw new \RuntimeException('Unable to roll back blindsheet transaction.');
            }
            return true;
        } catch (\Throwable $error) {
            // Preserve the operation's original error and do not claim mirror rollback.
            log_message('error', 'Unable to roll back Items blindsheet transaction: {exception}', ['exception' => get_class($error)]);
            return false;
        }
    }

    /**
     * Record a blindsheet operation failure
     */
    private function _blindsheet_record_error(\Throwable $error): void
    {
        $this->blindsheet_error_status = $error instanceof \OutOfBoundsException ? FAIL_NOT_FOUND : FAIL;
        if ($error instanceof \InvalidArgumentException || $error instanceof \OutOfBoundsException) {
            $this->error_message = $error->getMessage();
        } else {
            log_message('error', 'Items blindsheet operation failed: {exception}', ['exception' => get_class($error)]);
            $this->error_message = 'Blindsheet operation failed. Review inventory mirrors before retrying.';
        }
    }

    /**
     * Validate a positive identifier
     */
    private function _blindsheet_positive_id($value, string $field): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || strlen((string) $value) > 10 || (int) $value > 2147483647) {
            throw new \InvalidArgumentException($field . ' must be a positive integer.');
        }
        return (int) $value;
    }

    /**
     * Validate a decimal quantity without floating-point rounding
     */
    private function _blindsheet_quantity_cents($value, string $field, bool $signed = false): int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new \InvalidArgumentException($field . ' must be a valid quantity with at most two decimal places.');
        }
        $value = (string) $value;
        $pattern = $signed ? '/^(-?)([0-9]{1,11})(?:\.([0-9]{1,2}))?$/D' : '/^()([0-9]{1,11})(?:\.([0-9]{1,2}))?$/D';
        if (!preg_match($pattern, $value, $parts)) {
            throw new \InvalidArgumentException($field . ' must be ' . ($signed ? '' : 'non-negative and ')
                . 'fit DECIMAL(13,2) with at most two decimal places.');
        }
        $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');
        return ($parts[1] ?? '') === '-' ? -$cents : $cents;
    }

    /**
     * Format an exact decimal quantity
     */
    private function _blindsheet_quantity_value(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Validate inventory consistency across the model results
     */
    private function _blindsheet_read_inventory(int $branch_item_id, int $branch_id, int $item_id): array
    {
        $canonical = null;
        $rows = $this->blindsheet_master->get_inventory_mirrors($branch_item_id, $branch_id, $item_id);
        foreach ($rows as $row) {
            if ($canonical !== null && ($this->_blindsheet_quantity_cents($canonical['current_qty'], 'current_qty') !== $this->_blindsheet_quantity_cents($row['current_qty'], 'current_qty')
                || (int) $canonical['unit'] !== (int) $row['unit'])) {
                throw new \InvalidArgumentException('Inventory mirrors differ. Reconcile inventory before submitting or approving a blindsheet.');
            }
            $canonical = $canonical ?? $row;
        }
        return $canonical;
    }

    /**
     * Validate the pending selection across the model results
     */
    private function _blindsheet_read_selection(int $blindsheet_id, int $branch_id): object
    {
        $blindsheet = null;
        $rows = $this->blindsheet_master->get_selection_mirrors($blindsheet_id, $branch_id);
        foreach ($rows as $row) {
            if ($row->status !== 'pending') {
                throw new \InvalidArgumentException('Only pending blindsheets can change status.');
            }
            if ($blindsheet !== null) {
                foreach (['branch_item_id', 'item_id', 'physical_count', 'prev_qty', 'difference', 'department_id'] as $field) {
                    if ((string) ($blindsheet->$field ?? '') !== (string) ($row->$field ?? '')) {
                        throw new \InvalidArgumentException('Blindsheet mirrors differ. Reconcile them before approval.');
                    }
                }
            }
            $blindsheet = $blindsheet ?? $row;
        }
        return $blindsheet;
    }

    /**
     * Retire related blindsheets through the model
     */
    private function _soft_delete_item_blindsheets(int $item_id, array $values, ?int $branch_id = null): bool
    {
        $groups = Branch_item_blindsheet::database_groups();
        $branch_ids = $branch_id === null ? $this->inventory_db_ids() : [$branch_id];
        foreach ($branch_ids as $source_id) {
            $model = new Branch_item_blindsheet(db_connect($groups[$source_id]));
            $audit_source = $this->csr_items_authenticated ? (int) $this->payload['branch_id'] : 0;
            if (!$model->retire_item($item_id, $values, $audit_source)) {
                $this->error_message = 'Failed to retire item blindsheets.';
                return false;
            }
        }
        return true;
    }

    /**
     * Get item transfers per branch
     */
    public function get_transfer()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->_load_transfer_models();

        $transfer_id = $this->payload['transfer_id'] ?? null;
        $transfer_date_from = $this->payload['transfer_date_from'] ?? null;
        $transfer_date_to = $this->payload['transfer_date_to'] ?? null;
        $delivery_date_from = $this->payload['delivery_date_from'] ?? null;
        $delivery_date_to = $this->payload['delivery_date_to'] ?? null;
        $branch_id_from = $this->payload['branch_id_from'] ?? null;
        $branch_id_to = $this->payload['branch_id_to'] ?? null;
        $status = $this->payload['status'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $transfers = $this->branch_item_transfer->get_details($transfer_id, $transfer_date_from, $transfer_date_to, $delivery_date_from, $delivery_date_to, $branch_id_from, $branch_id_to, $status, $page, $data_size)?:[];
        $size = count($this->branch_item_transfer->get_details($transfer_id, $transfer_date_from, $transfer_date_to, $delivery_date_from, $delivery_date_to, $branch_id_from, $branch_id_to, $status, null, null)?:[]);

        if(!$transfers) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No transfers found.'
            ];
        } else {
            foreach ($transfers as $tf => $transfer) {
                // Fetch branch names
                $branch_from = $this->branch_master->select('name', ['id' => $transfer['branch_id_from'], 'is_deleted' => 0], 1);
                $branch_to = $this->branch_master->select('name', ['id' => $transfer['branch_id_to'], 'is_deleted' => 0], 1);
                $transfers[$tf]['branch_name_from'] = $branch_from ? $branch_from->name : null;
                $transfers[$tf]['branch_name_to'] = $branch_to ? $branch_to->name : null;

                // Fetch adder name
                $user_adder = $this->user->select('id, first_name, last_name, role_id', ['id' => $transfer['added_by'], 'is_deleted' => 0], 1);
                $transfers[$tf]['added_by_name'] = $user_adder ? $user_adder->first_name . ' ' . $user_adder->last_name : null;

                // Fetch approver name
                $user_approver = $this->user->select('id, first_name, last_name, role_id', ['id' => $transfer['approved_by'], 'is_deleted' => 0], 1);
                $transfers[$tf]['approved_by_name'] = $user_approver ? $user_approver->first_name . ' ' . $user_approver->last_name : null;

                // Fetch opener name
                $user_opener = $this->user->select('id, first_name, last_name, role_id', ['id' => $transfer['opened_by'], 'is_deleted' => 0], 1);
                $transfers[$tf]['opened_by_name'] = $user_opener ? $user_opener->first_name . ' ' . $user_opener->last_name : null;

                // Fetch receiver name
                $user_receiver = $this->user->select('id, first_name, last_name, role_id', ['id' => $transfer['received_by'], 'is_deleted' => 0], 1);
                $transfers[$tf]['received_by_name'] = $user_receiver ? $user_receiver->first_name . ' ' . $user_receiver->last_name : null;

                // Fetch disapprover name
                $user_disapprover = $this->user->select('id, first_name, last_name, role_id', ['id' => $transfer['disapproved_by'], 'is_deleted' => 0], 1);
                $transfers[$tf]['disapproved_by_name'] = $user_disapprover ? $user_disapprover->first_name . ' ' . $user_disapprover->last_name : null;

                // Fetch transfer items for each transfer
                $transfer_items = $this->branch_item_transfer_item->get_details($transfer['id']);
                if ($transfer_items === false) {
                    return $this->_create_response(FAIL, ['Failed to read transfer items.']);
                }
                $transfers[$tf]['transfer_items'] = $transfer_items;
            }
            $transfers = $this->branch_item_transfer_item->get_transfer_expiry_details($transfers);
            if ($transfers === false) {
                return $this->_create_response(FAIL, ['Failed to read receipt expiry details.']);
            }
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => $transfers
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Transfer item
     */
    public function transfer()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->_load_transfer_models();

        if (!$this->db->transBegin()) {
            return $this->_create_response(FAIL, ['Failed to begin transfer transaction.']);
        }

        $branch_id_from = $this->payload['branch_id_from'] ?? null;
        $branch_id_to = $this->payload['branch_id_to'] ?? null;

        if ($branch_id_from == null || $branch_id_to == null) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "branch_id_from and branch_id_to are required. ",
            ];
        } elseif (!$this->_attempt_validate_transfer_items()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif ($this->branch_item_transfer->select('', ['branch_id_from' => $branch_id_from, 'branch_id_to' => $branch_id_to, 'status' => 'pending', 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "There is still a pending transfer for this branch from and branch to. ",
            ];
        } elseif (!$branch_item_transfer_id = $this->_attempt_transfer()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to transfer branch item. ",
            ];
        } elseif (!$this->_attempt_transfer_items($branch_item_transfer_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to transfer branch item details. Check if items are provided. ",
            ];
        } elseif (!$this->db->transStatus() || !$this->db->transCommit()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = ['Failed to commit branch item transfer.'];
        } else {
            $response_status = SUCCESS;
            $response = [
                'branch_item_transfer_id' => $branch_item_transfer_id,
                'message'    => 'branch item transfer added for approval successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Use the same master connection for transfer records, locks, and qualified branch inventory writes.
     */
    protected function _load_transfer_models()
    {
        $this->db = db_connect('master');
        $this->branch_item_transfer = new Branch_item_transfer($this->db);
        $this->branch_item_transfer_item = new Branch_item_transfer_item($this->db);
        $this->resolve_branch_models(0);
    }

    /**
     * Validate transfer selections before inserting or moving inventory.
     */
    private function _attempt_validate_transfer_items($items = null, $branch_id_from = null, $transfer_id = null)
    {
        $save_items = $items === null;
        $items = $items ?? ($this->payload['items'] ?? []);
        $branch_id_from = $branch_id_from ?? ($this->payload['branch_id_from'] ?? null);
        $branch_id_to = $this->payload['branch_id_to'] ?? null;
        if (!$this->_valid_transfer_id($branch_id_from) || !in_array((int) $branch_id_from, [1, 2, 3, 4, 5, 6, 7], true)
            || ($save_items && (!$this->_valid_transfer_id($branch_id_to)
                || !in_array((int) $branch_id_to, [1, 2, 3, 4, 5, 6, 7], true)
                || (int) $branch_id_from === (int) $branch_id_to))) {
            $this->error_message = 'Select different source and destination branches from 1 through 7.';
            return false;
        }
        if (!is_array($items) || !$items) {
            $this->error_message = 'items is required and must be a non-empty array.';
            return false;
        }
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $this->error_message = 'items['.$index.'] must be an object.';
                return false;
            }
            foreach (['item_id', 'branch_item_id', 'unit'] as $field) {
                if (!$this->_valid_transfer_id($item[$field] ?? null)) {
                    $this->error_message = 'items['.$index.'].'.$field.' must be a positive integer.';
                    return false;
                }
            }
            $qty = $item['transfer_qty'] ?? null;
            if (!$this->_valid_transfer_qty($qty) || (float) $qty <= 0) {
                $this->error_message = 'items['.$index.'].transfer_qty must be positive with at most four decimal places.';
                return false;
            }
            if (!$this->_normalize_transfer_batch_no($item['batch_no'] ?? null, $batch_no)) {
                $this->error_message = 'items['.$index.'].batch_no must be a batch code of up to 255 characters or null.';
                return false;
            }
            $expiry_id = $item['receipt_note_item_expiry_date_id'] ?? null;
            if (is_string($expiry_id) && trim($expiry_id) === '') {
                $expiry_id = null;
            }
            if ($batch_no === null && $expiry_id !== null) {
                $this->error_message = 'items['.$index.'].receipt_note_item_expiry_date_id must be null when batch_no is absent.';
                return false;
            }
            if (($batch_no !== null && $save_items) || $expiry_id !== null) {
                if (!$this->_valid_transfer_id($expiry_id)) {
                    $this->error_message = 'items['.$index.'].receipt_note_item_expiry_date_id must be a positive integer when batch_no is provided.';
                    return false;
                }
            }
            $items[$index]['receipt_note_item_expiry_date_id'] = $expiry_id === null ? null : (int) $expiry_id;
            $items[$index]['batch_no'] = $batch_no;
        }
        if (!$this->branch_item_transfer_item->validate_quantities($items, $branch_id_from, $transfer_id)) {
            $this->error_message = $this->branch_item_transfer_item->get_quantity_error();
            return false;
        }
        if ($save_items) {
            $this->payload['items'] = $items;
        }
        return true;
    }

    // private function _valid_transfer_id($value)
    // {
    //     return (is_int($value) || is_string($value)) && ctype_digit((string) $value)
    //         && filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0;
    // }

    private function _valid_transfer_id($value)
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && trim($value) !== '';
    }

    private function _valid_transfer_qty($value)
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0
            && (float) $value <= 999999999.9999 && round((float) $value, 4) == (float) $value;
    }

    private function _normalize_transfer_batch_no($value, &$batch_no)
    {
        $batch_no = null;
        if ($value === null) {
            return true;
        }
        if (is_int($value) && $value >= 0) {
            $value = (string) $value;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/\p{Cc}/u', $value)) {
            return false;
        }
        $value = trim($value, ' ');
        if ($value === '') {
            return true;
        }
        if (str_contains($value, ',') || mb_strlen($value, 'UTF-8') > 255) {
            return false;
        }
        $batch_no = $value;
        return true;
    }

    private function _attempt_lock_transfer_items($transfer_id)
    {
        $items = $this->branch_item_transfer_item->get_details($transfer_id);
        if (!$items || !$this->branch_item_transfer_item->lock_items($items)) {
            $this->error_message = 'Failed to lock transfer inventory items.';
            return false;
        }
        $this->transfer_items = $this->branch_item_transfer_item->get_details($transfer_id, null, null, null, null, null, null, true);
        if (!$this->transfer_items) {
            $this->error_message = 'Failed to read current transfer items.';
            return false;
        }
        return true;
    }

    private function _attempt_validate_transfer_status($transfer)
    {
        $status = $this->payload['status'] ?? null;
        if (!in_array($status, ['approved', 'disapproved', 'received'], true)) {
            $this->error_message = 'status must be approved, disapproved, or received.';
            return false;
        }
        if (!in_array($transfer->status, ['pending', 'approved', 'opened'], true)) {
            $this->error_message = 'This transfer already has a terminal status.';
            return false;
        }
        if ($status === 'approved' && $transfer->status !== 'pending') {
            $this->error_message = 'Only pending transfers may be approved.';
            return false;
        }
        if ($status === 'disapproved') {
            foreach ($this->transfer_items as $item) {
                if ((float) $item['received_qty'] > 0) {
                    $this->error_message = 'Cannot disapprove a transfer after stock has moved.';
                    return false;
                }
            }
        }
        if ($status === 'approved' && !$this->_attempt_validate_transfer_items($this->transfer_items, $transfer->branch_id_from, $transfer->id)) {
            return false;
        }
        return true;
    }

    /**
     * Resolve receipt lines and validate cumulative quantities before any stock writes.
     */
    private function _attempt_validate_transfer_receipts($transfer)
    {
        $transfer_item_ids = $this->payload['transfer_item_ids'] ?? null;
        $item_ids = $this->payload['item_ids'] ?? null;
        $received_qtys = $this->payload['received_qtys'] ?? null;
        $use_transfer_item_ids = $transfer_item_ids !== null;
        $ids = $use_transfer_item_ids ? $transfer_item_ids : $item_ids;
        if (!is_array($ids) || !$ids || !is_array($received_qtys) || count($ids) !== count($received_qtys)
            || ($use_transfer_item_ids && !empty($item_ids))) {
            $this->error_message = 'Pass transfer_item_ids (or unique item_ids) and matching received_qtys arrays.';
            return false;
        }
        $ids = array_values($ids);
        $received_qtys = array_values($received_qtys);
        $seen_ids = [];
        $validation_items = [];
        $this->transfer_receipts = [];
        foreach ($ids as $index => $id) {
            if (!$this->_valid_transfer_id($id) || isset($seen_ids[(int) $id])) {
                $this->error_message = 'Transfer receipt IDs must be positive and unique.';
                return false;
            }
            $seen_ids[(int) $id] = true;
            $matches = [];
            foreach ($this->transfer_items as $item) {
                if ((int) $item[$use_transfer_item_ids ? 'id' : 'item_id'] === (int) $id) {
                    $matches[] = $item;
                }
            }
            if (count($matches) !== 1) {
                $this->error_message = 'Receipt selection is missing or ambiguous. Use transfer_item_ids from get_transfer.';
                return false;
            }
            $item = $matches[0];
            $received_qty = $received_qtys[$index];
            if (!$this->_valid_transfer_qty($received_qty) || (float) $received_qty < (float) $item['received_qty']
                || (float) $received_qty > (float) $item['transfer_qty']) {
                $this->error_message = 'received_qtys['.$index.'] must not decrease or exceed its transfer quantity.';
                return false;
            }
            $item['qty_delta'] = round((float) $received_qty - (float) $item['received_qty'], 4, PHP_ROUND_HALF_UP);
            $item['received_qty'] = $received_qty;
            $this->transfer_receipts[] = $item;
            if ($item['qty_delta'] > 0) {
                $item['transfer_qty'] = $item['qty_delta'];
                $validation_items[] = $item;
            }
        }
        if (!$validation_items) {
            $this->error_message = 'No additional received quantity was supplied.';
            return false;
        }
        return $this->_attempt_validate_transfer_items($validation_items, $transfer->branch_id_from, $transfer->id);
    }

    /**
     * Get distinct item values for filter selections.
     */
    public function get_item_filters()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->resolve_branch_models(0);
        $filters = $this->item->get_item_filters();

        if ($filters === false) {
            $response_status = FAIL;
            $response = ['Failed to load item filters.'];
        } else {
            $response_status = SUCCESS;
            $response = ['data' => $filters];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get distinct receipt batches through the selected branch connection.
     */
    public function get_item_batches()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $item_id = $this->payload['item_id'] ?? null;
        $branch_id_from = $this->payload['branch_id_from'] ?? null;
        $date = $this->payload['date'] ?? null;
        $database_groups = [
            1 => 'mobile',
            2 => 'oakridge',
            3 => 'mactan',
            4 => 'consolacion',
            5 => 'warehouse',
            6 => 'pharmacy',
            7 => 'mantlewood',
        ];
        $valid_date = is_string($date) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));

        if (!$this->_valid_transfer_id($item_id)) {
            $response_status = FAIL;
            $response = ['item_id is required and must be a positive integer.'];
        } elseif (!$this->_valid_transfer_id($branch_id_from) || !isset($database_groups[(int) $branch_id_from])) {
            $response_status = FAIL;
            $response = ['branch_id_from is required and must be a branch from 1 through 7.'];
        } elseif (!$valid_date) {
            $response_status = FAIL;
            $response = ['date is required and must be a real YYYY-MM-DD date.'];
        } else {
            $db = db_connect($database_groups[(int) $branch_id_from]);
            $receipt_note_item_expiry_date = new \App\Models\Receipt_note_item_expiry_date($db);
            $batches = $receipt_note_item_expiry_date->get_item_batches($item_id, $branch_id_from, $date);

            if ($batches === false) {
                $response_status = FAIL;
                $response = ['Failed to load item batches.'];
            } elseif (!$batches) {
                $response_status = FAIL_NOT_FOUND;
                $response = ['No item batches found.'];
            } else {
                $response_status = SUCCESS;
                $response = [
                    'size' => count($batches),
                    'data' => $batches
                ];
            }
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Get receipt batch starting quantities and actual received transfer movements.
     */
    public function transfer_report()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $item_id = $this->payload['item_id'] ?? null;
        $batch_value = $this->payload['batch_no'] ?? null;
        $brand = $this->payload['brand'] ?? null;
        $generic = $this->payload['generic'] ?? null;
        $dosage = $this->payload['dosage'] ?? null;
        $form = $this->payload['form'] ?? null;
        $kind = $this->payload['kind'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        if ($item_id !== null && $item_id !== '' && !$this->_valid_transfer_id($item_id)) {
            $response_status = FAIL;
            $response = ['item_id must be a positive integer when supplied.'];
        } elseif (!$this->_normalize_transfer_batch_no($batch_value, $batch_no)) {
            $response_status = FAIL;
            $response = ['batch_no must be a batch code of up to 255 characters or null.'];
        } elseif (!$this->_attempt_validate_transfer_report_filters()) {
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (($page !== null && $page !== '' && !$this->_valid_transfer_id($page))
            || ($data_size !== null && $data_size !== '' && !$this->_valid_transfer_id($data_size))) {
            $response_status = FAIL;
            $response = ['page and data_size must be positive integers.'];
        } else {
            $this->_load_transfer_models();
            $rows = $this->branch_item_transfer_item->get_transfer_report($item_id, $batch_no, $page, $data_size, $brand, $generic, $dosage, $form, $kind, $term);
            $size = $this->branch_item_transfer_item->get_transfer_report_count($item_id, $batch_no, $brand, $generic, $dosage, $form, $kind, $term);

            if ($rows === false || $size === false) {
                $response_status = FAIL;
                $response = ['Failed to load item transfer movements.'];
            } elseif (!$rows) {
                $response_status = FAIL_NOT_FOUND;
                $response = ['No item transfer movements found.'];
            } else {
                $response_status = SUCCESS;
                $response = [
                    'size' => $size,
                    'data' => $rows
                ];
            }
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Validate optional report text filters before model queries.
     */
    private function _attempt_validate_transfer_report_filters()
    {
        foreach (['brand', 'generic', 'dosage', 'form', 'kind', 'term'] as $field) {
            $value = $this->payload[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                $this->error_message = $field.' must be a string or null.';
                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to transfer item
     */
    private function _attempt_transfer()
    {
        $values = [
            'branch_id_from'        => $this->payload['branch_id_from'],
            'branch_id_to'          => $this->payload['branch_id_to'],
            'department_id_from'    => $this->payload['department_id_from'] ?? null,
            'department_id_to'      => $this->payload['department_id_to'] ?? null,
            'transfer_date'         => $this->payload['transfer_date'] ?? null,
            'delivery_date'         => $this->payload['delivery_date'] ?? null,
            'dispatcher'            => $this->payload['dispatcher'] ?? null,
            'remarks'               => $this->payload['remarks'] ?? null,
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        if (!$branch_item_transfer_id = $this->branch_item_transfer->insert($values)) {
            $this->error_message = $this->branch_item_transfer->error();
            return false;
        }

        return $branch_item_transfer_id;
    }

    /**
     * Attempt to transfer branch_item_transfer_item
     */
    private function _attempt_transfer_items($branch_item_transfer_id)
    {
        if (!array_key_exists('items', $this->payload) || !is_array($this->payload['items']) || count($this->payload['items']) == 0) {
            return false;
        }

        foreach ($this->payload['items'] as $item) {
            $values = [
                'branch_item_transfer_id'    => $branch_item_transfer_id,
                'branch_item_id'   => $item['branch_item_id'],
                'item_id'       => $item['item_id'],
                'batch_no' => $item['batch_no'] ?? null,
                'receipt_note_item_expiry_date_id' => $item['receipt_note_item_expiry_date_id'] ?? null,
                'transfer_qty'  => $item['transfer_qty'],
                'unit'  => $item['unit'],
                'added_by'      => $this->payload['requester'],
                'added_on'      => date('Y-m-d H:i:s'),
            ];

            if (!$this->branch_item_transfer_item->insert($values)) {
                $this->error_message = $this->branch_item_transfer_item->error();
                return false;
            }
        }

        return true;
    }

     /**
     * Transfer branch item change status
     */
    public function transfer_change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->_load_transfer_models();

        $transfer_id = $this->payload['transfer_id'] ?? null;
        $item_ids = $this->payload['item_ids'] ?? null;
        $received_qtys = $this->payload['received_qtys'] ?? null;

        if (!$this->db->transBegin()) {
            return $this->_create_response(FAIL, ['Failed to begin transfer transaction.']);
        }

        if (!$this->_valid_transfer_id($transfer_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = ['transfer_id is required and must be a positive integer.'];
        } elseif (!$transfer = $this->branch_item_transfer->select('', ['id' => $transfer_id, 'is_deleted' => 0], 1)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Branch item transfer ID '.$transfer_id.' is not found.',
            ];
        } elseif (!$this->_attempt_lock_transfer_items($transfer_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (!$transfer = $this->branch_item_transfer->get_for_update($transfer_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = ['Failed to read current transfer.'];
        } elseif (!$this->_attempt_validate_transfer_status($transfer)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif ($transfer->status == ($this->payload['status'] ?? null)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Branch item transfer ID '.$transfer_id.' status is already '.$this->payload['status'].'.',
            ];
        } elseif ($this->payload['status'] == 'received' && !$this->_attempt_validate_transfer_receipts($transfer)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [$this->error_message];
        } elseif (!$status = $this->_attempt_transfer_branch_item($transfer_id, $item_ids, $received_qtys)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to transfer branch item. Review cost mirrors before retrying.',
            ];
            if ($this->error_message) {
                $response[] = $this->error_message;
            }
        } elseif (!$this->_attempt_transfer_change_status($transfer_id, $status)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change branch item transfer status. Review cost mirrors before retrying.',
            ];
        } elseif (!$this->db->transStatus() || !$this->db->transCommit()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = ['Failed to commit transfer status. Review cost mirrors before retrying.'];
        } else {
            $response_status = SUCCESS;
            $response = [
                'transfer_id' => $transfer_id,
                'message'    => 'Branch item transfer status changed successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Deduct source batch balances in the same transaction as received quantities.
     */
    protected function _attempt_transfer_batch_balances($transfer)
    {
        $projected_receipts = array_column($this->transfer_receipts, 'received_qty', 'id');
        $receipts = $this->transfer_receipts;
        // Apply explicit allocations first, then protect their unreceived reservations from pooled lines.
        usort($receipts, static function ($first, $second) {
            return (empty($first['receipt_note_item_expiry_date_id']) <=> empty($second['receipt_note_item_expiry_date_id']))
                ?: ($first['id'] <=> $second['id']);
        });
        foreach ($receipts as $receipt) {
            $batch_no = $receipt['batch_no'] === null ? null : trim((string) $receipt['batch_no'], ' ');
            if ($receipt['qty_delta'] <= 0 || $batch_no === null || $batch_no === '') {
                continue;
            }
            if (!$this->branch_item_transfer_item->deduct_batch_balance(
                $receipt['item_id'], $transfer->branch_id_from, $batch_no,
                $receipt['unit'], $receipt['qty_delta'], $this->payload['requester'],
                $receipt['receipt_note_item_expiry_date_id'] ?? null, $transfer->id, $projected_receipts
            )) {
                $this->error_message = $this->branch_item_transfer_item->get_quantity_error();
                return false;
            }
        }
        return true;
    }

    /**
     * Apply a converted quantity delta only to the selected branch's inventory.
     */
    protected function _attempt_transfer_inventory_update($transfer, $transfer_item, $branch_id, $qty_delta)
    {
        $stock = $this->branch_item_transfer_item->get_source_stock($transfer_item->item_id, $branch_id);
        if (!$stock) {
            $this->error_message = 'Failed to read branch-local transfer inventory.';
            return false;
        }

        $unit_qty = null;
        $seen_units = [];
        foreach ($stock as $row) {
            if (isset($seen_units[$row['unit']]) || (float) $row['conversion_qty'] <= 0) {
                $this->error_message = 'Transfer inventory has an ambiguous or invalid item conversion.';
                return false;
            }
            $seen_units[$row['unit']] = true;
            if ((int) $row['unit'] === (int) $transfer_item->unit) {
                $unit_qty = (float) $row['conversion_qty'];
            }
        }
        if ($unit_qty === null) {
            $this->error_message = 'The transfer unit is unavailable in the selected branch.';
            return false;
        }

        $is_source = (int) $branch_id === (int) $transfer->branch_id_from;
        foreach ($stock as $row) {
            if (!$row['id']) {
                if ($is_source) {
                    $this->error_message = 'Source inventory is unavailable.';
                    return false;
                }
                $row['id'] = $this->branch_item_transfer_item->insert_branch_stock($branch_id, [
                    'item_id' => $transfer_item->item_id,
                    'beggining_qty' => 0,
                    'current_qty' => 0,
                    'unit' => $row['unit'],
                    'added_by' => $this->payload['requester'],
                    'added_on' => date('Y-m-d H:i:s'),
                ]);
                if (!$row['id']) {
                    $this->error_message = $this->branch_item_transfer_item->get_quantity_error();
                    return false;
                }
                $row['current_qty'] = 0;
            }

            $new_qty = (float) $row['current_qty'] + $qty_delta * (float) $row['conversion_qty'] / $unit_qty;
            if ($new_qty < -0.0000001) {
                $this->error_message = 'Source inventory no longer has enough quantity.';
                return false;
            }
            $values = [
                'current_qty' => max(0, $new_qty),
                'latest_activity' => $is_source ? 'Transfer Out' : 'Transfer In',
                'latest_activity_id' => $transfer->id,
                'latest_department_id' => $is_source ? $transfer->department_id_from : $transfer->department_id_to,
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s'),
            ];
            if (!$this->branch_item_transfer_item->update_branch_stock($branch_id, $transfer_item->item_id, $row['id'], $values)) {
                $this->error_message = $this->branch_item_transfer_item->get_quantity_error();
                return false;
            }
        }
        return true;
    }

    /**
     * Attempt to receive branch-local stock and record the cumulative received quantity.
     */
    protected function _attempt_transfer_branch_item($transfer_id, $item_ids, $received_qtys)
    {
        if ($this->payload['status'] != 'received') {
            return $this->payload['status'];
        }

        $transfer = $this->branch_item_transfer->get_for_update($transfer_id);
        if (!$transfer) {
            return false;
        }

        if (!$this->_attempt_transfer_batch_balances($transfer)) {
            return false;
        }

        foreach ($this->transfer_receipts as $receipt) {
            $transfer_item = (object) $receipt;
            $received_qty = $receipt['qty_delta'];
            if ($received_qty <= 0) {
                continue;
            }

            if (!$this->_attempt_transfer_inventory_update($transfer, $transfer_item, $transfer->branch_id_from, -$received_qty)) {
                return false;
            }
            if (!$this->_attempt_transfer_inventory_update($transfer, $transfer_item, $transfer->branch_id_to, $received_qty)) {
                return false;
            }

            $this->resolve_branch_models(0);
            $purchase_qty = $this->_qty_to_purchase_uom(
                (int)$transfer_item->item_id,
                $transfer_item->unit,
                (float)$received_qty
            );
            $source_trail = $this->_latest_item_cost_row(
                (int)$transfer_item->item_id,
                (int)$transfer->branch_id_from,
                true
            );
            $source_wac = (float)$source_trail['current_amount'];

            if (!$this->_apply_qty_delta_weighted_cost(
                (int)$transfer_item->item_id,
                (int)$transfer->branch_id_from,
                -$purchase_qty,
                null,
                true
            )) {
                return false;
            }

            if (!$this->_apply_qty_delta_weighted_cost(
                (int)$transfer_item->item_id,
                (int)$transfer->branch_id_to,
                $purchase_qty,
                $source_wac,
                true
            )) {
                return false;
            }

            // Save the cumulative received quantity after applying this receipt's delta.
            if (!$this->branch_item_transfer_item->update(['id' => $transfer_item->id], [
                'received_qty' => $receipt['received_qty'],
                'updated_by' => $this->payload['requester'],
                'updated_on' => date('Y-m-d H:i:s')
            ])) {
                $this->error_message = 'Failed to save received transfer quantity.';
                return false;
            }
        }

        $items = $this->branch_item_transfer_item->get_details($transfer_id, null, null, null, null, null, null, true);
        if (!$items) {
            return false;
        }
        foreach ($items as $item) {
            if ((float) $item['received_qty'] + 0.0000001 < (float) $item['transfer_qty']) {
                return 'opened';
            }
        }
        return 'received';
    }

    /**
     * Attempt transfer change status
     */
    protected function _attempt_transfer_change_status($transfer_id, $status)
    {
        $transfer = $this->branch_item_transfer->get_for_update($transfer_id);
        if (!$transfer) {
            return false;
        }
        
        $values = [
            'status'            => $status,
            'approved_by'      => $status == 'approved' ? $this->payload['requester'] : ($transfer->approved_by ?? null),
            'approved_on'      => $status == 'approved' ? date('Y-m-d H:i:s') : ($transfer->approved_on ?? null),
            'disapproved_by'   => $status == 'disapproved' ? $this->payload['requester'] : ($transfer->disapproved_by ?? null),
            'disapproved_on'   => $status == 'disapproved' ? date('Y-m-d H:i:s') : ($transfer->disapproved_on ?? null),
            'opened_by'      => $status == 'opened' ? $this->payload['requester'] : ($transfer->opened_by ?? null),
            'opened_on'      => $status == 'opened' ? date('Y-m-d H:i:s') : ($transfer->opened_on ?? null),
            'received_by'      => $status == 'received' ? $this->payload['requester'] : ($transfer->received_by ?? null),
            'received_on'      => $status == 'received' ? date('Y-m-d H:i:s') : ($transfer->received_on ?? null),
            'updated_by'        => $this->payload['requester'],
            'updated_on'        => date('Y-m-d H:i:s')
        ];

        $where = ['id' => $transfer->id];

        if (!$this->branch_item_transfer->update($where, $values)) {
            $this->error_message = $this->branch_item_transfer->error();
            return false;
        }

        return true;
    }

    /**
     * Change status item
     */
    public function change_status()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $status = $this->payload['status'] ?? null;
        $item_id = $this->payload['item_id'];
        $where = [
                    'id' => $item_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$item = $this->item->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'item is not found.'
            ];
        } elseif ($status && $item->status == $status) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Item is already $status. ",
            ];
        } elseif (!$this->_attempt_update_status($item)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change item status.',
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'item_id' => $item_id,
                'message'    => "item $status successfully."
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update status
     */
    protected function _attempt_update_status($item)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        $status = $this->payload['status'];
        if ($status == 'approved') {
            $values['approved_by'] = $this->payload['requester'];
            $values['approved_on'] = date('Y-m-d H:i:s');
            $values['status'] = 'approved';
        } elseif ($status == 'disapproved') {
            $values['status'] = 'disapproved';
            $values['disapproved_reason'] = $this->payload['disapproved_reason'] ?? null;
            $values['disapproved_by'] = $this->payload['requester'];
            $values['disapproved_on'] = date('Y-m-d H:i:s');
        } else {
            return false;
        }

        $where = ['id' => $item->id];

        if (!$this->mirror_update('item', $where, $values)) {
            return false;
        }

        return true;
    }

    /**
     * Download inventory upload template (columns A–S).
     * GET items/download_inventory_template
     */
    public function download_inventory_template()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        if (!class_exists(Spreadsheet::class)) {
            return $this->_create_response(FAIL, [
                'PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet:^1.29',
            ]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ITEM FORM');

        $sheet->setCellValue('A1', 'ITEM FORM');
        $sheet->setCellValue('N1', 'CONVERSION');

        $headers = [
            'A2' => 'Item Code',
            'B2' => 'Type',
            'C2' => 'Kinds of Goods',
            'D2' => 'Name',
            'E2' => 'Brand',
            'F2' => 'For Canvassing',
            'G2' => 'Item Description',
            'H2' => 'Min.',
            'I2' => 'Max',
            'J2' => 'COST PRICE',
            'K2' => 'VAT TYPE',
            'L2' => 'VAT Account',
            'M2' => 'Account Type',
            'N2' => 'Purchase Quantity',
            'O2' => 'Unit',
            'P2' => 'Clinic Quantity',
            'Q2' => 'Unit',
            'R2' => 'Usage',
            'S2' => 'Unit',
        ];
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $excelOutput = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="inventory_list_template.xlsx"')
            ->setHeader('Cache-Control', 'max-age=0')
            ->setBody($excelOutput);
    }

    /**
     * Bulk upload inventory items from Excel template.
     * POST items/upload_inventory (multipart field: file)
     * Optional form-data: start_row (default 3), limit (default 25)
     */
    public function upload_inventory()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        @set_time_limit(120);
        @ini_set('max_execution_time', '120');

        if (!class_exists(IOFactory::class)) {
            return $this->_create_response(FAIL, [
                'PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet:^1.29',
            ]);
        }

        $file = $this->request->getFile('file');
        if (!$file || !$file->isValid()) {
            return $this->_create_response(FAIL, [
                'Valid Excel file (.xlsx) is required in field "file".',
            ]);
        }

        $ext = strtolower($file->getClientExtension());
        if (!in_array($ext, ['xlsx', 'xls'])) {
            return $this->_create_response(FAIL, [
                'Only .xlsx or .xls files are allowed.',
            ]);
        }

        try {
            $spreadsheet = IOFactory::load($file->getTempName());
            $rows = $this->_parse_inventory_workbook($spreadsheet);
        } catch (\Throwable $e) {
            return $this->_create_response(FAIL, [
                'Failed to read Excel file: ' . $e->getMessage(),
            ]);
        }

        if (empty($rows)) {
            return $this->_create_response(FAIL, [
                'No data rows found. Fill rows starting at row 3.',
            ]);
        }

        $branches = $this->branch_master->get_details() ?: [];
        if (empty($branches)) {
            return $this->_create_response(FAIL, [
                'No branches found. Configure branches before uploading inventory.',
            ]);
        }

        $startRow = (int)($this->payload['start_row'] ?? 3);
        if ($startRow < 3) {
            $startRow = 3;
        }
        $limit = (int)($this->payload['limit'] ?? 25);
        if ($limit < 1) {
            $limit = 25;
        }

        $eligible = array_values(array_filter($rows, function ($row) use ($startRow) {
            return (int)$row['row'] >= $startRow;
        }));

        $chunk = array_slice($eligible, 0, $limit);
        $remaining = array_slice($eligible, $limit);

        if (empty($chunk)) {
            return $this->_create_response(SUCCESS, [
                'total_rows'      => count($rows),
                'processed_rows'  => 0,
                'success_count'   => 0,
                'failed_count'    => 0,
                'created'         => [],
                'errors'          => [],
                'next_start_row'  => null,
                'done'            => true,
                'message'         => 'No rows at or after start_row ' . $startRow . '.',
            ]);
        }

        $chunkResult = $this->_upload_inventory_chunk($chunk, $branches);

        $nextStartRow = null;
        $done = true;
        if (!empty($remaining)) {
            $nextStartRow = (int)$remaining[0]['row'];
            $done = false;
        }

        $response = [
            'total_rows'     => count($rows),
            'processed_rows' => count($chunk),
            'success_count'  => count($chunkResult['created']),
            'failed_count'   => count($chunkResult['errors']),
            'created'        => $chunkResult['created'],
            'errors'         => $chunkResult['errors'],
            'next_start_row' => $nextStartRow,
            'done'           => $done,
        ];

        return $this->_create_response(
            empty($chunkResult['errors']) ? SUCCESS : FAIL,
            $response
        );
    }

    /**
     * Validate a chunk of inventory rows and insert via insert_batch across all DBs.
     */
    private function _upload_inventory_chunk(array $rows, array $branches): array
    {
        $created = [];
        $errors = [];
        $valid = [];

        foreach ($rows as $row) {
            $build = $this->_build_inventory_create_payload($row, $branches);
            if (!$build['success']) {
                $errors[] = [
                    'row'       => $row['row'],
                    'item_code' => $row['item_code'] ?? null,
                    'name'      => $row['name'] ?? null,
                    'errors'    => $build['errors'],
                ];
                continue;
            }

            $valid[] = [
                'row'     => $row,
                'payload' => $build['payload'],
            ];
        }

        if (empty($valid)) {
            return ['created' => $created, 'errors' => $errors];
        }

        $now = date('Y-m-d H:i:s');
        $requester = $this->payload['requester'];

        $nextItemId = $this->_get_global_max_id('item') + 1;
        $nextConvId = $this->_get_global_max_id('item_conversion') + 1;
        $nextBranchItemId = $this->_get_global_max_id('branch_item') + 1;
        $nextCostId = $this->_get_global_max_id('item_cost') + 1;

        $items = [];
        $conversions = [];
        $branchItems = [];
        $itemCosts = [];
        $createdMap = [];

        foreach ($valid as $entry) {
            $row = $entry['row'];
            $payload = $entry['payload'];
            $itemId = $nextItemId++;

            $items[] = [
                'id'                  => $itemId,
                'added_by'            => $requester,
                'added_on'            => $now,
                'type'                => $payload['type'],
                'name'                => $payload['name'],
                'brand'               => $payload['brand'],
                'is_canvassing'       => $payload['is_canvassing'],
                'description'         => $payload['description'],
                'cost'                => $payload['cost'],
                'weighted_cost'       => $payload['cost'],
                'vat_type'            => $payload['vat_type'],
                'account_type_id'     => $payload['account_type_id'],
                'vat_account_type_id' => $payload['vat_account_type_id'],
                'kind'                => $payload['kind'],
                'critical_level'      => $payload['critical_level'],
                'vaccine_type'        => $payload['vaccine_type'] !== '' ? $payload['vaccine_type'] : null,
            ];

            $convByType = [];
            foreach ($payload['conversions'] as $conversion) {
                $convId = $nextConvId++;
                $conversions[] = [
                    'id'              => $convId,
                    'item_id'         => $itemId,
                    'conversion_type' => $conversion['conversion_type'],
                    'price'           => $conversion['price'],
                    'qty'             => $conversion['qty'],
                    'unit'            => $conversion['unit'],
                    'min'             => $conversion['min'],
                    'max'             => $conversion['max'],
                    'added_by'        => $requester,
                    'added_on'        => $now,
                ];
                $convByType[$conversion['conversion_type']] = [
                    'id'   => $convId,
                    'unit' => $conversion['unit'],
                    'qty'  => $conversion['qty'],
                ];
            }

            $costBranchIds = [];
            $purchaseQtyByBranch = [];
            foreach ($payload['branches'] as $branch) {
                if (($branch['conversion_type'] ?? '') === 'purchase') {
                    $purchaseQtyByBranch[(int)$branch['branch_id']] = (float)($branch['current_qty'] ?? 0);
                }
            }

            foreach ($payload['branches'] as $branch) {
                $convType = $branch['conversion_type'];
                if (!isset($convByType[$convType])) {
                    continue;
                }

                $branchItems[] = [
                    'id'            => $nextBranchItemId++,
                    'item_id'       => $itemId,
                    'branch_id'     => $branch['branch_id'],
                    'beggining_qty' => $branch['current_qty'],
                    'current_qty'   => $branch['current_qty'],
                    'unit'          => $convByType[$convType]['id'],
                    'added_by'      => $requester,
                    'added_on'      => $now,
                ];

                $branchIdKey = (int)$branch['branch_id'];
                if (!isset($costBranchIds[$branchIdKey])) {
                    $costBranchIds[$branchIdKey] = true;
                    $qty = $purchaseQtyByBranch[$branchIdKey] ?? 0;
                    $cost = (float)$payload['cost'];
                    $itemCosts[] = [
                        'id'             => $nextCostId++,
                        'item_id'        => $itemId,
                        'branch_id'      => $branchIdKey,
                        'last_value'     => $qty * $cost,
                        'latest_total'   => $qty,
                        'current_amount' => $cost,
                        'added_by'       => $requester,
                        'added_on'       => $now,
                    ];
                }
            }

            $createdMap[] = [
                'row'     => $row['row'],
                'item_id' => $itemId,
                'name'    => $row['name'] ?? null,
                'item_code' => $row['item_code'] ?? null,
            ];
        }

        if (!$this->_mirror_insert_batch('item', $items)) {
            return $this->_chunk_batch_failure($createdMap, $errors, 'Failed to batch insert items. ' . ($this->error_message ?? ''));
        }
        if (!$this->_mirror_insert_batch('item_conversion', $conversions)) {
            return $this->_chunk_batch_failure($createdMap, $errors, 'Failed to batch insert item conversions. ' . ($this->error_message ?? ''));
        }
        if (!$this->_mirror_insert_batch('branch_item', $branchItems)) {
            return $this->_chunk_batch_failure($createdMap, $errors, 'Failed to batch insert branch items. ' . ($this->error_message ?? ''));
        }
        if (!$this->_mirror_insert_batch('item_cost', $itemCosts)) {
            return $this->_chunk_batch_failure($createdMap, $errors, 'Failed to batch insert item costs. ' . ($this->error_message ?? ''));
        }

        foreach ($createdMap as $item) {
            $created[] = [
                'row'     => $item['row'],
                'item_id' => $item['item_id'],
                'name'    => $item['name'],
            ];
        }

        return ['created' => $created, 'errors' => $errors];
    }

    /**
     * Mark all insertable rows in a chunk as failed when a batch write fails.
     */
    private function _chunk_batch_failure(array $createdMap, array $errors, string $message): array
    {
        foreach ($createdMap as $item) {
            $errors[] = [
                'row'       => $item['row'],
                'item_code' => $item['item_code'] ?? null,
                'name'      => $item['name'] ?? null,
                'errors'    => [$message],
            ];
        }

        return ['created' => [], 'errors' => $errors];
    }

    /**
     * Parse inventory workbook rows (data starts at row 3).
     */
    private function _parse_inventory_workbook($spreadsheet): array
    {
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($rowNum = 3; $rowNum <= $highestRow; $rowNum++) {
            $name = trim((string)$sheet->getCell('D' . $rowNum)->getCalculatedValue());
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'row'              => $rowNum,
                'item_code'        => trim((string)$sheet->getCell('A' . $rowNum)->getCalculatedValue()),
                'type'             => trim((string)$sheet->getCell('B' . $rowNum)->getCalculatedValue()),
                'kind'             => trim((string)$sheet->getCell('C' . $rowNum)->getCalculatedValue()),
                'name'             => $name,
                'brand'            => trim((string)$sheet->getCell('E' . $rowNum)->getCalculatedValue()),
                'is_canvassing'    => trim((string)$sheet->getCell('F' . $rowNum)->getCalculatedValue()),
                'description'      => trim((string)$sheet->getCell('G' . $rowNum)->getCalculatedValue()),
                'min'              => trim((string)$sheet->getCell('H' . $rowNum)->getCalculatedValue()),
                'max'              => trim((string)$sheet->getCell('I' . $rowNum)->getCalculatedValue()),
                'cost'             => trim((string)$sheet->getCell('J' . $rowNum)->getCalculatedValue()),
                'vat_type'         => trim((string)$sheet->getCell('K' . $rowNum)->getCalculatedValue()),
                'vat_account'      => trim((string)$sheet->getCell('L' . $rowNum)->getCalculatedValue()),
                'account_type'     => trim((string)$sheet->getCell('M' . $rowNum)->getCalculatedValue()),
                'purchase_qty'     => trim((string)$sheet->getCell('N' . $rowNum)->getCalculatedValue()),
                'purchase_unit'    => trim((string)$sheet->getCell('O' . $rowNum)->getCalculatedValue()),
                'clinic_qty'       => trim((string)$sheet->getCell('P' . $rowNum)->getCalculatedValue()),
                'clinic_unit'      => trim((string)$sheet->getCell('Q' . $rowNum)->getCalculatedValue()),
                'usage_qty'        => trim((string)$sheet->getCell('R' . $rowNum)->getCalculatedValue()),
                'usage_unit'       => trim((string)$sheet->getCell('S' . $rowNum)->getCalculatedValue()),
            ];
        }

        return $rows;
    }

    /**
     * Build items/create payload from a parsed inventory row.
     */
    private function _build_inventory_create_payload(array $row, array $branches): array
    {
        $errors = [];

        if ($row['name'] === '') {
            $errors[] = 'Name is required.';
        }

        $vatAccountId = $this->_resolve_account_type_id_by_name($row['vat_account']);
        if ($row['vat_account'] === '') {
            $errors[] = 'VAT Account is required.';
        } elseif (!$vatAccountId) {
            $errors[] = 'VAT Account not found: ' . $row['vat_account'];
        }

        $accountTypeId = $this->_resolve_account_type_id_by_name($row['account_type']);
        if ($row['account_type'] === '') {
            $errors[] = 'Account Type is required.';
        } elseif (!$accountTypeId) {
            $errors[] = 'Account Type not found: ' . $row['account_type'];
        }

        $kinds = [];
        if ($row['kind'] !== '') {
            foreach (preg_split('/\s*,\s*/', $row['kind']) as $kindLabel) {
                if ($kindLabel !== '') {
                    $kinds[] = $this->_label_to_snake($kindLabel);
                }
            }
        }

        if (empty($kinds)) {
            $errors[] = 'Kinds of Goods is required.';
        }

        $min = $row['min'] !== '' ? $row['min'] : 0;
        $max = $row['max'] !== '' ? $row['max'] : 0;

        $conversionDefs = [
            ['type' => 'purchase', 'qty' => $row['purchase_qty'], 'unit' => $row['purchase_unit']],
            ['type' => 'clinic', 'qty' => $row['clinic_qty'], 'unit' => $row['clinic_unit']],
            ['type' => 'usage', 'qty' => $row['usage_qty'], 'unit' => $row['usage_unit']],
        ];

        $conversions = [];
        foreach ($conversionDefs as $def) {
            if ($def['qty'] === '') {
                $def['qty'] = 1;
            }
            if ($def['unit'] === '') {
                $def['unit'] = $def['type'];
            }

            $conversions[] = [
                'conversion_type' => $def['type'],
                'description'     => self::INVENTORY_CONVERSION_DESCRIPTIONS[$def['type']],
                'unit'            => $def['unit'],
                'qty'             => $def['qty'],
                'min'             => $min,
                'max'             => $max,
                'price'           => 0,
                'id'              => '',
            ];
        }

        $branchPayload = [];
        foreach ($branches as $branch) {
            $branchId = (int)$branch['id'];
            foreach ($conversions as $conversion) {
                $branchPayload[] = [
                    'id'              => '',
                    'branch_id'       => $branchId,
                    'current_qty'     => $conversion['qty'],
                    'unit'            => $conversion['unit'],
                    'conversion_type' => $conversion['conversion_type'],
                ];
            }
        }

        $isCanvassing = strtolower($row['is_canvassing']) === 'yes' ? 1 : 0;

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        return [
            'success' => true,
            'payload' => [
                'type'                 => $this->_label_to_snake($row['type']),
                'name'                 => $row['name'],
                'brand'                => $row['brand'] !== '' ? $row['brand'] : '-',
                'is_canvassing'        => $isCanvassing,
                'description'          => $row['description'] !== '' ? $row['description'] : null,
                'cost'                 => $row['cost'],
                'vat_type'             => $this->_label_to_snake($row['vat_type']),
                'account_type_id'      => $accountTypeId,
                'vat_account_type_id'  => $vatAccountId,
                'kind'                 => implode(',', $kinds),
                'critical_level'       => 0,
                'vaccine_type'         => '',
                'item_id'              => '',
                'conversions'          => $conversions,
                'branches'             => $branchPayload,
            ],
        ];
    }

    /**
     * Resolve account_type.id by exact name on master DB.
     */
    private function _resolve_account_type_id_by_name(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $record = $this->account_type->select('id', [
            'name'       => $name,
            'is_deleted' => 0,
        ], 1);

        return $record ? (int)$record->id : null;
    }

    /**
    * Check duplicate
    */
    protected function _has_duplicate($table, $where, $id = null)
    {
        $id?($where['id <>'] = $id):null;

        return ($this->$table->select('', $where, 1) ? true : false);
    }
}
