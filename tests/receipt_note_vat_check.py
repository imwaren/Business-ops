#!/usr/bin/env python3
"""Isolated MariaDB receipt VAT/reference checks; never uses application credentials."""
from pathlib import Path
import os
import re
import subprocess
ROOT = Path(__file__).resolve().parents[1]
SOCKET = os.environ.get('BILLING_FIXTURE_SOCKET', '/private/tmp/billing_deposit_fixture_20261021/mysql.sock')
assert SOCKET.startswith('/private/tmp/')
CLIENT = ['/Applications/XAMPP/xamppfiles/bin/mysql', '--no-defaults', '--socket='+SOCKET, '-uroot', '--batch', '--skip-column-names', '--default-character-set=utf8mb4']
checks = 0

def sql(text, db=None, fail=False):
    result = subprocess.run(CLIENT+([db] if db else []), input='SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n'+text, text=True, capture_output=True)
    if fail:
        assert result.returncode, 'Expected prerequisite rejection'
        return result.stderr
    if result.returncode:
        raise RuntimeError(result.stderr)
    return result.stdout.strip()

def check(value, label):
    global checks
    if not value: raise AssertionError(label)
    checks += 1

def obj(source, kind, name):
    return re.search(r'CREATE '+kind+' '+name+r'\b.*?END\$\$', source, re.S).group()

def install(source, objects, db):
    sql('DELIMITER $$\n'+'\n'.join('DROP '+k+' IF EXISTS '+n+'$$\n'+obj(source,k,n) for k,n in objects)+'\nDELIMITER ;',db)

for env, suffix in [('prod',''),('uat','_uat')]:
 for master in [False,True]:
    db='receipt_vat_fixture_'+env+('_master' if master else '_clinic')
    sql(f'DROP DATABASE IF EXISTS `{db}`; CREATE DATABASE `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    try:
        sql('''CREATE TABLE account_type(id INT PRIMARY KEY,parent_id INT,code VARCHAR(40),name VARCHAR(100),status VARCHAR(20),is_deleted INT DEFAULT 0);
CREATE TABLE item(id INT PRIMARY KEY,name VARCHAR(100),account_type_id INT,vat_account_type_id INT,vat_type VARCHAR(40),is_deleted INT DEFAULT 0);
CREATE TABLE branch(id INT PRIMARY KEY,type VARCHAR(30),is_deleted INT DEFAULT 0);
INSERT INTO branch VALUES(20,'shared',0);
CREATE TABLE receipt_note(id INT PRIMARY KEY,branch_id INT,status VARCHAR(30),purchase_order_id INT,receive_date DATE,needed_date DATE,added_on DATETIME,is_deleted INT DEFAULT 0);
CREATE TABLE receipt_note_item(qty DECIMAL(13,4) DEFAULT 1,id INT PRIMARY KEY,receipt_note_id INT,item_id INT,amount DECIMAL(13,4),total DECIMAL(13,4),vat DECIMAL(13,4),vat_type VARCHAR(40),account_type_id INT,added_by INT,is_deleted INT DEFAULT 0);
CREATE TABLE general_ledger(id INT AUTO_INCREMENT PRIMARY KEY,branch_id INT,source VARCHAR(250),source_id INT,account_type_id INT,date DATE,debit DECIMAL(13,4),credit DECIMAL(13,4),remark LONGTEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0,is_migrated INT DEFAULT 0);
CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(250),remarks LONGTEXT,total_debit DECIMAL(13,4),total_credit DECIMAL(13,4),status VARCHAR(30),source VARCHAR(250),source_id INT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0,is_migrated INT DEFAULT 0);
CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,journal_entry_id INT,account_type_id INT,debit DECIMAL(13,4),credit DECIMAL(13,4),remarks LONGTEXT,type VARCHAR(40) DEFAULT 'Automated',added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0);
CREATE TABLE refund(id INT PRIMARY KEY,transaction_id INT,branch_id INT);
CREATE TABLE `transaction`(id INT PRIMARY KEY,transaction_no VARCHAR(100));
DELIMITER $$
CREATE FUNCTION gl_branch_id() RETURNS INT DETERMINISTIC RETURN 2$$
CREATE FUNCTION gl_transaction_context(x INT) RETURNS LONGTEXT DETERMINISTIC RETURN NULL$$
CREATE FUNCTION gl_transaction_patient_context(x INT) RETURNS LONGTEXT DETERMINISTIC RETURN NULL$$
CREATE FUNCTION gl_csr_txn_id(s VARCHAR(250),i INT) RETURNS INT DETERMINISTIC RETURN NULL$$
CREATE FUNCTION gl_item_history_remarks(i INT) RETURNS LONGTEXT DETERMINISTIC RETURN NULL$$
CREATE FUNCTION gl_item_history_transaction_id(i INT) RETURNS INT DETERMINISTIC RETURN NULL$$
CREATE FUNCTION gl_transaction_item_names(i INT) RETURNS LONGTEXT DETERMINISTIC RETURN NULL$$
CREATE FUNCTION gl_patient_display_name(a VARBINARY(255),b VARBINARY(255),c VARBINARY(255),d VARBINARY(50)) RETURNS LONGTEXT DETERMINISTIC RETURN NULL$$
CREATE FUNCTION gl_billing_transaction_no(b INT,t INT) RETURNS VARCHAR(100) DETERMINISTIC RETURN NULL$$
CREATE PROCEDURE gl_post_purchase_order(IN i INT) BEGIN DO 0; END$$
CREATE PROCEDURE gl_shared_post_purchase_order(IN i INT) BEGIN DO 0; END$$
CREATE PROCEDURE gl_rebuild_txn_je(IN i INT) BEGIN DO 0; END$$
DELIMITER ;''',db)
        vat_id=169 if env=='prod' else 269
        sql(f"INSERT INTO account_type VALUES ({vat_id},NULL,'106-000','INPUT VAT','active',0),(197,NULL,'500-508','Old inventory VAT','active',0),(81,NULL,'104-030','Inventory','active',0),(83,NULL,'201-110','Transitory','active',0); INSERT INTO item VALUES (1,'CBC supplies',81,197,'vat_in',0);",db)
        source=(ROOT/f'sql/general_ledger_triggers{suffix}.sql').read_text()
        source=source.replace('mytemr_master'+suffix,db)
        common=(ROOT/'sql/fragments/general_ledger_remarks_common.sql').read_text()
        install(common,[('FUNCTION','gl_remark_append')],db)
        install(source,[('FUNCTION','gl_account_id'),('FUNCTION','gl_rn_vat_type'),('FUNCTION','gl_rn_net'),('FUNCTION','gl_rn_vat_amt')],db)
        if master:
            funds=(ROOT/'sql/shared_funds/posting.sql').read_text()
            install(funds,[('FUNCTION','gl_master_apv_context'),('PROCEDURE','gl_shared_clear'),('PROCEDURE','gl_shared_je')],db)
            procurement=(ROOT/'sql/shared_procurement/posting.sql').read_text()
            install(procurement,[('PROCEDURE','gl_shared_post_receipt_note'),('TRIGGER','trg_gl_shared_receipt_note_ai'),('TRIGGER','trg_gl_shared_receipt_note_au'),('TRIGGER','trg_gl_shared_receipt_note_item_ai'),('TRIGGER','trg_gl_shared_receipt_note_item_au')],db)
        else:
            install(source,[('FUNCTION','gl_receipt_note_item_names'),('FUNCTION','gl_remark_context'),('FUNCTION','gl_enrich_remark'),('PROCEDURE','gl_clear'),('PROCEDURE','gl_je_from_gl'),('PROCEDURE','gl_post_receipt_note'),('TRIGGER','trg_gl_general_ledger_bi'),('TRIGGER','trg_gl_general_ledger_ai'),('TRIGGER','trg_gl_receipt_note_ai'),('TRIGGER','trg_gl_receipt_note_au'),('TRIGGER','trg_gl_receipt_note_item_ai'),('TRIGGER','trg_gl_receipt_note_item_au')],db)
        installer=(ROOT/f'sql/receipt_note_vat/install_{env}{"_master" if master else ""}.sql').read_text()
        # Preserve installer validation while pointing its reviewed target allowlist at fixtures.
        installer=installer.replace('mytemr_master'+suffix,db)
        installer=re.sub(r"'mytemr_[^']+'",repr(db),installer)
        sql(installer,db)
        sql("INSERT INTO general_ledger(source,source_id,debit,credit,remark,is_migrated) VALUES ('journal_entry',999,5,0,'Unrelated migrated history',1); INSERT INTO journal_entry(source,source_id,remarks,is_migrated) VALUES ('manual_fixture',999,'Manual journal history',0); UPDATE general_ledger SET is_deleted=1 WHERE source_id=999; UPDATE journal_entry SET is_deleted=1 WHERE source_id=999;",db)
        history=sql("SELECT source,source_id,remark,is_deleted,is_migrated FROM general_ledger WHERE source_id=999",db)
        sql(f"INSERT INTO receipt_note VALUES (10,20,'pending',NULL,'2026-10-22',NULL,'2026-10-22',0); INSERT INTO receipt_note_item(id,receipt_note_id,item_id,amount,total,vat,vat_type,account_type_id,added_by,is_deleted) VALUES (1,10,1,1120,1120,120,'vat_in',81,67,0),(2,10,1,1000,1000,120,'vat_ex',81,67,0),(3,10,1,500,500,0,'non_vat',81,67,0);",db)
        check(sql('SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0',db)=='0','Pending receipt does not post')
        sql("UPDATE receipt_note SET status='approved' WHERE id=10",db)
        check(sql(f'SELECT COUNT(*) FROM general_ledger WHERE account_type_id={vat_id} AND debit=120 AND is_deleted=0',db)=='2','VAT ignores old item mapping and uses code 106-000')
        check(sql('SELECT COUNT(*) FROM general_ledger WHERE account_type_id=197 AND is_deleted=0',db)=='0','No old VAT account postings')
        check(sql('SELECT SUM(debit)=SUM(credit) AND SUM(debit)=2740 FROM general_ledger WHERE is_deleted=0',db)=='1','Inclusive/exclusive/non-VAT amounts balance')
        check(sql("SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0 AND remark NOT LIKE '%receipt_note #10%'",db)=='0','GL includes parent receipt')
        check(sql("SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0 AND (CHAR_LENGTH(remark)-CHAR_LENGTH(REPLACE(remark,'receipt_note #10','')))/CHAR_LENGTH('receipt_note #10')<>1",db)=='0','Parent reference occurs once per GL remark')
        check(sql("SELECT COUNT(*) FROM journal_entry WHERE is_deleted=0 AND remarks NOT LIKE '%receipt_note #10%'",db)=='0','JE headers include parent receipt')
        check(sql("SELECT COUNT(*) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE i.is_deleted=0 AND j.is_deleted=0 AND i.remarks NOT LIKE '%receipt_note #10%'",db)=='0','JE items include parent receipt')
        check(sql('SELECT COUNT(*) FROM journal_entry WHERE is_deleted=0 AND total_debit=total_credit',db)=='3','One balanced JE per receipt item')
        expected=0 if master else 2
        check(sql(f'SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0 AND branch_id<>{expected}',db)=='0','Attribution preserved')
        before=sql('SELECT source_id,account_type_id,debit,credit,remark FROM general_ledger WHERE is_deleted=0 ORDER BY source_id,account_type_id',db)
        sql(f'CALL {"gl_shared_post_receipt_note" if master else "gl_post_receipt_note"}(10);',db)
        check(sql('SELECT source_id,account_type_id,debit,credit,remark FROM general_ledger WHERE is_deleted=0 ORDER BY source_id,account_type_id',db)==before,'Repeated posting preserves amounts and references')
        check(sql('SELECT COUNT(*) FROM journal_entry WHERE is_deleted=0',db)=='3','Repeated posting keeps journal count')
        sql("UPDATE receipt_note SET is_deleted=1 WHERE id=10",db)
        check(sql('SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0',db)=='0','Soft deletion retires receipt posting')
        # Reproduce RN30/item24: VAT is already extended, amount is per unit.
        sql("INSERT INTO receipt_note VALUES (30,20,'pending',NULL,'2026-10-23',NULL,'2026-10-23',0); INSERT INTO receipt_note_item(qty,id,receipt_note_id,item_id,amount,total,vat,vat_type,account_type_id,added_by,is_deleted) VALUES(10,24,30,1,250,2232.14,267.86,'vat_in',81,67,0); UPDATE receipt_note SET status='approved' WHERE id=30;",db)
        check(sql(f"SELECT COUNT(*) FROM general_ledger WHERE source_id=24 AND is_deleted=0 AND ((account_type_id=81 AND debit=2232.14 AND credit=0) OR (account_type_id={vat_id} AND debit=267.86 AND credit=0) OR (account_type_id=83 AND debit=0 AND credit=2500))",db)=='3','RN30 unit times qty and line VAT reproduce exact expected amounts')
        check(sql("SELECT COUNT(*) FROM journal_entry WHERE source_id=24 AND is_deleted=0 AND total_debit=2500 AND total_credit=2500",db)=='1','RN30 single balanced JE header')
        check(sql("SELECT COUNT(*) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source_id=24 AND j.is_deleted=0 AND i.is_deleted=0",db)=='3','RN30 matching journal items')
        sql("UPDATE receipt_note_item SET qty=4,vat=107.14 WHERE id=24",db)
        check(sql("SELECT SUM(debit)=1000 AND SUM(credit)=1000 FROM general_ledger WHERE source_id=24 AND is_deleted=0",db)=='1','Quantity edit rebuilds extended amount via trigger')
        sql(f"CALL {'gl_shared_post_receipt_note' if master else 'gl_post_receipt_note'}(30)",db)
        check(sql("SELECT COUNT(*) FROM journal_entry WHERE source_id=24 AND is_deleted=0",db)=='1','Quantity repost retains one active JE')
        for cid,qty,amount,total,vat,kind,gross in [(40,'2.5','100','250','30','vat_ex','280'),(41,'1','112','100','12','vat_in','112'),(42,'0','100','100','0','non_vat','0'),(43,'NULL','100','100','0','non_vat','0'),(44,'10','NULL','1120','120','vat_in','1120'),(45,'3','100','300','0','non_vat','300')]:
            sql(f"INSERT INTO receipt_note_item(qty,id,receipt_note_id,item_id,amount,total,vat,vat_type,account_type_id,added_by,is_deleted) VALUES({qty},{cid},30,1,{amount},{total},{vat},'{kind}',81,67,0)",db)
            check(sql(f"SELECT COALESCE(SUM(debit),0)={gross} AND COALESCE(SUM(credit),0)={gross} FROM general_ledger WHERE source_id={cid} AND is_deleted=0",db)=='1','Extended amount/fallback/zero quantity case '+str(cid))
        check(sql("SELECT COUNT(*) FROM journal_entry WHERE is_deleted=0 AND total_debit<>total_credit",db)=='0','All extended receipts remain balanced')
        sql("UPDATE receipt_note SET is_deleted=1 WHERE id=30",db)
        check(sql("SELECT COUNT(*) FROM general_ledger WHERE is_deleted=0",db)=='0','Extended receipt deletion retires only its postings')
        check(sql("SELECT source,source_id,remark,is_deleted,is_migrated FROM general_ledger WHERE source_id=999",db)==history,'Unrelated migrated history unchanged')
        check(sql("SELECT remarks FROM journal_entry WHERE source_id=999",db)=='Manual journal history','Unrelated manual journal unchanged')
        sql("UPDATE account_type SET status='inactive' WHERE code='106-000'",db)
        check('INPUT VAT' in sql(installer,db,fail=True),'Scoped installer rejects inactive mapping')
    finally:
        sql(f'DROP DATABASE `{db}`;')
print(f'PASS {checks} receipt VAT/reference checks across clinic/shared prod/UAT')
