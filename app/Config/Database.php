<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations
     * and Seeds directories.
     *
     * @var string
     */
    public $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to
     * use if no other is specified.
     *
     * @var string
     */
    public $defaultGroup = 'default';

    /**
     * The default database connection.
     *
     * @var array
     */
    public $default = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_master',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_master',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The mobile database connection.
     *
     * @var array
     */
    public $mobile = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_diagnostic_mobile',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_diagnostic_mobile',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The oakridge database connection.
     *
     * @var array
     */
    public $oakridge = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_diagnostic',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_diagnostic',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The mactan database connection.
     *
     * @var array
     */
    public $mactan = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_diagnostic_mactan',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_diagnostic_mactan',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The consolacion database connection.
     *
     * @var array
     */
    public $consolacion = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_diagnostic_conso',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_diagnostic_conso',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The mantlewood database connection.
     *
     * @var array
     */
    public $mantlewood = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_diagnostic_mantlewood',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_diagnostic_mantlewood',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The pharmacy database connection.
     *
     * @var array
     */
    public $pharmacy = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_oakridge_pharmacy',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_oakridge_pharmacy',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The warehouse database connection.
     *
     * @var array
     */
    public $warehouse = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_oakridge_warehouse',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_oakridge_warehouse',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The master database connection.
     *
     * @var array
     */
    public $master = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_master',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_master',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The report database connection.
     *
     * @var array
     */
    public $report = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_reports',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_reports',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];
    
    /**
     * The emr database connection.
     *
     * @var array
     */
    public $oakridge_emr = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_emr',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_emr',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];
    
    
        /**
     * The default database connection.
     *
     * @var array
     */
    public $mactan_emr = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_emr_mactan',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_emr_mactan',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];


        /**
     * The default database connection.
     *
     * @var array
     */
    public $consolacion_emr = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_emr_conso',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_emr_conso',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * The mantlewood emr database connection.
     *
     * @var array
     */
    public $mantlewood_emr = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_emr_mantlewood',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_emr_mantlewood',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

         /**
     * The emr database connection.
     *
     * @var array
     */
    public $mobile_emr = [
        'DSN'      => 'mysql:host=162.55.235.111;port=3306;dbname=mytemr_mobile',
        'hostname' => '162.55.235.111',
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'database' => 'mytemr_mobile',
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'development'),
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    /**
     * This database connection is used when
     * running PHPUnit database tests.
     *
     * @var array
     */
    public $tests = [
        'DSN'      => '',
        'hostname' => '127.0.0.1',
        'username' => '',
        'password' => '',
        'database' => ':memory:',
        'DBDriver' => 'SQLite3',
        'DBPrefix' => 'db_',  // Needed to ensure we're working correctly with prefixes live. DO NOT REMOVE FOR CI DEVS
        'pConnect' => false,
        'DBDebug'  => (ENVIRONMENT !== 'production'),
        'charset'  => 'utf8',
        'DBCollat' => 'utf8_general_ci',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 3306,
    ];

    public function __construct()
    {
        parent::__construct();

        $database_groups = [
            'default' => 'MASTER_DB', 'master' => 'MASTER_DB',
            'mobile' => 'MOBILE_DB', 'oakridge' => 'OAKRIDGE_DB',
            'mactan' => 'MAC_DB', 'consolacion' => 'CONSO_DB',
            'mantlewood' => 'MANTLEWOOD_DB', 'warehouse' => 'WAREHOUSE_DB',
            'pharmacy' => 'PHARMACY_DB', 'report' => 'REPORT_DB',
            'oakridge_emr' => 'OAKRIDGE_EMR_DB', 'mactan_emr' => 'MAC_EMR_DB',
            'consolacion_emr' => 'CONSO_EMR_DB', 'mantlewood_emr' => 'MANTLEWOOD_EMR_DB',
            'mobile_emr' => 'MOBILE_EMR_DB',
        ];
        foreach ($database_groups as $group => $database_key) {
            if (!isset($this->{$group}) || !is_array($this->{$group})) {
                continue;
            }
            $settings = $this->{$group};
            $prefix = 'database.' . $group . '.';
            foreach (['hostname', 'username', 'password', 'port'] as $field) {
                $settings[$field] = myt_env($prefix . $field,
                    myt_env('database.default.' . $field, $settings[$field]));
            }
            $settings['database'] = myt_env($prefix . 'database',
                myt_env($database_key, $settings['database']));
            $settings['port'] = (int) $settings['port'];
            if ($settings['DBDriver'] === 'MySQLi') {
                // Individual settings are authoritative; an old DSN must not override them.
                $settings['DSN'] = '';
            }
            $this->{$group} = $settings;
        }

        // Ensure that we always set the database group to 'tests' if
        // we are currently running an automated test suite, so that
        // we don't overwrite live data on accident.
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}
