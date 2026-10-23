#!/usr/bin/env python3
"""UAT master only: install three reference routines and update journal 76061 remarks."""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
import uuid

os.umask(0o077)
DATABASE = 'mytemr_master_uat'
PACKAGE = Path(__file__).resolve().parent


class Database:
    def __init__(self, schema, executable='mariadb', socket=None):
        self.errors = tempfile.TemporaryFile(mode='w+t')
        command = [executable, '--default-character-set=utf8mb4', '--binary-as-hex', '--batch',
                   '--silent', '--skip-column-names', '--unbuffered', '--skip-reconnect']
        if socket:
            command += ['--socket=' + socket, '--user=root']
        self.process = subprocess.Popen(command + [schema], stdin=subprocess.PIPE,
                                        stdout=subprocess.PIPE, stderr=self.errors, text=True)

    def query(self, statement, digest=None):
        marker = 'memo_end_' + uuid.uuid4().hex
        self.process.stdin.write(statement.rstrip(';\n') + ";\nSELECT '" + marker + "';\n")
        self.process.stdin.flush()
        rows = []
        count = 0
        while True:
            line = self.process.stdout.readline()
            if not line:
                raise RuntimeError('Database statement failed; private SQL error retained')
            if line.rstrip('\n') == marker:
                return count if digest else rows
            if digest:
                digest.update(line.encode('utf8'))
                count += 1
            else:
                rows.append(line.rstrip('\n').split('\t'))

    def scalar(self, statement):
        rows = self.query(statement)
        require(len(rows) == 1 and len(rows[0]) == 1, 'Unexpected scalar result')
        return rows[0][0]

    def close(self, folder):
        try:
            self.process.stdin.close()
        except BrokenPipeError:
            pass
        self.process.wait(timeout=30)
        self.errors.seek(0)
        errors = self.errors.read()
        if errors:
            (folder / 'transaction_error.private.txt').write_text(errors)
        self.errors.close()


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def save(folder, name, value):
    (folder / name).write_text(json.dumps(value, indent=2) + '\n')


def fingerprint(db):
    columns = [row[0] for row in db.query('SHOW COLUMNS FROM journal_entry')
               if row[0] not in ['remarks', 'updated_by', 'updated_on']]
    queries = {
        'gl': 'SELECT * FROM general_ledger ORDER BY id',
        'journal_items': 'SELECT * FROM journal_entry_item ORDER BY id',
        'other_journals': 'SELECT * FROM journal_entry WHERE id<>76061 ORDER BY id',
        'target_protected_fields': 'SELECT ' + ','.join('`' + name + '`' for name in columns) + ' FROM journal_entry WHERE id=76061',
        'credit_memos': 'SELECT * FROM credit_memo ORDER BY id',
        'debit_memos': 'SELECT * FROM debit_memo ORDER BY id',
        'billings': 'SELECT * FROM billing ORDER BY id',
        'billing_entries': 'SELECT * FROM billing_entry ORDER BY id',
    }
    result = {}
    for name, query in queries.items():
        digest = hashlib.sha256()
        count = db.query(query, digest)
        result[name] = {'rows': count, 'sha256': digest.hexdigest()}
    return result


def validate_target(db):
    require(db.scalar("SELECT COUNT(*) FROM credit_memo WHERE id=4 AND control_no='CM2120261014000002' AND billing_id=2 AND branch_id=4 AND status='approved' AND is_deleted=0 AND amount=600 AND debit_account_type_id=261 AND credit_account_type_id=57") == '1', 'Credit memo 4 changed')
    require(db.scalar("SELECT COUNT(*) FROM billing WHERE id=2 AND invoice_no='C-PK0008'") == '1', 'Invoice reference changed')
    require(db.scalar("SELECT COUNT(*) FROM journal_entry WHERE id=76061 AND source='credit_memo' AND source_id=4 AND cost_center_id=4 AND date='2026-10-14' AND status='approved' AND total_debit=600 AND total_credit=600 AND is_deleted=0") == '1', 'Target journal changed')
    require(db.scalar("SELECT COUNT(*) FROM general_ledger WHERE source='credit_memo' AND source_id=4") == '2', 'Unexpected credit memo posting history')
    require(db.scalar("SELECT COUNT(*) FROM general_ledger WHERE source='credit_memo' AND source_id=4 AND branch_id=4 AND date='2026-10-14' AND is_deleted=0 AND ((id=154798 AND account_type_id=261 AND debit=600 AND credit=0) OR (id=154799 AND account_type_id=57 AND debit=0 AND credit=600))") == '2', 'Target GL differs')
    require(db.scalar('SELECT COUNT(*) FROM journal_entry_item WHERE journal_entry_id=76061') == '2', 'Unexpected journal items')
    require(db.scalar('SELECT COUNT(*) FROM journal_entry_item WHERE journal_entry_id=76061 AND is_deleted=0 AND ((id=232152 AND account_type_id=261 AND debit=600 AND credit=0) OR (id=232153 AND account_type_id=57 AND debit=0 AND credit=600))') == '2', 'Target items differ')
    require(db.scalar("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='journal_entry' AND EVENT_MANIPULATION='UPDATE'") == '0', 'Journal update has unexpected side effects')
    for table in ['general_ledger', 'journal_entry', 'journal_entry_item', 'credit_memo', 'debit_memo', 'billing', 'billing_entry']:
        require(db.scalar(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{table}' AND ENGINE='InnoDB'") == '1', 'Missing transactional table: ' + table)


def verify_search(db):
    for term in ['CM2120261014000002', 'C-PK0008']:
        predicate = "is_deleted=0 AND status='approved' AND date BETWEEN '2026-10-01' AND '2026-10-31' AND remarks LIKE '%" + term + "%'"
        rows = db.query('SELECT id FROM journal_entry WHERE ' + predicate + ' ORDER BY date DESC,id DESC LIMIT 0,10')
        require(any(row == ['76061'] for row in rows), 'Expected journal not searchable')
        require(int(db.scalar('SELECT COUNT(*) FROM journal_entry WHERE ' + predicate)) == len(db.query('SELECT id FROM journal_entry WHERE ' + predicate)), 'Search row/count mismatch')
    require(db.scalar("SELECT gl_journal_base_remark(source,source_id,remarks) <=> remarks FROM journal_entry WHERE id=76061") == '1', 'Reference formatting is not idempotent')


def load_support(folder):
    spec = importlib.util.spec_from_file_location('memo_sql_support', PACKAGE / 'gl_remark_deploy_support.py')
    support = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(support)
    support.ERROR_DIR = folder
    return support


def snapshot_definitions(support, objects):
    result = {}
    for obj in objects:
        exists = support.scalar("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='mytemr_master_uat' AND ROUTINE_NAME=" + support.literal(obj['name'])) == '1'
        original = support.show(DATABASE, obj['kind'], obj['name']) if exists else None
        require(original is not None or obj['baseline'] is None, 'Required existing routine missing')
        if original:
            allowed = [support.canonical(support.body(obj['definition']))]
            if obj['baseline']:
                allowed.append(support.canonical(support.body(obj['baseline'])))
            require(support.canonical(support.body(original['definition'])) in allowed, 'Unreviewed installed routine: ' + obj['name'])
        result[obj['name']] = original
    return result


def protected_definitions(support, objects):
    names = ','.join(support.literal(obj['name']) for obj in objects)
    routines = support.query("SELECT ROUTINE_NAME,ROUTINE_TYPE,HEX(ROUTINE_DEFINITION),DEFINER,SQL_MODE,SECURITY_TYPE,IS_DETERMINISTIC,SQL_DATA_ACCESS,DTD_IDENTIFIER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='mytemr_master_uat' AND ROUTINE_NAME NOT IN (" + names + ') ORDER BY ROUTINE_NAME')
    triggers = support.query("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,HEX(ACTION_STATEMENT),SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION,ACTION_ORDER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='mytemr_master_uat' ORDER BY TRIGGER_NAME")
    return {'routines': hashlib.sha256(routines).hexdigest(), 'triggers': hashlib.sha256(triggers).hexdigest()}


def install_object(support, obj, old, metadata):
    definition = obj['definition']
    fields = (old or metadata)['fields']
    user, host = fields.get('Definer', 'root@localhost').rsplit('@', 1)
    definition = definition.replace('CREATE ' + obj['kind'] + ' ', 'CREATE DEFINER=`' + user.replace('`', '``') + '`@`' + host.replace('`', '``') + '` ' + obj['kind'] + ' ', 1)
    charset = fields['character_set_client']
    collation = fields['collation_connection']
    require(re.fullmatch(r'\w+', charset) and re.fullmatch(r'\w+', collation), 'Invalid saved character-set settings')
    support.query('SET SESSION sql_mode=' + support.literal(fields['sql_mode']) + ';\nSET NAMES ' + charset + ' COLLATE ' + collation + ';\nDELIMITER $$\nDROP ' + obj['kind'] + ' IF EXISTS `' + obj['name'] + '`$$\n' + definition + '$$\nDELIMITER ;\n', DATABASE)


def verify_definitions(support, objects, originals, protected):
    hashes = {}
    for obj in objects:
        actual = support.show(DATABASE, obj['kind'], obj['name'])
        require(support.canonical(support.body(actual['definition'])) == support.canonical(support.body(obj['definition'])), 'Definition mismatch: ' + obj['name'])
        expected = originals[obj['name']] or originals['gl_je_rebuild']
        for field in ['Definer', 'sql_mode', 'character_set_client', 'collation_connection', 'Database Collation']:
            require(actual['fields'].get(field) == expected['fields'].get(field), 'Routine settings changed: ' + obj['name'] + '.' + field)
        hashes[obj['name']] = hashlib.sha256(support.canonical(support.body(actual['definition'])).encode()).hexdigest()
    require(protected_definitions(support, objects) == protected, 'Unrelated SQL definitions changed')
    return hashes


def deploy(folder):
    require(os.geteuid() == 0 and str(folder).startswith('/root/memo_journal_refs_'), 'Private VPS6 root-only deployment')
    folder.mkdir(parents=True, mode=0o700, exist_ok=False)
    support = load_support(folder)
    objects = json.loads((PACKAGE / 'manifest.json').read_text())['uat']
    db = Database(DATABASE)
    state = None
    original_header = None
    original_header_hex = None
    originals = None
    protected = None
    before = None
    changed = False
    committed = False
    recovery_ok = False
    try:
        db.query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET SESSION time_zone='+08:00'; SET SESSION innodb_lock_wait_timeout=15")
        validate_target(db)
        originals = snapshot_definitions(support, objects)
        protected = protected_definitions(support, objects)
        save(folder, 'definitions.before.json', originals)
        save(folder, 'protected_definitions.before.json', protected)
        restore = support.restore_sql(DATABASE, 'master', {'objects': originals}, {'objects': objects})
        (folder / 'restore_definitions.sql').write_text(restore)
        state = {'read_only': db.scalar('SELECT @@global.read_only'), 'event_scheduler': db.scalar('SELECT @@global.event_scheduler'),
                 'cron': subprocess.run(['systemctl', 'is-active', 'cron'], capture_output=True, text=True).stdout.strip()}
        save(folder, 'maintenance.before.json', state)
        require(db.scalar("SELECT COUNT(*) FROM mysql.user WHERE BINARY Super_priv=BINARY 'Y' AND BINARY User NOT IN (BINARY 'root',BINARY 'mysql')") == '0', 'Application account bypasses read_only')
        require(db.scalar("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='READ_ONLY ADMIN' AND GRANTEE NOT IN ('''root''@''localhost''','''mysql''@''localhost''')") == '0', 'Unexpected read-only administrator')
        require(db.scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER='root' AND COMMAND<>'Sleep' AND ID<>CONNECTION_ID()") == '0', 'Privileged operation active')
        subprocess.run(['systemctl', 'stop', 'cron'], check=True)
        db.query('SET GLOBAL read_only=ON')
        if state['event_scheduler'] == 'ON':
            db.query('SET GLOBAL event_scheduler=OFF')
        for attempt in range(30):
            if db.scalar('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_is_read_only=0') == '0':
                break
            time.sleep(1)
        else:
            raise RuntimeError('Write transactions failed to drain')
        require(snapshot_definitions(support, objects) == originals, 'Routine drift after hold')
        require(protected_definitions(support, objects) == protected, 'Protected routine drift after hold')
        validate_target(db)
        before = fingerprint(db)
        save(folder, 'fingerprints.before.json', before)
        original_header = db.query('SELECT * FROM journal_entry WHERE id=76061')
        original_header_hex = db.scalar('SELECT HEX(remarks) FROM journal_entry WHERE id=76061')
        save(folder, 'header.before.private.json', original_header)
        for table, where in [('journal_entry', 'id=76061'), ('general_ledger', 'id IN (154798,154799)'), ('journal_entry_item', 'id IN (232152,232153)')]:
            with (folder / (table + '.sql')).open('wb') as handle:
                subprocess.run(['mariadb-dump', '--default-character-set=utf8mb4', '--single-transaction', '--skip-triggers', '--hex-blob', DATABASE, table, '--where=' + where], stdout=handle, check=True)
        print('UAT write hold enabled; exact definitions and target rows backed up.', flush=True)
        changed = True
        for obj in objects:
            install_object(support, obj, originals[obj['name']], originals['gl_je_rebuild'])
        hashes = verify_definitions(support, objects, originals, protected)
        require(fingerprint(db) == before, 'Financial/history changes during DDL')
        db.query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ; START TRANSACTION')
        db.query('SELECT id FROM journal_entry WHERE id=76061 FOR UPDATE')
        db.query('SELECT id FROM credit_memo WHERE id=4 FOR UPDATE')
        db.query('SELECT id FROM billing WHERE id=2 FOR UPDATE')
        db.query('SELECT id FROM general_ledger WHERE id IN (154798,154799) ORDER BY id FOR UPDATE')
        db.query('SELECT id FROM journal_entry_item WHERE id IN (232152,232153) ORDER BY id FOR UPDATE')
        validate_target(db)
        db.query("UPDATE journal_entry SET remarks=gl_journal_base_remark(source,source_id,remarks),updated_by=NULL,updated_on=NOW() WHERE id=76061 AND source='credit_memo' AND source_id=4 AND NOT (remarks <=> gl_journal_base_remark(source,source_id,remarks))")
        verify_search(db)
        if original_header_hex not in ['NULL', '']:
            require(db.scalar("SELECT LOCATE(UNHEX('" + original_header_hex + "'),CAST(remarks AS BINARY))>0 FROM journal_entry WHERE id=76061") == '1', 'Existing header context lost')
        after = fingerprint(db)
        save(folder, 'fingerprints.in_transaction.json', after)
        require(before == after, 'Protected records changed; rollback required')
        require(verify_definitions(support, objects, originals, protected) == hashes, 'Definition drift during correction')
        db.query('COMMIT')
        committed = True
        validate_target(db)
        verify_search(db)
        after = fingerprint(db)
        require(before == after, 'Postcommit protected fingerprint mismatch')
        save(folder, 'fingerprints.after.json', after)
        save(folder, 'verified.json', {'journal_id': 76061, 'credit_memo_id': 4, 'definition_hashes': hashes,
                                      'protected_fingerprints_unchanged': True, 'search_matches': True,
                                      'no_posting_or_rebuild': True})
        recovery_ok = True
        print('UAT verified: journal 76061 searchable by control/invoice; GL/items and all other headers unchanged.', flush=True)
    except BaseException:
        try:
            # CLI exits on SQL errors. Disconnect first to guarantee rollback,
            # then verify recovery on a fresh connection instead of a dead pipe.
            db.close(folder)
            db = Database(DATABASE)
            db.query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET SESSION time_zone='+08:00'")
            if committed and original_header:
                # Maintenance remains held. Restore ONLY the three permitted header fields.
                saved_columns = [row[0] for row in db.query('SHOW COLUMNS FROM journal_entry')]
                values = dict(zip(saved_columns, original_header[0]))
                assignments = []
                for name in ['remarks', 'updated_by', 'updated_on']:
                    raw = values[name]
                    if raw == 'NULL':
                        value = 'NULL'
                    else:
                        raw = raw.replace('\\n', '\n').replace('\\r', '\r').replace('\\t', '\t').replace('\\\\', '\\')
                        value = support.literal(raw)
                    assignments.append('`' + name + '`=' + value)
                db.query('START TRANSACTION; UPDATE journal_entry SET ' + ','.join(assignments) + ' WHERE id=76061; COMMIT')
            else:
                db.query('ROLLBACK')
            if changed:
                support.query((folder / 'restore_definitions.sql').read_text(), DATABASE)
            if originals:
                require(snapshot_definitions(support, objects) == originals, 'SQL restoration failed')
            if protected:
                require(protected_definitions(support, objects) == protected, 'Unrelated SQL changed during recovery')
            if before:
                require(fingerprint(db) == before, 'Recovery fingerprints changed')
            if original_header:
                require(db.query('SELECT * FROM journal_entry WHERE id=76061') == original_header, 'Header recovery failed')
            recovery_ok = True
            save(folder, 'recovery.json', {'original_definitions_and_header_restored': True})
        except BaseException:
            print('Recovery verification failed; keep maintenance hold and inspect private backups.', flush=True)
        raise
    finally:
        if state and recovery_ok:
            db.query('SET GLOBAL read_only=' + state['read_only'])
            if state['event_scheduler'] in ['ON', 'OFF']:
                db.query('SET GLOBAL event_scheduler=' + state['event_scheduler'])
            if state['cron'] == 'active':
                subprocess.run(['systemctl', 'start', 'cron'], check=True)
            save(folder, 'maintenance.restored.json', state)
            print('Original database maintenance and cron settings restored.', flush=True)
        elif state:
            print('Maintenance hold retained.', flush=True)
        db.close(folder)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backup', type=Path, required=True)
    args = parser.parse_args()
    with open('/root/memo_journal_refs_uat.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        deploy(args.backup)
