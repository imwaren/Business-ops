<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Userlog;

class Login extends MYT_Controller
{
    protected $request;
    protected $user;
    protected $userlog;
    protected $helpers = ['text'];

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        // Use this to make selected functions public
        // $is_public = ['/attendances/time_in', '/attendances/time_out'];
        // $path = array_key_exists('PATH_INFO', $_SERVER) ? $_SERVER['PATH_INFO'] : $_SERVER['ORIG_PATH_INFO'];
        // $this->public_page = in_array($path, $is_public)?true:false;
        $this->public_page = true;
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        // Preload any models, libraries, etc, here.
        $this->user = new User();
        $this->userlog = new Userlog();
        $this->validation = \Config\Services::validation();
        $this->db = \Config\Database::connect();
    }
    
    /**
     * login to website
     */
    public function index()
    {
        // var_dump($this->request->getVar('api_key'));
        // die();
        // $this->requested_by = 0;
        // if (($response = $this->_api_validation('login', 'index')) !== true) {
        //     return $response;
        // }

        return $this->_attempt_login();
    }

    protected function _attempt_login()
    {
        $email = $this->request->getVar('email');
        $password = $this->request->getVar('password');
        $uniformError = 'Invalid email or password';

        $throttleKey = md5(strtolower((string) $email) . '|' . $this->request->getIPAddress());
        $throttler   = service('throttler');
        if ($throttler->check($throttleKey, 5, 900) === false) {
            return $this->_create_response(FAIL_TOO_MANY, ['Too many login attempts. Try again later.']);
        }

        // if user doesn't exist
        if (!$user = $this->user->get_details_by_email($email)) {
            $response_status = FAIL;
            $response = [
                $uniformError
            ];
            $this->_add_user_log($user, 'unregistered user', $uniformError); // add userlog
        } elseif (!password_verify($password, $user['password'])) { // if password incorrect
            $response_status = FAIL;
            $response = [
                $uniformError
            ];
            $this->_add_user_log($user, 'Incorrect Password', $uniformError); // add userlog
        } else {
            $hashAlgo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            if (password_needs_rehash($user['password'], $hashAlgo)) {
                $this->user->update(['id' => $user['id']], [
                    'password'   => app_password_hash($password),
                    'updated_by' => $user['id'],
                    'updated_on' => date('Y-m-d H:i:s'),
                ]);
            }
            if ($token_details = $this->_register_token($user['id'])) {
                $user['token'] = $token_details['token'];
                $user['token_expiry'] = $token_details['token_expiry'];
                $user['message'] = 'Login successful';
            }
            
            unset($user['password']);
            $response_status = SUCCESS;
            //$response = $this->respond($user);
            $response = $user;
            // $response = $this->respond([
            //     'status' => 200,
            //     'message' => 'Login successful',
            //     'user' => $user[0]
            // ]);
            $this->_add_user_log($user, 'login', 'Login Successfully'); // add userlog
        }

        return $this->_create_response($response_status, $response);
        //return $response;
    }

    protected function _register_token($requested_by)
    {

        $token = $this->_generate_token(40);

        $current_datetime = date('Y-m-d H:i:s');
        $token_expiry = date("Y-m-d H:i:s", strtotime("$current_datetime +9 hours"));

        $where = [
            'id' => $requested_by,
        ];

        $values = [
            'token' => $token,
            'token_expiry' => $token_expiry,
            'updated_by' => $requested_by,
            'updated_on' => $current_datetime
        ];

        if (!$this->user->update($where, $values))
            return false;

        // Update requester for webapp log
        // $webappLogModel = new Webapp_log();
        // $value = ['requested_by' => $requested_by];

        // if (!$webappLogModel->update($this->webapp_log_id, $value))
        //     return false;

        return ['token' => $token, 'token_expiry' => $token_expiry];
    }

    /**
     * Add Userlog
     */
    protected function _add_user_log($user, $type, $response)
    {

        if (empty($user)) {
            return false;
        }

        $agent = $this->request->getUserAgent();

        if ($agent->isBrowser()) {
            $currentAgent = $agent->getBrowser() . ' ' . $agent->getVersion();
        } elseif ($agent->isRobot()) {
            $currentAgent = $agent->getRobot();
        } elseif ($agent->isMobile()) {
            $currentAgent = $agent->getMobile();
        } else {
            $currentAgent = 'Unidentified User Agent';
        }

        if ($agent->getPlatform()) {
            $platform = $agent->getPlatform();
        }

        $values = [
            'user_id' => $user['id'],
            'ip_address' => $this->request->getServer('REMOTE_ADDR'),
            'user_agent' => $currentAgent,
            'platform' => $platform,
            'token' => $user['token'],
            'token_expiry' => $user['token_expiry'],
            'type' => $type,
            'response' => $response,
            'time_check' => date('Y-m-d H:i:s'),
            // 'latitude' => 1,
            // 'longitude' => 1,
            'added_by' => $user['id'],
            'added_on' => date('Y-m-d H:i:s')
        ];

        $this->userlog->insert($values);

    }
}
