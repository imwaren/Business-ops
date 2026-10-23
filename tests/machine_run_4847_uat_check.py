#!/usr/bin/env python3
"""Disposable socket-only fixture for the frozen UAT machine-run repair."""
import hashlib
import importlib.util
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
MYSQL = '/Applications/XAMPP/xamppfiles/bin/mysql'
DUMP = '/Applications/XAMPP/xamppfiles/bin/mysqldump'
SOCKET = '/private/tmp/items_blindsheet_fixture/mysql.sock'
SCHEMA = 'machine_run_4847_fixture_oakridge'
MASTER = 'machine_run_4847_fixture_master'
spec = importlib.util.spec_from_file_location('repair', ROOT / 'scripts/repairs/machine_run_4847_uat.py')
repair = importlib.util.module_from_spec(spec)
spec.loader.exec_module(repair)
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1


def install(sql):
    result = subprocess.run([MYSQL, '--socket=' + SOCKET, '-u', 'root'], input=sql, text=True,
                            capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr)


def definition(source, kind, name):
    text = re.search(r'CREATE ' + kind + ' ' + name + r'\b.*?END\$\$', source, re.S).group()
    return 'DROP ' + kind + ' IF EXISTS ' + name + '$$\n' + text + '\n'


setup = f'''
DROP DATABASE IF EXISTS `{SCHEMA}`;
DROP DATABASE IF EXISTS `{MASTER}`;
CREATE DATABASE `{SCHEMA}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE `{MASTER}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `{MASTER}`;
CREATE TABLE item (id INT PRIMARY KEY,name VARCHAR(100),cost DECIMAL(13,4),weighted_cost DECIMAL(13,4),account_type_id INT,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE account_type(id INT PRIMARY KEY,code VARCHAR(50),parent_id INT,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE refund(id INT PRIMARY KEY,transaction_id INT,branch_id INT) ENGINE=InnoDB;
INSERT INTO item VALUES(4847,'Synthetic reagent',5,5,141,0);
INSERT INTO account_type VALUES(1109,'500-205',NULL,0),(137,'104-030',NULL,0);
USE `{SCHEMA}`;
CREATE TABLE item LIKE `{MASTER}`.item;
INSERT INTO item SELECT * FROM `{MASTER}`.item;
CREATE TABLE item_conversion(id INT PRIMARY KEY,item_id INT,conversion_type VARCHAR(30),qty DECIMAL(13,4),is_deleted INT DEFAULT 0) ENGINE=InnoDB;
INSERT INTO item_conversion VALUES(14489,4847,'purchase',1,0),(14490,4847,'clinic',1,0),(14491,4847,'usage',1000,0);
CREATE TABLE branch_item(id INT PRIMARY KEY,item_id INT,branch_id INT,beggining_qty DECIMAL(13,2),current_qty DECIMAL(13,2),unit INT,latest_activity VARCHAR(255),latest_activity_id INT,latest_department_id INT,adjustment_type VARCHAR(100),added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
INSERT INTO branch_item VALUES(22584,4847,2,100000,99999.95,14489,'Machine Run',0,NULL,NULL,65,'2026-10-13 11:59:52',9,'2026-10-13 14:43:43',0),(22585,4847,2,100000,99999.95,14490,'Machine Run',0,NULL,NULL,65,'2026-10-13 11:59:52',9,'2026-10-13 14:43:43',0),(22586,4847,2,100000000,99999950,14491,'Machine Run',0,NULL,NULL,65,'2026-10-13 11:59:52',9,'2026-10-13 14:43:43',0);
CREATE TABLE item_history(id INT AUTO_INCREMENT PRIMARY KEY,branch_item_id INT,item_id INT,branch_id INT,department_id INT,beggining_qty DECIMAL(13,2),current_qty DECIMAL(13,2),unit INT,activity VARCHAR(255),activity_id INT,quantity DECIMAL(13,2),adjustment_type VARCHAR(100),added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
INSERT INTO item_history VALUES(23367,22584,4847,2,NULL,100000,100000,14489,'Beginning Inventory',4847,100000,NULL,65,'2026-10-13 11:59:52',NULL,NULL,0),(23368,22585,4847,2,NULL,100000,100000,14490,'Beginning Inventory',4847,100000,NULL,65,'2026-10-13 11:59:52',NULL,NULL,0),(23369,22586,4847,2,NULL,100000000,100000000,14491,'Beginning Inventory',4847,100000000,NULL,65,'2026-10-13 11:59:52',NULL,NULL,0),(23391,22584,4847,2,NULL,100000,99999.95,14489,'Machine Run',0,0,NULL,9,'2026-10-13 14:43:43',NULL,NULL,0),(23392,22585,4847,2,NULL,100000,99999.95,14490,'Machine Run',0,0,NULL,9,'2026-10-13 14:43:43',NULL,NULL,0),(23393,22586,4847,2,NULL,100000000,99999950,14491,'Machine Run',0,50,NULL,9,'2026-10-13 14:43:43',NULL,NULL,0);
CREATE TABLE item_cost(id INT PRIMARY KEY,item_id INT,branch_id INT,last_value DECIMAL(13,4),latest_total DECIMAL(13,4),current_amount DECIMAL(13,4),added_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
INSERT INTO item_cost VALUES(17921,4847,2,500000,100000,5,'2026-10-13 11:59:53',0);
CREATE TABLE branch_item_adjustment(id INT PRIMARY KEY,physical_count DECIMAL(13,2),prev_qty DECIMAL(13,2),is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE branch_item_blindsheet(id INT PRIMARY KEY,branch_item_id INT,item_id INT,branch_id INT,physical_count DECIMAL(13,2),prev_qty DECIMAL(13,2),is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE `transaction`(id INT PRIMARY KEY,transaction_no VARCHAR(30)) ENGINE=InnoDB;
CREATE TABLE general_ledger(id INT AUTO_INCREMENT PRIMARY KEY,branch_id INT,source VARCHAR(250),source_id INT,account_type_id INT,date DATE,debit DECIMAL(13,4),credit DECIMAL(13,4),remark LONGTEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(64),remarks LONGTEXT,status VARCHAR(20),total_debit DECIMAL(13,4),total_credit DECIMAL(13,4),source VARCHAR(250),source_id INT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,journal_entry_id INT,account_type_id INT,debit DECIMAL(13,4),credit DECIMAL(13,4),remarks LONGTEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE FUNCTION gl_branch_id() RETURNS INT DETERMINISTIC RETURN 2;
CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250),p_id INT,p_branch INT,p_remark LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN p_remark;
CREATE FUNCTION gl_csr_txn_id(p_source VARCHAR(250),p_id INT) RETURNS INT DETERMINISTIC RETURN NULL;
CREATE PROCEDURE gl_rebuild_txn_je(IN p_id INT) SELECT p_id;
'''
source = (ROOT / 'sql/general_ledger_triggers_uat.sql').read_text().replace('mytemr_master_uat', MASTER)
objects = [('FUNCTION', 'gl_account_id'), ('PROCEDURE', 'gl_clear'), ('PROCEDURE', 'gl_je_from_gl'),
           ('FUNCTION', 'gl_item_history_remarks'), ('PROCEDURE', 'gl_post_item_history'),
           ('TRIGGER', 'trg_gl_general_ledger_ai'), ('TRIGGER', 'trg_gl_item_history_ai'), ('TRIGGER', 'trg_gl_item_history_au')]
setup += '\nDELIMITER $$\n' + ''.join(definition(source, kind, name) for kind, name in objects) + '\nDELIMITER ;\n'
fixed = (ROOT / 'item_history_trigger.sql').read_text()
setup += fixed
install(setup)
db = repair.Database(MYSQL, SCHEMA, SOCKET)


def full_hash():
    digest = hashlib.sha256()
    for table in ['item_history', 'branch_item', 'general_ledger', 'journal_entry', 'journal_entry_item']:
        db.query('SELECT * FROM ' + table + ' ORDER BY id', digest)
    return digest.hexdigest()


def run(mode='apply', failure=False, success=True):
    output = Path(tempfile.mkdtemp(prefix='machine_run_4847_')) / 'run'
    command = ['python3', str(ROOT / 'scripts/repairs/machine_run_4847_uat.py'), mode, '--output', str(output),
               '--mysql', MYSQL, '--dump', DUMP, '--socket', SOCKET, '--schema', SCHEMA, '--master', MASTER]
    if failure:
        command.append('--inject-failure')
    result = subprocess.run(command, capture_output=True, text=True)
    check((result.returncode == 0) == success, 'Repair expected outcome: ' + result.stderr[-500:])
    return result


try:
    run('preview')
    original = full_hash()
    run(failure=True, success=False)
    check(full_hash() == original, 'Post-validation failure rolls quantities/GL/JE back atomically')
    run()
    check(repair.accounting_state(db) == 'complete', 'Clinic-only posting matches accounts/date/creator/amount')
    check(db.scalar('SELECT COUNT(*) FROM item_history WHERE id IN (23391,23392) AND quantity=.05 AND updated_by IS NULL AND updated_on IS NOT NULL') == '2', 'Fractional correction and system audit')
    check(db.scalar('SELECT quantity FROM item_history WHERE id=23393') == '50.00', 'Usage remains 50')
    after = full_hash()
    run()
    check(full_hash() == after, 'Repeat execution is a full no-op including timestamps and history')
    # Delete only synthetic financial rows to exercise corrected-history/missing-posting recovery.
    db.query('DELETE FROM journal_entry_item; DELETE FROM journal_entry; DELETE FROM general_ledger')
    run()
    check(repair.accounting_state(db) == 'complete', 'Only clinic source reposts when corrected history lacks accounting')
    db.query('UPDATE general_ledger SET debit=.30 WHERE account_type_id=1109')
    invalid = full_hash()
    run(success=False)
    check(full_hash() == invalid, 'Unexpected accounting aborts without changes')
    db.query('UPDATE general_ledger SET debit=.25 WHERE account_type_id=1109')
    db.query(f'UPDATE `{MASTER}`.item SET cost=6 WHERE id=4847')
    run(success=False)
    db.query(f'UPDATE `{MASTER}`.item SET cost=5 WHERE id=4847')
    # Exercise actual inventory history trigger and actual GL/JE hooks; rollback synthetic stock changes.
    db.query('START TRANSACTION')
    db.query("UPDATE branch_item SET current_qty=current_qty-.05 WHERE id IN (22584,22585)")
    db.query('UPDATE branch_item SET current_qty=current_qty-50 WHERE id=22586')
    check(db.scalar('SELECT COUNT(*) FROM item_history WHERE id>23393 AND unit IN (14489,14490) AND quantity=.05') == '2', 'Fixed trigger preserves fractional purchase/clinic changes')
    check(db.scalar('SELECT COUNT(*) FROM item_history WHERE id>23393 AND unit=14491 AND quantity=50') == '1', 'Usage conversion quantity remains integral')
    check(db.scalar("SELECT COUNT(*) FROM general_ledger WHERE source_id>23393 AND is_deleted=0") == '2', 'Only new clinic history posts')
    db.query('ROLLBACK')
    update_trigger = re.search(r'CREATE TRIGGER `trg_branch_item_update`.*?END\$\$', fixed, re.S).group()
    install('USE `' + SCHEMA + '`;\nDELIMITER $$\nDROP TRIGGER trg_branch_item_update$$\n'
            + update_trigger.replace('qty_diff DECIMAL(13,2)', 'qty_diff INT') + '\nDELIMITER ;\n')
    db.query('START TRANSACTION; UPDATE branch_item SET current_qty=current_qty-.05 WHERE id=22584')
    check(db.scalar('SELECT quantity FROM item_history ORDER BY id DESC LIMIT 1') == '0.00', 'Old integer declaration reproduces original fault')
    db.query('ROLLBACK')
    print(f'PASS: {checks} isolated machine-run repair checks')
finally:
    db.close()
    install('DROP DATABASE `' + SCHEMA + '`; DROP DATABASE `' + MASTER + '`;')
