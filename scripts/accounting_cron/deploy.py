#!/usr/bin/env python3
"""Run privately on VPS5. Scoped cron/model repair with backups and recovery."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import zoneinfo

os.umask(0o077)
p = argparse.ArgumentParser(description=__doc__)
p.add_argument('release', type=Path)
p.add_argument('environment', choices=['uat', 'prod'])
p.add_argument('mode', choices=['prepare', 'apply', 'verify', 'recover'])
a = p.parse_args()
release = a.release.resolve()
assert os.geteuid() == 0 and str(release).startswith('/root/accounting_cron_')
folder = release / a.environment
host = ('zuat-' if a.environment == 'uat' else '') + 'accounting-back.myt-emr.com'
root = Path('/home/mytemr/web') / host / 'public_html'
config = Path('/home/mytemr/conf/web') / host
php = '/usr/bin/php8.2' if a.environment == 'uat' else '/usr/bin/php8.3'
helper = config / ('uat-accounting-cron.php' if a.environment == 'uat' else 'production-accounting-cron.php')
model = root / 'app/Models/Report.php'
hestia = Path('/usr/local/hestia/data/users/mytemr/cron.conf')
includes = [config / 'apache2.conf_accounting_cron', config / 'apache2.ssl.conf_accounting_cron']
methods = json.loads((release / 'report_methods.json').read_text())
expected_jobs = 10 if a.environment == 'uat' else 11
user = pwd.getpwnam('mytemr')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def save(name, value):
    (folder / name).write_text(json.dumps(value, indent=2) + '\n')


def read(name):
    return json.loads((folder / name).read_text())


def run(command, name, ok=(0,)):
    result = subprocess.run(command, capture_output=True, text=True)
    (folder / (name + '.stdout.private.log')).write_text(result.stdout)
    (folder / (name + '.stderr.private.log')).write_text(result.stderr)
    if result.returncode not in ok:
        raise RuntimeError('Command failed: ' + name)
    return result


def crontab():
    return subprocess.check_output(['crontab', '-u', 'mytemr', '-l'], text=True)


def atomic(path, content, uid, gid, mode):
    staged = path.with_name('.' + path.name + '.accounting_cron')
    staged.write_bytes(content)
    os.chown(staged, uid, gid)
    staged.chmod(mode)
    staged.replace(path)


def install_crontab(text, label):
    path = folder / (label + '.crontab.private')
    path.write_text(text)
    run(['crontab', '-u', 'mytemr', str(path)], label)


def targeted(line):
    return ('https://' + host + '/cron/') in line


def transform(text, persistent=False):
    result = []
    count = 0
    for line in text.splitlines():
        if targeted(line):
            count += 1
            if a.environment == 'uat':
                line, changed = re.subn(r'wget\s+-q\s+-O\s+-\s+(https://zuat-accounting-back\.myt-emr\.com/cron/[a-z0-9_]+)(?=\s)',
                    lambda m: php + ' ' + str(helper) + ' ' + m[1], line)
                if changed != 1:
                    raise RuntimeError('Unreviewed UAT cron command')
            elif 'production-accounting-cron.php' not in line:
                raise RuntimeError('Unreviewed production cron command')
            if '/cron/snapshot_item_inventory' in line:
                if persistent:
                    line, changed = re.subn(r"HOUR='(?:11|23)'", "HOUR='23'", line)
                    if changed != 1:
                        raise RuntimeError('Unexpected Hestia snapshot schedule')
                else:
                    line, changed = re.subn(r'^0\s+(?:11|23)\s+', '0 23 ', line)
                    if changed != 1:
                        raise RuntimeError('Unexpected snapshot schedule')
        result.append(line)
    if count != expected_jobs:
        raise RuntimeError('Unexpected cron job count')
    return '\n'.join(result) + '\n'


def probe(mode):
    result = run([php, str(release / 'probe.php'), str(root), str(folder), mode], mode)
    data = json.loads(result.stdout)
    if not data['ok']:
        raise RuntimeError('Probe failed: ' + mode)
    save(mode + '.json', data)
    return data


def verify():
    manifest = read('manifest.private.json')
    for path, record in manifest['files'].items():
        if digest(Path(path)) != record['after']:
            raise RuntimeError('Installed file drift: ' + path)
        stat = Path(path).stat()
        if (stat.st_uid, stat.st_gid, stat.st_mode & 0o777) != (record['uid'], record['gid'], record['mode']):
            raise RuntimeError('Installed file permission drift: ' + path)
    current = crontab()
    current_jobs = [line for line in current.splitlines() if targeted(line)]
    expected_lines = [line for line in (folder / 'crontab.after.private').read_text().splitlines() if targeted(line)]
    if current_jobs != expected_lines or [line for line in hestia.read_text().splitlines() if targeted(line)] != [line for line in (folder / 'hestia.after.private').read_text().splitlines() if targeted(line)]:
        raise RuntimeError('Scheduler mismatch')
    if digest(root / '.env') != manifest['env_hash']:
        raise RuntimeError('Private configuration changed')
    for name, state in manifest['services'].items():
        if subprocess.run(['systemctl', 'is-active', name], capture_output=True, text=True).stdout.strip() != state:
            raise RuntimeError('Service state changed: ' + name)
    other_environment = 'prod' if a.environment == 'uat' else 'uat'
    other_folder = release / other_environment
    other_host = ('zuat-' if other_environment == 'uat' else '') + 'accounting-back.myt-emr.com'
    other_installed = (other_folder / 'installed.json').is_file()
    other_targeted = lambda line: ('https://' + other_host + '/cron/') in line
    for current_text, old_name in [(current, 'crontab.before.private'), (hestia.read_text(), 'hestia.before.private')]:
        if other_installed:
            other_expected = (other_folder / old_name.replace('.before.', '.after.')).read_text()
            if [line for line in current_text.splitlines() if other_targeted(line)] != [line for line in other_expected.splitlines() if other_targeted(line)]:
                raise RuntimeError('Other released environment scheduler drift')
        untouched = lambda text: [line for line in text.splitlines() if not targeted(line) and not (other_installed and other_targeted(line))]
        if untouched(current_text) != untouched((folder / old_name).read_text()):
            raise RuntimeError('Unrelated scheduler drift')
    report = {'ok': True, 'environment': a.environment, 'jobs': expected_jobs, 'snapshot_schedule': '0 23 * * *',
        'timezone': 'Asia/Manila', 'secret_preserved': True,
        'files': {path: record['after'] for path, record in manifest['files'].items()}, 'backup': str(folder)}
    save('installed.json', report)
    return report


def recover():
    manifest = read('manifest.private.json')
    for path, record in manifest['files'].items():
        target = Path(path)
        if record['before'] is None:
            target.unlink(missing_ok=True)
        else:
            atomic(target, (folder / record['backup']).read_bytes(), record['uid'], record['gid'], record['mode'])
    current = crontab()
    old = (folder / 'crontab.before.private').read_text()
    # The cutover is short; stop rather than discard unrelated scheduler changes.
    untouched = lambda text: [line for line in text.splitlines() if not targeted(line)]
    if untouched(current) != untouched(old):
        raise RuntimeError('Unrelated cron drift; review before scheduler recovery')
    install_crontab(old, 'restored')
    stat = hestia.stat()
    atomic(hestia, (folder / 'hestia.before.private').read_bytes(), stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
    run(['apache2ctl', '-t'], 'recovery_apache_test')
    run(['systemctl', 'reload', 'apache2'], 'recovery_apache_reload')
    run(['systemctl', 'reload', 'php8.2-fpm' if a.environment == 'uat' else 'php8.3-fpm'], 'recovery_php_reload')
    save('recovered.json', {'ok': True})


if a.mode == 'prepare':
    folder.mkdir(mode=0o700, exist_ok=False)
    source = model.read_text()
    for name in methods:
        if re.search(r'function\s+' + name + r'\s*\(', source):
            raise RuntimeError('Unexpected existing report method: ' + name)
    needle = '    public function daily_branch_sales_report_mantlewood()'
    if source.count(needle) != 1:
        raise RuntimeError('Report insertion point mismatch')
    (folder / 'Report.stage.php').write_text(source.replace(needle, '\n\n'.join(methods.values()) + '\n\n' + needle, 1))
    text = (release / 'request.php').read_text().replace('__ACCOUNTING_ROOT__', str(root) + '/').replace('__ACCOUNTING_HOST__', host)
    (folder / 'helper.stage.php').write_text(text)
    (folder / 'timeout.stage').write_text('# Accounting cron requests need more than the global 30 seconds.\nProxyTimeout 300\n')
    before = crontab()
    persistent = hestia.read_text()
    (folder / 'crontab.before.private').write_text(before)
    (folder / 'hestia.before.private').write_text(persistent)
    (folder / 'crontab.after.private').write_text(transform(before))
    (folder / 'hestia.after.private').write_text(transform(persistent, True))
    manifest = {'env_hash': digest(root / '.env'), 'files': {}}
    for index, (target, stage) in enumerate([(model, 'Report.stage.php'), (helper, 'helper.stage.php')] + [(path, 'timeout.stage') for path in includes]):
        if target in includes and target.exists():
            raise RuntimeError('Unexpected existing timeout override')
        record = {'before': digest(target), 'after': digest(folder / stage), 'stage': stage,
            'uid': user.pw_uid, 'gid': user.pw_gid, 'mode': 0o600 if target == helper else 0o644, 'backup': 'before_' + str(index)}
        if target.exists():
            stat = target.stat()
            record.update(uid=stat.st_uid, gid=stat.st_gid, mode=stat.st_mode & 0o777)
            (folder / record['backup']).write_bytes(target.read_bytes())
        manifest['files'][str(target)] = record
    shutil.copyfile(root / '.env', folder / 'environment.before.private')
    manifest['services'] = {name: subprocess.run(['systemctl', 'is-active', name], capture_output=True, text=True).stdout.strip() for name in ['apache2', 'nginx', 'cron', 'php8.2-fpm', 'php8.3-fpm']}
    manifest['env_stat'] = {'uid': (root / '.env').stat().st_uid, 'gid': (root / '.env').stat().st_gid, 'mode': (root / '.env').stat().st_mode & 0o777}
    save('manifest.private.json', manifest)
    for stage in ['Report.stage.php', 'helper.stage.php']:
        run([php, '-l', str(folder / stage)], 'lint_' + stage)
    probe('preflight')
    probe('backup_snapshot')
    print(json.dumps({'ok': True, 'environment': a.environment, 'prepared': True, 'backup': str(folder)}))
elif a.mode == 'apply':
    manifest = read('manifest.private.json')
    if crontab() != (folder / 'crontab.before.private').read_text() or hestia.read_text() != (folder / 'hestia.before.private').read_text():
        raise RuntimeError('Scheduler drift before cutover')
    if digest(root / '.env') != manifest['env_hash'] or any(digest(Path(path)) != record['before'] for path, record in manifest['files'].items()):
        raise RuntimeError('Application/configuration drift before cutover')
    paused = '\n'.join('# ACCOUNTING_CRON_PAUSED ' + line if targeted(line) else line for line in crontab().splitlines()) + '\n'
    install_crontab(paused, 'paused')
    try:
        for path, record in manifest['files'].items():
            atomic(Path(path), (folder / record['stage']).read_bytes(), record['uid'], record['gid'], record['mode'])
        run(['apache2ctl', '-t'], 'apache_test')
        run(['systemctl', 'reload', 'apache2'], 'apache_reload')
        run(['systemctl', 'reload', 'php8.2-fpm' if a.environment == 'uat' else 'php8.3-fpm'], 'php_reload')
        run(['runuser', '-u', 'mytemr', '--', 'test', '-r', str(helper)], 'helper_readable')
        run(['runuser', '-u', 'mytemr', '--', 'test', '-r', str(root / '.env')], 'environment_readable')
        stat = hestia.stat()
        atomic(hestia, (folder / 'hestia.after.private').read_bytes(), stat.st_uid, stat.st_gid, stat.st_mode & 0o777)
        install_crontab((folder / 'crontab.after.private').read_text(), 'installed')
        print(json.dumps(verify()))
    except BaseException:
        recover()
        raise
elif a.mode == 'verify':
    print(json.dumps(verify()))
elif a.mode == 'recover':
    recover()
    print(json.dumps({'ok': True, 'recovered': a.environment}))
