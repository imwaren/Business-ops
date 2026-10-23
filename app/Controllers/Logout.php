<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Models\User;
use App\Models\Userlog;

class Logout extends MYT_Controller
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
        parent::initController($request, $response, $logger);
        helper(['filesystem', 'text']);
        $this->_load_essentials();
        $this->payload = $this->request->getVar();
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

        $this->db->transBegin();
        $where = [
            'id' => $this->request->getVar('requester'), 
            'is_deleted' => 0
        ];

        //var_dump($this->_clear_token()); die();
        $user = $this->user->select('', $where, 1);

        if ($this->_clear_token()) {
            $this->db->transCommit();
            $response_status = SUCCESS;
            $response = [
                'message' => 'Logout successfully.'
            ];
            $this->_add_user_log($user, 'logout', 'Logout successfully'); // add userlog
        } else {
            $this->db->transRollback();
            $response_status = FAIL;
            $response = [
                'Failed to logout.',
            ];
            $this->_add_user_log($user, 'logout', 'Failed to logout'); // add userlog
        }

        return $this->_create_response($response_status, $response);
    }

    protected function _clear_token()
    {
        $where = [
            'id' => $this->payload['requester']
        ];

        $values = [
            'token' => null,
            'token_expiry' => null,
            'updated_by' => $this->payload['requester'],
            'updated_on' => date('Y-m-d H:i:s')
        ];

        if (!$this->user->update($where, $values)) {
            return false;
        }

        return true;
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
            'user_id' => $user->id,
            'ip_address' => $this->request->getServer('REMOTE_ADDR'),
            'user_agent' => $currentAgent,
            'platform' => $platform,
            'token' => $user->token,
            'token_expiry' => $user->token_expiry,
            'type' => $type,
            'response' => $response,
            'time_check' => date('Y-m-d H:i:s'),
            // 'latitude' => 1,
            // 'longitude' => 1,
            'added_by' => $user->id,
            'added_on' => date('Y-m-d H:i:s')
        ];

        $this->userlog->insert($values);

    }
}