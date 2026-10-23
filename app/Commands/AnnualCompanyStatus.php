<?php
namespace App\Commands;

use App\Models\Company;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AnnualCompanyStatus extends BaseCommand
{
    protected $group = 'Maintenance';
    protected $name = 'companies:annual-status';
    protected $description = 'Deactivate companies without requests in the previous calendar year.';
    protected $options = [
        '--dry-run' => 'Read-only count of companies that would change.',
        '--year' => 'Completed calendar year to evaluate; defaults to the previous year in Asia/Manila.',
    ];

    public function run(array $params)
    {
        $db = null;
        $locked = false;
        $lockName = null;
        try {
            $db = \Config\Database::connect('master');
            $dryRun = CLI::getOption('dry-run') !== null;
            if (!$dryRun) {
                $lockName = 'companies:annual:' . substr(hash('sha256', $db->getDatabase()), 0, 24);
                $locked = (int) $db->query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->getRow()->acquired === 1;
                if (!$locked) {
                    throw new \RuntimeException('Another annual company status job is running.');
                }
            }
            $result = (new Company($db))->annualStatus(CLI::getOption('year'), $dryRun);
            CLI::write(json_encode($result, JSON_THROW_ON_ERROR));
            if (!$dryRun) {
                log_message('info', 'Annual company status completed: ' . json_encode($result));
            }
            return EXIT_SUCCESS;
        } catch (\Throwable $error) {
            log_message('error', 'Annual company status failed: ' . $error->getMessage());
            CLI::error('Annual company status failed: ' . $error->getMessage());
            return EXIT_ERROR;
        } finally {
            if ($locked && $db !== null) {
                $db->query('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }
}
