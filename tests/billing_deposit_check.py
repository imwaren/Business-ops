"""Isolated local MariaDB deposit tests. Never uses configured application connections."""
import os, re, subprocess
from pathlib import Path
root = Path(__file__).resolve().parents[1]
schema = 'billing_deposit_fixture'
client = ['/Applications/XAMPP/xamppfiles/bin/mysql', '--no-defaults',
          '--socket=' + os.environ.get('BILLING_FIXTURE_SOCKET', '/private/tmp/items_blindsheet_fixture/mysql.sock'),
          '-u', os.environ.get('BILLING_FIXTURE_USER', 'root'), '--batch', '--skip-column-names']
checks = 0

def sql(text, db=schema):
    result = subprocess.run(client + ([db] if db else []), input=text, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip())
    return result.stdout.strip()

def check(ok, label):
    global checks
    if not ok:
        raise AssertionError(label)
    checks += 1

def definition(source, kind, name):
    return re.search(r'DROP ' + kind + ' IF EXISTS ' + name + r'\$\$.*?END\$\$', source, re.S).group()

def gl(deposit_id=1):
    return sql(f"SELECT branch_id,account_type_id,debit,credit FROM general_ledger WHERE source='billing_payment_deposit' AND source_id={deposit_id} AND is_deleted=0 AND is_migrated=0 ORDER BY branch_id,account_type_id")

def assert_journal(deposit_id, amount, count):
    row = sql(f"SELECT id,status,total_debit,total_credit FROM journal_entry WHERE source='billing_payment_deposit' AND source_id={deposit_id} AND is_deleted=0 AND is_migrated=0").split('\t')
    check(len(row) == 4 and row[1:] == ['approved', amount, amount], 'One balanced deposit header')
    items = sql(f"SELECT account_type_id,debit,credit FROM journal_entry_item WHERE journal_entry_id={row[0]} AND is_deleted=0 ORDER BY account_type_id,debit,credit")
    expected = sql(f"SELECT account_type_id,debit,credit FROM general_ledger WHERE source='billing_payment_deposit' AND source_id={deposit_id} AND is_deleted=0 AND is_migrated=0 ORDER BY account_type_id,debit,credit")
    check(items == expected and len(items.splitlines()) == count, 'JE items exactly match GL')

sql('CREATE DATABASE IF NOT EXISTS ' + schema, None)
try:
    # Reuse the existing synthetic schema contract, not application schemas/data.
    fixture = (root / 'tests/billing_ar_movement_check.py').read_text()
    ddl = re.search(r"sql\('''(DROP TABLE IF EXISTS .*?)'''\)", fixture, re.S).group(1)
    sql('DROP TABLE IF EXISTS billing_payment_deposit; ' + ddl)
    sql("""DELETE FROM account_type;
    INSERT INTO account_type VALUES(13,'100-004',NULL,0),(53,'100-115',NULL,0),(57,'102-113',NULL,0),(65,'102-113B',NULL,0),(165,'105-000',NULL,0),(250,'102-250',NULL,0);
    CREATE TABLE billing_payment_deposit(id INT PRIMARY KEY,billing_payment_id INT,account_type_id INT,amount DECIMAL(13,4),added_by INT,added_on DATETIME,updated_on DATETIME,is_deleted INT DEFAULT 0);
    DROP FUNCTION IF EXISTS gl_enrich_remark;
    CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250),p_id INT,p_branch INT,p_base LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN CONCAT(p_base,' | fixture context');
    """)
    prod = (root / 'sql/general_ledger_triggers_master.sql').read_text()
    uat = (root / 'sql/general_ledger_triggers_master_uat.sql').read_text()
    scoped = (root / 'sql/billing_deposit_repair/install.sql').read_text()
    block = definition(scoped, 'PROCEDURE', 'gl_post_billing_payment_deposit')
    check(block == definition(prod, 'PROCEDURE', 'gl_post_billing_payment_deposit') == definition(uat, 'PROCEDURE', 'gl_post_billing_payment_deposit'), 'Production/UAT/scoped definitions identical')
    helpers = '\n\n'.join(definition(prod, kind, name) for kind, name in [
        ('FUNCTION','gl_account_id'),('FUNCTION','gl_billing_payer_label'),('PROCEDURE','gl_clear')])
    hooks = '\n\n'.join(definition(prod, 'TRIGGER', name) for name in ['trg_gl_billing_payment_deposit_ai','trg_gl_billing_payment_deposit_au'])
    sql('DELIMITER $$\n' + helpers + '\n' + hooks + '\nDELIMITER ;')
    sql("DROP FUNCTION IF EXISTS gl_remark_append; CREATE FUNCTION gl_remark_append(a LONGTEXT,b LONGTEXT) RETURNS LONGTEXT DETERMINISTIC RETURN CONCAT_WS(' | ',a,b);")
    sql((root / 'sql/billing_collection_repair/install.sql').read_text())
    sql(scoped); sql(scoped)
    sql("""CREATE TRIGGER fixture_enrich BEFORE INSERT ON general_ledger FOR EACH ROW SET NEW.remark=CONCAT(NEW.remark,' | detail context');
    INSERT INTO billing VALUES(1,2048,'approved',0,'2026-10-21');
    INSERT INTO billing_entry VALUES(1,1,4,1,350,'open_bill',0,132,'2026-10-21'),(2,1,4,2,480,'open_bill',0,132,'2026-10-21');
    INSERT INTO billing_payment(id,account_type_id,mop,others,paid_date,date,added_on,grand_total,wht_amount,is_deleted,is_unidentified,charge_to,added_by,wht_account) VALUES(1448,13,'check',NULL,'2026-10-21','2026-10-20','2026-10-21',813.4,16.6,0,0,2048,132,165);
    INSERT INTO billing_payment_entry VALUES(1,1448,1,350,0),(2,1448,2,480,0);
    """)
    collection_before = sql("SELECT * FROM general_ledger WHERE source='billing_payment'; SELECT * FROM journal_entry WHERE source='billing_payment'; SELECT i.* FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source='billing_payment';")
    check(sql("SELECT account_type_id,SUM(debit),SUM(credit) FROM general_ledger WHERE source='billing_payment' AND source_id=1448 AND is_deleted=0 GROUP BY account_type_id ORDER BY account_type_id") == '13\t813.4000\t0.0000\n57\t0.0000\t830.0000\n165\t16.6000\t0.0000', 'Collection is correct before deposit')
    sql("INSERT INTO billing_payment_deposit VALUES(1,1448,53,813.4,132,'2026-10-21 04:52:49',NULL,0);")
    check(gl() == '4\t13\t0.0000\t813.4000\n4\t53\t813.4000\t0.0000', 'Deposit source 813.40 remains net, not grossed up to 830')
    assert_journal(1, '813.4000', 2)
    check(sql("SELECT DISTINCT date FROM general_ledger WHERE source='billing_payment_deposit' AND is_deleted=0") == '2026-10-21', 'Deposit date precedence unchanged')
    check(sql("SELECT COUNT(*) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source='billing_payment_deposit' AND j.is_deleted=0 AND i.is_deleted=0 AND i.remarks LIKE '%detail context%'") == '2', 'Enriched GL remarks copied to JE')
    sql((root / 'sql/billing_deposit_repair/repost.sql').read_text())
    sql((root / 'sql/billing_deposit_repair/repost.sql').read_text())
    assert_journal(1, '813.4000', 2)
    check(collection_before == sql("SELECT * FROM general_ledger WHERE source='billing_payment'; SELECT * FROM journal_entry WHERE source='billing_payment'; SELECT i.* FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source='billing_payment';"), 'Collection GL/JE and EWT unchanged by deposit/repost')
    # Partial deposits each post their own actual amount.
    sql("UPDATE billing_payment_deposit SET amount=300 WHERE id=1; INSERT INTO billing_payment_deposit VALUES(2,1448,53,513.4,132,'2026-10-21',NULL,0);")
    assert_journal(1, '300.0000', 2); assert_journal(2, '513.4000', 2)
    check(sql("SELECT SUM(debit),SUM(credit) FROM general_ledger WHERE source='billing_payment_deposit' AND is_deleted=0") == '813.4000\t813.4000', 'Partial deposits sum exactly to net receipt')
    sql('UPDATE billing_payment_deposit SET is_deleted=1 WHERE id=2;')
    check(gl(2) == '' and sql("SELECT COUNT(*) FROM journal_entry WHERE source='billing_payment_deposit' AND source_id=2 AND is_deleted=0") == '0', 'Soft deletion retires deposit GL/JE')
    sql('UPDATE billing_payment_deposit SET is_deleted=0 WHERE id=2;')
    assert_journal(2, '513.4000', 2)
    # Three branches, exact cumulative rounding, and no EWT to subtract.
    sql("""INSERT INTO billing_payment(id,account_type_id,mop,others,paid_date,date,added_on,grand_total,wht_amount,is_deleted,is_unidentified,charge_to,added_by,wht_account) VALUES(1449,13,'cash',NULL,'2026-10-21',NULL,'2026-10-21',3,0,0,0,2048,132,NULL);
    INSERT INTO billing_entry VALUES(3,1,1,3,1,'open_bill',0,132,'2026-10-21'),(4,1,2,4,1,'open_bill',0,132,'2026-10-21'),(5,1,3,5,1,'open_bill',0,132,'2026-10-21');
    INSERT INTO billing_payment_entry VALUES(3,1449,3,1,0),(4,1449,4,1,0),(5,1449,5,1,0);
    INSERT INTO billing_payment_deposit VALUES(3,1449,53,0.01,132,'2026-10-21',NULL,0);""")
    check(sql("SELECT GROUP_CONCAT(debit ORDER BY branch_id) FROM general_ledger WHERE source='billing_payment_deposit' AND source_id=3 AND is_deleted=0 AND account_type_id=53") == '0.0033,0.0034,0.0033', 'Multi-branch deterministic rounding')
    assert_journal(3, '0.0100', 6)
    sql('UPDATE billing_payment_deposit SET amount=3 WHERE id=3;')
    assert_journal(3, '3.0000', 6)
    # Validation fails before existing automated entries are retired.
    before = gl(1)
    try:
        sql('UPDATE billing_payment_deposit SET account_type_id=999999 WHERE id=1;')
        raise AssertionError('Invalid account accepted')
    except RuntimeError as error:
        check('account mapping' in str(error), 'Clear invalid-account error')
    check(gl(1) == before, 'Failed update leaves old postings intact')
    sql('UPDATE billing_entry SET branch_id=0 WHERE id IN(1,2);')
    try:
        sql((root / 'sql/billing_deposit_repair/repost.sql').read_text())
        raise AssertionError('Invalid branches accepted by checked repost')
    except RuntimeError as error:
        check('active branch allocations' in str(error), 'Checked repost rejects missing eligible branches')
    check(gl(1) == before, 'Failed repost rolls back without clearing old GL')
    sql('UPDATE billing_entry SET branch_id=4 WHERE id IN(1,2); DROP PROCEDURE IF EXISTS gl_repost_billing_deposit_checked;')
    sql("INSERT INTO billing_payment(id,account_type_id,mop,others,paid_date,date,added_on,grand_total,wht_amount,is_deleted,is_unidentified,charge_to,added_by,wht_account) VALUES(1450,13,'cash',NULL,'2026-10-21',NULL,'2026-10-21',1,0,0,0,2048,132,NULL);")
    try:
        sql("INSERT INTO billing_payment_deposit VALUES(4,1450,53,1,132,'2026-10-21',NULL,0);")
        raise AssertionError('Deposit without allocations accepted')
    except RuntimeError as error:
        check('active branch allocations' in str(error), 'Missing allocations explicitly rejected')
    check(sql('SELECT COUNT(*) FROM billing_payment_deposit WHERE id=4') == '0', 'Rejected deposit insert rolled back')
    sql("""INSERT INTO general_ledger(source,source_id,account_type_id,date,debit,credit,is_migrated) VALUES('billing_payment_deposit',1,53,'2026-01-01',7,7,1);
    INSERT INTO journal_entry(cost_center_id,date,type,status,total_debit,total_credit,source,source_id,is_migrated) VALUES(0,'2026-01-01','billing_payment_deposit','approved',7,7,'billing_payment_deposit',1,1),(0,'2026-01-01','manual','approved',9,9,NULL,NULL,0);
    INSERT INTO journal_entry_item(journal_entry_id,account_type_id,debit,credit) SELECT id,53,7,7 FROM journal_entry WHERE is_migrated=1;""")
    protected_query = "SELECT * FROM general_ledger WHERE is_migrated=1; SELECT * FROM journal_entry WHERE is_migrated=1 OR source IS NULL; SELECT i.* FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.is_migrated=1;"
    protected = sql(protected_query)
    sql('CALL gl_post_billing_payment_deposit(1);')
    check(protected == sql(protected_query), 'Migrated and manual journals preserved')
    assert_journal(1, '300.0000', 2)
    check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='billing_payment_deposit' AND source_id=1 AND is_deleted=1 AND is_migrated=0") != '0', 'Retired automated history retained')
    check(sql('SELECT amount FROM billing_payment_deposit WHERE id=1') == '300.0000', 'Poster does not mutate deposit amount')
    sql('UPDATE billing_payment_deposit SET amount=0 WHERE id=2;')
    check(gl(2) == '', 'Zero-amount edit retires the selected deposit')
    print('PASS:', checks, 'isolated net-deposit checks')
finally:
    sql('DROP DATABASE ' + schema, None)
