#!/usr/bin/env python3
"""VPS5 CORS-only patch, exact backups, atomic replacement, and automatic recovery."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import urllib.error
import urllib.request

os.umask(0o077)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('environment', choices=['uat', 'prod'])
parser.add_argument('release', type=Path)
args = parser.parse_args()
folder = args.release / args.environment
folder.mkdir(parents=True, exist_ok=False)
domain = ('zuat-' if args.environment == 'uat' else '') + 'accounting-back.myt-emr.com'
root = Path('/home/mytemr/web') / domain / 'public_html'
targets = {'uat': {'app/Common.php': '70af07060894d5dc165d0e4b0d9e6135238f9ce831e358601a2c9316aab7c8cc',
                   'app/Config/BootstrapGuard.php': '3d16d4cbefeeda2ad51dd8a1dc4a078f8de68473a9894d82c7de2cbe0f7efdc2'},
           'prod': {'app/Common.php': '5b78129266cf617e104f8fced084541f368e95e9a7f26dc5d5a5396cfc74d852',
                    '.htaccess': 'e379190780e2c85465600f552a02a4e00ca8c8b14f4c7f4e801d34c72724e59d'}}[args.environment]
old_php = 'Content-Type, Authorization, X-Requested-With, api-key, API-KEY, latitude, longitude, X-Cron-Secret'
old_apache = 'Content-Type, Authorization, X-Requested-With, api-key, latitude, longitude'
origin = 'http://192.168.1.11:3001' if args.environment == 'uat' else 'https://accounting-front.myt-emr.com'


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def save(name, value):
    (folder / name).write_text(json.dumps(value, indent=2) + '\n')


def run(command):
    result = subprocess.run(command, capture_output=True, text=True)
    if result.returncode:
        (folder / 'command_error.private.log').write_text(result.stderr)
        raise RuntimeError('Command failed; inspect private command log: ' + command[0])
    return result.stdout


def lint(path):
    if path.suffix == '.php':
        for php in ['/usr/bin/php8.2', '/usr/bin/php8.3']:
            if Path(php).exists():
                run([php, '-l', str(path)])


def reload_php():
    for service in ['php8.2-fpm', 'php8.3-fpm']:
        if subprocess.run(['systemctl', 'is-active', '--quiet', service]).returncode == 0:
            run(['systemctl', 'reload', service])


def preflight(selected_origin=origin):
    request = urllib.request.Request('https://' + domain + '/items/get_items_list?kind=pharmacy&branch_id=2',
        method='OPTIONS', headers={'Origin': selected_origin, 'Access-Control-Request-Method': 'GET',
                                   'Access-Control-Request-Headers': 'api-key,origin-api-key'})
    with urllib.request.urlopen(request, timeout=30) as response:
        # Include duplicate CORS values rather than silently hiding conflicting server/PHP headers.
        return {'status': response.status, 'headers': {name.lower(): response.headers.get_all(name)
                for name in response.headers if name.lower().startswith('access-control-') or name.lower() == 'vary'}}


def replace(source, name, record):
    target = root / name
    staged = target.with_name('.' + target.name + '.origin_api_key_cors')
    shutil.copyfile(source, staged)
    os.chown(staged, record['uid'], record['gid'])
    staged.chmod(record['mode'])
    staged.replace(target)


protected_names = ['.env', 'index.php', 'app/Core/MYT_Controller.php', 'app/Config/Constants.php',
                   'app/Config/Filters.php', 'app/Filters/SecurityHeaders.php', 'app/Config/BootstrapGuard.php', '.htaccess']
protected = {name: digest(root / name) for name in protected_names if name not in targets}
before = preflight()
save('preflight.before.json', before)
save('unallowed_origin.before.json', preflight('https://cors-not-allowed.invalid'))
manifest = {}
for name, expected_hash in targets.items():
    path = root / name
    if digest(path) != expected_hash:
        raise RuntimeError('Unreviewed deployed drift: ' + name)
    original = path.read_bytes().decode('utf-8')
    old = old_apache if name == '.htaccess' else old_php
    if original.count(old) != 1:
        raise RuntimeError('Ambiguous expected CORS header list: ' + name)
    new = old.replace('api-key, ', 'api-key, Origin-API-Key, ', 1)
    updated = original.replace(old, new, 1)
    if args.environment == 'prod' and name == 'app/Common.php':
        # The deployed validator already calls this Core helper, but legacy Constants lacks it.
        if 'function myt_env(' in original:
            raise RuntimeError('Unexpected existing environment helper; review compatibility patch')
        anchor = original.index('function csr_api_key_is_valid(')
        comment = updated.rfind('/**', 0, updated.index('function csr_api_key_is_valid('))
        updated = updated[:comment] + (args.release / 'compatibility.php.fragment').read_text() + '\n' + updated[comment:]
    for kind, content in [('before', path.read_bytes()), ('stage', updated.encode())]:
        target = folder / kind / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
    stat = path.stat()
    manifest[name] = {'before': expected_hash, 'after': digest(folder / 'stage' / name),
                      'uid': stat.st_uid, 'gid': stat.st_gid, 'mode': stat.st_mode & 0o777}
    lint(folder / 'stage' / name)
save('manifest.json', manifest)
save('protected.before.json', protected)
save('services.before.json', {service: subprocess.run(['systemctl', 'is-active', service], capture_output=True, text=True).stdout.strip()
                            for service in ['cron', 'php8.2-fpm', 'php8.3-fpm']})
print(args.environment + ' exact files backed up, drift guards and staged syntax passed.', flush=True)
try:
    for name, record in manifest.items():
        if digest(root / name) != record['before']:
            raise RuntimeError('Deployed file changed after backup')
        replace(folder / 'stage' / name, name, record)
        lint(root / name)
    if args.environment == 'prod':
        run(['apache2ctl', 'configtest'])
    reload_php()
    after = preflight()
    save('preflight.after.json', after)
    if after['status'] != before['status']:
        raise RuntimeError('Unexpected preflight status change')
    for name, values in before['headers'].items():
        if name != 'access-control-allow-headers' and after['headers'].get(name) != values:
            raise RuntimeError('Existing CORS policy changed: ' + name)
    allowed = {value.strip().lower() for line in after['headers'].get('access-control-allow-headers', []) for value in line.split(',')}
    old_allowed = {value.strip().lower() for line in before['headers'].get('access-control-allow-headers', []) for value in line.split(',')}
    if allowed != old_allowed | {'origin-api-key'}:
        raise RuntimeError('Unexpected CORS allowed-header set')
    unexpected_origin = preflight('https://cors-not-allowed.invalid')
    save('unallowed_origin.after.json', unexpected_origin)
    old_unexpected = json.loads((folder / 'unallowed_origin.before.json').read_text())
    for name in ['access-control-allow-origin', 'access-control-allow-methods', 'access-control-allow-credentials']:
        if unexpected_origin['headers'].get(name) != old_unexpected['headers'].get(name):
            raise RuntimeError('Existing origin policy changed')
    result = subprocess.run(['/usr/bin/php8.2', str(args.release / 'probe.php'), args.environment], capture_output=True, text=True)
    (folder / 'http.private.json').write_text(result.stdout)
    (folder / 'http.stderr.private.log').write_text(result.stderr)
    if result.returncode or not json.loads(result.stdout).get('ok'):
        raise RuntimeError('Read-only GET verification failed; inspect private probe logs')
    if {name: digest(root / name) for name in protected} != protected:
        raise RuntimeError('Unrelated application/configuration drift')
    for name, record in manifest.items():
        if digest(root / name) != record['after']:
            raise RuntimeError('Installed checksum mismatch')
    save('verified.json', {'environment': args.environment, 'ok': True, 'preflight': after,
                           'http': json.loads(result.stdout), 'protected_files_unchanged': True})
    print(args.environment + ' CORS deployed; preflight, read-only GET checks, hashes and preserved policies passed.', flush=True)
except Exception:
    for name, record in reversed(list(manifest.items())):
        replace(folder / 'before' / name, name, record)
    reload_php()
    recovered = preflight()
    if recovered != before or any(digest(root / name) != record['before'] for name, record in manifest.items()):
        raise RuntimeError('Recovery verification failed; inspect exact backups before further changes')
    save('recovered.json', {'ok': True, 'preflight': recovered})
    print(args.environment + ' previous files and preflight behavior restored after failed verification.', flush=True)
    raise
