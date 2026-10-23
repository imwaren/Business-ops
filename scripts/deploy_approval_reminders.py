#!/usr/bin/env python3
"""VPS5-only targeted deployment. Backups, checksums and read-only release checks."""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import pwd
import re
import shutil
import subprocess


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('environment', choices=['uat', 'prod'])
    parser.add_argument('--release', required=True, type=pathlib.Path)
    parser.add_argument('--backup-root', required=True, type=pathlib.Path)
    args = parser.parse_args()
    host = 'zuat-accounting-back.myt-emr.com' if args.environment == 'uat' else 'accounting-back.myt-emr.com'
    root = pathlib.Path('/home/mytemr/web') / host / 'public_html'
    release = args.release.resolve()
    manifest = json.loads((release/'manifest.json').read_text())
    original = json.loads((release/'original.json').read_text())
    backup = args.backup_root / args.environment
    backup.mkdir(parents=True, mode=0o700, exist_ok=False)
    for name, expected in original.items():
        if digest(root/name) != expected:
            raise RuntimeError('Deployed controller changed since inspection: '+name)
    for name, expected in manifest.items():
        if not name.startswith('app/') or '..' in pathlib.Path(name).parts or digest(release/name) != expected:
            raise RuntimeError('Invalid release file: '+name)
        subprocess.run(['/usr/bin/php','-l',str(release/name)],check=True)
    existed = {}
    for name in list(manifest)+['.env']:
        dest = root/name
        existed[name] = dest.exists()
        if dest.exists():
            saved=backup/name; saved.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(dest,saved)
    (backup/'existed.json').write_text(json.dumps(existed,indent=2))
    user = pwd.getpwnam('mytemr')
    cron = pathlib.Path('/etc/cron.d/accounting-approval-reminders-'+args.environment)
    if cron.exists():
        shutil.copy2(cron,backup/'cron.before')
    try:
        for name in manifest:
            dest=root/name; dest.parent.mkdir(parents=True,exist_ok=True)
            temp=dest.with_name(dest.name+'.approval-release')
            shutil.copyfile(release/name,temp)
            os.chown(temp,user.pw_uid,user.pw_gid); os.chmod(temp,0o644)
            os.replace(temp,dest)
            subprocess.run(['/usr/bin/php','-l',str(dest)],check=True)
            if digest(dest)!=manifest[name]:
                raise RuntimeError('Checksum mismatch: '+name)
        env=root/'.env'
        text=env.read_text()
        for key,value in [('APPROVAL_REMINDER_MODE','log'),('APPROVAL_REMINDER_AP_ROLE','35')]:
            pattern=r'^\s*'+key+r'\s*=.*$'
            if re.search(pattern,text,re.M):
                text=re.sub(pattern,key+' = '+value,text,flags=re.M)
            else:
                text+='\n'+key+' = '+value+'\n'
        env.write_text(text)
        os.chown(env,user.pw_uid,user.pw_gid)
        command=['runuser','-u','mytemr','--','/usr/bin/php','spark','approval:reminders','--check']
        ready=subprocess.run(command,cwd=root,text=True,capture_output=True,check=True)
        (backup/'readiness.txt').write_text(ready.stdout+ready.stderr)
        if '"mode":"log"' not in ready.stdout or '"schemas"' not in ready.stdout:
            raise RuntimeError('Read-only readiness command did not confirm log mode and schemas.')
        cron.write_text('SHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
            '0 8-17 * * 1-5 mytemr cd '+str(root)+' && /usr/bin/flock -n '+str(root/'writable/cache/approval-reminders.lock')
            +' /usr/bin/php spark approval:reminders >> '+str(root/'writable/logs/approval-reminders-worker.log')+' 2>&1\n')
        os.chmod(cron,0o644)
        (backup/'deployed.json').write_text(json.dumps({'environment':args.environment,'mode':'log',
            'checksums':manifest,'checked_at':datetime.datetime.now().isoformat(),'cron':str(cron)},indent=2))
        print(ready.stdout)
        print('SUCCESS '+args.environment+' deployed in log-only mode. Backup: '+str(backup))
    except Exception:
        if (backup/'cron.before').exists():
            shutil.copy2(backup/'cron.before',cron)
        elif cron.exists():
            cron.unlink()
        for name,present in existed.items():
            dest=root/name
            if present:
                shutil.copy2(backup/name,dest)
            elif dest.exists():
                dest.unlink()
        raise


if __name__=='__main__':
    main()
