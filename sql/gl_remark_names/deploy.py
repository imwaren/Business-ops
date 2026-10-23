#!/usr/bin/env python3
"""VPS6 root-only scoped installer; never calls a financial posting procedure."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time
import xml.etree.ElementTree as ET

PACKAGE = Path(__file__).resolve().parent
CLIENT = ['mariadb', '--comments', '--binary-mode', '--default-character-set=utf8mb4',
          '--batch', '--skip-column-names', '--skip-reconnect']
BRANCHES = {1: 'mytemr_diagnostic_mobile', 2: 'mytemr_diagnostic', 3: 'mytemr_diagnostic_mactan',
            4: 'mytemr_diagnostic_conso', 7: 'mytemr_diagnostic_mantlewood'}
TOKEN = '__GL_ENCRYPTION_KEY_HEX__'
ERROR_DIR = PACKAGE


def save(path, value):
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False))
    path.chmod(0o600)


def emit(event, **details):
    print(json.dumps({'event': event, **details}), flush=True)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def query(statement, database=None, xml=False):
    command = CLIENT + ([database] if database else [])
    if xml:
        command += ['--xml']
    result = subprocess.run(command, input=('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n' + statement).encode(), capture_output=True)
    if result.returncode:
        error_file = ERROR_DIR / 'sql_error.private.txt'
        error_file.write_bytes(result.stderr)
        error_file.chmod(0o600)
        raise RuntimeError('SQL operation failed; details are in the private server error file.')
    return result.stdout


def scalar(statement, database=None):
    return query(statement, database).decode().strip()


def literal(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "''") + "'"


def canonical(source):
    # Preserve string contents; ignore formatting, identifier case/quotes and comments.
    tokens = re.findall(r"'(?:\\.|''|[^'])*'|\"(?:\\.|\"\"|[^\"])*\"|`(?:``|[^`])*`|--[^\n]*|/\*[\s\S]*?\*/|[A-Za-z_][\w$]*|\d+(?:\.\d+)?|<>|!=|<=|>=|:=|[^\s]", source)
    return ' '.join(token if token[0] in "'\"" else token.strip('`').lower()
                    for token in tokens if not token.startswith(('--', '/*')))


def body(definition):
    return re.search(r'\bBEGIN\b.*\bEND\b', definition, re.S | re.I).group()


def show(database, kind, name):
    xml = query('SHOW CREATE ' + kind + ' `' + name + '`;', database, xml=True)
    fields = {field.attrib['name']: field.text or '' for field in ET.fromstring(xml).findall('.//field')}
    field_name = 'SQL Original Statement' if kind == 'TRIGGER' else 'Create ' + kind.title()
    definition = fields[field_name]
    definer = re.search(r'CREATE DEFINER=`((?:``|[^`])*)`@`((?:``|[^`])*)`', definition)
    if definer:
        fields['Definer'] = definer.group(1).replace('``','`') + '@' + definer.group(2).replace('``','`')
    fields.pop('Created', None)
    if kind == 'TRIGGER':
        raw = scalar('SELECT HEX(ACTION_STATEMENT) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='
                     + literal(database) + ' AND TRIGGER_NAME=' + literal(name))
        action = bytes.fromhex(raw).decode()
        definition = re.sub(r'(FOR EACH ROW\s+).*', lambda match: match[1] + action, definition, count=1, flags=re.S)
    return {'definition': definition, 'fields': fields}


def schemas(environment):
    suffix = '_uat' if environment == 'uat' else ''
    return [(schema + suffix, 'diagnostic', branch) for branch, schema in BRANCHES.items()] + [('mytemr_master' + suffix, 'master', None)]


def manifest_for(environment, kind):
    return json.loads((PACKAGE / 'manifest.json').read_text())['install_' + environment + '_' + kind + '.sql']


def inventory(database, excluded):
    names = ','.join(literal(obj['name']) for obj in excluded)
    routines = query("SELECT ROUTINE_NAME,ROUTINE_TYPE,HEX(ROUTINE_DEFINITION),DEFINER,SQL_MODE,SECURITY_TYPE,IS_DETERMINISTIC,SQL_DATA_ACCESS,DTD_IDENTIFIER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=" + literal(database) + ' AND ROUTINE_NAME NOT IN (' + names + ') ORDER BY ROUTINE_NAME;')
    triggers = query("SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,HEX(ACTION_STATEMENT),DEFINER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION,ACTION_ORDER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=" + literal(database) + " AND TRIGGER_NAME<>'trg_gl_general_ledger_bi' ORDER BY TRIGGER_NAME;")
    return {'routines_sha256': hashlib.sha256(routines).hexdigest(), 'triggers_sha256': hashlib.sha256(triggers).hexdigest()}


def inspect(environment, folder):
    folder.mkdir(mode=0o700, parents=True, exist_ok=True)
    require(not (folder/'verified.json').exists(), 'Completed release backups are immutable; use a new release directory or --verify-only')
    result = {}
    for database, kind, branch in schemas(environment):
        details = manifest_for(environment, kind)
        existing = {}
        for obj in details['objects']:
            table = 'TRIGGERS' if obj['kind'] == 'TRIGGER' else 'ROUTINES'
            column = 'TRIGGER' if obj['kind'] == 'TRIGGER' else 'ROUTINE'
            exists = scalar('SELECT COUNT(*) FROM information_schema.' + table + ' WHERE ' + column + '_SCHEMA=' + literal(database) + ' AND ' + column + '_NAME=' + literal(obj['name'])) == '1'
            if exists:
                old = show(database, obj['kind'], obj['name'])
                actual = canonical(body(old['definition']))
                acceptable = [canonical(body(obj['definition']))]
                if obj['baseline_definition']:
                    acceptable.append(canonical(body(obj['baseline_definition'])))
                require(actual in acceptable, 'Live definition differs from reviewed baseline: ' + database + '.' + obj['name'])
                existing[obj['name']] = old
            else:
                require(obj['baseline_definition'] is None, 'Required existing routine missing: ' + database + '.' + obj['name'])
                existing[obj['name']] = None
        types = scalar("SELECT TABLE_NAME,DATA_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=" + literal(database) + " AND TABLE_NAME IN ('journal_entry','journal_entry_item') AND COLUMN_NAME='remarks' ORDER BY TABLE_NAME;")
        require(len(types.splitlines()) == 2 and all(row.split('\t')[1] in ['varchar', 'text', 'mediumtext', 'longtext'] for row in types.splitlines()), 'Invalid journal remarks schema: ' + database)
        require(scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=" + literal(database) + " AND TABLE_NAME IN ('general_ledger','journal_entry','journal_entry_item') AND ENGINE='InnoDB';") == '3', 'Required accounting tables are not InnoDB: ' + database)
        require(scalar("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=" + literal(database) + " AND TABLE_NAME='general_ledger' AND COLUMN_NAME='remark';") == 'longtext', 'GL remark must be LONGTEXT: ' + database)
        if branch is not None:
            require(scalar('SELECT branch_id FROM gl_settings LIMIT 1;', database) == str(branch), 'Branch setting differs: ' + database)
        required_columns = ({
            'patient': ['id','last_name','first_name','middle_name','suffix'],
            'transaction': ['id','patient_id','transaction_no'],
            'booking': ['id','transaction_id','is_deleted'],
            'booking_medicine': ['id','booking_id','medicine_id','is_deleted'],
            'booking_details': ['id','booking_id'],
            'booking_package_details': ['id','booking_detail_id'],
            'item_history': ['id','item_id','activity','activity_id'],
            'receipt_note_item': ['id','receipt_note_id','item_id','is_deleted'],
            'accounts_payable_voucher': ['id','type','type_id'],
            'accounts_payable_voucher_payment_detail': ['id','accounts_payable_voucher_id'],
            'non_cash_deposit': ['id','transaction_id'],
        } if kind=='diagnostic' else {
            'item': ['id','name'], 'refund': ['id','branch_id','transaction_id'],
            'billing_entry': ['id','billing_id','branch_id','transaction_id','is_deleted'],
            'billing_payment_entry': ['billing_payment_id','billing_entry_id','is_deleted'],
            'billing_payment_deposit': ['id','billing_payment_id'],
            'check_deposit': ['id','billing_payment_id'],
            'credit_memo': ['id','billing_id'], 'debit_memo': ['id','billing_id'],
        })
        for table, columns in required_columns.items():
            require(int(scalar('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='+literal(database)+' AND TABLE_NAME='+literal(table)+' AND COLUMN_NAME IN ('+','.join(map(literal,columns))+');'))==len(columns), 'Source metadata columns missing: '+database+'.'+table)
        definitions = {table: show(database, 'TABLE', table) for table in ['journal_entry', 'journal_entry_item']}
        result[database] = {'kind': kind, 'objects': existing, 'table_definitions': definitions,
                            'remarks_before': types, 'protected': inventory(database, details['objects'])}
        save(folder / (database + '.before.json'), result[database])
    save(folder / 'inspection.json', result)
    emit('preflight-definitions-verified', environment=environment, schemas=len(result))
    return result


def fingerprints(environment):
    result = {}
    for database, _, _ in schemas(environment):
        for table in ['general_ledger', 'journal_entry', 'journal_entry_item']:
            count = int(scalar('SELECT COUNT(*) FROM `' + table + '`;', database))
            process = subprocess.Popen(CLIENT + [database], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            process.stdin.write(('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SELECT * FROM `' + table + '` ORDER BY id;').encode())
            process.stdin.close()
            digest = hashlib.sha256()
            while True:
                chunk = process.stdout.read(1024 * 1024)
                if not chunk:
                    break
                digest.update(chunk)
            error = process.stderr.read()
            require(process.wait() == 0, 'Accounting fingerprint query failed: ' + database + '.' + table)
            result[database + '.' + table] = {'rows': count, 'sha256': digest.hexdigest()}
        emit('accounting-fingerprint', environment=environment, schema=database)
    return result


def restore_sql(database, kind, old, desired):
    output = ['USE `' + database + '`;', 'DELIMITER $$']
    objects = list(reversed(desired['objects']))
    for obj in objects:
        output.append('DROP ' + obj['kind'] + ' IF EXISTS `' + obj['name'] + '`$$')
    for obj in desired['objects']:
        original = old['objects'][obj['name']]
        if original is None:
            continue
        fields = original['fields']
        output += ['SET SESSION sql_mode=' + literal(fields['sql_mode']) + '$$',
                   'SET NAMES ' + fields['character_set_client'] + ' COLLATE ' + fields['collation_connection'] + '$$',
                   original['definition'] + '$$']
    output += ['DELIMITER ;']
    return '\n'.join(output) + '\n'


def verify(environment, originals):
    result = {}
    for database, kind, _ in schemas(environment):
        details = manifest_for(environment, kind)
        require(inventory(database, details['objects']) == originals[database]['protected'], 'Unrelated definitions changed: ' + database)
        hashes = {}
        for obj in details['objects']:
            installed = show(database, obj['kind'], obj['name'])
            require(canonical(body(installed['definition'])) == canonical(body(obj['definition'])), 'Installed definition mismatch: ' + database + '.' + obj['name'])
            hashes[obj['name']] = hashlib.sha256(canonical(body(installed['definition'])).encode()).hexdigest()
        require(scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=" + literal(database) + " AND TABLE_NAME IN ('journal_entry','journal_entry_item') AND COLUMN_NAME='remarks' AND DATA_TYPE='longtext';") == '2', 'Journal LONGTEXT verification failed: ' + database)
        result[database] = hashes
    return result


def metadata_checks(environment):
    suffix = '_uat' if environment == 'uat' else ''
    master = 'mytemr_master' + suffix
    result = {}
    for branch, schema in BRANCHES.items():
        database = schema + suffix
        # Boolean comparison only: decrypted patients never enter deployment output.
        statement = "SELECT COUNT(*),SUM(p.last_name IS NOT NULL OR p.first_name IS NOT NULL),SUM(gl_patient_display_name(p.last_name,p.first_name,p.middle_name,p.suffix) IS NOT NULL) FROM (SELECT patient_id FROM `transaction` WHERE NULLIF(TRIM(transaction_no),'') IS NOT NULL AND patient_id IS NOT NULL ORDER BY id DESC LIMIT 100) t JOIN patient p ON p.id=t.patient_id;"
        counts = list(map(lambda value: 0 if value == 'NULL' else int(value), scalar(statement, database).split('\t')))
        require(counts[1] == 0 or counts[2] > 0, 'No sampled encrypted names decrypt: ' + database)
        # Master context adds the branch reference. Compare its payload with the
        # diagnostic payload, removing only that known generated prefix.
        master_context = master+'.gl_branch_transaction_context('+str(branch)+',t.id)'
        prefix = literal('branch #'+str(branch)+' / ')
        payload = 'IF(LEFT('+master_context+',CHAR_LENGTH('+prefix+'))='+prefix+',SUBSTRING('+master_context+',CHAR_LENGTH('+prefix+')+1),'+master_context+')'
        equal = scalar('SELECT COUNT(*),SUM(gl_transaction_context(t.id) <=> '+payload+') FROM (SELECT id FROM `transaction` ORDER BY id DESC LIMIT 30) t;',database).split('\t')
        require(equal[0] == equal[1] or equal[0] == '0', 'Branch metadata payload differs: ' + database)
        require(scalar('SELECT gl_transaction_context(0) IS NULL;', database) == '1', 'Empty transaction fallback failed')
        items = scalar('SELECT COUNT(*),SUM(gl_transaction_item_names(t.transaction_id) IS NOT NULL) FROM (SELECT DISTINCT b.transaction_id FROM booking b JOIN booking_medicine bm ON bm.booking_id=b.id JOIN '+master+".item i ON i.id=bm.medicine_id WHERE b.is_deleted=0 AND bm.is_deleted=0 AND b.transaction_id>0 AND NULLIF(TRIM(i.name),'') IS NOT NULL ORDER BY b.transaction_id DESC LIMIT 10) t;",database).split('\t')
        require(items[0]=='0' or items[0]==items[1], 'Catalog-linked transaction item names missing: '+database)
        receipts = scalar('SELECT COUNT(*),SUM(gl_receipt_note_item_names(t.receipt_note_id) IS NOT NULL) FROM (SELECT DISTINCT receipt_note_id FROM receipt_note_item WHERE is_deleted=0 AND receipt_note_id>0 ORDER BY receipt_note_id DESC LIMIT 10) t;',database).split('\t')
        require(receipts[0]=='0' or receipts[0]==receipts[1], 'Receipt-note item context missing: '+database)
        require(scalar("SELECT CHAR_LENGTH(gl_remark_append('Synthetic prefix',REPEAT('Synthetic metadata Ω ',1000)))>20000;",database)=='1', 'Long Unicode aggregation is truncated')
        result[database] = {'sample_transactions': counts[0], 'sample_nonempty_encrypted_names': counts[1],
                            'sample_decrypted_names': counts[2], 'branch_payload_agreement': True,
                            'catalog_transaction_samples': int(items[0]), 'receipt_note_samples': int(receipts[0]),
                            'long_unicode_context_verified': True}
    require(scalar('SELECT gl_branch_transaction_context(99,1) IS NULL;', master) == '1', 'Unknown branch fallback failed')
    billings = scalar("SELECT COUNT(*),SUM(gl_enrich_remark('billing_entry',t.id,t.branch_id,CONCAT('Billing #',t.billing_id,' / entry #',t.id)) LIKE CONCAT('%Billing #',t.billing_id,' / entry #',t.id,'%')) FROM (SELECT id,billing_id,branch_id FROM billing_entry ORDER BY id DESC LIMIT 10) t;",master).split('\t')
    require(billings[0]=='0' or billings[0]==billings[1], 'Billing reference preservation failed')
    result[master] = {'billing_entry_samples':int(billings[0]), 'unknown_branch_fallback_verified':True}
    return result


def deploy(environment, folder):
    global ERROR_DIR
    ERROR_DIR = folder
    originals = inspect(environment, folder)
    prior = {'read_only': scalar('SELECT @@global.read_only;'), 'event_scheduler': scalar('SELECT @@global.event_scheduler;')}
    save(folder / 'settings_before.json', prior)
    for database, kind, _ in schemas(environment):
        path = folder / (database + '.restore.sql')
        path.write_text(restore_sql(database, kind, originals[database], manifest_for(environment, kind)))
        path.chmod(0o600)
    recovery_ok = True
    changed = False
    before = None
    try:
        require(scalar("SELECT COUNT(*) FROM mysql.user WHERE BINARY Super_priv=BINARY 'Y' AND BINARY User NOT IN (BINARY 'root',BINARY 'mysql');") == '0', 'Application accounts bypass read_only')
        require(scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER='root' AND COMMAND<>'Sleep' AND ID<>CONNECTION_ID();") == '0', 'Privileged database operation is active')
        query('SET GLOBAL read_only=ON;')
        if prior['event_scheduler'] == 'ON':
            query('SET GLOBAL event_scheduler=OFF;')
        for _ in range(30):
            if scalar('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_is_read_only=0;') == '0':
                break
            time.sleep(1)
        else:
            raise RuntimeError('Existing write transactions did not drain')
        emit('writes-paused', environment=environment)
        before = fingerprints(environment)
        save(folder / 'fingerprints_before.json', before)
        # Recheck definitions after acquiring the maintenance hold.
        for database, kind, _ in schemas(environment):
            require(inventory(database, manifest_for(environment, kind)['objects']) == originals[database]['protected'], 'Protected definitions changed since preflight')
            for obj in manifest_for(environment, kind)['objects']:
                old = originals[database]['objects'][obj['name']]
                if old:
                    require(show(database, obj['kind'], obj['name']) == old, 'Target definition changed since preflight')
        changed = True
        for database, kind, _ in schemas(environment):
            source = (PACKAGE / ('install_' + environment + '_' + kind + '.sql')).read_text()
            require(TOKEN not in source, 'Installer was not rendered')
            # Preserve existing routine/trigger definers and SQL modes.
            for obj in manifest_for(environment, kind)['objects']:
                old = originals[database]['objects'][obj['name']]
                if old:
                    user, host = old['fields'].get('Definer', 'root@localhost').rsplit('@', 1)
                    definition = obj['definition'].replace('CREATE ' + obj['kind'] + ' ', 'CREATE DEFINER=`' + user.replace('`','``') + '`@`' + host.replace('`','``') + '` ' + obj['kind'] + ' ', 1)
                    source = source.replace(obj['definition'], 'SET SESSION sql_mode=' + literal(old['fields']['sql_mode']) + '$$\n' + definition)
            query(source, database)
            emit('schema-installed', environment=environment, schema=database)
        definitions = verify(environment, originals)
        checks = metadata_checks(environment)
        after = fingerprints(environment)
        save(folder / 'fingerprints_after.json', after)
        require(before == after, 'Historical accounting fingerprint changed during installation')
        save(folder / 'verified.json', {'environment': environment, 'schemas': len(originals), 'definition_hashes': definitions,
                                      'metadata_checks': checks, 'historical_accounting_unchanged': True,
                                      'unrelated_definitions_unchanged': True, 'no_backfill_or_rebuild': True})
        emit('environment-verified', environment=environment, schemas=len(originals), historical_accounting_unchanged=True)
    except BaseException:
        if changed:
            try:
                for database, _, _ in schemas(environment):
                    query((folder / (database + '.restore.sql')).read_text(), database)
                for database, kind, _ in schemas(environment):
                    require(inventory(database, manifest_for(environment, kind)['objects']) == originals[database]['protected'], 'Recovery protected definitions differ')
                    for obj in manifest_for(environment, kind)['objects']:
                        old = originals[database]['objects'][obj['name']]
                        if old:
                            require(show(database, obj['kind'], obj['name']) == old, 'Recovery target definition differs')
                        else:
                            table, prefix = ('TRIGGERS','TRIGGER') if obj['kind']=='TRIGGER' else ('ROUTINES','ROUTINE')
                            require(scalar('SELECT COUNT(*) FROM information_schema.'+table+' WHERE '+prefix+'_SCHEMA='+literal(database)+' AND '+prefix+'_NAME='+literal(obj['name']))=='0', 'New definition remains after recovery')
                if before:
                    require(fingerprints(environment) == before, 'Recovery accounting fingerprints differ')
                emit('original-definitions-restored', environment=environment)
            except BaseException:
                recovery_ok = False
                emit('recovery-needs-review', environment=environment, backup_path=str(folder))
        raise
    finally:
        if recovery_ok:
            query('SET GLOBAL read_only=' + ('ON' if prior['read_only']=='1' else 'OFF') + ';')
            if prior['event_scheduler'] == 'ON':
                query('SET GLOBAL event_scheduler=ON;')
            emit('maintenance-restored', environment=environment, read_only=prior['read_only'], event_scheduler=prior['event_scheduler'])
        else:
            emit('writes-remain-paused', environment=environment)


def main():
    global ERROR_DIR
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('environment', choices=['uat','prod'])
    parser.add_argument('--inspect-only', action='store_true')
    parser.add_argument('--verify-only', action='store_true')
    args = parser.parse_args()
    folder = PACKAGE.parent / args.environment
    ERROR_DIR = folder
    folder.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (PACKAGE.parent / 'deployment.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.verify_only:
            originals=json.loads((folder/'inspection.json').read_text())
            hashes=verify(args.environment,originals)
            checks=metadata_checks(args.environment)
            save(folder/'readonly_checks.json',{'definition_hashes':hashes,'metadata_checks':checks})
            emit('read-only-verification-passed',environment=args.environment,schemas=len(hashes))
        elif args.inspect_only:
            inspect(args.environment, folder)
        else:
            deploy(args.environment, folder)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        emit('deployment-failed', message=str(error))
        raise SystemExit(1)
