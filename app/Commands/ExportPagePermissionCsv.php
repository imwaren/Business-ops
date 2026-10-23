<?php

namespace App\Commands;

use App\Libraries\PageInventory;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ExportPagePermissionCsv extends BaseCommand
{
    protected $group       = 'Pages';
    protected $name        = 'pages:export-csv';
    protected $description = 'Write page.csv and permission.csv (all roles is_allowed=1) for phpMyAdmin import.';
    protected $usage       = 'pages:export-csv';

    public function run(array $params)
    {
        $dir = ROOTPATH . 'docs/seeds';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            CLI::error('Unable to create docs/seeds.');
            return;
        }

        $pages   = PageInventory::pages();
        $roleIds = PageInventory::roleIds();

        $pagePath = $dir . '/page.csv';
        $pageFh   = fopen($pagePath, 'wb');
        if ($pageFh === false) {
            CLI::error('Unable to write page.csv.');
            return;
        }
        fputcsv($pageFh, ['id', 'controller', 'method']);
        $pageId = 1;
        foreach ($pages as $page) {
            fputcsv($pageFh, [$pageId, $page['controller'], $page['method']]);
            $pageId++;
        }
        fclose($pageFh);

        $permPath = $dir . '/permission.csv';
        $permFh   = fopen($permPath, 'wb');
        if ($permFh === false) {
            CLI::error('Unable to write permission.csv.');
            return;
        }
        fputcsv($permFh, ['id', 'role_id', 'page_id', 'is_allowed']);
        $permId     = 1;
        $pageCount  = count($pages);
        foreach ($roleIds as $roleId) {
            for ($pid = 1; $pid <= $pageCount; $pid++) {
                fputcsv($permFh, [$permId, $roleId, $pid, 1]);
                $permId++;
            }
        }
        fclose($permFh);

        CLI::write(
            'Wrote ' . ($pageId - 1) . ' pages and ' . ($permId - 1) . ' permissions for ' . count($roleIds) . ' roles.',
            'green'
        );
        CLI::write($pagePath);
        CLI::write($permPath);
    }
}
