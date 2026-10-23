<?php

namespace App\Controllers;

namespace App\Core;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * MYT Controller Class
 *
 * @package       MYT
 * @subpackage    Application
 * @category      Core
 * @author        MYT SoftDev Solutions, Inc.
 * @link          http://mytsoftdevsolutions.com
 */
abstract class MYT_Controller extends Controller
{
    /**
     * Response traits
     */
    use ResponseTrait;

    /**
     * Error
     */
    protected $_error;

    /**
     * API payload
     */
    protected $payload;

    /**
     * Page meta
     */
    protected $router;

    protected $validation;
    protected $validation_errors;

    protected $api_log_id;
    protected $environment;
    protected $public_page               = false;
    protected $cron_page                 = false;
    protected $enable_payload_encryption = true;
    protected $login_access = 'User';
    protected $requester_user;
    protected $excel_response_capture = false;
    protected $excel_captured_response;

    protected $error_message;
    protected $api_log;
    protected $api_response;

    /**
     * Database connection
     */
    protected $db;

    /**
     * Instance of the main Request object.
     *
     * @var CLIRequest|IncomingRequest
     */
    protected $request;

    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var array
     */
    protected $helpers = [];

    /**
     * Constructor
     *
     * @access public
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        //load services
        $this->router     = \Config\Services::router();
        $this->request    = \Config\Services::request();
        $this->validation = \Config\Services::validation();

        $this->environment       = getenv('CI_ENVIRONMENT');
        $this->validation_errors = null;

        // Use $this->payload instead of using $this->request->getVar()
        // if ($this->environment === 'development') {
            $this->payload = (array) $this->request->getVar();
        // } elseif ($this->environment === 'production') {
        //     $this->payload = $this->_decrypt_payload();
        // }

        // load models
        $separate_db_connection = db_connect();
        $this->api_log          = model("App\Models\Api_log", true, $separate_db_connection);
        $this->api_response     = model("App\Models\Api_response", true, $separate_db_connection);

        //load helpers
        helper(['language', 'service_line']);
    }

    /**
     * Remap function is called every before entering a method
     * API validation is transferred here because "return" is not
     * allowed in initController as per Codeigniter documentation
     */
    public function _remap($method, ...$params)
    {
        //if ($this->environment === 'development' && !$this->public_page) {
            if (!$this->api_log_id = $this->_api_log()) {
                return $this->_create_response(FAIL_SERVER_ERROR, ['message' => 'Unable to save logs. Please try again.']);
            }
        //}

        if ($this->cron_page) {
            $cron_result = $this->_cron_authorized();
            if ($cron_result !== true) {
                return $cron_result;
            }
        } elseif (!$this->public_page) {
            $validation_result = $this->_api_validation();
            if ($validation_result !== true) {
                return $validation_result;
            }
            $permission_result = $this->_permission_allowed($method);
            if ($permission_result !== true) {
                return $permission_result;
            }
        }

        if (method_exists($this, $method)) {
            return $this->{$method}(...$params);
        }

        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }

    /**
     * Api Log
     * @access private
     *
     * only for development ENVIRONMENT
     * acceptable methods GET and POST
     */
    private function _api_log()
    {
        $_controller = $this->router->controllerName();
        $_method     = $this->router->methodName();
        $ip          = $_SERVER['REMOTE_ADDR'];
        $latitude    = $this->request->getHeaderLine('latitude') ?: null;
        $longitude   = $this->request->getHeaderLine('longitude') ?: null;
        $_payload    = json_encode(redact_sensitive($this->payload));

        $values = [
            'controller'    => $_controller,
            'method'        => $_method,
            'ip_address'    => $ip,
            'signature'     => $this->request->getVar('signature'),
            'latitude'      => $latitude,
            'longitude'     => $longitude,
            'data_received' => $_payload,
            'requested_by'  => !empty($this->payload['requester']) ? $this->payload['requester'] : 0,
            'requested_on'  => date('Y-m-d H:i:s'),
        ];

        if (!$api_log_id = $this->api_log->insert($values)) {
            return false;
        } else {
            return $api_log_id;
        }
    }

    /**
     * Api Validation
     */
    protected function _api_validation()
    {

        $date_today   = new \DateTime ();
        $api_key      = $this->request->getHeaderLine('API-KEY');
        // Clients must use Origin-API-Key; underscore headers may be dropped by the web server.
        $origin_api_key = trim($this->request->getHeaderLine('Origin-API-Key'));
        $origin_api_key = $origin_api_key === '' ? null : $origin_api_key;
        $latitude     = $this->request->getHeaderLine('latitude') ?: null;
        $longitude    = $this->request->getHeaderLine('longitude') ?: null;
        $requested_by = $this->request->getVar('requester');
        $token        = $this->request->getVar('token');

        if (! api_key_is_valid($api_key)) {
            $response = ['message' => 'Invalid API KEY'];
            return $this->_create_response(FAIL_UNAUTHORIZED, $response);
        }
        
        if ($origin_api_key) {
            if (!csr_api_key_is_valid($origin_api_key)) {
                $response = ['message' => 'CSR Invalid API KEY'];
                return $this->_create_response(FAIL_UNAUTHORIZED, $response);
            } else {
                return true;
            }
        }

        // if (empty($latitude) || empty($longitude)) {
        //     $response = ['message' => 'Invalid lat & long'];
        //     return $this->_create_response(FAIL_UNAUTHORIZED, $response);
        // }

        //Check requester token and token expiry validity
        $user = model("App\Models\\" . ucfirst($this->login_access));
        $requester = $user->select('', ['id' => $requested_by, 'token' => $token], 1);

        if (empty($requester)) {
            $response = ['message' => 'Invalid Requester'];
            return $this->_create_response(FAIL_UNAUTHORIZED, $response);
        }

        $token_expiry = new \DateTime ($requester->token_expiry);
        if (! is_string($requester->token) || ! is_string($token) || ! hash_equals((string) $requester->token, (string) $token)) {
            $response = ['message' => 'Invalid Auth token'];
            return $this->_create_response(FAIL_UNAUTHORIZED, $response);
        } elseif ($token_expiry < $date_today) {
            $response = ['message' => 'Invalid Auth token'];
            return $this->_create_response(FAIL_UNAUTHORIZED, $response);
        }

        $this->requester_user = $requester;

        return true;
    }

    /**
     * Cron endpoints: CLI, X-Cron-Secret header, or ?cron_secret= query.
     */
    protected function _cron_authorized()
    {
        if (is_cli()) {
            return true;
        }

        $expected = (string) CRON_SECRET;
        $header   = (string) $this->request->getHeaderLine('X-Cron-Secret');
        $query    = (string) $this->request->getGet('cron_secret');
        if (! cron_secret_is_valid($expected, $header, $query)) {
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Unauthorized']);
        }

        return true;
    }

    protected function _controller_short_name(): string
    {
        $name  = (string) $this->router->controllerName();
        $parts = explode('\\', $name);

        return (string) end($parts);
    }

    protected function _is_admin_role($user = null): bool
    {
        $user = $user ?? $this->requester_user;

        return $user && (int) $user->role_id === (int) ROLE_ADMIN;
    }

    protected function _require_admin()
    {
        if (! $this->_is_admin_role()) {
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Forbidden']);
        }

        return true;
    }

    protected function _require_self_or_admin($user_id)
    {
        $requesterId = $this->payload['requester'] ?? null;
        if ((int) $requesterId === (int) $user_id || $this->_is_admin_role()) {
            return true;
        }

        return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Forbidden']);
    }

    /**
     * page + permission check. Admin always allowed. Missing page row is allowed.
     */
    protected function _permission_allowed(string $method)
    {
        return $this->_permission_allowed_for($this->_controller_short_name(), $method);
    }

    protected function _permission_allowed_for(string $controller, string $method)
    {
        if ($this->_is_admin_role()) {
            return true;
        }

        $page = model('App\Models\Page')->select('', [
            'controller' => $controller,
            'method'     => $method,
        ], 1);

        if (empty($page) || empty($page->id)) {
            return true;
        }

        $roleId = (int) ($this->requester_user->role_id ?? 0);
        $perm   = model('App\Models\Permission')->select('', [
            'role_id' => $roleId,
            'page_id' => (int) $page->id,
        ], 1);

        if (! empty($perm) && (int) $perm->is_allowed === 0) {
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Forbidden']);
        }

        return true;
    }

    protected function _requester_branch_ids(): array
    {
        $user = $this->requester_user;
        if (! $user || empty($user->branch_id)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $user->branch_id))));
    }

    /**
     * Success response without api_response logging (for heavy report endpoints).
     */
    protected function _respond_success(array $data)
    {
        if ($this->excel_response_capture) {
            $this->excel_captured_response = [SUCCESS, $data];
        }
        return $this->respond($data);
    }

    /**
     * Response Creator
     * @param string $status response trait code SUCCESS = 200, FAIL_UNAUTHORIZED = 401, FAIL = 400 or FAIL_NOT_FOUND = 404
     * @param array $data_response array of response
     * @access protected
     * @return   array
     * log api response only for development ENVIRONMENT
     */
    protected function _create_response($status, $data_response, $skip_response_logging = false)
    {
        if ($this->excel_response_capture) {
            $this->excel_captured_response = [$status, $data_response];
            $skip_response_logging = true;
        }
        $response = null;
        if (is_array($data_response) || is_object($data_response)) {
            if ($status === SUCCESS) {
                $response = $this->respond($data_response);
            } elseif ($status === FAIL_UNAUTHORIZED) {
                $data_response = ['error_messages' => $data_response];
                $response      = $this->fail($data_response, $status);
            } elseif ($status === FAIL) {
                $data_response = ['error_messages' => $data_response];
                $response      = $this->fail($data_response);
            } elseif ($status === FAIL_NOT_FOUND) {
                $data_response = ['error_messages' => $data_response];
                $response      = $this->fail($data_response, $status);
            } elseif ($status === FAIL_SERVER_ERROR) {
                $data_response = ['error_messages' => $data_response];
                $response      = $this->fail($data_response, $status);
            } elseif ($status === FAIL_TOO_MANY) {
                $data_response = ['error_messages' => $data_response];
                $response      = $this->fail($data_response, $status);
            }
        } else {
            $this->_throw_exception('Error: Data returned to client is not in an array/object form.');
        }

        $methodName = (string) $this->router->methodName();
        if ($skip_response_logging === false && ! should_skip_api_response_logging($methodName)) {

            $values = [
                'api_log_id'   => $this->api_log_id,
                'response'     => json_encode(redact_sensitive($data_response)),
                'status'       => $status,
                'responded_on' => date('Y-m-d H:i:s'),
            ];

            if (!$this->api_response->insert($values)) {
                $response = $this->fail(['error_messages' => ['Server error.']], $status);
            }
        }
        //}

        return $response;
    }

    /**
     * Capture one allowlisted base action with its original request and pagination.
     * Only rendering/publication runs in the private worker; reports are queried once.
     */
    protected function _queue_excel_export(string $method)
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $controller = $this->_controller_short_name();
        $base_method = \App\Libraries\Excel_export::base_method($controller, $method);
        if (!$base_method || !is_callable([$this, $base_method])) {
            return $this->_create_response(FAIL, ['base endpoint doesnt exist']);
        }
        foreach ([$method, $base_method] as $permission_method) {
            if (($response = $this->_permission_allowed($permission_method)) !== true) {
                return $response;
            }
        }

        $previous_capture = $this->excel_response_capture;
        $previous_response = $this->excel_captured_response;
        $this->excel_response_capture = true;
        $this->excel_captured_response = null;
        try {
            $base_response = $this->{$base_method}();
            $captured = $this->excel_captured_response;
        } finally {
            $this->excel_response_capture = $previous_capture;
            $this->excel_captured_response = $previous_response;
        }
        if (!$captured) {
            // Support ResponseTrait responses without changing ordinary JSON behavior.
            if (!$base_response instanceof \CodeIgniter\HTTP\ResponseInterface) {
                return $this->_create_response(FAIL_SERVER_ERROR, ['Unable to capture base response.']);
            }
            if ($base_response->getStatusCode() !== SUCCESS) {
                return $base_response;
            }
            $captured = [SUCCESS, json_decode($base_response->getBody(), false, 512, JSON_THROW_ON_ERROR)];
        }
        if ($captured[0] !== SUCCESS) {
            return $this->_create_response($captured[0], $captured[1]);
        }

        try {
            $owner = $this->_excel_export_owner();
            if ($owner === null) {
                return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Invalid export owner.']);
            }
            $snapshot = $base_response instanceof \CodeIgniter\HTTP\ResponseInterface
                ? json_decode($base_response->getBody(), false, 512, JSON_THROW_ON_ERROR)
                : $captured[1];
            $response = $this->_excel_export_service()->queue($controller, $method, $owner, $snapshot);
            $response['status_url'] = site_url('exports/get_status') . '?export_id=' . $response['export_id'];
            $response_status = SUCCESS;
        } catch (\Throwable $exception) {
            $response_status = FAIL_SERVER_ERROR;
            $response = ['Unable to queue Excel export.'];
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _excel_export_service(): \App\Libraries\Excel_export
    {
        return new \App\Libraries\Excel_export();
    }

    /** Fingerprint an authenticated context, never an unverified requester payload. */
    protected function _excel_export_owner(): ?string
    {
        $origin_key = trim($this->request->getHeaderLine('Origin-API-Key'));
        if ($origin_key !== '') {
            if (!csr_api_key_is_valid($origin_key)) {
                return null;
            }
            return hash_hmac('sha256', 'origin:' . $origin_key, (string) ENCRYPTION_KEY);
        }
        if (!$this->requester_user || empty($this->requester_user->id)) {
            return null;
        }
        return hash_hmac('sha256', 'user:' . $this->login_access . ':' . $this->requester_user->id, (string) ENCRYPTION_KEY);
    }

    /**
     * Decrypt signature sent by frontend
     * Format of the signature: <length of string payload>_<signature>
     * @access private
     */
    private function _decrypt_payload()
    {
        // $key = hex2bin("0123456789abcdef0123456789abcdef");
        // $iv = hex2bin("abcdef9876543210abcdef9876543210");
        $key = (string) myt_env('PAYLOAD_AES_KEY', '');
        $iv  = (string) myt_env('PAYLOAD_AES_IV', '');
        if ($key === '' || $iv === '') {
            return [];
        }

        $signature         = $this->request->getVar('signature');
        $signature_details = explode('_', $signature);
        $signature         = $signature_details[1];
        $payload_length    = $signature_details[0];

        $value     = openssl_decrypt($signature, 'AES-128-CBC', $key, OPENSSL_ZERO_PADDING, $iv);
        $value     = substr($value, 0, $payload_length);
        $decrypted = json_decode($value);
        $decrypted = (array) $decrypted;

        return $decrypted;
    }

    /**
     * Convert base64 to jpeg and save file to server
     */
    protected function _base_64_upload($field_name, $unique_id)
    {
        $field_name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $field_name);
        $unique_id  = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $unique_id);
        $upload_path = WRITEPATH . 'uploads/' . $field_name . '/' . $unique_id . '/';

        if (!file_exists($upload_path)) {
            mkdir($upload_path, 0750, true);
        }

        $filenames = [];

        $files = $this->request->getVar($field_name) ?: [];

        foreach ($files as $base64) {
            $filename = $field_name . '-' . bin2hex(random_bytes(8)) . '.jpg';
            if (!$this->_base64_to_jpeg($base64, $filename, $upload_path)) {
                return false;
            }

            $filenames[] = $filename;
        }

        return $filenames;
    }

    /**
     * Save JSON file to server
     */
    protected function _multipart_form_data_upload($field_name, $unique_id)
    {
        $field_name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $field_name);
        $unique_id  = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $unique_id);
        $upload_path = WRITEPATH . 'uploads/' . $field_name . '/' . $unique_id . '/';

        if (!file_exists($upload_path)) {
            mkdir($upload_path, 0750, true);
        }

        $filenames = [];
        $allowed   = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];

        $files = $this->request->getFiles();
        if (empty($files[$field_name])) {
            return $filenames;
        }
        foreach ($files[$field_name] as $file) {
            if (! $file->isValid() || $file->hasMoved()) {
                continue;
            }
            $ext = strtolower((string) $file->getClientExtension());
            if (! in_array($ext, $allowed, true) || $file->getSize() > 10 * 1024 * 1024) {
                continue;
            }
            $filename    = $file->getRandomName();
            $filenames[] = $filename;
            $file->move($upload_path, $filename);
        }

        return $filenames;
    }

    /**
     * Convert base 64 to image
     */
    private function _base64_to_jpeg($base64_string, $output_file, $filepath)
    {
        // open the output file for writing
        $ifp = fopen($filepath . $output_file, 'wb');

        // split the string on commas
        // $data[ 0 ] == "data:image/png;base64"
        // $data[ 1 ] == <actual base64 string>
        $data = explode(',', $base64_string);

        // we could add validation here with ensuring count( $data ) > 1
        if (count($data) < 2 or !fwrite($ifp, base64_decode($data[1]))) {
            return false;
        }

        // clean up the file resource
        fclose($ifp);

        return true;
    }

    /**
     * Input validation using Codeigniter 4 validation library
     * @access protected
     * @param string rule group declared in app/Config/Validation.php
     */
    protected function _validation_check($rule_group)
    {
        if (!$this->validation->run($this->payload, $rule_group)) {
            $errors                  = $this->validation->getErrors();
            $this->validation_errors = [];

            foreach ($errors as $error) {
                $this->validation_errors[] = $error;
            }

            return false;
        }
        return true;
    }

    /**
     * Generate token every user login
     * This gets removed after the user logs-out
     * @access protected
     */
    protected function _generate_token($length)
    {
        $length = max(16, (int) $length);
        $bytes  = random_bytes((int) ceil($length / 2));

        return substr(bin2hex($bytes), 0, $length);
    }

    /**
     * Custom throw of exception
     * @access private
     */
    private function _throw_exception($message)
    {
        $backtrace = debug_backtrace();
        $caller    = array_shift($backtrace);

        throw new \ErrorException ($message, 0, 5, $caller['file'], $caller['line']);
    }

    /**
     * Create triggers
     * @access private
     */
    private function _create_triggers()
    {
        $upload_path = FCPATH . 'public';

        if (!file_exists($upload_path)) {
            mkdir($upload_path, 0777, true);
        }

        $filename = 'columns_' . uniqid() . '.csv';

        $file = $this->request->getFile('columns');
        $file->store('../../public', $filename);

        // based on an excel file
        // assume, $data variable contains all data inside excel
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();

        $spreadsheet = $reader->load(FCPATH . 'public/' . $filename);
        $sheet = $spreadsheet->getSheet(0)->toArray();

        $column_sql = "IF (NEW.NAME_OF_COLUMN <> OLD.NAME_OF_COLUMN) THEN INSERT INTO trail SET source_id = OLD.id, table_name = 'NAME_OF_TABLE', col_name = 'NAME_OF_COLUMN', old_value = OLD.NAME_OF_COLUMN, new_value = NEW.NAME_OF_COLUMN, added_by = NEW.updated_by, added_on = NEW.updated_on; END IF;";

        $parent_trigger_queries = [];
        $trigger_create_queries = [];

        foreach ($sheet as $index => $datum) {

            $current_column_sql = $column_sql;

            if ($index == 0) continue;
            if (in_array($datum[0], ['webapp_log', 'webapp_response'])) continue;
            if (in_array($datum[1], ['id', 'added_by', 'added_on', 'updated_by', 'updated_on', 'is_deleted'])) continue;

            // $datum[0] is the table name
            // $datum[1] is the column name

            $current_column_sql = str_replace('NAME_OF_TABLE', $datum[0], $current_column_sql);
            $current_column_sql = str_replace('NAME_OF_COLUMN', $datum[1], $current_column_sql);

            if (array_key_exists($datum[0], $trigger_create_queries)) {

                $trigger_create_queries[$datum[0]] .= " $current_column_sql";
                $parent_trigger_queries[$datum[0]] = "DELIMITER $$ CREATE TRIGGER {$table_name}_after_update AFTER UPDATE ON {$table_name} FOR EACH ROW BEGIN {$trigger_create_queries[$datum[0]]} END $$ DELIMITER ;";

            } else {
                $table_name = $datum[0];

                $trigger_create_queries[$datum[0]] = " $current_column_sql";
                $parent_trigger_queries[$datum[0]] = "DELIMITER $$ CREATE TRIGGER {$table_name}_after_update AFTER UPDATE ON {$table_name} FOR EACH ROW BEGIN {$trigger_create_queries[$datum[0]]} END $$ DELIMITER ;";
            }

            $trigger_create_query = <<<EOT

EOT;
        }

        return array_values($parent_trigger_queries);
    }

    /**
     * Error msg
     * @return   string
     * @access protected
     */
    protected function _error_msg()
    {
        switch ($this->_error) {
            case is_array($this->_error):
                $errorMessages = '';
                foreach ($this->_error as $error) {
                    $errorMessages .= $error . '</br>'; // Concatenate errors
                }
                return '<p>' . $errorMessages . '</p>';

            default:
                if (!empty($this->_error)) {
                    $errorMessage = lang('error_messages.' . $this->_error);
                    return !empty($errorMessage) ? $errorMessage : $this->_error;
                } else {
                    return null;
                }
        }
    }

     /**
     * Decrypt data
     * @return   string
     * @access protected
     */
    protected function _decrypt_data($encrypted_data)
    {
        $cipher = 'aes-256-cbc';

        // Split the encrypted data and IV
        list($encrypted_value, $iv) = explode('::', base64_decode($encrypted_data), 2);

        // Decrypt the value
        $decrypted_value = openssl_decrypt($encrypted_value, $cipher, ENCRYPTION_KEY, 0, $iv);

        return $decrypted_value;
    }

    /**
     * Group result rows by a column value.
     */
    protected function _group_by(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!isset($row[$key])) {
                continue;
            }
            $out[$row[$key]][] = $row;
        }
        return $out;
    }

    /**
     * Collect unique non-empty values for a field from a list of rows.
     */
    protected function _collect_ids(array $rows, string $key): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!isset($row[$key]) || $row[$key] === null || $row[$key] === '' || $row[$key] === 'all') {
                continue;
            }
            $ids[] = $row[$key];
        }
        return array_values(array_unique($ids));
    }

    /**
     * Collect unique non-empty values for a field from grouped row maps.
     */
    protected function _collect_grouped_ids(array $grouped, string $key): array
    {
        $ids = [];
        foreach ($grouped as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row[$key]) || $row[$key] === null || $row[$key] === '' || $row[$key] === 'all') {
                    continue;
                }
                $ids[] = $row[$key];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Resolve comma-separated customer_account_id values against accounts keyed by company id.
     */
    protected function _customer_account_details(array $accountsByCompanyId, $customerAccountId): array
    {
        $details = [];
        foreach (preg_split('/\s*,\s*/', (string) $customerAccountId, -1, PREG_SPLIT_NO_EMPTY) as $id) {
            if (isset($accountsByCompanyId[$id])) {
                foreach ($accountsByCompanyId[$id] as $account) {
                    $details[] = $account;
                }
            }
        }
        return $details;
    }

    /**
     * Attach all *_reason history rows and fill empty live reason fields from the latest trail.
     */
    protected function _apply_reason_trails(array $row, array $histories, array $usersById = []): array
    {
        $trails = [];
        foreach ($histories as $history) {
            $col = $history['col_name'] ?? '';
            // matches: reason, approved_reason, approved_reason_1, reason_other, etc.
            if ($col !== '' && stripos($col, 'reason') !== false) {
                $addedBy = $history['added_by'] ?? null;
                $history['added_by_name'] = isset($usersById[$addedBy])
                    ? $usersById[$addedBy]['name']
                    : null;
                $trails[] = $history;
            }
        }
        $row['reason_trails'] = $trails;

        foreach ($trails as $trail) {
            $col = $trail['col_name'];
            $val = $trail['new_value'] ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            if (!isset($row[$col]) || $row[$col] === '' || $row[$col] === null) {
                $row[$col] = $val;
            }
        }
        return $row;
    }

    /**
     * Persist resolved service-line package_ids after insert/update (never store UUID keys).
     */
    protected function _rewrite_service_line_package_ids($model, array $services, array $keyToId, array $indexToId = []): bool
    {
        $result = service_line_collect_rewrites($services, $keyToId, $indexToId);
        if (empty($result['ok'])) {
            $this->error_message = $result['error'] ?? 'Unable to resolve add-on parent keys.';
            return false;
        }

        foreach ($result['updates'] as $update) {
            if (!$model->update(['id' => $update['id']], [
                'package_ids' => $update['package_ids'],
                'is_add_on' => $update['is_add_on'],
            ])) {
                $this->error_message = $model->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Remap copied package_ids from source service-row ids to destination service-row ids.
     */
    protected function _remap_copied_service_package_ids($model, array $sourceServices, array $sourceIdToDestId): bool
    {
        foreach (service_line_collect_copied_rewrites($sourceServices, $sourceIdToDestId) as $update) {
            if (!$model->update(['id' => $update['id']], [
                'package_ids' => $update['package_ids'],
                'is_add_on' => $update['is_add_on'],
            ])) {
                $this->error_message = $model->error();
                return false;
            }
        }

        return true;
    }

    /**
     * Generate a string primary key: YYYYMMDDHHMMSS + 3-letter branch prefix + last 4 hex chars of UUID v4.
     * Example: 20260918164501OAKA3F2
     *
     * CSR can copy this method for patient and transaction inserts.
     */
    protected function _generate_id(string $branch_prefix): string
    {
        $prefix = strtoupper($branch_prefix);
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        $suffix = strtoupper(substr(bin2hex($bytes), -4));

        return date('YmdHis') . $prefix . $suffix;
    }

    /**
     * Resolve the 3-letter id prefix for a branch. Unknown / 0 (master VIP sync) → OAK.
     */
    protected function _branch_id_prefix(?int $branch_id): string
    {
        $config = config('Branch');

        if ($branch_id && isset($config->id_prefixes[$branch_id])) {
            return $config->id_prefixes[$branch_id];
        }

        if ($branch_id && isset($config->branches[$branch_id]['id_prefix'])) {
            return $config->branches[$branch_id]['id_prefix'];
        }

        return 'OAK'; // default to Oakridge
    }

    /**
     * Insert with a generated string PK. Retries on duplicate key (MySQL 1062).
     *
     * @param \App\Core\MYT_Model $model
     * @return string|false inserted id
     */
    protected function _insert_encrypt_generated_id($model, array $data, ?array $fieldsToEncrypt = [], ?int $branch_id)
    {
        $maxAttempts = 5;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $data['id'] = $this->_generate_id($this->_branch_id_prefix($branch_id)); //uncomment this for production
            $insertedId = $model->insert_encrypt($data, true, $fieldsToEncrypt);

            if ($insertedId) {
                return $insertedId;
            }

            $error = method_exists($model, 'db_error') ? $model->db_error() : [];
            if ((int) ($error['code'] ?? 0) !== 1062) {
                $this->error_message = $error['message'] ?? (method_exists($model, 'error') ? $model->error() : 'Insert failed.');
                return false;
            }
        }

        $this->error_message = 'Unable to generate a unique id. Please try again.';
        return false;
    }

}

/*End of file MYT_Controller.php*/
/*Location .application/Core/MYT_Controller.php*/
