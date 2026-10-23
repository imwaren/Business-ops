<?php

namespace Tests\Support\Database;

use Config\Database;
use PDO;
use ReflectionProperty;
use RuntimeException;

class Fda_sales_fixture
{
    public static $pdo;
    public static $schemas = [];
    private static $saved_connections;
    private static $saved_config = [];
    private static $groups = [0 => 'master', 1 => 'mobile', 2 => 'oakridge', 3 => 'mactan',
        4 => 'consolacion', 7 => 'mantlewood'];

    public static function create()
    {
        $socket = getenv('FDA_SALES_TEST_SOCKET');
        if (!$socket || !str_starts_with($socket, '/private/tmp/') || !file_exists($socket)) {
            throw new RuntimeException('An explicit disposable FDA sales socket is required.');
        }
        self::$pdo = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((int) self::$pdo->query('SELECT @@skip_networking')->fetchColumn() !== 1) {
            throw new RuntimeException('FDA fixtures require a socket-only server.');
        }
        $instances = new ReflectionProperty(Database::class, 'instances');
        self::$saved_connections = $instances->getValue();
        $instances->setValue(null, []);
        $config = config('Database');
        foreach (self::$groups as $branch_id => $group) {
            self::$saved_config[$group] = $config->$group;
            self::$schemas[$branch_id] = 'fda_sales_test_'.$group;
            self::guard(self::$schemas[$branch_id]);
            self::$pdo->exec('DROP DATABASE IF EXISTS `'.self::$schemas[$branch_id].'`; CREATE DATABASE `'
                .self::$schemas[$branch_id].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $config->$group = ['hostname' => $socket, 'database' => self::$schemas[$branch_id], 'username' => 'root',
                'password' => '', 'DBDriver' => 'MySQLi', 'DBDebug' => false, 'charset' => 'utf8mb4',
                'DBCollat' => 'utf8mb4_unicode_ci'];
        }
    }

    public static function reset()
    {
        // CI resets config factories between tests; reapply every isolated connection first.
        $socket = getenv('FDA_SALES_TEST_SOCKET');
        foreach (self::$groups as $branch_id => $group) {
            config('Database')->$group = ['hostname' => $socket, 'database' => self::$schemas[$branch_id],
                'username' => 'root', 'password' => '', 'DBDriver' => 'MySQLi', 'DBDebug' => false,
                'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci'];
        }
        foreach (self::$schemas as $branch_id => $schema) {
            self::guard($schema);
            self::$pdo->exec("USE `$schema`; DROP TABLE IF EXISTS dispense_medicine_details, item, user;
                CREATE TABLE item(id INT PRIMARY KEY, name VARCHAR(100), brand VARCHAR(100), generic VARCHAR(100),
                    dosage VARCHAR(100), form VARCHAR(100), kind VARCHAR(100), is_deleted INT DEFAULT 0);
                CREATE TABLE user(id INT PRIMARY KEY, first_name VARCHAR(50), middle_name VARCHAR(50), last_name VARCHAR(50), is_deleted INT DEFAULT 0);
                CREATE TABLE dispense_medicine_details(id INT PRIMARY KEY, dispense_medicine_id INT, added_by INT, item_id INT,
                    added_on DATETIME, quantity DECIMAL(20,4), price DECIMAL(20,2), amount DECIMAL(20,2), patient_id INT,
                    pharmacy_doctor_id INT, doctor_name VARCHAR(100), patient_name VARCHAR(100), generic VARCHAR(100),
                    brand VARCHAR(100), dosage VARCHAR(100), form VARCHAR(100), note LONGTEXT, code VARCHAR(255),
                    fs INT, ps INT, updated_by INT, updated_on DATETIME, is_deleted INT DEFAULT 0);
                INSERT INTO item VALUES(10,'Drug A','Acme','Catalog A','10 mg','Tablet','pharmacy,laboratory',0),
                    (11,'Drug B','Beta','Catalog B','5 mg','Capsule','pharmacy_other',0);
                INSERT INTO user VALUES(1,'Alice','Q','Dispenser',0),(2,'Former',NULL,'User',1)");
            if ($branch_id === 0) {
                self::$pdo->exec("UPDATE user SET first_name='Master' WHERE id=1");
            } elseif ($branch_id === 2) {
                self::$pdo->exec("UPDATE user SET first_name='Bob',middle_name=NULL,last_name='Local' WHERE id=1");
            }
        }
        self::insert(1, ['id' => 1, 'patient_id' => 1, 'quantity' => '1.2500', 'price' => '2.10', 'amount' => '2.63', 'code' => '0012']);
        self::insert(1, ['id' => 2, 'patient_id' => 2, 'doctor_name' => 'Doctor Beta', 'patient_name' => 'Patient B',
            'added_on' => '2026-10-15 23:59:59', 'quantity' => '2.5000', 'price' => '3.20', 'amount' => '8.00', 'code' => '12']);
        self::insert(1, ['id' => 3, 'added_on' => '2026-10-16 00:00:00']);
        self::insert(1, ['id' => 4, 'added_by' => 2]);
        self::insert(1, ['id' => 5, 'item_id' => 11]);
        self::insert(1, ['id' => 6, 'quantity' => '100', 'price' => '100', 'amount' => '100', 'is_deleted' => 1]);
        self::insert(1, ['id' => 7, 'item_id' => 99, 'added_by' => 99]);
        self::insert(1, ['id' => 8, 'quantity' => null, 'price' => null, 'amount' => null, 'note' => ' ', 'code' => '0']);
        self::insert(2, ['id' => 1, 'quantity' => '4', 'price' => '5.50', 'amount' => '22.00']);
    }

    public static function insert(int $branch_id, array $values)
    {
        $values += ['dispense_medicine_id' => 100, 'added_by' => 1, 'item_id' => 10,
            'added_on' => '2026-10-15 08:00:00', 'quantity' => '1', 'price' => '2', 'amount' => '2',
            'patient_id' => 1, 'pharmacy_doctor_id' => 7, 'doctor_name' => 'Doctor Alpha', 'patient_name' => 'Patient A',
            'generic' => 'Snapshot A', 'brand' => 'Old brand', 'dosage' => '5 mg', 'form' => 'Saved tablet',
            'note' => 'A, "quoted" note', 'code' => 'AB123', 'fs' => 0, 'ps' => 1, 'updated_by' => null,
            'updated_on' => null, 'is_deleted' => 0];
        $columns = implode(',', array_map(static fn($column) => '`'.$column.'`', array_keys($values)));
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $query = self::$pdo->prepare('INSERT INTO `'.self::$schemas[$branch_id].'`.dispense_medicine_details ('.$columns.') VALUES ('.$placeholders.')');
        $query->execute(array_values($values));
    }

    public static function destroy()
    {
        foreach (Database::getConnections() as $connection) {
            if (str_starts_with($connection->getDatabase(), 'fda_sales_test_')) {
                $connection->close();
            }
        }
        foreach (self::$schemas as $schema) {
            self::guard($schema);
            self::$pdo->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
        }
        foreach (self::$saved_config as $group => $settings) {
            config('Database')->$group = $settings;
        }
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, self::$saved_connections);
    }

    private static function guard(string $schema)
    {
        if (!str_starts_with($schema, 'fda_sales_test_')) {
            throw new RuntimeException('Refusing an unguarded FDA fixture database.');
        }
    }
}
