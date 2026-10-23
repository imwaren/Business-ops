"""Synthetic full accounting checks on selected private schema copies; no network."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
CLIENT = ['/Applications/XAMPP/xamppfiles/bin/mysql', '--no-defaults',
          '--socket=/private/tmp/items_blindsheet_fixture/mysql.sock', '-u', 'root',
          '--batch', '--skip-column-names', '--default-character-set=utf8mb4', '--binary-mode', '--comments']
MASTER = 'weighted_uat_fixture_master'
OAKRIDGE = 'weighted_uat_fixture_oakridge'
KEY = b'weighted-cost-fixture-only-key'
CHECKS = 0
ENVIRONMENT = 'uat'


def check(condition, label):
    global CHECKS
    if not condition:
        raise AssertionError(label)
    CHECKS += 1


def sql(statement, database=None, failure=False):
    result = subprocess.run(CLIENT + ([database] if database else []),
                            input='SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n' + statement,
                            text=True, capture_output=True)
    if failure:
        check(result.returncode != 0, 'Expected transactional failure')
        return
    if result.returncode:
        raise RuntimeError(result.stderr.replace(KEY.hex(), '[fixture-key]')[-3500:])
    return result.stdout.strip()


def rewrite(text):
    suffix = '_uat' if ENVIRONMENT == 'uat' else ''
    text = text.replace('mytemr_master' + suffix, MASTER).replace('mytemr_diagnostic' + suffix, OAKRIDGE).replace('__GL_ENCRYPTION_KEY_HEX__', KEY.hex())
    # A privately rendered release may be used as input; tests always use their own key.
    return re.sub(r"UNHEX\('[0-9a-fA-F]+'\)", "UNHEX('" + KEY.hex() + "')", text)


def seed(database, table, supplied):
    values = dict(supplied)
    metadata = sql('SHOW COLUMNS FROM `' + table + '`', database).splitlines()
    names = {line.split('\t')[0] for line in metadata}
    for key, value in [('added_on', '2026-10-15 08:00:00'), ('added_by', 67)]:
        if key in names:
            values.setdefault(key, value)
    for line in metadata:
        parts = line.split('\t')
        name, kind, nullable, _, default, extra = (parts + [''] * 6)[:6]
        if name in values or nullable == 'YES' or default != 'NULL' or 'auto_increment' in extra:
            continue
        if kind.startswith('enum('):
            values[name] = re.findall(r"'([^']*)'", kind)[0]
        elif kind.startswith(('int','bigint','tinyint','smallint','decimal','float','double','mediumint')):
            values[name] = 0
        elif kind.startswith(('date','timestamp')):
            values[name] = '2026-10-15' if kind == 'date' else '2026-10-15 08:00:00'
        elif kind.startswith('time'):
            values[name] = '08:00:00'
        else:
            values[name] = ''
    def literal(value):
        if value is None:
            return 'NULL'
        if isinstance(value, (int, float)):
            return str(value)
        return "'" + value.replace('\\', '\\\\').replace("'", "''") + "'"
    sql('INSERT INTO `' + table + '` (' + ','.join('`' + key + '`' for key in values) + ') VALUES ('
        + ','.join(literal(value) for value in values.values()) + ')', database)


def install(manifest, schema):
    text = 'SET SESSION sql_mode=\'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION\';\nDELIMITER $$\n'
    for obj in manifest['full_objects']:
        text += 'DROP ' + obj['kind'] + ' IF EXISTS `' + obj['name'] + '`$$\n' + rewrite(obj['definition']) + '$$\n'
    sql(text + 'DELIMITER ;', schema)


def active(database, source, source_id):
    return sql("SELECT account_type_id,debit,credit FROM general_ledger WHERE source='" + source
               + "' AND source_id=" + str(source_id) + ' AND is_deleted=0 ORDER BY id', database)


def main():
    global ENVIRONMENT, MASTER, OAKRIDGE
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('private_fixture_dir', type=Path)
    args = parser.parse_args()
    folder = args.private_fixture_dir
    manifest = json.loads((folder / 'package/manifest.json').read_text())
    ENVIRONMENT = manifest['environment']
    check(ENVIRONMENT in ['uat', 'prod'], 'Explicit supported package environment')
    prefix = 'weighted_' + ENVIRONMENT + '_fixture_'
    MASTER, OAKRIDGE = prefix + 'master', prefix + 'oakridge'
    check(not any(obj['name'] in manifest['excluded'] for obj in manifest['full_objects']), 'No backfill procedures packaged')
    prereqs = rewrite((folder / 'package/prerequisites.sql').read_text())
    created = []
    legacy = json.loads((folder / 'legacy_objects.json').read_text()) if ENVIRONMENT == 'prod' else {}
    deployment_spec = importlib.util.spec_from_file_location('fixture_weighted_deployment', folder / 'package/deploy.py')
    deployment = importlib.util.module_from_spec(deployment_spec)
    deployment_spec.loader.exec_module(deployment)
    deployment.support.CLIENT = CLIENT
    deployment.support.ERROR_DIR = folder
    try:
        for label, schema in [('master', MASTER), ('oakridge', OAKRIDGE),
                              ('warehouse', prefix + 'branch_5'), ('pharmacy', prefix + 'branch_6')]:
            check(schema.startswith(prefix), 'Disposable schema guard')
            sql('DROP DATABASE IF EXISTS `' + schema + '`; CREATE DATABASE `' + schema + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            created.append(schema)
            dump = (folder / (label + '_schema.sql')).read_text()
            dump = re.sub(r'^/\*M!999999.*?\*/\s*', '', dump)
            # Local MariaDB 10.4 predates this server-default collation; column types remain exact.
            dump = dump.replace('utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci')
            sql(rewrite(dump), schema)
        source_path = 'sql/general_ledger_triggers' + ('_uat' if ENVIRONMENT == 'uat' else '') + '.sql'
        codes = sorted(set(re.findall(r"'([0-9]{3}-[0-9A-Z]{3,5})'", (ROOT / source_path).read_text())))
        accounts = {code: index for index, code in enumerate(codes, 1)}
        for code, account_id in accounts.items():
            seed(MASTER, 'account_type', {'id': account_id, 'code': code, 'name': 'Synthetic ' + code, 'status': 'active', 'is_deleted': 0})
        seed(MASTER, 'item', {'id': 1, 'name': 'Synthetic supply Ω', 'cost': 99, 'weighted_cost': 5,
                            'account_type_id': accounts['500-205'], 'is_deleted': 0})
        for branch in [5, 6]:
            database = prefix + 'branch_' + str(branch)
            deployment.SCHEMAS = {branch: database}
            if legacy:
                for name, value in legacy[str(branch)].items():
                    definition = re.sub(r'^CREATE DEFINER=\S+\s+', 'CREATE ', rewrite(value['definition']))
                    sql('DELIMITER $$\n' + definition + '$$\nDELIMITER ;', database)
            old_objects = {obj['name']: deployment.show_exact(database, obj['kind'], obj['name'])
                           if deployment.exists(database, obj) else None for obj in manifest['full_objects']}
            recovery_state = {'outside': deployment.outside_definitions(), 'schemas': {
                database: {'objects': old_objects, 'protected': deployment.protected(database, manifest['full_objects'])}}}
            legacy_hex = sql("SELECT HEX(ROUTINE_DEFINITION) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_post_direct_payment'", database) if legacy else None
            seed(database, 'accounts_payable_voucher', {'id': 500, 'type': 'direct_payment', 'type_id': 500, 'amount': 100, 'status': 'approved', 'is_deleted': 0})
            seed(database, 'direct_payment', {'id': 500, 'amount': 100, 'status': 'approved', 'is_deleted': 0})
            seed(database, 'general_ledger', {'id': 777, 'branch_id': branch, 'date': '2026-10-01', 'debit': 1, 'credit': 0, 'remark': 'Synthetic old ledger'})
            original_columns = [line.split('\t')[0] for line in sql('SHOW COLUMNS FROM general_ledger', database).splitlines()]
            projection = ','.join('`' + name + '`' for name in original_columns)
            before = sql('SELECT ' + projection + ' FROM general_ledger ORDER BY id', database)
            sql(prereqs, database)
            sql('INSERT IGNORE INTO gl_settings VALUES(' + str(branch) + ')', database)
            sql(prereqs, database)
            cutover = sql('SELECT * FROM apv_accounting_cutover', database)
            install(manifest, database)
            install(manifest, database)
            if legacy:
                labeled = deployment.show_exact(database, 'PROCEDURE', 'gl_post_apv')['definition']
                check(labeled.count('proc: BEGIN') == 1, 'Labeled routine snapshots retain exactly one body label')
            check(before == sql('SELECT ' + projection + ' FROM general_ledger ORDER BY id', database), 'Repeated schema/routine installation leaves historical columns unchanged')
            check(sql('SELECT gl_branch_id()', database) == str(branch), 'Correct Warehouse/Pharmacy identity')
            # Every non-argument helper is safe; exercise every one-ID poster against missing sources.
            for obj in manifest['full_objects']:
                if obj['kind'] == 'PROCEDURE' and re.search(r'\(IN p_\w+ INT\)', obj['definition']):
                    sql('CALL `' + obj['name'] + '`(0)', database)
                    check(True, 'All source-query dependencies resolve: ' + obj['name'])
            for unit, kind in [(1, 'purchase'), (2, 'clinic'), (3, 'usage')]:
                seed(database, 'item_conversion', {'id': unit, 'item_id': 1, 'unit': kind, 'conversion_type': kind, 'qty': 1 if unit < 3 else 1000, 'is_deleted': 0})
            seed(database, 'branch_item', {'id': 1, 'item_id': 1, 'branch_id': branch, 'unit': 2, 'current_qty': 10, 'beggining_qty': 10})
            seed(database, 'branch_item_blindsheet', {'id': 1, 'branch_item_id': 1, 'branch_id': branch, 'item_id': 1,
                 'physical_count': 10.05, 'prev_qty': 10, 'difference': .05, 'date_counted': '2026-10-15', 'status': 'approved'})
            for index, activity in enumerate(['Machine Run', 'perform', 'lab supplies', 'pharmacy', 'Adjustment', 'Blindsheet', 'Transfer In', 'Transfer Out'], 1):
                seed(database, 'item_history', {'id': index, 'item_id': 1, 'branch_item_id': 1, 'branch_id': branch, 'unit': 2,
                     'activity': activity, 'activity_id': 1 if activity == 'Blindsheet' else 0, 'quantity': .05, 'is_deleted': 0})
                expected = 1 if activity.startswith('Transfer') else 2
                vector = active(database, 'item_history', index)
                check(len(vector.splitlines()) == expected and all('.2500' in row for row in vector.splitlines()), 'Weighted cost, fractional quantity and mappings: ' + activity)
                sql('CALL gl_post_item_history(' + str(index) + ')', database)
                check(vector == active(database, 'item_history', index), 'Repeated posting preserves one active movement')
                check(sql("SELECT COUNT(*) FROM journal_entry WHERE source='item_history' AND source_id=" + str(index) + " AND status='approved' AND cost_center_id=" + str(branch) + ' AND is_deleted=0', database) == '1', 'Approved journal in correct branch')
            # The exact live master schema makes weighted_cost NOT NULL; nullable-source
            # behavior is covered separately by items_blindsheet_check.php.
            for value in ['0']:
                sql('UPDATE `' + MASTER + '`.item SET weighted_cost=' + value + ' WHERE id=1; CALL gl_post_item_history(1)', database)
                check(active(database, 'item_history', 1) == '', 'Null/zero WAC never uses purchase cost 99')
            sql('UPDATE `' + MASTER + '`.item SET weighted_cost=5 WHERE id=1', database)
            seed(database, 'item_history', {'id': 90, 'item_id': 1, 'branch_item_id': 1, 'branch_id': 99, 'unit': 2,
                 'activity': 'Blindsheet', 'activity_id': 1, 'quantity': .05, 'is_deleted': 0})
            check(active(database, 'item_history', 90) == '', 'Other-branch mirrored blindsheet cannot post here')
            seed(database, 'accounts_payable_voucher', {'id': 600, 'type': 'direct_payment', 'type_id': 600, 'amount': 100, 'status': 'pending', 'is_deleted': 0})
            sql('UPDATE accounts_payable_voucher SET status=\'approved\' WHERE id=600', database, failure=True)
            check(sql('SELECT status FROM accounts_payable_voucher WHERE id=600', database) == 'pending', 'Invalid APV approval rolls back')
            sql("SET @apv_entry_sync=1; INSERT INTO accounts_payable_voucher_entry(accounts_payable_voucher_id,account_type_id,debit,credit,added_by,added_on) VALUES(600,"
                + str(accounts['500-205']) + ',100,0,67,NOW()),(600,' + str(accounts['201-111']) + ",0,100,67,NOW()); SET @apv_entry_sync=0; UPDATE accounts_payable_voucher SET status='approved' WHERE id=600", database)
            check(len(active(database, 'accounts_payable_voucher', 600).splitlines()) == 2, 'Entry-based APV posts balanced accounting')
            seed(database, 'accounts_payable_voucher_payment_detail', {'id': 600, 'accounts_payable_voucher_id': 600,
                 'amount': 100, 'date': '2026-10-15', 'is_deleted': 0})
            check(len(active(database, 'accounts_payable_voucher_payment_detail', 600).splitlines()) == 2, 'APV payment posts payable/asset clearing')
            sql('UPDATE accounts_payable_voucher SET updated_by=68 WHERE id=500', database)
            check(active(database, 'accounts_payable_voucher', 500) == '', 'Legacy APV unrelated edit does not synthesize history')
            sql(prereqs, database)
            check(sql('SELECT * FROM apv_accounting_cutover', database) == cutover, 'Redeployment never advances APV cutover')
            # Strict-mode actual source schemas: transaction, payment and deposits.
            seed(database, 'transaction', {'id': 10, 'transaction_no': 'SYNTHETIC-10', 'grand_total': 100, 'transaction_date': '2026-10-15', 'is_deleted': 0})
            seed(database, 'payment', {'id': 10, 'transaction_id': 10, 'grand_total': 100, 'discount': 0, 'is_deleted': 0})
            seed(database, 'payment_multiple_method', {'id': 10, 'payment_id': 10, 'type': 'cash', 'amount': 100, 'is_deleted': 0})
            check(len(active(database, 'payment_multiple_method', 10).splitlines()) >= 2, 'Cash payment creates GL in actual schema')
            seed(database, 'bank', {'id': 10, 'account_type_ids': str(accounts['102-192']), 'mop': 'card', 'is_deleted': 0})
            seed(database, 'cash_deposit', {'id': 10, 'deposit_date': '2026-10-15', 'amount': 100, 'bank_id': 10, 'status': 'posted', 'is_deleted': 0})
            check(len(active(database, 'cash_deposit', 10).splitlines()) == 2, 'Cash deposit creates balanced movement')
            seed(database, 'transaction', {'id': 11, 'transaction_no': 'SYNTHETIC-11', 'grand_total': 100, 'transaction_date': '2026-10-15', 'is_deleted': 0})
            seed(database, 'payment', {'id': 11, 'transaction_id': 11, 'grand_total': 100, 'discount': 0, 'is_deleted': 0})
            seed(database, 'payment_multiple_method', {'id': 11, 'payment_id': 11, 'type': 'card', 'amount': 100, 'is_deleted': 0})
            ncd_id = sql('SELECT id FROM non_cash_deposit WHERE payment_multiple_method_id=11 AND is_deleted=0', database)
            check(bool(ncd_id), 'Card payment creates a pending non-cash deposit')
            sql("UPDATE non_cash_deposit SET status='posted',bank_service_fee_percentage=2 WHERE id=" + ncd_id, database)
            check(len(active(database, 'non_cash_deposit', int(ncd_id)).splitlines()) == 3, 'Non-cash deposit includes bank fee and asset clearing')
            seed(database, 'receipt_note', {'id': 10, 'status': 'approved', 'receive_date': '2026-10-15', 'is_deleted': 0})
            seed(database, 'receipt_note_item', {'id': 10, 'receipt_note_id': 10, 'item_id': 1, 'amount': 100, 'vat': 0,
                 'vat_type': 'nvat', 'account_type_id': accounts['104-030'], 'is_deleted': 0})
            check(len(active(database, 'receipt_note_item', 10).splitlines()) == 2, 'Purchasing receipt creates inventory/transitory payable movement')
            original = active(database, 'item_history', 2)
            sql("START TRANSACTION; UPDATE item_history SET quantity=9 WHERE id=2; ROLLBACK;", database)
            check(active(database, 'item_history', 2) == original, 'Trigger-generated GL/JE rolls back with business update')
            financial_history = sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;', database)
            deployment.restore(manifest, recovery_state)
            check(sql('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()', database) == '0', 'Recovery removes introduced hooks')
            check(financial_history == sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;', database), 'Definition recovery does not mutate accounting records')
            if legacy:
                restored_hex = sql("SELECT HEX(ROUTINE_DEFINITION) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_post_direct_payment'", database)
                check(restored_hex == legacy_hex and '0D0A' in restored_hex, 'Legacy procedure bytes/CRLF restored exactly')
                check(deployment.protected(database, manifest['full_objects']) == recovery_state['schemas'][database]['protected'], 'Both census definitions preserved exactly')
                check(sql("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()", database) == '3', 'Only the original three routines remain after recovery')
            install(manifest, database)
            check(sql('SELECT gl_branch_id()', database) == str(branch), 'Full installation succeeds after definition recovery')
    finally:
        for schema in reversed(created):
            sql('DROP DATABASE IF EXISTS `' + schema + '`')
    print(f'PASS: {CHECKS} {ENVIRONMENT} Warehouse/Pharmacy schema-copy installation checks.')


if __name__ == '__main__':
    main()
