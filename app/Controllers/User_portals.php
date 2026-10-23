<?php
namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User_portal;
use App\Models\User_portal_log;

class User_portals extends MYT_Controller
{
    protected $request;
    protected $user_portal;
    protected $user_portal_log;
    protected $helpers = ['text'];

    /**
     * Constructor.
     */
    public function initController($request, $response, $logger)
    {
        parent::initController($request, $response, $logger);
        if (in_array((string) $this->router->methodName(), ['login', 'forgot_password'], true)) {
            $this->public_page = true;
        }
        helper(['filesystem', 'text']);
        $this->_load_essentials();
    }

    /**
     * Load Pre-requisite Models, Config Files, and Libraries
     */
    protected function _load_essentials()
    {
        // Preload any models, libraries, etc, here.
        $this->user_portal     = new User_portal();
        $this->user_portal_log = new User_portal_log();

        $this->db = \Config\Database::connect();
    }

    /**
     * Get user_portals
     */
    public function get()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $company_id = $this->payload['company_id'] ?? null;
        $user_portal_id = $this->payload['user_portal_id'] ?? null;
        $term = $this->payload['term'] ?? null;
        $page = $this->payload['page'] ?? null;
        $data_size = $this->payload['data_size'] ?? null;

        $user_portals = $this->user_portal->get_details($company_id, $user_portal_id, $term, $page, $data_size)?:[];
        $size = count($this->user_portal->get_details($company_id, $user_portal_id, $term, null, null)?:[]);

        if(!$user_portals) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'No user_portals found.'
            ];
        } else {
            $response_status = SUCCESS;
            $response = [
                'size' => $size,
                'data' => strip_sensitive_user_fields($user_portals)
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * login to patient portal
     */
    public function login()
    {
        $username = $this->request->getVar('username');
        $password = $this->request->getVar('password');

        // if user doesn't exist
        if (! $user = $this->user_portal->select('', ['username' => $username, 'is_deleted' => 0], 1)) {
            $response_status = FAIL;
            $response        = [
                'Unregistered user',
            ];
            $this->_add_user_portal_log($user, 'unregistered user', 'Unregistered user'); // add userlog
        } elseif (! password_verify($password, $user->password)) {                     // if password incorrect
            $response_status = FAIL_NOT_FOUND;
            $response        = [
                'Incorrect Password',
            ];
            $this->_add_user_portal_log($user, 'Incorrect Password', 'Incorrect Password'); // add userlog
        } else {
            if ($token_details = $this->_register_token($user->id)) {
                $user->token        = $token_details['token'];
                $user->token_expiry = $token_details['token_expiry'];
                $user->message      = 'Login successful';
            }

            unset($user->password);
            $response_status = SUCCESS;
            $response        = $user;
            $this->_add_user_portal_log($user, 'login', 'Login Successfully'); // add userlog
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _register_token($requested_by)
    {

        $token = $this->_generate_token(40);

        $current_datetime = date('Y-m-d H:i:s');
        $token_expiry     = date("Y-m-d H:i:s", strtotime("$current_datetime +12 hours"));

        $where = [
            'id' => $requested_by,
        ];

        $values = [
            'token'        => $token,
            'token_expiry' => $token_expiry,
            'updated_by'   => $requested_by,
            'updated_on'   => $current_datetime,
        ];

        if (! $this->user_portal->update($where, $values)) {
            return false;
        }

        return ['token' => $token, 'token_expiry' => $token_expiry];
    }

    /**
     * Add Userlog
     */
    protected function _add_user_portal_log($user, $type, $response)
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
            'user_id'      => $user->id,
            'ip_address'   => $this->request->getServer('REMOTE_ADDR'),
            'user_agent'   => $currentAgent,
            'platform'     => $platform,
            'token'        => $user->token,
            'token_expiry' => $user->token_expiry,
            'type'         => $type,
            'response'     => $response,
            'time_check'   => date('Y-m-d H:i:s'),
            'added_by'     => $user->id,
            'added_on'     => date('Y-m-d H:i:s'),
        ];

        $this->user_portal_log->insert($values);

    }

    /**
     * Reset password: forgot-password flow (reset_token) or first-login flow (requester).
     */
    public function reset_password()
    {
        if (($response = $this->_require_admin()) !== true) {
            return $response;
        }

        $reset_token = $this->payload['token'] ?? null;
        $email = $this->payload['email'] ?? null;
        if ($reset_token && $email) {
            return $this->_reset_password_by_token($reset_token, $email);
        }

        $requester      = $this->request->getVar('requester');
        $new_password   = $this->request->getVar('new_password');
        $is_first_login = $this->request->getVar('is_first_login');
        $is_nda = $this->request->getVar('is_nda') ?? 0;

        $user = $this->user_portal->select('', ['id' => $requester], 1);

        if (empty($user)) {
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Invalid user']);
        }

        $now = date('Y-m-d H:i:s');
        $values = [
            'password'       => app_password_hash($new_password),
            'is_first_login' => $is_first_login,
            'is_nda'         => $is_nda,
            'nda_signed_on'  => $is_nda == 1 ? $now : null,
            'is_changed'     => 1,
            'changed_by'     => $requester,
            'changed_on'     => $now,
            'updated_by'     => $requester,
            'updated_on'     => $now,
        ];

        if (! $this->user_portal->update(['id' => $user->id], $values)) {
            return $this->_create_response(FAIL, ['message' => 'Failed to reset password. Please try again.']);
        }

        return $this->_create_response(SUCCESS, [
            'message' => 'Password reset successful.',
            'user_id' => $user->id,
        ]);
    }

    /**
     * Create user_portal
     */
    public function create()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$user_portal_id = $this->_attempt_create()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add user_portal. ",
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'user_portal already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'user_portal_id' => $user_portal_id,
                'message'    => 'user_portal added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create a user_portal
     */
    private function _attempt_create()
    {
        $values = [
            'added_by'              => $this->payload['requester'],
            'added_on'              => date('Y-m-d H:i:s'),
        ];

        $this->payload['password'] = app_password_hash($this->payload['password']);

        $not_allowed = ['token', 'token_expiry'];
        foreach($this->user_portal->allowedFields AS $field) {
            if(in_array($field, $not_allowed)) {
                continue;
            }
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        if (!$user_portal_id = $this->user_portal->insert($values)) {
            $this->error_message = $this->user_portal->error();
            return false;
        }

        return $user_portal_id;
    }

    /**
     * Create multiple user_portals
     */
    public function create_multiple()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $this->db->transBegin();

        if (!$user_portal_ids = $this->_attempt_create_multiple()) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                "Failed to add user_portals. ",
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'user_portal already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'user_portal_ids' => $user_portal_ids,
                'message'    => 'user_portals added successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to create multiple user_portals
     */
    private function _attempt_create_multiple()
    {
        if (!array_key_exists('user_portals', $this->payload) || !is_array($this->payload['user_portals']) || empty($this->payload['user_portals'])) {
            $this->error_message = ['No user_portals added.'];
            return false;
        }

        $default_company_id = array_key_exists('company_id', $this->payload) && $this->payload['company_id'] != ''
            ? $this->payload['company_id']
            : null;

        $user_portal_ids = [];
        $not_allowed = ['token', 'token_expiry'];

        foreach ($this->payload['user_portals'] as $user_portal) {
            if (!is_array($user_portal)) {
                continue;
            }

            $values = [
                'added_by' => $this->payload['requester'],
                'added_on' => date('Y-m-d H:i:s'),
            ];

            if (array_key_exists('password', $user_portal) && $user_portal['password'] != '') {
                $user_portal['password'] = app_password_hash($user_portal['password']);
            }

            if ((!array_key_exists('company_id', $user_portal) || $user_portal['company_id'] == '') && $default_company_id !== null) {
                $user_portal['company_id'] = $default_company_id;
            }

            foreach ($this->user_portal->allowedFields as $field) {
                if (in_array($field, $not_allowed)) {
                    continue;
                }
                if (!array_key_exists($field, $user_portal)) {
                    continue;
                }
                $values[$field] = $user_portal[$field] != '' ? $user_portal[$field] : null;
            }

            if (!$user_portal_id = $this->user_portal->insert($values)) {
                $this->error_message = $this->user_portal->error();
                return false;
            }

            $user_portal_ids[] = $user_portal_id;
        }

        if (empty($user_portal_ids)) {
            $this->error_message = ['No user_portals added.'];
            return false;
        }

        return $user_portal_ids;
    }

    /**
     * Update user_portal
     */
    public function update()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $user_portal_id = $this->payload['user_portal_id'];
        $where = [
                    'id' => $user_portal_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$user_portal = $this->user_portal->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'user_portal is not found.'
            ];
        } elseif (!$this->_attempt_update($user_portal)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update user_portal.',
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'user_portal_id' => $user_portal_id,
                'message'    => 'user_portal updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt update
     */
    protected function _attempt_update($user_portal)
    {
        $values = [
            'updated_by'            => $this->payload['requester'],
            'updated_on'            => date('Y-m-d H:i:s')
        ];

        if(array_key_exists('password', $this->payload)) {
            $this->payload['password'] = app_password_hash($this->payload['password']);
        }

        // This iterates over each necessary field
        // $this->user_portal->allowedFields is declared at the top of the model
        $not_allowed = ['token', 'token_expiry'];
        foreach($this->user_portal->allowedFields AS $field) {
            if(in_array($field, $not_allowed)) {
                continue;
            }
            if(!array_key_exists($field, $this->payload)) {
                continue;
            }
            $values[$field] = $this->payload[$field]!=''?$this->payload[$field]:null;
        }

        // Manually add/modify the fields here
        // $values['field_name'] => 'value';

        $where = ['id' => $user_portal->id];

        if (!$this->user_portal->update($where, $values)) {
            $this->error_message = $this->user_portal->error();
            return false;
        }
        return true;
    }

    /**
     * change password user_portal
     */
    public function change_password()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $is_changed = $this->payload['is_changed'] ?? null;
        $user_portal_id = $this->payload['user_portal_id'];
        $where = [
                    'id' => $user_portal_id, 
                    'is_deleted' => 0
                ];

        $this->db->transBegin();

        if (!$user_portal = $this->user_portal->select('', $where, 1)) {
            $response_status = FAIL_NOT_FOUND;
            $response = [
                'user_portal not found.'
            ];
        } elseif ($is_changed == 1 && $user_portal->is_changed == 1) {
            $response_status = FAIL;
            $response = [
                'Password is already changed.'
            ];
        } elseif (!$this->payload['password']) {
            $response_status = FAIL;
            $response = [
                'Password is required.'
            ];
        } elseif (!$this->_attempt_change_password($user_portal)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to change password.'
            ];
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'user_portal_id' => $user_portal_id,
                'message'    => 'Password changed successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt change password
     */
    protected function _attempt_change_password($user_portal)
    {
        $values = [
            'password' => app_password_hash($this->payload['password']),
            'is_changed' => 1,
            'changed_by' => $this->payload['requester'],
            'changed_on' => date('Y-m-d H:i:s'),
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s'),
        ];

        $where = ['id' => $user_portal->id];
        if (!$this->user_portal->update($where, $values)) {
            return false;
        }
        return true;
    }

    /**
     * Update multiple user_portals (details sync by company_id)
     */
    public function update_multiple()
    {
        if (($response = $this->_api_validation()) !== true) {
            return $response;
        }

        $company_id = $this->payload['company_id'] ?? null;
        if (empty($company_id)) {
            return $this->_create_response(FAIL, ['company_id is required.']);
        }

        if (!array_key_exists('user_portals', $this->payload) || !is_array($this->payload['user_portals'])) {
            return $this->_create_response(FAIL, ['user_portals is required.']);
        }

        $this->db->transBegin();

        if (!$this->_attempt_update_multiple($company_id)) {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to update user_portals.',
            ];

            if($this->error_message && array_key_exists('code', $this->error_message) && $this->error_message['code'] === 1062) {
                $response[] = 'unique fields already exists.';
            }
        } else {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'company_id' => $company_id,
                'message'    => 'user_portals updated successfully.'
            ];
        }

        return $this->_create_response($response_status, $response);
    }

    /**
     * Attempt to sync user_portals for a company
     * - id present => update
     * - id omitted => insert (is_deleted = 0)
     * - existing ids missing from payload => soft-delete
     */
    private function _attempt_update_multiple($company_id)
    {
        $existing_portals = $this->user_portal->select('id', ['company_id' => $company_id, 'is_deleted' => 0]) ?: [];
        $existing_ids = array_column($existing_portals, 'id');

        $payload_ids = [];
        foreach ($this->payload['user_portals'] as $user_portal) {
            if (!empty($user_portal['id'])) {
                $payload_ids[] = (int)$user_portal['id'];
            }
        }

        $ids_to_delete = array_diff($existing_ids, $payload_ids);
        if (!empty($ids_to_delete)) {
            foreach ($ids_to_delete as $delete_id) {
                $delete_values = [
                    'is_deleted' => 1,
                    'updated_by' => $this->payload['requester'],
                    'updated_on' => date('Y-m-d H:i:s'),
                ];
                if (!$this->user_portal->update(['id' => $delete_id], $delete_values)) {
                    $this->error_message = $this->user_portal->error();
                    return false;
                }
            }
        }

        $not_allowed = ['token', 'token_expiry'];

        foreach ($this->payload['user_portals'] as $user_portal) {
            if (!is_array($user_portal)) {
                continue;
            }

            $values = [
                'company_id' => $company_id,
            ];

            foreach ($this->user_portal->allowedFields as $field) {
                if (in_array($field, $not_allowed) || $field === 'company_id') {
                    continue;
                }
                if (!array_key_exists($field, $user_portal)) {
                    continue;
                }

                if ($field === 'password') {
                    if ($user_portal['password'] === '' || $user_portal['password'] === null) {
                        continue;
                    }
                    $values[$field] = app_password_hash($user_portal['password']);
                    continue;
                }

                $values[$field] = $user_portal[$field] != '' ? $user_portal[$field] : null;
            }

            if (!empty($user_portal['id'])) {
                $values['updated_by'] = $this->payload['requester'];
                $values['updated_on'] = date('Y-m-d H:i:s');
                $values['is_deleted'] = 0;

                if (!$this->user_portal->update(['id' => $user_portal['id']], $values)) {
                    $this->error_message = $this->user_portal->error();
                    return false;
                }
            } else {
                $values['added_by'] = $this->payload['requester'];
                $values['added_on'] = date('Y-m-d H:i:s');
                $values['is_deleted'] = 0;

                if (!$this->user_portal->insert($values)) {
                    $this->error_message = $this->user_portal->error();
                    return false;
                }
            }
        }

        return true;
    }
    
    /**
     * Request a password reset email. Unauthenticated.
     */
    public function forgot_password()
    {
        $login = strtolower(preg_replace('/\s+/', '', $this->payload['email'] ?? ($this->payload['username'] ?? '')));

        if ($login === '') {
            return $this->_create_response(FAIL, ['Email or username is required.']);
        }

        $generic_message = [
            'message' => 'If the email is registered, a password reset link has been sent.',
        ];

        $user = $this->user_portal->select('', [
            'username' => $login,
            'is_deleted' => 0,
        ], 1);

        if (!$user) {
            return $this->_create_response(SUCCESS, $generic_message);
        }

        $mail_to = filter_var($user->username, FILTER_VALIDATE_EMAIL) ? $user->username : null;
        if (!$mail_to) {
            return $this->_create_response(SUCCESS, $generic_message);
        }

        $reset_token = $this->_generate_token(64);
        $now = date('Y-m-d H:i:s');
        $reset_token_expiry = date('Y-m-d H:i:s', strtotime($now . ' +1 hour'));

        $where = ['id' => $user->id];
        $values = [
            'reset_token' => $reset_token,
            'reset_token_expiry' => $reset_token_expiry,
            'updated_by' => $user->id,
            'updated_on' => $now,
        ];

        if (!$this->user_portal->update($where, $values)) {
            return $this->_create_response(FAIL, ['Failed to create password reset token.']);
        }
        $reset_link = 'https://corporate-portal.myt-emr.com/reset-password?token=' . urlencode($reset_token) . '&email=' . urlencode($mail_to);
        $message = view('emails/forgot_password', [
            'user' => $user,
            'reset_link' => $reset_link,
        ]);

        if (!send_email('Password Reset Request', $message, $mail_to)) {
            $this->user_portal->update($where, [
                'reset_token' => null,
                'reset_token_expiry' => null,
                'updated_by' => $user->id,
                'updated_on' => date('Y-m-d H:i:s'),
            ]);
            return $this->_create_response(FAIL, ['Failed to send password reset email. Please try again.']);
        }

        return $this->_create_response(SUCCESS, $generic_message);
    }

    /**
     * Complete forgot-password using token from email.
     */
    protected function _reset_password_by_token($reset_token, $email)
    {
        $password = $this->payload['password'] ?? ($this->payload['new_password'] ?? null);

        if (!$password) {
            return $this->_create_response(FAIL, ['Password is required.']);
        }

        $user = $this->user_portal->select('', [
            'reset_token' => $reset_token,
            'username'  => $email,
            'is_deleted' => 0,
        ], 1);

        if (!$user || empty($user->reset_token_expiry) || strtotime($user->reset_token_expiry) < time()) {
            return $this->_create_response(FAIL, ['Invalid or expired reset token.']);
        }

        $now = date('Y-m-d H:i:s');
        $values = [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'reset_token' => null,
            'reset_token_expiry' => null,
            'is_first_login' => 0,
            'is_changed' => 1,
            'changed_by' => $user->id,
            'changed_on' => $now,
            'updated_by' => $user->id,
            'updated_on' => $now,
        ];

        if (!$this->user_portal->update(['id' => $user->id], $values)) {
            return $this->_create_response(FAIL, ['Failed to reset password. Please try again.']);
        }

        return $this->_create_response(SUCCESS, [
            'user_id' => $user->id,
            'message' => 'Password reset successful.',
        ]);
    }
}
