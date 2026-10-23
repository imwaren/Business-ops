"""Full local SQL installations and synthetic accounting fixtures; no live connections."""
import importlib.util
import os
from pathlib import Path
import re
import subprocess
import tempfile
import sys

sys.dont_write_bytecode = True

ROOT = Path(__file__).resolve().parents[1]
CLIENT = ['/Applications/XAMPP/xamppfiles/bin/mysql', '--no-defaults',
          '--socket=' + os.environ.get('BILLING_FIXTURE_SOCKET', '/private/tmp/company_cleanup_db/mysql.sock'), '-u', os.environ.get('BILLING_FIXTURE_USER', 'myt_waren'),
          '--batch', '--skip-column-names', '--default-character-set=utf8mb4']
KEY = b'local-fixture-only-key'
BRANCHES = {1: 'mytemr_diagnostic_mobile', 2: 'mytemr_diagnostic', 3: 'mytemr_diagnostic_mactan',
            4: 'mytemr_diagnostic_conso', 7: 'mytemr_diagnostic_mantlewood'}
CHECKS = 0
spec = importlib.util.spec_from_file_location('prepare_gl_remarks_sql', ROOT / 'scripts/prepare_gl_remarks_sql.py')
preparation = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preparation)
spec = importlib.util.spec_from_file_location('build_gl_remark_release', ROOT / 'sql/gl_remark_names/build_release.py')
release_builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release_builder)


def check(condition, label):
    global CHECKS
    if not condition:
        raise AssertionError(label)
    CHECKS += 1


def sql(statement, database=None, expect_failure=False):
    result = subprocess.run(CLIENT + ([database] if database else []),
                            input='SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n' + statement,
                            text=True, capture_output=True)
    if expect_failure:
        check(result.returncode != 0, 'Unprepared SQL rejected before installation')
        return result.stderr
    if result.returncode:
        # Even synthetic key values need not appear in failure logs.
        error = result.stderr.replace(KEY.hex(), '[test-key]').replace(KEY.decode(), '[test-key]')
        raise RuntimeError(error[-7000:])
    return result.stdout.strip()


def rewrite(source, environment):
    suffix = '_uat' if environment == 'uat' else ''
    mapping = {'mytemr_master' + suffix: f'gl_names_{environment}_master'}
    mapping.update({schema + suffix: f'gl_names_{environment}_branch_{branch}' for branch, schema in BRANCHES.items()})
    for old, new in sorted(mapping.items(), key=lambda pair: len(pair[0]), reverse=True):
        source = re.sub(r'\b' + old + r'\b', new, source)
    return source


def make_schema(database, dump_name):
    sql(f'DROP DATABASE IF EXISTS {database}; CREATE DATABASE {database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    dump = (Path('/Users/myt_waren/Downloads') / dump_name).read_text()
    statements = []
    for name, body in re.findall(r'^CREATE TABLE `([^`]+)` \((.*?)^\) ENGINE=.*?;', dump, re.M | re.S):
        # Preserve real source column types; unrelated mandatory metadata is nullable in fixtures.
        lines = body.splitlines()
        for index, line in enumerate(lines):
            if re.match(r'\s*`id`\s+(?:bigint|int|tinyint|smallint|mediumint)', line, re.I):
                comma = ',' if line.rstrip().endswith(',') else ''
                kind = re.search(r'`id`\s+((?:bigint|int|tinyint|smallint|mediumint)(?:\(\d+\))?)', line, re.I).group(1)
                lines[index] = f'  `id` {kind} NOT NULL AUTO_INCREMENT PRIMARY KEY{comma}'
            elif re.match(r'\s*`', line):
                lines[index] = line.replace('NOT NULL', 'NULL')
        statements.append(f'CREATE TABLE `{name}` (\n' + '\n'.join(lines) + '\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;')
    sql('\n'.join(statements), database)
    sql((ROOT / 'sql/migrations/journal_entry_source.sql').read_text(), database)
    sql('ALTER TABLE journal_entry ADD COLUMN IF NOT EXISTS is_migrated TINYINT DEFAULT 0;', database)
    if 'branch_' in database:
        sql((ROOT / 'sql/migrations/branch_item_blindsheet.sql').read_text(), database)
        sql((ROOT / 'sql/apv_entries/schema.sql').read_text(), database)
        sql((ROOT / 'sql/apv_entries/cutover.sql').read_text(), database)
        sql('CREATE TABLE IF NOT EXISTS accounts_payable_voucher_payment(id INT PRIMARY KEY,account_type_id INT,is_deleted INT DEFAULT 0);', database)


def fingerprint(database):
    return sql("SELECT branch_id,source,source_id,account_type_id,date,debit,credit FROM general_ledger WHERE is_deleted=0 ORDER BY source,source_id,account_type_id,debit,credit;", database)


def journal_fingerprint(database):
    return sql("SELECT h.source,h.source_id,h.cost_center_id,h.date,h.status,h.total_debit,h.total_credit,i.account_type_id,i.debit,i.credit FROM journal_entry h JOIN journal_entry_item i ON i.journal_entry_id=h.id AND i.is_deleted=0 WHERE h.is_deleted=0 ORDER BY h.source,h.source_id,i.account_type_id,i.debit,i.credit;", database)


def accounting_history(database):
    return sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;', database)


def expected_helpers(is_master, environment):
    common = (ROOT / 'sql/fragments/general_ledger_remarks_common.sql').read_text()
    kind = 'master' if is_master else 'diagnostic'
    fragment = (ROOT / ('sql/fragments/general_ledger_remarks_' + kind + '.sql')).read_text()
    fragment = fragment.replace('{MASTER}', 'mytemr_master' + ('_uat' if environment == 'uat' else ''))
    if is_master and environment == 'uat':
        for schema in BRANCHES.values():
            fragment = re.sub(r'\b' + schema + r'\b', schema + '_uat', fragment)
    return '-- BEGIN SHARED REMARK HELPERS\n' + common + fragment + '-- END SHARED REMARK HELPERS'


def encrypted(value):
    return f"AES_ENCRYPT('{value}', UNHEX('{KEY.hex()}'))" if value is not None else 'NULL'


def seed(environment, master, clinic):
    codes = sorted(set(re.findall(r"'([0-9]{3}-[0-9A-Z]{3,5})'", (ROOT / 'sql/general_ledger_triggers.sql').read_text() + (ROOT / 'sql/general_ledger_triggers_master.sql').read_text())) | {'201-110', '202-120', '201-111', '102-101'})
    sql('INSERT INTO account_type(id,code,name,status,is_deleted,parent_id) VALUES ' + ','.join(f"({index},'{code}','Fixture {code}','active',0,NULL)" for index, code in enumerate(codes, 1)) + ';', master)
    ids = {code: index for index, code in enumerate(codes, 1)}
    sql("INSERT INTO company(id,name,type,is_hmo,is_deleted) VALUES(2048,'Synthetic company','corporate',0,0);", master)
    sql(f"INSERT INTO item(id,name,cost,weighted_cost,account_type_id,is_deleted) VALUES(10,'Synthetic Medicine Ω',100,100,{ids['500-205']},0),(11,'Synthetic Supply',100,100,{ids['500-205']},0);", master)
    for branch in BRANCHES:
        database = f'gl_names_{environment}_branch_{branch}'
        sql(f"INSERT INTO patient(id,last_name,first_name,middle_name,suffix) VALUES(10,{encrypted('Dela Cruz' if branch == 2 else 'Branch'+str(branch))},{encrypted('José' if branch == 2 else 'Alex')},NULL,NULL);", database)
        sql(f"INSERT INTO `transaction`(id,patient_id,transaction_no,transaction_date,grand_total,added_by,is_deleted) VALUES(1575,10,'BRANCH{branch}-1575','2026-10-01',900,67,0);", database)
        sql("INSERT INTO booking(id,transaction_id,transaction_type,is_deleted,status) VALUES(1,1575,'pharmacy',0,'approved'); INSERT INTO booking_medicine(id,booking_id,medicine_id,is_deleted) VALUES(1,1,10,0);", database)
    sql(f"INSERT INTO gl_settings(branch_id) VALUES(2); INSERT INTO receipt_note(id,status,is_deleted,added_on,added_by) VALUES(10,'approved',0,'2026-10-01',67); INSERT INTO receipt_note_item(id,receipt_note_id,item_id,amount,vat,vat_type,account_type_id,is_deleted,added_on,added_by) VALUES(1,10,11,1120,120,'vat_in',{ids['104-030']},0,'2026-10-01',67);", clinic)
    sql(f"INSERT INTO accounts_payable_voucher(id,type,type_id,status,amount,withholding_tax,is_deleted,added_on,added_by) VALUES(10,'receipt_note',10,'approved',1110,10,0,'2026-10-01',67); INSERT INTO accounts_payable_voucher_entry(accounts_payable_voucher_id,account_type_id,debit,credit,remarks,added_on,added_by) VALUES(10,{ids['201-110']},1120,0,'Keep entered debit prose','2026-10-01',67),(10,{ids['202-120']},0,10,'Keep entered tax prose','2026-10-01',67),(10,{ids['201-111']},0,1110,'Keep entered payable prose','2026-10-01',67);", clinic)
    sql(f"INSERT INTO billing(id,company_id,charge_to,status,is_deleted,added_on) VALUES(5754,2048,2048,'approved',0,'2026-10-01'); INSERT INTO billing_entry(id,billing_id,branch_id,transaction_id,amount,status,is_deleted,added_on,added_by) VALUES(1,5754,2,1575,900,'pending',0,'2026-10-01',67),(2,5754,1,1575,800,'pending',0,'2026-10-01',67); INSERT INTO billing_payment(id,account_type_id,mop,grand_total,wht_amount,is_unidentified,charge_to,is_deleted,added_on,paid_date,added_by) VALUES(1,{ids['102-101']},'cash',1700,34,0,2048,0,'2026-10-01','2026-10-02',67); INSERT INTO billing_payment_entry(id,billing_payment_id,billing_entry_id,amount,is_deleted) VALUES(1,1,1,900,0),(2,1,2,800,0);", master)
    sql(f"INSERT INTO billing_payment_deposit(id,billing_payment_id,account_type_id,amount,is_deleted,added_on) VALUES(1,1,{ids['102-101']},1700,0,'2026-10-03'); INSERT INTO credit_memo(id,billing_id,branch_id,debit_account_type_id,amount,status,is_deleted,added_on,date_issued) VALUES(1,5754,2,{ids['102-101']},100,'approved',0,'2026-10-03','2026-10-03');", master)
    sql("INSERT INTO booking_details(id,booking_id,type,amount,price,is_deleted) VALUES(1,1,'lab',900,900,0); INSERT INTO payment(id,transaction_id,discount,grand_total,is_deleted,added_on,added_by) VALUES(1,1575,10,890,0,'2026-10-01',67); INSERT INTO payment_multiple_method(id,payment_id,type,amount,is_deleted,added_on,added_by) VALUES(1,1,'cash',890,0,'2026-10-01',67);", clinic)
    sql(f"INSERT INTO bank(id,account_type_ids,is_deleted) VALUES(1,'{ids['102-101']}',0); INSERT INTO non_cash_deposit(id,transaction_id,bank_id,amount,mop,status,is_deleted,transaction_date,added_by) VALUES(1,1575,1,20,'check','posted',0,'2026-10-03',67); INSERT INTO accounts_payable_voucher_payment(id,account_type_id,is_deleted) VALUES(1,{ids['102-101']},0); INSERT INTO accounts_payable_voucher_payment_detail(id,accounts_payable_voucher_id,accounts_payable_voucher_payment_id,amount,date,added_by,is_deleted) VALUES(1,10,1,1110,'2026-10-03',67,0);", clinic)
    sql("INSERT INTO refund(id,branch_id,transaction_id,amount,status,approved_on,added_on,added_by,is_deleted) VALUES(1,2,1575,25,'refunded','2026-10-03','2026-10-03',67,0);", master)
    sql(f"INSERT INTO billing_payment(id,account_type_id,mop,grand_total,is_unidentified,is_deleted,paid_date,added_by) VALUES(2,{ids['100-004']},'check',100,0,0,'2026-10-03',67); INSERT INTO billing_payment_entry(id,billing_payment_id,billing_entry_id,amount,is_deleted) VALUES(3,2,1,100,0); INSERT INTO check_deposit(id,billing_payment_id,account_type_id,amount,status,posted_on,is_deleted) VALUES(1,2,{ids['102-101']},100,'posted','2026-10-04',0); INSERT INTO debit_memo(id,billing_id,branch_id,debit_account_type_id,credit_account_type_id,amount,status,is_deleted,date_issued) VALUES(1,5754,2,{ids['102-113']},{ids['102-101']},100,'approved',0,'2026-10-04');", master)
    return ids


for environment in ['uat', 'prod']:
    master = f'gl_names_{environment}_master'
    clinic = f'gl_names_{environment}_branch_2'
    make_schema(master, 'mytemr_master (table structure only).sql')
    make_schema(clinic, 'mytemr_diagnostic (table structure only).sql')
    # The older master dump predates shared-fund/APV tables now required by the full installer.
    master_tables=set(sql('SHOW TABLES;',master).splitlines())
    for table in sql('SHOW TABLES;',clinic).splitlines():
        if table not in master_tables:
            sql(f'CREATE TABLE `{table}` LIKE `{clinic}`.`{table}`;',master)
    # Model the already-migrated type/payee columns; enum migration is outside this remark fixture.
    sql('ALTER TABLE accounts_payable_voucher MODIFY type VARCHAR(50) NULL, MODIFY supplier_type VARCHAR(50) NULL; ALTER TABLE accounts_payable_voucher_payment_detail MODIFY supplier_type VARCHAR(50) NULL;',master)
    for branch in [1,3,4,7]:
        database = f'gl_names_{environment}_branch_{branch}'
        sql(f'DROP DATABASE IF EXISTS {database}; CREATE DATABASE {database} CHARACTER SET utf8mb4;')
        sql('CREATE TABLE patient(id INT PRIMARY KEY,last_name VARBINARY(255),first_name VARBINARY(255),middle_name VARBINARY(255),suffix VARBINARY(50)); CREATE TABLE `transaction`(id INT PRIMARY KEY,patient_id INT,transaction_no VARCHAR(255),transaction_date DATE,grand_total DECIMAL(13,4),added_by INT,is_deleted INT); CREATE TABLE booking(id INT PRIMARY KEY,transaction_id INT,transaction_type VARCHAR(100),is_deleted INT,status VARCHAR(50)); CREATE TABLE booking_medicine(id INT PRIMARY KEY,booking_id INT,medicine_id INT,is_deleted INT);', database)
    ids = seed(environment, master, clinic)
    suffix = '_uat' if environment == 'uat' else ''
    paths = [f'sql/general_ledger_triggers{suffix}.sql', f'sql/general_ledger_triggers_master{suffix}.sql']
    # Compare monetary vectors from the original routines with the remark-only update.
    for path, database in zip(paths, [clinic, master]):
        baseline = subprocess.check_output(['git', 'show', 'HEAD:' + path], cwd=ROOT, text=True)
        sql(rewrite(preparation.render_sql(baseline, KEY), environment), database)
    clinic_post = 'CALL gl_post_receipt_note(10); CALL gl_post_apv(10); CALL gl_post_apv_payment_detail(1); CALL gl_post_transaction(1575); CALL gl_post_refund(1); CALL gl_post_non_cash_deposit(1);'
    master_post = 'CALL gl_post_billing(5754); CALL gl_post_billing_payment(1); CALL gl_post_billing_payment(2); CALL gl_post_billing_payment_deposit(1); CALL gl_post_check_deposit(1); CALL gl_post_credit_memo(1); CALL gl_post_debit_memo(1);'
    sql(clinic_post, clinic)
    sql(master_post, master)
    before_clinic, before_master = fingerprint(clinic), fingerprint(master)
    before_clinic_journal, before_master_journal = journal_fingerprint(clinic), journal_fingerprint(master)
    for path, database in zip(paths, [clinic, master]):
        history_before = accounting_history(database)
        source = (ROOT / path).read_text()
        block = source[source.index('-- BEGIN SHARED REMARK HELPERS'):source.index('-- END SHARED REMARK HELPERS') + len('-- END SHARED REMARK HELPERS')]
        check(block == expected_helpers(database == master, environment), 'Canonical/embedded helper parity')
        sql(rewrite(source, environment), database, expect_failure=True)
        prepared = rewrite(preparation.render_sql(source, KEY), environment)
        sql(prepared, database)
        sql(prepared, database)
        scoped, _ = release_builder.build(environment, 'master' if database == master else 'diagnostic')
        scoped = rewrite(preparation.render_sql(scoped, KEY), environment)
        sql(scoped, database)
        sql(scoped, database)
        check(True, path + ' scoped installation repeatable')
        check(True, path + ' full installation repeatable')
        check(history_before == accounting_history(database), 'Installation does not rewrite any existing accounting rows')
        check(sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('journal_entry','journal_entry_item') AND COLUMN_NAME='remarks' AND DATA_TYPE='longtext';", database) == '2', 'LONGTEXT migration applied')
    sql(clinic_post, clinic)
    sql(master_post, master)
    check(before_clinic == fingerprint(clinic), 'Diagnostic monetary vectors unchanged')
    check(before_master == fingerprint(master), 'Master monetary vectors unchanged')
    check(before_clinic_journal == journal_fingerprint(clinic), 'Diagnostic journal accounts, amounts, dates, statuses and grouping unchanged')
    check(before_master_journal == journal_fingerprint(master), 'Master journal accounts, amounts, dates, statuses and grouping unchanged')
    for source in ['payment', 'payment_multiple_method', 'refund', 'non_cash_deposit']:
        count, matching = sql(f"SELECT COUNT(*),SUM(remark LIKE '%Dela Cruz, José%' AND remark LIKE '%BRANCH2-1575%') FROM general_ledger WHERE source='{source}' AND is_deleted=0;", clinic).split('\t')
        check(int(count) > 0 and count == matching, 'Diagnostic ' + source + ' GL patient context')
    check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='transaction' AND source_id=1575 AND is_deleted=0 AND remarks LIKE '%Dela Cruz, José%';", clinic) == '1', 'CSR grouping/header preserved')
    check(sql("SELECT (CHAR_LENGTH(remarks)-CHAR_LENGTH(REPLACE(remarks,'transaction #','')))=13 FROM journal_entry WHERE source='transaction' AND source_id=1575 AND is_deleted=0;", clinic) == '1', 'CSR header has one generated transaction label')
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND is_deleted=0 AND remark LIKE '%Synthetic Supply%';", clinic) == '2', 'APV payment has receipt-note item names')
    for source in ['check_deposit', 'debit_memo']:
        check(sql(f"SELECT COUNT(*) FROM general_ledger WHERE source='{source}' AND is_deleted=0 AND remark LIKE '%Dela Cruz, José%' AND remark NOT LIKE '%Branch1%';", master) == '2', 'Master ' + source + ' branch context')
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='accounts_payable_voucher' AND is_deleted=0 AND remark LIKE '%Keep entered%' AND remark LIKE '%Synthetic Supply%';", clinic) == '3', 'APV user prose and item names retained')
    check(sql("SELECT COUNT(*) FROM journal_entry WHERE source IN ('receipt_note_item','accounts_payable_voucher') AND is_deleted=0 AND remarks LIKE '%Synthetic Supply%';", clinic) == '2', 'Receipt/APV journal headers carry item names')
    check(sql("SELECT COUNT(*) FROM journal_entry_item j JOIN journal_entry h ON h.id=j.journal_entry_id WHERE h.source='accounts_payable_voucher' AND h.is_deleted=0 AND j.is_deleted=0 AND j.remarks LIKE '%Synthetic Supply%';", clinic) == '3', 'APV journal items match GL remarks')
    reference = sql("SELECT gl_branch_transaction_context(2,1575);", master)
    check('BRANCH2-1575' in reference and 'Dela Cruz, José' in reference and 'Synthetic Medicine Ω' in reference and 'Branch1' not in reference, 'Branch-specific patient and catalog item resolution')
    for branch in BRANCHES:
        check(f'BRANCH{branch}-1575' in sql(f'SELECT gl_branch_transaction_context({branch},1575);', master), 'All branch lookups use their own same-ID transaction')
    check(sql('SELECT gl_branch_transaction_context(99,1575);', master) == 'NULL', 'Unknown branch has no invented patient')
    check(sql('SELECT gl_branch_transaction_context(2,99999);', master) == 'NULL', 'Missing transaction does not leak a caller cursor handler')
    sql("INSERT INTO billing_entry(id,billing_id,branch_id,transaction_id,amount,is_deleted,status,added_on) VALUES(3,5754,2,99999,50,0,'pending','2026-10-01');", master)
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_entry' AND source_id=3 AND is_deleted=0 AND remark LIKE '%Billing #5754 / entry #3%' AND remark NOT LIKE '%Patient:%';", master) == '2', 'Missing metadata preserves the financial pair and references')
    check(sql(f"SELECT gl_patient_display_name({encrypted('姓')},{encrypted('名')},{encrypted('Middle')},{encrypted('Jr.')});", clinic) == '姓, 名 Middle Jr.', 'Unicode and all name components formatted')
    check(sql(f"SELECT gl_patient_display_name(NULL,{encrypted('Alex')},{encrypted('  ')},NULL);", clinic) == 'Alex', 'Absent name components skipped')
    check(sql(f"SELECT gl_patient_display_name({encrypted('  ')},NULL,NULL,NULL);", clinic) == 'NULL', 'All-blank decrypted name remains unavailable')
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_entry' AND source_id=1 AND is_deleted=0 AND remark LIKE '%BRANCH2-1575%' AND remark LIKE '%Dela Cruz, José%';", master) == '2', 'Master billing AR pair includes patient')
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_payment' AND source_id=1 AND branch_id=2 AND is_deleted=0 AND remark LIKE '%Dela Cruz, José%' AND remark NOT LIKE '%Branch1%';", master) == '3', 'Collection branch lines contain only their own patient')
    check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='billing_payment' AND is_deleted=0 AND remarks LIKE '%Dela Cruz, José%' AND remarks LIKE '%Branch1, Alex%';", master) == '1', 'Combined collection header keeps both patient/transaction pairs')
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='credit_memo' AND is_deleted=0 AND remark LIKE '%Dela Cruz, José%' AND remark NOT LIKE '%Branch1%';", master) == '2', 'Memo references remain branch scoped')
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_payment_deposit' AND branch_id=2 AND is_deleted=0 AND remark LIKE '%Dela Cruz, José%';", master) == '2', 'Linked deposit receives patient metadata')
    # Scalar enrichment is idempotent and manually entered journal source rows remain untouched.
    check(sql("SELECT gl_enrich_remark('billing_entry',1,2,gl_enrich_remark('billing_entry',1,2,'original'))=gl_enrich_remark('billing_entry',1,2,'original');", master) == '1', 'Repeated formatting does not append duplicate metadata')
    sql("INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,remark,is_deleted) VALUES(2,'journal_entry',1,1,'2026-10-01',1,0,'Manual prose',0);", master)
    check(sql("SELECT remark FROM general_ledger WHERE source='journal_entry' ORDER BY id DESC LIMIT 1;", master) == 'Manual prose', 'Manual GL journal movement prose retained')
    # Missing/blank/decryption-failed metadata never supplies ciphertext or suppresses GL.
    sql("UPDATE `transaction` SET transaction_no='' WHERE id=1575;", clinic)
    check('Patient:' not in sql('SELECT gl_transaction_context(1575);', clinic), 'Blank transaction number does not invent a patient label')
    sql("UPDATE `transaction` SET transaction_no='BRANCH2-1575' WHERE id=1575; UPDATE patient SET last_name='not encrypted', first_name='not encrypted' WHERE id=10;", clinic)
    check('not encrypted' not in sql('SELECT gl_transaction_context(1575);', clinic), 'Failed decryption never emits ciphertext/plain fallback')
    sql(f"UPDATE patient SET last_name={encrypted('Dela Cruz')},first_name={encrypted('José')} WHERE id=10;", clinic)
    # Exercise item-history ambiguity independently of inventory monetary rules.
    sql("INSERT INTO booking_details(id,booking_id,is_deleted) VALUES(80,1,0); INSERT INTO item_history(id,item_id,activity,activity_id,is_deleted) VALUES(80,11,'perform',80,0);", clinic)
    check('Synthetic Supply' in sql('SELECT gl_item_history_remarks(80);', clinic) and 'Dela Cruz, José' in sql('SELECT gl_item_history_remarks(80);', clinic), 'Item-history remark contains the exact item and linked patient')
    sql("INSERT INTO `transaction`(id,transaction_no,patient_id,transaction_date,added_by) VALUES(999,'OTHER',10,'2026-10-01',67); INSERT INTO booking(id,transaction_id,is_deleted) VALUES(99,999,0); INSERT INTO booking_details(id,booking_id,is_deleted) VALUES(99,99,0); INSERT INTO booking_package_details(id,booking_detail_id,is_deleted) VALUES(80,99,0);", clinic)
    check(sql('SELECT gl_item_history_transaction_id(80);', clinic) == 'NULL' and 'Patient:' not in sql('SELECT gl_item_history_remarks(80);', clinic), 'Ambiguous activity IDs never guess a patient')
    check('Synthetic Medicine' not in sql('SELECT gl_item_history_remarks(80);', clinic), 'Item-history lines do not copy unrelated transaction medicines')
    # Long context is not limited by GROUP_CONCAT or the old 250-character columns.
    long_name = 'Long synthetic item ' + ('X' * 190)
    for index in range(100,112):
        sql(f"INSERT INTO item(id,name,cost,weighted_cost,is_deleted) VALUES({index},'{long_name}{index}',1,1,0);", master)
        sql(f'INSERT INTO booking_medicine(id,booking_id,medicine_id,is_deleted) VALUES({index},1,{index},0);', clinic)
    sql('SET SESSION group_concat_max_len=32; CALL gl_post_billing_entry(1);', master)
    check(int(sql("SELECT MIN(CHAR_LENGTH(remark)) FROM general_ledger WHERE source='billing_entry' AND source_id=1 AND is_deleted=0;", master)) > 2000, 'Long GL detail survives low GROUP_CONCAT limit')
    check(int(sql("SELECT MIN(CHAR_LENGTH(j.remarks)) FROM journal_entry_item j JOIN journal_entry h ON h.id=j.journal_entry_id WHERE h.source='billing_entry' AND h.source_id=1 AND h.is_deleted=0 AND j.is_deleted=0;", master)) > 2000, 'Long journal item detail retained')
    check(int(sql("SELECT CHAR_LENGTH(remarks) FROM journal_entry WHERE source='billing_entry' AND source_id=1 AND is_deleted=0;", master)) > 2000, 'Long journal header detail retained')
    count_before = sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_entry' AND source_id=1 AND is_deleted=0;", master)
    sql('CALL gl_post_billing_entry(1); CALL gl_post_billing_entry(1);', master)
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='billing_entry' AND source_id=1 AND is_deleted=0;", master) == count_before, 'Ordinary reposting creates no duplicate active billing lines')
    financial_before, journals_before = fingerprint(master), journal_fingerprint(master)
    sql(master_post + master_post, master)
    check(financial_before == fingerprint(master) and journals_before == journal_fingerprint(master), 'All master movements repeat without financial or journal duplication')
    check(sql("SELECT COUNT(*) FROM (SELECT source,source_id FROM journal_entry WHERE is_deleted=0 AND source IN ('billing_entry','billing_payment','billing_payment_deposit','check_deposit','credit_memo','debit_memo') GROUP BY source,source_id HAVING COUNT(*)>1) duplicates;", master) == '0', 'One active master journal per movement after repeated posting')
    # Cancellation is exercised after the normal postings: the new hook must
    # preserve the existing sales-return trigger and its CSR journal grouping.
    sql("UPDATE `transaction` SET is_deleted=1,updated_on='2026-10-02' WHERE id=1575;", clinic)
    check(sql("SELECT COUNT(*) FROM general_ledger WHERE source='sales_return' AND is_deleted=0 AND remark LIKE '%Dela Cruz, José%';", clinic) != '0', 'Later cancellation sales return has patient context')
    print('PASS: ' + environment + ' full synthetic installations and remark paths', flush=True)

with tempfile.TemporaryDirectory(prefix='gl_names_preparation_') as directory:
    env_file = Path(directory) / '.env'
    env_file.write_text("ENCRYPTION_KEY='local-fixture-only-key'\n")
    output = Path(directory) / 'prepared'
    child_environment = os.environ.copy()
    child_environment['ENCRYPTION_KEY'] = 'unrelated-environment-fixture-key'
    result = subprocess.run(['python3', str(ROOT / 'scripts/prepare_gl_remarks_sql.py'), '--environment', 'uat', '--env-file', str(env_file), '--output-dir', str(output), '--php', '/opt/homebrew/bin/php'], capture_output=True, text=True, env=child_environment)
    check(result.returncode == 0, 'Preparation uses the project dotenv parser')
    check(KEY.decode() not in result.stdout + result.stderr and KEY.hex() not in result.stdout + result.stderr, 'Preparation never logs the key')
    check((output.stat().st_mode & 0o777) == 0o700, 'Prepared directory is private')
    for path in output.iterdir():
        check((path.stat().st_mode & 0o777) == 0o600 and preparation.KEY_TOKEN not in path.read_text(), 'Prepared SQL private and fully rendered')
        check(KEY.hex() in path.read_text(), 'Selected environment file overrides unrelated shell key')
    result = subprocess.run(['python3', str(ROOT / 'scripts/prepare_gl_remarks_sql.py'), '--environment', 'uat', '--env-file', str(env_file), '--output-dir', str(ROOT / 'sql/should_not_exist')], capture_output=True)
    check(result.returncode != 0 and not (ROOT / 'sql/should_not_exist').exists(), 'Rendered keys cannot be written inside checkout')

print(f'PASS: {CHECKS} isolated GL/journal remark checks; all four complete SQL variants installed twice.')
