<?php

namespace App\Commands;

use App\Libraries\Excel_export;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class Generate_excel extends BaseCommand
{
    protected $group = 'Exports';
    protected $name = 'exports:generate';
    protected $description = 'Render and publish one private Excel response snapshot on this application VM.';
    protected $usage = 'exports:generate [export_id]';
    protected $arguments = ['export_id' => 'Generated export ID; no request credentials.'];

    public function run(array $params)
    {
        $id = $params[0] ?? '';
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            CLI::error('A valid export_id is required.');
            return EXIT_ERROR;
        }
        return (new Excel_export())->generate($id) ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
