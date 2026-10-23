#!/usr/bin/env python3
"""Private production parity tooling; never prints configuration values."""
import argparse
import datetime
import difflib
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tarfile
import urllib.request
import zoneinfo

os.umask(0o077)
ROOT = Path('/home/mytemr/web/accounting-back.myt-emr.com/public_html')
UAT = Path('/home/mytemr/web/zuat-accounting-back.myt-emr.com/public_html')
PHP = '/usr/bin/php8.3'


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def tree_digest(root):
    checksum=hashlib.sha256()
    for path in sorted(root.rglob('*')):
        if path.is_file():
            checksum.update(str(path.relative_to(root)).encode()+b'\0'+hashlib.sha256(path.read_bytes()).digest())
    return checksum.hexdigest()


def run(args, logfile):
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    logfile.write_bytes(result.stdout)
    if result.returncode:
        raise RuntimeError('Command failed; inspect private ' + str(logfile))
    return result.stdout


def save(path, data):
    path.write_text(json.dumps(data, indent=2) + '\n')


def app_hashes(root):
    names = ['index.php', 'spark', '.htaccess', '.env', 'composer.json', 'composer.lock']
    names += [str(p.relative_to(root)) for p in (root / 'app').rglob('*') if p.is_file()]
    return {name: digest(root / name) for name in sorted(names)}


def functions(path, release):
    return json.loads(subprocess.check_output([PHP, str(release / 'php_inventory.php'), 'functions', str(path)]))


def replace_functions(original, reference, names, release, allow_add=False):
    source = original.read_text(); target = reference.read_text()
    old = functions(original, release); new = functions(reference, release)
    edits = []
    additions = []
    for name in names:
        if name not in new:
            raise RuntimeError('Missing reference method ' + name)
        content = target[new[name]['start']:new[name]['end']]
        if name in old:
            edits.append((old[name]['start'], old[name]['end'], content))
        elif allow_add:
            additions.append(content)
        else:
            raise RuntimeError('Missing production method ' + name)
    for start, end, content in sorted(edits, reverse=True):
        source = source[:start] + content + source[end:]
    if additions:
        # Named methods are inserted before the final class closing brace.
        position = source.rfind('}')
        source = source[:position] + '\n\n' + '\n\n'.join(additions) + '\n' + source[position:]
    return source


def prepare(release):
    folder = release / 'prod'
    folder.mkdir(exist_ok=False)
    save(folder / 'uat.before.json', app_hashes(UAT))
    save(folder / 'production.before.json', app_hashes(ROOT))
    backup = folder / 'before'
    backup.mkdir()
    for name in ['app', 'vendor']:
        with tarfile.open(backup / (name + '.tar.gz'), 'w:gz') as archive:
            archive.add(ROOT / name, arcname=name)
    for name in ['.env', '.htaccess', 'index.php', 'spark', 'composer.json', 'composer.lock']:
        shutil.copy2(ROOT / name, backup / name)
    metadata = {}
    for base in [ROOT, Path('/home/mytemr/conf/web/accounting-back.myt-emr.com')]:
        for path in base.rglob('*') if base != ROOT else [ROOT / name for name in app_hashes(ROOT)]:
            if path.is_file():
                stat = path.stat()
                metadata[str(path)] = {'uid': stat.st_uid, 'gid': stat.st_gid, 'mode': stat.st_mode & 0o777, 'sha256': digest(path)}
    for path in [Path('/var/spool/cron/crontabs/mytemr'), Path('/usr/local/hestia/data/users/mytemr/cron.conf')]:
        target = backup / str(path).lstrip('/')
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, target)
        stat = path.stat()
        metadata[str(path)] = {'uid':stat.st_uid,'gid':stat.st_gid,'mode':stat.st_mode & 0o777,'sha256':digest(path)}
    shutil.copytree('/home/mytemr/conf/web/accounting-back.myt-emr.com', backup / 'vhost', symlinks=True)
    save(folder / 'metadata.private.json', metadata)
    run([PHP, str(release / 'php_inventory.php'), 'snapshot', str(ROOT), str(folder / 'production.config.private')], folder / 'snapshot.log')
    run(['/usr/bin/php8.2', str(release / 'php_inventory.php'), 'snapshot', str(UAT), str(folder / 'uat.config.private')], folder / 'uat_snapshot.log')
    source = release / 'uat_source'
    shutil.copytree(UAT / 'app/Config', source / 'app/Config')
    for name in ['app/Common.php','app/Core/MYT_Controller.php','app/Core/MYT_Model.php','app/Libraries/CorsExceptionHandler.php','app/Libraries/PageInventory.php','app/Filters/SecurityHeaders.php','index.php','spark','.htaccess']:
        target = source / name; target.parent.mkdir(parents=True, exist_ok=True); shutil.copy2(UAT / name, target)
    for name in ['Login','Roles','Pages','Permissions','Files','Prints','User_portals','Users','Cron']:
        target = source / ('app/Controllers/' + name + '.php');target.parent.mkdir(parents=True, exist_ok=True);shutil.copy2(UAT / ('app/Controllers/' + name + '.php'),target)
    # Source-level security diff inventory contains function names/hashes only.
    diffs = {}
    for name in ['app/Common.php','app/Core/MYT_Controller.php','app/Core/MYT_Model.php'] + ['app/Controllers/'+name+'.php' for name in ['Login','Roles','Pages','Permissions','Files','Prints','User_portals','Users','Cron']]:
        old=functions(ROOT/name,release);new=functions(source/name,release)
        a=(ROOT/name).read_text();b=(source/name).read_text()
        diffs[name]={'changed':[key for key in old.keys() & new.keys() if a[old[key]['start']:old[key]['end']] != b[new[key]['start']:new[key]['end']]],'new':sorted(new.keys()-old.keys())}
    save(folder/'method_diffs.json',diffs)
    print(json.dumps({'ok':True,'phase':'prepare','backup':str(backup),'method_diffs':diffs}),flush=True)


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase',choices=['prepare', 'build'])
    parser.add_argument('release',type=Path)
    args=parser.parse_args()
    if not str(args.release).startswith('/root/prod_uat_parity_'):
        raise RuntimeError('Private production release path required')
    if args.phase == 'prepare':
        prepare(args.release)
    else:
        from build import build
        build(args.release)
