#!/usr/bin/env python3
"""Socket-only synthetic master fixtures. Never uses configured application credentials."""
import hashlib
import importlib.util
from pathlib import Path
import re
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
MYSQL = '/Applications/XAMPP/xamppfiles/bin/mysql'
SOCKET = '/private/tmp/items_blindsheet_fixture/mysql.sock'
spec = importlib.util.spec_from_file_location('memo_deploy', ROOT / 'scripts/deploy_memo_journal_uat.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1


def install(schema, sql):
    result = subprocess.run([MYSQL, '--socket=' + SOCKET, '-u', 'root', '--default-character-set=utf8mb4'] + ([schema] if schema else []),
                            input='SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n' + sql, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr)


def extract(source, kind, name):
    return re.search(r'CREATE ' + kind + ' ' + name + r'\b.*?END\$\$', source, re.S).group()


def objects_sql(source, objects):
    return '\nDELIMITER $$\n' + '\n'.join('DROP ' + kind + ' IF EXISTS ' + name + '$$\n' + extract(source, kind, name)
                                          for kind, name in objects) + '\nDELIMITER ;\n'


setup = '''
CREATE TABLE billing(id INT PRIMARY KEY,invoice_no VARCHAR(64)) ENGINE=InnoDB;
CREATE TABLE billing_entry(id INT PRIMARY KEY,billing_id INT) ENGINE=InnoDB;
CREATE TABLE credit_memo(id INT PRIMARY KEY,control_no VARCHAR(255),billing_id INT,branch_id INT,debit_account_type_id INT,credit_account_type_id INT,date_issued DATE,amount DECIMAL(13,2),status VARCHAR(20),is_deleted INT DEFAULT 0,added_by INT,added_on DATETIME) ENGINE=InnoDB;
CREATE TABLE debit_memo LIKE credit_memo;
CREATE TABLE general_ledger(id INT AUTO_INCREMENT PRIMARY KEY,branch_id INT,source VARCHAR(250),source_id INT,account_type_id INT,date DATE,debit DECIMAL(13,4),credit DECIMAL(13,4),remark LONGTEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(64),remarks LONGTEXT,status VARCHAR(20),total_debit DECIMAL(13,4),total_credit DECIMAL(13,4),source VARCHAR(250),source_id INT,added_by INT,added_on DATETIME,updated_by INT,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,journal_entry_id INT,account_type_id INT,debit DECIMAL(13,4),credit DECIMAL(13,4),remarks LONGTEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0) ENGINE=InnoDB;
CREATE FUNCTION gl_account_id(p_code VARCHAR(255)) RETURNS INT DETERMINISTIC RETURN 57;
CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250),p_id INT,p_branch INT,p_base LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN CONCAT(p_base,' | Synthetic transaction / Patient context');
INSERT INTO billing VALUES(2,'C-PK0008'),(3,NULL),(5,'   '),(6,'Invoice-é漢');
INSERT INTO credit_memo VALUES(4,'CM2120261014000002',2,4,261,57,'2026-10-14',600,'approved',0,176,'2026-10-14 10:08:25');
INSERT INTO general_ledger VALUES(154798,4,'credit_memo',4,261,'2026-10-14',600,0,'Credit memo #CM2120261014000002',176,'2026-10-14 10:08:54',NULL,0),(154799,4,'credit_memo',4,57,'2026-10-14',0,600,'Credit memo AR #CM2120261014000002',176,'2026-10-14 10:08:54',NULL,0);
INSERT INTO journal_entry VALUES(76061,4,'2026-10-14','credit_memo','credit_memo #4 | Existing synthetic context é漢','approved',600,600,'credit_memo',4,176,'2026-10-14 10:08:54',NULL,NULL,0);
INSERT INTO journal_entry_item VALUES(232152,76061,261,600,0,'Credit memo #CM2120261014000002',176,'2026-10-14 10:08:54',NULL,0),(232153,76061,57,0,600,'Credit memo AR #CM2120261014000002',176,'2026-10-14 10:08:54',NULL,0);
'''
new_objects = [('FUNCTION', 'gl_journal_base_remark'), ('PROCEDURE', 'gl_je_from_gl'), ('PROCEDURE', 'gl_je_rebuild')]
poster_objects = [('PROCEDURE', 'gl_clear'), ('PROCEDURE', 'gl_post_credit_memo'), ('PROCEDURE', 'gl_post_debit_memo'),
                  ('TRIGGER', 'trg_gl_credit_memo_ai'), ('TRIGGER', 'trg_gl_credit_memo_au'), ('TRIGGER', 'trg_gl_debit_memo_ai'), ('TRIGGER', 'trg_gl_debit_memo_au')]
for environment, suffix in [('uat', '_uat'), ('prod', '')]:
    schema = 'memo_journal_fixture_' + environment
    check(schema.startswith('memo_journal_fixture_'), 'Fixture-only schema guard')
    install(None, 'DROP DATABASE IF EXISTS `' + schema + '`; CREATE DATABASE `' + schema + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    install(schema, setup)
    source = (ROOT / ('sql/general_ledger_triggers_master' + suffix + '.sql')).read_text()
    baseline = subprocess.check_output(['git', 'show', 'HEAD:sql/general_ledger_triggers_master' + suffix + '.sql'], cwd=ROOT, text=True)
    for kind, name in new_objects[1:]:
        old = extract(baseline, kind, name)
        reverted = extract(source, kind, name).replace("gl_journal_base_remark(p_source, p_source_id, CONCAT(p_source, ' #', p_source_id))", "CONCAT(p_source, ' #', p_source_id)")
        check(old == reverted, name + ': financial logic unchanged')
    helper = extract(source, 'FUNCTION', 'gl_journal_base_remark')
    check(helper in (ROOT / 'sql/fragments/general_ledger_remarks_master.sql').read_text(), 'Embedded/shared formatter matches')
    common = (ROOT / 'sql/fragments/general_ledger_remarks_common.sql').read_text()
    install(schema, objects_sql(common, [('FUNCTION', 'gl_remark_append')]) + objects_sql(source, new_objects + poster_objects))
    install(schema, objects_sql(source, new_objects))
    db = module.Database(schema, MYSQL, SOCKET)
    try:
        module.validate_target(db)
        before = module.fingerprint(db)
        original = db.query('SELECT * FROM journal_entry WHERE id=76061')
        original_hex = db.scalar('SELECT HEX(remarks) FROM journal_entry WHERE id=76061')
        db.query('START TRANSACTION')
        db.query("UPDATE journal_entry SET remarks=gl_journal_base_remark(source,source_id,remarks),updated_by=NULL,updated_on=NOW() WHERE id=76061")
        module.verify_search(db)
        check(module.fingerprint(db) == before, 'Only permitted header fields change')
        check(db.scalar("SELECT LOCATE('Existing synthetic context',remarks)>0 FROM journal_entry WHERE id=76061") == '1', 'Existing context retained')
        check(db.scalar("SELECT LOCATE(UNHEX('" + original_hex + "'),CAST(remarks AS BINARY))>0 FROM journal_entry WHERE id=76061") == '1', 'Binary preservation check handles Unicode without collation mixing')
        db.query('ROLLBACK')
        check(db.query('SELECT * FROM journal_entry WHERE id=76061') == original, 'Correction rollback restores original header')
        db.query("START TRANSACTION; UPDATE journal_entry SET remarks='Synthetic failing operation' WHERE id=76061")
        try:
            db.query('SELECT fixture_missing_function()')
            raise AssertionError('Expected fixture SQL failure')
        except RuntimeError:
            db.close(Path(tempfile.mkdtemp(prefix='memo_sql_failure_')))
            db = module.Database(schema, MYSQL, SOCKET)
        check(db.query('SELECT * FROM journal_entry WHERE id=76061') == original, 'SQL-client exit rolls back and fresh connection verifies recovery')
        db.query("START TRANSACTION; UPDATE journal_entry SET remarks=gl_journal_base_remark(source,source_id,remarks),updated_by=NULL,updated_on=NOW() WHERE id=76061; COMMIT")
        corrected = db.query('SELECT * FROM journal_entry WHERE id=76061')
        db.query("UPDATE journal_entry SET remarks=gl_journal_base_remark(source,source_id,remarks),updated_by=NULL,updated_on=NOW() WHERE id=76061 AND NOT (remarks <=> gl_journal_base_remark(source,source_id,remarks))")
        check(db.query('SELECT * FROM journal_entry WHERE id=76061') == corrected, 'Correction rerun leaves audit timestamps unchanged')
        check(db.scalar("SELECT gl_journal_base_remark('billing_entry',10,'untouched original')") == 'untouched original', 'Nonmemo header base unchanged')
        check(db.scalar("SELECT gl_journal_base_remark('credit_memo',999,NULL)") == 'credit_memo #999', 'Missing memo fallback')
        for memo_id, billing_id, control in [(5,3,'CM-NULL-INVOICE'),(6,5,'CM-BLANK-INVOICE'),(7,999,None),(8,None,'   '),(9,6,'CM-é漢')]:
            bill = str(billing_id) if billing_id else 'NULL'
            ctl = "'" + control + "'" if control else 'NULL'
            db.query(f"INSERT INTO credit_memo VALUES({memo_id},{ctl},{bill},4,261,57,'2026-10-14',600,'pending',0,176,NOW())")
            value = db.scalar(f"SELECT gl_journal_base_remark('credit_memo',{memo_id},NULL)")
            check('Invoice #' not in value if memo_id!=9 else 'Invoice #Invoice-é漢' in value, 'Null/blank/missing/Unicode invoice handling')
            check('Credit memo #' not in value if memo_id in (7,8) else 'Credit memo #' in value, 'Null/blank/Unicode control handling')
        for kind, memo_id, ctl in [('credit',10,'CM-FUTURE'),('debit',1,'DM-FUTURE')]:
            db.query(f"INSERT INTO {kind}_memo VALUES({memo_id},'{ctl}',2,4,261,57,'2026-10-14',123.45,'pending',0,176,NOW())")
            check(db.scalar(f"SELECT COUNT(*) FROM general_ledger WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0") == '0', 'Pending memo creates no posting')
            db.query(f"UPDATE {kind}_memo SET status='approved' WHERE id={memo_id}")
            check(db.scalar(f"SELECT COUNT(*) FROM journal_entry WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0 AND status='approved' AND total_debit=123.45 AND total_credit=123.45 AND remarks LIKE '%{ctl}%' AND remarks LIKE '%C-PK0008%' AND remarks LIKE '%Synthetic transaction / Patient context%'") == '1', 'Approval produces searchable balanced enriched journal')
            db.query(f"CALL gl_post_{kind}_memo({memo_id})")
            check(db.scalar(f"SELECT COUNT(*) FROM journal_entry WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0") == '1', 'Repeated rebuild retains one active journal')
            check(db.scalar(f"SELECT COUNT(*) FROM general_ledger WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0") == '2', 'Repeated posting retains two active GL lines')
            db.query(f"UPDATE {kind}_memo SET status='disapproved' WHERE id={memo_id}")
            check(db.scalar(f"SELECT COUNT(*) FROM journal_entry WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0") == '0', 'Disapproval retires active journal')
        # Exercise the other journal-generation procedure, preserving passed item remarks.
        db.query("CALL gl_je_from_gl('debit_memo',1,261,'2026-10-14',1,0,'Original item remarks',176,4)")
        check(db.scalar("SELECT COUNT(*) FROM journal_entry WHERE source='debit_memo' AND source_id=1 AND is_deleted=0 AND remarks LIKE '%DM-FUTURE%' AND remarks LIKE '%C-PK0008%'") == '1', 'gl_je_from_gl uses shared references')
        check(db.scalar("SELECT COUNT(*) FROM journal_entry_item WHERE is_deleted=0 AND remarks='Original item remarks'") == '1', 'gl_je_from_gl item content preserved')
    finally:
        db.close(Path(tempfile.mkdtemp(prefix='memo_fixture_')))
        install(None, 'DROP DATABASE `' + schema + '`;')
print(f'PASS: {checks} isolated memo reference checks across both master variants')
