#!/usr/bin/env python3
"""Current SQL execution checks, using only the local memo fixture socket.

Reuse the established memo fixture declarations. Its historical HEAD text comparison
predates the collection-header helper, so this suite tests current routine execution
with that dependency installed rather than comparing against an older release.
"""
import ast
import os
from pathlib import Path
import tempfile

ROOT = Path(__file__).resolve().parents[1]
fixture = ROOT / 'tests/memo_journal_references_check.py'
tree = ast.parse(fixture.read_text())
declarations = []
for node in tree.body:
    if isinstance(node, ast.For):
        break
    declarations.append(node)
namespace = {'__file__': str(fixture), '__name__': 'memo_fixture_declarations'}
exec(compile(ast.Module(body=declarations, type_ignores=[]), str(fixture), 'exec'), namespace)
socket = os.environ.get('BILLING_FIXTURE_SOCKET', '/private/tmp/billing_deposit_fixture_20261021/mysql.sock')
assert socket.startswith('/private/tmp/'), 'Dedicated local socket required'
namespace['SOCKET'] = socket
install = namespace['install']
objects_sql = namespace['objects_sql']
check = namespace['check']
for suffix in ['', '_uat']:
    schema = 'memo_allocations_accounting_fixture' + suffix
    install(None, f'DROP DATABASE IF EXISTS `{schema}`; CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    try:
        install(schema, namespace['setup'] + '''
ALTER TABLE journal_entry ADD is_migrated INT DEFAULT 0;
ALTER TABLE general_ledger ADD is_migrated INT DEFAULT 0;
CREATE TABLE billing_payment(id INT PRIMARY KEY,remarks LONGTEXT);
CREATE TABLE credit_memo_entries(id INT AUTO_INCREMENT PRIMARY KEY,credit_memo_id INT,billing_entry_id INT,billing_id INT,branch_id INT,amount DECIMAL(13,2),is_deleted INT DEFAULT 0);
CREATE TABLE debit_memo_entries(id INT AUTO_INCREMENT PRIMARY KEY,debit_memo_id INT,billing_entry_id INT,billing_id INT,branch_id INT,amount DECIMAL(13,2),is_deleted INT DEFAULT 0);
''')
        source = (ROOT / f'sql/general_ledger_triggers_master{suffix}.sql').read_text()
        common = (ROOT / 'sql/fragments/general_ledger_remarks_common.sql').read_text()
        objects = [('FUNCTION', 'gl_billing_payment_journal_remark')] + namespace['new_objects'] + namespace['poster_objects']
        install(schema, objects_sql(common, [('FUNCTION', 'gl_remark_append')]) + objects_sql(source, objects))
        db = namespace['module'].Database(schema, namespace['MYSQL'], socket)
        try:
            history = db.query('SELECT * FROM journal_entry WHERE id=76061')
            for kind in ['credit', 'debit']:
                memo_id = 100
                db.query(f"INSERT INTO {kind}_memo VALUES({memo_id},'ALLOCATION-FIXTURE',2,4,261,57,'2026-10-22',123.45,'pending',0,67,NOW())")
                db.query(f'INSERT INTO {kind}_memo_entries({kind}_memo_id,billing_entry_id,billing_id,branch_id,amount) VALUES ({memo_id},1,2,4,100),({memo_id},2,2,4,23.45)')
                check(db.scalar(f"SELECT COUNT(*) FROM general_ledger WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0") == '0', 'Children do not post pending memos')
                db.query(f"UPDATE {kind}_memo SET status='approved' WHERE id={memo_id}")
                check(db.scalar(f"SELECT COUNT(*) FROM journal_entry WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0 AND total_debit=123.45 AND total_credit=123.45") == '1', 'One balanced header-based journal')
                check(db.scalar(f"SELECT COUNT(*) FROM general_ledger WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0") == '2', 'No duplicate child GL postings')
                check(db.scalar(f"SELECT COUNT(*) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source='{kind}_memo' AND j.source_id={memo_id} AND j.is_deleted=0 AND i.is_deleted=0") == '2', 'Matching journal items')
                db.query(f'CALL gl_post_{kind}_memo({memo_id})')
                check(db.scalar(f"SELECT COUNT(*) FROM journal_entry WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0") == '1', 'Repeated posting has one journal and no recursion')
                db.query(f"UPDATE {kind}_memo_entries SET is_deleted=1 WHERE {kind}_memo_id={memo_id}")
                check(db.scalar(f"SELECT COUNT(*) FROM general_ledger WHERE source='{kind}_memo' AND source_id={memo_id} AND is_deleted=0") == '2', 'Child edits do not retire existing accounting')
            check(db.query('SELECT * FROM journal_entry WHERE id=76061') == history, 'Unrelated accounting history unchanged')
        finally:
            db.close(Path(tempfile.mkdtemp(prefix='memo_allocations_sql_')))
    finally:
        install(None, f'DROP DATABASE `{schema}`;')
print(f"PASS {namespace['checks']} current master/UAT memo accounting checks")
