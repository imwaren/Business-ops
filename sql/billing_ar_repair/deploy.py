#!/usr/bin/env python3
"""Run on VPS6 as root. Installs scoped SQL and repairs the frozen creation-day cohort."""
import datetime, hashlib, json, os, re, subprocess, sys, uuid
from decimal import Decimal
from pathlib import Path
import xml.etree.ElementTree as ET
PACKAGE=Path(__file__).resolve().parent
BACKUP=Path('/root/billing_ar_20261010_0015_final')
AUDIT_NAMES=[]
CLIENT=['mariadb','--comments','--binary-mode','--default-character-set=utf8mb4','--batch','--skip-column-names','--skip-reconnect']
ROUTINES=[('FUNCTION','gl_billing_transaction_no'),('PROCEDURE','gl_clear_billing_movement'),('PROCEDURE','gl_post_billing_entry'),('PROCEDURE','gl_post_billing_payment')]
SCHEMAS=['mytemr_diagnostic_mobile','mytemr_diagnostic','mytemr_diagnostic_mactan','mytemr_diagnostic_conso','mytemr_diagnostic_mantlewood']
def emit(event,**details):
 print(json.dumps({'event':event,**details}),flush=True)
def run(sql,db=None):
 p=subprocess.run(CLIENT+([db] if db else []),input=sql.encode(),capture_output=True)
 if p.returncode:raise RuntimeError(p.stderr.decode(errors='replace').strip())
 return p.stdout.decode().strip()
def scalar(sql,db=None):return run(sql,db)
def listing(ids):return ','.join(map(str,ids)) if ids else 'NULL'
def require(ok,message):
 if not ok:raise RuntimeError(message)
class Transaction:
 def __init__(self,db,folder):
  self.stderr=open(folder/'transaction.stderr','wb')
  self.p=subprocess.Popen(CLIENT+['--unbuffered',db],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=self.stderr)
 def query(self,sql,hash_only=False):
  token='__billing_ar_'+uuid.uuid4().hex
  self.p.stdin.write((sql.rstrip('; \n')+';\nSELECT \''+token+'\';\n').encode());self.p.stdin.flush()
  lines=[];digest=hashlib.sha256()
  while True:
   line=self.p.stdout.readline()
   if not line:raise RuntimeError('Transaction client failed; see transaction.stderr')
   if line.rstrip(b'\r\n')==token.encode():break
   if hash_only:digest.update(line)
   else:lines.append(line.decode().rstrip('\r\n'))
  return digest.hexdigest() if hash_only else '\n'.join(lines)
 def close(self):
  if self.p.stdin and not self.p.stdin.closed:
   try:self.p.stdin.close()
   except BrokenPipeError:pass
  self.p.wait(timeout=30);self.stderr.close()
def freeze(db):
 date="added_on >= '2026-10-09' AND added_on < '2026-10-10'"
 bills=[int(i) for i in run('SELECT id FROM billing WHERE '+date+' ORDER BY id',db).splitlines()]
 payments=[int(i) for i in run('SELECT id FROM billing_payment WHERE '+date+' ORDER BY id',db).splitlines()]
 entries=[int(i) for i in run('SELECT id FROM billing_entry WHERE billing_id IN('+listing(bills)+') ORDER BY id',db).splitlines()]
 return {'bills':bills,'entries':entries,'payments':payments,'date_from':'2026-10-09','date_to_exclusive':'2026-10-10'}
def predicates(cohort):
 movement="((source='billing_entry' AND source_id IN("+listing(cohort['entries'])+")) OR (source='billing_payment' AND source_id IN("+listing(cohort['payments'])+")))"
 return movement

def save_definitions(db,folder):
 originals=[];restore=['USE `'+db+'`;','DELIMITER $$']
 for kind,name in ROUTINES:
  exists=scalar("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='"+db+"' AND routine_name='"+name+"'")=='1'
  restore.append('DROP '+kind+' IF EXISTS '+name+'$$')
  if exists:
   p=subprocess.run(['mariadb','--default-character-set=utf8mb4','--xml',db],input=('SHOW CREATE '+kind+' '+name+';').encode(),capture_output=True,check=True)
   node=ET.fromstring(p.stdout)
   fields={f.attrib['name']:f.text or '' for f in node.findall('.//field')}
   definition=fields['Create '+kind.title()]
   originals.append({'kind':kind,'name':name,'definition':definition,'sql_mode':fields.get('sql_mode','')})
   restore.append('SET SESSION sql_mode='+"'"+fields.get('sql_mode','').replace("'","''")+"'$$")
   restore.append(definition+'$$')
 restore.append('DELIMITER ;')
 (folder/'definitions_before.json').write_text(json.dumps(originals,indent=2))
 (folder/'restore_definitions.sql').write_text('\n'.join(restore)+'\n')
 triggers=run("SELECT trigger_name,event_object_table,action_timing,event_manipulation,action_statement,definer,sql_mode FROM information_schema.triggers WHERE trigger_schema='"+db+"' AND event_object_table IN('billing','billing_entry','billing_payment','general_ledger','journal_entry','journal_entry_item') ORDER BY trigger_name")
 (folder/'triggers_before.tsv').write_text(triggers)
 # Capture the exact hook CREATE statements without ever replacing them in this rollout.
 trigger_sql=['USE `'+db+'`;','DELIMITER $$']
 trigger_rows={line.split('\t')[0]:line.split('\t') for line in triggers.split('\n')}
 for name in run("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema='"+db+"' AND event_object_table IN('billing','billing_entry','billing_payment','general_ledger','journal_entry','journal_entry_item') ORDER BY trigger_name").splitlines():
  p=subprocess.run(['mariadb','--default-character-set=utf8mb4','--xml',db],input=('SHOW CREATE TRIGGER `'+name+'`;').encode(),capture_output=True,check=True)
  fields={f.attrib['name']:f.text or '' for f in ET.fromstring(p.stdout).findall('.//field')}
  body=re.sub(r'\\([nrt0\\])',lambda m:{'n':'\n','r':'\r','t':'\t','0':'\0','\\':'\\'}[m[1]],trigger_rows[name][4])
  definition=re.sub(r'(FOR EACH ROW\s+).*',lambda m:m[1]+body,fields['SQL Original Statement'],count=1,flags=re.S)
  trigger_sql+=['DROP TRIGGER IF EXISTS `'+name+'`$$','SET SESSION sql_mode='+"'"+fields['sql_mode'].replace("'","''")+"'$$",definition+'$$']
 trigger_sql.append('DELIMITER ;');(folder/'restore_hooks.sql').write_text('\n'.join(trigger_sql)+'\n')
 return triggers

def audit_definitions(db,folder):
 names=run("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema='"+db+"' AND event_object_table IN('general_ledger','journal_entry','journal_entry_item') AND definer <> 'root@localhost' ORDER BY trigger_name").splitlines()
 original_rows={line.split('\t')[0]:line.split('\t') for line in (folder/'triggers_before.tsv').read_bytes().decode().split('\n')}
 original=['USE `'+db+'`;','DELIMITER $$'];temporary=['USE `'+db+'`;','DELIMITER $$']
 for name in names:
  p=subprocess.run(['mariadb','--default-character-set=utf8mb4','--xml',db],input=('SHOW CREATE TRIGGER `'+name+'`;').encode(),capture_output=True,check=True)
  fields={f.attrib['name']:f.text or '' for f in ET.fromstring(p.stdout).findall('.//field')}
  definition=fields['SQL Original Statement']
  body=re.sub(r'\\([nrt0\\])',lambda m:{'n':'\n','r':'\r','t':'\t','0':'\0','\\':'\\'}[m[1]],original_rows[name][4])
  definition=re.sub(r'(FOR EACH ROW\s+).*',lambda m:m[1]+body,definition,count=1,flags=re.S)
  mode="SET SESSION sql_mode='"+fields['sql_mode'].replace("'","''")+"'$$"
  root_definition=re.sub(r'^CREATE DEFINER=.*? TRIGGER ', 'CREATE DEFINER=`root`@`localhost` TRIGGER ',definition,count=1)
  require(root_definition!=definition,'Cannot prepare maintenance audit definer '+name)
  original+=['DROP TRIGGER IF EXISTS `'+name+'`$$',mode,definition+'$$']
  temporary+=['DROP TRIGGER IF EXISTS `'+name+'`$$',mode,root_definition+'$$']
 original.append('DELIMITER ;');temporary.append('DELIMITER ;')
 (folder/'restore_audit_definers.sql').write_text('\n'.join(original)+'\n')
 (folder/'maintenance_audit_definers.sql').write_text('\n'.join(temporary)+'\n')
 return names

def backup_tables(db,cohort,folder):
 movement=predicates(cohort)
 specs={'billing':'id IN('+listing(cohort['bills'])+')','billing_entry':'billing_id IN('+listing(cohort['bills'])+')','billing_payment':'id IN('+listing(cohort['payments'])+')','billing_payment_entry':'billing_payment_id IN('+listing(cohort['payments'])+')','general_ledger':movement,'journal_entry':movement,'journal_entry_item':'journal_entry_id IN(SELECT id FROM journal_entry WHERE '+movement+')'}
 for table,where in specs.items():
  with open(folder/(table+'.sql'),'wb') as f:
   subprocess.run(['mariadb-dump','--default-character-set=utf8mb4','--single-transaction','--skip-lock-tables','--no-create-info','--skip-triggers','--complete-insert','--hex-blob','--where='+where,db,table],stdout=f,stderr=subprocess.PIPE,check=True)
 (folder/'backup_predicates.json').write_text(json.dumps(specs,indent=2))

def fingerprints(tx,db,cohort,env):
 result={}
 for table in ['billing','billing_entry','billing_payment','billing_payment_entry','account_type']:
  result[db+'.'+table]=tx.query('SELECT * FROM `'+table+'` ORDER BY id',True)
 movement=predicates(cohort)
 result[db+'.GL_outside']=tx.query('SELECT * FROM general_ledger WHERE COALESCE('+movement+',0)=0 ORDER BY id',True)
 result[db+'.JE_outside']=tx.query('SELECT * FROM journal_entry WHERE COALESCE('+movement+',0)=0 ORDER BY id',True)
 result[db+'.JE_items_outside']=tx.query('SELECT * FROM journal_entry_item WHERE journal_entry_id NOT IN(SELECT id FROM journal_entry WHERE '+movement+') ORDER BY id',True)
 for branch in SCHEMAS:
  schema=branch+('_uat' if env=='uat' else '')
  for table in ['general_ledger','journal_entry','journal_entry_item']:
   result[schema+'.'+table]=tx.query('SELECT * FROM `'+schema+'`.`'+table+'` ORDER BY id',True)
 result['diagnostic_definitions']=tx.query("SELECT routine_schema,routine_name,routine_definition,definer,sql_mode FROM information_schema.routines WHERE routine_schema IN("+','.join("'"+s+('_uat' if env=='uat' else '')+"'" for s in SCHEMAS)+") ORDER BY routine_schema,routine_name",True)
 result['master_other_definitions']=tx.query("SELECT routine_name,routine_definition,definer,sql_mode FROM information_schema.routines WHERE routine_schema='"+db+"' AND routine_name NOT IN('gl_billing_transaction_no','gl_clear_billing_movement','gl_post_billing_entry','gl_post_billing_payment') ORDER BY routine_name",True)
 result['master_triggers']=tx.query("SELECT trigger_name,action_statement,definer,sql_mode FROM information_schema.triggers WHERE trigger_schema='"+db+"' AND trigger_name NOT IN("+','.join("'"+name+"'" for name in AUDIT_NAMES or ['__none__'])+") ORDER BY trigger_name",True)
 return result

def preflight(db,cohort):
 for code in ['102-113','102-113B']:
  require(scalar("SELECT COUNT(*) FROM account_type WHERE code='"+code+"' AND parent_id IS NULL AND is_deleted=0",db)=='1','Ambiguous or missing '+code)
 for hook,table in [('trg_gl_billing_entry_ai','billing_entry'),('trg_gl_billing_entry_au','billing_entry'),('trg_gl_billing_ai','billing'),('trg_gl_billing_au','billing'),('trg_gl_billing_payment_ai','billing_payment'),('trg_gl_billing_payment_au','billing_payment')]:
  require(scalar("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='"+db+"' AND trigger_name='"+hook+"' AND event_object_table='"+table+"'")=='1','Missing billing hook '+hook)
 require(scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='"+db+"' AND table_name IN('billing','billing_entry','billing_payment','billing_payment_entry','general_ledger','journal_entry','journal_entry_item') AND engine='InnoDB'")=='7','Required InnoDB tables missing')
 if cohort['payments']:
  bad=scalar("SELECT COUNT(*) FROM billing_payment p WHERE p.id IN("+listing(cohort['payments'])+") AND p.is_deleted=0 AND (IFNULL(p.wht_amount,0)<0 OR IFNULL(p.wht_amount,0)>IFNULL(p.grand_total,0) OR (IFNULL(p.is_unidentified,0)=0 AND IFNULL(p.grand_total,0) <> (SELECT IFNULL(SUM(e.amount),0) FROM billing_payment_entry e JOIN billing_entry b ON b.id=e.billing_entry_id AND b.is_deleted=0 AND b.branch_id>0 WHERE e.billing_payment_id=p.id AND e.is_deleted=0)))",db)
  require(bad=='0','Selected collections do not reconcile to active payment entries')
 return {'bill_count':len(cohort['bills']),'entry_count':len(cohort['entries']),'payment_count':len(cohort['payments'])}

def validate(tx,cohort):
 result={'entries':0,'payments':0,'debit':'0','credit':'0'};debit=Decimal(0)
 rows=tx.query("SELECT e.id,IFNULL(e.branch_id,0),IFNULL(e.amount,0),e.is_deleted,e.status,b.is_deleted,b.status,DATE(COALESCE(NULLIF(b.added_on,'0000-00-00 00:00:00'),e.added_on)),gl_billing_transaction_no(e.branch_id,e.transaction_id),e.billing_id FROM billing_entry e JOIN billing b ON b.id=e.billing_id WHERE e.id IN("+listing(cohort['entries'])+") ORDER BY e.id")
 ar=int(tx.query("SELECT gl_account_id('102-113')"));unbilled=int(tx.query("SELECT gl_account_id('102-113B')"))
 for row in rows.splitlines():
  id,branch,amount,deleted,status,bdeleted,bstatus,date,txn,bill=row.split('\t');id=int(id);branch=int(branch);amount=Decimal(amount)
  eligible=deleted=='0' and bdeleted=='0' and status in ('pending','open_bill','closed_bill') and bstatus in ('pending','approved','open_bill','closed_bill') and branch>=0 and amount>0
  gl=tx.query(f"SELECT account_type_id,branch_id,date,debit,credit,remark FROM general_ledger WHERE source='billing_entry' AND source_id={id} AND is_deleted=0 ORDER BY account_type_id")
  headers=tx.query(f"SELECT id,status,total_debit,total_credit,date,cost_center_id,remarks FROM journal_entry WHERE source='billing_entry' AND source_id={id} AND is_deleted=0")
  if not eligible:require(gl=='' and headers=='','Ineligible entry remains posted '+str(id));continue
  records=[r.split('\t') for r in gl.splitlines()]
  require(len(records)==2,'Entry GL pair missing/duplicated '+str(id))
  for account,want_debit,want_credit in [(ar,amount,Decimal(0)),(unbilled,Decimal(0),amount)]:
   matches=[r for r in records if int(r[0])==account]
   require(len(matches)==1,'Wrong AR account '+str(id));r=matches[0]
   require(int(r[1])==branch and r[2]==date and Decimal(r[3])==want_debit and Decimal(r[4])==want_credit,'GL values differ '+str(id))
   require('Billing #'+bill in r[5] and 'entry #'+str(id) in r[5] and (txn=='NULL' or 'transaction #'+txn in r[5]),'GL reference missing '+str(id))
  require(len(headers.splitlines())==1,'Duplicate/missing JE '+str(id))
  header=headers.split('\t');require(header[1]=='approved' and Decimal(header[2])==amount and Decimal(header[3])==amount and header[4]==date and int(header[5])==branch,'JE header differs '+str(id))
  require(txn=='NULL' or 'transaction #'+txn in header[6],'JE header transaction missing')
  items=tx.query('SELECT account_type_id,debit,credit,remarks FROM journal_entry_item WHERE journal_entry_id='+header[0]+' AND is_deleted=0 ORDER BY account_type_id')
  require(items=='\n'.join('\t'.join([r[0],r[3],r[4],r[5]]) for r in records),'Journal items differ from GL '+str(id))
  result['entries']+=1;debit+=amount
 for id in cohort['payments']:
  header=tx.query(f'SELECT is_deleted,IFNULL(grand_total,0),COALESCE(paid_date,date,DATE(added_on)) FROM billing_payment WHERE id={id}').split('\t')
  gl=tx.query(f"SELECT COUNT(*),IFNULL(SUM(debit),0),IFNULL(SUM(credit),0),SUM(account_type_id IS NULL),MIN(date),MAX(date) FROM general_ledger WHERE source='billing_payment' AND source_id={id} AND is_deleted=0").split('\t')
  headers=tx.query(f"SELECT id,total_debit,total_credit,status FROM journal_entry WHERE source='billing_payment' AND source_id={id} AND is_deleted=0")
  if header[0]=='1' or Decimal(header[1])<=0:require(gl[0]=='0' and headers=='','Deleted/zero collection remains posted');continue
  require(int(gl[0])>0 and Decimal(gl[1])==Decimal(header[1])==Decimal(gl[2]) and gl[3]=='0' and gl[4]==header[2]==gl[5],'Collection GL mismatch '+str(id))
  require(len(headers.splitlines())==1,'Collection JE duplicate/missing')
  je=headers.split('\t');require(Decimal(je[1])==Decimal(gl[1])==Decimal(je[2]) and je[3]=='approved','Collection JE totals mismatch')
  expected=tx.query(f"SELECT account_type_id,SUM(debit),SUM(credit) FROM general_ledger WHERE source='billing_payment' AND source_id={id} AND is_deleted=0 GROUP BY account_type_id ORDER BY account_type_id")
  actual=tx.query('SELECT account_type_id,SUM(debit),SUM(credit) FROM journal_entry_item WHERE journal_entry_id='+je[0]+' AND is_deleted=0 GROUP BY account_type_id ORDER BY account_type_id')
  require(actual==expected,'Collection journal items differ');result['payments']+=1
 movement=predicates(cohort)
 require(tx.query('SELECT COUNT(*) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE i.is_deleted=0 AND j.is_deleted=1 AND ('+movement.replace('source','j.source')+')')=='0','Active items under retired headers')
 result['debit']=str(debit);result['credit']=str(debit);return result

def definition_checks(db,env):
 source=(PACKAGE/('install_'+env+'.sql')).read_text()
 hashes={}
 for kind,name in ROUTINES:
  expected=re.search(r'CREATE '+kind+' '+name+r'\b.*?(BEGIN\n.*?END)\$\$',source,re.S).group(1)
  actual=run("SELECT routine_definition FROM information_schema.routines WHERE routine_schema='"+db+"' AND routine_name='"+name+"'")
  # Batch mode escapes line breaks; unescape only mysql's serialization, not SQL literals.
  actual=re.sub(r'\\([nrt0\\])',lambda m:{'n':'\n','r':'\r','t':'\t','0':'\0','\\':'\\'}[m[1]],actual)
  require(actual.strip()==expected.strip(),'Installed definition mismatch '+name)
  hashes[name]=hashlib.sha256(actual.encode()).hexdigest()
 return hashes

def deploy(env):
 global AUDIT_NAMES
 db='mytemr_master'+('_uat' if env=='uat' else '')
 folder=BACKUP/env;folder.mkdir(parents=True,exist_ok=False)
 prior=scalar('SELECT @@global.read_only');(folder/'settings_before.json').write_text(json.dumps({'read_only':prior}))
 tx=None;installed=False;committed=False;recovery_ok=True;audit_changed=False
 try:
  require(run("SELECT COUNT(*) FROM information_schema.processlist WHERE USER='root' AND COMMAND<>'Sleep' AND ID<>CONNECTION_ID()")=='0','Another privileged writer is active')
  emit('pause-normal-db-writes',environment=env)
  run('SET GLOBAL read_only=ON')
  # Freeze only once after normal application writes have been paused.
  cohort=freeze(db);(folder/'cohort.json').write_text(json.dumps(cohort,indent=2))
  stats=preflight(db,cohort);emit('preflight',environment=env,**stats)
  hooks=save_definitions(db,folder);AUDIT_NAMES=audit_definitions(db,folder);backup_tables(db,cohort,folder);emit('backups-complete',environment=env,path=str(folder))
  tx=Transaction(db,folder)
  before=fingerprints(tx,db,cohort,env);(folder/'fingerprints_before.json').write_text(json.dumps(before,indent=2))
  tx.close();tx=None
  # Audit triggers execute with their own definer; use root temporarily during read-only repair.
  if AUDIT_NAMES:
   audit_changed=True;run((folder/'maintenance_audit_definers.sql').read_bytes().decode());emit('audit-maintenance-definers',environment=env,triggers=AUDIT_NAMES)
  # CREATE/DROP routine statements have implicit commits; repair starts afterward.
  installed=True;run((PACKAGE/('install_'+env+'.sql')).read_text(),db)
  definitions=definition_checks(db,env)
  tx=Transaction(db,folder);tx.query('START TRANSACTION')
  tx.query('SELECT id FROM billing WHERE id IN('+listing(cohort['bills'])+') FOR UPDATE')
  tx.query('SELECT id FROM billing_entry WHERE id IN('+listing(cohort['entries'])+') FOR UPDATE')
  tx.query('SELECT id FROM billing_payment WHERE id IN('+listing(cohort['payments'])+') FOR UPDATE')
  for id in cohort['bills']:tx.query(f'CALL gl_post_billing({id})')
  for id in cohort['payments']:tx.query(f'CALL gl_post_billing_payment({id})')
  emit('repair-built-uncommitted',environment=env)
  verification=validate(tx,cohort)
  if env=='uat' and 5754 in cohort['bills']:
   require(tx.query("SELECT SUM(debit),SUM(credit) FROM general_ledger WHERE source='billing_entry' AND source_id IN(75975,75976) AND is_deleted=0")=="1800.0000\t1800.0000",'Reference billing 5754 differs from the expected 1800 AR transfer')
  # Test a sample within the same repair transaction; each run leaves one active set.
  sample=5754 if 5754 in cohort['bills'] else (cohort['bills'][0] if cohort['bills'] else None)
  if sample:
   tx.query(f'CALL gl_post_billing({sample}); CALL gl_post_billing({sample})');verification=validate(tx,cohort)
  after=fingerprints(tx,db,cohort,env)
  require(before==after,'Source/out-of-scope/diagnostic fingerprint changed')
  tx.query('COMMIT');committed=True;tx.close();tx=None
  if audit_changed:
   run((folder/'restore_audit_definers.sql').read_bytes().decode());audit_changed=False
  restored_hooks=run("SELECT trigger_name,event_object_table,action_timing,event_manipulation,action_statement,definer,sql_mode FROM information_schema.triggers WHERE trigger_schema='"+db+"' AND event_object_table IN('billing','billing_entry','billing_payment','general_ledger','journal_entry','journal_entry_item') ORDER BY trigger_name")
  require(restored_hooks==hooks,'Original hook definitions were not restored exactly')
  final=Transaction(db,folder);verification=validate(final,cohort);post=fingerprints(final,db,cohort,env);final.close()
  require(post==before,'Post-commit fingerprint check failed')
  report={'environment':env,'schema':db,'cohort':stats,'verification':verification,'definitions':definitions,'protected_fingerprints_unchanged':True,'original_hooks_restored':True,'backup_path':str(folder)}
  (folder/'fingerprints_after.json').write_text(json.dumps(post,indent=2));(folder/'verified.json').write_text(json.dumps(report,indent=2))
  emit('verified',**report)
 except BaseException as error:
  if tx:
   try:tx.query('ROLLBACK')
   except Exception:pass
   tx.close();tx=None
  if installed and not committed:
   try:run((folder/'restore_definitions.sql').read_bytes().decode());emit('definitions-restored',environment=env)
   except Exception as restore_error:recovery_ok=False;emit('recovery-failed',environment=env,error=str(restore_error))
  if committed:recovery_ok=False
  (folder/'failure.txt').write_text(str(error))
  raise
 finally:
  if audit_changed:
   try:run((folder/'restore_audit_definers.sql').read_bytes().decode())
   except Exception as error:recovery_ok=False;emit('audit-recovery-failed',environment=env,error=str(error))
  if recovery_ok:
   run('SET GLOBAL read_only='+('ON' if prior=='1' else 'OFF'));emit('maintenance-restored',environment=env,read_only=prior)
  else:emit('writes-remain-paused',environment=env,backup_path=str(folder))

def main():
 BACKUP.mkdir(parents=True,exist_ok=True)
 (BACKUP/'package_sha256.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in PACKAGE.glob('install_*.sql')},indent=2))
 for env in ([sys.argv[1]] if len(sys.argv)>1 and sys.argv[1] in ('uat','prod') else ['uat','prod']):deploy(env)
 emit('rollout-complete',backup_root=str(BACKUP))
if __name__=='__main__':main()
