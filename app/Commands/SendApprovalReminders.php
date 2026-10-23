<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\Approval_reminders;

class SendApprovalReminders extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'approval:reminders';
    protected $description = 'Process due purchasing approval reminders in bounded branch batches.';
    protected $options = ['--check' => 'Run read-only schema and role checks without dispatching reminders.'];

    public function run(array $params)
    {
        try {
            $service = new Approval_reminders();
            CLI::write(json_encode(CLI::getOption('check') ? $service->readiness() : $service->run(), JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            log_message('error', 'Approval reminder worker failed: ' . $e->getMessage());
            CLI::error('Approval reminder worker failed: ' . $e->getMessage());
            return EXIT_ERROR;
        }
        return EXIT_SUCCESS;
    }
}
