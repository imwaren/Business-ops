#!/usr/bin/env python3
"""VPS6 environment-scoped installer. No posting, financial rebuild, or backfill calls."""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import time

PACKAGE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('weighted_gl_support', PACKAGE / 'gl_support.py')
support = importlib.util.module_from_spec(spec)
spec.loader.exec_module(support)
BASE_SCHEMAS = {1: 'mytemr_diagnostic_mobile', 2: 'mytemr_diagnostic',
                3: 'mytemr_diagnostic_mactan', 4: 'mytemr_diagnostic_conso',
                7: 'mytemr_diagnostic_mantlewood', 5: 'mytemr_oakridge_warehouse',
                6: 'mytemr_oakridge_pharmacy'}
ENVIRONMENT = 'uat'
SCHEMAS = {branch: name + '_uat' for branch, name in BASE_SCHEMAS.items()}
MASTER_SCHEMA = 'mytemr_master_uat'
SNAPSHOT_TABLES = ['general_ledger', 'journal_entry', 'journal_entry_item', 'branch_item',
    'branch_item_blindsheet', 'branch_item_adjustment', 'item_history', 'item', 'item_conversion',
    'item_cost', 'transaction', 'patient', 'booking', 'booking_details', 'booking_package_details',
    'booking_medicine', 'payment', 'payment_multiple_method', 'receipt_note', 'receipt_note_item',
    'purchase_order', 'purchase_order_item', 'purchase_request', 'purchase_request_item',
    'accounts_payable_voucher', 'accounts_payable_voucher_entry',
    'accounts_payable_voucher_amortization_entry', 'apv_accounting_cutover',
    'accounts_payable_voucher_payment_detail', 'direct_payment', 'direct_payment_entry',
    'change_fund', 'liquidation', 'cash_advance', 'reimbursement', 'replenishment', 'petty_cash',
    'cash_deposit', 'non_cash_deposit', 'bank', 'bank_service_fee']
query, scalar, require, literal = support.query, support.scalar, support.require, support.literal


def configure_environment(environment):
    global ENVIRONMENT, SCHEMAS, MASTER_SCHEMA
    require(environment in ['uat', 'prod'], 'Unknown deployment environment')
    ENVIRONMENT = environment
    suffix = '_uat' if environment == 'uat' else ''
    SCHEMAS = {branch: name + suffix for branch, name in BASE_SCHEMAS.items()}
    MASTER_SCHEMA = 'mytemr_master' + suffix


def show_exact(database, kind, name):
    value = support.show(database, kind, name)
    if kind in ['PROCEDURE', 'FUNCTION']:
        raw = scalar('SELECT HEX(ROUTINE_DEFINITION) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='
                     + literal(database) + ' AND ROUTINE_NAME=' + literal(name))
        # XML normalizes CRLF; recover original bytes for exact legacy restoration.
        raw_body = bytes.fromhex(raw).decode()
        normalized_body = raw_body.replace('\r\n', '\n').replace('\r', '\n')
        require(normalized_body and value['definition'].endswith(normalized_body), 'Routine body boundary mismatch: ' + name)
        value['definition'] = value['definition'][:-len(normalized_body)] + raw_body
        value['fields']['Create ' + kind.title()] = value['definition']
    return value


def save(folder, name, value):
    support.save(folder / name, value)


def targets(manifest, branch):
    return manifest['full_objects'] if branch in [5, 6] else manifest['clinic_objects']


def exists(database, obj):
    table, prefix = ('TRIGGERS', 'TRIGGER') if obj['kind'] == 'TRIGGER' else ('ROUTINES', 'ROUTINE')
    return scalar(f'SELECT COUNT(*) FROM information_schema.{table} WHERE {prefix}_SCHEMA='
                  + literal(database) + f' AND {prefix}_NAME=' + literal(obj['name'])) == '1'


def protected(database, excluded):
    names = ','.join(literal(obj['name']) for obj in excluded) or "''"
    routine_data = query('SELECT ROUTINE_NAME,ROUTINE_TYPE,HEX(ROUTINE_DEFINITION),DEFINER,SQL_MODE,'
        'SECURITY_TYPE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION '
        'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=' + literal(database)
        + ' AND ROUTINE_NAME NOT IN (' + names + ') ORDER BY ROUTINE_NAME')
    trigger_data = query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,'
        'ACTION_ORDER,HEX(ACTION_STATEMENT),DEFINER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,'
        'DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=' + literal(database)
        + ' AND TRIGGER_NAME NOT IN (' + names + ') ORDER BY TRIGGER_NAME')
    return {'routines': hashlib.sha256(routine_data).hexdigest(), 'triggers': hashlib.sha256(trigger_data).hexdigest()}


def outside_definitions():
    # Protect master and the other environment as well as every other schema.
    names = ','.join(map(literal, SCHEMAS.values()))
    statements = ["SELECT ROUTINE_SCHEMA,ROUTINE_NAME,HEX(ROUTINE_DEFINITION),DEFINER,SQL_MODE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA NOT IN (" + names + ') ORDER BY ROUTINE_SCHEMA,ROUTINE_NAME',
        "SELECT TRIGGER_SCHEMA,TRIGGER_NAME,HEX(ACTION_STATEMENT),DEFINER,SQL_MODE,ACTION_ORDER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA NOT IN (" + names + ') ORDER BY TRIGGER_SCHEMA,TRIGGER_NAME']
    return [hashlib.sha256(query(statement)).hexdigest() for statement in statements]


def columns(database):
    rows = query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='
                 + literal(database) + ' AND TABLE_NAME IN (' + ','.join(map(literal, SNAPSHOT_TABLES))
                 + ') ORDER BY TABLE_NAME,ORDINAL_POSITION').decode().splitlines()
    result = {}
    for row in rows:
        table, column = row.split('\t')
        result.setdefault(table, []).append(column)
    return result


def fingerprints(column_maps):
    result = {}
    for database, tables in column_maps.items():
        for table, names in tables.items():
            # Normalize only the intentional INT -> DECIMAL widening, before and after.
            projection = ','.join('CAST(`quantity` AS DECIMAL(13,2))' if table == 'item_history' and name == 'quantity'
                                  else '`' + name + '`' for name in names)
            process = subprocess.Popen(support.CLIENT + [database], stdin=subprocess.PIPE,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            process.stdin.write(('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SELECT ' + projection
                                 + ' FROM `' + table + '` ORDER BY id;').encode())
            process.stdin.close()
            digest, row_count = hashlib.sha256(), 0
            while chunk := process.stdout.read(1024 * 1024):
                digest.update(chunk)
            error = process.stderr.read()
            require(process.wait() == 0, 'Fingerprint failed: ' + database + '.' + table)
            row_count = int(scalar('SELECT COUNT(*) FROM `' + table + '`', database))
            result[database + '.' + table] = {'rows': row_count, 'sha256': digest.hexdigest()}
        print('Fingerprinted ' + database, flush=True)
    return result


def inspect(manifest, folder):
    require(manifest['environment'] == ENVIRONMENT, 'Package/deployment environment mismatch')
    require('__GL_ENCRYPTION_KEY_HEX__' not in json.dumps(manifest), 'Unrendered package')
    require(scalar("SELECT COUNT(*) FROM mysql.user WHERE BINARY Super_priv=BINARY 'Y' AND User NOT IN ('root','mysql')") == '0', 'Unreviewed privileged database user')
    for code in manifest['account_codes']:
        require(scalar('SELECT COUNT(*) FROM ' + MASTER_SCHEMA + '.account_type WHERE code='
                       + literal(code) + ' AND parent_id IS NULL AND is_deleted=0') == '1',
                'Missing or ambiguous account mapping: ' + code)
    state = {'schemas': {}, 'outside': outside_definitions()}
    def table_signature(definition):
        return support.canonical(re.sub(r'\bAUTO_INCREMENT=\d+', '', definition).rstrip(';'))
    require(table_signature(support.show(SCHEMAS[2], 'TABLE', 'non_cash_deposit')['definition'])
            == table_signature(manifest['non_cash_template']), 'Canonical non-cash schema changed since fixture inspection')
    for branch, database in SCHEMAS.items():
        require(database == BASE_SCHEMAS[branch] + ('_uat' if ENVIRONMENT == 'uat' else ''), 'Wrong environment target rejected')
        own = {}
        if branch in [5, 6]:
            for table, expected in manifest['schema_before'][str(branch)].items():
                actual = support.show(database, 'TABLE', table)['definition']
                require(table_signature(actual) == table_signature(expected), 'Source schema drift: ' + database + '.' + table)
        for obj in targets(manifest, branch):
            old = show_exact(database, obj['kind'], obj['name']) if exists(database, obj) else None
            if branch in [5, 6]:
                expected_old = manifest.get('existing_objects', {}).get(str(branch), {}).get(obj['name'])
                require(old == expected_old, 'Unexpected existing accounting definition: ' + database + '.' + obj['name'])
            else:
                require(old is not None, 'Existing clinic poster missing')
                acceptable = [support.canonical(support.body(item['definition'])) for item in
                              [manifest['clinic_baseline'], manifest['clinic_objects'][0]]]
                require(support.canonical(support.body(old['definition'])) in acceptable,
                        'Clinic poster drift: ' + database)
                require(scalar('SELECT COUNT(*) FROM gl_settings WHERE branch_id=' + str(branch), database) == '1'
                        and scalar('SELECT COUNT(*) FROM gl_settings', database) == '1', 'Invalid clinic identity')
            own[obj['name']] = old
        column_map = columns(database)
        for table in ['general_ledger', 'journal_entry', 'journal_entry_item', 'item_history', 'branch_item',
                      'item', 'item_conversion', 'accounts_payable_voucher', 'direct_payment']:
            require(table in column_map, 'Required source table missing: ' + database + '.' + table)
        require('weighted_cost' in column_map['item'], 'Weighted cost source column missing')
        engines = scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='
            + literal(database) + ' AND TABLE_NAME IN (' + ','.join(map(literal, column_map))
            + ") AND ENGINE<>'InnoDB'")
        require(engines == '0', 'Nontransactional accounting source: ' + database)
        state['schemas'][database] = {'branch': branch, 'objects': own, 'columns': column_map,
            'protected': protected(database, targets(manifest, branch))}
        print('Preflight passed ' + database, flush=True)
    # Capture creation metadata for new objects from their installed clinic equivalents.
    state['templates'] = {obj['name']: show_exact(SCHEMAS[2], obj['kind'], obj['name'])
                          for obj in manifest['full_objects']}
    for obj in manifest['full_objects']:
        expected = [support.canonical(support.body(obj['definition']))]
        if obj['name'] == 'gl_post_item_history':
            expected.append(support.canonical(support.body(manifest['clinic_baseline']['definition'])))
        require(support.canonical(support.body(state['templates'][obj['name']]['definition'])) in expected,
                'Unreviewed diagnostic financial/helper definition: ' + obj['name'])
    save(folder, 'inspection.json', state)
    return state


def install(database, obj, template):
    fields = template['fields']
    user, host = fields.get('Definer', 'root@localhost').rsplit('@', 1)
    definer = '`' + user.replace('`', '``') + '`@`' + host.replace('`', '``') + '`'
    definition = re.sub(r'^CREATE\s+', lambda _: 'CREATE DEFINER=' + definer + ' ', obj['definition'], count=1)
    charset, collation = fields['character_set_client'], fields['collation_connection']
    require(re.fullmatch(r'\w+', charset) and re.fullmatch(r'\w+', collation), 'Invalid SQL charset metadata')
    old_collation = scalar('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=' + literal(database))
    creation_collation = fields.get('Database Collation', old_collation)
    require(re.fullmatch(r'\w+', creation_collation), 'Invalid database collation')
    try:
        if creation_collation != old_collation:
            query('ALTER DATABASE `' + database + '` COLLATE ' + creation_collation)
        query('SET SESSION sql_mode=' + literal(fields['sql_mode']) + ';\nSET NAMES ' + charset
              + ' COLLATE ' + collation + ';\nDELIMITER $$\nDROP ' + obj['kind'] + ' IF EXISTS `'
              + obj['name'] + '`$$\n' + definition + '$$\nDELIMITER ;\n', database)
    finally:
        if creation_collation != old_collation:
            query('ALTER DATABASE `' + database + '` COLLATE ' + old_collation)


def restore(manifest, state):
    for branch, database in SCHEMAS.items():
        for obj in sorted(targets(manifest, branch), key=lambda value: value['kind'] != 'TRIGGER'):
            old = state['schemas'][database]['objects'][obj['name']]
            if old:
                # Restore clinic posters and reviewed legacy procedures byte-for-byte.
                original = dict(obj, definition=re.sub(r'^CREATE DEFINER=\S+\s+', 'CREATE ', old['definition']))
                install(database, original, old)
            else:
                query('DROP ' + obj['kind'] + ' IF EXISTS `' + obj['name'] + '`', database)
    verify_protected(manifest, state)
    for branch, database in SCHEMAS.items():
        for obj in targets(manifest, branch):
            old = state['schemas'][database]['objects'][obj['name']]
            if old:
                require(show_exact(database, obj['kind'], obj['name']) == old, 'Original definition recovery mismatch')
            else:
                require(not exists(database, obj), 'New accounting hook remains after rollback')


def verify_protected(manifest, state):
    require(outside_definitions() == state['outside'], 'Out-of-scope master/other-environment definitions changed')
    for branch, database in SCHEMAS.items():
        require(protected(database, targets(manifest, branch)) == state['schemas'][database]['protected'],
                'Unrelated definitions changed: ' + database)


def verify(manifest, state, held=False):
    result = {}
    for branch, database in SCHEMAS.items():
        hashes = {}
        for obj in targets(manifest, branch):
            actual = show_exact(database, obj['kind'], obj['name'])
            expected = support.canonical(support.body(obj['definition']))
            require(support.canonical(support.body(actual['definition'])) == expected,
                    'Installed definition mismatch: ' + database + '.' + obj['name'])
            old = state['schemas'][database]['objects'][obj['name']] or state['templates'][obj['name']]
            for key in ['sql_mode', 'character_set_client', 'collation_connection', 'Database Collation', 'Definer']:
                require(actual['fields'].get(key) == old['fields'].get(key), 'Creation metadata mismatch: ' + database + '.' + obj['name'] + '.' + key)
            hashes[obj['name']] = hashlib.sha256(expected.encode()).hexdigest()
        require(scalar('SELECT gl_branch_id()', database) == str(branch), 'Installed branch identity mismatch')
        if branch in [5, 6]:
            require(scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('journal_entry','journal_entry_item') AND COLUMN_NAME='remarks' AND DATA_TYPE='longtext'", database) == '2', 'Missing LONGTEXT support')
            require(scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item_history' AND COLUMN_NAME='quantity' AND DATA_TYPE='decimal' AND NUMERIC_PRECISION=13 AND NUMERIC_SCALE=2", database) == '1', 'Fractional history schema missing')
            for table in ['accounts_payable_voucher_entry', 'accounts_payable_voucher_amortization_entry', 'non_cash_deposit']:
                if held and table not in state['schemas'][database]['columns']:
                    require(scalar('SELECT COUNT(*) FROM `' + table + '`', database) == '0', 'New table contains unexpected records')
            require(scalar('SELECT * FROM apv_accounting_cutover WHERE id=1', database)
                    == state['cutovers'][database], 'Immutable cutover changed')
            require(scalar("SELECT gl_transaction_context(0) IS NULL AND CHAR_LENGTH(gl_remark_append('check', REPEAT('Unicode Ω ',1000)))>9000", database) == '1', 'Read-only remark helper checks failed')
        result[database] = {'branch': branch, 'objects': len(hashes), 'hashes': hashes}
    verify_protected(manifest, state)
    return result


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['inspect', 'deploy', 'verify'])
    parser.add_argument('--environment', choices=['uat', 'prod'], default='uat')
    args = parser.parse_args()
    configure_environment(args.environment)
    folder = PACKAGE.parent / ENVIRONMENT
    folder.mkdir(mode=0o700, exist_ok=True)
    support.ERROR_DIR = folder
    for name, expected in json.loads((PACKAGE / 'checksums.json').read_text()).items():
        require(re.fullmatch(r'[a-zA-Z0-9_.]+', name), 'Invalid release filename')
        require(hashlib.sha256((PACKAGE / name).read_bytes()).hexdigest() == expected, 'Release checksum mismatch: ' + name)
    manifest = json.loads((PACKAGE / 'manifest.json').read_text())
    with (PACKAGE.parent / 'deployment.lock').open('a') as handle:
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.mode == 'inspect':
            inspect(manifest, folder)
            return
        if args.mode == 'verify':
            state = json.loads((folder / 'inspection.json').read_text())
            save(folder, 'readonly_verified.json', verify(manifest, state))
            print('Read-only definition checks passed for all seven ' + ENVIRONMENT + ' schemas.', flush=True)
            return
        require(not (folder / 'maintenance.json').exists(), 'Use a new release directory for another deployment')
        state = inspect(manifest, folder)
        prior = {'read_only': scalar('SELECT @@global.read_only'), 'event_scheduler': scalar('SELECT @@global.event_scheduler'),
                 'cron': subprocess.run(['systemctl', 'is-active', 'cron'], capture_output=True, text=True).stdout.strip()}
        save(folder, 'maintenance.json', prior)
        save(folder, 'package_sha256.json', {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in PACKAGE.iterdir() if p.is_file()})
        (folder / 'RECOVERY.txt').write_text('Restore saved scoped definitions/remove new GL hooks before resuming writes. Retain compatible additive schema. Never load old table data over newer records. If recovery or fingerprints fail, keep read_only ON and cron paused. Exact settings, definitions and table data are retained here.\n')
        changed, recovery_ok, before = False, True, None
        column_maps = {database: value['columns'] for database, value in state['schemas'].items()}
        try:
            subprocess.run(['systemctl', 'stop', 'cron'], check=True)
            if prior['event_scheduler'] == 'ON':
                query('SET GLOBAL event_scheduler=OFF')
            query('SET GLOBAL read_only=ON')
            print('Write hold enabled; original scheduler settings recorded.', flush=True)
            for _ in range(30):
                if scalar('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_is_read_only=0') == '0':
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Write transactions did not drain')
            require(scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER='root' AND COMMAND<>'Sleep' AND ID<>CONNECTION_ID()") == '0', 'Concurrent privileged database operation')
            # Fresh exact backups under the write hold, before DDL.
            for database, value in state['schemas'].items():
                for label, options in [('tables', ['--single-transaction', '--skip-triggers', '--hex-blob']),
                                       ('definitions', ['--no-data', '--routines', '--triggers'])]:
                    with (folder / (database + '_' + label + '.sql')).open('wb') as backup:
                        command = ['mariadb-dump'] + options + [database]
                        if label == 'tables':
                            command += list(value['columns'])
                        outcome = subprocess.run(command, stdout=backup, stderr=subprocess.PIPE)
                        require(outcome.returncode == 0, 'Private backup failed: ' + database)
                print('Backed up ' + database, flush=True)
            before = fingerprints(column_maps)
            save(folder, 'fingerprints_before.json', before)
            verify_protected(manifest, state)
            # Reject any target drift between preflight and write hold.
            for branch, database in SCHEMAS.items():
                for obj in targets(manifest, branch):
                    old = state['schemas'][database]['objects'][obj['name']]
                    require((show_exact(database, obj['kind'], obj['name']) if exists(database, obj) else None) == old, 'Target drift under write hold')
            changed = True
            for branch, database in SCHEMAS.items():
                if branch in [5, 6]:
                    query((PACKAGE / 'prerequisites.sql').read_text(), database)
                    query('INSERT IGNORE INTO gl_settings(branch_id) VALUES(' + str(branch) + ')', database)
                    require(scalar('SELECT COUNT(*) FROM apv_accounting_cutover WHERE id=1 AND legacy_max_apv_id=(SELECT COALESCE(MAX(id),0) FROM accounts_payable_voucher) AND legacy_max_direct_payment_id=(SELECT COALESCE(MAX(id),0) FROM direct_payment)', database) == '1', 'New cutover does not match frozen sources')
                    state.setdefault('cutovers', {})[database] = scalar('SELECT * FROM apv_accounting_cutover WHERE id=1', database)
                for obj in targets(manifest, branch):
                    old = state['schemas'][database]['objects'][obj['name']] or state['templates'][obj['name']]
                    install(database, obj, old)
                print('Installed ' + database, flush=True)
            save(folder, 'inspection.json', state)
            definitions = verify(manifest, state, held=True)
            after = fingerprints(column_maps)
            save(folder, 'fingerprints_after.json', after)
            require(before == after, 'Historical data changed during installation')
            save(folder, 'verified.json', {'schemas': definitions, 'history_unchanged': True,
                'outside_definitions_unchanged': True, 'posting_calls': 0, 'backfill': False,
                'tables_verified': len(after), 'rows_verified': sum(v['rows'] for v in after.values())})
            print('Verified all seven ' + ENVIRONMENT + ' schemas; history and out-of-scope definitions unchanged.', flush=True)
        except BaseException:
            if changed:
                try:
                    restore(manifest, state)
                    require(before == fingerprints(column_maps), 'Recovery historical fingerprints differ')
                    save(folder, 'recovered.json', {'definitions_restored': True, 'history_unchanged': True})
                    print('Original accounting definitions restored; additive schema retained.', flush=True)
                except BaseException:
                    recovery_ok = False
                    print('Recovery requires inspection; maintenance hold retained.', flush=True)
            raise
        finally:
            if recovery_ok:
                query('SET GLOBAL read_only=' + prior['read_only'])
                if prior['event_scheduler'] == 'ON':
                    query('SET GLOBAL event_scheduler=ON')
                if prior['cron'] == 'active':
                    subprocess.run(['systemctl', 'start', 'cron'], check=True)
                require(scalar('SELECT @@global.read_only') == prior['read_only'], 'Read-only state was not restored')
                require(scalar('SELECT @@global.event_scheduler') == prior['event_scheduler'], 'Event scheduler was not restored')
                save(folder, 'maintenance_restored.json', prior)
                print('Original database and scheduler settings restored.', flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Accounting deployment failed: ' + str(error), flush=True)
        raise SystemExit(1)
