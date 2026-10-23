#!/usr/bin/env python3
"""VPS6-only execution wrapper. No connection is made unless this script is run."""
import argparse
import hashlib
import json
import pathlib
import subprocess
import sys
import signal
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parent
SCHEMAS = ['mytemr_master', 'mytemr_diagnostic_mobile', 'mytemr_diagnostic',
           'mytemr_diagnostic_mactan', 'mytemr_diagnostic_conso', 'mytemr_diagnostic_mantlewood']


def quote(value):
    return '`' + value.replace('`', '``') + '`'


def sql_string(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "''") + "'"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['preview', 'apply', 'restore'])
    parser.add_argument('--defaults-file', help='MySQL credentials option file; never put a password in argv')
    parser.add_argument('--mysql', default='mariadb')
    parser.add_argument('--work-dir', required=True, type=pathlib.Path)
    parser.add_argument('--maintenance-confirmed', action='store_true')
    args = parser.parse_args()
    args.work_dir.mkdir(parents=True, exist_ok=True)
    client = [args.mysql]
    if args.defaults_file:
        client.append('--defaults-extra-file=' + str(pathlib.Path(args.defaults_file).resolve()))
    client += ['--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names', '--comments']

    def execute(sql, xml=False):
        command = client + (['--xml'] if xml else [])
        result = subprocess.run(command, input=sql, text=True, capture_output=True)
        if result.returncode:
            (args.work_dir / 'failed_query_output.txt').write_text(result.stdout + '\n' + result.stderr)
            raise RuntimeError(result.stderr.strip() + '\nDetails: ' + str(args.work_dir / 'failed_query_output.txt'))
        return result.stdout

    def rows(sql):
        return [line.split('\t') for line in execute(sql).splitlines() if line]

    def restore(triggers, all_records=False):
        failures = []
        for trigger in triggers:
            try:
                if all_records:
                    exists = rows('SELECT COUNT(*) FROM information_schema.triggers WHERE TRIGGER_SCHEMA=' + sql_string(trigger['schema']) + ' AND TRIGGER_NAME=' + sql_string(trigger['name']) + ';')[0][0]
                    if exists != '0':
                        # Never overwrite a trigger that is still installed or was changed externally.
                        current = trigger_record(trigger['schema'], trigger['name'])
                        if current['create'] != trigger['create']:
                            raise RuntimeError('Existing definition differs from saved definition')
                        continue
                execute(trigger['restore'])
                if trigger_record(trigger['schema'], trigger['name'])['create'] != trigger['create']:
                    raise RuntimeError('Restored trigger does not match backup')
            except Exception as error:
                failures.append(trigger['schema'] + '.' + trigger['name'] + ': ' + str(error))
        if failures:
            raise RuntimeError('TRIGGER RESTORATION REQUIRED:\n' + '\n'.join(failures))

    def trigger_record(db, name):
        xml = execute('SHOW CREATE TRIGGER ' + quote(db) + '.' + quote(name) + ';', xml=True)
        fields = {field.attrib['name']: field.text or '' for field in ET.fromstring(xml).findall('.//field')}
        create = fields['SQL Original Statement']
        restore_sql = ('USE ' + quote(db) + ';\nSET SESSION sql_mode=' + sql_string(fields['sql_mode']) + ';\n'
                       + 'SET NAMES ' + fields['character_set_client'] + ' COLLATE ' + fields['collation_connection'] + ';\n'
                       + 'DELIMITER $$\n' + create + '$$\nDELIMITER ;\n')
        return dict(schema=db, name=name, create=create, restore=restore_sql)

    def interrupted(signum, frame):
        raise KeyboardInterrupt('Interrupted; restoring suspended triggers')
    signal.signal(signal.SIGTERM, interrupted)
    archive = args.work_dir / 'triggers.json'
    if args.mode == 'restore':
        restore(json.loads(archive.read_text()), all_records=True)
        print('Saved trigger definitions are restored and verified.')
        return
    if args.mode == 'apply' and not args.maintenance_confirmed:
        raise RuntimeError('Stop application/worker writes, then pass --maintenance-confirmed')
    version = rows('SELECT VERSION();')[0][0]
    if 'MariaDB' not in version:
        raise RuntimeError('This package requires MariaDB BEGIN NOT ATOMIC support; found ' + version)

    inventory = json.loads((ROOT / 'reference_inventory.json').read_text())
    required = {(r['schema'], r['table'], r['column']) for r in inventory}
    required.update(('mytemr_master', 'company', c) for c in ['id', 'is_deleted', 'name', 'account_id', 'bank_id'])
    required.update(('mytemr_master', 'customer_account', c) for c in ['id', 'company_id', 'name', 'is_deleted'])
    schema_list = ','.join(sql_string(db) for db in SCHEMAS)
    columns = rows('SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE FROM information_schema.columns WHERE TABLE_SCHEMA IN (' + schema_list + ');')
    available = {(db, table, column): kind for db, table, column, kind in columns}
    missing = sorted(required - set(available))
    if missing:
        raise RuntimeError('Live schema differs from supplied structures. Review missing columns before proceeding: ' + repr(missing))
    touched = {(r['schema'], r['table']) for r in inventory} | {('mytemr_master', 'company'), ('mytemr_master', 'customer_account')}
    engines = {(db, table): engine for db, table, engine in rows('SELECT TABLE_SCHEMA,TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA IN (' + schema_list + ');')}
    if any(engines.get(key) != 'InnoDB' for key in touched):
        raise RuntimeError('All cleanup tables must use InnoDB for transaction rollback')
    # Stop for added live uniqueness constraints that the supplied schemas did not contain.
    unique = rows('SELECT TABLE_SCHEMA,TABLE_NAME,INDEX_NAME,COLUMN_NAME FROM information_schema.statistics WHERE NON_UNIQUE=0 AND TABLE_SCHEMA IN (' + schema_list + ');')
    for db, table, index, column in unique:
        if (db, table, column) in required and index != 'PRIMARY' and (db, table, index) not in [
            ('mytemr_master', 'company', 'name'), ('mytemr_master', 'user_portal', 'company_id')]:
            raise RuntimeError('Additional live unique index requires collision preflight: ' + repr((db, table, index, column)))

    setup = (ROOT / '00_setup.sql').read_text()
    preflight = (ROOT / '01_preflight.sql').read_text()
    result = execute(setup + '\n' + preflight)
    (args.work_dir / 'preflight.txt').write_text(result)
    print('Preflight passed; affected-row preview: ' + str(args.work_dir / 'preflight.txt'))
    if args.mode == 'preview':
        print('Preview complete; no persistent tables or application records changed.')
        return

    def ledger_fingerprints():
        fingerprints = {}
        for db in SCHEMAS:
            for table in ['general_ledger', 'journal_entry', 'journal_entry_item']:
                cols = [column for d, t, column, kind in columns if d == db and t == table]
                if not cols:
                    raise RuntimeError('Missing ledger table: ' + db + '.' + table)
                # Hash every field of every row in ID order, streaming output rather than loading it.
                object_sql = ','.join(sql_string(c) + ',' + quote(c) for c in cols)
                query = 'SELECT SHA2(JSON_OBJECT(' + object_sql + '),256) FROM ' + quote(db) + '.' + quote(table) + ' ORDER BY id;'
                proc = subprocess.Popen(client, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                proc.stdin.write(query.encode()); proc.stdin.close()
                digest = hashlib.sha256()
                for line in proc.stdout:
                    digest.update(line)
                error = proc.stderr.read().decode()
                if proc.wait():
                    raise RuntimeError(error)
                fingerprints[db + '.' + table] = digest.hexdigest()
        return fingerprints

    baseline = ledger_fingerprints()
    (args.work_dir / 'ledger_before.json').write_text(json.dumps(baseline, indent=2))
    triggers = []
    for db, table, name in rows('SELECT TRIGGER_SCHEMA,EVENT_OBJECT_TABLE,TRIGGER_NAME FROM information_schema.triggers WHERE EVENT_MANIPULATION=\'UPDATE\' AND TRIGGER_SCHEMA IN (' + schema_list + ') ORDER BY TRIGGER_SCHEMA,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_ORDER;'):
        if (db, table) in touched:
            triggers.append(trigger_record(db, name))
    if archive.exists():
        raise RuntimeError('Use a new --work-dir for apply; never overwrite a trigger backup')
    archive.write_text(json.dumps(triggers, indent=2))
    (args.work_dir / 'restore_triggers.sql').write_text('\n'.join(t['restore'] for t in triggers))
    dropped = []
    try:
        for trigger in triggers:
            execute('DROP TRIGGER ' + quote(trigger['schema']) + '.' + quote(trigger['name']) + ';')
            dropped.append(trigger)
        result = execute(setup + '\n' + preflight + '\n' + (ROOT / '02_cleanup.sql').read_text() + '\n' + (ROOT / '03_postflight.sql').read_text())
        (args.work_dir / 'postflight.txt').write_text(result)
    finally:
        restore(dropped)
    after = ledger_fingerprints()
    (args.work_dir / 'ledger_after.json').write_text(json.dumps(after, indent=2))
    if after != baseline:
        raise RuntimeError('GL/JE fingerprints changed; inspect before resuming writes')
    print('Cleanup committed; saved UPDATE triggers restored; all GL/JE row fingerprints unchanged.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
