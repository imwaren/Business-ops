"""Shared-master accounting regression. Local socket, disposable schema only."""
import os,re,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
socket=os.environ.get('BILLING_FIXTURE_SOCKET','/private/tmp/billing_deposit_fixture_20261021/mysql.sock')
assert socket.startswith('/private/tmp/'), 'Use an isolated local socket'
client=['/Applications/XAMPP/xamppfiles/bin/mysql','--no-defaults','--socket='+socket,'-u','root','--batch','--skip-column-names']
db='shared_funds_fixture';checks=0
def sql(s,selected=db):
 p=subprocess.run(client+([selected] if selected else []),input=s,text=True,capture_output=True)
 if p.returncode:raise RuntimeError(p.stderr)
 return p.stdout.strip()
def check(ok,msg):
 global checks
 if not ok:raise AssertionError(msg)
 checks+=1
sql('DROP DATABASE IF EXISTS '+db+'; CREATE DATABASE '+db+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',None)
roots=['petty_cash','cash_advance','liquidation','reimbursement','replenishment','change_fund','direct_payment','accounts_payable_voucher']
common={'id':'INT AUTO_INCREMENT PRIMARY KEY','cost_center_id':'INT','date':'DATE','due_date':'DATE','date_needed':'DATE','amount':'DECIMAL(13,4)','grand_total':'DECIMAL(13,4)','total':'DECIMAL(13,4)','status':"VARCHAR(30) DEFAULT 'pending'",'account_type_id':'INT','requested_by_id':'INT','department_id':'INT','remarks':'TEXT','added_by':'INT DEFAULT 1','added_on':'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_by':'INT','updated_on':'DATETIME','is_deleted':'TINYINT DEFAULT 0','approved_by':'INT','disapproved_by':'INT','approved_on':'DATETIME','disapproved_on':'DATETIME','liquidated_by_id':'INT','cash_advance_id':'INT','petty_cash_id':'INT','type':'VARCHAR(50)','type_id':'INT','supplier_id':'INT','supplier_type':'VARCHAR(30)','balance':'DECIMAL(13,4)','withholding_tax':'DECIMAL(13,4)','duration_from':'DATE','duration_to':'DATE'}
for t in roots:sql('CREATE TABLE '+t+' ('+','.join('`'+k+'` '+v for k,v in common.items())+') ENGINE=InnoDB;')
sql('''CREATE TABLE refund(id INT PRIMARY KEY,branch_id INT,transaction_id INT,patient_id INT,added_on DATETIME,status VARCHAR(30),amount DECIMAL(13,4),is_deleted INT DEFAULT 0);
CREATE TABLE patient(id INT PRIMARY KEY,last_name VARBINARY(255),first_name VARBINARY(255),middle_name VARBINARY(255),suffix VARBINARY(50),is_deleted INT DEFAULT 0);
CREATE FUNCTION gl_billing_transaction_no(bid INT,tid INT) RETURNS VARCHAR(100) DETERMINISTIC RETURN CAST(tid AS CHAR);
CREATE FUNCTION gl_patient_display_name(a VARBINARY(255),b VARBINARY(255),c VARBINARY(255),d VARBINARY(50)) RETURNS VARCHAR(100) DETERMINISTIC RETURN NULL;
CREATE TABLE branch(id INT PRIMARY KEY,name VARCHAR(100),type VARCHAR(30),is_deleted TINYINT DEFAULT 0);
INSERT INTO branch VALUES(20,'Finance','shared',0),(23,'IT','shared',0),(99,'Future','shared',0),(2,'Clinic','clinic',0),(100,'Deleted','shared',1);
CREATE TABLE account_type(id INT PRIMARY KEY,code VARCHAR(50),name VARCHAR(100),status VARCHAR(30),is_deleted TINYINT DEFAULT 0);
INSERT INTO account_type VALUES(13,'100-004','Cash','active',0),(57,'201-111','AP','active',0),(80,'500-000','Expense','active',0);
CREATE TABLE general_ledger(id INT AUTO_INCREMENT PRIMARY KEY,branch_id INT,source VARCHAR(250),source_id INT,account_type_id INT,date DATE,debit DECIMAL(13,4),credit DECIMAL(13,4),remark LONGTEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted TINYINT DEFAULT 0,is_migrated TINYINT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(250),remarks LONGTEXT,total_debit DECIMAL(13,4),total_credit DECIMAL(13,4),status VARCHAR(30),source VARCHAR(250),source_id INT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted TINYINT DEFAULT 0,is_migrated TINYINT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,direct_payment_id INT,journal_entry_id INT,account_type_id INT,debit DECIMAL(13,4),credit DECIMAL(13,4),remarks LONGTEXT,type VARCHAR(50) DEFAULT 'Automated',added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted TINYINT DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE accounts_payable_voucher_payment(id INT AUTO_INCREMENT PRIMARY KEY,account_type_id INT,is_deleted TINYINT DEFAULT 0);
CREATE TABLE accounts_payable_voucher_payment_detail(id INT AUTO_INCREMENT PRIMARY KEY,accounts_payable_voucher_id INT,accounts_payable_voucher_payment_id INT,amount DECIMAL(13,4),date DATE,added_by INT DEFAULT 1,added_on DATETIME DEFAULT CURRENT_TIMESTAMP,updated_on DATETIME,is_deleted TINYINT DEFAULT 0);
CREATE FUNCTION gl_account_id(p VARCHAR(50)) RETURNS INT READS SQL DATA RETURN (SELECT id FROM account_type WHERE code=p AND is_deleted=0 AND status='active' LIMIT 1);
CREATE PROCEDURE gl_post_direct_payment(IN p INT) BEGIN END;
CREATE TABLE user(id INT PRIMARY KEY,first_name VARCHAR(50),middle_name VARCHAR(50),last_name VARCHAR(50),is_deleted TINYINT DEFAULT 0);
INSERT INTO user VALUES(1,'Local','Fixture','User',0);
CREATE TABLE department(id INT PRIMARY KEY,name VARCHAR(50));
CREATE TABLE supplier(id INT PRIMARY KEY,name VARCHAR(100),category VARCHAR(50),is_deleted TINYINT DEFAULT 0);
CREATE TABLE receipt_note(id INT PRIMARY KEY,sales_invoice_no VARCHAR(100),remarks TEXT,purchase_order_id INT,is_deleted TINYINT DEFAULT 0);
CREATE TABLE purchase_order(id INT PRIMARY KEY,po_number VARCHAR(100),purchase_request_id INT,is_deleted TINYINT DEFAULT 0);
CREATE TABLE aoq(id INT PRIMARY KEY,purchase_request_id INT,is_deleted TINYINT DEFAULT 0);
''')
sql('ALTER TABLE user ADD role_id INT DEFAULT 4, ADD immediate_head INT NULL;')
for t in ['accounts_payable_voucher_payment','accounts_payable_voucher_payment_detail']:
 text=(root/f'app/Models/{t[0].upper()+t[1:]}.php').read_text().split('protected $allowedFields = [')[1].split('];')[0]
 fields=re.findall(r"'([^']+)'",text)+['added_by','added_on','updated_by','updated_on','reference_no']
 for f in fields:
  typ='DECIMAL(13,4)' if f in ['amount','grand_total','balance'] else ('DATETIME' if f.endswith('_on') else ('INT' if f.endswith('_id') or f.endswith('_by') else 'VARCHAR(500)'))
  sql(f'ALTER TABLE {t} ADD COLUMN IF NOT EXISTS `{f}` {typ} NULL;')
sql((root/'sql/shared_funds/schema.sql').read_text());sql((root/'sql/shared_funds/schema.sql').read_text())
# Preserve independent balance/audit hooks and exercise the nested payment->APV path.
sql('''CREATE TRIGGER fixture_apv_balance_ai AFTER INSERT ON accounts_payable_voucher_payment_detail
FOR EACH ROW UPDATE accounts_payable_voucher SET balance=COALESCE(balance,amount)-NEW.amount WHERE id=NEW.accounts_payable_voucher_id;
CREATE TRIGGER fixture_apv_balance_au AFTER UPDATE ON accounts_payable_voucher_payment_detail
FOR EACH ROW UPDATE accounts_payable_voucher SET balance=COALESCE(balance,amount)+OLD.amount-NEW.amount WHERE id=NEW.accounts_payable_voucher_id;''')
# Posting script must reinstall without advancing cutoffs or creating accounting rows.
posting=(root/'sql/shared_funds/posting.sql').read_text();sql(posting);sql(posting)
for t in roots[:-1]:
 for center in [20,23,99,2]:
  sql(f"INSERT INTO {t}(cost_center_id,date,amount,grand_total,account_type_id,requested_by_id,liquidated_by_id) VALUES({center},'2026-10-21',100,100,80,1,1);")
  sid=sql('SELECT MAX(id) FROM '+t)
  sql(f"UPDATE {t} SET status='approved' WHERE id={sid};")
  if t in ['petty_cash','cash_advance','change_fund']:
   check(sql(f"SELECT COUNT(*) FROM general_ledger WHERE source='{t}' AND source_id={sid} AND is_deleted=0")=='0',t+' source approval has no direct accounting')
  if t in ['liquidation','reimbursement','replenishment']:
   child={'liquidation':'liquidation_detail','reimbursement':'reimbursement_item','replenishment':'replenishment_item'}[t]
   sql(f"INSERT INTO {child}({t}_id,account_type_id,amount,added_by) VALUES({sid},80,100,1);")
   cid=sql('SELECT MAX(id) FROM '+child)
   check(sql(f"SELECT COUNT(*) FROM journal_entry WHERE source='{child}' AND source_id={cid} AND is_deleted=0")=='0',t+' child has no direct JE')
   sql(f"UPDATE {child} SET amount=120 WHERE id={cid};")
   if center!=2:check(sql(f"SELECT COUNT(*) FROM general_ledger WHERE source='{child}' AND source_id={cid} AND is_deleted=0")=='0','child edits do not post')
  if center!=2 and t in ['petty_cash','change_fund']:
   sql(f"UPDATE {t} SET amount=120 WHERE id={sid};")
   check(sql(f"SELECT COUNT(*) FROM general_ledger WHERE source='{t}' AND source_id={sid} AND is_deleted=0")=='0','repeat source edits do not post')
  # Keep sources live for PHP model tests below.
# Explicit APV create deferred while inserting its two balanced lines.
sql("SET @apv_entry_sync=1; INSERT INTO accounts_payable_voucher(cost_center_id,type,type_id,amount,status,date,added_by) VALUES(20,'change_fund',1,100,'approved','2026-10-21',1); SET @vid=LAST_INSERT_ID(); INSERT INTO accounts_payable_voucher_entry(accounts_payable_voucher_id,account_type_id,debit,credit,added_by,added_on) VALUES(@vid,80,100,0,1,NOW()),(@vid,57,0,100,1,NOW()); SET @apv_entry_sync=0; CALL gl_post_apv(@vid);")
check(sql("SELECT cost_center_id,total_debit,total_credit,status FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=1 AND is_deleted=0")=='0\t100.0000\t100.0000\tapproved','approved APV accounting center0')
sql("INSERT INTO accounts_payable_voucher_payment(id,account_type_id,is_deleted) VALUES(1,13,0); INSERT INTO accounts_payable_voucher_payment_detail(accounts_payable_voucher_id,accounts_payable_voucher_payment_id,amount,date) VALUES(1,1,100,'2026-10-21');")
check(sql("SELECT COUNT(*),SUM(debit),SUM(credit) FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0")=='2\t100.0000\t100.0000','APV payment balanced')
sql("CALL gl_post_apv(1); CALL gl_post_apv(1);")
check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=1 AND is_deleted=0")=='1','repeat APV posting one active JE')
check(sql('SELECT COUNT(*) FROM general_ledger WHERE branch_id<>0')=='0','all shared GL center0')
check(sql('SELECT COUNT(*) FROM journal_entry WHERE cost_center_id<>0')=='0','all shared JE center0')
print(f'PASS {checks} shared SQL checks; fixture retained for PHP checks')
# Preserve imported and manual data across source rebuild/retirement.
sql("INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,is_migrated) VALUES(0,'change_fund',1,80,'2026-10-21',9,0,1); INSERT INTO journal_entry(cost_center_id,date,source,source_id,is_migrated,status) VALUES(0,'2026-10-21','change_fund',1,1,'approved'),(0,'2026-10-21',NULL,NULL,0,'approved');")
before=sql("SELECT * FROM general_ledger WHERE is_migrated=1; SELECT * FROM journal_entry WHERE is_migrated=1 OR source IS NULL;")
sql("UPDATE change_fund SET amount=130 WHERE id=1; UPDATE change_fund SET is_deleted=1 WHERE id=1;")
check(before==sql("SELECT * FROM general_ledger WHERE is_migrated=1; SELECT * FROM journal_entry WHERE is_migrated=1 OR source IS NULL;"),'Migrated/manual preservation')
check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='change_fund' AND source_id=1 AND is_migrated=0 AND is_deleted=0")=='0','Source deletion has no direct automated JE')
sql("UPDATE change_fund SET is_deleted=0 WHERE id=1;")
# Physical child deletion and source cancellation both retire active child journals.
sql('DELETE FROM reimbursement_item WHERE reimbursement_id=3; UPDATE liquidation SET is_deleted=1 WHERE id=3;')
check(sql("SELECT COUNT(*) FROM general_ledger g JOIN journal_entry j ON j.source=g.source AND j.source_id=g.source_id WHERE g.source='reimbursement_item' AND g.source_id=3 AND g.is_deleted=0")=='0','Deleted child retires prior GL')
check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='liquidation_detail' AND source_id=3 AND is_deleted=0")=='0','Cancelled parent retires child GL')
sql("UPDATE liquidation SET is_deleted=0 WHERE id=3;")
# Cancellation must also retire payment movements; restoration should create one active set.
sql("UPDATE accounts_payable_voucher SET is_deleted=1 WHERE id=1;")
check(sql("SELECT COUNT(*) FROM journal_entry WHERE source IN ('accounts_payable_voucher','accounts_payable_voucher_payment_detail') AND is_deleted=0")=='0','APV cancellation retires voucher and payment JEs')
sql("UPDATE accounts_payable_voucher SET is_deleted=0 WHERE id=1;")
check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0")=='1','Restore has one active payment JE')
# Move a detail to another shared parent's voucher and verify no old-center data is reachable.
sql("SET @apv_entry_sync=1; INSERT INTO accounts_payable_voucher(cost_center_id,type,type_id,amount,status,added_by) VALUES(23,'change_fund',2,100,'approved',1); INSERT INTO accounts_payable_voucher_entry(accounts_payable_voucher_id,account_type_id,debit,credit,added_by,added_on) VALUES(2,80,100,0,1,NOW()),(2,57,0,100,1,NOW()); SET @apv_entry_sync=0; CALL gl_post_apv(2);")
check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=2 AND is_deleted=0")=='1','Second shared center APV')
# Fixture restore for the model checks (second voucher is left pending/deleted).
sql("SET @apv_entry_sync=1; DELETE FROM accounts_payable_voucher_entry WHERE accounts_payable_voucher_id=2; SET @apv_entry_sync=0; UPDATE accounts_payable_voucher SET is_deleted=1 WHERE id=2;")
print(f'PASS {checks} total shared SQL checks')
sql('UPDATE accounts_payable_voucher_payment SET is_deleted=1 WHERE id=1;')
check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0")=='0','Master payment header soft deletion retires shared GL')
sql('UPDATE accounts_payable_voucher_payment SET is_deleted=0 WHERE id=1;')
check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0")=='1','Master payment header restoration rebuilds once')
# Invalid explicit entries roll back the invoking source statement.
try:
 sql('UPDATE accounts_payable_voucher_entry SET credit=101 WHERE accounts_payable_voucher_id=1 AND credit>0;')
 raise AssertionError('Expected explicit entry validation failure')
except RuntimeError as e:check('Invalid APV entries' in str(e),'Unbalanced explicit edits rejected transactionally')
# Consistent scoped definitions in both complete master installers.
for name in ['general_ledger_triggers_master.sql','general_ledger_triggers_master_uat.sql']:
 check((root/'sql/shared_funds/posting.sql').read_text().strip() in (root/'sql'/name).read_text(),name+' synchronized')
print(f'PASS {checks} final SQL checks')
# A standalone installer aborts on the wrong target before replacing posting code.
before=sql("SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_post_apv'")
try:
 sql((root/'sql/shared_funds/install.sql').read_text())
 raise AssertionError('Expected master-only installer guard')
except RuntimeError as e:check('requires a master schema' in str(e),'Standalone installer rejects non-master database')
check(before==sql("SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_post_apv'"),'Failed guard leaves posting definition unchanged')
# Same flattened batch under a fixture-only target allowlist, without rewriting accounting logic.
install=(root/'sql/shared_funds/install.sql').read_text().replace("'mytemr_master','mytemr_master_uat'", "'shared_funds_fixture','shared_funds_fixture_uat'")
rows=sql('SELECT COUNT(*) FROM general_ledger; SELECT COUNT(*) FROM journal_entry; SELECT * FROM apv_accounting_cutover;')
sql(install)
check(rows==sql('SELECT COUNT(*) FROM general_ledger; SELECT COUNT(*) FROM journal_entry; SELECT * FROM apv_accounting_cutover;'),'Flattened installer repeat changes neither postings nor legacy cutoffs')
print(f'PASS {checks} shared SQL checks including installer guards')

check(sql("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'fixture_apv_balance_%'")=='2','Independent APV balance hooks retained')
print(f'PASS {checks} final SQL assertions')
# All APV types share approval-first recognition; payment dates stay independent.
for apv_type in ['receipt_note','petty_cash','reimbursement','cash_advance','liquidation','change_fund','direct_payment','replenishment','excess','send_out','refund']:
 sql(f"UPDATE accounts_payable_voucher SET type='{apv_type}',cost_center_id=20,approved_on='2026-10-22 08:00:00',added_on='2026-10-21 09:00:00',due_date='2026-10-31' WHERE id=1;")
 check(sql("SELECT MIN(date),MAX(date),SUM(debit),SUM(credit) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=1 AND is_deleted=0")=='2026-10-22\t2026-10-22\t100.0000\t100.0000','Shared approval-first GL '+apv_type)
 check(sql("SELECT COUNT(*),MIN(date),MAX(date) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=1 AND is_deleted=0")=='1\t2026-10-22\t2026-10-22','Shared JE approval date '+apv_type)
sql("UPDATE accounts_payable_voucher SET type='refund',cost_center_id=2 WHERE id=1;")
check(sql("SELECT MIN(branch_id),MIN(date),MAX(date) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=1 AND is_deleted=0")=='2\t2026-10-22\t2026-10-22','Clinic-owned master refund approval date/branch')
check(sql("SELECT MIN(cost_center_id),MIN(date) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=1 AND is_deleted=0")=='2\t2026-10-22','Clinic-owned master refund JE date/branch')
sql("UPDATE accounts_payable_voucher SET type='change_fund',cost_center_id=20,approved_on=NULL WHERE id=1;")
check(sql("SELECT MIN(date),MAX(date) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=1 AND is_deleted=0")=='2026-10-21\t2026-10-21','Shared missing approval falls back to creation')
check(sql("SELECT MIN(date),MAX(date) FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0")=='2026-10-21\t2026-10-21','Shared APV payment retains its own date')
print(f'PASS {checks} shared SQL assertions including APV date rules')
for env in ['prod','uat']:
 date_install=(root/f'sql/apv_posting_date/install_{env}_master.sql').read_text()
 date_install=date_install.replace("'mytemr_master_uat'" if env=='uat' else "'mytemr_master'","'shared_funds_fixture'")
 before=sql('SELECT COUNT(*) FROM general_ledger; SELECT COUNT(*) FROM journal_entry; SELECT * FROM apv_accounting_cutover;')
 sql(date_install);sql(date_install)
 check(before==sql('SELECT COUNT(*) FROM general_ledger; SELECT COUNT(*) FROM journal_entry; SELECT * FROM apv_accounting_cutover;'),'Scoped master date install is definition-only '+env)
print(f'PASS {checks} shared SQL assertions including scoped date installers')
