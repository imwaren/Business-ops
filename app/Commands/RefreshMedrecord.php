<?php

namespace App\Commands;

use App\Models\Report;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class RefreshMedrecord extends BaseCommand
{
    protected $group       = 'Reports';
    protected $name        = 'medrecord:refresh';
    protected $description = 'Rebuild medrecord snapshot rows for one branch and transaction date.';
    protected $usage       = 'medrecord:refresh [branch] [date]';
    protected $arguments   = [
        'branch' => 'Branch id (1, 2, 3, 4, or 7).',
        'date'   => 'Transaction date YYYY-MM-DD (default: today).',
    ];
    protected $options     = [
        '--branch' => 'Branch id (1, 2, 3, 4, or 7).',
        '--date'   => 'Transaction date YYYY-MM-DD (default: today).',
        '--force'  => 'Ignore the 30-second debounce stamp.',
    ];

    public function run(array $params)
    {
        $branchIn = $params[0] ?? CLI::getOption('branch');
        $dateIn   = $params[1] ?? CLI::getOption('date');
        $force    = CLI::getOption('force') !== null;

        $branchId = (int) $branchIn;
        if ($branchId <= 0) {
            CLI::error('branch is required. Use 1, 2, 3, 4, or 7.');
            return;
        }

        $date = $dateIn ?: date('Y-m-d');
        CLI::write("Refreshing medrecord branch {$branchId} for {$date}...", 'yellow');

        $status = (new Report())->refresh_medrecord_target($branchId, $date, $force);
        if ($status === 'ok') {
            CLI::write("Medrecord refresh complete for branch {$branchId} date {$date}.", 'green');
            return;
        }

        if (in_array($status, ['debounce', 'locked'], true)) {
            CLI::write("Medrecord refresh skipped ({$status}) for branch {$branchId} date {$date}.", 'yellow');
            return;
        }

        CLI::error("Medrecord refresh {$status} for branch {$branchId} date {$date}.");
    }
}
