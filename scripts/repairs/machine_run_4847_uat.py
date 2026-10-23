#!/usr/bin/env python3
"""Frozen Oakridge UAT repair. No stock writes, DDL, mirror writes or broad reposts."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
import uuid

os.umask(0o077)
SOURCE_IDS = '23391,23392,23393'
HISTORY_IDS = '23367,23368,23369,' + SOURCE_IDS
STOCK_IDS = '22584,22585,22586'
TARGET = "source='item_history' AND source_id=23392"


class Database:
    """Keep all reads, fingerprints, locks and writes on ONE CLI connection."""
    def __init__(self, executable, schema, socket=None):
        self.errors = tempfile.TemporaryFile(mode='w+t')
        command = [executable, '--default-character-set=utf8mb4', '--binary-as-hex',
                   '--batch', '--silent', '--skip-column-names', '--unbuffered']
        if socket:
            command += ['--socket=' + socket, '--user=root']
        self.process = subprocess.Popen(command + [schema], stdin=subprocess.PIPE,
                                        stdout=subprocess.PIPE, stderr=self.errors, text=True)

    def query(self, statement, digest=None):
        marker = 'repair_end_' + uuid.uuid4().hex
        self.process.stdin.write(statement.rstrip(';\n') + ";\nSELECT '" + marker + "';\n")
        self.process.stdin.flush()
        rows = []
        count = 0
        while True:
            line = self.process.stdout.readline()
            if not line:
                self.errors.seek(0)
                raise RuntimeError('Database statement failed: ' + self.errors.read())
            if line.rstrip('\n') == marker:
                return count if digest else rows
            if digest:
                digest.update(line.encode('utf8'))
                count += 1
            else:
                rows.append(line.rstrip('\n').split('\t'))

    def scalar(self, statement):
        rows = self.query(statement)
        if len(rows) != 1 or len(rows[0]) != 1:
            raise RuntimeError('Unexpected scalar result')
        return rows[0][0]

    def close(self):
        # Disconnect rolls back any uncommitted transaction even if SQL failed.
        try:
            self.process.stdin.close()
        except BrokenPipeError:
            pass
        self.process.wait(timeout=30)
        self.errors.close()


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def normalize(value):
    value = re.sub(r'CREATE\s+DEFINER\s*=\s*\S+\s+', 'CREATE ', value, flags=re.I)
    value = re.sub(r'\s+(?:FOLLOWS|PRECEDES)\s+`?\w+`?', '', value, flags=re.I)
    value = re.sub(r'--[^\n]*', '', value).replace('`', '')
    value = re.sub(r'\b(?:int|integer)\(11\)', 'int', value, flags=re.I)
    return re.sub(r'\s+', ' ', value).strip().lower()


def definitions(db):
    # Metadata is retained privately; never displayed (helpers can contain keys).
    return db.query("SELECT ROUTINE_TYPE,ROUTINE_NAME,ROUTINE_DEFINITION,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_TYPE,ROUTINE_NAME") + db.query("SELECT 'TRIGGER',TRIGGER_NAME,ACTION_STATEMENT,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION,ACTION_ORDER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME")


def preflight(db, master, expected=None):
    require(db.scalar('SELECT gl_branch_id()') == '2', 'Wrong source branch')
    for table in ['item_history', 'branch_item', 'general_ledger', 'journal_entry', 'journal_entry_item', 'item_conversion', 'item_cost']:
        require(db.scalar(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{table}' AND ENGINE='InnoDB'") == '1', 'Nontransactional/missing dependency: ' + table)
    require(db.scalar(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{master}' AND TABLE_NAME IN ('item','account_type') AND ENGINE='InnoDB'") == '2', 'Nontransactional master dependency')
    require(db.scalar(f"SELECT COUNT(*) FROM `{master}`.item WHERE id=4847 AND cost=5 AND is_deleted=0") == '1', 'Item cost/activity changed')
    require(db.scalar("SELECT COUNT(*) FROM item WHERE id=4847 AND cost=5 AND is_deleted=0") == '1', 'Branch item catalog changed')
    require(db.scalar("SELECT COUNT(*) FROM item_conversion WHERE item_id=4847 AND is_deleted=0 AND ((id=14489 AND conversion_type='purchase' AND qty=1) OR (id=14490 AND conversion_type='clinic' AND qty=1) OR (id=14491 AND conversion_type='usage' AND qty=1000))") == '3', 'Conversion links changed')
    for code, account in [('500-205', 1109), ('104-030', 137)]:
        require(db.scalar(f"SELECT gl_account_id('{code}')") == str(account), 'Account mapping changed')
        require(db.scalar(f"SELECT COUNT(*) FROM `{master}`.account_type WHERE id={account} AND code='{code}' AND is_deleted=0") == '1', 'Account unavailable')
    require(db.scalar("SELECT COUNT(*) FROM branch_item WHERE item_id=4847 AND branch_id=2 AND is_deleted=0 AND latest_activity='Machine Run' AND latest_activity_id=0 AND updated_by=9 AND updated_on='2026-10-13 14:43:43' AND added_by=65 AND added_on='2026-10-13 11:59:52' AND ((id=22584 AND unit=14489 AND beggining_qty=100000 AND current_qty=99999.95) OR (id=22585 AND unit=14490 AND beggining_qty=100000 AND current_qty=99999.95) OR (id=22586 AND unit=14491 AND beggining_qty=100000000 AND current_qty=99999950))") == '3', 'Stock snapshot changed')
    require(db.scalar("SELECT COUNT(*) FROM item_history WHERE item_id=4847 AND branch_id=2 AND activity='Machine Run' AND activity_id=0 AND added_by=9 AND added_on='2026-10-13 14:43:43' AND is_deleted=0 AND ((id=23391 AND branch_item_id=22584 AND unit=14489 AND current_qty=99999.95 AND quantity IN (0,.05)) OR (id=23392 AND branch_item_id=22585 AND unit=14490 AND current_qty=99999.95 AND quantity IN (0,.05)) OR (id=23393 AND branch_item_id=22586 AND unit=14491 AND current_qty=99999950 AND quantity=50))") == '3', 'Frozen history targets changed')
    require(db.scalar("SELECT COUNT(*) FROM item_history WHERE item_id=4847 AND branch_id=2 AND activity='Beginning Inventory' AND activity_id=4847 AND added_by=65 AND added_on='2026-10-13 11:59:52' AND is_deleted=0 AND ((id=23367 AND branch_item_id=22584 AND unit=14489 AND current_qty=100000 AND quantity=100000) OR (id=23368 AND branch_item_id=22585 AND unit=14490 AND current_qty=100000 AND quantity=100000) OR (id=23369 AND branch_item_id=22586 AND unit=14491 AND current_qty=100000000 AND quantity=100000000))") == '3', 'Previous history snapshots changed')
    require(db.scalar("SELECT COUNT(*) FROM item_history WHERE (branch_item_id=22584 AND id>23367 AND id<23391) OR (branch_item_id=22585 AND id>23368 AND id<23392) OR (branch_item_id=22586 AND id>23369 AND id<23393)") == '0', 'Unexpected intervening history')
    require(db.scalar("SELECT COUNT(*) FROM item_cost WHERE id=17921 AND item_id=4847 AND branch_id=2 AND last_value=500000 AND latest_total=100000 AND current_amount=5 AND added_on='2026-10-13 11:59:53' AND is_deleted=0") == '1', 'Cost trail changed')
    require(db.scalar('SELECT MAX(id) FROM item_cost WHERE item_id=4847 AND branch_id=2 AND is_deleted=0') == '17921', 'Newer cost trail exists')
    trigger = db.scalar("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_branch_item_update'")
    require('declare qty_diff decimal(13,2)' in normalize(trigger), 'Fractional stock trigger not installed')
    if expected:
        for name, definition in expected.items():
            kind = 'TRIGGER' if name.startswith('trg_') else ('FUNCTION' if name == 'gl_item_history_remarks' else 'PROCEDURE')
            fields = db.query(f'SHOW CREATE {kind} `{name}`')[0]
            actual = fields[2]
            # Batch escaping is not SQL definition text; unescape CLI sequences.
            actual = actual.replace('\\n', '\n').replace('\\r', '\r').replace('\\t', '\t').replace('\\\\', '\\')
            require(normalize(actual) == definition, 'Installed definition drift: ' + name)
    return accounting_state(db)


def accounting_state(db):
    require(db.scalar("SELECT COUNT(*) FROM general_ledger WHERE source='item_history' AND source_id IN (23367,23368,23369,23391,23393)") == '0', 'Unexpected purchase/usage/beginning GL')
    require(db.scalar("SELECT COUNT(*) FROM journal_entry WHERE source='item_history' AND source_id IN (23367,23368,23369,23391,23393)") == '0', 'Unexpected nonclinic journal')
    gl_count = int(db.scalar('SELECT COUNT(*) FROM general_ledger WHERE ' + TARGET))
    je_count = int(db.scalar('SELECT COUNT(*) FROM journal_entry WHERE ' + TARGET))
    if gl_count == je_count == 0:
        return 'missing'
    require(gl_count == 2 and je_count == 1, 'Unexpected existing accounting/history; stop')
    require(db.scalar("SELECT COUNT(*) FROM general_ledger WHERE " + TARGET + " AND is_deleted=0 AND branch_id=2 AND date='2026-10-13' AND added_by=9 AND ((account_type_id=1109 AND debit=.25 AND credit=0) OR (account_type_id=137 AND debit=0 AND credit=.25))") == '2', 'GL does not match expected pair')
    require(db.scalar("SELECT COUNT(DISTINCT account_type_id) FROM general_ledger WHERE " + TARGET) == '2', 'Duplicate GL side')
    require(db.scalar("SELECT COUNT(*) FROM journal_entry WHERE " + TARGET + " AND is_deleted=0 AND cost_center_id=2 AND date='2026-10-13' AND status='approved' AND total_debit=.25 AND total_credit=.25 AND added_by=9") == '1', 'Journal does not match expected totals')
    require(db.scalar("SELECT COUNT(*) FROM journal_entry_item ji JOIN journal_entry je ON je.id=ji.journal_entry_id WHERE je." + TARGET.replace(' AND source_id', ' AND je.source_id')) == '2', 'Journal item count mismatch')
    require(db.scalar("SELECT COUNT(*) FROM journal_entry_item ji JOIN journal_entry je ON je.id=ji.journal_entry_id JOIN general_ledger gl ON gl.source=je.source AND gl.source_id=je.source_id AND gl.account_type_id=ji.account_type_id AND gl.debit=ji.debit AND gl.credit=ji.credit AND gl.remark <=> ji.remarks WHERE je.source='item_history' AND je.source_id=23392 AND ji.is_deleted=0 AND ji.added_by=9") == '2', 'Journal items do not match GL')
    require(db.query("SELECT COUNT(DISTINCT ji.account_type_id),SUM(ji.debit),SUM(ji.credit) FROM journal_entry_item ji JOIN journal_entry je ON je.id=ji.journal_entry_id WHERE je.source='item_history' AND je.source_id=23392") == [['2', '0.2500', '0.2500']], 'Journal item sides/totals mismatch')
    require(db.scalar("SELECT COUNT(*) FROM item_history WHERE id=23392 AND quantity=.05") == '1', 'Posting exists before quantity correction')
    return 'complete'


def fingerprints(db, master):
    history_columns = [row[0] for row in db.query('SHOW COLUMNS FROM item_history')
                       if row[0] not in ['quantity', 'updated_by', 'updated_on']]
    queries = {
        'stock': 'SELECT * FROM branch_item ORDER BY id',
        'other_history': 'SELECT * FROM item_history WHERE id NOT IN (23391,23392) ORDER BY id',
        'protected_target_fields': 'SELECT ' + ','.join('`' + name + '`' for name in history_columns) + ' FROM item_history WHERE id IN (23391,23392) ORDER BY id',
        'other_gl': "SELECT * FROM general_ledger WHERE NOT (source='item_history' AND source_id=23392) ORDER BY id",
        'other_journals': "SELECT * FROM journal_entry WHERE NOT (source <=> 'item_history' AND source_id <=> 23392) ORDER BY id",
        'other_journal_items': "SELECT ji.* FROM journal_entry_item ji LEFT JOIN journal_entry je ON je.id=ji.journal_entry_id WHERE NOT (je.source <=> 'item_history' AND je.source_id <=> 23392) ORDER BY ji.id",
        'conversions': 'SELECT * FROM item_conversion ORDER BY id',
        'costs': 'SELECT * FROM item_cost ORDER BY id',
        'branch_catalog': 'SELECT * FROM item ORDER BY id',
        'master_catalog': f'SELECT * FROM `{master}`.item ORDER BY id',
        'accounts': f'SELECT * FROM `{master}`.account_type ORDER BY id',
    }
    result = {}
    for name, statement in queries.items():
        digest = hashlib.sha256()
        rows = db.query(statement, digest)
        result[name] = {'rows': rows, 'sha256': digest.hexdigest()}
    return result


def save(out, name, value):
    (out / name).write_text(json.dumps(value, indent=2) + '\n')


def backup(args, db, out):
    # Exact DDL retains definers, modes, and charset settings. Dumps never enter git.
    command = [args.dump, '--default-character-set=utf8mb4', '--hex-blob']
    if args.socket:
        command += ['--socket=' + args.socket, '--user=root']
    with (out / 'definitions.sql').open('wb') as handle:
        subprocess.run(command + ['--no-data', '--routines', '--triggers', args.schema], stdout=handle, check=True)
    scopes = {
        'item_history': 'id IN (' + HISTORY_IDS + ')',
        'branch_item': 'id IN (' + STOCK_IDS + ')',
        'general_ledger': "source='item_history' AND source_id IN (" + HISTORY_IDS + ')',
        'journal_entry': "source='item_history' AND source_id IN (" + HISTORY_IDS + ')',
        'journal_entry_item': "journal_entry_id IN (SELECT id FROM journal_entry WHERE source='item_history' AND source_id IN (" + HISTORY_IDS + '))',
    }
    for table, where in scopes.items():
        with (out / (table + '.sql')).open('wb') as handle:
            subprocess.run(command + ['--single-transaction', '--skip-triggers', args.schema, table, '--where=' + where], stdout=handle, check=True)
    save(out, 'definitions.before.json', definitions(db))


def run(args):
    fixture = args.schema.startswith('machine_run_4847_fixture') and args.master.startswith('machine_run_4847_fixture')
    require(fixture or (args.schema == 'mytemr_diagnostic_uat' and args.master == 'mytemr_master_uat' and not args.socket), 'UAT-only database guard')
    require(fixture or not args.inject_failure, 'Fault injection is fixture-only')
    require(fixture or (os.geteuid() == 0 and str(args.output).startswith('/root/machine_run_4847_')), 'Live output must be a private root backup')
    args.output.mkdir(parents=True, exist_ok=False)
    os.chmod(args.output, 0o700)
    out = args.output
    db = Database(args.mysql, args.schema, args.socket)
    expected = None
    if not fixture:
        expected = json.loads(Path('/root/blindsheet_20261013_202203/uat/installed.json').read_text())[args.schema]
    state = None
    committed = False
    recovered = False
    before = None
    original_targets = None
    try:
        db.query("SET SESSION time_zone='+08:00'; SET SESSION innodb_lock_wait_timeout=15")
        status = preflight(db, args.master, expected)
        original_targets = db.query('SELECT * FROM item_history WHERE id IN (' + SOURCE_IDS + ') ORDER BY id')
        quantities = db.query('SELECT id,quantity FROM item_history WHERE id IN (' + SOURCE_IDS + ') ORDER BY id')
        save(out, 'preview.json', {'quantities': quantities, 'accounting': status, 'scope': args.schema})
        print('Preflight passed: frozen history/stock/cost/accounts; accounting ' + status, flush=True)
        if args.mode == 'preview':
            return
        if not fixture:
            state = {'read_only': db.scalar('SELECT @@global.read_only'), 'event_scheduler': db.scalar('SELECT @@global.event_scheduler'),
                     'cron': subprocess.run(['systemctl', 'is-active', 'cron'], capture_output=True, text=True).stdout.strip()}
            save(out, 'maintenance.json', state)
            subprocess.run(['systemctl', 'stop', 'cron'], check=True)
            if state['event_scheduler'] == 'ON':
                db.query('SET GLOBAL event_scheduler=OFF')
            db.query('SET GLOBAL read_only=ON')
            for attempt in range(30):
                if db.scalar('SELECT COUNT(*) FROM information_schema.INNODB_TRX') == '0':
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Existing transactions did not drain')
        backup(args, db, out)
        (out / 'RECOVERY.txt').write_text('No SQL definitions were changed. Before commit, disconnect/ROLLBACK restores all target changes. Do not load table dumps over newer business data. If verification fails, retain the write hold and inspect result/fingerprint files before resuming writers. Saved maintenance.json records the exact original global and cron settings.\n')
        db.query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ; START TRANSACTION')
        db.query('SELECT id FROM item_history WHERE id IN (' + HISTORY_IDS + ') ORDER BY id FOR UPDATE')
        db.query('SELECT id FROM branch_item WHERE id IN (' + STOCK_IDS + ') ORDER BY id FOR UPDATE')
        db.query(f'SELECT id FROM `{args.master}`.item WHERE id=4847 FOR UPDATE')
        db.query('SELECT id FROM item_conversion WHERE id IN (14489,14490,14491) ORDER BY id FOR UPDATE')
        db.query('SELECT id FROM item_cost WHERE id=17921 FOR UPDATE')
        db.query(f'SELECT id FROM `{args.master}`.account_type WHERE id IN (137,1109) ORDER BY id FOR UPDATE')
        db.query('SELECT id FROM general_ledger WHERE ' + TARGET + ' FOR UPDATE')
        db.query('SELECT id FROM journal_entry WHERE ' + TARGET + ' FOR UPDATE')
        status = preflight(db, args.master, expected)
        original_definitions = definitions(db)
        original_targets = db.query('SELECT * FROM item_history WHERE id IN (' + SOURCE_IDS + ') ORDER BY id')
        before = fingerprints(db, args.master)
        save(out, 'fingerprints.before.json', before)
        corrected_clinic = db.scalar('SELECT quantity FROM item_history WHERE id=23392') == '0.00'
        for history_id in [23391, 23392]:
            # The existing AFTER UPDATE hook posts only the clinic unit. No extra call.
            db.query(f'UPDATE item_history SET quantity=.05,updated_by=NULL,updated_on=NOW() WHERE id={history_id} AND quantity=0')
        if not corrected_clinic and status == 'missing':
            db.query('CALL gl_post_item_history(23392)')
        require(accounting_state(db) == 'complete', 'Expected accounting missing after repair')
        require(db.scalar('SELECT COUNT(*) FROM item_history WHERE id IN (23391,23392) AND quantity=.05') == '2', 'Quantity correction incomplete')
        require(definitions(db) == original_definitions, 'Definitions unexpectedly changed')
        after = fingerprints(db, args.master)
        save(out, 'fingerprints.in_transaction.json', after)
        require(before == after, 'Stock or out-of-scope fingerprint changed; rolling back')
        if args.inject_failure:
            require(fixture, 'Fault injection is fixture-only')
            raise RuntimeError('Synthetic post-validation failure')
        db.query('COMMIT')
        committed = True
        require(accounting_state(db) == 'complete', 'Postcommit accounting mismatch')
        after = fingerprints(db, args.master)
        save(out, 'fingerprints.after.json', after)
        require(before == after, 'Postcommit fingerprint mismatch; retain maintenance')
        require(definitions(db) == original_definitions, 'Postcommit definition drift')
        result = {
            'history': db.query('SELECT id,quantity FROM item_history WHERE id IN (' + SOURCE_IDS + ') ORDER BY id'),
            'gl': db.query('SELECT id,account_type_id,date,debit,credit FROM general_ledger WHERE ' + TARGET + ' ORDER BY id'),
            'journal': db.query('SELECT id,date,status,total_debit,total_credit FROM journal_entry WHERE ' + TARGET),
            'journal_items': db.query("SELECT ji.id,ji.journal_entry_id,ji.account_type_id,ji.debit,ji.credit FROM journal_entry_item ji JOIN journal_entry je ON je.id=ji.journal_entry_id WHERE je.source='item_history' AND je.source_id=23392 ORDER BY ji.id"),
            'unchanged_fingerprints': True, 'definitions_unchanged': True,
        }
        save(out, 'result.json', result)
        print(json.dumps(result), flush=True)
        recovered = True
    except Exception:
        if not committed:
            try:
                db.query('ROLLBACK')
                require(before is None or fingerprints(db, args.master) == before, 'Rollback fingerprint mismatch')
                require(original_targets is None or db.query('SELECT * FROM item_history WHERE id IN (' + SOURCE_IDS + ') ORDER BY id') == original_targets, 'Rollback target mismatch')
                recovered = True
                save(out, 'rollback.json', {'transaction': 'rolled back', 'out_of_scope': 'unchanged'})
            except Exception:
                pass
        raise
    finally:
        if state and recovered:
            db.query('SET GLOBAL read_only=' + state['read_only'])
            if state['event_scheduler'] in ['ON', 'OFF']:
                db.query('SET GLOBAL event_scheduler=' + state['event_scheduler'])
            if state['cron'] == 'active':
                subprocess.run(['systemctl', 'start', 'cron'], check=True)
            save(out, 'maintenance.restored.json', state)
            print('Original database maintenance and cron settings restored.', flush=True)
        elif state:
            print('Maintenance hold retained; inspect private recovery files.', flush=True)
        db.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['preview', 'apply'])
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--mysql', default='mariadb')
    parser.add_argument('--dump', default='mariadb-dump')
    parser.add_argument('--schema', default='mytemr_diagnostic_uat')
    parser.add_argument('--master', default='mytemr_master_uat')
    parser.add_argument('--socket')
    parser.add_argument('--inject-failure', action='store_true', help='Disposable fixtures only')
    run(parser.parse_args())


if __name__ == '__main__':
    main()
