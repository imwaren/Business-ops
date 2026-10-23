<?php
/** Isolated controller/service fixtures: all models and clinic reads are mocked. */
namespace App\Core {
    class MYT_Controller { protected $payload = []; }
}
namespace App\Models {
    class Billing_payment
    {
        public array $calls = [];
        private function _rows($id, $mop, $date, $charge_to, $unidentified, $branch_id, $date_from, $date_to): array
        {
            $rows = array_values(array_filter(\CollectionFixtureData::$payments, static function ($row) use ($id, $mop, $date, $charge_to, $unidentified, $branch_id, $date_from, $date_to) {
                if ($row['is_deleted'] || ($id && $row['id'] !== (int) $id) || ($mop && $row['mop'] !== $mop)
                    || ($date && $row['date'] !== $date) || ($charge_to && $row['charge_to'] !== (int) $charge_to)
                    || ($unidentified !== null && $row['is_unidentified'] !== (int) $unidentified)
                    || ($date_from && substr($row['paid_date'], 0, 10) < $date_from)
                    || ($date_to && substr($row['paid_date'], 0, 10) > $date_to)) { return false; }
                if ($branch_id) {
                    foreach (\CollectionFixtureData::$payment_entries as $entry) {
                        $billing_entry = \CollectionFixtureData::$billing_entries[$entry['billing_entry_id']] ?? null;
                        if ($entry['billing_payment_id'] === $row['id'] && !$entry['is_deleted'] && $billing_entry && !$billing_entry['is_deleted']
                            && !$billing_entry['parent_deleted'] && $billing_entry['branch_id'] === (int) $branch_id) { return true; }
                    }
                    return false;
                }
                return true;
            }));
            usort($rows, static fn($a, $b) => [$b['paid_date'], $b['id']] <=> [$a['paid_date'], $a['id']]);
            return $rows;
        }
        public function get_details($id = null, $mop = null, $date = null, $charge_to = null, $page = null, $data_size = null, $unidentified = null, $branch_id = null, $date_from = null, $date_to = null): array
        {
            $this->calls[] = ['rows', func_get_args()];
            $rows = $this->_rows($id, $mop, $date, $charge_to, $unidentified, $branch_id, $date_from, $date_to);
            return array_slice($rows, (($page ?: 1) - 1) * ($data_size ?: 20), $data_size ?: 20);
        }
        public function count_details($id = null, $mop = null, $date = null, $charge_to = null, $unidentified = null, $branch_id = null, $date_from = null, $date_to = null): int
        {
            $this->calls[] = ['count', func_get_args()];
            return count($this->_rows($id, $mop, $date, $charge_to, $unidentified, $branch_id, $date_from, $date_to));
        }
    }
    class Billing_payment_entry
    {
        public array $calls = [];
        public function get_details($id = null, $payment_ids = null, $page = null, $data_size = null): array
        {
            $this->calls[] = func_get_args();
            $rows = [];
            foreach (\CollectionFixtureData::$payment_entries as $entry) {
                $payment = \CollectionFixtureData::$payments[$entry['billing_payment_id']] ?? null;
                if (!$entry['is_deleted'] && $payment && !$payment['is_deleted'] && in_array($entry['billing_payment_id'], $payment_ids, true)) {
                    unset($entry['is_deleted']);
                    $rows[] = $entry;
                }
            }
            return $rows;
        }
    }
    class Billing_entry
    {
        public array $calls = [];
        public function get_details($ids = null, $billing_id = null, $company_id = null, $charge_to = null, $screen = null, $with_invoice = null, $page = null, $data_size = null, $sort_by_patient_name = false, $include_invoice_no = false): array
        {
            $this->calls[] = ['ids' => $ids, 'invoice' => $include_invoice_no, 'page' => $page, 'data_size' => $data_size];
            $rows = [];
            foreach (\CollectionFixtureData::$billing_entries as $id => $entry) {
                if (in_array($id, $ids, true) && !$entry['is_deleted'] && !$entry['parent_deleted']) {
                    unset($entry['is_deleted'], $entry['parent_deleted']);
                    $rows[] = $entry;
                }
            }
            return $rows;
        }
    }
    class Branch_master
    {
        public int $calls = 0;
        public function select(...$args): array
        {
            $this->calls++;
            return array_map(static fn($id, $name) => (object) ['id' => $id, 'name' => $name], array_keys(\CollectionFixtureData::$branch_names), \CollectionFixtureData::$branch_names);
        }
    }
    class Booking
    {
        public function __construct(public $db) {}
        public function select(...$args) { return null; }
    }
    class Booking_detail
    {
        public function __construct(private $db) {}
        public function get_details($booking_id, $charge_to): array
        {
            \CollectionFixtureData::$booking_detail_calls[] = [$this->db->branch_id, $booking_id, $charge_to];
            return $booking_id === 77 ? [['id' => $this->db->branch_id * 100, 'type' => 'lab', 'type_name' => 'Fixture lab', 'charge_to' => $charge_to]] : [];
        }
    }
    class Booking_medicine
    {
        public function __construct($db) {}
        public function get_details(...$args): array { return [['vat_ex' => '10.00', 'vat_amount' => '1.20']]; }
    }
    class Payment
    {
        public function __construct($db) {}
        public function get_details(...$args): array { return [['withholding_tax' => 2,'withholding_percent' => 2,'discount' => 1,'subtotal' => 100,'grand_total' => 99]]; }
    }
    class Payment_multiple_method { public function __construct($db) {} }
    class Billing_payment_deposit
    {
        public function get_details(...$args): array { return [['id' => 1,'billing_payment_id' => 501,'amount' => 25]]; }
        public function get_deposited_totals(...$args): array { return [501 => 25.0]; }
    }
    class Yakap_billing
    {
        public static int $instances = 0;
        public function __construct() { self::$instances++; }
        public function is_tranche_entry($entry): bool { return !empty($entry['yakap_tranche']); }
        public static function tranche_from_entry($entry): ?int { return !empty($entry['yakap_tranche']) ? (int) $entry['yakap_tranche'] : null; }
        public function stored_patient_ids($entry) { return $entry['yakap_patient_ids'] ?? null; }
        public function stored_patient_name($entry) { return $entry['yakap_patient_names'] ?? null; }
        public function get_fpe_patient_name($branch_id, $patient_id): string { return "FPE fixture {$branch_id}/{$patient_id}"; }
        public function is_fpe_entry($entry, $charge_to = null): bool { return !empty($entry['fpe_fixture']); }
        public static function booking_details_for_tranche($tranche): array { return [['id' => null,'type_name' => "Tranche {$tranche}"]]; }
        public static function tranche_1_booking_details(): array { return self::booking_details_for_tranche(1); }
    }
}
namespace {
    chdir(dirname(__DIR__));
    define('SUCCESS', 200);
    define('FAIL', 400);
    define('FAIL_NOT_FOUND', 404);
    define('ENCRYPTION_KEY', 'synthetic_fixture_key');
    $checks = 0;
    function check($condition, string $label): void
    {
        global $checks;
        if (!$condition) { throw new \RuntimeException($label); }
        $checks++;
    }
    set_exception_handler(static function (\Throwable $error): void {
        fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
        exit(1);
    });
    set_error_handler(static function ($severity, $message): void { throw new \RuntimeException($message); });
    require 'app/Libraries/Billing_collection_entries.php';
    require 'app/Controllers/Billings.php';
    require 'app/Controllers/Reports.php';
    preg_match('/function list_page_params\(.*?\n\}/s', file_get_contents('app/Common.php'), $pagination_match);
    eval($pagination_match[0]);
    // Compare against the exact tracked pre-refactor controller, not a second copy of new formatting.
    exec('git show HEAD:app/Controllers/Billings.php', $baseline_lines, $baseline_status);
    check($baseline_status === 0, 'Read tracked Billings baseline');
    $baseline_source = implode("\n", $baseline_lines);
    $baseline_source = str_replace(['namespace App\\Controllers;', 'class Billings extends'], ['namespace CollectionBaseline;', 'class Baseline_billings extends'], $baseline_source);
    eval(substr($baseline_source, 5));

    class CollectionFixtureData
    {
        public static array $payments = [];
        public static array $payment_entries = [];
        public static array $billing_entries = [];
        public static array $branch_names = [1 => 'Mobile',2 => 'Oakridge',3 => 'Mactan',4 => 'Consolacion',7 => 'Mantlewood'];
        public static array $connections = [];
        public static array $booking_detail_calls = [];
    }
    class CollectionQueryFixture
    {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResult(): array { return array_map(static fn($row) => (object) $row, $this->rows); }
    }
    class CollectionClinicFixture
    {
        public array $calls = [];
        public function __construct(public int $branch_id) {}
        public function query($sql, $binds)
        {
            $this->calls[] = [$sql, count($binds), end($binds)];
            return new CollectionQueryFixture(end($binds) === 77 ? [[
                'patient_id' => $this->branch_id * 100 + 1,
                'patient_name' => 'Synthetic patient branch ' . $this->branch_id,
                'transaction_no' => 'TX-' . $this->branch_id . '-77',
            ]] : []);
        }
    }
    function db_connect($connection)
    {
        $branch_id = ['mobile' => 1,'oakridge' => 2,'mactan' => 3,'consolacion' => 4,'mantlewood' => 7][$connection];
        CollectionFixtureData::$connections[] = $branch_id;
        return new CollectionClinicFixture($branch_id);
    }
    function config($name)
    {
        return new class {
            public function getClinicDB($branch_id) { return [1 => 'mobile',2 => 'oakridge',3 => 'mactan',4 => 'consolacion',7 => 'mantlewood'][$branch_id] ?? null; }
        };
    }
    class CollectionCompanyFixture
    {
        public $db;
        public function __construct()
        {
            $this->db = new class {
                public function query($sql, $binds) { return new CollectionQueryFixture([['id' => 2048,'name' => 'Example Company','address' => 'Example address','tin_no' => '000000000']]); }
            };
        }
    }
    trait CollectionControllerFixture
    {
        public $fixture_payment;
        public $fixture_payment_entry;
        public $fixture_billing_entry;
        public $fixture_branch;
        public function __construct(array $payload)
        {
            $this->payload = $payload;
            $this->billing_payment = $this->fixture_payment = new \App\Models\Billing_payment();
            $this->billing_payment_entry = $this->fixture_payment_entry = new \App\Models\Billing_payment_entry();
            $this->billing_entry = $this->fixture_billing_entry = new \App\Models\Billing_entry();
            $this->branch_master = $this->fixture_branch = new \App\Models\Branch_master();
            $this->company = new CollectionCompanyFixture();
            if (property_exists($this, 'yakap_billing')) {
                $this->yakap_billing = new \App\Models\Yakap_billing();
                $this->billing_payment_deposit = new \App\Models\Billing_payment_deposit();
            }
        }
        protected function _api_validation() { return true; }
        protected function _create_response($status, $data_response, $skip_response_logging = false) { return ['status' => $status, 'data' => $data_response]; }
        public function decorate_for_fixture($entry, $extras): array { return $this->_decorate_billing_entry($entry, $entry['charge_to'] ?? null, $extras); }
    }
    class ReportCollectionFixture extends \App\Controllers\Reports { use CollectionControllerFixture; }
    class BillingCollectionFixture extends \App\Controllers\Billings { use CollectionControllerFixture; }
    class BaselineCollectionFixture extends \CollectionBaseline\Baseline_billings { use CollectionControllerFixture; }

    foreach ([501,502,503,504,505,506] as $id) {
        CollectionFixtureData::$payments[$id] = ['id' => $id,'charge_to' => 2048,'mop' => 'cash','date' => '2026-10-12','paid_date' => '2026-10-13 23:59:59','grand_total' => '900.00','is_unidentified' => $id === 503 ? 1 : 0,'is_deleted' => $id === 504 ? 1 : 0];
    }
    $base_entry = ['company_id' => 2048,'charge_to' => 2048,'transaction_id' => 100,'amount' => '100.00','paid_amount' => '50.00','balance' => '50.00','due_date' => '2026-11-01','floating_patient_no' => null,'master_patient_id' => null,'yakap_tranche' => null,'remarks' => null,'is_deleted' => 0,'parent_deleted' => 0];
    foreach ([100 => [1,77,'INV-A'],101 => [2,77,'INV-B'],102 => [99,77,null],103 => [4,0,'INV-C'],104 => [7,0,'INV-D'],105 => [3,0,'INV-E'],106 => [1,999,'INV-F'],107 => [1,77,'INV-G'],108 => [2,77,'INV-H'],109 => [2,77,null],110 => [1,0,'INV-I']] as $id => [$branch_id, $booking_id, $invoice]) {
        CollectionFixtureData::$billing_entries[$id] = ['id' => $id,'billing_id' => $id + 1000,'branch_id' => $branch_id,'booking_id' => $booking_id,'invoice_no' => $invoice] + $base_entry;
    }
    CollectionFixtureData::$billing_entries[102]['remarks'] = 'Unmatched fixture | other detail';
    CollectionFixtureData::$billing_entries[103]['floating_patient_no'] = 7;
    CollectionFixtureData::$billing_entries[104]['yakap_tranche'] = 2;
    CollectionFixtureData::$billing_entries[104]['yakap_patient_ids'] = '1,2';
    CollectionFixtureData::$billing_entries[104]['yakap_patient_names'] = 'Synthetic YAKAP snapshot';
    CollectionFixtureData::$billing_entries[105]['fpe_fixture'] = true;
    CollectionFixtureData::$billing_entries[105]['master_patient_id'] = 600;
    CollectionFixtureData::$billing_entries[107]['is_deleted'] = 1;
    CollectionFixtureData::$billing_entries[108]['parent_deleted'] = 1;
    foreach ([100,101,102,103,104,105,106,107,108,999,100] as $index => $id) {
        CollectionFixtureData::$payment_entries[] = ['id' => $index + 1,'billing_payment_id' => 501,'billing_entry_id' => $id,'amount' => '100.00','is_deleted' => $index === 10 ? 1 : 0];
    }
    CollectionFixtureData::$payment_entries[] = ['id' => 12,'billing_payment_id' => 502,'billing_entry_id' => 100,'amount' => '50.00','is_deleted' => 0];
    CollectionFixtureData::$payment_entries[] = ['id' => 13,'billing_payment_id' => 505,'billing_entry_id' => 109,'amount' => '25.00','is_deleted' => 0];
    CollectionFixtureData::$payment_entries[] = ['id' => 14,'billing_payment_id' => 506,'billing_entry_id' => 100,'amount' => '10.00','is_deleted' => 1];
    $dates = ['date_from' => '2026-10-01','date_to' => '2026-10-13'];

    foreach ([501,502,503,505,506] as $id) {
        $report = new ReportCollectionFixture($dates + ['billing_payment_id' => (string) $id]);
        $report_result = $report->get_collections();
        $billing = new BillingCollectionFixture(['billing_payment_id' => $id]);
        $billing_result = $billing->get_collection();
        $baseline_result = (new BaselineCollectionFixture(['billing_payment_id' => $id]))->get_collection();
        check($billing_result === $baseline_result, 'Billings complete response identical to tracked baseline');
        check($report_result['status'] === SUCCESS && $report_result['data']['size'] === 1, 'ID report keeps collection wrapper');
        check($report_result['data']['data'][0]['entries'] === $billing_result['data']['data'][0]['entries'], 'Both endpoints have exactly matching entry structures');
        check(!isset($report_result['data']['data'][0]['deposits'], $report_result['data']['data'][0]['sub_total']), 'Reports adds no deposit/subtotal fields');
        check(count($report->fixture_payment_entry->calls) === 1 && $report->fixture_payment_entry->calls[0] === [null,[$id]], 'One unpaginated payment-entry batch');
        check($report->fixture_payment->calls[0][1][0] === $id && $report->fixture_payment->calls[1][1][0] === $id, 'ID forwarded to rows and count');
    }
    $result = (new ReportCollectionFixture($dates + ['billing_payment_id' => 501]))->get_collections();
    $entries = $result['data']['data'][0]['entries'];
    check(count($entries) === 10, 'Active entries preserved, including missing/deleted billing links');
    check($entries[0]['billing_entry_details'][0]['patient_name'] === 'Synthetic patient branch 1' && $entries[1]['billing_entry_details'][0]['patient_name'] === 'Synthetic patient branch 2', 'Same booking ID resolves by source branch');
    check($entries[0]['billing_entry_details'][0]['transaction_no'] === 'TX-1-77' && $entries[1]['billing_entry_details'][0]['transaction_no'] === 'TX-2-77', 'Transaction enrichment stays branch-local');
    check($entries[0]['billing_entry_invoice_no'] === 'INV-A' && $entries[1]['billing_entry_invoice_no'] === 'INV-B', 'Per-entry invoices from distinct billings');
    check($entries[0]['billing_entry_branch_name'] === 'Mobile' && $entries[1]['billing_entry_branch_name'] === 'Oakridge', 'Per-entry branch names');
    check($entries[2]['billing_entry_branch_name'] === null && $entries[2]['billing_entry_invoice_no'] === null && $entries[2]['billing_entry_details'][0]['patient_name'] === 'Unmatched fixture', 'Unknown branch/null invoice retain details without fallback branch');
    check($entries[3]['billing_entry_details'][0]['patient_name'] === 'Floating Patient 7', 'Floating patient enrichment retained');
    check($entries[4]['billing_entry_details'][0]['patient_name'] === 'Synthetic YAKAP snapshot' && $entries[4]['billing_entry_details'][0]['transaction_no'] === '02', 'YAKAP saved snapshot retained');
    check($entries[5]['billing_entry_details'][0]['patient_name'] === 'FPE fixture 3/600', 'Legacy FPE enrichment retained');
    check($entries[6]['billing_entry_details'][0]['patient_name'] === null, 'Missing booking retains entry');
    foreach ([7,8,9] as $index) {
        check($entries[$index]['billing_entry_details'] === [] && $entries[$index]['billing_entry_invoice_no'] === null && $entries[$index]['billing_entry_branch_name'] === null, 'Missing/deleted linked billing has null metadata and empty details');
    }
    foreach ([503,506] as $id) {
        check((new ReportCollectionFixture($dates + ['billing_payment_id' => $id]))->get_collections()['data']['data'][0]['entries'] === [], 'Unidentified/no active entries returns empty array');
    }
    $null_invoice = (new ReportCollectionFixture($dates + ['billing_payment_id' => 505]))->get_collections()['data']['data'][0]['entries'][0];
    check($null_invoice['billing_entry_invoice_no'] === null && $null_invoice['billing_entry_branch_name'] === 'Oakridge', 'Null invoice does not remove known branch');
    foreach ([false,true] as $extras) {
        foreach ([100,101,102,103,104,105,106,110] as $id) {
            $entry = CollectionFixtureData::$billing_entries[$id];
            unset($entry['is_deleted'], $entry['parent_deleted']);
            check((new BillingCollectionFixture([]))->decorate_for_fixture($entry, $extras) === (new BaselineCollectionFixture([]))->decorate_for_fixture($entry, $extras), 'Shared helper preserves false and full-extras decoration');
        }
    }
    $billing_list = (new BillingCollectionFixture([]))->get_collection();
    check($billing_list === (new BaselineCollectionFixture([]))->get_collection(), 'Unfiltered Billings collections unchanged');
    foreach ([[], ['billing_payment_id' => ''], ['billing_payment_id' => '   '], ['billing_payment_id' => null]] as $payload) {
        $report = new ReportCollectionFixture($dates + $payload);
        $before_yakap = \App\Models\Yakap_billing::$instances;
        $before_connections = count(CollectionFixtureData::$connections);
        $response = $report->get_collections();
        check($response['status'] === SUCCESS && $response['data']['size'] === 5, 'Ordinary report list/count preserved');
        foreach ($response['data']['data'] as $row) { check(!array_key_exists('entries', $row), 'List rows omit entries'); }
        check($report->fixture_payment_entry->calls === [] && $report->fixture_billing_entry->calls === [], 'Lists perform no entry lookups');
        check(\App\Models\Yakap_billing::$instances === $before_yakap && count(CollectionFixtureData::$connections) === $before_connections, 'Lists instantiate no detail dependencies');
    }
    $report = new ReportCollectionFixture($dates + ['billing_payment_id' => 501, 'branch_id' => 1, 'mop' => 'cash', 'date' => '2026-10-12', 'charge_to_id' => 2048]);
    check(count($report->get_collections()['data']['data'][0]['entries']) === 10, 'Branch filter selects payment but retains entries from all branches');
    check($report->fixture_payment->calls[0][1] === [501,'cash','2026-10-12',2048,1,20,null,1,'2026-10-01','2026-10-13'], 'Existing filters and defaults passed with payment ID');
    check($report->fixture_payment->calls[1][1] === [501,'cash','2026-10-12',2048,null,1,'2026-10-01','2026-10-13'], 'Count uses matching filters');
    check(count($report->fixture_billing_entry->calls) === 1 && $report->fixture_billing_entry->calls[0]['invoice'] === true && $report->fixture_billing_entry->calls[0]['page'] === null, 'Linked billing entries loaded in one invoice-enabled unpaginated batch');
    check($report->fixture_branch->calls === 1, 'Branch names batched once');
    foreach ([['billing_payment_id' => 999], ['billing_payment_id' => 504], ['billing_payment_id' => 501,'page' => 2,'data_size' => 1], ['billing_payment_id' => 501,'branch_id' => 6], ['billing_payment_id' => 501,'mop' => 'check'], ['billing_payment_id' => 501,'charge_to' => 999], ['billing_payment_id' => 501,'date' => '2026-10-11']] as $payload) {
        $report = new ReportCollectionFixture($dates + $payload);
        $response = $report->get_collections();
        check($response['status'] === FAIL_NOT_FOUND && $response['data'] === ['No collections found.'], 'ID must match filters/pagination and active payment');
        check($report->fixture_payment_entry->calls === [], 'No-match requests skip entries');
    }
    foreach ([0, -1, '0', 'abc', '1abc', '1.5', 1.5, true, false, [], ['501'], new \stdClass(), '9999999999999999999999'] as $id) {
        $report = new ReportCollectionFixture($dates + ['billing_payment_id' => $id]);
        $response = $report->get_collections();
        check($response['status'] === FAIL && $response['data'] === ['billing_payment_id must be a positive integer.'], 'Malformed payment IDs return validation failure');
        check($report->fixture_payment->calls === [] && $report->fixture_payment_entry->calls === [], 'Invalid IDs query nothing');
    }
    foreach ([['billing_payment_id' => 501], ['billing_payment_id' => 501,'date_from' => '2026-10-01'], ['billing_payment_id' => 501,'date_from' => '2026-02-30','date_to' => '2026-10-13'], ['billing_payment_id' => 501,'date_from' => '2026-10-14','date_to' => '2026-10-13']] as $payload) {
        $report = new ReportCollectionFixture($payload);
        check($report->get_collections()['status'] === FAIL && $report->fixture_payment->calls === [], 'Dates remain required and valid for ID lookup');
    }
    check((new ReportCollectionFixture(['billing_payment_id' => 501,'date_from' => '2026-10-13','date_to' => '2026-10-13']))->get_collections()['status'] === SUCCESS, 'Inclusive paid-date end boundary');
    check((new ReportCollectionFixture(['billing_payment_id' => 501,'date_from' => '2026-10-01','date_to' => '2026-10-12']))->get_collections()['status'] === FAIL_NOT_FOUND, 'Out-of-range ID not returned');
    $paged_report = new ReportCollectionFixture($dates + ['page' => 2, 'data_size' => 2]);
    $paged = $paged_report->get_collections();
    check($paged['data']['size'] === 5 && count($paged['data']['data']) === 2, 'List pagination/count unchanged');

    $entry_model = new \App\Models\Billing_payment_entry();
    $billing_entry_model = new \App\Models\Billing_entry();
    $service = new \App\Libraries\Billing_collection_entries($entry_model, $billing_entry_model, new \App\Models\Branch_master(), new \App\Models\Yakap_billing());
    check($service->get_entries_by_payment_ids([]) === [] && $entry_model->calls === [], 'Empty payment-ID batch never fetches all entries');
    $multiple = $service->get_entries_by_payment_ids([501,501,502]);
    check(array_keys($multiple) === [501,502] && $entry_model->calls[0] === [null,[501,502]], 'Batch IDs deduplicated and grouped by payment');
    $entry_ids = $billing_entry_model->calls[0]['ids'];
    check(count($entry_ids) === count(array_unique($entry_ids)), 'Repeated linked billing IDs deduplicated');
    check(count($multiple[501]) === 10 && count($multiple[502]) === 1, 'Multiple-payment batches do not cross-attach entries');
    echo "PASS: {$checks} isolated collection report checks (mocked reads only).\n";
}
