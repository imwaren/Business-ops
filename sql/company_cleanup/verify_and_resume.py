#!/usr/bin/env python3
"""Verify recovery artifacts and resume maintenance after exact trigger/ledger checks."""
import argparse,datetime,hashlib,json,pathlib,subprocess,xml.etree.ElementTree as ET
import run_cleanup

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--work-dir',required=True,type=pathlib.Path)
    p.add_argument('--repair-comment-differences',action='store_true')
    args=p.parse_args(); work=args.work_dir
    client=['mariadb','--default-character-set=utf8mb4','--batch','--raw','--skip-column-names','--comments']
    def sql(query,xml=False):
        r=subprocess.run(client+(['--xml'] if xml else []),input=query,text=True,capture_output=True,check=True)
        return r.stdout
    def create(t):
        f={e.attrib['name']:e.text or '' for e in ET.fromstring(sql('SHOW CREATE TRIGGER '+run_cleanup.quote(t['schema'])+'.'+run_cleanup.quote(t['name'])+';',True)).findall('.//field')}
        return f['SQL Original Statement']
    triggers=json.loads((work/'apply/triggers.json').read_text())
    current_meta=[x.split('\t') for x in sql("SELECT TRIGGER_SCHEMA,EVENT_OBJECT_TABLE,TRIGGER_NAME FROM information_schema.triggers WHERE EVENT_MANIPULATION='UPDATE' ORDER BY TRIGGER_SCHEMA,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_ORDER;").splitlines()]
    table_by_trigger={(db,name):(db,table) for db,table,name in current_meta}
    mismatches=[t for t in triggers if create(t)!=t['create']]
    if mismatches:
        if not args.repair_comment_differences: raise RuntimeError('Exact trigger definitions differ; keep maintenance enabled')
        def executable(text):
            return '\n'.join(line.strip() for line in text.splitlines() if line.strip() and not line.lstrip().startswith('--'))
        for t in mismatches:
            if executable(t['create'])!=executable(create(t)):
                raise RuntimeError('Non-comment trigger difference; refusing automatic repair: '+t['name'])
        affected={table_by_trigger[(t['schema'],t['name'])] for t in mismatches}
        # Restore every UPDATE hook for each affected table to retain original action ordering.
        repair=[t for t in triggers if table_by_trigger[(t['schema'],t['name'])] in affected]
        print('Recreating '+str(len(repair))+' UPDATE triggers with original comments/order',flush=True)
        for t in repair:sql('DROP TRIGGER '+run_cleanup.quote(t['schema'])+'.'+run_cleanup.quote(t['name'])+';')
        for t in repair:sql(t['restore'])
    for t in triggers:
        if create(t)!=t['create']:raise RuntimeError('Exact trigger mismatch: '+t['schema']+'.'+t['name'])
    saved={(t['schema'],t['name']) for t in triggers}
    actual=[(db,name) for db,table,name in [l.split('\t') for l in sql("SELECT TRIGGER_SCHEMA,EVENT_OBJECT_TABLE,TRIGGER_NAME FROM information_schema.triggers WHERE EVENT_MANIPULATION='UPDATE' ORDER BY TRIGGER_SCHEMA,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_ORDER;").splitlines()] if (db,name) in saved]
    if actual!=[(t['schema'],t['name']) for t in triggers]:raise RuntimeError('Original trigger order not restored')
    print('All '+str(len(triggers))+' exact trigger definitions and their order verified',flush=True)
    baseline=json.loads((work/'apply/ledger_before.json').read_text()); after={}
    for key in baseline:
        db,table=key.split('.')
        cols=sql('SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA='+run_cleanup.sql_string(db)+' AND TABLE_NAME='+run_cleanup.sql_string(table)+' ORDER BY ORDINAL_POSITION;').splitlines()
        obj=','.join(run_cleanup.sql_string(c)+','+run_cleanup.quote(c) for c in cols)
        query='SELECT SHA2(JSON_OBJECT('+obj+'),256) FROM '+run_cleanup.quote(db)+'.'+run_cleanup.quote(table)+' ORDER BY id;'
        proc=subprocess.Popen(client,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        proc.stdin.write(query.encode());proc.stdin.close();digest=hashlib.sha256()
        for line in proc.stdout:digest.update(line)
        error=proc.stderr.read().decode()
        if proc.wait():raise RuntimeError(error)
        after[key]=digest.hexdigest()
        print('Verified fingerprint '+key,flush=True)
    (work/'apply/ledger_after.json').write_text(json.dumps(after,indent=2))
    if after!=baseline:raise RuntimeError('Accounting fingerprints differ; do not resume')
    # Postflight is rerun with the approved snapshot/mapping in the same session.
    root=pathlib.Path(__file__).resolve().parent
    post=sql((root/'00_setup.sql').read_text()+'\n'+(root/'01_preflight.sql').read_text()+'\n'+(root/'03_postflight.sql').read_text())
    (work/'verified_postflight.txt').write_text(post)
    for line in post.splitlines():
        parts=line.split('\t')
        if len(parts)==2 and parts[0].startswith('mytemr_') and parts[1]!='0':
            raise RuntimeError('Remaining duplicate references: '+line)
    settings=json.loads((work/'maintenance_before.json').read_text())
    if settings['event_scheduler']!='DISABLED':sql('SET GLOBAL event_scheduler='+settings['event_scheduler']+';')
    sql('SET GLOBAL read_only='+str(settings['read_only'])+';')
    final=sql('SELECT @@GLOBAL.read_only,@@GLOBAL.event_scheduler;').strip().split('\t')
    if final!=[str(settings['read_only']),settings['event_scheduler']]:raise RuntimeError('Settings restoration mismatch')
    (work/'SUCCESS.json').write_text(json.dumps({'settings_restored':True,'trigger_count':len(triggers),'ledger_tables':len(after),'completed_at':datetime.datetime.now().isoformat()},indent=2))
    print('SUCCESS: references verified; accounting unchanged; exact triggers/order and original service settings restored',flush=True)

if __name__=='__main__':main()
