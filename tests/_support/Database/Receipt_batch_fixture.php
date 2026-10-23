<?php

namespace Tests\Support\Database;

use Config\Database;
use PDO;
use ReflectionProperty;
use RuntimeException;

class Receipt_batch_fixture
{
    public static $pdo;
    public static $schemas = [];
    private static $groups = [0 => 'master', 1 => 'mobile', 2 => 'oakridge', 3 => 'mactan',
        4 => 'consolacion', 5 => 'warehouse', 6 => 'pharmacy', 7 => 'mantlewood'];
    private static $saved_connections = [];

    public static function configure()
    {
        $socket = getenv('RECEIPT_BATCH_TEST_SOCKET');
        if (!$socket || !str_starts_with($socket, '/private/tmp/') || !is_file($socket) && !file_exists($socket)) {
            throw new RuntimeException('An explicit local disposable fixture socket is required.');
        }
        $config = config('Database');
        foreach (self::$groups as $branch_id => $group) {
            self::$schemas[$branch_id] = 'receipt_batch_test_'.$group;
            $config->$group = ['hostname' => $socket, 'port' => 3306, 'database' => self::$schemas[$branch_id],
                'username' => 'root', 'password' => '', 'DBDriver' => 'MySQLi', 'DBDebug' => false,
                'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci'];
        }
        $config->defaultGroup = 'master';
        $config->default = $config->tests = $config->master;
    }

    public static function create()
    {
        $instances = new ReflectionProperty(Database::class, 'instances');
        self::$saved_connections = $instances->getValue();
        $instances->setValue(null, []);
        self::configure();
        self::$pdo = new PDO('mysql:unix_socket='.getenv('RECEIPT_BATCH_TEST_SOCKET').';charset=utf8mb4', 'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((int) self::$pdo->query('SELECT @@skip_networking')->fetchColumn() !== 1) {
            throw new RuntimeException('The disposable fixture server must have networking disabled.');
        }
        foreach (self::$schemas as $branch_id => $schema) {
            self::guard($schema);
            self::$pdo->exec("DROP DATABASE IF EXISTS `$schema`; CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$schema`");
            self::$pdo->exec("CREATE TABLE item(id INT PRIMARY KEY,name VARCHAR(100),kind VARCHAR(40),cost DECIMAL(13,4),weighted_cost DECIMAL(13,4),is_deleted INT DEFAULT 0,brand VARCHAR(100),generic VARCHAR(100),dosage VARCHAR(100),form VARCHAR(100));
                CREATE TABLE item_conversion(id INT PRIMARY KEY,item_id INT,unit VARCHAR(20),conversion_type VARCHAR(30),qty DECIMAL(13,4),is_deleted INT DEFAULT 0);
                CREATE TABLE branch_item(id INT PRIMARY KEY,item_id INT,branch_id INT,beggining_qty DECIMAL(13,2),current_qty DECIMAL(13,2),unit INT,
                    latest_activity VARCHAR(255),latest_activity_id INT,latest_department_id INT,adjustment_type VARCHAR(255),added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0);
                CREATE TABLE item_cost(id INT PRIMARY KEY,item_id INT,branch_id INT,last_value DECIMAL(13,4),latest_total DECIMAL(13,4),current_amount DECIMAL(13,4),added_by INT,added_on DATETIME,is_deleted INT DEFAULT 0);
                CREATE TABLE item_history(id INT AUTO_INCREMENT PRIMARY KEY,branch_item_id INT,item_id INT,branch_id INT,department_id INT,beggining_qty DECIMAL(13,2),current_qty DECIMAL(13,2),unit INT,activity VARCHAR(255),activity_id INT,quantity DECIMAL(13,2),adjustment_type VARCHAR(255),added_by INT,added_on DATETIME);
                CREATE TABLE receipt_note(id INT PRIMARY KEY,branch_id INT,status VARCHAR(30),receive_date DATE,added_on DATETIME,is_deleted INT DEFAULT 0);
                CREATE TABLE receipt_note_item(id INT AUTO_INCREMENT PRIMARY KEY,receipt_note_id INT,purchase_order_id INT,purchase_order_item_id INT,account_type_id INT,cost_center_id INT,expenditure_type VARCHAR(30),item_id INT,ordered_qty DECIMAL(13,4),qty DECIMAL(13,4),unit INT,cost DECIMAL(13,4),discount DECIMAL(13,4),amount DECIMAL(13,4),price DECIMAL(13,4),total DECIMAL(13,4),vat DECIMAL(13,4),vat_type VARCHAR(30),total_value DECIMAL(13,4),remarks TEXT,added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0);
                CREATE TABLE receipt_note_item_expiry_date(id INT AUTO_INCREMENT PRIMARY KEY,receipt_note_id INT,receipt_note_item_id INT,date DATE,qty DECIMAL(13,4),qty_balance DECIMAL(13,4) NULL DEFAULT 0,added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0);
                CREATE TABLE account_type(id INT PRIMARY KEY,name VARCHAR(60));
                INSERT INTO item(id,name,kind,cost,weighted_cost,is_deleted) VALUES(1,'Fixture stock item','pharmacy',8,5,0),(2,'Fixture without inventory','pharmacy',8,5,0),(3,'Deleted fixture','pharmacy',8,5,1);
                INSERT INTO item_conversion VALUES(11,1,'box','purchase',1,0),(12,1,'piece','clinic',10,0),(13,1,'dose','usage',1000,0);
                INSERT INTO receipt_note VALUES(1,2,'approved','2026-10-02','2026-10-02 10:00:00',0),(2,2,'pending','2026-10-03','2026-10-03 10:00:00',0),(3,2,'approved','2026-10-03','2026-10-03 10:00:00',1);");
            if ($branch_id === 0) {
                self::$pdo->exec("CREATE TABLE branch(id INT PRIMARY KEY,name VARCHAR(100),is_deleted INT DEFAULT 0);
                    CREATE TABLE user(id INT PRIMARY KEY,first_name VARCHAR(40),last_name VARCHAR(40),role_id INT DEFAULT 1,is_deleted INT DEFAULT 0);
                    CREATE TABLE department(id INT PRIMARY KEY,name VARCHAR(100));
                    CREATE TABLE branch_item_transfer(id INT AUTO_INCREMENT PRIMARY KEY,branch_id_from INT,branch_id_to INT,department_id_from INT,department_id_to INT,
                        transfer_date DATE,delivery_date DATE,dispatcher VARCHAR(100),remarks TEXT,status VARCHAR(30) DEFAULT 'pending',
                        approved_by INT,approved_on DATETIME,disapproved_by INT,disapproved_on DATETIME,opened_by INT,opened_on DATETIME,received_by INT,received_on DATETIME,
                        added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0);
                    CREATE TABLE branch_item_transfer_item(id INT AUTO_INCREMENT PRIMARY KEY,branch_item_transfer_id INT,branch_item_id INT,item_id INT,receipt_note_item_expiry_date_id INT NULL,transfer_qty DECIMAL(13,4),received_qty DECIMAL(13,4) DEFAULT 0,unit INT,added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0);
                    INSERT INTO branch VALUES(1,'Mobile',0),(2,'Oakridge',0),(3,'Mactan',0),(4,'Consolacion',0),(5,'Warehouse',0),(6,'Pharmacy',0),(7,'Mantlewood',0);
                    INSERT INTO user(id,first_name,last_name) VALUES(67,'Fixture','User');");
            }
            self::install(file_get_contents(ROOTPATH.'sql/receipt_batches/install.sql'));
            self::install(file_get_contents(ROOTPATH.'sql/receipt_batches/install.sql'));
            self::install(file_get_contents(ROOTPATH.'item_history_trigger.sql'));
        }
    }

    public static function reset()
    {
        self::configure();
        foreach (Database::getConnections() as $connection) {
            if (str_starts_with($connection->getDatabase(), 'receipt_batch_test_')) {
                while ($connection->transDepth > 0) {
                    $connection->transRollback();
                }
                $connection->resetTransStatus();
            }
        }
        foreach (self::$schemas as $schema) {
            self::$pdo->exec("USE `$schema`;
                UPDATE item SET brand=CASE WHEN id=1 THEN 'Acme' WHEN id=2 THEN 'Beta' ELSE 'Deleted brand' END,
                    generic=CASE WHEN id=1 THEN 'Compound A' WHEN id=2 THEN 'Compound B' ELSE 'Deleted generic' END,
                    dosage=CASE WHEN id=1 THEN '10 mg' WHEN id=2 THEN '0' ELSE 'Deleted dosage' END,
                    form=CASE WHEN id=1 THEN 'Tablet' WHEN id=2 THEN 'Capsule' ELSE 'Deleted form' END,
                    kind='pharmacy',is_deleted=CASE WHEN id=3 THEN 1 ELSE 0 END;
                UPDATE receipt_note SET branch_id=2,status=CASE WHEN id=2 THEN 'pending' ELSE 'approved' END,
                    is_deleted=CASE WHEN id=3 THEN 1 ELSE 0 END,
                    receive_date=CASE WHEN id=1 THEN '2026-10-02' ELSE '2026-10-03' END,
                    added_on=CASE WHEN id=1 THEN '2026-10-02 10:00:00' ELSE '2026-10-03 10:00:00' END;
                DELETE FROM branch_item; DELETE FROM item_history; DELETE FROM item_cost; DELETE FROM receipt_note_item; DELETE FROM receipt_note_item_expiry_date;
                INSERT INTO branch_item(id,item_id,branch_id,beggining_qty,current_qty,unit,added_by,added_on) VALUES
                    (1,1,2,10,10,11,67,'2026-10-01'),(2,1,2,100,100,12,67,'2026-10-01'),(3,1,2,10000,10000,13,67,'2026-10-01'),
                    (4,1,3,0,0,11,67,'2026-10-01'),(5,1,3,0,0,12,67,'2026-10-01'),(6,1,3,0,0,13,67,'2026-10-01'),
                    (7,1,4,0,0,11,67,'2026-10-01'),(8,1,4,0,0,12,67,'2026-10-01'),(9,1,4,0,0,13,67,'2026-10-01');
                INSERT INTO item_cost VALUES(1,1,2,50,10,5,67,NOW(),0),(2,1,3,0,0,5,67,NOW(),0),(3,1,4,0,0,5,67,NOW(),0);
                INSERT INTO receipt_note_item(id,receipt_note_id,item_id,qty,unit,batch_no) VALUES(1,1,1,10,11,'12,13'),(2,1,1,20,12,'12'),(3,2,1,1000,11,'12'),(4,3,1,1000,11,'12');
                INSERT INTO receipt_note_item_expiry_date(id,receipt_note_id,receipt_note_item_id,date,qty,batch_no) VALUES
                    (1,1,1,'2028-01-01',3,12),(2,1,1,'2028-02-01',2,12),(3,1,1,'2028-03-01',4,13),(4,1,2,'2028-01-01',20,12),
                    (5,2,3,'2028-01-01',1000,12),(6,3,4,'2028-01-01',1000,12);
                UPDATE receipt_note_item_expiry_date SET qty_balance=qty;");
        }
        self::$pdo->exec('USE `'.self::$schemas[0].'`; DELETE FROM branch_item_transfer_item; DELETE FROM branch_item_transfer');
    }

    public static function install($source)
    {
        $delimiter = ';';
        $buffer = '';
        foreach (explode("\n", $source) as $line) {
            if (str_starts_with(trim($line), '--')) {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(\S+)/i', trim($line), $match)) {
                $delimiter = $match[1];
                continue;
            }
            $buffer .= $line."\n";
            while (($position = strpos($buffer, $delimiter)) !== false) {
                $statement = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + strlen($delimiter));
                if ($statement !== '') {
                    self::$pdo->exec($statement);
                }
            }
        }
    }

    public static function destroy()
    {
        foreach (Database::getConnections() as $connection) {
            if (str_starts_with($connection->getDatabase(), 'receipt_batch_test_')) {
                $connection->close();
            }
        }
        foreach (self::$schemas as $schema) {
            self::guard($schema);
            self::$pdo->exec("DROP DATABASE IF EXISTS `$schema`");
        }
        $instances = new ReflectionProperty(Database::class, 'instances');
        $instances->setValue(null, self::$saved_connections);
    }

    private static function guard($schema)
    {
        if (!str_starts_with($schema, 'receipt_batch_test_')) {
            throw new RuntimeException('Refusing an unguarded fixture database.');
        }
    }
}
