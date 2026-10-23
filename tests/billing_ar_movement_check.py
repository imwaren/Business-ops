"""Disposable local MariaDB fixtures; never connects to a configured/live database."""
import subprocess, re, json, os
from pathlib import Path
root=Path(__file__).resolve().parents[1]
client=['/Applications/XAMPP/xamppfiles/bin/mysql','--no-defaults','--socket='+os.environ.get('BILLING_FIXTURE_SOCKET','/private/tmp/company_cleanup_db/mysql.sock'),'-u',os.environ.get('BILLING_FIXTURE_USER','myt_waren'),'--batch','--skip-column-names']
checks=0
schema='billing_ar_fixture'
branches={1:'mytemr_diagnostic_mobile',2:'mytemr_diagnostic',3:'mytemr_diagnostic_mactan',4:'mytemr_diagnostic_conso',7:'mytemr_diagnostic_mantlewood'}
def sql(text,db=schema):
 p=subprocess.run(client+([db] if db else []),input=text,text=True,capture_output=True)
 if p.returncode: raise RuntimeError(p.stderr.strip())
 return p.stdout.strip()
def check(ok,label):
 global checks
 if not ok: raise AssertionError(label)
 checks+=1
def routine(source,name,kind='PROCEDURE'):
 match=re.search(r'DROP '+kind+' IF EXISTS '+name+r'\$\$\n.*?END\$\$',source,re.S)
 assert match,name
 return match.group()
def active(source,id):
 return sql(f"SELECT COUNT(*),IFNULL(SUM(debit),0),IFNULL(SUM(credit),0) FROM general_ledger WHERE source='{source}' AND source_id={id} AND is_deleted=0").split('\t')
def journal(source,id):
 return sql(f"SELECT COUNT(DISTINCT je.id),COUNT(item.id),IFNULL(SUM(item.debit),0),IFNULL(SUM(item.credit),0) FROM journal_entry je LEFT JOIN journal_entry_item item ON item.journal_entry_id=je.id AND item.is_deleted=0 WHERE je.source='{source}' AND je.source_id={id} AND je.is_deleted=0").split('\t')
sql('CREATE DATABASE IF NOT EXISTS '+schema,db=None)
for branch in branches:
 sql(f'CREATE DATABASE IF NOT EXISTS billing_ar_branch_{branch}; CREATE TABLE IF NOT EXISTS billing_ar_branch_{branch}.`transaction`(id INT PRIMARY KEY,transaction_no VARCHAR(100)); REPLACE INTO billing_ar_branch_{branch}.`transaction` VALUES(1575,\'10135016\'),(1563,\'10129014\');',db=None)
sql('''DROP TABLE IF EXISTS billing,billing_entry,billing_payment,billing_payment_entry,company,account_type,general_ledger,journal_entry,journal_entry_item;
CREATE TABLE account_type(id INT PRIMARY KEY,code VARCHAR(100),parent_id INT NULL,is_deleted INT DEFAULT 0);
INSERT INTO account_type VALUES(57,'102-113',NULL,0),(65,'102-113B',NULL,0),(100,'100-004',NULL,0),(105,'105-000',NULL,0),(250,'102-250',NULL,0),(120,'102-101',NULL,0);
CREATE TABLE company(id INT PRIMARY KEY,name VARCHAR(100),type VARCHAR(30),is_hmo INT,is_deleted INT);
INSERT INTO company VALUES(2048,'Example','corporate',0,0);
CREATE TABLE billing(id INT PRIMARY KEY,charge_to INT,status VARCHAR(30),is_deleted INT DEFAULT 0,added_on DATETIME);
CREATE TABLE billing_entry(id INT PRIMARY KEY,billing_id INT,branch_id INT,transaction_id INT,amount DECIMAL(13,4),status VARCHAR(30),is_deleted INT DEFAULT 0,added_by INT,added_on DATETIME);
CREATE TABLE billing_payment(id INT PRIMARY KEY,account_type_id INT,mop VARCHAR(50),others VARCHAR(255),paid_date DATE,date DATE,added_on DATETIME,grand_total DECIMAL(13,4),wht_amount DECIMAL(13,4),is_deleted INT DEFAULT 0,is_unidentified INT,charge_to INT,added_by INT,wht_account INT DEFAULT NULL,remarks TEXT DEFAULT NULL);
CREATE TABLE billing_payment_entry(id INT PRIMARY KEY,billing_payment_id INT,billing_entry_id INT,amount DECIMAL(13,4),is_deleted INT DEFAULT 0);
CREATE TABLE general_ledger(id INT AUTO_INCREMENT PRIMARY KEY,branch_id INT,source VARCHAR(250),source_id INT,account_type_id INT,date DATE,debit DECIMAL(13,4),credit DECIMAL(13,4),remark TEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0,is_migrated INT DEFAULT 0);
CREATE TABLE journal_entry(id INT AUTO_INCREMENT PRIMARY KEY,cost_center_id INT,date DATE,type VARCHAR(100),remarks TEXT,status VARCHAR(30),total_debit DECIMAL(13,4),total_credit DECIMAL(13,4),source VARCHAR(250),source_id INT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0,is_migrated INT DEFAULT 0);
CREATE TABLE journal_entry_item(id INT AUTO_INCREMENT PRIMARY KEY,journal_entry_id INT,account_type_id INT,debit DECIMAL(13,4),credit DECIMAL(13,4),remarks TEXT,added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0,is_migrated INT DEFAULT 0);
''')
source=(root/'sql/general_ledger_triggers_master_uat.sql').read_text()
# These focused monetary fixtures have no patient/catalog tables. Full real
# metadata lookups are exercised by general_ledger_remark_names_check.py.
sql('DROP FUNCTION IF EXISTS gl_enrich_remark; CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250),p_id INT,p_branch INT,p_base LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN p_base;')
sql('DROP FUNCTION IF EXISTS gl_journal_base_remark; CREATE FUNCTION gl_journal_base_remark(p_source VARCHAR(250),p_id INT,p_base LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN p_base;')
base=['DELIMITER $$',routine(source,'gl_remark_append','FUNCTION'),routine(source,'gl_billing_payment_journal_remark','FUNCTION'),routine(source,'gl_account_id','FUNCTION'),routine(source,'gl_billing_payer_label','FUNCTION'),routine(source,'gl_billing_mop_code','FUNCTION'),routine(source,'gl_clear'),routine(source,'gl_je_rebuild'),routine(source,'gl_post_billing'),'''DROP TRIGGER IF EXISTS fixture_entry_ai$$
CREATE TRIGGER fixture_entry_ai AFTER INSERT ON billing_entry FOR EACH ROW BEGIN CALL gl_post_billing_entry(NEW.id); END$$
DROP TRIGGER IF EXISTS fixture_entry_au$$
CREATE TRIGGER fixture_entry_au AFTER UPDATE ON billing_entry FOR EACH ROW BEGIN CALL gl_post_billing_entry(NEW.id); END$$
DROP TRIGGER IF EXISTS fixture_billing_au$$
CREATE TRIGGER fixture_billing_au AFTER UPDATE ON billing FOR EACH ROW BEGIN CALL gl_post_billing(NEW.id); END$$
DROP TRIGGER IF EXISTS fixture_payment_ai$$
CREATE TRIGGER fixture_payment_ai AFTER INSERT ON billing_payment FOR EACH ROW BEGIN CALL gl_post_billing_payment(NEW.id); END$$
DROP TRIGGER IF EXISTS fixture_payment_au$$
CREATE TRIGGER fixture_payment_au AFTER UPDATE ON billing_payment FOR EACH ROW BEGIN CALL gl_post_billing_payment(NEW.id); END$$''','DELIMITER ;']
sql('\n'.join(base))
for env in ['uat','prod']:
 install=(root/f'sql/billing_ar_repair/install_{env}.sql').read_text()
 for branch,branch_schema in sorted(branches.items(), key=lambda x:len(x[1]),reverse=True):
  install=install.replace(branch_schema+('_uat' if env=='uat' else ''),f'billing_ar_branch_{branch}')
 sql(install);sql(install);check(True,env+' installation repeatable')
 sql('DROP TRIGGER IF EXISTS fixture_payment_ai; DROP TRIGGER IF EXISTS fixture_payment_au;')
 sql((root/'sql/billing_collection_repair/install.sql').read_text());sql((root/'sql/billing_collection_repair/install.sql').read_text())
 check(sql("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('billing_payment','billing_payment_entry')")=='5','scoped parent/allocation hooks repeatable')
 # Reproduce the production deployment gap, then prove the scoped installer fixes creation.
 sql("INSERT INTO billing VALUES(9900,2048,'approved',0,'2026-10-22'); DROP FUNCTION gl_journal_base_remark;")
 try:
  sql("INSERT INTO billing_entry VALUES(99000,9900,0,0,450,'pending',0,1,'2026-10-22');")
  raise AssertionError('Expected missing journal helper failure')
 except RuntimeError as error:
  check('gl_journal_base_remark' in str(error),'incomplete production baseline reproduced')
 sql((root/'sql/billing_collection_repair/remarks_install.sql').read_text())
 sql("INSERT INTO billing_entry VALUES(99000,9900,0,0,450,'pending',0,1,'2026-10-22');")
 check(active('billing_entry',99000)==['2','450.0000','450.0000'] and journal('billing_entry',99000)[0:2]==['1','2'],'billing creation executes repaired real helper')

 sql("DELETE FROM billing_entry; DELETE FROM billing; DELETE FROM billing_payment_entry; DELETE FROM billing_payment; DELETE FROM general_ledger; DELETE FROM journal_entry_item; DELETE FROM journal_entry;")
 sql("INSERT INTO billing VALUES(5754,2048,'approved',0,'2026-10-09 21:58:50'); INSERT INTO billing_entry VALUES(75975,5754,1,1575,900,'pending',0,67,'2026-10-09 21:58:50'),(75976,5754,1,1563,900,'pending',0,67,'2026-10-09 21:58:50');")
 for id,txn in [(75975,'10135016'),(75976,'10129014')]:
  check(active('billing_entry',id)==['2','900.0000','900.0000'],'sample AR pair')
  check(journal('billing_entry',id)==['1','2','900.0000','900.0000'],'sample JE matches')
  check(sql(f"SELECT COUNT(*) FROM general_ledger WHERE source_id={id} AND source='billing_entry' AND is_deleted=0 AND remark LIKE '%{txn}%' AND remark LIKE '%5754%'")=='2','GL transaction tracking')
  check(sql(f"SELECT COUNT(*) FROM journal_entry WHERE source_id={id} AND source='billing_entry' AND is_deleted=0 AND remarks LIKE '%{txn}%'")=='1','header transaction tracking')
  check(sql(f"SELECT COUNT(*) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source='billing_entry' AND j.source_id={id} AND i.is_deleted=0 AND i.remarks LIKE '%{txn}%'")=='2','JE item tracking')
 check(sql("SELECT SUM(debit),SUM(credit),MIN(date),MAX(date) FROM general_ledger WHERE source='billing_entry' AND is_deleted=0")=='1800.0000\t1800.0000\t2026-10-09\t2026-10-09','sample total/date')
 for status in ['pending','approved','open_bill','closed_bill']:
  sql(f"UPDATE billing SET status='{status}' WHERE id=5754;")
  check(active('billing_entry',75975)==['2','900.0000','900.0000'] and journal('billing_entry',75975)[0:2]==['1','2'],'status preserves one movement '+status)
 sql("CALL gl_post_billing(5754); CALL gl_post_billing(5754);")
 check(journal('billing_entry',75975)[0:2]==['1','2'],'repeated posting no active duplicate')
 check(int(sql("SELECT COUNT(*) FROM journal_entry WHERE source='billing_entry' AND source_id=75975 AND is_deleted=1"))>0,'history soft retained')
 sql("UPDATE billing SET status='cancelled' WHERE id=5754;")
 check(active('billing_entry',75975)[0]=='0' and journal('billing_entry',75975)[0:2]==['0','0'],'cancel retires GL/JE')
 sql("UPDATE billing SET status='approved',is_deleted=1 WHERE id=5754;")
 check(active('billing_entry',75976)[0]=='0','deleted bill no posting')
 sql("UPDATE billing SET is_deleted=0 WHERE id=5754; UPDATE billing_entry SET is_deleted=1 WHERE id=75975;")
 check(active('billing_entry',75975)[0]=='0' and journal('billing_entry',75975)[0:2]==['0','0'],'deleted entry retires movement')
 sql("UPDATE billing_entry SET is_deleted=0 WHERE id=75975;")
 before=sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;')
 # One mysql connection owns the whole transaction.
 sql("START TRANSACTION; UPDATE billing_entry SET amount=777 WHERE id=75975; CALL gl_post_billing(5754); ROLLBACK;")
 check(before==sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;'),'transaction rollback restores history')
 for branch in branches:
  id=80000+branch
  sql(f"INSERT INTO billing_entry VALUES({id},5754,{branch},1575,20,'pending',0,67,'2026-10-09');")
  check(sql(f"SELECT COUNT(*) FROM general_ledger WHERE source='billing_entry' AND source_id={id} AND is_deleted=0 AND remark LIKE '%10135016%'")=='2','branch routing '+str(branch))
 sql("INSERT INTO billing_entry VALUES(81000,5754,1,0,25,'pending',0,67,'2026-10-09');")
 check(sql("SELECT COUNT(*) FROM general_ledger WHERE source_id=81000 AND source='billing_entry' AND is_deleted=0 AND remark NOT LIKE '%transaction #%'")=='2','missing transaction fallback does not invent number')
 sql("INSERT INTO billing_entry VALUES(81001,5754,1,0,0,'pending',0,67,'2026-10-09');")
 check(active('billing_entry',81001)[0]=='0','zero amount skipped')
 sql("INSERT INTO billing_entry VALUES(81002,5754,0,0,50,'pending',0,67,'2026-10-09');")
 check(active('billing_entry',81002)==['2','50.0000','50.0000'],'branch-zero billing posts full AR movement')
 check(sql("SELECT DISTINCT branch_id FROM general_ledger WHERE source='billing_entry' AND source_id=81002 AND is_deleted=0")=='0','branch-zero metadata retained')
 check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='billing_entry' AND source_id=81002 AND is_deleted=0 AND cost_center_id=0 AND remarks NOT LIKE '%transaction #%'")=='1','branch-zero journal uses reference fallback')
 # Multi-branch collection: 900 + 400, withholding 26 => bank 1274 + CWT 26, credit AR 1300.
 sql("INSERT INTO billing_entry VALUES(82000,5754,2,1575,400,'pending',0,67,'2026-10-09'); INSERT INTO billing_payment(id,account_type_id,mop,others,paid_date,date,added_on,grand_total,wht_amount,is_deleted,is_unidentified,charge_to,added_by,wht_account) VALUES(1,120,'cash',NULL,'2026-10-08','2026-10-09','2026-10-09',1274,26,0,0,2048,67,NULL); INSERT INTO billing_payment_entry VALUES(1,1,75975,900,0),(2,1,82000,400,0); UPDATE billing_payment SET id=id WHERE id=1;")
 check(active('billing_payment',1)==['6','1300.0000','1300.0000'],'identified balanced collection')
 check(sql("SELECT account_type_id,SUM(debit),SUM(credit) FROM general_ledger WHERE source='billing_payment' AND source_id=1 AND is_deleted=0 GROUP BY account_type_id ORDER BY account_type_id")=='57\t0.0000\t1300.0000\n105\t26.0000\t0.0000\n120\t1274.0000\t0.0000','collection account math preserved')
 check(journal('billing_payment',1)==['1','6','1300.0000','1300.0000'],'collection JE matches')
 sql("CALL gl_post_billing_payment(1); CALL gl_post_billing_payment(1);")
 check(journal('billing_payment',1)[0:2]==['1','6'],'collection repeat no duplicate')
 check(sql("SELECT MIN(date),MAX(date) FROM general_ledger WHERE source='billing_payment' AND source_id=1 AND is_deleted=0")=='2026-10-08\t2026-10-08','paid date preserved')
 sql("UPDATE billing_payment SET mop='check',account_type_id=100 WHERE id=1;")
 check(sql("SELECT SUM(debit) FROM general_ledger WHERE source='billing_payment' AND source_id=1 AND is_deleted=0 AND account_type_id=100")=='1274.0000','check source account preserved')
 sql("INSERT INTO billing_payment(id,account_type_id,mop,others,paid_date,date,added_on,grand_total,wht_amount,is_deleted,is_unidentified,charge_to,added_by,wht_account) VALUES(2,120,'cash',NULL,'2026-10-09','2026-10-09','2026-10-09',200,4,0,1,NULL,67,NULL);")
 check(active('billing_payment',2)==['3','200.0000','200.0000'] and journal('billing_payment',2)[0:2]==['1','3'],'unidentified branch0 collection balanced')
 check(sql("SELECT DISTINCT branch_id FROM general_ledger WHERE source='billing_payment' AND source_id=2 AND is_deleted=0")=='0','unidentified branch0 retained')
 sql("UPDATE billing_payment SET is_deleted=1 WHERE id=2;")
 check(active('billing_payment',2)[0]=='0' and journal('billing_payment',2)[0:2]==['0','0'],'deleted collection retires all movement')
 # Collection regression: source headers precede allocation rows, including sample 1447.
 sql("INSERT INTO billing_entry VALUES(83001,5754,4,1575,650,'pending',0,67,'2026-10-20'),(83002,5754,4,1575,1730,'pending',0,67,'2026-10-20'),(83003,5754,4,1575,0,'pending',0,67,'2026-10-20');")
 sql("INSERT IGNORE INTO account_type VALUES(53,'100-115',NULL,0),(165,'105-001',NULL,0),(193,'102-193',NULL,0),(192,'102-192',NULL,0);")
 sql("INSERT INTO billing_payment(id,account_type_id,mop,others,paid_date,date,added_on,grand_total,wht_amount,is_deleted,is_unidentified,charge_to,added_by,wht_account) VALUES(1447,53,'bank',NULL,'2026-10-20','2026-10-19','2026-10-20',2332.4,47.6,0,0,2048,132,165);")
 check(active('billing_payment',1447)[0]=='0','header before allocations has no identified movement')
 sql("INSERT INTO billing_payment_entry VALUES(31741,1447,83001,650,0),(31742,1447,83002,1730,0),(31743,1447,83003,0,0);")
 check(active('billing_payment',1447)==['3','2380.0000','2380.0000'],'allocation insert automatically posts sample without header update')
 check(sql("SELECT DISTINCT branch_id FROM general_ledger WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0")=='4','sample attribution to branch 4')
 check(sql("SELECT account_type_id,SUM(debit),SUM(credit) FROM general_ledger WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0 GROUP BY account_type_id ORDER BY account_type_id")=='53\t2332.4000\t0.0000\n57\t0.0000\t2380.0000\n165\t47.6000\t0.0000','sample debit explicit bank + explicit WHT = AR allocation total')
 check(journal('billing_payment',1447)==['1','3','2380.0000','2380.0000'],'sample exactly one approved JE')
 sql('CALL gl_post_billing_payment(1447); CALL gl_post_billing_payment(1447);')
 check(journal('billing_payment',1447)==['1','3','2380.0000','2380.0000'],'sample rerun has one active journal')
 sql((root/'sql/billing_collection_repair/repost.sql').read_text())
 check(journal('billing_payment',1447)==['1','3','2380.0000','2380.0000'],'scoped repost SQL verified against sample')
 for mop in ['cash','check','bank','online_payment','others','unrecognized',' CASH ']:
  sql(f"UPDATE billing_payment SET mop='{mop}' WHERE id=1447;")
  check(sql("SELECT SUM(debit) FROM general_ledger WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0 AND account_type_id=53")=='2332.4000','explicit account wins for '+mop)
 for mop,other,expected in [(' cash ',None,100),('check',None,100),('online_payment',None,193),('others','G Cash',193),('others','credit card',192)]:
  other_sql='NULL' if other is None else "'"+other+"'"
  sql(f"UPDATE billing_payment SET account_type_id=NULL,mop='{mop}',others={other_sql} WHERE id=1447;")
  check(sql(f"SELECT SUM(debit) FROM general_ledger WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0 AND account_type_id={expected}")=='2332.4000','recognized MOP fallback '+str((mop,other)))
 before_invalid=sql("SELECT * FROM general_ledger WHERE source='billing_payment' AND source_id=1447 ORDER BY id;")
 try:
  sql("UPDATE billing_payment SET mop='unrecognized',account_type_id=999999 WHERE id=1447;")
  raise AssertionError('Unknown MOP with invalid account accepted')
 except RuntimeError as error:
  check('requires a valid account_type_id' in str(error),'unknown MOP gives clear error')
 check(before_invalid==sql("SELECT * FROM general_ledger WHERE source='billing_payment' AND source_id=1447 ORDER BY id;"),'failed MOP update preserves existing GL')
 sql("UPDATE billing_payment SET mop='bank',account_type_id=53,wht_account=999999 WHERE id=1447;")
 check(sql("SELECT SUM(debit) FROM general_ledger WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0 AND account_type_id=105")=='47.6000','invalid WHT account falls back to CWT')
 # Explicit master branch zero participates in header-then-allocation posting.
 sql("INSERT INTO billing_entry VALUES(99001,5754,0,0,1000,'pending',0,1,'2026-10-22'),(99002,5754,NULL,0,1000,'pending',0,1,'2026-10-22');")
 sql("INSERT INTO billing_payment(id,account_type_id,mop,grand_total,wht_amount,is_unidentified,is_deleted,charge_to,added_by,added_on,paid_date) VALUES(1501,53,'bank',1000,0,0,0,2048,1,'2026-10-22','2026-10-22');")
 check(active('billing_payment',1501)[0]=='0','master collection header waits for allocations')
 sql('INSERT INTO billing_payment_entry VALUES(39001,1501,99001,1000,0);')
 check(active('billing_payment',1501)==['2','1000.0000','1000.0000'] and journal('billing_payment',1501)==['1','2','1000.0000','1000.0000'],'branch-zero allocation generates balanced GL/JE/items')
 check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_payment' AND source_id=1501 AND is_deleted=0 AND branch_id<>0")=='0','explicit master ownership remains accounting zero')
 sql('CALL gl_post_billing_payment(1501); CALL gl_post_billing_payment(1501);')
 check(journal('billing_payment',1501)[0:2]==['1','2'],'master repeat posting one active journal')
 sql('UPDATE billing_payment_entry SET amount=550 WHERE id=39001;')
 check(active('billing_payment',1501)==['2','550.0000','550.0000'],'master allocation amount edit')
 sql("INSERT INTO billing_payment(id,account_type_id,mop,grand_total,wht_amount,is_unidentified,is_deleted,charge_to,added_by,added_on,paid_date) VALUES(1502,53,'bank',1.99,0.01,0,0,2048,1,'2026-10-22','2026-10-22'); INSERT INTO billing_payment_entry VALUES(39002,1502,99001,1,0),(39003,1502,80001,1,0),(39004,1502,99002,999,0);")
 check(active('billing_payment',1502)==['6','2.0000','2.0000'],'mixed master/clinic allocation excludes null branch and balances')
 check(sql("SELECT SUM(debit) FROM general_ledger WHERE source='billing_payment' AND source_id=1502 AND account_type_id=105 AND is_deleted=0")=='0.0100','mixed master/clinic WHT exact')
 check(sql("SELECT GROUP_CONCAT(DISTINCT branch_id ORDER BY branch_id) FROM general_ledger WHERE source='billing_payment' AND source_id=1502 AND is_deleted=0")=='0,1','mixed branch attribution preserved')
 sql('UPDATE billing_payment_entry SET is_deleted=1 WHERE id=39002; DELETE FROM billing_payment_entry WHERE id=39003;')
 check(active('billing_payment',1502)[0]=='0' and journal('billing_payment',1502)[0]=='0','remaining null-only allocation cannot silently create master posting')
 sql('UPDATE billing_payment SET is_deleted=1 WHERE id=1501;')
 check(active('billing_payment',1501)[0]=='0' and journal('billing_payment',1501)[0]=='0','master collection deletion retires only its automated journal')
 # Three equal branches force a deterministic fractional withholding remainder.
 sql("INSERT INTO billing_payment(id,account_type_id,mop,others,paid_date,date,added_on,grand_total,wht_amount,is_deleted,is_unidentified,charge_to,added_by,wht_account) VALUES(1448,53,'bank',NULL,'2026-10-20',NULL,'2026-10-20',2.99,0.01,0,0,2048,132,NULL);")
 sql("INSERT INTO billing_payment_entry VALUES(31801,1448,80001,1,0),(31802,1448,82000,1,0),(31803,1448,80003,1,0);")
 check(active('billing_payment',1448)==['9','3.0000','3.0000'],'fractional multi-branch payment balances')
 check(sql("SELECT SUM(debit) FROM general_ledger WHERE source='billing_payment' AND source_id=1448 AND account_type_id=105 AND is_deleted=0")=='0.0100','rounding preserves exact WHT header')
 check(sql("SELECT GROUP_CONCAT(amount ORDER BY branch_id) FROM (SELECT branch_id,SUM(debit) amount FROM general_ledger WHERE source='billing_payment' AND source_id=1448 AND account_type_id=105 AND is_deleted=0 GROUP BY branch_id) t")=='0.0033,0.0034,0.0033','rounding assigned deterministically')
 sql('UPDATE billing_payment_entry SET amount=2 WHERE id=31801;')
 check(active('billing_payment',1448)==['9','4.0000','4.0000'],'allocation amount edit rebuilds')
 sql('UPDATE billing_payment_entry SET billing_payment_id=1447 WHERE id=31801;')
 check(active('billing_payment',1448)==['6','2.0000','2.0000'] and active('billing_payment',1447)==['6','2382.0000','2382.0000'],'moving allocation rebuilds old/new payments')
 sql('UPDATE billing_payment_entry SET is_deleted=1 WHERE id=31802;')
 check(active('billing_payment',1448)==['3','1.0000','1.0000'],'allocation soft deletion rebuilds')
 sql('DELETE FROM billing_payment_entry WHERE id=31803;')
 check(active('billing_payment',1448)[0]=='0' and journal('billing_payment',1448)[0]=='0','hard delete last allocation retires GL/JE')
 sql('UPDATE billing_payment SET is_deleted=1 WHERE id=1447;')
 check(active('billing_payment',1447)[0]=='0' and journal('billing_payment',1447)[0]=='0','payment soft delete retires GL and JE')
 sql('UPDATE billing_payment SET is_deleted=0 WHERE id=1447;')
 # Retained migrated and manual records are never retired or copied into automated journals.
 sql("INSERT INTO general_ledger(source,source_id,account_type_id,date,debit,credit,is_migrated) VALUES('billing_payment',1447,53,'2026-01-01',7,7,1);")
 sql("INSERT INTO journal_entry(cost_center_id,date,type,status,source,source_id,total_debit,total_credit,is_migrated) VALUES(0,'2026-01-01','billing_payment','approved','billing_payment',1447,7,7,1),(0,'2026-01-01','manual','approved',NULL,NULL,9,9,0);")
 sql("INSERT INTO journal_entry_item(journal_entry_id,account_type_id,debit,credit) SELECT id,53,7,7 FROM journal_entry WHERE is_migrated=1;")
 protected=sql("SELECT * FROM general_ledger WHERE is_migrated=1; SELECT * FROM journal_entry WHERE is_migrated=1 OR source IS NULL; SELECT i.* FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.is_migrated=1;")
 sql('CALL gl_post_billing_payment(1447);')
 check(protected==sql("SELECT * FROM general_ledger WHERE is_migrated=1; SELECT * FROM journal_entry WHERE is_migrated=1 OR source IS NULL; SELECT i.* FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.is_migrated=1;"),'migrated/manual journals and migrated GL untouched')
 check(sql("SELECT COUNT(*),SUM(total_debit),SUM(total_credit) FROM journal_entry WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0 AND is_migrated=0")=='1\t2382.0000\t2382.0000','migrated GL excluded from automated JE totals')

 # Reproduce the live UAT audit-definer conflict during read-only maintenance.
 sql("CREATE TABLE IF NOT EXISTS fixture_trail(id INT AUTO_INCREMENT PRIMARY KEY,source_id INT); CREATE USER IF NOT EXISTS 'billing_ar_audit_fixture'@'localhost'; GRANT SELECT,INSERT,TRIGGER ON billing_ar_fixture.* TO 'billing_ar_audit_fixture'@'localhost'; CREATE DEFINER='billing_ar_audit_fixture'@'localhost' TRIGGER fixture_audit AFTER UPDATE ON general_ledger FOR EACH ROW INSERT INTO fixture_trail(source_id) VALUES(NEW.source_id);")
 original=sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;')
 try:
  sql('SET GLOBAL read_only=ON')
  try:
   sql('START TRANSACTION; CALL gl_post_billing(5754); COMMIT;')
   raise AssertionError('Nonprivileged audit definer unexpectedly wrote during read-only')
  except RuntimeError as error:
   check('ERROR 1290' in str(error),'read-only audit conflict reproduced')
  check(original==sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;'),'failed repair rolled back completely')
  sql("DROP TRIGGER fixture_audit; CREATE DEFINER='root'@'localhost' TRIGGER fixture_audit AFTER UPDATE ON general_ledger FOR EACH ROW INSERT INTO fixture_trail(source_id) VALUES(NEW.source_id);")
  sql('START TRANSACTION; CALL gl_post_billing(5754); CALL gl_post_billing(5754); COMMIT;')
  check(journal('billing_entry',75975)[0:2]==['1','2'],'temporary root definer permits idempotent protected repair')
  check(int(sql('SELECT COUNT(*) FROM fixture_trail'))>0,'audit body still runs during maintenance')
 finally:
  sql('SET GLOBAL read_only=OFF; DROP TRIGGER IF EXISTS fixture_audit;')
 sql("DROP USER 'billing_ar_audit_fixture'@'localhost'")
 # Header-only collection notes: both parent and allocation hooks use fresh source text.
 sql("DROP FUNCTION IF EXISTS gl_enrich_remark; CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250),p_id INT,p_branch INT,p_base LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN CONCAT(p_base,' | existing context');")
 protected=sql("SELECT * FROM general_ledger WHERE is_migrated=1; SELECT * FROM journal_entry WHERE is_migrated=1 OR source IS NULL;")
 def note_snapshot():
  return sql("SELECT branch_id,account_type_id,date,debit,credit,remark FROM general_ledger WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0 AND is_migrated=0 ORDER BY account_type_id,branch_id; SELECT i.account_type_id,i.debit,i.credit,i.remarks FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source='billing_payment' AND j.source_id=1447 AND j.is_deleted=0 AND j.is_migrated=0 AND i.is_deleted=0 ORDER BY i.account_type_id,i.debit,i.credit,i.remarks;")
 sql('CALL gl_post_billing_payment(1447);')
 financial_before=note_snapshot()
 for note in [None,'','   ','\t\r\n','collection','  received by cashier  ',"Customer's \"collection\"",'first line\nsecond line','existing context']:
  value='NULL' if note is None else "CONVERT(0x"+note.encode().hex()+" USING utf8mb4)" if note else "''"
  sql(f'UPDATE billing_payment SET remarks={value} WHERE id=1447;')
  trimmed=note.strip(' ') if note is not None else ''
  suffix=' | '+trimmed if note and note.strip() else ''
  expected='billing_payment #1447 | existing context'+suffix
  for movement in ["UPDATE billing_payment_entry SET amount=amount WHERE billing_payment_id=1447;",'CALL gl_post_billing_payment(1447);','CALL gl_je_rebuild(\'billing_payment\',1447);']:
   sql(movement)
   check(sql("SELECT HEX(remarks) FROM journal_entry WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0 AND is_migrated=0")==expected.encode().hex().upper(),'Source notes appended once after context across posting paths')
   check(note_snapshot()==financial_before,'Note edits preserve GL/item remarks, dates, amounts and accounts')
  # Retained incremental helper updates the header from source, not its previous text.
  header=sql("START TRANSACTION; CALL gl_je_from_gl('billing_payment',1447,53,'2026-10-20',0,0,'unchanged item',132,4); SELECT HEX(remarks) FROM journal_entry WHERE source='billing_payment' AND source_id=1447 AND is_deleted=0 AND is_migrated=0; ROLLBACK;")
  check(header==expected.encode().hex().upper(),'Incremental helper shares fresh header formatting')
 check(protected==sql("SELECT * FROM general_ledger WHERE is_migrated=1; SELECT * FROM journal_entry WHERE is_migrated=1 OR source IS NULL;"),'All remark paths preserve migrated/manual journals')
 before_install=sql('SELECT COUNT(*) FROM general_ledger; SELECT COUNT(*) FROM journal_entry;')
 patch=(root/'sql/billing_collection_repair/remarks_install.sql').read_text()
 sql(patch);sql(patch)
 check(before_install==sql('SELECT COUNT(*) FROM general_ledger; SELECT COUNT(*) FROM journal_entry;'),'Scoped remark installer has no historical writes')
 for name,kind in [('gl_billing_payment_journal_remark','FUNCTION'),('gl_je_from_gl','PROCEDURE'),('gl_je_rebuild','PROCEDURE'),('gl_post_billing_payment','PROCEDURE')]:
  wanted=routine(patch,name,kind)
  for path in ['sql/general_ledger_triggers_master.sql','sql/general_ledger_triggers_master_uat.sql','sql/billing_collection_repair/install.sql']:
   check(routine((root/path).read_text(),name,kind)==wanted,'Header remark definitions synchronized')
 sql('UPDATE billing_payment SET remarks=NULL WHERE id=1447;')
 print('PASS:',env,'billing/collection fixture lifecycle')
sql('DROP DATABASE '+schema,db=None)
for branch in branches:sql(f'DROP DATABASE billing_ar_branch_{branch}',db=None)
print('PASS:',checks,'isolated billing AR movement checks')
