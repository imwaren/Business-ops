<?php
namespace Config;

use CodeIgniter\Config\BaseConfig;

class Branch extends BaseConfig
{
    public $branches = [
        1 => ['name' => 'Mobile', 'db' => 'mobile', 'id_prefix' => 'MOB'],
        2 => ['name' => 'Oakridge', 'db' => 'oakridge', 'id_prefix' => 'OAK'],
        3 => ['name' => 'Mactan', 'db' => 'mactan', 'id_prefix' => 'MAC'],
        4 => ['name' => 'Consolacion', 'db' => 'consolacion', 'id_prefix' => 'CON'],
        7 => ['name' => 'Mantlewood', 'db' => 'mantlewood', 'id_prefix' => 'MAN'],
    ];

    // Clinic + EMR branch ids for generated string PKs. Not added to $branches
    // so getClinicDB / getEmrDB stay clinic-only (1/2/3/4/7).
    public $id_prefixes = [
        1 => 'MOB',
        10 => 'MOB',
        2 => 'OAK',
        12 => 'OAK',
        3 => 'MAC',
        13 => 'MAC',
        4 => 'CON',
        14 => 'CON',
        7 => 'MAN',
        17 => 'MAN',
    ];

    // Keep backward compat if other parts of the app use branch_db_map
    public $branch_db_map;

    public function __construct()
    {
        parent::__construct();
        // Auto-derive branch_db_map from $branches so nothing breaks
        $this->branch_db_map = array_map(fn($b) => $b['db'], $this->branches);
    }

    public function getClinicDB($branch_id): ?string
    {
        return $this->branches[$branch_id]['db'] ?? null;
    }

    public function getBranchName($branch_id): ?string
    {
        return $this->branches[$branch_id]['name'] ?? null;
    }

    public function getEmrDB($branch_id): ?string
    {
        $base = $this->getClinicDB($branch_id);
        return $base ? $base . '_emr' : null;
    }
}
