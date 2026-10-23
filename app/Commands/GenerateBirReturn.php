<?php

namespace App\Commands;

use App\Libraries\Bir_return_generator;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class GenerateBirReturn extends BaseCommand
{
    protected $group       = 'BIR';
    protected $name        = 'bir:generate';
    protected $description = 'Generate a queued BIR 1702-RT or 1702Q PDF.';
    protected $usage       = 'bir:generate [bir_return_file_id]';
    protected $arguments   = [
        'bir_return_file_id' => 'bir_return_file.id to generate.',
    ];

    public function run(array $params)
    {
        $id = (int) ($params[0] ?? 0);
        if ($id <= 0) {
            CLI::error('bir_return_file_id is required.');
            return;
        }

        CLI::write('Generating BIR return id ' . $id . '...', 'yellow');

        $ok = (new Bir_return_generator())->generate($id);
        if ($ok) {
            CLI::write('BIR return id ' . $id . ' is ready.', 'green');
            return;
        }

        CLI::error('BIR return generation failed for id ' . $id . '. Check writable/logs/bir_return.log.');
    }
}
