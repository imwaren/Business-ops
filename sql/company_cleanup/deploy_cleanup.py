#!/usr/bin/env python3
"""Production maintenance/backups wrapper; explicitly invoked on VPS6 only."""
import argparse
import datetime
import gzip
import hashlib
import json
import pathlib
import shutil
import subprocess
import sys
import run_cleanup


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--work-dir', required=True, type=pathlib.Path)
    p.add_argument('--defaults-file')
    args = p.parse_args()
    work = args.work_dir.resolve()
    work.mkdir(mode=0o700, parents=True, exist_ok=False)
    client = ['mariadb']
    dump_client = ['mariadb-dump']
    if args.defaults_file:
        opt = '--defaults-extra-file=' + str(pathlib.Path(args.defaults_file).resolve())
        client.append(opt); dump_client.append(opt)
    client += ['--batch', '--raw', '--skip-column-names', '--default-character-set=utf8mb4']
    def sql(query):
        return subprocess.run(client, input=query, text=True, capture_output=True, check=True).stdout
    def progress(message):
        print(datetime.datetime.now().isoformat(timespec='seconds') + ' ' + message, flush=True)
    package = pathlib.Path(__file__).resolve().parent
    refs = json.loads((package/'reference_inventory.json').read_text())
    tables = {(r['schema'],r['table']) for r in refs} | {('mytemr_master','company'),('mytemr_master','customer_account')}
    bypass = sql("SELECT User,Host FROM mysql.user WHERE Super_priv='Y' AND User NOT IN ('root','mysql') UNION SELECT User,Host FROM mysql.global_priv WHERE User NOT IN ('root','mysql') AND (CAST(JSON_VALUE(Priv,'$.access') AS UNSIGNED)&8589934592)<>0;")
    if bypass.strip():
        raise RuntimeError('Unreviewed read-only bypass account exists; abort before maintenance')
    previous = sql('SELECT @@GLOBAL.read_only,@@GLOBAL.event_scheduler;').strip().split('\t')
    settings = {'read_only':int(previous[0]),'event_scheduler':previous[1]}
    (work/'maintenance_before.json').write_text(json.dumps(settings,indent=2))
    progress('Enabling server read-only maintenance; pausing event scheduler')
    sql('SET GLOBAL read_only=1;')
    if settings['event_scheduler'] != 'DISABLED':
        sql('SET GLOBAL event_scheduler=OFF;')
    # Leave maintenance enabled on failures; do not resume without verified recovery.
    privileged = sql("SELECT ID,USER,HOST,DB,COMMAND FROM information_schema.processlist WHERE ID<>CONNECTION_ID() AND USER IN ('root','mysql') AND COMMAND<>'Sleep';")
    if privileged.strip():
        raise RuntimeError('Other active privileged sessions need review; maintenance remains enabled')
    progress('Maintenance enabled; validating live preflight again')
    command = [sys.executable,str(package/'run_cleanup.py'),'preview','--work-dir',str(work/'preview')]
    if args.defaults_file: command += ['--defaults-file',args.defaults_file]
    subprocess.run(command,check=True)
    # Dump each affected table with no trigger DDL. Keep database identity in each backup.
    sizes = sql("SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.tables WHERE TABLE_SCHEMA IN ("+','.join(run_cleanup.sql_string(x) for x in run_cleanup.SCHEMAS)+");").strip()
    if shutil.disk_usage(work).free < int(sizes)+1024**3:
        raise RuntimeError('Insufficient backup headroom; maintenance remains enabled')
    manifest = {}
    for db in run_cleanup.SCHEMAS:
        selected = sorted(t for d,t in tables if d==db)
        path = work/(db+'.affected_tables.sql.gz')
        progress('Backing up '+db+' ('+str(len(selected))+' tables)')
        proc = subprocess.Popen(dump_client+['--single-transaction','--quick','--skip-triggers','--hex-blob',db]+selected,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        with gzip.open(path,'wb') as gz:
            gz.write(('USE '+run_cleanup.quote(db)+';\n').encode())
            for chunk in iter(lambda:proc.stdout.read(1024*1024),b''):
                gz.write(chunk)
        error = proc.stderr.read().decode()
        if proc.wait(): raise RuntimeError('Backup failed: '+error)
        if path.stat().st_size<100: raise RuntimeError('Empty backup: '+str(path))
        digest = hashlib.sha256()
        with path.open('rb') as f:
            for chunk in iter(lambda:f.read(1024*1024),b''):digest.update(chunk)
        manifest[path.name]={'bytes':path.stat().st_size,'sha256':digest.hexdigest(),'tables':selected}
    (work/'backup_manifest.json').write_text(json.dumps(manifest,indent=2))
    progress('Backups completed; executing cleanup with trigger preservation')
    command = [sys.executable,str(package/'run_cleanup.py'),'apply','--maintenance-confirmed','--work-dir',str(work/'apply')]
    if args.defaults_file: command+=['--defaults-file',args.defaults_file]
    subprocess.run(command,check=True)
    # run_cleanup refuses success until trigger definitions and accounting fingerprints match.
    progress('Cleanup verified; restoring original maintenance settings')
    if settings['event_scheduler'] != 'DISABLED':
        sql('SET GLOBAL event_scheduler='+settings['event_scheduler']+';')
    sql('SET GLOBAL read_only='+str(settings['read_only'])+';')
    final = sql('SELECT @@GLOBAL.read_only,@@GLOBAL.event_scheduler;').strip().split('\t')
    if final != previous: raise RuntimeError('Maintenance settings did not restore exactly')
    (work/'SUCCESS.json').write_text(json.dumps({'settings_restored':True,'completed_at':datetime.datetime.now().isoformat()},indent=2))
    progress('SUCCESS: company cleanup committed, accounting unchanged, triggers/settings restored. Recovery: '+str(work))


if __name__=='__main__':
    try:
        main()
    except Exception as error:
        print('FAILED: '+str(error)+'\nIf maintenance was enabled, keep it enabled until recovery checks pass.',file=sys.stderr)
        sys.exit(1)
