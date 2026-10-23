<?php

namespace Tests\Support\Database;

use RuntimeException;

/** Minimal dependencies for exercising the installed inventory GL/JE objects. */
class Transfer_accounting_fixture
{
    private static $objects = [
        ['FUNCTION', 'gl_account_id'],
        ['FUNCTION', 'gl_branch_id'],
        ['FUNCTION', 'gl_item_history_remarks'],
        ['PROCEDURE', 'gl_clear'],
        ['PROCEDURE', 'gl_je_from_gl'],
        ['PROCEDURE', 'gl_post_item_history'],
        ['TRIGGER', 'trg_gl_general_ledger_ai'],
        ['TRIGGER', 'trg_gl_item_history_ai'],
    ];

    public static function install()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        $master = Receipt_batch_fixture::$schemas[0];
        $source = str_replace('mytemr_master_uat', $master, file_get_contents(ROOTPATH.'sql/general_ledger_triggers_uat.sql'));
        $definitions = '';
        foreach (self::$objects as [$kind, $name]) {
            if (!preg_match('/CREATE '.$kind.' '.$name.'\b.*?END\$\$/s', $source, $match)) {
                throw new RuntimeException('Missing fixture accounting object: '.$name);
            }
            $definitions .= $match[0]."\n";
        }
        foreach (Receipt_batch_fixture::$schemas as $branch_id => $schema) {
            $pdo->exec("USE `$schema`;
                ALTER TABLE item ADD account_type_id INT NULL;
                ALTER TABLE account_type ADD code VARCHAR(50), ADD parent_id INT NULL, ADD is_deleted INT DEFAULT 0;
                ALTER TABLE item_history ADD is_deleted INT DEFAULT 0;
                CREATE TABLE refund(id INT PRIMARY KEY,transaction_id INT,branch_id INT);
                CREATE TABLE `transaction`(id INT PRIMARY KEY,transaction_no VARCHAR(30));
                CREATE TABLE branch_item_blindsheet(id INT PRIMARY KEY,branch_item_id INT,item_id INT,branch_id INT,
                    physical_count DECIMAL(13,2),prev_qty DECIMAL(13,2),is_deleted INT DEFAULT 0);
                CREATE TABLE branch_item_adjustment(id INT PRIMARY KEY,physical_count DECIMAL(13,2),prev_qty DECIMAL(13,2),is_deleted INT DEFAULT 0);
                CREATE TABLE gl_settings(branch_id INT);
                INSERT INTO gl_settings VALUES($branch_id);
                CREATE TABLE general_ledger(id INT AUTO_INCREMENT PRIMARY KEY,branch_id INT,source VARCHAR(250),source_id INT,
                    account_type_id INT,date DATE,debit DECIMAL(13,4),credit DECIMAL(13,4),remark LONGTEXT,
                    added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0);
                CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(64),remarks LONGTEXT,
                    status VARCHAR(20),total_debit DECIMAL(13,4),total_credit DECIMAL(13,4),source VARCHAR(250),source_id INT,
                    added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0);
                CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,journal_entry_id INT,account_type_id INT,
                    debit DECIMAL(13,4),credit DECIMAL(13,4),remarks LONGTEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0);");
            Receipt_batch_fixture::install("DELIMITER $$\n
                CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250),p_id INT,p_branch INT,p_remark LONGTEXT)
                    RETURNS LONGTEXT DETERMINISTIC RETURN p_remark$$
                CREATE FUNCTION gl_csr_txn_id(p_source VARCHAR(250),p_id INT) RETURNS INT DETERMINISTIC RETURN NULL$$
                CREATE PROCEDURE gl_rebuild_txn_je(IN p_id INT) BEGIN END$$\n".$definitions."\nDELIMITER ;\n");
        }
        $pdo->exec("INSERT INTO `$master`.account_type(id,name,code) VALUES(137,'Fixture inventory','104-030'),(1109,'Fixture supplies','500-205');
            UPDATE `$master`.item SET account_type_id=137 WHERE id=1");
        $pdo->exec("USE `$master`");
    }

    public static function remove()
    {
        $pdo = Receipt_batch_fixture::$pdo;
        foreach (Receipt_batch_fixture::$schemas as $schema) {
            $pdo->exec("USE `$schema`");
            foreach (array_reverse(self::$objects) as [$kind, $name]) {
                $pdo->exec("DROP $kind IF EXISTS $name");
            }
            $pdo->exec('DROP FUNCTION IF EXISTS gl_enrich_remark; DROP FUNCTION IF EXISTS gl_csr_txn_id;
                DROP PROCEDURE IF EXISTS gl_rebuild_txn_je;
                DROP TABLE IF EXISTS general_ledger,journal_entry,journal_entry_item,gl_settings,
                    refund,`transaction`,branch_item_blindsheet,branch_item_adjustment;
                ALTER TABLE item DROP account_type_id;
                ALTER TABLE account_type DROP code,DROP parent_id,DROP is_deleted;
                ALTER TABLE item_history DROP is_deleted;
                DELETE FROM account_type WHERE id IN(137,1109)');
        }
        $pdo->exec('USE `'.Receipt_batch_fixture::$schemas[0].'`');
    }
}
