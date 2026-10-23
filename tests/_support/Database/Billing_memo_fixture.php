<?php

namespace Tests\Support\Database;

use Config\Database;
use PDO;
use RuntimeException;

class Billing_memo_fixture
{
    public static $pdo;
    public static $schema;
    private static $connections = [];

    public static function connect_existing(string $schema)
    {
        $port = getenv('BILLING_MEMO_TEST_PORT');
        if (!$port || !ctype_digit($port) || !preg_match('/^billing_memo_test_[0-9]+$/D', $schema)) {
            throw new RuntimeException('An explicit disposable billing memo fixture is required.');
        }
        self::$pdo = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (self::$pdo->query('SELECT @@hostname')->fetchColumn() !== 'cpci-memo-test') {
            throw new RuntimeException('Refusing a database outside the disposable memo container.');
        }
        self::$schema = $schema;
    }

    public static function create()
    {
        self::connect_existing('billing_memo_test_'.getmypid());
        self::$pdo->exec('CREATE DATABASE `'.self::$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public static function connection($debug = false)
    {
        $db = Database::connect([
            'hostname' => '127.0.0.1', 'port' => (int) getenv('BILLING_MEMO_TEST_PORT'),
            'database' => self::$schema, 'username' => 'root', 'password' => '', 'DBDriver' => 'MySQLi',
            'DBPrefix' => '', 'DBDebug' => $debug, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci',
        ], false);
        $db->query('SET SESSION innodb_lock_wait_timeout=10');
        self::$connections[] = $db;
        return $db;
    }

    public static function reset()
    {
        foreach (self::$connections as $db) {
            $db->close();
        }
        self::$connections = [];
        self::$pdo->exec('USE `'.self::$schema.'`');
        self::$pdo->exec('DROP TABLE IF EXISTS credit_memo, debit_memo, billing, company, branch, account_type, user, fixture_ledger, fixture_journal, fixture_journal_item');
        self::$pdo->exec("CREATE TABLE billing (
            id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NULL, charge_to INT NULL,
            due_date DATE NULL, date_from DATE NULL, date_to DATE NULL,
            grand_total DECIMAL(13,2) NOT NULL DEFAULT 0, balance DECIMAL(13,2) NOT NULL DEFAULT 0,
            credit_memo_total DECIMAL(13,2) NOT NULL DEFAULT 0, debit_memo_total DECIMAL(13,2) NOT NULL DEFAULT 0,
            status VARCHAR(32) DEFAULT 'approved', approved_by INT NULL, approved_on DATETIME NULL,
            invoice_id INT NULL, invoice_no VARCHAR(100) NULL, invoice_date DATE NULL, invoice_status VARCHAR(30) NULL,
            is_migrated INT DEFAULT 0, is_for_cancellation INT DEFAULT 0,
            added_by INT NULL, added_on DATETIME NULL, updated_by INT NULL, updated_on DATETIME NULL, is_deleted INT DEFAULT 0
        ) ENGINE=InnoDB;
        CREATE TABLE company(id INT PRIMARY KEY, name VARCHAR(100), is_deleted INT DEFAULT 0);
        CREATE TABLE branch(id INT PRIMARY KEY, name VARCHAR(100));
        CREATE TABLE account_type(id INT PRIMARY KEY, name VARCHAR(100), code VARCHAR(30));
        CREATE TABLE user(id INT PRIMARY KEY, first_name VARCHAR(100), middle_name VARCHAR(100), last_name VARCHAR(100));
        CREATE TABLE fixture_ledger(id INT PRIMARY KEY AUTO_INCREMENT, source VARCHAR(30), source_id INT, debit DECIMAL(13,2), credit DECIMAL(13,2)) ENGINE=InnoDB;
        CREATE TABLE fixture_journal(id INT PRIMARY KEY AUTO_INCREMENT, source VARCHAR(30), source_id INT, amount DECIMAL(13,2)) ENGINE=InnoDB;
        CREATE TABLE fixture_journal_item(id INT PRIMARY KEY AUTO_INCREMENT, source VARCHAR(30), source_id INT, debit DECIMAL(13,2), credit DECIMAL(13,2)) ENGINE=InnoDB;
        INSERT INTO billing(id, grand_total, balance, invoice_no, due_date, date_from, date_to) VALUES
            (1,1000,800,'INV-1','2026-10-30','2026-10-01','2026-10-31'),
            (2,500,500,'INV-2','2026-10-31','2026-10-01','2026-10-31');
        INSERT INTO branch VALUES(2,'Fixture Oakridge'),(4,'Fixture Consolacion');
        INSERT INTO account_type VALUES(1,'Fixture debit','101'),(2,'Fixture credit','102');
        INSERT INTO user VALUES(7,'Fixture','Q','Approver')");
        foreach (['credit_memo', 'debit_memo'] as $table) {
            self::$pdo->exec("CREATE TABLE $table (
                id INT PRIMARY KEY AUTO_INCREMENT, billing_id INT, branch_id INT DEFAULT 2,
                control_no VARCHAR(100), debit_account_type_id INT DEFAULT 1, credit_account_type_id INT DEFAULT 2,
                date_issued DATE DEFAULT '2026-10-19', reason TEXT NULL, amount DECIMAL(13,2), remarks TEXT NULL,
                status VARCHAR(30) DEFAULT 'pending', approved_by INT NULL, approved_on DATETIME NULL,
                disapproved_by INT NULL, disapproved_on DATETIME NULL, added_by INT NULL, added_on DATETIME NULL,
                updated_by INT NULL, updated_on DATETIME NULL, is_deleted INT DEFAULT 0,
                INDEX(billing_id)
            ) ENGINE=InnoDB");
            // Synthetic posting hooks verify that rollback includes side effects of memo approval.
            self::$pdo->exec("CREATE TRIGGER fixture_{$table}_posting AFTER UPDATE ON $table FOR EACH ROW
                BEGIN
                    IF NEW.status='approved' AND OLD.status='pending' THEN
                        INSERT INTO fixture_ledger(source, source_id, debit, credit) VALUES
                            ('$table',NEW.id,NEW.amount,0),('$table',NEW.id,0,NEW.amount);
                        INSERT INTO fixture_journal(source, source_id, amount) VALUES('$table',NEW.id,NEW.amount);
                        INSERT INTO fixture_journal_item(source, source_id, debit, credit) VALUES
                            ('$table',NEW.id,NEW.amount,0),('$table',NEW.id,0,NEW.amount);
                    END IF;
                END");
        }
        self::memo('credit_memo', ['id' => 1, 'billing_id' => 1, 'amount' => '100.25']);
        self::memo('debit_memo', ['id' => 1, 'billing_id' => 1, 'amount' => '50.75']);
    }

    public static function memo(string $table, array $values)
    {
        if (!in_array($table, ['credit_memo', 'debit_memo'], true)) {
            throw new RuntimeException('Invalid fixture table.');
        }
        $values += ['billing_id' => 1, 'control_no' => 'FIXTURE', 'amount' => '1.00'];
        $columns = implode(',', array_keys($values));
        $binds = implode(',', array_fill(0, count($values), '?'));
        $query = self::$pdo->prepare('INSERT INTO `'.self::$schema.'`.'.$table.' ('.$columns.') VALUES ('.$binds.')');
        $query->execute(array_values($values));
    }

    public static function destroy()
    {
        foreach (self::$connections as $db) {
            $db->close();
        }
        self::$connections = [];
        if (preg_match('/^billing_memo_test_[0-9]+$/D', self::$schema)) {
            self::$pdo->exec('DROP DATABASE `'.self::$schema.'`');
        }
    }
}
