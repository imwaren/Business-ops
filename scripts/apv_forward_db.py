#!/usr/bin/env python3
"""Run on VPS6. Scoped forward deployment; never backfills/rebuilds accounting rows."""
import argparse, hashlib, json, subprocess, xml.etree.ElementTree as ET
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('mode',choices=['backup','before','apply','verify','rollback']);p.add_argument('environment',choices=['uat','prod']);p.add_argument('release');a=p.parse_args()
release=Path(a.release);out=release/a.environment;out.mkdir(parents=True,exist_ok=True)
schemas=[base+('_uat' if a.environment=='uat' else '') for base in ['mytemr_diagnostic_mobile','mytemr_diagnostic','mytemr_diagnostic_mactan','mytemr_diagnostic_conso','mytemr_diagnostic_mantlewood']]
procs=['gl_post_apv','gl_post_direct_payment'];funcs=['gl_apv_is_legacy'];triggers=['trg_gl_apv_legacy_bu','trg_gl_apv_entry_ai','trg_gl_apv_entry_au','trg_gl_apv_entry_ad','trg_gl_direct_payment_entry_ai','trg_gl_direct_payment_entry_au','trg_gl_direct_payment_entry_ad']
tables=['accounts_payable_voucher','accounts_payable_voucher_entry','direct_payment','direct_payment_entry','general_ledger','journal_entry','journal_entry_item']
def sql(db,statement):return subprocess.check_output(['mariadb','-N','-B',db,'-e',statement],text=True)
def fingerprints():
 result={}
 for db in schemas:
  result[db]={}
  for table in tables:
   process=subprocess.Popen(['mariadb','-N','-B',db,'-e',f'SELECT * FROM `{table}` ORDER BY id'],stdout=subprocess.PIPE)
   digest=hashlib.sha256();count=0
   while chunk:=process.stdout.read(1024*1024):digest.update(chunk);count+=len(chunk)
   if process.wait():raise RuntimeError('Fingerprint query failed')
   result[db][table]={'sha256':digest.hexdigest(),'bytes':count}
 return result
if a.mode=='backup':
 for db in schemas:
  with (out/(db+'_tables.sql')).open('wb') as f:
   subprocess.run(['mariadb-dump','--single-transaction','--skip-triggers',db]+tables,stdout=f,check=True)
  with (out/(db+'_all_definitions.sql')).open('wb') as f:
   subprocess.run(['mariadb-dump','--no-data','--routines','--triggers',db],stdout=f,check=True)
  restore=['DELIMITER $$']
  for name in triggers:restore.append(f'DROP TRIGGER IF EXISTS `{name}`$$')
  for name in funcs:restore.append(f'DROP FUNCTION IF EXISTS `{name}`$$')
  objects=[]
  for kind,names in [('PROCEDURE',procs),('FUNCTION',funcs),('TRIGGER',triggers)]:
   for name in names:
    proc=subprocess.run(['mariadb','--xml',db,'-e',f'SHOW CREATE {kind} `{name}`'],capture_output=True,text=True)
    if proc.returncode:
     if kind=='PROCEDURE':raise RuntimeError(proc.stderr)
     continue
    fields={f.attrib['name']:f.text for f in ET.fromstring(proc.stdout).findall('.//field')}
    definition=fields.get('Create Procedure') or fields.get('Create Function') or fields.get('SQL Original Statement')
    if not definition:raise RuntimeError('Missing exact definition for '+name)
    objects.append({'kind':kind,'name':name,**fields})
    mode=(fields.get('sql_mode') or '').replace("'","''")
    restore.append(f"SET SESSION sql_mode='{mode}'$$")
    restore.append(f'DROP {kind} IF EXISTS `{name}`$$')
    restore.append(definition+'$$')
  restore.append('DELIMITER ;')
  (out/(db+'_restore.sql')).write_text('\n'.join(restore)+'\n')
  (out/(db+'_objects.json')).write_text(json.dumps(objects,indent=2)+'\n')
 print('Exact definitions and affected tables backed up for five '+a.environment+' schemas.',flush=True)
elif a.mode=='before':
 if int(sql(schemas[0],'SELECT COUNT(*) FROM information_schema.INNODB_TRX').strip())>0:
  raise RuntimeError('Active DB transactions remain; drain requests before rollout')
 (out/'before.json').write_text(json.dumps(fingerprints(),indent=2)+'\n');print('Baseline historical fingerprints recorded.',flush=True)
elif a.mode=='apply':
 for db in schemas:
  for file in ['schema.sql','cutover.sql','posting_'+a.environment+'.sql']:
   subprocess.run(['mariadb',db],input=(release/file).read_text(),text=True,check=True)
  print('Installed additive schema and scoped posting definitions: '+db,flush=True)
elif a.mode=='verify':
 after=fingerprints();(out/'after.json').write_text(json.dumps(after,indent=2)+'\n')
 if after!=json.loads((out/'before.json').read_text()):raise RuntimeError('Historical table fingerprints changed')
 evidence={}
 for db in schemas:
  state=sql(db,"SELECT legacy_max_apv_id,legacy_max_direct_payment_id,cutover_on FROM apv_accounting_cutover WHERE id=1").strip()
  n=int(sql(db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('accounts_payable_voucher_entry','accounts_payable_voucher_amortization_entry','apv_accounting_cutover','direct_payment_entry')").strip());assert n==4
  assert int(sql(db,"SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('trg_gl_apv_legacy_bu','trg_gl_apv_entry_ai','trg_gl_apv_entry_au','trg_gl_apv_entry_ad')").strip())==4
  assert int(sql(db,"SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME IN ('gl_post_apv','gl_post_direct_payment','gl_apv_is_legacy')").strip())==3
  assert int(sql(db,"SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='direct_payment_entry'").strip())==0
  expected=(release/('posting_'+a.environment+'.sql')).read_text()
  import re
  for kind,name in [('PROCEDURE',x) for x in procs]+[('FUNCTION',x) for x in funcs]+[('TRIGGER',x) for x in triggers[:4]]:
   got=ET.fromstring(subprocess.check_output(['mariadb','--xml',db,'-e',f'SHOW CREATE {kind} `{name}`'],text=True))
   fields={f.attrib['name']:f.text for f in got.findall('.//field')}
   definition=fields.get('Create Procedure') or fields.get('Create Function') or fields.get('SQL Original Statement')
   exp=re.search(r'CREATE '+kind+' '+name+r'\b.*?END\$\$',expected,re.S)[0][:-2]
   def normalized(x):return re.sub(r'\s+',' ',re.sub(r'tinyint\(4\)','tinyint',re.sub(r'CREATE DEFINER=\S+\s+','CREATE ',x).replace('`',''),flags=re.I)).strip().lower()
   if normalized(definition)!=normalized(exp):raise RuntimeError('Definition differs: '+db+'.'+name)
  evidence[db]={'cutover':state,'historical_fingerprints':'unchanged','definitions':'match','legacy_table':'retained'}
 (out/'verification.json').write_text(json.dumps(evidence,indent=2)+'\n');print('Verified all five '+a.environment+' schemas: exact definitions, retained legacy tables, unchanged historical fingerprints.',flush=True)
elif a.mode=='rollback':
 for db in schemas:subprocess.run(['mariadb',db],input=(out/(db+'_restore.sql')).read_text(),text=True,check=True)
 print('Original scoped definitions restored.',flush=True)
