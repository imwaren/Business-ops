<?php

namespace App\Controllers;

use App\Core\MYT_Controller;
use App\Libraries\Approval_reminders;

/** Uses the same CLI / X-Cron-Secret authorization as the existing Cron module. */
class Approval_reminder_jobs extends MYT_Controller
{
    protected $cron_page = true;

    public function initController($request, $response, $logger)
    {
        $this->public_page = true;
        parent::initController($request, $response, $logger);
    }

    public function _remap($method, ...$params)
    {
        // Older production MYT_Controller versions do not enforce cron_page.
        // Enforce the existing cron-secret policy here before their dispatcher.
        if (($response = $this->_cron_authorized()) !== true) {
            return $response;
        }
        if ($method !== 'process') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        return parent::_remap($method, ...$params);
    }

    protected function _cron_authorized()
    {
        if (is_cli()) {
            return true;
        }
        $expected = defined('CRON_SECRET') ? (string) CRON_SECRET : (string) env('CRON_SECRET', '');
        if (!cron_secret_is_valid($expected, (string) $this->request->getHeaderLine('X-Cron-Secret'),
            (string) $this->request->getGet('cron_secret'))) {
            return $this->_create_response(FAIL_UNAUTHORIZED, ['message' => 'Unauthorized'], true);
        }
        return true;
    }

    public function process()
    {
        return $this->_create_response(SUCCESS, (new Approval_reminders())->run(), true);
    }
}
