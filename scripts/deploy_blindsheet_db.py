#!/usr/bin/env python3
"""Private VPS6 blindsheet rollout: scoped DDL only, no live posting/backfill."""
import argparse, hashlib, json, os, re, subprocess, time, xml.etree.ElementTree as ET
from pathlib import Path

os.umask(0o077)
p = argparse.ArgumentParser(description=__doc__)
p.add_argument('mode', choices=['inspect','backup','pause','before','apply','verify','rollback','resume'])
p.add_argument('environment', choices=['uat','prod']); p.add_argument('release', type=Path)
a = p.parse_args(); release = a.release; out = release/a.environment; out.mkdir(parents=True, exist_ok=True)
bases = {0:'mytemr_master',1:'mytemr_diagnostic_mobile',2:'mytemr_diagnostic',3:'mytemr_diagnostic_mactan',4:'mytemr_diagnostic_conso',5:'mytemr_oakridge_warehouse',6:'mytemr_oakridge_pharmacy',7:'mytemr_diagnostic_mantlewood'}
schemas = {branch: name+('_uat' if a.environment=='uat' else '') for branch,name in bases.items()}
clinic_ids = [1,2,3,4,7]
tables = ['branch_item_blindsheet','branch_item','item','item_conversion','item_cost','item_history','general_ledger','journal_entry','journal_entry_item']
accounting_objects = [('FUNCTION','gl_item_history_remarks'),('PROCEDURE','gl_post_item_history'),('TRIGGER','trg_gl_item_history_ai'),('TRIGGER','trg_gl_item_history_au')]

def save(name, value): (out/name).write_text(json.dumps(value,indent=2)+'\n')
def sql(statement, db=None):
    command=['mariadb','--default-character-set=utf8mb4','-N','-B']+([db] if db else [])+['-e',statement]
    result=subprocess.run(command,text=True,capture_output=True)
    if result.returncode: raise RuntimeError('Database metadata command failed; details retained by server logs.')
    return result.stdout

def objects(branch): return [('TRIGGER','trg_branch_item_update')]+(accounting_objects if branch in clinic_ids else [])
def object_fields(db, kind, name):
    data=subprocess.check_output(['mariadb','--xml',db,'-e',f'SHOW CREATE {kind} `{name}`']).decode('utf8').replace('\r','&#13;')
    fields={f.attrib['name']:f.text for f in ET.fromstring(data).findall('.//field')}
    definition=fields.get('Create Procedure') or fields.get('Create Function') or fields.get('SQL Original Statement')
    if not definition: raise RuntimeError('Missing definition: '+db+'.'+name)
    return {'kind':kind,'name':name,'definition':definition,'fields':fields}

def normalize(value):
    value=re.sub(r'CREATE\s+DEFINER\s*=\s*\S+\s+','CREATE ',value,flags=re.I)
    value=re.sub(r'\s+(?:FOLLOWS|PRECEDES)\s+`?\w+`?','',value,flags=re.I)
    value=re.sub(r'--[^\n]*','',value).replace('`','')
    value=re.sub(r'\b(?:int|integer)\(11\)','int',value,flags=re.I)
    return re.sub(r'\s+',' ',value).strip().lower()

def column_map():
    result={}
    for db in schemas.values():
        result[db]={}
        for table in tables:
            names=sql(f"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{db}' AND TABLE_NAME='{table}' ORDER BY ORDINAL_POSITION").splitlines()
            if names: result[db][table]=names
    return result

def fingerprint(columns):
    result={}
    for db, db_tables in columns.items():
        result[db]={}
        for table,names in db_tables.items():
            projection=','.join('`'+name+'`' for name in names)
            process=subprocess.Popen(['mariadb','--default-character-set=utf8mb4','--binary-as-hex','-N','-B',db,'-e',f'SELECT {projection} FROM `{table}` ORDER BY id'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            digest=hashlib.sha256(); size=0
            while chunk:=process.stdout.read(1024*1024): digest.update(chunk); size+=len(chunk)
            if process.wait(): raise RuntimeError('Fingerprint query failed: '+db+'.'+table)
            result[db][table]={'sha256':digest.hexdigest(),'bytes':size,'rows':int(sql(f'SELECT COUNT(*) FROM `{table}`',db).strip())}
        print('Fingerprinted '+db,flush=True)
    return result

def definitions():
    result={}
    for db in schemas.values():
        rows=sql("SELECT 'ROUTINE',ROUTINE_TYPE,ROUTINE_NAME,IFNULL(ROUTINE_DEFINITION,''),SQL_MODE,SECURITY_TYPE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_TYPE,ROUTINE_NAME",db)
        triggers=sql("SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_ORDER,ACTION_STATEMENT,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME",db)
        result[db]={'routine_rows':rows,'trigger_rows':triggers}
    return result

def definition_digest(value, exclude):
    result={}
    for db, rows in value.items():
        result[db]={}
        for kind,text in rows.items():
            filtered=[]
            pattern=(r'(?m)^ROUTINE\t(?:PROCEDURE|FUNCTION)\t([^\t\r\n]+)\t' if kind=='routine_rows' else r'(?m)^([^\t\r\n]+)\t(?:INSERT|UPDATE|DELETE)\t[^\t\r\n]+\t(?:BEFORE|AFTER)\t\d+\t')
            matches=list(re.finditer(pattern,text))
            for index,match in enumerate(matches):
                end=matches[index+1].start() if index+1<len(matches) else len(text)
                if match.group(1) not in exclude.get(db,set()): filtered.append(text[match.start():end])
            result[db][kind]=hashlib.sha256('\n'.join(filtered).encode()).hexdigest()
    return result

def ordering(db):
    rows=sql('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_ORDER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_ORDER',db)
    return [dict(zip(['name','table','event','timing','order'],line.split('\t'))) for line in rows.splitlines()]

def ordered_create(text, name, rows):
    target=next((row for row in rows if row['name']==name),None)
    if not target: return text
    peers=[row for row in rows if (row['table'],row['event'],row['timing'])==(target['table'],target['event'],target['timing']) and row['name']!=name]
    after=[row for row in peers if int(row['order'])>int(target['order'])]
    before=[row for row in peers if int(row['order'])<int(target['order'])]
    clause=(' PRECEDES `'+after[0]['name']+'`') if after else (' FOLLOWS `'+before[-1]['name']+'`' if before else '')
    return re.sub(r'FOR EACH ROW',lambda match:match.group()+clause,text,count=1,flags=re.I)

def install(db, text, old, rows):
    kind,name=old['kind'],old['name']; fields=old['fields']
    definer=re.search(r'CREATE\s+(DEFINER\s*=\s*\S+)\s+',old['definition'],re.I)
    if definer: text=re.sub(r'^CREATE\s+',lambda _: 'CREATE '+definer.group(1)+' ',text,count=1,flags=re.I)
    if kind=='TRIGGER': text=ordered_create(text,name,rows)
    mode=(fields.get('sql_mode') or '').replace("'","''")
    charset=fields.get('character_set_client') or 'utf8mb4'; collation=fields.get('collation_connection') or 'utf8mb4_unicode_ci'
    if not re.fullmatch(r'\w+',charset) or not re.fullmatch(r'\w+',collation): raise RuntimeError('Invalid saved charset metadata')
    content=f"SET SESSION sql_mode='{mode}';\nSET NAMES {charset} COLLATE {collation};\nDELIMITER $$\nDROP {kind} IF EXISTS `{name}`$$\n{text}$$\nDELIMITER ;\n"
    database_collation=fields.get('Database Collation')
    current_collation=sql("SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()",db).strip()
    if database_collation and not re.fullmatch(r'\w+',database_collation): raise RuntimeError('Invalid saved database collation')
    # CREATE captures the database default separately from the connection collation.
    # Restore that historical metadata without changing the schema's final default.
    change_collation=database_collation and database_collation!=current_collation
    try:
        if change_collation: sql(f'ALTER DATABASE `{db}` COLLATE {database_collation}',db)
        subprocess.run(['mariadb','--binary-mode','--comments','--default-character-set=utf8mb4',db],input=content,text=True,check=True,capture_output=True)
    finally:
        if change_collation: sql(f'ALTER DATABASE `{db}` COLLATE {current_collation}',db)

def baseline_check():
    expected=json.loads((release/'baseline.json').read_text())
    info={}
    for branch,db in schemas.items():
        for kind,name in objects(branch):
            got=object_fields(db,kind,name)
            if normalize(got['definition'])!=normalize(expected[a.environment][name]): raise RuntimeError('Unexpected deployed definition: '+db+'.'+name)
        missing=sql("SELECT required.name FROM (SELECT 'branch_item' name UNION SELECT 'item' UNION SELECT 'item_conversion' UNION SELECT 'item_cost' UNION SELECT 'item_history') required LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=required.name WHERE t.TABLE_NAME IS NULL OR t.ENGINE<>'InnoDB'",db).strip()
        if missing: raise RuntimeError('Missing inventory dependency: '+db)
        info[db]={'definitions':'match pre-feature baseline','inventory_dependencies':'InnoDB'}
    save('preflight.json',info)
    return info

if a.mode=='inspect':
    baseline_check(); print(a.environment+' all eight schemas match pre-feature definitions and inventory dependencies.',flush=True)
elif a.mode=='backup':
    baseline_check(); columns=column_map(); save('columns.json',columns); save('definitions.before.json',definitions())
    scoped={}; orders={}
    for branch,db in schemas.items():
        scoped[db]=[object_fields(db,kind,name) for kind,name in objects(branch)]; orders[db]=ordering(db)
        with (out/(db+'_tables.sql')).open('wb') as handle:
            subprocess.run(['mariadb-dump','--single-transaction','--skip-triggers','--hex-blob',db]+list(columns[db]),stdout=handle,check=True)
        with (out/(db+'_definitions.sql')).open('wb') as handle:
            subprocess.run(['mariadb-dump','--no-data','--routines','--triggers',db],stdout=handle,check=True)
        print('Backed up '+db,flush=True)
    save('scoped.before.json',scoped); save('ordering.before.json',orders)
    (out/'RECOVERY.txt').write_text('Retain the write hold. Restore previous API controller before removing its new model. Run db_deploy.py rollback to restore exact scoped definitions and verify historical fingerprints. Additive tables/columns/indexes remain. Resume database/app maintenance settings only after recovery verification succeeds.\n')
elif a.mode=='pause':
    cron=subprocess.run(['systemctl','is-active','cron'],capture_output=True,text=True).stdout.strip()
    state={'read_only':int(sql('SELECT @@global.read_only').strip()),'event_scheduler':sql('SELECT @@global.event_scheduler').strip(),'cron':cron}
    if not (out/'maintenance.json').exists(): save('maintenance.json',state)
    subprocess.run(['systemctl','stop','cron'],check=True)
    if state['event_scheduler']=='ON': sql('SET GLOBAL event_scheduler=OFF')
    sql('SET GLOBAL read_only=ON')
    for _ in range(30):
        if int(sql('SELECT COUNT(*) FROM information_schema.INNODB_TRX').strip())==0: break
        time.sleep(2)
    else: raise RuntimeError('Transactions did not drain; maintenance hold retained.')
    print('Database write hold enabled; scheduled root writers paused; transactions drained.',flush=True)
elif a.mode=='before':
    if int(sql('SELECT @@global.read_only').strip())!=1: raise RuntimeError('Missing database write hold')
    baseline_check(); save('before.json',fingerprint(json.loads((out/'columns.json').read_text())))
    print(a.environment+' immutable historical baseline captured.',flush=True)
elif a.mode=='apply':
    if int(sql('SELECT @@global.read_only').strip())!=1: raise RuntimeError('Missing database write hold')
    baseline_check(); scoped=json.loads((out/'scoped.before.json').read_text()); orders=json.loads((out/'ordering.before.json').read_text()); installed={}
    for branch,db in schemas.items():
        subprocess.run(['mariadb',db],input=(release/'schema.sql').read_text(),text=True,capture_output=True,check=True)
        inventory=(release/'inventory_history.sql').read_text()
        trigger=re.search(r'CREATE TRIGGER\b.*?END\$\$',inventory,re.S).group()[:-2]
        old=scoped[db][0]; install(db,trigger,old,orders[db]); installed[db]={old['name']:normalize(trigger)}
        if branch in clinic_ids:
            source=(release/('accounting_'+a.environment+'.sql')).read_text()
            for old in scoped[db][1:]:
                text=re.search(r'CREATE '+old['kind']+' '+old['name']+r'\b.*?END\$\$',source,re.S).group()[:-2]
                install(db,text,old,orders[db]); installed[db][old['name']]=normalize(text)
        print('Installed scoped blindsheet schema/definitions: '+db,flush=True)
    save('installed.json',installed)
elif a.mode=='verify':
    installed=json.loads((out/'installed.json').read_text()); scoped=json.loads((out/'scoped.before.json').read_text())
    after=fingerprint(json.loads((out/'columns.json').read_text())); save('after.json',after)
    if after!=json.loads((out/'before.json').read_text()): raise RuntimeError('Historical row fingerprints changed; keep write hold.')
    evidence={}
    for branch,db in schemas.items():
        for old in scoped[db]:
            got=object_fields(db,old['kind'],old['name'])
            if normalize(got['definition'])!=installed[db][old['name']]: raise RuntimeError('Installed definition mismatch: '+db+'.'+old['name'])
            for field in ['sql_mode','character_set_client','collation_connection','Database Collation']:
                if got['fields'].get(field)!=old['fields'].get(field): raise RuntimeError('Saved SQL settings changed: '+db+'.'+old['name'])
            if re.search(r'DEFINER\s*=\s*\S+',got['definition']).group()!=re.search(r'DEFINER\s*=\s*\S+',old['definition']).group(): raise RuntimeError('Definer changed')
        if int(sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branch_item_blindsheet' AND COLUMN_NAME IN ('added_by_branch_id','updated_by_branch_id','approved_by_branch_id','disapproved_by_branch_id')",db).strip())!=4: raise RuntimeError('Audit columns missing: '+db)
        if int(sql("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branch_item_blindsheet' AND INDEX_NAME IN ('idx_blindsheet_pending','idx_blindsheet_branch')",db).strip())!=2: raise RuntimeError('Indexes missing')
        if 'branch_item_blindsheet' not in json.loads((out/'columns.json').read_text())[db] and int(sql('SELECT COUNT(*) FROM branch_item_blindsheet',db).strip())!=0: raise RuntimeError('New table unexpectedly populated')
        evidence[db]={'historical_fingerprints':'unchanged','definitions':'match','audit_columns':4,'accounting':'source clinic only' if branch in clinic_ids else 'unchanged'}
    exclude={db:{old['name'] for old in values} for db,values in scoped.items()}
    before_defs=json.loads((out/'definitions.before.json').read_text()); after_defs=definitions(); save('definitions.after.json',after_defs)
    if definition_digest(before_defs,exclude)!=definition_digest(after_defs,exclude): raise RuntimeError('Unrelated routine/trigger definitions changed')
    if json.loads((out/'ordering.before.json').read_text())!={db:ordering(db) for db in schemas.values()}: raise RuntimeError('Trigger execution order changed')
    save('verified.json',evidence); print(a.environment+' verified: eight schemas, exact definitions/settings, unchanged history and unrelated accounting.',flush=True)
elif a.mode=='rollback':
    scoped=json.loads((out/'scoped.before.json').read_text()); orders=json.loads((out/'ordering.before.json').read_text())
    before_definitions=json.loads((out/'definitions.before.json').read_text())
    for db,values in scoped.items():
        for old in values:
            text=re.sub(r'^CREATE\s+DEFINER\s*=\s*\S+\s+','CREATE ',old['definition'],flags=re.I)
            if old['kind']=='TRIGGER':
                # Earlier XML snapshots normalize CRLF. Recover the exact body from
                # the independent batch metadata snapshot, whose CR became LF in
                # Python universal-newline reading and whose LF is escaped by SQL.
                raw=before_definitions[db]['trigger_rows']
                matches=list(re.finditer(r'(?m)^([^\t\r\n]+)\t(?:INSERT|UPDATE|DELETE)\t[^\t\r\n]+\t(?:BEFORE|AFTER)\t\d+\t',raw))
                for index,match in enumerate(matches):
                    if match.group(1)==old['name']:
                        end=matches[index+1].start() if index+1<len(matches) else len(raw)
                        body=raw[match.start():end].split('\t')[5].replace('\n','\r')
                        escaped={'0':'\0','n':'\n','t':'\t','r':'\r','b':'\b','\\':'\\'}
                        body=re.sub(r'\\([0ntrb\\])',lambda part:escaped[part.group(1)],body)
                        text=re.sub(r'(FOR EACH ROW\s+).*',lambda part:part.group(1).rstrip()+' '+body,text,count=1,flags=re.I|re.S)
                        break
            install(db,text,old,orders[db])
    after=fingerprint(json.loads((out/'columns.json').read_text()))
    if (out/'before.json').exists() and after!=json.loads((out/'before.json').read_text()): raise RuntimeError('Recovery history fingerprints differ; keep write hold.')
    if definitions()!=json.loads((out/'definitions.before.json').read_text()): raise RuntimeError('Recovery definitions differ; keep write hold.')
    save('recovered.json',{'history':'unchanged','definitions':'restored','additive_schema':'retained'})
    print('Exact definitions restored; recovery history verified; additive schema retained.',flush=True)
elif a.mode=='resume':
    if not (out/'verified.json').exists() and not (out/'recovered.json').exists(): raise RuntimeError('Neither deployment nor recovery verified; keep maintenance hold.')
    state=json.loads((out/'maintenance.json').read_text()); sql('SET GLOBAL read_only='+str(state['read_only']))
    if state['event_scheduler']=='ON': sql('SET GLOBAL event_scheduler=ON')
    if state['cron']=='active': subprocess.run(['systemctl','start','cron'],check=True)
    if int(sql('SELECT @@global.read_only').strip())!=state['read_only']: raise RuntimeError('Original maintenance setting not restored')
    print('Original database write and scheduler settings restored.',flush=True)
