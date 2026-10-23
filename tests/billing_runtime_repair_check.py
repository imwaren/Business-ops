"""Execute the incomplete deployed baseline and repaired routines in local MariaDB only."""
import os,re,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
socket=os.environ.get('BILLING_FIXTURE_SOCKET','/private/tmp/billing_deposit_fixture_20261021/mysql.sock')
assert socket.startswith('/private/tmp/'), 'Only a disposable local socket is supported'
client=['/Applications/XAMPP/xamppfiles/bin/mysql','--no-defaults','--socket='+socket,'-u','root','-N','-B']
db='billing_runtime_fixture';checks=0

def sql(statement,selected=db):
 result=subprocess.run(client+([selected] if selected else []),input=statement,text=True,capture_output=True)
 if result.returncode:raise RuntimeError(result.stderr)
 return result.stdout.strip()
def check(value,message):
 global checks
 if not value:raise AssertionError(message)
 checks+=1
def routine(source,kind,name):return re.search(r'DROP '+kind+' IF EXISTS '+name+r'\$\$.*?END\$\$',source,re.S)[0]
def patch(text):return 'DELIMITER $$\n'+text+'\nDELIMITER ;'
def fails(statement,expected):
 try:sql(statement)
 except RuntimeError as e:check(expected in str(e),'expected failure '+expected);return
 raise AssertionError('Expected SQL failure: '+expected)
def counts():return sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;')
source=(root/'sql/general_ledger_triggers_master.sql').read_text()
shared=(root/'sql/shared_funds/posting.sql').read_text()
fixture=(root/'tests/billing_ar_movement_check.py').read_text()
sql('DROP DATABASE IF EXISTS '+db+'; CREATE DATABASE '+db+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',None)
try:
 sql(re.search(r"sql\('''(DROP TABLE IF EXISTS .*?)'''\)",fixture,re.S)[1])
 sql('ALTER TABLE journal_entry_item ADD COLUMN type VARCHAR(30) DEFAULT \'Automated\'; CREATE TABLE branch(id INT PRIMARY KEY,type VARCHAR(30),is_deleted INT DEFAULT 0); INSERT INTO branch VALUES(20,\'shared\',0),(2,\'clinic\',0); CREATE TABLE direct_payment(id INT PRIMARY KEY,cost_center_id INT,status VARCHAR(30),is_deleted INT DEFAULT 0); CREATE TABLE apv_accounting_cutover(id INT PRIMARY KEY,legacy_max_direct_payment_id INT); INSERT INTO apv_accounting_cutover VALUES(1,10);')
 sql("CREATE FUNCTION gl_enrich_remark(a VARCHAR(250),b INT,c INT,d LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN d;")
 for kind,name in [('FUNCTION','gl_remark_append'),('FUNCTION','gl_account_id'),('FUNCTION','gl_billing_payer_label'),('FUNCTION','gl_billing_mop_code'),('FUNCTION','gl_billing_transaction_no'),('PROCEDURE','gl_clear'),('PROCEDURE','gl_je_from_gl'),('PROCEDURE','gl_je_rebuild'),('PROCEDURE','gl_clear_billing_movement'),('PROCEDURE','gl_post_billing_entry'),('PROCEDURE','gl_post_billing')]:
  sql(patch(routine(source,kind,name)))
 for name in ['gl_shared_clear','gl_shared_post_direct_payment']:sql(patch(routine(shared,'PROCEDURE',name)))
 # Exact old eligibility expression from the incomplete release, before branch-zero fix.
 old=routine(source,'PROCEDURE','gl_post_billing_payment').replace('be.branch_id >= 0','IFNULL(be.branch_id, 0) > 0')
 sql(patch(routine(source,'FUNCTION','gl_billing_payment_journal_remark')+'\n'+old))
 sql(patch("DROP PROCEDURE IF EXISTS gl_post_direct_payment$$\nCREATE PROCEDURE gl_post_direct_payment(IN p_id INT) BEGIN DECLARE b INT; SET b=gl_branch_id(); END$$"))
 for name in ['trg_gl_billing_entry_ai','trg_gl_billing_entry_au','trg_gl_billing_payment_ai','trg_gl_billing_payment_au','trg_gl_billing_payment_entry_ai','trg_gl_billing_payment_entry_au','trg_gl_billing_payment_entry_ad']:
  sql(patch(re.search(r'DROP TRIGGER IF EXISTS '+name+r'\$\$.*?END\$\$',source,re.S)[0]))
 sql("INSERT INTO billing VALUES(1,2048,'approved',0,'2026-10-22');")
 fails("INSERT INTO billing_entry VALUES(1,1,0,0,550,'pending',0,1,'2026-10-22');",'gl_journal_base_remark')
 fails('CALL gl_post_direct_payment(1);','gl_branch_id')
 # Establish the allocation baseline while leaving the missing helper absent.
 sql('DROP TRIGGER trg_gl_billing_entry_ai;')
 sql("INSERT INTO billing_entry VALUES(1,1,0,0,550,'pending',0,1,'2026-10-22'); INSERT INTO billing_payment(id,account_type_id,mop,paid_date,grand_total,wht_amount,is_deleted,is_unidentified,added_by) VALUES(1,100,'cash','2026-10-22',550,0,0,0,1); INSERT INTO billing_payment_entry VALUES(1,1,1,550,0);")
 check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_payment'")=='0','old deployed branch filter reproduces missing payment')
 before=counts()
 installer=(root/'sql/billing_runtime_repair/install.sql').read_text().replace("'mytemr_master','mytemr_master_uat'","'billing_runtime_fixture'")
 sql(installer);sql(installer)
 check(before==counts(),'definition-only installation does not repair historical rows')
 check(sql("SELECT gl_journal_base_remark('billing_entry',1,'probe')")=='probe','real helper executes')
 sql(patch(re.search(r'DROP TRIGGER IF EXISTS trg_gl_billing_entry_ai\$\$.*?END\$\$',source,re.S)[0]))
 sql("INSERT INTO billing_entry VALUES(2,1,0,0,450,'pending',0,1,'2026-10-22');")
 check(sql("SELECT SUM(debit),SUM(credit) FROM general_ledger WHERE source='billing_entry' AND source_id=2 AND is_deleted=0")=='450.0000\t450.0000','billing creation works after repair')
 sql("INSERT INTO billing_payment(id,account_type_id,mop,paid_date,grand_total,wht_amount,is_deleted,is_unidentified,added_by) VALUES(2,100,'cash','2026-10-22',450,0,0,0,1); INSERT INTO billing_payment_entry VALUES(2,2,2,450,0);")
 check(sql("SELECT COUNT(*),SUM(debit),SUM(credit),MIN(branch_id),MAX(branch_id) FROM general_ledger WHERE source='billing_payment' AND source_id=2 AND is_deleted=0")=='2\t450.0000\t450.0000\t0\t0','header then master allocation posts balanced GL')
 check(sql("SELECT COUNT(*),MIN(cost_center_id),SUM(total_debit),SUM(total_credit) FROM journal_entry WHERE source='billing_payment' AND source_id=2 AND is_deleted=0")=='1\t0\t450.0000\t450.0000','one master automated JE')
 check(sql("SELECT COUNT(*),SUM(i.debit),SUM(i.credit) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source='billing_payment' AND j.source_id=2 AND j.is_deleted=0 AND i.is_deleted=0")=='2\t450.0000\t450.0000','matching JE items')
 sql('CALL gl_post_billing_payment(2); CALL gl_post_direct_payment(999); INSERT INTO direct_payment VALUES(11,20,\'approved\',0); CALL gl_post_direct_payment(11); CALL gl_post_direct_payment(11);')
 check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='billing_payment' AND source_id=2 AND is_deleted=0")=='1','repeated posting retains one active JE without recursion')
 check(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gl_settings'")=='0','master direct-payment dispatch requires no gl_settings')
 check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_payment' AND source_id=1")=='0','old missing collection deliberately remains untouched')
 check(sql("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_branch_id'")=='0','legacy missing helper is unnecessary after dispatch repair')
 print(f'PASS {checks} incomplete-baseline runtime repair checks')
finally:sql('DROP DATABASE IF EXISTS '+db,None)
