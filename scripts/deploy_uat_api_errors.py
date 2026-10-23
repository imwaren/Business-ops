#!/usr/bin/env python3
"""UAT-only scoped error handling release. Run privately on VPS5 with a reviewed package."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import urllib.request

os.umask(0o077)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('release', type=Path)
args = parser.parse_args()
release = args.release.resolve()
if not str(release).startswith('/root/uat_api_errors_'):
    raise RuntimeError('Unexpected private release location')
folder = release / 'uat'
folder.mkdir(exist_ok=False)
root = Path('/home/mytemr/web/zuat-accounting-back.myt-emr.com/public_html')
prod = Path('/home/mytemr/web/accounting-back.myt-emr.com/public_html')
expected = json.loads((release / 'release.json').read_text())


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def save(name, data):
    (folder / name).write_text(json.dumps(data, indent=2) + '\n')


def run(command):
    result = subprocess.run(command, capture_output=True, text=True)
    if result.returncode:
        (folder / 'command_error.private.log').write_text(result.stdout + result.stderr)
        raise RuntimeError('Command failed; inspect private command log: ' + command[0])
    return result.stdout


def lint(path):
    for php in ['/usr/bin/php8.2', '/usr/bin/php8.3']:
        if Path(php).exists():
            run([php, '-l', str(path)])


def reload_php():
    for service in ['php8.2-fpm', 'php8.3-fpm']:
        if subprocess.run(['systemctl', 'is-active', '--quiet', service]).returncode == 0:
            run(['systemctl', 'reload', service])


def replace(source, name, record):
    target = root / name
    staged = target.with_name('.' + target.name + '.uat_api_errors')
    shutil.copyfile(source, staged)
    os.chown(staged, record['uid'], record['gid'])
    staged.chmod(record['mode'])
    staged.replace(target)


def preflight():
    request = urllib.request.Request('https://zuat-accounting-back.myt-emr.com/items/get_items_list', method='OPTIONS',
        headers={'Origin': 'http://192.168.1.11:3001', 'Access-Control-Request-Method': 'GET',
                 'Access-Control-Request-Headers': 'api-key,origin-api-key'})
    with urllib.request.urlopen(request, timeout=30) as response:
        return {'status': response.status, 'headers': {name.lower(): response.headers.get_all(name)
                for name in response.headers if name.lower().startswith('access-control-') or name.lower() == 'vary'}}


def probe(name, script, *arguments):
    result = subprocess.run(['/usr/bin/php8.2', str(release / script), *arguments], capture_output=True, text=True)
    (folder / (name + '.private.json')).write_text(result.stdout)
    (folder / (name + '.stderr.private.log')).write_text(result.stderr)
    decoded = json.loads(result.stdout)
    if result.returncode or not decoded.get('ok'):
        raise RuntimeError('Private verification failed: ' + name)
    return decoded


protected_names = ['.htaccess', 'app/Common.php', 'app/Core/MYT_Controller.php', 'app/Config/Constants.php',
                   'app/Config/Filters.php', 'app/Config/Exceptions.php', 'app/Config/Logger.php',
                   'app/Filters/SecurityHeaders.php']
protected = {str(base / name): digest(base / name) for base in [root, prod]
             for name in protected_names + (list(expected) + ['.env'] if base == prod else [])}
save('protected.before.json', protected)
save('maintenance.before.json', {'changed': False, 'cron': digest(Path('/etc/crontab')),
      'services': {name: subprocess.run(['systemctl', 'is-active', name], capture_output=True, text=True).stdout.strip()
                   for name in ['cron', 'php8.2-fpm', 'php8.3-fpm']}})
before = preflight()
save('preflight.before.json', before)
manifest = {}
for name, hashes in expected.items():
    target = root / name
    if digest(target) != hashes['before'] or digest(release / 'stage' / name) != hashes['after']:
        raise RuntimeError('Unreviewed file drift: ' + name)
    backup = folder / 'before' / name
    backup.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(target, backup)
    stat = target.stat()
    manifest[name] = {**hashes, 'uid': stat.st_uid, 'gid': stat.st_gid, 'mode': stat.st_mode & 0o777}
    lint(release / 'stage' / name)
# The only private configuration change is the UAT detail flag.
env_path = root / '.env'
original = env_path.read_bytes()
backup = folder / 'before/.env'
backup.write_bytes(original)
text = original.decode('utf-8')
if len(re.findall(r'^\s*ERROR_RESPONSE_DETAILS\s*=', text, re.M)) > 1:
    raise RuntimeError('Ambiguous existing error detail flag')
updated = re.sub(r'^\s*ERROR_RESPONSE_DETAILS\s*=.*$', 'ERROR_RESPONSE_DETAILS = true', text, flags=re.M) if re.search(r'^\s*ERROR_RESPONSE_DETAILS\s*=', text, re.M) else text.rstrip('\n') + '\n\nERROR_RESPONSE_DETAILS = true\n'
staged_env = folder / 'stage.env'
staged_env.write_text(updated)
stat = env_path.stat()
manifest['.env'] = {'before': digest(env_path), 'after': digest(staged_env), 'uid': stat.st_uid, 'gid': stat.st_gid, 'mode': stat.st_mode & 0o777}
save('manifest.private.json', manifest)
# Keep a public manifest without private configuration hashes.
save('manifest.json', {name: record for name, record in manifest.items() if name != '.env'})
boot_log = root / 'writable/logs/boot-error.log'
boot_before = {'size': boot_log.stat().st_size, 'mtime_ns': boot_log.stat().st_mtime_ns} if boot_log.exists() else None
save('historical_boot_log.before.json', boot_before)
print('UAT exact files/configuration backed up; staged syntax and drift guards passed.', flush=True)
try:
    # Guard is compatible with old callers; enable details only after all PHP replacements.
    for name, record in manifest.items():
        if digest(root / name) != record['before']:
            raise RuntimeError('File changed after backup: ' + name)
        replace(staged_env if name == '.env' else release / 'stage' / name, name, record)
        if name.endswith('.php'):
            lint(root / name)
    reload_php()
    cli = probe('synthetic_cli', 'probe.php')
    after = preflight()
    if after != before or after['status'] != 204:
        raise RuntimeError('CORS preflight changed')
    http = probe('http', 'http_probe.php', 'uat')
    if any(digest(Path(name)) != value for name, value in protected.items()):
        raise RuntimeError('Unrelated UAT/production file drift')
    if any(digest(root / name) != record['after'] for name, record in manifest.items()):
        raise RuntimeError('Installed checksum mismatch')
    boot_after = {'size': boot_log.stat().st_size, 'mtime_ns': boot_log.stat().st_mtime_ns} if boot_log.exists() else None
    if boot_before != boot_after:
        raise RuntimeError('Historical bootstrap log changed')
    save('verified.json', {'ok': True, 'environment': 'uat', 'cli': cli, 'preflight': after, 'http': http,
         'production_unchanged': True, 'unrelated_files_unchanged': True, 'historical_boot_log_unchanged': True,
         'maintenance_settings_changed': False, 'opcode_caches_refreshed': True})
    print('UAT deployed: private CLI, GET/OPTIONS, hashes, preserved CORS and production isolation passed.', flush=True)
except Exception:
    for name, record in reversed(list(manifest.items())):
        replace(folder / 'before' / name, name, record)
    reload_php()
    if any(digest(root / name) != record['before'] for name, record in manifest.items()) or preflight() != before:
        raise RuntimeError('Recovery verification failed; inspect private backups before proceeding')
    save('recovered.json', {'ok': True, 'exact_files_restored': True})
    print('UAT exact files/configuration restored after failed verification.', flush=True)
    raise
