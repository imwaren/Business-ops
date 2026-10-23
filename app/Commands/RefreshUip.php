<?php

namespace App\Commands;

use App\Models\Report_clinic;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class RefreshUip extends BaseCommand
{
    protected $group       = 'Reports';
    protected $name        = 'uip:refresh';
    protected $description = 'Backfill or incrementally refresh the consolidated UIP report tables.';
    protected $usage       = 'uip:refresh [date_from] [date_to]';
    protected $arguments   = [
        'date_from' => 'Inclusive YYYY-MM-DD start date (default: three days ago).',
        'date_to'   => 'Inclusive YYYY-MM-DD end date (default: today).',
    ];

    public function run(array $params)
    {
        $dateFrom = $params[0] ?? date('Y-m-d', strtotime('-3 days'));
        $dateTo   = $params[1] ?? date('Y-m-d');

        foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $name => $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                CLI::error("{$name} must use YYYY-MM-DD format.");
                return;
            }
        }

        if ($dateFrom > $dateTo) {
            CLI::error('date_from must not be after date_to.');
            return;
        }

        CLI::write("Refreshing UIP data from {$dateFrom} through {$dateTo}...", 'yellow');

        try {
            $result = (new Report_clinic())->refresh_uip_read_model($dateFrom, $dateTo);
            CLI::write(
                "UIP refresh complete ({$result['batches']} monthly batch(es)).",
                'green'
            );
        } catch (\Throwable $e) {
            CLI::error('UIP refresh failed: ' . $e->getMessage());
        }
    }
}
