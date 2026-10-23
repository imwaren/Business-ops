#!/usr/bin/env python3
"""Scoped VPS5/VPS6 receipt-batch rollout. Never executes business DML or posting routines."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import time

os.umask(0o077)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('server', choices=['app', 'db'])
parser.add_argument('mode', choices=['inspect', 'backup', 'hold', 'apply', 'verify', 'resume', 'rollback'])
parser.add_argument('environment', choices=['uat', 'prod'])
parser.add_argument('release', type=Path)
args = parser.parse_args()
release = args.release.resolve()
folder = release / args.environment
folder.mkdir(parents=True, exist_ok=True)
manifest = json.loads((folder / 'manifest.json').read_text())
assert manifest['environment'] == args.environment
bases = {0: 'mytemr_master', 1: 'mytemr_diagnostic_mobile', 2: 'mytemr_diagnostic',
         3: 'mytemr_diagnostic_mactan', 4: 'mytemr_diagnostic_conso',
         5: 'mytemr_oakridge_warehouse', 6: 'mytemr_oakridge_pharmacy', 7: 'mytemr_diagnostic_mantlewood'}
schemas = {branch: name + ('_uat' if args.environment == 'uat' else '') for branch, name in bases.items()}
root = Path('/home/mytemr/web') / (('zuat-' if args.environment == 'uat' else '') + 'accounting-back.myt-emr.com') / 'public_html'
tables = ['receipt_note', 'receipt_note_item', 'receipt_note_item_expiry_date', 'receipt_note_additional_item',
          'receipt_note_attachment', 'branch_item_transfer', 'branch_item_transfer_item', 'branch_item',
          'item', 'item_conversion', 'item_cost', 'item_history', 'general_ledger', 'journal_entry', 'journal_entry_item']


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def save(name, data):
    (folder / name).write_text(json.dumps(data, indent=2) + '\n')


def load(name):
    return json.loads((folder / name).read_text())


def run(command, **kwargs):
    result = subprocess.run(command, capture_output=True, **kwargs)
    if result.returncode:
        # Never put application configuration, row data, or routine bodies in console output.
        (folder / 'last_error.private.log').write_bytes(result.stderr.encode() if isinstance(result.stderr, str) else result.stderr)
        raise RuntimeError('Command failed; inspect private last_error log: ' + command[0])
    return result.stdout


def sql(statement, database=None):
    return run(['mariadb', '--default-character-set=utf8mb4', '--binary-as-hex', '-N', '-B']
               + ([database] if database else []) + ['-e', statement], text=True)


def definitions():
    statements = ["SELECT ROUTINE_SCHEMA,ROUTINE_NAME,ROUTINE_TYPE,HEX(ROUTINE_DEFINITION),DEFINER,SQL_MODE,SECURITY_TYPE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.ROUTINES ORDER BY ROUTINE_SCHEMA,ROUTINE_NAME",
                  "SELECT TRIGGER_SCHEMA,TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_ORDER,HEX(ACTION_STATEMENT),DEFINER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.TRIGGERS ORDER BY TRIGGER_SCHEMA,TRIGGER_NAME"]
    return [hashlib.sha256(sql(statement).encode()).hexdigest() for statement in statements]


def column_maps():
    result = {}
    for db in schemas.values():
        result[db] = {}
        for line in sql("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ("
                        + ','.join("'" + table + "'" for table in tables) + ') ORDER BY TABLE_NAME,ORDINAL_POSITION', db).splitlines():
            table, column = line.split('\t')
            result[db].setdefault(table, []).append(column)
    return result


def fingerprints(columns):
    result = {}
    for db, db_tables in columns.items():
        for table, names in db_tables.items():
            query = 'SELECT ' + ','.join('`' + name + '`' for name in names) + ' FROM `' + table + '` ORDER BY id'
            process = subprocess.Popen(['mariadb', '--default-character-set=utf8mb4', '--binary-as-hex', '-N', '-B', db, '-e', query], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            value = hashlib.sha256()
            while chunk := process.stdout.read(1024 * 1024):
                value.update(chunk)
            require(process.wait() == 0, 'Fingerprint query failed: ' + db + '.' + table)
            result[db + '.' + table] = {'sha256': value.hexdigest(), 'rows': int(sql('SELECT COUNT(*) FROM `' + table + '`', db).strip())}
        print('Fingerprinted ' + db, flush=True)
    return result


def app_protected():
    paths = [root / name for name in ['.env', 'app/Config/Database.php', 'app/Config/Constants.php',
             'app/Core/MYT_Controller.php', 'app/Core/MYT_Model.php', 'app/Common.php', 'spark']]
    paths += list(Path('/etc/cron.d').glob('*')) + list(Path('/var/spool/cron/crontabs').glob('*'))
    return {str(path): digest(path) for path in paths if path.is_file()}


def lint(path):
    for php in ['/usr/bin/php8.2', '/usr/bin/php8.3']:
        if Path(php).exists():
            run([php, '-l', str(path)])


def replace(source, target, metadata):
    staged = target.with_name('.' + target.name + '.receipt_batches_release')
    shutil.copyfile(source, staged)
    os.chown(staged, metadata['uid'], metadata['gid'])
    staged.chmod(metadata['mode'])
    staged.replace(target)


def reload_php():
    for service in ['php8.2-fpm', 'php8.3-fpm']:
        if subprocess.run(['systemctl', 'is-active', '--quiet', service]).returncode == 0:
            run(['systemctl', 'reload', service])


def probe(before=False):
    result = subprocess.run(['/usr/bin/php8.2', str(release / 'probe.php'), str(root), args.environment]
                            + (['--before'] if before else []), capture_output=True, text=True)
    output = result.stdout
    (folder / ('probe.before.private.json' if before else 'probe.after.private.json')).write_text(output)
    (folder / 'probe.stderr.private.log').write_text(result.stderr)
    require(result.returncode == 0, 'Application probe failed; inspect private probe logs')
    data = json.loads(output)
    require(data.get('ok'), 'Application probe failed; inspect private probe log')
    print('Application credential probe passed: ' + str(data['checks']) + ' checks; no live writes.', flush=True)
    return data


def inspect_app():
    for name, record in manifest['files'].items():
        require(digest(root / name) == record['before'], 'Unexpected deployed file: ' + name)
        require(digest(folder / 'stage' / name) == record['release'], 'Staged file mismatch: ' + name)
        lint(folder / 'stage' / name)
    probe(before=True)
    print(args.environment + ' application baseline, release syntax, and effective access passed.', flush=True)


def inspect_db():
    require(digest(release / 'install.sql') == manifest['sql_sha256'], 'SQL checksum mismatch')
    for branch, db in schemas.items():
        required_tables = ['receipt_note', 'receipt_note_item', 'receipt_note_item_expiry_date',
                           'item', 'item_conversion', 'branch_item', 'item_cost', 'item_history']
        if branch == 0:
            required_tables += ['branch_item_transfer', 'branch_item_transfer_item']
        for table in required_tables:
            require(sql("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "'", db).strip() == 'InnoDB', 'Missing/nontransactional dependency: ' + db + '.' + table)
        for table in ['receipt_note_item', 'receipt_note_item_expiry_date', 'branch_item_transfer_item']:
            if table == 'branch_item_transfer_item' and branch != 0:
                continue
            row = sql("SELECT DATA_TYPE,IS_NULLABLE,IFNULL(CHARACTER_MAXIMUM_LENGTH,0) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "' AND COLUMN_NAME='batch_no'", db).strip()
            if row:
                data_type, nullable, length = row.split('\t')
                allowed_types = ['varchar'] if table == 'receipt_note_item' else ['int', 'varchar']
                require(data_type in allowed_types and nullable == 'YES' and (data_type != 'varchar' or int(length) >= 255), 'Incompatible batch column: ' + db + '.' + table)
        for table, name, expected in [('receipt_note_item_expiry_date', 'idx_expiry_batch', 'batch_no,receipt_note_item_id,is_deleted'),
                                      ('branch_item_transfer_item', 'idx_transfer_item_batch', 'item_id,batch_no,branch_item_transfer_id,is_deleted')]:
            found = sql("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "' AND INDEX_NAME='" + name + "'", db).strip()
            require(found in ['NULL', expected], 'Incompatible index: ' + db + '.' + name)
        require(sql("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='install_receipt_batches'", db).strip() == '0', 'Unexpected installer procedure exists')
    privileged = sql("SELECT GRANTEE,PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE IN ('SUPER','READ_ONLY ADMIN') AND REPLACE(SUBSTRING_INDEX(GRANTEE,'@',1),CHAR(39),'') NOT IN ('root','mysql')").strip()
    require(not privileged, 'Unreviewed account can bypass read-only protection')
    print(args.environment + ' eight schemas and additive DDL prerequisites passed.', flush=True)


lock = open('/run/receipt_batches_deploy.lock', 'w')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
if args.server == 'app':
    if args.mode == 'inspect':
        inspect_app()
    elif args.mode == 'backup':
        inspect_app()
        original = {}
        for name in list(manifest['files']) + ['.htaccess']:
            path = root / name
            stat = path.stat()
            original[name] = {'uid': stat.st_uid, 'gid': stat.st_gid, 'mode': stat.st_mode & 0o777, 'sha256': digest(path)}
            target = folder / 'before' / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)
            target.chmod(0o600)
        save('app.original.json', original)
        save('app.protected.json', app_protected())
        save('logs.before.json', {str(path): path.stat().st_size for path in (root / 'writable/logs').glob('*.log')})
        save('cron.before.json', {str(path): digest(path) for directory in ['/etc/cron.d', '/var/spool/cron/crontabs'] for path in Path(directory).glob('*') if path.is_file()})
        print(args.environment + ' exact deployed files, permissions, and configuration hashes backed up.', flush=True)
    elif args.mode == 'hold':
        require(not (folder / 'app.maintenance.json').exists(), 'Application hold already recorded')
        save('app.maintenance.json', {'cron': run(['systemctl', 'is-active', 'cron'], text=True).strip()})
        run(['systemctl', 'stop', 'cron'])
        hold = folder / 'htaccess.hold'
        hold.write_bytes(b'# Temporary authorized receipt-batch deployment hold\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n</IfModule>\n' + (folder / 'before/.htaccess').read_bytes())
        replace(hold, root / '.htaccess', load('app.original.json')['.htaccess'])
        domain = root.parent.name
        code = run(['curl', '--max-time', '25', '-sS', '-o', '/dev/null', '-w', '%{http_code}', 'https://' + domain + '/items/transfer_report'], text=True)
        require(code == '503', 'HTTP write hold did not take effect')
        print(args.environment + ' HTTP hold verified and application cron paused.', flush=True)
    elif args.mode == 'apply':
        require((folder / 'app.maintenance.json').exists(), 'Application hold required')
        original = load('app.original.json')
        for name, record in manifest['files'].items():
            require(digest(root / name) == record['before'], 'Deployed baseline drift before replacement')
            require(digest(folder / 'stage' / name) == record['release'], 'Stage drift')
        for name in manifest['files']:
            replace(folder / 'stage' / name, root / name, original[name])
            lint(root / name)
            require(digest(root / name) == manifest['files'][name]['release'], 'Installed checksum mismatch')
        reload_php()
        print(args.environment + ' nine PHP files installed; dependencies before controllers; syntax/checksums passed.', flush=True)
    elif args.mode == 'verify':
        for name, record in manifest['files'].items():
            require(digest(root / name) == record['release'], 'Release checksum mismatch')
            lint(root / name)
        require(app_protected() == load('app.protected.json'), 'Unrelated configuration/schedules changed')
        result = probe()
        new_errors = 0
        for path in (root / 'writable/logs').glob('*.log'):
            start = load('logs.before.json').get(str(path), 0)
            content = path.read_bytes()[start:].decode(errors='replace')
            if any(message in content for message in ['Class "App\\', 'Unknown column', 'Uncaught TypeError', 'CRITICAL -']):
                new_errors += 1
        require(new_errors == 0, 'New critical/query errors found; inspect private application logs')
        save('app.verified.json', result)
    elif args.mode == 'rollback':
        original = load('app.original.json')
        for name in reversed(list(manifest['files'])):
            replace(folder / 'before' / name, root / name, original[name])
            lint(root / name)
            require(digest(root / name) == original[name]['sha256'], 'Restored file checksum mismatch')
        reload_php()
        save('app.recovered.json', {'ok': True})
        print('Previous PHP restored; maintenance hold retained.', flush=True)
    elif args.mode == 'resume':
        require((folder / 'app.verified.json').exists() or (folder / 'app.recovered.json').exists(), 'Verification/recovery required before resume')
        replace(folder / 'before/.htaccess', root / '.htaccess', load('app.original.json')['.htaccess'])
        require(digest(root / '.htaccess') == load('app.original.json')['.htaccess']['sha256'], 'Routing restoration mismatch')
        if load('app.maintenance.json')['cron'] == 'active':
            run(['systemctl', 'start', 'cron'])
        save('app.resumed.json', {'routing': 'restored', 'cron': load('app.maintenance.json')['cron']})
        print(args.environment + ' original routing and application scheduler restored.', flush=True)
else:
    if args.mode == 'inspect':
        inspect_db()
    elif args.mode == 'backup':
        inspect_db()
        columns = column_maps()
        save('db.columns.json', columns)
        save('db.definitions.hashes.json', definitions())
        for db, db_tables in columns.items():
            for option, filename in [(['--single-transaction', '--skip-triggers', '--hex-blob'], db + '_rows.sql'),
                                      (['--no-data', '--routines', '--triggers'], db + '_definitions.sql')]:
                with (folder / filename).open('wb') as target:
                    result = subprocess.run(['mariadb-dump'] + option + [db] + list(db_tables), stdout=target, stderr=subprocess.PIPE)
                    require(result.returncode == 0, 'Database backup failed: ' + db)
            print('Backed up ' + db, flush=True)
    elif args.mode == 'hold':
        require(not (folder / 'db.maintenance.json').exists(), 'Database hold already recorded')
        state = {'read_only': int(sql('SELECT @@global.read_only').strip()), 'event_scheduler': sql('SELECT @@global.event_scheduler').strip(),
                 'cron': run(['systemctl', 'is-active', 'cron'], text=True).strip()}
        save('db.maintenance.json', state)
        run(['systemctl', 'stop', 'cron'])
        if state['event_scheduler'] == 'ON':
            sql('SET GLOBAL event_scheduler=OFF')
        sql('SET GLOBAL read_only=ON')
        for attempt in range(60):
            if sql('SELECT COUNT(*) FROM information_schema.INNODB_TRX').strip() == '0':
                break
            time.sleep(1)
        else:
            raise RuntimeError('Transactions did not drain; retain hold for review')
        save('db.before.json', fingerprints(load('db.columns.json')))
        require(definitions() == load('db.definitions.hashes.json'), 'Definitions changed before installation')
        print(args.environment + ' database hold active; transactions drained and original data fingerprinted.', flush=True)
    elif args.mode == 'apply':
        require(sql('SELECT @@global.read_only').strip() == '1', 'Database write hold required')
        require((folder / 'db.before.json').exists(), 'Protected baseline required')
        require(digest(release / 'install.sql') == manifest['sql_sha256'], 'Installer checksum mismatch')
        for db in schemas.values():
            for iteration in range(2):
                run(['mariadb', '--default-character-set=utf8mb4', '--binary-mode', db], input=(release / 'install.sql').read_bytes())
            print('Installed and repeated additive schema: ' + db, flush=True)
    elif args.mode in ['verify', 'rollback']:
        if args.mode == 'rollback':
            # The reviewed baseline has no installer routine; a failed CALL may leave it behind.
            for db in schemas.values():
                sql('DROP PROCEDURE IF EXISTS install_receipt_batches', db)
        after = fingerprints(load('db.columns.json'))
        require(after == load('db.before.json'), 'Historical data mismatch; retain maintenance hold')
        require(definitions() == load('db.definitions.hashes.json'), 'Routine/trigger definition mismatch')
        if args.mode == 'verify':
            for branch, db in schemas.items():
                for table in ['receipt_note_item', 'receipt_note_item_expiry_date', 'branch_item_transfer_item']:
                    if table == 'branch_item_transfer_item' and branch != 0:
                        continue
                    row = sql("SELECT DATA_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "' AND COLUMN_NAME='batch_no'", db).strip().split('\t')
                    require(len(row) == 4 and row[0] == 'varchar' and row[1] == 'YES' and int(row[2]) >= 255 and row[3] == 'utf8mb4_bin', 'Installed batch column mismatch')
                require(sql("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='receipt_note_item_expiry_date' AND INDEX_NAME='idx_expiry_batch'", db).strip() == 'batch_no,receipt_note_item_id,is_deleted', 'Expiry index mismatch')
                if branch == 0:
                    require(sql("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branch_item_transfer_item' AND INDEX_NAME='idx_transfer_item_batch'", db).strip() == 'item_id,batch_no,branch_item_transfer_id,is_deleted', 'Transfer index mismatch')
        save('db.verified.json' if args.mode == 'verify' else 'db.recovered.json', {'ok': True, 'tables': len(after), 'rows': sum(value['rows'] for value in after.values()), 'definitions': 'unchanged', 'fingerprints': after})
        print(args.environment + ' historical rows and all routines/triggers unchanged; ' + str(len(after)) + ' tables verified.', flush=True)
    elif args.mode == 'resume':
        require((folder / 'db.verified.json').exists() or (folder / 'db.recovered.json').exists(), 'Verification/recovery required before resume')
        state = load('db.maintenance.json')
        sql('SET GLOBAL read_only=' + str(state['read_only']))
        if state['event_scheduler'] == 'ON':
            sql('SET GLOBAL event_scheduler=ON')
        if state['cron'] == 'active':
            run(['systemctl', 'start', 'cron'])
        save('db.resumed.json', state)
        print(args.environment + ' original database read-only, event scheduler, and cron states restored.', flush=True)
