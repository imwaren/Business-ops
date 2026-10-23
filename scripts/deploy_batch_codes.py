#!/usr/bin/env python3
"""Schema-only alphanumeric batch rollout; never replaces application PHP or runs business DML."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import time

os.umask(0o077)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('server', choices=['app', 'db'])
parser.add_argument('mode', choices=['inspect', 'backup', 'hold', 'apply', 'verify', 'resume', 'recover'])
parser.add_argument('environment', choices=['uat', 'prod'])
parser.add_argument('release', type=Path)
args = parser.parse_args()
release = args.release.resolve()
folder = release / args.environment
folder.mkdir(parents=True, exist_ok=True)
manifest = json.loads((release / 'manifest.json').read_text())
bases = {0: 'mytemr_master', 1: 'mytemr_diagnostic_mobile', 2: 'mytemr_diagnostic',
         3: 'mytemr_diagnostic_mactan', 4: 'mytemr_diagnostic_conso',
         5: 'mytemr_oakridge_warehouse', 6: 'mytemr_oakridge_pharmacy', 7: 'mytemr_diagnostic_mantlewood'}
schemas = {branch: name + ('_uat' if args.environment == 'uat' else '') for branch, name in bases.items()}
roots = {env: Path('/home/mytemr/web') / (('zuat-' if env == 'uat' else '') + 'accounting-back.myt-emr.com') / 'public_html' for env in ['uat', 'prod']}
tables = ['receipt_note', 'receipt_note_item', 'receipt_note_item_expiry_date', 'receipt_note_additional_item',
          'receipt_note_attachment', 'branch_item_transfer', 'branch_item_transfer_item', 'branch_item',
          'item', 'item_conversion', 'item_cost', 'item_history', 'general_ledger', 'journal_entry', 'journal_entry_item']
batch_tables = ['receipt_note_item', 'receipt_note_item_expiry_date', 'branch_item_transfer_item']


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def save(name, data):
    (folder / name).write_text(json.dumps(data, indent=2) + '\n')


def load(name):
    return json.loads((folder / name).read_text())


def run(command, **kwargs):
    result = subprocess.run(command, capture_output=True, **kwargs)
    if result.returncode:
        (folder / 'last_error.private.log').write_bytes(result.stderr.encode() if isinstance(result.stderr, str) else result.stderr)
        raise RuntimeError('Command failed; private error saved: ' + command[0])
    return result.stdout


def sql(statement, database=None):
    return run(['mariadb', '--default-character-set=utf8mb4', '--binary-as-hex', '-N', '-B']
               + ([database] if database else []) + ['-e', statement], text=True)


def service_state(service):
    result = subprocess.run(['systemctl', 'is-active', service], capture_output=True, text=True)
    return result.stdout.strip()


def definitions():
    queries = ["SELECT ROUTINE_SCHEMA,ROUTINE_NAME,ROUTINE_TYPE,HEX(ROUTINE_DEFINITION),DEFINER,SQL_MODE,SECURITY_TYPE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.ROUTINES ORDER BY ROUTINE_SCHEMA,ROUTINE_NAME",
               "SELECT TRIGGER_SCHEMA,TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_ORDER,HEX(ACTION_STATEMENT),DEFINER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.TRIGGERS ORDER BY TRIGGER_SCHEMA,TRIGGER_NAME"]
    return [hashlib.sha256(sql(query).encode()).hexdigest() for query in queries]


def indexes():
    return {db: sql("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,IFNULL(SUB_PART,''),INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('receipt_note_item','receipt_note_item_expiry_date','branch_item_transfer_item') ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX", db) for db in schemas.values()}


def column_maps():
    result = {}
    for db in schemas.values():
        result[db] = {}
        for line in sql("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (" + ','.join("'" + table + "'" for table in tables) + ') ORDER BY TABLE_NAME,ORDINAL_POSITION', db).splitlines():
            table, column = line.split('\t')
            result[db].setdefault(table, []).append(column)
    return result


def fingerprints(columns):
    result = {}
    for db, db_tables in columns.items():
        for table, names in db_tables.items():
            query = 'SELECT ' + ','.join('`' + name + '`' for name in names) + ' FROM `' + table + '` ORDER BY id'
            with (folder / 'fingerprint.stderr.private.log').open('wb') as errors:
                process = subprocess.Popen(['mariadb', '--default-character-set=utf8mb4', '--binary-as-hex', '-N', '-B', db, '-e', query], stdout=subprocess.PIPE, stderr=errors)
                value = hashlib.sha256()
                while chunk := process.stdout.read(1024 * 1024):
                    value.update(chunk)
                require(process.wait() == 0, 'Fingerprint failed: ' + db + '.' + table)
            result[db + '.' + table] = {'sha256': value.hexdigest(), 'rows': int(sql('SELECT COUNT(*) FROM `' + table + '`', db).strip())}
        print('Fingerprinted ' + db, flush=True)
    return result


def app_hashes():
    paths = []
    for root in roots.values():
        paths.extend(root.joinpath('app').rglob('*.php'))
        paths.extend(root / name for name in ['.env', 'index.php', 'spark', 'composer.json', 'composer.lock', 'vendor/autoload.php'])
    paths.extend(path for directory in ['/etc/cron.d', '/var/spool/cron/crontabs'] for path in Path(directory).glob('*'))
    return {str(path): digest(path) for path in paths if path.is_file()}


def metadata(path):
    stat = path.stat()
    return {'uid': stat.st_uid, 'gid': stat.st_gid, 'mode': stat.st_mode & 0o777, 'sha256': digest(path)}


def replace(source, target, record):
    staged = target.with_name('.' + target.name + '.batch_codes_hold')
    shutil.copyfile(source, staged)
    os.chown(staged, record['uid'], record['gid'])
    staged.chmod(record['mode'])
    staged.replace(target)


def probe(before=False):
    runtime = '/usr/bin/php8.2' if args.environment == 'uat' else '/usr/bin/php8.3'
    result = subprocess.run([runtime, str(release / 'probe.php'), str(roots[args.environment]), args.environment] + (['--before'] if before else []), capture_output=True, text=True)
    (folder / ('probe.before.private.json' if before else 'probe.after.private.json')).write_text(result.stdout)
    (folder / 'probe.stderr.private.log').write_text(result.stderr)
    require(result.returncode == 0, 'Application probe failed; inspect private results')
    data = json.loads(result.stdout)
    require(data.get('ok'), 'Application probe failed')
    print(args.environment + ' application credential probe: ' + str(data['checks']) + ' checks; no business writes.', flush=True)
    return data


def inspect_app():
    for env, root in roots.items():
        for name, expected in manifest['methods'].items():
            source = (root / name).read_text()
            actual = {}
            for match in re.finditer(r'^    (?:public|protected|private) (?:static )?function (\w+)\(', source, re.M):
                end = source.find('\n    }', match.start())
                actual[match.group(1)] = hashlib.sha256(source[match.start():end + 6].encode()).hexdigest()
            require(actual == expected, 'Deployed method drift: ' + env + '/' + name)
        for name in manifest['methods']:
            runtime = '/usr/bin/php8.2' if env == 'uat' else '/usr/bin/php8.3'
            run([runtime, '-l', str(root / name)])
    probe(before=True)
    print('Both deployed PHP method baselines match; no PHP replacement required.', flush=True)


def inspect_db():
    require(digest(release / 'install.sql') == manifest['sql_sha256'], 'Installer hash mismatch')
    count = 0
    for branch, db in schemas.items():
        for table in tables:
            engine = sql("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "'", db).strip()
            if engine:
                require(engine == 'InnoDB', 'Nontransactional dependency: ' + db + '.' + table)
        for table in batch_tables:
            row = sql("SELECT DATA_TYPE,IS_NULLABLE,IFNULL(CHARACTER_MAXIMUM_LENGTH,0),EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "' AND COLUMN_NAME='batch_no'", db).rstrip('\n')
            if not row and table == 'branch_item_transfer_item' and branch != 0:
                require(not sql("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branch_item_transfer_item'", db).strip(), 'Unexpected branch transfer table')
                continue
            fields = row.split('\t')
            require(len(fields) == 4, 'Missing expected batch column: ' + db + '.' + table)
            kind, nullable, length, extra = fields
            require(nullable == 'YES' and not extra and (kind == 'varchar' and int(length) >= 255 or kind == 'int' and table != 'receipt_note_item'), 'Incompatible batch column: ' + db + '.' + table)
            count += 1
        require(sql("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='install_receipt_batches'", db).strip() == '0', 'Unexpected installer routine')
    require(count == 17, 'Unexpected schema scope')
    privileged = sql("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE IN ('SUPER','READ_ONLY ADMIN') AND REPLACE(SUBSTRING_INDEX(GRANTEE,'@',1),CHAR(39),'') NOT IN ('root','mysql')").strip()
    require(privileged == '0', 'Unreviewed privileged writer')
    print(args.environment + ': eight schemas, 17 columns, transactional tables and writer privileges checked.', flush=True)


lock = open('/run/batch_codes_deploy.lock', 'w')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
if args.server == 'app':
    if args.mode == 'inspect':
        inspect_app()
    elif args.mode == 'backup':
        inspect_app()
        require(not (folder / 'app.original.json').exists(), 'Backup already exists')
        records = {}
        for env, root in roots.items():
            records[env] = {}
            for name in list(manifest['methods']) + ['.htaccess', '.env']:
                path = root / name
                records[env][name] = metadata(path)
                target = folder / 'before' / env / name
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(path, target)
                target.chmod(0o600)
        save('app.original.json', records)
        save('app.hashes.json', app_hashes())
        save('app.logs.json', {str(path): path.stat().st_size for root in roots.values() for path in (root / 'writable/logs').glob('*.log')})
        save('app.services.json', {service: service_state(service) for service in ['cron', 'php8.2-fpm', 'php8.3-fpm']})
        print('Both applications backed up privately, with permissions/configuration hashes and service states.', flush=True)
    elif args.mode == 'hold':
        require(app_hashes() == load('app.hashes.json'), 'Application/configuration drift')
        require(not (folder / 'app.hold.json').exists(), 'Hold already recorded')
        save('app.hold.json', {'scope': 'both environments; shared database write hold'})
        run(['systemctl', 'stop', 'cron'])
        for env, root in roots.items():
            require(digest(root / '.htaccess') == load('app.original.json')[env]['.htaccess']['sha256'], 'Routing drift')
            hold = folder / ('htaccess.hold.' + env)
            hold.write_bytes(b'# Temporary authorized batch-code schema deployment\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n</IfModule>\n' + (folder / 'before' / env / '.htaccess').read_bytes())
            replace(hold, root / '.htaccess', load('app.original.json')[env]['.htaccess'])
            code = run(['curl', '--max-time', '25', '-sS', '-o', '/dev/null', '-w', '%{http_code}', 'https://' + root.parent.name + '/items/transfer_report'], text=True)
            require(code == '503', 'HTTP maintenance hold failed: ' + env)
        for service, state in load('app.services.json').items():
            if service != 'cron' and state == 'active':
                run(['systemctl', 'stop', service])
        print('Both HTTP holds verified; application cron and PHP workers paused.', flush=True)
    elif args.mode in ['verify', 'recover']:
        require(app_hashes() == load('app.hashes.json'), 'PHP/configuration/schedule fingerprint changed')
        result = probe() if args.mode == 'verify' else {'ok': True}
        new_errors = 0
        for root in roots.values():
            for path in (root / 'writable/logs').glob('*.log'):
                start = load('app.logs.json').get(str(path), 0)
                content = path.read_bytes()[start:].decode(errors='replace')
                if any(value in content for value in ['Unknown column', 'Uncaught TypeError', 'CRITICAL -', 'Class "App\\']):
                    new_errors += 1
        require(new_errors == 0, 'New application errors; hold retained for investigation')
        save('app.verified.json', {'ok': True, 'probe': result, 'application_hashes': 'unchanged', 'new_errors': 0})
        print('Application PHP/configuration/schedules unchanged; no new critical/query errors.', flush=True)
    elif args.mode == 'resume':
        require(load('app.verified.json')['ok'], 'Verification required')
        for service, state in load('app.services.json').items():
            if service != 'cron' and state == 'active':
                run(['systemctl', 'start', service])
        for env, root in roots.items():
            replace(folder / 'before' / env / '.htaccess', root / '.htaccess', load('app.original.json')[env]['.htaccess'])
            require(metadata(root / '.htaccess') == load('app.original.json')[env]['.htaccess'], 'Routing metadata not restored')
        if load('app.services.json')['cron'] == 'active':
            run(['systemctl', 'start', 'cron'])
        require({name: service_state(name) for name in load('app.services.json')} == load('app.services.json'), 'Service restoration mismatch')
        save('app.resumed.json', {'ok': True, 'both_routes': 'restored', 'services': load('app.services.json')})
        print('Both application routes, PHP services and original cron settings restored.', flush=True)
    else:
        raise RuntimeError('Schema-only release has no application apply step')
else:
    if args.mode == 'inspect':
        inspect_db()
    elif args.mode == 'backup':
        inspect_db()
        require(not (folder / 'db.columns.json').exists(), 'Backup already exists')
        columns = column_maps()
        save('db.columns.json', columns)
        save('db.definitions.json', definitions())
        save('db.indexes.json', indexes())
        save('db.columns.before.json', {db: sql("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,IFNULL(COLLATION_NAME,''),IFNULL(COLUMN_DEFAULT,''),EXTRA,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('receipt_note_item','receipt_note_item_expiry_date','branch_item_transfer_item') ORDER BY TABLE_NAME,ORDINAL_POSITION", db) for db in schemas.values()})
        for db, db_tables in columns.items():
            with (folder / (db + '_definitions.private.sql')).open('wb') as output:
                result = subprocess.run(['mariadb-dump', '--no-data', '--routines', '--triggers', db] + list(db_tables), stdout=output, stderr=subprocess.PIPE)
                require(result.returncode == 0, 'Definitions backup failed')
        print('Exact table/routine/trigger definitions and index baselines saved.', flush=True)
    elif args.mode == 'hold':
        inspect_db()
        require(not (folder / 'db.hold.json').exists(), 'Hold already recorded')
        state = {'read_only': int(sql('SELECT @@global.read_only').strip()), 'event_scheduler': sql('SELECT @@global.event_scheduler').strip(), 'cron': service_state('cron')}
        save('db.hold.json', state)
        run(['systemctl', 'stop', 'cron'])
        if state['event_scheduler'] == 'ON':
            sql('SET GLOBAL event_scheduler=OFF')
        sql('SET GLOBAL read_only=ON')
        for attempt in range(60):
            if sql('SELECT COUNT(*) FROM information_schema.INNODB_TRX').strip() == '0':
                break
            time.sleep(1)
        else:
            raise RuntimeError('Transactions did not drain; retain hold')
        require(definitions() == load('db.definitions.json') and indexes() == load('db.indexes.json'), 'Definition/index drift')
        columns = load('db.columns.json')
        for db, db_tables in columns.items():
            with (folder / (db + '_batch_rows.private.sql')).open('wb') as output:
                result = subprocess.run(['mariadb-dump', '--single-transaction', '--skip-triggers', '--hex-blob', db] + [table for table in batch_tables if table in db_tables], stdout=output, stderr=subprocess.PIPE)
                require(result.returncode == 0, 'Batch data backup failed')
        save('db.before.json', fingerprints(columns))
        print('Shared database writes held; transactions drained; affected rows backed up and all protected rows fingerprinted.', flush=True)
    elif args.mode == 'apply':
        require(sql('SELECT @@global.read_only').strip() == '1', 'Write hold required')
        require((folder / 'db.before.json').exists(), 'Protected fingerprint required')
        require(digest(release / 'install.sql') == manifest['sql_sha256'], 'Installer drift')
        require(definitions() == load('db.definitions.json') and indexes() == load('db.indexes.json'), 'Last-minute definition/index drift')
        for db in schemas.values():
            for iteration in range(2):
                run(['mariadb', '--default-character-set=utf8mb4', '--binary-mode', db], input=(release / 'install.sql').read_bytes())
            print('Converted and verified repeat installation: ' + db, flush=True)
    elif args.mode in ['verify', 'recover']:
        if args.mode == 'recover':
            for db in schemas.values():
                sql('DROP PROCEDURE IF EXISTS install_receipt_batches', db)
        after = fingerprints(load('db.columns.json'))
        require(after == load('db.before.json'), 'Historical data mismatch; retain hold')
        require(definitions() == load('db.definitions.json'), 'Routine/trigger drift; retain hold')
        require(indexes() == load('db.indexes.json'), 'Existing index drift; retain hold')
        verified_columns = 0
        for branch, db in schemas.items():
            for table in batch_tables:
                if table == 'branch_item_transfer_item' and branch != 0:
                    continue
                row = sql("SELECT DATA_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" + table + "' AND COLUMN_NAME='batch_no'", db).strip().split('\t')
                require(len(row) == 4 and row[0] == 'varchar' and row[1] == 'YES' and int(row[2]) >= 255 and row[3] == 'utf8mb4_bin', 'Column conversion not complete; retain hold')
                verified_columns += 1
        save('db.verified.json', {'ok': True, 'columns': verified_columns, 'tables': len(after), 'rows': sum(value['rows'] for value in after.values()), 'fingerprints_sha256': hashlib.sha256(json.dumps(after, sort_keys=True).encode()).hexdigest(), 'definitions': 'unchanged', 'indexes': 'unchanged'})
        print(args.environment + ': ' + str(verified_columns) + ' columns verified; ' + str(len(after)) + ' historical table fingerprints, indexes and all routine/trigger definitions unchanged.', flush=True)
    elif args.mode == 'resume':
        require(load('db.verified.json')['ok'], 'Verification required')
        state = load('db.hold.json')
        sql('SET GLOBAL read_only=' + str(state['read_only']))
        if state['event_scheduler'] == 'ON':
            sql('SET GLOBAL event_scheduler=ON')
        if state['cron'] == 'active':
            run(['systemctl', 'start', 'cron'])
        require(int(sql('SELECT @@global.read_only').strip()) == state['read_only'], 'Read-only state restoration failed')
        save('db.resumed.json', state)
        print('Original database read-only, event scheduler and cron settings restored.', flush=True)
